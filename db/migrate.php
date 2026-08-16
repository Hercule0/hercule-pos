<?php
/**
 * Standalone upgrade migration for the Password Recovery Request System
 * (see PASSWORD_RECOVERY_REQUEST_PLAN.md).
 *
 * Adds ONLY the two new tables this feature needs:
 *   - password_recovery_requests
 *   - recovery_audit_log
 *
 * Safe to run against your existing, already-live database:
 *   - Uses CREATE TABLE IF NOT EXISTS, so it never touches or drops
 *     anything that already exists.
 *   - Does not modify any existing table (licenses, customers, etc).
 *   - Safe to run more than once — the second run just does nothing.
 *
 * Usage:
 *   php db/migrate_recovery.php
 *
 * This does the same thing as re-running the full `php db/migrate.php`
 * after deploying the updated db/schema.sql — use whichever you prefer.
 * This one exists so you can upgrade an existing deployment with a single
 * small, obviously-scoped script instead of re-running the whole schema.
 */

require_once __DIR__ . '/../includes/Database.php';

function run(): void
{
    $pdo = Database::pdo();

    echo "Connecting to database... OK\n";

    $statements = [
        'password_recovery_requests' => "
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
                INDEX idx_recovery_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ",
        'recovery_audit_log' => "
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ",
    ];

    foreach ($statements as $tableName => $sql) {
        try {
            $pdo->exec($sql);
            echo "OK  - {$tableName} (created if it didn't already exist)\n";
        } catch (PDOException $e) {
            fwrite(STDERR, "FAILED creating {$tableName}: " . $e->getMessage() . "\n");
            exit(1);
        }
    }

    $retentionIndexes = [
        ['login_attempts', 'idx_login_attempts_created', 'created_at'],
        ['api_requests', 'idx_api_requests_created', 'created_at'],
        ['recovery_audit_log', 'idx_recovery_audit_created', 'created_at'],
    ];

    foreach ($retentionIndexes as [$tableName, $indexName, $columnName]) {
        $check = $pdo->prepare(
            'SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?'
        );
        $check->execute([$tableName, $indexName]);

        if ((int) $check->fetchColumn() === 0) {
            $pdo->exec("ALTER TABLE {$tableName} ADD INDEX {$indexName} ({$columnName})");
            echo "OK  - {$indexName} added to {$tableName}\n";
        } else {
            echo "OK  - {$indexName} already exists\n";
        }
    }

    // Sanity check: confirm both tables are actually queryable now.
    foreach (array_keys($statements) as $tableName) {
        try {
            $pdo->query("SELECT 1 FROM {$tableName} LIMIT 1");
            echo "VERIFIED - {$tableName} is queryable\n";
        } catch (PDOException $e) {
            fwrite(STDERR, "VERIFY FAILED for {$tableName}: " . $e->getMessage() . "\n");
            exit(1);
        }
    }

    echo "\nRecovery tables migration complete.\n";
    echo "You can now use /public/api/recovery_*.php and /public/admin/recovery_requests.php.\n";
}

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

run();
