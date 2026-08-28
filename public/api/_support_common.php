<?php

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../includes/RateLimiter.php';
require_once __DIR__ . '/../../includes/SupportTicket.php';

function support_credentials(array $input): array
{
    $licenseKey = strtoupper(trim((string)(
        $input['license_key'] ?? $input['licenseKey'] ?? $input['key'] ?? ''
    )));
    $hwid = trim((string)(
        $input['hwid'] ?? $input['hardware_id'] ?? $input['hardwareId'] ?? $input['device_id'] ?? $input['deviceId'] ?? ''
    ));

    if ($licenseKey === '' || $hwid === '') {
        json_response(['ok' => false, 'error' => 'license_key and hwid are required.'], 400);
    }

    return [$licenseKey, $hwid];
}

function support_rate_guard(string $licenseKey, string $endpoint): void
{
    $config = require __DIR__ . '/../../config/config.php';
    $security = $config['security'];

    if (!RateLimiter::check(
        client_ip(),
        $endpoint,
        (int)$security['api_rate_limit_max_requests'],
        (int)$security['api_rate_limit_window_minutes']
    )) {
        json_response(['ok' => false, 'error' => 'Too many requests. Please try again shortly.'], 429);
    }

    if (!RateLimiter::check(
        'key:' . $licenseKey,
        $endpoint . '_by_key',
        (int)$security['key_rate_limit_max_requests'],
        (int)$security['key_rate_limit_window_minutes']
    )) {
        json_response(['ok' => false, 'error' => 'Too many support requests for this license. Please try again shortly.'], 429);
    }
}

function support_json_result(array $result, int $successStatus = 200): void
{
    if (!empty($result['ok'])) {
        json_response($result, $successStatus);
    }

    $status = (int)($result['status'] ?? 400);
    unset($result['status']);
    json_response($result, $status);
}
