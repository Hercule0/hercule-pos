<?php
final class Database
{
    private static ?PDO $instance = null;

    public static function pdo(): PDO
    {
        if (self::$instance === null) {
            $config = require __DIR__ . '/../config/config.php';
            $db = $config['db'];

            $host = $db['host'] ?? '';
            $dbname = $db['dbname'] ?? $db['name'] ?? '';
            $username = $db['username'] ?? $db['user'] ?? '';
            $password = $db['password'] ?? $db['pass'] ?? '';
            $port = $db['port'] ?? '3306';
            $charset = $db['charset'] ?? 'utf8mb4';

            $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset={$charset}";

            self::$instance = new PDO($dsn, $username, $password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => false,
            ]);
        }

        return self::$instance;
    }

    public static function setTestInstance(PDO $pdo): void
    {
        self::$instance = $pdo;
    }
}
