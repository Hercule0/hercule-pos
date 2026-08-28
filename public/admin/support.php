<?php
require_once __DIR__ . '/includes/bootstrap.php';
Auth::require();
require_once __DIR__ . '/../../includes/SupportTicket.php';
require_once __DIR__ . '/../../includes/SupportAccess.php';

function support_type_label(string $type): string
{
    return match ($type) {
        'problem' => 'مشكلة',
        'suggestion' => 'اقتراح',
        'feature_request' => 'طلب ميزة',
        default => $type,
    };
}

function support_status_label(string $status): string
{
    return match ($status) {
        'new' => 'جديد',
        'reviewed' => 'تمت المراجعة',
        'in_progress' => 'قيد المعالجة',
        'resolved' => 'تم الحل',
        'closed' => 'مغلق',
        'under_review' => 'قيد الدراسة',
        'accepted' => 'مقبول',
        'planned' => 'مخطط للتنفيذ',
        'implemented' => 'تم التنفيذ',
        'rejected' => 'مرفوض',
        'duplicate' => 'مكرر',
        default => $status,
    };
}

function support_category_label(string $category): string
{
    return match ($category) {
        'pos' => 'نقطة البيع',
        'inventory' => 'المخزون',
        'invoices' => 'الفواتير',
        'printing' => 'الطباعة',
        'reports' => 'التقارير',
        'suppliers' => 'الموردون',
        'customers' => 'الزبائن',
        'updates' => 'التحديثات',
        'account' => 'الحساب',
        'settings' => 'الإعدادات',
        'ai' => 'المساعد الذكي',
        'other' => 'أخرى',
        default => $category,
    };
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::guard();
    SupportAccess::requireManage();

    $ticketNumber = strtoupper(trim((string)($_POST['ticket_number'] ?? '')));
    $action = (string)($_POST['action'] ?? '');
    $admin = Auth::currentUsername() ?? 'admin';

    if ($action === 'reply') {
        $message = trim((string)($_POST['message'] ?? ''));
        $internal = ($_POST['internal'] ?? '') === '1';
        $result = SupportTicket::adminReply($ticketNumber, $admin, $message, $internal);
        flash_set(
            !empty($result['ok']) ? ($internal ? 'تم حفظ الملاحظة الداخلية.' : 'تم إرسال الرد للعميل.') : ($result['error'] ?? 'تعذر إرسال الرد.'),
            !empty($result['ok']) ? 'success' : 'error'
        );
    } elseif ($action === 'status') {
        $newStatus = trim((string)($_POST['status'] ?? ''));
        $note = trim((string)($_POST['note'] ?? '')) ?: null;
        $resolvedVersion = trim((string)($_POST['resolved_in_version'] ?? '')) ?: null;
        $internalNote = ($_POST['internal_note'] ?? '') === '1';
        $result = SupportTicket::adminChangeStatus(
            $ticketNumber,
            $newStatus,
            $admin,
            $note,
            $internalNote,
            $resolvedVersion
        );
        flash_set(
            !empty($result['ok']) ? 'تم تحديث حالة البلاغ.' : ($result['error'] ?? 'تعذر تحديث الحالة.'),
            !empty($result['ok']) ? 'success' : 'error'
        );
    }

    header('Location: /public/admin/support.php?ticket=' . rawurlencode($ticketNumber));
    exit;
}

$filters = [
    'status' => trim((string)($_GET['status'] ?? '')),
    'type' => trim((string)($_GET['type'] ?? '')),
    'category' => trim((string)($_GET['category'] ?? '')),
    'search' => trim((string)($_GET['search'] ?? '')),
];
$tickets = SupportTicket::adminList($filters, 250);
$counts = SupportTicket::dashboardCounts();
$selectedNumber = strtoupper(trim((string)($_GET['ticket'] ?? '')));
$selected = $selectedNumber !== '' ? SupportTicket::adminDetail($selectedNumber) : null;

render_header('مركز الملاحظات والدعم');
flash_render();
?>

