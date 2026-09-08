<?php
/**
 * Fix495 — atomic device move into the canonical Multi store license.
 *
 * The operation is idempotent and acquires the same named seat locks used by
 * EntitlementV2 before mutating either source or target license. A device that
 * was temporarily activated with another license is released from that source
 * and admitted to the target Store UUID in one database transaction.
 */
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/EntitlementV2.php';

final class DeviceLicenseTransition
{
    private const DEVICE_ROLES = [
        'single_terminal', 'manager_server', 'manager_terminal', 'cashier_terminal', 'management_only',
    ];

    public static function transition(array $request, ?string $ip = null): array
    {
        $targetKey = self::requiredString($request, 'target_license_key', 64);
        $sourceKey = self::optionalString($request, 'source_license_key', 64);
        if ($sourceKey !== null && hash_equals($sourceKey, $targetKey)) $sourceKey = null;
        $hwid = self::requiredString($request, 'hwid', 160);
        $storeUuid = self::requiredUuid($request, 'store_uuid');
        $deviceUuid = self::requiredUuid($request, 'device_uuid');
        $role = self::normalizeRole((string) ($request['device_role'] ?? 'cashier_terminal'));
        $appVersion = self::optionalString($request, 'app_version', 50);
        $countsAsTerminal = self::roleCountsAsTerminal($role);

        $keys = [$targetKey];
        if ($sourceKey !== null) $keys[] = $sourceKey;

        return self::withSeatLocks($keys, function () use (
            $targetKey, $sourceKey, $hwid, $storeUuid, $deviceUuid, $role, $appVersion, $countsAsTerminal, $ip
        ): array {
            $pdo = Database::pdo();
            $pdo->beginTransaction();
            try {
                $target = self::findLicenseForUpdate($targetKey);
                if (!$target) { $pdo->rollBack(); return self::failure('invalid', 'Invalid target license key.'); }
                if (($target['status'] ?? '') !== 'active') { $pdo->rollBack(); return self::failure((string) ($target['status'] ?? 'invalid'), 'Target license is not active.'); }
                if (self::isExpired($target)) { $pdo->rollBack(); return self::failure('expired', 'Target license has expired.'); }
                if (!(bool) ($target['multi_cashier'] ?? false)) { $pdo->rollBack(); return self::failure('multi_disabled', 'Target license is not enabled for Multi-Cashier.'); }
                if (!empty($target['store_uuid']) && !hash_equals(strtolower((string) $target['store_uuid']), $storeUuid)) {
                    $pdo->rollBack();
                    return self::failure('store_mismatch', 'Target license belongs to a different store.');
                }

                $source = null;
                if ($sourceKey !== null) {
                    $source = self::findLicenseForUpdate($sourceKey);
                    if (!$source) { $pdo->rollBack(); return self::failure('source_invalid', 'Source license key is invalid.'); }
                }

                $targetStmt = $pdo->prepare('SELECT * FROM license_activations WHERE license_id = ? AND hwid = ? LIMIT 1');
                $targetStmt->execute([(int) $target['id'], $hwid]);
                $targetActivation = $targetStmt->fetch() ?: null;
                if ($targetActivation && !empty($targetActivation['revoked_at'])) {
                    $pdo->rollBack();
                    return self::failure('device_revoked', 'This device was permanently revoked on the target license.');
                }

                $sourceActivation = null;
                if ($source) {
                    $sourceStmt = $pdo->prepare('SELECT * FROM license_activations WHERE license_id = ? AND hwid = ? LIMIT 1');
                    $sourceStmt->execute([(int) $source['id'], $hwid]);
                    $sourceActivation = $sourceStmt->fetch() ?: null;
                    if ($sourceActivation && !empty($sourceActivation['revoked_at'])) {
                        $pdo->rollBack();
                        return self::failure('source_device_revoked', 'This device was permanently revoked on the source license.');
                    }
                    if ($sourceActivation && !empty($sourceActivation['device_uuid']) &&
                        !hash_equals(strtolower((string) $sourceActivation['device_uuid']), $deviceUuid)) {
                        $pdo->rollBack();
                        return self::failure('source_device_identity_mismatch', 'Source license activation belongs to another device identity.');
                    }
                }

                $collisionStmt = $pdo->prepare('SELECT * FROM license_activations WHERE device_uuid = ? LIMIT 1');
                $collisionStmt->execute([$deviceUuid]);
                $collision = $collisionStmt->fetch() ?: null;
                if ($collision) {
                    $targetOwnsCollision = (int) $collision['license_id'] === (int) $target['id'] && (string) $collision['hwid'] === $hwid;
                    $sourceOwnsCollision = $source && (int) $collision['license_id'] === (int) $source['id'] && (string) $collision['hwid'] === $hwid;
                    if (!$targetOwnsCollision && !$sourceOwnsCollision) {
                        $pdo->rollBack();
                        return self::failure('device_identity_conflict', 'This device identity is already bound to another license.');
                    }
                    if ($sourceOwnsCollision && !$sourceActivation) $sourceActivation = $collision;
                }

                $targetAlreadyExact = $targetActivation
                    && (int) $targetActivation['is_active'] === 1
                    && empty($targetActivation['revoked_at'])
                    && hash_equals(strtolower((string) ($targetActivation['device_uuid'] ?? '')), $deviceUuid)
                    && hash_equals(strtolower((string) ($targetActivation['store_uuid'] ?? '')), $storeUuid)
                    && strtolower((string) ($targetActivation['device_role'] ?? '')) === $role;

                $sourceChanged = false;
                if ($sourceActivation && ((int) $sourceActivation['is_active'] === 1 || !empty($sourceActivation['device_uuid']))) {
                    $pdo->prepare(
                        'UPDATE license_activations
                         SET is_active = 0, device_uuid = NULL, store_uuid = NULL, last_seen_at = CURRENT_TIMESTAMP
                         WHERE id = ?'
                    )->execute([(int) $sourceActivation['id']]);
                    self::bumpEntitlementVersion((int) $source['id'], $sourceKey);
                    self::logEvent((int) $source['id'], 'device_transitioned_out_v2', 'Device ' . $deviceUuid . ' moved to another store license', 'api_v2');
                    $sourceChanged = true;
                }

                if (!$targetAlreadyExact) {
                    $excludeId = $targetActivation ? (int) $targetActivation['id'] : null;
                    if (!self::seatAvailable($target, $countsAsTerminal, $excludeId)) {
                        $pdo->rollBack();
                        return self::failure($countsAsTerminal ? 'terminal_limit' : 'management_device_limit',
                            $countsAsTerminal ? 'Target license has reached its terminal limit.' : 'Target license has reached its management-device limit.');
                    }

                    if (empty($target['store_uuid'])) {
                        $pdo->prepare('UPDATE licenses SET store_uuid = ? WHERE id = ?')->execute([$storeUuid, (int) $target['id']]);
                    }

                    if ($targetActivation) {
                        $pdo->prepare(
                            'UPDATE license_activations
                             SET device_uuid = ?, store_uuid = ?, device_role = ?, counts_as_terminal = ?, app_version = ?,
                                 is_active = 1, paired_at = COALESCE(paired_at, CURRENT_TIMESTAMP), last_seen_at = CURRENT_TIMESTAMP,
                                 ip_address = ?, revoked_by = NULL, revoke_reason = NULL
                             WHERE id = ?'
                        )->execute([$deviceUuid, $storeUuid, $role, $countsAsTerminal ? 1 : 0, $appVersion, $ip, (int) $targetActivation['id']]);
                        $activationId = (int) $targetActivation['id'];
                    } else {
                        $pdo->prepare(
                            'INSERT INTO license_activations
                             (license_id, hwid, device_uuid, store_uuid, device_role, counts_as_terminal,
                              app_version, ip_address, paired_at, is_active)
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP, 1)'
                        )->execute([(int) $target['id'], $hwid, $deviceUuid, $storeUuid, $role, $countsAsTerminal ? 1 : 0, $appVersion, $ip]);
                        $activationId = (int) $pdo->lastInsertId();
                    }
                    self::bumpEntitlementVersion((int) $target['id'], $targetKey);
                    self::logEvent((int) $target['id'], 'device_transitioned_in_v2', 'Device ' . $deviceUuid . ' admitted as ' . $role, 'api_v2');
                } else {
                    $activationId = (int) $targetActivation['id'];
                }

                self::logVerification((int) $target['id'], $targetKey, $hwid, 'ok_v2_transition', $ip);
                $pdo->commit();
                return [
                    'ok' => true,
                    'activation_id' => $activationId,
                    'device_uuid' => $deviceUuid,
                    'device_role' => $role,
                    'counts_as_terminal' => $countsAsTerminal,
                    'source_released' => $sourceChanged,
                    'duplicate' => $targetAlreadyExact,
                    'entitlement' => EntitlementV2::entitlementByKey($targetKey),
                ];
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }
        });
    }

    private static function withSeatLocks(array $licenseKeys, callable $callback)
    {
        $pdo = Database::pdo();
        $keys = array_values(array_unique(array_map('strval', $licenseKeys)));
        sort($keys, SORT_STRING);
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') return $callback();

        $locks = [];
        try {
            foreach ($keys as $key) {
                $name = 'hercule-seat-' . substr(hash('sha256', $key), 0, 40);
                $stmt = $pdo->prepare('SELECT GET_LOCK(?, 10)');
                $stmt->execute([$name]);
                if ((int) $stmt->fetchColumn() !== 1) throw new RuntimeException('Unable to acquire license seat lock.');
                $locks[] = $name;
            }
            return $callback();
        } finally {
            foreach (array_reverse($locks) as $name) {
                try { $stmt = $pdo->prepare('SELECT RELEASE_LOCK(?)'); $stmt->execute([$name]); } catch (Throwable $ignored) {}
            }
        }
    }

    private static function seatAvailable(array $license, bool $countsAsTerminal, ?int $excludeId): bool
    {
        $pdo = Database::pdo();
        $sql = 'SELECT COUNT(*) FROM license_activations WHERE license_id = ? AND is_active = 1 AND revoked_at IS NULL AND counts_as_terminal = ?';
        $params = [(int) $license['id'], $countsAsTerminal ? 1 : 0];
        if ($excludeId !== null) { $sql .= ' AND id <> ?'; $params[] = $excludeId; }
        $stmt = $pdo->prepare($sql); $stmt->execute($params);
        $active = (int) $stmt->fetchColumn();
        $limit = $countsAsTerminal ? max(1, (int) $license['max_terminals']) : max(1, (int) $license['max_management_devices']);
        return $active < $limit;
    }

    private static function findLicenseForUpdate(string $key): ?array
    {
        $pdo = Database::pdo();
        $lock = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' LIMIT 1 FOR UPDATE' : ' LIMIT 1';
        $stmt = $pdo->prepare('SELECT * FROM licenses WHERE license_key = ?' . $lock); $stmt->execute([$key]);
        return $stmt->fetch() ?: null;
    }

    private static function bumpEntitlementVersion(int $licenseId, string $licenseKey): void
    {
        $pdo = Database::pdo();
        $pdo->prepare('UPDATE licenses SET entitlement_version = entitlement_version + 1 WHERE id = ?')->execute([$licenseId]);
        try { $pdo->prepare('INSERT INTO license_change_notifications (license_key) VALUES (?)')->execute([$licenseKey]); } catch (Throwable $ignored) {}
    }

    private static function logEvent(int $licenseId, string $type, string $note, string $actor): void
    {
        try { Database::pdo()->prepare('INSERT INTO subscription_events (license_id,event_type,note,created_by) VALUES (?,?,?,?)')->execute([$licenseId,$type,mb_substr($note,0,255),$actor]); } catch (Throwable $ignored) {}
    }

    private static function logVerification(int $licenseId, string $licenseKey, string $hwid, string $result, ?string $ip): void
    {
        try { Database::pdo()->prepare('INSERT INTO verification_log (license_id,license_key,hwid,result,ip_address) VALUES (?,?,?,?,?)')->execute([$licenseId,$licenseKey,$hwid,$result,$ip]); } catch (Throwable $ignored) {}
    }

    private static function isExpired(array $license): bool { return !empty($license['expires_at']) && strtotime((string) $license['expires_at']) < time(); }
    private static function roleCountsAsTerminal(string $role): bool { return in_array($role, ['single_terminal','manager_terminal','cashier_terminal'], true); }
    private static function normalizeRole(string $role): string { $role = trim($role); if (!in_array($role, self::DEVICE_ROLES, true)) throw new InvalidArgumentException('Invalid device_role.'); return $role; }
    private static function requiredUuid(array $request, string $key): string { $value = strtolower(trim((string) ($request[$key] ?? ''))); if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $value)) throw new InvalidArgumentException("Invalid {$key}."); return $value; }
    private static function requiredString(array $request, string $key, int $max): string { $value = trim((string) ($request[$key] ?? '')); if ($value === '' || strlen($value) > $max || preg_match('/[\x00-\x1F\x7F]/', $value)) throw new InvalidArgumentException("Invalid {$key}."); return $value; }
    private static function optionalString(array $request, string $key, int $max): ?string { $value = trim((string) ($request[$key] ?? '')); if ($value === '') return null; if (strlen($value) > $max || preg_match('/[\x00-\x1F\x7F]/', $value)) throw new InvalidArgumentException("Invalid {$key}."); return $value; }
    private static function failure(string $status, string $error): array { return ['ok'=>false,'status'=>$status,'error'=>$error]; }
}
