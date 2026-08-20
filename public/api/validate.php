<?php
/**
 * POST /api/validate.php
 * Body: { "license_key": "...", "hwid": "...", "app_version": "..." }
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
require_once __DIR__ . '/../../includes/DeviceManager.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'error' => 'Method not allowed'], 405);
}

$config = require __DIR__ . '/../../config/config.php';
$rateLimitCfg = $config['security'];
if (!RateLimiter::check(client_ip(), 'validate', $rateLimitCfg['api_rate_limit_max_requests'], $rateLimitCfg['api_rate_limit_window_minutes'])) {
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
$appVersion = trim((string) (
    $input['app_version'] ??
    $input['appVersion'] ??
    $input['version'] ?? ''
));

if ($licenseKey === '' || $hwid === '') {
    json_response(['ok' => false, 'error' => 'license_key and hwid are required'], 400);
}

if (!RateLimiter::check('key:' . $licenseKey, 'validate_by_key', $rateLimitCfg['key_rate_limit_max_requests'], $rateLimitCfg['key_rate_limit_window_minutes'])) {
    json_response(['ok' => false, 'error' => 'Too many validation attempts for this license key. Please try again in a few minutes.'], 429);
}

if (DeviceManager::isBlocked($licenseKey, $hwid)) {
    License::consumePendingChanges($licenseKey);
    $payload = [
        'status' => 'device_blocked',
        'error' => 'This device has been blocked by the license administrator.',
        'server_time' => gmdate('Y-m-d\TH:i:s\Z'),
    ];
    json_response(['ok' => false] + RsaSigner::sign($payload));
}

$result = License::validate($licenseKey, $hwid, client_ip());

License::consumePendingChanges($licenseKey);

if (!$result['ok']) {
    $payload = [
        'status' => 'invalid',
        'error' => $result['error'],
        'server_time' => gmdate('Y-m-d\TH:i:s\Z'),
    ];
    json_response(['ok' => false] + RsaSigner::sign($payload));
}

DeviceManager::recordClientVersion($licenseKey, $hwid, $appVersion);

$payload = [
    'status' => $result['license']['status'],
    'plan' => $result['license']['plan'],
    'expires_at' => $result['license']['expires_at'],
    'server_time' => gmdate('Y-m-d\TH:i:s\Z'),
];

json_response(['ok' => true] + RsaSigner::sign($payload));
