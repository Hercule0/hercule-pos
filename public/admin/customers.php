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
        header('Location: /public/admin/customers.php'); exit;
    }

    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '') ?: null;
    $phone = trim($_POST['phone'] ?? '') ?: null;
    $notes = trim($_POST['notes'] ?? '') ?: null;
    if ($name === '') flash_set('Customer name is required.', 'error');
    else {
        $stmt = $pdo->prepare('INSERT INTO customers (name, email, phone, notes) VALUES (?, ?, ?, ?)');
        $stmt->execute([$name, $email, $phone, $notes]);
        flash_set("Customer \"{$name}\" added.");
    }
    header('Location: /public/admin/customers.php'); exit;
}

$searchQuery = mb_substr(trim($_GET['q'] ?? ''), 0, 100);
$currentPage = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 12;
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
$sql = "SELECT c.*, COUNT(DISTINCT l.id) AS license_count, COUNT(DISTINCT a.id) AS device_count
        FROM customers c LEFT JOIN licenses l ON l.customer_id = c.id
        LEFT JOIN license_activations a ON a.license_id = l.id AND a.is_active = 1" . $whereSql .
       " GROUP BY c.id ORDER BY c.created_at DESC LIMIT ? OFFSET ?";
$stmt = $pdo->prepare($sql); $position = 1;
foreach ($params as $value) $stmt->bindValue($position++, $value, PDO::PARAM_STR);
$stmt->bindValue($position++, $perPage, PDO::PARAM_INT); $stmt->bindValue($position, $offset, PDO::PARAM_INT); $stmt->execute();
$customers = $stmt->fetchAll();
$customerPageUrl = static function (int $page) use ($searchQuery): string {
    $query = ['page' => $page]; if ($searchQuery !== '') $query['q'] = $searchQuery;
    return '/public/admin/customers.php?' . http_build_query($query);
};
$rangeStart = $totalCustomers ? $offset + 1 : 0;
$rangeEnd = min($offset + count($customers), $totalCustomers);

