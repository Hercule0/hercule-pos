<?php
require_once __DIR__ . '/../_common.php';
require_once __DIR__ . '/../../../../includes/ManagerDeviceAuth.php';

$input = v2_input();
v2_rate_limit('device_replace', $input);
try {
    $oldDeviceUuid = strtolower(trim((string) ($input['old_device_uuid'] ?? '')));
    $auth = ManagerDeviceAuth::authorizeAction($input, 'device_replace', $oldDeviceUuid, false);
    if (!($auth['ok'] ?? false)) {
        v2_signed_response($auth);
    }
    v2_signed_response(EntitlementV2::replaceDevice($input, client_ip()));
} catch (Throwable $e) {
    v2_exception_response($e);
}
