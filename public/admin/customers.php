<?php
require_once __DIR__ . '/includes/bootstrap.php';
Auth::require();

$pdo = Database::pdo();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::guard();
    $formAction = $_POST['form_action'] ?? 'add';
    Auth::requirePermission('customers.manage');

    if ($formAction === 'delete') {
        $customerId = (int) ($_POST['customer_id'] ?? 0);
        $nameStmt = $pdo->prepare('SELECT name FROM customers WHERE id = ?');
        $nameStmt->execute([$customerId]);
        $name = $nameStmt->fetchColumn();

        $stmt = $pdo->prepare('DELETE FROM customers WHERE id = ?');
        $stmt->execute([$customerId]);

        flash_set($name ? "Customer \"{$name}\" and all their licenses deleted." : 'Customer deleted.');
        header('Location: /public/admin/customers.php');
        exit;
    }

    // --- Add customer ---
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '') ?: null;
    $phone = trim($_POST['phone'] ?? '') ?: null;
    $notes = trim($_POST['notes'] ?? '') ?: null;

    if ($name === '') {
        flash_set('Customer name is required.', 'error');
    } else {
        $stmt = $pdo->prepare('INSERT INTO customers (name, email, phone, notes) VALUES (?, ?, ?, ?)');
        $stmt->execute([$name, $email, $phone, $notes]);
        flash_set("Customer \"{$name}\" added.");
    }
    header('Location: /public/admin/customers.php');
    exit;
}

$searchQuery = mb_substr(trim($_GET['q'] ?? ''), 0, 100);
$currentPage = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 24;
$whereSql = '';
$params = [];

if ($searchQuery !== '') {
    $whereSql = ' WHERE c.name LIKE ? OR c.email LIKE ? OR c.phone LIKE ?';
    $pattern = '%' . $searchQuery . '%';
    $params = [$pattern, $pattern, $pattern];
}

$countStmt = $pdo->prepare('SELECT COUNT(*) FROM customers c' . $whereSql);
$countStmt->execute($params);
$totalCustomers = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($totalCustomers / $perPage));
$currentPage = min($currentPage, $totalPages);
$offset = ($currentPage - 1) * $perPage;

$sql = "SELECT c.*, 
            COUNT(DISTINCT l.id) AS license_count,
            COUNT(DISTINCT a.id) AS device_count
        FROM customers c
        LEFT JOIN licenses l ON l.customer_id = c.id
        LEFT JOIN license_activations a ON a.license_id = l.id AND a.is_active = 1"
        . $whereSql .
       " GROUP BY c.id
         ORDER BY c.created_at DESC
         LIMIT ? OFFSET ?";
$stmt = $pdo->prepare($sql);
$position = 1;
foreach ($params as $value) {
    $stmt->bindValue($position++, $value, PDO::PARAM_STR);
}
$stmt->bindValue($position++, $perPage, PDO::PARAM_INT);
$stmt->bindValue($position, $offset, PDO::PARAM_INT);
$stmt->execute();
$customers = $stmt->fetchAll();

$customerPageUrl = static function (int $page) use ($searchQuery): string {
    $query = ['page' => $page];
    if ($searchQuery !== '') $query['q'] = $searchQuery;
    return '/public/admin/customers.php?' . http_build_query($query);
};

render_header('Customers');
flash_render();
?>

