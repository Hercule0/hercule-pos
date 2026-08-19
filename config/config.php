<?php
/**
 * Central configuration. Values are pulled from environment variables —
 * set these in your hosting platform (e.g. Azure App Service ->
 * Configuration -> Application settings), never hardcode real credentials
 * here.
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

    // Web Push credentials. Keep the private key in the hosting environment only.
    // VAPID_PUBLIC_KEY may be public by design, but keeping both values in Azure
    // makes rotation and multi-environment deployments safer.
    'vapid' => [
        'subject' => env('VAPID_SUBJECT', 'mailto:admin@herculepos.com'),
        'public_key' => env('VAPID_PUBLIC_KEY', 'BKraEuulwXx3knDp50hkOAI1QaJBnFxTngjhnfi48WkMMKcDSBCwxn4WePT0RSrEnJWEmgX-DpG9WiVgK_rNAAY'),
        'private_key' => env('VAPID_PRIVATE_KEY'),
    ],
];
