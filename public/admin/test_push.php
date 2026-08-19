<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/../includes/PushNotifier.php';

Auth::require();

PushNotifier::sendPush(
    "Emergency Terminal Recovery #REQ-" . rand(1000, 9999),
    "POS Terminal 01 requested hardware recovery. One-time PIN generated.",
    "/public/admin/recovery_requests.php"
);

echo json_encode(['ok' => true]);
