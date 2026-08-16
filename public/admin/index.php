<?php
require_once __DIR__ . '/includes/bootstrap.php';
Auth::require();

$pdo = Database::pdo();

$totalLicenses = (int) $pdo->query("SELECT COUNT(*) FROM licenses")->fetchColumn();
$activeLicenses = (int) $pdo->query("SELECT COUNT(*) FROM licenses WHERE status = 'active'")->fetchColumn();
$expiredLicenses = (int) $pdo->query("SELECT COUNT(*) FROM licenses WHERE status = 'expired'")->fetchColumn();
$totalCustomers = (int) $pdo->query("SELECT COUNT(*) FROM customers")->fetchColumn();

$soon = (new DateTime())->modify('+7 days')->format('Y-m-d H:i:s');
$now = (new DateTime())->format('Y-m-d H:i:s');
$stmt = $pdo->prepare(
    "SELECT l.*, c.name AS customer_name FROM licenses l
     JOIN customers c ON c.id = l.customer_id
     WHERE l.status = 'active' AND l.expires_at IS NOT NULL AND l.expires_at BETWEEN ? AND ?
     ORDER BY l.expires_at ASC LIMIT 10"
);
$stmt->execute([$now, $soon]);
$expiringSoon = $stmt->fetchAll();

$recentActivity = $pdo->query(
    "SELECT * FROM verification_log ORDER BY created_at DESC LIMIT 6"
)->fetchAll();

$activeRate = $totalLicenses > 0 ? (int) round(($activeLicenses / $totalLicenses) * 100) : 0;

render_header('Dashboard');
flash_render();
?>

<div class="dashboard-page">
    <section class="dashboard-hero">
        <div>
            <p class="eyebrow">Overview</p>
            <h1>Dashboard</h1>
            <p class="page-subtitle">Your license business at a glance.</p>
        </div>
        <div class="quick-actions" aria-label="Quick actions">
            <a href="/public/admin/customers.php" class="quick-action secondary">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>
                <span>Customer</span>
            </a>
            <a href="/public/admin/licenses.php" class="quick-action primary">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h10a4 4 0 0 1 0 8H9l-3 3v-3H4a4 4 0 0 1 0-8z"/><circle cx="14" cy="11" r="1"/></svg>
                <span>Issue license</span>
            </a>
        </div>
    </section>

    <section class="metric-grid" aria-label="License statistics">
        <article class="metric-card metric-primary">
            <div class="metric-icon">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h10a4 4 0 0 1 0 8H9l-3 3v-3H4a4 4 0 0 1 0-8z"/><circle cx="14" cy="11" r="1"/></svg>
            </div>
            <span class="metric-label">Total licenses</span>
            <strong><?= $totalLicenses ?></strong>
            <span class="metric-note"><?= $activeRate ?>% currently active</span>
        </article>
        <article class="metric-card">
            <div class="metric-icon success">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m5 12 4 4 10-10"/></svg>
            </div>
            <span class="metric-label">Active</span>
            <strong><?= $activeLicenses ?></strong>
            <span class="metric-note">Ready to verify</span>
        </article>
        <article class="metric-card">
            <div class="metric-icon danger">
                <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="8"/><path d="M12 8v5M12 16h.01"/></svg>
            </div>
            <span class="metric-label">Expired</span>
            <strong><?= $expiredLicenses ?></strong>
            <span class="metric-note">Needs attention</span>
        </article>
        <article class="metric-card">
            <div class="metric-icon neutral">
                <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="9" cy="8" r="3"/><path d="M3.5 20c.2-4 2-6 5.5-6s5.3 2 5.5 6M16 5.5a3 3 0 0 1 0 5.8M16 14c3 0 4.5 2 4.5 5"/></svg>
            </div>
            <span class="metric-label">Customers</span>
            <strong><?= $totalCustomers ?></strong>
            <span class="metric-note">Total accounts</span>
        </article>
    </section>

    <div class="dashboard-columns">
        <section class="dashboard-section attention-section" id="expiring-soon">
            <div class="section-heading">
                <div>
                    <p class="eyebrow">Needs attention</p>
                    <h2>Expiring soon</h2>
                </div>
                <span class="section-count"><?= count($expiringSoon) ?></span>
            </div>

            <?php if (empty($expiringSoon)): ?>
                <div class="empty-state compact">
                    <span class="empty-icon">✓</span>
                    <div><strong>Everything looks good</strong><p>No licenses expire in the next 7 days.</p></div>
                </div>
            <?php else: ?>
                <div class="dashboard-list">
                    <?php foreach ($expiringSoon as $l): ?>
                        <a class="license-row" href="/public/admin/license_detail.php?id=<?= $l['id'] ?>">
                            <span class="list-avatar"><?= strtoupper(htmlspecialchars(substr($l['customer_name'], 0, 1))) ?></span>
                            <span class="list-copy">
                                <strong dir="auto"><?= htmlspecialchars($l['customer_name']) ?></strong>
                                <small class="mono" dir="ltr"><?= htmlspecialchars($l['license_key']) ?></small>
                            </span>
                            <span class="list-meta">
                                <strong><?= htmlspecialchars(date('M j', strtotime($l['expires_at']))) ?></strong>
                                <small><?= htmlspecialchars($l['plan']) ?></small>
                            </span>
                            <svg class="row-chevron" viewBox="0 0 24 24" aria-hidden="true"><path d="m9 6 6 6-6 6"/></svg>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <section class="dashboard-section activity-section">
            <div class="section-heading">
                <div>
                    <p class="eyebrow">Live status</p>
                    <h2>Recent activity</h2>
                </div>
                <span class="live-indicator"><i></i>Live</span>
            </div>

            <?php if (empty($recentActivity)): ?>
                <div class="empty-state compact">
                    <span class="empty-icon">—</span>
                    <div><strong>No activity yet</strong><p>Verification attempts will appear here.</p></div>
                </div>
            <?php else: ?>
                <div class="dashboard-list activity-list">
                    <?php foreach ($recentActivity as $a): ?>
                        <?php $isOk = ($a['result'] ?? '') === 'ok'; ?>
                        <div class="activity-row">
                            <span class="activity-status <?= $isOk ? 'ok' : 'failed' ?>">
                                <?= $isOk ? '✓' : '!' ?>
                            </span>
                            <span class="list-copy">
                                <strong><?= $isOk ? 'Verification successful' : htmlspecialchars(str_replace('_', ' ', $a['result'])) ?></strong>
                                <small class="mono" dir="ltr"><?= htmlspecialchars($a['license_key']) ?></small>
                            </span>
                            <span class="list-meta">
                                <strong dir="ltr"><?= htmlspecialchars($a['ip_address'] ?? '—') ?></strong>
                                <small><?= htmlspecialchars(date('M j, H:i', strtotime($a['created_at']))) ?></small>
                            </span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    </div>
</div>

<?php render_footer(); ?>
