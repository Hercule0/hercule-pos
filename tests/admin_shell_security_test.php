<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$bootstrap = file_get_contents($root . '/public/admin/includes/bootstrap.php');
$shell = file_get_contents($root . '/public/admin/assets/js/admin-shell.js');
$pwa = file_get_contents($root . '/public/admin/assets/js/pwa.js');

$fail = static function (string $message): never {
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
};

if (!is_string($bootstrap) || !is_string($shell) || !is_string($pwa)) {
    $fail('admin shell source files could not be read');
}
if (str_contains($bootstrap, "'unsafe-eval'")) {
    $fail('admin CSP still allows unsafe-eval');
}
if (preg_match('/<script(?![^>]*\bsrc=)[^>]*>/i', $bootstrap)) {
    $fail('shared admin bootstrap still contains inline script blocks');
}
if (str_contains($bootstrap, 'BKraEuulwXx3knDp50hkOAI1QaJBnFxTngjhnfi48WkMMKcDSBCwxn4WePT0RSrEnJWEmgX-DpG9WiVgK_rNAAY')) {
    $fail('legacy hard-coded VAPID public key remains in shared bootstrap');
}
foreach (['admin-shell.js', 'pwa.js', 'admin-health-live.js'] as $asset) {
    if (!str_contains($bootstrap, '/public/admin/assets/js/' . $asset)) {
        $fail("shared bootstrap does not load {$asset}");
    }
}
if (!str_contains($shell, 'textContent = String(options.message || "")')) {
    $fail('admin toast message is not rendered through textContent');
}
if (!str_contains($shell, 'url.origin !== window.location.origin') || !str_contains($shell, '/public/admin/')) {
    $fail('admin toast action URL is not constrained to same-origin admin paths');
}
if (!str_contains($pwa, '/public/admin/push_config.php')) {
    $fail('PWA push subscription no longer obtains the VAPID public key from server config');
}

echo "PASS admin shell CSP/runtime hardening\n";
