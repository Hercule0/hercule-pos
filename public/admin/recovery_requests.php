<?php
require_once __DIR__ . '/includes/bootstrap.php';
Auth::require();
require_once __DIR__ . '/../../includes/PasswordRecovery.php';

$pdo = Database::pdo();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::guard();
    Auth::requirePermission('recovery.review');
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

$recoveryCounts = ['all' => count($requests), 'pending' => 0, 'approved' => 0, 'rejected' => 0, 'expired' => 0];
foreach ($requests as &$requestRow) {
    $licenseInfoStmt->execute([$requestRow['license_key']]);
    $requestRow['_license_info'] = $licenseInfoStmt->fetch() ?: null;
    if (isset($recoveryCounts[$requestRow['status']])) {
        $recoveryCounts[$requestRow['status']]++;
    }
}
unset($requestRow);

render_header('Password Recovery Requests');
flash_render();
?>

<div class="recovery-page">
    <section class="page-hero recovery-hero">
        <div>
            <p class="eyebrow">Account security</p>
            <h1>Recovery requests</h1>
            <p class="page-subtitle">Review identity signals before approving password recovery.</p>
        </div>
        <?php if ($recoveryCounts['pending'] > 0): ?>
            <span class="pending-summary">
                <i></i><?= $recoveryCounts['pending'] ?> pending
            </span>
        <?php endif; ?>
    </section>

    <aside class="recovery-notice">
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3 4 6v5c0 5 3.4 8.5 8 10 4.6-1.5 8-5 8-10V6z"/><path d="m8.5 12 2.2 2.2 4.8-5"/></svg>
        <p><strong>Passwords stay private.</strong> Approval only creates a short-lived, single-use authorization for the requesting device.</p>
    </aside>

    <section class="recovery-tools" aria-label="Recovery request tools">
        <label class="app-search" for="recovery-search">
            <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m16.5 16.5 4 4"/></svg>
            <input id="recovery-search" type="search" placeholder="Search username, customer, or key" autocomplete="off">
            <kbd id="recovery-result-count"><?= count($requests) ?></kbd>
        </label>
        <div class="pill-filters" id="recovery-filters" role="group" aria-label="Filter requests">
            <?php foreach (['all' => 'All', 'pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected', 'expired' => 'Expired'] as $value => $label): ?>
                <button type="button" data-recovery-filter="<?= $value ?>" class="pill-filter <?= $value === 'all' ? 'active' : '' ?>" style="background:var(--panel-alt); cursor:pointer;">
                    <?= $label ?><span class="count"><?= $recoveryCounts[$value] ?></span>
                </button>
            <?php endforeach; ?>
        </div>
    </section>

    <?php if (empty($requests)): ?>
        <section class="recovery-empty">
            <span class="empty-illustration">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3 4 6v5c0 5 3.4 8.5 8 10 4.6-1.5 8-5 8-10V6z"/><path d="m8.5 12 2.2 2.2 4.8-5"/></svg>
            </span>
            <h2>No recovery requests</h2>
            <p>New requests from locked-out users will appear here.</p>
        </section>
    <?php else: ?>
        <div class="modern-table-wrapper">
            <table class="modern-table">
                <thead>
                    <tr>
                        <th>Request ID</th>
                        <th>User & Customer</th>
                        <th>License & Plan</th>
                        <th>Status</th>
                        <th style="text-align: right;">Actions</th>
                    </tr>
                </thead>
                <tbody id="recovery-list" aria-live="polite">
                    <?php foreach ($requests as $r): ?>
                        <?php
                            $li = $r['_license_info'];
                            $customerName = $li['customer_name'] ?? 'Unknown customer';
                            $searchText = implode(' ', [$r['requested_username'], $customerName, $r['license_key'], $r['status']]);
                            
                            $badgeClass = 'badge-ok';
                            if ($r['status'] === 'pending') $badgeClass = 'badge-pending';
                            if ($r['status'] === 'rejected' || $r['status'] === 'expired') $badgeClass = 'badge-expired';
                        ?>
                        <tr data-recovery-card data-status="<?= htmlspecialchars($r['status']) ?>" data-search="<?= htmlspecialchars($searchText, ENT_QUOTES) ?>">
                            <td data-label="Request ID">
                                <div class="cell-main">
                                    <strong>#<?= (int) $r['id'] ?></strong>
                                    <span><?= htmlspecialchars(date('M j, Y', strtotime($r['created_at']))) ?></span>
                                </div>
                            </td>
                            <td data-label="User & Customer">
                                <div class="cell-main">
                                    <strong><?= htmlspecialchars($r['requested_username']) ?></strong>
                                    <span><?= htmlspecialchars($customerName) ?></span>
                                </div>
                            </td>
                            <td data-label="License & Plan">
                                <div class="cell-main">
                                    <code dir="ltr" style="font-size:13px; font-weight:600;" title="<?= htmlspecialchars($r['license_key'], ENT_QUOTES) ?>"><?= htmlspecialchars(strlen($r['license_key']) > 16 ? '•••• ' . substr($r['license_key'], -12) : $r['license_key']) ?></code>
                                    <span><?= $li ? htmlspecialchars(str_replace('_', ' ', $li['plan'])) . ' plan' : 'N/A' ?></span>
                                </div>
                            </td>
                            <td data-label="Status">
                                <span class="badge <?= $badgeClass ?>"><?= htmlspecialchars($r['status']) ?></span>
                            </td>
                            <td data-label="Actions">
                                <div class="cell-actions">
                                    <button type="button" class="table-btn" data-open-recovery-dialog="recovery-dialog-<?= (int) $r['id'] ?>">
                                        <?= $r['status'] === 'pending' ? 'Review' : 'Details' ?>
                                    </button>
                                </div>
                            </td>
                        </tr>

                <dialog class="app-dialog recovery-dialog" id="recovery-dialog-<?= (int) $r['id'] ?>">
                    <div class="recovery-dialog-content">
                        <div class="dialog-header">
                            <div>
                                <p class="eyebrow">Recovery request #<?= (int) $r['id'] ?></p>
                                <h2 dir="auto"><?= htmlspecialchars($r['requested_username']) ?></h2>
                            </div>
                            <button type="button" class="dialog-close" data-close-recovery-dialog aria-label="Close">
                                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m6 6 12 12M18 6 6 18"/></svg>
                            </button>
                        </div>

                        <div class="recovery-detail-body">
                            <div class="identity-summary">
                                <span class="customer-avatar"><?= strtoupper(htmlspecialchars(substr($customerName, 0, 1))) ?></span>
                                <div>
                                    <strong dir="auto"><?= htmlspecialchars($customerName) ?></strong>
                                    <small>
                                        <?= $li ? htmlspecialchars(str_replace('_', ' ', $li['plan'])) . ' · ' . htmlspecialchars($li['license_status']) : 'License not found' ?>
                                    </small>
                                </div>
                                <span class="license-status status-<?= htmlspecialchars($r['status']) ?>"><?= htmlspecialchars($r['status']) ?></span>
                            </div>

                            <dl class="recovery-detail-list">
                                <div>
                                    <dt>License key</dt>
                                    <dd><code dir="ltr"><?= htmlspecialchars($r['license_key']) ?></code><button type="button" data-copy-value="<?= htmlspecialchars($r['license_key'], ENT_QUOTES) ?>" aria-label="Copy license key"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="8" y="8" width="11" height="11" rx="2"/><path d="M16 8V5H5v11h3"/></svg></button></dd>
                                </div>
                                <div>
                                    <dt>Hardware ID</dt>
                                    <dd><code dir="ltr"><?= htmlspecialchars($r['hwid']) ?></code><button type="button" data-copy-value="<?= htmlspecialchars($r['hwid'], ENT_QUOTES) ?>" aria-label="Copy hardware ID"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="8" y="8" width="11" height="11" rx="2"/><path d="M16 8V5H5v11h3"/></svg></button></dd>
                                </div>
                                <div>
                                    <dt>Requested</dt>
                                    <dd><?= htmlspecialchars($r['created_at']) ?></dd>
                                </div>
                                <div>
                                    <dt>Reviewed</dt>
                                    <dd>
                                        <?= htmlspecialchars($r['reviewed_at'] ?? 'Not reviewed') ?>
                                        <?= $r['reviewed_by'] ? ' by ' . htmlspecialchars($r['reviewed_by']) : '' ?>
                                    </dd>
                                </div>
                                <?php if (!empty($r['admin_note'])): ?>
                                    <div>
                                        <dt>Admin note</dt>
                                        <dd dir="auto"><?= htmlspecialchars($r['admin_note']) ?></dd>
                                    </div>
                                <?php endif; ?>
                            </dl>
                        </div>

                        <?php if ($r['status'] === 'pending'): ?>
                            <form method="post" class="recovery-review-form">
                                <?= Csrf::field() ?>
                                <input type="hidden" name="request_id" value="<?= $r['id'] ?>">
                                <label>
                                    <span>Internal note</span>
                                    <textarea name="note" rows="2" placeholder="Optional reason or verification note"></textarea>
                                </label>
                                <div class="recovery-review-actions">
                                    <button type="submit" name="action" value="reject" class="reject-action" onclick="return confirm('Reject this recovery request?');">
                                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m7 7 10 10M17 7 7 17"/></svg>
                                        Reject
                                    </button>
                                    <button type="submit" name="action" value="approve" class="approve-action" onclick="return confirm('Approve this recovery request for this device?');">
                                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m5 12 4 4 10-10"/></svg>
                                        Approve
                                    </button>
                                </div>
                            </form>
                        <?php endif; ?>
                    </div>
                </dialog>
            <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="search-empty" id="recovery-search-empty" hidden>
            <strong>No matching requests</strong>
            <p>Try another search or select a different status.</p>
        </div>
    <?php endif; ?>
</div>

<script>
(function () {
    document.querySelectorAll('[data-open-recovery-dialog]').forEach(function (button) {
        button.addEventListener('click', function () {
            var dialog = document.getElementById(button.dataset.openRecoveryDialog);
            if (!dialog) return;
            if (typeof dialog.showModal === 'function') dialog.showModal();
            else dialog.setAttribute('open', '');
        });
    });
    document.querySelectorAll('[data-close-recovery-dialog]').forEach(function (button) {
        button.addEventListener('click', function () {
            var dialog = button.closest('dialog');
            if (!dialog) return;
            if (typeof dialog.close === 'function') dialog.close();
            else dialog.removeAttribute('open');
        });
    });
    document.querySelectorAll('.recovery-dialog').forEach(function (dialog) {
        dialog.addEventListener('click', function (event) {
            if (event.target === dialog) dialog.close();
        });
    });

    var requestedId = new URLSearchParams(window.location.search).get('request_id');
    if (requestedId && /^\d+$/.test(requestedId)) {
        var requestedDialog = document.getElementById('recovery-dialog-' + requestedId);
        if (requestedDialog) {
            if (typeof requestedDialog.showModal === 'function') requestedDialog.showModal();
            else requestedDialog.setAttribute('open', '');
        }
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
