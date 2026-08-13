<?php
/**
 * Idempotent migration runner. Safe to run on every deploy — schema.sql
 * uses CREATE TABLE IF NOT EXISTS throughout, so re-running never errors
 * or duplicates anything.
 *
 * Usage: php db/migrate.php
 */

require_once __DIR__ . '/../includes/Database.php';

function run(): void
{
    $pdo = Database::pdo();
    $schema = file_get_contents(__DIR__ . '/schema.sql');

    if ($schema === false) {
        fwrite(STDERR, "Could not read schema.sql\n");
        exit(1);
    }

    try {
        $pdo->exec($schema);
        echo "Migration complete.\n";
    } catch (PDOException $e) {
        fwrite(STDERR, 'Migration failed: ' . $e->getMessage() . "\n");
        exit(1);
    }

    // Widen the plan ENUM to include 'custom' for deployments that were
    // created before the custom-duration feature existed. Wrapped in
    // try/catch: harmless if it's already applied, and not applicable at
    // all on SQLite (used only in tests), where plan is a plain TEXT column.
    try {
        $pdo->exec("ALTER TABLE licenses MODIFY COLUMN plan
            ENUM('trial','monthly','semi_annual','annual','custom','lifetime') NOT NULL");
        echo "Widened licenses.plan ENUM to include 'custom'.\n";
    } catch (PDOException $e) {
        // Already applied, or this DB driver doesn't support ENUM (SQLite) — ignore.
    }

    // License System Upgrade Plan — Phase 6 (Realtime Notification).
    // The CREATE TABLE IF NOT EXISTS in schema.sql (executed above)
    // already creates this table on its own, so this explicit block is
    // technically redundant on a fresh run — it's here purely so the
    // migration LOG makes it obvious, at a glance, exactly which release
    // introduced this table, the same way the ENUM widen above documents
    // itself. Safe to run against a database that already has the table
    // (IF NOT EXISTS), and safe against one that doesn't yet.
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS license_change_notifications (
            id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            license_key     VARCHAR(29) NOT NULL,
            created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            consumed_at     DATETIME NULL,
            INDEX idx_notif_key_pending (license_key, consumed_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        echo "Ensured license_change_notifications table exists (Phase 6 — Realtime Notification).\n";
    } catch (PDOException $e) {
        fwrite(STDERR, 'Failed to ensure license_change_notifications table: ' . $e->getMessage() . "\n");
        exit(1);
    }

    // Seed a default admin user if none exists yet, so the panel is
    // reachable on first deploy. CHANGE THIS PASSWORD IMMEDIATELY.
    $count = (int) $pdo->query('SELECT COUNT(*) FROM admin_users')->fetchColumn();
    if ($count === 0) {
        $defaultPassword = bin2hex(random_bytes(8)); // random, not a guessable default
        $stmt = $pdo->prepare('INSERT INTO admin_users (username, password_hash) VALUES (?, ?)');
        $stmt->execute(['admin', password_hash($defaultPassword, PASSWORD_DEFAULT)]);
        echo "Created default admin user:\n";
        echo "  username: admin\n";
        echo "  password: {$defaultPassword}\n";
        echo "Log in and consider this password already burned — it's printed to your terminal/logs.\n";
    }
}

if (php_sapi_name() === 'cli') {
    run();
}
