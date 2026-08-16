<?php
require_once __DIR__ . '/includes/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (!Auth::isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Authentication required.']);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    header('Allow: GET');
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed.']);
    exit;
}

$afterId = max(0, (int) ($_GET['after_id'] ?? 0));
$pdo = Database::pdo();

$countStmt = $pdo->query(
    "SELECT COUNT(*) FROM password_recovery_requests WHERE status = 'pending'"
);
$pendingCount = (int) $countStmt->fetchColumn();

$stmt = $pdo->prepare(
    "SELECT id, requested_username, created_at
     FROM password_recovery_requests
     WHERE status = 'pending' AND id > ?
     ORDER BY id DESC
     LIMIT 5"
);
$stmt->execute([$afterId]);
$requests = $stmt->fetchAll();

$latestStmt = $pdo->query('SELECT COALESCE(MAX(id), 0) FROM password_recovery_requests');
$latestId = (int) $latestStmt->fetchColumn();

echo json_encode([
    'ok' => true,
    'pending_count' => $pendingCount,
    'latest_id' => $latestId,
    'requests' => array_map(static function (array $request): array {
        return [
            'id' => (int) $request['id'],
            'username' => $request['requested_username'],
            'created_at' => $request['created_at'],
            'url' => '/public/admin/recovery_requests.php?request_id=' . (int) $request['id'],
        ];
    }, $requests),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
