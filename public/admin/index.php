<?php
require_once __DIR__ . '/includes/bootstrap.php';
Auth::require();

$pdo = Database::pdo();

// ── Live stats ──────────────────────────────────────────────────────────────
$totalLicenses   = (int) $pdo->query("SELECT COUNT(*) FROM licenses")->fetchColumn();
$activeLicenses  = (int) $pdo->query("SELECT COUNT(*) FROM licenses WHERE status = 'active'")->fetchColumn();
$expiredLicenses = (int) $pdo->query("SELECT COUNT(*) FROM licenses WHERE status = 'expired'")->fetchColumn();
$totalCustomers  = (int) $pdo->query("SELECT COUNT(*) FROM customers")->fetchColumn();
$totalDevices    = (int) $pdo->query("SELECT COUNT(*) FROM license_activations WHERE status = 'active'")->fetchColumn();
$pendingRecovery = (int) $pdo->query("SELECT COUNT(*) FROM password_recovery_requests WHERE status = 'pending'")->fetchColumn();

$expiringSoon = (int) $pdo->query(
    "SELECT COUNT(*) FROM licenses WHERE status = 'active' AND expires_at IS NOT NULL AND expires_at BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 30 DAY)"
)->fetchColumn();

// ── Recent licenses ──────────────────────────────────────────────────────────
$recentLicenses = $pdo->query(
    "SELECT l.id, l.license_key, l.plan, l.status, l.max_activations, l.expires_at, l.created_at,
            c.name AS customer_name,
            (SELECT COUNT(*) FROM license_activations a WHERE a.license_id = l.id AND a.status = 'active') AS active_devices
     FROM licenses l
     LEFT JOIN customers c ON c.id = l.customer_id
     ORDER BY l.created_at DESC
     LIMIT 10"
)->fetchAll();

