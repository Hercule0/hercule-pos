<?php
require_once __DIR__ . '/includes/bootstrap.php';
Auth::requirePermission('admins.manage');

$pdo = Database::pdo();
$error = null;
$roles = ['owner', 'support', 'read_only'];
$currentAdminId = (int) $_SESSION['admin_id'];

function admin_audit(PDO $pdo, int $actorId, ?int $targetId, string $action, ?string $details = null): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO admin_audit_log (actor_id, target_id, action, details, ip_address) VALUES (?, ?, ?, ?, ?)'
    );
    $stmt->execute([$actorId, $targetId, $action, $details, $_SERVER['REMOTE_ADDR'] ?? null]);
}

function active_owner_count(PDO $pdo): int
{
    return (int) $pdo->query("SELECT COUNT(*) FROM admin_users WHERE role = 'owner' AND is_active = 1")->fetchColumn();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::guard();
    $action = $_POST['action'] ?? '';
    $currentPassword = $_POST['current_password'] ?? '';

    if (!Auth::confirmCurrentPassword($currentPassword)) {
        $error = 'Your current password is required to manage administrator accounts.';
    } else {
        try {
            $pdo->beginTransaction();

            if ($action === 'create') {
                $username = trim($_POST['username'] ?? '');
                $temporaryPassword = $_POST['temporary_password'] ?? '';
                $role = $_POST['role'] ?? 'read_only';
                if (!preg_match('/^[A-Za-z0-9._-]{3,64}$/', $username)) {
                    throw new RuntimeException('Username must be 3–64 letters, numbers, dots, underscores, or dashes.');
                }
                if (strlen($temporaryPassword) < 12) {
                    throw new RuntimeException('Temporary password must be at least 12 characters.');
                }
                if (!in_array($role, $roles, true)) {
                    throw new RuntimeException('Invalid administrator role.');
                }
                $stmt = $pdo->prepare(
                    'INSERT INTO admin_users (username, password_hash, role, is_active, must_change_password)
                     VALUES (?, ?, ?, 1, 1)'
                );
                $stmt->execute([$username, password_hash($temporaryPassword, PASSWORD_DEFAULT), $role]);
                $targetId = (int) $pdo->lastInsertId();
                admin_audit($pdo, $currentAdminId, $targetId, 'admin_created', 'role=' . $role);
                flash_set("Administrator {$username} created. They must change the temporary password.", 'success');
            } else {
                $targetId = (int) ($_POST['admin_id'] ?? 0);
                $lock = $pdo->prepare('SELECT id, username, role, is_active FROM admin_users WHERE id = ? FOR UPDATE');
                if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
                    $lock = $pdo->prepare('SELECT id, username, role, is_active FROM admin_users WHERE id = ?');
                }
                $lock->execute([$targetId]);
                $target = $lock->fetch();
                if (!$target) {
                    throw new RuntimeException('Administrator not found.');
                }

                if ($action === 'change_role') {
                    $newRole = $_POST['role'] ?? '';
                    if (!in_array($newRole, $roles, true)) {
                        throw new RuntimeException('Invalid administrator role.');
                    }
                    if ($targetId === $currentAdminId) {
                        throw new RuntimeException('You cannot change your own role.');
                    }
                    if ($target['role'] === 'owner' && $newRole !== 'owner' && (int) $target['is_active'] === 1 && active_owner_count($pdo) <= 1) {
                        throw new RuntimeException('The final active Owner cannot be demoted.');
                    }
                    $pdo->prepare('UPDATE admin_users SET role = ? WHERE id = ?')->execute([$newRole, $targetId]);
                    admin_audit($pdo, $currentAdminId, $targetId, 'role_changed', $target['role'] . ' -> ' . $newRole);
                    flash_set('Administrator role updated.', 'success');
                } elseif ($action === 'toggle_active') {
                    if ($targetId === $currentAdminId) {
                        throw new RuntimeException('You cannot disable your own account.');
                    }
                    $next = (int) $target['is_active'] === 1 ? 0 : 1;
                    if ($next === 0 && $target['role'] === 'owner' && active_owner_count($pdo) <= 1) {
                        throw new RuntimeException('The final active Owner cannot be disabled.');
                    }
                    $pdo->prepare('UPDATE admin_users SET is_active = ? WHERE id = ?')->execute([$next, $targetId]);
                    admin_audit($pdo, $currentAdminId, $targetId, $next ? 'admin_enabled' : 'admin_disabled');
                    flash_set($next ? 'Administrator enabled.' : 'Administrator disabled and future sign-ins blocked.', 'success');
                } elseif ($action === 'reset_mfa') {
                    if (empty($_POST['confirm_reset_mfa'])) {
                        throw new RuntimeException('Confirm the MFA reset first.');
                    }
                    $pdo->prepare(
                        'UPDATE admin_users SET totp_enabled = 0, totp_secret = NULL, recovery_codes = NULL WHERE id = ?'
                    )->execute([$targetId]);
                    admin_audit($pdo, $currentAdminId, $targetId, 'mfa_reset');
                    flash_set('MFA reset. The administrator can configure it again after signing in.', 'success');
                } elseif ($action === 'delete') {
                    if ($targetId === $currentAdminId) {
                        throw new RuntimeException('You cannot delete your own account.');
                    }
                    if ($target['role'] === 'owner' && (int) $target['is_active'] === 1 && active_owner_count($pdo) <= 1) {
                        throw new RuntimeException('The final active Owner cannot be deleted.');
                    }
                    admin_audit($pdo, $currentAdminId, $targetId, 'admin_deleted', 'username=' . $target['username']);
                    $pdo->prepare('DELETE FROM admin_users WHERE id = ?')->execute([$targetId]);
                    flash_set('Administrator permanently deleted.', 'success');
                } else {
                    throw new RuntimeException('Unknown administrator action.');
                }
            }

            $pdo->commit();
            header('Location: /public/admin/admin_users.php');
            exit;
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error = $e->getCode() === '23000' ? 'That username already exists.' : 'The administrator change could not be saved.';
            error_log('Admin management failed: ' . $e->getMessage());
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error = $e->getMessage();
        }
    }
}

