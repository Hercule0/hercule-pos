<?php

require_once __DIR__ . '/Database.php';

final class LicenseLifecycle
{
    private const PLANS = ['trial', 'monthly', 'semi_annual', 'annual', 'custom', 'lifetime'];

    public static function extendDays(int $licenseId, int $days, string $adminUsername): array
    {
        if ($days < 1 || $days > 3650) {
            throw new InvalidArgumentException('Extension must be between 1 and 3650 days.');
        }

        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $license = self::findLicense($licenseId, true);
            if ($license['expires_at'] === null) {
                throw new InvalidArgumentException('Lifetime licenses do not need an expiry extension.');
            }

            $base = strtotime($license['expires_at']) > time()
                ? new DateTime($license['expires_at'])
                : new DateTime();
            $newExpiry = (clone $base)->modify("+{$days} days")->format('Y-m-d H:i:s');

            $stmt = $pdo->prepare("UPDATE licenses SET expires_at = ?, status = 'active' WHERE id = ?");
            $stmt->execute([$newExpiry, $licenseId]);
            self::logEvent($licenseId, 'extended', $license['expires_at'], $newExpiry, "Extended by {$days} day(s)", $adminUsername);
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
        if (!in_array($plan, self::PLANS, true)) {
            throw new InvalidArgumentException('Invalid license plan.');
        }
        if ($plan === 'custom' && ($customDays === null || $customDays < 1 || $customDays > 3650)) {
            throw new InvalidArgumentException('Custom plan duration must be between 1 and 3650 days.');
        }

        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $license = self::findLicense($licenseId, true);
            $previousPlan = $license['plan'];
            $previousExpiry = $license['expires_at'];

            // A plan change starts a fresh term from now. Keeping the old expiry when
            // moving monthly -> annual (or trial -> monthly) would change only the
            // label while leaving the actual entitlement duration unchanged.
            $newExpiry = self::computeExpiry($plan, $customDays);

            $stmt = $pdo->prepare("UPDATE licenses SET plan = ?, expires_at = ?, status = 'active' WHERE id = ?");
            $stmt->execute([$plan, $newExpiry, $licenseId]);
            $note = "Plan changed from {$previousPlan} to {$plan}";
            if ($plan === 'custom') $note .= " ({$customDays} days)";
            self::logEvent($licenseId, 'plan_changed', $previousExpiry, $newExpiry, $note, $adminUsername);
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
        if ($maxActivations < 1 || $maxActivations > 100) {
            throw new InvalidArgumentException('Device limit must be between 1 and 100.');
        }

        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            // Locking the license row serializes this change with License::activate(),
            // which locks the same row before counting/adding devices in production.
            $license = self::findLicense($licenseId, true);
            $countStmt = $pdo->prepare('SELECT COUNT(*) FROM license_activations WHERE license_id = ? AND is_active = 1');
            $countStmt->execute([$licenseId]);
            $activeCount = (int) $countStmt->fetchColumn();
            if ($maxActivations < $activeCount) {
                throw new InvalidArgumentException("Device limit cannot be lower than the {$activeCount} currently active device(s).");
            }

            $previous = (int) $license['max_activations'];
            $stmt = $pdo->prepare('UPDATE licenses SET max_activations = ? WHERE id = ?');
            $stmt->execute([$maxActivations, $licenseId]);
            self::logEvent($licenseId, 'activation_limit_changed', null, null, "Device limit changed from {$previous} to {$maxActivations}", $adminUsername);
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
            if (!$newCustomer) {
                throw new InvalidArgumentException('Target customer was not found.');
            }
            if ((int) $license['customer_id'] === $customerId) {
                throw new InvalidArgumentException('License already belongs to the selected customer.');
            }

            $oldStmt = $pdo->prepare('SELECT name FROM customers WHERE id = ?');
            $oldStmt->execute([(int) $license['customer_id']]);
            $oldName = (string) ($oldStmt->fetchColumn() ?: ('Customer #' . $license['customer_id']));

            $stmt = $pdo->prepare('UPDATE licenses SET customer_id = ? WHERE id = ?');
            $stmt->execute([$customerId, $licenseId]);
            self::logEvent($licenseId, 'customer_transferred', null, null, "Transferred from {$oldName} to {$newCustomer['name']}", $adminUsername);
            // Force active desktop clients to perform a fresh signed validation so any
            // ownership/customer metadata derived from this license is not stale.
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
        if (mb_strlen($notes) > 2000) {
            throw new InvalidArgumentException('Notes cannot exceed 2000 characters.');
        }

        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            self::findLicense($licenseId, true);
            $stmt = $pdo->prepare('UPDATE licenses SET notes = ? WHERE id = ?');
            $stmt->execute([$notes !== '' ? $notes : null, $licenseId]);
            self::logEvent($licenseId, 'notes_updated', null, null, 'License notes updated', $adminUsername);
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
}
