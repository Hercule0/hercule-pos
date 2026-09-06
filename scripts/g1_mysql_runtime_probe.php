<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "G1 MySQL runtime probe is CLI-only.\n");
    exit(2);
}

$root = dirname(__DIR__);
require_once $root . '/includes/EntitlementV2.php';

function fail_probe(string $code): never
{
    echo json_encode([
        'ok' => false,
        'status' => 'G1_MYSQL_RUNTIME_PROBE_FAILED',
        'error_code' => $code,
    ], JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(1);
}

function independent_mysql_pdo(array $db): PDO
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

    $dsn = "mysql:host={$host};port={$port};dbname={$name};charset={$charset}";
    return new PDO($dsn, $user, $pass, $options);
}

function scalar_lock(PDO $pdo, string $sql, string $lockName): int
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$lockName]);
    $value = $stmt->fetchColumn();
    return is_numeric($value) ? (int) $value : -1;
}

$config = require $root . '/config/config.php';
$db = is_array($config['db'] ?? null) ? $config['db'] : [];
$sourcePath = $root . '/includes/EntitlementV2.php';
$sourceSha = is_file($sourcePath) ? hash_file('sha256', $sourcePath) : false;
if (!is_string($sourceSha) || !preg_match('/^[a-f0-9]{64}$/', $sourceSha)) {
    fail_probe('entitlement_source_hash_failed');
}

try {
    $schemaReady = EntitlementV2::schemaReady();
    if (!$schemaReady) {
        fail_probe('entitlement_schema_not_ready');
    }

    $a = independent_mysql_pdo($db);
    $b = independent_mysql_pdo($db);
    if (strtolower((string) $a->getAttribute(PDO::ATTR_DRIVER_NAME)) !== 'mysql'
        || strtolower((string) $b->getAttribute(PDO::ATTR_DRIVER_NAME)) !== 'mysql') {
        fail_probe('production_driver_not_mysql');
    }

    $lockName = 'hercule-g1-runtime-' . bin2hex(random_bytes(12));
    $aHeld = false;
    $bHeld = false;

    try {
        $aAcquire = scalar_lock($a, 'SELECT GET_LOCK(?, 0)', $lockName);
        $aHeld = $aAcquire === 1;
        if (!$aHeld) fail_probe('connection_a_lock_failed');

        $bBlocked = scalar_lock($b, 'SELECT GET_LOCK(?, 0)', $lockName);
        if ($bBlocked !== 0) fail_probe('named_lock_exclusion_failed');

        $aRelease = scalar_lock($a, 'SELECT RELEASE_LOCK(?)', $lockName);
        $aHeld = false;
        if ($aRelease !== 1) fail_probe('connection_a_release_failed');

        $bAcquire = scalar_lock($b, 'SELECT GET_LOCK(?, 0)', $lockName);
        $bHeld = $bAcquire === 1;
        if (!$bHeld) fail_probe('lock_handoff_failed');

        $bRelease = scalar_lock($b, 'SELECT RELEASE_LOCK(?)', $lockName);
        $bHeld = false;
        if ($bRelease !== 1) fail_probe('connection_b_release_failed');
    } finally {
        if ($aHeld) {
            try { scalar_lock($a, 'SELECT RELEASE_LOCK(?)', $lockName); } catch (Throwable $ignored) {}
        }
        if ($bHeld) {
            try { scalar_lock($b, 'SELECT RELEASE_LOCK(?)', $lockName); } catch (Throwable $ignored) {}
        }
    }

    echo json_encode([
        'ok' => true,
        'status' => 'G1_MYSQL_RUNTIME_PROBE_PASS',
        'database_driver' => 'mysql',
        'entitlement_schema_ready' => true,
        'independent_connections' => true,
        'named_lock_exclusion' => true,
        'lock_handoff' => true,
        'production_rows_mutated' => false,
        'entitlement_source_sha256' => $sourceSha,
    ], JSON_UNESCAPED_SLASHES) . PHP_EOL;
} catch (Throwable $e) {
    fail_probe('runtime_probe_exception');
}
