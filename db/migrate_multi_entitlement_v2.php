<?php
/**
 * Hercule Multi-Cashier Phase 1 / Fix408 — Entitlement v2 schema.
 * Idempotent MySQL migration. Safe to run repeatedly.
 */

require_once __DIR__ . '/../includes/Database.php';

$pdo = Database::pdo();
if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
    // Production migration is MySQL-only. SQLite test harnesses create the
    // same compatibility columns explicitly in their test setup.
    return;
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

// Capture pre-migration state before ADD COLUMN defaults make an old database
// indistinguishable from an already-configured v2 database.
$maxTerminalsWasMissing = !$columnExists('licenses', 'max_terminals');
$multiCashierWasMissing = !$columnExists('licenses', 'multi_cashier');
$featuresWasMissing = !$columnExists('licenses', 'features_json');

$licenseColumns = [
    'license_uuid' => 'VARCHAR(36) NULL',
    'store_uuid' => 'VARCHAR(36) NULL',
    'multi_cashier' => 'TINYINT(1) NOT NULL DEFAULT 0',
    'max_terminals' => 'INT UNSIGNED NOT NULL DEFAULT 1',
    'max_management_devices' => 'INT UNSIGNED NOT NULL DEFAULT 1',
    'features_json' => 'LONGTEXT NULL',
    'entitlement_version' => 'BIGINT UNSIGNED NOT NULL DEFAULT 1',
    'offline_valid_until' => 'DATETIME NULL',
];
foreach ($licenseColumns as $name => $definition) {
    if (!$columnExists('licenses', $name)) {
        $pdo->exec("ALTER TABLE licenses ADD COLUMN {$name} {$definition}");
        echo "COLUMN - licenses.{$name} added\n";
    }
}

$activationColumns = [
    'device_uuid' => 'VARCHAR(36) NULL',
    'store_uuid' => 'VARCHAR(36) NULL',
    'device_role' => "VARCHAR(32) NOT NULL DEFAULT 'single_terminal'",
    'counts_as_terminal' => 'TINYINT(1) NOT NULL DEFAULT 1',
    'certificate_fingerprint' => 'VARCHAR(128) NULL',
    'paired_at' => 'DATETIME NULL',
    'revoked_at' => 'DATETIME NULL',
    'revoked_by' => 'VARCHAR(64) NULL',
    'revoke_reason' => 'VARCHAR(255) NULL',
];
foreach ($activationColumns as $name => $definition) {
    if (!$columnExists('license_activations', $name)) {
        $pdo->exec("ALTER TABLE license_activations ADD COLUMN {$name} {$definition}");
        echo "COLUMN - license_activations.{$name} added\n";
    }
}

// Stable server-side license identity. Store identity is intentionally NOT
// generated here: the already-existing Desktop store_uuid is bound on the
// first successful v2 activation so no second store identity is invented.
$pdo->exec("UPDATE licenses SET license_uuid = UUID() WHERE license_uuid IS NULL OR license_uuid = ''");

// Legacy max_activations already represented the customer's purchased device
// capacity. Preserve it exactly on the FIRST v2 migration instead of silently
// collapsing every old license to the new column's DEFAULT 1.
if ($maxTerminalsWasMissing) {
    $pdo->exec('UPDATE licenses SET max_terminals = GREATEST(1, max_activations)');
}
if ($multiCashierWasMissing) {
    $pdo->exec('UPDATE licenses SET multi_cashier = CASE WHEN max_activations > 1 THEN 1 ELSE 0 END');
}
if ($featuresWasMissing) {
    $pdo->exec(
        "UPDATE licenses
         SET features_json = CASE
             WHEN multi_cashier = 1 THEN '{\"multi_cashier\":true,\"offline_sale\":true}'
             ELSE '{\"multi_cashier\":false,\"offline_sale\":true}'
         END"
    );
} else {
    $pdo->exec(
        "UPDATE licenses
         SET features_json = CASE
             WHEN multi_cashier = 1 THEN '{\"multi_cashier\":true,\"offline_sale\":true}'
             ELSE '{\"multi_cashier\":false,\"offline_sale\":true}'
         END
         WHERE features_json IS NULL OR features_json = ''"
    );
}
$pdo->exec('UPDATE licenses SET offline_valid_until = expires_at WHERE offline_valid_until IS NULL AND expires_at IS NOT NULL');

if (!$indexExists('licenses', 'uq_licenses_license_uuid')) {
    $pdo->exec('ALTER TABLE licenses ADD UNIQUE KEY uq_licenses_license_uuid (license_uuid)');
}
if (!$indexExists('licenses', 'uq_licenses_store_uuid')) {
    $pdo->exec('ALTER TABLE licenses ADD UNIQUE KEY uq_licenses_store_uuid (store_uuid)');
}
if (!$indexExists('license_activations', 'uq_activation_device_uuid')) {
    $pdo->exec('ALTER TABLE license_activations ADD UNIQUE KEY uq_activation_device_uuid (device_uuid)');
}
if (!$indexExists('license_activations', 'idx_activation_store_role')) {
    $pdo->exec('ALTER TABLE license_activations ADD INDEX idx_activation_store_role (store_uuid, device_role, is_active)');
}
if (!$indexExists('license_activations', 'idx_activation_revoked')) {
    $pdo->exec('ALTER TABLE license_activations ADD INDEX idx_activation_revoked (license_id, revoked_at, is_active)');
}

// Recreate the trigger on every migration run so a previously installed
// Fix408 draft cannot retain an older trigger definition.
$pdo->exec('DROP TRIGGER IF EXISTS trg_licenses_entitlement_v2_bu');
$pdo->exec(
    "CREATE TRIGGER trg_licenses_entitlement_v2_bu
     BEFORE UPDATE ON licenses
     FOR EACH ROW
     BEGIN
       IF NEW.max_activations <> OLD.max_activations THEN
         SET NEW.max_terminals = NEW.max_activations;
       END IF;
       IF NOT (NEW.expires_at <=> OLD.expires_at) THEN
         SET NEW.offline_valid_until = NEW.expires_at;
       END IF;
       IF NOT (NEW.plan <=> OLD.plan)
          OR NOT (NEW.status <=> OLD.status)
          OR NOT (NEW.max_activations <=> OLD.max_activations)
          OR NOT (NEW.store_uuid <=> OLD.store_uuid)
          OR NOT (NEW.multi_cashier <=> OLD.multi_cashier)
          OR NOT (NEW.max_terminals <=> OLD.max_terminals)
          OR NOT (NEW.max_management_devices <=> OLD.max_management_devices)
          OR NOT (NEW.features_json <=> OLD.features_json)
          OR NOT (NEW.expires_at <=> OLD.expires_at)
          OR NOT (NEW.offline_valid_until <=> OLD.offline_valid_until) THEN
         SET NEW.entitlement_version = OLD.entitlement_version + 1;
       END IF;
     END"
);
echo "TRIGGER - trg_licenses_entitlement_v2_bu installed/refreshed\n";

echo "Multi entitlement v2 migration complete.\n";