// ── Pending recovery requests ────────────────────────────────────────────────
$pendingRecoveries = $pdo->query(
    "SELECT id, license_key, hwid, username, created_at
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

    <!-- ── KPI Cards ── -->
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
        <!-- ── Recent Licenses ── -->
        <section class="panel panel-wide">
            <div class="panel-header">
                <h2 class="panel-title">Recent Licenses</h2>
                <a href="/public/admin/licenses.php" class="panel-link">View all →</a>
            </div>
            <div class="table-scroll">
                <table class="data-table">
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
                        <tr>
                            <td>
                                <a href="/public/admin/license_detail.php?id=<?= $lic['id'] ?>" class="license-key-link">
                                    <?= htmlspecialchars($lic['license_key']) ?>
                                </a>
                            </td>
                            <td><?= htmlspecialchars($lic['customer_name'] ?? '—') ?></td>
                            <td><span class="badge badge-plan"><?= htmlspecialchars($lic['plan']) ?></span></td>
                            <td><?= (int)$lic['active_devices'] ?> / <?= (int)$lic['max_activations'] ?></td>
                            <td>
                                <span class="status-badge status-<?= htmlspecialchars($lic['status']) ?>">
                                    <?= htmlspecialchars(ucfirst($lic['status'])) ?>
                                </span>
                            </td>
                            <td class="<?= (!empty($lic['expires_at']) && strtotime($lic['expires_at']) < strtotime('+30 days')) ? 'text-amber' : '' ?>">
                                <?= $lic['expires_at'] ? date('M j, Y', strtotime($lic['expires_at'])) : 'Lifetime' ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- ── Right column ── -->
        <div class="dashboard-side">

            <!-- Expiring Soon -->
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

            <!-- Pending Recovery -->
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

            <!-- Recent Verifications -->
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
</main>

<style>
.page-header-bar { display:flex; align-items:flex-start; justify-content:space-between; gap:1rem; margin-bottom:1.5rem; flex-wrap:wrap; }
.page-title { font-size:1.6rem; font-weight:700; color:#f0f4ff; margin:0 0 .25rem; }
.page-subtitle { color:#8b95a8; font-size:.875rem; margin:0; }
.page-header-actions { display:flex; gap:.75rem; }

.alert-banner { display:flex; align-items:center; gap:1rem; padding:1rem 1.25rem; border-radius:10px; margin-bottom:1.5rem; flex-wrap:wrap; }
.alert-banner svg { width:1.25rem; height:1.25rem; flex-shrink:0; stroke:currentColor; fill:none; stroke-width:2; stroke-linecap:round; stroke-linejoin:round; }
.alert-banner-warning { background:rgba(245,158,11,.12); border:1px solid rgba(245,158,11,.3); color:#fbbf24; }
.alert-banner-warning strong { display:block; color:#fcd34d; }
.alert-banner-warning span { font-size:.85rem; color:#fde68a; }
.btn { display:inline-flex; align-items:center; gap:.4rem; padding:.55rem 1.1rem; border-radius:8px; font-size:.85rem; font-weight:600; text-decoration:none; border:none; cursor:pointer; transition:opacity .15s; }
.btn svg { width:1rem; height:1rem; stroke:currentColor; fill:none; stroke-width:2.5; stroke-linecap:round; stroke-linejoin:round; }
.btn:hover { opacity:.85; }
.btn-primary { background:#3b82f6; color:#fff; }
.btn-secondary { background:rgba(255,255,255,.08); color:#e2e8f0; border:1px solid rgba(255,255,255,.12); }
.btn-warning { background:#d97706; color:#fff; }
.btn-sm { padding:.35rem .75rem; font-size:.8rem; }

.stats-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(200px,1fr)); gap:1rem; margin-bottom:1.5rem; }
.stat-card { background:rgba(255,255,255,.04); border:1px solid rgba(255,255,255,.08); border-radius:12px; padding:1.25rem; }
.stat-card-blue { border-left:3px solid #3b82f6; }
.stat-card-emerald { border-left:3px solid #10b981; }
.stat-card-amber { border-left:3px solid #f59e0b; }
.stat-card-purple { border-left:3px solid #8b5cf6; }
.stat-card-header { display:flex; justify-content:space-between; align-items:center; margin-bottom:.75rem; }
.stat-label { font-size:.78rem; font-weight:600; color:#8b95a8; text-transform:uppercase; letter-spacing:.06em; }
.stat-icon { width:1.3rem; height:1.3rem; stroke:rgba(255,255,255,.25); fill:none; stroke-width:1.8; stroke-linecap:round; stroke-linejoin:round; }
.stat-value { font-size:2rem; font-weight:700; color:#f0f4ff; line-height:1; margin-bottom:.35rem; }
.stat-sub { font-size:1rem; font-weight:400; color:#8b95a8; }
.stat-footer { font-size:.78rem; color:#6b7280; }

.dashboard-grid { display:grid; grid-template-columns:1fr 340px; gap:1.25rem; }
@media(max-width:1024px){ .dashboard-grid { grid-template-columns:1fr; } }
.dashboard-side { display:flex; flex-direction:column; gap:1.25rem; }

.panel { background:rgba(255,255,255,.04); border:1px solid rgba(255,255,255,.08); border-radius:12px; overflow:hidden; }
.panel-wide { min-width:0; }
.panel-alert { border-color:rgba(245,158,11,.2); }
.panel-header { display:flex; align-items:center; justify-content:space-between; padding:.9rem 1.1rem; border-bottom:1px solid rgba(255,255,255,.07); }
.panel-title { font-size:.95rem; font-weight:600; color:#e2e8f0; margin:0; }
.panel-link { font-size:.8rem; color:#3b82f6; text-decoration:none; }
.panel-empty { padding:1.5rem; text-align:center; color:#6b7280; font-size:.875rem; margin:0; }

.table-scroll { overflow-x:auto; }
.data-table { width:100%; border-collapse:collapse; font-size:.85rem; }
.data-table th { padding:.6rem 1rem; text-align:left; font-size:.75rem; font-weight:600; color:#6b7280; text-transform:uppercase; letter-spacing:.06em; border-bottom:1px solid rgba(255,255,255,.07); white-space:nowrap; }
.data-table td { padding:.7rem 1rem; border-bottom:1px solid rgba(255,255,255,.04); color:#cbd5e1; vertical-align:middle; }
.data-table tbody tr:last-child td { border-bottom:none; }
.data-table tbody tr:hover td { background:rgba(255,255,255,.03); }
.table-empty { text-align:center; color:#6b7280; padding:2rem !important; }
.table-empty a { color:#3b82f6; text-decoration:none; }
.license-key-link { font-family:monospace; font-size:.8rem; color:#60a5fa; text-decoration:none; }
.license-key-link:hover { color:#93c5fd; }

.status-badge { display:inline-block; padding:.2rem .6rem; border-radius:20px; font-size:.75rem; font-weight:600; }
.status-active { background:rgba(16,185,129,.15); color:#34d399; border:1px solid rgba(16,185,129,.25); }
.status-suspended { background:rgba(245,158,11,.12); color:#fbbf24; border:1px solid rgba(245,158,11,.2); }
.status-expired { background:rgba(239,68,68,.12); color:#f87171; border:1px solid rgba(239,68,68,.2); }
.status-revoked { background:rgba(239,68,68,.15); color:#ef4444; border:1px solid rgba(239,68,68,.25); }
.badge-plan { background:rgba(99,102,241,.15); color:#a5b4fc; border:1px solid rgba(99,102,241,.2); display:inline-block; padding:.15rem .5rem; border-radius:6px; font-size:.75rem; font-weight:600; }
.text-amber { color:#fbbf24 !important; }

.expiry-list { list-style:none; margin:0; padding:.5rem 0; }
.expiry-item { display:grid; grid-template-columns:1fr auto; gap:.25rem .5rem; padding:.6rem 1.1rem; border-bottom:1px solid rgba(255,255,255,.05); }
.expiry-item:last-child { border-bottom:none; }
.expiry-key { font-family:monospace; font-size:.78rem; color:#60a5fa; grid-column:1; }
.expiry-customer { font-size:.78rem; color:#6b7280; grid-column:1; }
.expiry-date { font-size:.78rem; font-weight:600; text-align:right; grid-row:1/3; grid-column:2; display:flex; align-items:center; }

.verif-list { list-style:none; margin:0; padding:.5rem 0; }
.verif-item { display:flex; align-items:center; gap:.6rem; padding:.5rem 1.1rem; border-bottom:1px solid rgba(255,255,255,.04); }
.verif-item:last-child { border-bottom:none; }
.verif-dot { width:7px; height:7px; border-radius:50%; flex-shrink:0; }
.verif-ok .verif-dot { background:#10b981; }
.verif-fail .verif-dot { background:#ef4444; }
.verif-detail { flex:1; min-width:0; }
.verif-key { font-family:monospace; font-size:.78rem; color:#94a3b8; display:block; }
.verif-result { font-size:.73rem; color:#6b7280; }
.verif-ok .verif-result { color:#34d399; }
.verif-fail .verif-result { color:#f87171; }
.verif-time { font-size:.73rem; color:#475569; }
</style>

<?php
render_footer();
?>
