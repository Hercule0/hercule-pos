<?php
require_once __DIR__ . '/includes/bootstrap.php';
Auth::require();
require_once __DIR__ . '/../../includes/PasswordRecovery.php';

$pdo = Database::pdo();

function recovery_type_from_row(array $row): string
{
    $requested = (string) ($row['requested_username'] ?? '');
    if ($requested === 'الحساب الرئيسي — استرداد اسم المستخدم') return 'username';
    if ($requested === 'الحساب الرئيسي — استرداد بيانات الدخول') return 'account';
    return 'password';
}

function recovery_type_label(string $type): string
{
    return match ($type) {
        'username' => 'اسم المستخدم',
        'account' => 'اسم المستخدم وكلمة المرور',
        default => 'كلمة المرور',
    };
}

function recovery_public_status(array $row): string
{
    if (($row['status'] ?? '') === 'rejected' && ($row['admin_note'] ?? '') === '__CLIENT_CANCELLED__') {
        return 'cancelled';
    }
    return (string) ($row['status'] ?? 'pending');
}

function recovery_status_label(string $status): string
{
    return match ($status) {
        'pending' => 'قيد الانتظار',
        'approved' => 'تمت الموافقة',
        'rejected' => 'مرفوض',
        'expired' => 'منتهي الصلاحية',
        'completed' => 'مكتمل',
        'cancelled' => 'ملغي من العميل',
        default => $status,
    };
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::guard();
    Auth::requirePermission('recovery.review');

    $id = (int) ($_POST['request_id'] ?? 0);
    $action = (string) ($_POST['action'] ?? '');
    $note = trim((string) ($_POST['note'] ?? '')) ?: null;
    $admin = Auth::currentUsername() ?? 'admin';

    if ($action === 'approve') {
        $identityVerified = ($_POST['identity_verified'] ?? '') === '1';
        $verificationMethod = (string) ($_POST['verification_method'] ?? '');
        $allowedMethods = ['phone', 'whatsapp', 'email', 'other'];

        if (!$identityVerified) {
            flash_set('يجب تأكيد التحقق من هوية العميل قبل الموافقة على الاسترداد.', 'error');
        } elseif (!in_array($verificationMethod, $allowedMethods, true)) {
            flash_set('اختر طريقة التحقق من هوية العميل قبل الموافقة.', 'error');
        } else {
            $methodLabels = [
                'phone' => 'phone',
                'whatsapp' => 'whatsapp',
                'email' => 'email',
                'other' => 'other',
            ];
            $auditNote = 'method=' . $methodLabels[$verificationMethod];
            if ($note) $auditNote .= '; note=' . mb_substr($note, 0, 180);

            $audit = $pdo->prepare(
                'INSERT INTO recovery_audit_log (request_id, event_type, actor, ip_address, note) VALUES (?, ?, ?, ?, ?)'
            );
            $audit->execute([$id, 'identity_verified', $admin, $_SERVER['REMOTE_ADDR'] ?? null, $auditNote]);

            $result = PasswordRecovery::approve($id, $admin, $note);
            flash_set(
                $result['ok'] ? "تمت الموافقة على طلب الاسترداد #{$id} بعد التحقق من هوية العميل." : $result['error'],
                $result['ok'] ? 'success' : 'error'
            );
        }
    } elseif ($action === 'reject') {
        $result = PasswordRecovery::reject($id, $admin, $note);
        flash_set($result['ok'] ? "تم رفض طلب الاسترداد #{$id}." : $result['error'], $result['ok'] ? 'success' : 'error');
    }

    header('Location: /public/admin/recovery_requests.php');
    exit;
}

$licenseInfoStmt = $pdo->prepare(
    'SELECT l.status AS license_status, l.plan,
            c.name AS customer_name, c.phone AS customer_phone, c.email AS customer_email,
            a.device_name, a.app_version, a.last_seen_at, a.ip_address AS device_ip
     FROM licenses l
     JOIN customers c ON c.id = l.customer_id
     LEFT JOIN license_activations a ON a.license_id = l.id AND a.hwid = ?
     WHERE l.license_key = ?
     LIMIT 1'
);

$verifiedStmt = $pdo->prepare(
    "SELECT actor, note, created_at
     FROM recovery_audit_log
     WHERE request_id = ? AND event_type = 'identity_verified'
     ORDER BY id DESC LIMIT 1"
);

$requests = PasswordRecovery::allList();
$recoveryCounts = [
    'all' => count($requests),
    'pending' => 0,
    'approved' => 0,
    'rejected' => 0,
    'expired' => 0,
    'completed' => 0,
    'cancelled' => 0,
];

