<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$endpoint = file_get_contents($root . '/public/api/v2/g1_attestation.php');
$security = file_get_contents($root . '/scripts/security-gate.sh');
$probe = file_get_contents($root . '/scripts/check_entitlement_v2_routes.sh');

$checks = [
    'attestation is signed by license RSA trust' => str_contains($endpoint, "RsaSigner::sign($payload)"),
    'production runtime requires MySQL' => str_contains($endpoint, "$driver !== 'mysql'"),
    'production schema readiness is required' => str_contains($endpoint, 'EntitlementV2::schemaReady()'),
    'deployment provenance must contain a 40-hex commit' => str_contains($endpoint, "preg_match('/^[a-f0-9]{40}$/', $commitSha)"),
    'critical entitlement files are hashed' => str_contains($endpoint, "hash_file('sha256', $file)"),
    'G1 scenarios are server constructed rather than request supplied' => !str_contains($endpoint, 'json_input(') && str_contains($endpoint, "'concurrent_last_seat'"),
    'GitHub workflow provenance is stamped by security gate' => str_contains($security, 'deployment-source.json') && str_contains($security, 'GITHUB_SHA') && str_contains($security, "'tests_passed': True"),
    'post-deploy route probe verifies G1 RSA signature' => str_contains($probe, 'g1_attestation.php') && str_contains($probe, 'RsaSigner::verify'),
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
echo "PASS G1 signed production attestation contract\n";
