-- Hercule License Server — Phase 4 schema (MySQL)
-- Idempotent: safe to run against an existing DB, migrate.php wraps this
-- with CREATE TABLE IF NOT EXISTS semantics.

CREATE TABLE IF NOT EXISTS admin_users (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username        VARCHAR(64) NOT NULL UNIQUE,
    password_hash   VARCHAR(255) NOT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS login_attempts (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username        VARCHAR(64) NOT NULL,
    ip_address      VARCHAR(45) NOT NULL,
    success         TINYINT(1) NOT NULL DEFAULT 0,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_login_attempts_lookup (username, ip_address, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Rate limiting for the public API endpoints (activate.php / validate.php /
-- check_update.php). Separate from login_attempts since these aren't login
-- attempts at all — just a generic "how many times has this IP/key hit
-- this endpoint recently".
CREATE TABLE IF NOT EXISTS api_requests (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ip_address      VARCHAR(45) NOT NULL,
    endpoint        VARCHAR(30) NOT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_api_requests_lookup (ip_address, endpoint, created_at)
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
    license_key         VARCHAR(29) NOT NULL UNIQUE, -- format XXXX-XXXX-XXXX-XXXX-XXXX
    plan                ENUM('trial','monthly','semi_annual','annual','custom','lifetime') NOT NULL,
    status              ENUM('active','suspended','revoked','expired') NOT NULL DEFAULT 'active',
    max_activations     INT UNSIGNED NOT NULL DEFAULT 1,
    issued_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at          DATETIME NULL, -- NULL = lifetime, never expires
    last_verified_at    DATETIME NULL,
    notes               TEXT,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
    INDEX idx_licenses_status (status),
    INDEX idx_licenses_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS license_activations (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    license_id      INT UNSIGNED NOT NULL,
    hwid            VARCHAR(128) NOT NULL,
    is_active       TINYINT(1) NOT NULL DEFAULT 1,
    activated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_seen_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ip_address      VARCHAR(45),
    FOREIGN KEY (license_id) REFERENCES licenses(id) ON DELETE CASCADE,
    UNIQUE KEY uq_license_hwid (license_id, hwid),
    INDEX idx_activations_license (license_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS verification_log (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    license_id      INT UNSIGNED NULL,
    license_key     VARCHAR(29) NOT NULL,
    hwid            VARCHAR(128),
    result          VARCHAR(30) NOT NULL, -- ok | invalid_key | hwid_mismatch | expired | suspended | revoked | activation_limit
    ip_address      VARCHAR(45),
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_verification_log_license (license_id),
    INDEX idx_verification_log_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Subscription lifecycle events — renewals, plan changes, manual admin adjustments.
-- Not wired to a payment processor yet (Phase 4 scope is the server + admin panel);
-- this table is where a future Stripe/Paddle webhook handler would write rows.
CREATE TABLE IF NOT EXISTS subscription_events (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    license_id      INT UNSIGNED NOT NULL,
    event_type      VARCHAR(30) NOT NULL, -- issued | renewed | plan_changed | suspended | revoked | reactivated
    previous_expires_at DATETIME NULL,
    new_expires_at  DATETIME NULL,
    note            VARCHAR(255),
    created_by      VARCHAR(64), -- admin username, or 'system' for automated events
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (license_id) REFERENCES licenses(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- License System Upgrade Plan — Phase 6 (Realtime Notification)
-- ============================================================
-- A deliberately "dumb" table: it carries NO trusted license state (per
-- the plan's §12 security principle — a notification must never itself
-- grant a privilege), just "something about this license changed, go
-- re-validate for real." The desktop app polls check_update.php (cheap,
-- unsigned, indexed boolean lookup) far more often than it would ever
-- want to run a full signed validate.php round trip; a hit there triggers
-- one FORCED validate.php call, whose signed response is the only thing
-- ever actually trusted. validate.php marks matching rows consumed as a
-- side effect of that forced call, so this table self-clears without a
-- separate "I saw it" round trip from the client.
CREATE TABLE IF NOT EXISTS license_change_notifications (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    license_key     VARCHAR(29) NOT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    consumed_at     DATETIME NULL,
    INDEX idx_notif_key_pending (license_key, consumed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
