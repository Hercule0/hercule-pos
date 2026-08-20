<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../includes/Database.php';
$pdo = Database::pdo();
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS admin_notification_preferences (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        admin_username VARCHAR(64) NOT NULL UNIQUE,
        activation_enabled TINYINT(1) NOT NULL DEFAULT 1,
        recovery_enabled TINYINT(1) NOT NULL DEFAULT 1,
        expiry_enabled TINYINT(1) NOT NULL DEFAULT 1,
        security_enabled TINYINT(1) NOT NULL DEFAULT 1,
        system_enabled TINYINT(1) NOT NULL DEFAULT 1,
        muted_until DATETIME NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_notification_prefs_mute (muted_until)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);
echo "Notification preferences migration complete.\n";
