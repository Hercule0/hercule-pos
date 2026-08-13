<?php
/**
 * POST /api/validate.php
 * Body: { "license_key": "...", "hwid": "..." }
 *
 * Runtime check — called on app launch and periodically thereafter (Phase 5
 * decides the interval and adds clock-tamper detection using the
 * `server_time` field). Requires the hwid to already be activated.
 *
 * The response is signed with RSA so the desktop app can verify it wasn't
 * forged or replayed from an old response, even over plain HTTP.
 */

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../includes/RateLimiter.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'error' => 'Method not allowed'], 405);
}

$config = require __DIR__ . '/../../config/config.php';
$rateLimitCfg = $config['security'];
if (!RateLimiter::check(client_ip(), 'validate', $rateLimitCfg['api_rate_limit_max_requests'], $rateLimitCfg['api_rate_limit_window_minutes'])) {
    json_response(['ok' => false, 'error' => 'Too many requests. Please try again in a few minutes.'], 429);
}

$input = json_input();
$licenseKey = trim($input['license_key'] ?? '');
$hwid = trim($input['hwid'] ?? '');

if ($licenseKey === '' || $hwid === '') {
    json_response(['ok' => false, 'error' => 'license_key and hwid are required'], 400);
}

// License System Upgrade Plan §19 — same key-scoped rate limit as
// activate.php (see that file's comment). Slightly more headroom is fine
// here since the desktop app's OWN background scheduler now also caches
// and de-duplicates its validation calls (see LicenseManager.js), so
// legitimate traffic per key should be low and steady, not bursty.
if (!RateLimiter::check('key:' . $licenseKey, 'validate_by_key', $rateLimitCfg['key_rate_limit_max_requests'], $rateLimitCfg['key_rate_limit_window_minutes'])) {
    json_response(['ok' => false, 'error' => 'Too many validation attempts for this license key. Please try again in a few minutes.'], 429);
}

$result = License::validate($licenseKey, $hwid, client_ip());

// License System Upgrade Plan — Phase 6 (Realtime Notification). A real,
// signed validation for this key just happened — whatever "something
// changed" markers check_update.php was reporting are now stale, since
// the desktop app is about to receive the actual current, trusted state
// below. Consumed regardless of whether validation succeeded or failed:
// either way the app now has an authoritative answer for this moment.
License::consumePendingChanges($licenseKey);

if (!$result['ok']) {
    // Still sign failure responses — otherwise a network attacker could
    // strip the signature from a "no" response and the app would have no
    // way to tell a forged failure from a real one either.
    $payload = [
        'status' => 'invalid',
        'error' => $result['error'],
        'server_time' => gmdate('Y-m-d\TH:i:s\Z'),
    ];
    json_response(['ok' => false] + RsaSigner::sign($payload));
}

$payload = [
    'status' => $result['license']['status'],
    'plan' => $result['license']['plan'],
    'expires_at' => $result['license']['expires_at'],
    'server_time' => gmdate('Y-m-d\TH:i:s\Z'),
];

json_response(['ok' => true] + RsaSigner::sign($payload));
