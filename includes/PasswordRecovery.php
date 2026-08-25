<?php
/**
 * Hercule POS account recovery authorization service.
 *
 * POS credentials remain local to the encrypted desktop SQLite database.
 * The server never receives a new username/password. It only verifies the
 * license/device, lets support approve a request, and authorizes a local reset.
 */

require_once __DIR__ . '/Database.php';

final class PasswordRecovery
{
    /** Approval / claim window. */
    private const TOKEN_TTL_MINUTES = 30;

    private static function lockingClause(PDO $pdo): string
    {
        return $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
    }

    /**
     * Once authorization_prepared exists the exact current token is frozen as
     * a completion proof. The original 30-minute window no longer expires that
     * request, because local credentials may already have been committed while
     * the client is offline. No plaintext secret is stored in the audit log.
     */
    private static function preparedAt(PDO $pdo, int $requestId): ?string
    {
        $stmt = $pdo->prepare(
            "SELECT created_at FROM recovery_audit_log
             WHERE request_id = ? AND event_type = 'authorization_prepared'
             ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute([$requestId]);
        $value = $stmt->fetchColumn();
        return $value ? (string) $value : null;
    }

    public static function isPrepared(int $requestId): bool
    {
        return self::preparedAt(Database::pdo(), $requestId) !== null;
    }

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

            try {
                require_once __DIR__ . '/PushNotifier.php';
                PushNotifier::notifyRecovery($licenseKey, $hwid, $username);
            } catch (\Throwable $e) {
                // A notification failure must never block a valid recovery request.
            }

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

    public static function allList(): array
    {
        return Database::pdo()->query(
            'SELECT * FROM password_recovery_requests ORDER BY created_at DESC LIMIT 300'
        )->fetchAll();
    }

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
     * Issues/reissues the authorization token while the 30-minute approval
     * window is still active. Reissue is disabled after prepare() so the token
     * used for local commit cannot silently change before final confirmation.
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
            if (!hash_equals((string) $request['hwid'], $hwid)) {
                self::log($id, 'claim_device_mismatch', null, $ip, null);
                $pdo->commit();
                return ['ok' => false, 'error' => 'جهازك الحالي لا يطابق الجهاز الذي أُرسل منه الطلب الأصلي.'];
            }

            $preparedAt = self::preparedAt($pdo, $id);
            if ($preparedAt !== null) {
                $pdo->rollBack();
                return ['ok' => false, 'error' => 'تم تثبيت تصريح الاسترداد لهذا الجهاز بالفعل ولا يمكن إصدار رمز بديل.'];
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
                return ['ok' => false, 'error' => 'الطلب ليس معتمداً حالياً (الحالة: ' . self::arabicStatus((string) $request['status']) . ').'];
            }
            if ($request['used_at'] !== null) {
                $pdo->rollBack();
                return ['ok' => false, 'error' => 'تم استخدام هذا التفويض مسبقاً.'];
            }

            $wasPreviouslyDelivered = $request['delivered_at'] !== null;
            $token = bin2hex(random_bytes(32));
            $tokenHash = hash('sha256', $token);
            $update = $pdo->prepare(
                "UPDATE password_recovery_requests
                 SET token_hash = ?, delivered_at = CURRENT_TIMESTAMP
                 WHERE id = ? AND status = 'approved' AND used_at IS NULL"
            );
            $update->execute([$tokenHash, $id]);

            if ($update->rowCount() !== 1) {
                $pdo->rollBack();
                return ['ok' => false, 'error' => 'تعذر استلام التفويض.'];
            }

            self::log($id, $wasPreviouslyDelivered ? 'authorization_reissued' : 'authorization_claimed', null, $ip, null);
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
     * Phase 1 of the local commit. The exact claimed token is validated while
     * its normal approval window is active, then authorization_prepared is
     * recorded. From that moment the token is frozen: it cannot be reissued,
     * and it may be used later only to finalize this same request/device.
     *
     * This closes the last crash/offline gap: after prepare succeeds, the
     * desktop may safely commit credentials locally. If Internet disappears
     * for hours/days before reset(), the eventual final confirmation remains
     * valid because prepare proves the authorization was live beforehand.
     */
    public static function prepare(int $id, string $licenseKey, string $hwid, string $token, ?string $ip): array
    {
        $pdo = Database::pdo();
        $pdo->beginTransaction();

        try {
            $stmt = $pdo->prepare(
                'SELECT * FROM password_recovery_requests WHERE id = ?' . self::lockingClause($pdo)
            );
            $stmt->execute([$id]);
            $request = $stmt->fetch();

            if (!$request || $request['license_key'] !== $licenseKey || !hash_equals((string) $request['hwid'], $hwid)) {
                self::log($id, 'prepare_failed_mismatch', null, $ip, null);
                $pdo->commit();
                return ['ok' => false, 'error' => 'طلب غير صالح.'];
            }
            if ($request['status'] === 'completed') {
                $pdo->commit();
                return ['ok' => true, 'already_completed' => true];
            }
            if ($request['status'] !== 'approved' || $request['used_at'] !== null) {
                self::log($id, 'prepare_failed_bad_status', null, $ip, null);
                $pdo->commit();
                return ['ok' => false, 'error' => 'الطلب غير معتمد أو لم يعد قابلاً للاسترداد.'];
            }
            if ($request['delivered_at'] === null) {
                self::log($id, 'prepare_failed_not_claimed', null, $ip, null);
                $pdo->commit();
                return ['ok' => false, 'error' => 'يجب استلام تصريح الاسترداد من هذا الجهاز أولاً.'];
            }
            if (!$request['token_hash'] || !hash_equals((string) $request['token_hash'], hash('sha256', $token))) {
                self::log($id, 'prepare_failed_bad_token', null, $ip, null);
                $pdo->commit();
                return ['ok' => false, 'error' => 'رمز التفويض غير صحيح.'];
            }

            $preparedAt = self::preparedAt($pdo, $id);
            if ($preparedAt !== null) {
                $pdo->commit();
                return ['ok' => true, 'already_prepared' => true, 'prepared_at' => $preparedAt];
            }

            if ($request['token_expires_at'] && strtotime($request['token_expires_at']) < time()) {
                $pdo->prepare("UPDATE password_recovery_requests SET status = 'expired' WHERE id = ?")
                    ->execute([$id]);
                self::log($id, 'authorization_expired', null, $ip, 'expired_before_prepare');
                $pdo->commit();
                return ['ok' => false, 'error' => 'انتهت صلاحية التفويض قبل تثبيت عملية الاسترداد. أرسل طلباً جديداً.'];
            }

            self::log($id, 'authorization_prepared', null, $ip, null);
            $preparedAt = self::preparedAt($pdo, $id);
            $pdo->commit();

            return ['ok' => true, 'prepared_at' => $preparedAt ?: date('Y-m-d H:i:s')];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Final server acknowledgement. Before prepare this still obeys the normal
     * 30-minute token TTL for old clients. After prepare, expiry no longer
     * invalidates this exact token: it is only a durable proof that the local
     * credential commit was authorized while the approval was live.
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

            if (!$request || $request['license_key'] !== $licenseKey || !hash_equals((string) $request['hwid'], $hwid)) {
                self::log($id, 'reset_failed_mismatch', null, $ip, null);
                $pdo->commit();
                return ['ok' => false, 'error' => 'طلب غير صالح.'];
            }
            if ($request['status'] === 'completed') {
                self::log($id, 'reset_failed_reused', null, $ip, null);
                $pdo->commit();
                return ['ok' => false, 'error' => 'تم استخدام هذا التفويض مسبقاً.'];
            }

            $preparedAt = self::preparedAt($pdo, $id);
            if ($preparedAt === null && $request['status'] === 'approved' && $request['token_expires_at']
                && strtotime($request['token_expires_at']) < time()) {
                $pdo->prepare("UPDATE password_recovery_requests SET status = 'expired' WHERE id = ?")
                    ->execute([$id]);
                self::log($id, 'authorization_expired', null, $ip, null);
                $pdo->commit();
                return ['ok' => false, 'error' => 'انتهت صلاحية التفويض.'];
            }
            if ($request['status'] !== 'approved') {
                self::log($id, 'reset_failed_bad_status', null, $ip, null);
                $pdo->commit();
                return ['ok' => false, 'error' => 'الطلب غير معتمد أو انتهت صلاحيته.'];
            }
            if (!$request['token_hash'] || !hash_equals((string) $request['token_hash'], hash('sha256', $token))) {
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

            self::log($id, $preparedAt !== null ? 'recovery_completed_after_prepare' : 'password_changed', null, $ip, null);
            $pdo->commit();
            return ['ok' => true, 'prepared' => $preparedAt !== null];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Expire only authorizations that have NOT entered the prepared phase.
     * Prepared requests retain the frozen completion proof until reset().
     */
    private static function expireIfNeeded(int $id): void
    {
        $stmt = Database::pdo()->prepare(
            "UPDATE password_recovery_requests AS pr
             SET status = 'expired'
             WHERE pr.id = ? AND pr.status = 'approved'
               AND pr.token_expires_at IS NOT NULL AND pr.token_expires_at < CURRENT_TIMESTAMP
               AND NOT EXISTS (
                   SELECT 1 FROM recovery_audit_log ra
                   WHERE ra.request_id = pr.id AND ra.event_type = 'authorization_prepared'
               )"
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
                "DELETE FROM recovery_audit_log
                 WHERE created_at < ?
                   AND NOT (
                       event_type = 'authorization_prepared'
                       AND EXISTS (
                           SELECT 1 FROM password_recovery_requests pr
                           WHERE pr.id = recovery_audit_log.request_id AND pr.status = 'approved'
                       )
                   )
                 ORDER BY id LIMIT 1000"
            );
            $cleanup->execute([$threshold]);
        }
    }
}
