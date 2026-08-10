<?php
require_once __DIR__ . '/includes/bootstrap.php';
Auth::require();

$pdo = Database::pdo();
$licenseId = (int) ($_GET['id'] ?? 0);
$license = License::findById($licenseId);

if (!$license) {
    flash_set('License not found.', 'error');
    header('Location: /public/admin/licenses.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::guard();
    $action = $_POST['action'] ?? '';
    $admin = Auth::currentUsername() ?? 'admin';

    switch ($action) {
        case 'renew':
            $plan = $_POST['plan'] ?? '';
            $customDays = null;
            if ($plan === 'custom') {
                $customDays = (int) ($_POST['renew_custom_days'] ?? 0);
                if ($customDays < 1) {
                    flash_set('Enter a valid number of days (1 or more) for a custom renewal.', 'error');
                    header("Location: /public/admin/license_detail.php?id={$licenseId}");
                    exit;
                }
            }
            License::renew($licenseId, $plan, $admin, $customDays);
            flash_set('License renewed.');
            break;
        case 'suspend':
            License::suspend($licenseId, $admin);
            flash_set('License suspended.');
            break;
        case 'revoke':
            License::revoke($licenseId, $admin);
            flash_set('License revoked.');
            break;
        case 'reactivate':
            License::reactivate($licenseId, $admin);
            flash_set('License reactivated.');
            break;
        case 'deactivate_device':
            License::deactivateDevice((int) ($_POST['activation_id'] ?? 0));
            flash_set('Device deactivated — that slot is now free.');
            break;
        case 'delete':
            $keyForMessage = $license['license_key'];
            License::deleteLicense($licenseId);
            flash_set("License {$keyForMessage} permanently deleted.");
            header('Location: /public/admin/licenses.php');
            exit;
    }
    header("Location: /public/admin/license_detail.php?id={$licenseId}");
    exit;
}

$customerStmt = $pdo->prepare('SELECT * FROM customers WHERE id = ?');
$customerStmt->execute([$license['customer_id']]);
$customer = $customerStmt->fetch();

$activations = License::activationsFor($licenseId);

$eventsStmt = $pdo->prepare('SELECT * FROM subscription_events WHERE license_id = ? ORDER BY created_at DESC');
$eventsStmt->execute([$licenseId]);
$events = $eventsStmt->fetchAll();

$logStmt = $pdo->prepare('SELECT * FROM verification_log WHERE license_id = ? ORDER BY created_at DESC LIMIT 20');
$logStmt->execute([$licenseId]);
$logs = $logStmt->fetchAll();

render_header('License ' . $license['license_key']);
flash_render();
?>

<h1><?= htmlspecialchars($license['license_key']) ?></h1>
<p class="muted">Customer: <a href="/public/admin/customers.php"><?= htmlspecialchars($customer['name'] ?? 'Unknown') ?></a></p>

<div class="stat-cards">
    <div class="stat-card"><span class="label">Status</span><span class="value badge badge-<?= htmlspecialchars($license['status']) ?>"><?= htmlspecialchars($license['status']) ?></span></div>
    <div class="stat-card"><span class="label">Plan</span><span class="value"><?= htmlspecialchars($license['plan']) ?></span></div>
    <div class="stat-card"><span class="label">Expires</span><span class="value"><?= htmlspecialchars($license['expires_at'] ?? 'Never') ?></span></div>
    <div class="stat-card"><span class="label">Devices</span><span class="value"><?= count(array_filter($activations, fn($a) => $a['is_active'])) ?> / <?= (int) $license['max_activations'] ?></span></div>
</div>

<div class="panel-grid">
    <div class="panel">
        <h2>Actions</h2>
        <form method="post" class="stacked-form">
            <?= Csrf::field() ?>
            <label>Renew as</label>
            <select name="plan" onchange="document.getElementById('renew-custom-days-row').style.display = (this.value === 'custom') ? 'block' : 'none';">
                <option value="monthly">Monthly</option>
                <option value="semi_annual">Semi-Annual</option>
                <option value="annual">Annual</option>
                <option value="custom">Custom — choose exact days</option>
                <option value="lifetime">Lifetime</option>
            </select>
            <div id="renew-custom-days-row" style="display:none;">
                <label>Duration (days)</label>
                <input type="number" name="renew_custom_days" min="1" step="1" placeholder="e.g. 1, 2, 7, 90">
            </div>
            <button type="submit" name="action" value="renew" class="primary-btn">Renew</button>
        </form>
        <div class="action-row">
            <?php if ($license['status'] === 'active'): ?>
                <form method="post" onsubmit="return confirm('Suspend this license?');">
                    <?= Csrf::field() ?>
                    <button type="submit" name="action" value="suspend" class="secondary-btn">Suspend</button>
                </form>
            <?php else: ?>
                <form method="post">
                    <?= Csrf::field() ?>
                    <button type="submit" name="action" value="reactivate" class="secondary-btn">Reactivate</button>
                </form>
            <?php endif; ?>
            <form method="post" onsubmit="return confirm('Revoke this license permanently? This cannot be undone from here.');">
                <?= Csrf::field() ?>
                <button type="submit" name="action" value="revoke" class="danger-btn">Revoke</button>
            </form>
        </div>
        <form method="post" style="margin-top: 14px;" onsubmit="return confirm('PERMANENTLY DELETE this license, its devices, and its event history? This is irreversible.');">
            <?= Csrf::field() ?>
            <button type="submit" name="action" value="delete" class="danger-btn" style="width:100%;">Delete License Permanently</button>
        </form>
    </div>

    <div class="panel panel-wide">
        <h2>Activated Devices</h2>
        <table class="data-table">
            <thead><tr><th>HWID</th><th>Status</th><th>Activated</th><th>Last Seen</th><th>IP</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($activations as $a): ?>
                <tr>
                    <td class="mono"><?= htmlspecialchars($a['hwid']) ?></td>
                    <td><?= $a['is_active'] ? '<span class="badge badge-active">active</span>' : '<span class="badge badge-revoked">freed</span>' ?></td>
                    <td><?= htmlspecialchars($a['activated_at']) ?></td>
                    <td><?= htmlspecialchars($a['last_seen_at']) ?></td>
                    <td><?= htmlspecialchars($a['ip_address'] ?? '—') ?></td>
                    <td>
                        <?php if ($a['is_active']): ?>
                        <form method="post" onsubmit="return confirm('Free up this device slot?');">
                            <?= Csrf::field() ?>
                            <input type="hidden" name="activation_id" value="<?= $a['id'] ?>">
                            <button type="submit" name="action" value="deactivate_device" class="icon-btn">Deactivate</button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($activations)): ?>
                <tr><td colspan="6" class="muted">No devices activated yet.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="panel-grid">
    <div class="panel">
        <h2>Subscription Events</h2>
        <table class="data-table small">
            <thead><tr><th>Event</th><th>By</th><th>When</th></tr></thead>
            <tbody>
            <?php foreach ($events as $e): ?>
                <tr>
                    <td><?= htmlspecialchars($e['event_type']) ?><?= $e['note'] ? ' — ' . htmlspecialchars($e['note']) : '' ?></td>
                    <td><?= htmlspecialchars($e['created_by'] ?? '—') ?></td>
                    <td><?= htmlspecialchars($e['created_at']) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($events)): ?>
                <tr><td colspan="3" class="muted">No events logged.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <div class="panel panel-wide">
        <h2>Recent Verification Attempts</h2>
        <table class="data-table small">
            <thead><tr><th>Result</th><th>HWID</th><th>IP</th><th>When</th></tr></thead>
            <tbody>
            <?php foreach ($logs as $l): ?>
                <tr>
                    <td><span class="badge badge-<?= htmlspecialchars($l['result']) ?>"><?= htmlspecialchars($l['result']) ?></span></td>
                    <td class="mono"><?= htmlspecialchars($l['hwid'] ?? '—') ?></td>
                    <td><?= htmlspecialchars($l['ip_address'] ?? '—') ?></td>
                    <td><?= htmlspecialchars($l['created_at']) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($logs)): ?>
                <tr><td colspan="4" class="muted">No verification attempts logged yet.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php render_footer(); ?>
