<?php
/**
 * POST /api/recovery_claim.php
 * Body: { "request_id": 123, "license_key": "...", "hwid": "..." }
 *
 * Called by the client once it sees status === 'approved'. Returns a
 * fresh single-use authorization token exactly once, bound to the SAME
 * device that originally submitted the request. This is an intermediate
 * step — the token still has to be presented (and is re-validated in
 * full) at recovery_reset.php before anything is actually consumed.
 */

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../includes/RateLimiter.php';
require_once __DIR__ . '/../../includes/PasswordRecovery.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'error' => 'Method not allowed'], 405);
}

$config = require __DIR__ . '/../../config/config.php';
$rateLimitCfg = $config['security'];

if (!RateLimiter::check(client_ip(), 'recovery_claim', $rateLimitCfg['api_rate_limit_max_requests'], $rateLimitCfg['api_rate_limit_window_minutes'])) {
    json_response(['ok' => false, 'error' => 'Too many requests. Please try again in a few minutes.'], 429);
}

$input = json_input();
$requestId = (int) ($input['request_id'] ?? 0);
$licenseKey = trim($input['license_key'] ?? '');
$hwid = trim($input['hwid'] ?? '');

if (!$requestId || $licenseKey === '' || $hwid === '') {
    json_response(['ok' => false, 'error' => 'request_id, license_key, and hwid are required'], 400);
}

$result = PasswordRecovery::claim($requestId, $licenseKey, $hwid, client_ip());
json_response($result, $result['ok'] ? 200 : 400);
