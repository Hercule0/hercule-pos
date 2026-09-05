<?php
/**
 * Fix433 — Rollout coupling guard for Multi-Cashier Entitlement v2.
 *
 * This is intentionally a static deployment-contract test. The business
 * behavior is covered by multi_entitlement_v2_test.php and
 * multi_entitlement_admin_test.php; this gate prevents the API surface from
 * being deployed while the production release migration runner forgets the
 * required Fix408 schema migration.
 */

$root = dirname(__DIR__);
$failures = [];

function rollout_check(string $label, bool $ok): void
{
    global $failures;
    echo ($ok ? '[PASS] ' : '[FAIL] ') . $label . "\n";
    if (!$ok) $failures[] = $label;
}

$runnerPath = $root . '/scripts/run_release_migrations.sh';
$wrapperPath = $root . '/db/migrate_fix408.php';
$v2ValidatePath = $root . '/public/api/v2/validate.php';
$v2CommonPath = $root . '/public/api/v2/_common.php';

rollout_check('Fix408 migration wrapper exists', is_file($wrapperPath));
rollout_check('v2 validate endpoint exists', is_file($v2ValidatePath));
rollout_check('v2 shared signing endpoint helper exists', is_file($v2CommonPath));
rollout_check('release migration runner exists', is_file($runnerPath));

$runner = is_file($runnerPath) ? file_get_contents($runnerPath) : '';
$wrapper = is_file($wrapperPath) ? file_get_contents($wrapperPath) : '';
$common = is_file($v2CommonPath) ? file_get_contents($v2CommonPath) : '';

rollout_check(
    'release runner explicitly executes db/migrate_fix408.php',
    str_contains($runner, 'fix408_migration="db/migrate_fix408.php"')
        && str_contains($runner, 'php "$fix408_migration"')
);
rollout_check(
    'release runner fails closed when Fix408 migration is absent',
    str_contains($runner, 'Entitlement v2 must not be deployed without its schema migration.')
);
rollout_check(
    'Fix408 wrapper runs canonical migration before Entitlement v2 migration',
    strpos($wrapper, "require __DIR__ . '/migrate.php';") !== false
        && strpos($wrapper, "require __DIR__ . '/migrate_multi_entitlement_v2.php';") !== false
        && strpos($wrapper, "require __DIR__ . '/migrate.php';") < strpos($wrapper, "require __DIR__ . '/migrate_multi_entitlement_v2.php';")
);
rollout_check(
    'v2 responses remain signed with the existing RSA signer',
    str_contains($common, 'RsaSigner::sign($payload)')
);
rollout_check(
    'v2 endpoint does not downgrade to unsigned v1 output',
    !str_contains(file_get_contents($v2ValidatePath), "require_once __DIR__ . '/../validate.php'")
);

if ($failures) {
    fwrite(STDERR, 'Fix433 rollout guard failures: ' . implode(', ', $failures) . "\n");
    exit(1);
}

echo "PASS Fix433 Entitlement v2 rollout guard — API=true, signed=true, migration-wrapper=true, release-runner-coupled=true, fail-closed=true\n";
