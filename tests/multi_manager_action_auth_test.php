<?php
date_default_timezone_set('UTC');
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';

require_once __DIR__ . '/../includes/Database.php';
require_once __DIR__ . '/../includes/License.php';
require_once __DIR__ . '/../includes/EntitlementV2.php';
require_once __DIR__ . '/../includes/ManagerDeviceAuth.php';
require_once __DIR__ . '/../includes/MultiEntitlementPolicy.php';

$failures = [];
function f496_check(string $label, bool $ok): void {
    global $failures;
    echo ($ok ? '[PASS] ' : '[FAIL] ') . $label . "\n";
    if (!$ok) $failures[] = $label;
}
function f496_uuid(string $digit): string {
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
$pdo->exec('CREATE UNIQUE INDEX uq_fix496_license_uuid ON licenses(license_uuid)');
$pdo->exec('CREATE UNIQUE INDEX uq_fix496_device_uuid ON license_activations(device_uuid)');

$pdo->prepare('INSERT INTO customers (name,email) VALUES (?,?)')->execute(['Fix496','fix496@example.com']);
$customerId = (int) $pdo->lastInsertId();
$license = License::issue($customerId, 'annual', 3, 'fix496');
$licenseId = (int) $license['id'];
$licenseKey = (string) $license['license_key'];
$storeUuid = f496_uuid('1');
$managerUuid = f496_uuid('2');
$cashierUuid = f496_uuid('3');
$manager2Uuid = f496_uuid('4');

$pdo->prepare(
    'UPDATE licenses SET license_uuid=?, multi_cashier=1, max_terminals=3, max_management_devices=2, features_json=? WHERE id=?'
)->execute([f496_uuid('5'), '{"multi_cashier":true,"offline_sale":true}', $licenseId]);

$managerRequest = [
    'license_key' => $licenseKey,
    'hwid' => 'FIX496-MANAGER',
    'store_uuid' => $storeUuid,
    'device_uuid' => $managerUuid,
    'device_role' => 'manager_server',
];
$managerPolicy = MultiEntitlementPolicy::preflightActivation($managerRequest);
f496_check('unused store permits one manager_server bootstrap', ($managerPolicy['ok'] ?? false) === true && ($managerPolicy['bootstrap_manager'] ?? false) === true);
$managerActivation = EntitlementV2::activate($managerRequest);
f496_check('manager_server bootstrap activation succeeds', ($managerActivation['ok'] ?? false) === true);
$managerActivation = ManagerDeviceAuth::maybeIssueForActivation($managerRequest, $managerActivation);
$managerToken = (string) ($managerActivation['manager_auth_token'] ?? '');
f496_check('manager capability issued exactly on trusted manager activation', (bool) preg_match('/^hma1_[a-f0-9]{64}$/', $managerToken));

$fingerprint = (string) $pdo->query("SELECT certificate_fingerprint FROM license_activations WHERE license_id={$licenseId} AND hwid='FIX496-MANAGER'")->fetchColumn();
f496_check('server stores only manager capability fingerprint', (bool) preg_match('/^[a-f0-9]{64}$/', $fingerprint) && !hash_equals($fingerprint, $managerToken));

$secondIssue = ManagerDeviceAuth::maybeIssueForActivation($managerRequest, $managerActivation);
f496_check('manager capability is one-time and is not re-exposed', !isset($secondIssue['manager_auth_token']) && ($secondIssue['manager_auth_token_issued'] ?? true) === false);

$cashierRequest = [
    'license_key' => $licenseKey,
    'hwid' => 'FIX496-CASHIER',
    'store_uuid' => $storeUuid,
    'device_uuid' => $cashierUuid,
    'device_role' => 'cashier_terminal',
];
f496_check('ordinary cashier activation remains allowed', (MultiEntitlementPolicy::preflightActivation($cashierRequest)['ok'] ?? false) === true);
$cashierActivation = EntitlementV2::activate($cashierRequest);
f496_check('cashier activation succeeds', ($cashierActivation['ok'] ?? false) === true);

$selfPromote = $cashierRequest;
$selfPromote['device_role'] = 'manager_terminal';
$selfPromotePolicy = MultiEntitlementPolicy::preflightActivation($selfPromote);
f496_check('cashier cannot self-promote to manager_terminal', ($selfPromotePolicy['ok'] ?? true) === false && ($selfPromotePolicy['status'] ?? '') === 'manager_authorization_required');

$secondServer = [
    'license_key' => $licenseKey,
    'hwid' => 'FIX496-SERVER2',
    'store_uuid' => $storeUuid,
    'device_uuid' => f496_uuid('6'),
    'device_role' => 'manager_server',
    'requester_hwid' => 'FIX496-MANAGER',
    'manager_auth_token' => $managerToken,
];
$secondServerPolicy = MultiEntitlementPolicy::preflightActivation($secondServer);
f496_check('second manager_server is blocked even with manager capability', ($secondServerPolicy['ok'] ?? true) === false && ($secondServerPolicy['status'] ?? '') === 'manager_server_already_established');

$newManagerRequest = [
    'license_key' => $licenseKey,
    'hwid' => 'FIX496-MANAGER2',
    'store_uuid' => $storeUuid,
    'device_uuid' => $manager2Uuid,
    'device_role' => 'manager_terminal',
];
$unauthorizedManagerPolicy = MultiEntitlementPolicy::preflightActivation($newManagerRequest);
f496_check('new manager_terminal requires existing-manager authorization', ($unauthorizedManagerPolicy['ok'] ?? true) === false && ($unauthorizedManagerPolicy['status'] ?? '') === 'manager_authorization_required');

$authorizedManagerRequest = $newManagerRequest;
$authorizedManagerRequest['requester_hwid'] = 'FIX496-MANAGER';
$authorizedManagerRequest['manager_auth_token'] = $managerToken;
$authorizedManagerPolicy = MultiEntitlementPolicy::preflightActivation($authorizedManagerRequest);
f496_check('valid manager capability authorizes a new manager_terminal', ($authorizedManagerPolicy['ok'] ?? false) === true && ($authorizedManagerPolicy['authorized_by_manager'] ?? false) === true);
$manager2Activation = EntitlementV2::activate($authorizedManagerRequest);
f496_check('authorized manager_terminal activation succeeds', ($manager2Activation['ok'] ?? false) === true);

$wrongTokenAuth = ManagerDeviceAuth::authorizeAction([
    'license_key' => $licenseKey,
    'requester_hwid' => 'FIX496-MANAGER',
    'manager_auth_token' => 'hma1_' . str_repeat('0', 64),
], 'device_revoke', $cashierUuid, true);
f496_check('wrong manager capability cannot control another device', ($wrongTokenAuth['ok'] ?? true) === false && ($wrongTokenAuth['status'] ?? '') === 'manager_auth_invalid');

$validAuth = ManagerDeviceAuth::authorizeAction([
    'license_key' => $licenseKey,
    'requester_hwid' => 'FIX496-MANAGER',
    'manager_auth_token' => $managerToken,
], 'device_revoke', $cashierUuid, true);
f496_check('correct manager capability authorizes cross-device action', ($validAuth['ok'] ?? false) === true && ($validAuth['self'] ?? true) === false);

$cashierSpoof = ManagerDeviceAuth::authorizeAction([
    'license_key' => $licenseKey,
    'requester_hwid' => 'FIX496-CASHIER',
    'manager_auth_token' => $managerToken,
], 'device_revoke', $managerUuid, true);
f496_check('cashier cannot reuse a manager capability', ($cashierSpoof['ok'] ?? true) === false && ($cashierSpoof['status'] ?? '') === 'permission_denied');

$selfAction = ManagerDeviceAuth::authorizeAction([
    'license_key' => $licenseKey,
    'requester_hwid' => 'FIX496-CASHIER',
], 'device_release', $cashierUuid, true);
f496_check('ordinary terminal may still release itself without manager privilege', ($selfAction['ok'] ?? false) === true && ($selfAction['self'] ?? false) === true);

$managerSelfNoToken = ManagerDeviceAuth::authorizeAction([
    'license_key' => $licenseKey,
    'requester_hwid' => 'FIX496-MANAGER',
], 'device_release', $managerUuid, true);
f496_check('manager cannot self-release with license key and HWID alone', ($managerSelfNoToken['ok'] ?? true) === false && ($managerSelfNoToken['status'] ?? '') === 'manager_auth_required');

$managerSelfWithToken = ManagerDeviceAuth::authorizeAction([
    'license_key' => $licenseKey,
    'requester_hwid' => 'FIX496-MANAGER',
    'manager_auth_token' => $managerToken,
], 'device_release', $managerUuid, true);
f496_check('manager capability authorizes manager self-release', ($managerSelfWithToken['ok'] ?? false) === true && ($managerSelfWithToken['self'] ?? false) === true);

$managerNoToken = ManagerDeviceAuth::authorizeAction([
    'license_key' => $licenseKey,
    'requester_hwid' => 'FIX496-MANAGER',
], 'device_replace', $cashierUuid, false);
f496_check('sensitive manager action fails closed when capability missing', ($managerNoToken['ok'] ?? true) === false && ($managerNoToken['status'] ?? '') === 'manager_auth_required');

$reactivationPolicy = MultiEntitlementPolicy::preflightActivation($managerRequest);
f496_check('exact registered manager may reactivate without self-promotion bypass', ($reactivationPolicy['ok'] ?? false) === true && ($reactivationPolicy['existing_manager'] ?? false) === true);

// Simulate the exact soft-release state created by release.php: inactive row,
// globally unique device/store UUIDs cleared, capability fingerprint retained.
$pdo->prepare(
    'UPDATE license_activations SET is_active=0, device_uuid=NULL, store_uuid=NULL WHERE license_id=? AND hwid=?'
)->execute([$licenseId, 'FIX496-MANAGER']);

$releasedWithoutToken = MultiEntitlementPolicy::preflightActivation($managerRequest);
f496_check('soft-released manager cannot rebind without its capability', ($releasedWithoutToken['ok'] ?? true) === false && ($releasedWithoutToken['status'] ?? '') === 'manager_auth_required');

$releasedRebindRequest = $managerRequest;
$releasedRebindRequest['manager_auth_token'] = $managerToken;
$releasedWithToken = MultiEntitlementPolicy::preflightActivation($releasedRebindRequest);
f496_check('soft-released manager may rebind with its original capability', ($releasedWithToken['ok'] ?? false) === true && ($releasedWithToken['released_manager_rebind'] ?? false) === true);
$rebound = EntitlementV2::activate($releasedRebindRequest);
f496_check('capability-authenticated manager rebind restores the same activation row', ($rebound['ok'] ?? false) === true);
$rebound = ManagerDeviceAuth::maybeIssueForActivation($releasedRebindRequest, $rebound);
f496_check('manager rebind does not expose a second capability', !isset($rebound['manager_auth_token']) && ($rebound['manager_auth_token_issued'] ?? true) === false);

$tokenAfterRebind = ManagerDeviceAuth::authorizeAction([
    'license_key' => $licenseKey,
    'requester_hwid' => 'FIX496-MANAGER',
    'manager_auth_token' => $managerToken,
], 'device_replace', $cashierUuid, false);
f496_check('original capability remains valid after authenticated soft rebind', ($tokenAfterRebind['ok'] ?? false) === true);

if ($failures) {
    fwrite(STDERR, 'Fix496 manager auth failures: ' . implode(', ', $failures) . "\n");
    exit(1);
}
echo "PASS Fix496 manager capability auth — self-promotion blocked, single manager_server=true, self-action protected, soft-rebind authenticated, cross-device actions capability-protected\n";
