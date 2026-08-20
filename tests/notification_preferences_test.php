<?php
require_once __DIR__ . '/../includes/Database.php';
require_once __DIR__ . '/../includes/NotificationPreferences.php';

function preference_check(string $label, bool $condition): void
{
    if (!$condition) {
        fwrite(STDERR, "[FAIL] {$label}\n");
        exit(1);
    }
    echo "[PASS] {$label}\n";
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE admin_notification_preferences (
    admin_username TEXT PRIMARY KEY,
    activation_enabled INTEGER NOT NULL DEFAULT 1,
    recovery_enabled INTEGER NOT NULL DEFAULT 1,
    expiry_enabled INTEGER NOT NULL DEFAULT 1,
    security_enabled INTEGER NOT NULL DEFAULT 1,
    system_enabled INTEGER NOT NULL DEFAULT 1,
    muted_until TEXT NULL,
    updated_at TEXT NULL
)');
Database::setTestInstance($pdo);

$defaults = NotificationPreferences::get('missing-admin');
preference_check('Missing preference row defaults activation on', (int) $defaults['activation'] === 1);
preference_check('Missing preference row defaults recovery on', (int) $defaults['recovery'] === 1);
preference_check('Missing preference row is not muted', $defaults['muted_until'] === null);

$pdo->prepare('INSERT INTO admin_notification_preferences
    (admin_username, activation_enabled, recovery_enabled, expiry_enabled, security_enabled, system_enabled, muted_until)
    VALUES (?, ?, ?, ?, ?, ?, ?)')
    ->execute(['support', 0, 1, 0, 1, 1, null]);

preference_check('Disabled activation category is blocked', NotificationPreferences::allows('support', 'activation') === false);
preference_check('Enabled recovery category is allowed', NotificationPreferences::allows('support', 'recovery') === true);
preference_check('Disabled expiry category is blocked', NotificationPreferences::allows('support', 'expiry') === false);

$future = date('Y-m-d H:i:s', time() + 3600);
$pdo->prepare('UPDATE admin_notification_preferences SET muted_until = ? WHERE admin_username = ?')->execute([$future, 'support']);
preference_check('Temporary mute suppresses enabled category', NotificationPreferences::allows('support', 'recovery') === false);

$past = date('Y-m-d H:i:s', time() - 60);
$pdo->prepare('UPDATE admin_notification_preferences SET muted_until = ? WHERE admin_username = ?')->execute([$past, 'support']);
preference_check('Expired mute no longer suppresses enabled category', NotificationPreferences::allows('support', 'recovery') === true);

echo "NOTIFICATION PREFERENCE TESTS PASSED\n";
