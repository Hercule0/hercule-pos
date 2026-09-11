<?php
date_default_timezone_set('UTC');

require_once __DIR__ . '/../includes/Database.php';
require_once __DIR__ . '/../includes/License.php';
require_once __DIR__ . '/../includes/EntitlementV2.php';
require_once __DIR__ . '/../includes/ManagerDeviceAuth.php';

$failures = [];
function f496ms_check(string $label, bool $ok): void {
    global $failures;
    echo ($ok ? '[PASS] ' : '[FAIL] ') . $label . "\n";
    if (!$ok) $failures[] = $label;
}
function f496ms_uuid(string $digit): string {
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
$pdo->exec('CREATE UNIQUE INDEX uq_fix496ms_license_uuid ON licenses(license_uuid)');
$pdo->exec('CREATE UNIQUE INDEX uq_fix496ms_device_uuid ON license_activations(device_uuid)');

$pdo->prepare('INSERT INTO customers (name,email) VALUES (?,?)')->execute(['Fix496MS','fix496ms@example.com']);
$customerId = (int) $pdo->lastInsertId();
$license = License::issue($customerId, 'annual', 3, 'manager-lifecycle');
$licenseId = (int) $license['id'];
$key = (string) $license['license_key'];
$store = f496ms_uuid('1');
$manager = f496ms_uuid('2');
$cashier = f496ms_uuid('3');

$pdo->prepare('UPDATE licenses SET license_uuid=?,multi_cashier=1,max_terminals=3,max_management_devices=2,features_json=? WHERE id=?')
    ->execute([f496ms_uuid('4'), '{"multi_cashier":true}', $licenseId]);

$managerResult = EntitlementV2::activate([
    'license_key'=>$key,
    'hwid'=>'FIX496MS-MANAGER',
    'store_uuid'=>$store,
    'device_uuid'=>$manager,
    'device_role'=>'manager_server',
]);
f496ms_check('manager server fixture activated', ($managerResult['ok'] ?? false) === true);

$cashierResult = EntitlementV2::activate([
    'license_key'=>$key,
    'hwid'=>'FIX496MS-CASHIER',
    'store_uuid'=>$store,
    'device_uuid'=>$cashier,
    'device_role'=>'cashier_terminal',
]);
f496ms_check('cashier fixture activated', ($cashierResult['ok'] ?? false) === true);

$revokeManager = ManagerDeviceAuth::preflightPermanentRevoke($key, $manager);
f496ms_check('manager server permanent revoke is blocked', ($revokeManager['ok'] ?? true) === false && ($revokeManager['status'] ?? '') === 'manager_server_replace_required');

$revokeCashier = ManagerDeviceAuth::preflightPermanentRevoke($key, $cashier);
f496ms_check('ordinary terminal may still be permanently revoked', ($revokeCashier['ok'] ?? false) === true);

$managerToCashier = ManagerDeviceAuth::preflightReplacementRole($key, $manager, 'cashier_terminal');
f496ms_check('manager server cannot be downgraded through replace', ($managerToCashier['ok'] ?? true) === false && ($managerToCashier['status'] ?? '') === 'manager_server_role_required');

$cashierToManager = ManagerDeviceAuth::preflightReplacementRole($key, $cashier, 'manager_server');
f496ms_check('cashier cannot be upgraded into manager server through replace', ($cashierToManager['ok'] ?? true) === false && ($cashierToManager['status'] ?? '') === 'manager_server_replacement_mismatch');

$managerToManager = ManagerDeviceAuth::preflightReplacementRole($key, $manager, 'manager_server');
f496ms_check('manager server may be atomically replaced by another manager server', ($managerToManager['ok'] ?? false) === true);

$cashierToManagerTerminal = ManagerDeviceAuth::preflightReplacementRole($key, $cashier, 'manager_terminal');
f496ms_check('replacement role guard does not block manager-authorized manager-terminal provisioning', ($cashierToManagerTerminal['ok'] ?? false) === true);

if ($failures) {
    fwrite(STDERR, 'Fix496 Manager Server lifecycle failures: ' . implode(', ', $failures) . "\n");
    exit(1);
}
echo "PASS Fix496 Manager Server lifecycle — no permanent revoke, no role injection/downgrade, replacement continuity=true\n";
