<?php
/**
 * Central configuration. Copy this file's values into your actual
 * environment — for a real deployment, pull these from environment
 * variables instead of hardcoding, especially DB_PASS.
 */

return [
    'db' => [
        'driver'   => 'mysql',
        'host'     => 'wftuqljwesiffol6.cbetxkdyhwsb.us-east-1.rds.amazonaws.com',
        'dbname'   => 'd7xvzabaym8eabhv',
        'username' => 'cpxmfa1ha1zwwlip',
        'password' => 'kp0a1ra4qqqiqhfo',
        'port'     => '3306',
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
