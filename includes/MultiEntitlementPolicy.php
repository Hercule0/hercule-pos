<?php
/**
 * Fix408 / Multi Phase 1 — activation policy shared by the v2 activation API.
 *
 * A license without multi_cashier may keep/replace its single POS terminal,
 * but it can never activate a second concurrent terminal even if stale legacy
 * limits were configured incorrectly. Management-only seats remain separate.
 */
require_once __DIR__ . '/Database.php';

final class MultiEntitlementPolicy
{
    private const TERMINAL_ROLES = ['single_terminal', 'manager_terminal', 'cashier_terminal'];

    public static function preflightActivation(array $request): array
    {
        $licenseKey = trim((string) ($request['license_key'] ?? ''));
        $hwid = trim((string) ($request['hwid'] ?? ''));
        $role = trim((string) ($request['device_role'] ?? 'single_terminal'));

        if ($licenseKey === '' || $hwid === '' || !in_array($role, self::TERMINAL_ROLES, true)) {
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
