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

    <section class="grid-cards-wrapper">
        <?php foreach ($admins as $admin): ?>
            <article class="grid-card <?= $admin['is_active'] ? '' : 'is-disabled' ?>" style="<?= !$admin['is_active'] ? 'opacity: 0.6;' : '' ?>">
                <div class="grid-card-header" style="margin-bottom: 12px;">
                    <div class="grid-card-avatar" style="<?= $admin['role'] === 'owner' ? 'background: #3b82f6;' : '' ?>"><?= strtoupper(htmlspecialchars(substr($admin['username'], 0, 1))) ?></div>
                    <div class="grid-card-title-group">
                        <h2 class="grid-card-title"><?= htmlspecialchars($admin['username']) ?><?= (int) $admin['id'] === $currentAdminId ? ' (You)' : '' ?></h2>
                        <div class="grid-card-subtitle" style="display:flex; gap:6px; margin-top:6px;">
                            <span class="badge badge-<?= $admin['role'] === 'owner' ? 'active' : ($admin['role'] === 'support' ? 'pending' : 'expired') ?>"><?= htmlspecialchars(strtoupper(str_replace('_', ' ', $admin['role']))) ?></span>
                            <?= $admin['is_active'] ? '' : '<span class="badge badge-revoked">DISABLED</span>' ?>
                        </div>
                    </div>
                    <?php if ((int) $admin['id'] !== $currentAdminId): ?>
                    <div class="grid-card-actions">
                        <form method="post" onsubmit="return confirm('Permanently delete this administrator?');" style="display:inline;">
                            <?= Csrf::field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="admin_id" value="<?= (int) $admin['id'] ?>">
                            <!-- Assuming the user enters password before submitting -->
                            <button type="button" class="grid-card-action-btn" title="Delete Admin" onclick="const p = prompt('Enter your password to delete:'); if(p) { this.form.insertAdjacentHTML('beforeend', '<input type=\'hidden\' name=\'current_password\' value=\''+p+'\'>'); this.form.submit(); }">
                                <svg viewBox="0 0 24 24"><path d="M3 6h18M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                            </button>
                        </form>
                    </div>
                    <?php endif; ?>
                </div>
                
                <div class="grid-card-body" style="margin-bottom: 12px;">
                    <div class="grid-card-info-row">
                        <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                        <span dir="auto">Added <?= date('Y-m-d', strtotime($admin['created_at'])) ?></span>
                    </div>
                    <div class="grid-card-info-row">
                        <svg viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                        <span dir="auto"><?= $admin['totp_enabled'] ? 'MFA Active' : 'MFA Inactive' ?></span>
                    </div>
                    <?php if ($admin['must_change_password']): ?>
                    <div class="grid-card-note text-amber">Password change required on next login.</div>
                    <?php endif; ?>
                </div>

                <?php if ((int) $admin['id'] !== $currentAdminId): ?>
                <div class="grid-card-footer" style="flex-direction: column; gap: 8px; align-items: stretch;">
                    <form method="post" style="display:flex; gap:6px;">
                        <?= Csrf::field() ?><input type="hidden" name="action" value="change_role"><input type="hidden" name="admin_id" value="<?= (int) $admin['id'] ?>">
                        <select name="role" style="background:var(--panel-alt); color:var(--text); border:1px solid var(--border); border-radius:6px; padding:4px; font-size:11px; flex:1;"><?php foreach ($roles as $role): ?><option value="<?= $role ?>" <?= $role === $admin['role'] ? 'selected' : '' ?>><?= htmlspecialchars(ucwords(str_replace('_', ' ', $role))) ?></option><?php endforeach; ?></select>
                        <button type="button" class="grid-card-btn" style="padding:4px 8px; flex-shrink:0;" onclick="const p = prompt('Enter your password to update role:'); if(p) { this.form.insertAdjacentHTML('beforeend', '<input type=\'hidden\' name=\'current_password\' value=\''+p+'\'>'); this.form.submit(); }">Update</button>
                    </form>
                    <div style="display:flex; gap:6px;">
                        <form method="post" style="flex:1;">
                            <?= Csrf::field() ?><input type="hidden" name="action" value="toggle_active"><input type="hidden" name="admin_id" value="<?= (int) $admin['id'] ?>">
                            <button type="button" class="grid-card-btn" style="width:100%; justify-content:center; <?= $admin['is_active'] ? 'color:var(--warning); border-color:var(--warning);' : 'color:var(--success); border-color:var(--success);' ?>" onclick="const p = prompt('Enter your password to toggle access:'); if(p) { this.form.insertAdjacentHTML('beforeend', '<input type=\'hidden\' name=\'current_password\' value=\''+p+'\'>'); this.form.submit(); }"><?= $admin['is_active'] ? 'Disable Access' : 'Enable Access' ?></button>
                        </form>
                    </div>
                </div>
                <?php endif; ?>
            </article>
        <?php endforeach; ?>
    </section>
</div>
<?php render_footer(); ?>