foreach ($requests as &$requestRow) {
    $licenseInfoStmt->execute([$requestRow['hwid'], $requestRow['license_key']]);
    $requestRow['_license_info'] = $licenseInfoStmt->fetch() ?: null;
    $requestRow['_recovery_type'] = recovery_type_from_row($requestRow);
    $requestRow['_public_status'] = recovery_public_status($requestRow);

    $verifiedStmt->execute([(int) $requestRow['id']]);
    $requestRow['_identity_verification'] = $verifiedStmt->fetch() ?: null;

    if (isset($recoveryCounts[$requestRow['_public_status']])) {
        $recoveryCounts[$requestRow['_public_status']]++;
    }
}
unset($requestRow);

render_header('طلبات استرداد الحساب');
flash_render();
?>

<style>
.recovery-meta-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;margin-top:12px}.recovery-meta-card{background:var(--panel-alt);border:1px solid var(--border);border-radius:12px;padding:12px}.recovery-meta-card span{display:block;font-size:12px;color:var(--muted);margin-bottom:5px}.recovery-meta-card strong,.recovery-meta-card code{font-size:14px;overflow-wrap:anywhere}.recovery-type-badge{display:inline-flex;align-items:center;gap:6px;border-radius:999px;padding:6px 10px;background:var(--panel-alt);border:1px solid var(--border);font-size:12px;font-weight:700}.identity-check-box{margin-top:14px;padding:14px;border:1px solid var(--border);border-radius:14px;background:var(--panel-alt)}.identity-check-box>strong{display:block;margin-bottom:8px}.identity-check-box label{display:flex;gap:8px;align-items:flex-start;margin:8px 0}.identity-check-box select{width:100%;margin-top:7px}.verified-proof{margin-top:12px;padding:10px 12px;border-radius:12px;background:var(--panel-alt);border:1px solid var(--border);font-size:13px}.recovery-contact-row{display:flex;gap:8px;align-items:center;flex-wrap:wrap}.recovery-contact-row a{font-weight:700}.recovery-status-cancelled{opacity:.75}@media(max-width:760px){.recovery-meta-grid{grid-template-columns:1fr}}
</style>

