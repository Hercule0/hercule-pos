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

$nowTimestamp = time();
$soonTimestamp = strtotime('+7 days');
$licenseCounts = ['all' => count($licenses), 'active' => 0, 'expiring' => 0, 'expired' => 0];
foreach ($licenses as &$licenseRow) {
    $expiresTimestamp = $licenseRow['expires_at'] ? strtotime($licenseRow['expires_at']) : null;
    $licenseRow['is_expiring'] = $licenseRow['status'] === 'active'
        && $expiresTimestamp
        && $expiresTimestamp >= $nowTimestamp
        && $expiresTimestamp <= $soonTimestamp;
    if ($licenseRow['status'] === 'active') $licenseCounts['active']++;
    if ($licenseRow['is_expiring']) $licenseCounts['expiring']++;
    if ($licenseRow['status'] === 'expired') $licenseCounts['expired']++;
}
unset($licenseRow);

render_header('Licenses');
flash_render();
?>

<div class="licenses-page">
    <section class="page-hero licenses-hero">
        <div>
            <p class="eyebrow">Subscriptions</p>
            <h1>Licenses</h1>
            <p class="page-subtitle">
                <?= count($licenses) ?> license<?= count($licenses) === 1 ? '' : 's' ?>
                <?= $customerFilter ? ' for the selected customer' : ' in your workspace' ?>.
            </p>
        </div>
        <div class="hero-actions">
            <a href="/public/admin/export_csv.php<?= $customerFilter ? '?customer_id=' . $customerFilter : '' ?>" class="app-secondary-action">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3v12M7 10l5 5 5-5M5 20h14"/></svg>
                <span>Export</span>
            </a>
            <button type="button" class="app-primary-action" data-open-license-dialog <?= empty($customers) ? 'disabled' : '' ?>>
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>
                <span>Issue license</span>
            </button>
        </div>
    </section>

    <?php if ($customerFilter): ?>
        <div class="active-filter-banner">
            <span>Showing licenses for one customer</span>
            <a href="/public/admin/licenses.php">Clear filter</a>
        </div>
    <?php endif; ?>

    <section class="license-tools" aria-label="License tools">
        <label class="app-search" for="license-search">
            <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m16.5 16.5 4 4"/></svg>
            <input id="license-search" type="search" placeholder="Search key, customer, or plan" autocomplete="off">
            <kbd id="license-result-count"><?= count($licenses) ?></kbd>
        </label>
        <div class="filter-chips" id="license-filters" role="group" aria-label="Filter licenses">
            <?php foreach (['all' => 'All', 'active' => 'Active', 'expiring' => 'Expiring', 'expired' => 'Expired'] as $value => $label): ?>
                <button type="button" data-license-filter="<?= $value ?>" class="<?= $value === 'all' ? 'active' : '' ?>">
                    <?= $label ?><span><?= $licenseCounts[$value] ?></span>
                </button>
            <?php endforeach; ?>
        </div>
    </section>

    <?php if (empty($licenses)): ?>
        <section class="licenses-empty">
            <span class="empty-illustration">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h10a4 4 0 0 1 0 8H9l-3 3v-3H4a4 4 0 0 1 0-8z"/><circle cx="14" cy="11" r="1"/></svg>
            </span>
            <h2>No licenses yet</h2>
            <p>Issue a license to connect a customer with Hercule POS.</p>
            <?php if (empty($customers)): ?>
                <a href="/public/admin/customers.php" class="app-primary-action">Add a customer first</a>
            <?php else: ?>
                <button type="button" class="app-primary-action" data-open-license-dialog>Issue license</button>
            <?php endif; ?>
        </section>
    <?php else: ?>
        <section class="license-grid" id="license-grid" aria-live="polite">
            <?php foreach ($licenses as $l): ?>
                <?php
                    $filterStatus = $l['is_expiring'] ? 'expiring' : $l['status'];
                    $searchText = implode(' ', [$l['license_key'], $l['customer_name'], $l['plan'], $l['status']]);
                    $shortKey = strlen($l['license_key']) > 13 ? '•••• ' . substr($l['license_key'], -9) : $l['license_key'];
                ?>
                <article class="license-card" data-license-card data-status="<?= htmlspecialchars($filterStatus) ?>" data-search="<?= htmlspecialchars($searchText, ENT_QUOTES) ?>">
                    <div class="license-card-head">
                        <span class="license-customer-avatar" aria-hidden="true"><?= strtoupper(htmlspecialchars(substr($l['customer_name'], 0, 1))) ?></span>
                        <div class="license-identity">
                            <h2 dir="auto"><?= htmlspecialchars($l['customer_name']) ?></h2>
                            <span><?= htmlspecialchars(str_replace('_', ' ', $l['plan'])) ?> plan</span>
                        </div>
                        <span class="license-status status-<?= htmlspecialchars($filterStatus) ?>"><?= htmlspecialchars($filterStatus) ?></span>
                    </div>

                    <div class="license-key-row">
                        <div>
                            <span>License key</span>
                            <code dir="ltr" title="<?= htmlspecialchars($l['license_key'], ENT_QUOTES) ?>"><?= htmlspecialchars($shortKey) ?></code>
                        </div>
                        <button type="button" class="copy-key" data-copy-key="<?= htmlspecialchars($l['license_key'], ENT_QUOTES) ?>" aria-label="Copy license key">
                            <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="8" y="8" width="11" height="11" rx="2"/><path d="M16 8V5H5v11h3"/></svg>
                        </button>
                    </div>

                    <div class="license-facts">
                        <div>
                            <span>Expires</span>
                            <strong><?= $l['expires_at'] ? htmlspecialchars(date('M j, Y', strtotime($l['expires_at']))) : 'Never' ?></strong>
                        </div>
                        <div>
                            <span>Devices</span>
                            <strong><?= (int) $l['max_activations'] ?></strong>
                        </div>
                        <div>
                            <span>Created</span>
                            <strong><?= htmlspecialchars(date('M j, Y', strtotime($l['created_at']))) ?></strong>
                        </div>
                    </div>

                    <div class="license-card-actions">
                        <a href="/public/admin/license_detail.php?id=<?= $l['id'] ?>" class="license-view-action">
                            View details
                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m9 6 6 6-6 6"/></svg>
                        </a>
                        <details class="card-menu">
                            <summary aria-label="License actions">
                                <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="5" cy="12" r="1"/><circle cx="12" cy="12" r="1"/><circle cx="19" cy="12" r="1"/></svg>
                            </summary>
                            <div class="card-menu-popover">
                                <a href="/public/admin/license_detail.php?id=<?= $l['id'] ?>">
                                    <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="8"/><path d="M12 11v5M12 8h.01"/></svg>
                                    Manage license
                                </a>
                                <form method="post" onsubmit="return confirm('Permanently delete license &quot;<?= htmlspecialchars($l['license_key'], ENT_QUOTES) ?>&quot;? This cannot be undone.');">
                                    <?= Csrf::field() ?>
                                    <input type="hidden" name="form_action" value="delete">
                                    <input type="hidden" name="license_id" value="<?= $l['id'] ?>">
                                    <button type="submit" class="menu-danger">
                                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 7h14M9 7V4h6v3M8 10v7M12 10v7M16 10v7M6 7l1 13h10l1-13"/></svg>
                                        Delete license
                                    </button>
                                </form>
                            </div>
                        </details>
                    </div>
                </article>
            <?php endforeach; ?>
        </section>

        <div class="search-empty" id="license-search-empty" hidden>
            <strong>No matching licenses</strong>
            <p>Try another search or select a different status.</p>
        </div>
    <?php endif; ?>
