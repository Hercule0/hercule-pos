<?php
require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/../../../includes/MultiEntitlementPolicy.php';

$input = v2_input();
v2_rate_limit('validate', $input);

try {
    $result = EntitlementV2::validate($input, client_ip());
    $bootstrapRequested = filter_var($input['bootstrap_if_unbound'] ?? false, FILTER_VALIDATE_BOOLEAN);

    // Fix438 server bridge: legacy v1 installations may not yet have a
    // store_uuid bound on the server. G6A can request a one-time bootstrap
    // through the already proven validate.php route. This path is deliberately
    // narrow: only an authenticated/signed store_mismatch result may reach the
    // activation logic. A license already bound to another store remains
    // fail-closed because EntitlementV2::activate() re-checks store_uuid before
    // any mutation.
    if ($bootstrapRequested
        && !($result['ok'] ?? false)
        && strtolower((string) ($result['status'] ?? '')) === 'store_mismatch') {
        // Apply the same key/IP activation throttles as the dedicated endpoint.
        v2_rate_limit('activate', $input);

        $policy = MultiEntitlementPolicy::preflightActivation($input);
        if (!($policy['ok'] ?? false)) {
            v2_signed_response($policy);
        }

        $result = EntitlementV2::activate($input, client_ip());
    }

    v2_signed_response($result);
} catch (Throwable $e) {
    v2_exception_response($e);
}
