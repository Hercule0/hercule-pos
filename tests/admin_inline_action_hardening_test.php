<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$adminUsers = file_get_contents($root . '/public/admin/admin_users.php');
$adminPermissions = file_get_contents($root . '/public/admin/admin_permissions.php');
$auditLog = file_get_contents($root . '/public/admin/audit_log.php');
$backups = file_get_contents($root . '/public/admin/backups.php');
$changePassword = file_get_contents($root . '/public/admin/change_password.php');
$changePasswordJs = file_get_contents($root . '/public/admin/assets/js/change-password.js');
$changePasswordCss = file_get_contents($root . '/public/admin/assets/css/change-password.css');
$customers = file_get_contents($root . '/public/admin/customers.php');
$customerJs = file_get_contents($root . '/public/admin/assets/js/customers.js');
$devices = file_get_contents($root . '/public/admin/devices.php');
$deviceJs = file_get_contents($root . '/public/admin/assets/js/devices.js');
$dashboard = file_get_contents($root . '/public/admin/index.php');
$dashboardCss = file_get_contents($root . '/public/admin/assets/css/dashboard.css');
$licenses = file_get_contents($root . '/public/admin/licenses.php');
$licensesJs = file_get_contents($root . '/public/admin/assets/js/licenses.js');
$licenseDetail = file_get_contents($root . '/public/admin/license_detail.php');
$licenseDetailJs = file_get_contents($root . '/public/admin/assets/js/license-detail.js');
$licenseLifecycle = file_get_contents($root . '/public/admin/license_lifecycle.php');
$licenseLifecycleJs = file_get_contents($root . '/public/admin/assets/js/license-lifecycle.js');
$login = file_get_contents($root . '/public/admin/login.php');
$loginJs = file_get_contents($root . '/public/admin/assets/js/login.js');
$mfa = file_get_contents($root . '/public/admin/mfa_settings.php');
$monitoring = file_get_contents($root . '/public/admin/monitoring.php');
$notifications = file_get_contents($root . '/public/admin/notification_settings.php');
$notificationCss = file_get_contents($root . '/public/admin/assets/css/notification-settings.css');
$recovery = file_get_contents($root . '/public/admin/recovery_requests.php');
$recoveryJs = file_get_contents($root . '/public/admin/assets/js/recovery-requests.js');
$recoveryCss = file_get_contents($root . '/public/admin/assets/css/recovery-requests.css');
$sessions = file_get_contents($root . '/public/admin/sessions.php');
$tools = file_get_contents($root . '/public/admin/tools.php');
$toolsCss = file_get_contents($root . '/public/admin/assets/css/tools.css');
$shell = file_get_contents($root . '/public/admin/assets/js/admin-shell.js');
$style = file_get_contents($root . '/public/admin/assets/css/style.css');

$fail = static function (string $message): never {
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
};

foreach ([
    $adminUsers, $adminPermissions, $auditLog, $backups,
    $changePassword, $changePasswordJs, $changePasswordCss,
    $customers, $customerJs, $devices, $deviceJs,
    $dashboard, $dashboardCss, $licenses, $licensesJs,
    $licenseDetail, $licenseDetailJs, $licenseLifecycle, $licenseLifecycleJs,
    $login, $loginJs, $mfa, $monitoring,
    $notifications, $notificationCss,
    $recovery, $recoveryJs, $recoveryCss,
    $sessions, $tools, $toolsCss, $shell, $style,
] as $source) {
    if (!is_string($source)) $fail('admin hardening source files could not be read');
}

foreach (['public/admin/push_test.php', 'public/admin/push_subscribe.php', 'public/admin/release_upload.php'] as $legacy) {
    if (is_file($root . '/' . $legacy)) {
        $fail("obsolete admin endpoint still exists: {$legacy}");
    }
}

