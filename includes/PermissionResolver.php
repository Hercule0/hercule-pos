<?php
/**
 * Resolves per-admin permission overrides on top of role defaults.
 * Missing table / rollout failures deliberately fall back to role defaults.
 */
final class PermissionResolver
{
    /** @var array<string,bool> */
    private static array $cache = [];

    public static function resolve(int $adminId, string $permission, bool $roleDefault): bool
    {
        // Include the current role default in the cache key. A live administrator
        // session can have its role refreshed from the database during the same
        // request/process, so caching only admin+permission can otherwise retain
        // an allow/deny decision from the previous role.
        $key = $adminId . ':' . $permission . ':' . ($roleDefault ? '1' : '0');
        if (array_key_exists($key, self::$cache)) {
            return self::$cache[$key];
        }

        try {
            $stmt = Database::pdo()->prepare(
                'SELECT allowed FROM admin_permission_overrides WHERE admin_id = ? AND permission = ? LIMIT 1'
            );
            $stmt->execute([$adminId, $permission]);
            $value = $stmt->fetchColumn();
            if ($value !== false) {
                return self::$cache[$key] = ((int) $value === 1);
            }
        } catch (Throwable $e) {
            // Migration may not have run yet. Keep the existing role behavior.
        }

        return self::$cache[$key] = $roleDefault;
    }

    public static function clearCache(): void
    {
        self::$cache = [];
    }
}
