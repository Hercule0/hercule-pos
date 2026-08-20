<?php
date_default_timezone_set('UTC');
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';

require_once __DIR__ . '/../includes/Database.php';
require_once __DIR__ . '/../includes/License.php';
require_once __DIR__ . '/../includes/DeviceManager.php';

$failures = [];
function dm_check(string $label, bool $condition): void
{
    global $failures;
    echo ($condition ? '[PASS] ' : '[FAIL] ') . $label . "\n";
    if (!$condition) $failures[] = $label;
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec(file_get_contents(__DIR__ . '/../db/schema.sqlite.test.sql'));
Database::setTestInstance($pdo);

$pdo->prepare('INSERT INTO customers (name, email) VALUES (?, ?)')->execute(['Device Test', 'device@example.com']);
$customerId = (int) $pdo->lastInsertId();
$license = License::issue($customerId, 'annual', 2, 'Device manager regression');
$licenseId = (int) $license['id'];
$key = $license['license_key'];
$hwid = 'REGRESSION-HWID-001';

$activation = License::activate($key, $hwid, '127.0.0.1');
dm_check('Activation succeeds before device management actions', $activation['ok'] === true);

$row = DeviceManager::findByLicenseAndHwid($key, $hwid);
dm_check('DeviceManager finds the activated HWID', is_array($row) && $row['hwid'] === $hwid);
dm_check('Newly activated device is not blocked', DeviceManager::isBlocked($key, $hwid) === false);

$activationId = (int) ($row['id'] ?? 0);
dm_check('Blocking an existing activation succeeds', DeviceManager::setBlocked($activationId, true, 'test-admin') === true);
dm_check('Blocked flag is visible through DeviceManager', DeviceManager::isBlocked($key, $hwid) === true);

$event = $pdo->prepare("SELECT event_type, created_by FROM subscription_events WHERE license_id = ? ORDER BY id DESC LIMIT 1");
$event->execute([$licenseId]);
$blockedEvent = $event->fetch();
dm_check('Blocking writes a device_blocked subscription event', ($blockedEvent['event_type'] ?? null) === 'device_blocked');
dm_check('Blocking event records the acting administrator', ($blockedEvent['created_by'] ?? null) === 'test-admin');

dm_check('Unblocking the activation succeeds', DeviceManager::setBlocked($activationId, false, 'test-admin') === true);
dm_check('Blocked flag clears after unblock', DeviceManager::isBlocked($key, $hwid) === false);

$event->execute([$licenseId]);
$unblockedEvent = $event->fetch();
dm_check('Unblocking writes a device_unblocked subscription event', ($unblockedEvent['event_type'] ?? null) === 'device_unblocked');

dm_check('Blocking a missing activation returns false', DeviceManager::setBlocked(999999, true, 'test-admin') === false);

if ($failures) {
    echo "\n" . count($failures) . " TEST(S) FAILED\n";
    exit(1);
}

echo "\nDEVICE MANAGER TESTS PASSED\n";
