<?php
require_once __DIR__ . '/includes/bootstrap.php';
Auth::require();

$pdo = Database::pdo();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::guard();
    $formAction = $_POST['form_action'] ?? 'issue';

    if ($formAction === 'delete') {
        Auth::requirePermission('licenses.delete');
        $licenseId = (int) ($_POST['license_id'] ?? 0);
        License::deleteLicense($licenseId);
        flash_set('License permanently deleted.');
        header('Location: /public/admin/licenses.php');
        exit;
    }

    // --- Issue a new license ---
    Auth::requirePermission('licenses.manage');

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

$customerFilter = isset($_GET['customer_id']) ? max(0, (int) $_GET['customer_id']) : null;
$searchQuery = mb_substr(trim($_GET['q'] ?? ''), 0, 100);
$statusFilter = $_GET['status'] ?? 'all';
$validStatusFilters = ['all', 'active', 'expiring', 'expired'];
if (!in_array($statusFilter, $validStatusFilters, true)) $statusFilter = 'all';

$currentPage = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 24;
$customers = $pdo->query('SELECT id, name FROM customers ORDER BY name')->fetchAll();

$scopeWhere = [];
$scopeParams = [];
if ($customerFilter) {
    $scopeWhere[] = 'l.customer_id = ?';
    $scopeParams[] = $customerFilter;
}
$scopeSql = $scopeWhere ? ' WHERE ' . implode(' AND ', $scopeWhere) : '';

$countSummary = $pdo->prepare(
    "SELECT COUNT(*) AS all_count,
            COUNT(CASE WHEN l.status = 'active' THEN 1 END) AS active_count,
            COUNT(CASE WHEN l.status = 'expired' THEN 1 END) AS expired_count,
            COUNT(CASE WHEN l.status = 'active'
                        AND l.expires_at BETWEEN CURRENT_TIMESTAMP AND DATE_ADD(CURRENT_TIMESTAMP, INTERVAL 7 DAY)
                       THEN 1 END) AS expiring_count
     FROM licenses l" . $scopeSql
);
$countSummary->execute($scopeParams);
$summary = $countSummary->fetch();
$licenseCounts = [
    'all' => (int) ($summary['all_count'] ?? 0),
    'active' => (int) ($summary['active_count'] ?? 0),
    'expiring' => (int) ($summary['expiring_count'] ?? 0),
    'expired' => (int) ($summary['expired_count'] ?? 0),
];

$where = $scopeWhere;
$params = $scopeParams;
if ($searchQuery !== '') {
    $where[] = '(l.license_key LIKE ? OR c.name LIKE ? OR l.plan LIKE ?)';
    $pattern = '%' . $searchQuery . '%';
    array_push($params, $pattern, $pattern, $pattern);
}
if ($statusFilter === 'active') {
    $where[] = "l.status = 'active'";
} elseif ($statusFilter === 'expired') {
    $where[] = "l.status = 'expired'";
} elseif ($statusFilter === 'expiring') {
    $where[] = "l.status = 'active' AND l.expires_at BETWEEN CURRENT_TIMESTAMP AND DATE_ADD(CURRENT_TIMESTAMP, INTERVAL 7 DAY)";
}
$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

$totalStmt = $pdo->prepare(
    'SELECT COUNT(*) FROM licenses l JOIN customers c ON c.id = l.customer_id' . $whereSql
);
$totalStmt->execute($params);
$totalLicenses = (int) $totalStmt->fetchColumn();
$totalPages = max(1, (int) ceil($totalLicenses / $perPage));
$currentPage = min($currentPage, $totalPages);
$offset = ($currentPage - 1) * $perPage;

$sql = 'SELECT l.*, c.name AS customer_name
        FROM licenses l JOIN customers c ON c.id = l.customer_id'
        . $whereSql .
       ' ORDER BY l.created_at DESC LIMIT ? OFFSET ?';
