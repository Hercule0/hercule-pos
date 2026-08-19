<?php
require_once __DIR__ . '/includes/bootstrap.php';
Auth::require();

$input = json_decode(file_get_contents('php://input'), true);

if (!$input || !isset($input['endpoint'])) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid subscription payload']);
    exit;
}

$endpoint = $input['endpoint'];
$p256dh = $input['keys']['p256dh'] ?? '';
$auth = $input['keys']['auth'] ?? '';
$adminUsername = Auth::currentUsername();

$pdo = Database::pdo();
$stmt = $pdo->prepare("
    INSERT INTO push_subscriptions (admin_username, endpoint, p256dh_key, auth_key)
    VALUES (?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE 
        admin_username = VALUES(admin_username),
        p256dh_key = VALUES(p256dh_key),
        auth_key = VALUES(auth_key),
        created_at = CURRENT_TIMESTAMP
");

try {
    $stmt->execute([$adminUsername, $endpoint, $p256dh, $auth]);
    echo json_encode(['ok' => true]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Database error']);
}
