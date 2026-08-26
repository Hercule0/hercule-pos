<?php
/**
 * Generic sliding-window rate limiter, keyed by IP + endpoint. Used to
 * protect the public API. Some callers intentionally use a synthetic device
 * or license bucket in place of an IP; those identifiers are always hashed so
 * bearer-like license keys never end up stored in the api_requests table.
 */

require_once __DIR__ . '/Database.php';

final class RateLimiter
{
    private const STORAGE_KEY_MAX = 45;

    private static function storageKey(string $key): string
    {
        $key = trim($key);
        if ($key === '') return 'unknown';

        // Preserve only literal IP addresses for useful abuse diagnostics.
        // Every synthetic bucket (key:<license>, trial:<hwid>, upd-..., global,
        // etc.) is domain-separated and hashed so sensitive identifiers are
        // never persisted in plaintext and can never overflow VARCHAR(45).
        if (filter_var($key, FILTER_VALIDATE_IP) !== false
            && strlen($key) <= self::STORAGE_KEY_MAX
            && !preg_match('/[\x00-\x1F\x7F]/', $key)) {
            return $key;
        }
        if ($key === 'unknown') return $key;

        return 'h:' . substr(hash('sha256', "hercule-rate-limit-v1\0" . $key), 0, self::STORAGE_KEY_MAX - 2);
    }

    private static function endpointKey(string $endpoint): string
    {
        $endpoint = trim($endpoint);
        if ($endpoint === '') return 'unknown';
        if (strlen($endpoint) <= 30 && !preg_match('/[\x00-\x1F\x7F]/', $endpoint)) {
            return $endpoint;
        }
        return 'h:' . substr(hash('sha256', "hercule-rate-endpoint-v1\0" . $endpoint), 0, 28);
    }

    /**
     * @return bool true if this key is still within its allowance for this
     *              endpoint, false if it should be rejected.
     */
    public static function isAllowed(string $ip, string $endpoint, int $maxRequests, int $windowMinutes): bool
    {
        $pdo = Database::pdo();
        $ip = self::storageKey($ip);
        $endpoint = self::endpointKey($endpoint);
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
        $pdo = Database::pdo();
        $ip = self::storageKey($ip);
        $endpoint = self::endpointKey($endpoint);

        $stmt = $pdo->prepare(
            'INSERT INTO api_requests (ip_address, endpoint) VALUES (?, ?)'
        );
        $stmt->execute([$ip, $endpoint]);

        // About 1% of requests perform a bounded retention cleanup, avoiding
        // a cleanup query on every API call while preventing unbounded growth.
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' && random_int(1, 100) === 1) {
            $threshold = (new DateTime())->modify('-7 days')->format('Y-m-d H:i:s');
            $cleanup = $pdo->prepare(
                'DELETE FROM api_requests WHERE created_at < ? ORDER BY id LIMIT 1000'
            );
            $cleanup->execute([$threshold]);
        }
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
