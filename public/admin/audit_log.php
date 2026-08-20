<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/../../includes/AuditLog.php';
Auth::require();
Auth::requirePermission('admins.manage');

$pdo = Database::pdo();
$search = mb_substr(trim($_GET['q'] ?? ''), 0, 100);
$action = mb_substr(trim($_GET['action'] ?? ''), 0, 40);
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 40;

$where = [];
$params = [];
if ($search !== '') {
    $where[] = '(au.username LIKE ? OR aal.action LIKE ? OR aal.details LIKE ? OR aal.ip_address LIKE ?)';
    $pattern = '%' . $search . '%';
    array_push($params, $pattern, $pattern, $pattern, $pattern);
}
if ($action !== '') {
    $where[] = 'aal.action = ?';
    $params[] = $action;
}
$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

$count = $pdo->prepare('SELECT COUNT(*) FROM admin_audit_log aal LEFT JOIN admin_users au ON au.id = aal.actor_id' . $whereSql);
$count->execute($params);
$total = (int)$count->fetchColumn();
$totalPages = max(1, (int)ceil($total / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$sql = 'SELECT aal.*, au.username AS actor_username
        FROM admin_audit_log aal
        LEFT JOIN admin_users au ON au.id = aal.actor_id' . $whereSql .
       ' ORDER BY aal.created_at DESC, aal.id DESC LIMIT ? OFFSET ?';
$stmt = $pdo->prepare($sql);
$i = 1;
foreach ($params as $value) $stmt->bindValue($i++, $value, PDO::PARAM_STR);
$stmt->bindValue($i++, $perPage, PDO::PARAM_INT);
$stmt->bindValue($i, $offset, PDO::PARAM_INT);
$stmt->execute();
$rows = $stmt->fetchAll();

$actions = $pdo->query('SELECT action, COUNT(*) AS count FROM admin_audit_log GROUP BY action ORDER BY count DESC, action ASC')->fetchAll();
$todayCount = (int)$pdo->query("SELECT COUNT(*) FROM admin_audit_log WHERE created_at >= CURDATE()")->fetchColumn();
$sevenDayCount = (int)$pdo->query("SELECT COUNT(*) FROM admin_audit_log WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)")->fetchColumn();

$pageUrl = static function(int $target) use ($search, $action): string {
    $q = ['page' => $target];
    if ($search !== '') $q['q'] = $search;
    if ($action !== '') $q['action'] = $action;
    return '/public/admin/audit_log.php?' . http_build_query($q);
};

render_header('Audit Log');
flash_render();
?>
<div class="audit-page">
    <section class="page-hero">
        <div>
            <p class="eyebrow">Security & accountability</p>
            <h1>Audit Log</h1>
            <p class="page-subtitle">Trace administrative actions, security events, affected records, IP addresses, and timestamps.</p>
        </div>
    </section>

    <section class="audit-summary-grid">
        <article><span>Total events</span><strong><?= $total ?></strong></article>
        <article><span>Today</span><strong><?= $todayCount ?></strong></article>
        <article><span>Last 7 days</span><strong><?= $sevenDayCount ?></strong></article>
    </section>

    <section class="audit-tools">
        <form method="get" class="audit-search-form">
            <?php if ($action !== ''): ?><input type="hidden" name="action" value="<?= htmlspecialchars($action, ENT_QUOTES) ?>"><?php endif; ?>
            <label class="app-search" for="audit-search">
                <input id="audit-search" name="q" type="search" value="<?= htmlspecialchars($search, ENT_QUOTES) ?>" placeholder="Search actor, action, detail, or IP" autocomplete="off">
                <kbd><?= count($rows) ?></kbd>
            </label>
        </form>
        <form method="get" class="audit-action-filter">
            <?php if ($search !== ''): ?><input type="hidden" name="q" value="<?= htmlspecialchars($search, ENT_QUOTES) ?>"><?php endif; ?>
            <select name="action" onchange="this.form.submit()">
                <option value="">All actions</option>
                <?php foreach ($actions as $item): ?>
                    <option value="<?= htmlspecialchars($item['action'], ENT_QUOTES) ?>" <?= $action === $item['action'] ? 'selected' : '' ?>><?= htmlspecialchars(str_replace('_', ' ', $item['action'])) ?> (<?= (int)$item['count'] ?>)</option>
                <?php endforeach; ?>
            </select>
        </form>
    </section>

    <?php if (!$rows): ?>
        <section class="audit-empty"><h2>No audit events found</h2><p>Administrative actions will appear here as they occur.</p></section>
    <?php else: ?>
        <div class="modern-table-wrapper">
            <table class="modern-table audit-table">
                <thead><tr><th>Time</th><th>Actor</th><th>Action</th><th>Target</th><th>Details</th><th>IP</th></tr></thead>
                <tbody>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td data-label="Time"><strong><?= htmlspecialchars(date('M j, Y H:i', strtotime($row['created_at']))) ?></strong></td>
                        <td data-label="Actor"><?= htmlspecialchars($row['actor_username'] ?: 'System / unknown') ?></td>
                        <td data-label="Action"><span class="audit-action-pill"><?= htmlspecialchars(str_replace('_', ' ', $row['action'])) ?></span></td>
                        <td data-label="Target"><?= $row['target_id'] !== null ? '#' . (int)$row['target_id'] : '—' ?></td>
                        <td data-label="Details" class="audit-details" dir="auto"><?= htmlspecialchars($row['details'] ?: '—') ?></td>
                        <td data-label="IP"><code dir="ltr"><?= htmlspecialchars($row['ip_address'] ?: '—') ?></code></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if ($totalPages > 1): ?>
            <nav class="app-pagination enhanced-pagination" aria-label="Audit pages">
                <a href="<?= htmlspecialchars($pageUrl(max(1, $page - 1)), ENT_QUOTES) ?>" class="<?= $page <= 1 ? 'disabled' : '' ?>">Previous</a>
                <span>Page <?= $page ?> of <?= $totalPages ?></span>
                <a href="<?= htmlspecialchars($pageUrl(min($totalPages, $page + 1)), ENT_QUOTES) ?>" class="<?= $page >= $totalPages ? 'disabled' : '' ?>">Next</a>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
</div>
<?php render_footer(); ?>
