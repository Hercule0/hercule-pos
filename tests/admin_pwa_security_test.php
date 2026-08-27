<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$sw = file_get_contents($root . '/public/admin/sw.js');

$fail = static function (string $message): never {
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
};

if (!is_string($sw)) {
    $fail('admin service worker could not be read');
}

if (!str_contains($sw, 'function safeAdminUrl(candidate)')) {
    $fail('push notification navigation has no URL guard');
}
if (!str_contains($sw, 'parsed.origin !== self.location.origin')) {
    $fail('push navigation does not enforce same-origin targets');
}
if (!str_contains($sw, '!parsed.pathname.startsWith("/public/admin/")')) {
    $fail('push navigation can escape the admin URL scope');
}
if (!str_contains($sw, 'data: { url: safeAdminUrl(data.url) }')) {
    $fail('push payload stores an unvalidated notification URL');
}
if (!str_contains($sw, 'var targetUrl = safeAdminUrl(event.notification.data && event.notification.data.url);')) {
    $fail('notification click does not revalidate its target URL');
}
if (str_contains($sw, 'var absoluteTarget = new URL(targetUrl, self.location.origin).href;')) {
    $fail('legacy unrestricted notification target navigation is still present');
}

if (!str_contains($sw, '.slice(0, 120)') || !str_contains($sw, '.slice(0, 500)')) {
    $fail('push title/body text is not bounded');
}

echo "PASS admin PWA push navigation security\n";
