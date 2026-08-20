<?php
/**
 * Admin authentication: session-based login, is_admin() guard for every
 * protected page, and a DB-backed rate limiter on login attempts.
 */

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Totp.php';
require_once __DIR__ . '/AuthPermissionBridge.php';
require_once __DIR__ . '/PasswordPolicy.php';

final class Auth
{
    private const ROLES = ['owner', 'support', 'read_only'];

    private const ROLE_PERMISSIONS = [
        'owner' => ['*'],
        'support' => ['licenses.manage', 'recovery.review', 'exports.download'],
        'read_only' => [],
    ];

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
            'samesite' => 'Lax',
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
            return self::attemptRememberMeLogin();
        }

        $lifetime = (int) self::config()['security']['session_lifetime_minutes'] * 60;
        $lastActivity = (int) ($_SESSION['last_activity'] ?? 0);
        if ($lastActivity > 0 && (time() - $lastActivity) > $lifetime) {
            self::logout();
            return false;
        }

        // Revalidate the account on every protected request. This makes account
        // disable/delete and role changes effective immediately for live PHP
        // sessions instead of waiting for the inactivity timeout.
        $stmt = Database::pdo()->prepare(
            'SELECT username, role, is_active, must_change_password FROM admin_users WHERE id = ?'
        );
        $stmt->execute([(int) $_SESSION['admin_id']]);
        $user = $stmt->fetch();
        if (!$user || empty($user['is_active'])) {
            self::logout();
            return false;
        }

        $role = in_array($user['role'] ?? '', self::ROLES, true) ? $user['role'] : 'read_only';
        $_SESSION['admin_username'] = $user['username'];
        $_SESSION['admin_role'] = $role;
        $_SESSION['must_change_password'] = !empty($user['must_change_password']);
        $_SESSION['last_activity'] = time();
        return true;
    }

    private static function attemptRememberMeLogin(): bool
    {
        $cookie = $_COOKIE['hercule_remember'] ?? '';
        if (!$cookie || strpos($cookie, ':') === false) {
            return false;
        }

        [$selector, $validator] = explode(':', $cookie, 2);

        $stmt = Database::pdo()->prepare(
            'SELECT us.id, us.admin_id, us.validator_hash, us.user_agent, us.ip_address, us.expires_at,
                    a.username, a.role, a.is_active, a.must_change_password
             FROM user_sessions us
             JOIN admin_users a ON us.admin_id = a.id
             WHERE us.selector = ?'
        );
        $stmt->execute([$selector]);
        $session = $stmt->fetch();

        if (!$session) {
            self::clearRememberCookie();
            return false;
        }

        if (!hash_equals($session['validator_hash'], hash('sha256', $validator))) {
            self::clearRememberCookie();
            return false;
        }

        if (strtotime($session['expires_at']) < time()) {
            self::clearRememberCookie();
            return false;
        }

        if (empty($session['is_active'])) {
            self::clearRememberCookie();
            return false;
        }

        self::finishLogin((int) $session['admin_id'], $session['username'], $session['role'], !empty($session['must_change_password']), false);
        return true;
    }

    public static function require(): void
    {
        self::ensureSession();
        if (!self::isLoggedIn()) {
            header('Location: /public/admin/login.php');
            exit;
        }

        $page = basename($_SERVER['PHP_SELF'] ?? '');
        if (!empty($_SESSION['must_change_password']) && !in_array($page, ['change_password.php', 'logout.php'], true)) {
            header('Location: /public/admin/change_password.php?required=1');
            exit;
        }
    }

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

        if (Database::pdo()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' && random_int(1, 100) === 1) {
            $threshold = (new DateTime())->modify('-30 days')->format('Y-m-d H:i:s');
            $cleanup = Database::pdo()->prepare(
                'DELETE FROM login_attempts WHERE created_at < ? ORDER BY id LIMIT 1000'
            );
            $cleanup->execute([$threshold]);
        }
    }

    public static function attemptLogin(string $username, string $password, string $ip, bool $remember = false): array
    {
        self::ensureSession();

        if (self::isRateLimited($username, $ip)) {
            $cfg = self::config()['security'];
            return ['ok' => false, 'error' => "Too many failed attempts. Try again in {$cfg['login_window_minutes']} minutes."];
        }

        try {
            $stmt = Database::pdo()->prepare('SELECT id, password_hash, role, is_active, must_change_password, totp_enabled, totp_secret, recovery_codes FROM admin_users WHERE username = ?');
            $stmt->execute([$username]);
            $user = $stmt->fetch();
        } catch (PDOException $e) {
            error_log('admin_users.role is not migrated yet: ' . $e->getMessage());
            $stmt = Database::pdo()->prepare('SELECT id, password_hash FROM admin_users WHERE username = ?');
            $stmt->execute([$username]);
            $user = $stmt->fetch();
            if ($user) {
                $user['role'] = 'owner';
                $user['is_active'] = 1;
                $user['must_change_password'] = 0;
            }
        }

        $hashToCheck = $user['password_hash'] ?? '$2y$10$abcdefghijklmnopqrstuuVGA5G8B1t2b8lFVOoW8n8W8n8W8n8W8n';
        $passwordOk = password_verify($password, $hashToCheck);

        if (!$user || !$passwordOk || empty($user['is_active'])) {
            self::recordAttempt($username, $ip, false);
            return ['ok' => false, 'error' => 'Invalid username or password.'];
        }

        self::recordAttempt($username, $ip, true);

        $role = $user['role'] ?? 'read_only';
        $role = in_array($role, self::ROLES, true) ? $role : 'read_only';

        if (!empty($user['totp_enabled'])) {
            unset($_SESSION['admin_id'], $_SESSION['admin_username'], $_SESSION['admin_role']);
            $_SESSION['mfa_pending'] = [
                'admin_id' => (int) $user['id'],
                'username' => $username,
                'role' => $role,
                'must_change_password' => !empty($user['must_change_password']),
                'expires_at' => time() + 300,
                'failures' => 0,
                'remember' => $remember,
            ];
            unset($_SESSION['csrf_token']);
            return ['ok' => false, 'requires_mfa' => true];
        }

        self::finishLogin((int) $user['id'], $username, $role, !empty($user['must_change_password']), $remember);
        return ['ok' => true];
    }

    private static function finishLogin(int $adminId, string $username, string $role, bool $mustChangePassword = false, bool $remember = false): void
    {
        if (session_status() === PHP_SESSION_ACTIVE && !headers_sent()) {
            session_regenerate_id(true);
        }
        unset($_SESSION['csrf_token'], $_SESSION['mfa_pending'], $_SESSION['mfa_setup_secret']);
        $_SESSION['admin_id'] = $adminId;
        $_SESSION['admin_username'] = $username;
        $_SESSION['admin_role'] = in_array($role, self::ROLES, true) ? $role : 'read_only';
        $_SESSION['must_change_password'] = $mustChangePassword;
        $_SESSION['last_activity'] = time();
        $_SESSION['logged_in_at'] = time();

        if ($remember) {
            $selector = bin2hex(random_bytes(12));
            $validator = bin2hex(random_bytes(32));
            $hashedValidator = hash('sha256', $validator);
            $expiresAt = time() + (86400 * 30);

            $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
            $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';

            $stmt = Database::pdo()->prepare(
                'INSERT INTO user_sessions (admin_id, selector, validator_hash, user_agent, ip_address, expires_at)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([$adminId, $selector, $hashedValidator, $ua, $ip, date('Y-m-d H:i:s', $expiresAt)]);

            $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

            setcookie('hercule_remember', $selector . ':' . $validator, [
                'expires' => $expiresAt,
                'path' => '/',
                'secure' => $isHttps,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }
    }

    public static function verifySecondFactor(string $code): array
    {
        self::ensureSession();
        $pending = $_SESSION['mfa_pending'] ?? null;
        if (!is_array($pending) || (int) ($pending['expires_at'] ?? 0) < time()) {
            unset($_SESSION['mfa_pending']);
            return ['ok' => false, 'error' => 'Your verification session expired. Sign in again.'];
        }
        if ((int) ($pending['failures'] ?? 0) >= 5) {
            unset($_SESSION['mfa_pending']);
            return ['ok' => false, 'error' => 'Too many invalid codes. Sign in again.'];
        }

        $stmt = Database::pdo()->prepare(
            'SELECT is_active, totp_enabled, totp_secret, recovery_codes FROM admin_users WHERE id = ?'
        );
        $stmt->execute([(int) $pending['admin_id']]);
        $user = $stmt->fetch();
        if (!$user || empty($user['is_active']) || empty($user['totp_enabled']) || empty($user['totp_secret'])) {
            unset($_SESSION['mfa_pending']);
            return ['ok' => false, 'error' => 'Two-factor authentication is unavailable.'];
        }

        $valid = false;
        try {
            $valid = Totp::verify(Totp::decrypt($user['totp_secret']), $code);
        } catch (Throwable $e) {
            error_log('MFA verification failed: ' . $e->getMessage());
        }

        $normalized = strtoupper(preg_replace('/[^A-Z0-9]/i', '', $code) ?? '');
        $recovery = json_decode($user['recovery_codes'] ?? '[]', true);
        if (!$valid && strlen($normalized) === 10 && is_array($recovery)) {
            foreach ($recovery as $index => $hash) {
                if (password_verify($normalized, $hash)) {
                    $valid = true;
                    unset($recovery[$index]);
                    $update = Database::pdo()->prepare('UPDATE admin_users SET recovery_codes = ? WHERE id = ?');
                    $update->execute([json_encode(array_values($recovery)), (int) $pending['admin_id']]);
                    break;
                }
            }
        }

        if (!$valid) {
            $_SESSION['mfa_pending']['failures'] = (int) ($pending['failures'] ?? 0) + 1;
            return ['ok' => false, 'error' => 'Invalid authentication or recovery code.'];
        }

        $remember = !empty($pending['remember']);
        self::finishLogin((int) $pending['admin_id'], $pending['username'], $pending['role'], !empty($pending['must_change_password']), $remember);
        return ['ok' => true];
    }

    public static function beginMfaSetup(): array
    {
        self::require();
        $secret = Totp::generateSecret();
        $_SESSION['mfa_setup_secret'] = ['secret' => $secret, 'expires_at' => time() + 600];
        return [
            'secret' => $secret,
            'uri' => Totp::provisioningUri($secret, self::currentUsername() ?? 'admin'),
        ];
    }

    public static function enableMfa(string $currentPassword, string $code): array
    {
        self::require();
        $setup = $_SESSION['mfa_setup_secret'] ?? null;
        if (!is_array($setup) || (int) ($setup['expires_at'] ?? 0) < time()) {
            return ['ok' => false, 'error' => 'Setup expired. Generate a new secret.'];
        }
        if (!self::verifyCurrentPassword((int) $_SESSION['admin_id'], $currentPassword)) {
            return ['ok' => false, 'error' => 'Current password is incorrect.'];
        }
        if (!Totp::verify($setup['secret'], $code)) {
            return ['ok' => false, 'error' => 'The six-digit code is invalid.'];
        }

        $codes = [];
        $hashes = [];
        for ($i = 0; $i < 8; $i++) {
            $raw = strtoupper(bin2hex(random_bytes(5)));
            $codes[] = substr($raw, 0, 5) . '-' . substr($raw, 5);
            $hashes[] = password_hash($raw, PASSWORD_DEFAULT);
        }

        try {
            $encrypted = Totp::encrypt($setup['secret']);
        } catch (Throwable $e) {
            error_log('MFA setup failed: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'MFA encryption is not configured on the server.'];
        }

        $stmt = Database::pdo()->prepare(
            'UPDATE admin_users SET totp_enabled = 1, totp_secret = ?, recovery_codes = ? WHERE id = ?'
        );
        $stmt->execute([$encrypted, json_encode($hashes), (int) $_SESSION['admin_id']]);
        unset($_SESSION['mfa_setup_secret']);
        return ['ok' => true, 'recovery_codes' => $codes];
    }

    public static function disableMfa(string $currentPassword, string $code): array
    {
        self::require();
        if (!self::verifyCurrentPassword((int) $_SESSION['admin_id'], $currentPassword)) {
            return ['ok' => false, 'error' => 'Current password is incorrect.'];
        }
        $stmt = Database::pdo()->prepare('SELECT totp_secret FROM admin_users WHERE id = ? AND totp_enabled = 1');
        $stmt->execute([(int) $_SESSION['admin_id']]);
        $encrypted = $stmt->fetchColumn();
        if (!$encrypted) {
            return ['ok' => false, 'error' => 'Two-factor authentication is not enabled.'];
        }
        try {
            $valid = Totp::verify(Totp::decrypt($encrypted), $code);
        } catch (Throwable $e) {
            $valid = false;
        }
        if (!$valid) {
            return ['ok' => false, 'error' => 'A valid authenticator code is required.'];
        }
        $update = Database::pdo()->prepare(
            'UPDATE admin_users SET totp_enabled = 0, totp_secret = NULL, recovery_codes = NULL WHERE id = ?'
        );
        $update->execute([(int) $_SESSION['admin_id']]);
        return ['ok' => true];
    }

    public static function mfaEnabled(): bool
    {
        self::require();
        $stmt = Database::pdo()->prepare('SELECT totp_enabled FROM admin_users WHERE id = ?');
        $stmt->execute([(int) $_SESSION['admin_id']]);
        return (bool) $stmt->fetchColumn();
    }

    public static function confirmCurrentPassword(string $password): bool
    {
        self::require();
        return self::verifyCurrentPassword((int) $_SESSION['admin_id'], $password);
    }

    private static function verifyCurrentPassword(int $adminId, string $password): bool
    {
        $stmt = Database::pdo()->prepare('SELECT password_hash FROM admin_users WHERE id = ?');
        $stmt->execute([$adminId]);
        $hash = $stmt->fetchColumn();
        return is_string($hash) && password_verify($password, $hash);
    }

    public static function logout(): void
    {
        self::ensureSession();
        self::clearRememberCookie();
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
                    'samesite' => 'Lax',
                ]);
            }
            session_destroy();
        }
    }

    private static function clearRememberCookie(): void
    {
        $cookie = $_COOKIE['hercule_remember'] ?? '';
        if ($cookie && strpos($cookie, ':') !== false) {
            [$selector, ] = explode(':', $cookie, 2);
            $stmt = Database::pdo()->prepare('DELETE FROM user_sessions WHERE selector = ?');
            $stmt->execute([$selector]);
        }

        if (!headers_sent()) {
            $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
            setcookie('hercule_remember', '', [
                'expires' => time() - 3600,
                'path' => '/',
                'secure' => $isHttps,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }
    }

    public static function check(): bool
    {
        return self::isLoggedIn();
    }

    public static function currentUserId(): ?int
    {
        self::ensureSession();
        return isset($_SESSION['admin_id']) ? (int) $_SESSION['admin_id'] : null;
    }

    public static function currentUsername(): ?string
    {
        self::ensureSession();
        return $_SESSION['admin_username'] ?? null;
    }

    public static function currentRole(): string
    {
        self::ensureSession();
        $role = $_SESSION['admin_role'] ?? 'read_only';
        return in_array($role, self::ROLES, true) ? $role : 'read_only';
    }

    public static function can(string $permission): bool
    {
        if (!self::isLoggedIn()) {
            return false;
        }

        $role = self::currentRole();
        $permissions = self::ROLE_PERMISSIONS[$role] ?? [];
        $roleDefault = in_array('*', $permissions, true) || in_array($permission, $permissions, true);

        return AuthPermissionBridge::resolve(self::currentUserId(), $permission, $roleDefault, $role);
    }

    public static function requirePermission(string $permission): void
    {
        self::require();
        if (!self::can($permission)) {
            http_response_code(403);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'You do not have permission to perform this action.';
            exit;
        }
    }

    public static function changePassword(int $adminId, string $currentPassword, string $newPassword): array
    {
        $stmt = Database::pdo()->prepare('SELECT password_hash FROM admin_users WHERE id = ?');
        $stmt->execute([$adminId]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($currentPassword, $user['password_hash'])) {
            return ['ok' => false, 'error' => 'Current password is incorrect.'];
        }

        $policy = PasswordPolicy::validate($newPassword, $currentPassword);
        if (!$policy['ok']) {
            return $policy;
        }

        $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $update = $pdo->prepare('UPDATE admin_users SET password_hash = ?, must_change_password = 0 WHERE id = ?');
            $update->execute([$newHash, $adminId]);
            $pdo->prepare('DELETE FROM user_sessions WHERE admin_id = ?')->execute([$adminId]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        $_SESSION['must_change_password'] = false;

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
            unset($_SESSION['csrf_token']);
            $_SESSION['last_activity'] = time();
        }

        return ['ok' => true];
    }
}
