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
    is_active INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
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

$pdo->exec("INSERT INTO admin_users (username,is_active,created_at) VALUES
    ('owner',1,'2026-08-20 10:00:00'),
    ('support',1,'2026-08-20 10:00:00'),
    ('disabled',0,'2026-08-20 10:00:00'),
    ('recreated',1,'2026-08-20 11:00:00')");
$insertSub = $pdo->prepare('INSERT INTO push_subscriptions (admin_username,endpoint,p256dh_key,auth_key,created_at) VALUES (?,?,?,?,?)');
$insertSub->execute(['owner', 'https://example.test/owner', 'k1', 'a1', '2026-08-20 10:05:00']);
$insertSub->execute(['support', 'https://example.test/support', 'k2', 'a2', '2026-08-20 10:05:00']);
$insertSub->execute(['disabled', 'https://example.test/disabled', 'k3', 'a3', '2026-08-20 10:05:00']);
$insertSub->execute(['deleted-admin', 'https://example.test/deleted', 'k4', 'a4', '2026-08-20 10:05:00']);
$insertSub->execute(['recreated', 'https://example.test/recreated-old', 'k5', 'a5', '2026-08-20 10:30:00']);

$pdo->prepare('INSERT INTO admin_notification_preferences
    (admin_username,activation_enabled,recovery_enabled,expiry_enabled,security_enabled,system_enabled,muted_until)
    VALUES (?,?,?,?,?,?,?)')->execute(['support', 1, 0, 1, 1, 1, null]);

$allActive = PushNotifier::getSubscriptions();
push_filter_check('Only current active admins receive uncategorized push', count($allActive) === 2);
push_filter_check('Subscription from a previous account incarnation is excluded', !in_array('recreated', array_column($allActive, 'admin_username'), true));

$recovery = PushNotifier::getSubscriptions('recovery');
push_filter_check('Recovery preference can suppress an active admin', count($recovery) === 1 && $recovery[0]['admin_username'] === 'owner');

$activation = PushNotifier::getSubscriptions('activation');
push_filter_check('Enabled category keeps both current active admins', count($activation) === 2);

$pdo->prepare('UPDATE admin_notification_preferences SET muted_until = ? WHERE admin_username = ?')
    ->execute([date('Y-m-d H:i:s', time() + 3600), 'support']);
$activationMuted = PushNotifier::getSubscriptions('activation');
push_filter_check('Temporary mute suppresses active admin subscription', count($activationMuted) === 1 && $activationMuted[0]['admin_username'] === 'owner');

$insertSub->execute(['recreated', 'https://example.test/recreated-new', 'k6', 'a6', '2026-08-20 11:05:00']);
$afterRecreation = PushNotifier::getSubscriptions();
push_filter_check('New subscription after account recreation is eligible', in_array('https://example.test/recreated-new', array_column($afterRecreation, 'endpoint'), true));
push_filter_check('Old subscription remains ineligible after new subscription', !in_array('https://example.test/recreated-old', array_column($afterRecreation, 'endpoint'), true));

$pdo->exec('DROP TABLE admin_notification_preferences');
$fallback = PushNotifier::getSubscriptions('recovery');
push_filter_check('Missing preference table fails open only for current active admins', count($fallback) === 3);
push_filter_check('Fallback still excludes stale pre-recreation subscription', !in_array('https://example.test/recreated-old', array_column($fallback, 'endpoint'), true));

echo "PUSH SUBSCRIPTION FILTER TESTS PASSED\n";
