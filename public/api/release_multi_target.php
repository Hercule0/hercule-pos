<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../includes/ReleaseManager.php';
require_once __DIR__ . '/../../includes/ReleaseStorage.php';
require_once __DIR__ . '/../../includes/RateLimiter.php';
require_once __DIR__ . '/../../includes/UpdateSigner.php';
require_once __DIR__ . '/../../includes/MultiUpdateSigner.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_response(['ok' => false, 'error' => 'Method not allowed'], 405);
}

$input = json_input();
$licenseKey = trim((string) ($input['license_key'] ?? ''));
$hwid = trim((string) ($input['hwid'] ?? ''));
$currentVersion = trim((string) ($input['current_version'] ?? ''));
$channel = strtolower(trim((string) ($input['channel'] ?? 'stable')));
$releaseId = (int) ($input['release_id'] ?? 0);

if ($licenseKey === '' || $hwid === '' || $currentVersion === '' || $releaseId <= 0) {
    json_response(['ok' => false, 'error' => 'license_key, hwid, current_version and release_id are required'], 400);
}
if (strlen($licenseKey) > 64 || strlen($hwid) > 160 || strlen($currentVersion) > 50) {
    json_response(['ok' => false, 'error' => 'Request fields are too long'], 400);
}

$deviceBucket = 'multi-target-' . substr(hash('sha256', $licenseKey . '|' . $hwid), 0, 36);
if (!RateLimiter::check(client_ip(), 'release_multi_target_ip', 600, 5)
    || !RateLimiter::check($deviceBucket, 'release_multi_target_device', 30, 5)) {
    json_response(['ok' => false, 'error' => 'Too many update compatibility checks.'], 429);
}

try {
    $eligible = ReleaseManager::eligibleForClient($currentVersion, $licenseKey, $hwid, $channel);
} catch (Throwable $e) {
    ErrorHandler::report($e, 'release_multi_target_eligibility_failed');
    json_response(['ok' => false, 'error' => 'Release service unavailable'], 503);
}

if (empty($eligible['ok']) || empty($eligible['update_available']) || empty($eligible['release'])) {
    json_response(['ok' => false, 'error' => 'Requested release is not eligible for this device'], 403);
}

$release = $eligible['release'];
if ((int) ($release['id'] ?? 0) !== $releaseId) {
    json_response(['ok' => false, 'error' => 'Requested release does not match the eligible release'], 409);
}

$storageKey = basename(trim((string) ($release['storage_key'] ?? '')));
$installerSha256 = strtolower(trim((string) ($release['installer_sha256'] ?? '')));
if ($storageKey === '' || !preg_match('/^[a-f0-9]{64}$/', $installerSha256)) {
    json_response(['ok' => true, 'multi_target_available' => false, 'signed_multi_target' => null]);
}

$base = realpath(ReleaseStorage::baseDir());
$dir = realpath(ReleaseStorage::baseDir() . DIRECTORY_SEPARATOR . $storageKey);
$manifestPath = $dir ? realpath($dir . DIRECTORY_SEPARATOR . 'manifest.json') : false;
if (!$base || !$dir || !$manifestPath
    || !str_starts_with($dir, $base . DIRECTORY_SEPARATOR)
    || !str_starts_with($manifestPath, $dir . DIRECTORY_SEPARATOR)
    || !is_file($manifestPath)) {
    json_response(['ok' => true, 'multi_target_available' => false, 'signed_multi_target' => null]);
}

$manifestText = file_get_contents($manifestPath, false, null, 0, 256 * 1024 + 1);
if (!is_string($manifestText) || strlen($manifestText) > 256 * 1024) {
    json_response(['ok' => false, 'error' => 'Stored release manifest is invalid'], 503);
}
$manifest = json_decode($manifestText, true);
$multi = is_array($manifest) ? ($manifest['multi_runtime'] ?? null) : null;
if (!is_array($multi)) {
    // Legacy bundles remain valid for Single POS, but committed Multi clients
    // will fail closed at install time because they require this signed target.
    json_response(['ok' => true, 'multi_target_available' => false, 'signed_multi_target' => null]);
}

$version = trim((string) ($release['version'] ?? ''));
$protocolMin = (int) ($multi['protocol_min'] ?? 0);
$protocolMax = (int) ($multi['protocol_max'] ?? 0);
$centralSchema = (int) ($multi['central_schema_version'] ?? 0);
if ((int) ($multi['schema_version'] ?? 0) !== 1
    || trim((string) ($multi['app_version'] ?? '')) !== $version
    || $protocolMin < 1 || $protocolMax < $protocolMin || $centralSchema < 1) {
    json_response(['ok' => false, 'error' => 'Stored Multi target metadata is invalid'], 503);
}

try {
    $signed = MultiUpdateSigner::sign([
        'release_id' => $releaseId,
        'version' => $version,
        'installer_sha256' => $installerSha256,
        'protocol_min' => $protocolMin,
        'protocol_max' => $protocolMax,
        'central_schema_version' => $centralSchema,
    ]);
} catch (Throwable $e) {
    ErrorHandler::report($e, 'release_multi_target_signing_failed', ['release_id' => $releaseId]);
    json_response(['ok' => false, 'error' => 'Secure Multi update signing is unavailable'], 503);
}

json_response([
    'ok' => true,
    'multi_target_available' => true,
    'signed_multi_target' => $signed,
]);
