<?php
require_once __DIR__ . '/../includes/Database.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

try {
    Database::pdo()->query('SELECT 1')->fetchColumn();

    http_response_code(200);
    echo json_encode([
        'ok' => true,
        'service' => 'hercule-license-server',
        'database' => 'reachable',
        'time' => gmdate('Y-m-d\TH:i:s\Z'),
    ], JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(503);
    echo json_encode([
        'ok' => false,
        'service' => 'hercule-license-server',
        'database' => 'unavailable',
        'time' => gmdate('Y-m-d\TH:i:s\Z'),
    ], JSON_UNESCAPED_SLASHES);
}
