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
$check('normal validate still runs first', strpos($validate, 'EntitlementV2::validate') < strpos($validate, 'bootstrap_if_unbound'));
$check('bootstrap reuses activation rate-limit bucket', str_contains($validate, "v2_rate_limit('activate'"));
$check('bootstrap applies Multi entitlement preflight policy', str_contains($validate, 'MultiEntitlementPolicy::preflightActivation'));
$check('bootstrap delegates to authoritative EntitlementV2 activation', str_contains($validate, 'EntitlementV2::activate'));
$check('bootstrap does not bypass signed response path', str_contains($validate, 'v2_signed_response($result)'));

if ($failures) {
    fwrite(STDERR, 'Fix438 validate-bootstrap failures: ' . implode(', ', $failures) . "\n");
    exit(1);
}

echo "PASS Fix438 Entitlement v2 validate bootstrap — explicit opt-in, store-mismatch-only, policy-gated, signed, fail-closed\n";
