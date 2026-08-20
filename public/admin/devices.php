<?php
require_once __DIR__ . '/includes/bootstrap.php';
Auth::require();

$pdo = Database::pdo();

$columnCheck = $pdo->prepare(
    'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = ?
       AND COLUMN_NAME IN (?, ?)'
);
$columnCheck->execute(['license_activations', 'device_name', 'admin_note']);
$deviceSchemaReady = (int) $columnCheck->fetchColumn() === 2;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::guard();
    Auth::requirePermission('licenses.manage');

    if (!$deviceSchemaReady) {
        flash_set('Device Management migration has not been run yet.', 'error');
        header('Location: /public/admin/devices.php');
        exit;
    }

    $activationId = max(0, (int) ($_POST['activation_id'] ?? 0));
    $action = $_POST['action'] ?? '';
    $admin = Auth::currentUsername() ?? 'admin';

    $activationStmt = $pdo->prepare(
        'SELECT a.id, a.license_id, a.hwid, l.license_key
         FROM license_activations a
         JOIN licenses l ON l.id = a.license_id
         WHERE a.id = ?'
    );
    $activationStmt->execute([$activationId]);
    $activation = $activationStmt->fetch();

    if (!$activation) {
        flash_set('Device activation not found.', 'error');
        header('Location: /public/admin/devices.php');
        exit;
    }

    if ($action === 'update_device') {
        $deviceName = mb_substr(trim($_POST['device_name'] ?? ''), 0, 100);
        $adminNote = mb_substr(trim($_POST['admin_note'] ?? ''), 0, 255);

        $stmt = $pdo->prepare(
            'UPDATE license_activations
             SET device_name = ?, admin_note = ?
             WHERE id = ?'
        );
        $stmt->execute([
            $deviceName !== '' ? $deviceName : null,
            $adminNote !== '' ? $adminNote : null,
            $activationId,
        ]);

        $event = $pdo->prepare(
            'INSERT INTO subscription_events
             (license_id, event_type, note, created_by)
             VALUES (?, ?, ?, ?)'
        );
        $eventNote = $deviceName !== '' ? "Device named {$deviceName}" : 'Device details updated';
        $event->execute([(int) $activation['license_id'], 'device_updated', $eventNote, $admin]);
        flash_set('Device details updated.');
    } elseif ($action === 'reset_slot') {
        $stmt = $pdo->prepare(
            'UPDATE license_activations SET is_active = 0 WHERE id = ?'
        );
        $stmt->execute([$activationId]);

        $event = $pdo->prepare(
            'INSERT INTO subscription_events
             (license_id, event_type, note, created_by)
             VALUES (?, ?, ?, ?)'
        );
        $event->execute([
            (int) $activation['license_id'],
            'device_reset',
            'Activation slot reset for HWID ' . mb_substr((string) $activation['hwid'], 0, 90),
            $admin,
        ]);
        flash_set('Device slot reset. The slot is available for another activation.');
    }

    header('Location: /public/admin/devices.php');
    exit;
}

$searchQuery = mb_substr(trim($_GET['q'] ?? ''), 0, 100);
$statusFilter = $_GET['status'] ?? 'all';
if (!in_array($statusFilter, ['all', 'active', 'inactive'], true)) {
    $statusFilter = 'all';
}
$currentPage = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 18;

$where = [];
$params = [];
if ($searchQuery !== '') {
    $where[] = '(a.hwid LIKE ? OR l.license_key LIKE ? OR c.name LIKE ?' . ($deviceSchemaReady ? ' OR a.device_name LIKE ?' : '') . ')';
    $pattern = '%' . $searchQuery . '%';
    $params = [$pattern, $pattern, $pattern];
    if ($deviceSchemaReady) $params[] = $pattern;
}
if ($statusFilter === 'active') $where[] = 'a.is_active = 1';
if ($statusFilter === 'inactive') $where[] = 'a.is_active = 0';
$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

$countSql = 'SELECT COUNT(*)
             FROM license_activations a
             JOIN licenses l ON l.id = a.license_id
             JOIN customers c ON c.id = l.customer_id' . $whereSql;
$countStmt = $pdo->prepare($countSql);
$countStmt->execute($params);
$totalDevices = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($totalDevices / $perPage));
$currentPage = min($currentPage, $totalPages);
$offset = ($currentPage - 1) * $perPage;

$selectDeviceFields = $deviceSchemaReady ? 'a.device_name, a.admin_note,' : 'NULL AS device_name, NULL AS admin_note,';
$sql = "SELECT a.id, a.license_id, a.hwid, {$selectDeviceFields}
               a.is_active, a.activated_at, a.last_seen_at, a.ip_address,
               l.license_key, l.status AS license_status, l.plan,
               c.name AS customer_name
        FROM license_activations a
        JOIN licenses l ON l.id = a.license_id
        JOIN customers c ON c.id = l.customer_id"
        . $whereSql .
       ' ORDER BY a.is_active DESC, a.last_seen_at DESC LIMIT ? OFFSET ?';
