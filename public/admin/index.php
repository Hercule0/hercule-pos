<?php
require_once __DIR__ . '/includes/bootstrap.php';
Auth::require();

$pdo = Database::pdo();

// ── Live stats ──────────────────────────────────────────────────────────────
$totalLicenses   = (int) $pdo->query("SELECT COUNT(*) FROM licenses")->fetchColumn();
$activeLicenses  = (int) $pdo->query("SELECT COUNT(*) FROM licenses WHERE status = 'active'")->fetchColumn();
$expiredLicenses = (int) $pdo->query("SELECT COUNT(*) FROM licenses WHERE status = 'expired'")->fetchColumn();
$totalCustomers  = (int) $pdo->query("SELECT COUNT(*) FROM customers")->fetchColumn();
$totalDevices    = (int) $pdo->query("SELECT COUNT(*) FROM license_activations WHERE is_active = 1")->fetchColumn();
$pendingRecovery = (int) $pdo->query("SELECT COUNT(*) FROM password_recovery_requests WHERE status = 'pending'")->fetchColumn();

$expiringSoon = (int) $pdo->query(
    "SELECT COUNT(*) FROM licenses WHERE status = 'active' AND expires_at IS NOT NULL AND expires_at BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 30 DAY)"
)->fetchColumn();

// ── Recent licenses ──────────────────────────────────────────────────────────
$recentLicenses = $pdo->query(
    "SELECT l.id, l.license_key, l.plan, l.status, l.max_activations, l.expires_at, l.created_at,
            c.name AS customer_name,
            (SELECT COUNT(*) FROM license_activations a WHERE a.license_id = l.id AND a.is_active = 1) AS active_devices
     FROM licenses l
     LEFT JOIN customers c ON c.id = l.customer_id
     ORDER BY l.created_at DESC
     LIMIT 10"
)->fetchAll();

// ── Pending recovery requests ────────────────────────────────────────────────
$pendingRecoveries = $pdo->query(
    "SELECT id, license_key, hwid, requested_username AS username, created_at
     FROM password_recovery_requests
     WHERE status = 'pending'
     ORDER BY created_at DESC
     LIMIT 5"
)->fetchAll();

// ── Expiring soon ─────────────────────────────────────────────────────────────
$expiringSoonList = $pdo->query(
    "SELECT l.id, l.license_key, l.plan, l.expires_at, c.name AS customer_name
     FROM licenses l
     LEFT JOIN customers c ON c.id = l.customer_id
     WHERE l.status = 'active' AND l.expires_at IS NOT NULL
       AND l.expires_at BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 30 DAY)
     ORDER BY l.expires_at ASC
     LIMIT 5"
)->fetchAll();

// ── Recent verifications ─────────────────────────────────────────────────────
$recentVerifications = $pdo->query(
    "SELECT license_key, hwid, result, ip_address, created_at
     FROM verification_log
     ORDER BY created_at DESC
     LIMIT 8"
)->fetchAll();

