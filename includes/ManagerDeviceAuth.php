<?php
/**
 * Fix496 — manager-device capability authentication for sensitive Multi actions.
 *
 * The existing certificate_fingerprint field is used as a one-way fingerprint
 * for a random per-manager capability. The raw capability is returned only once
 * inside the already RSA-signed v2 activation response and is never stored.
 */
require_once __DIR__ . '/Database.php';

final class ManagerDeviceAuth
{
    private const MANAGER_ROLES = ['manager_server', 'manager_terminal'];
    private const TOKEN_PREFIX = 'hma1_';
    private const TOKEN_DOMAIN = "hercule-manager-action-v1\0";

    public static function maybeIssueForActivation(array $request, array $result): array
    {
        if (!(bool) ($result['ok'] ?? false)) return $result;

        // Defensive one-time semantics: never trust or re-expose capability
        // fields that may have been carried in by an upstream/retry result.
        unset($result['manager_auth_token'], $result['manager_auth_token_issued']);

        $role = strtolower(trim((string) ($result['device_role'] ?? $request['device_role'] ?? '')));
        if (!in_array($role, self::MANAGER_ROLES, true)) return $result;

        $licenseKey = self::requiredString($request, 'license_key', 64);
        $hwid = self::requiredString($request, 'hwid', 160);
        $deviceUuid = self::requiredUuid($request, 'device_uuid');

        $pdo = Database::pdo();
        $stmt = $pdo->prepare(
            'SELECT a.* FROM license_activations a
             JOIN licenses l ON l.id = a.license_id
             WHERE l.license_key = ? AND a.hwid = ? AND a.device_uuid = ?
               AND a.is_active = 1 AND a.revoked_at IS NULL
             LIMIT 1'
        );
        $stmt->execute([$licenseKey, $hwid, $deviceUuid]);
        $activation = $stmt->fetch();
        if (!$activation || !in_array(strtolower((string) ($activation['device_role'] ?? '')), self::MANAGER_ROLES, true)) {
            throw new RuntimeException('Manager activation identity could not be confirmed.');
        }

        $result['manager_auth_required'] = true;
        if (trim((string) ($activation['certificate_fingerprint'] ?? '')) !== '') {
            $result['manager_auth_token_issued'] = false;
            return $result;
        }

        $token = self::TOKEN_PREFIX . bin2hex(random_bytes(32));
        $fingerprint = self::fingerprint($token);
        $update = $pdo->prepare(
            'UPDATE license_activations
             SET certificate_fingerprint = ?
             WHERE id = ? AND (certificate_fingerprint IS NULL OR certificate_fingerprint = ?)'
        );
        $update->execute([$fingerprint, (int) $activation['id'], '']);

        if ($update->rowCount() === 1) {
            $result['manager_auth_token'] = $token;
            $result['manager_auth_token_issued'] = true;
            return $result;
        }

        $result['manager_auth_token_issued'] = false;
        return $result;
    }

