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
header("Content-Security-Policy: default-src 'self'; base-uri 'self'; object-src 'none'; frame-ancestors 'none'; form-action 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline'");
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
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#151b23">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="mobile-web-app-capable" content="yes">
<link rel="manifest" href="/public/admin/manifest.json">
<link rel="icon" href="/public/admin/assets/icons/app-icon-192.png" type="image/png">
<link rel="apple-touch-icon" href="/public/admin/assets/icons/apple-touch-icon.png" sizes="180x180">
<title><?= htmlspecialchars($title) ?> — Hercule License Admin</title>
<link rel="stylesheet" href="/public/admin/assets/css/style.css?v=server-pagination">
</head>
<body class="role-<?= htmlspecialchars($role) ?>">
<div class="shell">
    <header class="topbar">
        <div class="brand"><span class="brand-mark" aria-hidden="true">H</span><span class="brand-copy"><strong>Hercule</strong><small>License Admin</small></span></div>
        <?php if ($username): ?>
        <nav class="nav" id="admin-navigation" aria-label="Primary navigation">
            <a href="/public/admin/index.php" class="<?= $currentPage === 'index.php' ? 'active' : '' ?>" <?= $currentPage === 'index.php' ? 'aria-current="page"' : '' ?>>
                <svg class="nav-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M3 11.5 12 4l9 7.5v8a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1z"/></svg><span>Dashboard</span>
            </a>
            <a href="/public/admin/customers.php" class="<?= $currentPage === 'customers.php' ? 'active' : '' ?>" <?= $currentPage === 'customers.php' ? 'aria-current="page"' : '' ?>>
                <svg class="nav-icon" viewBox="0 0 24 24" aria-hidden="true"><circle cx="9" cy="8" r="3"/><path d="M3.5 20c.2-4 2-6 5.5-6s5.3 2 5.5 6M16 5.5a3 3 0 0 1 0 5.8M16 14c3 0 4.5 2 4.5 5"/></svg><span>Customers</span>
            </a>
            <a href="/public/admin/licenses.php" class="<?= in_array($currentPage, ['licenses.php', 'license_detail.php'], true) ? 'active' : '' ?>" <?= in_array($currentPage, ['licenses.php', 'license_detail.php'], true) ? 'aria-current="page"' : '' ?>>
                <svg class="nav-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h10a4 4 0 0 1 0 8H9l-3 3v-3H4a4 4 0 0 1 0-8z"/><circle cx="14" cy="11" r="1"/></svg><span>Licenses</span>
            </a>
            <a href="/public/admin/recovery_requests.php" class="<?= $currentPage === 'recovery_requests.php' ? 'active' : '' ?>" <?= $currentPage === 'recovery_requests.php' ? 'aria-current="page"' : '' ?>>
                <svg class="nav-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M4 12a8 8 0 1 0 2.3-5.7L4 8.5M4 4v4.5h4.5"/><path d="M12 8v4l3 2"/></svg><span>Recovery</span>
            </a>
        </nav>
        <div class="notification-area">
            <a class="notification-button" id="recovery-notification-button" href="/public/admin/recovery_requests.php" aria-label="Recovery requests">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4"/></svg>
                <span class="notification-badge" id="recovery-notification-count" hidden>0</span>
            </a>
            <a class="notification-button expiry-notification-button" id="license-expiry-button" href="/public/admin/index.php#expiring-soon" aria-label="License expiry alerts">
                <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="8"/><path d="M12 8v5l3 2"/></svg>
                <span class="notification-badge" id="license-expiry-count" hidden>0</span>
            </a>
        </div>
        <div class="account-area">
            <button class="nav-toggle account-button" type="button" aria-label="Open account menu" aria-controls="account-menu" aria-expanded="false">
                <span class="account-avatar" aria-hidden="true"><?= strtoupper(htmlspecialchars(substr($username, 0, 1))) ?></span>
                <span class="account-button-text">Account</span>
                <svg class="account-chevron" viewBox="0 0 24 24" aria-hidden="true"><path d="m7 9 5 5 5-5"/></svg>
            </button>
            <div class="account-menu" id="account-menu">
                <div class="account-menu-header">
                    <span class="account-avatar account-avatar-large" aria-hidden="true"><?= strtoupper(htmlspecialchars(substr($username, 0, 1))) ?></span>
                    <span class="nav-user"><small>Signed in as</small><strong><?= htmlspecialchars($username) ?></strong><small><?= htmlspecialchars($roleLabel) ?></small></span>
                </div>
                <?php if (Auth::can('admins.manage')): ?>
                <a href="/public/admin/admin_users.php" class="<?= $currentPage === 'admin_users.php' ? 'active' : '' ?>">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="9" cy="8" r="3"/><path d="M3.5 20c.2-4 2-6 5.5-6s5.3 2 5.5 6M16 5.5a3 3 0 0 1 0 5.8M16 14c3 0 4.5 2 4.5 5"/></svg>
                    <span>Administrators</span>
                </a>
                <?php endif; ?>
                <a href="/public/admin/mfa_settings.php" class="<?= $currentPage === 'mfa_settings.php' ? 'active' : '' ?>">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3 4 6v5c0 5 3.4 8.5 8 10 4.6-1.5 8-5 8-10V6z"/><path d="m8.5 12 2.2 2.2 4.8-5"/></svg>
                    <span>Two-factor authentication</span>
                </a>
                <a href="/public/admin/change_password.php" class="<?= $currentPage === 'change_password.php' ? 'active' : '' ?>">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="5" y="10" width="14" height="10" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3M12 14v2"/></svg>
                    <span>Change password</span>
                </a>
                <button type="button" class="account-install-action" data-install-app hidden>
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3v12M7 10l5 5 5-5M5 20h14"/></svg>
                    <span>Install mobile app</span>
                </button>
                <a href="/public/admin/logout.php" class="nav-logout">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M10 5H5v14h5M14 8l4 4-4 4M8 12h10"/></svg>
                    <span>Log out</span>
                </a>
            </div>
        </div>
        <?php endif; ?>
    </header>
    <main class="content">
    <?php
}

