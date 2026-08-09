<?php
final class Database
{
    private static ?PDO $instance = null;

    public static function pdo(): PDO
    {
        if (self::$instance === null) {
            $config = require __DIR__ . '/../config/config.php';
            $db = $config['db'];

            $host = $db['host'] ?? 'wftuqljwesiffol6.cbetxkdyhwsb.us-east-1.rds.amazonaws.com';
            $dbname = $db['dbname'] ?? $db['name'] ?? 'd7xvzabaym8eabhv';
            $username = $db['username'] ?? $db['user'] ?? 'cpxmfa1ha1zwwlip';
            $password = $db['password'] ?? $db['pass'] ?? 'kp0a1ra4qqqiqhfo';
            $port = $db['port'] ?? '3306';
            $charset = $db['charset'] ?? 'utf8mb4';

            $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset={$charset}";

            self::$instance = new PDO($dsn, $username, $password, [
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