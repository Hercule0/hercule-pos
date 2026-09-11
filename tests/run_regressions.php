<?php
$files = glob(__DIR__ . '/*_test.php') ?: [];
$files = array_values(array_filter(
    $files,
    static fn(string $file): bool => basename($file) !== 'run_test.php'
));
sort($files);

if (!$files) {
    echo "No focused regression suites found.\n";
    exit(0);
}

$failures = [];
$passed = [];
foreach ($files as $file) {
    $name = basename($file);
    echo "\n=== {$name} ===\n";
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($file);
    passthru($command, $code);
    if ($code !== 0) {
        $failures[] = $name;
    } else {
        $passed[$name] = $file;
    }
}

if ($failures) {
    fwrite(STDERR, "\nFocused regression failures: " . implode(', ', $failures) . "\n");
    exit(1);
}

// Fix496 release evidence is generated only after the complete focused suite
// passes. It binds the new Multi security/transition tests AND the exact source
// files they certify to the workflow commit/run. The deployment package keeps
// this root artifact while excluding tests themselves.
$fix496Tests = [
    'device_license_transition_test.php',
    'device_license_transition_contract_test.php',
    'multi_manager_action_auth_test.php',
];
$fix496Sources = [
    'includes/ManagerDeviceAuth.php',
    'includes/DeviceLicenseTransition.php',
    'includes/MultiEntitlementPolicy.php',
    'public/api/v2/_common.php',
    'public/api/v2/activate.php',
    'public/api/v2/device/transition.php',
    'public/api/v2/device/release.php',
    'public/api/v2/device/revoke.php',
    'public/api/v2/device/replace.php',
    'includes/G1ProductionAttestation.php',
    'scripts/check_entitlement_v2_routes.sh',
];

$testEvidence = [];
foreach ($fix496Tests as $name) {
    $path = $passed[$name] ?? null;
    if (!is_string($path) || !is_file($path)) {
        fwrite(STDERR, "Fix496 evidence missing required passing test: {$name}\n");
        exit(1);
    }
    $digest = hash_file('sha256', $path);
    if (!is_string($digest)) {
        fwrite(STDERR, "Fix496 evidence could not hash test: {$name}\n");
        exit(1);
    }
    $testEvidence[] = ['name' => $name, 'sha256' => $digest, 'passed' => true];
}

$root = dirname(__DIR__);
$sourceEvidence = [];
foreach ($fix496Sources as $relative) {
    $path = $root . '/' . $relative;
    if (!is_file($path)) {
        fwrite(STDERR, "Fix496 evidence missing required source: {$relative}\n");
        exit(1);
    }
    $digest = hash_file('sha256', $path);
    if (!is_string($digest)) {
        fwrite(STDERR, "Fix496 evidence could not hash source: {$relative}\n");
        exit(1);
    }
    $sourceEvidence[] = ['path' => $relative, 'sha256' => $digest];
}

$evidence = [
    'schema_version' => 1,
    'status' => 'FIX496_MULTI_FINAL_GATE_PASS',
    'repository' => (string) (getenv('GITHUB_REPOSITORY') ?: ''),
    'commit_sha' => strtolower((string) (getenv('GITHUB_SHA') ?: '')),
    'run_id' => (string) (getenv('GITHUB_RUN_ID') ?: ''),
    'all_passed' => true,
    'tests' => $testEvidence,
    'source_files' => $sourceEvidence,
];
$encoded = json_encode($evidence, JSON_UNESCAPED_SLASHES);
if (!is_string($encoded)) {
    fwrite(STDERR, "Fix496 evidence JSON encoding failed.\n");
    exit(1);
}
if (file_put_contents($root . '/fix496-test-evidence.json', $encoded . "\n") === false) {
    fwrite(STDERR, "Fix496 evidence file could not be written.\n");
    exit(1);
}

echo "\nFIX496 MULTI FINAL GATE EVIDENCE STAMPED\n";
echo "ALL FOCUSED REGRESSION SUITES PASSED\n";
