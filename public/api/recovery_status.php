<?php
/**
 * POST /api/recovery_status.php
 * Body: { "request_id": 123, "license_key": "..." }
 *
 * Step 3 of the plan: lets the client show submitted / waiting / approved
 * / rejected / expired without ever exposing the authorization token
 * itself (that only comes from recovery_claim.php, and only once).
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
$licenseKey = trim($input['license_key'] ?? '');

if (!$requestId || $licenseKey === '') {
    json_response(['ok' => false, 'error' => 'request_id and license_key are required'], 400);
}
if (strlen($licenseKey) > 29 || !preg_match('/^[A-Z0-9-]+$/', $licenseKey)) {
    json_response(['ok' => false, 'error' => 'Invalid license_key format.'], 400);
}
if (!RateLimiter::check('key:' . $licenseKey, 'recovery_status_by_key', $rateLimitCfg['key_rate_limit_max_requests'], $rateLimitCfg['key_rate_limit_window_minutes'])) {
    json_response(['ok' => false, 'error' => 'Too many status checks for this license. Please try again later.'], 429);
}

$status = PasswordRecovery::statusFor($requestId, $licenseKey);
if (!$status) {
    json_response(['ok' => false, 'error' => 'Request not found.'], 404);
}

json_response([
    'ok' => true,
    'status' => $status['status'],
    'created_at' => $status['created_at'],
    'reviewed_at' => $status['reviewed_at'],
]);
