<?php
require_once __DIR__ . '/../includes/Database.php';
require_once __DIR__ . '/../includes/Auth.php';

function live_auth_check(string $label, bool $condition): void
{
    if (!$condition) {
        fwrite(STDERR, "[FAIL] {$label}\n");
        exit(1);
    }
    echo "[PASS] {$label}\n";
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE admin_users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT NOT NULL UNIQUE,
    password_hash TEXT NOT NULL,
    role TEXT NOT NULL DEFAULT "read_only",
    is_active INTEGER NOT NULL DEFAULT 1,
    must_change_password INTEGER NOT NULL DEFAULT 0,
    totp_enabled INTEGER NOT NULL DEFAULT 0,
    totp_secret TEXT NULL,
    recovery_codes TEXT NULL
)');
$pdo->exec('CREATE TABLE user_sessions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    admin_id INTEGER NOT NULL,
    selector TEXT NOT NULL UNIQUE,
    validator_hash TEXT NOT NULL,
    user_agent TEXT NOT NULL,
    ip_address TEXT NOT NULL,
    expires_at TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
)');
$pdo->exec('CREATE TABLE login_attempts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT NOT NULL,
    ip_address TEXT NOT NULL,
    success INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
)');
$pdo->exec('CREATE TABLE admin_permission_overrides (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    admin_id INTEGER NOT NULL,
    permission TEXT NOT NULL,
    allowed INTEGER NOT NULL DEFAULT 1,
    updated_by INTEGER NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(admin_id, permission)
)');
Database::setTestInstance($pdo);

$password = 'Strong!Pass123';
$pdo->prepare('INSERT INTO admin_users (username,password_hash,role,is_active,must_change_password) VALUES (?,?,?,?,?)')
    ->execute(['supporter', password_hash($password, PASSWORD_DEFAULT), 'support', 1, 0]);
$adminId = (int)$pdo->lastInsertId();

Auth::startSession();
$_SESSION['admin_id'] = $adminId;
$_SESSION['admin_username'] = 'supporter';
$_SESSION['admin_role'] = 'support';
$_SESSION['must_change_password'] = false;
$_SESSION['last_activity'] = time();

live_auth_check('Active live session remains authenticated', Auth::isLoggedIn());

$pdo->prepare('UPDATE admin_users SET role = ? WHERE id = ?')->execute(['read_only', $adminId]);
live_auth_check('Role changes are reflected in the live session', Auth::isLoggedIn() && Auth::currentRole() === 'read_only');

$pdo->prepare('UPDATE admin_users SET must_change_password = 1 WHERE id = ?')->execute([$adminId]);
live_auth_check('Forced password-change flag refreshes in live session', Auth::isLoggedIn() && !empty($_SESSION['must_change_password']));

$pdo->prepare('UPDATE admin_users SET is_active = 0 WHERE id = ?')->execute([$adminId]);
live_auth_check('Disabled administrator live session is rejected immediately', !Auth::isLoggedIn());

echo "AUTH LIVE STATE TESTS PASSED\n";
