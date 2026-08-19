<?php
require_once __DIR__ . '/../includes/Database.php';

$pdo = Database::pdo();
$pdo->exec("
    CREATE TABLE IF NOT EXISTS user_sessions (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        admin_id INT UNSIGNED NOT NULL,
        selector VARCHAR(24) NOT NULL UNIQUE,
        validator_hash VARCHAR(64) NOT NULL,
        user_agent VARCHAR(255) NOT NULL,
        ip_address VARCHAR(45) NOT NULL,
        expires_at DATETIME NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (admin_id) REFERENCES admin_users(id) ON DELETE CASCADE,
        INDEX idx_user_sessions_selector (selector)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");
echo "Table `user_sessions` created successfully.\n";