render_header('Customers'); flash_render();
?>
<div class="customers-page">
<section class="page-hero customers-hero"><div><p class="eyebrow">People</p><h1>Customers</h1><p class="page-subtitle"><span id="customer-count"><?= $totalCustomers ?></span> customer<?= $totalCustomers === 1 ? '' : 's' ?><?= $searchQuery !== '' ? ' matching your search' : ' in your workspace' ?>.</p></div><button type="button" class="app-primary-action" data-open-customer-dialog><svg viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg><span>Add customer</span></button></section>
<form class="customer-toolbar" method="get" aria-label="Customer search"><label class="app-search" for="customer-search"><svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="m16.5 16.5 4 4"/></svg><input id="customer-search" name="q" type="search" value="<?= htmlspecialchars($searchQuery, ENT_QUOTES) ?>" placeholder="Search by name, phone, or email" autocomplete="off"><kbd id="search-result-count"><?= count($customers) ?></kbd></label></form>
<?php if (empty($customers)): ?><section class="customers-empty"><h2>No customers found</h2><p><?= $searchQuery !== '' ? 'Try another name, phone number, or email address.' : 'Add your first customer to get started.' ?></p><?php if($searchQuery!==''):?><a href="/public/admin/customers.php" class="app-primary-action">Clear search</a><?php else:?><button type="button" class="app-primary-action" data-open-customer-dialog>Add customer</button><?php endif;?></section>
<?php else: ?><div class="list-range-summary">Showing <strong><?= $rangeStart ?>–<?= $rangeEnd ?></strong> of <strong><?= $totalCustomers ?></strong> customers</div><section class="grid-cards-wrapper" id="customer-grid" aria-live="polite">
<?php foreach ($customers as $c): $searchText=implode(' ',[$c['name']??'',$c['email']??'',$c['phone']??'']); ?>
<article class="grid-card" data-customer-card data-search="<?= htmlspecialchars($searchText, ENT_QUOTES) ?>"><div class="grid-card-header"><div class="grid-card-avatar"><?= strtoupper(htmlspecialchars(substr($c['name'],0,1))) ?></div><div class="grid-card-title-group"><h2 class="grid-card-title" dir="auto"><?= htmlspecialchars($c['name']) ?></h2><div class="grid-card-subtitle">ID #<?= $c['id'] ?> • Added <?= htmlspecialchars(date('Y-m-d',strtotime($c['created_at']))) ?></div></div><div class="grid-card-actions"><form method="post" onsubmit="return confirm('Delete this customer and ALL their licenses? This is irreversible.');"><?= Csrf::field() ?><input type="hidden" name="form_action" value="delete"><input type="hidden" name="customer_id" value="<?= $c['id'] ?>"><button type="submit" class="grid-card-action-btn" title="Delete customer">×</button></form></div></div><div class="grid-card-body"><div class="grid-card-info-row"><span>Email</span><span dir="auto"><?= htmlspecialchars($c['email']??'No email address') ?></span></div><div class="grid-card-info-row"><span>Phone</span><span dir="auto"><?= htmlspecialchars($c['phone']??'No phone number') ?></span></div><?php if(!empty($c['notes'])):?><div class="grid-card-note"><?= htmlspecialchars($c['notes']) ?></div><?php endif;?></div><div class="grid-card-footer"><div class="grid-card-stats"><strong><?= (int)$c['license_count'] ?> license<?= (int)$c['license_count']===1?'':'s' ?></strong><br><span class="text-emerald"><?= (int)$c['device_count'] ?> bound POS devices</span></div><a href="/public/admin/licenses.php?action=new&customer_id=<?= $c['id'] ?>" class="grid-card-btn">Issue Key</a></div></article>
<?php endforeach;?></section>
<?php if($totalPages>1):?><nav class="app-pagination enhanced-pagination" aria-label="Customer pages"><a href="<?=htmlspecialchars($customerPageUrl(max(1,$currentPage-1)),ENT_QUOTES)?>" class="<?=$currentPage<=1?'disabled':''?>">Previous</a><form method="get" class="page-jump-form"><?php if($searchQuery!==''):?><input type="hidden" name="q" value="<?=htmlspecialchars($searchQuery,ENT_QUOTES)?>"><?php endif;?><label>Page <select name="page" onchange="this.form.submit()"><?php for($p=1;$p<=$totalPages;$p++):?><option value="<?=$p?>" <?=$p===$currentPage?'selected':''?>><?=$p?></option><?php endfor;?></select> of <?=$totalPages?></label></form><a href="<?=htmlspecialchars($customerPageUrl(min($totalPages,$currentPage+1)),ENT_QUOTES)?>" class="<?=$currentPage>=$totalPages?'disabled':''?>">Next</a></nav><?php endif;?>
<?php endif;?></div>
<button type="button" class="customer-fab" data-open-customer-dialog aria-label="Add customer"><svg viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg></button>
<dialog class="app-dialog" id="customer-dialog"><form method="post" class="customer-form"><?=Csrf::field()?><input type="hidden" name="form_action" value="add"><div class="dialog-header"><div><p class="eyebrow">New record</p><h2>Add customer</h2></div><button type="button" class="dialog-close" data-close-customer-dialog>×</button></div><div class="dialog-fields"><label><span>Name *</span><input type="text" name="name" required autofocus autocomplete="name"></label><label><span>Phone</span><input type="tel" name="phone" autocomplete="tel"></label><label><span>Email</span><input type="email" name="email" autocomplete="email"></label><label><span>Notes</span><textarea name="notes" rows="3"></textarea></label></div><div class="dialog-actions"><button type="button" class="secondary-btn" data-close-customer-dialog>Cancel</button><button type="submit" class="primary-btn">Save customer</button></div></form></dialog>
<script>(function(){var d=document.getElementById('customer-dialog');document.querySelectorAll('[data-open-customer-dialog]').forEach(b=>b.addEventListener('click',()=>d.showModal?d.showModal():d.setAttribute('open','')));document.querySelectorAll('[data-close-customer-dialog]').forEach(b=>b.addEventListener('click',()=>d.close?d.close():d.removeAttribute('open')));if(d)d.addEventListener('click',e=>{if(e.target===d)d.close()});})();</script>
<?php render_footer(); ?>