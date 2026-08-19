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
                            <td>
                                <div class="cell-main">
                                    <a href="/public/admin/license_detail.php?id=<?= $lic['id'] ?>" class="license-key-link" style="background:transparent; border:none; padding:0;">
                                        <code dir="ltr" style="font-size:13px; font-weight:600; color:var(--text);"><?= htmlspecialchars($lic['license_key']) ?></code>
                                    </a>
                                </div>
                            </td>
                            <td>
                                <div class="cell-main">
                                    <strong><?= htmlspecialchars($lic['customer_name'] ?? '—') ?></strong>
                                </div>
                            </td>
                            <td><span style="color:var(--text-dim); text-transform:capitalize;"><?= htmlspecialchars(str_replace('_', ' ', $lic['plan'])) ?></span></td>
                            <td>
                                <span style="color:var(--text-dim);"><?= (int)$lic['active_devices'] ?> / <?= (int)$lic['max_activations'] ?></span>
                            </td>
                            <td>
                                <span class="badge <?= $badgeClass ?>">
                                    <?= htmlspecialchars(ucfirst($lic['status'])) ?>
                                </span>
                            </td>
                            <td>
                                <span style="color:var(--text-dim);" class="<?= (!empty($lic['expires_at']) && strtotime($lic['expires_at']) < strtotime('+30 days')) ? 'text-amber' : '' ?>">
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
@import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap');

body { font-family: 'Inter', sans-serif; }

.dashboard-wrap { animation: fadeIn 0.4s ease-out; }
@keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }

