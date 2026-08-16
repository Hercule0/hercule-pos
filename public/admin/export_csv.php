<?php
require_once __DIR__ . '/includes/bootstrap.php';
Auth::require();
Auth::requirePermission('exports.download');

$pdo = Database::pdo();
$customerFilter = isset($_GET['customer_id']) ? (int) $_GET['customer_id'] : null;

$sql = "SELECT l.license_key, c.name AS customer_name, c.email, l.plan, l.status,
               l.max_activations, l.issued_at, l.expires_at, l.last_verified_at
        FROM licenses l JOIN customers c ON c.id = l.customer_id";
$params = [];
if ($customerFilter) {
    $sql .= " WHERE l.customer_id = ?";
    $params[] = $customerFilter;
}
$sql .= " ORDER BY l.created_at DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="licenses_export_' . date('Y-m-d') . '.csv"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

$out = fopen('php://output', 'w');
fputcsv($out, ['License Key', 'Customer', 'Email', 'Plan', 'Status', 'Max Activations', 'Issued', 'Expires', 'Last Verified']);

while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
    // CSV injection guard: prefix any value starting with =, +, -, @ so
    // spreadsheet apps don't treat it as a formula (same fix already
    // applied in Ur Library's export).
    $safe = array_map(function ($v) {
        if (is_string($v) && $v !== '' && in_array($v[0], ['=', '+', '-', '@'], true)) {
            return "'" . $v;
        }
        return $v;
    }, [
        $r['license_key'], $r['customer_name'], $r['email'], $r['plan'], $r['status'],
        $r['max_activations'], $r['issued_at'], $r['expires_at'] ?? 'Never', $r['last_verified_at'] ?? 'Never',
    ]);
    fputcsv($out, $safe);
}

fclose($out);
exit;
