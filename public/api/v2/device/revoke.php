<?php
require_once __DIR__ . '/../_common.php';
require_once __DIR__ . '/../../../../includes/ManagerDeviceAuth.php';

$input = v2_input();
v2_rate_limit('device_revoke', $input);
try {
    $targetDeviceUuid = strtolower(trim((string) ($input['device_uuid'] ?? '')));
    $auth = ManagerDeviceAuth::authorizeAction($input, 'device_revoke', $targetDeviceUuid, true);
    if (!($auth['ok'] ?? false)) {
        v2_signed_response($auth);
    }
    $lifecycle = ManagerDeviceAuth::preflightPermanentRevoke((string) ($input['license_key'] ?? ''), $targetDeviceUuid);
    if (!($lifecycle['ok'] ?? false)) {
        v2_signed_response($lifecycle);
    }
    v2_signed_response(EntitlementV2::revokeDevice($input));
} catch (Throwable $e) {
    v2_exception_response($e);
}
