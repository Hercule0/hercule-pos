-- Hercule License Server — Phase 4 schema (MySQL)
-- Idempotent: safe to run against an existing DB, migrate.php wraps this
-- with CREATE TABLE IF NOT EXISTS semantics.

CREATE TABLE IF NOT EXISTS admin_users (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username        VARCHAR(64) NOT NULL UNIQUE,
    password_hash   VARCHAR(255) NOT NULL,
    role            ENUM('owner','support','read_only') NOT NULL DEFAULT 'owner',
    is_active       TINYINT(1) NOT NULL DEFAULT 1,
    must_change_password TINYINT(1) NOT NULL DEFAULT 0,
    totp_enabled    TINYINT(1) NOT NULL DEFAULT 0,
    totp_secret     TEXT NULL,
    recovery_codes  TEXT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS admin_audit_log (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    actor_id        INT UNSIGNED NULL,
    target_id       INT UNSIGNED NULL,
    action          VARCHAR(40) NOT NULL,
    details         VARCHAR(255) NULL,
    ip_address      VARCHAR(45) NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_admin_audit_created (created_at),
    INDEX idx_admin_audit_target (target_id),
    INDEX idx_admin_audit_action_created (action, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS login_attempts (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username        VARCHAR(64) NOT NULL,
    ip_address      VARCHAR(45) NOT NULL,
    success         TINYINT(1) NOT NULL DEFAULT 0,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_login_attempts_lookup (username, ip_address, created_at),
    INDEX idx_login_attempts_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS api_requests (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ip_address      VARCHAR(45) NOT NULL,
    endpoint        VARCHAR(30) NOT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_api_requests_lookup (ip_address, endpoint, created_at),
    INDEX idx_api_requests_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

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

CREATE TABLE IF NOT EXISTS admin_notification_preferences (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS admin_permission_overrides (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS customers (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name            VARCHAR(150) NOT NULL,
    email           VARCHAR(150),
    phone           VARCHAR(30),
    notes           TEXT,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_customers_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS licenses (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_id         INT UNSIGNED NOT NULL,
    license_key         VARCHAR(29) NOT NULL UNIQUE,
    plan                ENUM('trial','monthly','semi_annual','annual','custom','lifetime') NOT NULL,
    status              ENUM('active','suspended','revoked','expired') NOT NULL DEFAULT 'active',
    max_activations     INT UNSIGNED NOT NULL DEFAULT 1,
    issued_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at          DATETIME NULL,
    last_verified_at    DATETIME NULL,
    notes               TEXT,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
    INDEX idx_licenses_status (status),
    INDEX idx_licenses_expires (expires_at),
    INDEX idx_licenses_status_expires (status, expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS license_activations (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    license_id      INT UNSIGNED NOT NULL,
    hwid            VARCHAR(128) NOT NULL,
    device_name     VARCHAR(100) NULL,
    app_version     VARCHAR(50) NULL,
    is_active       TINYINT(1) NOT NULL DEFAULT 1,
    activated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_seen_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ip_address      VARCHAR(45),
    admin_note      VARCHAR(255) NULL,
    is_blocked      TINYINT(1) NOT NULL DEFAULT 0,
    blocked_at      DATETIME NULL,
    blocked_by      VARCHAR(64) NULL,
    FOREIGN KEY (license_id) REFERENCES licenses(id) ON DELETE CASCADE,
    UNIQUE KEY uq_license_hwid (license_id, hwid),
    INDEX idx_activations_license (license_id),
    INDEX idx_activations_blocked (is_blocked, is_active),
    INDEX idx_activations_active_seen (is_active, last_seen_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS verification_log (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    license_id      INT UNSIGNED NULL,
    license_key     VARCHAR(29) NOT NULL,
    hwid            VARCHAR(128),
    result          VARCHAR(30) NOT NULL,
    ip_address      VARCHAR(45),
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_verification_log_license (license_id),
    INDEX idx_verification_log_created (created_at),
    INDEX idx_verification_result_created (result, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS subscription_events (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    license_id      INT UNSIGNED NOT NULL,
    event_type      VARCHAR(30) NOT NULL,
    previous_expires_at DATETIME NULL,
    new_expires_at  DATETIME NULL,
    note            VARCHAR(255),
    created_by      VARCHAR(64),
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (license_id) REFERENCES licenses(id) ON DELETE CASCADE,
    INDEX idx_subscription_license_created (license_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS license_change_notifications (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    license_key     VARCHAR(29) NOT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    consumed_at     DATETIME NULL,
    INDEX idx_notif_key_pending (license_key, consumed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS password_recovery_requests (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    license_key         VARCHAR(29) NOT NULL,
    hwid                VARCHAR(128) NOT NULL,
    requested_username  VARCHAR(64) NOT NULL,
    status              ENUM('pending','approved','rejected','expired','completed') NOT NULL DEFAULT 'pending',
    admin_note          TEXT,
    token_hash          VARCHAR(64) NULL,
    token_expires_at    DATETIME NULL,
    delivered_at        DATETIME NULL,
    used_at             DATETIME NULL,
    reviewed_by         VARCHAR(64) NULL,
    reviewed_at         DATETIME NULL,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_recovery_license (license_key),
    INDEX idx_recovery_status (status),
    INDEX idx_recovery_status_created (status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS recovery_audit_log (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    request_id      INT UNSIGNED NULL,
    event_type      VARCHAR(40) NOT NULL,
    actor           VARCHAR(64) NULL,
    ip_address      VARCHAR(45) NULL,
    note            VARCHAR(255) NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_recovery_audit_request (request_id),
    INDEX idx_recovery_audit_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

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

CREATE TABLE IF NOT EXISTS app_releases (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS license_expiry_alerts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    license_id INT UNSIGNED NOT NULL,
    threshold_days INT NOT NULL,
    expires_at DATETIME NOT NULL,
    sent_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_license_expiry_alert (license_id, threshold_days, expires_at),
    INDEX idx_license_expiry_alert_sent (sent_at),
    FOREIGN KEY (license_id) REFERENCES licenses(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
