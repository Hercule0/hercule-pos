<?php
/**
 * POST /api/recovery_request.php
 * Body: { "license_key": "...", "hwid": "...", "username": "..." }
 *
 * Step 2 of the password recovery plan: creates a pending recovery
 * request. Requires Internet connectivity by nature of this being an API
 * call — the client is responsible for explaining that offline (see
 * plan §9).
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
$licenseKey = trim($input['license_key'] ?? '');
$hwid = trim($input['hwid'] ?? '');
$username = trim($input['username'] ?? '');

if ($licenseKey === '' || $hwid === '' || $username === '') {
    json_response(['ok' => false, 'error' => 'license_key, hwid, and username are required'], 400);
}
if (strlen($licenseKey) > 29 || !preg_match('/^[A-Z0-9-]+$/', $licenseKey)) {
    json_response(['ok' => false, 'error' => 'Invalid license_key format.'], 400);
}
if (strlen($hwid) > 128 || preg_match('/[\\x00-\\x1F\\x7F]/', $hwid)) {
    json_response(['ok' => false, 'error' => 'Invalid hwid.'], 400);
}

if (mb_strlen($username) > 64) {
    json_response(['ok' => false, 'error' => 'Username is too long.'], 400);
}

// Second rate limit scoped to the license key itself (same pattern as
// activate.php / validate.php) — stops one leaked license key from being
// used to spam recovery requests from many rotating IPs.
if (!RateLimiter::check('key:' . $licenseKey, 'recovery_request_by_key', $rateLimitCfg['key_rate_limit_max_requests'], $rateLimitCfg['key_rate_limit_window_minutes'])) {
    json_response(['ok' => false, 'error' => 'Too many recovery requests for this license. Please try again later.'], 429);
}

$result = PasswordRecovery::createRequest($licenseKey, $hwid, $username, client_ip());
json_response($result, $result['ok'] ? 200 : 400);
