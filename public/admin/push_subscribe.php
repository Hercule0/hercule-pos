<?php
/**
 * POST /public/admin/push_subscribe.php
 * Endpoint for mobile devices & PWAs to save Web Push subscriptions.
 */

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/../../includes/PushNotifier.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

$raw = file_get_contents('php://input');
$data = json_decode($raw, true) ?? $_POST;

$endpoint = trim($data['endpoint'] ?? '');
$p256dh = $data['keys']['p256dh'] ?? $data['p256dh'] ?? null;
$auth = $data['keys']['auth'] ?? $data['auth'] ?? null;
$userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;
$adminId = Auth::currentUserId();

if ($endpoint === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Push subscription endpoint is required.']);
    exit;
}

$success = PushNotifier::subscribe($endpoint, $p256dh, $auth, $adminId, $userAgent);

echo json_encode(['ok' => $success]);
