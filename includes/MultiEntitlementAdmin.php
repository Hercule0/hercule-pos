<?php
/**
 * Fix408 / Multi Phase 1 — trusted administrative entitlement editor.
 *
 * Keeps the old max_activations contract compatible while exposing the
 * explicit Multi-Cashier entitlement required by the v1.3 master plan.
 */
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/EntitlementV2.php';

final class MultiEntitlementAdmin
{
    public static function update(
        int $licenseId,
        bool $multiCashier,
        int $maxTerminals,
        int $maxManagementDevices,
        string $adminUsername
    ): array {
        if (!EntitlementV2::schemaReady()) {
            throw new RuntimeException('Multi entitlement schema is not installed. Run the Fix408 migration first.');
        }
        if ($maxTerminals < 1 || $maxTerminals > 100) {
            throw new InvalidArgumentException('Terminal limit must be between 1 and 100.');
        }
        if ($maxManagementDevices < 1 || $maxManagementDevices > 20) {
            throw new InvalidArgumentException('Management-device limit must be between 1 and 20.');
        }
        if ($multiCashier && $maxTerminals < 2) {
            throw new InvalidArgumentException('Multi-Cashier requires at least 2 terminal seats.');
        }
        if (!$multiCashier) {
            $maxTerminals = 1;
        }

        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $lock = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
            $stmt = $pdo->prepare('SELECT * FROM licenses WHERE id = ?' . $lock);
            $stmt->execute([$licenseId]);
            $license = $stmt->fetch();
            if (!$license) {
                throw new InvalidArgumentException('License not found.');
            }

            $terminalStmt = $pdo->prepare(
                'SELECT COUNT(*) FROM license_activations
                 WHERE license_id = ? AND is_active = 1 AND revoked_at IS NULL AND counts_as_terminal = 1'
            );
            $terminalStmt->execute([$licenseId]);
            $activeTerminals = (int) $terminalStmt->fetchColumn();

            $managementStmt = $pdo->prepare(
                'SELECT COUNT(*) FROM license_activations
                 WHERE license_id = ? AND is_active = 1 AND revoked_at IS NULL AND counts_as_terminal = 0'
            );
            $managementStmt->execute([$licenseId]);
            $activeManagement = (int) $managementStmt->fetchColumn();

            if ($maxTerminals < $activeTerminals) {
                throw new InvalidArgumentException(
                    "Terminal limit cannot be lower than the {$activeTerminals} active terminal(s)."
                );
            }
            if ($maxManagementDevices < $activeManagement) {
                throw new InvalidArgumentException(
                    "Management-device limit cannot be lower than the {$activeManagement} active management device(s)."
                );
            }
            if (!$multiCashier && $activeTerminals > 1) {
                throw new InvalidArgumentException(
                    'Multi-Cashier cannot be disabled while more than one terminal is active. Revoke/deactivate extra terminals first.'
                );
            }

            $features = json_decode((string) ($license['features_json'] ?? ''), true);
            if (!is_array($features)) $features = [];
            $features['multi_cashier'] = $multiCashier;
            if (!array_key_exists('offline_sale', $features)) $features['offline_sale'] = true;
            $featuresJson = json_encode($features, JSON_UNESCAPED_SLASHES);
            if ($featuresJson === false) {
                throw new RuntimeException('Unable to encode entitlement features.');
            }

            // max_activations mirrors terminal seats for old v1 clients. The
            // Fix408 DB trigger keeps max_terminals synchronized when legacy
            // admin paths edit max_activations directly.
            $update = $pdo->prepare(
                'UPDATE licenses
                 SET multi_cashier = ?, max_terminals = ?, max_management_devices = ?,
                     max_activations = ?, features_json = ?
                 WHERE id = ?'
            );
            $update->execute([
                $multiCashier ? 1 : 0,
                $maxTerminals,
                $maxManagementDevices,
                $maxTerminals,
                $featuresJson,
                $licenseId,
            ]);

            $detail = sprintf(
                'Multi-Cashier %s; terminal seats %d; management seats %d',
                $multiCashier ? 'enabled' : 'disabled',
                $maxTerminals,
                $maxManagementDevices
            );
            try {
                $event = $pdo->prepare(
                    'INSERT INTO subscription_events (license_id, event_type, note, created_by)
                     VALUES (?, ?, ?, ?)'
                );
                $event->execute([$licenseId, 'multi_entitlement_changed', $detail, $adminUsername]);
            } catch (Throwable $ignored) {
            }
            try {
                $notify = $pdo->prepare(
                    'INSERT INTO license_change_notifications (license_key) VALUES (?)'
                );
                $notify->execute([(string) $license['license_key']]);
            } catch (Throwable $ignored) {
            }
            try {
                $actor = $pdo->prepare('SELECT id FROM admin_users WHERE username = ? LIMIT 1');
                $actor->execute([$adminUsername]);
                $actorId = $actor->fetchColumn();
                $audit = $pdo->prepare(
                    'INSERT INTO admin_audit_log (actor_id, target_id, action, details, ip_address)
                     VALUES (?, ?, ?, ?, ?)'
                );
                $audit->execute([
                    $actorId !== false ? (int) $actorId : null,
                    $licenseId,
                    'license_multi_entitlement_changed',
                    mb_substr($detail, 0, 255),
                    $_SERVER['REMOTE_ADDR'] ?? null,
                ]);
            } catch (Throwable $ignored) {
            }

            $pdo->commit();
            $fresh = $pdo->prepare('SELECT * FROM licenses WHERE id = ?');
            $fresh->execute([$licenseId]);
            return $fresh->fetch() ?: [];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }
}
