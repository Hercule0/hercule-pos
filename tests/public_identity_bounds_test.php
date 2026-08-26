<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$files = [
    'activate' => file_get_contents($root . '/public/api/activate.php'),
    'validate' => file_get_contents($root . '/public/api/validate.php'),
    'check_update' => file_get_contents($root . '/public/api/check_update.php'),
];

$fail = static function (string $message): never {
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
};

foreach ($files as $name => $source) {
    if (!is_string($source)) $fail("could not read {$name} endpoint");
    if (!str_contains($source, 'strlen($licenseKey) > 64')) {
        $fail("{$name} does not bound license_key length before database access");
    }
    if (!str_contains($source, "preg_match('/[\\x00-\\x1F\\x7F]/', \$licenseKey)")) {
        $fail("{$name} does not reject control characters in license_key");
    }
}

foreach (['activate', 'validate'] as $name) {
    $source = $files[$name];
    if (!str_contains($source, 'strlen($hwid) > 160')) {
        $fail("{$name} does not bound hwid length");
    }
    if (!str_contains($source, 'strlen($appVersion) > 50')) {
        $fail("{$name} does not bound app_version length");
    }
}

if (!str_contains($files['check_update'], "'check_update_by_key'")) {
    $fail('cheap update polling is not rate-limited per license key');
}

echo "PASS public licensing identity bounds and per-key polling protection\n";
