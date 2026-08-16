<?php
require_once __DIR__ . '/includes/bootstrap.php';

if (!Auth::isLoggedIn()) {
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'Authentication required.']);
    exit;
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$stmt = Database::pdo()->query(
    "SELECT l.id, l.license_key, l.status, l.expires_at, c.name AS customer_name,
            CASE WHEN l.expires_at < NOW() THEN 'expired' ELSE 'expiring' END AS alert_type,
            CASE WHEN l.expires_at < NOW() THEN 0 ELSE DATEDIFF(l.expires_at, NOW()) END AS days_remaining
     FROM licenses l
     JOIN customers c ON c.id = l.customer_id
     WHERE l.expires_at IS NOT NULL
       AND (
         (l.status = 'active' AND l.expires_at <= DATE_ADD(NOW(), INTERVAL 7 DAY))
         OR l.status = 'expired'
       )
     ORDER BY (l.expires_at < NOW()) DESC, l.expires_at ASC
     LIMIT 100"
);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$expiring = 0;
$expired = 0;
$signatureParts = [];
$alerts = [];

foreach ($rows as $row) {
    $type = $row['alert_type'] === 'expired' ? 'expired' : 'expiring';
    $type === 'expired' ? $expired++ : $expiring++;
    $signatureParts[] = $row['id'] . ':' . $row['expires_at'] . ':' . $type;
    $alerts[] = [
        'id' => (int) $row['id'],
        'customer' => $row['customer_name'],
        'license_key' => $row['license_key'],
        'type' => $type,
        'days_remaining' => (int) $row['days_remaining'],
        'expires_at' => $row['expires_at'],
        'url' => '/public/admin/license_detail.php?id=' . (int) $row['id'],
    ];
}

echo json_encode([
    'ok' => true,
    'total_count' => count($alerts),
    'expiring_count' => $expiring,
    'expired_count' => $expired,
    'signature' => hash('sha256', implode('|', $signatureParts)),
    'alerts' => $alerts,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
