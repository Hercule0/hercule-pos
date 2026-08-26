<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$bootstrap = file_get_contents($root . '/public/admin/includes/bootstrap.php');
$shell = file_get_contents($root . '/public/admin/assets/js/admin-shell.js');
$pwa = file_get_contents($root . '/public/admin/assets/js/pwa.js');
$pushConfig = file_get_contents($root . '/public/admin/push_config.php');
$testPush = file_get_contents($root . '/public/admin/test_push.php');

$fail = static function (string $message): never {
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
};

foreach ([$bootstrap, $shell, $pwa, $pushConfig, $testPush] as $source) {
    if (!is_string($source)) {
        $fail('admin shell source files could not be read');
    }
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
    $fail('PWA push subscription no longer obtains server-side push configuration');
}
if (!str_contains($pushConfig, "'csrfToken' => Csrf::token()")) {
    $fail('push configuration does not expose the authenticated session CSRF token');
}
if (!str_contains($pwa, '"X-CSRF-Token": config.csrfToken')) {
    $fail('PWA mutating push requests are not bound to the admin CSRF token');
}
if (str_contains($pwa, '.innerHTML')) {
    $fail('PWA admin UI still constructs markup with innerHTML');
}
if (!str_contains($testPush, 'Csrf::check(Csrf::submittedToken())')) {
    $fail('test push endpoint does not require CSRF validation');
}
if (!str_contains($testPush, 'PushNotifier::sendPushToAdmin(')) {
    $fail('test push endpoint is not scoped to the current administrator');
}
if (str_contains($testPush, 'PushNotifier::sendPush(')) {
    $fail('test push endpoint still uses the broadcast push path');
}

echo "PASS admin shell CSP/runtime hardening\n";
