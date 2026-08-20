<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/../../includes/AuditLog.php';

if (Auth::isLoggedIn()) {
    header('Location: /public/admin/index.php');
    exit;
}

$error = null;
$mfaPending = isset($_SESSION['mfa_pending']) && is_array($_SESSION['mfa_pending']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::guard();
    if ($mfaPending) {
        $pendingUsername = $_SESSION['mfa_pending']['username'] ?? 'unknown';
        $result = Auth::verifySecondFactor($_POST['mfa_code'] ?? '');
        if ($result['ok']) {
            AuditLog::adminAction('login_success', null, 'MFA login completed for ' . mb_substr((string)$pendingUsername, 0, 64));
            header('Location: /public/admin/index.php');
            exit;
        }
        AuditLog::write('mfa_failed', null, null, 'Invalid MFA code for ' . mb_substr((string)$pendingUsername, 0, 64), $_SERVER['REMOTE_ADDR'] ?? null);
        $error = $result['error'];
        $mfaPending = isset($_SESSION['mfa_pending']);
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $remember = !empty($_POST['remember']);
        $result = Auth::attemptLogin($username, $password, $_SERVER['REMOTE_ADDR'] ?? 'unknown', $remember);
        if ($result['ok']) {
            AuditLog::adminAction('login_success', null, 'Administrator signed in');
            header('Location: /public/admin/index.php');
            exit;
        }
        if (!empty($result['requires_mfa'])) {
            header('Location: /public/admin/login.php');
            exit;
        }
        AuditLog::write('login_failed', null, null, 'Failed login for ' . mb_substr($username, 0, 64), $_SERVER['REMOTE_ADDR'] ?? null);
        $error = $result['error'];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#0d1117">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="mobile-web-app-capable" content="yes">
<link rel="manifest" href="/public/admin/manifest.json">
<link rel="icon" href="/public/admin/assets/icons/app-icon-192.png" type="image/png">
<link rel="apple-touch-icon" href="/public/admin/assets/icons/apple-touch-icon.png" sizes="180x180">
<title><?= $mfaPending ? 'Verify identity' : 'Sign in' ?> — Hercule License Admin</title>
<link rel="stylesheet" href="/public/admin/assets/css/style.css?v=20260820-login-polish">
<link rel="stylesheet" href="/public/admin/assets/css/mobile-no-overflow.css?v=ui-mobile-4">
</head>
<body class="auth-body">
<main class="auth-layout">
    <section class="auth-brand-panel">
        <div class="auth-brand">
            <span class="auth-logo">H</span>
            <span><strong>Hercule</strong><small>License Admin</small></span>
        </div>
        <div class="auth-intro">
            <span class="auth-shield">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3 4 6v5c0 5 3.4 8.5 8 10 4.6-1.5 8-5 8-10V6z"/><path d="m8.5 12 2.2 2.2 4.8-5"/></svg>
            </span>
            <p class="eyebrow">Secure workspace</p>
            <h1><?= $mfaPending ? 'One more security check.' : 'Control your license infrastructure from one secure workspace.' ?></h1>
            <p><?= $mfaPending ? 'Use your authenticator or a one-time recovery code.' : 'Issue subscriptions, monitor devices, review activity, and manage access with a focused admin experience.' ?></p>
        </div>
        <small class="auth-brand-footer">Hercule POS · Protected administrative access</small>
    </section>

    <section class="auth-form-panel">
        <form class="auth-card" method="post" id="login-form" novalidate>
            <div class="auth-mobile-brand">
                <span class="auth-logo">H</span>
                <span><strong>Hercule</strong><small>License Admin</small></span>
            </div>

            <div class="auth-heading">
                <p class="eyebrow"><?= $mfaPending ? 'Two-factor authentication' : 'Welcome back' ?></p>
                <h2><?= $mfaPending ? 'Verify your identity' : 'Sign in' ?></h2>
                <p><?= $mfaPending ? 'Enter the current six-digit code or one unused recovery code.' : 'Sign in to access the Hercule admin control panel.' ?></p>
            </div>

            <?php if ($error): ?>
            <div class="auth-error" role="alert">
                <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="8"/><path d="M12 8v5M12 16h.01"/></svg>
                <span><?= htmlspecialchars($error) ?></span>
            </div>
            <?php endif; ?>

            <?= Csrf::field() ?>

            <?php if ($mfaPending): ?>
            <label class="auth-field" for="mfa-code">
                <span>Authenticator or recovery code</span>
                <div class="auth-input">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3 4 6v5c0 5 3.4 8.5 8 10 4.6-1.5 8-5 8-10V6z"/></svg>
                    <input id="mfa-code" type="text" name="mfa_code" required autofocus autocomplete="one-time-code" inputmode="text" maxlength="11" placeholder="000000 or recovery code">
                </div>
            </label>
            <?php else: ?>
            <label class="auth-field" for="login-username">
                <span>Username</span>
                <div class="auth-input">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="8" r="3"/><path d="M5 20c.3-4 2.5-6 7-6s6.7 2 7 6"/></svg>
                    <input id="login-username" type="text" name="username" value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" autofocus required autocomplete="username" placeholder="Enter your username" autocapitalize="none" spellcheck="false">
                </div>
            </label>

            <label class="auth-field" for="login-password">
                <span>Password</span>
                <div class="auth-input">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="5" y="10" width="14" height="10" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/></svg>
                    <input id="login-password" type="password" name="password" required autocomplete="current-password" placeholder="Enter your password">
                    <button type="button" class="password-toggle" data-toggle-password="login-password" aria-label="Show password">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 12s3.5-5 9-5 9 5 9 5-3.5 5-9 5-9-5-9-5z"/><circle cx="12" cy="12" r="2"/></svg>
                    </button>
                </div>
            </label>

            <div class="auth-field-checkbox">
                <label><input type="checkbox" name="remember" value="1"><span>Remember me on this device</span></label>
            </div>
            <?php endif; ?>

            <button type="submit" class="auth-submit" id="login-submit">
                <span data-submit-label><?= $mfaPending ? 'Verify and continue' : 'Sign in to Admin Panel' ?></span>
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m9 6 6 6-6 6"/></svg>
            </button>

            <button type="button" class="auth-install-action" data-install-app hidden>Install App</button>

            <p class="auth-security-note">
                <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="5" y="10" width="14" height="10" rx="2"/></svg>
                <?= $mfaPending ? 'Codes expire every 30 seconds. Five failed attempts cancel sign-in.' : 'Your session is encrypted, protected, and rate-limited.' ?>
            </p>
        </form>
    </section>
</main>
<script>
(function () {
    document.querySelectorAll('[data-toggle-password]').forEach(function (button) {
        button.addEventListener('click', function () {
            var input = document.getElementById(button.dataset.togglePassword);
            if (!input) return;
            var show = input.type === 'password';
            input.type = show ? 'text' : 'password';
            button.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
            button.classList.toggle('is-active', show);
            input.focus({ preventScroll: true });
        });
    });

    var form = document.getElementById('login-form');
    var submit = document.getElementById('login-submit');
    if (form && submit) {
        form.addEventListener('submit', function () {
            if (!form.checkValidity()) return;
            submit.disabled = true;
            submit.classList.add('is-loading');
            var label = submit.querySelector('[data-submit-label]');
            if (label) label.textContent = <?= json_encode($mfaPending ? 'Verifying…' : 'Signing in…') ?>;
        });
    }
})();
</script>
<script src="/public/admin/assets/js/login-pwa.js?v=1" defer></script>
</body>
</html>
