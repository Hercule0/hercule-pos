<?php
$root = dirname(__DIR__);
$script = file_get_contents($root . '/scripts/check_entitlement_v2_routes.sh');
$workflow = file_get_contents($root . '/.github/workflows/deploy.yml');
$failures = [];

$check = static function (string $label, bool $ok) use (&$failures): void {
    echo ($ok ? '[PASS] ' : '[FAIL] ') . $label . "\n";
    if (!$ok) $failures[] = $label;
};

$check('probe covers v2 validate route', str_contains($script, '/public/api/v2/validate.php'));
$check('probe covers v2 activate route', str_contains($script, '/public/api/v2/activate.php'));
$check('probe requires HTTP 400 for empty-body contract', str_contains($script, 'expected signed JSON HTTP 400'));
$check('probe requires schema_version 2', str_contains($script, "schema_version') or 0) != 2"));
$check('probe requires RSA signature', str_contains($script, 'missing RSA signature'));
$check('deployment workflow invokes Entitlement v2 probe', str_contains($workflow, 'check_entitlement_v2_routes.sh'));
$check('deployment workflow runs probe after app health', strpos($workflow, 'Verify deployed application health') < strpos($workflow, 'Verify Entitlement v2 production routes'));

if ($failures) {
    fwrite(STDERR, 'Fix435 deploy probe failures: ' . implode(', ', $failures) . "\n");
    exit(1);
}

echo "PASS Fix435 Entitlement v2 deploy probe — validate+activate live-route verification is release-blocking\n";
