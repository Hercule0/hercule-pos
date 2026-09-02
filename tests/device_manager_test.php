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

// Mirror production device-management + MC-001 lifecycle migrations for
// the SQLite regression DB.
$pdo->exec('ALTER TABLE license_activations ADD COLUMN device_name TEXT NULL');
$pdo->exec('ALTER TABLE license_activations ADD COLUMN admin_note TEXT NULL');
$pdo->exec('ALTER TABLE license_activations ADD COLUMN app_version TEXT NULL');
$pdo->exec('ALTER TABLE license_activations ADD COLUMN is_blocked INTEGER NOT NULL DEFAULT 0');
$pdo->exec('ALTER TABLE license_activations ADD COLUMN blocked_at TEXT NULL');
$pdo->exec('ALTER TABLE license_activations ADD COLUMN blocked_by TEXT NULL');
$pdo->exec('ALTER TABLE license_activations ADD COLUMN deactivated_at TEXT NULL');
$pdo->exec('ALTER TABLE license_activations ADD COLUMN deactivated_by TEXT NULL');
$pdo->exec('ALTER TABLE license_activations ADD COLUMN revoked_at TEXT NULL');
$pdo->exec('ALTER TABLE license_activations ADD COLUMN revoked_by TEXT NULL');
$pdo->exec('ALTER TABLE license_activations ADD COLUMN replaced_at TEXT NULL');
$pdo->exec('ALTER TABLE license_activations ADD COLUMN replaced_by TEXT NULL');
Database::setTestInstance($pdo);

dm_check('MC-001 seat-safety lifecycle schema is detected', DeviceManager::seatSafetySchemaReady() === true);

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

DeviceManager::recordClientVersion($key, $hwid, 'v2.5.7');
$row = DeviceManager::findByLicenseAndHwid($key, $hwid);
dm_check('Client version recording is portable and persisted', ($row['app_version'] ?? null) === 'v2.5.7');

$activationId = (int) ($row['id'] ?? 0);
$beforeNotifications = (int)$pdo->query("SELECT COUNT(*) FROM license_change_notifications WHERE license_key = " . $pdo->quote($key))->fetchColumn();
dm_check('Blocking an existing activation succeeds', DeviceManager::setBlocked($activationId, true, 'test-admin') === true);
dm_check('Blocked flag is visible through DeviceManager', DeviceManager::isBlocked($key, $hwid) === true);

$event = $pdo->prepare("SELECT event_type, created_by FROM subscription_events WHERE license_id = ? ORDER BY id DESC LIMIT 1");
$event->execute([$licenseId]);
$blockedEvent = $event->fetch();
dm_check('Blocking writes a device_blocked subscription event', ($blockedEvent['event_type'] ?? null) === 'device_blocked');
dm_check('Blocking event records the acting administrator', ($blockedEvent['created_by'] ?? null) === 'test-admin');

$afterBlockNotifications = (int)$pdo->query("SELECT COUNT(*) FROM license_change_notifications WHERE license_key = " . $pdo->quote($key))->fetchColumn();
dm_check('Blocking emits a realtime license-change marker', $afterBlockNotifications === $beforeNotifications + 1);

dm_check('Unblocking the activation succeeds', DeviceManager::setBlocked($activationId, false, 'test-admin') === true);
dm_check('Blocked flag clears after unblock', DeviceManager::isBlocked($key, $hwid) === false);

$event->execute([$licenseId]);
$unblockedEvent = $event->fetch();
dm_check('Unblocking writes a device_unblocked subscription event', ($unblockedEvent['event_type'] ?? null) === 'device_unblocked');

$afterUnblockNotifications = (int)$pdo->query("SELECT COUNT(*) FROM license_change_notifications WHERE license_key = " . $pdo->quote($key))->fetchColumn();
dm_check('Unblocking emits a realtime license-change marker', $afterUnblockNotifications === $afterBlockNotifications + 1);

dm_check('Blocking a missing activation returns false', DeviceManager::setBlocked(999999, true, 'test-admin') === false);

// =====================================================================
// MC-001 regression: old inactive HWID must NOT bypass max_activations.
// =====================================================================
$hwid2 = 'REGRESSION-HWID-002';
$hwid3 = 'REGRESSION-HWID-003';
$act2 = License::activate($key, $hwid2, '10.0.0.2');
dm_check('Second seat activates normally', $act2['ok'] === true);

$row1 = DeviceManager::findByLicenseAndHwid($key, $hwid);
$row2 = DeviceManager::findByLicenseAndHwid($key, $hwid2);

dm_check(
    'Temporary deactivation frees the first seat',
    DeviceManager::deactivateDevice((int)$row1['id'], 'test-admin') === true
);
$row1AfterDeactivate = DeviceManager::findByLicenseAndHwid($key, $hwid);
dm_check('Temporary deactivation records lifecycle timestamp', !empty($row1AfterDeactivate['deactivated_at']));
dm_check('Temporary deactivation is not a revoke', empty($row1AfterDeactivate['revoked_at']));
dm_check('Temporary deactivation is not a replacement', empty($row1AfterDeactivate['replaced_at']));

