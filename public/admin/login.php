<?php
require_once __DIR__ . '/includes/bootstrap.php';

if (Auth::isLoggedIn()) {
    header('Location: /public/admin/index.php');
    exit;
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::guard();
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $result = Auth::attemptLogin($username, $password, $_SERVER['REMOTE_ADDR'] ?? 'unknown');

    if ($result['ok']) {
        header('Location: /public/admin/index.php');
        exit;
    }
    $error = $result['error'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#0d1117">
<meta name="apple-mobile-web-app-capable" content="yes">
<title>Log in — Hercule License Admin</title>
<link rel="stylesheet" href="/public/admin/assets/css/style.css?v=auth-v2">
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
                <h1>Manage every license with confidence.</h1>
                <p>Issue subscriptions, monitor devices, and review security activity from one protected dashboard.</p>
            </div>
            <small class="auth-brand-footer">Hercule POS · Administrative access</small>
        </section>

        <section class="auth-form-panel">
            <form class="auth-card" method="post">
                <div class="auth-mobile-brand">
                    <span class="auth-logo">H</span>
                    <span><strong>Hercule</strong><small>License Admin</small></span>
                </div>
                <div class="auth-heading">
                    <p class="eyebrow">Welcome back</p>
                    <h2>Sign in</h2>
                    <p>Enter your administrator credentials to continue.</p>
                </div>

                <?php if ($error): ?>
                    <div class="auth-error">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="8"/><path d="M12 8v5M12 16h.01"/></svg>
                        <span><?= htmlspecialchars($error) ?></span>
                    </div>
                <?php endif; ?>

                <?= Csrf::field() ?>
                <label class="auth-field">
                    <span>Username</span>
                    <div class="auth-input">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="8" r="3"/><path d="M5 20c.3-4 2.5-6 7-6s6.7 2 7 6"/></svg>
                        <input type="text" name="username" value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" autofocus required autocomplete="username" placeholder="Administrator username" autocapitalize="none">
                    </div>
                </label>
                <label class="auth-field">
                    <span>Password</span>
                    <div class="auth-input">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="5" y="10" width="14" height="10" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/></svg>
                        <input id="login-password" type="password" name="password" required autocomplete="current-password" placeholder="Your password">
                        <button type="button" class="password-toggle" data-toggle-password="login-password" aria-label="Show password">
                            <svg class="eye-open" viewBox="0 0 24 24" aria-hidden="true"><path d="M3 12s3.5-5 9-5 9 5 9 5-3.5 5-9 5-9-5-9-5z"/><circle cx="12" cy="12" r="2"/></svg>
                        </button>
                    </div>
                </label>

                <button type="submit" class="auth-submit">
                    <span>Sign in securely</span>
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m9 6 6 6-6 6"/></svg>
                </button>
                <p class="auth-security-note">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="5" y="10" width="14" height="10" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/></svg>
                    Your session is protected and rate-limited.
                </p>
            </form>
        </section>
    </main>

<script>
document.querySelectorAll('[data-toggle-password]').forEach(function (button) {
    button.addEventListener('click', function () {
        var input = document.getElementById(button.dataset.togglePassword);
        if (!input) return;
        var show = input.type === 'password';
        input.type = show ? 'text' : 'password';
        button.classList.toggle('showing', show);
        button.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
    });
});
</script>
</body>
</html>
