<?php
/**
 * Idempotent CLI migration for the complete Hercule license-server schema.
 *
 * Usage:
 *   php db/migrate.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../includes/Database.php';

$pdo = Database::pdo();
$schemaPath = __DIR__ . '/schema.sql';
$schema = file_get_contents($schemaPath);

if ($schema === false) {
    fwrite(STDERR, "Unable to read {$schemaPath}\n");
    exit(1);
}

// The schema contains only CREATE TABLE statements, no procedures or triggers,
// so splitting on statement terminators is safe and keeps errors attributable.
$schema = preg_replace('/^\s*--.*$/m', '', $schema);
$statements = preg_split('/;\s*(?:\r?\n|$)/', $schema) ?: [];

echo "Connecting to database... OK\n";

foreach ($statements as $statement) {
    $statement = trim($statement);
    if ($statement === '') continue;

    try {
        $pdo->exec($statement);
    } catch (PDOException $e) {
        fwrite(STDERR, "Schema migration failed: " . $e->getMessage() . "\n");
        exit(1);
    }
}

$roleColumn = $pdo->prepare(
    'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
);
$roleColumn->execute(['admin_users', 'role']);
if ((int) $roleColumn->fetchColumn() === 0) {
    $pdo->exec(
        "ALTER TABLE admin_users
         ADD COLUMN role ENUM('owner','support','read_only') NOT NULL DEFAULT 'owner'
         AFTER password_hash"
    );
    echo "COLUMN - admin_users.role added (existing admins are owners)\n";
}

$accountColumns = [
    'is_active' => 'TINYINT(1) NOT NULL DEFAULT 1',
    'must_change_password' => 'TINYINT(1) NOT NULL DEFAULT 0',
];
foreach ($accountColumns as $columnName => $definition) {
    $check = $pdo->prepare(
        'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $check->execute(['admin_users', $columnName]);
    if ((int) $check->fetchColumn() === 0) {
        $pdo->exec("ALTER TABLE admin_users ADD COLUMN {$columnName} {$definition}");
        echo "COLUMN - admin_users.{$columnName} added\n";
    }
}

$mfaColumns = [
    'totp_enabled' => 'TINYINT(1) NOT NULL DEFAULT 0',
    'totp_secret' => 'TEXT NULL',
    'recovery_codes' => 'TEXT NULL',
];
foreach ($mfaColumns as $columnName => $definition) {
    $check = $pdo->prepare(
        'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $check->execute(['admin_users', $columnName]);
    if ((int) $check->fetchColumn() === 0) {
        $pdo->exec("ALTER TABLE admin_users ADD COLUMN {$columnName} {$definition}");
        echo "COLUMN - admin_users.{$columnName} added\n";
    }
}

$retentionIndexes = [
    ['login_attempts', 'idx_login_attempts_created', 'created_at'],
    ['api_requests', 'idx_api_requests_created', 'created_at'],
    ['recovery_audit_log', 'idx_recovery_audit_created', 'created_at'],
];

foreach ($retentionIndexes as [$tableName, $indexName, $columnName]) {
    $check = $pdo->prepare(
        'SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?'
    );
    $check->execute([$tableName, $indexName]);

    if ((int) $check->fetchColumn() === 0) {
        $pdo->exec("ALTER TABLE {$tableName} ADD INDEX {$indexName} ({$columnName})");
        echo "INDEX - {$indexName} added\n";
    }
}

$tables = [
    'admin_users',
    'admin_audit_log',
    'login_attempts',
    'api_requests',
    'customers',
    'licenses',
    'license_activations',
    'verification_log',
    'subscription_events',
    'license_change_notifications',
    'password_recovery_requests',
    'recovery_audit_log',
    'push_subscriptions',
    'user_sessions',
];

foreach ($tables as $tableName) {
    try {
        $pdo->query("SELECT 1 FROM {$tableName} LIMIT 1");
        echo "VERIFIED - {$tableName}\n";
    } catch (PDOException $e) {
        fwrite(STDERR, "Verification failed for {$tableName}: " . $e->getMessage() . "\n");
        exit(1);
    }
}

echo "Schema migration complete.\n";
