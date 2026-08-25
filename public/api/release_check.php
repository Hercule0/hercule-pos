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
$version = trim((string)($input['version'] ?? $input['app_version'] ?? ''));
$channel = strtolower(trim((string)($input['channel'] ?? 'stable')));

if ($licenseKey === '' || $hwid === '' || $version === '') {
    json_response(['ok'=>false,'code'=>'INVALID_REQUEST','error'=>'license_key, hwid and version are required'], 400);
}
if (strlen($licenseKey) > 64 || strlen($hwid) > 160 || strlen($version) > 50) {
    json_response(['ok'=>false,'code'=>'INVALID_REQUEST','error'=>'Update check fields are too long'], 400);
}

// Many terminals can share one public IP. Keep a generous NAT-level ceiling,
// then enforce a tighter per-device bucket so one terminal still cannot spam.
$deviceBucket = 'upd-' . substr(hash('sha256', $licenseKey . '|' . $hwid), 0, 36);
if (!RateLimiter::check(client_ip(), 'release_check_ip', 600, 5)
    || !RateLimiter::check($deviceBucket, 'release_check_device', 30, 5)) {
    json_response(['ok'=>false,'code'=>'RATE_LIMIT','error'=>'Too many update checks.'], 429);
}

try {
    $eligible = ReleaseManager::eligibleForClient($version, $licenseKey, $hwid, $channel);
} catch (Throwable $e) {
    json_response(['ok'=>false,'code'=>'RELEASE_SERVICE_UNAVAILABLE','error'=>'Release service unavailable'], 503);
}

if (empty($eligible['ok'])) {
    $code = (string)($eligible['code'] ?? 'UPDATE_DENIED');
    $status = $code === 'RELEASE_SCHEMA_NOT_READY' ? 503 : 403;
    json_response(['ok'=>false,'code'=>$code,'error'=>'Update check is not available for this device'], $status);
}

try {
    DeviceManager::recordClientVersion($licenseKey, $hwid, $version);
} catch (Throwable $e) {
    // Version telemetry must never prevent update delivery.
}

if (empty($eligible['update_available'])) {
    json_response([
        'ok'=>true,
        'update_available'=>false,
        'current_version'=>$version,
        'server_time'=>gmdate('Y-m-d\TH:i:s\Z'),
    ]);
}

$release = $eligible['release'];
$releaseId = (int)$release['id'];
$licenseId = (int)$eligible['license_id'];
$activationId = (int)$eligible['activation_id'];

$base = trim((string)($_ENV['HERCULE_PUBLIC_BASE_URL'] ?? $_SERVER['HERCULE_PUBLIC_BASE_URL'] ?? getenv('HERCULE_PUBLIC_BASE_URL') ?: ''));
if ($base === '') {
    $host = trim((string)($_SERVER['WEBSITE_HOSTNAME'] ?? $_SERVER['HTTP_HOST'] ?? ''));
    if (!preg_match('/^[A-Za-z0-9.-]+(?::\d+)?$/', $host)) $host = '';
    $proto = strtolower(trim((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? 'https')));
    if (!in_array($proto, ['http','https'], true)) $proto = 'https';
    $base = $host !== '' ? $proto . '://' . $host : '';
}
$base = rtrim($base, '/');

$artifactUrls = [];
if (!empty($release['storage_key'])) {
    try {
        $token = ReleaseManager::createDownloadGrant($releaseId, $licenseId, $activationId);
        if ($base === '') throw new RuntimeException('Public base URL is unavailable.');
        foreach (['installer','blockmap','metadata'] as $artifact) {
            $artifactUrls[$artifact] = $base . '/public/api/release_download.php?token=' . rawurlencode($token) . '&artifact=' . $artifact;
        }
    } catch (Throwable $e) {
        json_response(['ok'=>false,'code'=>'DOWNLOAD_GRANT_FAILED','error'=>'Could not prepare secure update download'], 503);
    }
}

try {
    ReleaseManager::recordEvent($releaseId, $licenseId, $activationId, 'offered', $version);
} catch (Throwable $e) {
    // Telemetry is best-effort.
}

json_response([
    'ok'=>true,
    'update_available'=>true,
    'mandatory'=>(bool)$eligible['mandatory'],
    'below_minimum_supported'=>(bool)$eligible['below_minimum_supported'],
    'current_version'=>$version,
    'release'=>[
        'id'=>$releaseId,
        'version'=>$release['version'],
        'channel'=>$release['channel'] ?? 'stable',
        'minimum_supported_version'=>$release['minimum_supported_version'],
        'release_notes'=>$release['release_notes'],
        'published_at'=>$release['published_at'],
        'installer'=>!empty($artifactUrls['installer']) ? [
            'url'=>$artifactUrls['installer'],
            'file'=>$release['installer_filename'],
            'size'=>(int)$release['installer_size'],
            'sha256'=>$release['installer_sha256'],
            'sha512'=>$release['installer_sha512'],
        ] : null,
        'blockmap'=>!empty($artifactUrls['blockmap']) ? [
            'url'=>$artifactUrls['blockmap'],
            'file'=>$release['blockmap_filename'],
            'size'=>(int)$release['blockmap_size'],
            'sha256'=>$release['blockmap_sha256'],
        ] : null,
        'metadata'=>!empty($artifactUrls['metadata']) ? [
            'url'=>$artifactUrls['metadata'],
            'file'=>$release['metadata_filename'],
            'size'=>(int)$release['metadata_size'],
            'sha256'=>$release['metadata_sha256'],
        ] : null,
        'external_url'=>empty($release['storage_key']) ? ($release['download_url'] ?? null) : null,
    ],
    'server_time'=>gmdate('Y-m-d\TH:i:s\Z'),
]);
