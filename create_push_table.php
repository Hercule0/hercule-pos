<?php
require_once __DIR__ . '/includes/Database.php';

$pdo = Database::pdo();
$pdo->exec("
    CREATE TABLE IF NOT EXISTS push_subscriptions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        admin_username VARCHAR(255) NOT NULL,
        endpoint VARCHAR(2048) NOT NULL,
        p256dh_key VARCHAR(255) NOT NULL,
        auth_key VARCHAR(255) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX(admin_username),
        UNIQUE(endpoint(255))
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");
echo "Table created successfully.\n";
