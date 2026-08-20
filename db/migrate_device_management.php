<?php
/**
 * Device Management phase 1 migration.
 *
 * Usage in production:
 *   php db/migrate_device_management.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../includes/Database.php';

$pdo = Database::pdo();

$columnExists = static function (string $columnName) use ($pdo): bool {
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = ?
           AND COLUMN_NAME = ?'
    );
    $stmt->execute(['license_activations', $columnName]);
    return (int) $stmt->fetchColumn() > 0;
};

$columns = [
    'device_name' => "VARCHAR(100) NULL AFTER hwid",
    'admin_note' => "VARCHAR(255) NULL AFTER ip_address",
];

echo "Connecting to database... OK\n";

foreach ($columns as $columnName => $definition) {
    if ($columnExists($columnName)) {
        echo "EXISTS - license_activations.{$columnName}\n";
        continue;
    }

    $pdo->exec("ALTER TABLE license_activations ADD COLUMN {$columnName} {$definition}");
    echo "ADDED - license_activations.{$columnName}\n";
}

$pdo->query('SELECT id, device_name, admin_note FROM license_activations LIMIT 1');
echo "Device management migration complete.\n";
