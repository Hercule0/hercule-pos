<?php

declare(strict_types=1);

final class G1ProductionMysqlProbe
{
    private const TOKEN_FILE = '/home/data/hercule-g1/mysql-runtime-probe.token';

    public static function respond(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            json_response(['ok' => false, 'error' => 'Not found.'], 404);
        }

        if (!RateLimiter::check(client_ip(), 'g1_mysql_runtime_probe', 6, 5)) {
            json_response(['ok' => false, 'error' => 'Too many requests.'], 429);
        }

        self::consumeOneTimeToken();

        $root = dirname(__DIR__);
        $sourcePath = $root . '/includes/EntitlementV2.php';
        $sourceSha = is_file($sourcePath) ? hash_file('sha256', $sourcePath) : false;
        if (!is_string($sourceSha) || !preg_match('/^[a-f0-9]{64}$/', $sourceSha)) {
            json_response(['ok' => false, 'error' => 'Runtime proof source is unavailable.'], 503);
        }

        try {
            $main = Database::pdo();
            if (strtolower((string) $main->getAttribute(PDO::ATTR_DRIVER_NAME)) !== 'mysql') {
                throw new RuntimeException('production_driver_not_mysql');
            }
            if (!EntitlementV2::schemaReady()) {
                throw new RuntimeException('entitlement_schema_not_ready');
            }

            $config = require $root . '/config/config.php';
            $db = is_array($config['db'] ?? null) ? $config['db'] : [];
            $competitor = self::independentMysqlPdo($db);
            if (strtolower((string) $competitor->getAttribute(PDO::ATTR_DRIVER_NAME)) !== 'mysql') {
                throw new RuntimeException('competitor_driver_not_mysql');
            }

            $mainId = (int) $main->query('SELECT CONNECTION_ID()')->fetchColumn();
            $competitorId = (int) $competitor->query('SELECT CONNECTION_ID()')->fetchColumn();
            if ($mainId <= 0 || $competitorId <= 0 || $mainId === $competitorId) {
                throw new RuntimeException('connections_not_independent');
            }

            $syntheticKey = 'G1-PROBE-' . bin2hex(random_bytes(16));
            $lockName = 'hercule-seat-' . substr(hash('sha256', $syntheticKey), 0, 40);
            $excluded = false;

            EntitlementV2::withSeatLock($syntheticKey, static function () use ($competitor, $lockName, &$excluded): void {
                $blocked = self::scalarLock($competitor, 'SELECT GET_LOCK(?, 0)', $lockName);
                if ($blocked !== 0) {
                    throw new RuntimeException('named_lock_exclusion_failed');
                }
                $excluded = true;
            });

            if (!$excluded) {
                throw new RuntimeException('named_lock_exclusion_not_observed');
            }

            $handoff = self::scalarLock($competitor, 'SELECT GET_LOCK(?, 0)', $lockName);
            if ($handoff !== 1) {
                throw new RuntimeException('lock_handoff_failed');
            }
            try {
                $released = self::scalarLock($competitor, 'SELECT RELEASE_LOCK(?)', $lockName);
                if ($released !== 1) {
                    throw new RuntimeException('competitor_release_failed');
                }
            } finally {
                try { self::scalarLock($competitor, 'SELECT RELEASE_LOCK(?)', $lockName); } catch (Throwable $ignored) {}
            }

            $payload = [
                'schema_version' => 1,
                'status' => 'G1_MYSQL_RUNTIME_PROBE_PASS',
                'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
                'database_driver' => 'mysql',
                'entitlement_schema_ready' => true,
                'tested_function' => 'EntitlementV2::withSeatLock',
                'independent_connections' => true,
                'named_lock_exclusion' => true,
                'lock_handoff' => true,
                'synthetic_key_only' => true,
                'production_rows_mutated' => false,
                'entitlement_source_sha256' => $sourceSha,
            ];

            json_response(['ok' => true] + RsaSigner::sign($payload), 200);
        } catch (Throwable $e) {
            ErrorHandler::report($e, 'g1_mysql_runtime_probe_failed');
            json_response(['ok' => false, 'error' => 'Production MySQL runtime proof failed.'], 503);
        }
    }

    private static function consumeOneTimeToken(): void
    {
        $provided = strtolower(trim((string) ($_SERVER['HTTP_X_HERCULE_G1_PROBE_TOKEN'] ?? '')));
        if (!preg_match('/^[a-f0-9]{64}$/', $provided) || !is_file(self::TOKEN_FILE)) {
            json_response(['ok' => false, 'error' => 'Not found.'], 404);
        }

        $fh = @fopen(self::TOKEN_FILE, 'c+');
        if (!$fh || !flock($fh, LOCK_EX)) {
            if (is_resource($fh)) fclose($fh);
            json_response(['ok' => false, 'error' => 'Not found.'], 404);
        }

        $matched = false;
        try {
            rewind($fh);
            $raw = trim((string) stream_get_contents($fh));
            if (preg_match('/^([a-f0-9]{64})\|(\d{10})$/', $raw, $m)) {
                $expected = $m[1];
                $expiresAt = (int) $m[2];
                if ($expiresAt >= time() && hash_equals($expected, $provided)) {
                    $matched = true;
                    ftruncate($fh, 0);
                    fflush($fh);
                }
            }
        } finally {
            flock($fh, LOCK_UN);
            fclose($fh);
            if ($matched) @unlink(self::TOKEN_FILE);
        }

        if (!$matched) {
            json_response(['ok' => false, 'error' => 'Not found.'], 404);
        }
    }

    private static function independentMysqlPdo(array $db): PDO
    {
        $host = trim((string) ($db['host'] ?? ''));
        $name = trim((string) ($db['dbname'] ?? $db['name'] ?? ''));
        $user = (string) ($db['username'] ?? $db['user'] ?? '');
        $pass = (string) ($db['password'] ?? $db['pass'] ?? '');
        $port = trim((string) ($db['port'] ?? '3306'));
        $charset = trim((string) ($db['charset'] ?? 'utf8mb4'));
        if ($host === '' || $name === '' || $user === '') {
            throw new RuntimeException('db_config_incomplete');
        }

        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];
        $sslCa = '/etc/ssl/certs/ca-certificates.crt';
        if (defined('PDO::MYSQL_ATTR_SSL_CA') && is_file($sslCa)) {
            $options[PDO::MYSQL_ATTR_SSL_CA] = $sslCa;
            if (defined('PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT')) {
                $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = true;
            }
        }

        return new PDO(
            "mysql:host={$host};port={$port};dbname={$name};charset={$charset}",
            $user,
            $pass,
            $options
        );
    }

    private static function scalarLock(PDO $pdo, string $sql, string $lockName): int
    {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$lockName]);
        $value = $stmt->fetchColumn();
        return is_numeric($value) ? (int) $value : -1;
    }
}
