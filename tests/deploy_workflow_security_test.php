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

$mainGate = "github.event_name == 'workflow_dispatch' && github.ref == 'refs/heads/main'";
if (substr_count($workflow, $mainGate) < 2) {
    $fail('production artifact/deploy paths are not both restricted to manual main-branch runs');
}

if (!str_contains($workflow, "environment: production")) {
    $fail('production deployment is not protected by the production environment');
}

if (!str_contains($workflow, "deploy-production:\n    if: " . $mainGate)) {
    $fail('production deploy job can run from a non-main ref');
}

if (!str_contains($workflow, "Upload validated deployment package\n        if: " . $mainGate)) {
    $fail('deployment artifact can be produced from a non-main manual ref');
}

echo "PASS deployment workflow privilege gate\n";
