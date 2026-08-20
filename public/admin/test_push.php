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

if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'POST'], true)) {
    http_response_code(405);
    header('Allow: GET, POST');
    echo json_encode(['ok' => false, 'error' => 'Unsupported request method']);
    exit;
}

try {
    $result = PushNotifier::sendPush(
        'Hercule POS Test Alert',
        'Web Push is connected to this browser and working correctly.',
        '/public/admin/index.php',
        'hercule-test-' . time()
    );

    $subscriptions = (int)($result['subscriptions_count'] ?? 0);
    $dispatched = (int)($result['dispatched'] ?? 0);
    $failed = (int)($result['failed'] ?? 0);

    if ($subscriptions === 0) {
        http_response_code(409);
        echo json_encode($result + ['ok' => false, 'code' => 'NO_SUBSCRIPTIONS', 'message' => 'No browser endpoint is subscribed. Enable Alerts on the browser or phone first.']);
        exit;
    }

    if (empty($result['ok']) || $dispatched === 0) {
        http_response_code(502);
        echo json_encode($result + ['ok' => false, 'code' => 'DELIVERY_FAILED', 'message' => $result['error'] ?? ('Push provider accepted 0 of ' . $subscriptions . ' endpoint(s).')]);
        exit;
    }

    echo json_encode($result + ['ok' => true, 'code' => 'PUSH_SENT', 'message' => 'Push sent to ' . $dispatched . ' active browser endpoint(s).' . ($failed ? ' Failed: ' . $failed . '.' : '')]);
} catch (Throwable $e) {
    ErrorHandler::log('Push test failed', ['error' => $e->getMessage()]);
    http_response_code(500);
    echo json_encode(['ok' => false, 'code' => 'SERVER_ERROR', 'error' => 'Unable to dispatch test push', 'message' => 'The server could not run the Web Push test.']);
}
