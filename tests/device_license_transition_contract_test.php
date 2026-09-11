<?php
date_default_timezone_set('UTC');
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';

require_once __DIR__ . '/../includes/Database.php';
require_once __DIR__ . '/../includes/License.php';
require_once __DIR__ . '/../includes/EntitlementV2.php';
require_once __DIR__ . '/../includes/DeviceLicenseTransition.php';

$failures = [];
function f496t_check(string $label, bool $ok): void {
    global $failures;
    echo ($ok ? '[PASS] ' : '[FAIL] ') . $label . "\n";
    if (!$ok) $failures[] = $label;
}
function f496t_uuid(string $digit): string {
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
$pdo->exec('CREATE UNIQUE INDEX uq_fix496t_license_uuid ON licenses(license_uuid)');
$pdo->exec('CREATE UNIQUE INDEX uq_fix496t_device_uuid ON license_activations(device_uuid)');

$pdo->prepare('INSERT INTO customers (name,email) VALUES (?,?)')->execute(['Fix496T','fix496t@example.com']);
$customerId = (int) $pdo->lastInsertId();
$source = License::issue($customerId, 'annual', 3, 'source');
$target = License::issue($customerId, 'annual', 3, 'target');
$sourceId = (int) $source['id'];
$targetId = (int) $target['id'];
$sourceKey = (string) $source['license_key'];
$targetKey = (string) $target['license_key'];
$sourceStore = f496t_uuid('1');
$targetStore = f496t_uuid('2');
$deviceUuid = f496t_uuid('3');
$managerUuid = f496t_uuid('4');

$pdo->prepare('UPDATE licenses SET license_uuid=?,multi_cashier=1,max_terminals=3,max_management_devices=2,features_json=? WHERE id=?')
    ->execute([f496t_uuid('5'), '{"multi_cashier":true}', $sourceId]);
$pdo->prepare('UPDATE licenses SET license_uuid=?,multi_cashier=1,max_terminals=3,max_management_devices=2,features_json=? WHERE id=?')
    ->execute([f496t_uuid('6'), '{"multi_cashier":true}', $targetId]);

EntitlementV2::activate([
    'license_key'=>$targetKey,
    'hwid'=>'FIX496T-MANAGER',
    'store_uuid'=>$targetStore,
    'device_uuid'=>$managerUuid,
    'device_role'=>'manager_server',
]);

$missingSource = DeviceLicenseTransition::transition([
    'source_license_key'=>$sourceKey,
    'target_license_key'=>$targetKey,
    'hwid'=>'FIX496T-NOT-IN-SOURCE',
    'store_uuid'=>$targetStore,
    'device_uuid'=>f496t_uuid('7'),
    'device_role'=>'cashier_terminal',
]);
f496t_check('explicit source license must actually own historical device activation', ($missingSource['ok'] ?? true) === false && ($missingSource['status'] ?? '') === 'source_activation_missing');

EntitlementV2::activate([
    'license_key'=>$sourceKey,
    'hwid'=>'FIX496T-CASHIER',
    'store_uuid'=>$sourceStore,
    'device_uuid'=>$deviceUuid,
    'device_role'=>'cashier_terminal',
]);

$selfPromote = DeviceLicenseTransition::transition([
    'source_license_key'=>$sourceKey,
    'target_license_key'=>$targetKey,
    'hwid'=>'FIX496T-CASHIER',
    'store_uuid'=>$targetStore,
    'device_uuid'=>$deviceUuid,
    'device_role'=>'manager_terminal',
]);
f496t_check('transition cannot self-promote cashier into manager role', ($selfPromote['ok'] ?? true) === false && ($selfPromote['status'] ?? '') === 'manager_authorization_required');

$moved = DeviceLicenseTransition::transition([
    'source_license_key'=>$sourceKey,
    'target_license_key'=>$targetKey,
    'hwid'=>'FIX496T-CASHIER',
    'store_uuid'=>$targetStore,
    'device_uuid'=>$deviceUuid,
    'device_role'=>'cashier_terminal',
]);
$transitionId = (string) ($moved['transition_id'] ?? '');
f496t_check('strict source-to-target transition succeeds', ($moved['ok'] ?? false) === true);
f496t_check('successful transition reports final source release', ($moved['source_released'] ?? false) === true);
f496t_check('successful transition exposes stable opaque transition id', (bool) preg_match('/^[a-f0-9]{32}$/', $transitionId));
f496t_check('first transition is not marked duplicate', ($moved['duplicate'] ?? true) === false);

$retry = DeviceLicenseTransition::transition([
    'source_license_key'=>$sourceKey,
    'target_license_key'=>$targetKey,
    'hwid'=>'FIX496T-CASHIER',
    'store_uuid'=>$targetStore,
    'device_uuid'=>$deviceUuid,
    'device_role'=>'cashier_terminal',
]);
f496t_check('transition retry remains idempotent', ($retry['ok'] ?? false) === true && ($retry['duplicate'] ?? false) === true);
f496t_check('idempotent retry still confirms source is released', ($retry['source_released'] ?? false) === true);
f496t_check('idempotent retry keeps same transition id', hash_equals($transitionId, (string) ($retry['transition_id'] ?? '')));

if ($failures) {
    fwrite(STDERR, 'Fix496 transition contract failures: ' . implode(', ', $failures) . "\n");
    exit(1);
}
echo "PASS Fix496 transition contract — source ownership enforced, manager self-promotion blocked, signed-result fields deterministic\n";