foreach (
    [
        'admin_users.php' => $adminUsers,
        'admin_permissions.php' => $adminPermissions,
        'audit_log.php' => $auditLog,
        'backups.php' => $backups,
        'change_password.php' => $changePassword,
        'customers.php' => $customers,
        'devices.php' => $devices,
        'index.php' => $dashboard,
        'licenses.php' => $licenses,
        'license_detail.php' => $licenseDetail,
        'license_lifecycle.php' => $licenseLifecycle,
        'login.php' => $login,
        'mfa_settings.php' => $mfa,
        'monitoring.php' => $monitoring,
        'notification_settings.php' => $notifications,
        'recovery_requests.php' => $recovery,
        'sessions.php' => $sessions,
        'tools.php' => $tools,
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
if (!str_contains($auditLog, 'data-submit-on-change')) {
    $fail('audit log action filter still depends on an inline change handler');
}
if (!str_contains($customers, '/public/admin/assets/js/customers.js') || !str_contains($customers, 'data-submit-on-change')) {
    $fail('customer page is not fully wired to external/declarative behavior');
}
if (!str_contains($customers, 'data-confirm="Delete this customer')) {
    $fail('customer deletion is not wired through shared confirmation handling');
}
if (!str_contains($devices, '/public/admin/assets/js/devices.js') || !str_contains($devices, 'data-confirm=')) {
    $fail('device page is not fully wired to external/declarative behavior');
}
if (!str_contains($style, 'dashboard.css') || trim($dashboardCss) === '') {
    $fail('dashboard external stylesheet is not loaded');
}
if (str_contains($dashboard, '</main>')) {
    $fail('dashboard closes the shared shell main element directly');
}
if (!str_contains($licenses, '/public/admin/assets/js/licenses.js') || !str_contains($licenses, 'data-submit-on-change')) {
    $fail('license listing page is not fully wired to external/declarative behavior');
}
if (!str_contains($licenseDetail, '/public/admin/assets/js/license-detail.js') || !str_contains($licenseDetail, 'data-confirm=')) {
    $fail('license detail page is not fully wired to external/shared behavior');
}
if (!str_contains($licenseLifecycle, '/public/admin/assets/js/license-lifecycle.js') || !str_contains($licenseLifecycle, 'data-confirm=')) {
    $fail('license lifecycle page is not fully wired to external/shared behavior');
}
if (!str_contains($changePassword, '/public/admin/assets/js/change-password.js') || !str_contains($style, 'change-password.css')) {
    $fail('password change page is not fully externalized');
}
if (!str_contains($changePasswordJs, 'bar.dataset.score') || str_contains($changePasswordJs, '.style.')) {
    $fail('password strength UI still mutates inline styles');
}
if (trim($changePasswordCss) === '') {
    $fail('password strength stylesheet is empty');
}
if (!str_contains($mfa, 'data-confirm="Disable two-factor authentication?"')) {
    $fail('MFA disable confirmation is not wired declaratively');
}
if (!str_contains($notifications, 'notification-settings-card') || !str_contains($style, 'notification-settings.css') || trim($notificationCss) === '') {
    $fail('notification settings styles are not fully externalized');
}
if (!str_contains($tools, 'admin-tool-card') || !str_contains($style, 'tools.css') || trim($toolsCss) === '') {
    $fail('admin tools styles are not fully externalized');
}
if (!str_contains($login, '/public/admin/assets/js/login.js') || !str_contains($login, 'data-loading-label=')) {
    $fail('login page is not fully wired to external behavior');
}
if (!str_contains($recovery, '/public/admin/assets/js/recovery-requests.js') || !str_contains($style, 'recovery-requests.css')) {
    $fail('recovery review page is not fully externalized');
}
if (!str_contains($recovery, 'data-confirm="هل تريد رفض طلب الاسترداد؟"') || !str_contains($recovery, 'data-confirm="هل تحققت من هوية العميل')) {
    $fail('recovery approve/reject confirmations are not declarative');
}
if (!str_contains($shell, 'event.submitter') || !str_contains($shell, 'submitter.dataset.confirm')) {
    $fail('shared admin shell does not support submit-button confirmations');
}

foreach (
    [
        'customers.js' => $customerJs,
        'devices.js' => $deviceJs,
        'licenses.js' => $licensesJs,
        'license-detail.js' => $licenseDetailJs,
        'license-lifecycle.js' => $licenseLifecycleJs,
        'change-password.js' => $changePasswordJs,
        'login.js' => $loginJs,
        'recovery-requests.js' => $recoveryJs,
    ] as $name => $source
) {
    if (str_contains($source, '.innerHTML') || str_contains($source, 'insertAdjacentHTML')) {
        $fail("{$name} uses unsafe HTML insertion");
    }
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
foreach ([
    'admin-users.css', 'admin-permissions.css', 'sessions.css', 'license-lifecycle.css',
    'dashboard.css', 'change-password.css', 'notification-settings.css', 'tools.css', 'recovery-requests.css',
] as $asset) {
    if (!str_contains($style, $asset)) $fail("style.css does not load {$asset}");
}

echo "PASS admin inline action hardening\n";
