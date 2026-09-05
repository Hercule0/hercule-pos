<?php
$root = dirname(__DIR__);
$validate = file_get_contents($root . '/public/api/v2/validate.php');
$failures = [];

$check = static function (string $label, bool $ok) use (&$failures): void {
    echo ($ok ? '[PASS] ' : '[FAIL] ') . $label . "\n";
    if (!$ok) $failures[] = $label;
};

$check('validate bootstrap is explicit opt-in', str_contains($validate, "bootstrap_if_unbound"));
$check('bootstrap is restricted to store_mismatch', str_contains($validate, "=== 'store_mismatch'"));
$check('normal validate still runs before activation', strpos($validate, 'EntitlementV2::validate') < strpos($validate, 'EntitlementV2::activate'));
$check('bootstrap reuses activation rate-limit bucket', str_contains($validate, "v2_rate_limit('activate'"));
$check('bootstrap applies Multi entitlement preflight policy', str_contains($validate, 'MultiEntitlementPolicy::preflightActivation'));
$check('bootstrap delegates to authoritative EntitlementV2 activation', str_contains($validate, 'EntitlementV2::activate'));
$check('bootstrap does not bypass signed response path', str_contains($validate, 'v2_signed_response($result)'));

// Fix443 tracing must be useful enough to isolate the exact server stage while
// remaining safe for production logs.
$check('bootstrap trace uses correlation request id', str_contains($validate, 'ErrorHandler::requestId()'));
$check('bootstrap trace has request received marker', str_contains($validate, "'request_received'"));
$check('bootstrap trace has validate return marker', str_contains($validate, "'validate_return'"));
$check('bootstrap trace has activation rate-limit marker', str_contains($validate, "'activation_rate_limit_ok'"));
$check('bootstrap trace has policy marker', str_contains($validate, "'policy_return'"));
$check('bootstrap trace has activate enter marker', str_contains($validate, "'activate_enter'"));
$check('bootstrap trace has activate return marker', str_contains($validate, "'activate_return'"));
$check('bootstrap trace has signed response marker', str_contains($validate, "'signed_response_enter'"));
$check('bootstrap trace never logs raw request input', !str_contains($validate, 'json_encode($input') && !str_contains($validate, 'error_log($input'));

if ($failures) {
    fwrite(STDERR, 'Fix438/Fix443 validate-bootstrap failures: ' . implode(', ', $failures) . "\n");
    exit(1);
}

echo "PASS Fix438/Fix443 Entitlement v2 validate bootstrap — explicit opt-in, store-mismatch-only, policy-gated, signed, fail-closed, stage-traced\n";
