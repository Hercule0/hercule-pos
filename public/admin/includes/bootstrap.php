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
<link rel="stylesheet" href="/public/admin/assets/css/style.css?v=mobile-app-3">
</head>
<body>
<div class="shell">
    <header class="topbar">
        <div class="brand">Hercule <span>License Admin</span></div>
        <?php if ($username): ?>
        <nav class="nav" id="admin-navigation" aria-label="Primary navigation">
            <a href="/public/admin/index.php" class="<?= $currentPage === 'index.php' ? 'active' : '' ?>">
                <span class="nav-icon" aria-hidden="true">⌂</span><span>Dashboard</span>
            </a>
            <a href="/public/admin/customers.php" class="<?= $currentPage === 'customers.php' ? 'active' : '' ?>">
                <span class="nav-icon" aria-hidden="true">♙</span><span>Customers</span>
            </a>
            <a href="/public/admin/licenses.php" class="<?= in_array($currentPage, ['licenses.php', 'license_detail.php'], true) ? 'active' : '' ?>">
                <span class="nav-icon" aria-hidden="true">◇</span><span>Licenses</span>
            </a>
            <a href="/public/admin/recovery_requests.php" class="<?= $currentPage === 'recovery_requests.php' ? 'active' : '' ?>">
                <span class="nav-icon" aria-hidden="true">↺</span><span>Recovery</span>
            </a>
        </nav>
        <div class="account-area">
            <button class="nav-toggle" type="button" aria-label="Open account menu" aria-controls="account-menu" aria-expanded="false">
                <span class="account-avatar" aria-hidden="true"><?= strtoupper(htmlspecialchars(substr($username, 0, 1))) ?></span>
            </button>
            <div class="account-menu" id="account-menu">
                <span class="nav-user">Signed in as <strong><?= htmlspecialchars($username) ?></strong></span>
                <a href="/public/admin/change_password.php">Change password</a>
                <a href="/public/admin/logout.php" class="nav-logout">Log out</a>
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
