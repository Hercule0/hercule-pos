<?php
/**
 * POST /public/api/v2/entitlement.php
 * Returns the current signed entitlement for an active device.
 */

require_once __DIR__ . '/_bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    v2_signed_error('method_not_allowed', 'Method not allowed', 405);
}

$input = json_input();
[$licenseKey, $hwid] = v2_input_identity($input);
v2_rate_limit($licenseKey, 'entitlement');

$result = Entitlement::entitlementForDevice($licenseKey, $hwid);
if (!$result['ok']) {
    v2_signed_error(
        (string) ($result['code'] ?? 'entitlement_failed'),
        (string) ($result['error'] ?? 'Entitlement lookup failed.')
    );
}

v2_signed_success($result['payload']);
