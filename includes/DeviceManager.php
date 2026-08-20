<?php

require_once __DIR__ . '/Database.php';

final class DeviceManager
{
    public static function schemaReady(): bool
    {
        $pdo = Database::pdo();
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
            return true;
        }

        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND COLUMN_NAME IN (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            'license_activations',
            'device_name',
            'admin_note',
            'app_version',
            'is_blocked',
            'blocked_at',
            'blocked_by',
        ]);

        return (int) $stmt->fetchColumn() === 6;
    }

    public static function findByLicenseAndHwid(string $licenseKey, string $hwid): ?array
    {
        if (!self::schemaReady()) {
            return null;
        }

        $stmt = Database::pdo()->prepare(
            'SELECT a.*, l.license_key
             FROM license_activations a
             JOIN licenses l ON l.id = a.license_id
             WHERE l.license_key = ? AND a.hwid = ?
             LIMIT 1'
        );
        $stmt->execute([$licenseKey, $hwid]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function isBlocked(string $licenseKey, string $hwid): bool
    {
        $row = self::findByLicenseAndHwid($licenseKey, $hwid);
        return $row ? (bool) $row['is_blocked'] : false;
    }

    public static function recordClientVersion(string $licenseKey, string $hwid, ?string $version): void
    {
        if (!self::schemaReady()) {
            return;
        }

        $version = $version !== null ? trim($version) : '';
        if ($version === '') {
            return;
        }

        $version = mb_substr($version, 0, 50);
        $stmt = Database::pdo()->prepare(
            'UPDATE license_activations a
             JOIN licenses l ON l.id = a.license_id
             SET a.app_version = ?
             WHERE l.license_key = ? AND a.hwid = ?'
        );
        $stmt->execute([$version, $licenseKey, $hwid]);
    }

    public static function setBlocked(int $activationId, bool $blocked, string $adminUsername): bool
    {
        if (!self::schemaReady()) {
            return false;
        }

        $pdo = Database::pdo();
        $stmt = $pdo->prepare(
            'SELECT a.id, a.license_id, a.hwid
             FROM license_activations a
             WHERE a.id = ?'
        );
        $stmt->execute([$activationId]);
        $activation = $stmt->fetch();
        if (!$activation) {
            return false;
        }

        if ($blocked) {
            $update = $pdo->prepare(
                'UPDATE license_activations
                 SET is_blocked = 1, blocked_at = CURRENT_TIMESTAMP, blocked_by = ?
                 WHERE id = ?'
            );
            $update->execute([$adminUsername, $activationId]);
            $eventType = 'device_blocked';
            $note = 'Blocked device HWID ' . mb_substr((string) $activation['hwid'], 0, 90);
        } else {
            $update = $pdo->prepare(
                'UPDATE license_activations
                 SET is_blocked = 0, blocked_at = NULL, blocked_by = NULL
                 WHERE id = ?'
            );
            $update->execute([$activationId]);
            $eventType = 'device_unblocked';
            $note = 'Unblocked device HWID ' . mb_substr((string) $activation['hwid'], 0, 90);
        }

        $event = $pdo->prepare(
            'INSERT INTO subscription_events
             (license_id, event_type, note, created_by)
             VALUES (?, ?, ?, ?)'
        );
        $event->execute([(int) $activation['license_id'], $eventType, $note, $adminUsername]);
        return true;
    }
}
