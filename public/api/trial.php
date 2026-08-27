<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../includes/TrialManager.php';
require_once __DIR__ . '/../../includes/RateLimiter.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_response(['ok'=>false,'error'=>'Method not allowed'], 405);
}

$input = json_input();
$hwid = trim((string)($input['hwid'] ?? ''));
$appVersion = trim((string)($input['app_version'] ?? $input['version'] ?? ''));

if ($hwid === '' || strlen($hwid) > 128 || preg_match('/[\x00-\x1F\x7F]/', $hwid)) {
    json_response(['ok'=>false,'error'=>'Invalid hwid'], 400);
}
if (strlen($appVersion) > 50) {
    json_response(['ok'=>false,'error'=>'Invalid app version'], 400);
}

$deviceBucket = 'trial:' . substr(hash('sha256', $hwid), 0, 40);
if (!RateLimiter::check(client_ip(), 'trial_ip', 120, 5)
    || !RateLimiter::check($deviceBucket, 'trial_device', 20, 5)) {
    json_response(['ok'=>false,'error'=>'Too many trial checks. Please try again later.'], 429);
}

try {
    $payload = TrialManager::status($hwid, client_ip(), $appVersion);
    $signed = RsaSigner::sign($payload);
} catch (Throwable $e) {
    ErrorHandler::report($e, 'trial_status_failed');
    json_response(['ok'=>false,'error'=>'Trial service unavailable'], 503);
}

$ok = ($payload['status'] ?? '') === 'trial';
json_response([
    'ok' => $ok,
    'payload' => $signed['payload'],
    'signature' => $signed['signature'],
], $ok ? 200 : 403);
