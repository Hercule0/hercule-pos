<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/../../includes/PushNotifier.php';

header('Content-Type: application/json; charset=utf-8');

if (!Auth::isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Authentication required']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['ok' => false, 'error' => 'POST required']);
    exit;
}

try {
    $result = PushNotifier::sendPush(
        'Hercule POS Test Alert',
        'Web Push is connected to this device and working correctly.',
        '/public/admin/index.php',
        'hercule-test-' . time()
    );
    echo json_encode($result + ['message' => 'Test push dispatched']);
} catch (Throwable $e) {
    ErrorHandler::log('Push test failed', ['error' => $e->getMessage()]);
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Unable to dispatch test push']);
}
