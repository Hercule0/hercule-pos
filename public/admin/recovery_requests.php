<?php
require_once __DIR__ . '/includes/bootstrap.php';
Auth::require();

$pdo = Database::pdo();

// معالجة طلبات الموافقة أو الرفض
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::guard();
    $action = $_POST['action'] ?? '';
    $requestId = (int) ($_POST['request_id'] ?? 0);
    $adminNote = $_POST['admin_note'] ?? null;

    if ($action === 'approve') {
        // توليد رمز مصادقة آمن جداً (Token)
        $authToken = bin2hex(random_bytes(32));
        // صلاحية الرمز لمدة ساعتين فقط
        $expiresAt = date('Y-m-d H:i:s', strtotime('+2 hours'));

        $stmt = $pdo->prepare("UPDATE password_recovery_requests 
                               SET status = 'approved', auth_token = ?, expires_at = ?, admin_note = ?, resolved_at = CURRENT_TIMESTAMP 
                               WHERE id = ? AND status = 'pending'");
        $stmt->execute([$authToken, $expiresAt, $adminNote, $requestId]);
        flash_set('تمت الموافقة على الطلب وتوليد رمز الاسترجاع.', 'success');
        
    } elseif ($action === 'reject') {
        $stmt = $pdo->prepare("UPDATE password_recovery_requests 
                               SET status = 'rejected', admin_note = ?, resolved_at = CURRENT_TIMESTAMP 
                               WHERE id = ? AND status = 'pending'");
        $stmt->execute([$adminNote, $requestId]);
        flash_set('تم رفض الطلب.', 'success');
    }

    header('Location: /public/admin/recovery_requests.php');
    exit;
}

// جلب الطلبات المعلقة
$stmt = $pdo->prepare("SELECT * FROM password_recovery_requests WHERE status = 'pending' ORDER BY created_at ASC");
$stmt->execute();
$pendingRequests = $stmt->fetchAll();

render_header('طلبات استرجاع كلمة المرور');
flash_render();
?>

<h1>طلبات استرجاع كلمة المرور المعلقة</h1>
<p class="muted">هذه الطلبات مقدمة من أجهزة الزبائن التي فقدت كلمة المرور. الموافقة ستسمح للزبون بتعيين كلمة مرور جديدة مرة واحدة فقط.</p>

<div class="panel panel-wide">
    <table class="data-table">
        <thead>
            <tr>
                <th>رقم الطلب</th>
                <th>مفتاح الترخيص</th>
                <th>معرف الجهاز (HWID)</th>
                <th>وقت الطلب</th>
                <th>الإجراء</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($pendingRequests as $req): ?>
            <tr>
                <td>#<?= $req['id'] ?></td>
                <td class="mono"><?= htmlspecialchars($req['license_key']) ?></td>
                <td class="mono" style="font-size: 0.85em;" title="<?= htmlspecialchars($req['hwid']) ?>">
                    <?= htmlspecialchars(substr($req['hwid'], 0, 15)) ?>...
                </td>
                <td><?= htmlspecialchars($req['created_at']) ?></td>
                <td>
                    <form method="post" style="display:inline-block; margin-right: 5px;">
                        <?= Csrf::field() ?>
                        <input type="hidden" name="request_id" value="<?= $req['id'] ?>">
                        <input type="hidden" name="admin_note" value="Approved by Admin">
                        <button type="submit" name="action" value="approve" class="primary-btn" style="padding: 4px 8px; font-size: 12px;">موافقة</button>
                    </form>
                    <form method="post" style="display:inline-block;" onsubmit="return confirm('هل أنت متأكد من رفض هذا الطلب؟');">
                        <?= Csrf::field() ?>
                        <input type="hidden" name="request_id" value="<?= $req['id'] ?>">
                        <input type="hidden" name="admin_note" value="Rejected by Admin">
                        <button type="submit" name="action" value="reject" class="danger-btn" style="padding: 4px 8px; font-size: 12px;">رفض</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (empty($pendingRequests)): ?>
            <tr><td colspan="5" class="muted" style="text-align:center;">لا توجد طلبات استرجاع معلقة حالياً.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<?php render_footer(); ?>
