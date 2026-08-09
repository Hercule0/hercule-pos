<?php
date_default_timezone_set('UTC');

$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
/**
 * Manual test harness — NOT part of the deployed app. Exercises License,
 * RsaSigner, and Auth logic against an in-memory SQLite DB standing in for
 * MySQL, so the business logic can be verified without a real MySQL server.
 *
 * Run: php tests/run_test.php
 */

require_once __DIR__ . '/../includes/Database.php';
require_once __DIR__ . '/../includes/License.php';
require_once __DIR__ . '/../includes/RsaSigner.php';
require_once __DIR__ . '/../includes/Auth.php';

function check(string $label, bool $condition): void
{
    echo ($condition ? "[PASS] " : "[FAIL] ") . $label . "\n";
    if (!$condition) {
        global $failures;
        $failures[] = $label;
    }
}

$failures = [];

// ---- Set up in-memory SQLite standing in for MySQL ----
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec(file_get_contents(__DIR__ . '/../db/schema.sqlite.test.sql'));
Database::setTestInstance($pdo);

// ---- Seed a customer ----
$pdo->prepare('INSERT INTO customers (name, email) VALUES (?, ?)')->execute(['Test Customer', 'test@example.com']);
$customerId = (int) $pdo->lastInsertId();

// ================= License key generation =================
$key1 = License::generateKey();
$key2 = License::generateKey();
check('Generated key matches XXXX-XXXX-XXXX-XXXX-XXXX format', (bool) preg_match('/^[A-Z0-9]{4}(-[A-Z0-9]{4}){4}$/', $key1));
check('Two generated keys are different', $key1 !== $key2);
check('Key alphabet excludes ambiguous chars (0,O,1,I,L)', !preg_match('/[01OIL]/', $key1 . $key2));

// ================= Expiry computation =================
$trialExpiry = License::computeExpiry('trial', new DateTime('2026-01-01 00:00:00'));
check('Trial expiry is +10 days', $trialExpiry === '2026-01-11 00:00:00');
$monthlyExpiry = License::computeExpiry('monthly', new DateTime('2026-01-01 00:00:00'));
check('Monthly expiry is +1 month', $monthlyExpiry === '2026-02-01 00:00:00');
$annualExpiry = License::computeExpiry('annual', new DateTime('2026-01-01 00:00:00'));
check('Annual expiry is +1 year', $annualExpiry === '2027-01-01 00:00:00');
$lifetimeExpiry = License::computeExpiry('lifetime');
check('Lifetime plan has null expiry', $lifetimeExpiry === null);

// ================= Issuing a license =================
$license = License::issue($customerId, 'monthly', 2, 'Test issue');
check('Issued license has a key', !empty($license['license_key']));
check('Issued license status is active', $license['status'] === 'active');
check('Issued license has expires_at set', $license['expires_at'] !== null);
check('max_activations respected', (int) $license['max_activations'] === 2);

$licenseKey = $license['license_key'];
$licenseId = (int) $license['id'];

// ================= Activation flow =================
$hwid1 = 'HWID-MACHINE-ONE';
$hwid2 = 'HWID-MACHINE-TWO';
$hwid3 = 'HWID-MACHINE-THREE';

$act1 = License::activate($licenseKey, $hwid1, '127.0.0.1');
check('First activation succeeds', $act1['ok'] === true);

$act1Again = License::activate($licenseKey, $hwid1, '127.0.0.1');
check('Re-activating same HWID is idempotent (still ok)', $act1Again['ok'] === true);
check('Re-activating same HWID does not consume a new slot', count(License::activationsFor($licenseId)) === 1);

$act2 = License::activate($licenseKey, $hwid2, '10.0.0.1');
check('Second (different) HWID activation succeeds (within limit of 2)', $act2['ok'] === true);

$act3 = License::activate($licenseKey, $hwid3, '10.0.0.2');
check('Third HWID activation REJECTED (exceeds max_activations=2)', $act3['ok'] === false);
check('Rejection reason mentions activation limit', str_contains($act3['error'] ?? '', 'activation limit'));

// ================= Validation flow (runtime checks) =================
$val1 = License::validate($licenseKey, $hwid1, '127.0.0.1');
check('Validate succeeds for activated HWID', $val1['ok'] === true);

$valWrongHwid = License::validate($licenseKey, 'SOME-OTHER-UNREGISTERED-HWID', '1.2.3.4');
check('Validate REJECTS an unregistered HWID (hwid_mismatch)', $valWrongHwid['ok'] === false);

