<?php
/**
 * Core license business logic. All license lifecycle operations go
 * through here — the admin panel and the public API endpoints are both
 * thin wrappers around these methods.
 */

require_once __DIR__ . '/Database.php';

final class License
{
    /** Characters chosen to avoid visual ambiguity (no 0/O, 1/I/L). */
    private const KEY_ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    /** Generates a license key like XXXX-XXXX-XXXX-XXXX-XXXX. */
    public static function generateKey(): string
    {
        $groups = [];
        for ($g = 0; $g < 5; $g++) {
            $chars = '';
            for ($i = 0; $i < 4; $i++) {
                $chars .= self::KEY_ALPHABET[random_int(0, strlen(self::KEY_ALPHABET) - 1)];
            }
            $groups[] = $chars;
        }
        return implode('-', $groups);
    }

    /**
     * Computes the expiry datetime for a plan, relative to now.
     * Returns null for lifetime (never expires).
     *
     * @param int|null $customDays Required when $plan === 'custom'. Any
     *                             positive integer — 1 day, 2 days, 400
     *                             days, whatever the admin wants.
     */
    public static function computeExpiry(string $plan, ?DateTime $from = null, ?int $customDays = null): ?string
    {
        $from = $from ?? new DateTime();
        $dt = clone $from;

        if ($plan === 'custom') {
            if ($customDays === null || $customDays < 1) {
                throw new InvalidArgumentException('Custom plan requires a positive number of days.');
            }
            $dt->modify("+{$customDays} days");
            return $dt->format('Y-m-d H:i:s');
        }

        switch ($plan) {
            case 'trial':
                $dt->modify('+10 days');
                break;
            case 'monthly':
                $dt->modify('+1 month');
                break;
            case 'semi_annual':
                $dt->modify('+6 months');
                break;
            case 'annual':
                $dt->modify('+1 year');
                break;
            case 'lifetime':
                return null;
            default:
                throw new InvalidArgumentException("Unknown plan: {$plan}");
        }

        return $dt->format('Y-m-d H:i:s');
    }

    /**
     * Issues a brand-new license for a customer.
     * @return array the newly created license row
     */
    public static function issue(
        int $customerId,
        string $plan,
        int $maxActivations = 1,
        ?string $notes = null,
        ?int $customDays = null
    ): array {
        $pdo = Database::pdo();
        $key = self::generateKey();
        $expiresAt = self::computeExpiry($plan, null, $customDays);

        if ($plan === 'custom') {
            $customNote = "Custom {$customDays}-day plan";
            $notes = $notes ? "{$customNote} — {$notes}" : $customNote;
        }

        $stmt = $pdo->prepare(
            'INSERT INTO licenses (customer_id, license_key, plan, status, max_activations, expires_at, notes)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$customerId, $key, $plan, 'active', $maxActivations, $expiresAt, $notes]);
        $licenseId = (int) $pdo->lastInsertId();

        self::logEvent($licenseId, 'issued', null, $expiresAt, "Issued as {$plan}", 'system');

        return self::findById($licenseId);
    }

    public static function findById(int $id): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM licenses WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function findByKey(string $key): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM licenses WHERE license_key = ?');
        $stmt->execute([$key]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function activationsFor(int $licenseId): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT * FROM license_activations WHERE license_id = ? ORDER BY activated_at ASC'
        );
        $stmt->execute([$licenseId]);
        return $stmt->fetchAll();
    }

    private static function activeActivationCount(int $licenseId): int
    {
        $stmt = Database::pdo()->prepare(
            'SELECT COUNT(*) FROM license_activations WHERE license_id = ? AND is_active = 1'
        );
        $stmt->execute([$licenseId]);
        return (int) $stmt->fetchColumn();
    }

