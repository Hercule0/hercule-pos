<?php
/**
 * Hercule License Server — Database Migration Script
 * يقوم بإنشاء أو تحديث كافة جداول قاعدة البيانات بشكل آمن (Idempotent).
 */

require_once __DIR__ . '/../includes/Database.php'; // تم تعديل المسار هنا بالصعود لالمجلد الرئيسي

try {
    $pdo = Database::pdo();

    // إيقاف تدقيق المفاتيح الأجنبية مؤقتاً لتسهيل الإنشاء
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 0;");

    // 1. جدول مدراء السيرفر
    $pdo->exec("CREATE TABLE IF NOT EXISTS admin_users (
        id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        username        VARCHAR(64) NOT NULL UNIQUE,
        password_hash   VARCHAR(255) NOT NULL,
        created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    // 2. جدول محاولات تسجيل الدخول
    $pdo->exec("CREATE TABLE IF NOT EXISTS login_attempts (
        id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        username        VARCHAR(64) NOT NULL,
        ip_address      VARCHAR(45) NOT NULL,
        success         TINYINT(1) NOT NULL DEFAULT 0,
        created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_login_attempts_lookup (username, ip_address, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    // 3. جدول مراقبة ومعدل الطلبات للـ API
    $pdo->exec("CREATE TABLE IF NOT EXISTS api_requests (
        id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        ip_address      VARCHAR(45) NOT NULL,
        endpoint        VARCHAR(30) NOT NULL,
        created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_api_requests_lookup (ip_address, endpoint, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    // 4. جدول الزبائن
    $pdo->exec("CREATE TABLE IF NOT EXISTS customers (
        id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        name            VARCHAR(150) NOT NULL,
        email           VARCHAR(150),
        phone           VARCHAR(30),
        notes           TEXT,
        created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_customers_email (email)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    // 5. جدول التراخيص
    $pdo->exec("CREATE TABLE IF NOT EXISTS licenses (
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
        INDEX idx_licenses_expires (expires_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    // 6. جدول تفعيل الأجهزة
    $pdo->exec("CREATE TABLE IF NOT EXISTS license_activations (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    // 7. جدول سجل عمليات التحقق
    $pdo->exec("CREATE TABLE IF NOT EXISTS verification_log (
        id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        license_id      INT UNSIGNED NULL,
        license_key     VARCHAR(29) NOT NULL,
        hwid            VARCHAR(128),
        result          VARCHAR(30) NOT NULL,
        ip_address      VARCHAR(45),
        created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_verification_log_license (license_id),
        INDEX idx_verification_log_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    // 8. جدول أحداث دورة حياة الاشتراك
    $pdo->exec("CREATE TABLE IF NOT EXISTS subscription_events (
        id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        license_id      INT UNSIGNED NOT NULL,
        event_type      VARCHAR(30) NOT NULL,
        previous_expires_at DATETIME NULL,
        new_expires_at  DATETIME NULL,
        note            VARCHAR(255),
        created_by      VARCHAR(64),
        created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (license_id) REFERENCES licenses(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    // 9. جدول الإشعارات الفورية للتراخيص (Phase 6)
    $pdo->exec("CREATE TABLE IF NOT EXISTS license_change_notifications (
        id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        license_key     VARCHAR(29) NOT NULL,
        created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        consumed_at     DATETIME NULL,
        INDEX idx_notif_key_pending (license_key, consumed_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    // 10. جدول طلبات استعادة كلمة المرور
    $pdo->exec("CREATE TABLE IF NOT EXISTS password_recovery_requests (
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
        INDEX idx_recovery_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    // 11. جدول سجل تدقيق الاستعادة (Audit Trail)
    $pdo->exec("CREATE TABLE IF NOT EXISTS recovery_audit_log (
        id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        request_id      INT UNSIGNED NULL,
        event_type      VARCHAR(40) NOT NULL,
        actor           VARCHAR(64) NULL,
        ip_address      VARCHAR(45) NULL,
        note            VARCHAR(255) NULL,
        created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_recovery_audit_request (request_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    // اعادة تفعيل المفاتيح الأجنبية
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 1;");

    echo "تمت عملية الهجرة (Migration) وتحديث قاعدة البيانات بنجاح تام!";

} catch (\Throwable $e) {
    echo "حدث خطأ أثناء عملية الهجرة: " . $e->getMessage();
}
