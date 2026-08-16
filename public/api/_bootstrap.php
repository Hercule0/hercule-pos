<?php
/**
 * Shared bootstrap for public API endpoints (not the admin panel — see
 * admin/includes/bootstrap.php for that, which additionally starts sessions
 * and enforces auth).
 */

require_once __DIR__ . '/../../includes/Database.php';
require_once __DIR__ . '/../../includes/License.php';
require_once __DIR__ . '/../../includes/RsaSigner.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('X-Frame-Options: DENY');

function json_input(): array
{
    $contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($contentLength > 16384) {
        json_response(['ok' => false, 'error' => 'Request body is too large.'], 413);
    }

    $contentType = strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0]));
    if ($contentType !== 'application/json') {
        json_response(['ok' => false, 'error' => 'Content-Type must be application/json.'], 415);
    }

    $raw = file_get_contents('php://input', false, null, 0, 16385);
    if ($raw === false || strlen($raw) > 16384) {
        json_response(['ok' => false, 'error' => 'Request body is too large.'], 413);
    }

    try {
        $data = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        json_response(['ok' => false, 'error' => 'Invalid JSON body.'], 400);
    }

    return is_array($data) ? $data : [];
}

function json_response(array $data, int $statusCode = 200): void
{
    http_response_code($statusCode);
    echo json_encode($data);
    exit;
}

function client_ip(): string
{
    return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
}
