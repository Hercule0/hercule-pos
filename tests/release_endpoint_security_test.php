<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$check = file_get_contents($root . '/public/api/release_check.php');
$download = file_get_contents($root . '/public/api/release_download.php');

$fail = static function (string $message): never {
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
};

if (!is_string($check) || !is_string($download)) {
    $fail('release endpoint sources could not be read');
}

foreach ([
    'release_public_base_url()' => 'update check does not resolve a guarded public base URL',
    'HERCULE_PUBLIC_BASE_URL must use HTTPS in production.' => 'production update URLs are not HTTPS-only',
    "\$_SERVER['WEBSITE_HOSTNAME']" => 'Azure trusted hostname fallback is missing',
    "['test', 'dev', 'development', 'local']" => 'Host-header fallback is not constrained to non-production environments',
    'UpdateSigner::sign($manifestInput)' => 'update manifest is not cryptographically signed',
    'ReleaseManager::createDownloadGrant(' => 'download grant is missing',
] as $needle => $message) {
    if (!str_contains($check, $needle)) {
        $fail($message);
    }
}

if (str_contains($check, "\$_SERVER['WEBSITE_HOSTNAME'] ?? \$_SERVER['HTTP_HOST']")) {
    $fail('production update URL can still fall back directly to client-controlled HTTP_HOST');
}

$basePosition = strpos($check, '$base = release_public_base_url();');
$grantPosition = strpos($check, 'ReleaseManager::createDownloadGrant(');
if ($basePosition === false || $grantPosition === false || $basePosition > $grantPosition) {
    $fail('download grant is created before the public base URL is validated');
}

if (!str_contains($download, "in_array(\$artifact, ['installer','blockmap','metadata'], true)")) {
    $fail('release download artifact selector is not allow-listed');
}
if (!str_contains($download, 'ReleaseManager::resolveDownloadGrant($token)')) {
    $fail('release download does not require a resolved bearer grant');
}
if (!str_contains($download, "if (\$remaining === 0 && \$artifact === 'installer')")) {
    $fail('completed installer download event is not tied to a full transfer');
}

echo "PASS release endpoint security hardening\n";
