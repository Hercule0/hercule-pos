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

    $rolePolicy = ManagerDeviceAuth::preflightReplacementRole(
        (string) ($input['license_key'] ?? ''),
        $oldDeviceUuid,
        (string) ($input['device_role'] ?? 'cashier_terminal')
    );
    if (!($rolePolicy['ok'] ?? false)) {
        v2_signed_response($rolePolicy);
    }

    $result = EntitlementV2::replaceDevice($input, client_ip());
    if (($result['ok'] ?? false) && in_array(strtolower((string) ($input['device_role'] ?? '')), ['manager_server', 'manager_terminal'], true)) {
        $issueRequest = [
            'license_key' => (string) ($input['license_key'] ?? ''),
            'hwid' => (string) ($input['new_hwid'] ?? ''),
            'device_uuid' => (string) ($input['new_device_uuid'] ?? ''),
            'device_role' => (string) ($input['device_role'] ?? ''),
        ];
        $result = ManagerDeviceAuth::maybeIssueForActivation($issueRequest, $result);
    }
    v2_signed_response($result);
} catch (Throwable $e) {
    v2_exception_response($e);
}
