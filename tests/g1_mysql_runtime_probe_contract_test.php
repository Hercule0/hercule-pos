<?php

declare(strict_types=1);

$script = file_get_contents(dirname(__DIR__) . '/scripts/g1_mysql_runtime_probe.php');
$lower = strtolower($script);

$checks = [
    'probe is CLI-only' => str_contains($script, "PHP_SAPI !== 'cli'"),
    'probe uses two independent PDO connections' => substr_count($script, 'independent_mysql_pdo($db)') >= 2,
    'probe requires real MySQL driver' => str_contains($script, 'PDO::ATTR_DRIVER_NAME') && str_contains($script, "!== 'mysql'"),
    'probe verifies Entitlement v2 schema readiness' => str_contains($script, 'EntitlementV2::schemaReady()'),
    'connection A acquires a zero-wait named lock' => str_contains($script, 'SELECT GET_LOCK(?, 0)'),
    'connection B must be excluded while A owns lock' => str_contains($script, 'named_lock_exclusion_failed') && str_contains($script, '$bBlocked !== 0'),
    'lock handoff is explicitly verified' => str_contains($script, 'lock_handoff_failed'),
    'all acquired locks are released' => str_contains($script, 'SELECT RELEASE_LOCK(?)'),
    'probe uses a synthetic random lock namespace' => str_contains($script, 'hercule-g1-runtime-') && str_contains($script, 'random_bytes(12)'),
    'probe binds evidence to EntitlementV2 source SHA256' => str_contains($script, 'entitlement_source_sha256') && str_contains($script, "hash_file('sha256', \$sourcePath)"),
    'probe declares zero production row mutation' => str_contains($script, "'production_rows_mutated' => false"),
    'probe contains no INSERT statement' => !preg_match('/\binsert\s+(?:into\s+)?[a-z_]/i', $script),
    'probe contains no UPDATE statement' => !preg_match('/\bupdate\s+[a-z_]/i', $script),
    'probe contains no DELETE statement' => !preg_match('/\bdelete\s+from\s+[a-z_]/i', $script),
    'probe never reads a production license key' => !str_contains($lower, 'license_key') && !str_contains($lower, 'licensekey'),
    'probe never emits DB credentials' => !str_contains($script, "'DB_PASS'") && !str_contains($script, "'password' =>"),
];

$failed = [];
foreach ($checks as $label => $ok) {
    echo ($ok ? '[PASS] ' : '[FAIL] ') . $label . "\n";
    if (!$ok) $failed[] = $label;
}

if ($failed) {
    fwrite(STDERR, 'G1 MySQL runtime probe contract failures: ' . implode(', ', $failed) . "\n");
    exit(1);
}

echo "PASS Fix475 G1 MySQL runtime probe contract — mysql=true, two-connections=true, no-row-mutation=true\n";
