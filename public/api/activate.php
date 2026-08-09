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
$licenseKey = trim($input['license_key'] ?? '');
$hwid = trim($input['hwid'] ?? '');

if ($licenseKey === '' || $hwid === '') {
    json_response(['ok' => false, 'error' => 'license_key and hwid are required'], 400);
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
