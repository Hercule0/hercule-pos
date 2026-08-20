<?php
require_once __DIR__ . '/../includes/Database.php';

$pdo = Database::pdo();
$driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

if ($driver !== 'mysql') {
    fwrite(STDERR, "Push subscription hygiene migration currently supports MySQL production databases only.\n");
    exit(1);
}

function columnExists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?'
    );
    $stmt->execute([$table, $column]);
    return (int)$stmt->fetchColumn() > 0;
}

function indexExists(PDO $pdo, string $table, string $index): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?'
    );
    $stmt->execute([$table, $index]);
    return (int)$stmt->fetchColumn() > 0;
}

$table = 'push_subscriptions';

if (!columnExists($pdo, $table, 'device_id')) {
    $pdo->exec("ALTER TABLE push_subscriptions ADD COLUMN device_id VARCHAR(64) NULL AFTER admin_username");
}
if (!columnExists($pdo, $table, 'user_agent')) {
    $pdo->exec("ALTER TABLE push_subscriptions ADD COLUMN user_agent VARCHAR(255) NULL AFTER auth_key");
}
if (!columnExists($pdo, $table, 'last_seen_at')) {
    $pdo->exec("ALTER TABLE push_subscriptions ADD COLUMN last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER created_at");
}
if (!columnExists($pdo, $table, 'updated_at')) {
    $pdo->exec("ALTER TABLE push_subscriptions ADD COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER last_seen_at");
}

// Existing rows predate device identity and are the source of inflated browser
// endpoint counts. Clearing only those legacy rows is safe: currently active
// browsers automatically re-register on the next authenticated page load.
$deletedLegacy = $pdo->exec("DELETE FROM push_subscriptions WHERE device_id IS NULL OR device_id = ''");

if (!indexExists($pdo, $table, 'uq_push_admin_device')) {
    $pdo->exec("ALTER TABLE push_subscriptions ADD UNIQUE KEY uq_push_admin_device (admin_username, device_id)");
}
if (!indexExists($pdo, $table, 'idx_push_last_seen')) {
    $pdo->exec("ALTER TABLE push_subscriptions ADD INDEX idx_push_last_seen (last_seen_at)");
}

printf("Push subscription hygiene migration complete. Removed %d legacy endpoint(s).\n", (int)$deletedLegacy);
