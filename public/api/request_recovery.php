<?php
/**
 * POST /api/request_recovery.php
 * Body: { "license_key": "...", "hwid": "..." }
 *
 * يستقبل طلب استرجاع كلمة المرور من التطبيق المكتبي،
 * ويقوم بتسجيله في قاعدة البيانات ليراجعه المدير.
 */

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../includes/RateLimiter.php';
require_once __DIR__ . '/../../includes/Database.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'error' => 'Method not allowed'], 405);
}

// حماية الـ API من الهجمات المتكررة (Rate Limiting)
$config = require __DIR__ . '/../../config/config.php';
$rateLimitCfg = $config['security'];
if (!RateLimiter::check(client_ip(), 'recovery_request', 5, 15)) {
    json_response(['ok' => false, 'error' => 'طلبات كثيرة جداً. يرجى المحاولة لاحقاً.'], 429);
}

$input = json_input();
$licenseKey = trim($input['license_key'] ?? '');
$hwid = trim($input['hwid'] ?? '');

if ($licenseKey === '' || $hwid === '') {
    json_response(['ok' => false, 'error' => 'مفتاح الترخيص ومعرف الجهاز مطلوبان.'], 400);
}

$pdo = Database::pdo();

// التحقق مما إذا كان هناك طلب قيد الانتظار (Pending) بالفعل لنفس الترخيص
$stmt = $pdo->prepare("SELECT id FROM password_recovery_requests WHERE license_key = ? AND status = 'pending'");
$stmt->execute([$licenseKey]);
if ($stmt->fetch()) {
    json_response(['ok' => false, 'error' => 'يوجد طلب استرجاع قيد المراجعة بالفعل لهذا الحساب.'], 409);
}

// إدخال الطلب الجديد إلى قاعدة البيانات
$stmt = $pdo->prepare("INSERT INTO password_recovery_requests (license_key, hwid, status) VALUES (?, ?, 'pending')");
$stmt->execute([$licenseKey, $hwid]);

// تسجيل الحدث في سجل التدقيق (Audit Logging)
$requestId = $pdo->lastInsertId();
$stmtLog = $pdo->prepare("INSERT INTO verification_log (license_id, license_key, hwid, result, ip_address) VALUES (NULL, ?, ?, 'recovery_requested', ?)");
$stmtLog->execute([$licenseKey, $hwid, client_ip()]);

json_response([
    'ok' => true,
    'message' => 'تم إرسال طلب الاسترجاع بنجاح. يرجى انتظار موافقة الإدارة.'
]);
