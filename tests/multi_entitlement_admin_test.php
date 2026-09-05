<?php
date_default_timezone_set('UTC');
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';

require_once __DIR__ . '/../includes/Database.php';
require_once __DIR__ . '/../includes/License.php';
require_once __DIR__ . '/../includes/EntitlementV2.php';
require_once __DIR__ . '/../includes/MultiEntitlementPolicy.php';
require_once __DIR__ . '/../includes/MultiEntitlementAdmin.php';

$failures = [];
function g1_admin_check(string $label, bool $ok): void {
    global $failures;
    echo ($ok ? '[PASS] ' : '[FAIL] ') . $label . "\n";
    if (!$ok) $failures[] = $label;
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
$pdo->exec('CREATE UNIQUE INDEX uq_admin_test_license_uuid ON licenses(license_uuid)');
$pdo->exec('CREATE UNIQUE INDEX uq_admin_test_device_uuid ON license_activations(device_uuid)');

$pdo->prepare('INSERT INTO customers (name,email) VALUES (?,?)')->execute(['Admin G1','admin-g1@example.com']);
$customerId = (int) $pdo->lastInsertId();
$license = License::issue($customerId, 'annual', 1, 'Fix408 admin G1');
$licenseId = (int) $license['id'];
$key = (string) $license['license_key'];
$storeUuid = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
$pdo->prepare('UPDATE licenses SET license_uuid=?, store_uuid=?, features_json=? WHERE id=?')
    ->execute(['bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', $storeUuid, '{"multi_cashier":false,"offline_sale":true}', $licenseId]);

// Seed the existing single terminal as a v2-aware activation.
$pdo->prepare(
    'INSERT INTO license_activations
     (license_id,hwid,is_active,device_uuid,store_uuid,device_role,counts_as_terminal,paired_at)
     VALUES (?,?,1,?,?,?,1,CURRENT_TIMESTAMP)'
)->execute([
    $licenseId,
    'POS-ONE',
    'cccccccc-cccc-4ccc-8ccc-cccccccccccc',
    $storeUuid,
    'single_terminal',
]);

$blocked = MultiEntitlementPolicy::preflightActivation([
    'license_key' => $key,
    'hwid' => 'POS-TWO',
    'device_role' => 'cashier_terminal',
]);
g1_admin_check('second POS is blocked while multi_cashier=false', ($blocked['status'] ?? '') === 'multi_not_entitled');

$managementAllowed = MultiEntitlementPolicy::preflightActivation([
    'license_key' => $key,
    'hwid' => 'MANAGER-ONLY',
    'device_role' => 'management_only',
]);
g1_admin_check('management-only device is not treated as a POS terminal', ($managementAllowed['ok'] ?? false) === true);

$enabled = MultiEntitlementAdmin::update($licenseId, true, 2, 1, 'tester');
g1_admin_check('admin can enable Multi-Cashier with two terminal seats', (int) $enabled['multi_cashier'] === 1 && (int) $enabled['max_terminals'] === 2);
g1_admin_check('legacy max_activations mirrors terminal seats', (int) $enabled['max_activations'] === 2);
$features = json_decode((string) $enabled['features_json'], true);
g1_admin_check('features_json exposes multi entitlement', is_array($features) && ($features['multi_cashier'] ?? false) === true);

$nowAllowed = MultiEntitlementPolicy::preflightActivation([
    'license_key' => $key,
    'hwid' => 'POS-TWO',
    'device_role' => 'cashier_terminal',
]);
g1_admin_check('second POS becomes eligible after entitlement upgrade', ($nowAllowed['ok'] ?? false) === true);

$pdo->prepare(
    'INSERT INTO license_activations
     (license_id,hwid,is_active,device_uuid,store_uuid,device_role,counts_as_terminal,paired_at)
     VALUES (?,?,1,?,?,?,1,CURRENT_TIMESTAMP)'
)->execute([
    $licenseId,
    'POS-TWO',
    'dddddddd-dddd-4ddd-8ddd-dddddddddddd',
    $storeUuid,
    'cashier_terminal',
]);

$reductionBlocked = false;
try {
    MultiEntitlementAdmin::update($licenseId, true, 1, 1, 'tester');
} catch (InvalidArgumentException $e) {
    $reductionBlocked = true;
}
g1_admin_check('cannot reduce terminal seats below active terminals', $reductionBlocked);

$disableBlocked = false;
try {
    MultiEntitlementAdmin::update($licenseId, false, 1, 1, 'tester');
} catch (InvalidArgumentException $e) {
    $disableBlocked = true;
}
g1_admin_check('cannot disable Multi while two terminals are active', $disableBlocked);

$pdo->prepare('UPDATE license_activations SET is_active=0 WHERE hwid=?')->execute(['POS-TWO']);
$disabled = MultiEntitlementAdmin::update($licenseId, false, 1, 1, 'tester');
g1_admin_check('Multi can be disabled after extra terminal is released', (int) $disabled['multi_cashier'] === 0 && (int) $disabled['max_terminals'] === 1);

// Static migration contract: preserve legacy >1 capacity and keep offline
// validity synchronized with expiry renewals.
$migration = file_get_contents(__DIR__ . '/../db/migrate_multi_entitlement_v2.php');
g1_admin_check('migration captures pre-column state before ADD COLUMN defaults', str_contains($migration, '$maxTerminalsWasMissing'));
g1_admin_check('first v2 migration preserves legacy max_activations capacity', str_contains($migration, 'GREATEST(1, max_activations)'));
g1_admin_check('legacy multi-device licenses become multi entitled', str_contains($migration, 'max_activations > 1 THEN 1'));
g1_admin_check('expiry changes refresh offline_valid_until', str_contains($migration, 'SET NEW.offline_valid_until = NEW.expires_at'));
g1_admin_check('entitlement trigger is refreshed idempotently', str_contains($migration, 'DROP TRIGGER IF EXISTS trg_licenses_entitlement_v2_bu'));

if ($failures) {
    fwrite(STDERR, 'Fix408 admin G1 failures: ' . implode(', ', $failures) . "\n");
    exit(1);
}

echo "PASS Fix408 Multi entitlement admin G1 — explicit-toggle=true, 1-to-2=true, reduction-guard=true, management-seat-separated=true, legacy-capacity-preserved=true, offline-expiry-sync=true\n";
