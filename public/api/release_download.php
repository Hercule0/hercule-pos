<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../includes/ReleaseManager.php';
require_once __DIR__ . '/../../includes/ReleaseStorage.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    json_response(['ok'=>false,'error'=>'Method not allowed'], 405);
}

$token = trim((string)($_GET['token'] ?? ''));
$artifact = strtolower(trim((string)($_GET['artifact'] ?? 'installer')));
if (!in_array($artifact, ['installer','blockmap','metadata'], true)) {
    json_response(['ok'=>false,'error'=>'Unknown release artifact'], 400);
}

try {
    $grant = ReleaseManager::resolveDownloadGrant($token);
    if (!$grant) json_response(['ok'=>false,'code'=>'DOWNLOAD_TOKEN_INVALID','error'=>'Download token is invalid or expired'], 403);
    $file = ReleaseStorage::artifactPath($grant, $artifact);
} catch (Throwable $e) {
    json_response(['ok'=>false,'code'=>'ARTIFACT_UNAVAILABLE','error'=>'Release artifact unavailable'], 404);
}

$path = $file['path'];
$size = (int)$file['size'];
if ($size <= 0) json_response(['ok'=>false,'error'=>'Release artifact is empty'], 404);

$start = 0;
$end = $size - 1;
$status = 200;
$range = trim((string)($_SERVER['HTTP_RANGE'] ?? ''));
if ($range !== '') {
    if (!preg_match('/^bytes=(\d*)-(\d*)$/', $range, $m)) {
        header('Content-Range: bytes */' . $size);
        http_response_code(416);
        exit;
    }
    if ($m[1] === '' && $m[2] !== '') {
        $suffix = (int)$m[2];
        if ($suffix <= 0) { header('Content-Range: bytes */' . $size); http_response_code(416); exit; }
        $start = max(0, $size - $suffix);
    } else {
        $start = (int)$m[1];
        if ($m[2] !== '') $end = min($end, (int)$m[2]);
    }
    if ($start < 0 || $start >= $size || $end < $start) {
        header('Content-Range: bytes */' . $size);
        http_response_code(416);
        exit;
    }
    $status = 206;
}

$length = $end - $start + 1;
while (ob_get_level() > 0) @ob_end_clean();
@set_time_limit(0);
ignore_user_abort(true);

header('Content-Type: ' . $file['mime']);
header('Content-Disposition: attachment; filename="' . addcslashes($file['filename'], '"\\') . '"');
header('Accept-Ranges: bytes');
header('Content-Length: ' . $length);
header('Cache-Control: private, no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
if ($status === 206) {
    http_response_code(206);
    header("Content-Range: bytes {$start}-{$end}/{$size}");
} else {
    http_response_code(200);
}

try { ReleaseManager::touchDownloadGrant((int)$grant['grant_id']); } catch (Throwable $e) {}
if ($artifact === 'installer') {
    try { ReleaseManager::recordEvent((int)$grant['release_id'], (int)$grant['license_id'], (int)$grant['activation_id'], 'download_started', null); } catch (Throwable $e) {}
}

$fh = fopen($path, 'rb');
if (!is_resource($fh)) exit;
fseek($fh, $start);
$remaining = $length;
$chunkSize = 1024 * 1024;
while ($remaining > 0 && !feof($fh)) {
    $chunk = fread($fh, min($chunkSize, $remaining));
    if ($chunk === false || $chunk === '') break;
    echo $chunk;
    $remaining -= strlen($chunk);
    flush();
    if (connection_aborted()) break;
}
fclose($fh);

if ($remaining === 0 && $artifact === 'installer') {
    try { ReleaseManager::recordEvent((int)$grant['release_id'], (int)$grant['license_id'], (int)$grant['activation_id'], 'downloaded', null); } catch (Throwable $e) {}
}
exit;