<div class="recovery-page" dir="rtl">
    <section class="page-hero recovery-hero">
        <div>
            <p class="eyebrow">Hercule Support Center</p>
            <h1>مركز الملاحظات والدعم</h1>
            <p class="page-subtitle">راجع مشاكل المستخدمين والاقتراحات وطلبات الميزات، ورد عليها واربط الحل بإصدار محدد.</p>
        </div>
        <?php if (($counts['new'] ?? 0) > 0): ?>
            <span class="pending-summary"><i></i><?= (int)$counts['new'] ?> جديد</span>
        <?php endif; ?>
    </section>

    <section class="recovery-tools" aria-label="فلاتر مركز الدعم">
        <form method="get" class="app-search">
            <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m16.5 16.5 4 4"/></svg>
            <input name="search" type="search" value="<?= htmlspecialchars($filters['search']) ?>" placeholder="رقم البلاغ، العنوان، العميل أو الترخيص" autocomplete="off">
            <button type="submit" class="table-btn">بحث</button>
        </form>
        <form method="get" class="pill-filters">
            <?php if ($filters['search'] !== ''): ?><input type="hidden" name="search" value="<?= htmlspecialchars($filters['search']) ?>"><?php endif; ?>
            <select name="type" aria-label="نوع البلاغ">
                <option value="">كل الأنواع</option>
                <?php foreach (SupportTicket::allowedTypes() as $type): ?>
                    <option value="<?= htmlspecialchars($type) ?>" <?= $filters['type'] === $type ? 'selected' : '' ?>><?= htmlspecialchars(support_type_label($type)) ?></option>
                <?php endforeach; ?>
            </select>
            <select name="status" aria-label="الحالة">
                <option value="">كل الحالات</option>
                <?php foreach (SupportTicket::allowedStatuses() as $status): ?>
                    <option value="<?= htmlspecialchars($status) ?>" <?= $filters['status'] === $status ? 'selected' : '' ?>><?= htmlspecialchars(support_status_label($status)) ?></option>
                <?php endforeach; ?>
            </select>
            <select name="category" aria-label="القسم">
                <option value="">كل الأقسام</option>
                <?php foreach (SupportTicket::allowedCategories() as $category): ?>
                    <option value="<?= htmlspecialchars($category) ?>" <?= $filters['category'] === $category ? 'selected' : '' ?>><?= htmlspecialchars(support_category_label($category)) ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="table-btn">تطبيق</button>
            <a href="/public/admin/support.php" class="table-btn">مسح</a>
        </form>
    </section>

    <div class="modern-table-wrapper">
        <table class="modern-table">
            <thead><tr><th>البلاغ</th><th>العميل</th><th>النوع والقسم</th><th>العنوان</th><th>الحالة</th><th>آخر تحديث</th><th>الإجراء</th></tr></thead>
            <tbody>
            <?php foreach ($tickets as $ticket): ?>
                <?php
                    $badgeClass = 'badge-ok';
                    if ($ticket['status'] === 'new') $badgeClass = 'badge-pending';
                    if (in_array($ticket['status'], ['rejected','duplicate','closed'], true)) $badgeClass = 'badge-expired';
                ?>
                <tr>
                    <td data-label="البلاغ"><div class="cell-main"><strong dir="ltr"><?= htmlspecialchars($ticket['ticket_number']) ?></strong><span dir="ltr"><?= htmlspecialchars($ticket['created_at']) ?></span></div></td>
                    <td data-label="العميل"><div class="cell-main"><strong><?= htmlspecialchars($ticket['customer_name']) ?></strong><span dir="ltr"><?= htmlspecialchars($ticket['license_key']) ?></span></div></td>
                    <td data-label="النوع والقسم"><div class="cell-main"><strong><?= htmlspecialchars(support_type_label($ticket['type'])) ?></strong><span><?= htmlspecialchars(support_category_label($ticket['category'])) ?></span></div></td>
                    <td data-label="العنوان"><strong><?= htmlspecialchars($ticket['title']) ?></strong></td>
                    <td data-label="الحالة"><span class="badge <?= $badgeClass ?>"><?= htmlspecialchars(support_status_label($ticket['status'])) ?></span></td>
                    <td data-label="آخر تحديث" dir="ltr"><?= htmlspecialchars($ticket['updated_at']) ?></td>
                    <td data-label="الإجراء"><a class="table-btn" href="/public/admin/support.php?ticket=<?= rawurlencode($ticket['ticket_number']) ?>">فتح</a></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$tickets): ?>
                <tr><td colspan="7">لا توجد بلاغات مطابقة للفلاتر الحالية.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php if ($selected): ?>
        <section class="page-hero recovery-hero">
            <div>
                <p class="eyebrow" dir="ltr"><?= htmlspecialchars($selected['ticket_number']) ?></p>
                <h2><?= htmlspecialchars($selected['title']) ?></h2>
                <p class="page-subtitle"><?= nl2br(htmlspecialchars($selected['description'])) ?></p>
            </div>
            <span class="badge badge-ok"><?= htmlspecialchars(support_status_label($selected['status'])) ?></span>
        </section>

        <div class="recovery-meta-grid">
            <div class="recovery-meta-card"><span>العميل</span><strong><?= htmlspecialchars($selected['customer_name']) ?></strong></div>
            <div class="recovery-meta-card"><span>الترخيص</span><strong dir="ltr"><?= htmlspecialchars($selected['license_key']) ?></strong></div>
            <div class="recovery-meta-card"><span>الجهاز</span><strong><?= htmlspecialchars($selected['device_name'] ?: 'غير متوفر') ?></strong></div>
            <div class="recovery-meta-card"><span>الإصدار / Build</span><strong dir="ltr"><?= htmlspecialchars(trim(($selected['app_version'] ?: '—') . ' / ' . ($selected['build'] ?: '—'))) ?></strong></div>
            <div class="recovery-meta-card"><span>النظام</span><strong dir="auto"><?= htmlspecialchars($selected['os'] ?: 'غير متوفر') ?></strong></div>
            <div class="recovery-meta-card"><span>الصفحة</span><strong dir="auto"><?= htmlspecialchars($selected['current_page'] ?: 'غير متوفر') ?></strong></div>
            <div class="recovery-meta-card"><span>الأولوية</span><strong><?= htmlspecialchars($selected['priority']) ?></strong></div>
            <div class="recovery-meta-card"><span>تم الحل في</span><strong dir="ltr"><?= htmlspecialchars($selected['resolved_in_version'] ?: '—') ?></strong></div>
        </div>

        <?php if (!empty($selected['error_code']) || !empty($selected['error_message'])): ?>
            <aside class="recovery-notice">
                <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v6M12 17h.01"/></svg>
                <p><strong dir="ltr"><?= htmlspecialchars($selected['error_code'] ?: 'Error context') ?></strong><br><span dir="auto"><?= htmlspecialchars($selected['error_message'] ?: '') ?></span></p>
            </aside>
        <?php endif; ?>

        <div class="modern-table-wrapper">
            <table class="modern-table">
                <thead><tr><th>المرسل</th><th>الرسالة</th><th>النوع</th><th>الوقت</th></tr></thead>
                <tbody>
                <?php foreach ($selected['messages'] as $message): ?>
                    <tr>
                        <td><?= htmlspecialchars($message['sender_name'] ?: ($message['sender_type'] === 'client' ? 'العميل' : 'النظام')) ?></td>
                        <td><?= nl2br(htmlspecialchars($message['message'])) ?></td>
                        <td><?= !empty($message['is_internal']) ? 'ملاحظة داخلية' : 'مرئية للعميل' ?></td>
                        <td dir="ltr"><?= htmlspecialchars($message['created_at']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$selected['messages']): ?><tr><td colspan="4">لا توجد ردود بعد.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>

        <?php if (SupportAccess::canManage()): ?>
            <form method="post" class="recovery-review-form">
                <?= Csrf::field() ?>
                <input type="hidden" name="ticket_number" value="<?= htmlspecialchars($selected['ticket_number']) ?>">
                <label><span>الرد على العميل</span><textarea name="message" rows="4" maxlength="8000" placeholder="اكتب رد الدعم هنا"></textarea></label>
                <label><input type="checkbox" name="internal" value="1"> حفظ كملاحظة داخلية لا تظهر للعميل</label>
                <button type="submit" name="action" value="reply" class="approve-action">إرسال / حفظ الرد</button>
            </form>

            <form method="post" class="recovery-review-form">
                <?= Csrf::field() ?>
                <input type="hidden" name="ticket_number" value="<?= htmlspecialchars($selected['ticket_number']) ?>">
                <label><span>الحالة</span><select name="status">
                    <?php foreach (SupportTicket::allowedStatuses() as $status): ?>
                        <option value="<?= htmlspecialchars($status) ?>" <?= $selected['status'] === $status ? 'selected' : '' ?>><?= htmlspecialchars(support_status_label($status)) ?></option>
                    <?php endforeach; ?>
                </select></label>
                <label><span>تم الحل في الإصدار / Fix</span><input name="resolved_in_version" maxlength="50" value="<?= htmlspecialchars($selected['resolved_in_version'] ?? '') ?>" placeholder="مثال: 1.1.2 / Fix 230"></label>
                <label><span>ملاحظة تغيير الحالة</span><textarea name="note" rows="2" maxlength="255"></textarea></label>
                <label><input type="checkbox" name="internal_note" value="1"> الملاحظة داخلية فقط</label>
                <button type="submit" name="action" value="status" class="approve-action">حفظ الحالة</button>
            </form>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php render_footer(); ?>