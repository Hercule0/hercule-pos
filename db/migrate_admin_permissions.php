<?php
/**
 * Idempotent CLI migration for per-admin permission overrides.
 * Usage: php db/migrate_admin_permissions.php
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../includes/Database.php';
$pdo = Database::pdo();

$pdo->exec(
    "CREATE TABLE IF NOT EXISTS admin_permission_overrides (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        admin_id INT UNSIGNED NOT NULL,
        permission VARCHAR(80) NOT NULL,
        allowed TINYINT(1) NOT NULL DEFAULT 1,
        updated_by INT UNSIGNED NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_admin_permission (admin_id, permission),
        INDEX idx_admin_permission_admin (admin_id),
        CONSTRAINT fk_admin_permission_admin FOREIGN KEY (admin_id) REFERENCES admin_users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);

echo "VERIFIED - admin_permission_overrides\n";
echo "Admin permission migration complete.\n";
