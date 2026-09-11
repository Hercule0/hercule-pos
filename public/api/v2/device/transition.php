<?php
require_once __DIR__ . '/../_common.php';
require_once __DIR__ . '/../../../../includes/DeviceLicenseTransition.php';
require_once __DIR__ . '/../../../../includes/ManagerDeviceAuth.php';

$input = v2_input();
v2_rate_limit('device_transition', $input, ['target_license_key', 'source_license_key']);
try {
    $result = DeviceLicenseTransition::transition($input, client_ip());
    if (($result['ok'] ?? false) && in_array(strtolower((string) ($input['device_role'] ?? '')), ['manager_server', 'manager_terminal'], true)) {
        $issueRequest = [
            'license_key' => (string) ($input['target_license_key'] ?? ''),
            'hwid' => (string) ($input['hwid'] ?? ''),
            'device_uuid' => (string) ($input['device_uuid'] ?? ''),
            'device_role' => (string) ($input['device_role'] ?? ''),
        ];
        $result = ManagerDeviceAuth::maybeIssueForActivation($issueRequest, $result);
    }
    v2_signed_response($result);
} catch (Throwable $e) {
    v2_exception_response($e);
}
