<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/../../includes/PasswordPolicy.php';
Auth::require();

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::guard();
    $current = $_POST['current_password'] ?? '';
    $new = $_POST['new_password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';

    if ($new !== $confirm) {
        $error = 'New password and confirmation do not match.';
    } else {
        $policy = PasswordPolicy::validate($new, $current);
        if (!$policy['ok']) {
            $error = $policy['error'] ?? 'New password does not meet the security policy.';
        } else {
            $adminId = (int) $_SESSION['admin_id'];
            $result = Auth::changePassword($adminId, $current, $new);
            if ($result['ok']) {
                $stmt = Database::pdo()->prepare('DELETE FROM user_sessions WHERE admin_id = ?');
                $stmt->execute([$adminId]);

                flash_set('Password changed successfully. Remembered sessions were signed out.');
                header('Location: /public/admin/index.php');
                exit;
            }
            $error = $result['error'];
        }
    }
}

render_header('Change Password');
?>

<div class="password-page">
    <a href="/public/admin/index.php" class="back-link">
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m15 18-6-6 6-6"/></svg>
        Dashboard
    </a>

    <section class="page-hero password-hero">
        <div>
            <p class="eyebrow">Account security</p>
            <h1>Change password</h1>
            <p class="page-subtitle">Choose a strong password you do not use anywhere else.</p>
        </div>
        <span class="security-badge">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3 4 6v5c0 5 3.4 8.5 8 10 4.6-1.5 8-5 8-10V6z"/><path d="m8.5 12 2.2 2.2 4.8-5"/></svg>
            Protected
        </span>
    </section>

    <div class="password-layout">
        <form method="post" class="password-card" id="password-form">
            <?= Csrf::field() ?>
            <?php if ($error): ?>
                <div class="auth-error">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="8"/><path d="M12 8v5M12 16h.01"/></svg>
                    <span><?= htmlspecialchars($error) ?></span>
                </div>
            <?php endif; ?>

            <label class="auth-field">
                <span>Current password</span>
                <div class="auth-input">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="5" y="10" width="14" height="10" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/></svg>
                    <input id="current-password" type="password" name="current_password" required autofocus autocomplete="current-password" placeholder="Enter current password">
                    <button type="button" class="password-toggle" data-toggle-password="current-password" aria-label="Show password"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 12s3.5-5 9-5 9 5 9 5-3.5 5-9 5-9-5-9-5z"/><circle cx="12" cy="12" r="2"/></svg></button>
                </div>
            </label>

            <div class="password-divider"><span>New credentials</span></div>

            <label class="auth-field">
                <span>New password</span>
                <div class="auth-input">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 10a5 5 0 1 1 9.5 2H21v3h-3v2h-3v2h-4l-2-2"/></svg>
                    <input id="new-password" type="password" name="new_password" required minlength="12" autocomplete="new-password" placeholder="At least 12 characters">
                    <button type="button" class="password-toggle" data-toggle-password="new-password" aria-label="Show password"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 12s3.5-5 9-5 9 5 9 5-3.5 5-9 5-9-5-9-5z"/><circle cx="12" cy="12" r="2"/></svg></button>
                </div>
            </label>

            <div class="password-strength" aria-live="polite">
                <div class="strength-track"><i id="strength-bar" data-score="0"></i></div>
                <span id="strength-label">Enter a new password</span>
            </div>

            <label class="auth-field">
                <span>Confirm new password</span>
                <div class="auth-input">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m5 12 4 4 10-10"/></svg>
                    <input id="confirm-password" type="password" name="confirm_password" required minlength="12" autocomplete="new-password" placeholder="Repeat new password">
                    <button type="button" class="password-toggle" data-toggle-password="confirm-password" aria-label="Show password"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 12s3.5-5 9-5 9 5 9 5-3.5 5-9 5-9-5-9-5z"/><circle cx="12" cy="12" r="2"/></svg></button>
                </div>
                <small class="match-message" id="match-message"></small>
            </label>

            <button type="submit" class="auth-submit">
                <span>Update password</span>
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m9 6 6 6-6 6"/></svg>
            </button>
        </form>

        <aside class="password-guidance">
            <span class="guidance-icon">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3 4 6v5c0 5 3.4 8.5 8 10 4.6-1.5 8-5 8-10V6z"/></svg>
            </span>
            <h2>Password checklist</h2>
            <ul>
                <li data-rule="length"><i>✓</i>At least 12 characters</li>
                <li data-rule="case"><i>✓</i>Uppercase and lowercase letters</li>
                <li data-rule="number"><i>✓</i>At least one number</li>
                <li data-rule="symbol"><i>✓</i>At least one symbol</li>
                <li data-rule="different"><i>✓</i>Different from current password</li>
            </ul>
            <p>Changing your password signs out remembered sessions but does not affect customer licenses or activated devices.</p>
        </aside>
    </div>
</div>

<script src="/public/admin/assets/js/change-password.js?v=20260826-hardening1" defer></script>
<?php render_footer(); ?>