<div class="recovery-page" dir="rtl">
    <section class="page-hero recovery-hero">
        <div>
            <p class="eyebrow">أمان الحسابات</p>
            <h1>طلبات استرداد الحساب</h1>
            <p class="page-subtitle">راجع بيانات العميل والجهاز وتحقق من الهوية قبل منح تصريح الاسترداد.</p>
        </div>
        <?php if ($recoveryCounts['pending'] > 0): ?>
            <span class="pending-summary"><i></i><?= (int) $recoveryCounts['pending'] ?> قيد الانتظار</span>
        <?php endif; ?>
    </section>

    <aside class="recovery-notice">
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3 4 6v5c0 5 3.4 8.5 8 10 4.6-1.5 8-5 8-10V6z"/><path d="m8.5 12 2.2 2.2 4.8-5"/></svg>
        <p><strong>كلمات المرور تبقى خاصة داخل جهاز العميل.</strong> الموافقة هنا تمنح تصريحاً مؤقتاً ومرة واحدة للجهاز الذي أرسل الطلب فقط.</p>
    </aside>

    <section class="recovery-tools" aria-label="أدوات طلبات الاسترداد">
        <label class="app-search" for="recovery-search">
            <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m16.5 16.5 4 4"/></svg>
            <input id="recovery-search" type="search" placeholder="ابحث بالعميل أو المستخدم أو الهاتف أو الترخيص" autocomplete="off">
            <kbd id="recovery-result-count"><?= count($requests) ?></kbd>
        </label>
        <div class="pill-filters" id="recovery-filters" role="group" aria-label="تصفية الطلبات">
            <?php
            $filterLabels = [
                'all' => 'الكل', 'pending' => 'قيد الانتظار', 'approved' => 'موافق عليه',
                'completed' => 'مكتمل', 'rejected' => 'مرفوض', 'expired' => 'منتهي', 'cancelled' => 'ملغي'
            ];
            foreach ($filterLabels as $value => $label): ?>
                <button type="button" data-recovery-filter="<?= $value ?>" class="pill-filter <?= $value === 'all' ? 'active' : '' ?>" style="background:var(--panel-alt);cursor:pointer;">
                    <?= $label ?><span class="count"><?= (int) ($recoveryCounts[$value] ?? 0) ?></span>
                </button>
            <?php endforeach; ?>
        </div>
    </section>

    <?php if (empty($requests)): ?>
        <section class="recovery-empty">
            <span class="empty-illustration"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3 4 6v5c0 5 3.4 8.5 8 10 4.6-1.5 8-5 8-10V6z"/><path d="m8.5 12 2.2 2.2 4.8-5"/></svg></span>
            <h2>لا توجد طلبات استرداد</h2>
            <p>ستظهر هنا الطلبات الجديدة القادمة من أجهزة Hercule POS.</p>
        </section>
    <?php else: ?>
        <div class="modern-table-wrapper">
            <table class="modern-table">
                <thead><tr><th>الطلب</th><th>الحساب والعميل</th><th>نوع الاسترداد</th><th>الحالة</th><th style="text-align:left;">الإجراء</th></tr></thead>
                <tbody id="recovery-list" aria-live="polite">
                <?php foreach ($requests as $r):
                    $li = $r['_license_info'];
                    $customerName = $li['customer_name'] ?? 'عميل غير معروف';
                    $customerPhone = $li['customer_phone'] ?? '';
                    $customerEmail = $li['customer_email'] ?? '';
                    $type = $r['_recovery_type'];
                    $publicStatus = $r['_public_status'];
                    $displayUser = $type === 'password' ? ($r['requested_username'] ?: '—') : 'الحساب الرئيسي';
                    $searchText = implode(' ', [$displayUser, $customerName, $customerPhone, $customerEmail, $r['license_key'], $publicStatus, recovery_type_label($type)]);
                    $badgeClass = 'badge-ok';
                    if ($publicStatus === 'pending') $badgeClass = 'badge-pending';
                    if (in_array($publicStatus, ['rejected','expired','cancelled'], true)) $badgeClass = 'badge-expired';
                ?>
                    <tr data-recovery-card data-status="<?= htmlspecialchars($publicStatus) ?>" data-search="<?= htmlspecialchars($searchText, ENT_QUOTES) ?>" class="<?= $publicStatus === 'cancelled' ? 'recovery-status-cancelled' : '' ?>">
                        <td data-label="الطلب"><div class="cell-main"><strong>#<?= (int) $r['id'] ?></strong><span><?= htmlspecialchars(date('Y-m-d H:i', strtotime($r['created_at']))) ?></span></div></td>
                        <td data-label="الحساب والعميل"><div class="cell-main"><strong dir="auto"><?= htmlspecialchars($displayUser) ?></strong><span dir="auto"><?= htmlspecialchars($customerName) ?></span></div></td>
                        <td data-label="نوع الاسترداد"><span class="recovery-type-badge"><?= htmlspecialchars(recovery_type_label($type)) ?></span></td>
                        <td data-label="الحالة"><span class="badge <?= $badgeClass ?>"><?= htmlspecialchars(recovery_status_label($publicStatus)) ?></span></td>
                        <td data-label="الإجراء"><div class="cell-actions"><button type="button" class="table-btn" data-open-recovery-dialog="recovery-dialog-<?= (int) $r['id'] ?>"><?= $publicStatus === 'pending' ? 'مراجعة' : 'التفاصيل' ?></button></div></td>
                    </tr>

                    <dialog class="app-dialog recovery-dialog" id="recovery-dialog-<?= (int) $r['id'] ?>">
                        <div class="recovery-dialog-content" dir="rtl">
                            <div class="dialog-header">
                                <div><p class="eyebrow">طلب الاسترداد #<?= (int) $r['id'] ?></p><h2><?= htmlspecialchars(recovery_type_label($type)) ?></h2></div>
                                <button type="button" class="dialog-close" data-close-recovery-dialog aria-label="إغلاق"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m6 6 12 12M18 6 6 18"/></svg></button>
                            </div>

                            <div class="recovery-detail-body">
                                <div class="identity-summary">
                                    <span class="customer-avatar"><?= htmlspecialchars(mb_strtoupper(mb_substr($customerName, 0, 1))) ?></span>
                                    <div><strong dir="auto"><?= htmlspecialchars($customerName) ?></strong><small><?= $li ? htmlspecialchars(str_replace('_', ' ', $li['plan'])) . ' · ' . htmlspecialchars($li['license_status']) : 'تعذر العثور على بيانات الترخيص' ?></small></div>
                                    <span class="license-status status-<?= htmlspecialchars($publicStatus) ?>"><?= htmlspecialchars(recovery_status_label($publicStatus)) ?></span>
                                </div>

                                <div class="recovery-meta-grid">
                                    <div class="recovery-meta-card"><span>الحساب المطلوب</span><strong dir="auto"><?= htmlspecialchars($displayUser) ?></strong></div>
                                    <div class="recovery-meta-card"><span>نوع الاسترداد</span><strong><?= htmlspecialchars(recovery_type_label($type)) ?></strong></div>
                                    <div class="recovery-meta-card"><span>هاتف العميل</span><div class="recovery-contact-row"><strong dir="ltr"><?= htmlspecialchars($customerPhone ?: 'غير مسجل') ?></strong></div></div>
                                    <div class="recovery-meta-card"><span>البريد الإلكتروني</span><strong dir="ltr"><?= htmlspecialchars($customerEmail ?: 'غير مسجل') ?></strong></div>
                                    <div class="recovery-meta-card"><span>اسم الجهاز</span><strong dir="auto"><?= htmlspecialchars($li['device_name'] ?? 'غير متوفر') ?></strong></div>
                                    <div class="recovery-meta-card"><span>إصدار التطبيق</span><strong dir="ltr"><?= htmlspecialchars($li['app_version'] ?? 'غير متوفر') ?></strong></div>
                                    <div class="recovery-meta-card"><span>آخر ظهور للجهاز</span><strong dir="ltr"><?= htmlspecialchars($li['last_seen_at'] ?? 'غير متوفر') ?></strong></div>
                                    <div class="recovery-meta-card"><span>عنوان IP الأخير</span><strong dir="ltr"><?= htmlspecialchars($li['device_ip'] ?? 'غير متوفر') ?></strong></div>
                                </div>

                                <dl class="recovery-detail-list">
                                    <div><dt>مفتاح الترخيص</dt><dd><code dir="ltr"><?= htmlspecialchars($r['license_key']) ?></code><button type="button" data-copy-value="<?= htmlspecialchars($r['license_key'], ENT_QUOTES) ?>" aria-label="نسخ مفتاح الترخيص"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="8" y="8" width="11" height="11" rx="2"/><path d="M16 8V5H5v11h3"/></svg></button></dd></div>
                                    <div><dt>معرّف الجهاز</dt><dd><code dir="ltr"><?= htmlspecialchars($r['hwid']) ?></code><button type="button" data-copy-value="<?= htmlspecialchars($r['hwid'], ENT_QUOTES) ?>" aria-label="نسخ معرّف الجهاز"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="8" y="8" width="11" height="11" rx="2"/><path d="M16 8V5H5v11h3"/></svg></button></dd></div>
                                    <div><dt>وقت إرسال الطلب</dt><dd dir="ltr"><?= htmlspecialchars($r['created_at']) ?></dd></div>
                                    <div><dt>وقت المراجعة</dt><dd dir="auto"><?= htmlspecialchars($r['reviewed_at'] ?? 'لم تتم المراجعة بعد') ?><?= $r['reviewed_by'] ? ' — ' . htmlspecialchars($r['reviewed_by']) : '' ?></dd></div>
                                </dl>

                                <?php if (!empty($r['_identity_verification'])): $v = $r['_identity_verification']; ?>
                                    <div class="verified-proof"><strong>✓ تم تسجيل تحقق الهوية</strong><br><span><?= htmlspecialchars($v['created_at']) ?> بواسطة <?= htmlspecialchars($v['actor'] ?? 'admin') ?></span><?php if (!empty($v['note'])): ?><br><small dir="auto"><?= htmlspecialchars($v['note']) ?></small><?php endif; ?></div>
                                <?php endif; ?>
                            </div>

                            <?php if ($publicStatus === 'pending'): ?>
                                <form method="post" class="recovery-review-form">
                                    <?= Csrf::field() ?>
                                    <input type="hidden" name="request_id" value="<?= (int) $r['id'] ?>">

                                    <div class="identity-check-box">
                                        <strong>التحقق من هوية العميل</strong>
                                        <p>تواصل مع صاحب الترخيص باستخدام بيانات الاتصال المسجلة أعلاه قبل الموافقة.</p>
                                        <label><input type="checkbox" name="identity_verified" value="1"> <span>أؤكد أنني تحققت من هوية صاحب الترخيص.</span></label>
                                        <label style="display:block;"><span>طريقة التحقق</span>
                                            <select name="verification_method">
                                                <option value="">اختر الطريقة</option>
                                                <option value="phone">مكالمة هاتفية</option>
                                                <option value="whatsapp">واتساب</option>
                                                <option value="email">البريد الإلكتروني</option>
                                                <option value="other">طريقة أخرى موثوقة</option>
                                            </select>
                                        </label>
                                    </div>

                                    <label><span>ملاحظة داخلية</span><textarea name="note" rows="2" placeholder="مثال: تم التحقق من اسم العميل وآخر 4 أرقام من الهاتف"></textarea></label>
                                    <div class="recovery-review-actions">
                                        <button type="submit" name="action" value="reject" class="reject-action" onclick="return confirm('هل تريد رفض طلب الاسترداد؟');"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m7 7 10 10M17 7 7 17"/></svg>رفض</button>
                                        <button type="submit" name="action" value="approve" class="approve-action" onclick="return confirm('هل تحققت من هوية العميل وتريد الموافقة على الاسترداد لهذا الجهاز؟');"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m5 12 4 4 10-10"/></svg>موافقة بعد التحقق</button>
                                    </div>
                                </form>
                            <?php elseif ($publicStatus === 'cancelled'): ?>
                                <div class="verified-proof">ألغى العميل هذا الطلب من نفس جهاز Hercule POS قبل مراجعته.</div>
                            <?php elseif (!empty($r['admin_note']) && $r['admin_note'] !== '__CLIENT_CANCELLED__'): ?>
                                <div class="verified-proof"><strong>ملاحظة المراجعة</strong><br><span dir="auto"><?= htmlspecialchars($r['admin_note']) ?></span></div>
                            <?php endif; ?>
                        </div>
                    </dialog>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="search-empty" id="recovery-search-empty" hidden><strong>لا توجد نتائج مطابقة</strong><p>جرّب عبارة بحث أخرى أو غيّر حالة التصفية.</p></div>
    <?php endif; ?>
