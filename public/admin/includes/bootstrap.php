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
<title><?= htmlspecialchars($title) ?> — Hercule License Admin</title>
<link rel="stylesheet" href="/public/admin/assets/css/style.css">
</head>
<body>
<div class="shell">
    <header class="topbar">
        <div class="brand">Hercule <span>License Admin</span></div>
        <?php if ($username): ?>
        <nav class="nav">
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
