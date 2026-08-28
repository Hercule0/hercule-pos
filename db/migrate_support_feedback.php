<?php
/**
 * Idempotent migration for the Hercule customer support / feedback center.
 *
 * Usage:
 *   php db/migrate_support_feedback.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../includes/Database.php';

$pdo = Database::pdo();

$statements = [
    <<<'SQL'
CREATE TABLE IF NOT EXISTS support_tickets (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ticket_number VARCHAR(32) NULL UNIQUE,
    license_id INT UNSIGNED NOT NULL,
    activation_id INT UNSIGNED NULL,
    client_request_id VARCHAR(64) NULL,
    type ENUM('problem','suggestion','feature_request') NOT NULL,
    category VARCHAR(50) NOT NULL,
    title VARCHAR(160) NOT NULL,
    description TEXT NOT NULL,
    priority ENUM('normal','important','very_important') NOT NULL DEFAULT 'normal',
    status ENUM('new','reviewed','in_progress','resolved','closed','under_review','accepted','planned','implemented','rejected','duplicate') NOT NULL DEFAULT 'new',
    app_version VARCHAR(50) NULL,
    build VARCHAR(50) NULL,
    os VARCHAR(120) NULL,
    current_page VARCHAR(100) NULL,
    error_code VARCHAR(100) NULL,
    error_message TEXT NULL,
    resolved_in_version VARCHAR(50) NULL,
    last_admin_reply_at DATETIME NULL,
    last_client_reply_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_support_ticket_license FOREIGN KEY (license_id) REFERENCES licenses(id) ON DELETE CASCADE,
    CONSTRAINT fk_support_ticket_activation FOREIGN KEY (activation_id) REFERENCES license_activations(id) ON DELETE SET NULL,
    UNIQUE KEY uq_support_ticket_request (license_id, client_request_id),
    INDEX idx_support_ticket_license_created (license_id, created_at),
    INDEX idx_support_ticket_status_updated (status, updated_at),
    INDEX idx_support_ticket_type_created (type, created_at),
    INDEX idx_support_ticket_category_created (category, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL,
    <<<'SQL'
CREATE TABLE IF NOT EXISTS support_messages (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ticket_id BIGINT UNSIGNED NOT NULL,
    sender_type ENUM('client','admin','system') NOT NULL,
    sender_name VARCHAR(64) NULL,
    message TEXT NOT NULL,
    is_internal TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_support_message_ticket FOREIGN KEY (ticket_id) REFERENCES support_tickets(id) ON DELETE CASCADE,
    INDEX idx_support_message_ticket_created (ticket_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL,
    <<<'SQL'
CREATE TABLE IF NOT EXISTS support_status_history (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ticket_id BIGINT UNSIGNED NOT NULL,
    from_status VARCHAR(32) NULL,
    to_status VARCHAR(32) NOT NULL,
    changed_by_type ENUM('client','admin','system') NOT NULL,
    changed_by VARCHAR(64) NULL,
    note VARCHAR(255) NULL,
    is_internal TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_support_status_ticket FOREIGN KEY (ticket_id) REFERENCES support_tickets(id) ON DELETE CASCADE,
    INDEX idx_support_status_ticket_created (ticket_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL,
    <<<'SQL'
CREATE TABLE IF NOT EXISTS support_attachments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ticket_id BIGINT UNSIGNED NOT NULL,
    message_id BIGINT UNSIGNED NULL,
    uploaded_by_type ENUM('client','admin') NOT NULL,
    original_name VARCHAR(180) NOT NULL,
    mime_type VARCHAR(100) NOT NULL,
    size_bytes INT UNSIGNED NOT NULL,
    sha256 CHAR(64) NOT NULL,
    storage_key VARCHAR(255) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_support_attachment_ticket FOREIGN KEY (ticket_id) REFERENCES support_tickets(id) ON DELETE CASCADE,
    CONSTRAINT fk_support_attachment_message FOREIGN KEY (message_id) REFERENCES support_messages(id) ON DELETE SET NULL,
    INDEX idx_support_attachment_ticket (ticket_id),
    INDEX idx_support_attachment_sha (sha256)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL,
];

foreach ($statements as $statement) {
    $pdo->exec($statement);
}

foreach (['support_tickets', 'support_messages', 'support_status_history', 'support_attachments'] as $table) {
    $pdo->query("SELECT 1 FROM {$table} LIMIT 1");
    echo "VERIFIED - {$table}\n";
}

echo "Support / feedback migration complete.\n";
