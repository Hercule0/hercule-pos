<?php
/**
 * POST /api/activate.php
 * Body: { "license_key": "...", "hwid": "...", "app_version": "..." }
 *
 * Backwards-compatible v1 activation. Fix408 preserves the exact v1 signed
 * response contract while closing the inactive-HWID seat bypass.
 */

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../includes/RateLimiter.php';
require_once __DIR__ . '/../../includes/DeviceManager.php';
require_once __DIR__ . '/../../includes/EntitlementV2.php';

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
$appVersion = trim((string) (
    $input['app_version'] ??
    $input['appVersion'] ??
    $input['version'] ?? ''
));

if ($licenseKey === '' || $hwid === '') {
    json_response(['ok' => false, 'error' => 'license_key and hwid are required'], 400);
}
if (strlen($licenseKey) > 64 || preg_match('/[\x00-\x1F\x7F]/', $licenseKey)) {
    json_response(['ok' => false, 'error' => 'Invalid license_key.'], 400);
}
if (strlen($hwid) > 160 || preg_match('/[\x00-\x1F\x7F]/', $hwid)) {
    json_response(['ok' => false, 'error' => 'Invalid hwid.'], 400);
}
if (strlen($appVersion) > 50 || preg_match('/[\x00-\x1F\x7F]/', $appVersion)) {
    json_response(['ok' => false, 'error' => 'Invalid app_version.'], 400);
}

if (!RateLimiter::check('key:' . $licenseKey, 'activate_by_key', $rateLimitCfg['key_rate_limit_max_requests'], $rateLimitCfg['key_rate_limit_window_minutes'])) {
    json_response(['ok' => false, 'error' => 'Too many activation attempts for this license key. Please try again in a few minutes.'], 429);
}

// A blocked HWID stays blocked even if its activation slot was reset.
if (DeviceManager::isBlocked($licenseKey, $hwid)) {
    $payload = [
        'status' => 'device_blocked',
        'error' => 'This device has been blocked by the license administrator.',
        'server_time' => gmdate('Y-m-d\TH:i:s\Z'),
    ];
    json_response(['ok' => false] + RsaSigner::sign($payload));
}

// Fix408: serialize all v1 seat decisions per license. Existing inactive HWIDs
// no longer jump directly back to is_active=1 when the last seat is occupied,
// and a final-revoked device can never silently return through the old API.
$result = EntitlementV2::withSeatLock($licenseKey, static function () use ($licenseKey, $hwid): array {
    $preflight = EntitlementV2::preflightLegacyActivation($licenseKey, $hwid);
    if (!($preflight['ok'] ?? false)) {
        return $preflight;
    }
    return License::activate($licenseKey, $hwid, client_ip());
});

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
