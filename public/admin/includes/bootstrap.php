<?php
require_once __DIR__ . '/../../../includes/Database.php';
require_once __DIR__ . '/../../../includes/Auth.php';
require_once __DIR__ . '/../../../includes/Csrf.php';
require_once __DIR__ . '/../../../includes/License.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function render_header(string $title): void
{
    $username = Auth::currentUsername();
    $currentPage = basename($_SERVER['PHP_SELF'] ?? '');
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#151b23">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<title><?= htmlspecialchars($title) ?> — Hercule License Admin</title>
<link rel="stylesheet" href="/public/admin/assets/css/style.css?v=license-detail-v2">
</head>
<body>
<div class="shell">
    <header class="topbar">
        <div class="brand"><span class="brand-mark" aria-hidden="true">H</span><span class="brand-copy"><strong>Hercule</strong><small>License Admin</small></span></div>
        <?php if ($username): ?>
        <nav class="nav" id="admin-navigation" aria-label="Primary navigation">
            <a href="/public/admin/index.php" class="<?= $currentPage === 'index.php' ? 'active' : '' ?>">
                <svg class="nav-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M3 11.5 12 4l9 7.5v8a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1z"/></svg><span>Dashboard</span>
            </a>
            <a href="/public/admin/customers.php" class="<?= $currentPage === 'customers.php' ? 'active' : '' ?>">
                <svg class="nav-icon" viewBox="0 0 24 24" aria-hidden="true"><circle cx="9" cy="8" r="3"/><path d="M3.5 20c.2-4 2-6 5.5-6s5.3 2 5.5 6M16 5.5a3 3 0 0 1 0 5.8M16 14c3 0 4.5 2 4.5 5"/></svg><span>Customers</span>
            </a>
            <a href="/public/admin/licenses.php" class="<?= in_array($currentPage, ['licenses.php', 'license_detail.php'], true) ? 'active' : '' ?>">
                <svg class="nav-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h10a4 4 0 0 1 0 8H9l-3 3v-3H4a4 4 0 0 1 0-8z"/><circle cx="14" cy="11" r="1"/></svg><span>Licenses</span>
            </a>
            <a href="/public/admin/recovery_requests.php" class="<?= $currentPage === 'recovery_requests.php' ? 'active' : '' ?>">
                <svg class="nav-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M4 12a8 8 0 1 0 2.3-5.7L4 8.5M4 4v4.5h4.5"/><path d="M12 8v4l3 2"/></svg><span>Recovery</span>
            </a>
        </nav>
        <div class="account-area">
            <button class="nav-toggle account-button" type="button" aria-label="Open account menu" aria-controls="account-menu" aria-expanded="false">
                <span class="account-avatar" aria-hidden="true"><?= strtoupper(htmlspecialchars(substr($username, 0, 1))) ?></span>
                <span class="account-button-text">Account</span>
                <svg class="account-chevron" viewBox="0 0 24 24" aria-hidden="true"><path d="m7 9 5 5 5-5"/></svg>
            </button>
            <div class="account-menu" id="account-menu">
                <div class="account-menu-header">
                    <span class="account-avatar account-avatar-large" aria-hidden="true"><?= strtoupper(htmlspecialchars(substr($username, 0, 1))) ?></span>
                    <span class="nav-user"><small>Signed in as</small><strong><?= htmlspecialchars($username) ?></strong></span>
                </div>
                <a href="/public/admin/change_password.php" class="<?= $currentPage === 'change_password.php' ? 'active' : '' ?>">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="5" y="10" width="14" height="10" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3M12 14v2"/></svg>
                    <span>Change password</span>
                </a>
                <a href="/public/admin/logout.php" class="nav-logout">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M10 5H5v14h5M14 8l4 4-4 4M8 12h10"/></svg>
                    <span>Log out</span>
                </a>
            </div>
        </div>
        <?php endif; ?>
    </header>
    <main class="content">
    <?php
}

function render_footer(): void
{
    ?>
    </main>
</div>
<script>
(function () {
    var toggle = document.querySelector(".nav-toggle");
    var menu = document.getElementById("account-menu");

    if (toggle && menu) {
        toggle.addEventListener("click", function () {
            var open = menu.classList.toggle("is-open");
            toggle.setAttribute("aria-expanded", open ? "true" : "false");
        });

        document.addEventListener("click", function (event) {
            if (!event.target.closest(".account-area")) {
                menu.classList.remove("is-open");
                toggle.setAttribute("aria-expanded", "false");
            }
        });
    }

    document.querySelectorAll(".data-table").forEach(function (table) {
        var labels = Array.from(table.querySelectorAll("thead th")).map(function (th) {
            return th.textContent.trim() || "Action";
        });

        table.querySelectorAll("tbody tr").forEach(function (row) {
            Array.from(row.children).forEach(function (cell, index) {
                cell.setAttribute("data-label", labels[index] || "");
            });
        });
    });
})();
</script>
</body>
</html>
    <?php
}

function flash_set(string $message, string $type = 'success'): void
{
    $_SESSION['flash'] = ['message' => $message, 'type' => $type];
}

function flash_render(): void
{
    if (!empty($_SESSION['flash'])) {
        $f = $_SESSION['flash'];
        $class = $f['type'] === 'error' ? 'flash-error' : 'flash-success';
        echo '<div class="flash ' . $class . '">' . htmlspecialchars($f['message']) . '</div>';
        unset($_SESSION['flash']);
    }
}
