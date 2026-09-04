<?php
date_default_timezone_set('UTC');
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';

require_once __DIR__ . '/../includes/Database.php';
require_once __DIR__ . '/../includes/License.php';
require_once __DIR__ . '/../includes/EntitlementV2.php';

$failures = [];
function g1_check(string $label, bool $ok): void {
    global $failures;
    echo ($ok ? '[PASS] ' : '[FAIL] ') . $label . "\n";
    if (!$ok) $failures[] = $label;
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec(file_get_contents(__DIR__ . '/../db/schema.sqlite.test.sql'));
Database::setTestInstance($pdo);

// Mirror the production Fix408 migration in SQLite for the business-logic test.
foreach ([
    "ALTER TABLE licenses ADD COLUMN license_uuid TEXT",
    "ALTER TABLE licenses ADD COLUMN store_uuid TEXT",
    "ALTER TABLE licenses ADD COLUMN multi_cashier INTEGER NOT NULL DEFAULT 0",
    "ALTER TABLE licenses ADD COLUMN max_terminals INTEGER NOT NULL DEFAULT 1",
    "ALTER TABLE licenses ADD COLUMN max_management_devices INTEGER NOT NULL DEFAULT 1",
    "ALTER TABLE licenses ADD COLUMN features_json TEXT",
    "ALTER TABLE licenses ADD COLUMN entitlement_version INTEGER NOT NULL DEFAULT 1",
    "ALTER TABLE licenses ADD COLUMN offline_valid_until TEXT",
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
$pdo->exec('CREATE UNIQUE INDEX uq_test_license_uuid ON licenses(license_uuid)');
$pdo->exec('CREATE UNIQUE INDEX uq_test_device_uuid ON license_activations(device_uuid)');

$pdo->prepare('INSERT INTO customers (name,email) VALUES (?,?)')->execute(['Multi G1','g1@example.com']);
$customerId = (int) $pdo->lastInsertId();
$license = License::issue($customerId, 'annual', 1, 'Fix408 G1');
$licenseId = (int) $license['id'];
$key = (string) $license['license_key'];
$licenseUuid = '11111111-1111-4111-8111-111111111111';
$storeUuid = '22222222-2222-4222-8222-222222222222';
$pdo->prepare("UPDATE licenses SET license_uuid=?, max_terminals=1, max_management_devices=1, features_json=? WHERE id=?")
    ->execute([$licenseUuid, '{"multi_cashier":true,"offline_sale":true}', $licenseId]);

// Exact bug from Phase 1: inactive old HWID must not jump back into a full seat.
$old = License::activate($key, 'OLD-HWID');
g1_check('legacy first activation succeeds', $old['ok'] === true);
$oldId = (int) License::activationsFor($licenseId)[0]['id'];
License::deactivateDevice($oldId);
$new = License::activate($key, 'CURRENT-HWID');
g1_check('replacement occupant fills the only legacy seat', $new['ok'] === true);
$legacyPreflight = EntitlementV2::preflightLegacyActivation($key, 'OLD-HWID');
g1_check('inactive legacy HWID cannot bypass a full seat', ($legacyPreflight['ok'] ?? true) === false);

// A manager_server is a management seat, not a POS terminal seat.
$managerUuid = '33333333-3333-4333-8333-333333333333';
$manager = EntitlementV2::activate([
    'license_key' => $key,
    'hwid' => 'MANAGER-HWID',
    'store_uuid' => $storeUuid,
    'device_uuid' => $managerUuid,
    'device_role' => 'manager_server',
]);
g1_check('manager server uses management seat independently', ($manager['ok'] ?? false) === true);
g1_check('first v2 activation binds existing Desktop store UUID', ($manager['entitlement']['store_uuid'] ?? '') === $storeUuid);

// Upgrade the existing v1 terminal identity in place, then prove second terminal is blocked.
$currentUuid = '44444444-4444-4444-8444-444444444444';
$currentVal = EntitlementV2::validate([
    'license_key' => $key,
    'hwid' => 'CURRENT-HWID',
    'store_uuid' => $storeUuid,
    'device_uuid' => $currentUuid,
]);
g1_check('v1 terminal upgrades to v2 identity without consuming another seat', ($currentVal['ok'] ?? false) === true);

$blockedSecond = EntitlementV2::activate([
    'license_key' => $key,
    'hwid' => 'SECOND-HWID',
    'store_uuid' => $storeUuid,
    'device_uuid' => '55555555-5555-4555-8555-555555555555',
    'device_role' => 'cashier_terminal',
]);
g1_check('second terminal is blocked at max_terminals=1', ($blockedSecond['status'] ?? '') === 'terminal_limit');

$revoked = EntitlementV2::revokeDevice([
    'license_key' => $key,
    'requester_hwid' => 'MANAGER-HWID',
    'device_uuid' => $currentUuid,
    'reason' => 'G1 final revoke test',
]);
g1_check('manager can final-revoke a terminal', ($revoked['ok'] ?? false) === true);
$revokedPreflight = EntitlementV2::preflightLegacyActivation($key, 'CURRENT-HWID');
g1_check('final-revoked device cannot return through legacy v1', ($revokedPreflight['ok'] ?? true) === false);

$terminal2Uuid = '66666666-6666-4666-8666-666666666666';
$terminal2 = EntitlementV2::activate([
    'license_key' => $key,
    'hwid' => 'SECOND-HWID',
    'store_uuid' => $storeUuid,
    'device_uuid' => $terminal2Uuid,
    'device_role' => 'cashier_terminal',
]);
g1_check('revocation releases terminal seat for a new device', ($terminal2['ok'] ?? false) === true);

$versionBefore = (int) EntitlementV2::entitlementByKey($key)['entitlement_version'];
$replacementUuid = '77777777-7777-4777-8777-777777777777';
$replace = EntitlementV2::replaceDevice([
    'license_key' => $key,
    'requester_hwid' => 'MANAGER-HWID',
    'old_device_uuid' => $terminal2Uuid,
    'new_hwid' => 'THIRD-HWID',
    'new_device_uuid' => $replacementUuid,
    'device_role' => 'cashier_terminal',
    'reason' => 'hardware replacement',
]);
g1_check('manager device replacement is atomic at seat level', ($replace['ok'] ?? false) === true);
$oldAfter = $pdo->prepare('SELECT is_active,revoked_at FROM license_activations WHERE device_uuid=?')->execute([$terminal2Uuid]);
$oldRow = $pdo->query("SELECT is_active,revoked_at FROM license_activations WHERE device_uuid='{$terminal2Uuid}'")->fetch();
g1_check('replaced old device is inactive and permanently revoked', (int) $oldRow['is_active'] === 0 && !empty($oldRow['revoked_at']));
$newRow = $pdo->query("SELECT is_active FROM license_activations WHERE device_uuid='{$replacementUuid}'")->fetch();
g1_check('replacement device is the active seat holder', (int) $newRow['is_active'] === 1);
$versionAfter = (int) EntitlementV2::entitlementByKey($key)['entitlement_version'];
g1_check('device lifecycle advances entitlement_version', $versionAfter > $versionBefore);

$ent = EntitlementV2::entitlementByKey($key);
g1_check('signed entitlement contract exposes schema v2', (int) $ent['schema_version'] === 2);
g1_check('entitlement exposes max terminal and management seats', (int) $ent['max_terminals'] === 1 && (int) $ent['max_management_devices'] === 1);
g1_check('entitlement preserves license/store UUIDs', $ent['license_uuid'] === $licenseUuid && $ent['store_uuid'] === $storeUuid);

if ($failures) {
    fwrite(STDERR, 'Fix408 G1 failures: ' . implode(', ', $failures) . "\n");
    exit(1);
}
echo "PASS Fix408 Multi entitlement v2 G1 — legacy-seat-bypass=blocked, store-binding=true, terminal-seat=true, management-seat=true, revoke-final=true, replace=true\n";
