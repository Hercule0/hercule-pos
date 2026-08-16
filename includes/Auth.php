<?php
/**
 * Admin authentication: session-based login, is_admin() guard for every
 * protected page, and a DB-backed rate limiter on login attempts (the same
 * class of bug that was fixed in Ur Library's login flow — handled up
 * front here instead).
 */

require_once __DIR__ . '/Database.php';

final class Auth
{
    private static function config(): array
    {
        static $config = null;
        if ($config === null) {
            $config = require __DIR__ . '/../config/config.php';
        }
        return $config;
    }

    public static function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE || headers_sent()) {
            return;
        }

        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        session_name('hercule_admin');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => $isHttps,
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        session_start();
    }

    private static function ensureSession(): void
    {
        self::startSession();
    }

    public static function isLoggedIn(): bool
    {
        self::ensureSession();
        if (empty($_SESSION['admin_id'])) {
            return false;
        }

        $lifetime = (int) self::config()['security']['session_lifetime_minutes'] * 60;
        $lastActivity = (int) ($_SESSION['last_activity'] ?? 0);
        if ($lastActivity > 0 && (time() - $lastActivity) > $lifetime) {
            self::logout();
            return false;
        }

        $_SESSION['last_activity'] = time();
        return true;
    }

    /** Call at the top of every admin page. Redirects to login if not authenticated. */
    public static function require(): void
    {
        self::ensureSession();
        if (!self::isLoggedIn()) {
            header('Location: /public/admin/login.php');
            exit;
        }
    }

    /**
     * Returns true/false for whether this username+IP is currently allowed
     * to attempt a login, based on recent failures.
     */
    public static function isRateLimited(string $username, string $ip): bool
    {
        $cfg = self::config()['security'];
        $pdo = Database::pdo();

        $threshold = (new DateTime())
            ->modify("-{$cfg['login_window_minutes']} minutes")
            ->format('Y-m-d H:i:s');

        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM login_attempts
             WHERE username = ? AND ip_address = ? AND success = 0
               AND created_at > ?'
        );
        $stmt->execute([$username, $ip, $threshold]);

        return (int) $stmt->fetchColumn() >= $cfg['login_max_attempts'];
    }

    private static function recordAttempt(string $username, string $ip, bool $success): void
    {
        $stmt = Database::pdo()->prepare(
            'INSERT INTO login_attempts (username, ip_address, success) VALUES (?, ?, ?)'
        );
        $stmt->execute([$username, $ip, $success ? 1 : 0]);

        if (random_int(1, 100) === 1) {
            $threshold = (new DateTime())->modify('-30 days')->format('Y-m-d H:i:s');
            $cleanup = Database::pdo()->prepare(
                'DELETE FROM login_attempts WHERE created_at < ? ORDER BY id LIMIT 1000'
            );
            $cleanup->execute([$threshold]);
        }
    }

    /**
     * @return array{ok: bool, error?: string}
     */
    public static function attemptLogin(string $username, string $password, string $ip): array
    {
        self::ensureSession();

        if (self::isRateLimited($username, $ip)) {
            $cfg = self::config()['security'];
            return ['ok' => false, 'error' => "Too many failed attempts. Try again in {$cfg['login_window_minutes']} minutes."];
        }

        $stmt = Database::pdo()->prepare('SELECT id, password_hash FROM admin_users WHERE username = ?');
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        // Always run password_verify even on a missing user, against a fixed
        // dummy hash, so response timing doesn't reveal whether the
        // username exists (timing-safe login, same class of fix as
        // Ur Library's timing-safe token comparison).
        $hashToCheck = $user['password_hash'] ?? '$2y$10$abcdefghijklmnopqrstuuVGA5G8B1t2b8lFVOoW8n8W8n8W8n8W8n';
        $passwordOk = password_verify($password, $hashToCheck);

        if (!$user || !$passwordOk) {
            self::recordAttempt($username, $ip, false);
            return ['ok' => false, 'error' => 'Invalid username or password.'];
        }

        self::recordAttempt($username, $ip, true);
        
        // تم التعديل لمنع خطأ الـ Session ID في بيئة الـ CLI
        if (session_status() === PHP_SESSION_ACTIVE && !headers_sent()) {
            session_regenerate_id(true); // prevent session fixation
        }
        
        unset($_SESSION['csrf_token']);
        $_SESSION['admin_id'] = $user['id'];
        $_SESSION['admin_username'] = $username;
        $_SESSION['last_activity'] = time();
        $_SESSION['logged_in_at'] = time();

        return ['ok' => true];
    }

    public static function logout(): void
    {
        self::ensureSession();
        $_SESSION = [];
        
        if (session_status() === PHP_SESSION_ACTIVE) {
            $params = session_get_cookie_params();
            if (!headers_sent()) {
                setcookie(session_name(), '', [
                    'expires' => time() - 42000,
                    'path' => $params['path'] ?: '/',
                    'domain' => $params['domain'] ?? '',
                    'secure' => (bool) ($params['secure'] ?? false),
                    'httponly' => true,
                    'samesite' => 'Strict',
                ]);
            }
            session_destroy();
        }
    }

    public static function currentUsername(): ?string
    {
        self::ensureSession();
        return $_SESSION['admin_username'] ?? null;
    }

    /**
     * @return array{ok: bool, error?: string}
     */
    public static function changePassword(int $adminId, string $currentPassword, string $newPassword): array
    {
        if (strlen($newPassword) < 10) {
            return ['ok' => false, 'error' => 'New password must be at least 10 characters.'];
        }

        $stmt = Database::pdo()->prepare('SELECT password_hash FROM admin_users WHERE id = ?');
        $stmt->execute([$adminId]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($currentPassword, $user['password_hash'])) {
            return ['ok' => false, 'error' => 'Current password is incorrect.'];
        }

        $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
        $update = Database::pdo()->prepare('UPDATE admin_users SET password_hash = ? WHERE id = ?');
        $update->execute([$newHash, $adminId]);

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
            unset($_SESSION['csrf_token']);
            $_SESSION['last_activity'] = time();
        }

        return ['ok' => true];
    }
}