</div>

<button type="button" class="license-fab" data-open-license-dialog aria-label="Issue license" <?= empty($customers) ? 'disabled' : '' ?>>
    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>
</button>

<dialog class="app-dialog license-dialog" id="license-dialog">
    <form method="post" class="license-issue-form">
        <?= Csrf::field() ?>
        <input type="hidden" name="form_action" value="issue">
        <div class="dialog-header">
            <div>
                <p class="eyebrow">New subscription</p>
                <h2>Issue license</h2>
            </div>
            <button type="button" class="dialog-close" data-close-license-dialog aria-label="Close">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m6 6 12 12M18 6 6 18"/></svg>
            </button>
        </div>
        <div class="dialog-fields">
            <label>
                <span>Customer *</span>
                <select name="customer_id" required>
                    <option value="">Select a customer…</option>
                    <?php foreach ($customers as $c): ?>
                        <option value="<?= $c['id'] ?>" <?= $customerFilter === (int) $c['id'] ? 'selected' : '' ?>><?= htmlspecialchars($c['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                <span>Plan *</span>
                <select name="plan" id="plan-select" required>
                    <option value="trial">Trial — 10 days</option>
                    <option value="monthly">Monthly — 1 month</option>
                    <option value="semi_annual">Semi-Annual — 6 months</option>
                    <option value="annual">Annual — 1 year</option>
                    <option value="custom">Custom duration</option>
                    <option value="lifetime">Lifetime</option>
                </select>
            </label>
            <label id="custom-days-row" hidden>
                <span>Duration in days *</span>
                <input type="number" name="custom_days" min="1" step="1" placeholder="Example: 30" inputmode="numeric">
            </label>
            <label>
                <span>Maximum devices</span>
                <input type="number" name="max_activations" value="1" min="1" inputmode="numeric">
            </label>
            <label>
                <span>Notes</span>
                <textarea name="notes" rows="3" placeholder="Optional internal notes"></textarea>
            </label>
        </div>
        <div class="dialog-actions">
            <button type="button" class="secondary-btn" data-close-license-dialog>Cancel</button>
            <button type="submit" class="primary-btn">Issue license</button>
        </div>
    </form>
</dialog>

<script>
(function () {
    var dialog = document.getElementById('license-dialog');
    document.querySelectorAll('[data-open-license-dialog]').forEach(function (button) {
        button.addEventListener('click', function () {
            if (button.disabled) return;
            if (dialog && typeof dialog.showModal === 'function') dialog.showModal();
            else if (dialog) dialog.setAttribute('open', '');
        });
    });
    document.querySelectorAll('[data-close-license-dialog]').forEach(function (button) {
        button.addEventListener('click', function () {
            if (dialog && typeof dialog.close === 'function') dialog.close();
            else if (dialog) dialog.removeAttribute('open');
        });
    });
    if (dialog) {
        dialog.addEventListener('click', function (event) {
            if (event.target === dialog) dialog.close();
        });
    }

    var plan = document.getElementById('plan-select');
    var customDays = document.getElementById('custom-days-row');
    function syncCustomDays() {
        if (!plan || !customDays) return;
        customDays.hidden = plan.value !== 'custom';
        var input = customDays.querySelector('input');
        if (input) input.required = plan.value === 'custom';
    }
    if (plan) {
        plan.addEventListener('change', syncCustomDays);
        syncCustomDays();
    }

    var cards = Array.from(document.querySelectorAll('[data-license-card]'));
    var search = document.getElementById('license-search');
    var filters = Array.from(document.querySelectorAll('[data-license-filter]'));
    var resultCount = document.getElementById('license-result-count');
    var empty = document.getElementById('license-search-empty');
    var activeFilter = 'all';

    function applyFilters() {
        var query = search ? search.value.trim().toLocaleLowerCase() : '';
        var visible = 0;
        cards.forEach(function (card) {
            var matchesSearch = card.dataset.search.toLocaleLowerCase().includes(query);
            var matchesStatus = activeFilter === 'all'
                || card.dataset.status === activeFilter
                || (activeFilter === 'active' && card.dataset.status === 'expiring');
            var show = matchesSearch && matchesStatus;
            card.hidden = !show;
            if (show) visible++;
        });
        if (resultCount) resultCount.textContent = visible;
        if (empty) empty.hidden = visible !== 0;
    }

    if (search) search.addEventListener('input', applyFilters);
    filters.forEach(function (button) {
        button.addEventListener('click', function () {
            activeFilter = button.dataset.licenseFilter;
            filters.forEach(function (item) { item.classList.toggle('active', item === button); });
            applyFilters();
        });
    });

    document.querySelectorAll('[data-copy-key]').forEach(function (button) {
        button.addEventListener('click', function () {
            var key = button.dataset.copyKey;
            if (!navigator.clipboard) return;
            navigator.clipboard.writeText(key).then(function () {
                button.classList.add('copied');
                button.setAttribute('aria-label', 'Copied');
                setTimeout(function () {
                    button.classList.remove('copied');
                    button.setAttribute('aria-label', 'Copy license key');
                }, 1400);
            });
        });
    });
})();
</script>

<?php render_footer(); ?>