    public static function authorizeAction(
        array $request,
        string $action,
        ?string $targetDeviceUuid,
        bool $allowSelf
    ): array {
        $licenseKey = self::requiredString($request, 'license_key', 64);
        $requesterHwid = self::requiredString($request, 'requester_hwid', 160);
        $targetDeviceUuid = $targetDeviceUuid !== null ? strtolower(trim($targetDeviceUuid)) : null;
        if ($targetDeviceUuid !== null && !self::isUuid($targetDeviceUuid)) {
            throw new InvalidArgumentException('Invalid target device identity.');
        }

        $pdo = Database::pdo();
        $licenseStmt = $pdo->prepare('SELECT id FROM licenses WHERE license_key = ? LIMIT 1');
        $licenseStmt->execute([$licenseKey]);
        $licenseId = $licenseStmt->fetchColumn();
        if ($licenseId === false) return self::failure('invalid', 'Invalid license key.');

        $requesterStmt = $pdo->prepare(
            'SELECT * FROM license_activations
             WHERE license_id = ? AND hwid = ? AND is_active = 1 AND revoked_at IS NULL
             LIMIT 1'
        );
        $requesterStmt->execute([(int) $licenseId, $requesterHwid]);
        $requester = $requesterStmt->fetch();
        if (!$requester) return self::failure('requester_not_authorized', 'Requesting device is not active.');

        $requesterUuid = strtolower(trim((string) ($requester['device_uuid'] ?? '')));
        $requesterRole = strtolower((string) ($requester['device_role'] ?? ''));
        $isManager = in_array($requesterRole, self::MANAGER_ROLES, true);
        $isSelf = $allowSelf
            && $targetDeviceUuid !== null
            && $requesterUuid !== ''
            && hash_equals($requesterUuid, $targetDeviceUuid);

        // Ordinary terminals may release/revoke only themselves. Managers must
        // prove their capability even for self-destructive lifecycle actions so
        // license_key + HWID alone cannot disable the store authority device.
        if ($isSelf && !$isManager) {
            return ['ok' => true, 'self' => true, 'requester' => $requester];
        }

        if (!$isManager) {
            return self::failure('permission_denied', 'Only an active Manager may perform this action.');
        }

        $stored = strtolower(trim((string) ($requester['certificate_fingerprint'] ?? '')));
        if (!preg_match('/^[a-f0-9]{64}$/', $stored)) {
            return self::failure('manager_auth_not_bootstrapped', 'Manager device authentication is not initialized. Reactivate the Manager device first.');
        }

        $token = trim((string) ($request['manager_auth_token'] ?? ''));
        $tokenCheck = self::verifyToken($token, $stored);
        if ($tokenCheck !== null) return $tokenCheck;

        return [
            'ok' => true,
            'self' => $isSelf,
            'requester' => $requester,
            'action' => $action,
        ];
    }

