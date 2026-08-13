<?php
require_once __DIR__ . '/includes/bootstrap.php';
Auth::require();
require_once __DIR__ . '/includes/PasswordRecovery.php';

$pdo = Database::pdo();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::guard();
    $id = (int) ($_POST['request_id'] ?? 0);
    $action = $_POST['action'] ?? '';
    $note = trim($_POST['note'] ?? '') ?: null;
    $admin = Auth::currentUsername() ?? 'admin';

    if ($action === 'approve') {
        $result = PasswordRecovery::approve($id, $admin, $note);
        flash_set($result['ok'] ? "Request #{$id} approved — the client can now retrieve its authorization." : $result['error'], $result['ok'] ? 'success' : 'error');
    } elseif ($action === 'reject') {
        $result = PasswordRecovery::reject($id, $admin, $note);
        flash_set($result['ok'] ? "Request #{$id} rejected." : $result['error'], $result['ok'] ? 'success' : 'error');
    }
    header('Location: /public/admin/recovery_requests.php');
    exit;
}

$licenseInfoStmt = $pdo->prepare(
    'SELECT l.status AS license_status, l.plan, c.name AS customer_name
     FROM licenses l JOIN customers c ON c.id = l.customer_id
     WHERE l.license_key = ?'
);

$requests = PasswordRecovery::allList();

render_header('Password Recovery Requests');
flash_render();
?>

<h1>Password Recovery Requests</h1>
<p class="muted">
    Requests from Hercule POS users who are locked out of their local admin/cashier
    account. Use the license/customer and HWID info below to verify identity before
    approving — you will never see or set their actual password here. Approving
    issues a short-lived, single-use authorization that only the requesting client
    can retrieve and consume.
</p>

<div class="panel panel-wide">
    <table class="data-table">
        <thead>
            <tr>
                <th>ID</th><th>Username</th><th>License / Customer</th>
                <th>Status</th><th>Requested</th><th>Reviewed</th><th>Actions / Note</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($requests as $r): ?>
            <?php
                $licenseInfoStmt->execute([$r['license_key']]);
                $li = $licenseInfoStmt->fetch();
            ?>
            <tr>
                <td>#<?= (int) $r['id'] ?></td>
                <td><?= htmlspecialchars($r['requested_username']) ?></td>
                <td>
                    <?php if ($li): ?>
                        <?= htmlspecialchars($li['customer_name']) ?>
                        <span class="muted">(<?= htmlspecialchars($li['plan']) ?>, <?= htmlspecialchars($li['license_status']) ?>)</span><br>
                    <?php else: ?>
                        <em class="muted">license not found</em><br>
                    <?php endif; ?>
                    <span class="mono"><?= htmlspecialchars($r['license_key']) ?></span>
                    <div class="muted" style="font-size:11px;">HWID: <span class="mono"><?= htmlspecialchars($r['hwid']) ?></span></div>
                </td>
                <td><span class="badge badge-<?= htmlspecialchars($r['status']) ?>"><?= htmlspecialchars($r['status']) ?></span></td>
                <td><?= htmlspecialchars($r['created_at']) ?></td>
                <td>
                    <?= htmlspecialchars($r['reviewed_at'] ?? '—') ?>
                    <?= $r['reviewed_by'] ? '<br><span class="muted">by ' . htmlspecialchars($r['reviewed_by']) . '</span>' : '' ?>
                </td>
                <td>
                    <?php if ($r['status'] === 'pending'): ?>
                        <form method="post">
                            <?= Csrf::field() ?>
                            <input type="hidden" name="request_id" value="<?= $r['id'] ?>">
                            <input type="text" name="note" placeholder="Internal note (optional)" style="width:170px; margin-bottom:6px; display:block;">
                            <button type="submit" name="action" value="approve" class="icon-btn" style="color: var(--success);">Approve</button>
                            <button type="submit" name="action" value="reject" class="icon-btn" style="color: var(--danger);">Reject</button>
                        </form>
                    <?php else: ?>
                        <span class="muted"><?= htmlspecialchars($r['admin_note'] ?? '—') ?></span>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (empty($requests)): ?>
            <tr><td colspan="7" class="muted">No recovery requests yet.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<?php render_footer(); ?>
