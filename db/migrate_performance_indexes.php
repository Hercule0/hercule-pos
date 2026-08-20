<?php
/**
 * Adds composite indexes for the hottest admin and API monitoring queries.
 * Idempotent and safe to run repeatedly.
 *
 * Usage:
 *   php db/migrate_performance_indexes.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../includes/Database.php';
$pdo = Database::pdo();

if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
    echo "Performance index migration skipped: MySQL only.\n";
    exit(0);
}

$indexExists = static function (string $table, string $index) use ($pdo): bool {
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?'
    );
    $stmt->execute([$table, $index]);
    return (int) $stmt->fetchColumn() > 0;
};

$indexes = [
    ['license_activations', 'idx_activations_active_seen', '(is_active, last_seen_at)'],
    ['verification_log', 'idx_verification_result_created', '(result, created_at)'],
    ['password_recovery_requests', 'idx_recovery_status_created', '(status, created_at)'],
    ['subscription_events', 'idx_subscription_events_license_created', '(license_id, created_at)'],
    ['licenses', 'idx_licenses_status_expires', '(status, expires_at)'],
    ['admin_audit_log', 'idx_admin_audit_action_created', '(action, created_at)'],
];

foreach ($indexes as [$table, $name, $columns]) {
    if ($indexExists($table, $name)) {
        echo "EXISTS - {$table}.{$name}\n";
        continue;
    }

    $pdo->exec("CREATE INDEX {$name} ON {$table} {$columns}");
    echo "ADDED - {$table}.{$name}\n";
}

echo "Performance index migration complete.\n";
