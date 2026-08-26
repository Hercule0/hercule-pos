<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../includes/ReleaseManager.php';
require_once __DIR__ . '/../../includes/DeviceManager.php';
require_once __DIR__ . '/../../includes/RateLimiter.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_response(['ok'=>false,'error'=>'Method not allowed'], 405);
}

$input = json_input();
$licenseKey = trim((string)($input['license_key'] ?? $input['licenseKey'] ?? ''));
$hwid = trim((string)($input['hwid'] ?? ''));
$releaseId = (int)($input['release_id'] ?? 0);
$event = strtolower(trim((string)($input['event'] ?? '')));
$version = trim((string)($input['version'] ?? ''));
$detail = trim((string)($input['detail'] ?? ''));
$allowedEvents = ['install_started','installed','failed','dismissed'];

if ($licenseKey === '' || $hwid === '' || $releaseId <= 0 || !in_array($event, $allowedEvents, true)) {
    json_response(['ok'=>false,'code'=>'INVALID_REQUEST','error'=>'Invalid update event payload'], 400);
}
if (strlen($licenseKey)>64 || strlen($hwid)>160 || strlen($version)>50 || strlen($detail)>500) {
    json_response(['ok'=>false,'code'=>'INVALID_REQUEST','error'=>'Update event fields are too long'], 400);
}

$deviceBucket = 'upd-' . substr(hash('sha256', $licenseKey . '|' . $hwid), 0, 36);
if (!RateLimiter::check(client_ip(), 'release_event_ip', 600, 5) || !RateLimiter::check($deviceBucket, 'release_event_device', 60, 5)) {
    json_response(['ok'=>false,'code'=>'RATE_LIMIT','error'=>'Too many update events'], 429);
}

try {
    $pdo = Database::pdo();
    $stmt = $pdo->prepare("SELECT a.id AS activation_id, a.license_id, a.is_active, a.is_blocked,
               l.status AS license_status, l.expires_at AS license_expires_at
        FROM license_activations a JOIN licenses l ON l.id=a.license_id
        WHERE l.license_key=? AND a.hwid=? LIMIT 1");
    $stmt->execute([$licenseKey,$hwid]);
    $client = $stmt->fetch();
    $licenseExpired = !empty($client['license_expires_at'])
        && strtotime((string)$client['license_expires_at']) <= time();
    if (!$client || ($client['license_status']??'')!=='active' || $licenseExpired || empty($client['is_active']) || !empty($client['is_blocked'])) {
        json_response(['ok'=>false,'code'=>'DEVICE_DENIED','error'=>'Update event is not allowed for this device'], 403);
    }

    $release = ReleaseManager::find($releaseId);
    if (!$release) json_response(['ok'=>false,'code'=>'RELEASE_NOT_FOUND','error'=>'Release not found'], 404);

    $licenseId = (int)$client['license_id'];
    $activationId = (int)$client['activation_id'];
    $targetMode = (string)($release['target_mode'] ?? 'all');
    if ($targetMode !== 'all') {
        $targets = ReleaseManager::targets($releaseId);
        $eligible = false;
        foreach ($targets as $target) {
            if (!empty($target['license_id']) && (int)$target['license_id'] === $licenseId) { $eligible=true; break; }
            if (!empty($target['activation_id']) && (int)$target['activation_id'] === $activationId) { $eligible=true; break; }
        }
        if (!$eligible) json_response(['ok'=>false,'code'=>'NOT_TARGETED','error'=>'Release is not targeted to this device'], 403);
    }

    ReleaseManager::recordEvent($releaseId,$licenseId,$activationId,$event,$version ?: null,$detail ?: null);
    if ($event === 'installed' && $version !== '') {
        DeviceManager::recordClientVersion($licenseKey,$hwid,$version);
    }
    json_response(['ok'=>true]);
} catch (Throwable $e) {
    json_response(['ok'=>false,'code'=>'RELEASE_EVENT_FAILED','error'=>'Could not record update event'], 503);
}
