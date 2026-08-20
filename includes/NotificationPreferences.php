<?php
require_once __DIR__ . '/Database.php';

final class NotificationPreferences
{
    private const DEFAULTS = [
        'activation' => 1,
        'recovery' => 1,
        'expiry' => 1,
        'security' => 1,
        'system' => 1,
    ];

    public static function get(string $adminUsername): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT activation_enabled, recovery_enabled, expiry_enabled, security_enabled, system_enabled, muted_until
             FROM admin_notification_preferences WHERE admin_username = ? LIMIT 1'
        );
        $stmt->execute([$adminUsername]);
        $row = $stmt->fetch();

        if (!$row) {
            return self::DEFAULTS + ['muted_until' => null];
        }

        return [
            'activation' => (int) $row['activation_enabled'],
            'recovery' => (int) $row['recovery_enabled'],
            'expiry' => (int) $row['expiry_enabled'],
            'security' => (int) $row['security_enabled'],
            'system' => (int) $row['system_enabled'],
            'muted_until' => $row['muted_until'],
        ];
    }

    public static function save(string $adminUsername, array $values, ?string $mutedUntil): void
    {
        $stmt = Database::pdo()->prepare(
            'INSERT INTO admin_notification_preferences
                (admin_username, activation_enabled, recovery_enabled, expiry_enabled, security_enabled, system_enabled, muted_until)
             VALUES (?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                activation_enabled = VALUES(activation_enabled),
                recovery_enabled = VALUES(recovery_enabled),
                expiry_enabled = VALUES(expiry_enabled),
                security_enabled = VALUES(security_enabled),
                system_enabled = VALUES(system_enabled),
                muted_until = VALUES(muted_until),
                updated_at = CURRENT_TIMESTAMP'
        );
        $stmt->execute([
            $adminUsername,
            !empty($values['activation']) ? 1 : 0,
            !empty($values['recovery']) ? 1 : 0,
            !empty($values['expiry']) ? 1 : 0,
            !empty($values['security']) ? 1 : 0,
            !empty($values['system']) ? 1 : 0,
            $mutedUntil,
        ]);
    }

    public static function allows(string $adminUsername, string $eventType): bool
    {
        $prefs = self::get($adminUsername);
        if (!empty($prefs['muted_until']) && strtotime((string) $prefs['muted_until']) > time()) {
            return false;
        }

        return !empty($prefs[$eventType] ?? 1);
    }
}
