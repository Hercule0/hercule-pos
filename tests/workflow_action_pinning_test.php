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
$node24Pins = [
    'actions/checkout@fbc6f3992d24b796d5a048ff273f7fcc4a7b6c09',
    'actions/upload-artifact@b7c566a772e6b6bfb58ed0dc250532a479d7789f',
    'actions/download-artifact@37930b1c2abaa49bbe596cd826c3c89aef350131',
];

foreach ($workflowFiles as $file) {
    $lines = file($file, FILE_IGNORE_NEW_LINES);
    if (!is_array($lines)) $fail('could not read workflow ' . basename($file));

    foreach ($lines as $number => $line) {
        if (!preg_match('/^\s*uses:\s*([^\s#]+)(?:\s+#.*)?$/', $line, $match)) continue;
        $uses = trim($match[1]);
        if (str_starts_with($uses, './') || str_starts_with($uses, 'docker://')) continue;
        $externalUses++;

        if (!preg_match('/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+@[a-f0-9]{40}$/', $uses)) {
            $fail(basename($file) . ':' . ($number + 1) . ' external action is not pinned to an immutable 40-char commit: ' . $uses);
        }

        if (str_starts_with($uses, 'actions/checkout@')) {
            $checkoutUses++;
            if ($uses !== $node24Pins[0]) {
                $fail(basename($file) . ':' . ($number + 1) . ' checkout is not pinned to the reviewed Node 24 commit');
            }

            $window = implode("\n", array_slice($lines, $number + 1, 6));
            if (!preg_match('/\bwith:\s*\n\s+persist-credentials:\s*false\b/', $window)) {
                $fail(basename($file) . ':' . ($number + 1) . ' checkout must set persist-credentials: false');
            }
        }

        if (str_starts_with($uses, 'actions/upload-artifact@') && $uses !== $node24Pins[1]) {
            $fail(basename($file) . ':' . ($number + 1) . ' upload-artifact is not pinned to the reviewed Node 24 commit');
        }

        if (str_starts_with($uses, 'actions/download-artifact@') && $uses !== $node24Pins[2]) {
            $fail(basename($file) . ':' . ($number + 1) . ' download-artifact is not pinned to the reviewed Node 24 commit');
        }
    }
}

if ($externalUses < 1) {
    $fail('no external GitHub Actions references were inspected');
}
if ($checkoutUses < 1) {
    $fail('no checkout actions were inspected');
}

echo "PASS GitHub Actions immutable pinning, Node 24 artifact actions, and non-persistent checkout credentials ({$externalUses} references)\n";
