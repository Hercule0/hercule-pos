<?php
/**
 * POST /api/recovery_status.php
 * Body: { "request_id": 123, "license_key": "...", "hwid": "..." }
 */

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../includes/RateLimiter.php';
require_once __DIR__ . '/../../includes/PasswordRecovery.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'error' => 'Method not allowed'], 405);
}

$config = require __DIR__ . '/../../config/config.php';
$rateLimitCfg = $config['security'];

if (!RateLimiter::check(client_ip(), 'recovery_status', $rateLimitCfg['api_rate_limit_max_requests'], $rateLimitCfg['api_rate_limit_window_minutes'])) {
    json_response(['ok' => false, 'error' => 'Too many requests. Please try again in a few minutes.'], 429);
}

$input = json_input();
$requestId = (int) ($input['request_id'] ?? 0);
$licenseKey = trim((string) ($input['license_key'] ?? ''));
$hwid = trim((string) ($input['hwid'] ?? ''));

if (!$requestId || $licenseKey === '') {
    json_response(['ok' => false, 'error' => 'request_id and license_key are required'], 400);
}
if (strlen($licenseKey) > 29 || !preg_match('/^[A-Z0-9-]+$/', $licenseKey)) {
    json_response(['ok' => false, 'error' => 'Invalid license_key format.'], 400);
}
if ($hwid !== '' && (strlen($hwid) > 128 || preg_match('/[\x00-\x1F\x7F]/', $hwid))) {
    json_response(['ok' => false, 'error' => 'Invalid hwid.'], 400);
}
if (!RateLimiter::check('key:' . $licenseKey, 'recovery_status_by_key', $rateLimitCfg['key_rate_limit_max_requests'], $rateLimitCfg['key_rate_limit_window_minutes'])) {
    json_response(['ok' => false, 'error' => 'Too many status checks for this license. Please try again later.'], 429);
}

$pdo = Database::pdo();
$pdo->prepare(
    "UPDATE password_recovery_requests
     SET status = 'expired'
     WHERE id = ? AND license_key = ? AND status = 'approved'
       AND token_expires_at IS NOT NULL AND token_expires_at < CURRENT_TIMESTAMP
       AND NOT EXISTS (
           SELECT 1 FROM recovery_audit_log ra
           WHERE ra.request_id = password_recovery_requests.id
             AND ra.event_type = 'authorization_prepared'
       )"
)->execute([$requestId, $licenseKey]);

$sql = 'SELECT id, status, requested_username, admin_note, created_at, reviewed_at FROM password_recovery_requests WHERE id = ? AND license_key = ?';
$params = [$requestId, $licenseKey];
if ($hwid !== '') {
    $sql .= ' AND hwid = ?';
    $params[] = $hwid;
}
$stmt = $pdo->prepare($sql . ' LIMIT 1');
$stmt->execute($params);
$status = $stmt->fetch();
if (!$status) {
    json_response(['ok' => false, 'error' => 'Request not found.'], 404);
}

$requested = (string) ($status['requested_username'] ?? '');
$recoveryType = 'password';
if ($requested === 'الحساب الرئيسي — استرداد اسم المستخدم') {
    $recoveryType = 'username';
} elseif ($requested === 'الحساب الرئيسي — استرداد بيانات الدخول') {
    $recoveryType = 'account';
}

$publicStatus = $status['status'];
if ($status['status'] === 'rejected' && ($status['admin_note'] ?? '') === '__CLIENT_CANCELLED__') {
    $publicStatus = 'cancelled';
}
$prepared = $publicStatus === 'approved' && PasswordRecovery::isPrepared($requestId);

json_response([
    'ok' => true,
    'status' => $publicStatus,
    'phase' => $prepared ? 'prepared' : $publicStatus,
    'prepared' => $prepared,
    'recovery_type' => $recoveryType,
    'created_at' => $status['created_at'],
    'reviewed_at' => $status['reviewed_at'],
]);
