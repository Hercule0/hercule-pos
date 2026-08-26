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
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');

if ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')) {
    header('Strict-Transport-Security: max-age=31536000');
}

// Desktop Electron requests do not need browser CORS. Cross-origin browser
// access is opt-in through HERCULE_API_CORS_ORIGINS (comma-separated exact
// origins). Never fall back to "*" for licensing/recovery/update endpoints.
$origin = trim((string)($_SERVER['HTTP_ORIGIN'] ?? ''));
$allowedRaw = $_ENV['HERCULE_API_CORS_ORIGINS'] ?? $_SERVER['HERCULE_API_CORS_ORIGINS'] ?? getenv('HERCULE_API_CORS_ORIGINS') ?: '';
$allowedOrigins = array_values(array_filter(array_map('trim', explode(',', (string)$allowedRaw))));
if ($origin !== '' && in_array($origin, $allowedOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-Request-ID');
    header('Access-Control-Max-Age: 600');
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    if ($origin !== '' && !in_array($origin, $allowedOrigins, true)) {
        http_response_code(403);
        exit;
    }
    http_response_code(204);
    exit;
}

function json_input(int $maxBytes = 16384): array
{
    // Keep the normal licensing/recovery surface small while allowing selected
    // endpoints such as ai_agent.php to opt into a larger but still bounded
    // JSON envelope for local read-only tool results.
    $maxBytes = max(1024, min(262144, $maxBytes));
    $contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($contentLength > $maxBytes) {
        json_response(['ok' => false, 'error' => 'Request body is too large.'], 413);
    }

    $raw = file_get_contents('php://input', false, null, 0, $maxBytes + 1);
    if ($raw === false || strlen($raw) > $maxBytes) {
        json_response(['ok' => false, 'error' => 'Request body is too large.'], 413);
    }

    if (trim($raw) !== '') {
        try {
            $data = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
            if (is_array($data)) {
                return $data;
            }
        } catch (JsonException $e) {
            // Fall through to $_POST fallback if present.
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
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function client_ip(): string
{
    // Azure/PHP exposes the actual connection peer here. Do not trust arbitrary
    // X-Forwarded-For values supplied by clients for security buckets.
    return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
}
