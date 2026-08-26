<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$composer = json_decode((string)file_get_contents($root . '/composer.json'), true);
$lock = json_decode((string)file_get_contents($root . '/composer.lock'), true);

$fail = static function (string $message): never {
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
};

if (!is_array($composer) || !is_array($lock)) {
    $fail('composer metadata could not be parsed');
}

$requirements = $composer['require'] ?? [];
if (($requirements['guzzlehttp/guzzle'] ?? null) !== '^8.0.2') {
    $fail('guzzlehttp/guzzle must remain pinned to the reviewed v8 range');
}
if (($requirements['minishlink/web-push'] ?? null) !== '^11.0') {
    $fail('minishlink/web-push must remain pinned to the reviewed v11 range');
}

$versions = [];
foreach (($lock['packages'] ?? []) as $package) {
    if (isset($package['name'], $package['version'])) {
        $versions[(string)$package['name']] = ltrim((string)$package['version'], 'v');
    }
}

$guzzle = $versions['guzzlehttp/guzzle'] ?? '';
$webPush = $versions['minishlink/web-push'] ?? '';
if (!preg_match('/^8\./', $guzzle)) {
    $fail('composer.lock does not contain a reviewed Guzzle v8 release');
}
if (!preg_match('/^11\./', $webPush)) {
    $fail('composer.lock does not contain a reviewed Web Push v11 release');
}

echo "PASS composer locked dependency policy\n";
