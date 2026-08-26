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
    }
}

if ($externalUses < 1) {
    $fail('no external GitHub Actions references were inspected');
}

echo "PASS GitHub Actions immutable commit pinning ({$externalUses} references)\n";
