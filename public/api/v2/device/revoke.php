<?php
require_once __DIR__ . '/../_common.php';
$input = v2_input();
v2_rate_limit('device_revoke', $input);
try {
    v2_signed_response(EntitlementV2::revokeDevice($input));
} catch (Throwable $e) {
    v2_exception_response($e);
}
