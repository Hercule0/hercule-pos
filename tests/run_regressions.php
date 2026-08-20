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
foreach ($files as $file) {
    $name = basename($file);
    echo "\n=== {$name} ===\n";
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($file);
    passthru($command, $code);
    if ($code !== 0) {
        $failures[] = $name;
    }
}

if ($failures) {
    fwrite(STDERR, "\nFocused regression failures: " . implode(', ', $failures) . "\n");
    exit(1);
}

echo "\nALL FOCUSED REGRESSION SUITES PASSED\n";
