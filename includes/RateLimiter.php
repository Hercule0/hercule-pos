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
    private const MYSQL_LOCK_TIMEOUT_SECONDS = 2;

    private static function storageKey(string $key): string
    {
        $key = trim($key);
        if ($key === '') return 'unknown';

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

    private static function threshold(int $windowMinutes): string
    {
        $windowMinutes = max(1, $windowMinutes);
        return (new DateTime())
            ->modify("-{$windowMinutes} minutes")
            ->format('Y-m-d H:i:s');
    }

    private static function count(PDO $pdo, string $ip, string $endpoint, string $threshold): int
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM api_requests WHERE ip_address = ? AND endpoint = ? AND created_at > ?'
        );
        $stmt->execute([$ip, $endpoint, $threshold]);
        return (int) $stmt->fetchColumn();
    }

    private static function insert(PDO $pdo, string $ip, string $endpoint): void
    {
        $stmt = $pdo->prepare(
            'INSERT INTO api_requests (ip_address, endpoint) VALUES (?, ?)'
        );
        $stmt->execute([$ip, $endpoint]);
    }

    private static function cleanupBestEffort(PDO $pdo): void
    {
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql' || random_int(1, 100) !== 1) return;
        $threshold = (new DateTime())->modify('-7 days')->format('Y-m-d H:i:s');
        $cleanup = $pdo->prepare(
            'DELETE FROM api_requests WHERE created_at < ? ORDER BY id LIMIT 1000'
        );
        $cleanup->execute([$threshold]);
    }

    /**
     * Count then record while the caller owns the bucket serialization lock.
     * Rejected attempts are deliberately recorded as well.
     */
    private static function checkAndRecord(PDO $pdo, string $ip, string $endpoint, int $maxRequests, int $windowMinutes): bool
    {
        $allowed = self::count($pdo, $ip, $endpoint, self::threshold($windowMinutes)) < max(1, $maxRequests);
        self::insert($pdo, $ip, $endpoint);
        self::cleanupBestEffort($pdo);
        return $allowed;
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
        return self::count($pdo, $ip, $endpoint, self::threshold($windowMinutes)) < max(1, $maxRequests);
    }

    public static function record(string $ip, string $endpoint): void
    {
        $pdo = Database::pdo();
        $ip = self::storageKey($ip);
        $endpoint = self::endpointKey($endpoint);
        self::insert($pdo, $ip, $endpoint);
        self::cleanupBestEffort($pdo);
    }

    /**
     * Atomically records the hit and decides whether it is allowed.
     *
     * Production MySQL uses a named advisory lock derived only from the
     * already-normalized bucket + endpoint. This closes the old race where
     * concurrent requests could all COUNT before any of them INSERTed.
     * If the lock cannot be acquired within the short bounded timeout, the
     * request fails closed (false) rather than bypassing the limiter.
     *
     * SQLite is used only by local regression tests here; it keeps the same
     * semantics without MySQL-specific advisory-lock SQL.
     */
    public static function check(string $ip, string $endpoint, int $maxRequests, int $windowMinutes): bool
    {
        $pdo = Database::pdo();
        $ip = self::storageKey($ip);
        $endpoint = self::endpointKey($endpoint);
        $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        if ($driver !== 'mysql') {
            return self::checkAndRecord($pdo, $ip, $endpoint, $maxRequests, $windowMinutes);
        }

        $lockName = 'hrl:' . substr(hash('sha256', "hercule-rate-lock-v1\0" . $ip . "\0" . $endpoint), 0, 48);
        $acquire = $pdo->prepare('SELECT GET_LOCK(?, ?)');
        $acquire->execute([$lockName, self::MYSQL_LOCK_TIMEOUT_SECONDS]);
        if ((int) $acquire->fetchColumn() !== 1) {
            return false;
        }

        try {
            return self::checkAndRecord($pdo, $ip, $endpoint, $maxRequests, $windowMinutes);
        } finally {
            try {
                $release = $pdo->prepare('SELECT RELEASE_LOCK(?)');
                $release->execute([$lockName]);
            } catch (Throwable $ignored) {
                // MySQL releases named locks when the connection closes. Do not
                // turn an already-decided request into a limiter bypass.
            }
        }
    }
}
