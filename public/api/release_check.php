<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../includes/ReleaseManager.php';
require_once __DIR__ . '/../../includes/DeviceManager.php';
require_once __DIR__ . '/../../includes/RateLimiter.php';
require_once __DIR__ . '/../../includes/UpdateSigner.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_response(['ok' => false, 'error' => 'Method not allowed'], 405);
}

/**
 * ReleaseStorage keeps Electron's standard SHA-512 representation (base64)
 * in manifest.json/latest.yml. The signed desktop trust contract deliberately
 * uses a fixed 128-character lowercase hex representation. Accept either
 * storage representation here and normalize before signing or returning it.
 */
function release_sha512_hex(?string $stored): ?string
{
    $value = trim((string) $stored);
    if (preg_match('/^[a-f0-9]{128}$/i', $value)) {
        return strtolower($value);
    }
    $raw = base64_decode($value, true);
    if ($raw !== false && strlen($raw) === 64) {
        return bin2hex($raw);
    }
    return null;
}

$input = json_input();
$licenseKey = trim((string) ($input['license_key'] ?? $input['licenseKey'] ?? ''));
$hwid = trim((string) ($input['hwid'] ?? ''));
$version = trim((string) ($input['version'] ?? $input['app_version'] ?? ''));
$channel = strtolower(trim((string) ($input['channel'] ?? 'stable')));

if ($licenseKey === '' || $hwid === '' || $version === '') {
    json_response(['ok' => false, 'code' => 'INVALID_REQUEST', 'error' => 'license_key, hwid and version are required'], 400);
}
if (strlen($licenseKey) > 64 || strlen($hwid) > 160 || strlen($version) > 50) {
    json_response(['ok' => false, 'code' => 'INVALID_REQUEST', 'error' => 'Update check fields are too long'], 400);
}

$deviceBucket = 'upd-' . substr(hash('sha256', $licenseKey . '|' . $hwid), 0, 36);
if (!RateLimiter::check(client_ip(), 'release_check_ip', 600, 5)
    || !RateLimiter::check($deviceBucket, 'release_check_device', 30, 5)) {
    json_response(['ok' => false, 'code' => 'RATE_LIMIT', 'error' => 'Too many update checks.'], 429);
}

try {
    $eligible = ReleaseManager::eligibleForClient($version, $licenseKey, $hwid, $channel);
} catch (Throwable $e) {
    ErrorHandler::report($e, 'release_check_eligibility_failed');
    json_response(['ok' => false, 'code' => 'RELEASE_SERVICE_UNAVAILABLE', 'error' => 'Release service unavailable'], 503);
}

if (empty($eligible['ok'])) {
    $code = (string) ($eligible['code'] ?? 'UPDATE_DENIED');
    $status = $code === 'RELEASE_SCHEMA_NOT_READY' ? 503 : 403;
    json_response(['ok' => false, 'code' => $code, 'error' => 'Update check is not available for this device'], $status);
}

try {
    DeviceManager::recordClientVersion($licenseKey, $hwid, $version);
} catch (Throwable $e) {
    // Telemetry is best-effort and never blocks delivery.
}

if (empty($eligible['update_available'])) {
    json_response([
        'ok' => true,
        'update_available' => false,
        'current_version' => $version,
        'server_time' => gmdate('Y-m-d\TH:i:s\Z'),
    ]);
}

$release = $eligible['release'];
$releaseId = (int) $release['id'];
$licenseId = (int) $eligible['license_id'];
$activationId = (int) $eligible['activation_id'];
$installerSha512Hex = release_sha512_hex($release['installer_sha512'] ?? null);

// Only bundles imported and verified by Hercule storage can enter the trusted
// auto-update channel. Legacy URL-only releases remain visible administratively
// but cannot be offered to the secured desktop updater.
if (empty($release['storage_key'])
    || empty($release['installer_filename'])
    || (int) ($release['installer_size'] ?? 0) <= 0
    || !preg_match('/^[a-f0-9]{64}$/i', (string) ($release['installer_sha256'] ?? ''))
    || $installerSha512Hex === null) {
    json_response([
        'ok' => false,
        'code' => 'UNSIGNED_LEGACY_RELEASE',
        'error' => 'This release is not eligible for the secured desktop updater.',
    ], 503);
}

