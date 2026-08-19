<?php
/**
 * POST /api/check_update.php
 * Body: { "license_key": "..." }
 *
 * License System Upgrade Plan — Phase 6 (Realtime Notification).
 *
 * Deliberately cheap and UNSIGNED: the only thing this returns is a
 * boolean hint ("something about this license changed since you last
 * validated"), never any license state itself — so there is nothing here
 * for a network attacker to usefully forge. Spoofing `has_update: true`
 * just makes the desktop app perform one extra, harmless FORCED
 * validate.php call. Spoofing `false` just delays detection until the
 * next scheduled validate.php anyway (which still runs on its own
 * cadence regardless of this endpoint). This is what lets the desktop
 * app poll far more often than it would ever want to run a full
 * RSA-signed round trip — see LicenseManager.js's pending-update poll on
 * the desktop side, and validate.php's consumePendingChanges() call,
 * which is what actually clears the flag this endpoint reads.
 *
 * Response:
 * { "ok": true, "has_update": true|false }
 */

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../includes/RateLimiter.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'error' => 'Method not allowed'], 405);
}

$config = require __DIR__ . '/../../config/config.php';
$rateLimitCfg = $config['security'];
if (!RateLimiter::check(client_ip(), 'check_update', $rateLimitCfg['api_rate_limit_max_requests'], $rateLimitCfg['api_rate_limit_window_minutes'])) {
    json_response(['ok' => false, 'error' => 'Too many requests. Please try again in a few minutes.'], 429);
}

$input = json_input();
$licenseKey = trim(
    $input['license_key'] ??
    $input['licenseKey'] ??
    $input['key'] ??
    $input['serial'] ??
    $input['license'] ?? ''
);

if ($licenseKey === '') {
    json_response(['ok' => false, 'error' => 'license_key is required'], 400);
}

json_response(['ok' => true, 'has_update' => License::hasPendingChange($licenseKey)]);
