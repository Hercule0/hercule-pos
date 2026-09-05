<?php
/**
 * Hercule Multi-Cashier Phase 1 / Fix408.
 *
 * Trusted entitlement/device-seat logic shared by the v2 public API and the
 * legacy v1 activation compatibility guard. The v1 response contract remains
 * unchanged; only its unsafe inactive-HWID reactivation path is gated here.
 */

require_once __DIR__ . '/Database.php';

final class EntitlementV2
{
    private const DEVICE_ROLES = [
        'single_terminal',
        'manager_server',
        'manager_terminal',
        'cashier_terminal',
        'management_only',
    ];

    public static function schemaReady(): bool
    {
        return self::hasColumn('licenses', 'license_uuid')
            && self::hasColumn('licenses', 'store_uuid')
            && self::hasColumn('licenses', 'entitlement_version')
            && self::hasColumn('license_activations', 'device_uuid')
            && self::hasColumn('license_activations', 'revoked_at');
    }

    public static function withSeatLock(string $licenseKey, callable $callback)
    {
        $pdo = Database::pdo();
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
            return $callback();
        }

        $lockName = 'hercule-seat-' . substr(hash('sha256', $licenseKey), 0, 40);
        $stmt = $pdo->prepare('SELECT GET_LOCK(?, 10)');
        $stmt->execute([$lockName]);
        if ((int) $stmt->fetchColumn() !== 1) {
            throw new RuntimeException('Unable to acquire license seat lock.');
        }

