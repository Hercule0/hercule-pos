<?php
require_once __DIR__ . '/includes/bootstrap.php';

if (Auth::isLoggedIn()) {
    header('Location: /public/admin/index.php');
    exit;
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::guard();
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $result = Auth::attemptLogin($username, $password, $_SERVER['REMOTE_ADDR'] ?? 'unknown');

    if ($result['ok']) {
        header('Location: /public/admin/index.php');
        exit;
    }
    $error = $result['error'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Log in — Hercule License Admin</title>
<link rel="stylesheet" href="/public/admin/assets/css/style.css">
</head>
<body class="login-body">
    <form class="login-card" method="post">
        <h1>Hercule <span>License Admin</span></h1>
        <?php if ($error): ?>
            <div class="flash flash-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <?= Csrf::field() ?>
        <label>Username</label>
        <input type="text" name="username" autofocus required>
        <label>Password</label>
        <input type="password" name="password" required>
        <button type="submit" class="primary-btn">Log in</button>
    </form>
</body>
</html>