<div class="customers-page">
    <section class="page-hero customers-hero">
        <div>
            <p class="eyebrow">People</p>
            <h1>Customers</h1>
            <p class="page-subtitle"><span id="customer-count"><?= $totalCustomers ?></span> customer<?= $totalCustomers === 1 ? '' : 's' ?><?= $searchQuery !== '' ? ' matching your search' : ' in your workspace' ?>.</p>
        </div>
        <button type="button" class="app-primary-action" data-open-customer-dialog>
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>
            <span>Add customer</span>
        </button>
    </section>

    <form class="customer-toolbar" method="get" aria-label="Customer search">
        <label class="app-search" for="customer-search">
            <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m16.5 16.5 4 4"/></svg>
            <input id="customer-search" name="q" type="search" value="<?= htmlspecialchars($searchQuery, ENT_QUOTES) ?>" placeholder="Search by name, phone, or email" autocomplete="off">
            <kbd id="search-result-count"><?= count($customers) ?></kbd>
        </label>
    </form>

    <?php if (empty($customers)): ?>
        <section class="customers-empty">
            <span class="empty-illustration">
                <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="9" cy="8" r="3"/><path d="M3.5 20c.2-4 2-6 5.5-6s5.3 2 5.5 6M17 9v6M14 12h6"/></svg>
            </span>
            <?php if ($searchQuery !== ''): ?>
                <h2>No customers found</h2>
                <p>Try another name, phone number, or email address.</p>
                <a href="/public/admin/customers.php" class="app-primary-action">Clear search</a>
            <?php else: ?>
                <h2>Add your first customer</h2>
                <p>Customers keep contact details and licenses organized in one place.</p>
                <button type="button" class="app-primary-action" data-open-customer-dialog>Add customer</button>
            <?php endif; ?>
        </section>
    <?php else: ?>
        <section class="grid-cards-wrapper" id="customer-grid" aria-live="polite">
            <?php foreach ($customers as $c): ?>
                <?php
                    $searchText = implode(' ', [
                        $c['name'] ?? '',
                        $c['email'] ?? '',
                        $c['phone'] ?? ''
                    ]);
                ?>
                <article class="grid-card" data-customer-card data-search="<?= htmlspecialchars($searchText, ENT_QUOTES) ?>">
                    <div class="grid-card-header">
                        <div class="grid-card-avatar" aria-hidden="true"><?= strtoupper(htmlspecialchars(substr($c['name'], 0, 1))) ?></div>
                        <div class="grid-card-title-group">
                            <h2 class="grid-card-title" dir="auto"><?= htmlspecialchars($c['name']) ?></h2>
                            <div class="grid-card-subtitle">ID #<?= $c['id'] ?> • Added <?= htmlspecialchars(date('Y-m-d', strtotime($c['created_at']))) ?></div>
                        </div>
                        <div class="grid-card-actions">
                            <form method="post" onsubmit="return confirm('Delete customer &quot;<?= htmlspecialchars($c['name'], ENT_QUOTES) ?>&quot; and ALL <?= (int) $c['license_count'] ?> of their licenses? This is irreversible.');" style="display:inline;">
                                <?= Csrf::field() ?>
                                <input type="hidden" name="form_action" value="delete">
                                <input type="hidden" name="customer_id" value="<?= $c['id'] ?>">
                                <button type="submit" class="grid-card-action-btn" title="Delete customer">
                                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 6h18M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                                </button>
                            </form>
                        </div>
                    </div>

                    <div class="grid-card-body">
                        <div class="grid-card-info-row">
                            <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m4 7 8 6 8-6"/></svg>
                            <span dir="auto"><?= htmlspecialchars($c['email'] ?? 'No email address') ?></span>
                        </div>
                        <div class="grid-card-info-row">
                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
                            <span dir="auto"><?= htmlspecialchars($c['phone'] ?? 'No phone number') ?></span>
                        </div>
                        <?php if (!empty($c['notes'])): ?>
                            <div class="grid-card-note"><?= htmlspecialchars($c['notes']) ?></div>
                        <?php endif; ?>
                    </div>

                    <div class="grid-card-footer">
                        <div class="grid-card-stats">
                            <strong><?= (int) $c['license_count'] ?> active license<?= (int) $c['license_count'] === 1 ? '' : 's' ?></strong><br>
                            <span class="text-emerald"><?= (int) $c['device_count'] ?? 0 ?> bound POS devices</span>
                        </div>
                        <a href="/public/admin/licenses.php?action=new&customer_id=<?= $c['id'] ?>" class="grid-card-btn">
                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg> Issue Key
                        </a>
                    </div>
                </article>
            <?php endforeach; ?>
        </section>

        <?php if ($totalPages > 1): ?>
            <nav class="app-pagination" aria-label="Customer pages">
                <a href="<?= htmlspecialchars($customerPageUrl(max(1, $currentPage - 1)), ENT_QUOTES) ?>" class="<?= $currentPage <= 1 ? 'disabled' : '' ?>" <?= $currentPage <= 1 ? 'aria-disabled="true" tabindex="-1"' : '' ?>>Previous</a>
                <span>Page <strong><?= $currentPage ?></strong> of <?= $totalPages ?></span>
                <a href="<?= htmlspecialchars($customerPageUrl(min($totalPages, $currentPage + 1)), ENT_QUOTES) ?>" class="<?= $currentPage >= $totalPages ? 'disabled' : '' ?>" <?= $currentPage >= $totalPages ? 'aria-disabled="true" tabindex="-1"' : '' ?>>Next</a>
            </nav>
        <?php endif; ?>

        <div class="search-empty" id="customer-search-empty" hidden>
            <strong>No matching customers</strong>
            <p>Try a different name, phone number, or email.</p>
        </div>
    <?php endif; ?>
