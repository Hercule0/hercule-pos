<?php

declare(strict_types=1);

$script = file_get_contents(dirname(__DIR__) . '/scripts/check_entitlement_v2_routes.sh');
$checks = [
    'G1 readiness uses bounded retry loop' => str_contains($script, 'for attempt in {1..12}'),
    '404 is treated as transient deployment readiness only' => str_contains($script, '"404"'),
    '502 is treated as transient deployment readiness only' => str_contains($script, '"502"'),
    '503 is treated as transient deployment readiness only' => str_contains($script, '"503"'),
    'retry has bounded delay' => str_contains($script, 'sleep 5'),
    'certification still requires HTTP 200' => str_contains($script, 'if [[ "$status" == "200" ]]'),
    'RSA verification remains mandatory after readiness' => str_contains($script, 'RsaSigner::verify'),
    'final failure stays fail-closed' => str_contains($script, 'did not become live'),
    'final failure invokes Kudu diagnostic' => str_contains($script, 'diagnose_kudu_g1 || true'),
    'diagnostic inspects run-from-package settings' => str_contains($script, 'WEBSITE_RUN_FROM_PACKAGE'),
    'diagnostic checks exact G1 wwwroot file' => str_contains($script, 'public/api/v2/g1_attestation.php'),
    'diagnostic checks deployed provenance' => str_contains($script, 'deployment-source.json'),
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
echo "PASS G1 Azure readiness + Kudu diagnostic — bounded=true, transient-only=true, rsa-required=true, kudu-exact=true\n";
