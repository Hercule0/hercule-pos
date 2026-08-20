<?php
$failures = [];
function nav_check(string $label, bool $condition): void
{
    global $failures;
    echo ($condition ? '[PASS] ' : '[FAIL] ') . $label . "\n";
    if (!$condition) $failures[] = $label;
}

$tools = file_get_contents(__DIR__ . '/../public/admin/tools.php');
$pwa = file_get_contents(__DIR__ . '/../public/admin/assets/js/pwa.js');

nav_check('Admin tools hub exists and requires authentication', is_string($tools) && str_contains($tools, 'Auth::require();'));
nav_check('Tools hub gates license operations by licenses.manage', str_contains($tools, "Auth::can('licenses.manage')"));
nav_check('Tools hub gates releases by releases.manage', str_contains($tools, "Auth::can('releases.manage')"));
nav_check('Tools hub gates owner-only controls by current owner role', str_contains($tools, "$isOwner = $role === 'owner'"));
nav_check('Tools hub links device management', str_contains($tools, '/public/admin/devices.php'));
nav_check('Tools hub links monitoring', str_contains($tools, '/public/admin/monitoring.php'));
nav_check('Tools hub links releases', str_contains($tools, '/public/admin/releases.php'));
nav_check('Tools hub links sessions', str_contains($tools, '/public/admin/sessions.php'));
nav_check('Tools hub links notification settings', str_contains($tools, '/public/admin/notification_settings.php'));
nav_check('Tools hub links audit log', str_contains($tools, '/public/admin/audit_log.php'));
nav_check('Tools hub links permission overrides', str_contains($tools, '/public/admin/admin_permissions.php'));
nav_check('Tools hub links backup health', str_contains($tools, '/public/admin/backups.php'));

nav_check('Global navigation exposes Admin Tools', is_string($pwa) && str_contains($pwa, 'wireAdminToolsNavigation'));
nav_check('Global navigation targets tools.php', str_contains($pwa, '/public/admin/tools.php'));
nav_check('Push registration remains present', str_contains($pwa, 'registerServiceWorker();'));
nav_check('Push subscription flow remains present', str_contains($pwa, 'ensurePushSubscription'));

if ($failures) {
    echo "\n" . count($failures) . " TEST(S) FAILED\n";
    exit(1);
}

echo "\nNAVIGATION HUB TESTS PASSED\n";
