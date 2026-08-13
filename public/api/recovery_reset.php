<?php
/**
 * POST /api/recovery_reset.php
 * Body: { "request_id": 123, "license_key": "...", "hwid": "...", "token": "..." }
 *
 * Final step of the recovery flow. Validates and CONSUMES (single-use)
 * the authorization issued by recovery_claim.php. Deliberately does NOT
 * accept a new password/username here — the server is only the
 * authority over whether the authorization is legitimate; applying the
 * credential change happens locally on the client immediately after this
 * call succeeds (see RecoveryManager.js / main.js's recovery:completeReset
 * handler on the desktop app side).
 */

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../includes/RateLimiter.php';
require_once __DIR__ . '/../../includes/PasswordRecovery.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'error' => 'Method not allowed'], 405);
}

$config = require __DIR__ . '/../../config/config.php';
$rateLimitCfg = $config['security'];

if (!RateLimiter::check(client_ip(), 'recovery_reset', $rateLimitCfg['api_rate_limit_max_requests'], $rateLimitCfg['api_rate_limit_window_minutes'])) {
    json_response(['ok' => false, 'error' => 'Too many requests. Please try again in a few minutes.'], 429);
}

$input = json_input();
$requestId = (int) ($input['request_id'] ?? 0);
$licenseKey = trim($input['license_key'] ?? '');
$hwid = trim($input['hwid'] ?? '');
$token = trim($input['token'] ?? '');

if (!$requestId || $licenseKey === '' || $hwid === '' || $token === '') {
    json_response(['ok' => false, 'error' => 'request_id, license_key, hwid, and token are required'], 400);
}

$result = PasswordRecovery::reset($requestId, $licenseKey, $hwid, $token, client_ip());
json_response($result, $result['ok'] ? 200 : 400);
