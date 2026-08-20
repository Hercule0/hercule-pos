<?php

require_once __DIR__ . '/Database.php';

final class AuditLog
{
    public static function write(
        string $action,
        ?int $actorId = null,
        ?int $targetId = null,
        ?string $details = null,
        ?string $ipAddress = null
    ): void {
        try {
            $action = mb_substr(trim($action), 0, 40);
            if ($action === '') return;

            $details = $details !== null ? mb_substr(trim($details), 0, 255) : null;
            if ($details === '') $details = null;
            $ipAddress = $ipAddress ?: ($_SERVER['REMOTE_ADDR'] ?? null);

            $stmt = Database::pdo()->prepare(
                'INSERT INTO admin_audit_log (actor_id, target_id, action, details, ip_address)
                 VALUES (?, ?, ?, ?, ?)'
            );
            $stmt->execute([$actorId, $targetId, $action, $details, $ipAddress]);
        } catch (Throwable $e) {
            error_log('AuditLog write failed: ' . $e->getMessage());
        }
    }

    public static function actorId(): ?int
    {
        if (session_status() !== PHP_SESSION_ACTIVE) return null;
        return isset($_SESSION['admin_id']) ? (int) $_SESSION['admin_id'] : null;
    }

    public static function adminAction(string $action, ?int $targetId = null, ?string $details = null): void
    {
        self::write($action, self::actorId(), $targetId, $details);
    }
}
