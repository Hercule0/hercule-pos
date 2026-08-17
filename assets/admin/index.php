<?php
require_once __DIR__ . '/includes/bootstrap.php';
Auth::require();

$username = Auth::currentUsername();
$role = Auth::currentRole();

// Load the compiled SPA index.html
$spaHtmlFile = __DIR__ . '/../../dist/index.html';
if (!file_exists($spaHtmlFile)) {
    $spaHtmlFile = __DIR__ . '/../app.html';
}
if (!file_exists($spaHtmlFile)) {
    $spaHtmlFile = __DIR__ . '/../index.html';
}

$html = file_exists($spaHtmlFile) ? file_get_contents($spaHtmlFile) : '';

if (!empty($html)) {
    // Inject bootstrap auth payload right before </head>
    $bootstrapJson = json_encode([
        'authenticated' => true,
        'user' => [
            'id' => (int)Auth::currentUserId(),
            'username' => $username,
            'role' => $role
        ],
        'apiEndpoint' => '/public/admin/api.php'
    ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

    $injection = "<script>window.HERCULE_BOOTSTRAP = {$bootstrapJson};</script></head>";
    $html = str_replace('</head>', $injection, $html);

    // Adjust relative assets paths so they load seamlessly from /public/admin/
    // Both ./assets/ and /assets/ and assets/ work
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
