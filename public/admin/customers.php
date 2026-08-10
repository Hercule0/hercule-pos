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

$customers = $pdo->query(
    "SELECT c.*, COUNT(l.id) AS license_count
     FROM customers c LEFT JOIN licenses l ON l.customer_id = c.id
     GROUP BY c.id ORDER BY c.created_at DESC"
)->fetchAll();

render_header('Customers');
flash_render();
?>

<h1>Customers</h1>

<div class="panel-grid">
    <div class="panel">
        <h2>Add Customer</h2>
        <form method="post" class="stacked-form">
            <?= Csrf::field() ?>
            <input type="hidden" name="form_action" value="add">
            <label>Name *</label>
            <input type="text" name="name" required>
            <label>Email</label>
            <input type="email" name="email">
            <label>Phone</label>
            <input type="text" name="phone">
            <label>Notes</label>
            <textarea name="notes" rows="3"></textarea>
            <button type="submit" class="primary-btn">Add Customer</button>
        </form>
    </div>

    <div class="panel panel-wide">
        <h2>All Customers</h2>
        <table class="data-table">
            <thead><tr><th>Name</th><th>Email</th><th>Phone</th><th>Licenses</th><th>Added</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($customers as $c): ?>
                <tr>
                    <td><?= htmlspecialchars($c['name']) ?></td>
                    <td><?= htmlspecialchars($c['email'] ?? '—') ?></td>
                    <td><?= htmlspecialchars($c['phone'] ?? '—') ?></td>
                    <td><?= (int) $c['license_count'] ?></td>
                    <td><?= htmlspecialchars($c['created_at']) ?></td>
                    <td>
                        <a href="/public/admin/licenses.php?customer_id=<?= $c['id'] ?>" class="icon-link">View licenses</a>
                        <form method="post" style="display:inline; margin-left:8px;" onsubmit="return confirm('Delete customer &quot;<?= htmlspecialchars($c['name'], ENT_QUOTES) ?>&quot; and ALL <?= (int) $c['license_count'] ?> of their licenses? This is irreversible.');">
                            <?= Csrf::field() ?>
                            <input type="hidden" name="form_action" value="delete">
                            <input type="hidden" name="customer_id" value="<?= $c['id'] ?>">
                            <button type="submit" class="icon-btn" style="color: var(--danger);">Delete</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($customers)): ?>
                <tr><td colspan="6" class="muted">No customers yet.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php render_footer(); ?>
