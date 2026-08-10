<?php
require_once __DIR__ . '/includes/bootstrap.php';
Auth::require();

$pdo = Database::pdo();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::guard();
    $formAction = $_POST['form_action'] ?? 'issue';

    if ($formAction === 'delete') {
        $licenseId = (int) ($_POST['license_id'] ?? 0);
        License::deleteLicense($licenseId);
        flash_set('License permanently deleted.');
        header('Location: /public/admin/licenses.php');
        exit;
    }

    // --- Issue a new license ---
    $customerId = (int) ($_POST['customer_id'] ?? 0);
    $plan = $_POST['plan'] ?? '';
    $maxActivations = max(1, (int) ($_POST['max_activations'] ?? 1));
    $notes = trim($_POST['notes'] ?? '') ?: null;

    $validPlans = ['trial', 'monthly', 'semi_annual', 'annual', 'custom', 'lifetime'];

    $customDays = null;
    if ($plan === 'custom') {
        $customDays = (int) ($_POST['custom_days'] ?? 0);
    }

    if (!$customerId || !in_array($plan, $validPlans, true)) {
        flash_set('Please select a customer and a valid plan.', 'error');
    } elseif ($plan === 'custom' && $customDays < 1) {
        flash_set('Enter a valid number of days (1 or more) for a custom-duration license.', 'error');
    } else {
        $license = License::issue($customerId, $plan, $maxActivations, $notes, $customDays);
        flash_set("License issued: {$license['license_key']}");
    }
    header('Location: /public/admin/licenses.php');
    exit;
}

$customerFilter = isset($_GET['customer_id']) ? (int) $_GET['customer_id'] : null;
$customers = $pdo->query('SELECT id, name FROM customers ORDER BY name')->fetchAll();

$sql = "SELECT l.*, c.name AS customer_name FROM licenses l JOIN customers c ON c.id = l.customer_id";
$params = [];
if ($customerFilter) {
    $sql .= " WHERE l.customer_id = ?";
    $params[] = $customerFilter;
}
$sql .= " ORDER BY l.created_at DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$licenses = $stmt->fetchAll();

render_header('Licenses');
flash_render();
?>

<h1>Licenses</h1>

<div class="panel-grid">
    <div class="panel">
        <h2>Issue New License</h2>
        <form method="post" class="stacked-form">
            <?= Csrf::field() ?>
            <input type="hidden" name="form_action" value="issue">
            <label>Customer *</label>
            <select name="customer_id" required>
                <option value="">Select a customer…</option>
                <?php foreach ($customers as $c): ?>
                    <option value="<?= $c['id'] ?>" <?= $customerFilter === (int)$c['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($c['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <label>Plan *</label>
            <select name="plan" id="plan-select" required onchange="document.getElementById('custom-days-row').style.display = (this.value === 'custom') ? 'block' : 'none';">
                <option value="trial">Trial (10 days)</option>
                <option value="monthly">Monthly (1 month)</option>
                <option value="semi_annual">Semi-Annual (6 months)</option>
                <option value="annual">Annual (1 year)</option>
                <option value="custom">Custom — choose exact days</option>
                <option value="lifetime">Lifetime</option>
            </select>
            <div id="custom-days-row" style="display:none;">
                <label>Duration (days) *</label>
                <input type="number" name="custom_days" min="1" step="1" placeholder="e.g. 1, 2, 7, 90, 365">
            </div>
            <label>Max Activations (devices)</label>
            <input type="number" name="max_activations" value="1" min="1">
            <label>Notes</label>
            <textarea name="notes" rows="2"></textarea>
            <button type="submit" class="primary-btn">Issue License</button>
        </form>
        <?php if (empty($customers)): ?>
            <p class="muted">Add a customer first.</p>
        <?php endif; ?>
    </div>

    <div class="panel panel-wide">
        <div class="panel-header-row">
            <h2><?= $customerFilter ? 'Licenses for this customer' : 'All Licenses' ?></h2>
            <a href="/public/admin/export_csv.php<?= $customerFilter ? '?customer_id=' . $customerFilter : '' ?>" class="icon-link">Export CSV</a>
        </div>
        <table class="data-table">
            <thead><tr><th>Key</th><th>Customer</th><th>Plan</th><th>Status</th><th>Expires</th><th>Devices</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($licenses as $l): ?>
                <tr>
                    <td><a href="/public/admin/license_detail.php?id=<?= $l['id'] ?>"><?= htmlspecialchars($l['license_key']) ?></a></td>
                    <td><?= htmlspecialchars($l['customer_name']) ?></td>
                    <td><?= htmlspecialchars($l['plan']) ?></td>
                    <td><span class="badge badge-<?= htmlspecialchars($l['status']) ?>"><?= htmlspecialchars($l['status']) ?></span></td>
                    <td><?= htmlspecialchars($l['expires_at'] ?? 'Never') ?></td>
                    <td><?= (int) $l['max_activations'] ?></td>
                    <td>
                        <form method="post" style="display:inline" onsubmit="return confirm('Permanently delete license &quot;<?= htmlspecialchars($l['license_key'], ENT_QUOTES) ?>&quot;? This cannot be undone.');">
                            <?= Csrf::field() ?>
                            <input type="hidden" name="form_action" value="delete">
                            <input type="hidden" name="license_id" value="<?= $l['id'] ?>">
                            <button type="submit" class="icon-btn" style="color: var(--danger);">Delete</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($licenses)): ?>
                <tr><td colspan="7" class="muted">No licenses yet.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php render_footer(); ?>
