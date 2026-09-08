<?php
require_once __DIR__ . '/../_common.php';
require_once __DIR__ . '/../../../../includes/DeviceLicenseTransition.php';

$input = v2_input();
v2_rate_limit('device_transition', $input);
try {
    v2_signed_response(DeviceLicenseTransition::transition($input, client_ip()));
} catch (Throwable $e) {
    v2_exception_response($e);
}