$stmt = $pdo->prepare($sql);
$position = 1;
foreach ($params as $value) {
    $stmt->bindValue($position++, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
}
$stmt->bindValue($position++, $perPage, PDO::PARAM_INT);
$stmt->bindValue($position, $offset, PDO::PARAM_INT);
$stmt->execute();
$licenses = $stmt->fetchAll();

$nowTimestamp = time();
$soonTimestamp = strtotime('+7 days');
foreach ($licenses as &$licenseRow) {
    $expiresTimestamp = $licenseRow['expires_at'] ? strtotime($licenseRow['expires_at']) : null;
    $licenseRow['is_expiring'] = $licenseRow['status'] === 'active'
        && $expiresTimestamp
        && $expiresTimestamp >= $nowTimestamp
        && $expiresTimestamp <= $soonTimestamp;
}
unset($licenseRow);

$licensePageUrl = static function (int $page, ?string $status = null) use ($customerFilter, $searchQuery, $statusFilter): string {
    $query = ['page' => $page, 'status' => $status ?? $statusFilter];
    if ($customerFilter) $query['customer_id'] = $customerFilter;
    if ($searchQuery !== '') $query['q'] = $searchQuery;
    if ($query['status'] === 'all') unset($query['status']);
    return '/public/admin/licenses.php' . ($query ? '?' . http_build_query($query) : '');
};

render_header('Licenses');
flash_render();
?>

<div class="licenses-page">
    <section class="page-hero licenses-hero">
        <div>
            <p class="eyebrow">Subscriptions</p>
            <h1>Licenses</h1>
            <p class="page-subtitle">
                <?= $totalLicenses ?> license<?= $totalLicenses === 1 ? '' : 's' ?>
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
        <form method="get">
            <?php if ($customerFilter): ?><input type="hidden" name="customer_id" value="<?= $customerFilter ?>"><?php endif; ?>
            <?php if ($statusFilter !== 'all'): ?><input type="hidden" name="status" value="<?= htmlspecialchars($statusFilter, ENT_QUOTES) ?>"><?php endif; ?>
            <label class="app-search" for="license-search">
                <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m16.5 16.5 4 4"/></svg>
                <input id="license-search" name="q" type="search" value="<?= htmlspecialchars($searchQuery, ENT_QUOTES) ?>" placeholder="Search key, customer, or plan" autocomplete="off">
                <kbd id="license-result-count"><?= count($licenses) ?></kbd>
            </label>
        </form>
        <div class="pill-filters" id="license-filters" role="group" aria-label="Filter licenses">
            <?php foreach (['all' => 'All', 'active' => 'Active', 'expiring' => 'Expiring', 'expired' => 'Expired'] as $value => $label): ?>
                <a href="<?= htmlspecialchars($licensePageUrl(1, $value), ENT_QUOTES) ?>" class="pill-filter <?= $value === $statusFilter ? 'active' : '' ?>" <?= $value === $statusFilter ? 'aria-current="page"' : '' ?>>
                    <?= $label ?><span class="count"><?= $licenseCounts[$value] ?></span>
                </a>
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
        <div class="modern-table-wrapper">
            <table class="modern-table">
                <thead>
                    <tr>
                        <th>License Key</th>
                        <th>Customer</th>
                        <th>Plan</th>
                        <th>Status</th>
                        <th>Expires</th>
                        <th style="text-align: right;">Actions</th>
                    </tr>
                </thead>
                <tbody id="license-grid" aria-live="polite">
                    <?php foreach ($licenses as $l): ?>
                        <?php
                            $filterStatus = $l['is_expiring'] ? 'expiring' : $l['status'];
                            $searchText = implode(' ', [$l['license_key'], $l['customer_name'], $l['plan'], $l['status']]);
                            $shortKey = strlen($l['license_key']) > 16 ? '•••• ' . substr($l['license_key'], -12) : $l['license_key'];
                            
                            $badgeClass = 'badge-ok';
                            if ($filterStatus === 'expiring') $badgeClass = 'badge-pending';
                            if ($filterStatus === 'expired' || $filterStatus === 'suspended') $badgeClass = 'badge-expired';
                            if ($filterStatus === 'revoked') $badgeClass = 'badge-revoked';
                        ?>
                        <tr data-license-card data-status="<?= htmlspecialchars($filterStatus) ?>" data-search="<?= htmlspecialchars($searchText, ENT_QUOTES) ?>">
                            <td>
                                <div class="cell-main" style="flex-direction:row; align-items:center; gap:8px;">
                                    <code dir="ltr" title="<?= htmlspecialchars($l['license_key'], ENT_QUOTES) ?>" style="font-size:13px; font-weight:600;"><?= htmlspecialchars($shortKey) ?></code>
                                    <button type="button" class="copy-key" style="background:transparent; border:none; cursor:pointer; color:var(--text-dim);" data-copy-key="<?= htmlspecialchars($l['license_key'], ENT_QUOTES) ?>" aria-label="Copy license key">
                                        <svg viewBox="0 0 24 24" style="width:14px; height:14px; stroke:currentColor; fill:none; stroke-width:2;"><rect x="8" y="8" width="11" height="11" rx="2"/><path d="M16 8V5H5v11h3"/></svg>
                                    </button>
                                </div>
                            </td>
                            <td>
                                <div class="cell-main">
                                    <strong><?= htmlspecialchars($l['customer_name']) ?></strong>
                                </div>
                            </td>
                            <td>
                                <span style="color:var(--text-dim); text-transform:capitalize;"><?= htmlspecialchars(str_replace('_', ' ', $l['plan'])) ?></span>
                            </td>
                            <td>
                                <span class="badge <?= $badgeClass ?>"><?= htmlspecialchars($filterStatus) ?></span>
                            </td>
                            <td>
                                <span style="color:var(--text-dim);"><?= $l['expires_at'] ? htmlspecialchars(date('M j, Y', strtotime($l['expires_at']))) : 'Never' ?></span>
                            </td>
                            <td>
                                <div class="cell-actions">
                                    <a href="/public/admin/license_detail.php?id=<?= $l['id'] ?>" class="table-btn">
                                        Manage
                                    </a>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($totalPages > 1): ?>
            <nav class="app-pagination" aria-label="License pages">
                <a href="<?= htmlspecialchars($licensePageUrl(max(1, $currentPage - 1)), ENT_QUOTES) ?>" class="<?= $currentPage <= 1 ? 'disabled' : '' ?>" <?= $currentPage <= 1 ? 'aria-disabled="true" tabindex="-1"' : '' ?>>Previous</a>
                <span>Page <strong><?= $currentPage ?></strong> of <?= $totalPages ?></span>
                <a href="<?= htmlspecialchars($licensePageUrl(min($totalPages, $currentPage + 1)), ENT_QUOTES) ?>" class="<?= $currentPage >= $totalPages ? 'disabled' : '' ?>" <?= $currentPage >= $totalPages ? 'aria-disabled="true" tabindex="-1"' : '' ?>>Next</a>
            </nav>
        <?php endif; ?>

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
    var resultCount = document.getElementById('license-result-count');
    var empty = document.getElementById('license-search-empty');
    function applyFilters() {
        var query = search ? search.value.trim().toLocaleLowerCase() : '';
        var visible = 0;
        cards.forEach(function (card) {
            var matchesSearch = card.dataset.search.toLocaleLowerCase().includes(query);
            var show = matchesSearch;
            card.hidden = !show;
            if (show) visible++;
        });
        if (resultCount) resultCount.textContent = visible;
        if (empty) empty.hidden = visible !== 0;
    }

    if (search) search.addEventListener('input', applyFilters);
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
