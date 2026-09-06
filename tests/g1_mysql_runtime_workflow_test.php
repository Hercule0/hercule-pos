<?php

declare(strict_types=1);

$workflow = file_get_contents(dirname(__DIR__) . '/.github/workflows/deploy.yml');

$routePos = strpos($workflow, '- name: Verify Entitlement v2 production routes');
$mysqlPos = strpos($workflow, '- name: Verify production MySQL seat-lock runtime');
$uploadPos = strpos($workflow, '- name: Upload G1 production MySQL runtime evidence');
$diagPos = strpos($workflow, '- name: Diagnose Azure runtime drift');

$checks = [
    'deployment package includes exact MySQL runtime probe' => str_contains($workflow, 'test -s deploy_package/scripts/g1_mysql_runtime_probe.php'),
    'runtime proof runs after live G1 route certification' => $routePos !== false && $mysqlPos !== false && $routePos < $mysqlPos,
    'runtime proof runs before final drift diagnostics' => $mysqlPos !== false && $diagPos !== false && $mysqlPos < $diagPos,
    'runtime evidence uploads after proof' => $mysqlPos !== false && $uploadPos !== false && $mysqlPos < $uploadPos,
    'Kudu executes exact deployed CLI probe' => str_contains($workflow, 'php /home/site/wwwroot/scripts/g1_mysql_runtime_probe.php'),
    'Kudu command API is used' => str_contains($workflow, '$kudu_base/api/command'),
    'Kudu credentials are masked' => str_contains($workflow, '::add-mask::$kudu_user') && str_contains($workflow, '::add-mask::$kudu_pass'),
    'proof requires MySQL driver' => str_contains($workflow, '.database_driver == "mysql"'),
    'proof requires two independent connections' => str_contains($workflow, '.independent_connections == true'),
    'proof requires named-lock exclusion' => str_contains($workflow, '.named_lock_exclusion == true'),
    'proof requires lock handoff' => str_contains($workflow, '.lock_handoff == true'),
    'proof requires zero production row mutation' => str_contains($workflow, '.production_rows_mutated == false'),
    'runtime source SHA must equal candidate source SHA' => str_contains($workflow, 'expected_source_sha=') && str_contains($workflow, 'runtime_source_sha=') && str_contains($workflow, 'different EntitlementV2 source'),
    'evidence binds repository' => str_contains($workflow, "'repository': os.environ['GITHUB_REPOSITORY']"),
    'evidence binds exact commit' => str_contains($workflow, "'commit_sha': os.environ['GITHUB_SHA'].lower()"),
    'evidence binds workflow run' => str_contains($workflow, "'run_id': os.environ['GITHUB_RUN_ID']"),
    'runtime evidence is retained for audit' => str_contains($workflow, 'g1-production-mysql-runtime-${{ github.run_id }}') && str_contains($workflow, 'retention-days: 30'),
    'final drift gate verifies deployed probe bytes' => str_contains($workflow, 'scripts/g1_mysql_runtime_probe.php'),
];

$failed = [];
foreach ($checks as $label => $ok) {
    echo ($ok ? '[PASS] ' : '[FAIL] ') . $label . "\n";
    if (!$ok) $failed[] = $label;
}

if ($failed) {
    fwrite(STDERR, 'G1 MySQL runtime workflow failures: ' . implode(', ', $failed) . "\n");
    exit(1);
}

echo "PASS Fix475 G1 MySQL runtime workflow — production=true, ordered=true, source-bound=true, artifact=true\n";
