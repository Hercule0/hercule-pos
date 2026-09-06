<?php

declare(strict_types=1);

$workflow = file_get_contents(dirname(__DIR__) . '/.github/workflows/deploy.yml');

$restartPos = strpos($workflow, '- name: Restart Azure production worker on deployed package');
$healthPos = strpos($workflow, '- name: Verify deployed application health');
$routePos = strpos($workflow, '- name: Verify Entitlement v2 production routes');

$checks = [
    'production workflow explicitly restarts App Service after OneDeploy' => $restartPos !== false,
    'restart resolves resource group instead of hardcoding it' => str_contains($workflow, 'resource_group="$(az webapp list'),
    'restart uses Azure Web App restart command' => str_contains($workflow, 'az webapp restart --name "$AZURE_WEBAPP_NAME" --resource-group "$resource_group"'),
    'restart is ordered before health verification' => $restartPos !== false && $healthPos !== false && $restartPos < $healthPos,
    'health verification is ordered before entitlement certification' => $healthPos !== false && $routePos !== false && $healthPos < $routePos,
    'post-restart health wait is bounded' => str_contains($workflow, 'for attempt in {1..24}') && str_contains($workflow, 'sleep 5'),
    'failed restart cannot be ignored' => !str_contains($workflow, 'az webapp restart --name "$AZURE_WEBAPP_NAME" --resource-group "$resource_group" || true'),
    'health after restart remains fail-closed' => str_contains($workflow, 'Deployment did not become application+database healthy after worker restart.'),
];

$failed = [];
foreach ($checks as $label => $ok) {
    echo ($ok ? '[PASS] ' : '[FAIL] ') . $label . "\n";
    if (!$ok) $failed[] = $label;
}

if ($failed) {
    fwrite(STDERR, 'Azure worker remount gate failures: ' . implode(', ', $failed) . "\n");
    exit(1);
}

echo "PASS Fix474 Azure worker remount gate — restart-required=true, post-restart-health=true, fail-closed=true\n";
