<?php
date_default_timezone_set('UTC');
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';

require_once __DIR__ . '/../includes/Database.php';
require_once __DIR__ . '/../includes/License.php';
require_once __DIR__ . '/../includes/EntitlementV2.php';
require_once __DIR__ . '/../includes/DeviceLicenseTransition.php';

$failures = [];
function f495_check(string $label, bool $ok): void {
    global $failures;
    echo ($ok ? '[PASS] ' : '[FAIL] ') . $label . "\n";
    if (!$ok) $failures[] = $label;
}
function f495_uuid(string $digit): string { return $digit.$digit.$digit.$digit.$digit.$digit.$digit.$digit.'-'.$digit.$digit.$digit.$digit.'-4'.$digit.$digit.$digit.'-8'.$digit.$digit.$digit.'-'.$digit.$digit.$digit.$digit.$digit.$digit.$digit.$digit.$digit.$digit.$digit.$digit; }

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
$pdo->exec('CREATE UNIQUE INDEX uq_fix495_license_uuid ON licenses(license_uuid)');
$pdo->exec('CREATE UNIQUE INDEX uq_fix495_device_uuid ON license_activations(device_uuid)');

$pdo->prepare('INSERT INTO customers (name,email) VALUES (?,?)')->execute(['Fix495','fix495@example.com']);
$customerId = (int) $pdo->lastInsertId();
$source = License::issue($customerId, 'annual', 3, 'source');
$target = License::issue($customerId, 'annual', 3, 'target');
$sourceKey = (string) $source['license_key'];
$targetKey = (string) $target['license_key'];
$sourceId = (int) $source['id'];
$targetId = (int) $target['id'];
$sourceStore = f495_uuid('1');
$targetStore = f495_uuid('2');
$device = f495_uuid('3');
$manager = f495_uuid('4');
$pdo->prepare('UPDATE licenses SET license_uuid=?,multi_cashier=1,max_terminals=3,max_management_devices=1,features_json=? WHERE id=?')->execute([f495_uuid('5'),'{"multi_cashier":true}', $sourceId]);
$pdo->prepare('UPDATE licenses SET license_uuid=?,multi_cashier=1,max_terminals=3,max_management_devices=1,features_json=? WHERE id=?')->execute([f495_uuid('6'),'{"multi_cashier":true}', $targetId]);

$sourceAct = EntitlementV2::activate(['license_key'=>$sourceKey,'hwid'=>'FIX495-HWID','store_uuid'=>$sourceStore,'device_uuid'=>$device,'device_role'=>'cashier_terminal']);
f495_check('source device initially active', ($sourceAct['ok'] ?? false) === true);
$managerAct = EntitlementV2::activate(['license_key'=>$targetKey,'hwid'=>'FIX495-MANAGER','store_uuid'=>$targetStore,'device_uuid'=>$manager,'device_role'=>'manager_server']);
f495_check('target store bound by manager server', ($managerAct['ok'] ?? false) === true);

$moved = DeviceLicenseTransition::transition([
    'source_license_key'=>$sourceKey,
    'target_license_key'=>$targetKey,
    'hwid'=>'FIX495-HWID',
    'store_uuid'=>$targetStore,
    'device_uuid'=>$device,
    'device_role'=>'cashier_terminal',
]);
f495_check('atomic source-to-target transition succeeds', ($moved['ok'] ?? false) === true && ($moved['source_released'] ?? false) === true);
$srcRow = $pdo->query("SELECT is_active,device_uuid,store_uuid FROM license_activations WHERE license_id={$sourceId} AND hwid='FIX495-HWID'")->fetch();
$tgtRow = $pdo->query("SELECT is_active,device_uuid,store_uuid,device_role FROM license_activations WHERE license_id={$targetId} AND hwid='FIX495-HWID'")->fetch();
f495_check('source seat released and global device UUID freed', (int)$srcRow['is_active'] === 0 && $srcRow['device_uuid'] === null && $srcRow['store_uuid'] === null);
f495_check('target seat owns exact store/device identity', (int)$tgtRow['is_active'] === 1 && strtolower((string)$tgtRow['device_uuid']) === $device && strtolower((string)$tgtRow['store_uuid']) === $targetStore && $tgtRow['device_role'] === 'cashier_terminal');

$retry = DeviceLicenseTransition::transition([
    'source_license_key'=>$sourceKey,
    'target_license_key'=>$targetKey,
    'hwid'=>'FIX495-HWID',
    'store_uuid'=>$targetStore,
    'device_uuid'=>$device,
    'device_role'=>'cashier_terminal',
]);
f495_check('transition retry is idempotent', ($retry['ok'] ?? false) === true && ($retry['duplicate'] ?? false) === true);
$countTarget = (int)$pdo->query("SELECT COUNT(*) FROM license_activations WHERE license_id={$targetId} AND hwid='FIX495-HWID'")->fetchColumn();
f495_check('retry does not duplicate target activation row', $countTarget === 1);

// Prove rollback: target2 has one terminal seat already occupied. Transition must
// fail without releasing the source device.
$source2 = License::issue($customerId, 'annual', 2, 'source2');
$target2 = License::issue($customerId, 'annual', 2, 'target2');
$source2Id=(int)$source2['id']; $target2Id=(int)$target2['id'];
$pdo->prepare('UPDATE licenses SET license_uuid=?,multi_cashier=1,max_terminals=2,max_management_devices=1,features_json=? WHERE id=?')->execute([f495_uuid('7'),'{"multi_cashier":true}', $source2Id]);
$pdo->prepare('UPDATE licenses SET license_uuid=?,multi_cashier=1,max_terminals=1,max_management_devices=1,features_json=? WHERE id=?')->execute([f495_uuid('8'),'{"multi_cashier":true}', $target2Id]);
$source2Store=f495_uuid('9'); $target2Store='aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
$blockedDevice='bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb'; $occupant='cccccccc-cccc-4ccc-8ccc-cccccccccccc';
EntitlementV2::activate(['license_key'=>$source2['license_key'],'hwid'=>'FIX495-BLOCKED','store_uuid'=>$source2Store,'device_uuid'=>$blockedDevice,'device_role'=>'cashier_terminal']);
EntitlementV2::activate(['license_key'=>$target2['license_key'],'hwid'=>'FIX495-OCCUPANT','store_uuid'=>$target2Store,'device_uuid'=>$occupant,'device_role'=>'cashier_terminal']);
$blocked = DeviceLicenseTransition::transition([
    'source_license_key'=>$source2['license_key'],'target_license_key'=>$target2['license_key'],'hwid'=>'FIX495-BLOCKED','store_uuid'=>$target2Store,'device_uuid'=>$blockedDevice,'device_role'=>'cashier_terminal'
]);
f495_check('full target seat rejects transition', ($blocked['ok'] ?? true) === false && ($blocked['status'] ?? '') === 'terminal_limit');
$source2Row=$pdo->query("SELECT is_active,device_uuid,store_uuid FROM license_activations WHERE license_id={$source2Id} AND hwid='FIX495-BLOCKED'")->fetch();
f495_check('failed transition rolls source release back atomically', (int)$source2Row['is_active'] === 1 && strtolower((string)$source2Row['device_uuid']) === $blockedDevice && strtolower((string)$source2Row['store_uuid']) === $source2Store);

if ($failures) { fwrite(STDERR, 'Fix495 failures: '.implode(', ', $failures)."\n"); exit(1); }
echo "PASS Fix495 device license transition — atomic move, idempotent retry, rollback-on-seat-limit=true\n";
