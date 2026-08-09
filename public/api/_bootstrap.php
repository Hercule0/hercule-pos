<?php
/**
 * Shared bootstrap for public API endpoints (not the admin panel — see
 * admin/includes/bootstrap.php for that, which additionally starts sessions
 * and enforces auth).
 */

require_once __DIR__ . '/../../includes/Database.php';
require_once __DIR__ . '/../../includes/License.php';
require_once __DIR__ . '/../../includes/RsaSigner.php';

header('Content-Type: application/json');

function json_input(): array
{
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
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
