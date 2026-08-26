<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$api = file_get_contents($root . '/public/admin/api.php');
$csrf = file_get_contents($root . '/includes/Csrf.php');
$pwa = file_get_contents($root . '/public/admin/assets/js/pwa.js');

$fail = static function (string $message): never {
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
};

foreach ([$api, $csrf, $pwa] as $source) {
    if (!is_string($source)) $fail('admin API security source files could not be read');
}

if (!str_contains($api, 'if (!Csrf::check(Csrf::submittedToken()))')) {
    $fail('legacy admin mutations do not require a valid session CSRF token');
}
foreach (['HTTP_ORIGIN', 'HTTP_SEC_FETCH_SITE', '$sameOrigin', '$jsonRequest'] as $legacyFallback) {
    if (str_contains($api, $legacyFallback)) {
        $fail("legacy same-origin CSRF fallback remains: {$legacyFallback}");
    }
}
if (!str_contains($api, "case 'handle_recovery':")) {
    $fail('legacy recovery action compatibility guard is missing');
}
if (!str_contains($api, 'RECOVERY_REVIEW_REQUIRED') || !str_contains($api, '], 410);')) {
    $fail('legacy recovery mutation is not fail-closed');
}
if (str_contains($api, "UPDATE password_recovery_requests SET status = ?, resolved_at = NOW()")) {
    $fail('legacy admin API can still directly approve/reject recovery rows');
}
if (!str_contains($api, "'csrf_token' => Csrf::token()")) {
    $fail('admin bootstrap response no longer exposes the session CSRF token to authenticated legacy clients');
}
if (!str_contains($csrf, 'hash_equals($_SESSION[\'csrf_token\'], $submittedToken)')) {
    $fail('CSRF comparison is not timing-safe');
}
if (!str_contains($pwa, '"X-CSRF-Token": config.csrfToken')) {
    $fail('active PWA mutation client does not send the admin CSRF token');
}

echo "PASS legacy admin API CSRF/recovery hardening\n";
