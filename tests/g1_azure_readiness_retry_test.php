<?php

declare(strict_types=1);

$script = file_get_contents(dirname(__DIR__) . '/scripts/check_entitlement_v2_routes.sh');
$checks = [
    'G1 readiness uses bounded retry loop' => str_contains($script, 'for attempt in {1..12}'),
    '502 is treated as transient deployment readiness only' => str_contains($script, '"502"'),
    '503 is treated as transient deployment readiness only' => str_contains($script, '"503"'),
    '404 is not retryable on the proven POST validate route' => !str_contains($script, '$status" != "404"'),
    'retry has bounded delay' => str_contains($script, 'sleep 5'),
    'certification still requires HTTP 200' => str_contains($script, 'if [[ "$status" == "200" ]]'),
    'G1 probe uses existing validate filename' => str_contains($script, 'validate.php?g1_attestation=1'),
    'G1 probe uses the same proven POST transport' => str_contains($script, "--header 'Content-Type: application/json'") && substr_count($script, "--data '{}'") >= 2,
    'signed payload must report POST route binding' => str_contains($script, 'POST validate.php?g1_attestation=1'),
    'RSA verification remains mandatory after readiness' => str_contains($script, 'RsaSigner::verify'),
    'test-evidence digest is mandatory' => str_contains($script, 'g1_test_evidence_sha256'),
    'all six scenario evidence rows are checked' => str_contains($script, 'downgrade_below_active_blocked') && str_contains($script, 'v1_v2_compatibility'),
    'final failure stays fail-closed' => str_contains($script, 'did not become live'),
    'final failure invokes Kudu diagnostic' => str_contains($script, 'diagnose_kudu_g1 || true'),
    'diagnostic checks validate route attestation and web-worker probe helper' => str_contains($script, 'public/api/v2/validate.php') && str_contains($script, 'includes/G1ProductionAttestation.php') && str_contains($script, 'includes/G1ProductionMysqlProbe.php'),
    'diagnostic no longer depends on obsolete CLI probe' => !str_contains($script, 'scripts/g1_mysql_runtime_probe.php'),
    'diagnostic checks deployed evidence' => str_contains($script, 'g1-test-evidence.json') && str_contains($script, 'deployment-source.json'),
    'diagnostic compares expected and actual SHA' => str_contains($script, 'KUDU_COMPARE'),
    'Kudu credentials are masked' => str_contains($script, '::add-mask::$kudu_user') && str_contains($script, '::add-mask::$kudu_pass'),
];

$failed = [];
foreach ($checks as $label => $ok) {
    echo ($ok ? '[PASS] ' : '[FAIL] ') . $label . "\n";
    if (!$ok) $failed[] = $label;
}
if ($failed) {
    fwrite(STDERR, 'G1 Azure readiness retry failures: ' . implode(', ', $failed) . "\n");
    exit(1);
}
echo "PASS G1 Azure readiness + Kudu diagnostic — post-route=true, web-worker-proof=true, bounded=true, evidence=true\n";
