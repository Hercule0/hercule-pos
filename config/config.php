<?php
/**
 * Central configuration. Values are pulled from environment variables —
 * set these in your hosting platform (e.g. Azure App Service ->
 * Configuration -> Application settings), never hardcode real credentials
 * here.
 */

/**
 * Reads an environment variable safely. getenv() returns false (not null)
 * when a var is unset, and PHP's ?? operator only falls back on null — so
 * a bare `getenv('X') ?? 'default'` silently keeps `false` instead of
 * falling back. This normalizes that.
 */
function env(string $key, $default = null)
{
    $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
    if ($value === false || $value === null || $value === '') {
        return $default;
    }
    return $value;
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

    // RSA keypair used to sign license validation responses. The desktop
    // app (Phase 5) ships with ONLY the public key embedded, and verifies
    // every response signature before trusting it — this is what makes
    // validation responses hard to forge even by someone who can intercept
    // or replay HTTP traffic to the desktop app.
    'rsa' => [
        'private_key_path' => __DIR__ . '/../keys/license_signing_private.pem',
        'public_key_path'  => __DIR__ . '/../keys/license_signing_public.pem',
    ],

    'security' => [
        // Failed login lockout: max attempts within the window, then locked out.
        'login_max_attempts' => 5,
        'login_window_minutes' => 15,
        'session_lifetime_minutes' => 60,

        // Public API rate limiting (activate.php / validate.php), per IP.
        // Generous enough for legitimate retry-after-network-blip behavior,
        // tight enough to stop someone hammering the endpoint.
        'api_rate_limit_max_requests' => 20,
        'api_rate_limit_window_minutes' => 5,
    ],
];
