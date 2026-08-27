<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$workflowFiles = glob($root . '/.github/workflows/*.{yml,yaml}', GLOB_BRACE) ?: [];

$fail = static function (string $message): never {
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
};

if (!$workflowFiles) {
    $fail('no GitHub Actions workflows were found');
}

$externalUses = 0;
$checkoutUses = 0;
$reviewedPins = [
    'actions/checkout' => 'fbc6f3992d24b796d5a048ff273f7fcc4a7b6c09',
    'actions/upload-artifact' => 'b7c566a772e6b6bfb58ed0dc250532a479d7789f',
    'actions/download-artifact' => '37930b1c2abaa49bbe596cd826c3c89aef350131',
    'azure/login' => '7ddb5af1ef8758cf1353cf3b42f940aee27ba21c',
    'azure/webapps-deploy' => '02a81bead70021f5284939794bcec79c271ab383',
];

foreach ($workflowFiles as $file) {
    $lines = file($file, FILE_IGNORE_NEW_LINES);
    if (!is_array($lines)) $fail('could not read workflow ' . basename($file));

    foreach ($lines as $number => $line) {
        if (!preg_match('/^\s*uses:\s*([^\s#]+)(?:\s+#.*)?$/', $line, $match)) continue;
        $uses = trim($match[1]);
        if (str_starts_with($uses, './') || str_starts_with($uses, 'docker://')) continue;
        $externalUses++;

        if (!preg_match('/^([A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+)@([a-f0-9]{40})$/', $uses, $parts)) {
            $fail(basename($file) . ':' . ($number + 1) . ' external action is not pinned to an immutable 40-char commit: ' . $uses);
        }

        $action = strtolower($parts[1]);
        $sha = strtolower($parts[2]);
        if (isset($reviewedPins[$action]) && $sha !== $reviewedPins[$action]) {
            $fail(basename($file) . ':' . ($number + 1) . ' ' . $action . ' is not pinned to the reviewed hardened runtime commit');
        }

        if ($action === 'actions/checkout') {
            $checkoutUses++;
            $window = implode("\n", array_slice($lines, $number + 1, 6));
            if (!preg_match('/\bwith:\s*\n\s+persist-credentials:\s*false\b/', $window)) {
                $fail(basename($file) . ':' . ($number + 1) . ' checkout must set persist-credentials: false');
            }
        }
    }
}

if ($externalUses < 1) {
    $fail('no external GitHub Actions references were inspected');
}
if ($checkoutUses < 1) {
    $fail('no checkout actions were inspected');
}

echo "PASS GitHub Actions immutable pinning, reviewed Node 24/Azure runtime pins, and non-persistent checkout credentials ({$externalUses} references)\n";
