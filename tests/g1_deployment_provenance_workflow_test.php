<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$workflow = file_get_contents($root . '/.github/workflows/deploy.yml');
$attestation = file_get_contents($root . '/public/api/v2/g1_attestation.php');

$checks = [
    'workflow stamps tested provenance' => str_contains($workflow, 'Stamp tested deployment provenance'),
    'provenance binds repository' => str_contains($workflow, "os.environ['GITHUB_REPOSITORY']"),
    'provenance binds exact commit' => str_contains($workflow, "os.environ['GITHUB_SHA'].lower()"),
    'provenance binds workflow run' => str_contains($workflow, "os.environ['GITHUB_RUN_ID']"),
    'provenance asserts tests passed' => str_contains($workflow, "'tests_passed': True"),
    'deployment package must contain provenance' => str_contains($workflow, 'test -s deploy_package/deployment-source.json'),
    'Kudu verifies G1 route bytes' => str_contains($workflow, 'validate.php activate.php g1_attestation.php'),
    'Kudu verifies provenance bytes' => str_contains($workflow, 'kudu-deployment-source.json'),
    'attestation reads deployed provenance' => str_contains($attestation, 'deployment-source.json'),
    'attestation rejects untested provenance' => str_contains($attestation, '|| !$testsPassed'),
];

$failed = [];
foreach ($checks as $label => $ok) {
    echo ($ok ? '[PASS] ' : '[FAIL] ') . $label . "\n";
    if (!$ok) $failed[] = $label;
}

if ($failed) {
    fwrite(STDERR, 'G1 deployment provenance workflow failures: ' . implode(', ', $failed) . "\n");
    exit(1);
}

echo "PASS G1 deployment provenance workflow — tested-commit=true, run-bound=true, package-bound=true, Kudu-exact=true\n";
