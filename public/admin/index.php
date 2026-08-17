<?php
require_once __DIR__ . '/includes/bootstrap.php';
Auth::require();

// Find latest compiled JS & CSS bundle dynamically
$jsFiles = glob(__DIR__ . '/../assets/index-*.js');
if (empty($jsFiles)) {
    $jsFiles = glob(__DIR__ . '/../../assets/index-*.js');
}
if (empty($jsFiles)) {
    $jsFiles = glob(__DIR__ . '/../../dist/assets/index-*.js');
}

$cssFiles = glob(__DIR__ . '/../assets/index-*.css');
if (empty($cssFiles)) {
    $cssFiles = glob(__DIR__ . '/../../assets/index-*.css');
}
if (empty($cssFiles)) {
    $cssFiles = glob(__DIR__ . '/../../dist/assets/index-*.css');
}

$jsAsset = !empty($jsFiles) ? '/assets/' . basename(end($jsFiles)) : '/assets/index-H1hhHJSG.js';
$cssAsset = !empty($cssFiles) ? '/assets/' . basename(end($cssFiles)) : '/assets/index-uZ3tUJ2E.css';

$username = Auth::currentUsername();
$role = Auth::currentRole();
?>
<!DOCTYPE html>
<html lang="en" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <meta name="theme-color" content="#0b0f15">
    <title>Hercule POS License Server — Admin Console</title>
    <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%2338bdf8' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'><rect width='18' height='11' x='3' y='11' rx='2' ry='2'/><path d='M7 11V7a5 5 0 0 1 10 0v4'/></svg>">
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;500;600;700&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    
    <link rel="stylesheet" crossorigin href="<?= htmlspecialchars($cssAsset) ?>">
    <link rel="stylesheet" crossorigin href="/public<?= htmlspecialchars($cssAsset) ?>">
    
    <script>
        window.HERCULE_BOOTSTRAP = {
            authenticated: true,
            user: {
                id: <?= (int)Auth::currentUserId() ?>,
                username: <?= json_encode($username) ?>,
                role: <?= json_encode($role) ?>
            },
            apiEndpoint: '/public/admin/api.php'
        };
    </script>
</head>
<body class="bg-[#0b0f15] text-[#e6edf3] font-sans antialiased min-h-screen selection:bg-sky-500/20 selection:text-sky-300">
    <div id="root"></div>

    <script type="module" crossorigin src="<?= htmlspecialchars($jsAsset) ?>"></script>
    <script type="module" crossorigin src="/public<?= htmlspecialchars($jsAsset) ?>"></script>
</body>
</html>
