<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$releases = file_get_contents($root . '/public/admin/releases.php');
$bootstrap = file_get_contents($root . '/public/admin/includes/bootstrap.php');
$fast = file_get_contents($root . '/public/admin/assets/js/release-upload-fast.js');
$shell = file_get_contents($root . '/public/admin/assets/js/admin-shell.js');
$pwa = file_get_contents($root . '/public/admin/assets/js/pwa.js');
$css = file_get_contents($root . '/public/admin/assets/css/releases.css');

$fail = static function (string $message): never {
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
};

foreach ([$releases, $bootstrap, $fast, $shell, $pwa, $css] as $source) {
    if (!is_string($source)) {
        $fail('release management source files could not be read');
    }
}

if (is_file($root . '/public/admin/release_upload.php')) {
    $fail('legacy sequential release upload endpoint still exists');
}
if (preg_match('/<script\b/i', $releases)) {
    $fail('releases.php still contains an inline or page-local script tag');
}
if (preg_match('/<style\b/i', $releases)) {
    $fail('releases.php still contains an inline style block');
}
if (preg_match('/\sstyle\s*=\s*/i', $releases)) {
    $fail('releases.php still contains inline style attributes');
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
if (!str_contains($releases, '<progress class="progress-track progress-native" id="progress-bar"')) {
    $fail('release progress does not use the CSP-safe native progress element');
}
if (!str_contains($releases, 'data-confirm="Delete this release and its stored files?"')) {
    $fail('destructive release confirmation was not converted to shared external JS wiring');
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
foreach (['data.maxUploadBytes', 'target-search', '/public/admin/release_upload_fast.php', 'bar.value = safePercent'] as $needle) {
    if (!str_contains($fast, $needle)) {
        $fail("external release uploader is missing {$needle}");
    }
}
if (str_contains($fast, '.style.') || str_contains($fast, 'setAttribute("style"')) {
    $fail('release uploader still mutates inline styles');
}
if (!str_contains($shell, 'form.dataset.confirm') || !str_contains($shell, 'window.confirm(confirmMessage)')) {
    $fail('shared admin shell does not enforce data-confirm forms');
}
if (trim($css) === '' || !str_contains($css, '::-webkit-progress-value')) {
    $fail('release stylesheet does not define native progress styling');
}

echo "PASS release page external JS/CSS hardening\n";
