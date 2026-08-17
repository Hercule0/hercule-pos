<?php
require_once __DIR__ . '/includes/bootstrap.php';
Auth::require();

$error = null;
$setup = null;
$recoveryCodes = null;
$enabled = Auth::mfaEnabled();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::guard();
    $action = $_POST['action'] ?? '';

    if ($action === 'begin') {
        try {
            $setup = Auth::beginMfaSetup();
        } catch (Throwable $e) {
            $error = 'MFA cannot be configured until the server encryption key is set.';
        }
    } elseif ($action === 'enable') {
        $result = Auth::enableMfa($_POST['current_password'] ?? '', $_POST['code'] ?? '');
        if ($result['ok']) {
            $enabled = true;
            $recoveryCodes = $result['recovery_codes'];
        } else {
            $error = $result['error'];
            $setupState = $_SESSION['mfa_setup_secret'] ?? null;
            if (is_array($setupState)) {
                $setup = [
                    'secret' => $setupState['secret'],
                    'uri' => Totp::provisioningUri($setupState['secret'], Auth::currentUsername() ?? 'admin'),
                ];
            }
        }
    } elseif ($action === 'disable') {
        $result = Auth::disableMfa($_POST['current_password'] ?? '', $_POST['code'] ?? '');
        if ($result['ok']) {
            flash_set('Two-factor authentication disabled.', 'success');
            header('Location: /public/admin/mfa_settings.php');
            exit;
        }
        $error = $result['error'];
    }
}

render_header('Two-factor authentication');
?>
<div class="password-page">
    <a href="/public/admin/index.php" class="back-link"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m15 18-6-6 6-6"/></svg>Dashboard</a>
    <section class="page-hero password-hero">
        <div><p class="eyebrow">Account security</p><h1>Two-factor authentication</h1><p class="page-subtitle">Protect this administrator account with a rotating code from your phone.</p></div>
        <span class="security-badge"><?= $enabled ? 'Enabled' : 'Not enabled' ?></span>
    </section>

    <div class="password-layout">
        <section class="password-card">
            <?php if ($error): ?><div class="auth-error"><span><?= htmlspecialchars($error) ?></span></div><?php endif; ?>

            <?php if ($recoveryCodes): ?>
                <div class="auth-heading"><p class="eyebrow">Setup complete</p><h2>Save your recovery codes</h2><p>Each code works once. Store them offline; they will not be shown again.</p></div>
                <div class="mfa-recovery-codes" dir="ltr">
                    <?php foreach ($recoveryCodes as $recoveryCode): ?><code><?= htmlspecialchars($recoveryCode) ?></code><?php endforeach; ?>
                </div>
                <a href="/public/admin/index.php" class="auth-submit">I saved these codes</a>
            <?php elseif ($enabled): ?>
                <div class="auth-heading"><h2>MFA is active</h2><p>Your password must now be followed by an authenticator code on every new sign-in.</p></div>
                <form method="post">
                    <?= Csrf::field() ?><input type="hidden" name="action" value="disable">
                    <label class="auth-field"><span>Current password</span><div class="auth-input"><input type="password" name="current_password" required autocomplete="current-password"></div></label>
                    <label class="auth-field"><span>Current authenticator code</span><div class="auth-input"><input type="text" name="code" required inputmode="numeric" maxlength="6" autocomplete="one-time-code"></div></label>
                    <button type="submit" class="secondary-btn" onclick="return confirm('Disable two-factor authentication?');">Disable MFA</button>
                </form>
            <?php elseif ($setup): ?>
                <div class="auth-heading"><p class="eyebrow">Step 1</p><h2>Add Hercule to your authenticator</h2><p>In Google Authenticator, Microsoft Authenticator, or Authy choose “Enter setup key”.</p></div>
                <label class="auth-field"><span>Account</span><div class="auth-input"><input type="text" readonly value="Hercule License Admin (<?= htmlspecialchars(Auth::currentUsername() ?? 'admin') ?>)"></div></label>
                <label class="auth-field"><span>Setup key</span><div class="auth-input"><input type="text" readonly dir="ltr" value="<?= htmlspecialchars($setup['secret']) ?>"></div></label>
                <details><summary>Advanced: provisioning URI</summary><code class="mfa-uri"><?= htmlspecialchars($setup['uri']) ?></code></details>
                <form method="post">
                    <?= Csrf::field() ?><input type="hidden" name="action" value="enable">
                    <label class="auth-field"><span>Current password</span><div class="auth-input"><input type="password" name="current_password" required autocomplete="current-password"></div></label>
                    <label class="auth-field"><span>Six-digit code</span><div class="auth-input"><input type="text" name="code" required autofocus inputmode="numeric" maxlength="6" autocomplete="one-time-code" placeholder="000000"></div></label>
                    <button type="submit" class="auth-submit">Verify and enable MFA</button>
                </form>
            <?php else: ?>
                <div class="auth-heading"><h2>Add another layer of protection</h2><p>Even if your password is exposed, an attacker cannot enter without the changing code on your phone.</p></div>
                <form method="post"><?= Csrf::field() ?><input type="hidden" name="action" value="begin"><button type="submit" class="auth-submit">Start secure setup</button></form>
            <?php endif; ?>
        </section>
        <aside class="password-guidance">
            <span class="guidance-icon">✓</span><h2>Before enabling</h2>
            <ul><li><i>✓</i>Install an authenticator app</li><li><i>✓</i>Keep your phone clock automatic</li><li><i>✓</i>Save recovery codes offline</li></ul>
            <p>The secret is encrypted before it is stored in the database.</p>
        </aside>
    </div>
</div>
<?php render_footer(); ?>
