<?php
/**
 * Shared bootstrap for public API endpoints (not the admin panel — see
 * admin/includes/bootstrap.php for that, which additionally starts sessions
 * and enforces auth).
 */

require_once __DIR__ . '/../../includes/ErrorHandler.php';
ErrorHandler::register();

require_once __DIR__ . '/../../includes/Database.php';
require_once __DIR__ . '/../../includes/License.php';
require_once __DIR__ . '/../../includes/RsaSigner.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('X-Frame-Options: DENY');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(200);
    exit;
}

function json_input(): array
{
    $contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($contentLength > 16384) {
        json_response(['ok' => false, 'error' => 'Request body is too large.'], 413);
    }

    $raw = file_get_contents('php://input', false, null, 0, 16385);
    if ($raw === false || strlen($raw) > 16384) {
        json_response(['ok' => false, 'error' => 'Request body is too large.'], 413);
    }

    if (trim($raw) !== '') {
        try {
            $data = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
            if (is_array($data)) {
                return $data;
            }
        } catch (JsonException $e) {
            // Fall through to $_POST fallback if present
        }
    }

    if (!empty($_POST) && is_array($_POST)) {
        return $_POST;
    }

    return [];
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