$admins = $pdo->query(
    'SELECT id, username, role, is_active, must_change_password, totp_enabled, created_at
     FROM admin_users ORDER BY is_active DESC, role, username'
)->fetchAll();

render_header('Administrators');
?>
<section class="page-hero">
    <div><p class="eyebrow">Access control</p><h1>Administrators</h1><p class="page-subtitle">Create accounts and control access without sharing the Owner password.</p></div>
</section>

<?php if ($error): ?><div class="auth-error"><span><?= htmlspecialchars($error) ?></span></div><?php endif; ?>

<div class="admin-management-grid">
    <section class="admin-create-card">
        <h2>Add administrator</h2>
        <form method="post">
            <?= Csrf::field() ?><input type="hidden" name="action" value="create">
            <label class="auth-field"><span>Username</span><div class="auth-input"><input name="username" required minlength="3" maxlength="64" autocomplete="off"></div></label>
            <label class="auth-field"><span>Temporary password</span><div class="auth-input"><input type="password" name="temporary_password" required minlength="12" autocomplete="new-password"></div></label>
            <label class="auth-field"><span>Role</span><div class="auth-input"><select name="role"><?php foreach ($roles as $role): ?><option value="<?= $role ?>"><?= htmlspecialchars(ucwords(str_replace('_', ' ', $role))) ?></option><?php endforeach; ?></select></div></label>
            <label class="auth-field"><span>Your current password</span><div class="auth-input"><input type="password" name="current_password" required autocomplete="current-password"></div></label>
            <button class="auth-submit" type="submit">Create administrator</button>
        </form>
    </section>

    <section class="admin-list">
        <?php foreach ($admins as $admin): ?>
            <article class="admin-account-card <?= $admin['is_active'] ? '' : 'is-disabled' ?>">
                <div class="admin-account-head">
                    <span class="account-avatar"><?= strtoupper(htmlspecialchars(substr($admin['username'], 0, 1))) ?></span>
                    <div><h2><?= htmlspecialchars($admin['username']) ?><?= (int) $admin['id'] === $currentAdminId ? ' (You)' : '' ?></h2><p><?= htmlspecialchars(ucwords(str_replace('_', ' ', $admin['role']))) ?> · <?= $admin['is_active'] ? 'Active' : 'Disabled' ?></p></div>
                    <span class="status-pill <?= $admin['is_active'] ? 'active' : 'revoked' ?>"><?= $admin['is_active'] ? 'Active' : 'Disabled' ?></span>
                </div>
                <div class="admin-security-flags">
                    <span><?= $admin['totp_enabled'] ? '✓ MFA enabled' : '○ MFA not enabled' ?></span>
                    <?php if ($admin['must_change_password']): ?><span>! Password change required</span><?php endif; ?>
                </div>
                <?php if ((int) $admin['id'] !== $currentAdminId): ?>
                <div class="admin-account-actions">
                    <form method="post">
                        <?= Csrf::field() ?><input type="hidden" name="action" value="change_role"><input type="hidden" name="admin_id" value="<?= (int) $admin['id'] ?>">
                        <select name="role"><?php foreach ($roles as $role): ?><option value="<?= $role ?>" <?= $role === $admin['role'] ? 'selected' : '' ?>><?= htmlspecialchars(ucwords(str_replace('_', ' ', $role))) ?></option><?php endforeach; ?></select>
                        <input type="password" name="current_password" required placeholder="Your password" autocomplete="current-password">
                        <button type="submit">Update role</button>
                    </form>
                    <form method="post">
                        <?= Csrf::field() ?><input type="hidden" name="action" value="toggle_active"><input type="hidden" name="admin_id" value="<?= (int) $admin['id'] ?>">
                        <input type="password" name="current_password" required placeholder="Your password" autocomplete="current-password">
                        <button type="submit"><?= $admin['is_active'] ? 'Disable' : 'Enable' ?></button>
                    </form>
                    <?php if ($admin['totp_enabled']): ?>
                    <form method="post" onsubmit="return confirm('Reset MFA for this administrator?');">
                        <?= Csrf::field() ?><input type="hidden" name="action" value="reset_mfa"><input type="hidden" name="admin_id" value="<?= (int) $admin['id'] ?>"><input type="hidden" name="confirm_reset_mfa" value="1">
                        <input type="password" name="current_password" required placeholder="Your password" autocomplete="current-password">
                        <button type="submit">Reset MFA</button>
                    </form>
                    <?php endif; ?>
                    <form method="post" onsubmit="return confirm('Permanently delete this administrator?');">
                        <?= Csrf::field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="admin_id" value="<?= (int) $admin['id'] ?>">
                        <input type="password" name="current_password" required placeholder="Your password" autocomplete="current-password">
                        <button type="submit" class="danger">Delete</button>
                    </form>
                </div>
                <?php endif; ?>
            </article>
        <?php endforeach; ?>
    </section>
</div>
<?php render_footer(); ?>