$act3 = License::activate($key, $hwid3, '10.0.0.3');
dm_check('Replacement/new device can consume the freed seat', $act3['ok'] === true);

$oldReturnWhileFull = DeviceManager::activateExistingSafely($key, $hwid, '10.0.0.1');
dm_check(
    'Old inactive HWID is rejected when its former seat is already occupied',
    is_array($oldReturnWhileFull)
    && $oldReturnWhileFull['ok'] === false
    && ($oldReturnWhileFull['code'] ?? '') === 'activation_limit'
);
$row1StillInactive = DeviceManager::findByLicenseAndHwid($key, $hwid);
dm_check('Rejected old HWID remains inactive', (int)$row1StillInactive['is_active'] === 0);

$row3 = DeviceManager::findByLicenseAndHwid($key, $hwid3);
dm_check(
    'Freeing a seat permits a temporary-deactivated HWID to return',
    DeviceManager::deactivateDevice((int)$row3['id'], 'test-admin') === true
);
$oldReturnWithSeat = DeviceManager::activateExistingSafely($key, $hwid, '10.0.0.1');
dm_check(
    'Temporary-deactivated HWID reactivates when a seat is genuinely available',
    is_array($oldReturnWithSeat) && $oldReturnWithSeat['ok'] === true
);
$row1Reactivated = DeviceManager::findByLicenseAndHwid($key, $hwid);
dm_check('Successful safe reactivation clears deactivated lifecycle fields', empty($row1Reactivated['deactivated_at']));

// =====================================================================
// Final revoke cannot be undone by a normal activation or by unblock.
// =====================================================================
dm_check('Final device revoke succeeds', DeviceManager::revokeDevice((int)$row1Reactivated['id'], 'test-admin') === true);
$revokedRow = DeviceManager::findByLicenseAndHwid($key, $hwid);
dm_check('Final revoke makes the device inactive', (int)$revokedRow['is_active'] === 0);
dm_check('Final revoke records revoked_at', !empty($revokedRow['revoked_at']));

dm_check('Unblock operation can still clear security block flag', DeviceManager::setBlocked((int)$revokedRow['id'], false, 'test-admin') === true);
$revokedReturn = DeviceManager::activateExistingSafely($key, $hwid, '10.0.0.1');
dm_check(
    'Revoked HWID cannot reactivate even after unblock',
    is_array($revokedReturn)
    && $revokedReturn['ok'] === false
    && ($revokedReturn['code'] ?? '') === 'device_revoked'
);

// =====================================================================
// Replacement permanently retires old HWID while new device uses seat.
// =====================================================================
$replaceLicense = License::issue($customerId, 'annual', 1, 'Replacement workflow regression');
$replaceKey = $replaceLicense['license_key'];
$oldHwid = 'REPLACE-OLD-HWID';
$newHwid = 'REPLACE-NEW-HWID';
$oldAct = License::activate($replaceKey, $oldHwid, '10.1.0.1');
dm_check('Replacement test old device activates', $oldAct['ok'] === true);
$oldRow = DeviceManager::findByLicenseAndHwid($replaceKey, $oldHwid);

dm_check(
    'Preparing replacement retires old HWID and frees seat',
    DeviceManager::prepareReplacement((int)$oldRow['id'], 'test-admin') === true
);
$oldReplacedRow = DeviceManager::findByLicenseAndHwid($replaceKey, $oldHwid);
dm_check('Replacement lifecycle marker is persisted', !empty($oldReplacedRow['replaced_at']));
dm_check('Replaced device is inactive', (int)$oldReplacedRow['is_active'] === 0);

$newAct = License::activate($replaceKey, $newHwid, '10.1.0.2');
dm_check('New replacement HWID consumes the freed seat', $newAct['ok'] === true);

$oldReplacementReturn = DeviceManager::activateExistingSafely($replaceKey, $oldHwid, '10.1.0.1');
dm_check(
    'Replaced old HWID cannot return after new HWID fills the seat',
    is_array($oldReplacementReturn)
    && $oldReplacementReturn['ok'] === false
    && ($oldReplacementReturn['code'] ?? '') === 'device_replaced'
);

$replaceActiveCountStmt = $pdo->prepare(
    'SELECT COUNT(*) FROM license_activations WHERE license_id = ? AND is_active = 1'
);
$replaceActiveCountStmt->execute([(int)$replaceLicense['id']]);
dm_check('Replacement workflow never exceeds max_activations=1', (int)$replaceActiveCountStmt->fetchColumn() === 1);

if ($failures) {
    echo "\n" . count($failures) . " TEST(S) FAILED\n";
    exit(1);
}

echo "\nDEVICE MANAGER + MC-001 SEAT SAFETY TESTS PASSED\n";
