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

// Keep support/feedback tables available both in the release migration batch
// and when a fresh environment runs only the canonical db/migrate.php entry.
require __DIR__ . '/migrate_support_feedback.php';

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

// Legacy production databases used admin_id / p256dh / auth while the current
// push runtime expects admin_username / p256dh_key / auth_key. CREATE TABLE IF
// NOT EXISTS does not evolve an existing table, so normalize that table here.
$pushColumnExists = static function (string $columnName) use ($pdo): bool {
    $check = $pdo->prepare(
        'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $check->execute(['push_subscriptions', $columnName]);
    return (int) $check->fetchColumn() > 0;
};

$pushColumns = [
    'admin_username' => "VARCHAR(255) NOT NULL DEFAULT 'legacy'",
    'p256dh_key' => "VARCHAR(255) NULL",
    'auth_key' => "VARCHAR(255) NULL",
];
foreach ($pushColumns as $columnName => $definition) {
    if (!$pushColumnExists($columnName)) {
        $pdo->exec("ALTER TABLE push_subscriptions ADD COLUMN {$columnName} {$definition}");
        echo "COLUMN - push_subscriptions.{$columnName} added\n";
    }
}

// Preserve usable subscriptions created by the legacy schema.
if ($pushColumnExists('p256dh')) {
    $pdo->exec(
        "UPDATE push_subscriptions
         SET p256dh_key = p256dh
         WHERE (p256dh_key IS NULL OR p256dh_key = '')
           AND p256dh IS NOT NULL AND p256dh <> ''"
    );
    echo "DATA - legacy push_subscriptions.p256dh copied to p256dh_key\n";
}
if ($pushColumnExists('auth')) {
    $pdo->exec(
        "UPDATE push_subscriptions
         SET auth_key = auth
         WHERE (auth_key IS NULL OR auth_key = '')
           AND auth IS NOT NULL AND auth <> ''"
    );
    echo "DATA - legacy push_subscriptions.auth copied to auth_key\n";
}
if ($pushColumnExists('admin_id')) {
    $pdo->exec(
        "UPDATE push_subscriptions ps
         JOIN admin_users au ON au.id = ps.admin_id
         SET ps.admin_username = au.username
         WHERE ps.admin_username = 'legacy'"
    );
    echo "DATA - legacy push subscription admin IDs mapped to usernames\n";
}

// Rows without complete browser keys cannot ever receive Web Push. Remove only
// those unusable legacy rows so PushNotifier never attempts to send through them.
$removedLegacyPushRows = $pdo->exec(
    "DELETE FROM push_subscriptions
     WHERE endpoint IS NULL OR endpoint = ''
        OR p256dh_key IS NULL OR p256dh_key = ''
        OR auth_key IS NULL OR auth_key = ''"
);
if ($removedLegacyPushRows > 0) {
    echo "DATA - removed {$removedLegacyPushRows} unusable legacy push subscription(s)\n";
}

$pushAdminIndex = $pdo->prepare(
    'SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?'
);
$pushAdminIndex->execute(['push_subscriptions', 'idx_push_subscriptions_admin']);
if ((int) $pushAdminIndex->fetchColumn() === 0) {
    $pdo->exec("ALTER TABLE push_subscriptions ADD INDEX idx_push_subscriptions_admin (admin_username)");
    echo "INDEX - idx_push_subscriptions_admin added\n";
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
    'support_tickets',
    'support_messages',
    'support_status_history',
    'support_attachments',
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
