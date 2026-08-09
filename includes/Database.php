<?php
final class Database
{
    private static ?PDO $instance = null;

    public static function pdo(): PDO
    {
        if (self::$instance === null) {
            $config = require __DIR__ . '/../config/config.php';
            $db = $config['db'];

            $host = $db['host'] ?? null;
            $dbname = $db['dbname'] ?? $db['name'] ?? null;
            $username = $db['username'] ?? $db['user'] ?? null;
            $password = $db['password'] ?? $db['pass'] ?? null;
            $port = $db['port'] ?? '3306';
            $charset = $db['charset'] ?? 'utf8mb4';

            if (!$host || !$dbname || !$username) {
                throw new RuntimeException(
                    'Database configuration is missing. Check that DB_HOST, DB_NAME, ' .
                    'DB_USER (and DB_PASS) are set as environment variables on the server.'
                );
            }

            $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset={$charset}";

            self::$instance = new PDO($dsn, $username, (string) $password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        }

        return self::$instance;
    }

    public static function setTestInstance(PDO $pdo): void
    {
        self::$instance = $pdo;
    }
}
