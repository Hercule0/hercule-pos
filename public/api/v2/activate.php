<?php
require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/../../../includes/MultiEntitlementPolicy.php';
$input = v2_input();
v2_rate_limit('activate', $input);
try {
    $policy = MultiEntitlementPolicy::preflightActivation($input);
    if (!($policy['ok'] ?? false)) {
        v2_signed_response($policy);
    }
    v2_signed_response(EntitlementV2::activate($input, client_ip()));
} catch (Throwable $e) {
    v2_exception_response($e);
}