$base = trim((string) ($_ENV['HERCULE_PUBLIC_BASE_URL'] ?? $_SERVER['HERCULE_PUBLIC_BASE_URL'] ?? getenv('HERCULE_PUBLIC_BASE_URL') ?: ''));
if ($base === '') {
    $host = trim((string) ($_SERVER['WEBSITE_HOSTNAME'] ?? $_SERVER['HTTP_HOST'] ?? ''));
    if (!preg_match('/^[A-Za-z0-9.-]+(?::\d+)?$/', $host)) {
        $host = '';
    }
    $proto = strtolower(trim((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? 'https')));
    if (!in_array($proto, ['http', 'https'], true)) {
        $proto = 'https';
    }
    $base = $host !== '' ? $proto . '://' . $host : '';
}
$base = rtrim($base, '/');

try {
    if ($base === '') {
        throw new RuntimeException('Public base URL is unavailable.');
    }
    $token = ReleaseManager::createDownloadGrant($releaseId, $licenseId, $activationId);
    $artifactUrls = [];
    foreach (['installer', 'blockmap', 'metadata'] as $artifact) {
        $artifactUrls[$artifact] = $base . '/public/api/release_download.php?token=' . rawurlencode($token) . '&artifact=' . $artifact;
    }
} catch (Throwable $e) {
    ErrorHandler::report($e, 'release_download_grant_failed', ['release_id' => $releaseId]);
    json_response(['ok' => false, 'code' => 'DOWNLOAD_GRANT_FAILED', 'error' => 'Could not prepare secure update download'], 503);
}

$manifestInput = [
    'release_id' => $releaseId,
    'version' => (string) $release['version'],
    'channel' => (string) ($release['channel'] ?? 'stable'),
    'minimum_supported_version' => $release['minimum_supported_version'] ?: null,
    'mandatory' => (bool) $eligible['mandatory'],
    'below_minimum_supported' => (bool) $eligible['below_minimum_supported'],
    'installer_file' => (string) $release['installer_filename'],
    'installer_size' => (int) $release['installer_size'],
    'installer_sha256' => strtolower((string) $release['installer_sha256']),
    'installer_sha512' => $installerSha512Hex,
    'published_at' => $release['published_at'] ?: null,
];

try {
    $signedUpdate = UpdateSigner::sign($manifestInput);
} catch (Throwable $e) {
    ErrorHandler::report($e, 'update_signing_unavailable', ['release_id' => $releaseId]);
    json_response([
        'ok' => false,
        'code' => 'UPDATE_SIGNING_UNAVAILABLE',
        'error' => 'Secure update signing is unavailable.',
    ], 503);
}

try {
    ReleaseManager::recordEvent($releaseId, $licenseId, $activationId, 'offered', $version);
} catch (Throwable $e) {
    // Telemetry is best-effort.
}

json_response([
    'ok' => true,
    'update_available' => true,
    'mandatory' => (bool) $eligible['mandatory'],
    'below_minimum_supported' => (bool) $eligible['below_minimum_supported'],
    'current_version' => $version,
    'signed_update' => $signedUpdate,
    'release' => [
        'id' => $releaseId,
        'version' => $release['version'],
        'channel' => $release['channel'] ?? 'stable',
        'minimum_supported_version' => $release['minimum_supported_version'],
        'release_notes' => $release['release_notes'],
        'published_at' => $release['published_at'],
        'installer' => [
            'url' => $artifactUrls['installer'],
            'file' => $release['installer_filename'],
            'size' => (int) $release['installer_size'],
            'sha256' => strtolower((string) $release['installer_sha256']),
            'sha512' => $installerSha512Hex,
        ],
        'blockmap' => !empty($release['blockmap_filename']) ? [
            'url' => $artifactUrls['blockmap'],
            'file' => $release['blockmap_filename'],
            'size' => (int) $release['blockmap_size'],
            'sha256' => $release['blockmap_sha256'],
        ] : null,
        'metadata' => !empty($release['metadata_filename']) ? [
            'url' => $artifactUrls['metadata'],
            'file' => $release['metadata_filename'],
            'size' => (int) $release['metadata_size'],
            'sha256' => $release['metadata_sha256'],
        ] : null,
        'external_url' => null,
    ],
    'server_time' => gmdate('Y-m-d\TH:i:s\Z'),
]);
