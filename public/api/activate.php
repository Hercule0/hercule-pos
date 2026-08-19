<?php
/**
 * POST /api/activate.php
 * Body: { "license_key": "...", "hwid": "..." }
 *
 * First-time device activation. Called once by the desktop app when a
 * license key is entered, before the trial/paid period begins tracking
 * this specific machine.
 */

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../includes/RateLimiter.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'error' => 'Method not allowed'], 405);
}

$config = require __DIR__ . '/../../config/config.php';
$rateLimitCfg = $config['security'];
if (!RateLimiter::check(client_ip(), 'activate', $rateLimitCfg['api_rate_limit_max_requests'], $rateLimitCfg['api_rate_limit_window_minutes'])) {
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

if ($licenseKey === '' || $hwid === '') {
    json_response(['ok' => false, 'error' => 'license_key and hwid are required'], 400);
}

// License System Upgrade Plan §19 — a SECOND rate limit, scoped to the
// license key itself (not the caller's IP). Reuses the existing generic
// RateLimiter with a synthetic identifier and a distinct endpoint label
// ('activate_by_key' vs 'activate') so this counts in its own window
// rather than sharing/colliding with the per-IP counter above. This is
// what stops someone from hammering one stolen/leaked key from many
// different IPs to route around the per-IP limit.
if (!RateLimiter::check('key:' . $licenseKey, 'activate_by_key', $rateLimitCfg['key_rate_limit_max_requests'], $rateLimitCfg['key_rate_limit_window_minutes'])) {
    json_response(['ok' => false, 'error' => 'Too many activation attempts for this license key. Please try again in a few minutes.'], 429);
}

$result = License::activate($licenseKey, $hwid, client_ip());

if (!$result['ok']) {
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