$valBadKey = License::validate('FAKE-FAKE-FAKE-FAKE-FAKE', $hwid1, '1.2.3.4');
check('Validate REJECTS a bogus license key', $valBadKey['ok'] === false);

// ================= Suspend / revoke / reactivate =================
License::suspend($licenseId, 'admin');
$valSuspended = License::validate($licenseKey, $hwid1, '127.0.0.1');
check('Validate REJECTS a suspended license', $valSuspended['ok'] === false);

License::reactivate($licenseId, 'admin');
$valReactivated = License::validate($licenseKey, $hwid1, '127.0.0.1');
check('Validate succeeds again after reactivation', $valReactivated['ok'] === true);

License::revoke($licenseId, 'admin');
$valRevoked = License::validate($licenseKey, $hwid1, '127.0.0.1');
check('Validate REJECTS a revoked license', $valRevoked['ok'] === false);
License::reactivate($licenseId, 'admin'); // undo for later tests

// ================= Expiry enforcement =================
$expiredLicense = License::issue($customerId, 'trial', 1, 'Backdated for expiry test');
$pdo->prepare("UPDATE licenses SET expires_at = '2020-01-01 00:00:00' WHERE id = ?")->execute([$expiredLicense['id']]);
License::activate($expiredLicense['license_key'], 'HWID-EXPIRED-TEST');
$valExpired = License::validate($expiredLicense['license_key'], 'HWID-EXPIRED-TEST');
check('Validate REJECTS an expired license', $valExpired['ok'] === false);
$reloaded = License::findById((int) $expiredLicense['id']);
check('Expired license status auto-updated to "expired" in DB', $reloaded['status'] === 'expired');

// ================= Renewal =================
$renewed = License::renew($licenseId, 'annual', 'admin');
check('Renewal changes plan', $renewed['plan'] === 'annual');
check('Renewal extends expiry further into the future', strtotime($renewed['expires_at']) > time() + (300 * 86400));

// ================= Device deactivation frees a slot =================
$activations = License::activationsFor($licenseId);
License::deactivateDevice((int) $activations[0]['id']);
$act3Retry = License::activate($licenseKey, $hwid3, '10.0.0.2');
check('Freed activation slot allows a new device to activate', $act3Retry['ok'] === true);

// ================= RSA signing round-trip =================
$config = require __DIR__ . '/../config/config.php';
@mkdir(dirname($config['rsa']['private_key_path']), 0700, true);
RsaSigner::generateKeypair();
check('RSA keypair files created', file_exists($config['rsa']['private_key_path']) && file_exists($config['rsa']['public_key_path']));

$samplePayload = ['status' => 'active', 'plan' => 'annual', 'expires_at' => '2027-01-01 00:00:00'];
$signed = RsaSigner::sign($samplePayload);
$publicKeyPem = file_get_contents($config['rsa']['public_key_path']);
check('Signed payload verifies correctly with public key', RsaSigner::verify($signed['payload'], $signed['signature'], $publicKeyPem));

$tamperedPayload = $samplePayload;
$tamperedPayload['plan'] = 'lifetime'; // attacker tries to upgrade their own plan client-side
check('Tampered payload FAILS verification', !RsaSigner::verify($tamperedPayload, $signed['signature'], $publicKeyPem));

// ================= Auth: rate limiting =================
$pdo->prepare('INSERT INTO admin_users (username, password_hash) VALUES (?, ?)')
    ->execute(['admin', password_hash('correct-horse-battery-staple', PASSWORD_DEFAULT)]);

$badLogin = Auth::attemptLogin('admin', 'wrong-password', '9.9.9.9');
check('Wrong password rejected', $badLogin['ok'] === false);

for ($i = 0; $i < 4; $i++) {
    Auth::attemptLogin('admin', 'wrong-password', '9.9.9.9');
}
$rateLimited = Auth::attemptLogin('admin', 'wrong-password', '9.9.9.9');
check('After 5 failed attempts, further attempts are rate-limited', str_contains($rateLimited['error'] ?? '', 'Too many'));

$goodLoginDifferentIp = Auth::attemptLogin('admin', 'correct-horse-battery-staple', '1.1.1.1');
check('Correct password from a DIFFERENT IP still succeeds (rate limit is per-IP)', $goodLoginDifferentIp['ok'] === true);

// ================= Summary =================
echo "\n";
if (empty($failures)) {
    echo "ALL TESTS PASSED\n";
    exit(0);
} else {
    echo count($failures) . " TEST(S) FAILED:\n";
    foreach ($failures as $f) {
        echo "  - {$f}\n";
    }
    exit(1);
}
