<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/../../includes/RsaSigner.php';
require_once __DIR__ . '/../../includes/UpdateSigner.php';
require_once __DIR__ . '/../../includes/ReleaseStorage.php';

Auth::require();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$started = microtime(true);
$checks = [
    'database' => ['ok' => false, 'label' => 'Unavailable'],
    'license_signer' => ['ok' => false, 'label' => 'Unavailable'],
    'update_signer' => ['ok' => false, 'label' => 'Unavailable'],
    'rate_limiter' => ['ok' => false, 'label' => 'Unavailable'],
    'release_storage' => ['ok' => false, 'label' => 'Unavailable'],
    'web_push' => ['ok' => false, 'label' => 'Not configured'],
];

try {
    $t = microtime(true);
    Database::pdo()->query('SELECT 1')->fetchColumn();
    $checks['database'] = [
        'ok' => true,
        'label' => 'Connected',
        'latency_ms' => round((microtime(true) - $t) * 1000, 1),
    ];
} catch (Throwable $e) {
    ErrorHandler::report($e, 'admin_health_database');
}

try {
    RsaSigner::sign(['status' => 'health', 'server_time' => gmdate('Y-m-d\TH:i:s\Z')]);
    $checks['license_signer'] = ['ok' => true, 'label' => 'Ready'];
} catch (Throwable $e) {
    $checks['license_signer']['label'] = 'Key unavailable';
}

try {
    UpdateSigner::sign([
        'release_id' => 1,
        'version' => '0.0.0-health',
        'channel' => 'stable',
        'minimum_supported_version' => null,
        'mandatory' => false,
        'below_minimum_supported' => false,
        'installer_file' => 'health.exe',
        'installer_size' => 1,
        'installer_sha256' => str_repeat('0', 64),
        'installer_sha512' => str_repeat('0', 128),
        'published_at' => null,
    ]);
    $checks['update_signer'] = ['ok' => true, 'label' => 'Ready'];
} catch (Throwable $e) {
    $checks['update_signer']['label'] = 'Key unavailable';
}

try {
    Database::pdo()->query('SELECT COUNT(*) FROM api_requests')->fetchColumn();
    $checks['rate_limiter'] = ['ok' => true, 'label' => 'Active'];
} catch (Throwable $e) {
    $checks['rate_limiter']['label'] = 'Storage unavailable';
}

try {
    $path = ReleaseStorage::ensureWritable();
    $checks['release_storage'] = ['ok' => is_writable($path), 'label' => is_writable($path) ? 'Writable' : 'Read only'];
} catch (Throwable $e) {
    $checks['release_storage']['label'] = 'Unavailable';
}

$config = require __DIR__ . '/../../config/config.php';
$vapid = $config['vapid'] ?? [];
if (trim((string)($vapid['subject'] ?? '')) !== ''
    && trim((string)($vapid['public_key'] ?? '')) !== ''
    && trim((string)($vapid['private_key'] ?? '')) !== '') {
    $checks['web_push'] = ['ok' => true, 'label' => 'Configured'];
}

$critical = ['database', 'license_signer', 'update_signer', 'rate_limiter', 'release_storage'];
$ok = true;
foreach ($critical as $name) {
    if (empty($checks[$name]['ok'])) {
        $ok = false;
        break;
    }
}

echo json_encode([
    'ok' => $ok,
    'checks' => $checks,
    'server_time' => gmdate('Y-m-d\TH:i:s\Z'),
    'response_ms' => round((microtime(true) - $started) * 1000, 1),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
