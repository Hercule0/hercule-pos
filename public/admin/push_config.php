<?php
require_once __DIR__ . '/includes/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if (!Auth::isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Authentication required.']);
    exit;
}

$config = require __DIR__ . '/../../config/config.php';
$publicKey = trim((string)($config['vapid']['public_key'] ?? ''));

if ($publicKey === '') {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'Push notifications are not configured.']);
    exit;
}

echo json_encode([
    'ok' => true,
    'publicKey' => $publicKey,
    'csrfToken' => Csrf::token(),
], JSON_UNESCAPED_SLASHES);
