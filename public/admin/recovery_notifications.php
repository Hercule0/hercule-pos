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

$summary = $pdo->query(
    "SELECT COUNT(CASE WHEN status = 'pending' THEN 1 END) AS pending_count,
            COALESCE(MAX(id), 0) AS latest_id
     FROM password_recovery_requests"
)->fetch();
$pendingCount = (int) ($summary['pending_count'] ?? 0);
$latestId = (int) ($summary['latest_id'] ?? 0);

$stmt = $pdo->prepare(
    "SELECT id, requested_username, created_at
     FROM password_recovery_requests
     WHERE status = 'pending' AND id > ?
     ORDER BY id DESC
     LIMIT 5"
);
$stmt->execute([$afterId]);
$requests = $stmt->fetchAll();

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
