<?php
/**
 * Tracks which expiry threshold alerts have already been sent.
 * Usage: php db/migrate_expiry_alerts.php
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../includes/Database.php';
$pdo = Database::pdo();

$pdo->exec(
    "CREATE TABLE IF NOT EXISTS license_expiry_alerts (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        license_id INT UNSIGNED NOT NULL,
        threshold_days INT NOT NULL,
        expires_at DATETIME NOT NULL,
        sent_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_license_expiry_alert (license_id, threshold_days, expires_at),
        INDEX idx_license_expiry_alert_sent (sent_at),
        FOREIGN KEY (license_id) REFERENCES licenses(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);

echo "Expiry alert migration complete.\n";
