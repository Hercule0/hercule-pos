<?php
/**
 * Central helper for resolving a role permission through per-admin overrides.
 * Kept separate so Auth can call one small, testable bridge.
 */
require_once __DIR__ . '/PermissionResolver.php';

final class AuthPermissionBridge
{
    public static function resolve(?int $adminId, string $permission, bool $roleDefault, string $role): bool
    {
        // Owners retain full access by design; overrides cannot lock out the owner.
        if ($role === 'owner') {
            return true;
        }

        if (!$adminId) {
            return $roleDefault;
        }

        return PermissionResolver::resolve($adminId, $permission, $roleDefault);
    }
}
