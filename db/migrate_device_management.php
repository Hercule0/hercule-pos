<?php
/**
 * Device Management migration (phase 1 + phase 2).
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
    'app_version' => "VARCHAR(50) NULL AFTER device_name",
    'admin_note' => "VARCHAR(255) NULL AFTER ip_address",
    'is_blocked' => "TINYINT(1) NOT NULL DEFAULT 0 AFTER admin_note",
    'blocked_at' => "DATETIME NULL AFTER is_blocked",
    'blocked_by' => "VARCHAR(64) NULL AFTER blocked_at",
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

$blockedIndex = $pdo->prepare(
    'SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = ?
       AND INDEX_NAME = ?'
);
$blockedIndex->execute(['license_activations', 'idx_activations_blocked']);
if ((int) $blockedIndex->fetchColumn() === 0) {
    $pdo->exec('ALTER TABLE license_activations ADD INDEX idx_activations_blocked (is_blocked, is_active)');
    echo "ADDED - idx_activations_blocked\n";
} else {
    echo "EXISTS - idx_activations_blocked\n";
}

$pdo->query('SELECT id, device_name, app_version, admin_note, is_blocked, blocked_at, blocked_by FROM license_activations LIMIT 1');
echo "Device management migration complete.\n";
