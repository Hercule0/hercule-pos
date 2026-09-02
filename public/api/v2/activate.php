<?php
/**
 * POST /public/api/v2/activate.php
 * Body: { license_key, hwid, app_version?, protocol_version? }
 */

require_once __DIR__ . '/_bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    v2_signed_error('method_not_allowed', 'Method not allowed', 405);
}

$input = json_input();
[$licenseKey, $hwid, $appVersion, $protocolVersion] = v2_input_identity($input);
v2_rate_limit($licenseKey, 'activate');
v2_ensure_identity($licenseKey, $hwid);

// Public activation may only claim a terminal role. Management-only devices
// are created later through an authenticated manager/pairing workflow.
$role = trim((string) ($input['device_role'] ?? 'cashier_terminal'));
if (!in_array($role, ['single_terminal', 'cashier_terminal', 'manager_terminal'], true)) {
    v2_signed_error('invalid_device_role', 'Public activation only accepts terminal roles.', 400);
}

$result = Entitlement::activateTerminal(
    $licenseKey,
    $hwid,
    $appVersion !== '' ? $appVersion : null,
    $protocolVersion,
    $role,
    client_ip()
);

if (!$result['ok']) {
    v2_signed_error(
        (string) ($result['code'] ?? 'activation_failed'),
        (string) ($result['error'] ?? 'Activation failed.')
    );
}

v2_signed_success($result['payload']);
