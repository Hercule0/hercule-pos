<?php

declare(strict_types=1);

final class G1ProductionAttestation
{
    private const REQUIRED_TESTS = [
        'entitlement_v2_mysql_lock_syntax_test.php',
        'multi_entitlement_v2_test.php',
        'multi_entitlement_admin_test.php',
        'entitlement_v2_validate_bootstrap_test.php',
    ];

    public static function respond(bool $allowPost = false): void
    {
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? ''));
        if ($method !== 'GET' && !($allowPost && $method === 'POST')) {
            json_response(['ok' => false, 'error' => 'Method not allowed'], 405);
        }

        if (!RateLimiter::check(client_ip(), 'g1_attestation', 20, 5)) {
            json_response(['ok' => false, 'error' => 'Too many attestation requests.'], 429);
        }

        $root = dirname(__DIR__);
        $sourceMeta = self::loadJson($root . '/deployment-source.json');
        $evidencePath = $root . '/g1-test-evidence.json';
        $evidence = self::loadJson($evidencePath);

        $repository = trim((string) ($sourceMeta['repository'] ?? ''));
        $commitSha = strtolower(trim((string) ($sourceMeta['commit_sha'] ?? '')));
        $runId = trim((string) ($sourceMeta['run_id'] ?? ''));
        $testsPassed = ($sourceMeta['tests_passed'] ?? false) === true;

        if (!preg_match('/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+$/', $repository)
            || !preg_match('/^[a-f0-9]{40}$/', $commitSha)
            || $runId === ''
            || !$testsPassed) {
            json_response(['ok' => false, 'error' => 'Deployment provenance metadata is unavailable.'], 503);
        }

        self::assertEvidenceMatchesDeployment($evidence, $repository, $commitSha, $runId);

        $requiredFiles = [
            'includes/EntitlementV2.php',
            'includes/MultiEntitlementAdmin.php',
            'includes/MultiEntitlementPolicy.php',
            'includes/G1ProductionAttestation.php',
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
        if ($baseUrl === '') {
            $azureHost = trim((string) ($_ENV['WEBSITE_HOSTNAME'] ?? $_SERVER['WEBSITE_HOSTNAME'] ?? getenv('WEBSITE_HOSTNAME') ?: ''));
            if ($azureHost !== '' && preg_match('/^[A-Za-z0-9.-]+$/', $azureHost)) {
                $baseUrl = 'https://' . $azureHost;
            }
        }
        if (!preg_match('#^https://[A-Za-z0-9.-]+(?::\d+)?(?:/.*)?$#', $baseUrl)) {
            json_response(['ok' => false, 'error' => 'Production public base URL is unavailable.'], 503);
        }

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
                'g1_test_evidence_sha256' => hash_file('sha256', $evidencePath),
            ],
            'runtime' => [
                'database_driver' => $driver,
                'entitlement_schema_ready' => true,
            ],
            'routes' => [
                'activate_v2' => ['signed_response' => true, 'schema_version' => 2],
                'validate_v2' => ['signed_response' => true, 'schema_version' => 2],
                'g1_attestation' => [
                    'via' => 'POST validate.php?g1_attestation=1',
                    'signed_response' => true,
                ],
            ],
            'scenarios' => self::buildScenarioEvidence($evidence),
        ];

        try {
            json_response(['ok' => true] + RsaSigner::sign($payload));
        } catch (Throwable $e) {
            ErrorHandler::report($e, 'g1_attestation_signing_failed');
            json_response(['ok' => false, 'error' => 'Production attestation signing is unavailable.'], 503);
        }
    }

    private static function loadJson(string $path): array
    {
        if (!is_file($path)) {
            json_response(['ok' => false, 'error' => 'Production certification evidence is unavailable.'], 503);
        }
        $decoded = json_decode((string) file_get_contents($path), true);
        if (!is_array($decoded)) {
            json_response(['ok' => false, 'error' => 'Production certification evidence is invalid.'], 503);
        }
        return $decoded;
    }

    private static function assertEvidenceMatchesDeployment(array $evidence, string $repository, string $commitSha, string $runId): void
    {
        if ((int) ($evidence['schema_version'] ?? 0) !== 1
            || trim((string) ($evidence['repository'] ?? '')) !== $repository
            || strtolower(trim((string) ($evidence['commit_sha'] ?? ''))) !== $commitSha
            || trim((string) ($evidence['run_id'] ?? '')) !== $runId
            || ($evidence['all_passed'] ?? false) !== true
            || !is_array($evidence['tests'] ?? null)) {
            json_response(['ok' => false, 'error' => 'G1 test evidence does not match this deployment.'], 503);
        }

        $indexed = [];
        foreach ($evidence['tests'] as $row) {
            if (!is_array($row)) continue;
            $name = basename((string) ($row['name'] ?? ''));
            if ($name !== '') $indexed[$name] = $row;
        }

        foreach (self::REQUIRED_TESTS as $name) {
            $row = $indexed[$name] ?? null;
            if (!is_array($row)
                || ($row['passed'] ?? false) !== true
                || !preg_match('/^[a-f0-9]{64}$/', (string) ($row['sha256'] ?? ''))) {
                json_response(['ok' => false, 'error' => 'G1 test evidence is incomplete or stale.'], 503);
            }
        }
    }

    private static function buildScenarioEvidence(array $evidence): array
    {
        $testMap = [];
        foreach ($evidence['tests'] as $row) {
            if (!is_array($row)) continue;
            $name = basename((string) ($row['name'] ?? ''));
            if ($name !== '') {
                $testMap[$name] = (string) ($row['sha256'] ?? '');
            }
        }

        $make = static function (array $tests) use ($testMap): array {
            $proof = [];
            foreach ($tests as $name) {
                $proof[] = ['test' => $name, 'sha256' => $testMap[$name] ?? ''];
            }
            return ['status' => 'PASS', 'evidence' => $proof];
        };

        return [
            'concurrent_last_seat' => $make(['entitlement_v2_mysql_lock_syntax_test.php', 'multi_entitlement_v2_test.php']),
            'inactive_hwid_reactivation' => $make(['multi_entitlement_v2_test.php']),
            'replace_a_to_b_revoke_a' => $make(['multi_entitlement_v2_test.php']),
            'upgrade_1_to_2' => $make(['multi_entitlement_admin_test.php']),
            'downgrade_below_active_blocked' => $make(['multi_entitlement_admin_test.php']),
            'v1_v2_compatibility' => $make(['multi_entitlement_v2_test.php', 'entitlement_v2_validate_bootstrap_test.php']),
        ];
    }
}
