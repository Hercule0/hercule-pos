<?php
/**
 * CSRF protection — one token per session, timing-safe comparison on check.
 */

final class Csrf
{
    public static function token(): string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    public static function field(): string
    {
        $token = htmlspecialchars(self::token(), ENT_QUOTES, 'UTF-8');
        return "<input type=\"hidden\" name=\"csrf_token\" value=\"{$token}\">";
    }

    public static function check(?string $submittedToken): bool
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        if (empty($_SESSION['csrf_token']) || empty($submittedToken)) {
            return false;
        }
        return hash_equals($_SESSION['csrf_token'], $submittedToken);
    }

    /**
     * Read the anti-CSRF token from either a normal HTML form or the standard
     * X-CSRF-Token request header used by authenticated admin JavaScript.
     */
    public static function submittedToken(): string
    {
        $form = $_POST['csrf_token'] ?? null;
        if (is_string($form) && $form !== '') {
            return $form;
        }
        $header = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        return is_string($header) ? $header : '';
    }

    /**
     * Call at the top of any state-changing admin handler (POST that mutates
     * data). Supports both normal forms and same-origin JSON/fetch requests.
     */
    public static function guard(): void
    {
        if (!self::check(self::submittedToken())) {
            http_response_code(403);
            die('Invalid or expired CSRF token. Please refresh the page and try again.');
        }
    }
}
