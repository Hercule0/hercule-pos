<?php
/**
 * POST /api/recovery_cancel.php
 * Body: { "request_id": 123, "license_key": "...", "hwid": "..." }
 *
 * Cancels a pending recovery request from the same activated device that
 * created it. The existing schema has no cancelled enum value, so the row is
 * stored as rejected with an internal marker; recovery_status.php maps that
 * marker back to the public "cancelled" state for clients.
 */

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../includes/RateLimiter.php';
require_once __DIR__ . '/../../includes/PasswordRecovery.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'error' => 'Method not allowed'], 405);
}

$config = require __DIR__ . '/../../config/config.php';
$rateLimitCfg = $config['security'];
if (!RateLimiter::check(client_ip(), 'recovery_cancel', $rateLimitCfg['api_rate_limit_max_requests'], $rateLimitCfg['api_rate_limit_window_minutes'])) {
    json_response(['ok' => false, 'error' => 'Too many requests. Please try again in a few minutes.'], 429);
}

$input = json_input();
$requestId = (int) ($input['request_id'] ?? 0);
$licenseKey = trim((string) ($input['license_key'] ?? ''));
$hwid = trim((string) ($input['hwid'] ?? ''));

if (!$requestId || $licenseKey === '' || $hwid === '') {
    json_response(['ok' => false, 'error' => 'request_id, license_key, and hwid are required'], 400);
}
if (strlen($licenseKey) > 29 || !preg_match('/^[A-Z0-9-]+$/', $licenseKey) || strlen($hwid) > 128 || preg_match('/[\x00-\x1F\x7F]/', $hwid)) {
    json_response(['ok' => false, 'error' => 'Invalid license_key or hwid.'], 400);
}
if (!RateLimiter::check('key:' . $licenseKey, 'recovery_cancel_by_key', $rateLimitCfg['key_rate_limit_max_requests'], $rateLimitCfg['key_rate_limit_window_minutes'])) {
    json_response(['ok' => false, 'error' => 'Too many cancellation attempts for this license. Please try again later.'], 429);
}

$pdo = Database::pdo();
$pdo->beginTransaction();
try {
    $stmt = $pdo->prepare(
        "SELECT id, status FROM password_recovery_requests
         WHERE id = ? AND license_key = ? AND hwid = ?
         FOR UPDATE"
    );
    $stmt->execute([$requestId, $licenseKey, $hwid]);
    $row = $stmt->fetch();
    if (!$row) {
        $pdo->rollBack();
        json_response(['ok' => false, 'error' => 'Request not found.'], 404);
    }
    if ($row['status'] !== 'pending') {
        $pdo->rollBack();
        json_response(['ok' => false, 'error' => 'لا يمكن إلغاء الطلب بعد مراجعته من فريق الدعم.'], 409);
    }

    $update = $pdo->prepare(
        "UPDATE password_recovery_requests
         SET status = 'rejected', admin_note = '__CLIENT_CANCELLED__', reviewed_by = 'client', reviewed_at = CURRENT_TIMESTAMP
         WHERE id = ? AND license_key = ? AND hwid = ? AND status = 'pending'"
    );
    $update->execute([$requestId, $licenseKey, $hwid]);
    if ($update->rowCount() !== 1) {
        $pdo->rollBack();
        json_response(['ok' => false, 'error' => 'Unable to cancel recovery request.'], 409);
    }

    $audit = $pdo->prepare(
        'INSERT INTO recovery_audit_log (request_id, event_type, actor, ip_address, note) VALUES (?, ?, ?, ?, ?)'
    );
    $audit->execute([$requestId, 'request_cancelled', 'client', client_ip(), null]);
    $pdo->commit();
    json_response(['ok' => true, 'status' => 'cancelled']);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $e;
}