</div>

<button type="button" class="customer-fab" data-open-customer-dialog aria-label="Add customer">
    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>
</button>

<dialog class="app-dialog" id="customer-dialog">
    <form method="post" class="customer-form">
        <?= Csrf::field() ?>
        <input type="hidden" name="form_action" value="add">
        <div class="dialog-header">
            <div>
                <p class="eyebrow">New record</p>
                <h2>Add customer</h2>
            </div>
            <button type="button" class="dialog-close" data-close-customer-dialog aria-label="Close">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m6 6 12 12M18 6 6 18"/></svg>
            </button>
        </div>
        <div class="dialog-fields">
            <label>
                <span>Name *</span>
                <input type="text" name="name" required autofocus placeholder="Customer name" autocomplete="name">
            </label>
            <label>
                <span>Phone</span>
                <input type="tel" name="phone" placeholder="Phone number" autocomplete="tel" inputmode="tel">
            </label>
            <label>
                <span>Email</span>
                <input type="email" name="email" placeholder="Email address" autocomplete="email" inputmode="email">
            </label>
            <label>
                <span>Notes</span>
                <textarea name="notes" rows="3" placeholder="Optional notes"></textarea>
            </label>
        </div>
        <div class="dialog-actions">
            <button type="button" class="secondary-btn" data-close-customer-dialog>Cancel</button>
            <button type="submit" class="primary-btn">Save customer</button>
        </div>
    </form>
</dialog>

<script>
(function () {
    var dialog = document.getElementById('customer-dialog');
    document.querySelectorAll('[data-open-customer-dialog]').forEach(function (button) {
        button.addEventListener('click', function () {
            if (dialog && typeof dialog.showModal === 'function') dialog.showModal();
            else if (dialog) dialog.setAttribute('open', '');
        });
    });
    document.querySelectorAll('[data-close-customer-dialog]').forEach(function (button) {
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

    var search = document.getElementById('customer-search');
    var cards = Array.from(document.querySelectorAll('[data-customer-card]'));
    var resultCount = document.getElementById('search-result-count');
    var empty = document.getElementById('customer-search-empty');

    if (search) {
        search.addEventListener('input', function () {
            var query = search.value.trim().toLocaleLowerCase();
            var visible = 0;
            cards.forEach(function (card) {
                var matches = card.dataset.search.toLocaleLowerCase().includes(query);
                card.hidden = !matches;
                if (matches) visible++;
            });
            if (resultCount) resultCount.textContent = visible;
            if (empty) empty.hidden = visible !== 0;
        });
    }
})();
</script>

<?php render_footer(); ?>
