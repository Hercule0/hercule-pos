<?php

require_once __DIR__ . '/Database.php';

final class Entitlement
{
    private const TERMINAL_ROLES = ['single_terminal', 'cashier_terminal', 'manager_terminal'];
    private const MANAGEMENT_ROLES = ['manager_server', 'management_only'];

    public static function schemaReady(): bool
    {
        $pdo = Database::pdo();
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $licenseRequired = [
            'license_uuid', 'store_uuid', 'multi_cashier', 'max_terminals',
            'max_management_devices', 'features_json', 'entitlement_version', 'offline_valid_until',
        ];
        $activationRequired = [
            'device_uuid', 'device_role', 'counts_as_terminal', 'protocol_version',
            'certificate_fingerprint', 'paired_at',
        ];

        if ($driver === 'mysql') {
            $check = static function (string $table, array $columns) use ($pdo): bool {
                $ph = implode(',', array_fill(0, count($columns), '?'));
                $stmt = $pdo->prepare(
                    "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME IN ({$ph})"
                );
                $stmt->execute(array_merge([$table], $columns));
                return (int) $stmt->fetchColumn() === count($columns);
            };
            return $check('licenses', $licenseRequired) && $check('license_activations', $activationRequired);
        }

        if ($driver === 'sqlite') {
            $check = static function (string $table, array $columns) use ($pdo): bool {
                $rows = $pdo->query("PRAGMA table_info({$table})")->fetchAll(PDO::FETCH_ASSOC);
                $existing = array_column($rows, 'name');
                foreach ($columns as $column) {
                    if (!in_array($column, $existing, true)) return false;
                }
                return true;
            };
            return $check('licenses', $licenseRequired) && $check('license_activations', $activationRequired);
        }

        return false;
    }

