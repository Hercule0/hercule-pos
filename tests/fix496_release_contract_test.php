<?php
$root = dirname(__DIR__);
$failures = [];

function f496c_check(string $label, bool $ok): void {
    global $failures;
    echo ($ok ? '[PASS] ' : '[FAIL] ') . $label . "\n";
    if (!$ok) $failures[] = $label;
}

$read = static function (string $relative) use ($root): string {
    $path = $root . '/' . $relative;
    $content = is_file($path) ? file_get_contents($path) : false;
    return is_string($content) ? $content : '';
};

$common = $read('public/api/v2/_common.php');
$activate = $read('public/api/v2/activate.php');
$transition = $read('public/api/v2/device/transition.php');
$release = $read('public/api/v2/device/release.php');
$revoke = $read('public/api/v2/device/revoke.php');
$replace = $read('public/api/v2/device/replace.php');
$managerAuth = $read('includes/ManagerDeviceAuth.php');
$transitionLogic = $read('includes/DeviceLicenseTransition.php');
$attestation = $read('includes/G1ProductionAttestation.php');
$probe = $read('scripts/check_entitlement_v2_routes.sh');
$runner = $read('tests/run_regressions.php');

f496c_check('transition rate limit explicitly covers target license key', str_contains($transition, "['target_license_key', 'source_license_key']"));
f496c_check('transition rate limit explicitly covers source license key', str_contains($transition, 'source_license_key'));
f496c_check('v2 rate limiter accepts explicit license-key fields', str_contains($common, "array $licenseKeyFields = ['license_key']"));

foreach (['source_released', 'duplicate', 'transition_id', 'manager_auth_required', 'manager_auth_token_issued', 'manager_auth_token'] as $field) {
    f496c_check("signed v2 payload exposes {$field}", str_contains($common, "'{$field}'"));
}

f496c_check('activate performs manager provisioning policy before activation', str_contains($activate, 'MultiEntitlementPolicy::preflightActivation'));
f496c_check('activate issues manager capability only after successful activation', str_contains($activate, 'ManagerDeviceAuth::maybeIssueForActivation'));
f496c_check('manager capability raw token is removed defensively before issuance decision', str_contains($managerAuth, "unset($result['manager_auth_token'], $result['manager_auth_token_issued'])"));
f496c_check('manager capability is fingerprinted with SHA-256', str_contains($managerAuth, "hash('sha256', self::TOKEN_DOMAIN . $token)"));
f496c_check('manager server bootstrap requires Multi entitlement', str_contains($managerAuth, "multi_not_entitled"));
f496c_check('second manager server is explicitly blocked', str_contains($managerAuth, 'manager_server_already_established'));
f496c_check('legacy exact main-device promotion path exists', str_contains($managerAuth, 'legacy_manager_promotion'));
f496c_check('new manager terminal requires manager authorization', str_contains($managerAuth, 'manager_authorization_required'));

foreach ([
    'release' => $release,
    'revoke' => $revoke,
    'replace' => $replace,
] as $name => $source) {
    f496c_check("{$name} route imports ManagerDeviceAuth", str_contains($source, 'ManagerDeviceAuth.php'));
    f496c_check("{$name} route calls manager action authorization", str_contains($source, 'ManagerDeviceAuth::authorizeAction'));
}

f496c_check('strict transition rejects missing explicit source activation', str_contains($transitionLogic, 'source_activation_missing'));
f496c_check('strict transition rejects incomplete inactive source move', str_contains($transitionLogic, 'source_activation_inactive'));
f496c_check('transition emits stable opaque transition id', str_contains($transitionLogic, 'transition_id'));
f496c_check('transition manager roles pass through manager provisioning policy', str_contains($transitionLogic, 'authorizeManagerProvisioning'));

foreach ([
    'includes/ManagerDeviceAuth.php',
    'includes/DeviceLicenseTransition.php',
    'public/api/v2/device/transition.php',
    'public/api/v2/device/release.php',
    'public/api/v2/device/revoke.php',
    'public/api/v2/device/replace.php',
    'fix496-test-evidence.json',
] as $needle) {
    f496c_check("G1 final attestation binds {$needle}", str_contains($attestation, $needle));
}

foreach ([
    '/public/api/v2/activate.php',
    '/public/api/v2/validate.php',
    '/public/api/v2/device/transition.php',
    '/public/api/v2/device/release.php',
    '/public/api/v2/device/revoke.php',
    '/public/api/v2/device/replace.php',
] as $route) {
    f496c_check("production probe covers {$route}", str_contains($probe, "probe_route \"{$route}\""));
}

f496c_check('Fix496 evidence is generated only by the complete regression runner', str_contains($runner, 'FIX496_MULTI_FINAL_GATE_PASS'));
f496c_check('Fix496 evidence binds source hashes', str_contains($runner, "'source_files' => $sourceEvidence"));
f496c_check('Fix496 evidence includes secure Single-to-Multi upgrade test', str_contains($runner, "'multi_manager_upgrade_test.php'"));

if ($failures) {
    fwrite(STDERR, 'Fix496 release contract failures: ' . implode(', ', $failures) . "\n");
    exit(1);
}

echo "PASS Fix496 final Multi release contract — manager-auth=true transition-strict=true dual-key-rate-limit=true full-route-probe=true evidence-bound=true\n";
