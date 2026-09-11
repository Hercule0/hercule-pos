<?php
/**
 * Fix408 / Fix496 — activation policy shared by the v2 activation API.
 *
 * - A non-Multi license may never activate a second concurrent terminal.
 * - A device may not promote itself into a Manager role merely by sending
 *   device_role=manager_*.
 * - manager_server may bootstrap only an unused/unbound store.
 * - Any NEW manager identity after bootstrap requires authorization from an
 *   already-active Manager device capability.
 */
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/ManagerDeviceAuth.php';

final class MultiEntitlementPolicy
{
    private const TERMINAL_ROLES = ['single_terminal', 'manager_terminal', 'cashier_terminal'];
    private const MANAGER_ROLES = ['manager_server', 'manager_terminal'];

    public static function preflightActivation(array $request): array
    {
        $licenseKey = trim((string) ($request['license_key'] ?? ''));
        $hwid = trim((string) ($request['hwid'] ?? ''));
        $role = strtolower(trim((string) ($request['device_role'] ?? 'single_terminal')));

        if ($licenseKey === '' || $hwid === '') {
            return ['ok' => true];
        }

        if (in_array($role, self::MANAGER_ROLES, true)) {
            try {
                $managerPolicy = ManagerDeviceAuth::authorizeManagerProvisioning($licenseKey, $request, $role);
            } catch (InvalidArgumentException $e) {
                return [
                    'ok' => false,
                    'status' => 'invalid_manager_identity',
                    'error' => $e->getMessage(),
                ];
            }
            if (!($managerPolicy['ok'] ?? false)) return $managerPolicy;
        }

        if (!in_array($role, self::TERMINAL_ROLES, true)) {
            return ['ok' => true];
        }

        $pdo = Database::pdo();
        $stmt = $pdo->prepare('SELECT id, multi_cashier FROM licenses WHERE license_key = ? LIMIT 1');
        $stmt->execute([$licenseKey]);
        $license = $stmt->fetch();
        if (!$license || (int) ($license['multi_cashier'] ?? 0) === 1) {
            return ['ok' => true];
        }

        // Allow the same existing terminal to validate/reactivate/upgrade its
        // identity. Only another concurrently active terminal is forbidden.
        $count = $pdo->prepare(
            'SELECT COUNT(*) FROM license_activations
             WHERE license_id = ? AND is_active = 1 AND revoked_at IS NULL
               AND counts_as_terminal = 1 AND hwid <> ?'
        );
        $count->execute([(int) $license['id'], $hwid]);
        if ((int) $count->fetchColumn() > 0) {
            return [
                'ok' => false,
                'status' => 'multi_not_entitled',
                'error' => 'Multi-Cashier is not enabled for this license.',
            ];
        }

        return ['ok' => true];
    }
}
