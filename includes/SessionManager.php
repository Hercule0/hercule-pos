<?php

require_once __DIR__ . '/Database.php';

final class SessionManager
{
    public static function visibleFor(int $adminId, bool $isOwner): array
    {
        $pdo = Database::pdo();
        if ($isOwner) {
            return $pdo->query(
                'SELECT us.id, us.admin_id, us.user_agent, us.ip_address, us.expires_at, us.created_at, au.username, au.role
                 FROM user_sessions us JOIN admin_users au ON au.id = us.admin_id
                 ORDER BY us.created_at DESC'
            )->fetchAll() ?: [];
        }

        $stmt = $pdo->prepare(
            'SELECT us.id, us.admin_id, us.user_agent, us.ip_address, us.expires_at, us.created_at, au.username, au.role
             FROM user_sessions us JOIN admin_users au ON au.id = us.admin_id
             WHERE us.admin_id = ? ORDER BY us.created_at DESC'
        );
        $stmt->execute([$adminId]);
        return $stmt->fetchAll() ?: [];
    }

    public static function revokeOne(int $sessionId, int $adminId, bool $isOwner): int
    {
        if ($sessionId <= 0) return 0;
        $pdo = Database::pdo();

        $targetStmt = $pdo->prepare('SELECT admin_id FROM user_sessions WHERE id = ?');
        $targetStmt->execute([$sessionId]);
        $targetAdminId = $targetStmt->fetchColumn();
        if ($targetAdminId === false) return 0;
        $targetAdminId = (int) $targetAdminId;

        if (!$isOwner && $targetAdminId !== $adminId) {
            return 0;
        }

        $stmt = $pdo->prepare('DELETE FROM user_sessions WHERE id = ?');
        $stmt->execute([$sessionId]);
        $count = $stmt->rowCount();
        if ($count > 0) {
            self::audit($adminId, $targetAdminId, 'session_revoked', 'Remembered session #' . $sessionId . ' revoked');
        }
        return $count;
    }

    public static function revokeOwn(int $adminId): int
    {
        $stmt = Database::pdo()->prepare('DELETE FROM user_sessions WHERE admin_id = ?');
        $stmt->execute([$adminId]);
        $count = $stmt->rowCount();
        if ($count > 0) {
            self::audit($adminId, $adminId, 'sessions_revoked_own', 'Revoked ' . $count . ' remembered session(s)');
        }
        return $count;
    }

    public static function revokeAll(int $adminId): int
    {
        $count = Database::pdo()->exec('DELETE FROM user_sessions');
        $count = $count === false ? 0 : (int) $count;
        if ($count > 0) {
            self::audit($adminId, null, 'sessions_revoked_all', 'Revoked ' . $count . ' remembered administrator session(s)');
        }
        return $count;
    }

    private static function audit(int $actorId, ?int $targetId, string $action, string $details): void
    {
        try {
            $stmt = Database::pdo()->prepare(
                'INSERT INTO admin_audit_log (actor_id, target_id, action, details, ip_address) VALUES (?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $actorId,
                $targetId,
                $action,
                mb_substr($details, 0, 255),
                $_SERVER['REMOTE_ADDR'] ?? null,
            ]);
        } catch (Throwable $e) {
            // Session revocation is a security action and must not fail just because
            // audit persistence is temporarily unavailable during rollout.
            error_log('Session audit write failed: ' . $e->getMessage());
        }
    }
}
