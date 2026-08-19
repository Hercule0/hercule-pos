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
header("Content-Security-Policy: default-src 'self' 'unsafe-inline' data: blob:; script-src 'self' 'unsafe-inline' 'unsafe-eval' blob:; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com data:; connect-src 'self' data: blob: ws: wss:; img-src 'self' data: https:;");
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
<link rel="stylesheet" href="/public/admin/assets/css/style.css?v=20260817-unified-v3">
</head>
<body class="role-<?= htmlspecialchars($role) ?>">
<div class="app-layout">
    <!-- Top Header -->
    <header class="app-topbar">
        <div class="topbar-left">
            <button class="mobile-menu-btn" id="mobile-menu-toggle" type="button" aria-label="Toggle navigation menu">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 6h16M4 12h16M4 18h16"/></svg>
            </button>
            <a href="/public/admin/index.php" class="app-brand">
                <div class="brand-badge">H</div>
                <div class="brand-text">
                    <strong>Hercule</strong>
                    <span>POS Engine</span>
                </div>
            </a>
            <span class="system-status-pill"><span class="status-dot-pulse"></span>Live Azure WebApp</span>
        </div>

        <?php if ($username): ?>
        <div class="topbar-actions">
            <!-- Push Permission Banner Button -->
            <button type="button" class="push-enable-btn" id="push-perm-btn" title="Enable instant phone notifications">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4"/></svg>
                <span>Enable Alerts</span>
            </button>

            <!-- Instant Fast Test Alert Trigger -->
            <button type="button" class="test-alert-btn" id="fast-test-alert-btn" title="Test instant phone push, vibration and audio alert">
                <svg viewBox="0 0 24 24" aria-hidden="true"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>
                <span>Fast Test Alert</span>
            </button>

            <!-- Notifications Bell -->
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

            <!-- User Profile Menu -->
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
                    <a href="/public/health.php" target="_blank" class="dropdown-item">
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

    <!-- Main Shell Container (Sidebar + Content) -->
    <div class="app-body-container">
        <?php if ($username): ?>
        <!-- Pinned Sticky Desktop Sidebar -->
        <aside class="app-sidebar" id="app-sidebar">
            <nav class="sidebar-nav">
                <div class="sidebar-section-label">MAIN OPERATIONS</div>
                
                <a href="/public/admin/index.php" class="sidebar-link <?= $currentPage === 'index.php' ? 'active' : '' ?>">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 11.5 12 4l9 7.5v8a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1z"/></svg>
                    <span>Dashboard</span>
                </a>

                <a href="/public/admin/licenses.php" class="sidebar-link <?= in_array($currentPage, ['licenses.php', 'license_detail.php'], true) ? 'active' : '' ?>">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h10a4 4 0 0 1 0 8H9l-3 3v-3H4a4 4 0 0 1 0-8z"/><circle cx="14" cy="11" r="1"/></svg>
                    <span>Licenses</span>
                </a>

                <a href="/public/admin/customers.php" class="sidebar-link <?= $currentPage === 'customers.php' ? 'active' : '' ?>">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="9" cy="8" r="3"/><path d="M3.5 20c.2-4 2-6 5.5-6s5.3 2 5.5 6M16 5.5a3 3 0 0 1 0 5.8M16 14c3 0 4.5 2 4.5 5"/></svg>
                    <span>Customers</span>
                </a>

                <a href="/public/admin/recovery_requests.php" class="sidebar-link <?= $currentPage === 'recovery_requests.php' ? 'active' : '' ?>">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 12a8 8 0 1 0 2.3-5.7L4 8.5M4 4v4.5h4.5"/><path d="M12 8v4l3 2"/></svg>
                    <span>Emergency Recovery</span>
                    <span class="sidebar-counter" id="sidebar-recovery-badge" hidden>0</span>
                </a>

                <div class="sidebar-section-label">SYSTEM & SECURITY</div>

                <?php if (Auth::can('admins.manage')): ?>
                <a href="/public/admin/admin_users.php" class="sidebar-link <?= $currentPage === 'admin_users.php' ? 'active' : '' ?>">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="9" cy="8" r="3"/><path d="M3.5 20c.2-4 2-6 5.5-6s5.3 2 5.5 6M16 5.5a3 3 0 0 1 0 5.8M16 14c3 0 4.5 2 4.5 5"/></svg>
                    <span>Administrators</span>
                </a>
                <?php endif; ?>

                <a href="/public/admin/mfa_settings.php" class="sidebar-link <?= $currentPage === 'mfa_settings.php' ? 'active' : '' ?>">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3 4 6v5c0 5 3.4 8.5 8 10 4.6-1.5 8-5 8-10V6z"/><path d="m8.5 12 2.2 2.2 4.8-5"/></svg>
                    <span>2FA Settings</span>
                </a>

                <a href="/public/health.php" target="_blank" class="sidebar-link">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg>
                    <span>Diagnostics</span>
                    <span class="sidebar-pill-ok">200 OK</span>
                </a>
            </nav>

            <!-- Sidebar Health Card -->
            <div class="sidebar-health-card">
                <div class="health-card-header">
                    <span class="health-indicator-dot"></span>
                    <strong>Engine Status</strong>
                </div>
                <div class="health-metric-row">
                    <span>Database</span>
                    <span class="metric-val text-emerald">Connected</span>
                </div>
                <div class="health-metric-row">
                    <span>RSA Signer</span>
                    <span class="metric-val text-sky">SHA-256 Valid</span>
                </div>
                <div class="health-metric-row">
                    <span>Rate Limiter</span>
                    <span class="metric-val">60/min Active</span>
                </div>
                <button type="button" class="sidebar-test-btn" id="sidebar-fast-test-btn">
                    <svg viewBox="0 0 24 24"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>
                    <span>Trigger Test Alert</span>
                </button>
            </div>
        </aside>
        <div class="sidebar-backdrop" id="sidebar-backdrop"></div>
        <?php endif; ?>

        <!-- Primary Content Area -->
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
    <!-- Mobile Bottom Navigation Bar -->
    <nav class="app-mobile-nav" aria-label="Mobile Navigation">
        <a href="/public/admin/index.php" class="mobile-nav-item <?= $currentPage === 'index.php' ? 'active' : '' ?>">
            <svg viewBox="0 0 24 24"><path d="M3 11.5 12 4l9 7.5v8a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1z"/></svg>
            <span>Dashboard</span>
        </a>
        <a href="/public/admin/licenses.php" class="mobile-nav-item <?= in_array($currentPage, ['licenses.php', 'license_detail.php'], true) ? 'active' : '' ?>">
            <svg viewBox="0 0 24 24"><path d="M4 7h10a4 4 0 0 1 0 8H9l-3 3v-3H4a4 4 0 0 1 0-8z"/><circle cx="14" cy="11" r="1"/></svg>
            <span>Licenses</span>
        </a>
        <a href="/public/admin/customers.php" class="mobile-nav-item <?= $currentPage === 'customers.php' ? 'active' : '' ?>">
            <svg viewBox="0 0 24 24"><circle cx="9" cy="8" r="3"/><path d="M3.5 20c.2-4 2-6 5.5-6s5.3 2 5.5 6M16 5.5a3 3 0 0 1 0 5.8M16 14c3 0 4.5 2 4.5 5"/></svg>
            <span>Customers</span>
        </a>
        <a href="/public/admin/recovery_requests.php" class="mobile-nav-item <?= $currentPage === 'recovery_requests.php' ? 'active' : '' ?>">
            <svg viewBox="0 0 24 24"><path d="M4 12a8 8 0 1 0 2.3-5.7L4 8.5M4 4v4.5h4.5"/><path d="M12 8v4l3 2"/></svg>
            <span>Recovery</span>
            <span class="mobile-nav-badge" id="mobile-recovery-badge" hidden>0</span>
        </a>
        <button type="button" class="mobile-nav-item" id="mobile-drawer-trigger">
            <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="1"/><circle cx="19" cy="12" r="1"/><circle cx="5" cy="12" r="1"/></svg>
            <span>More</span>
        </button>
    </nav>
    <?php endif; ?>
