<?php
require_once __DIR__ . '/includes/bootstrap.php';
Auth::require();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['ok' => false, 'error' => 'POST required']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$endpoint = trim((string)($input['endpoint'] ?? ''));
$p256dh = trim((string)($input['keys']['p256dh'] ?? ''));
$auth = trim((string)($input['keys']['auth'] ?? ''));

if ($endpoint === '' || $p256dh === '' || $auth === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Incomplete browser push subscription']);
    exit;
}

if (!filter_var($endpoint, FILTER_VALIDATE_URL) || stripos($endpoint, 'https://') !== 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid push endpoint']);
    exit;
}

try {
    $pdo = Database::pdo();
    $stmt = $pdo->prepare("REPLACE INTO push_subscriptions (admin_username, endpoint, p256dh_key, auth_key) VALUES (?, ?, ?, ?)");
    $stmt->execute([(string)Auth::currentUsername(), $endpoint, $p256dh, $auth]);
    echo json_encode(['ok' => true, 'saved' => true]);
} catch (Throwable $e) {
    ErrorHandler::log('Push subscription save failed', ['error' => $e->getMessage()]);
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Unable to save push subscription']);
}
