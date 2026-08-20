<?php
require_once __DIR__ . '/../includes/Database.php';
require_once __DIR__ . '/../includes/SessionManager.php';

$failures = [];
function sm_check(string $label, bool $condition): void
{
    global $failures;
    echo ($condition ? '[PASS] ' : '[FAIL] ') . $label . "\n";
    if (!$condition) $failures[] = $label;
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec(file_get_contents(__DIR__ . '/../db/schema.sqlite.test.sql'));
$pdo->exec(
    'CREATE TABLE user_sessions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        admin_id INTEGER NOT NULL,
        selector TEXT NOT NULL UNIQUE,
        validator_hash TEXT NOT NULL,
        user_agent TEXT NULL,
        ip_address TEXT NULL,
        expires_at TEXT NOT NULL,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )'
);
Database::setTestInstance($pdo);

$pdo->prepare('INSERT INTO admin_users (username,password_hash,role) VALUES (?,?,?)')->execute(['owner', 'x', 'owner']);
$ownerId = (int) $pdo->lastInsertId();
$pdo->prepare('INSERT INTO admin_users (username,password_hash,role) VALUES (?,?,?)')->execute(['support', 'x', 'support']);
$supportId = (int) $pdo->lastInsertId();

$insert = $pdo->prepare('INSERT INTO user_sessions (admin_id,selector,validator_hash,user_agent,ip_address,expires_at) VALUES (?,?,?,?,?,?)');
$insert->execute([$ownerId, 'owner-1', 'h1', 'UA1', '1.1.1.1', date('Y-m-d H:i:s', time()+3600)]);
$ownerSessionId = (int) $pdo->lastInsertId();
$insert->execute([$supportId, 'support-1', 'h2', 'UA2', '2.2.2.2', date('Y-m-d H:i:s', time()+3600)]);
$supportSessionId = (int) $pdo->lastInsertId();
$insert->execute([$supportId, 'support-2', 'h3', 'UA3', '2.2.2.3', date('Y-m-d H:i:s', time()+7200)]);

sm_check('Owner sees sessions for all administrators', count(SessionManager::visibleFor($ownerId, true)) === 3);
sm_check('Support sees only own remembered sessions', count(SessionManager::visibleFor($supportId, false)) === 2);
sm_check('Support cannot revoke another admin session', SessionManager::revokeOne($ownerSessionId, $supportId, false) === 0);
sm_check('Owner session remains after unauthorized revoke attempt', (int)$pdo->query("SELECT COUNT(*) FROM user_sessions WHERE id={$ownerSessionId}")->fetchColumn() === 1);
sm_check('Support can revoke own session', SessionManager::revokeOne($supportSessionId, $supportId, false) === 1);
sm_check('Revoke own clears remaining own sessions', SessionManager::revokeOwn($supportId) === 1);
sm_check('Owner can revoke any single remembered session', SessionManager::revokeOne($ownerSessionId, $ownerId, true) === 1);

$insert->execute([$ownerId, 'owner-2', 'h4', 'UA4', '1.1.1.2', date('Y-m-d H:i:s', time()+3600)]);
$insert->execute([$supportId, 'support-3', 'h5', 'UA5', '2.2.2.4', date('Y-m-d H:i:s', time()+3600)]);
sm_check('Owner revoke-all removes every remembered session', SessionManager::revokeAll() === 2);
sm_check('No remembered sessions remain', (int)$pdo->query('SELECT COUNT(*) FROM user_sessions')->fetchColumn() === 0);

if ($failures) exit(1);
echo "SESSION MANAGER TESTS PASSED\n";