        try {
            return $callback();
        } finally {
            try {
                $release = $pdo->prepare('SELECT RELEASE_LOCK(?)');
                $release->execute([$lockName]);
            } catch (Throwable $ignored) {
            }
        }
    }

    /**
     * Fixes the legacy v1 hole without changing its signed response format:
     * an existing inactive HWID may only reactivate when a seat is free.
     * A final-revoked activation can never silently come back through v1.
     */
    public static function preflightLegacyActivation(string $licenseKey, string $hwid): array
    {
        $pdo = Database::pdo();
        $stmt = $pdo->prepare('SELECT * FROM licenses WHERE license_key = ? LIMIT 1');
        $stmt->execute([$licenseKey]);
        $license = $stmt->fetch();
        if (!$license) {
            return ['ok' => true]; // Legacy License::activate owns invalid-key response.
        }

        $columns = self::schemaReady() ? ', revoked_at' : '';
        $existingStmt = $pdo->prepare(
            "SELECT id, is_active{$columns} FROM license_activations WHERE license_id = ? AND hwid = ? LIMIT 1"
        );
        $existingStmt->execute([(int) $license['id'], $hwid]);
        $existing = $existingStmt->fetch();
        if (!$existing) {
            return ['ok' => true];
        }

        if (self::schemaReady() && !empty($existing['revoked_at'])) {
            return ['ok' => false, 'error' => 'This device has been permanently revoked.'];
        }

        if ((int) $existing['is_active'] === 1) {
            return ['ok' => true];
        }

        $countStmt = $pdo->prepare(
            'SELECT COUNT(*) FROM license_activations WHERE license_id = ? AND is_active = 1'
        );
        $countStmt->execute([(int) $license['id']]);
        if ((int) $countStmt->fetchColumn() >= (int) $license['max_activations']) {
            return ['ok' => false, 'error' => 'This license has reached its device activation limit.'];
        }

        return ['ok' => true];
    }

    public static function activate(array $request, ?string $ip = null): array
    {
        self::requireSchema();
        $licenseKey = self::requiredString($request, 'license_key', 64);
        $hwid = self::requiredString($request, 'hwid', 160);
        $storeUuid = self::requiredUuid($request, 'store_uuid');
        $deviceUuid = self::requiredUuid($request, 'device_uuid');
        $role = self::normalizeRole((string) ($request['device_role'] ?? 'single_terminal'));
        $appVersion = self::optionalString($request, 'app_version', 50);
        $countsAsTerminal = self::roleCountsAsTerminal($role);

        return self::withSeatLock($licenseKey, function () use (
            $licenseKey, $hwid, $storeUuid, $deviceUuid, $role, $appVersion, $countsAsTerminal, $ip
        ): array {
            $pdo = Database::pdo();
            $pdo->beginTransaction();
            try {
                $license = self::findLicenseForUpdate($licenseKey);
                if (!$license) {
                    $pdo->rollBack();
                    return self::failure('invalid', 'Invalid license key.');
                }
                if (($license['status'] ?? '') !== 'active') {
                    $pdo->rollBack();
                    return self::failure((string) $license['status'], 'This license is not active.');
                }
                if (self::isExpired($license)) {
                    $pdo->prepare("UPDATE licenses SET status = 'expired' WHERE id = ?")->execute([(int) $license['id']]);
                    $pdo->commit();
                    return self::failure('expired', 'This license has expired.');
                }

                if (!empty($license['store_uuid']) && !hash_equals(strtolower((string) $license['store_uuid']), strtolower($storeUuid))) {
                    $pdo->rollBack();
                    return self::failure('store_mismatch', 'This license is already bound to a different store.');
                }

                $deviceCollision = $pdo->prepare(
                    'SELECT id, license_id, hwid FROM license_activations WHERE device_uuid = ? LIMIT 1'
                );
                $deviceCollision->execute([$deviceUuid]);
                $collision = $deviceCollision->fetch();
                if ($collision && ((int) $collision['license_id'] !== (int) $license['id'] || (string) $collision['hwid'] !== $hwid)) {
                    $pdo->rollBack();
                    return self::failure('device_identity_conflict', 'This device identity is already bound elsewhere.');
                }

                $existingStmt = $pdo->prepare(
                    'SELECT * FROM license_activations WHERE license_id = ? AND hwid = ? LIMIT 1'
                );
                $existingStmt->execute([(int) $license['id'], $hwid]);
                $activation = $existingStmt->fetch();
                if ($activation && !empty($activation['revoked_at'])) {
                    $pdo->rollBack();
                    return self::failure('device_revoked', 'This device has been permanently revoked.');
                }

                $excludeId = $activation ? (int) $activation['id'] : null;
                if (!self::seatAvailable($license, $countsAsTerminal, $excludeId)) {
                    $pdo->rollBack();
                    return self::failure(
                        $countsAsTerminal ? 'terminal_limit' : 'management_device_limit',
                        $countsAsTerminal
                            ? 'This license has reached its terminal limit.'
                            : 'This license has reached its management-device limit.'
                    );
                }

                if (empty($license['store_uuid'])) {
                    $pdo->prepare('UPDATE licenses SET store_uuid = ? WHERE id = ?')
                        ->execute([$storeUuid, (int) $license['id']]);
                }

                if ($activation) {
                    $stmt = $pdo->prepare(
                        'UPDATE license_activations
                         SET device_uuid = ?, store_uuid = ?, device_role = ?, counts_as_terminal = ?,
                             app_version = ?, is_active = 1, paired_at = COALESCE(paired_at, CURRENT_TIMESTAMP),
                             last_seen_at = CURRENT_TIMESTAMP, ip_address = ?
                         WHERE id = ?'
                    );
                    $stmt->execute([
                        $deviceUuid, $storeUuid, $role, $countsAsTerminal ? 1 : 0,
                        $appVersion, $ip, (int) $activation['id'],
                    ]);
                    $activationId = (int) $activation['id'];
                } else {
                    $stmt = $pdo->prepare(
                        'INSERT INTO license_activations
                         (license_id, hwid, device_uuid, store_uuid, device_role, counts_as_terminal,
                          app_version, ip_address, paired_at, is_active)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP, 1)'
                    );
                    $stmt->execute([
                        (int) $license['id'], $hwid, $deviceUuid, $storeUuid, $role,
                        $countsAsTerminal ? 1 : 0, $appVersion, $ip,
                    ]);
                    $activationId = (int) $pdo->lastInsertId();
                }

                self::logEvent((int) $license['id'], 'device_v2_activated', 'Device ' . $deviceUuid . ' activated as ' . $role, 'api_v2');
                self::logVerification((int) $license['id'], $licenseKey, $hwid, 'ok_v2', $ip);
                $pdo->commit();

                return [
                    'ok' => true,
                    'activation_id' => $activationId,
                    'device_uuid' => $deviceUuid,
                    'device_role' => $role,
                    'counts_as_terminal' => $countsAsTerminal,
                    'entitlement' => self::entitlementByKey($licenseKey),
                ];
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }
        });
    }

    public static function validate(array $request, ?string $ip = null): array
    {
        self::requireSchema();
        $licenseKey = self::requiredString($request, 'license_key', 64);
        $hwid = self::requiredString($request, 'hwid', 160);
        $storeUuid = self::requiredUuid($request, 'store_uuid');
        $deviceUuid = self::requiredUuid($request, 'device_uuid');
        $appVersion = self::optionalString($request, 'app_version', 50);

        $pdo = Database::pdo();
        $licenseStmt = $pdo->prepare('SELECT * FROM licenses WHERE license_key = ? LIMIT 1');
        $licenseStmt->execute([$licenseKey]);
        $license = $licenseStmt->fetch();
        if (!$license) return self::failure('invalid', 'Invalid license key.');
        if (($license['status'] ?? '') !== 'active') return self::failure((string) $license['status'], 'This license is not active.');
        if (self::isExpired($license)) return self::failure('expired', 'This license has expired.');
        if (empty($license['store_uuid']) || !hash_equals(strtolower((string) $license['store_uuid']), strtolower($storeUuid))) {
            return self::failure('store_mismatch', 'Store identity does not match this license.');
        }

        $stmt = $pdo->prepare(
            'SELECT * FROM license_activations WHERE license_id = ? AND hwid = ? LIMIT 1'
        );
        $stmt->execute([(int) $license['id'], $hwid]);
        $activation = $stmt->fetch();
        if (!$activation || !(int) $activation['is_active']) return self::failure('device_inactive', 'This device is not active.');
        if (!empty($activation['revoked_at'])) return self::failure('device_revoked', 'This device has been permanently revoked.');
        if (!empty($activation['device_uuid']) && !hash_equals(strtolower((string) $activation['device_uuid']), strtolower($deviceUuid))) {
            return self::failure('device_identity_mismatch', 'Device identity does not match this activation.');
        }

        // Upgrade an old v1 activation in place without consuming another seat.
        if (empty($activation['device_uuid'])) {
            $collision = $pdo->prepare('SELECT id FROM license_activations WHERE device_uuid = ? AND id <> ? LIMIT 1');
            $collision->execute([$deviceUuid, (int) $activation['id']]);
            if ($collision->fetch()) return self::failure('device_identity_conflict', 'This device identity is already bound elsewhere.');
            $pdo->prepare(
                'UPDATE license_activations SET device_uuid = ?, store_uuid = ?, paired_at = COALESCE(paired_at, CURRENT_TIMESTAMP) WHERE id = ?'
            )->execute([$deviceUuid, $storeUuid, (int) $activation['id']]);
        }

        $pdo->prepare(
            'UPDATE license_activations SET last_seen_at = CURRENT_TIMESTAMP, ip_address = ?, app_version = COALESCE(?, app_version) WHERE id = ?'
        )->execute([$ip, $appVersion, (int) $activation['id']]);
        $pdo->prepare('UPDATE licenses SET last_verified_at = CURRENT_TIMESTAMP WHERE id = ?')->execute([(int) $license['id']]);
        self::logVerification((int) $license['id'], $licenseKey, $hwid, 'ok_v2', $ip);

        return [
            'ok' => true,
            'device_uuid' => $deviceUuid,
            'device_role' => (string) ($activation['device_role'] ?: 'single_terminal'),
            'counts_as_terminal' => (bool) $activation['counts_as_terminal'],
            'entitlement' => self::entitlementByKey($licenseKey),
        ];
    }

    public static function revokeDevice(array $request): array
    {
        self::requireSchema();
        $licenseKey = self::requiredString($request, 'license_key', 64);
        $requesterHwid = self::requiredString($request, 'requester_hwid', 160);
        $targetDeviceUuid = self::requiredUuid($request, 'device_uuid');
        $reason = self::optionalString($request, 'reason', 255) ?: 'revoked_by_manager';

        return self::withSeatLock($licenseKey, function () use ($licenseKey, $requesterHwid, $targetDeviceUuid, $reason): array {
            $pdo = Database::pdo();
            $pdo->beginTransaction();
            try {
                $license = self::findLicenseForUpdate($licenseKey);
                if (!$license) { $pdo->rollBack(); return self::failure('invalid', 'Invalid license key.'); }
                $requester = self::activeActivationByHwid((int) $license['id'], $requesterHwid);
                if (!$requester) { $pdo->rollBack(); return self::failure('requester_not_authorized', 'Requesting device is not active.'); }
                $targetStmt = $pdo->prepare('SELECT * FROM license_activations WHERE license_id = ? AND device_uuid = ? LIMIT 1');
                $targetStmt->execute([(int) $license['id'], $targetDeviceUuid]);
                $target = $targetStmt->fetch();
                if (!$target) { $pdo->rollBack(); return self::failure('device_not_found', 'Target device was not found.'); }
                if (!self::canManageDevices($requester) && (int) $requester['id'] !== (int) $target['id']) {
                    $pdo->rollBack();
                    return self::failure('permission_denied', 'This device cannot revoke another device.');
                }
                if (!empty($target['revoked_at'])) {
                    $pdo->commit();
                    return ['ok' => true, 'already_revoked' => true, 'entitlement' => self::entitlementByKey($licenseKey)];
                }

                $pdo->prepare(
                    'UPDATE license_activations SET is_active = 0, revoked_at = CURRENT_TIMESTAMP, revoked_by = ?, revoke_reason = ? WHERE id = ?'
                )->execute([(string) ($requester['device_uuid'] ?: $requesterHwid), $reason, (int) $target['id']]);
                self::bumpEntitlementVersion((int) $license['id'], $licenseKey);
                self::logEvent((int) $license['id'], 'device_revoked_v2', 'Revoked device ' . $targetDeviceUuid . ': ' . $reason, 'api_v2');
                $pdo->commit();
                return ['ok' => true, 'entitlement' => self::entitlementByKey($licenseKey)];
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }
        });
    }

    public static function replaceDevice(array $request, ?string $ip = null): array
    {
        self::requireSchema();
        $licenseKey = self::requiredString($request, 'license_key', 64);
        $requesterHwid = self::requiredString($request, 'requester_hwid', 160);
        $oldDeviceUuid = self::requiredUuid($request, 'old_device_uuid');
        $newHwid = self::requiredString($request, 'new_hwid', 160);
        $newDeviceUuid = self::requiredUuid($request, 'new_device_uuid');
        $role = self::normalizeRole((string) ($request['device_role'] ?? 'cashier_terminal'));
        $appVersion = self::optionalString($request, 'app_version', 50);
        $reason = self::optionalString($request, 'reason', 255) ?: 'device_replacement';
        $countsAsTerminal = self::roleCountsAsTerminal($role);

        return self::withSeatLock($licenseKey, function () use (
            $licenseKey, $requesterHwid, $oldDeviceUuid, $newHwid, $newDeviceUuid,
            $role, $appVersion, $reason, $countsAsTerminal, $ip
        ): array {
            $pdo = Database::pdo();
            $pdo->beginTransaction();
            try {
                $license = self::findLicenseForUpdate($licenseKey);
                if (!$license) { $pdo->rollBack(); return self::failure('invalid', 'Invalid license key.'); }
                $requester = self::activeActivationByHwid((int) $license['id'], $requesterHwid);
                if (!$requester || !self::canManageDevices($requester)) {
                    $pdo->rollBack();
                    return self::failure('permission_denied', 'Only an active manager device can replace another device.');
                }
                $oldStmt = $pdo->prepare('SELECT * FROM license_activations WHERE license_id = ? AND device_uuid = ? LIMIT 1');
                $oldStmt->execute([(int) $license['id'], $oldDeviceUuid]);
                $old = $oldStmt->fetch();
                if (!$old) { $pdo->rollBack(); return self::failure('device_not_found', 'Old device was not found.'); }

                $collision = $pdo->prepare('SELECT * FROM license_activations WHERE device_uuid = ? OR (license_id = ? AND hwid = ?) LIMIT 1');
                $collision->execute([$newDeviceUuid, (int) $license['id'], $newHwid]);
                $existingNew = $collision->fetch();
                if ($existingNew && (int) $existingNew['id'] !== (int) $old['id']) {
                    if (!empty($existingNew['revoked_at'])) {
                        $pdo->rollBack();
                        return self::failure('replacement_device_revoked', 'Replacement device identity was previously revoked.');
                    }
                    $pdo->rollBack();
                    return self::failure('replacement_identity_conflict', 'Replacement device is already registered.');
                }

                $pdo->prepare(
                    'UPDATE license_activations SET is_active = 0, revoked_at = CURRENT_TIMESTAMP, revoked_by = ?, revoke_reason = ? WHERE id = ?'
                )->execute([(string) ($requester['device_uuid'] ?: $requesterHwid), $reason, (int) $old['id']]);

                if (!self::seatAvailable($license, $countsAsTerminal, null)) {
                    $pdo->rollBack();
                    return self::failure('seat_limit', 'No seat is available for the replacement device.');
                }

                $stmt = $pdo->prepare(
                    'INSERT INTO license_activations
                     (license_id, hwid, device_uuid, store_uuid, device_role, counts_as_terminal,
                      app_version, ip_address, paired_at, is_active)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP, 1)'
                );
                $stmt->execute([
                    (int) $license['id'], $newHwid, $newDeviceUuid, (string) $license['store_uuid'],
                    $role, $countsAsTerminal ? 1 : 0, $appVersion, $ip,
                ]);
                self::bumpEntitlementVersion((int) $license['id'], $licenseKey);
                self::logEvent((int) $license['id'], 'device_replaced_v2', 'Replaced ' . $oldDeviceUuid . ' with ' . $newDeviceUuid, 'api_v2');
                $pdo->commit();
                return [
                    'ok' => true,
                    'new_device_uuid' => $newDeviceUuid,
                    'entitlement' => self::entitlementByKey($licenseKey),
                ];
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }
        });
    }

    public static function entitlementByKey(string $licenseKey): array
    {
        self::requireSchema();
        $stmt = Database::pdo()->prepare('SELECT * FROM licenses WHERE license_key = ? LIMIT 1');
        $stmt->execute([$licenseKey]);
        $license = $stmt->fetch();
        if (!$license) throw new InvalidArgumentException('License not found.');

        $features = json_decode((string) ($license['features_json'] ?? ''), true);
        if (!is_array($features)) $features = [];
        $features['multi_cashier'] = (bool) ($license['multi_cashier'] ?? false);
        if (!array_key_exists('offline_sale', $features)) $features['offline_sale'] = true;

        return [
            'schema_version' => 2,
            'license_uuid' => (string) $license['license_uuid'],
            'store_uuid' => $license['store_uuid'] !== null ? (string) $license['store_uuid'] : null,
            'status' => (string) $license['status'],
            'plan' => (string) $license['plan'],
            'features' => $features,
            'multi_cashier' => (bool) $license['multi_cashier'],
            'max_terminals' => (int) $license['max_terminals'],
            'max_management_devices' => (int) $license['max_management_devices'],
            'entitlement_version' => (int) $license['entitlement_version'],
            'expires_at' => $license['expires_at'],
            'offline_valid_until' => $license['offline_valid_until'],
            'server_time' => gmdate('Y-m-d\TH:i:s\Z'),
        ];
    }

    private static function seatAvailable(array $license, bool $countsAsTerminal, ?int $excludeActivationId): bool
    {
        $pdo = Database::pdo();
        $sql = 'SELECT COUNT(*) FROM license_activations
                WHERE license_id = ? AND is_active = 1 AND revoked_at IS NULL
                  AND counts_as_terminal = ?';
        $params = [(int) $license['id'], $countsAsTerminal ? 1 : 0];
        if ($excludeActivationId !== null) {
            $sql .= ' AND id <> ?';
            $params[] = $excludeActivationId;
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $active = (int) $stmt->fetchColumn();
        $limit = $countsAsTerminal
            ? max(1, (int) $license['max_terminals'])
            : max(1, (int) $license['max_management_devices']);
        return $active < $limit;
    }

    private static function activeActivationByHwid(int $licenseId, string $hwid): ?array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT * FROM license_activations
             WHERE license_id = ? AND hwid = ? AND is_active = 1 AND revoked_at IS NULL LIMIT 1'
        );
        $stmt->execute([$licenseId, $hwid]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    private static function canManageDevices(array $activation): bool
    {
        return in_array((string) ($activation['device_role'] ?? ''), ['manager_server', 'manager_terminal'], true);
    }

    private static function findLicenseForUpdate(string $licenseKey): ?array
    {
        $pdo = Database::pdo();
        $limitAndLock = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'
            ? ' LIMIT 1 FOR UPDATE'
            : ' LIMIT 1';
        $stmt = $pdo->prepare('SELECT * FROM licenses WHERE license_key = ?' . $limitAndLock);
        $stmt->execute([$licenseKey]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    private static function bumpEntitlementVersion(int $licenseId, string $licenseKey): void
    {
        $pdo = Database::pdo();
        $pdo->prepare('UPDATE licenses SET entitlement_version = entitlement_version + 1 WHERE id = ?')->execute([$licenseId]);
        try {
            $pdo->prepare('INSERT INTO license_change_notifications (license_key) VALUES (?)')->execute([$licenseKey]);
        } catch (Throwable $ignored) {
        }
    }

    private static function logEvent(int $licenseId, string $type, string $note, string $actor): void
    {
        try {
            Database::pdo()->prepare(
                'INSERT INTO subscription_events (license_id, event_type, note, created_by) VALUES (?, ?, ?, ?)'
            )->execute([$licenseId, $type, mb_substr($note, 0, 255), $actor]);
        } catch (Throwable $ignored) {
        }
    }

    private static function logVerification(int $licenseId, string $licenseKey, string $hwid, string $result, ?string $ip): void
    {
        try {
            Database::pdo()->prepare(
                'INSERT INTO verification_log (license_id, license_key, hwid, result, ip_address) VALUES (?, ?, ?, ?, ?)'
            )->execute([$licenseId, $licenseKey, $hwid, $result, $ip]);
        } catch (Throwable $ignored) {
        }
    }

    private static function failure(string $status, string $error): array
    {
        return ['ok' => false, 'status' => $status, 'error' => $error];
    }

    private static function normalizeRole(string $role): string
    {
        $role = trim($role);
        if (!in_array($role, self::DEVICE_ROLES, true)) {
            throw new InvalidArgumentException('Invalid device_role.');
        }
        return $role;
    }

    private static function roleCountsAsTerminal(string $role): bool
    {
        return in_array($role, ['single_terminal', 'manager_terminal', 'cashier_terminal'], true);
    }

    private static function isExpired(array $license): bool
    {
        return !empty($license['expires_at']) && strtotime((string) $license['expires_at']) < time();
    }

    private static function requiredString(array $request, string $key, int $max): string
    {
        $value = trim((string) ($request[$key] ?? ''));
        if ($value === '' || strlen($value) > $max || preg_match('/[\x00-\x1F\x7F]/', $value)) {
            throw new InvalidArgumentException("Invalid {$key}.");
        }
        return $value;
    }

    private static function optionalString(array $request, string $key, int $max): ?string
    {
        $value = trim((string) ($request[$key] ?? ''));
        if ($value === '') return null;
        if (strlen($value) > $max || preg_match('/[\x00-\x1F\x7F]/', $value)) {
            throw new InvalidArgumentException("Invalid {$key}.");
        }
        return $value;
    }

    private static function requiredUuid(array $request, string $key): string
    {
        $value = strtolower(trim((string) ($request[$key] ?? '')));
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $value)) {
            throw new InvalidArgumentException("Invalid {$key}.");
        }
        return $value;
    }

    private static function requireSchema(): void
    {
        if (!self::schemaReady()) {
            throw new RuntimeException('Entitlement v2 schema is not installed. Run db/migrate_multi_entitlement_v2.php.');
        }
    }

    private static function hasColumn(string $table, string $column): bool
    {
        $pdo = Database::pdo();
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $stmt = $pdo->prepare(
                'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
            );
            $stmt->execute([$table, $column]);
            return (int) $stmt->fetchColumn() > 0;
        }
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $safeTable = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
            foreach ($pdo->query("PRAGMA table_info({$safeTable})") as $row) {
                if ((string) $row['name'] === $column) return true;
            }
        }
        return false;
    }
}
