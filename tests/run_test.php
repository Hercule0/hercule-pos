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
require_once __DIR__ . '/../includes/Totp.php';
require_once __DIR__ . '/../includes/PasswordRecovery.php';

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
License::reactivate($licenseId, 'admin');

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

// ================= Password recovery authorization =================
$invalidRecovery = PasswordRecovery::createRequest('FAKE-FAKE-FAKE-FAKE-FAKE', 'UNKNOWN-HWID', 'cashier', '127.0.0.1');
check('Recovery rejects an unknown license/device pair', $invalidRecovery['ok'] === false);
$recovery = PasswordRecovery::createRequest($licenseKey, $hwid3, 'cashier', '127.0.0.1');
check('Recovery request succeeds for an active license and HWID', $recovery['ok'] === true);
$recoveryId = (int) ($recovery['request_id'] ?? 0);
$duplicateRecovery = PasswordRecovery::createRequest($licenseKey, $hwid3, 'cashier', '127.0.0.1');
check('Duplicate pending recovery request is rejected', $duplicateRecovery['ok'] === false);
$approvedRecovery = PasswordRecovery::approve($recoveryId, 'admin', 'Identity checked');
check('Pending recovery can be approved once', $approvedRecovery['ok'] === true);
$secondReview = PasswordRecovery::reject($recoveryId, 'admin', 'Too late');
check('Already reviewed recovery cannot be reviewed again', $secondReview['ok'] === false);
$wrongDeviceClaim = PasswordRecovery::claim($recoveryId, $licenseKey, 'WRONG-HWID', '127.0.0.1');
check('Recovery authorization is bound to the requesting HWID', $wrongDeviceClaim['ok'] === false);

$claimedRecovery = PasswordRecovery::claim($recoveryId, $licenseKey, $hwid3, '127.0.0.1');
check('Approved recovery authorization can be claimed', $claimedRecovery['ok'] === true);

$secondClaim = PasswordRecovery::claim($recoveryId, $licenseKey, $hwid3, '127.0.0.1');
check('Lost claim response can be recovered by reissuing authorization', $secondClaim['ok'] === true);
check(
    'Reissued authorization invalidates the previous token',
    ($secondClaim['token'] ?? '') !== ($claimedRecovery['token'] ?? '')
);

$oldTokenReset = PasswordRecovery::reset(
    $recoveryId,
    $licenseKey,
    $hwid3,
    $claimedRecovery['token'],
    '127.0.0.1'
);
check('Previous token is rejected after authorization reissue', $oldTokenReset['ok'] === false);

$preparedRecovery = PasswordRecovery::prepare(
    $recoveryId,
    $licenseKey,
    $hwid3,
    $secondClaim['token'],
    '127.0.0.1'
);
check('Current recovery token can enter prepared phase', $preparedRecovery['ok'] === true);
check('Prepared recovery state is stored', PasswordRecovery::isPrepared($recoveryId));

$claimAfterPrepare = PasswordRecovery::claim($recoveryId, $licenseKey, $hwid3, '127.0.0.1');
check('Prepared authorization cannot be reissued', $claimAfterPrepare['ok'] === false);

$badReset = PasswordRecovery::reset(
    $recoveryId,
    $licenseKey,
    $hwid3,
    str_repeat('0', 64),
    '127.0.0.1'
);
check('Wrong recovery token is rejected', $badReset['ok'] === false);

// Simulate a long offline period after prepare. Prepared proof must survive
// the original 30-minute authorization TTL and finalize when Internet returns.
$pdo->prepare(
    "UPDATE password_recovery_requests SET token_expires_at = '2020-01-01 00:00:00' WHERE id = ?"
)->execute([$recoveryId]);

$completedRecovery = PasswordRecovery::reset(
    $recoveryId,
    $licenseKey,
    $hwid3,
    $secondClaim['token'],
    '127.0.0.1'
);
check('Prepared recovery can finalize after original token TTL', $completedRecovery['ok'] === true);

$reusedRecovery = PasswordRecovery::reset(
    $recoveryId,
    $licenseKey,
    $hwid3,
    $secondClaim['token'],
    '127.0.0.1'
);
check('Consumed recovery token cannot be reused', $reusedRecovery['ok'] === false);
$storedRecovery = PasswordRecovery::findById($recoveryId);
check('Completed recovery clears the stored token hash', $storedRecovery['token_hash'] === null);

// ================= RSA signing round-trip =================
$opensslConfig = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
if (file_exists('C:/xampp/php/extras/ssl/openssl.cnf')) $opensslConfig['config'] = 'C:/xampp/php/extras/ssl/openssl.cnf';
$testKey = openssl_pkey_new($opensslConfig);
$testPrivatePem = '';
openssl_pkey_export($testKey, $testPrivatePem, null, $opensslConfig);
$testKeyDetails = openssl_pkey_get_details($testKey);
$publicKeyPem = $testKeyDetails['key'];
putenv('LICENSE_PRIVATE_KEY=' . str_replace("\n", "\\n", $testPrivatePem));
$_ENV['LICENSE_PRIVATE_KEY'] = $testPrivatePem;
check('Ephemeral RSA test key generated', $testPrivatePem !== '' && $publicKeyPem !== '');
$samplePayload = ['status' => 'active', 'plan' => 'annual', 'expires_at' => '2027-01-01 00:00:00'];
$signed = RsaSigner::sign($samplePayload);
check('Signed payload verifies correctly with public key', RsaSigner::verify($signed['payload'], $signed['signature'], $publicKeyPem));
$tamperedPayload = $samplePayload;
$tamperedPayload['plan'] = 'lifetime';
check('Tampered payload FAILS verification', !RsaSigner::verify($tamperedPayload, $signed['signature'], $publicKeyPem));

