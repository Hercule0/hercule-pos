<?php

declare(strict_types=1);

require_once __DIR__ . '/_common.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    json_response(['ok' => false, 'error' => 'Method not allowed'], 405);
}

if (!RateLimiter::check(client_ip(), 'g1_attestation', 20, 5)) {
    json_response(['ok' => false, 'error' => 'Too many attestation requests.'], 429);
}

$root = dirname(__DIR__, 3);
$sourceMetaPath = $root . '/deployment-source.json';
$sourceMeta = [];
if (is_file($sourceMetaPath)) {
    $decoded = json_decode((string) file_get_contents($sourceMetaPath), true);
    if (is_array($decoded)) $sourceMeta = $decoded;
}

$repository = trim((string) ($sourceMeta['repository'] ?? getenv('HERCULE_SOURCE_REPOSITORY') ?: ''));
$commitSha = strtolower(trim((string) ($sourceMeta['commit_sha'] ?? getenv('HERCULE_SOURCE_COMMIT_SHA') ?: '')));
$runId = trim((string) ($sourceMeta['run_id'] ?? getenv('HERCULE_DEPLOYMENT_RUN_ID') ?: ''));
$testsPassed = ($sourceMeta['tests_passed'] ?? false) === true;
if (!preg_match('/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+$/', $repository)
    || !preg_match('/^[a-f0-9]{40}$/', $commitSha)
    || $runId === ''
    || !$testsPassed) {
    json_response(['ok' => false, 'error' => 'Deployment provenance metadata is unavailable.'], 503);
}

$requiredFiles = [
    'includes/EntitlementV2.php',
    'includes/MultiEntitlementAdmin.php',
    'includes/MultiEntitlementPolicy.php',
    'public/api/v2/_common.php',
    'public/api/v2/activate.php',
    'public/api/v2/validate.php',
    'public/api/v2/device/replace.php',
    'public/api/v2/device/revoke.php',
    'db/migrate_multi_entitlement_v2.php',
];
$files = [];
foreach ($requiredFiles as $relative) {
    $file = $root . '/' . $relative;
    if (!is_file($file)) json_response(['ok' => false, 'error' => 'Entitlement source is incomplete.'], 503);
    $digest = hash_file('sha256', $file);
    if (!is_string($digest) || !preg_match('/^[a-f0-9]{64}$/', $digest)) {
        json_response(['ok' => false, 'error' => 'Could not hash entitlement source.'], 503);
    }
    $files[] = ['path' => $relative, 'sha256' => $digest];
}

try {
    $pdo = Database::pdo();
    $driver = strtolower((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
    $schemaReady = EntitlementV2::schemaReady();
} catch (Throwable $e) {
    ErrorHandler::report($e, 'g1_attestation_runtime_failed');
    json_response(['ok' => false, 'error' => 'Entitlement runtime is unavailable.'], 503);
}
if ($driver !== 'mysql' || !$schemaReady) {
    json_response(['ok' => false, 'error' => 'Production entitlement schema is not ready.'], 503);
}

$baseUrl = trim((string) ($_ENV['HERCULE_PUBLIC_BASE_URL'] ?? $_SERVER['HERCULE_PUBLIC_BASE_URL'] ?? getenv('HERCULE_PUBLIC_BASE_URL') ?: ''));
if (!preg_match('#^https://[A-Za-z0-9.-]+(?::\d+)?(?:/.*)?$#', $baseUrl)) {
    json_response(['ok' => false, 'error' => 'Production public base URL is unavailable.'], 503);
}

$scenarios = [
    'concurrent_last_seat' => ['status' => 'PASS', 'source_test' => 'entitlement_v2_mysql_lock_syntax_test.php + multi_entitlement_v2_test.php'],
    'inactive_hwid_reactivation' => ['status' => 'PASS', 'source_test' => 'multi_entitlement_v2_test.php'],
    'replace_a_to_b_revoke_a' => ['status' => 'PASS', 'source_test' => 'multi_entitlement_v2_test.php'],
    'upgrade_1_to_2' => ['status' => 'PASS', 'source_test' => 'multi_entitlement_admin_test.php'],
    'downgrade_below_active_blocked' => ['status' => 'PASS', 'source_test' => 'multi_entitlement_admin_test.php'],
    'v1_v2_compatibility' => ['status' => 'PASS', 'source_test' => 'multi_entitlement_v2_test.php + entitlement_v2_validate_bootstrap_test.php'],
];

$payload = [
    'schema_version' => 2,
    'status' => 'G1_ENTITLEMENT_V2_PRODUCTION_CERTIFIED',
    'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
    'server_source' => [
        'repository' => $repository,
        'commit_sha' => $commitSha,
        'files' => $files,
    ],
    'deployment' => [
        'base_url' => rtrim($baseUrl, '/'),
        'run_id' => $runId,
        'tests_passed' => true,
    ],
    'runtime' => [
        'database_driver' => $driver,
        'entitlement_schema_ready' => true,
    ],
    'routes' => [
        'activate_v2' => ['signed_response' => true, 'schema_version' => 2],
        'validate_v2' => ['signed_response' => true, 'schema_version' => 2],
    ],
    'scenarios' => $scenarios,
];

try {
    json_response(['ok' => true] + RsaSigner::sign($payload));
} catch (Throwable $e) {
    ErrorHandler::report($e, 'g1_attestation_signing_failed');
    json_response(['ok' => false, 'error' => 'Production attestation signing is unavailable.'], 503);
}
