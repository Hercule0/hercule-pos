<?php
require_once __DIR__ . '/../_bootstrap.php';
require_once __DIR__ . '/../../../includes/RateLimiter.php';
require_once __DIR__ . '/../../../includes/EntitlementV2.php';

function v2_input(): array
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        json_response(['ok' => false, 'error' => 'Method not allowed'], 405);
    }
    $input = json_input();
    if (!is_array($input)) {
        json_response(['ok' => false, 'error' => 'Invalid JSON body'], 400);
    }
    return $input;
}

function v2_rate_limit(string $action, array $input): void
{
    $config = require __DIR__ . '/../../../config/config.php';
    $cfg = $config['security'];
    if (!RateLimiter::check(client_ip(), 'v2_' . $action, $cfg['api_rate_limit_max_requests'], $cfg['api_rate_limit_window_minutes'])) {
        json_response(['ok' => false, 'error' => 'Too many requests. Please try again in a few minutes.'], 429);
    }
    $licenseKey = trim((string) ($input['license_key'] ?? ''));
    if ($licenseKey !== '' && !RateLimiter::check('key:' . $licenseKey, 'v2_' . $action . '_by_key', $cfg['key_rate_limit_max_requests'], $cfg['key_rate_limit_window_minutes'])) {
        json_response(['ok' => false, 'error' => 'Too many requests for this license key. Please try again in a few minutes.'], 429);
    }
}

function v2_signed_response(array $result, int $failureCode = 200): void
{
    $payload = $result['ok'] ?? false
        ? array_merge($result['entitlement'] ?? [], array_filter([
            'device_uuid' => $result['device_uuid'] ?? $result['new_device_uuid'] ?? null,
            'device_role' => $result['device_role'] ?? null,
            'counts_as_terminal' => array_key_exists('counts_as_terminal', $result) ? (bool) $result['counts_as_terminal'] : null,
            'already_revoked' => array_key_exists('already_revoked', $result) ? (bool) $result['already_revoked'] : null,
        ], static fn($v) => $v !== null))
        : [
            'schema_version' => 2,
            'status' => (string) ($result['status'] ?? 'invalid'),
            'error' => (string) ($result['error'] ?? 'Request failed.'),
            'server_time' => gmdate('Y-m-d\TH:i:s\Z'),
        ];

    json_response(
        ['ok' => (bool) ($result['ok'] ?? false)] + RsaSigner::sign($payload),
        ($result['ok'] ?? false) ? 200 : $failureCode
    );
}

function v2_exception_response(Throwable $e): void
{
    $status = $e instanceof InvalidArgumentException ? 400 : 503;
    $payload = [
        'schema_version' => 2,
        'status' => 'request_failed',
        'error' => $e instanceof InvalidArgumentException ? $e->getMessage() : 'Entitlement service is temporarily unavailable.',
        'server_time' => gmdate('Y-m-d\TH:i:s\Z'),
    ];
    json_response(['ok' => false] + RsaSigner::sign($payload), $status);
}
