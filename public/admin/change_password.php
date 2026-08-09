<?php
require_once __DIR__ . '/includes/bootstrap.php';
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
        $result = Auth::changePassword((int) $_SESSION['admin_id'], $current, $new);
        if ($result['ok']) {
            flash_set('Password changed successfully.');
            header('Location: /public/admin/index.php');
            exit;
        }
        $error = $result['error'];
    }
}

render_header('Change Password');
?>

<h1>Change Password</h1>

<div class="panel-grid">
    <div class="panel">
        <?php if ($error): ?>
            <div class="flash flash-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <form method="post" class="stacked-form">
            <?= Csrf::field() ?>
            <label>Current Password</label>
            <input type="password" name="current_password" required autofocus>
            <label>New Password</label>
            <input type="password" name="new_password" required minlength="10">
            <label>Confirm New Password</label>
            <input type="password" name="confirm_password" required minlength="10">
            <button type="submit" class="primary-btn">Change Password</button>
        </form>
        <p class="muted">Minimum 10 characters.</p>
    </div>
</div>

<?php render_footer(); ?>
