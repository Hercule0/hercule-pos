<?php
/**
 * MC-002 — Multi-Cashier entitlement v2 migration.
 *
 * Adds stable cloud/store/device UUIDs and separates terminal entitlement
 * capacity from the legacy max_activations field while preserving v1.
 *
 * UUID columns intentionally remain nullable at the schema level because
 * legacy v1 issue/activation paths must keep working unchanged. Existing rows
 * are fully backfilled here; any future v1-created row is assigned its stable
 * UUID lazily on the first v2 operation.
 *
 * Usage in production:
 *   php db/migrate_multi_entitlement_v2.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../includes/Database.php';

$pdo = Database::pdo();
if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
    throw new RuntimeException('MC-002 production migration requires MySQL.');
}

$columnExists = static function (string $table, string $column) use ($pdo): bool {
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $stmt->execute([$table, $column]);
    return (int) $stmt->fetchColumn() > 0;
};

$indexExists = static function (string $table, string $index) use ($pdo): bool {
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?'
    );
    $stmt->execute([$table, $index]);
    return (int) $stmt->fetchColumn() > 0;
};

$licenseColumns = [
    'license_uuid' => 'VARCHAR(36) NULL AFTER id',
    'store_uuid' => 'VARCHAR(36) NULL AFTER license_uuid',
    'multi_cashier' => 'TINYINT(1) NOT NULL DEFAULT 0 AFTER max_activations',
    'max_terminals' => 'INT UNSIGNED NULL AFTER multi_cashier',
    'max_management_devices' => 'INT UNSIGNED NOT NULL DEFAULT 1 AFTER max_terminals',
    'features_json' => 'JSON NULL AFTER max_management_devices',
    'entitlement_version' => 'BIGINT UNSIGNED NOT NULL DEFAULT 1 AFTER features_json',
    'offline_valid_until' => 'DATETIME NULL AFTER entitlement_version',
];

$activationColumns = [
    'device_uuid' => 'VARCHAR(36) NULL AFTER hwid',
    'device_role' => "VARCHAR(32) NOT NULL DEFAULT 'single_terminal' AFTER device_uuid",
    'counts_as_terminal' => 'TINYINT(1) NOT NULL DEFAULT 1 AFTER device_role',
    'protocol_version' => 'INT UNSIGNED NOT NULL DEFAULT 1 AFTER app_version',
    'certificate_fingerprint' => 'VARCHAR(128) NULL AFTER protocol_version',
    'paired_at' => 'DATETIME NULL AFTER certificate_fingerprint',
];

echo "MC-002 entitlement v2 migration\n";
echo "Connecting to database... OK\n";

foreach ($licenseColumns as $column => $definition) {
    if (!$columnExists('licenses', $column)) {
        $pdo->exec("ALTER TABLE licenses ADD COLUMN {$column} {$definition}");
        echo "ADDED - licenses.{$column}\n";
    } else {
        echo "EXISTS - licenses.{$column}\n";
    }
}

foreach ($activationColumns as $column => $definition) {
    if (!$columnExists('license_activations', $column)) {
        $pdo->exec("ALTER TABLE license_activations ADD COLUMN {$column} {$definition}");
        echo "ADDED - license_activations.{$column}\n";
    } else {
        echo "EXISTS - license_activations.{$column}\n";
    }
}

// Existing rows get stable identities immediately. A legacy license does NOT
// become Multi merely because old max_activations happened to be >1.
$pdo->exec("UPDATE licenses SET license_uuid = UUID() WHERE license_uuid IS NULL OR license_uuid = ''");
$pdo->exec("UPDATE licenses SET store_uuid = UUID() WHERE store_uuid IS NULL OR store_uuid = ''");
$pdo->exec('UPDATE licenses SET max_terminals = CASE WHEN multi_cashier = 1 THEN GREATEST(1, max_activations) ELSE 1 END WHERE max_terminals IS NULL OR max_terminals < 1');
$pdo->exec("UPDATE licenses SET features_json = JSON_OBJECT('multi_cashier', IF(multi_cashier = 1, TRUE, FALSE), 'offline_sale', TRUE) WHERE features_json IS NULL");

$pdo->exec("UPDATE license_activations SET device_uuid = UUID() WHERE device_uuid IS NULL OR device_uuid = ''");
$pdo->exec("UPDATE license_activations SET device_role = 'single_terminal' WHERE device_role IS NULL OR device_role = ''");
$pdo->exec('UPDATE license_activations SET counts_as_terminal = 1 WHERE counts_as_terminal IS NULL');

$duplicates = (int) $pdo->query(
    "SELECT COUNT(*) FROM (
        SELECT license_uuid FROM licenses WHERE license_uuid IS NOT NULL GROUP BY license_uuid HAVING COUNT(*) > 1
        UNION ALL
        SELECT store_uuid FROM licenses WHERE store_uuid IS NOT NULL GROUP BY store_uuid HAVING COUNT(*) > 1
    ) AS d"
)->fetchColumn();
if ($duplicates !== 0) {
    throw new RuntimeException('MC-002 verification failed: duplicate license/store UUIDs detected.');
}

$deviceDuplicates = (int) $pdo->query(
    "SELECT COUNT(*) FROM (
        SELECT device_uuid FROM license_activations
        WHERE device_uuid IS NOT NULL GROUP BY device_uuid HAVING COUNT(*) > 1
    ) AS d"
)->fetchColumn();
if ($deviceDuplicates !== 0) {
    throw new RuntimeException('MC-002 verification failed: duplicate device UUIDs detected.');
}

if (!$indexExists('licenses', 'uq_licenses_license_uuid')) {
    $pdo->exec('ALTER TABLE licenses ADD UNIQUE KEY uq_licenses_license_uuid (license_uuid)');
    echo "ADDED - uq_licenses_license_uuid\n";
}
if (!$indexExists('licenses', 'uq_licenses_store_uuid')) {
    $pdo->exec('ALTER TABLE licenses ADD UNIQUE KEY uq_licenses_store_uuid (store_uuid)');
    echo "ADDED - uq_licenses_store_uuid\n";
}
if (!$indexExists('license_activations', 'uq_activations_device_uuid')) {
    $pdo->exec('ALTER TABLE license_activations ADD UNIQUE KEY uq_activations_device_uuid (device_uuid)');
    echo "ADDED - uq_activations_device_uuid\n";
}
if (!$indexExists('license_activations', 'idx_activations_terminal_capacity')) {
    $pdo->exec('ALTER TABLE license_activations ADD INDEX idx_activations_terminal_capacity (license_id, is_active, counts_as_terminal)');
    echo "ADDED - idx_activations_terminal_capacity\n";
}

$missingLicenses = (int) $pdo->query(
    "SELECT COUNT(*) FROM licenses
     WHERE license_uuid IS NULL OR license_uuid = ''
        OR store_uuid IS NULL OR store_uuid = ''
        OR max_terminals IS NULL OR max_terminals < 1"
)->fetchColumn();
$missingDevices = (int) $pdo->query(
    "SELECT COUNT(*) FROM license_activations WHERE device_uuid IS NULL OR device_uuid = ''"
)->fetchColumn();

if ($missingLicenses !== 0 || $missingDevices !== 0) {
    throw new RuntimeException('MC-002 verification failed: historical UUID/capacity backfill incomplete.');
}

echo "MC-002 entitlement v2 migration complete.\n";
