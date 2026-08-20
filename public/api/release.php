<?php
/**
 * GET /api/release.php?version=1.2.3
 * Returns latest published desktop release metadata.
 */
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../includes/ReleaseManager.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response(['ok' => false, 'error' => 'Method not allowed'], 405);
}

try {
    $release = ReleaseManager::latestPublished();
} catch (Throwable $e) {
    json_response(['ok' => false, 'error' => 'Release service unavailable'], 503);
}

if (!$release) {
    json_response([
        'ok' => true,
        'update_available' => false,
        'release' => null,
        'server_time' => gmdate('Y-m-d\TH:i:s\Z'),
    ]);
}

$clientVersion = trim((string) ($_GET['version'] ?? ''));
$updateAvailable = $clientVersion !== ''
    ? ReleaseManager::compare($clientVersion, (string) $release['version']) < 0
    : true;

$belowMinimum = false;
if ($clientVersion !== '' && !empty($release['minimum_supported_version'])) {
    $belowMinimum = ReleaseManager::compare($clientVersion, (string) $release['minimum_supported_version']) < 0;
}

$mandatory = !empty($release['is_mandatory']) || $belowMinimum;

json_response([
    'ok' => true,
    'update_available' => $updateAvailable,
    'mandatory' => $mandatory,
    'below_minimum_supported' => $belowMinimum,
    'release' => [
        'version' => $release['version'],
        'minimum_supported_version' => $release['minimum_supported_version'],
        'download_url' => $release['download_url'],
        'release_notes' => $release['release_notes'],
        'published_at' => $release['published_at'],
    ],
    'server_time' => gmdate('Y-m-d\TH:i:s\Z'),
]);
