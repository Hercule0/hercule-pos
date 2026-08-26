<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$releases = file_get_contents($root . '/public/admin/releases.php');
$bootstrap = file_get_contents($root . '/public/admin/includes/bootstrap.php');
$fast = file_get_contents($root . '/public/admin/assets/js/release-upload-fast.js');
$pwa = file_get_contents($root . '/public/admin/assets/js/pwa.js');
$css = file_get_contents($root . '/public/admin/assets/css/releases.css');

$fail = static function (string $message): never {
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
};

foreach ([$releases, $bootstrap, $fast, $pwa, $css] as $source) {
    if (!is_string($source)) {
        $fail('release management source files could not be read');
    }
}

if (preg_match('/<script\b/i', $releases)) {
    $fail('releases.php still contains an inline or page-local script tag');
}
if (preg_match('/<style\b/i', $releases)) {
    $fail('releases.php still contains an inline style block');
}
if (preg_match('/\son[a-z]+\s*=/i', $releases)) {
    $fail('releases.php still contains inline event handlers');
}
if (str_contains($releases, '/public/admin/release_upload.php')) {
    $fail('releases.php still references the legacy sequential upload endpoint');
}
if (!str_contains($releases, 'data-max-upload-bytes=')) {
    $fail('release form does not expose its server upload limit to the external uploader');
}
if (!str_contains($releases, 'data-confirm="Delete this release and its stored files?"')) {
    $fail('destructive release confirmation was not converted to external JS wiring');
}
if (!str_contains($bootstrap, '/public/admin/assets/css/releases.css')) {
    $fail('admin shell does not load the external release stylesheet');
}
if (!str_contains($bootstrap, '/public/admin/assets/js/release-upload-fast.js')) {
    $fail('admin shell does not load the release uploader explicitly');
}
if (str_contains($pwa, 'release-upload-fast.js')) {
    $fail('PWA shell still dynamically injects the release uploader');
}
foreach (['data.maxUploadBytes', 'target-search', 'data-confirm', '/public/admin/release_upload_fast.php'] as $needle) {
    if (!str_contains($fast, $needle)) {
        $fail("external release uploader is missing {$needle}");
    }
}
if (trim($css) === '') {
    $fail('release stylesheet is empty');
}

echo "PASS release page external JS/CSS hardening\n";
