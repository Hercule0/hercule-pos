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
        if ($isOwner) {
            $stmt = $pdo->prepare('DELETE FROM user_sessions WHERE id = ?');
            $stmt->execute([$sessionId]);
        } else {
            $stmt = $pdo->prepare('DELETE FROM user_sessions WHERE id = ? AND admin_id = ?');
            $stmt->execute([$sessionId, $adminId]);
        }
        return $stmt->rowCount();
    }

    public static function revokeOwn(int $adminId): int
    {
        $stmt = Database::pdo()->prepare('DELETE FROM user_sessions WHERE admin_id = ?');
        $stmt->execute([$adminId]);
        return $stmt->rowCount();
    }

    public static function revokeAll(): int
    {
        return Database::pdo()->exec('DELETE FROM user_sessions');
    }
}
