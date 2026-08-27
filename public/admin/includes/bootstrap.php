<?php
require_once __DIR__ . '/../../../includes/ErrorHandler.php';
ErrorHandler::register();
require_once __DIR__ . '/../../../includes/Database.php';
require_once __DIR__ . '/../../../includes/Auth.php';
require_once __DIR__ . '/../../../includes/Csrf.php';
require_once __DIR__ . '/../../../includes/License.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
header('X-Frame-Options: DENY');
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com data:; connect-src 'self'; img-src 'self' data: https:; worker-src 'self'; manifest-src 'self'; object-src 'none'; frame-ancestors 'none'; base-uri 'self'; form-action 'self';");
if ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')) {
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
}

Auth::startSession();

function render_header(string $title): void
{
    $username = Auth::currentUsername();
    $role = Auth::currentRole();
    $roleLabel = ['owner' => 'Owner', 'support' => 'Support', 'read_only' => 'Read only'][$role] ?? 'Read only';
    $currentPage = basename($_SERVER['PHP_SELF'] ?? '');
    ?>
<!DOCTYPE html>
<html lang="en" class="dark">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1.0, user-scalable=0, viewport-fit=cover">
<meta name="theme-color" content="#0d1117">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="mobile-web-app-capable" content="yes">
<link rel="manifest" href="/public/admin/manifest.json">
<link rel="icon" href="/public/admin/assets/icons/app-icon-192.png" type="image/png">
<link rel="apple-touch-icon" href="/public/admin/assets/icons/apple-touch-icon.png" sizes="180x180">
<title><?= htmlspecialchars($title) ?> — Hercule POS License Engine</title>
<link rel="stylesheet" href="/public/admin/assets/css/style.css?v=20260826-hardening2">
<?php if ($currentPage === 'releases.php'): ?>
<link rel="stylesheet" href="/public/admin/assets/css/releases.css?v=20260826-hardening2">
<?php endif; ?>
</head>
<body class="role-<?= htmlspecialchars($role) ?>">
<div class="app-layout">
    <header class="app-topbar">
        <div class="topbar-left">
            <button class="mobile-menu-btn" id="mobile-menu-toggle" type="button" aria-label="Toggle navigation menu">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 6h16M4 12h16M4 18h16"/></svg>
            </button>
            <a href="/public/admin/index.php" class="app-brand">
                <div class="brand-badge">H</div>
                <div class="brand-text"><strong>Hercule</strong><span>POS Engine</span></div>
            </a>
            <span class="system-status-pill"><span class="status-dot-pulse"></span><span data-health-summary>Checking…</span></span>
        </div>

        <?php if ($username): ?>
        <div class="topbar-actions">
            <button type="button" class="push-enable-btn" id="push-perm-btn" title="Enable instant phone notifications">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4"/></svg>
                <span>Enable Alerts</span>
            </button>
            <button type="button" class="test-alert-btn" id="fast-test-alert-btn" title="Test instant phone push, vibration and audio alert">
                <svg viewBox="0 0 24 24" aria-hidden="true"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>
                <span>Fast Test Alert</span>
            </button>

            <div class="notification-wrap">
                <a class="header-icon-btn" id="recovery-notification-button" href="/public/admin/recovery_requests.php" aria-label="Recovery alerts">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4"/></svg>
                    <span class="header-badge" id="recovery-notification-count" hidden>0</span>
                </a>
                <a class="header-icon-btn expiry-btn" id="license-expiry-button" href="/public/admin/index.php#expiring-soon" aria-label="License expiry alerts">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="8"/><path d="M12 8v5l3 2"/></svg>
                    <span class="header-badge warning" id="license-expiry-count" hidden>0</span>
                </a>
            </div>

            <div class="user-menu-area">
                <button class="user-pill-btn" id="user-menu-btn" type="button" aria-expanded="false" aria-label="User profile menu">
                    <span class="user-avatar-circle"><?= strtoupper(htmlspecialchars(substr($username, 0, 1))) ?></span>
                    <span class="user-pill-name"><?= htmlspecialchars($username) ?></span>
                    <svg class="user-pill-chevron" viewBox="0 0 24 24" aria-hidden="true"><path d="m7 9 5 5 5-5"/></svg>
                </button>
                <div class="user-dropdown-card" id="user-dropdown-menu">
                    <div class="dropdown-user-header">
                        <div class="dropdown-avatar"><?= strtoupper(htmlspecialchars(substr($username, 0, 1))) ?></div>
                        <div class="dropdown-user-info">
                            <strong><?= htmlspecialchars($username) ?></strong>
                            <small><?= htmlspecialchars($roleLabel) ?> Access</small>
                        </div>
                    </div>
                    <div class="dropdown-divider"></div>
                    <?php if (Auth::can('admins.manage')): ?>
                    <a href="/public/admin/admin_users.php" class="dropdown-item <?= $currentPage === 'admin_users.php' ? 'active' : '' ?>">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="9" cy="8" r="3"/><path d="M3.5 20c.2-4 2-6 5.5-6s5.3 2 5.5 6M16 5.5a3 3 0 0 1 0 5.8M16 14c3 0 4.5 2 4.5 5"/></svg>
                        <span>Administrators</span>
                    </a>
                    <?php endif; ?>
                    <a href="/public/admin/mfa_settings.php" class="dropdown-item <?= $currentPage === 'mfa_settings.php' ? 'active' : '' ?>">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3 4 6v5c0 5 3.4 8.5 8 10 4.6-1.5 8-5 8-10V6z"/><path d="m8.5 12 2.2 2.2 4.8-5"/></svg>
                        <span>Two-Factor Authentication</span>
                    </a>
                    <a href="/public/admin/change_password.php" class="dropdown-item <?= $currentPage === 'change_password.php' ? 'active' : '' ?>">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="5" y="10" width="14" height="10" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3M12 14v2"/></svg>
                        <span>Change Password</span>
                    </a>
                    <a href="/public/health.php" target="_blank" rel="noopener" class="dropdown-item">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg>
                        <span>System Health Check</span>
                    </a>
                    <div class="dropdown-divider"></div>
                    <a href="/public/admin/logout.php" class="dropdown-item logout-item">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M10 5H5v14h5M14 8l4 4-4 4M8 12h10"/></svg>
                        <span>Sign Out</span>
                    </a>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </header>

    <div class="app-body-container">
        <?php if ($username): ?>
        <aside class="app-sidebar" id="app-sidebar">
            <nav class="sidebar-nav">
                <div class="sidebar-section-label">MAIN OPERATIONS</div>
                <a href="/public/admin/index.php" class="sidebar-link <?= $currentPage === 'index.php' ? 'active' : '' ?>">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 11.5 12 4l9 7.5v8a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1z"/></svg><span>Dashboard</span>
                </a>
                <a href="/public/admin/licenses.php" class="sidebar-link <?= in_array($currentPage, ['licenses.php', 'license_detail.php'], true) ? 'active' : '' ?>">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h10a4 4 0 0 1 0 8H9l-3 3v-3H4a4 4 0 0 1 0-8z"/><circle cx="14" cy="11" r="1"/></svg><span>Licenses</span>
                </a>
                <a href="/public/admin/customers.php" class="sidebar-link <?= $currentPage === 'customers.php' ? 'active' : '' ?>">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="9" cy="8" r="3"/><path d="M3.5 20c.2-4 2-6 5.5-6s5.3 2 5.5 6M16 5.5a3 3 0 0 1 0 5.8M16 14c3 0 4.5 2 4.5 5"/></svg><span>Customers</span>
                </a>
                <a href="/public/admin/recovery_requests.php" class="sidebar-link <?= $currentPage === 'recovery_requests.php' ? 'active' : '' ?>">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 12a8 8 0 1 0 2.3-5.7L4 8.5M4 4v4.5h4.5"/><path d="M12 8v4l3 2"/></svg><span>Emergency Recovery</span>
                    <span class="sidebar-counter" id="sidebar-recovery-badge" hidden>0</span>
                </a>

                <div class="sidebar-section-label">SYSTEM & SECURITY</div>
                <?php if (Auth::can('admins.manage')): ?>
                <a href="/public/admin/admin_users.php" class="sidebar-link <?= $currentPage === 'admin_users.php' ? 'active' : '' ?>">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="9" cy="8" r="3"/><path d="M3.5 20c.2-4 2-6 5.5-6s5.3 2 5.5 6M16 5.5a3 3 0 0 1 0 5.8M16 14c3 0 4.5 2 4.5 5"/></svg><span>Administrators</span>
                </a>
                <?php endif; ?>
                <a href="/public/admin/mfa_settings.php" class="sidebar-link <?= $currentPage === 'mfa_settings.php' ? 'active' : '' ?>">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3 4 6v5c0 5 3.4 8.5 8 10 4.6-1.5 8-5 8-10V6z"/><path d="m8.5 12 2.2 2.2 4.8-5"/></svg><span>2FA Settings</span>
                </a>
                <a href="/public/health.php" target="_blank" rel="noopener" class="sidebar-link">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg><span>Diagnostics</span><span class="sidebar-pill-ok">CHECKING</span>
                </a>
            </nav>

            <div class="sidebar-health-card">
                <div class="health-card-header"><span class="health-indicator-dot"></span><strong>Engine Status</strong></div>
                <div class="health-metric-row"><span>Database</span><span class="metric-val">Checking…</span></div>
                <div class="health-metric-row"><span>RSA Signer</span><span class="metric-val">Checking…</span></div>
                <div class="health-metric-row"><span>Rate Limiter</span><span class="metric-val">Checking…</span></div>
                <button type="button" class="sidebar-test-btn" id="sidebar-fast-test-btn">
                    <svg viewBox="0 0 24 24"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg><span>Trigger Test Alert</span>
                </button>
            </div>
        </aside>
        <div class="sidebar-backdrop" id="sidebar-backdrop"></div>
        <?php endif; ?>

        <main class="app-main-content">
    <?php
}

