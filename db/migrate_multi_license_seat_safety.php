<?php
/**
 * MC-001 — Multi-Cashier license seat-safety migration.
 *
 * Adds explicit lifecycle markers so temporary deactivation, final revoke,
 * and device replacement are not represented by the same is_active flag.
 *
 * Usage in production:
 *   php db/migrate_multi_license_seat_safety.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../includes/Database.php';

$pdo = Database::pdo();

if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
    throw new RuntimeException('MC-001 production migration requires MySQL.');
}

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

$indexExists = static function (string $indexName) use ($pdo): bool {
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = ?
           AND INDEX_NAME = ?'
    );
    $stmt->execute(['license_activations', $indexName]);
    return (int) $stmt->fetchColumn() > 0;
};

$columns = [
    'deactivated_at' => 'DATETIME NULL AFTER blocked_by',
    'deactivated_by' => 'VARCHAR(64) NULL AFTER deactivated_at',
    'revoked_at' => 'DATETIME NULL AFTER deactivated_by',
    'revoked_by' => 'VARCHAR(64) NULL AFTER revoked_at',
    'replaced_at' => 'DATETIME NULL AFTER revoked_by',
    'replaced_by' => 'VARCHAR(64) NULL AFTER replaced_at',
];

echo "MC-001 license seat-safety migration\n";
echo "Connecting to database... OK\n";

$pdo->beginTransaction();
try {
    // ALTER TABLE causes an implicit commit in MySQL. We keep this loop
    // idempotent and verify every column after creation; the transaction is
    // primarily useful if the driver/configuration permits transactional DDL.
    if ($pdo->inTransaction()) {
        $pdo->commit();
    }

    foreach ($columns as $columnName => $definition) {
        if ($columnExists($columnName)) {
            echo "EXISTS - license_activations.{$columnName}\n";
            continue;
        }

        $pdo->exec("ALTER TABLE license_activations ADD COLUMN {$columnName} {$definition}");
        echo "ADDED - license_activations.{$columnName}\n";
    }

    if (!$indexExists('idx_activations_lifecycle')) {
        $pdo->exec(
            'ALTER TABLE license_activations
             ADD INDEX idx_activations_lifecycle
             (license_id, is_active, revoked_at, replaced_at)'
        );
        echo "ADDED - idx_activations_lifecycle\n";
    } else {
        echo "EXISTS - idx_activations_lifecycle\n";
    }

    $verify = $pdo->query(
        'SELECT id, is_active, is_blocked,
                deactivated_at, deactivated_by,
                revoked_at, revoked_by,
                replaced_at, replaced_by
         FROM license_activations
         LIMIT 1'
    );
    $verify->fetch();

    foreach (array_keys($columns) as $columnName) {
        if (!$columnExists($columnName)) {
            throw new RuntimeException("Verification failed: missing {$columnName}");
        }
    }

    echo "MC-001 license seat-safety migration complete.\n";
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, 'MC-001 migration FAILED: ' . $e->getMessage() . "\n");
    exit(1);
}
