<?php
/**
 * POST /public/admin/push_test.php
 * Endpoint for admins to send an immediate test push alert to all registered mobile devices.
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

$title = $data['title'] ?? '🔔 Hercule Fast Push Test';
$body = $data['body'] ?? 'Mobile lockscreen push notifications are working fast and live!';
$url = $data['url'] ?? '/public/admin/index.php';

$res = PushNotifier::sendPush($title, $body, $url, 'test-' . time());

echo json_encode($res);