</div>

<script>
(function () {
    document.querySelectorAll('[data-open-recovery-dialog]').forEach(function (button) {
        button.addEventListener('click', function () {
            var dialog = document.getElementById(button.dataset.openRecoveryDialog);
            if (!dialog) return;
            if (typeof dialog.showModal === 'function') dialog.showModal(); else dialog.setAttribute('open', '');
        });
    });
    document.querySelectorAll('[data-close-recovery-dialog]').forEach(function (button) {
        button.addEventListener('click', function () {
            var dialog = button.closest('dialog');
            if (!dialog) return;
            if (typeof dialog.close === 'function') dialog.close(); else dialog.removeAttribute('open');
        });
    });
    document.querySelectorAll('.recovery-dialog').forEach(function (dialog) {
        dialog.addEventListener('click', function (event) { if (event.target === dialog) dialog.close(); });
    });

    var requestedId = new URLSearchParams(window.location.search).get('request_id');
    if (requestedId && /^\d+$/.test(requestedId)) {
        var requestedDialog = document.getElementById('recovery-dialog-' + requestedId);
        if (requestedDialog) { if (typeof requestedDialog.showModal === 'function') requestedDialog.showModal(); else requestedDialog.setAttribute('open', ''); }
    }

    var cards = Array.from(document.querySelectorAll('[data-recovery-card]'));
    var search = document.getElementById('recovery-search');
    var filters = Array.from(document.querySelectorAll('[data-recovery-filter]'));
    var resultCount = document.getElementById('recovery-result-count');
    var empty = document.getElementById('recovery-search-empty');
    var activeFilter = 'all';

    function applyFilters() {
        var query = search ? search.value.trim().toLocaleLowerCase() : '';
        var visible = 0;
        cards.forEach(function (card) {
            var matchesSearch = card.dataset.search.toLocaleLowerCase().includes(query);
            var matchesStatus = activeFilter === 'all' || card.dataset.status === activeFilter;
            var show = matchesSearch && matchesStatus;
            card.hidden = !show;
            if (show) visible++;
        });
        if (resultCount) resultCount.textContent = visible;
        if (empty) empty.hidden = visible !== 0;
    }

    if (search) search.addEventListener('input', applyFilters);
    filters.forEach(function (button) {
        button.addEventListener('click', function () {
            activeFilter = button.dataset.recoveryFilter;
            filters.forEach(function (item) { item.classList.toggle('active', item === button); });
            applyFilters();
        });
    });

    document.querySelectorAll('[data-copy-value]').forEach(function (button) {
        button.addEventListener('click', function () {
            if (!navigator.clipboard) return;
            navigator.clipboard.writeText(button.dataset.copyValue).then(function () {
                button.classList.add('copied');
                setTimeout(function () { button.classList.remove('copied'); }, 1200);
            });
        });
    });
})();
</script>

<?php render_footer(); ?>
