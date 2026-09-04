<?php
require_once __DIR__ . '/_common.php';
$input = v2_input();
v2_rate_limit('entitlement', $input);
try {
    // Entitlement is returned only after the requesting device passes the same
    // store/device identity validation as a normal runtime validation.
    v2_signed_response(EntitlementV2::validate($input, client_ip()));
} catch (Throwable $e) {
    v2_exception_response($e);
}
