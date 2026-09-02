<?php

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/AuditLog.php';

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

    public static function seatSafetySchemaReady(): bool
    {
        $pdo = Database::pdo();
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        $required = [
            'deactivated_at',
            'deactivated_by',
            'revoked_at',
            'revoked_by',
            'replaced_at',
            'replaced_by',
        ];

        if ($driver === 'mysql') {
            $placeholders = implode(',', array_fill(0, count($required), '?'));
            $stmt = $pdo->prepare(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = ?
                   AND COLUMN_NAME IN ({$placeholders})"
            );
            $stmt->execute(array_merge(['license_activations'], $required));
            return (int) $stmt->fetchColumn() === count($required);
        }

        if ($driver === 'sqlite') {
            $rows = $pdo->query('PRAGMA table_info(license_activations)')->fetchAll(PDO::FETCH_ASSOC);
            $columns = array_column($rows, 'name');
            foreach ($required as $column) {
                if (!in_array($column, $columns, true)) {
                    return false;
                }
            }
            return true;
        }

        return false;
    }

    private static function supportsRowLocking(PDO $pdo): bool
    {
        return $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    }

    private static function lifecycleProjection(string $alias = 'a'): string
    {
        if (self::seatSafetySchemaReady()) {
            return "{$alias}.deactivated_at, {$alias}.deactivated_by,
                    {$alias}.revoked_at, {$alias}.revoked_by,
                    {$alias}.replaced_at, {$alias}.replaced_by";
        }

        return 'NULL AS deactivated_at, NULL AS deactivated_by,
                NULL AS revoked_at, NULL AS revoked_by,
                NULL AS replaced_at, NULL AS replaced_by';
    }

    private static function blockedProjection(string $alias = 'a'): string
    {
        if (self::schemaReady()) {
            return "{$alias}.is_blocked, {$alias}.blocked_at, {$alias}.blocked_by";
        }

        return '0 AS is_blocked, NULL AS blocked_at, NULL AS blocked_by';
    }

    private static function notifyLicenseChange(string $licenseKey): void
    {
        $stmt = Database::pdo()->prepare(
            'INSERT INTO license_change_notifications (license_key) VALUES (?)'
        );
        $stmt->execute([$licenseKey]);
    }

    private static function logSubscriptionEvent(
        int $licenseId,
        string $eventType,
        string $note,
        string $adminUsername
    ): void {
        $stmt = Database::pdo()->prepare(
            'INSERT INTO subscription_events
             (license_id, event_type, note, created_by)
             VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$licenseId, $eventType, mb_substr($note, 0, 255), $adminUsername]);
    }

    private static function logVerification(
        int $licenseId,
        string $licenseKey,
        string $hwid,
        string $result,
        ?string $ip
    ): void {
        $stmt = Database::pdo()->prepare(
            'INSERT INTO verification_log
             (license_id, license_key, hwid, result, ip_address)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$licenseId, $licenseKey, $hwid, $result, $ip]);
    }

    public static function findByLicenseAndHwid(string $licenseKey, string $hwid): ?array
    {
        $pdo = Database::pdo();
        $blocked = self::blockedProjection('a');
        $lifecycle = self::lifecycleProjection('a');

        $stmt = $pdo->prepare(
            "SELECT a.id, a.license_id, a.hwid, a.is_active, a.activated_at,
                    a.last_seen_at, a.ip_address,
                    " . (self::schemaReady()
                        ? 'a.device_name, a.admin_note, a.app_version,'
                        : 'NULL AS device_name, NULL AS admin_note, NULL AS app_version,') . "
                    {$blocked},
                    {$lifecycle},
                    l.license_key
             FROM license_activations a
             JOIN licenses l ON l.id = a.license_id
             WHERE l.license_key = ? AND a.hwid = ?
             LIMIT 1"
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

    public static function isRevokedOrReplaced(string $licenseKey, string $hwid): bool
    {
        $row = self::findByLicenseAndHwid($licenseKey, $hwid);
        if (!$row) {
            return false;
        }
        return !empty($row['revoked_at']) || !empty($row['replaced_at']);
    }

    /**
     * Handles only an EXISTING activation row while holding the same license-row
     * lock used by License::activate() for new devices.
     *
     * Returning null means the caller should continue with License::activate().
     * Returning an array means this method fully handled the request.
     *
     * This closes the old-seat resurrection race: an inactive HWID may not be
     * reactivated until max_activations is checked while the license row is locked.
     */
    public static function activateExistingSafely(
        string $licenseKey,
        string $hwid,
        ?string $ip = null
    ): ?array {
        $pdo = Database::pdo();
        $lockClause = self::supportsRowLocking($pdo) ? ' FOR UPDATE' : '';

        $pdo->beginTransaction();
        try {
            $licenseStmt = $pdo->prepare(
                'SELECT * FROM licenses WHERE license_key = ?' . $lockClause
            );
            $licenseStmt->execute([$licenseKey]);
            $license = $licenseStmt->fetch();

            if (!$license) {
                $pdo->commit();
                return null;
            }

            $blocked = self::blockedProjection('a');
            $lifecycle = self::lifecycleProjection('a');
            $activationStmt = $pdo->prepare(
                "SELECT a.id, a.is_active, {$blocked}, {$lifecycle}
                 FROM license_activations a
                 WHERE a.license_id = ? AND a.hwid = ?
                 LIMIT 1"
            );
            $activationStmt->execute([(int) $license['id'], $hwid]);
            $activation = $activationStmt->fetch();

            if (!$activation) {
                $pdo->commit();
                return null;
            }

            if (!empty($activation['replaced_at'])) {
                self::logVerification((int) $license['id'], $licenseKey, $hwid, 'device_replaced', $ip);
                $pdo->commit();
                return [
                    'ok' => false,
                    'code' => 'device_replaced',
                    'error' => 'This device was replaced and cannot reactivate automatically.',
                ];
            }

            if (!empty($activation['revoked_at'])) {
                self::logVerification((int) $license['id'], $licenseKey, $hwid, 'device_revoked', $ip);
                $pdo->commit();
                return [
                    'ok' => false,
                    'code' => 'device_revoked',
                    'error' => 'This device has been revoked and requires administrator approval.',
                ];
            }

            if (!empty($activation['is_blocked'])) {
                self::logVerification((int) $license['id'], $licenseKey, $hwid, 'device_blocked', $ip);
                $pdo->commit();
                return [
                    'ok' => false,
                    'code' => 'device_blocked',
                    'error' => 'This device has been blocked by the license administrator.',
                ];
            }

            // Active existing devices still use the established License::activate()
            // path so v1 behavior/logging stays unchanged.
            if ((int) $activation['is_active'] === 1) {
                $pdo->commit();
                return null;
            }

            // License::activate() performs these checks for new/active devices.
            // We mirror them only for the previously-unsafe inactive-row path.
            if (($license['status'] ?? '') !== 'active') {
                $pdo->commit();
                return null;
            }
            if (!empty($license['expires_at']) && strtotime((string) $license['expires_at']) < time()) {
                $pdo->commit();
                return null;
            }

            $countStmt = $pdo->prepare(
                'SELECT COUNT(*) FROM license_activations
                 WHERE license_id = ? AND is_active = 1'
            );
            $countStmt->execute([(int) $license['id']]);
            $activeCount = (int) $countStmt->fetchColumn();

            if ($activeCount >= (int) $license['max_activations']) {
                self::logVerification((int) $license['id'], $licenseKey, $hwid, 'activation_limit', $ip);
                $pdo->commit();
                return [
                    'ok' => false,
                    'code' => 'activation_limit',
                    'error' => 'This license has reached its device activation limit.',
                ];
            }

            if (self::seatSafetySchemaReady()) {
                $update = $pdo->prepare(
                    'UPDATE license_activations
                     SET is_active = 1,
                         last_seen_at = CURRENT_TIMESTAMP,
                         ip_address = ?,
                         deactivated_at = NULL,
                         deactivated_by = NULL
                     WHERE id = ?'
                );
            } else {
                $update = $pdo->prepare(
                    'UPDATE license_activations
                     SET is_active = 1,
                         last_seen_at = CURRENT_TIMESTAMP,
                         ip_address = ?
                     WHERE id = ?'
                );
            }
            $update->execute([$ip, (int) $activation['id']]);

            self::logVerification((int) $license['id'], $licenseKey, $hwid, 'ok', $ip);
            $pdo->commit();

            return [
                'ok' => true,
                'license' => $license,
                'reactivated_existing' => true,
            ];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
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
            'UPDATE license_activations
             SET app_version = ?
             WHERE hwid = ?
               AND license_id = (SELECT id FROM licenses WHERE license_key = ? LIMIT 1)'
        );
        $stmt->execute([$version, $hwid, $licenseKey]);
    }

    /** Temporary deactivation: frees a seat but permits a future reactivation
     * only if a seat is available at that future moment. */
    public static function deactivateDevice(
        int $activationId,
        string $adminUsername = 'system'
    ): bool {
        $pdo = Database::pdo();
        $lifecycleReady = self::seatSafetySchemaReady();

        $stmt = $pdo->prepare(
            'SELECT a.id, a.license_id, a.hwid, l.license_key
             FROM license_activations a
             JOIN licenses l ON l.id = a.license_id
             WHERE a.id = ?'
        );
        $stmt->execute([$activationId]);
        $activation = $stmt->fetch();
        if (!$activation) {
            return false;
        }

        $pdo->beginTransaction();
        try {
            if ($lifecycleReady) {
                $update = $pdo->prepare(
                    'UPDATE license_activations
                     SET is_active = 0,
                         deactivated_at = CURRENT_TIMESTAMP,
                         deactivated_by = ?
                     WHERE id = ?'
                );
                $update->execute([$adminUsername, $activationId]);
            } else {
                $update = $pdo->prepare('UPDATE license_activations SET is_active = 0 WHERE id = ?');
                $update->execute([$activationId]);
            }

            self::logSubscriptionEvent(
                (int) $activation['license_id'],
                'device_deactivated',
                'Temporarily deactivated HWID ' . mb_substr((string) $activation['hwid'], 0, 90),
                $adminUsername
            );
            self::notifyLicenseChange((string) $activation['license_key']);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        AuditLog::adminAction(
            'device_deactivated',
            $activationId,
            'License #' . (int) $activation['license_id'] . ' · HWID ' . mb_substr((string) $activation['hwid'], 0, 90)
        );
        return true;
    }

    /** Final device revoke. Unblocking does not clear this lifecycle state. */
    public static function revokeDevice(int $activationId, string $adminUsername): bool
    {
        if (!self::seatSafetySchemaReady()) {
            return false;
        }
        return self::terminalLifecycleTransition(
            $activationId,
            $adminUsername,
            'revoked_at',
            'revoked_by',
            'device_revoked',
            'Revoked HWID '
        );
    }

    /** Replacement workflow: permanently retires the old HWID and frees its
     * seat. The replacement PC then consumes that seat through normal activate.
     * The old HWID cannot silently come back even after the new seat is filled. */
    public static function prepareReplacement(int $activationId, string $adminUsername): bool
    {
        if (!self::seatSafetySchemaReady()) {
            return false;
        }
        return self::terminalLifecycleTransition(
            $activationId,
            $adminUsername,
            'replaced_at',
            'replaced_by',
            'device_replacement_prepared',
            'Prepared replacement for old HWID '
        );
    }

    private static function terminalLifecycleTransition(
        int $activationId,
        string $adminUsername,
        string $timestampColumn,
        string $actorColumn,
        string $eventType,
        string $notePrefix
    ): bool {
        $allowedTimestamp = ['revoked_at', 'replaced_at'];
        $allowedActor = ['revoked_by', 'replaced_by'];
        if (!in_array($timestampColumn, $allowedTimestamp, true)
            || !in_array($actorColumn, $allowedActor, true)) {
            throw new InvalidArgumentException('Invalid lifecycle transition.');
        }

        $pdo = Database::pdo();
        $stmt = $pdo->prepare(
            'SELECT a.id, a.license_id, a.hwid, l.license_key
             FROM license_activations a
             JOIN licenses l ON l.id = a.license_id
             WHERE a.id = ?'
        );
        $stmt->execute([$activationId]);
        $activation = $stmt->fetch();
        if (!$activation) {
            return false;
        }

        $pdo->beginTransaction();
        try {
            $update = $pdo->prepare(
                "UPDATE license_activations
                 SET is_active = 0,
                     {$timestampColumn} = CURRENT_TIMESTAMP,
                     {$actorColumn} = ?,
                     deactivated_at = COALESCE(deactivated_at, CURRENT_TIMESTAMP),
                     deactivated_by = COALESCE(deactivated_by, ?)
                 WHERE id = ?"
            );
            $update->execute([$adminUsername, $adminUsername, $activationId]);

            self::logSubscriptionEvent(
                (int) $activation['license_id'],
                $eventType,
                $notePrefix . mb_substr((string) $activation['hwid'], 0, 90),
                $adminUsername
            );
            self::notifyLicenseChange((string) $activation['license_key']);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        AuditLog::adminAction(
            $eventType,
            $activationId,
            'License #' . (int) $activation['license_id'] . ' · HWID ' . mb_substr((string) $activation['hwid'], 0, 90)
        );
        return true;
    }

    public static function setBlocked(int $activationId, bool $blocked, string $adminUsername): bool
    {
        if (!self::schemaReady()) {
            return false;
        }

        $pdo = Database::pdo();
        $stmt = $pdo->prepare(
            'SELECT a.id, a.license_id, a.hwid, l.license_key
             FROM license_activations a
             JOIN licenses l ON l.id = a.license_id
             WHERE a.id = ?'
        );
        $stmt->execute([$activationId]);
        $activation = $stmt->fetch();
        if (!$activation) {
            return false;
        }

        $pdo->beginTransaction();
        try {
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

            self::logSubscriptionEvent(
                (int) $activation['license_id'],
                $eventType,
                $note,
                $adminUsername
            );
            self::notifyLicenseChange((string) $activation['license_key']);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        AuditLog::adminAction(
            $eventType,
            $activationId,
            'License #' . (int) $activation['license_id'] . ' · HWID ' . mb_substr((string) $activation['hwid'], 0, 90)
        );
        return true;
    }
}
