<?php

require_once __DIR__ . '/Database.php';

final class LicenseLifecycle
{
    private const PLANS = ['trial', 'monthly', 'semi_annual', 'annual', 'custom', 'lifetime'];

    private static function entitlementSchemaReady(): bool
    {
        $pdo = Database::pdo();
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $required = ['multi_cashier','max_terminals','max_management_devices','features_json','entitlement_version'];
        if ($driver === 'mysql') {
            $ph = implode(',', array_fill(0, count($required), '?'));
            $stmt = $pdo->prepare(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'licenses' AND COLUMN_NAME IN ({$ph})"
            );
            $stmt->execute($required);
            return (int) $stmt->fetchColumn() === count($required);
        }
        if ($driver === 'sqlite') {
            $rows = $pdo->query('PRAGMA table_info(licenses)')->fetchAll(PDO::FETCH_ASSOC);
            $columns = array_column($rows, 'name');
            foreach ($required as $column) if (!in_array($column, $columns, true)) return false;
            return true;
        }
        return false;
    }

    public static function extendDays(int $licenseId, int $days, string $adminUsername): array
    {
        if ($days < 1 || $days > 3650) throw new InvalidArgumentException('Extension must be between 1 and 3650 days.');
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $license = self::findLicense($licenseId, true);
            if ($license['expires_at'] === null) throw new InvalidArgumentException('Lifetime licenses do not need an expiry extension.');
            $base = strtotime($license['expires_at']) > time() ? new DateTime($license['expires_at']) : new DateTime();
            $newExpiry = (clone $base)->modify("+{$days} days")->format('Y-m-d H:i:s');
            $stmt = $pdo->prepare("UPDATE licenses SET expires_at = ?, status = 'active' WHERE id = ?");
            $stmt->execute([$newExpiry, $licenseId]);
            $detail = "Extended by {$days} day(s)";
            self::logEvent($licenseId, 'extended', $license['expires_at'], $newExpiry, $detail, $adminUsername);
            self::audit($adminUsername, $licenseId, 'license_extended', $detail);
            self::notifyChange($license['license_key']);
            $pdo->commit();
            return self::findLicense($licenseId);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    public static function changePlan(int $licenseId, string $plan, string $adminUsername, ?int $customDays = null): array
    {
        if (!in_array($plan, self::PLANS, true)) throw new InvalidArgumentException('Invalid license plan.');
        if ($plan === 'custom' && ($customDays === null || $customDays < 1 || $customDays > 3650)) {
            throw new InvalidArgumentException('Custom plan duration must be between 1 and 3650 days.');
        }
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $license = self::findLicense($licenseId, true);
            $previousPlan = $license['plan'];
            $previousExpiry = $license['expires_at'];
            $newExpiry = self::computeExpiry($plan, $customDays);
            $stmt = $pdo->prepare("UPDATE licenses SET plan = ?, expires_at = ?, status = 'active' WHERE id = ?");
            $stmt->execute([$plan, $newExpiry, $licenseId]);
            $note = "Plan changed from {$previousPlan} to {$plan}";
            if ($plan === 'custom') $note .= " ({$customDays} days)";
            self::logEvent($licenseId, 'plan_changed', $previousExpiry, $newExpiry, $note, $adminUsername);
            self::audit($adminUsername, $licenseId, 'license_plan_changed', $note);
            self::notifyChange($license['license_key']);
            $pdo->commit();
            return self::findLicense($licenseId);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    public static function updateActivationLimit(int $licenseId, int $maxActivations, string $adminUsername): array
    {
        if ($maxActivations < 1 || $maxActivations > 100) throw new InvalidArgumentException('Device limit must be between 1 and 100.');
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $license = self::findLicense($licenseId, true);
            if (self::entitlementSchemaReady() && !empty($license['multi_cashier'])) {
                $countStmt = $pdo->prepare('SELECT COUNT(*) FROM license_activations WHERE license_id = ? AND is_active = 1 AND counts_as_terminal = 1');
            } else {
                $countStmt = $pdo->prepare('SELECT COUNT(*) FROM license_activations WHERE license_id = ? AND is_active = 1');
            }
            $countStmt->execute([$licenseId]);
            $activeCount = (int) $countStmt->fetchColumn();
            if ($maxActivations < $activeCount) {
                throw new InvalidArgumentException("Device limit cannot be lower than the {$activeCount} currently active device(s).");
            }

            $previous = (int) $license['max_activations'];
            if (self::entitlementSchemaReady() && !empty($license['multi_cashier'])) {
                $stmt = $pdo->prepare(
                    'UPDATE licenses
                     SET max_activations = ?, max_terminals = ?, entitlement_version = entitlement_version + 1
                     WHERE id = ?'
                );
                $stmt->execute([$maxActivations, $maxActivations, $licenseId]);
            } else {
                $stmt = $pdo->prepare('UPDATE licenses SET max_activations = ? WHERE id = ?');
                $stmt->execute([$maxActivations, $licenseId]);
            }
            $detail = "Device limit changed from {$previous} to {$maxActivations}";
            self::logEvent($licenseId, 'activation_limit_changed', null, null, $detail, $adminUsername);
            self::audit($adminUsername, $licenseId, 'license_activation_limit_changed', $detail);
            self::notifyChange($license['license_key']);
            $pdo->commit();
            return self::findLicense($licenseId);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    public static function updateMultiEntitlement(
        int $licenseId,
        bool $enabled,
        int $maxTerminals,
        int $maxManagementDevices,
        string $adminUsername
    ): array {
        if (!self::entitlementSchemaReady()) throw new RuntimeException('Entitlement v2 migration has not been run yet.');
        if ($maxTerminals < 1 || $maxTerminals > 100) throw new InvalidArgumentException('Terminal limit must be between 1 and 100.');
        if ($maxManagementDevices < 0 || $maxManagementDevices > 100) throw new InvalidArgumentException('Management-device limit must be between 0 and 100.');
        if (!$enabled) $maxTerminals = 1;

        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $license = self::findLicense($licenseId, true);
            $terminalStmt = $pdo->prepare(
                'SELECT COUNT(*) FROM license_activations
                 WHERE license_id = ? AND is_active = 1 AND counts_as_terminal = 1'
            );
            $terminalStmt->execute([$licenseId]);
            $activeTerminals = (int) $terminalStmt->fetchColumn();
            $managementStmt = $pdo->prepare(
                'SELECT COUNT(*) FROM license_activations
                 WHERE license_id = ? AND is_active = 1 AND counts_as_terminal = 0'
            );
            $managementStmt->execute([$licenseId]);
            $activeManagement = (int) $managementStmt->fetchColumn();

            if ($maxTerminals < $activeTerminals) {
                throw new InvalidArgumentException("Terminal limit cannot be lower than the {$activeTerminals} active terminal(s).");
            }
            if ($maxManagementDevices < $activeManagement) {
                throw new InvalidArgumentException("Management-device limit cannot be lower than the {$activeManagement} active management device(s).");
            }

            $features = [
                'multi_cashier' => $enabled,
                'offline_sale' => true,
            ];
            $featuresJson = json_encode($features, JSON_UNESCAPED_SLASHES);
            if ($featuresJson === false) throw new RuntimeException('Failed to encode entitlement features.');

            $previousEnabled = !empty($license['multi_cashier']);
            $previousTerminals = (int) ($license['max_terminals'] ?? 1);
            $stmt = $pdo->prepare(
                'UPDATE licenses
                 SET multi_cashier = ?,
                     max_terminals = ?,
                     max_management_devices = ?,
                     max_activations = ?,
                     features_json = ?,
                     entitlement_version = entitlement_version + 1
                 WHERE id = ?'
            );
            $stmt->execute([
                $enabled ? 1 : 0,
                $maxTerminals,
                $maxManagementDevices,
                $maxTerminals,
                $featuresJson,
                $licenseId,
            ]);

            $detail = sprintf(
                'Multi entitlement %s; terminals %d→%d; management devices=%d',
                $enabled ? 'enabled' : 'disabled',
                $previousTerminals,
                $maxTerminals,
                $maxManagementDevices
            );
            if ($previousEnabled !== $enabled) {
                $detail .= $previousEnabled ? '; previously enabled' : '; previously disabled';
            }
            self::logEvent($licenseId, 'multi_entitlement_changed', null, null, $detail, $adminUsername);
            self::audit($adminUsername, $licenseId, 'license_multi_entitlement_changed', $detail);
            self::notifyChange($license['license_key']);
            $pdo->commit();
            return self::findLicense($licenseId);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    public static function transferCustomer(int $licenseId, int $customerId, string $adminUsername): array
    {
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $license = self::findLicense($licenseId, true);
            $customerStmt = $pdo->prepare('SELECT id, name FROM customers WHERE id = ?');
            $customerStmt->execute([$customerId]);
            $newCustomer = $customerStmt->fetch();
            if (!$newCustomer) throw new InvalidArgumentException('Target customer was not found.');
            if ((int) $license['customer_id'] === $customerId) throw new InvalidArgumentException('License already belongs to the selected customer.');
            $oldStmt = $pdo->prepare('SELECT name FROM customers WHERE id = ?');
            $oldStmt->execute([(int) $license['customer_id']]);
            $oldName = (string) ($oldStmt->fetchColumn() ?: ('Customer #' . $license['customer_id']));
            $stmt = $pdo->prepare('UPDATE licenses SET customer_id = ? WHERE id = ?');
            $stmt->execute([$customerId, $licenseId]);
            $detail = "Transferred from {$oldName} to {$newCustomer['name']}";
            self::logEvent($licenseId, 'customer_transferred', null, null, $detail, $adminUsername);
            self::audit($adminUsername, $licenseId, 'license_customer_transferred', $detail);
            self::notifyChange($license['license_key']);
            $pdo->commit();
            return self::findLicense($licenseId);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    public static function updateNotes(int $licenseId, ?string $notes, string $adminUsername): array
    {
        $notes = trim((string) $notes);
        if (mb_strlen($notes) > 2000) throw new InvalidArgumentException('Notes cannot exceed 2000 characters.');
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            self::findLicense($licenseId, true);
            $stmt = $pdo->prepare('UPDATE licenses SET notes = ? WHERE id = ?');
            $stmt->execute([$notes !== '' ? $notes : null, $licenseId]);
            self::logEvent($licenseId, 'notes_updated', null, null, 'License notes updated', $adminUsername);
            self::audit($adminUsername, $licenseId, 'license_notes_updated', 'License notes updated');
            $pdo->commit();
            return self::findLicense($licenseId);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    private static function findLicense(int $licenseId, bool $forUpdate = false): array
    {
        $pdo = Database::pdo();
        $lock = $forUpdate && $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
        $stmt = $pdo->prepare('SELECT * FROM licenses WHERE id = ?' . $lock);
        $stmt->execute([$licenseId]);
        $license = $stmt->fetch();
        if (!$license) throw new InvalidArgumentException('License not found.');
        return $license;
    }

    private static function computeExpiry(string $plan, ?int $customDays): ?string
    {
        $dt = new DateTime();
        switch ($plan) {
            case 'trial': $dt->modify('+10 days'); break;
            case 'monthly': $dt->modify('+1 month'); break;
            case 'semi_annual': $dt->modify('+6 months'); break;
            case 'annual': $dt->modify('+1 year'); break;
            case 'custom': $dt->modify('+' . (int) $customDays . ' days'); break;
            case 'lifetime': return null;
            default: throw new InvalidArgumentException('Invalid license plan.');
        }
        return $dt->format('Y-m-d H:i:s');
    }

    private static function notifyChange(string $licenseKey): void
    {
        $stmt = Database::pdo()->prepare('INSERT INTO license_change_notifications (license_key) VALUES (?)');
        $stmt->execute([$licenseKey]);
    }

    private static function logEvent(int $licenseId, string $eventType, ?string $previousExpiry, ?string $newExpiry, ?string $note, string $adminUsername): void
    {
        $stmt = Database::pdo()->prepare(
            'INSERT INTO subscription_events (license_id, event_type, previous_expires_at, new_expires_at, note, created_by) VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$licenseId, $eventType, $previousExpiry, $newExpiry, $note, $adminUsername]);
    }

    private static function audit(string $adminUsername, int $licenseId, string $action, string $details): void
    {
        try {
            $pdo = Database::pdo();
            $actor = $pdo->prepare('SELECT id FROM admin_users WHERE username = ? LIMIT 1');
            $actor->execute([$adminUsername]);
            $actorId = $actor->fetchColumn();
            $stmt = $pdo->prepare(
                'INSERT INTO admin_audit_log (actor_id, target_id, action, details, ip_address) VALUES (?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $actorId !== false ? (int) $actorId : null,
                $licenseId,
                $action,
                mb_substr($details, 0, 255),
                $_SERVER['REMOTE_ADDR'] ?? null,
            ]);
        } catch (Throwable $e) {
            error_log('License lifecycle audit write failed: ' . $e->getMessage());
        }
    }
}