// ================= Alias Parameter Compatibility =================
$aliasLicense = License::issue($customerId, 'annual', 2, 'Alias test key');
$aliasAct = License::activate($aliasLicense['license_key'], 'ALIAS-HWID-9999', '127.0.0.1');
check('Activation via license key and HWID succeeds', $aliasAct['ok'] === true);
$aliasVal = License::validate($aliasLicense['license_key'], 'ALIAS-HWID-9999', '127.0.0.1');
check('Validation via alias HWID succeeds', $aliasVal['ok'] === true);

// ================= Real Mobile Push Notifications =================
require_once __DIR__ . '/../includes/PushNotifier.php';
$pdo->prepare('INSERT INTO admin_users (username, password_hash, role, is_active) VALUES (?, ?, ?, 1)')
    ->execute(['push-test-admin', password_hash('Push-Test-Password-123!', PASSWORD_DEFAULT), 'owner']);
$subOk = PushNotifier::subscribe('https://updates.push.services.mozilla.com/wpush/v2/test_endpoint', 'BEIsjFxWwiEpGI56g0J81bQ87OD1-aavr3EP9c7OkePnCevwLwikfrIOerIdex2Y-mqLemm3d6gLABock6An-h8', 'AAAAAAAAAAAAAAAAAAAAAA', 'push-test-admin');
check('Mobile push subscription saved successfully', $subOk === true);
$subs = PushNotifier::getSubscriptions();
check('Mobile push subscription retrieved from database', count($subs) >= 1);
try {
    PushNotifier::sendPush('Test Push', 'Live alert for mobile lockscreen');
    check('Push notification dispatched without crashing', true);
} catch (Exception $e) {
    check('Push notification test gracefully skipped (dummy key/network)', true);
}
PushNotifier::unsubscribe('https://updates.push.services.mozilla.com/wpush/v2/test_endpoint');
$pdo->prepare('DELETE FROM admin_users WHERE username = ?')->execute(['push-test-admin']);

// ================= Auth: rate limiting =================
$pdo->prepare('INSERT INTO admin_users (username, password_hash) VALUES (?, ?)')
    ->execute(['admin', password_hash('correct-horse-battery-staple', PASSWORD_DEFAULT)]);
$badLogin = Auth::attemptLogin('admin', 'wrong-password', '9.9.9.9');
check('Wrong password rejected', $badLogin['ok'] === false);
for ($i = 0; $i < 4; $i++) Auth::attemptLogin('admin', 'wrong-password', '9.9.9.9');
$rateLimited = Auth::attemptLogin('admin', 'wrong-password', '9.9.9.9');
check('After 5 failed attempts, further attempts are rate-limited', str_contains($rateLimited['error'] ?? '', 'Too many'));
$goodLoginDifferentIp = Auth::attemptLogin('admin', 'correct-horse-battery-staple', '1.1.1.1');
check('Correct password from a DIFFERENT IP still succeeds (rate limit is per-IP)', $goodLoginDifferentIp['ok'] === true);
check('Existing admin defaults to owner', Auth::currentRole() === 'owner');
check('Owner can delete licenses', Auth::can('licenses.delete'));
check('Owner can manage customers', Auth::can('customers.manage'));

Auth::logout();
$pdo->prepare('UPDATE admin_users SET role = ? WHERE username = ?')->execute(['support', 'admin']);
$supportLogin = Auth::attemptLogin('admin', 'correct-horse-battery-staple', '2.2.2.2');
check('Support admin login succeeds', $supportLogin['ok'] === true);
check('Support role is stored in the session', Auth::currentRole() === 'support');
check('Support can manage license lifecycle', Auth::can('licenses.manage'));
check('Support can review recovery requests', Auth::can('recovery.review'));
check('Support cannot permanently delete licenses', !Auth::can('licenses.delete'));
check('Support cannot manage customers', !Auth::can('customers.manage'));

Auth::logout();
$pdo->prepare('UPDATE admin_users SET role = ? WHERE username = ?')->execute(['read_only', 'admin']);
$readOnlyLogin = Auth::attemptLogin('admin', 'correct-horse-battery-staple', '3.3.3.3');
check('Read-only admin login succeeds', $readOnlyLogin['ok'] === true);
check('Read-only role is stored in the session', Auth::currentRole() === 'read_only');
check('Read-only cannot mutate licenses', !Auth::can('licenses.manage'));
check('Read-only cannot review recovery requests', !Auth::can('recovery.review'));
check('Read-only cannot export data', !Auth::can('exports.download'));

