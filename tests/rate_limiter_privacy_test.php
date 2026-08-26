<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/Database.php';
require_once __DIR__ . '/../includes/RateLimiter.php';

$failures = [];
$check = static function (string $label, bool $condition) use (&$failures): void {
    echo ($condition ? '[PASS] ' : '[FAIL] ') . $label . "\n";
    if (!$condition) $failures[] = $label;
};

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE api_requests (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    ip_address VARCHAR(45) NOT NULL,
    endpoint VARCHAR(30) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
)');
Database::setTestInstance($pdo);

$licenseBucket = 'key:HRC-ABCD-EFGH-JKLM-NPQR-STUV';
RateLimiter::record($licenseBucket, 'validate_by_key');
RateLimiter::record('203.0.113.9', 'validate');
RateLimiter::record($licenseBucket, str_repeat('very-long-endpoint-', 4));

$rows = $pdo->query('SELECT ip_address, endpoint FROM api_requests ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
$synthetic = (string)($rows[0]['ip_address'] ?? '');
$literalIp = (string)($rows[1]['ip_address'] ?? '');
$longEndpoint = (string)($rows[2]['endpoint'] ?? '');

$check('Synthetic license bucket is hashed at rest', str_starts_with($synthetic, 'h:') && strlen($synthetic) <= 45);
$check('Plain license key is never persisted in rate-limit storage', !str_contains($synthetic, 'HRC-') && !str_contains(json_encode($rows) ?: '', 'HRC-'));
$check('Literal IP remains available for abuse diagnostics', $literalIp === '203.0.113.9');
$check('Oversized endpoint names are bounded and hashed', str_starts_with($longEndpoint, 'h:') && strlen($longEndpoint) <= 30);
$check('Hashed synthetic bucket remains deterministic for enforcement', !RateLimiter::isAllowed($licenseBucket, 'validate_by_key', 1, 5));

if ($failures) exit(1);
echo "RATE LIMITER PRIVACY TESTS PASSED\n";
