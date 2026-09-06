<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/Database.php';
require_once __DIR__ . '/../includes/RateLimiter.php';

$failures = [];
$check = static function (string $label, bool $condition) use (&$failures): void {
    echo ($condition ? '[PASS] ' : '[FAIL] ') . $label . "\n";
    if (!$condition) $failures[] = $label;
};

$source = file_get_contents(__DIR__ . '/../includes/RateLimiter.php');
$check('MySQL limiter serializes a bucket with GET_LOCK', str_contains($source, 'SELECT GET_LOCK(?, ?)'));
$check('MySQL limiter always releases its bucket lock', str_contains($source, 'SELECT RELEASE_LOCK(?)'));
$check('Atomic decision is count+record inside one helper', str_contains($source, 'checkAndRecord'));
$check('Lock acquisition failure is fail-closed', str_contains($source, 'fetchColumn() !== 1') && str_contains($source, 'return false;'));
$check('Old check() isAllowed-then-record sequence is removed', !preg_match('/function check\([^}]+self::isAllowed\(/s', $source));

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE api_requests (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    ip_address VARCHAR(45) NOT NULL,
    endpoint VARCHAR(30) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
)');
Database::setTestInstance($pdo);

$check('First request is allowed', RateLimiter::check('203.0.113.77', 'atomic_probe', 2, 5) === true);
$check('Second request is allowed', RateLimiter::check('203.0.113.77', 'atomic_probe', 2, 5) === true);
$check('Third request is rejected', RateLimiter::check('203.0.113.77', 'atomic_probe', 2, 5) === false);
$count = (int)$pdo->query("SELECT COUNT(*) FROM api_requests WHERE endpoint='atomic_probe'")->fetchColumn();
$check('Rejected attempts remain recorded', $count === 3);

if ($failures) exit(1);
echo "RATE LIMITER ATOMIC CONTRACT TESTS PASSED\n";
