<?php
/**
 * POST /api/consume_recovery.php
 * Body: { "license_key": "...", "hwid": "...", "auth_token": "..." }
 *
 * يتم استدعاؤه بعد تغيير الرمز بنجاح محلياً لحرق (استهلاك) الرمز على السيرفر.
 */

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../includes/Database.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'error' => 'Method not allowed'], 405);
}

$input = json_input();
$licenseKey = trim($input['license_key'] ?? '');
$hwid = trim($input['hwid'] ?? '');
$authToken = trim($input['auth_token'] ?? '');

if ($licenseKey === '' || $hwid === '' || $authToken === '') {
    json_response(['ok' => false, 'error' => 'Missing data'], 400);
}

$pdo = Database::pdo();
$pdo->beginTransaction();

try {
    // جلب الطلب وتجميده (Lock) لمنع الاستخدام المزدوج بنفس اللحظة (Race condition)
    $stmt = $pdo->prepare("SELECT * FROM password_recovery_requests WHERE auth_token = ? AND license_key = ? AND hwid = ? AND status = 'approved' FOR UPDATE");
    $stmt->execute([$authToken, $licenseKey, $hwid]);
    $req = $stmt->fetch();

    if (!$req) {
        $pdo->rollBack();
        json_response(['ok' => false, 'error' => 'رمز الاسترجاع غير صالح أو تم استخدامه مسبقاً.'], 400);
    }

    // التحقق مجدداً من الصلاحية
    if (strtotime($req['expires_at']) < time()) {
        $update = $pdo->prepare("UPDATE password_recovery_requests SET status = 'expired' WHERE id = ?");
        $update->execute([$req['id']]);
        $pdo->commit();
        json_response(['ok' => false, 'error' => 'رمز الاسترجاع منتهي الصلاحية.'], 400);
    }

    // إغلاق الطلب وتحويله إلى مكتمل
    $update = $pdo->prepare("UPDATE password_recovery_requests SET status = 'completed', resolved_at = CURRENT_TIMESTAMP WHERE id = ?");
    $update->execute([$req['id']]);

    // تسجيل الحدث أمنياً
    $log = $pdo->prepare("INSERT INTO verification_log (license_id, license_key, hwid, result, ip_address) VALUES (NULL, ?, ?, 'recovery_consumed', ?)");
    $log->execute([$licenseKey, $hwid, client_ip()]);

    $pdo->commit();
    json_response(['ok' => true]);

} catch (\Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    json_response(['ok' => false, 'error' => 'حدث خطأ داخلي.'], 500);
}
