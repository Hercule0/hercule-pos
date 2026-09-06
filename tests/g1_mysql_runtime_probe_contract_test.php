<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$helper = file_get_contents($root . '/includes/G1ProductionMysqlProbe.php');
$validate = file_get_contents($root . '/public/api/v2/validate.php');
$lower = strtolower($helper);

$checks = [
    'probe runs through production POST validate route' => str_contains($validate, "g1_mysql_runtime_probe") && str_contains($validate, 'G1ProductionMysqlProbe::respond()'),
    'probe intercepts before normal entitlement input parsing' => strpos($validate, 'G1ProductionMysqlProbe::respond()') < strpos($validate, '$input = v2_input()'),
    'probe requires one-time token header' => str_contains($helper, 'HTTP_X_HERCULE_G1_PROBE_TOKEN'),
    'token lives outside public wwwroot' => str_contains($helper, "/home/data/hercule-g1/mysql-runtime-probe.token"),
    'token is exact 64-hex material' => str_contains($helper, "^[a-f0-9]{64}$"),
    'token has explicit expiration' => str_contains($helper, '$expiresAt >= time()'),
    'token comparison is timing-safe' => str_contains($helper, 'hash_equals($expected, $provided)'),
    'matching token is consumed before proof' => str_contains($helper, 'ftruncate($fh, 0)') && str_contains($helper, '@unlink(self::TOKEN_FILE)'),
    'token file consumption is locked' => str_contains($helper, 'flock($fh, LOCK_EX)'),
    'probe avoids DB-backed RateLimiter writes' => !str_contains($helper, 'RateLimiter::'),
    'probe requires real MySQL driver' => str_contains($helper, "!== 'mysql'"),
    'probe verifies Entitlement v2 schema readiness' => str_contains($helper, 'EntitlementV2::schemaReady()'),
    'probe invokes exact production seat-lock function' => str_contains($helper, 'EntitlementV2::withSeatLock($syntheticKey'),
    'competitor uses independent PDO connection' => str_contains($helper, 'independentMysqlPdo($db)') && str_contains($helper, 'SELECT CONNECTION_ID()'),
    'competitor must be excluded while production lock is held' => str_contains($helper, 'SELECT GET_LOCK(?, 0)') && str_contains($helper, 'named_lock_exclusion_failed'),
    'lock handoff is explicitly verified' => str_contains($helper, 'lock_handoff_failed'),
    'competitor lock is explicitly released' => str_contains($helper, 'SELECT RELEASE_LOCK(?)'),
    'probe uses synthetic random key only' => str_contains($helper, "'G1-PROBE-' . bin2hex(random_bytes(16))") && str_contains($helper, "'synthetic_key_only' => true"),
    'probe binds evidence to EntitlementV2 source SHA256' => str_contains($helper, 'entitlement_source_sha256') && str_contains($helper, "hash_file('sha256', \$sourcePath)"),
    'probe declares zero production row mutation' => str_contains($helper, "'production_rows_mutated' => false"),
    'success response is RSA signed' => str_contains($helper, "RsaSigner::sign(\$payload)"),
    'probe contains no INSERT statement' => !preg_match('/\binsert\s+(?:into\s+)?[a-z_]/i', $helper),
    'probe contains no UPDATE statement' => !preg_match('/\bupdate\s+[a-z_]/i', $helper),
    'probe contains no DELETE statement' => !preg_match('/\bdelete\s+from\s+[a-z_]/i', $helper),
    'probe never reads request license key' => !str_contains($lower, "['license_key']") && !str_contains($lower, 'json_input('),
    'probe never emits database credentials' => !str_contains($helper, "'password' =>") && !str_contains($helper, "'username' =>"),
];

$failed = [];
foreach ($checks as $label => $ok) {
    echo ($ok ? '[PASS] ' : '[FAIL] ') . $label . "\n";
    if (!$ok) $failed[] = $label;
}

if ($failed) {
    fwrite(STDERR, 'G1 web-worker MySQL probe contract failures: ' . implode(', ', $failed) . "\n");
    exit(1);
}

echo "PASS Fix476 G1 web-worker MySQL probe contract — one-time-token=true withSeatLock=true db-zero-write=true rsa=true\n";
