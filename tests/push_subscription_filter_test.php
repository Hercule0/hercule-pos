<?php
require_once __DIR__ . '/../includes/Database.php';
require_once __DIR__ . '/../includes/PushNotifier.php';

function push_filter_check(string $label, bool $condition): void
{
    if (!$condition) {
        fwrite(STDERR, "[FAIL] {$label}\n");
        exit(1);
    }
    echo "[PASS] {$label}\n";
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE admin_users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT NOT NULL UNIQUE,
    is_active INTEGER NOT NULL DEFAULT 1
)');
$pdo->exec('CREATE TABLE push_subscriptions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    admin_username TEXT NOT NULL,
    endpoint TEXT NOT NULL UNIQUE,
    p256dh_key TEXT,
    auth_key TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
)');
$pdo->exec('CREATE TABLE admin_notification_preferences (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    admin_username TEXT NOT NULL UNIQUE,
    activation_enabled INTEGER NOT NULL DEFAULT 1,
    recovery_enabled INTEGER NOT NULL DEFAULT 1,
    expiry_enabled INTEGER NOT NULL DEFAULT 1,
    security_enabled INTEGER NOT NULL DEFAULT 1,
    system_enabled INTEGER NOT NULL DEFAULT 1,
    muted_until TEXT NULL
)');
Database::setTestInstance($pdo);

$pdo->exec("INSERT INTO admin_users (username,is_active) VALUES ('owner',1),('support',1),('disabled',0)");
$insertSub = $pdo->prepare('INSERT INTO push_subscriptions (admin_username,endpoint,p256dh_key,auth_key) VALUES (?,?,?,?)');
$insertSub->execute(['owner', 'https://example.test/owner', 'k1', 'a1']);
$insertSub->execute(['support', 'https://example.test/support', 'k2', 'a2']);
$insertSub->execute(['disabled', 'https://example.test/disabled', 'k3', 'a3']);
$insertSub->execute(['deleted-admin', 'https://example.test/deleted', 'k4', 'a4']);

$pdo->prepare('INSERT INTO admin_notification_preferences
    (admin_username,activation_enabled,recovery_enabled,expiry_enabled,security_enabled,system_enabled,muted_until)
    VALUES (?,?,?,?,?,?,?)')->execute(['support', 1, 0, 1, 1, 1, null]);

$allActive = PushNotifier::getSubscriptions();
push_filter_check('Only active existing admins receive uncategorized push', count($allActive) === 2);

$recovery = PushNotifier::getSubscriptions('recovery');
push_filter_check('Recovery preference can suppress an active admin', count($recovery) === 1 && $recovery[0]['admin_username'] === 'owner');

$activation = PushNotifier::getSubscriptions('activation');
push_filter_check('Enabled category keeps both active admins', count($activation) === 2);

$pdo->prepare('UPDATE admin_notification_preferences SET muted_until = ? WHERE admin_username = ?')
    ->execute([date('Y-m-d H:i:s', time() + 3600), 'support']);
$activationMuted = PushNotifier::getSubscriptions('activation');
push_filter_check('Temporary mute suppresses active admin subscription', count($activationMuted) === 1 && $activationMuted[0]['admin_username'] === 'owner');

$pdo->exec('DROP TABLE admin_notification_preferences');
$fallback = PushNotifier::getSubscriptions('recovery');
push_filter_check('Missing preference table fails open only for active admins', count($fallback) === 2);

echo "PUSH SUBSCRIPTION FILTER TESTS PASSED\n";