    private static function findActivation(int $licenseId, string $hwid): ?array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT * FROM license_activations WHERE license_id = ? AND hwid = ?'
        );
        $stmt->execute([$licenseId, $hwid]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    private static function isExpired(array $license): bool
    {
        if ($license['expires_at'] === null) {
            return false; // lifetime
        }
        return strtotime($license['expires_at']) < time();
    }

    /**
     * First-time activation: binds a HWID to a license, subject to the
     * max_activations limit. Re-activating the SAME hwid is idempotent
     * (just refreshes last_seen_at) rather than consuming another slot.
     *
     * @return array{ok: bool, error?: string, license?: array}
     */
    public static function activate(string $licenseKey, string $hwid, ?string $ip = null): array
    {
        $license = self::findByKey($licenseKey);
        if (!$license) {
            self::log(null, $licenseKey, $hwid, 'invalid_key', $ip);
            return ['ok' => false, 'error' => 'Invalid license key.'];
        }

        if ($license['status'] !== 'active') {
            self::log((int) $license['id'], $licenseKey, $hwid, $license['status'], $ip);
            return ['ok' => false, 'error' => "This license is {$license['status']}."];
        }

        if (self::isExpired($license)) {
            self::markExpiredIfNeeded($license);
            self::log((int) $license['id'], $licenseKey, $hwid, 'expired', $ip);
            return ['ok' => false, 'error' => 'This license has expired.'];
        }

        $existing = self::findActivation((int) $license['id'], $hwid);
        if ($existing) {
            $stmt = Database::pdo()->prepare(
                'UPDATE license_activations SET is_active = 1, last_seen_at = CURRENT_TIMESTAMP, ip_address = ? WHERE id = ?'
            );
            $stmt->execute([$ip, $existing['id']]);
            self::log((int) $license['id'], $licenseKey, $hwid, 'ok', $ip);
            return ['ok' => true, 'license' => self::findById((int) $license['id'])];
        }

        if (self::activeActivationCount((int) $license['id']) >= $license['max_activations']) {
            self::log((int) $license['id'], $licenseKey, $hwid, 'activation_limit', $ip);
            return ['ok' => false, 'error' => 'This license has reached its device activation limit.'];
        }

        $stmt = Database::pdo()->prepare(
            'INSERT INTO license_activations (license_id, hwid, ip_address) VALUES (?, ?, ?)'
        );
        $stmt->execute([$license['id'], $hwid, $ip]);

        self::log((int) $license['id'], $licenseKey, $hwid, 'ok', $ip);
        return ['ok' => true, 'license' => self::findById((int) $license['id'])];
    }

    /**
     * Runtime validation check — called on every app launch (or on the
     * periodic interval Phase 5 defines). Requires the HWID to already be
     * activated; does NOT consume a new activation slot.
     *
     * @return array{ok: bool, error?: string, license?: array}
     */
    public static function validate(string $licenseKey, string $hwid, ?string $ip = null): array
    {
        $license = self::findByKey($licenseKey);
        if (!$license) {
            self::log(null, $licenseKey, $hwid, 'invalid_key', $ip);
            return ['ok' => false, 'error' => 'Invalid license key.'];
        }

        if ($license['status'] !== 'active') {
            self::log((int) $license['id'], $licenseKey, $hwid, $license['status'], $ip);
            return ['ok' => false, 'error' => "This license is {$license['status']}.", 'license' => $license];
        }

        if (self::isExpired($license)) {
            self::markExpiredIfNeeded($license);
            self::log((int) $license['id'], $licenseKey, $hwid, 'expired', $ip);
            return ['ok' => false, 'error' => 'This license has expired.', 'license' => $license];
        }

        $activation = self::findActivation((int) $license['id'], $hwid);
        if (!$activation || !$activation['is_active']) {
            self::log((int) $license['id'], $licenseKey, $hwid, 'hwid_mismatch', $ip);
            return ['ok' => false, 'error' => 'This device is not activated for this license.'];
        }

        $stmt = Database::pdo()->prepare(
            'UPDATE license_activations SET last_seen_at = CURRENT_TIMESTAMP, ip_address = ? WHERE id = ?'
        );
        $stmt->execute([$ip, $activation['id']]);

        $stmt2 = Database::pdo()->prepare('UPDATE licenses SET last_verified_at = CURRENT_TIMESTAMP WHERE id = ?');
        $stmt2->execute([$license['id']]);

        self::log((int) $license['id'], $licenseKey, $hwid, 'ok', $ip);
        return ['ok' => true, 'license' => self::findById((int) $license['id'])];
    }

    private static function markExpiredIfNeeded(array $license): void
    {
        if ($license['status'] === 'active') {
            $stmt = Database::pdo()->prepare("UPDATE licenses SET status = 'expired' WHERE id = ?");
            $stmt->execute([$license['id']]);
        }
    }

    /**
     * Extends a license's expiry — e.g. after a renewal payment.
     * @param int|null $customDays Required when $plan === 'custom'.
     */
    public static function renew(int $licenseId, string $plan, string $adminUsername, ?int $customDays = null): array
    {
        $license = self::findById($licenseId);
        if (!$license) {
            throw new InvalidArgumentException('License not found.');
        }

        // Renewal extends from the LATER of (now, current expiry) so early
        // renewals don't lose remaining time.
        $base = $license['expires_at'] && strtotime($license['expires_at']) > time()
            ? new DateTime($license['expires_at'])
            : new DateTime();

        $newExpiry = self::computeExpiry($plan, $base, $customDays);
        $previousExpiry = $license['expires_at'];

        $note = $plan === 'custom' ? "Renewed as custom ({$customDays} days)" : "Renewed as {$plan}";

        $stmt = Database::pdo()->prepare(
            "UPDATE licenses SET plan = ?, expires_at = ?, status = 'active' WHERE id = ?"
        );
        $stmt->execute([$plan, $newExpiry, $licenseId]);

        self::logEvent($licenseId, 'renewed', $previousExpiry, $newExpiry, $note, $adminUsername);

        return self::findById($licenseId);
    }

    public static function suspend(int $licenseId, string $adminUsername): void
    {
        $stmt = Database::pdo()->prepare("UPDATE licenses SET status = 'suspended' WHERE id = ?");
        $stmt->execute([$licenseId]);
        self::logEvent($licenseId, 'suspended', null, null, null, $adminUsername);
    }

    public static function revoke(int $licenseId, string $adminUsername): void
    {
        $stmt = Database::pdo()->prepare("UPDATE licenses SET status = 'revoked' WHERE id = ?");
        $stmt->execute([$licenseId]);
        self::logEvent($licenseId, 'revoked', null, null, null, $adminUsername);
    }

    public static function reactivate(int $licenseId, string $adminUsername): void
    {
        $stmt = Database::pdo()->prepare("UPDATE licenses SET status = 'active' WHERE id = ?");
        $stmt->execute([$licenseId]);
        self::logEvent($licenseId, 'reactivated', null, null, null, $adminUsername);
    }

    /** Frees up an activation slot (e.g. customer got a new PC). */
    public static function deactivateDevice(int $activationId): void
    {
        $stmt = Database::pdo()->prepare('UPDATE license_activations SET is_active = 0 WHERE id = ?');
        $stmt->execute([$activationId]);
    }

    /**
     * PERMANENTLY deletes a license and all its history (activations,
     * subscription events cascade via FK; verification_log rows are kept
     * as orphaned historical records since they have no FK constraint).
     * This is different from revoke() — revoke keeps the record and just
     * blocks future validation; this removes it entirely.
     */
    public static function deleteLicense(int $licenseId): void
    {
        $stmt = Database::pdo()->prepare('DELETE FROM licenses WHERE id = ?');
        $stmt->execute([$licenseId]);
    }

    private static function log(?int $licenseId, string $licenseKey, ?string $hwid, string $result, ?string $ip): void
    {
        $stmt = Database::pdo()->prepare(
            'INSERT INTO verification_log (license_id, license_key, hwid, result, ip_address) VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$licenseId, $licenseKey, $hwid, $result, $ip]);
    }

    private static function logEvent(
        int $licenseId,
        string $eventType,
        ?string $previousExpiresAt,
        ?string $newExpiresAt,
        ?string $note,
        string $createdBy
    ): void {
        $stmt = Database::pdo()->prepare(
            'INSERT INTO subscription_events (license_id, event_type, previous_expires_at, new_expires_at, note, created_by)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$licenseId, $eventType, $previousExpiresAt, $newExpiresAt, $note, $createdBy]);
    }
}