function render_footer(): void
{
    $currentPage = basename($_SERVER['PHP_SELF'] ?? '');
    $username = Auth::currentUsername();
    ?>
        </main>
    </div>

    <?php if ($username): ?>
    <nav class="app-mobile-nav" aria-label="Mobile Navigation">
        <a href="/public/admin/index.php" class="mobile-nav-item <?= $currentPage === 'index.php' ? 'active' : '' ?>">
            <svg viewBox="0 0 24 24"><path d="M3 11.5 12 4l9 7.5v8a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1z"/></svg><span>Dashboard</span>
        </a>
        <a href="/public/admin/licenses.php" class="mobile-nav-item <?= in_array($currentPage, ['licenses.php', 'license_detail.php'], true) ? 'active' : '' ?>">
            <svg viewBox="0 0 24 24"><path d="M4 7h10a4 4 0 0 1 0 8H9l-3 3v-3H4a4 4 0 0 1 0-8z"/><circle cx="14" cy="11" r="1"/></svg><span>Licenses</span>
        </a>
        <a href="/public/admin/customers.php" class="mobile-nav-item <?= $currentPage === 'customers.php' ? 'active' : '' ?>">
            <svg viewBox="0 0 24 24"><circle cx="9" cy="8" r="3"/><path d="M3.5 20c.2-4 2-6 5.5-6s5.3 2 5.5 6M16 5.5a3 3 0 0 1 0 5.8M16 14c3 0 4.5 2 4.5 5"/></svg><span>Customers</span>
        </a>
        <a href="/public/admin/recovery_requests.php" class="mobile-nav-item <?= $currentPage === 'recovery_requests.php' ? 'active' : '' ?>">
            <svg viewBox="0 0 24 24"><path d="M4 12a8 8 0 1 0 2.3-5.7L4 8.5M4 4v4.5h4.5"/><path d="M12 8v4l3 2"/></svg><span>Recovery</span><span class="mobile-nav-badge" id="mobile-recovery-badge" hidden>0</span>
        </a>
        <button type="button" class="mobile-nav-item" id="mobile-drawer-trigger">
            <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="1"/><circle cx="19" cy="12" r="1"/><circle cx="5" cy="12" r="1"/></svg><span>More</span>
        </button>
    </nav>
    <?php endif; ?>
</div>

<div class="toast-stack" id="app-toast-stack" aria-live="polite"></div>
<script src="/public/admin/assets/js/admin-shell.js?v=20260826-hardening2" defer></script>
<script src="/public/admin/assets/js/pwa.js?v=20260826-hardening2" defer></script>
<script src="/public/admin/assets/js/admin-health-live.js?v=20260826-hardening2" defer></script>
<?php if ($currentPage === 'releases.php'): ?>
<script src="/public/admin/assets/js/release-upload-fast.js?v=20260826-hardening2" defer></script>
<?php endif; ?>
</body>
</html>
    <?php
}

function flash_set(string $message, string $type = 'success'): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function flash_render(): void
{
    if (empty($_SESSION['flash'])) {
        return;
    }
    $f = $_SESSION['flash'];
    unset($_SESSION['flash']);
    $typeClass = $f['type'] === 'error' ? 'flash-error' : 'flash-success';
    $icon = $f['type'] === 'error'
        ? '<svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>'
        : '<svg viewBox="0 0 24 24"><path d="M20 6 9 17l-5-5"/></svg>';
    echo '<div class="app-flash ' . htmlspecialchars($typeClass) . '">' . $icon . '<span>' . htmlspecialchars($f['message']) . '</span></div>';
}
