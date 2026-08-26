<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$adminUsers = file_get_contents($root . '/public/admin/admin_users.php');
$adminPermissions = file_get_contents($root . '/public/admin/admin_permissions.php');
$customers = file_get_contents($root . '/public/admin/customers.php');
$customerJs = file_get_contents($root . '/public/admin/assets/js/customers.js');
$sessions = file_get_contents($root . '/public/admin/sessions.php');
$shell = file_get_contents($root . '/public/admin/assets/js/admin-shell.js');
$style = file_get_contents($root . '/public/admin/assets/css/style.css');

$fail = static function (string $message): never {
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
};

foreach ([$adminUsers, $adminPermissions, $customers, $customerJs, $sessions, $shell, $style] as $source) {
    if (!is_string($source)) $fail('admin hardening source files could not be read');
}

foreach (['public/admin/push_test.php', 'public/admin/push_subscribe.php'] as $legacy) {
    if (is_file($root . '/' . $legacy)) {
        $fail("obsolete push endpoint still exists: {$legacy}");
    }
}

foreach (
    [
        'admin_users.php' => $adminUsers,
        'admin_permissions.php' => $adminPermissions,
        'customers.php' => $customers,
        'sessions.php' => $sessions,
    ] as $name => $source
) {
    if (preg_match('/<style\b/i', $source)) $fail("{$name} still contains an inline style block");
    if (preg_match('/\sstyle\s*=\s*/i', $source)) $fail("{$name} still contains inline style attributes");
    if (preg_match('/\son[a-z]+\s*=\s*/i', $source)) $fail("{$name} still contains inline event handlers");
    if (preg_match('/<script(?![^>]*\bsrc=)[^>]*>/i', $source)) $fail("{$name} still contains inline JavaScript");
}

if (str_contains($adminUsers, 'insertAdjacentHTML')) {
    $fail('administrator password prompt still injects HTML');
}
if (!str_contains($adminUsers, 'data-password-prompt=')) {
    $fail('administrator actions are not wired to safe password prompts');
}
if (!str_contains($sessions, 'data-confirm=')) {
    $fail('session revocation forms are not wired to shared confirmations');
}
if (!str_contains($adminPermissions, 'data-submit-on-change')) {
    $fail('permission account selector still depends on an inline change handler');
}
if (!str_contains($customers, '/public/admin/assets/js/customers.js') || !str_contains($customers, 'data-submit-on-change')) {
    $fail('customer page is not fully wired to external/declarative behavior');
}
if (!str_contains($customers, 'data-confirm="Delete this customer')) {
    $fail('customer deletion is not wired through shared confirmation handling');
}
if (str_contains($customerJs, '.innerHTML') || str_contains($customerJs, 'insertAdjacentHTML')) {
    $fail('customer page JavaScript uses unsafe HTML insertion');
}
if (!str_contains($shell, 'input.value = password') || !str_contains($shell, 'document.createElement("input")')) {
    $fail('shared password prompt does not create a safe DOM input');
}
if (!str_contains($shell, 'data-submit-on-change')) {
    $fail('shared admin shell does not wire declarative change submission');
}
if (str_contains($shell, 'insertAdjacentHTML') || str_contains($shell, '.innerHTML')) {
    $fail('shared admin shell uses unsafe HTML insertion');
}
foreach (['admin-users.css', 'admin-permissions.css', 'sessions.css'] as $asset) {
    if (!str_contains($style, $asset)) $fail("style.css does not load {$asset}");
}

echo "PASS admin inline action hardening\n";