.page-header-bar { display:flex; align-items:center; justify-content:space-between; gap:1rem; margin-bottom:2rem; flex-wrap:wrap; }
.page-title { font-size:2rem; font-weight:800; background: linear-gradient(to right, #fff, #94a3b8); -webkit-background-clip: text; -webkit-text-fill-color: transparent; margin:0 0 .25rem; letter-spacing: -0.02em; }
.page-subtitle { color:#64748b; font-size:.95rem; margin:0; font-weight: 500; }
.page-header-actions { display:flex; gap:.75rem; }

.alert-banner { display:flex; align-items:center; gap:1rem; padding:1.25rem 1.5rem; border-radius:12px; margin-bottom:2rem; flex-wrap:wrap; box-shadow: 0 10px 25px -5px rgba(0,0,0,0.2); backdrop-filter: blur(10px); }
.alert-banner svg { width:1.5rem; height:1.5rem; flex-shrink:0; stroke:currentColor; fill:none; stroke-width:2; stroke-linecap:round; stroke-linejoin:round; }
.alert-banner-warning { background:linear-gradient(135deg, rgba(245,158,11,.15), rgba(245,158,11,.05)); border:1px solid rgba(245,158,11,.25); color:#f59e0b; }
.alert-banner-warning strong { display:block; color:#fbbf24; font-size: 1.05rem; }
.alert-banner-warning span { font-size:.9rem; color:#fde68a; opacity: 0.9; }

.btn { display:inline-flex; align-items:center; gap:.5rem; padding:.65rem 1.25rem; border-radius:8px; font-size:.9rem; font-weight:600; text-decoration:none; border:1px solid transparent; cursor:pointer; transition:all .2s ease; font-family: 'Inter', sans-serif; }
.btn svg { width:1.1rem; height:1.1rem; stroke:currentColor; fill:none; stroke-width:2.2; }
.btn-primary { background:linear-gradient(135deg, #3b82f6, #2563eb); color:#fff; box-shadow: 0 4px 12px rgba(59,130,246,0.3); }
.btn-primary:hover { transform: translateY(-1px); box-shadow: 0 6px 16px rgba(59,130,246,0.4); }
.btn-secondary { background:rgba(30,41,59,.6); color:#e2e8f0; border:1px solid rgba(255,255,255,.1); backdrop-filter: blur(10px); }
.btn-secondary:hover { background:rgba(30,41,59,.9); border-color: rgba(255,255,255,.2); }
.btn-warning { background:linear-gradient(135deg, #f59e0b, #d97706); color:#fff; box-shadow: 0 4px 12px rgba(245,158,11,0.3); }
.btn-warning:hover { transform: translateY(-1px); box-shadow: 0 6px 16px rgba(245,158,11,0.4); }
.btn-sm { padding:.4rem .85rem; font-size:.85rem; }

.stats-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(220px,1fr)); gap:1.25rem; margin-bottom:2rem; }
.stat-card { background:rgba(17,24,39,.6); backdrop-filter: blur(12px); border:1px solid rgba(255,255,255,.06); border-radius:16px; padding:1.5rem; transition: transform 0.2s, box-shadow 0.2s; position: relative; overflow: hidden; }
.stat-card:hover { transform: translateY(-3px); box-shadow: 0 12px 30px rgba(0,0,0,0.3); border-color: rgba(255,255,255,.1); }
.stat-card::before { content: ''; position: absolute; top: 0; left: 0; width: 100%; height: 3px; }
.stat-card-blue::before { background: linear-gradient(90deg, #3b82f6, #60a5fa); }
.stat-card-emerald::before { background: linear-gradient(90deg, #10b981, #34d399); }
.stat-card-amber::before { background: linear-gradient(90deg, #f59e0b, #fbbf24); }
.stat-card-purple::before { background: linear-gradient(90deg, #8b5cf6, #a78bfa); }

.stat-card-header { display:flex; justify-content:space-between; align-items:center; margin-bottom:1rem; }
.stat-label { font-size:.8rem; font-weight:600; color:#94a3b8; text-transform:uppercase; letter-spacing:.05em; }
.stat-icon { width:2.2rem; height:2.2rem; padding: 0.4rem; border-radius: 8px; stroke-width: 1.8; fill:none; }
.stat-card-blue .stat-icon { stroke: #3b82f6; background: rgba(59,130,246,0.1); }
.stat-card-emerald .stat-icon { stroke: #10b981; background: rgba(16,185,129,0.1); }
.stat-card-amber .stat-icon { stroke: #f59e0b; background: rgba(245,158,11,0.1); }
.stat-card-purple .stat-icon { stroke: #8b5cf6; background: rgba(139,92,246,0.1); }

.stat-value { font-size:2.25rem; font-weight:800; color:#fff; line-height:1; margin-bottom:.4rem; letter-spacing: -0.03em; }
.stat-sub { font-size:1rem; font-weight:500; color:#64748b; }
.stat-footer { font-size:.8rem; color:#64748b; font-weight: 500; }

.dashboard-grid { display:grid; grid-template-columns:1fr 360px; gap:1.5rem; }
@media(max-width:1100px){ .dashboard-grid { grid-template-columns:1fr; } }
.dashboard-side { display:flex; flex-direction:column; gap:1.5rem; }

.panel { background:rgba(17,24,39,.6); backdrop-filter: blur(12px); border:1px solid rgba(255,255,255,.06); border-radius:16px; overflow:hidden; box-shadow: 0 4px 20px rgba(0,0,0,0.15); }
.panel-alert { border-color:rgba(245,158,11,.2); }
.panel-header { display:flex; align-items:center; justify-content:space-between; padding:1.25rem 1.5rem; border-bottom:1px solid rgba(255,255,255,.05); background: rgba(255,255,255,.01); }
.panel-title { font-size:1.1rem; font-weight:600; color:#fff; margin:0; }
.panel-link { font-size:.85rem; font-weight: 500; color:#38bdf8; text-decoration:none; transition: color 0.2s; }
.panel-link:hover { color:#7dd3fc; }
.panel-empty { padding:2.5rem; text-align:center; color:#64748b; font-size:.9rem; margin:0; }

.table-scroll { overflow-x:auto; }
.data-table { width:100%; border-collapse:collapse; font-size:.9rem; }
.data-table th { padding:1rem 1.5rem; text-align:left; font-size:.75rem; font-weight:600; color:#94a3b8; text-transform:uppercase; letter-spacing:.05em; border-bottom:1px solid rgba(255,255,255,.06); white-space:nowrap; background: rgba(0,0,0,.1); }
.data-table td { padding:1rem 1.5rem; border-bottom:1px solid rgba(255,255,255,.03); color:#cbd5e1; vertical-align:middle; font-weight: 500; }
.data-table tbody tr { transition: background 0.15s; }
.data-table tbody tr:hover { background:rgba(255,255,255,.02); }
.data-table tbody tr:last-child td { border-bottom:none; }

.license-key-link { font-family:'Menlo', 'Monaco', monospace; font-size:.85rem; color:#38bdf8; text-decoration:none; font-weight: 600; background: rgba(56,189,248,0.1); padding: 0.25rem 0.5rem; border-radius: 6px; border: 1px solid rgba(56,189,248,0.15); transition: all 0.2s; }
.license-key-link:hover { background: rgba(56,189,248,0.2); }

.status-badge { display:inline-flex; align-items:center; padding:.25rem .75rem; border-radius:99px; font-size:.75rem; font-weight:700; text-transform: uppercase; letter-spacing: 0.05em; }
.status-active { background:rgba(16,185,129,.15); color:#10b981; border:1px solid rgba(16,185,129,.2); box-shadow: 0 0 10px rgba(16,185,129,0.1); }
.status-suspended { background:rgba(245,158,11,.15); color:#f59e0b; border:1px solid rgba(245,158,11,.2); }
.status-expired { background:rgba(239,68,68,.15); color:#ef4444; border:1px solid rgba(239,68,68,.2); }
.status-revoked { background:rgba(225,29,72,.15); color:#e11d48; border:1px solid rgba(225,29,72,.2); }
.badge-plan { background:rgba(99,102,241,.15); color:#818cf8; border:1px solid rgba(99,102,241,.2); display:inline-block; padding:.2rem .6rem; border-radius:6px; font-size:.75rem; font-weight:600; }
.text-amber { color:#fbbf24 !important; font-weight: 600; }

.expiry-list { list-style:none; margin:0; padding:0; }
.expiry-item { display:grid; grid-template-columns:1fr auto; gap:.25rem .5rem; padding:.85rem 1.5rem; border-bottom:1px solid rgba(255,255,255,.04); transition: background 0.15s; }
.expiry-item:hover { background: rgba(255,255,255,.01); }
.expiry-item:last-child { border-bottom:none; }
.expiry-key { font-family:'Menlo', monospace; font-size:.8rem; color:#38bdf8; grid-column:1; font-weight: 600; }
.expiry-customer { font-size:.8rem; color:#94a3b8; grid-column:1; font-weight: 500; }
.expiry-date { font-size:.8rem; font-weight:700; text-align:right; grid-row:1/3; grid-column:2; display:flex; align-items:center; }

.verif-list { list-style:none; margin:0; padding:0; }
.verif-item { display:flex; align-items:center; gap:.75rem; padding:.75rem 1.5rem; border-bottom:1px solid rgba(255,255,255,.04); transition: background 0.15s; }
.verif-item:hover { background: rgba(255,255,255,.01); }
.verif-item:last-child { border-bottom:none; }
.verif-dot { width:8px; height:8px; border-radius:50%; flex-shrink:0; box-shadow: 0 0 8px currentColor; }
.verif-ok .verif-dot { background:#10b981; color: #10b981; }
.verif-fail .verif-dot { background:#ef4444; color: #ef4444; }
.verif-detail { flex:1; min-width:0; }
.verif-key { font-family:'Menlo', monospace; font-size:.8rem; color:#cbd5e1; display:block; font-weight: 500; }
.verif-result { font-size:.75rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; }
.verif-ok .verif-result { color:#10b981; }
.verif-fail .verif-result { color:#ef4444; }
.verif-time { font-size:.75rem; color:#64748b; font-weight: 500; }
</style>

<?php
render_footer();
?>