$stmt = $pdo->prepare($sql);
$position = 1;
foreach ($params as $value) {
    $stmt->bindValue($position++, $value, PDO::PARAM_STR);
}
$stmt->bindValue($position++, $perPage, PDO::PARAM_INT);
$stmt->bindValue($position, $offset, PDO::PARAM_INT);
$stmt->execute();
$devices = $stmt->fetchAll();

$summary = $pdo->query(
    'SELECT COUNT(*) AS total_count,
            SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) AS active_count,
            SUM(CASE WHEN is_active = 0 THEN 1 ELSE 0 END) AS inactive_count
     FROM license_activations'
)->fetch();

$pageUrl = static function (int $page) use ($searchQuery, $statusFilter): string {
    $query = ['page' => $page];
    if ($searchQuery !== '') $query['q'] = $searchQuery;
    if ($statusFilter !== 'all') $query['status'] = $statusFilter;
    return '/public/admin/devices.php?' . http_build_query($query);
};

render_header('Devices');
flash_render();
?>
<div class="devices-page">
    <section class="page-hero devices-hero">
        <div>
            <p class="eyebrow">Hardware</p>
            <h1>Device Management</h1>
            <p class="page-subtitle">Track every bound POS terminal, label hardware, add internal notes, and free activation slots.</p>
        </div>
        <a href="/public/admin/licenses.php" class="app-secondary-action">View licenses</a>
    </section>

    <?php if (!$deviceSchemaReady): ?>
        <section class="device-migration-warning">
            <strong>Migration required</strong>
            <p>Run <code>php db/migrate_device_management.php</code> on production before editing device names or notes.</p>
        </section>
    <?php endif; ?>

    <section class="device-summary-grid" aria-label="Device summary">
        <article><span>Total devices</span><strong><?= (int)($summary['total_count'] ?? 0) ?></strong></article>
        <article><span>Active</span><strong class="text-emerald"><?= (int)($summary['active_count'] ?? 0) ?></strong></article>
        <article><span>Freed slots</span><strong><?= (int)($summary['inactive_count'] ?? 0) ?></strong></article>
    </section>

    <section class="device-tools">
        <form method="get" class="device-search-form">
            <?php if ($statusFilter !== 'all'): ?><input type="hidden" name="status" value="<?= htmlspecialchars($statusFilter, ENT_QUOTES) ?>"><?php endif; ?>
            <label class="app-search" for="device-search">
                <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m16.5 16.5 4 4"/></svg>
                <input id="device-search" name="q" type="search" value="<?= htmlspecialchars($searchQuery, ENT_QUOTES) ?>" placeholder="Search device, HWID, customer, or license" autocomplete="off">
                <kbd><?= count($devices) ?></kbd>
            </label>
        </form>
        <div class="pill-filters">
            <?php foreach (['all' => 'All', 'active' => 'Active', 'inactive' => 'Freed'] as $value => $label): ?>
                <?php $query = []; if ($searchQuery !== '') $query['q'] = $searchQuery; if ($value !== 'all') $query['status'] = $value; ?>
                <a class="pill-filter <?= $statusFilter === $value ? 'active' : '' ?>" href="/public/admin/devices.php<?= $query ? '?' . htmlspecialchars(http_build_query($query), ENT_QUOTES) : '' ?>"><?= $label ?></a>
            <?php endforeach; ?>
        </div>
    </section>

    <?php if (empty($devices)): ?>
        <section class="devices-empty">
            <h2>No devices found</h2>
            <p>Activated POS terminals will appear here automatically.</p>
        </section>
    <?php else: ?>
        <section class="device-grid">
            <?php foreach ($devices as $d): ?>
                <article class="device-card <?= $d['is_active'] ? 'is-active' : 'is-inactive' ?>">
                    <header>
                        <div class="device-card-icon">
                            <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="4" y="3" width="16" height="14" rx="2"/><path d="M8 21h8M12 17v4"/></svg>
                        </div>
                        <div class="device-card-title">
                            <strong><?= htmlspecialchars($d['device_name'] ?: 'Unnamed POS device') ?></strong>
                            <span><?= htmlspecialchars($d['customer_name']) ?></span>
                        </div>
                        <span class="badge <?= $d['is_active'] ? 'badge-ok' : 'badge-expired' ?>"><?= $d['is_active'] ? 'Active' : 'Freed' ?></span>
                    </header>

                    <dl class="device-meta">
                        <div><dt>HWID</dt><dd><code dir="ltr"><?= htmlspecialchars($d['hwid']) ?></code></dd></div>
                        <div><dt>License</dt><dd><a href="/public/admin/license_detail.php?id=<?= (int)$d['license_id'] ?>"><code dir="ltr"><?= htmlspecialchars($d['license_key']) ?></code></a></dd></div>
                        <div><dt>Last seen</dt><dd><?= htmlspecialchars(date('M j, Y · H:i', strtotime($d['last_seen_at']))) ?></dd></div>
                        <div><dt>IP address</dt><dd dir="ltr"><?= htmlspecialchars($d['ip_address'] ?: '—') ?></dd></div>
                    </dl>

                    <?php if (!empty($d['admin_note'])): ?>
                        <p class="device-note" dir="auto"><?= htmlspecialchars($d['admin_note']) ?></p>
                    <?php endif; ?>

                    <footer>
                        <button type="button" class="table-btn" data-open-device-dialog="device-dialog-<?= (int)$d['id'] ?>" <?= !$deviceSchemaReady ? 'disabled' : '' ?>>Edit</button>
                        <?php if ($d['is_active']): ?>
                            <form method="post" onsubmit="return confirm('Free this activation slot? The current device will no longer validate until it activates again.');">
                                <?= Csrf::field() ?>
                                <input type="hidden" name="activation_id" value="<?= (int)$d['id'] ?>">
                                <button type="submit" name="action" value="reset_slot" class="device-reset-btn" <?= !$deviceSchemaReady ? 'disabled' : '' ?>>Reset slot</button>
                            </form>
                        <?php endif; ?>
                    </footer>
                </article>

                <?php if ($deviceSchemaReady): ?>
                <dialog class="app-dialog device-dialog" id="device-dialog-<?= (int)$d['id'] ?>">
                    <form method="post" class="device-edit-form">
                        <?= Csrf::field() ?>
                        <input type="hidden" name="activation_id" value="<?= (int)$d['id'] ?>">
                        <div class="dialog-header">
                            <div><p class="eyebrow">Device #<?= (int)$d['id'] ?></p><h2>Edit device</h2></div>
                            <button type="button" class="dialog-close" data-close-device-dialog aria-label="Close">×</button>
                        </div>
                        <div class="dialog-fields">
                            <label><span>Device name</span><input type="text" name="device_name" maxlength="100" value="<?= htmlspecialchars($d['device_name'] ?? '', ENT_QUOTES) ?>" placeholder="Example: Main Cashier"></label>
                            <label><span>Internal note</span><textarea name="admin_note" maxlength="255" rows="3" placeholder="Location, owner, or support note"><?= htmlspecialchars($d['admin_note'] ?? '') ?></textarea></label>
                            <div class="device-readonly-block"><span>HWID</span><code dir="ltr"><?= htmlspecialchars($d['hwid']) ?></code></div>
                        </div>
                        <div class="dialog-actions">
                            <button type="button" class="secondary-btn" data-close-device-dialog>Cancel</button>
                            <button type="submit" name="action" value="update_device" class="primary-btn">Save changes</button>
                        </div>
                    </form>
                </dialog>
                <?php endif; ?>
            <?php endforeach; ?>
        </section>

        <?php if ($totalPages > 1): ?>
            <nav class="app-pagination enhanced-pagination" aria-label="Device pages">
                <a href="<?= htmlspecialchars($pageUrl(max(1, $currentPage - 1)), ENT_QUOTES) ?>" class="<?= $currentPage <= 1 ? 'disabled' : '' ?>">Previous</a>
                <span>Page <?= $currentPage ?> of <?= $totalPages ?></span>
                <a href="<?= htmlspecialchars($pageUrl(min($totalPages, $currentPage + 1)), ENT_QUOTES) ?>" class="<?= $currentPage >= $totalPages ? 'disabled' : '' ?>">Next</a>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
</div>

<script>
(function () {
    document.querySelectorAll('[data-open-device-dialog]').forEach(function (button) {
        button.addEventListener('click', function () {
            var dialog = document.getElementById(button.dataset.openDeviceDialog);
            if (!dialog) return;
            if (typeof dialog.showModal === 'function') dialog.showModal();
            else dialog.setAttribute('open', '');
        });
    });
    document.querySelectorAll('[data-close-device-dialog]').forEach(function (button) {
        button.addEventListener('click', function () {
            var dialog = button.closest('dialog');
            if (!dialog) return;
            if (typeof dialog.close === 'function') dialog.close();
            else dialog.removeAttribute('open');
        });
    });
    document.querySelectorAll('.device-dialog').forEach(function (dialog) {
        dialog.addEventListener('click', function (event) {
            if (event.target === dialog && typeof dialog.close === 'function') dialog.close();
        });
    });
})();
</script>
<?php render_footer(); ?>
