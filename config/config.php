<?php
/**
 * Central configuration. Environment variables override local defaults.
 */

if (!function_exists('env')) {
    function env(string $key, $default = null)
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
        if ($value === false || $value === null || $value === '') {
            return $default;
        }
        return $value;
    }
}

return [
    'db' => [
        'driver'   => 'mysql',
        'host'     => env('DB_HOST'),
        'dbname'   => env('DB_NAME'),
        'username' => env('DB_USER'),
        'password' => env('DB_PASS'),
        'port'     => env('DB_PORT', '3306'),
        'charset'  => 'utf8mb4',
    ],

    // Legacy paths are retained for public-key compatibility only. Production
    // private signing material is loaded from environment variables by the
    // dedicated signer classes and must never be committed to the repository.
    'rsa' => [
        'private_key_path' => __DIR__ . '/../keys/license_signing_private.pem',
        'public_key_path'  => __DIR__ . '/../keys/license_signing_public.pem',
    ],

    'security' => [
        'login_max_attempts' => 5,
        'login_window_minutes' => 15,
        'session_lifetime_minutes' => 60,
        'api_rate_limit_max_requests' => 20,
        'api_rate_limit_window_minutes' => 5,
        'key_rate_limit_max_requests' => 30,
        'key_rate_limit_window_minutes' => 5,
    ],

    // Web Push only. VAPID private material is environment-only. The former
    // committed fallback must be considered compromised and rotated before
    // this branch is merged into production.
    'vapid' => [
        'subject' => env('VAPID_SUBJECT', 'mailto:admin@herculepos.com'),
        'public_key' => env('VAPID_PUBLIC_KEY', ''),
        'private_key' => env('VAPID_PRIVATE_KEY', ''),
    ],
];
