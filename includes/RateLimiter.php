<?php
/**
 * Generic sliding-window rate limiter, keyed by IP + endpoint. Used to
 * protect the public API (activate.php / validate.php) from being
 * hammered — these have no login/session to rely on, so this is the
 * only throttle they get.
 */

require_once __DIR__ . '/Database.php';

final class RateLimiter
{
    /**
     * @return bool true if this IP is still within its allowance for this
     *              endpoint, false if it should be rejected.
     */
    public static function isAllowed(string $ip, string $endpoint, int $maxRequests, int $windowMinutes): bool
    {
        $pdo = Database::pdo();
        $threshold = (new DateTime())
            ->modify("-{$windowMinutes} minutes")
            ->format('Y-m-d H:i:s');

        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM api_requests WHERE ip_address = ? AND endpoint = ? AND created_at > ?'
        );
        $stmt->execute([$ip, $endpoint, $threshold]);

        return (int) $stmt->fetchColumn() < $maxRequests;
    }

    public static function record(string $ip, string $endpoint): void
    {
        $stmt = Database::pdo()->prepare(
            'INSERT INTO api_requests (ip_address, endpoint) VALUES (?, ?)'
        );
        $stmt->execute([$ip, $endpoint]);
    }

    /**
     * Convenience: records the hit AND returns whether it was allowed,
     * so callers do this in one line. Records even rejected attempts —
     * otherwise someone hammering the endpoint after being blocked would
     * see the window "reset" the moment their oldest attempt ages out,
     * rather than the block extending naturally.
     */
    public static function check(string $ip, string $endpoint, int $maxRequests, int $windowMinutes): bool
    {
        $allowed = self::isAllowed($ip, $endpoint, $maxRequests, $windowMinutes);
        self::record($ip, $endpoint);
        return $allowed;
    }
}
 
