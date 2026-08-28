<?php
/** Dedicated authorization bridge for the Support & Feedback center. */

require_once __DIR__ . '/Auth.php';
require_once __DIR__ . '/AuthPermissionBridge.php';

final class SupportAccess
{
    public const MANAGE_PERMISSION = 'support.manage';

    public static function canManage(): bool
    {
        if (!Auth::isLoggedIn()) {
            return false;
        }

        $role = Auth::currentRole();
        $roleDefault = $role === 'owner' || $role === 'support';

        return AuthPermissionBridge::resolve(
            Auth::currentUserId(),
            self::MANAGE_PERMISSION,
            $roleDefault,
            $role
        );
    }

    public static function requireManage(): void
    {
        Auth::require();
        if (!self::canManage()) {
            http_response_code(403);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'You do not have permission to manage support tickets.';
            exit;
        }
    }
}
