<?php
require_once __DIR__ . '/includes/bootstrap.php';
Auth::require();

$pdo = Database::pdo();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::guard();
    $formAction = $_POST['form_action'] ?? 'add';

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

$sql = "SELECT c.*, COUNT(l.id) AS license_count
        FROM customers c
        LEFT JOIN licenses l ON l.customer_id = c.id"
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
        <section class="customer-grid" id="customer-grid" aria-live="polite">
            <?php foreach ($customers as $c): ?>
                <?php
                    $searchText = implode(' ', [
                        $c['name'] ?? '',
                        $c['email'] ?? '',
                        $c['phone'] ?? ''
                    ]);
                ?>
                <article class="customer-card" data-customer-card data-search="<?= htmlspecialchars($searchText, ENT_QUOTES) ?>">
                    <div class="customer-card-head">
                        <span class="customer-avatar" aria-hidden="true"><?= strtoupper(htmlspecialchars(substr($c['name'], 0, 1))) ?></span>
                        <div class="customer-identity">
                            <h2 dir="auto"><?= htmlspecialchars($c['name']) ?></h2>
                            <span>Added <?= htmlspecialchars(date('M j, Y', strtotime($c['created_at']))) ?></span>
                        </div>
                        <details class="card-menu">
                            <summary aria-label="Customer actions">
                                <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="5" cy="12" r="1"/><circle cx="12" cy="12" r="1"/><circle cx="19" cy="12" r="1"/></svg>
                            </summary>
                            <div class="card-menu-popover">
                                <a href="/public/admin/licenses.php?customer_id=<?= $c['id'] ?>">
                                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h10a4 4 0 0 1 0 8H9l-3 3v-3H4a4 4 0 0 1 0-8z"/></svg>
                                    View licenses
                                </a>
                                <form method="post" onsubmit="return confirm('Delete customer &quot;<?= htmlspecialchars($c['name'], ENT_QUOTES) ?>&quot; and ALL <?= (int) $c['license_count'] ?> of their licenses? This is irreversible.');">
                                    <?= Csrf::field() ?>
                                    <input type="hidden" name="form_action" value="delete">
                                    <input type="hidden" name="customer_id" value="<?= $c['id'] ?>">
                                    <button type="submit" class="menu-danger">
                                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 7h14M9 7V4h6v3M8 10v7M12 10v7M16 10v7M6 7l1 13h10l1-13"/></svg>
                                        Delete customer
                                    </button>
                                </form>
                            </div>
                        </details>
                    </div>

                    <div class="customer-contact">
                        <a href="<?= $c['phone'] ? 'tel:' . htmlspecialchars($c['phone'], ENT_QUOTES) : '#' ?>" class="<?= $c['phone'] ? '' : 'is-empty' ?>">
                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 4 4.5 6.5c.5 6.5 6.5 12.5 13 13L20 17l-4-3-2 2c-2.5-1-5-3.5-6-6l2-2z"/></svg>
                            <span dir="auto"><?= htmlspecialchars($c['phone'] ?? 'No phone number') ?></span>
                        </a>
                        <a href="<?= $c['email'] ? 'mailto:' . htmlspecialchars($c['email'], ENT_QUOTES) : '#' ?>" class="<?= $c['email'] ? '' : 'is-empty' ?>">
                            <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m4 7 8 6 8-6"/></svg>
                            <span dir="auto"><?= htmlspecialchars($c['email'] ?? 'No email address') ?></span>
                        </a>
                    </div>

                    <a href="/public/admin/licenses.php?customer_id=<?= $c['id'] ?>" class="customer-card-footer">
                        <span>
                            <strong><?= (int) $c['license_count'] ?></strong>
                            license<?= (int) $c['license_count'] === 1 ? '' : 's' ?>
                        </span>
                        <span class="view-customer">
                            View
                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m9 6 6 6-6 6"/></svg>
                        </span>
                    </a>
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
