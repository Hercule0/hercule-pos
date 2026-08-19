<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/../../includes/PushNotifier.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (!Auth::isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Authentication required']);
    exit;
}

// The current admin header invokes this endpoint with GET. Keep that authenticated
// compatibility path until the header client is moved to POST in a later cleanup.
if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'POST'], true)) {
    http_response_code(405);
    header('Allow: GET, POST');
    echo json_encode(['ok' => false, 'error' => 'Unsupported request method']);
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
