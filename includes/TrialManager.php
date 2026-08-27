<?php

declare(strict_types=1);

require_once __DIR__ . '/Database.php';

/**
 * Server-authoritative 10-day trial binding.
 *
 * Only a SHA-256 digest of the desktop HWID is persisted. Deleting local
 * Electron userData therefore cannot start another trial on the same machine.
 */
final class TrialManager
{
    private const TRIAL_DAYS = 10;

    public static function ensureSchema(): void
    {
        Database::pdo()->exec(
            "CREATE TABLE IF NOT EXISTS trial_devices (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                hwid_hash CHAR(64) NOT NULL UNIQUE,
                status ENUM('active','blocked') NOT NULL DEFAULT 'active',
                started_at DATETIME NOT NULL,
                expires_at DATETIME NOT NULL,
                last_seen_at DATETIME NOT NULL,
                first_ip VARCHAR(64) NULL,
                last_ip VARCHAR(64) NULL,
                app_version VARCHAR(50) NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_trial_expires (expires_at),
                INDEX idx_trial_status (status, expires_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    private static function hashHwid(string $hwid): string
    {
        return hash('sha256', $hwid);
    }

    /**
     * @return array{status:string,plan:string,error?:string,expires_at:string,server_time:string}
     */
    public static function status(string $hwid, ?string $ip, string $appVersion = ''): array
    {
        self::ensureSchema();
        $pdo = Database::pdo();
        $hash = self::hashHwid($hwid);
        $pdo->beginTransaction();

        try {
            $lock = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
            $stmt = $pdo->prepare('SELECT * FROM trial_devices WHERE hwid_hash = ? LIMIT 1' . $lock);
            $stmt->execute([$hash]);
            $row = $stmt->fetch();
            $now = new DateTimeImmutable('now');

            if (!$row) {
                $started = $now->format('Y-m-d H:i:s');
                $expires = $now->modify('+' . self::TRIAL_DAYS . ' days')->format('Y-m-d H:i:s');
                $insert = $pdo->prepare(
                    'INSERT INTO trial_devices
                     (hwid_hash,status,started_at,expires_at,last_seen_at,first_ip,last_ip,app_version)
                     VALUES (?,\'active\',?,?,?,?,?,?)'
                );
                $insert->execute([
                    $hash,
                    $started,
                    $expires,
                    $started,
                    $ip,
                    $ip,
                    mb_substr($appVersion, 0, 50),
                ]);
                $row = [
                    'status' => 'active',
                    'started_at' => $started,
                    'expires_at' => $expires,
                ];
            } else {
                $update = $pdo->prepare(
                    'UPDATE trial_devices
                     SET last_seen_at=CURRENT_TIMESTAMP,last_ip=?,app_version=?
                     WHERE hwid_hash=?'
                );
                $update->execute([$ip, mb_substr($appVersion, 0, 50), $hash]);
            }

            $pdo->commit();

            $blocked = ($row['status'] ?? '') !== 'active';
            $expired = strtotime((string)$row['expires_at']) <= time();
            $serverTime = gmdate('Y-m-d\TH:i:s\Z');

            if ($blocked) {
                return [
                    'status' => 'invalid',
                    'plan' => 'trial',
                    'error' => 'This trial device has been blocked.',
                    'expires_at' => (string)$row['expires_at'],
                    'server_time' => $serverTime,
                ];
            }

            if ($expired) {
                return [
                    'status' => 'expired',
                    'plan' => 'trial',
                    'error' => 'Your 10-day trial has ended. Please enter a license key.',
                    'expires_at' => (string)$row['expires_at'],
                    'server_time' => $serverTime,
                ];
            }

            return [
                'status' => 'trial',
                'plan' => 'trial',
                'expires_at' => (string)$row['expires_at'],
                'server_time' => $serverTime,
            ];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }
}
