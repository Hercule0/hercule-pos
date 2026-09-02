<?php
/**
 * POST /public/api/v2/validate.php
 * Runtime validation for an already activated v2 device.
 */

require_once __DIR__ . '/_bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    v2_signed_error('method_not_allowed', 'Method not allowed', 405);
}

$input = json_input();
[$licenseKey, $hwid, $appVersion, $protocolVersion] = v2_input_identity($input);
v2_rate_limit($licenseKey, 'validate');
v2_ensure_identity($licenseKey, $hwid);

$result = Entitlement::validateDevice(
    $licenseKey,
    $hwid,
    $appVersion !== '' ? $appVersion : null,
    $protocolVersion,
    client_ip()
);

if (!$result['ok']) {
    v2_signed_error(
        (string) ($result['code'] ?? 'validation_failed'),
        (string) ($result['error'] ?? 'Validation failed.')
    );
}

v2_signed_success($result['payload']);
