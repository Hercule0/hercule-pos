<?php
/**
 * POST /api/check_recovery_status.php
 * Body: { "license_key": "...", "hwid": "..." }
 *
 * يتحقق من حالة آخر طلب استرجاع قدمه هذا الجهاز.
 */

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../includes/Database.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'error' => 'Method not allowed'], 405);
}

$input = json_input();
$licenseKey = trim($input['license_key'] ?? '');
$hwid = trim($input['hwid'] ?? '');

if ($licenseKey === '' || $hwid === '') {
    json_response(['ok' => false, 'error' => 'Missing data'], 400);
}

$pdo = Database::pdo();

// جلب أحدث طلب لهذا الجهاز
$stmt = $pdo->prepare("SELECT * FROM password_recovery_requests WHERE license_key = ? AND hwid = ? ORDER BY created_at DESC LIMIT 1");
$stmt->execute([$licenseKey, $hwid]);
$req = $stmt->fetch();

if (!$req) {
    json_response(['ok' => true, 'status' => 'none']);
}

// التحقق من انتهاء صلاحية الرمز إذا كان موافقاً عليه
if ($req['status'] === 'approved' && strtotime($req['expires_at']) < time()) {
    $update = $pdo->prepare("UPDATE password_recovery_requests SET status = 'expired' WHERE id = ?");
    $update->execute([$req['id']]);
    $req['status'] = 'expired';
}

$response = [
    'ok' => true,
    'status' => $req['status']
];

// إرسال الرمز فقط إذا كانت الحالة موافق عليها
if ($req['status'] === 'approved') {
    $response['auth_token'] = $req['auth_token'];
}

json_response($response);