    /**
     * Authorize creation/transition of a Manager role.
     *
     * Allowed paths:
     * 1) exact registered Manager reactivation;
     * 2) capability-authenticated rebind of a softly released Manager;
     * 3) first manager_server on an unused/unbound Multi store;
     * 4) secure promotion of the exact legacy single_terminal to manager_server
     *    when Multi is enabled and no Manager Server exists yet;
     * 5) a new manager_terminal explicitly authorized by an existing Manager
     *    capability.
     */
    public static function authorizeManagerProvisioning(string $licenseKey, array $request, string $requestedRole): array
    {
        $requestedRole = strtolower(trim($requestedRole));
        if (!in_array($requestedRole, self::MANAGER_ROLES, true)) {
            return ['ok' => true, 'manager_role' => false];
        }

        $pdo = Database::pdo();
        $licenseStmt = $pdo->prepare('SELECT * FROM licenses WHERE license_key = ? LIMIT 1');
        $licenseStmt->execute([$licenseKey]);
        $license = $licenseStmt->fetch();
        if (!$license) return ['ok' => true, 'manager_role' => true];
        if ((int) ($license['multi_cashier'] ?? 0) !== 1) {
            return self::failure('multi_not_entitled', 'Multi-Cashier is not enabled for this license.');
        }

        $hwid = self::requiredString($request, 'hwid', 160);
        $deviceUuid = self::requiredUuid($request, 'device_uuid');
        $storeUuid = self::requiredUuid($request, 'store_uuid');
        $existingStmt = $pdo->prepare(
            'SELECT * FROM license_activations WHERE license_id = ? AND hwid = ? LIMIT 1'
        );
        $existingStmt->execute([(int) $license['id'], $hwid]);
        $existing = $existingStmt->fetch();

        $existingRole = $existing ? strtolower((string) ($existing['device_role'] ?? '')) : '';
        $existingUuid = $existing ? strtolower((string) ($existing['device_uuid'] ?? '')) : '';
        $existingStore = $existing ? strtolower((string) ($existing['store_uuid'] ?? '')) : '';
        $licenseStore = strtolower((string) ($license['store_uuid'] ?? ''));

        if ($existing
            && empty($existing['revoked_at'])
            && $existingRole === $requestedRole
            && in_array($existingRole, self::MANAGER_ROLES, true)
            && $existingUuid !== ''
            && hash_equals($existingUuid, $deviceUuid)) {
            return ['ok' => true, 'manager_role' => true, 'existing_manager' => true];
        }

        // Soft release clears the globally unique device/store UUIDs but keeps
        // the one-way capability fingerprint. Rebinding the same manager HWID
        // therefore requires possession of the original capability; a copied
        // license key/HWID pair is insufficient.
        if ($existing
            && (int) ($existing['is_active'] ?? 0) === 0
            && empty($existing['revoked_at'])
            && $existingRole === $requestedRole
            && $existingUuid === ''
            && $licenseStore !== ''
            && hash_equals($licenseStore, $storeUuid)) {
            $stored = strtolower(trim((string) ($existing['certificate_fingerprint'] ?? '')));
            if (!preg_match('/^[a-f0-9]{64}$/', $stored)) {
                return self::failure('manager_auth_not_bootstrapped', 'Released Manager authentication is not initialized.');
            }
            $token = trim((string) ($request['manager_auth_token'] ?? ''));
            $tokenCheck = self::verifyToken($token, $stored);
            if ($tokenCheck !== null) return $tokenCheck;
            return ['ok' => true, 'manager_role' => true, 'released_manager_rebind' => true];
        }

        if ($requestedRole === 'manager_server') {
            // Fresh Multi store bootstrap.
            if (empty($license['store_uuid'])) {
                $count = $pdo->prepare(
                    'SELECT COUNT(*) FROM license_activations
                     WHERE license_id = ? AND is_active = 1 AND revoked_at IS NULL'
                );
                $count->execute([(int) $license['id']]);
                if ((int) $count->fetchColumn() === 0) {
                    return ['ok' => true, 'manager_role' => true, 'bootstrap_manager' => true];
                }
            }

            // Existing Single-POS -> Multi upgrade. Only the exact registered
            // single terminal may become the first manager_server.
            $managerCount = $pdo->prepare(
                "SELECT COUNT(*) FROM license_activations
                 WHERE license_id = ? AND is_active = 1 AND revoked_at IS NULL
                   AND device_role = 'manager_server'"
            );
            $managerCount->execute([(int) $license['id']]);
            $noManagerServer = (int) $managerCount->fetchColumn() === 0;
            $sameStore = $licenseStore !== '' && hash_equals($licenseStore, $storeUuid)
                && ($existingStore === '' || hash_equals($existingStore, $storeUuid));
            if ($noManagerServer
                && $sameStore
                && $existing
                && (int) ($existing['is_active'] ?? 0) === 1
                && empty($existing['revoked_at'])
                && $existingRole === 'single_terminal'
                && $existingUuid !== ''
                && hash_equals($existingUuid, $deviceUuid)) {
                return ['ok' => true, 'manager_role' => true, 'legacy_manager_promotion' => true];
            }

            return self::failure('manager_server_already_established', 'This store already has an established Manager Server or this device is not the registered upgrade device.');
        }

        $requesterHwid = trim((string) ($request['requester_hwid'] ?? ''));
        if ($requesterHwid === '') {
            return self::failure('manager_authorization_required', 'A registered Manager must authorize this manager role.');
        }

        $authRequest = $request;
        $authRequest['license_key'] = $licenseKey;
        $auth = self::authorizeAction($authRequest, 'manager_provision', null, false);
        if (!($auth['ok'] ?? false)) return $auth;

        return ['ok' => true, 'manager_role' => true, 'authorized_by_manager' => true];
    }

    private static function verifyToken(string $token, string $storedFingerprint): ?array
    {
        if (!preg_match('/^hma1_[a-f0-9]{64}$/', $token)) {
            return self::failure('manager_auth_required', 'Manager device authentication is required.');
        }
        if (!hash_equals($storedFingerprint, self::fingerprint($token))) {
            return self::failure('manager_auth_invalid', 'Manager device authentication failed.');
        }
        return null;
    }

    private static function fingerprint(string $token): string
    {
        return hash('sha256', self::TOKEN_DOMAIN . $token);
    }

    private static function requiredString(array $request, string $key, int $max): string
    {
        $value = trim((string) ($request[$key] ?? ''));
        if ($value === '' || strlen($value) > $max || preg_match('/[\x00-\x1F\x7F]/', $value)) {
            throw new InvalidArgumentException("Invalid {$key}.");
        }
        return $value;
    }

    private static function requiredUuid(array $request, string $key): string
    {
        $value = strtolower(trim((string) ($request[$key] ?? '')));
        if (!self::isUuid($value)) throw new InvalidArgumentException("Invalid {$key}.");
        return $value;
    }

    private static function isUuid(string $value): bool
    {
        return (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $value);
    }

    private static function failure(string $status, string $error): array
    {
        return ['ok' => false, 'status' => $status, 'error' => $error];
    }
}
