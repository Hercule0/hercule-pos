<?php
require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/../../../includes/MultiEntitlementPolicy.php';
require_once __DIR__ . '/../../../includes/ManagerDeviceAuth.php';

$input = v2_input();
v2_rate_limit('activate', $input);
try {
    $policy = MultiEntitlementPolicy::preflightActivation($input);
    if (!($policy['ok'] ?? false)) {
        v2_signed_response($policy);
    }

    $result = EntitlementV2::activate($input, client_ip());
    if ($result['ok'] ?? false) {
        $result = ManagerDeviceAuth::maybeIssueForActivation($input, $result);
    }
    v2_signed_response($result);
} catch (Throwable $e) {
    v2_exception_response($e);
}
