<?php
/**
 * POST /api/recovery_request.php
 * Body: {
 *   "license_key": "...",
 *   "hwid": "...",
 *   "recovery_type": "password|username|account",
 *   "username": "..." // required only for password recovery
 * }
 */

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../includes/RateLimiter.php';
require_once __DIR__ . '/../../includes/PasswordRecovery.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'error' => 'Method not allowed'], 405);
}

$config = require __DIR__ . '/../../config/config.php';
$rateLimitCfg = $config['security'];

if (!RateLimiter::check(client_ip(), 'recovery_request', $rateLimitCfg['api_rate_limit_max_requests'], $rateLimitCfg['api_rate_limit_window_minutes'])) {
    json_response(['ok' => false, 'error' => 'Too many requests. Please try again in a few minutes.'], 429);
}

$input = json_input();
$licenseKey = trim(
    $input['license_key'] ??
    $input['licenseKey'] ??
    $input['key'] ??
    $input['serial'] ??
    $input['license'] ?? ''
);
$hwid = trim(
    $input['hwid'] ??
    $input['hardware_id'] ??
    $input['hardwareId'] ??
    $input['device_id'] ??
    $input['deviceId'] ??
    $input['machine_id'] ??
    $input['uuid'] ??
    $input['mac'] ?? ''
);
$recoveryType = strtolower(trim((string) ($input['recovery_type'] ?? $input['recoveryType'] ?? 'password')));
$username = trim((string) ($input['username'] ?? $input['userName'] ?? $input['user'] ?? ''));

if (!in_array($recoveryType, ['password', 'username', 'account'], true)) {
    json_response(['ok' => false, 'error' => 'Invalid recovery_type.'], 400);
}
if ($licenseKey === '' || $hwid === '') {
    json_response(['ok' => false, 'error' => 'license_key and hwid are required'], 400);
}
if ($recoveryType === 'password' && $username === '') {
    json_response(['ok' => false, 'error' => 'username is required for password recovery'], 400);
}
if (strlen($licenseKey) > 29 || !preg_match('/^[A-Z0-9-]+$/', $licenseKey)) {
    json_response(['ok' => false, 'error' => 'Invalid license_key format.'], 400);
}
if (strlen($hwid) > 128 || preg_match('/[\x00-\x1F\x7F]/', $hwid)) {
    json_response(['ok' => false, 'error' => 'Invalid hwid.'], 400);
}
if (mb_strlen($username) > 64) {
    json_response(['ok' => false, 'error' => 'Username is too long.'], 400);
}

if (!RateLimiter::check('key:' . $licenseKey, 'recovery_request_by_key', $rateLimitCfg['key_rate_limit_max_requests'], $rateLimitCfg['key_rate_limit_window_minutes'])) {
    json_response(['ok' => false, 'error' => 'Too many recovery requests for this license. Please try again later.'], 429);
}

$pdo = Database::pdo();

// An approval may expire only before the desktop enters the prepared phase.
// Prepared requests retain the frozen completion proof because local credentials
// may already have been committed while the machine is offline.
$expire = $pdo->prepare(
    "UPDATE password_recovery_requests
     SET status = 'expired'
     WHERE license_key = ? AND hwid = ? AND status = 'approved'
       AND token_expires_at IS NOT NULL AND token_expires_at < CURRENT_TIMESTAMP
       AND NOT EXISTS (
           SELECT 1 FROM recovery_audit_log ra
           WHERE ra.request_id = password_recovery_requests.id
             AND ra.event_type = 'authorization_prepared'
       )"
);
$expire->execute([$licenseKey, $hwid]);

// One in-flight request per activated device. A prepared request deliberately
// remains active until its completion acknowledgement arrives.
$duplicate = $pdo->prepare(
    "SELECT id FROM password_recovery_requests
     WHERE license_key = ? AND hwid = ? AND status IN ('pending','approved')
     LIMIT 1"
);
$duplicate->execute([$licenseKey, $hwid]);
if ($duplicate->fetchColumn()) {
    json_response(['ok' => false, 'error' => 'يوجد طلب استرداد فعال بالفعل لهذا الجهاز. افتح حالة الطلب الحالي أو ألغِه أولاً.'], 409);
}

$requestedUsername = $username;
if ($recoveryType === 'username') {
    $requestedUsername = 'الحساب الرئيسي — استرداد اسم المستخدم';
} elseif ($recoveryType === 'account') {
    $requestedUsername = 'الحساب الرئيسي — استرداد بيانات الدخول';
}

$result = PasswordRecovery::createRequest($licenseKey, $hwid, $requestedUsername, client_ip());
$result['recovery_type'] = $recoveryType;
json_response($result, $result['ok'] ? 200 : 400);
