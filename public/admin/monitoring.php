<?php
require_once __DIR__ . '/includes/bootstrap.php';
Auth::require();
// Monitoring exposes license keys, HWIDs, IP addresses, recovery counts and
// security events. Keep it behind an operational permission instead of making
// those details visible to read-only administrators.
Auth::requirePermission('licenses.manage');

$pdo = Database::pdo();

$dbStart = microtime(true);
$pdo->query('SELECT 1')->fetchColumn();
$dbLatencyMs = round((microtime(true) - $dbStart) * 1000, 1);

$metrics = [
    'active_devices' => (int)$pdo->query("SELECT COUNT(*) FROM license_activations WHERE is_active = 1")->fetchColumn(),
    'online_devices' => (int)$pdo->query("SELECT COUNT(*) FROM license_activations WHERE is_active = 1 AND last_seen_at >= DATE_SUB(NOW(), INTERVAL 5 MINUTE)")->fetchColumn(),
    'failed_validations_24h' => (int)$pdo->query("SELECT COUNT(*) FROM verification_log WHERE result <> 'ok' AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)")->fetchColumn(),
    'pending_recovery' => (int)$pdo->query("SELECT COUNT(*) FROM password_recovery_requests WHERE status = 'pending'")->fetchColumn(),
    'push_subscriptions' => (int)$pdo->query("SELECT COUNT(*) FROM push_subscriptions")->fetchColumn(),
    'expiring_7d' => (int)$pdo->query("SELECT COUNT(*) FROM licenses WHERE status = 'active' AND expires_at BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 7 DAY)")->fetchColumn(),
    'api_requests_1h' => (int)$pdo->query("SELECT COUNT(*) FROM api_requests WHERE created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)")->fetchColumn(),
    'audit_events_24h' => (int)$pdo->query("SELECT COUNT(*) FROM admin_audit_log WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)")->fetchColumn(),
];

$recentFailures = $pdo->query(
    "SELECT vl.result, vl.license_key, vl.hwid, vl.ip_address, vl.created_at, c.name AS customer_name
     FROM verification_log vl
     LEFT JOIN licenses l ON l.id = vl.license_id
     LEFT JOIN customers c ON c.id = l.customer_id
     WHERE vl.result <> 'ok'
     ORDER BY vl.created_at DESC
     LIMIT 12"
)->fetchAll();

$recentDevices = $pdo->query(
    "SELECT a.id, a.hwid, a.last_seen_at, a.ip_address, a.is_active,
            l.id AS license_id, l.license_key, c.name AS customer_name
     FROM license_activations a
     JOIN licenses l ON l.id = a.license_id
     JOIN customers c ON c.id = l.customer_id
     ORDER BY a.last_seen_at DESC
     LIMIT 12"
)->fetchAll();

$generatedAt = date('Y-m-d H:i:s');
$dbState = $dbLatencyMs < 100 ? 'Healthy' : ($dbLatencyMs < 300 ? 'Elevated' : 'Slow');

render_header('Monitoring');
?>
<div class="monitoring-page">
    <section class="page-hero">
        <div>
            <p class="eyebrow">Operations</p>
            <h1>System Monitoring</h1>
            <p class="page-subtitle">Live operational signals for devices, licensing, recovery, push subscriptions, API traffic, and database health.</p>
        </div>
        <div class="monitoring-updated">Updated <?= htmlspecialchars($generatedAt) ?></div>
    </section>

    <section class="monitor-health-strip">
        <article><span>Database</span><strong><?= htmlspecialchars($dbState) ?></strong><small><?= htmlspecialchars((string)$dbLatencyMs) ?> ms</small></article>
        <article><span>PHP runtime</span><strong><?= htmlspecialchars(PHP_VERSION) ?></strong><small><?= htmlspecialchars(PHP_SAPI) ?></small></article>
        <article><span>Push subscribers</span><strong><?= $metrics['push_subscriptions'] ?></strong><small>browser endpoints</small></article>
        <article><span>API traffic</span><strong><?= $metrics['api_requests_1h'] ?></strong><small>requests / 1h</small></article>
    </section>

    <section class="monitor-metric-grid">
        <article><span>Online devices</span><strong><?= $metrics['online_devices'] ?></strong><small>seen in last 5 min</small></article>
        <article><span>Active devices</span><strong><?= $metrics['active_devices'] ?></strong><small>bound activation slots</small></article>
        <article class="<?= $metrics['failed_validations_24h'] > 0 ? 'metric-warning' : '' ?>"><span>Failed validations</span><strong><?= $metrics['failed_validations_24h'] ?></strong><small>last 24 hours</small></article>
        <article class="<?= $metrics['pending_recovery'] > 0 ? 'metric-warning' : '' ?>"><span>Pending recovery</span><strong><?= $metrics['pending_recovery'] ?></strong><small>awaiting review</small></article>
        <article class="<?= $metrics['expiring_7d'] > 0 ? 'metric-warning' : '' ?>"><span>Expiring licenses</span><strong><?= $metrics['expiring_7d'] ?></strong><small>within 7 days</small></article>
        <article><span>Audit events</span><strong><?= $metrics['audit_events_24h'] ?></strong><small>last 24 hours</small></article>
    </section>

    <div class="monitoring-columns">
        <section class="monitor-panel">
            <div class="section-heading"><div><p class="eyebrow">Security signal</p><h2>Recent validation failures</h2></div><span class="section-count"><?= count($recentFailures) ?></span></div>
            <?php if (!$recentFailures): ?>
                <div class="monitor-empty">No recent validation failures.</div>
            <?php else: ?>
                <div class="monitor-list">
                    <?php foreach ($recentFailures as $row): ?>
                        <article>
                            <span class="monitor-status warning">!</span>
                            <div><strong><?= htmlspecialchars(str_replace('_', ' ', $row['result'])) ?></strong><small><?= htmlspecialchars($row['customer_name'] ?: 'Unknown customer') ?> · <?= htmlspecialchars(date('M j H:i', strtotime($row['created_at']))) ?></small><code dir="ltr"><?= htmlspecialchars($row['license_key']) ?></code></div>
                            <span class="monitor-ip" dir="ltr"><?= htmlspecialchars($row['ip_address'] ?: '—') ?></span>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <section class="monitor-panel">
            <div class="section-heading"><div><p class="eyebrow">Hardware signal</p><h2>Recently seen devices</h2></div><span class="section-count"><?= count($recentDevices) ?></span></div>
            <?php if (!$recentDevices): ?>
                <div class="monitor-empty">No device activity yet.</div>
            <?php else: ?>
                <div class="monitor-list">
                    <?php foreach ($recentDevices as $row): ?>
                        <?php $online = strtotime($row['last_seen_at']) >= time() - 300; ?>
                        <article>
                            <span class="monitor-status <?= $online ? 'ok' : '' ?>"><?= $online ? '✓' : '·' ?></span>
                            <div><strong><?= htmlspecialchars($row['customer_name']) ?></strong><small><?= $online ? 'Online' : 'Last seen ' . htmlspecialchars(date('M j H:i', strtotime($row['last_seen_at']))) ?></small><a href="/public/admin/license_detail.php?id=<?= (int)$row['license_id'] ?>"><code dir="ltr"><?= htmlspecialchars($row['hwid']) ?></code></a></div>
                            <span class="monitor-ip" dir="ltr"><?= htmlspecialchars($row['ip_address'] ?: '—') ?></span>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    </div>
</div>
<?php render_footer(); ?>
