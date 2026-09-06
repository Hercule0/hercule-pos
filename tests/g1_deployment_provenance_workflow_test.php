<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$workflow = file_get_contents($root . '/.github/workflows/deploy.yml');
$helper = file_get_contents($root . '/includes/G1ProductionAttestation.php');
$validate = file_get_contents($root . '/public/api/v2/validate.php');

$checks = [
    'workflow runs dedicated G1 evidence tests' => str_contains($workflow, 'Run G1 entitlement certification evidence'),
    'all four required G1 tests are explicit' =>
        str_contains($workflow, 'entitlement_v2_mysql_lock_syntax_test.php')
        && str_contains($workflow, 'multi_entitlement_v2_test.php')
        && str_contains($workflow, 'multi_entitlement_admin_test.php')
        && str_contains($workflow, 'entitlement_v2_validate_bootstrap_test.php'),
    'G1 evidence binds repository' => str_contains($workflow, "'repository': os.environ['GITHUB_REPOSITORY']"),
    'G1 evidence binds exact commit' => str_contains($workflow, "'commit_sha': os.environ['GITHUB_SHA'].lower()"),
    'G1 evidence binds workflow run' => str_contains($workflow, "'run_id': os.environ['GITHUB_RUN_ID']"),
    'G1 evidence marks all passed only after commands succeed' => str_contains($workflow, "'all_passed': True"),
    'G1 evidence records test SHA256' => str_contains($workflow, 'hashlib.sha256'),
    'deployment provenance remains commit/run bound' => str_contains($workflow, 'Stamp tested deployment provenance') && str_contains($workflow, "'tests_passed': True"),
    'deployment package requires both evidence files' => str_contains($workflow, 'test -s deploy_package/deployment-source.json') && str_contains($workflow, 'test -s deploy_package/g1-test-evidence.json'),
    'deployment package requires shared attestation helper' => str_contains($workflow, 'test -s deploy_package/includes/G1ProductionAttestation.php'),
    'Kudu verifies G1 evidence bytes' => str_contains($workflow, 'g1-test-evidence.json') && str_contains($workflow, 'includes/G1ProductionAttestation.php'),
    'runtime helper reads both provenance manifests' => str_contains($helper, 'deployment-source.json') && str_contains($helper, 'g1-test-evidence.json'),
    'runtime helper rejects mismatched evidence' => str_contains($helper, 'G1 test evidence does not match this deployment.'),
    'G1 uses already-proven validate route' => str_contains($validate, "\$_GET['g1_attestation']"),
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

echo "PASS G1 deployment provenance workflow — explicit-tests=true, commit-bound=true, run-bound=true, Kudu-exact=true\n";
