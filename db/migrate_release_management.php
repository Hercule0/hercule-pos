<?php
/**
 * Release Management phase 1 migration.
 * Usage: php db/migrate_release_management.php
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../includes/Database.php';
$pdo = Database::pdo();

echo "Connecting to database... OK\n";

$pdo->exec(
    "CREATE TABLE IF NOT EXISTS app_releases (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        version VARCHAR(50) NOT NULL,
        minimum_supported_version VARCHAR(50) NULL,
        download_url VARCHAR(2048) NULL,
        release_notes TEXT NULL,
        is_mandatory TINYINT(1) NOT NULL DEFAULT 0,
        is_published TINYINT(1) NOT NULL DEFAULT 0,
        published_at DATETIME NULL,
        created_by VARCHAR(64) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_app_releases_version (version),
        INDEX idx_app_releases_published (is_published, published_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);

$pdo->query('SELECT id, version, is_published FROM app_releases LIMIT 1');
echo "Release management migration complete.\n";
