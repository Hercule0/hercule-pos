<?php

require_once __DIR__ . '/../_bootstrap.php';
require_once __DIR__ . '/../../../includes/Entitlement.php';
require_once __DIR__ . '/../../../includes/RateLimiter.php';

function v2_input_identity(array $input): array
{
    $licenseKey = trim((string) (
        $input['license_key'] ?? $input['licenseKey'] ?? $input['key'] ?? $input['license'] ?? ''
    ));
    $hwid = trim((string) (
        $input['hwid'] ?? $input['hardware_id'] ?? $input['hardwareId'] ??
        $input['device_id'] ?? $input['deviceId'] ?? $input['machine_id'] ?? ''
    ));
    $appVersion = trim((string) ($input['app_version'] ?? $input['appVersion'] ?? $input['version'] ?? ''));
    $protocolVersion = max(1, min(100000, (int) ($input['protocol_version'] ?? $input['protocolVersion'] ?? 2)));

    if ($licenseKey === '' || $hwid === '') {
        v2_signed_error('invalid_request', 'license_key and hwid are required', 400);
    }
    if (strlen($licenseKey) > 64 || preg_match('/[\x00-\x1F\x7F]/', $licenseKey)) {
        v2_signed_error('invalid_request', 'Invalid license_key.', 400);
    }
    if (strlen($hwid) > 160 || preg_match('/[\x00-\x1F\x7F]/', $hwid)) {
        v2_signed_error('invalid_request', 'Invalid hwid.', 400);
    }
    if (strlen($appVersion) > 50 || preg_match('/[\x00-\x1F\x7F]/', $appVersion)) {
        v2_signed_error('invalid_request', 'Invalid app_version.', 400);
    }

    return [$licenseKey, $hwid, $appVersion, $protocolVersion];
}

function v2_signed_error(string $code, string $message, int $httpStatus = 200): void
{
    $payload = [
        'schema_version' => 2,
        'status' => 'invalid',
        'code' => $code,
        'error' => $message,
        'server_time' => gmdate('Y-m-d\TH:i:s\Z'),
    ];
    json_response(['ok' => false] + RsaSigner::sign($payload), $httpStatus);
}

function v2_signed_success(array $payload): void
{
    json_response(['ok' => true] + RsaSigner::sign($payload));
}

function v2_rate_limit(string $licenseKey, string $endpoint): void
{
    $config = require __DIR__ . '/../../../config/config.php';
    $security = $config['security'];

    if (!RateLimiter::check(
        client_ip(),
        'v2_' . $endpoint,
        $security['api_rate_limit_max_requests'],
        $security['api_rate_limit_window_minutes']
    )) {
        v2_signed_error('rate_limited', 'Too many requests. Please try again in a few minutes.', 429);
    }

    if (!RateLimiter::check(
        'key:' . $licenseKey,
        'v2_' . $endpoint . '_by_key',
        $security['key_rate_limit_max_requests'],
        $security['key_rate_limit_window_minutes']
    )) {
        v2_signed_error('rate_limited', 'Too many requests for this license key. Please try again in a few minutes.', 429);
    }
}
