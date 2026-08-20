<?php
require_once __DIR__ . '/../includes/Database.php';
require_once __DIR__ . '/../includes/License.php';
require_once __DIR__ . '/../includes/LicenseLifecycle.php';

function lifecycle_check(string $label, bool $condition): void
{
    if (!$condition) {
        fwrite(STDERR, "[FAIL] {$label}\n");
        exit(1);
    }
    echo "[PASS] {$label}\n";
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec(file_get_contents(__DIR__ . '/../db/schema.sqlite.test.sql'));
Database::setTestInstance($pdo);

$pdo->prepare('INSERT INTO customers (name, email) VALUES (?, ?)')->execute(['Lifecycle A', 'a@example.com']);
$customerA = (int) $pdo->lastInsertId();
$pdo->prepare('INSERT INTO customers (name, email) VALUES (?, ?)')->execute(['Lifecycle B', 'b@example.com']);
$customerB = (int) $pdo->lastInsertId();

$license = License::issue($customerA, 'monthly', 2, 'Lifecycle test');
$id = (int) $license['id'];
$key = $license['license_key'];
$oldExpiry = strtotime($license['expires_at']);

$extended = LicenseLifecycle::extendDays($id, 15, 'tester');
lifecycle_check('Expiry extension adds time', strtotime($extended['expires_at']) > $oldExpiry);

$beforeAnnualChange = time();
$annual = LicenseLifecycle::changePlan($id, 'annual', 'tester');
$annualExpiry = strtotime($annual['expires_at']);
lifecycle_check('Plan changes without changing key', $annual['plan'] === 'annual' && $annual['license_key'] === $key);
lifecycle_check('Changing finite plan recalculates entitlement term', $annualExpiry >= strtotime('+364 days', $beforeAnnualChange));

$lifetime = LicenseLifecycle::changePlan($id, 'lifetime', 'tester');
lifecycle_check('Lifetime transition clears expiry', $lifetime['plan'] === 'lifetime' && $lifetime['expires_at'] === null);

$monthly = LicenseLifecycle::changePlan($id, 'monthly', 'tester');
lifecycle_check('Leaving lifetime recreates finite expiry', $monthly['plan'] === 'monthly' && $monthly['expires_at'] !== null);

License::activate($key, 'LIFE-HWID-1');
License::activate($key, 'LIFE-HWID-2');
$blockedReduction = false;
try {
    LicenseLifecycle::updateActivationLimit($id, 1, 'tester');
} catch (InvalidArgumentException $e) {
    $blockedReduction = true;
}
lifecycle_check('Cannot lower activation limit below active devices', $blockedReduction);

$raised = LicenseLifecycle::updateActivationLimit($id, 4, 'tester');
lifecycle_check('Activation limit can be increased', (int) $raised['max_activations'] === 4);

$transferred = LicenseLifecycle::transferCustomer($id, $customerB, 'tester');
lifecycle_check('License transfers to another customer', (int) $transferred['customer_id'] === $customerB);
lifecycle_check('Transfer preserves license key', $transferred['license_key'] === $key);

$duplicateTransferRejected = false;
try {
    LicenseLifecycle::transferCustomer($id, $customerB, 'tester');
} catch (InvalidArgumentException $e) {
    $duplicateTransferRejected = true;
}
lifecycle_check('Transfer to current customer is rejected', $duplicateTransferRejected);

$noted = LicenseLifecycle::updateNotes($id, 'Priority store', 'tester');
lifecycle_check('Internal notes can be updated', $noted['notes'] === 'Priority store');

$eventCount = (int) $pdo->query('SELECT COUNT(*) FROM subscription_events WHERE license_id = ' . $id)->fetchColumn();
lifecycle_check('Lifecycle changes create history events', $eventCount >= 6);

$changeCount = (int) $pdo->query("SELECT COUNT(*) FROM license_change_notifications WHERE license_key = " . $pdo->quote($key))->fetchColumn();
lifecycle_check('Client-relevant lifecycle changes create realtime markers', $changeCount >= 5);

echo "LICENSE LIFECYCLE TESTS PASSED\n";