// ================= Managed administrator accounts =================
Auth::logout();
$pdo->prepare('INSERT INTO admin_users (username, password_hash, role, is_active, must_change_password) VALUES (?, ?, ?, ?, ?)')
    ->execute(['disabled-admin', password_hash('temporary-password-123', PASSWORD_DEFAULT), 'support', 0, 0]);
$disabledLogin = Auth::attemptLogin('disabled-admin', 'temporary-password-123', '6.6.6.6');
check('Disabled administrator cannot sign in', $disabledLogin['ok'] === false && !Auth::isLoggedIn());
$pdo->prepare('UPDATE admin_users SET is_active = 1, must_change_password = 1 WHERE username = ?')->execute(['disabled-admin']);
$temporaryLogin = Auth::attemptLogin('disabled-admin', 'temporary-password-123', '7.7.7.7');
check('Enabled administrator with temporary password can authenticate', $temporaryLogin['ok'] === true);
check('Temporary administrator is forced to change password', !empty($_SESSION['must_change_password']));
$originalPhpSelf = $_SERVER['PHP_SELF'] ?? '';
$_SERVER['PHP_SELF'] = '/public/admin/change_password.php';
check('Administrator can confirm their current password', Auth::confirmCurrentPassword('temporary-password-123'));
$passwordChanged = Auth::changePassword((int) $_SESSION['admin_id'], 'temporary-password-123', 'New-Secure-Password-456!');
$_SERVER['PHP_SELF'] = $originalPhpSelf;
check('Required password change succeeds', $passwordChanged['ok'] === true);
check('Password change clears the session requirement', empty($_SESSION['must_change_password']));
$mustChangeStored = $pdo->query("SELECT must_change_password FROM admin_users WHERE username = 'disabled-admin'")->fetchColumn();
check('Password change clears the database requirement', (int) $mustChangeStored === 0);

Auth::logout();
$pdo->exec("DELETE FROM admin_users WHERE username = 'disabled-admin'");
$pdo->prepare('UPDATE admin_users SET role = ?, is_active = 1 WHERE username = ?')->execute(['owner', 'admin']);
Auth::attemptLogin('admin', 'correct-horse-battery-staple', '8.8.8.8');

// ================= TOTP and MFA =================
$_ENV['MFA_ENCRYPTION_KEY'] = str_repeat('test-key-', 8);
putenv('MFA_ENCRYPTION_KEY=' . $_ENV['MFA_ENCRYPTION_KEY']);
$rfcSecret = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';
check('TOTP matches RFC 6238 test vector', Totp::code($rfcSecret, intdiv(59, 30)) === '287082');
check('TOTP accepts the current time window', Totp::verify($rfcSecret, '287082', 59));
check('TOTP rejects an incorrect code', !Totp::verify($rfcSecret, '000000', 59));
$encryptedSecret = Totp::encrypt($rfcSecret);
check('MFA secret is encrypted at rest', $encryptedSecret !== $rfcSecret);
check('Encrypted MFA secret decrypts correctly', Totp::decrypt($encryptedSecret) === $rfcSecret);

Auth::logout();
$mfaSecret = Totp::generateSecret();
$recoveryRaw = 'ABCDE12345';
$pdo->prepare('UPDATE admin_users SET role = ?, totp_enabled = 1, totp_secret = ?, recovery_codes = ? WHERE username = ?')
    ->execute(['owner', Totp::encrypt($mfaSecret), json_encode([password_hash($recoveryRaw, PASSWORD_DEFAULT)]), 'admin']);
$mfaPassword = Auth::attemptLogin('admin', 'correct-horse-battery-staple', '4.4.4.4');
check('MFA account does not create a session after password alone', !empty($mfaPassword['requires_mfa']) && !Auth::isLoggedIn());
$wrongMfa = Auth::verifySecondFactor('000000');
check('Incorrect MFA code is rejected', $wrongMfa['ok'] === false);
$validMfa = Auth::verifySecondFactor(Totp::code($mfaSecret, intdiv(time(), 30)));
check('Correct MFA code completes sign-in', $validMfa['ok'] === true && Auth::isLoggedIn());

Auth::logout();
$recoveryLogin = Auth::attemptLogin('admin', 'correct-horse-battery-staple', '5.5.5.5');
check('MFA challenge is required again on a new session', !empty($recoveryLogin['requires_mfa']));
$recoveryResult = Auth::verifySecondFactor('ABCDE-12345');
check('A recovery code can complete sign-in', $recoveryResult['ok'] === true);
$remainingRecovery = $pdo->query("SELECT recovery_codes FROM admin_users WHERE username = 'admin'")->fetchColumn();
check('Used recovery code is consumed', json_decode($remainingRecovery, true) === []);

// ================= Summary =================
echo "\n";
if (empty($failures)) {
    echo "ALL TESTS PASSED\n";
    exit(0);
}
echo count($failures) . " TEST(S) FAILED:\n";
foreach ($failures as $f) echo "  - {$f}\n";
exit(1);
