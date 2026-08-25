<?php
/**
 * POST /api/recovery_prepare.php
 * Body: { "request_id": 123, "license_key": "...", "hwid": "...", "token": "..." }
 *
 * Two-phase recovery commit. The server validates the claimed token while the
 * normal approval window is still live and freezes that exact token as a
 * durable completion proof. No new username/password is ever sent here.
 */

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../includes/RateLimiter.php';
require_once __DIR__ . '/../../includes/PasswordRecovery.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'error' => 'Method not allowed'], 405);
}

$config = require __DIR__ . '/../../config/config.php';
$rateLimitCfg = $config['security'];

if (!RateLimiter::check(client_ip(), 'recovery_prepare', $rateLimitCfg['api_rate_limit_max_requests'], $rateLimitCfg['api_rate_limit_window_minutes'])) {
    json_response(['ok' => false, 'error' => 'Too many requests. Please try again in a few minutes.'], 429);
}

$input = json_input();
$requestId = (int) ($input['request_id'] ?? 0);
$licenseKey = trim((string) ($input['license_key'] ?? ''));
$hwid = trim((string) ($input['hwid'] ?? ''));
$token = trim((string) ($input['token'] ?? ''));

if (!$requestId || $licenseKey === '' || $hwid === '' || $token === '') {
    json_response(['ok' => false, 'error' => 'request_id, license_key, hwid, and token are required'], 400);
}
if (strlen($licenseKey) > 29 || !preg_match('/^[A-Z0-9-]+$/', $licenseKey)) {
    json_response(['ok' => false, 'error' => 'Invalid license_key format.'], 400);
}
if (strlen($hwid) > 128 || preg_match('/[\x00-\x1F\x7F]/', $hwid)) {
    json_response(['ok' => false, 'error' => 'Invalid hwid.'], 400);
}
if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
    json_response(['ok' => false, 'error' => 'Invalid authorization token format.'], 400);
}
if (!RateLimiter::check('key:' . $licenseKey, 'recovery_prepare_by_key', $rateLimitCfg['key_rate_limit_max_requests'], $rateLimitCfg['key_rate_limit_window_minutes'])) {
    json_response(['ok' => false, 'error' => 'Too many prepare attempts for this license. Please try again later.'], 429);
}

$result = PasswordRecovery::prepare($requestId, $licenseKey, $hwid, $token, client_ip());
json_response($result, $result['ok'] ? 200 : 400);
