<?php
require_once __DIR__ . '/includes/bootstrap.php';
Auth::require();

$username = Auth::currentUsername() ?? 'Admin';
$role = Auth::currentRole() ?? 'admin';
$userId = Auth::currentUserId() ?? 1;

// Load the compiled SPA index.html from any available location
$candidateFiles = [
    __DIR__ . '/../../dist/index.html',
    __DIR__ . '/../app.html',
    __DIR__ . '/../index.html',
    __DIR__ . '/index.html',
    dirname(__DIR__, 2) . '/dist/index.html',
    dirname(__DIR__, 2) . '/index.html'
];

$spaHtmlFile = null;
foreach ($candidateFiles as $file) {
    if (file_exists($file)) {
        $spaHtmlFile = $file;
        break;
    }
}

$html = $spaHtmlFile ? file_get_contents($spaHtmlFile) : '';

if (!empty($html)) {
    // Inject bootstrap auth payload right before </head>
    $bootstrapJson = json_encode([
        'authenticated' => true,
        'user' => [
            'id' => $userId,
            'username' => $username,
            'role' => $role
        ],
        'apiEndpoint' => '/public/admin/api.php'
    ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

    $injection = "<script>window.HERCULE_BOOTSTRAP = {$bootstrapJson};</script></head>";
    $html = str_replace('</head>', $injection, $html);

    echo $html;
    exit;
}
?>
<!DOCTYPE html>
<html lang="en" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <title>Hercule POS License Server</title>
</head>
<body style="background:#0b0f15;color:#fff;font-family:sans-serif;padding:40px;text-align:center;">
    <h2>Loading Hercule POS Console...</h2>
    <p>Please build the frontend assets or refresh in a moment.</p>
</body>
</html>