    public static function uuidV4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return sprintf('%s-%s-%s-%s-%s',
            substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4),
            substr($hex, 16, 4), substr($hex, 20, 12)
        );
    }

    public static function roleCountsAsTerminal(string $role): bool
    {
        return in_array($role, self::TERMINAL_ROLES, true);
    }

    public static function isAllowedRole(string $role): bool
    {
        return self::roleCountsAsTerminal($role) || in_array($role, self::MANAGEMENT_ROLES, true);
    }

    private static function supportsRowLocking(PDO $pdo): bool
    {
        return $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    }

    private static function normalizeFeatures($value, bool $multiCashier): array
    {
        if (is_array($value)) {
            $features = $value;
        } else {
            $decoded = json_decode((string) $value, true);
            $features = is_array($decoded) ? $decoded : [];
        }
        $features['multi_cashier'] = $multiCashier;
        if (!array_key_exists('offline_sale', $features)) $features['offline_sale'] = true;
        return $features;
    }

    private static function licenseExpired(array $license): bool
    {
        return !empty($license['expires_at']) && strtotime((string) $license['expires_at']) < time();
    }

    private static function findLicenseByKey(string $licenseKey, bool $forUpdate = false): ?array
    {
        $pdo = Database::pdo();
        $lock = $forUpdate && self::supportsRowLocking($pdo) ? ' FOR UPDATE' : '';
        $stmt = $pdo->prepare('SELECT * FROM licenses WHERE license_key = ?' . $lock);
        $stmt->execute([$licenseKey]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    private static function findActivation(int $licenseId, string $hwid): ?array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT * FROM license_activations WHERE license_id = ? AND hwid = ? LIMIT 1'
        );
        $stmt->execute([$licenseId, $hwid]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    private static function denyLifecycle(array $activation): ?array
    {
        if (!empty($activation['is_blocked'])) {
            return ['ok' => false, 'code' => 'device_blocked', 'error' => 'This device has been blocked by the license administrator.'];
        }
        if (!empty($activation['replaced_at'])) {
            return ['ok' => false, 'code' => 'device_replaced', 'error' => 'This device was replaced and cannot reactivate automatically.'];
        }
        if (!empty($activation['revoked_at'])) {
            return ['ok' => false, 'code' => 'device_revoked', 'error' => 'This device has been revoked and requires administrator approval.'];
        }
        return null;
    }

    private static function capacityCount(int $licenseId, bool $terminal): int
    {
        $stmt = Database::pdo()->prepare(
            'SELECT COUNT(*) FROM license_activations
             WHERE license_id = ? AND is_active = 1 AND counts_as_terminal = ?'
        );
        $stmt->execute([$licenseId, $terminal ? 1 : 0]);
        return (int) $stmt->fetchColumn();
    }

    private static function capacityLimit(array $license, bool $terminal): int
    {
        return $terminal
            ? max(1, (int) ($license['max_terminals'] ?? $license['max_activations'] ?? 1))
            : max(0, (int) ($license['max_management_devices'] ?? 0));
    }

    private static function logVerification(int $licenseId, string $licenseKey, string $hwid, string $result, ?string $ip): void
    {
        $stmt = Database::pdo()->prepare(
            'INSERT INTO verification_log (license_id, license_key, hwid, result, ip_address)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$licenseId, $licenseKey, $hwid, $result, $ip]);
    }

    private static function payloadFromRows(array $license, ?array $activation = null): array
    {
        $multi = !empty($license['multi_cashier']);
        $payload = [
            'schema_version' => 2,
            'license_uuid' => (string) $license['license_uuid'],
            'store_uuid' => (string) $license['store_uuid'],
            'status' => (string) $license['status'],
            'plan' => (string) $license['plan'],
            'features' => self::normalizeFeatures($license['features_json'] ?? null, $multi),
            'multi_cashier' => $multi,
            'max_terminals' => (int) $license['max_terminals'],
            'max_management_devices' => (int) $license['max_management_devices'],
            'entitlement_version' => (int) $license['entitlement_version'],
            'expires_at' => $license['expires_at'],
            'offline_valid_until' => $license['offline_valid_until'],
            'server_time' => gmdate('Y-m-d\TH:i:s\Z'),
        ];

        if ($activation) {
            $payload['device'] = [
                'device_uuid' => (string) $activation['device_uuid'],
                'device_role' => (string) $activation['device_role'],
                'counts_as_terminal' => (bool) $activation['counts_as_terminal'],
                'protocol_version' => (int) $activation['protocol_version'],
            ];
        }
        return $payload;
    }

    public static function activateTerminal(
        string $licenseKey,
        string $hwid,
        ?string $appVersion = null,
        int $protocolVersion = 2,
        string $role = 'cashier_terminal',
        ?string $ip = null
    ): array {
        if (!self::schemaReady()) {
            return ['ok' => false, 'code' => 'server_upgrade_required', 'error' => 'Entitlement v2 is not ready on this server.'];
        }
        if (!self::roleCountsAsTerminal($role)) {
            return ['ok' => false, 'code' => 'invalid_device_role', 'error' => 'Public activation only accepts terminal roles.'];
        }
        return self::activateDevice($licenseKey, $hwid, $role, $appVersion, $protocolVersion, $ip);
    }

    public static function activateManagementDevice(
        string $licenseKey,
        string $hwid,
        string $role,
        ?string $appVersion = null,
        int $protocolVersion = 2,
        ?string $ip = null
    ): array {
        if (!in_array($role, self::MANAGEMENT_ROLES, true)) {
            return ['ok' => false, 'code' => 'invalid_device_role', 'error' => 'Invalid management device role.'];
        }
        return self::activateDevice($licenseKey, $hwid, $role, $appVersion, $protocolVersion, $ip);
    }

    private static function activateDevice(
        string $licenseKey,
        string $hwid,
        string $role,
        ?string $appVersion,
        int $protocolVersion,
        ?string $ip
    ): array {
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $license = self::findLicenseByKey($licenseKey, true);
            if (!$license) {
                $pdo->commit();
                return ['ok' => false, 'code' => 'invalid_key', 'error' => 'Invalid license key.'];
            }
            if (($license['status'] ?? '') !== 'active') {
                self::logVerification((int) $license['id'], $licenseKey, $hwid, (string) $license['status'], $ip);
                $pdo->commit();
                return ['ok' => false, 'code' => (string) $license['status'], 'error' => 'License is not active.'];
            }
            if (self::licenseExpired($license)) {
                self::logVerification((int) $license['id'], $licenseKey, $hwid, 'expired', $ip);
                $pdo->commit();
                return ['ok' => false, 'code' => 'expired', 'error' => 'This license has expired.'];
            }

            $terminal = self::roleCountsAsTerminal($role);
            $activation = self::findActivation((int) $license['id'], $hwid);
            if ($activation) {
                $denied = self::denyLifecycle($activation);
                if ($denied) {
                    self::logVerification((int) $license['id'], $licenseKey, $hwid, $denied['code'], $ip);
                    $pdo->commit();
                    return $denied;
                }

                if ((int) $activation['is_active'] !== 1) {
                    $count = self::capacityCount((int) $license['id'], $terminal);
                    $limit = self::capacityLimit($license, $terminal);
                    if ($count >= $limit) {
                        self::logVerification((int) $license['id'], $licenseKey, $hwid, 'activation_limit', $ip);
                        $pdo->commit();
                        return ['ok' => false, 'code' => 'activation_limit', 'error' => 'This license has reached its device activation limit.'];
                    }
                }

                $update = $pdo->prepare(
                    'UPDATE license_activations
                     SET is_active = 1,
                         device_role = ?, counts_as_terminal = ?,
                         app_version = COALESCE(NULLIF(?, \'\'), app_version),
                         protocol_version = ?, ip_address = ?, last_seen_at = CURRENT_TIMESTAMP,
                         deactivated_at = NULL, deactivated_by = NULL
                     WHERE id = ?'
                );
                $update->execute([$role, $terminal ? 1 : 0, $appVersion, max(1, $protocolVersion), $ip, $activation['id']]);
                $activation = self::findActivation((int) $license['id'], $hwid);
                self::logVerification((int) $license['id'], $licenseKey, $hwid, 'ok_v2', $ip);
                $pdo->commit();
                return ['ok' => true, 'payload' => self::payloadFromRows($license, $activation)];
            }

            $count = self::capacityCount((int) $license['id'], $terminal);
            $limit = self::capacityLimit($license, $terminal);
            if ($count >= $limit) {
                self::logVerification((int) $license['id'], $licenseKey, $hwid, 'activation_limit', $ip);
                $pdo->commit();
                return ['ok' => false, 'code' => 'activation_limit', 'error' => 'This license has reached its device activation limit.'];
            }

            $deviceUuid = self::uuidV4();
            $insert = $pdo->prepare(
                'INSERT INTO license_activations
                 (license_id, hwid, device_uuid, device_role, counts_as_terminal,
                  app_version, protocol_version, ip_address, paired_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)'
            );
            $insert->execute([
                (int) $license['id'], $hwid, $deviceUuid, $role, $terminal ? 1 : 0,
                $appVersion ?: null, max(1, $protocolVersion), $ip,
            ]);
            $activation = self::findActivation((int) $license['id'], $hwid);
            self::logVerification((int) $license['id'], $licenseKey, $hwid, 'ok_v2', $ip);
            $pdo->commit();
            return ['ok' => true, 'payload' => self::payloadFromRows($license, $activation)];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    public static function validateDevice(
        string $licenseKey,
        string $hwid,
        ?string $appVersion = null,
        int $protocolVersion = 2,
        ?string $ip = null
    ): array {
        if (!self::schemaReady()) {
            return ['ok' => false, 'code' => 'server_upgrade_required', 'error' => 'Entitlement v2 is not ready on this server.'];
        }
        $license = self::findLicenseByKey($licenseKey, false);
        if (!$license) return ['ok' => false, 'code' => 'invalid_key', 'error' => 'Invalid license key.'];
        if (($license['status'] ?? '') !== 'active') return ['ok' => false, 'code' => (string) $license['status'], 'error' => 'License is not active.'];
        if (self::licenseExpired($license)) return ['ok' => false, 'code' => 'expired', 'error' => 'This license has expired.'];

        $activation = self::findActivation((int) $license['id'], $hwid);
        if (!$activation || (int) $activation['is_active'] !== 1) {
            return ['ok' => false, 'code' => 'device_not_active', 'error' => 'This device is not active for this license.'];
        }
        $denied = self::denyLifecycle($activation);
        if ($denied) return $denied;

        $stmt = Database::pdo()->prepare(
            'UPDATE license_activations
             SET last_seen_at = CURRENT_TIMESTAMP,
                 ip_address = ?,
                 app_version = COALESCE(NULLIF(?, \'\'), app_version),
                 protocol_version = ?
             WHERE id = ?'
        );
        $stmt->execute([$ip, $appVersion, max(1, $protocolVersion), $activation['id']]);
        $activation = self::findActivation((int) $license['id'], $hwid);
        self::logVerification((int) $license['id'], $licenseKey, $hwid, 'ok_v2', $ip);
        return ['ok' => true, 'payload' => self::payloadFromRows($license, $activation)];
    }

    public static function entitlementForDevice(string $licenseKey, string $hwid): array
    {
        return self::validateDevice($licenseKey, $hwid, null, 2, null);
    }
}
