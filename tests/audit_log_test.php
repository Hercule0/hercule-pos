<?php
date_default_timezone_set('UTC');
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';

require_once __DIR__ . '/../includes/Database.php';
require_once __DIR__ . '/../includes/AuditLog.php';

$failures = [];
function audit_check(string $label, bool $condition): void
{
    global $failures;
    echo ($condition ? '[PASS] ' : '[FAIL] ') . $label . "\n";
    if (!$condition) $failures[] = $label;
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec(file_get_contents(__DIR__ . '/../db/schema.sqlite.test.sql'));
Database::setTestInstance($pdo);

$pdo->prepare('INSERT INTO admin_users (username, password_hash, role) VALUES (?, ?, ?)')
    ->execute(['audit-admin', password_hash('temporary-password', PASSWORD_DEFAULT), 'owner']);
$adminId = (int) $pdo->lastInsertId();

AuditLog::write('login_success', $adminId, null, 'signed in', '10.1.2.3');
$row = $pdo->query('SELECT * FROM admin_audit_log ORDER BY id DESC LIMIT 1')->fetch();
audit_check('Audit event is persisted', is_array($row));
audit_check('Audit action is stored', ($row['action'] ?? null) === 'login_success');
audit_check('Audit actor id is stored', (int)($row['actor_id'] ?? 0) === $adminId);
audit_check('Audit IP address is stored', ($row['ip_address'] ?? null) === '10.1.2.3');
audit_check('Audit details are stored', ($row['details'] ?? null) === 'signed in');

AuditLog::write('   ', $adminId);
$count = (int) $pdo->query('SELECT COUNT(*) FROM admin_audit_log')->fetchColumn();
audit_check('Blank audit action is ignored', $count === 1);

$longAction = str_repeat('A', 80);
$longDetails = str_repeat('D', 400);
AuditLog::write($longAction, $adminId, 99, $longDetails, '10.9.8.7');
$row = $pdo->query('SELECT * FROM admin_audit_log ORDER BY id DESC LIMIT 1')->fetch();
audit_check('Audit action is capped at 40 characters', strlen((string)$row['action']) === 40);
audit_check('Audit details are capped at 255 characters', strlen((string)$row['details']) === 255);
audit_check('Audit target id is stored', (int)($row['target_id'] ?? 0) === 99);

$_SERVER['REMOTE_ADDR'] = '192.0.2.10';
AuditLog::write('implicit_ip', $adminId);
$row = $pdo->query('SELECT * FROM admin_audit_log ORDER BY id DESC LIMIT 1')->fetch();
audit_check('Audit writer falls back to request IP', ($row['ip_address'] ?? null) === '192.0.2.10');

if ($failures) {
    echo "\n" . count($failures) . " TEST(S) FAILED\n";
    exit(1);
}

echo "\nAUDIT LOG TESTS PASSED\n";
