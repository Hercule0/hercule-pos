<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/../../includes/ReleaseManager.php';
require_once __DIR__ . '/../../includes/ReleaseStorage.php';

Auth::require();
Auth::requirePermission('releases.manage');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

const HERCULE_RELEASE_CHUNK_BYTES = 1048576; // 1 MiB: stays below common PHP upload limits.
const HERCULE_RELEASE_UPLOAD_TTL = 21600; // 6 hours.

function release_upload_reply(bool $ok, string $message, array $extra = [], int $status = 200): never
{
    http_response_code($status);
    echo json_encode(array_merge(['ok' => $ok, 'message' => $message], $extra), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function release_upload_root(): string
{
    $base = ReleaseStorage::ensureWritable();
    $root = $base . DIRECTORY_SEPARATOR . '.admin-uploads';
    if (!is_dir($root) && !@mkdir($root, 0770, true) && !is_dir($root)) {
        throw new RuntimeException('Could not create temporary release upload storage.');
    }
    if (!is_writable($root)) {
        throw new RuntimeException('Temporary release upload storage is not writable.');
    }
    return $root;
}

function release_upload_cleanup_stale(): void
{
    $root = release_upload_root();
    $now = time();
    foreach (glob($root . DIRECTORY_SEPARATOR . 'upload-*', GLOB_ONLYDIR) ?: [] as $dir) {
        $mtime = @filemtime($dir) ?: 0;
        if ($mtime > 0 && ($now - $mtime) <= HERCULE_RELEASE_UPLOAD_TTL) continue;
        release_upload_remove_tree($dir);
    }
}

function release_upload_remove_tree(string $dir): void
{
    if (!is_dir($dir)) return;
    foreach (scandir($dir) ?: [] as $item) {
        if ($item === '.' || $item === '..') continue;
        $path = $dir . DIRECTORY_SEPARATOR . $item;
        if (is_dir($path)) release_upload_remove_tree($path); else @unlink($path);
    }
    @rmdir($dir);
}

function release_upload_id(string $raw): string
{
    $id = strtolower(trim($raw));
    if (!preg_match('/^[a-f0-9]{32}$/', $id)) {
        throw new InvalidArgumentException('Invalid upload session. Start the upload again.');
    }
    return $id;
}

function release_upload_dir(string $id): string
{
    return release_upload_root() . DIRECTORY_SEPARATOR . 'upload-' . $id;
}

function release_upload_meta_path(string $id): string
{
    return release_upload_dir($id) . DIRECTORY_SEPARATOR . 'meta.json';
}

function release_upload_part_path(string $id): string
{
    return release_upload_dir($id) . DIRECTORY_SEPARATOR . 'bundle.part';
}

function release_upload_read_meta(string $id): array
{
    $path = release_upload_meta_path($id);
    if (!is_file($path)) {
        throw new RuntimeException('Upload session was not found or has expired. Start the upload again.');
    }
    $raw = @file_get_contents($path);
    $meta = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($meta)) {
        throw new RuntimeException('Upload session metadata is damaged. Start the upload again.');
    }
    $owner = Auth::currentUsername() ?? '';
    if (!hash_equals((string)($meta['owner'] ?? ''), $owner)) {
        throw new RuntimeException('This upload session belongs to another administrator.');
    }
    return $meta;
}

function release_upload_write_meta(string $id, array $meta): void
{
    $dir = release_upload_dir($id);
    $path = release_upload_meta_path($id);
    $tmp = $dir . DIRECTORY_SEPARATOR . 'meta.tmp-' . bin2hex(random_bytes(4));
    $meta['updated_at'] = time();
    $json = json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if (!is_string($json) || @file_put_contents($tmp, $json . "\n", LOCK_EX) === false || !@rename($tmp, $path)) {
        @unlink($tmp);
        throw new RuntimeException('Could not save upload progress.');
    }
    @touch($dir);
}

function release_upload_validate_csrf(): void
{
    $token = (string)($_POST['csrf_token'] ?? '');
    if (!Csrf::check($token)) {
        release_upload_reply(false, 'Your session security token expired. Refresh the page and try again.', [], 403);
    }
}

function release_upload_begin(): never
{
    release_upload_cleanup_stale();

    $name = basename(trim((string)($_POST['bundle_name'] ?? '')));
    $size = (int)($_POST['bundle_size'] ?? 0);
    if ($name === '' || strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== 'zip') {
        release_upload_reply(false, 'Choose a Hercule update bundle ZIP first.', [], 400);
    }
    if ($size <= 0) {
        release_upload_reply(false, 'The selected update bundle is empty.', [], 400);
    }
    if ($size > ReleaseStorage::maxUploadBytes()) {
        release_upload_reply(false, 'Update bundle exceeds the configured total upload size limit.', [
            'max_bytes' => ReleaseStorage::maxUploadBytes(),
        ], 400);
    }

    $id = bin2hex(random_bytes(16));
    $dir = release_upload_dir($id);
    if (!@mkdir($dir, 0770, true) && !is_dir($dir)) {
        throw new RuntimeException('Could not create upload session directory.');
    }

    $meta = [
        'id' => $id,
        'owner' => Auth::currentUsername() ?? '',
        'name' => $name,
        'size' => $size,
        'chunk_size' => HERCULE_RELEASE_CHUNK_BYTES,
        'total_chunks' => (int)ceil($size / HERCULE_RELEASE_CHUNK_BYTES),
        'next_index' => 0,
        'received' => 0,
        'status' => 'uploading',
        'created_at' => time(),
        'updated_at' => time(),
    ];
    release_upload_write_meta($id, $meta);

    release_upload_reply(true, 'Upload session created.', [
        'upload_id' => $id,
        'chunk_size' => HERCULE_RELEASE_CHUNK_BYTES,
        'total_chunks' => $meta['total_chunks'],
        'max_bytes' => ReleaseStorage::maxUploadBytes(),
    ]);
}

function release_upload_chunk(): never
{
    $id = release_upload_id((string)($_POST['upload_id'] ?? ''));
    $index = filter_var($_POST['chunk_index'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
    if ($index === false) {
        release_upload_reply(false, 'Invalid upload chunk index.', [], 400);
    }

    $meta = release_upload_read_meta($id);
    if (($meta['status'] ?? '') === 'completed') {
        release_upload_reply(true, 'Release upload already completed.', ['completed' => true, 'result' => $meta['result'] ?? null]);
    }
    if (($meta['status'] ?? '') !== 'uploading') {
        release_upload_reply(false, 'Upload session is not accepting chunks.', [], 409);
    }

    $expectedIndex = (int)($meta['next_index'] ?? 0);
    if ($index < $expectedIndex) {
        release_upload_reply(true, 'Chunk was already received.', [
            'already_received' => true,
            'next_index' => $expectedIndex,
            'received' => (int)($meta['received'] ?? 0),
            'total' => (int)$meta['size'],
        ]);
    }
    if ($index !== $expectedIndex) {
        release_upload_reply(false, 'Upload chunks arrived out of order. Retry the current chunk.', ['next_index' => $expectedIndex], 409);
    }

    $chunk = $_FILES['chunk'] ?? [];
    $error = (int)($chunk['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error !== UPLOAD_ERR_OK) {
        $message = match ($error) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'This upload chunk exceeded the PHP server limit.',
            UPLOAD_ERR_PARTIAL => 'This upload chunk was only partially received.',
            UPLOAD_ERR_NO_TMP_DIR => 'Server temporary upload directory is unavailable.',
            UPLOAD_ERR_CANT_WRITE => 'Server could not write this upload chunk.',
            default => 'Upload chunk failed before it reached the server.',
        };
        release_upload_reply(false, $message, [], 400);
    }

    $tmp = (string)($chunk['tmp_name'] ?? '');
    $chunkSize = (int)($chunk['size'] ?? 0);
    if ($tmp === '' || !is_file($tmp) || $chunkSize <= 0) {
        release_upload_reply(false, 'Received upload chunk is missing or empty.', [], 400);
    }

    $totalSize = (int)$meta['size'];
    $chunkBytes = (int)$meta['chunk_size'];
    $offset = $index * $chunkBytes;
    $expectedBytes = min($chunkBytes, $totalSize - $offset);
    if ($expectedBytes <= 0 || $chunkSize !== $expectedBytes) {
        release_upload_reply(false, 'Upload chunk size does not match the expected file segment.', [
            'expected_bytes' => max(0, $expectedBytes),
            'received_bytes' => $chunkSize,
        ], 400);
    }

    $part = release_upload_part_path($id);
    $currentSize = is_file($part) ? (int)filesize($part) : 0;
    $received = (int)($meta['received'] ?? 0);
    if ($currentSize !== $received || $currentSize !== $offset) {
        release_upload_reply(false, 'Server upload state is inconsistent. Start the upload again.', [], 409);
    }

    $in = @fopen($tmp, 'rb');
    $out = @fopen($part, 'ab');
    if (!is_resource($in) || !is_resource($out)) {
        if (is_resource($in)) fclose($in);
        if (is_resource($out)) fclose($out);
        throw new RuntimeException('Could not open temporary release upload files.');
    }

    try {
        if (!flock($out, LOCK_EX)) throw new RuntimeException('Could not lock release upload file.');
        $written = stream_copy_to_stream($in, $out);
        fflush($out);
        flock($out, LOCK_UN);
    } finally {
        fclose($in);
        fclose($out);
    }

    if ($written !== $chunkSize) {
        throw new RuntimeException('Server did not persist the complete upload chunk.');
    }

    $meta['received'] = $received + $chunkSize;
    $meta['next_index'] = $index + 1;
    release_upload_write_meta($id, $meta);

    release_upload_reply(true, 'Chunk uploaded.', [
        'next_index' => $meta['next_index'],
        'received' => $meta['received'],
        'total' => $totalSize,
        'percent' => round(((int)$meta['received'] / $totalSize) * 100, 2),
    ]);
}

function release_upload_finish(): never
{
    $id = release_upload_id((string)($_POST['upload_id'] ?? ''));
    $meta = release_upload_read_meta($id);

    if (($meta['status'] ?? '') === 'completed') {
        $result = is_array($meta['result'] ?? null) ? $meta['result'] : [];
        release_upload_reply(true, (string)($meta['completion_message'] ?? 'Release upload already completed.'), ['release' => $result, 'already_completed' => true]);
    }

    $total = (int)($meta['size'] ?? 0);
    $received = (int)($meta['received'] ?? 0);
    $totalChunks = (int)($meta['total_chunks'] ?? 0);
    $nextIndex = (int)($meta['next_index'] ?? 0);
    $part = release_upload_part_path($id);
    $actual = is_file($part) ? (int)filesize($part) : -1;

    if ($total <= 0 || $received !== $total || $actual !== $total || $nextIndex !== $totalChunks) {
        release_upload_reply(false, 'Upload is incomplete. Continue uploading the missing chunks first.', [
            'received' => $received,
            'total' => $total,
            'next_index' => $nextIndex,
            'total_chunks' => $totalChunks,
        ], 409);
    }

    @set_time_limit(0);
    @ini_set('max_execution_time', '0');
    ignore_user_abort(true);

    $meta['status'] = 'verifying';
    release_upload_write_meta($id, $meta);

    $upload = [
        'error' => UPLOAD_ERR_OK,
        'tmp_name' => $part,
        'name' => (string)$meta['name'],
        'size' => $total,
        'type' => 'application/zip',
    ];

    $releaseData = [
        'minimum_supported_version' => $_POST['minimum_supported_version'] ?? null,
        'channel' => $_POST['channel'] ?? 'stable',
        'release_notes' => $_POST['release_notes'] ?? null,
        'target_mode' => $_POST['target_mode'] ?? 'all',
        'target_license_ids' => $_POST['target_license_ids'] ?? [],
        'is_mandatory' => !empty($_POST['is_mandatory']) ? 1 : 0,
        'is_published' => !empty($_POST['is_published']) ? 1 : 0,
    ];

    try {
        $result = ReleaseManager::createFromBundle($upload, $releaseData, Auth::currentUsername() ?? 'admin');
        $message = 'Update bundle v' . $result['version'] . ' uploaded successfully' . ($result['published'] ? ' and published.' : ' as a draft.');
        $meta['status'] = 'completed';
        $meta['result'] = $result;
        $meta['completion_message'] = $message;
        $meta['completed_at'] = time();
        release_upload_write_meta($id, $meta);
        @unlink($part);
        release_upload_reply(true, $message, ['release' => $result]);
    } catch (Throwable $e) {
        $meta['status'] = 'uploading';
        $meta['verify_error'] = $e->getMessage();
        release_upload_write_meta($id, $meta);
        release_upload_reply(false, $e->getMessage(), ['retryable_finish' => true], 400);
    }
}

function release_upload_cancel(): never
{
    $id = release_upload_id((string)($_POST['upload_id'] ?? ''));
    release_upload_read_meta($id);
    release_upload_remove_tree(release_upload_dir($id));
    release_upload_reply(true, 'Temporary upload removed.');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    release_upload_reply(false, 'POST required.', [], 405);
}

release_upload_validate_csrf();
$action = (string)($_POST['action'] ?? '');

try {
    match ($action) {
        'init' => release_upload_begin(),
        'chunk' => release_upload_chunk(),
        'finish' => release_upload_finish(),
        'cancel' => release_upload_cancel(),
        default => release_upload_reply(false, 'Unknown upload action.', [], 400),
    };
} catch (Throwable $e) {
    release_upload_reply(false, $e->getMessage(), [], 500);
}
