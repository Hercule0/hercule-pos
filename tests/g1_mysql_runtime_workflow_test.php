<?php

declare(strict_types=1);

$workflow = file_get_contents(dirname(__DIR__) . '/.github/workflows/deploy.yml');

$routePos = strpos($workflow, '- name: Verify Entitlement v2 production routes');
$mysqlPos = strpos($workflow, '- name: Verify production MySQL seat-lock runtime');
$uploadPos = strpos($workflow, '- name: Upload G1 production MySQL runtime evidence');
$diagPos = strpos($workflow, '- name: Diagnose Azure runtime drift');

$checks = [
    'deployment package includes web-worker MySQL probe helper' => str_contains($workflow, 'test -s deploy_package/includes/G1ProductionMysqlProbe.php'),
    'runtime proof runs after live G1 route certification' => $routePos !== false && $mysqlPos !== false && $routePos < $mysqlPos,
    'runtime proof runs before final drift diagnostics' => $mysqlPos !== false && $diagPos !== false && $mysqlPos < $diagPos,
    'runtime evidence uploads after proof' => $mysqlPos !== false && $uploadPos !== false && $mysqlPos < $uploadPos,
    'Kudu arms one-time token outside wwwroot' => str_contains($workflow, '/home/data/hercule-g1/mysql-runtime-probe.token') && str_contains($workflow, 'openssl rand -hex 32'),
    'one-time token is masked in Actions logs' => str_contains($workflow, '::add-mask::$token'),
    'token has short expiration' => str_contains($workflow, 'date +%s') && str_contains($workflow, '+ 120'),
    'token is cleaned on exit' => str_contains($workflow, 'cleanup_probe_token') && str_contains($workflow, 'trap cleanup_probe_token EXIT'),
    'web-worker proof uses proven POST validate transport' => str_contains($workflow, 'validate.php?g1_mysql_runtime_probe=1') && str_contains($workflow, 'X-Hercule-G1-Probe-Token'),
    'proof requires MySQL driver' => str_contains($workflow, '.payload.database_driver == "mysql"'),
    'proof requires exact production function' => str_contains($workflow, '.payload.tested_function == "EntitlementV2::withSeatLock"'),
    'proof requires independent connections' => str_contains($workflow, '.payload.independent_connections == true'),
    'proof requires named-lock exclusion' => str_contains($workflow, '.payload.named_lock_exclusion == true'),
    'proof requires lock handoff' => str_contains($workflow, '.payload.lock_handoff == true'),
    'proof requires synthetic key only' => str_contains($workflow, '.payload.synthetic_key_only == true'),
    'proof requires zero production row mutation' => str_contains($workflow, '.payload.production_rows_mutated == false'),
    'runtime source SHA must equal candidate source SHA' => str_contains($workflow, 'expected_source_sha=') && str_contains($workflow, 'runtime_source_sha=') && str_contains($workflow, 'different EntitlementV2 source'),
    'web-worker proof RSA signature is verified' => substr_count($workflow, 'RsaSigner::verify') >= 1 && str_contains($workflow, 'Production MySQL runtime proof RSA verification failed'),
    'evidence binds repository' => str_contains($workflow, "'repository': os.environ['GITHUB_REPOSITORY']"),
    'evidence binds exact commit' => str_contains($workflow, "'commit_sha': os.environ['GITHUB_SHA'].lower()"),
    'evidence binds workflow run' => str_contains($workflow, "'run_id': os.environ['GITHUB_RUN_ID']"),
    'evidence records production web-worker transport' => str_contains($workflow, "'transport': 'production_php_web_worker'"),
    'runtime evidence is retained for audit' => str_contains($workflow, 'g1-production-mysql-runtime-${{ github.run_id }}') && str_contains($workflow, 'retention-days: 30'),
    'final drift gate verifies deployed helper bytes' => str_contains($workflow, 'includes/G1ProductionMysqlProbe.php'),
    'obsolete Kudu PHP CLI execution is removed' => !str_contains($workflow, 'php /home/site/wwwroot/scripts/g1_mysql_runtime_probe.php'),
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

echo "PASS Fix476 G1 MySQL runtime workflow — web-worker=true one-time-token=true source-bound=true artifact=true\n";
