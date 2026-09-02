<?php

date_default_timezone_set('UTC');
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';

require_once __DIR__ . '/../includes/Database.php';
require_once __DIR__ . '/../includes/License.php';
require_once __DIR__ . '/../includes/DeviceManager.php';
require_once __DIR__ . '/../includes/Entitlement.php';
require_once __DIR__ . '/../includes/LicenseLifecycle.php';
require_once __DIR__ . '/../public/api/v2/_bootstrap.php';

$failures = [];
function e2_check(string $label, bool $condition): void
{
    global $failures;
    echo ($condition ? '[PASS] ' : '[FAIL] ') . $label . "\n";
    if (!$condition) $failures[] = $label;
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec(file_get_contents(__DIR__ . '/../db/schema.sqlite.test.sql'));
Database::setTestInstance($pdo);

// Mirror device-management + MC-001 + MC-002 columns for portable regression testing.
$activationColumns = [
    'device_name TEXT NULL',
    'admin_note TEXT NULL',
    'app_version TEXT NULL',
    'is_blocked INTEGER NOT NULL DEFAULT 0',
    'blocked_at TEXT NULL',
    'blocked_by TEXT NULL',
    'deactivated_at TEXT NULL',
    'deactivated_by TEXT NULL',
    'revoked_at TEXT NULL',
    'revoked_by TEXT NULL',
    'replaced_at TEXT NULL',
    'replaced_by TEXT NULL',
    'device_uuid TEXT NULL',
    "device_role TEXT NOT NULL DEFAULT 'single_terminal'",
    'counts_as_terminal INTEGER NOT NULL DEFAULT 1',
    'protocol_version INTEGER NOT NULL DEFAULT 1',
    'certificate_fingerprint TEXT NULL',
    'paired_at TEXT NULL',
];
foreach ($activationColumns as $definition) {
    $pdo->exec('ALTER TABLE license_activations ADD COLUMN ' . $definition);
}

$licenseColumns = [
    'license_uuid TEXT NULL',
    'store_uuid TEXT NULL',
    'multi_cashier INTEGER NOT NULL DEFAULT 0',
    'max_terminals INTEGER NULL',
    'max_management_devices INTEGER NOT NULL DEFAULT 1',
    'features_json TEXT NULL',
    'entitlement_version INTEGER NOT NULL DEFAULT 1',
    'offline_valid_until TEXT NULL',
];
foreach ($licenseColumns as $definition) {
    $pdo->exec('ALTER TABLE licenses ADD COLUMN ' . $definition);
}

$pdo->prepare('INSERT INTO admin_users (username, password_hash, role, is_active) VALUES (?, ?, ?, 1)')
    ->execute(['tester', password_hash('test-password', PASSWORD_DEFAULT), 'owner']);
$pdo->prepare('INSERT INTO customers (name, email) VALUES (?, ?)')
    ->execute(['MC-002 Test Store', 'mc002@example.com']);
$customerId = (int) $pdo->lastInsertId();

// Legacy v1 creation MUST still work after MC-002 columns exist.
$license = License::issue($customerId, 'annual', 3, 'MC-002 compatibility');
$licenseId = (int) $license['id'];
$key = (string) $license['license_key'];
e2_check('Legacy License::issue still succeeds after entitlement columns exist', $licenseId > 0);
e2_check('Legacy-created license starts without forced v2 UUID writes', empty($license['license_uuid']) && empty($license['store_uuid']));

// Legacy v1 activation MUST also still insert successfully with nullable v2 identity columns.
$v1 = License::activate($key, 'LEGACY-V1-HWID', '127.0.0.1');
e2_check('Legacy v1 activation still succeeds after MC-002 schema', $v1['ok'] === true);
$v1Row = $pdo->query("SELECT * FROM license_activations WHERE hwid = 'LEGACY-V1-HWID'")->fetch();
e2_check('Legacy v1 activation may initially have no device_uuid', empty($v1Row['device_uuid']));

// First v2 touch lazily freezes stable identity without changing the license key.
v2_ensure_identity($key, 'LEGACY-V1-HWID');
$identity1 = License::findById($licenseId);
$v1Row = $pdo->query("SELECT * FROM license_activations WHERE hwid = 'LEGACY-V1-HWID'")->fetch();
e2_check('First v2 touch assigns license_uuid', (bool) preg_match('/^[0-9a-f-]{36}$/i', (string) $identity1['license_uuid']));
e2_check('First v2 touch assigns store_uuid', (bool) preg_match('/^[0-9a-f-]{36}$/i', (string) $identity1['store_uuid']));
e2_check('First v2 touch assigns device_uuid to legacy activation', (bool) preg_match('/^[0-9a-f-]{36}$/i', (string) $v1Row['device_uuid']));
e2_check('Legacy non-Multi license gets one v2 terminal by default', (int) $identity1['max_terminals'] === 1 && (int) $identity1['multi_cashier'] === 0);

$licenseUuid = (string) $identity1['license_uuid'];
$storeUuid = (string) $identity1['store_uuid'];
v2_ensure_identity($key, 'LEGACY-V1-HWID');
$identity2 = License::findById($licenseId);
e2_check('Repeated v2 identity provisioning is idempotent', $identity2['license_uuid'] === $licenseUuid && $identity2['store_uuid'] === $storeUuid);

e2_check('Entitlement schema is detected as ready', Entitlement::schemaReady());
$validatedLegacy = Entitlement::validateDevice($key, 'LEGACY-V1-HWID', '1.1.4', 2, '127.0.0.1');
e2_check('v2 validation accepts a migrated active v1 device', $validatedLegacy['ok'] === true);
e2_check('v2 payload carries schema_version=2', ($validatedLegacy['payload']['schema_version'] ?? 0) === 2);
e2_check('v2 payload preserves frozen store_uuid', ($validatedLegacy['payload']['store_uuid'] ?? '') === $storeUuid);
e2_check('v2 payload exposes entitlement_version', (int) ($validatedLegacy['payload']['entitlement_version'] ?? 0) === 1);

// multi_cashier=false must NOT inherit legacy max_activations=3 as free Multi seats.
$secondBeforeUpgrade = Entitlement::activateTerminal($key, 'V2-TERM-B', '1.1.4', 2, 'cashier_terminal', '127.0.0.1');
e2_check('Second v2 terminal is rejected before Multi entitlement is enabled', $secondBeforeUpgrade['ok'] === false && ($secondBeforeUpgrade['code'] ?? '') === 'activation_limit');

$roleExploit = Entitlement::activateTerminal($key, 'ROLE-EXPLOIT', '1.1.4', 2, 'management_only', '127.0.0.1');
e2_check('Public terminal activation cannot claim management_only to bypass seats', $roleExploit['ok'] === false && ($roleExploit['code'] ?? '') === 'invalid_device_role');

// Upgrade same license/store from one terminal to two.
$upgraded = LicenseLifecycle::updateMultiEntitlement($licenseId, true, 2, 1, 'tester');
e2_check('Multi entitlement can be enabled on the same license', (int) $upgraded['multi_cashier'] === 1);
e2_check('1→2 upgrade synchronizes legacy max_activations and max_terminals', (int) $upgraded['max_activations'] === 2 && (int) $upgraded['max_terminals'] === 2);
e2_check('Entitlement version increments after Multi upgrade', (int) $upgraded['entitlement_version'] === 2);
e2_check('Store identity is unchanged by entitlement upgrade', $upgraded['store_uuid'] === $storeUuid);

$secondAfterUpgrade = Entitlement::activateTerminal($key, 'V2-TERM-B', '1.1.4', 2, 'cashier_terminal', '127.0.0.1');
e2_check('Second terminal activates after 1→2 upgrade', $secondAfterUpgrade['ok'] === true);
$thirdBeforeUpgrade = Entitlement::activateTerminal($key, 'V2-TERM-C', '1.1.4', 2, 'cashier_terminal', '127.0.0.1');
e2_check('Third terminal is rejected while max_terminals=2', $thirdBeforeUpgrade['ok'] === false);

// Management capacity is independent from terminal capacity.
$management1 = Entitlement::activateManagementDevice($key, 'MANAGER-ONLY-1', 'management_only', '1.1.4', 2, '127.0.0.1');
e2_check('One management-only device activates without consuming terminal capacity', $management1['ok'] === true && empty($management1['payload']['device']['counts_as_terminal']));
$management2 = Entitlement::activateManagementDevice($key, 'MANAGER-ONLY-2', 'management_only', '1.1.4', 2, '127.0.0.1');
e2_check('Second management-only device respects max_management_devices=1', $management2['ok'] === false && ($management2['code'] ?? '') === 'activation_limit');

// Existing legacy capacity control remains compatible and mirrors Multi capacity once enabled.
$beforeLegacyBump = License::findById($licenseId);
$legacyBump = LicenseLifecycle::updateActivationLimit($licenseId, 3, 'tester');
e2_check('Legacy activation-limit control still works', (int) $legacyBump['max_activations'] === 3);
e2_check('When Multi is enabled, legacy limit mirrors to max_terminals', (int) $legacyBump['max_terminals'] === 3);
e2_check('Mirrored capacity change increments entitlement_version', (int) $legacyBump['entitlement_version'] === (int) $beforeLegacyBump['entitlement_version'] + 1);

$thirdAfterUpgrade = Entitlement::activateTerminal($key, 'V2-TERM-C', '1.1.4', 2, 'manager_terminal', '127.0.0.1');
e2_check('Third terminal activates after 2→3 capacity upgrade', $thirdAfterUpgrade['ok'] === true);

$blockedReduction = false;
try {
    LicenseLifecycle::updateMultiEntitlement($licenseId, true, 2, 1, 'tester');
} catch (InvalidArgumentException $e) {
    $blockedReduction = true;
}
e2_check('Cannot reduce Multi terminal limit below active terminals', $blockedReduction);

$disableWithThree = false;
try {
    LicenseLifecycle::updateMultiEntitlement($licenseId, false, 1, 1, 'tester');
} catch (InvalidArgumentException $e) {
    $disableWithThree = true;
}
e2_check('Cannot disable Multi while multiple terminals are still active', $disableWithThree);

// A signed v2 entitlement must cover the identity/capacity fields, not raw DB state.
$payload = $thirdAfterUpgrade['payload'];
e2_check('v2 payload includes immutable license/store/device identities',
    !empty($payload['license_uuid']) && !empty($payload['store_uuid']) && !empty($payload['device']['device_uuid'])
);
e2_check('v2 payload reports terminal and management limits',
    (int) $payload['max_terminals'] === 3 && (int) $payload['max_management_devices'] === 1
);

if ($failures) {
    echo "\n" . count($failures) . " ENTITLEMENT V2 TEST(S) FAILED\n";
    exit(1);
}

echo "\nENTITLEMENT V2 TESTS PASSED\n";
