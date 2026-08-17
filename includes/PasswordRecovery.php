<?php
/**
 * Password Change Request System (see PASSWORD_RECOVERY_REQUEST_PLAN.md).
 *
 * Core principle: the server NEVER sees, stores, or discloses a plaintext
 * password. It only records who is asking, lets an admin approve or
 * reject, and — once approved — issues a short-lived, single-use
 * authorization token. Validating that token is the server's whole job;
 * the actual credential change always happens locally on the client
 * (Hercule POS's own db.Users.setPassword/updateUsername), because that
 * is the only place the account actually lives (Offline-First — there is
 * no central "users" table on this server, only per-store local SQLite).
 */

require_once __DIR__ . '/Database.php';

final class PasswordRecovery
{
    /** How long an approved authorization stays claimable/usable before it self-expires. */
    private const TOKEN_TTL_MINUTES = 30;

    private static function lockingClause(PDO $pdo): string
    {
        return $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
    }

    /**
     * Step 2 of the plan: user submits a recovery request. Tied to the
     * store's license_key + current hwid (the only account identifiers
     * this server has any authority over), plus the username they're
     * trying to recover, so the admin panel can show enough context to
     * verify identity without ever seeing a password.
     */
    public static function createRequest(string $licenseKey, string $hwid, string $username, ?string $ip): array
    {
        $pdo = Database::pdo();
        $pdo->beginTransaction();

        try {
            $licenseStmt = $pdo->prepare(
                "SELECT l.id
                 FROM licenses l
                 INNER JOIN license_activations a
                    ON a.license_id = l.id AND a.hwid = ? AND a.is_active = 1
                 WHERE l.license_key = ? AND l.status = 'active'
                   AND (l.expires_at IS NULL OR l.expires_at > CURRENT_TIMESTAMP)"
                . self::lockingClause($pdo)
            );
            $licenseStmt->execute([$hwid, $licenseKey]);

            if (!$licenseStmt->fetchColumn()) {
                $pdo->rollBack();
                return ['ok' => false, 'error' => 'تعذر التحقق من الترخيص والجهاز لهذا الطلب.'];
            }

            $duplicate = $pdo->prepare(
                "SELECT id FROM password_recovery_requests
                 WHERE license_key = ? AND requested_username = ? AND status = 'pending'
                 LIMIT 1" . self::lockingClause($pdo)
            );
            $duplicate->execute([$licenseKey, $username]);
            if ($duplicate->fetchColumn()) {
                $pdo->rollBack();
                return ['ok' => false, 'error' => 'يوجد طلب استرجاع معلّق بالفعل لهذا الحساب. يرجى الانتظار حتى تتم مراجعته.'];
            }

            $stmt = $pdo->prepare(
                'INSERT INTO password_recovery_requests (license_key, hwid, requested_username, status) VALUES (?, ?, ?, ?)'
            );
            $stmt->execute([$licenseKey, $hwid, $username, 'pending']);
            $id = (int) $pdo->lastInsertId();

            self::log($id, 'request_created', null, $ip, null);
            $pdo->commit();

            return ['ok' => true, 'request_id' => $id];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public static function findById(int $id): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM password_recovery_requests WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** Step 3 of the plan (client-facing status), scoped to license_key so one
     *  store can never poll another store's request by guessing an ID. */
    public static function statusFor(int $id, string $licenseKey): ?array
    {
        self::expireIfNeeded($id);

        $stmt = Database::pdo()->prepare(
            'SELECT id, status, created_at, reviewed_at FROM password_recovery_requests WHERE id = ? AND license_key = ?'
        );
        $stmt->execute([$id, $licenseKey]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** Admin panel listing — newest first, capped for a sane page size. */
    public static function allList(): array
    {
        return Database::pdo()->query(
            'SELECT * FROM password_recovery_requests ORDER BY created_at DESC LIMIT 300'
        )->fetchAll();
    }

    /**
     * Admin approves a pending request. Generates the authorization token
     * here purely so the admin panel *could* display/copy it for manual
     * support workflows if ever needed — but the normal path is the
     * client retrieving its own token via claim() below, independently.
     * Only the token's SHA-256 hash is ever persisted.
     */
    public static function approve(int $id, string $adminUsername, ?string $note): array
    {
        $token = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $token);
        $expiresAt = (new DateTime())->modify('+' . self::TOKEN_TTL_MINUTES . ' minutes')->format('Y-m-d H:i:s');

        $stmt = Database::pdo()->prepare(
            "UPDATE password_recovery_requests
             SET status = 'approved', token_hash = ?, token_expires_at = ?, admin_note = ?,
                 reviewed_by = ?, reviewed_at = CURRENT_TIMESTAMP
             WHERE id = ? AND status = 'pending'"
        );
        $stmt->execute([$tokenHash, $expiresAt, $note, $adminUsername, $id]);

        if ($stmt->rowCount() !== 1) {
            return ['ok' => false, 'error' => 'Request was already reviewed or does not exist.'];
        }

        self::log($id, 'request_approved', $adminUsername, null, $note);
        return ['ok' => true, 'token' => $token, 'expires_at' => $expiresAt];
    }

    public static function reject(int $id, string $adminUsername, ?string $note): array
    {
        $stmt = Database::pdo()->prepare(
            "UPDATE password_recovery_requests
             SET status = 'rejected', admin_note = ?, reviewed_by = ?, reviewed_at = CURRENT_TIMESTAMP
             WHERE id = ? AND status = 'pending'"
        );
        $stmt->execute([$note, $adminUsername, $id]);

        if ($stmt->rowCount() !== 1) {
            return ['ok' => false, 'error' => 'Request was already reviewed or does not exist.'];
        }

        self::log($id, 'request_rejected', $adminUsername, null, $note);
        return ['ok' => true];
    }

    /**
     * Client calls this once it independently learns status === 'approved'.
     * Issues a FRESH single-use token (invalidating whichever one the
     * admin-facing approve() call generated) and returns it exactly once —
     * "delivered" alone doesn't grant anything; reset() below still fully
     * re-validates hash + expiry + single-use state.
     *
     * hwid is checked against the ORIGINAL requesting device on purpose —
     * see plan §10 "Account and License Protection": recovery must not
     * quietly move an account to a different device.
     */
    public static function claim(int $id, string $licenseKey, string $hwid, ?string $ip): array
    {
        $pdo = Database::pdo();
        $pdo->beginTransaction();

        try {
            $stmt = $pdo->prepare(
                'SELECT * FROM password_recovery_requests WHERE id = ?' . self::lockingClause($pdo)
            );
            $stmt->execute([$id]);
            $request = $stmt->fetch();

            if (!$request || $request['license_key'] !== $licenseKey) {
                $pdo->rollBack();
                return ['ok' => false, 'error' => 'الطلب غير موجود.'];
            }
            if (!hash_equals($request['hwid'], $hwid)) {
                self::log($id, 'claim_device_mismatch', null, $ip, null);
                $pdo->commit();
                return ['ok' => false, 'error' => 'جهازك الحالي لا يطابق الجهاز الذي أُرسل منه الطلب الأصلي.'];
            }
            if ($request['status'] === 'approved' && $request['token_expires_at']
                && strtotime($request['token_expires_at']) < time()) {
                $pdo->prepare("UPDATE password_recovery_requests SET status = 'expired' WHERE id = ?")
                    ->execute([$id]);
                self::log($id, 'authorization_expired', null, $ip, null);
                $pdo->commit();
                return ['ok' => false, 'error' => 'انتهت صلاحية التفويض.'];
            }
            if ($request['status'] !== 'approved') {
                $pdo->rollBack();
                return ['ok' => false, 'error' => 'الطلب ليس معتمداً حالياً (الحالة: ' . self::arabicStatus($request['status']) . ').'];
            }
            if ($request['delivered_at'] !== null) {
                $pdo->rollBack();
                return ['ok' => false, 'error' => 'تم استلام التفويض مسبقاً.'];
            }

            $token = bin2hex(random_bytes(32));
            $tokenHash = hash('sha256', $token);
            $update = $pdo->prepare(
                'UPDATE password_recovery_requests
                 SET token_hash = ?, delivered_at = CURRENT_TIMESTAMP
                 WHERE id = ? AND status = ? AND delivered_at IS NULL'
            );
            $update->execute([$tokenHash, $id, 'approved']);

            if ($update->rowCount() !== 1) {
                $pdo->rollBack();
                return ['ok' => false, 'error' => 'تعذر استلام التفويض.'];
            }

            self::log($id, 'authorization_claimed', null, $ip, null);
            $pdo->commit();

            return ['ok' => true, 'token' => $token, 'expires_at' => $request['token_expires_at']];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Final step: consumes the single-use authorization. The new password
     * is NEVER part of this call — only proof that a legitimate,
     * un-expired, not-yet-used authorization exists. On success the
     * request is marked 'completed' so it can never be claimed or reset
     * again (single-use, enforced server-side per plan §8/§4).
     */
    public static function reset(int $id, string $licenseKey, string $hwid, string $token, ?string $ip): array
    {
        $pdo = Database::pdo();
        $pdo->beginTransaction();

        try {
            $stmt = $pdo->prepare(
                'SELECT * FROM password_recovery_requests WHERE id = ?' . self::lockingClause($pdo)
            );
            $stmt->execute([$id]);
            $request = $stmt->fetch();

            if (!$request || $request['license_key'] !== $licenseKey || !hash_equals($request['hwid'], $hwid)) {
                self::log($id, 'reset_failed_mismatch', null, $ip, null);
                $pdo->commit();
                return ['ok' => false, 'error' => 'طلب غير صالح.'];
            }
            if ($request['status'] === 'approved' && $request['token_expires_at']
                && strtotime($request['token_expires_at']) < time()) {
                $pdo->prepare("UPDATE password_recovery_requests SET status = 'expired' WHERE id = ?")
                    ->execute([$id]);
                self::log($id, 'authorization_expired', null, $ip, null);
                $pdo->commit();
                return ['ok' => false, 'error' => 'انتهت صلاحية التفويض.'];
            }
            if ($request['status'] === 'completed') {
                self::log($id, 'reset_failed_reused', null, $ip, null);
                $pdo->commit();
                return ['ok' => false, 'error' => 'تم استخدام هذا التفويض مسبقاً.'];
            }
            if ($request['status'] !== 'approved') {
                self::log($id, 'reset_failed_bad_status', null, $ip, null);
                $pdo->commit();
                return ['ok' => false, 'error' => 'الطلب غير معتمد أو انتهت صلاحيته.'];
            }
            if (!$request['token_hash'] || !hash_equals($request['token_hash'], hash('sha256', $token))) {
                self::log($id, 'reset_failed_bad_token', null, $ip, null);
                $pdo->commit();
                return ['ok' => false, 'error' => 'رمز التفويض غير صحيح.'];
            }

            $update = $pdo->prepare(
                "UPDATE password_recovery_requests
                 SET status = 'completed', used_at = CURRENT_TIMESTAMP, token_hash = NULL
                 WHERE id = ? AND status = 'approved' AND used_at IS NULL"
            );
            $update->execute([$id]);

            if ($update->rowCount() !== 1) {
                $pdo->rollBack();
                return ['ok' => false, 'error' => 'تعذر استخدام التفويض أو تم استخدامه مسبقاً.'];
            }

            self::log($id, 'password_changed', null, $ip, null);
            $pdo->commit();
            return ['ok' => true];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /** Lazily flips an approved-but-unclaimed/unused authorization to
     *  'expired' once its TTL has passed — no cron needed, since every
     *  read path (statusFor/claim/reset) calls this first. */
    private static function expireIfNeeded(int $id): void
    {
        $stmt = Database::pdo()->prepare(
            "UPDATE password_recovery_requests
             SET status = 'expired'
             WHERE id = ? AND status = 'approved'
               AND token_expires_at IS NOT NULL AND token_expires_at < CURRENT_TIMESTAMP"
        );
        $stmt->execute([$id]);
        self::logIfJustExpired($id);
    }

    private static function logIfJustExpired(int $id): void
    {
        static $logged = [];
        if (isset($logged[$id])) return;
        $request = self::findById($id);
        if ($request && $request['status'] === 'expired' && $request['used_at'] === null) {
            $logged[$id] = true;
            self::log($id, 'authorization_expired', null, null, null);
        }
    }

    private static function arabicStatus(string $status): string
    {
        $map = [
            'pending' => 'قيد الانتظار',
            'approved' => 'معتمد',
            'rejected' => 'مرفوض',
            'expired' => 'منتهي الصلاحية',
            'completed' => 'مكتمل',
        ];
        return $map[$status] ?? $status;
    }

    private static function log(?int $requestId, string $eventType, ?string $actor, ?string $ip, ?string $note): void
    {
        $stmt = Database::pdo()->prepare(
            'INSERT INTO recovery_audit_log (request_id, event_type, actor, ip_address, note) VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$requestId, $eventType, $actor, $ip, $note]);

        if (Database::pdo()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' && random_int(1, 250) === 1) {
            $threshold = (new DateTime())->modify('-180 days')->format('Y-m-d H:i:s');
            $cleanup = Database::pdo()->prepare(
                'DELETE FROM recovery_audit_log WHERE created_at < ? ORDER BY id LIMIT 1000'
            );
            $cleanup->execute([$threshold]);
        }
    }
}
