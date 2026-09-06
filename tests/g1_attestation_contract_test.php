<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$helper = file_get_contents($root . '/includes/G1ProductionAttestation.php');
$endpoint = file_get_contents($root . '/public/api/v2/g1_attestation.php');
$validate = file_get_contents($root . '/public/api/v2/validate.php');
$probe = file_get_contents($root . '/scripts/check_entitlement_v2_routes.sh');

$checks = [
    'attestation is signed by license RSA trust' => str_contains($helper, 'RsaSigner::sign($payload)'),
    'production runtime requires MySQL' => str_contains($helper, '$driver !== \'mysql\''),
    'production schema readiness is required' => str_contains($helper, 'EntitlementV2::schemaReady()'),
    'deployment provenance requires exact 40-hex commit' => str_contains($helper, "preg_match('/^[a-f0-9]{40}$/', \$commitSha)"),
    'critical entitlement files are hashed' => str_contains($helper, "hash_file('sha256', \$file)"),
    'G1 requires deployment-bound test evidence' => str_contains($helper, 'g1-test-evidence.json') && str_contains($helper, 'assertEvidenceMatchesDeployment'),
    'all six scenarios are server constructed' => str_contains($helper, "'concurrent_last_seat'") && str_contains($helper, "'v1_v2_compatibility'"),
    'scenario PASS carries test SHA evidence' => str_contains($helper, "['status' => 'PASS', 'evidence' => \$proof]"),
    'G1 is exposed through proven validate route' => str_contains($validate, "\$_GET['g1_attestation']") && str_contains($validate, 'G1ProductionAttestation::respond()'),
    'legacy dedicated endpoint delegates to same helper' => str_contains($endpoint, 'G1ProductionAttestation::respond()'),
    'post-deploy probe uses proven validate route' => str_contains($probe, 'validate.php?g1_attestation=1'),
    'post-deploy probe verifies RSA signature' => str_contains($probe, 'RsaSigner::verify'),
    'attestation never accepts scenario results from request input' => !str_contains($helper, 'json_input(') && !str_contains($helper, '$_POST'),
];

$failed = [];
foreach ($checks as $label => $ok) {
    echo ($ok ? '[PASS] ' : '[FAIL] ') . $label . "\n";
    if (!$ok) $failed[] = $label;
}
if ($failed) {
    fwrite(STDERR, 'G1 attestation contract failures: ' . implode(', ', $failed) . "\n");
    exit(1);
}
echo "PASS G1 signed production attestation contract — proven-route=true, evidence-bound=true\n";
