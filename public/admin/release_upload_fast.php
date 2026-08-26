<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/../../includes/ReleaseManager.php';
require_once __DIR__ . '/../../includes/ReleaseStorage.php';
require_once __DIR__ . '/../../includes/AuditLog.php';

Auth::require();
Auth::requirePermission('releases.manage');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

const HERCULE_FAST_UPLOAD_TTL = 21600; // 6 hours
const HERCULE_FAST_CHUNK_DEFAULT = 524288; // 512 KiB
const HERCULE_FAST_CHUNK_MIN = 131072; // 128 KiB
const HERCULE_FAST_CHUNK_MAX = 524288; // 512 KiB

function fast_upload_reply(bool $ok, string $message, array $extra = [], int $status = 200): never
{
    http_response_code($status);
    echo json_encode(array_merge(['ok' => $ok, 'message' => $message], $extra), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function fast_upload_root(): string
{
    $base = ReleaseStorage::ensureWritable();
    $root = $base . DIRECTORY_SEPARATOR . '.admin-uploads-fast';
    if (!is_dir($root) && !@mkdir($root, 0770, true) && !is_dir($root)) {
        throw new RuntimeException('Could not create temporary release upload storage.');
    }
    if (!is_writable($root)) {
        throw new RuntimeException('Temporary release upload storage is not writable.');
    }
    return $root;
}

function fast_upload_remove_tree(string $dir): void
{
    if (!is_dir($dir)) return;
    foreach (scandir($dir) ?: [] as $item) {
        if ($item === '.' || $item === '..') continue;
        $path = $dir . DIRECTORY_SEPARATOR . $item;
        if (is_dir($path)) fast_upload_remove_tree($path); else @unlink($path);
    }
    @rmdir($dir);
}

function fast_upload_cleanup(): void
{
    $root = fast_upload_root();
    $now = time();
    foreach (glob($root . DIRECTORY_SEPARATOR . 'upload-*', GLOB_ONLYDIR) ?: [] as $dir) {
        $mtime = @filemtime($dir) ?: 0;
        if ($mtime > 0 && ($now - $mtime) <= HERCULE_FAST_UPLOAD_TTL) continue;
        fast_upload_remove_tree($dir);
    }
}

function fast_upload_id(string $raw): string
{
    $id = strtolower(trim($raw));
    if (!preg_match('/^[a-f0-9]{32}$/', $id)) {
        throw new InvalidArgumentException('Invalid upload session. Start the upload again.');
    }
    return $id;
}

function fast_upload_dir(string $id): string
{
    return fast_upload_root() . DIRECTORY_SEPARATOR . 'upload-' . $id;
}

function fast_upload_meta_path(string $id): string
{
    return fast_upload_dir($id) . DIRECTORY_SEPARATOR . 'meta.json';
}

function fast_upload_chunk_path(string $id, int $index): string
{
    return fast_upload_dir($id) . DIRECTORY_SEPARATOR . sprintf('chunk-%06d.bin', $index);
}

function fast_upload_read_meta(string $id): array
{
    $path = fast_upload_meta_path($id);
    if (!is_file($path)) throw new RuntimeException('Upload session was not found or has expired. Start again.');
    $raw = @file_get_contents($path);
    $meta = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($meta)) throw new RuntimeException('Upload session metadata is damaged. Start again.');
    $owner = Auth::currentUsername() ?? '';
    if (!hash_equals((string)($meta['owner'] ?? ''), $owner)) {
        throw new RuntimeException('This upload session belongs to another administrator.');
    }
    return $meta;
}

function fast_upload_write_meta(string $id, array $meta): void
{
    $dir = fast_upload_dir($id);
    $tmp = $dir . DIRECTORY_SEPARATOR . 'meta.tmp-' . bin2hex(random_bytes(4));
    $path = fast_upload_meta_path($id);
    $meta['updated_at'] = time();
    $json = json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if (!is_string($json) || @file_put_contents($tmp, $json . "\n", LOCK_EX) === false || !@rename($tmp, $path)) {
        @unlink($tmp);
        throw new RuntimeException('Could not save upload progress.');
    }
    @touch($dir);
}

function fast_upload_validate_csrf(): void
{
    $token = (string)($_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
    if (!Csrf::check($token)) {
        fast_upload_reply(false, 'Your session security token expired. Refresh the page and try again.', [], 403);
    }
}

function fast_upload_action(): string
{
    return (string)($_POST['action'] ?? $_GET['action'] ?? '');
}

function fast_upload_begin(): never
{
    fast_upload_cleanup();
    $name = basename(trim((string)($_POST['bundle_name'] ?? '')));
    $size = (int)($_POST['bundle_size'] ?? 0);
    if ($name === '' || strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== 'zip') {
        fast_upload_reply(false, 'Choose a Hercule update bundle ZIP first.', [], 400);
    }
    if ($size <= 0) fast_upload_reply(false, 'The selected update bundle is empty.', [], 400);
    if ($size > ReleaseStorage::maxUploadBytes()) {
        fast_upload_reply(false, 'Update bundle exceeds the configured total upload size limit.', ['max_bytes' => ReleaseStorage::maxUploadBytes()], 400);
    }

    $requested = (int)($_POST['requested_chunk_size'] ?? HERCULE_FAST_CHUNK_DEFAULT);
    $chunkSize = max(HERCULE_FAST_CHUNK_MIN, min(HERCULE_FAST_CHUNK_MAX, $requested));
    // Align to 64 KiB so slicing remains predictable.
    $chunkSize = intdiv($chunkSize, 65536) * 65536;
    $chunkSize = max(HERCULE_FAST_CHUNK_MIN, $chunkSize);

    $id = bin2hex(random_bytes(16));
    $dir = fast_upload_dir($id);
    if (!@mkdir($dir, 0770, true) && !is_dir($dir)) throw new RuntimeException('Could not create upload session directory.');

    $meta = [
        'id' => $id,
        'owner' => Auth::currentUsername() ?? '',
        'name' => $name,
        'size' => $size,
        'chunk_size' => $chunkSize,
        'total_chunks' => (int)ceil($size / $chunkSize),
        'status' => 'uploading',
        'created_at' => time(),
        'updated_at' => time(),
    ];
    fast_upload_write_meta($id, $meta);
    fast_upload_reply(true, 'Upload session created.', [
        'upload_id' => $id,
        'chunk_size' => $chunkSize,
        'total_chunks' => $meta['total_chunks'],
        'parallelism' => 6,
        'max_bytes' => ReleaseStorage::maxUploadBytes(),
    ]);
}

function fast_upload_chunk(): never
{
    $id = fast_upload_id((string)($_GET['upload_id'] ?? $_POST['upload_id'] ?? ''));
    $index = filter_var($_GET['chunk_index'] ?? $_POST['chunk_index'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
    if ($index === false) fast_upload_reply(false, 'Invalid upload chunk index.', [], 400);

    $meta = fast_upload_read_meta($id);
    if (($meta['status'] ?? '') !== 'uploading') fast_upload_reply(false, 'Upload session is not accepting chunks.', [], 409);

    $totalSize = (int)$meta['size'];
    $chunkSize = (int)$meta['chunk_size'];
    $totalChunks = (int)$meta['total_chunks'];
    if ($index >= $totalChunks) fast_upload_reply(false, 'Upload chunk index is outside the session range.', [], 400);

    $offset = $index * $chunkSize;
    $expectedBytes = min($chunkSize, $totalSize - $offset);
    if ($expectedBytes <= 0) fast_upload_reply(false, 'Upload chunk is outside the file range.', [], 400);

    $final = fast_upload_chunk_path($id, $index);
    if (is_file($final)) {
        $existing = (int)filesize($final);
        if ($existing === $expectedBytes) {
            @touch(fast_upload_dir($id));
            fast_upload_reply(true, 'Chunk was already received.', ['already_received' => true, 'chunk_index' => $index, 'bytes' => $existing]);
        }
        @unlink($final);
    }

    $contentLength = isset($_SERVER['CONTENT_LENGTH']) ? (int)$_SERVER['CONTENT_LENGTH'] : 0;
    if ($contentLength > 0 && $contentLength !== $expectedBytes) {
        fast_upload_reply(false, 'Upload chunk size does not match the expected file segment.', ['expected_bytes' => $expectedBytes, 'received_bytes' => $contentLength], 400);
    }
    if ($contentLength > HERCULE_FAST_CHUNK_MAX) {
        fast_upload_reply(false, 'Upload chunk is larger than the server safety limit.', [], 413);
    }

    $in = @fopen('php://input', 'rb');
    if (!is_resource($in)) throw new RuntimeException('Could not read upload chunk body.');
    $tmp = $final . '.tmp-' . bin2hex(random_bytes(4));
    $out = @fopen($tmp, 'xb');
    if (!is_resource($out)) {
        fclose($in);
        throw new RuntimeException('Could not create temporary upload chunk.');
    }

    try {
        $written = stream_copy_to_stream($in, $out, $expectedBytes + 1);
        fflush($out);
    } finally {
        fclose($in);
        fclose($out);
    }

    if ($written !== $expectedBytes || (int)filesize($tmp) !== $expectedBytes) {
        @unlink($tmp);
        fast_upload_reply(false, 'Server did not receive the complete upload chunk.', ['expected_bytes' => $expectedBytes, 'received_bytes' => (int)$written], 400);
    }
    if (!@rename($tmp, $final)) {
        @unlink($tmp);
        if (is_file($final) && (int)filesize($final) === $expectedBytes) {
            fast_upload_reply(true, 'Chunk was already received.', ['already_received' => true, 'chunk_index' => $index, 'bytes' => $expectedBytes]);
        }
        throw new RuntimeException('Could not finalize upload chunk.');
    }
    @touch(fast_upload_dir($id));
    fast_upload_reply(true, 'Chunk uploaded.', ['chunk_index' => $index, 'bytes' => $expectedBytes]);
}

function fast_upload_finish(): never
{
    $id = fast_upload_id((string)($_POST['upload_id'] ?? ''));
    $meta = fast_upload_read_meta($id);
    if (($meta['status'] ?? '') === 'completed') {
        fast_upload_reply(true, (string)($meta['completion_message'] ?? 'Release upload already completed.'), ['release' => $meta['result'] ?? [], 'already_completed' => true]);
    }

    $total = (int)$meta['size'];
    $chunkSize = (int)$meta['chunk_size'];
    $totalChunks = (int)$meta['total_chunks'];
    $missing = [];
    $received = 0;
    for ($i = 0; $i < $totalChunks; $i++) {
        $path = fast_upload_chunk_path($id, $i);
        $expected = min($chunkSize, $total - ($i * $chunkSize));
        if (!is_file($path) || (int)filesize($path) !== $expected) {
            $missing[] = $i;
            if (count($missing) >= 20) break;
        } else {
            $received += $expected;
        }
    }
    if ($missing) {
        fast_upload_reply(false, 'Upload is incomplete. Some parts are still missing.', ['missing_chunks' => $missing, 'received' => $received, 'total' => $total], 409);
    }

    @set_time_limit(0);
    @ini_set('max_execution_time', '0');
    ignore_user_abort(true);

    $lockPath = fast_upload_dir($id) . DIRECTORY_SEPARATOR . 'finish.lock';
    $lock = @fopen($lockPath, 'c+');
    if (!is_resource($lock) || !flock($lock, LOCK_EX | LOCK_NB)) {
        if (is_resource($lock)) fclose($lock);
        fast_upload_reply(false, 'Release verification is already running. Wait a moment and retry.', ['retryable_finish' => true], 409);
    }

    $merged = fast_upload_dir($id) . DIRECTORY_SEPARATOR . 'bundle.part';
    try {
        $meta = fast_upload_read_meta($id);
        $meta['status'] = 'verifying';
        fast_upload_write_meta($id, $meta);

        $out = @fopen($merged, 'wb');
        if (!is_resource($out)) throw new RuntimeException('Could not create merged update bundle.');
        try {
            for ($i = 0; $i < $totalChunks; $i++) {
                $in = @fopen(fast_upload_chunk_path($id, $i), 'rb');
                if (!is_resource($in)) throw new RuntimeException('A required upload chunk disappeared during merge.');
                try {
                    if (stream_copy_to_stream($in, $out) === false) throw new RuntimeException('Could not merge upload chunks.');
                } finally {
                    fclose($in);
                }
            }
            fflush($out);
        } finally {
            fclose($out);
        }
        if (!is_file($merged) || (int)filesize($merged) !== $total) throw new RuntimeException('Merged update bundle size is invalid.');

        $upload = ['error' => UPLOAD_ERR_OK, 'tmp_name' => $merged, 'name' => (string)$meta['name'], 'size' => $total, 'type' => 'application/zip'];
        $releaseData = [
            'minimum_supported_version' => $_POST['minimum_supported_version'] ?? null,
            'channel' => $_POST['channel'] ?? 'stable',
            'release_notes' => $_POST['release_notes'] ?? null,
            'target_mode' => $_POST['target_mode'] ?? 'all',
            'target_license_ids' => $_POST['target_license_ids'] ?? [],
            'is_mandatory' => !empty($_POST['is_mandatory']) ? 1 : 0,
            'is_published' => !empty($_POST['is_published']) ? 1 : 0,
        ];

        $result = ReleaseManager::createFromBundle($upload, $releaseData, Auth::currentUsername() ?? 'admin');
        AuditLog::adminAction(
            'release_bundle_uploaded',
            (int)$result['id'],
            'version=' . $result['version'] . '; published=' . ($result['published'] ? '1' : '0') . '; target=' . $result['target_mode'] . '; transport=parallel'
        );
        $message = 'Update bundle v' . $result['version'] . ' uploaded successfully' . ($result['published'] ? ' and published.' : ' as a draft.');
        $meta['status'] = 'completed';
        $meta['result'] = $result;
        $meta['completion_message'] = $message;
        $meta['completed_at'] = time();
        fast_upload_write_meta($id, $meta);
        @unlink($merged);
        for ($i = 0; $i < $totalChunks; $i++) @unlink(fast_upload_chunk_path($id, $i));
        fast_upload_reply(true, $message, ['release' => $result]);
    } catch (Throwable $e) {
        $meta = fast_upload_read_meta($id);
        $meta['status'] = 'uploading';
        $meta['verify_error'] = $e->getMessage();
        fast_upload_write_meta($id, $meta);
        @unlink($merged);
        fast_upload_reply(false, $e->getMessage(), ['retryable_finish' => true], 400);
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function fast_upload_cancel(): never
{
    $id = fast_upload_id((string)($_POST['upload_id'] ?? ''));
    fast_upload_read_meta($id);
    fast_upload_remove_tree(fast_upload_dir($id));
    fast_upload_reply(true, 'Temporary upload removed.');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') fast_upload_reply(false, 'POST required.', [], 405);
fast_upload_validate_csrf();
$action = fast_upload_action();

try {
    match ($action) {
        'init' => fast_upload_begin(),
        'chunk' => fast_upload_chunk(),
        'finish' => fast_upload_finish(),
        'cancel' => fast_upload_cancel(),
        default => fast_upload_reply(false, 'Unknown upload action.', [], 400),
    };
} catch (Throwable $e) {
    fast_upload_reply(false, $e->getMessage(), [], 500);
}
