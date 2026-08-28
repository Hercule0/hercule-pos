<?php
require_once __DIR__ . '/includes/bootstrap.php';

Auth::require();
if (Auth::currentRole() !== 'owner') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Only the Owner can manage administrator permission overrides.';
    exit;
}

$pdo = Database::pdo();
$currentAdminId = (int) ($_SESSION['admin_id'] ?? 0);
$permissions = [
    'licenses.manage' => 'Manage licenses',
    'licenses.delete' => 'Delete licenses',
    'customers.manage' => 'Manage customers',
    'recovery.review' => 'Review password recovery',
    'support.manage' => 'Manage support & feedback',
    'exports.download' => 'Download exports',
    'releases.manage' => 'Manage desktop releases',
    'admins.manage' => 'Manage administrators',
];

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::guard();
    if (!Auth::confirmCurrentPassword($_POST['current_password'] ?? '')) {
        $error = 'Your current password is required to change permissions.';
    } else {
        $adminId = (int) ($_POST['admin_id'] ?? 0);
        $permission = (string) ($_POST['permission'] ?? '');
        $mode = (string) ($_POST['mode'] ?? 'inherit');

        try {
            if (!array_key_exists($permission, $permissions)) {
                throw new RuntimeException('Unknown permission.');
            }
            $stmt = $pdo->prepare('SELECT id, username, role FROM admin_users WHERE id = ?');
            $stmt->execute([$adminId]);
            $target = $stmt->fetch();
            if (!$target) {
                throw new RuntimeException('Administrator not found.');
            }
            if ($target['role'] === 'owner') {
                throw new RuntimeException('Owner permissions cannot be restricted with overrides.');
            }

            if ($mode === 'inherit') {
                $delete = $pdo->prepare('DELETE FROM admin_permission_overrides WHERE admin_id = ? AND permission = ?');
                $delete->execute([$adminId, $permission]);
            } elseif ($mode === 'allow' || $mode === 'deny') {
                $allowed = $mode === 'allow' ? 1 : 0;
                $upsert = $pdo->prepare(
                    'INSERT INTO admin_permission_overrides (admin_id, permission, allowed, updated_by)
                     VALUES (?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE allowed = VALUES(allowed), updated_by = VALUES(updated_by), updated_at = CURRENT_TIMESTAMP'
                );
                $upsert->execute([$adminId, $permission, $allowed, $currentAdminId]);
            } else {
                throw new RuntimeException('Invalid permission mode.');
            }

            $audit = $pdo->prepare(
                'INSERT INTO admin_audit_log (actor_id, target_id, action, details, ip_address) VALUES (?, ?, ?, ?, ?)'
            );
            $audit->execute([
                $currentAdminId,
                $adminId,
                'permission_override',
                $permission . '=' . $mode,
                $_SERVER['REMOTE_ADDR'] ?? null,
            ]);

            PermissionResolver::clearCache();
            flash_set('Permission updated.', 'success');
            header('Location: /public/admin/admin_permissions.php?admin_id=' . $adminId);
            exit;
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
}

$admins = $pdo->query(
    "SELECT id, username, role, is_active FROM admin_users ORDER BY role = 'owner' DESC, username"
)->fetchAll();
$selectedId = (int) ($_GET['admin_id'] ?? ($admins[0]['id'] ?? 0));
$selected = null;
foreach ($admins as $admin) {
    if ((int) $admin['id'] === $selectedId) {
        $selected = $admin;
        break;
    }
}

$overrides = [];
if ($selected) {
    try {
        $stmt = $pdo->prepare('SELECT permission, allowed FROM admin_permission_overrides WHERE admin_id = ?');
        $stmt->execute([$selectedId]);
        foreach ($stmt->fetchAll() as $row) {
            $overrides[$row['permission']] = (int) $row['allowed'];
        }
    } catch (Throwable $e) {
        $error = $error ?: 'Run db/migrate_admin_permissions.php before using permission overrides.';
    }
}

$roleDefaults = [
    'owner' => array_fill_keys(array_keys($permissions), true),
    'support' => [
        'licenses.manage' => true,
        'licenses.delete' => false,
        'customers.manage' => false,
        'recovery.review' => true,
        'support.manage' => true,
        'exports.download' => true,
        'releases.manage' => false,
        'admins.manage' => false,
    ],
    'read_only' => array_fill_keys(array_keys($permissions), false),
];

render_header('Admin Permissions');
flash_render();
?>
<section class="page-hero">
    <div>
        <p class="eyebrow">Access control</p>
        <h1>Granular permissions</h1>
        <p class="page-subtitle">Keep the role as the default, then allow or deny individual capabilities for a specific administrator. Only the Owner can change these overrides.</p>
    </div>
</section>

<?php if ($error): ?><div class="auth-error"><span><?= htmlspecialchars($error) ?></span></div><?php endif; ?>

<div class="admin-management-grid">
    <section class="admin-create-card">
        <h2>Administrator</h2>
        <form method="get">
            <label class="auth-field"><span>Select account</span><div class="auth-input"><select name="admin_id" data-submit-on-change>
                <?php foreach ($admins as $admin): ?>
                    <option value="<?= (int) $admin['id'] ?>" <?= (int) $admin['id'] === $selectedId ? 'selected' : '' ?>><?= htmlspecialchars($admin['username']) ?> — <?= htmlspecialchars($admin['role']) ?></option>
                <?php endforeach; ?>
            </select></div></label>
        </form>
        <p class="admin-permissions-note">Owner always keeps full access. Overrides apply to Support and Read-only accounts only.</p>
    </section>

    <section class="grid-cards-wrapper">
        <?php if ($selected): ?>
            <?php foreach ($permissions as $permission => $label):
                $default = (bool) ($roleDefaults[$selected['role']][$permission] ?? false);
                $hasOverride = array_key_exists($permission, $overrides);
                $effective = $hasOverride ? (bool) $overrides[$permission] : $default;
                $mode = $hasOverride ? ($effective ? 'allow' : 'deny') : 'inherit';
            ?>
                <article class="grid-card">
                    <div class="grid-card-header">
                        <div class="grid-card-title-group">
                            <h2 class="grid-card-title"><?= htmlspecialchars($label) ?></h2>
                            <div class="grid-card-subtitle"><?= htmlspecialchars($permission) ?></div>
                        </div>
                        <span class="badge <?= $effective ? 'badge-active' : 'badge-revoked' ?>"><?= $effective ? 'ALLOWED' : 'DENIED' ?></span>
                    </div>
                    <div class="grid-card-body">
                        <p>Role default: <strong><?= $default ? 'Allow' : 'Deny' ?></strong><?= $hasOverride ? ' · Custom override active' : ' · Inheriting role' ?></p>
                    </div>
                    <?php if ($selected['role'] !== 'owner'): ?>
                    <div class="grid-card-footer">
                        <form method="post" class="admin-permission-form">
                            <?= Csrf::field() ?>
                            <input type="hidden" name="admin_id" value="<?= (int) $selected['id'] ?>">
                            <input type="hidden" name="permission" value="<?= htmlspecialchars($permission, ENT_QUOTES) ?>">
                            <label class="auth-field"><span>Policy</span><div class="auth-input"><select name="mode">
                                <option value="inherit" <?= $mode === 'inherit' ? 'selected' : '' ?>>Inherit role default</option>
                                <option value="allow" <?= $mode === 'allow' ? 'selected' : '' ?>>Explicitly allow</option>
                                <option value="deny" <?= $mode === 'deny' ? 'selected' : '' ?>>Explicitly deny</option>
                            </select></div></label>
                            <label class="auth-field"><span>Your current password</span><div class="auth-input"><input type="password" name="current_password" required autocomplete="current-password"></div></label>
                            <button type="submit" class="grid-card-btn">Save permission</button>
                        </form>
                    </div>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        <?php endif; ?>
    </section>
</div>
<?php render_footer(); ?>