function render_footer(): void
{
    ?>
    </main>
</div>
<div class="notification-toast" id="recovery-notification-toast" role="status" aria-live="polite" hidden>
    <span class="notification-toast-icon" aria-hidden="true">
        <svg viewBox="0 0 24 24"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4"/></svg>
    </span>
    <a id="recovery-notification-link" href="/public/admin/recovery_requests.php">
        <strong id="recovery-notification-title">New recovery request</strong>
        <span id="recovery-notification-message"></span>
    </a>
    <button type="button" id="recovery-notification-close" aria-label="Dismiss notification">×</button>
</div>
<div class="notification-toast expiry-notification-toast" id="license-expiry-toast" role="status" aria-live="polite" hidden>
    <span class="notification-toast-icon" aria-hidden="true">
        <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="8"/><path d="M12 8v5l3 2"/></svg>
    </span>
    <a id="license-expiry-link" href="/public/admin/index.php#expiring-soon">
        <strong id="license-expiry-title">License expiry alert</strong>
        <span id="license-expiry-message"></span>
    </a>
    <button type="button" id="license-expiry-close" aria-label="Dismiss notification">×</button>
</div>
<script>
(function () {
    var toggle = document.querySelector(".nav-toggle");
    var menu = document.getElementById("account-menu");

    function closeAccountMenu() {
        if (!toggle || !menu) {
            return;
        }

        menu.classList.remove("is-open");
        toggle.setAttribute("aria-expanded", "false");
    }

    if (toggle && menu) {
        toggle.addEventListener("click", function () {
            var open = menu.classList.toggle("is-open");
            toggle.setAttribute("aria-expanded", open ? "true" : "false");
        });

        document.addEventListener("click", function (event) {
            if (!event.target.closest(".account-area")) {
                closeAccountMenu();
            }
        });

        document.addEventListener("keydown", function (event) {
            if (event.key === "Escape" && menu.classList.contains("is-open")) {
                closeAccountMenu();
                toggle.focus();
            }
        });
    }

    document.querySelectorAll("details.card-menu").forEach(function (details) {
        details.addEventListener("toggle", function () {
            if (!details.open) {
                return;
            }

            document.querySelectorAll("details.card-menu[open]").forEach(function (other) {
                if (other !== details) {
                    other.removeAttribute("open");
                }
            });
        });
    });


    var notificationButton = document.getElementById("recovery-notification-button");
    var notificationCount = document.getElementById("recovery-notification-count");
    var notificationToast = document.getElementById("recovery-notification-toast");
    var notificationLink = document.getElementById("recovery-notification-link");
    var notificationTitle = document.getElementById("recovery-notification-title");
    var notificationMessage = document.getElementById("recovery-notification-message");
    var notificationClose = document.getElementById("recovery-notification-close");
    var notificationTimer = null;
    var pollTimer = null;
    var lastSeenId = 0;
    var audioContext = null;

    try {
        lastSeenId = Number(localStorage.getItem("herculeRecoveryLastSeenId") || 0);
    } catch (error) {
        lastSeenId = 0;
    }

    function saveLastSeen(id) {
        lastSeenId = Math.max(lastSeenId, Number(id) || 0);
        try {
            localStorage.setItem("herculeRecoveryLastSeenId", String(lastSeenId));
        } catch (error) {}
    }

    function playNotificationTone() {
        if (!audioContext || audioContext.state !== "running") return;
        var oscillator = audioContext.createOscillator();
        var gain = audioContext.createGain();
        oscillator.type = "sine";
        oscillator.frequency.setValueAtTime(740, audioContext.currentTime);
        oscillator.frequency.setValueAtTime(880, audioContext.currentTime + 0.1);
        gain.gain.setValueAtTime(0.0001, audioContext.currentTime);
        gain.gain.exponentialRampToValueAtTime(0.12, audioContext.currentTime + 0.02);
        gain.gain.exponentialRampToValueAtTime(0.0001, audioContext.currentTime + 0.24);
        oscillator.connect(gain);
        gain.connect(audioContext.destination);
        oscillator.start();
        oscillator.stop(audioContext.currentTime + 0.25);
    }

    document.addEventListener("pointerdown", function unlockAudio() {
        try {
            audioContext = audioContext || new (window.AudioContext || window.webkitAudioContext)();
            audioContext.resume();
        } catch (error) {}
        document.removeEventListener("pointerdown", unlockAudio);
    }, { once: true });

    function hideRecoveryToast() {
        if (!notificationToast) return;
        notificationToast.classList.remove("is-visible");
        window.setTimeout(function () {
            if (!notificationToast.classList.contains("is-visible")) notificationToast.hidden = true;
        }, 220);
    }

    function showRecoveryToast(request, newCount) {
        if (!notificationToast || !notificationLink) return;
        var count = Math.max(1, Number(newCount) || 1);
        notificationTitle.textContent = count > 1 ? count + " new recovery requests" : "New recovery request";
        notificationMessage.textContent = request && request.username
            ? "Request from " + request.username
            : "A customer submitted a password change request.";
        notificationLink.href = request && request.url
            ? request.url
            : "/public/admin/recovery_requests.php";
        notificationToast.hidden = false;
        requestAnimationFrame(function () {
            notificationToast.classList.add("is-visible");
        });
        clearTimeout(notificationTimer);
        notificationTimer = window.setTimeout(hideRecoveryToast, 9000);
        playNotificationTone();

        if ("Notification" in window && Notification.permission === "granted") {
            try {
                new Notification(notificationTitle.textContent, {
                    body: notificationMessage.textContent,
                    tag: "hercule-recovery-request"
                });
            } catch (error) {}
        }
    }

    function updateRecoveryBadge(count) {
        if (!notificationCount || !notificationButton) return;
        var value = Math.max(0, Number(count) || 0);
        notificationCount.textContent = value > 99 ? "99+" : String(value);
        notificationCount.hidden = value === 0;
        notificationButton.classList.toggle("has-notifications", value > 0);
        notificationButton.setAttribute("aria-label", value > 0
            ? value + " pending recovery requests"
            : "Recovery requests");
    }

    function scheduleRecoveryPoll(delay) {
        clearTimeout(pollTimer);
        pollTimer = window.setTimeout(pollRecoveryRequests, delay);
    }

    function pollRecoveryRequests() {
        fetch("/public/admin/recovery_notifications.php?after_id=" + encodeURIComponent(lastSeenId), {
            credentials: "same-origin",
            cache: "no-store",
            headers: { "Accept": "application/json" }
        })
            .then(function (response) {
                if (response.status === 401) {
                    window.location.href = "/public/admin/login.php";
                    return null;
                }
                if (!response.ok) throw new Error("Notification request failed");
                return response.json();
            })
            .then(function (data) {
                if (!data || !data.ok) return;
                updateRecoveryBadge(data.pending_count);

                if (Array.isArray(data.requests) && data.requests.length > 0) {
                    showRecoveryToast(data.requests[0], data.requests.length);
                    window.dispatchEvent(new CustomEvent("hercule:recovery-request", {
                        detail: { requests: data.requests, pendingCount: data.pending_count }
                    }));
                }

                saveLastSeen(data.latest_id);
            })
            .catch(function () {})
            .finally(function () {
                scheduleRecoveryPoll(document.hidden ? 30000 : 15000);
            });
    }

    if (notificationClose) notificationClose.addEventListener("click", hideRecoveryToast);
    if (notificationButton) {
        notificationButton.addEventListener("click", function (event) {
            saveLastSeen(lastSeenId);

            if ("Notification" in window && Notification.permission === "default") {
                event.preventDefault();
                var destination = notificationButton.href;
                Notification.requestPermission()
                    .catch(function () { return "denied"; })
                    .finally(function () {
                        window.location.href = destination;
                    });
            }
        });
    }
    document.addEventListener("visibilitychange", function () {
        if (!document.hidden) scheduleRecoveryPoll(250);
    });
    scheduleRecoveryPoll(400);
})();
</script>
<script>
(function () {
    var button = document.getElementById("license-expiry-button");
    var badge = document.getElementById("license-expiry-count");
    var toast = document.getElementById("license-expiry-toast");
    var link = document.getElementById("license-expiry-link");
    var title = document.getElementById("license-expiry-title");
    var message = document.getElementById("license-expiry-message");
    var close = document.getElementById("license-expiry-close");
    var timer = null;
    var pollTimer = null;
    var lastSignature = "";

    try { lastSignature = localStorage.getItem("herculeLicenseExpirySignature") || ""; } catch (error) {}

    function saveSignature(signature) {
        lastSignature = signature || "";
        try { localStorage.setItem("herculeLicenseExpirySignature", lastSignature); } catch (error) {}
    }

    function updateBadge(count, expired, expiring) {
        if (!button || !badge) return;
        var total = Math.max(0, Number(count) || 0);
        badge.textContent = total > 99 ? "99+" : String(total);
        badge.hidden = total === 0;
        button.classList.toggle("has-notifications", total > 0);
        button.classList.toggle("has-expired", Number(expired) > 0);
        button.href = Number(expired) > 0
            ? "/public/admin/licenses.php?status=expired"
            : "/public/admin/index.php#expiring-soon";
        button.setAttribute("aria-label", total
            ? expired + " expired and " + expiring + " expiring licenses"
            : "No license expiry alerts");
    }

    function hideToast() {
        if (!toast) return;
        toast.classList.remove("is-visible");
        window.setTimeout(function () {
            if (!toast.classList.contains("is-visible")) toast.hidden = true;
        }, 220);
    }

    function playTone() {
        try {
            var Context = window.AudioContext || window.webkitAudioContext;
            if (!Context) return;
            var context = new Context();
            var oscillator = context.createOscillator();
            var gain = context.createGain();
            oscillator.frequency.value = 620;
            gain.gain.value = 0.08;
            oscillator.connect(gain);
            gain.connect(context.destination);
            oscillator.start();
            oscillator.stop(context.currentTime + 0.18);
            oscillator.onended = function () { context.close(); };
        } catch (error) {}
    }

    function showToast(data) {
        if (!toast || !link || !data.alerts || !data.alerts.length) return;
        var alert = data.alerts[0];
        title.textContent = data.expired_count > 0
            ? data.expired_count + " expired license" + (data.expired_count === 1 ? "" : "s")
            : data.expiring_count + " license" + (data.expiring_count === 1 ? "" : "s") + " expiring soon";
        message.textContent = alert.type === "expired"
            ? alert.customer + " requires attention."
            : alert.customer + " expires in " + alert.days_remaining + " day" + (alert.days_remaining === 1 ? "" : "s") + ".";
        link.href = alert.url || "/public/admin/index.php#expiring-soon";
        toast.hidden = false;
        requestAnimationFrame(function () { toast.classList.add("is-visible"); });
        clearTimeout(timer);
        timer = window.setTimeout(hideToast, 9000);
        playTone();

        if ("Notification" in window && Notification.permission === "granted") {
            try {
                new Notification(title.textContent, {
                    body: message.textContent,
                    tag: "hercule-license-expiry"
                });
            } catch (error) {}
        }
    }

    function schedule(delay) {
        clearTimeout(pollTimer);
        pollTimer = window.setTimeout(poll, delay);
    }

    function poll() {
        fetch("/public/admin/license_expiry_notifications.php", {
            credentials: "same-origin",
            cache: "no-store",
            headers: { "Accept": "application/json" }
        })
        .then(function (response) {
            if (response.status === 401) {
                window.location.href = "/public/admin/login.php";
                return null;
            }
            if (!response.ok) throw new Error("Expiry notification request failed");
            return response.json();
        })
        .then(function (data) {
            if (!data || !data.ok) return;
            updateBadge(data.total_count, data.expired_count, data.expiring_count);
            if (data.total_count > 0 && data.signature && data.signature !== lastSignature) {
                showToast(data);
                window.dispatchEvent(new CustomEvent("hercule:license-expiry", { detail: data }));
            }
            saveSignature(data.signature);
        })
        .catch(function () {})
        .finally(function () { schedule(document.hidden ? 120000 : 60000); });
    }

    if (close) close.addEventListener("click", hideToast);
    if (button) {
        button.addEventListener("click", function (event) {
            if ("Notification" in window && Notification.permission === "default") {
                event.preventDefault();
                var destination = button.href;
                Notification.requestPermission().catch(function () { return "denied"; }).finally(function () {
                    window.location.href = destination;
                });
            }
        });
    }
    document.addEventListener("visibilitychange", function () {
        if (!document.hidden) schedule(250);
    });
    schedule(600);
})();
</script>
<script src="/public/admin/assets/js/pwa.js?v=3" defer></script>
</body>
</html>
    <?php
}

function flash_set(string $message, string $type = 'success'): void
{
    $_SESSION['flash'] = ['message' => $message, 'type' => $type];
}

function flash_render(): void
{
    if (!empty($_SESSION['flash'])) {
        $f = $_SESSION['flash'];
        $class = $f['type'] === 'error' ? 'flash-error' : 'flash-success';
        echo '<div class="flash ' . $class . '">' . htmlspecialchars($f['message']) . '</div>';
        unset($_SESSION['flash']);
    }
}
