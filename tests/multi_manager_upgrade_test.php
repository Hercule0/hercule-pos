<?php
date_default_timezone_set('UTC');
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';

require_once __DIR__ . '/../includes/Database.php';
require_once __DIR__ . '/../includes/License.php';
require_once __DIR__ . '/../includes/EntitlementV2.php';
require_once __DIR__ . '/../includes/ManagerDeviceAuth.php';
require_once __DIR__ . '/../includes/MultiEntitlementPolicy.php';

$failures = [];
function f496u_check(string $label, bool $ok): void {
    global $failures;
    echo ($ok ? '[PASS] ' : '[FAIL] ') . $label . "\n";
    if (!$ok) $failures[] = $label;
}
function f496u_uuid(string $digit): string {
    return $digit.$digit.$digit.$digit.$digit.$digit.$digit.$digit.'-'.$digit.$digit.$digit.$digit.'-4'.$digit.$digit.$digit.'-8'.$digit.$digit.$digit.'-'.$digit.$digit.$digit.$digit.$digit.$digit.$digit.$digit.$digit.$digit.$digit.$digit;
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec(file_get_contents(__DIR__ . '/../db/schema.sqlite.test.sql'));
Database::setTestInstance($pdo);
foreach ([
    "ALTER TABLE licenses ADD COLUMN license_uuid TEXT",
    "ALTER TABLE licenses ADD COLUMN store_uuid TEXT",
    "ALTER TABLE licenses ADD COLUMN multi_cashier INTEGER NOT NULL DEFAULT 0",
    "ALTER TABLE licenses ADD COLUMN max_terminals INTEGER NOT NULL DEFAULT 1",
    "ALTER TABLE licenses ADD COLUMN max_management_devices INTEGER NOT NULL DEFAULT 1",
    "ALTER TABLE licenses ADD COLUMN features_json TEXT",
    "ALTER TABLE licenses ADD COLUMN entitlement_version INTEGER NOT NULL DEFAULT 1",
    "ALTER TABLE licenses ADD COLUMN offline_valid_until TEXT",
    "ALTER TABLE license_activations ADD COLUMN app_version TEXT",
    "ALTER TABLE license_activations ADD COLUMN device_uuid TEXT",
    "ALTER TABLE license_activations ADD COLUMN store_uuid TEXT",
    "ALTER TABLE license_activations ADD COLUMN device_role TEXT NOT NULL DEFAULT 'single_terminal'",
    "ALTER TABLE license_activations ADD COLUMN counts_as_terminal INTEGER NOT NULL DEFAULT 1",
    "ALTER TABLE license_activations ADD COLUMN certificate_fingerprint TEXT",
    "ALTER TABLE license_activations ADD COLUMN paired_at TEXT",
    "ALTER TABLE license_activations ADD COLUMN revoked_at TEXT",
    "ALTER TABLE license_activations ADD COLUMN revoked_by TEXT",
    "ALTER TABLE license_activations ADD COLUMN revoke_reason TEXT",
] as $sql) $pdo->exec($sql);
$pdo->exec('CREATE UNIQUE INDEX uq_fix496u_license_uuid ON licenses(license_uuid)');
$pdo->exec('CREATE UNIQUE INDEX uq_fix496u_device_uuid ON license_activations(device_uuid)');

$pdo->prepare('INSERT INTO customers (name,email) VALUES (?,?)')->execute(['Fix496U','fix496u@example.com']);
$customerId = (int) $pdo->lastInsertId();
$license = License::issue($customerId, 'annual', 1, 'single-before-multi');
$licenseId = (int) $license['id'];
$key = (string) $license['license_key'];
$storeUuid = f496u_uuid('1');
$deviceUuid = f496u_uuid('2');
$pdo->prepare('UPDATE licenses SET license_uuid=?,multi_cashier=0,max_terminals=1,max_management_devices=1,features_json=? WHERE id=?')
    ->execute([f496u_uuid('3'), '{"multi_cashier":false}', $licenseId]);

$singleRequest = [
    'license_key'=>$key,
    'hwid'=>'FIX496U-MAIN',
    'store_uuid'=>$storeUuid,
    'device_uuid'=>$deviceUuid,
    'device_role'=>'single_terminal',
];
$singlePolicy = MultiEntitlementPolicy::preflightActivation($singleRequest);
f496u_check('single POS activation remains allowed before Multi upgrade', ($singlePolicy['ok'] ?? false) === true);
$singleActivation = EntitlementV2::activate($singleRequest);
f496u_check('single POS owns the original Store UUID', ($singleActivation['ok'] ?? false) === true);

$blockedManagerBeforeEntitlement = $singleRequest;
$blockedManagerBeforeEntitlement['device_role'] = 'manager_server';
$beforePolicy = MultiEntitlementPolicy::preflightActivation($blockedManagerBeforeEntitlement);
f496u_check('manager_server cannot be created while Multi entitlement is disabled', ($beforePolicy['ok'] ?? true) === false && ($beforePolicy['status'] ?? '') === 'multi_not_entitled');

// Customer upgrades the same license from Single POS to Multi.
$pdo->prepare('UPDATE licenses SET multi_cashier=1,max_terminals=3,max_management_devices=2,features_json=? WHERE id=?')
    ->execute(['{"multi_cashier":true}', $licenseId]);

$promotionRequest = $singleRequest;
$promotionRequest['device_role'] = 'manager_server';
$promotionPolicy = MultiEntitlementPolicy::preflightActivation($promotionRequest);
f496u_check('exact registered single terminal is recognized as secure Multi upgrade device', ($promotionPolicy['ok'] ?? false) === true && ($promotionPolicy['legacy_manager_promotion'] ?? false) === true);
$promoted = EntitlementV2::activate($promotionRequest);
f496u_check('legacy main device promotes to manager_server without changing store identity', ($promoted['ok'] ?? false) === true);
$promoted = ManagerDeviceAuth::maybeIssueForActivation($promotionRequest, $promoted);
f496u_check('promoted manager receives one-time manager capability', (bool) preg_match('/^hma1_[a-f0-9]{64}$/', (string) ($promoted['manager_auth_token'] ?? '')));

$row = $pdo->query("SELECT device_role,counts_as_terminal,store_uuid,device_uuid FROM license_activations WHERE license_id={$licenseId} AND hwid='FIX496U-MAIN'")->fetch();
f496u_check('promotion updates same activation row to manager_server', ($row['device_role'] ?? '') === 'manager_server' && (int) ($row['counts_as_terminal'] ?? 1) === 0);
f496u_check('promotion preserves exact store/device UUIDs', strtolower((string) ($row['store_uuid'] ?? '')) === $storeUuid && strtolower((string) ($row['device_uuid'] ?? '')) === $deviceUuid);

$intruder = [
    'license_key'=>$key,
    'hwid'=>'FIX496U-INTRUDER',
    'store_uuid'=>$storeUuid,
    'device_uuid'=>f496u_uuid('4'),
    'device_role'=>'manager_server',
];
$intruderPolicy = MultiEntitlementPolicy::preflightActivation($intruder);
f496u_check('different device cannot claim a second manager_server after upgrade', ($intruderPolicy['ok'] ?? true) === false && ($intruderPolicy['status'] ?? '') === 'manager_server_already_established');

if ($failures) {
    fwrite(STDERR, 'Fix496 upgrade failures: ' . implode(', ', $failures) . "\n");
    exit(1);
}
echo "PASS Fix496 secure Single-POS -> Multi upgrade — exact main device promoted, store identity preserved, second manager_server blocked\n";
