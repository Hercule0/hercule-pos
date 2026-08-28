<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$workflow = file_get_contents($root . '/.github/workflows/deploy.yml');

$fail = static function (string $message): never {
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
};

if (!is_string($workflow)) {
    $fail('deployment workflow could not be read');
}

$topPermissions = strpos($workflow, "permissions:\n  contents: read\n\nconcurrency:");
if ($topPermissions === false) {
    $fail('workflow does not default to read-only repository permissions');
}

$deployJob = strpos($workflow, "  deploy-production:\n");
$idToken = strpos($workflow, "      id-token: write\n", $deployJob === false ? 0 : $deployJob);
if ($deployJob === false || $idToken === false || $idToken < $deployJob) {
    $fail('production job is missing job-scoped OIDC permission');
}

$globalIdToken = strpos(substr($workflow, 0, $deployJob), 'id-token: write');
if ($globalIdToken !== false) {
    $fail('OIDC permission is still available to validation or pull-request jobs');
}

$mainGate = "github.event_name == 'push' && github.ref == 'refs/heads/main'";
if (substr_count($workflow, $mainGate) < 2) {
    $fail('production artifact/deploy paths are not both restricted to pushes on main');
}

if (!str_contains($workflow, "deploy-production:\n    if: " . $mainGate)) {
    $fail('production deploy job can run outside an automatic main-branch push');
}

if (!str_contains($workflow, "Upload validated deployment package\n        if: " . $mainGate)) {
    $fail('deployment artifact can be produced outside an automatic main-branch push');
}

if (str_contains($workflow, "environment: production")) {
    $fail('production environment binding changes the OIDC subject away from the main branch ref');
}

if (!str_contains($workflow, 'bash deploy_package/scripts/check_production_health.sh "$HEALTH_URL"')) {
    $fail('post-deploy gate does not verify application and database readiness with the hardened health checker');
}
if (str_contains($workflow, 'if curl --fail --silent --show-error --max-time 10 "$HEALTH_URL"; then')) {
    $fail('post-deploy gate still accepts a generic HTTP 200 without validating the health payload');
}
if (!str_contains($workflow, 'timeout-minutes: 15')) {
    $fail('production deployment job has no bounded timeout');
}

echo "PASS deployment workflow privilege and automatic-main readiness gates\n";
