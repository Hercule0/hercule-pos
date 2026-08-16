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
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= htmlspecialchars($title) ?> — Hercule License Admin</title>
<link rel="stylesheet" href="/public/admin/assets/css/style.css">
</head>
<body>
<div class="shell">
    <header class="topbar">
        <div class="brand">Hercule <span>License Admin</span></div>
        <?php if ($username): ?>
        <button class="nav-toggle" type="button" aria-label="Toggle navigation" aria-controls="admin-navigation" aria-expanded="false">
            <span></span><span></span><span></span>
        </button>
        <nav class="nav" id="admin-navigation">
            <a href="/public/admin/index.php">Dashboard</a>
            <a href="/public/admin/customers.php">Customers</a>
            <a href="/public/admin/licenses.php">Licenses</a>
            <a href="/public/admin/recovery_requests.php">Recovery Requests</a>
            <span class="nav-user">signed in as <?= htmlspecialchars($username) ?></span>
            <a href="/public/admin/change_password.php">Change password</a>
            <a href="/public/admin/logout.php" class="nav-logout">Log out</a>
        </nav>
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
    var nav = document.getElementById("admin-navigation");
    if (!toggle || !nav) return;

    toggle.addEventListener("click", function () {
        var open = nav.classList.toggle("is-open");
        toggle.setAttribute("aria-expanded", open ? "true" : "false");
    });

    nav.addEventListener("click", function (event) {
        if (event.target.tagName === "A" && window.innerWidth <= 900) {
            nav.classList.remove("is-open");
            toggle.setAttribute("aria-expanded", "false");
        }
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