render_header('Dashboard');
?>
<div class="dashboard-wrap">

    <div class="page-header-bar">
        <div>
            <h1 class="page-title">Dashboard</h1>
            <p class="page-subtitle">Real-time POS license server — live data from database</p>
        </div>
        <?php if (Auth::can('licenses.create')): ?>
        <div class="page-header-actions">
            <a href="/public/admin/licenses.php?action=new" class="btn btn-primary">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>
                Issue License
            </a>
            <a href="/public/admin/customers.php?action=new" class="btn btn-secondary">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>
                New Customer
            </a>
        </div>
        <?php endif; ?>
    </div>

    <?php if ($pendingRecovery > 0): ?>
    <div class="alert-banner alert-banner-warning">
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0zM12 9v4M12 17h.01"/></svg>
        <div>
            <strong><?= $pendingRecovery ?> Pending Password Recovery Request<?= $pendingRecovery > 1 ? 's' : '' ?></strong>
            <span>Offline store terminals are waiting for admin authorization tokens to unlock supervisor access.</span>
        </div>
        <a href="/public/admin/recovery_requests.php" class="btn btn-warning btn-sm">Review Requests</a>
    </div>
    <?php endif; ?>

    <div class="stats-grid">
        <div class="stat-card stat-card-blue">
            <div class="stat-card-header">
                <span class="stat-label">Active Licenses</span>
                <svg class="stat-icon" viewBox="0 0 24 24" aria-hidden="true"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 7V5a2 2 0 0 0-4 0v2M12 12v3M10 15h4"/></svg>
            </div>
            <div class="stat-value"><?= $activeLicenses ?> <span class="stat-sub">/ <?= $totalLicenses ?> total</span></div>
            <div class="stat-footer"><?= $expiredLicenses ?> expired</div>
        </div>

        <div class="stat-card stat-card-emerald">
            <div class="stat-card-header">
                <span class="stat-label">Bound Devices</span>
                <svg class="stat-icon" viewBox="0 0 24 24" aria-hidden="true"><rect x="2" y="3" width="20" height="14" rx="2"/><path d="M8 21h8M12 17v4"/></svg>
            </div>
            <div class="stat-value"><?= $totalDevices ?></div>
            <div class="stat-footer">active hardware IDs</div>
        </div>

        <div class="stat-card stat-card-amber">
            <div class="stat-card-header">
                <span class="stat-label">Expiring in 30d</span>
                <svg class="stat-icon" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="8"/><path d="M12 8v5l3 2"/></svg>
            </div>
            <div class="stat-value"><?= $expiringSoon ?></div>
            <div class="stat-footer">subscriptions need renewal</div>
        </div>

        <div class="stat-card stat-card-purple">
            <div class="stat-card-header">
                <span class="stat-label">Customers</span>
                <svg class="stat-icon" viewBox="0 0 24 24" aria-hidden="true"><circle cx="9" cy="8" r="3"/><path d="M3.5 20c.2-4 2-6 5.5-6s5.3 2 5.5 6M16 5.5a3 3 0 0 1 0 5.8M16 14c3 0 4.5 2 4.5 5"/></svg>
            </div>
            <div class="stat-value"><?= $totalCustomers ?></div>
            <div class="stat-footer">merchant accounts</div>
        </div>
    </div>

    <div class="dashboard-grid">
        <section class="panel panel-wide">
            <div class="panel-header">
                <h2 class="panel-title">Recent Licenses</h2>
                <a href="/public/admin/licenses.php" class="panel-link">View all →</a>
            </div>
            <div class="modern-table-wrapper">
                <table class="modern-table">
                    <thead>
                        <tr>
                            <th>License Key</th>
                            <th>Customer</th>
                            <th>Plan</th>
                            <th>Devices</th>
                            <th>Status</th>
                            <th>Expires</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($recentLicenses)): ?>
                        <tr><td colspan="6" class="table-empty">No licenses yet. <a href="/public/admin/licenses.php?action=new">Issue one →</a></td></tr>
                        <?php else: ?>
                        <?php foreach ($recentLicenses as $lic): ?>
                        <?php
                            $badgeClass = 'badge-ok';
                            if ($lic['status'] === 'suspended' || $lic['status'] === 'expired') $badgeClass = 'badge-expired';
                            if ($lic['status'] === 'revoked') $badgeClass = 'badge-revoked';
                        ?>
                        <tr>
                            <td data-label="License Key">
                                <div class="cell-main">
                                    <a href="/public/admin/license_detail.php?id=<?= $lic['id'] ?>" class="license-key-link dashboard-license-link">
                                        <code dir="ltr" class="dashboard-license-code"><?= htmlspecialchars($lic['license_key']) ?></code>
                                    </a>
                                </div>
                            </td>
                            <td data-label="Customer">
                                <div class="cell-main">
                                    <strong><?= htmlspecialchars($lic['customer_name'] ?? '—') ?></strong>
                                </div>
                            </td>
                            <td data-label="Plan"><span class="dashboard-dim dashboard-plan"><?= htmlspecialchars(str_replace('_', ' ', $lic['plan'])) ?></span></td>
                            <td data-label="Devices"><span class="dashboard-dim"><?= (int)$lic['active_devices'] ?> / <?= (int)$lic['max_activations'] ?></span></td>
                            <td data-label="Status">
                                <span class="badge <?= $badgeClass ?>"><?= htmlspecialchars(ucfirst($lic['status'])) ?></span>
                            </td>
                            <td data-label="Expires">
                                <span class="dashboard-dim <?= (!empty($lic['expires_at']) && strtotime($lic['expires_at']) < strtotime('+30 days')) ? 'text-amber' : '' ?>">
                                    <?= $lic['expires_at'] ? date('M j, Y', strtotime($lic['expires_at'])) : 'Lifetime' ?>
                                </span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <div class="dashboard-side">
            <?php if (!empty($expiringSoonList)): ?>
            <section class="panel panel-alert" id="expiring-soon">
                <div class="panel-header">
                    <h2 class="panel-title">⚠ Expiring Soon</h2>
                    <a href="/public/admin/licenses.php" class="panel-link">Manage →</a>
                </div>
                <ul class="expiry-list">
                    <?php foreach ($expiringSoonList as $e): ?>
                    <li class="expiry-item">
                        <div class="expiry-key"><?= htmlspecialchars($e['license_key']) ?></div>
                        <div class="expiry-customer"><?= htmlspecialchars($e['customer_name'] ?? '—') ?></div>
                        <div class="expiry-date text-amber"><?= date('M j', strtotime($e['expires_at'])) ?></div>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </section>
            <?php endif; ?>

            <?php if (!empty($pendingRecoveries)): ?>
            <section class="panel panel-alert">
                <div class="panel-header">
                    <h2 class="panel-title">🚨 Pending Recovery</h2>
                    <a href="/public/admin/recovery_requests.php" class="panel-link">Review →</a>
                </div>
                <ul class="expiry-list">
                    <?php foreach ($pendingRecoveries as $r): ?>
                    <li class="expiry-item">
                        <div class="expiry-key"><?= htmlspecialchars($r['license_key']) ?></div>
                        <div class="expiry-customer">User: <?= htmlspecialchars($r['username'] ?? '—') ?></div>
                        <div class="expiry-date"><?= date('M j H:i', strtotime($r['created_at'])) ?></div>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </section>
            <?php endif; ?>

            <section class="panel">
                <div class="panel-header">
                    <h2 class="panel-title">Recent Validations</h2>
                </div>
                <?php if (empty($recentVerifications)): ?>
                <p class="panel-empty">No validations yet.</p>
                <?php else: ?>
                <ul class="verif-list">
                    <?php foreach ($recentVerifications as $v): ?>
                    <li class="verif-item verif-<?= $v['result'] === 'ok' ? 'ok' : 'fail' ?>">
                        <span class="verif-dot"></span>
                        <div class="verif-detail">
                            <span class="verif-key"><?= htmlspecialchars(substr($v['license_key'], 0, 14)) ?>…</span>
                            <span class="verif-result"><?= htmlspecialchars($v['result']) ?></span>
                        </div>
                        <span class="verif-time"><?= date('H:i', strtotime($v['created_at'])) ?></span>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>
            </section>
        </div>
    </div>
</div>

<?php render_footer(); ?>