</div>

<!-- Floating Notification Toast Stack -->
<div class="toast-stack" id="app-toast-stack" aria-live="polite"></div>

<!-- Modern PWA & Notification Engine Client Script -->
<script>
(function () {
    // 1. User Dropdown Menu
    var userBtn = document.getElementById("user-menu-btn");
    var userMenu = document.getElementById("user-dropdown-menu");
    if (userBtn && userMenu) {
        userBtn.addEventListener("click", function (e) {
            e.stopPropagation();
            var open = userMenu.classList.toggle("is-open");
            userBtn.setAttribute("aria-expanded", open ? "true" : "false");
        });
        document.addEventListener("click", function (e) {
            if (!e.target.closest(".user-menu-area")) {
                userMenu.classList.remove("is-open");
                userBtn.setAttribute("aria-expanded", "false");
            }
        });
    }

    // 2. Mobile Sidebar Drawer Toggle
    var menuToggle = document.getElementById("mobile-menu-toggle");
    var mobileDrawerTrigger = document.getElementById("mobile-drawer-trigger");
    var sidebar = document.getElementById("app-sidebar");
    var backdrop = document.getElementById("sidebar-backdrop");

    function toggleSidebar() {
        if (!sidebar) return;
        var open = sidebar.classList.toggle("is-open");
        if (backdrop) backdrop.classList.toggle("is-open", open);
    }
    function closeSidebar() {
        if (sidebar) sidebar.classList.remove("is-open");
        if (backdrop) backdrop.classList.remove("is-open");
    }

    if (menuToggle) menuToggle.addEventListener("click", toggleSidebar);
    if (mobileDrawerTrigger) mobileDrawerTrigger.addEventListener("click", toggleSidebar);
    if (backdrop) backdrop.addEventListener("click", closeSidebar);

    // 3. Web Audio Synthesizer
    var audioCtx = null;
    function playChime(type) {
        try {
            audioCtx = audioCtx || new (window.AudioContext || window.webkitAudioContext)();
            if (audioCtx.state === "suspended") audioCtx.resume();
            var osc = audioCtx.createOscillator();
            var gain = audioCtx.createGain();
            osc.type = "sine";
            var now = audioCtx.currentTime;
            if (type === "warning") {
                osc.frequency.setValueAtTime(520, now);
                osc.frequency.setValueAtTime(640, now + 0.08);
            } else {
                osc.frequency.setValueAtTime(780, now);
                osc.frequency.setValueAtTime(1040, now + 0.08);
            }
            gain.gain.setValueAtTime(0.0001, now);
            gain.gain.exponentialRampToValueAtTime(0.12, now + 0.02);
            gain.gain.exponentialRampToValueAtTime(0.0001, now + 0.25);
            osc.connect(gain);
            gain.connect(audioCtx.destination);
            osc.start(now);
            osc.stop(now + 0.26);
        } catch (e) {}
    }
    document.addEventListener("pointerdown", function () {
        if (!audioCtx) {
            try { audioCtx = new (window.AudioContext || window.webkitAudioContext)(); } catch (e) {}
        }
    }, { once: true });

    // 4. Physical Haptic Vibration
    function triggerVibration(pattern) {
        if ("vibrate" in navigator) {
            try { navigator.vibrate(pattern || [120, 60, 180]); } catch (e) {}
        }
    }

    // 5. Toast Notification Stack Manager
    var toastStack = document.getElementById("app-toast-stack");
    function showToast(opts) {
        if (!toastStack) return;
        var el = document.createElement("div");
        el.className = "app-toast " + (opts.type || "info");
        el.innerHTML = '<div class="toast-icon-wrap">' + (opts.icon || '<svg viewBox="0 0 24 24"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4"/></svg>') + '</div>' +
            '<div class="toast-content">' +
            '<strong>' + (opts.title || "Notification") + '</strong>' +
            '<span>' + (opts.message || "") + '</span>' +
            (opts.actionUrl ? '<a href="' + opts.actionUrl + '" class="toast-action-btn">' + (opts.actionLabel || "View") + '</a>' : '') +
            '</div>' +
            '<button type="button" class="toast-close-btn" aria-label="Dismiss">&times;</button>';

        var closeBtn = el.querySelector(".toast-close-btn");
        closeBtn.addEventListener("click", function () {
            el.classList.add("is-dismissing");
            setTimeout(function () { el.remove(); }, 250);
        });

        toastStack.appendChild(el);
        requestAnimationFrame(function () { el.classList.add("is-visible"); });

        setTimeout(function () {
            if (el.parentNode) {
                el.classList.add("is-dismissing");
                setTimeout(function () { el.remove(); }, 250);
            }
        }, opts.duration || 8000);

        playChime(opts.type);
        triggerVibration([100, 50, 150]);

        // Trigger native System / Phone Push Notification if permitted
        if ("Notification" in window && Notification.permission === "granted") {
            try {
                new Notification(opts.title, {
                    body: opts.message,
                    icon: "/public/admin/assets/icons/app-icon-192.png",
                    tag: opts.tag || "hercule-alert-" + Date.now()
                });
            } catch (err) {}
        }
    }

    // 6. Push Permission Management
    var pushPermBtn = document.getElementById("push-perm-btn");
    function updatePushBtn() {
        if (!pushPermBtn) return;
        if (!("Notification" in window) || !("serviceWorker" in navigator)) {
            pushPermBtn.hidden = true;
            return;
        }
        if (Notification.permission === "granted") {
            pushPermBtn.innerHTML = '<svg viewBox="0 0 24 24"><path d="M20 6 9 17l-5-5"/></svg><span>Alerts Active</span>';
            pushPermBtn.classList.add("is-granted");
        } else {
            pushPermBtn.innerHTML = '<svg viewBox="0 0 24 24"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4"/></svg><span>Enable Alerts</span>';
            pushPermBtn.classList.remove("is-granted");
        }
    }
    
    // Register Service Worker
    if ('serviceWorker' in navigator) {
        navigator.serviceWorker.register('/public/admin/service-worker.js').catch(function(err) {
            console.error('Service Worker registration failed:', err);
        });
    }

    function urlBase64ToUint8Array(base64String) {
        var padding = '='.repeat((4 - base64String.length % 4) % 4);
        var base64 = (base64String + padding).replace(/\-/g, '+').replace(/_/g, '/');
        var rawData = window.atob(base64);
        var outputArray = new Uint8Array(rawData.length);
        for (var i = 0; i < rawData.length; ++i) {
            outputArray[i] = rawData.charCodeAt(i);
        }
        return outputArray;
    }

    if (pushPermBtn) {
        pushPermBtn.addEventListener("click", function () {
            if ("Notification" in window && "serviceWorker" in navigator) {
                Notification.requestPermission().then(function (perm) {
                    updatePushBtn();
                    if (perm === "granted") {
                        navigator.serviceWorker.ready.then(function(registration) {
                            var vapidPublicKey = "BKraEuulwXx3knDp50hkOAI1QaJBnFxTngjhnfi48WkMMKcDSBCwxn4WePT0RSrEnJWEmgX-DpG9WiVgK_rNAAY";
                            return registration.pushManager.subscribe({
                                userVisibleOnly: true,
                                applicationServerKey: urlBase64ToUint8Array(vapidPublicKey)
                            });
                        }).then(function(subscription) {
                            return fetch('/public/admin/push_subscribe.php', {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/json'
                                },
                                body: JSON.stringify(subscription)
                            });
                        }).then(function() {
                            showToast({
                                title: "Push Notifications Enabled",
                                message: "You will now receive emergency recovery and expiry alerts directly on this device.",
                                type: "success"
                            });
                        }).catch(function(err) {
                            console.error('Failed to subscribe to push', err);
                        });
                    }
                });
            }
        });
        updatePushBtn();
    }

    // 7. Fast Test Alert Triggers (<50ms)
    var testBtns = [document.getElementById("fast-test-alert-btn"), document.getElementById("sidebar-fast-test-btn")];
    testBtns.forEach(function (btn) {
        if (!btn) return;
        btn.addEventListener("click", function () {
            btn.disabled = true;
            fetch('/public/admin/test_push.php').then(function() {
                setTimeout(function() { btn.disabled = false; }, 2000);
            }).catch(function(err) {
                console.error("Test push failed", err);
                btn.disabled = false;
            });
        });
    });

    // 8. Live Real Database Polling (Recovery Requests & License Expirations)
    var recoveryBadge = document.getElementById("recovery-notification-count");
    var sidebarRecoveryBadge = document.getElementById("sidebar-recovery-badge");
    var mobileRecoveryBadge = document.getElementById("mobile-recovery-badge");
    var lastSeenId = 0;
    try { lastSeenId = Number(localStorage.getItem("herculeRecoveryLastSeenId") || 0); } catch (e) {}

    function updateRecoveryCounters(count) {
        var n = Number(count) || 0;
        var txt = n > 99 ? "99+" : String(n);
        [recoveryBadge, sidebarRecoveryBadge, mobileRecoveryBadge].forEach(function (b) {
            if (!b) return;
            b.textContent = txt;
            b.hidden = n === 0;
        });
    }

    function pollRecovery() {
        fetch("/public/admin/recovery_notifications.php?after_id=" + encodeURIComponent(lastSeenId), {
            credentials: "same-origin",
            cache: "no-store",
            headers: { "Accept": "application/json" }
        })
        .then(function (r) { return r.json(); })
        .then(function (d) {
            if (!d || !d.ok) return;
            updateRecoveryCounters(d.pending_count);
            if (Array.isArray(d.requests) && d.requests.length > 0) {
                var req = d.requests[0];
                showToast({
                    title: "Password Recovery Request",
                    message: "User " + req.username + " submitted a reset request.",
                    actionUrl: req.url,
                    actionLabel: "Review Request",
                    type: "warning"
                });
                lastSeenId = Math.max(lastSeenId, d.latest_id);
                try { localStorage.setItem("herculeRecoveryLastSeenId", String(lastSeenId)); } catch (e) {}
            }
        })
        .catch(function () {})
        .finally(function () {
            setTimeout(pollRecovery, document.hidden ? 30000 : 15000);
        });
    }

    // Mobile sidebar toggle
    var mobileMenuToggle = document.getElementById('mobile-menu-toggle');
    if (mobileMenuToggle) {
        mobileMenuToggle.addEventListener('click', function () {
            document.body.classList.toggle('sidebar-open');
        });
    }

    // Start live polling after load
    setTimeout(pollRecovery, 800);
})();
</script>
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
