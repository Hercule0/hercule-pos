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
    "SELECT * FROM verification_log ORDER BY created_at DESC LIMIT 15"
)->fetchAll();

render_header('Dashboard');
flash_render();
?>

<h1>Dashboard</h1>

<div class="stat-cards">
    <div class="stat-card"><span class="label">Total Licenses</span><span class="value"><?= $totalLicenses ?></span></div>
    <div class="stat-card"><span class="label">Active</span><span class="value"><?= $activeLicenses ?></span></div>
    <div class="stat-card"><span class="label">Expired</span><span class="value"><?= $expiredLicenses ?></span></div>
    <div class="stat-card"><span class="label">Customers</span><span class="value"><?= $totalCustomers ?></span></div>
</div>

<div class="panel-grid">
    <div class="panel">
        <h2>Expiring in the next 7 days</h2>
        <?php if (empty($expiringSoon)): ?>
            <p class="muted">Nothing expiring soon.</p>
        <?php else: ?>
        <table class="data-table">
            <thead><tr><th>Customer</th><th>License Key</th><th>Plan</th><th>Expires</th></tr></thead>
            <tbody>
            <?php foreach ($expiringSoon as $l): ?>
                <tr>
                    <td><?= htmlspecialchars($l['customer_name']) ?></td>
                    <td><a href="/public/admin/license_detail.php?id=<?= $l['id'] ?>"><?= htmlspecialchars($l['license_key']) ?></a></td>
                    <td><?= htmlspecialchars($l['plan']) ?></td>
                    <td><?= htmlspecialchars($l['expires_at']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>

    <div class="panel">
        <h2>Recent verification activity</h2>
        <?php if (empty($recentActivity)): ?>
            <p class="muted">No activity yet.</p>
        <?php else: ?>
        <table class="data-table small">
            <thead><tr><th>Key</th><th>Result</th><th>IP</th><th>When</th></tr></thead>
            <tbody>
            <?php foreach ($recentActivity as $a): ?>
                <tr>
                    <td><?= htmlspecialchars($a['license_key']) ?></td>
                    <td><span class="badge badge-<?= htmlspecialchars($a['result']) ?>"><?= htmlspecialchars($a['result']) ?></span></td>
                    <td><?= htmlspecialchars($a['ip_address'] ?? '—') ?></td>
                    <td><?= htmlspecialchars($a['created_at']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>

<?php render_footer(); ?>
