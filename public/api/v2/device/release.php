<?php
require_once __DIR__ . '/../_common.php';

$input = v2_input();
v2_rate_limit('device_release', $input);

try {
    $licenseKey = trim((string) ($input['license_key'] ?? ''));
    $requesterHwid = trim((string) ($input['requester_hwid'] ?? ''));
    $targetDeviceUuid = strtolower(trim((string) ($input['device_uuid'] ?? '')));

    if ($licenseKey === '' || strlen($licenseKey) > 64 || preg_match('/[\x00-\x1F\x7F]/', $licenseKey)) {
        throw new InvalidArgumentException('Invalid license_key.');
    }
    if ($requesterHwid === '' || strlen($requesterHwid) > 160 || preg_match('/[\x00-\x1F\x7F]/', $requesterHwid)) {
        throw new InvalidArgumentException('Invalid requester_hwid.');
    }
    if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $targetDeviceUuid)) {
        throw new InvalidArgumentException('Invalid device_uuid.');
    }

    $result = EntitlementV2::withSeatLock($licenseKey, static function () use ($licenseKey, $requesterHwid, $targetDeviceUuid): array {
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $licenseStmt = $pdo->prepare('SELECT * FROM licenses WHERE license_key = ? LIMIT 1');
            $licenseStmt->execute([$licenseKey]);
            $license = $licenseStmt->fetch();
            if (!$license) {
                $pdo->rollBack();
                return ['ok' => false, 'status' => 'invalid', 'error' => 'Invalid license key.'];
            }

            $requesterStmt = $pdo->prepare(
                'SELECT * FROM license_activations
                 WHERE license_id = ? AND hwid = ? AND is_active = 1 AND revoked_at IS NULL LIMIT 1'
            );
            $requesterStmt->execute([(int) $license['id'], $requesterHwid]);
            $requester = $requesterStmt->fetch();
            if (!$requester) {
                $pdo->rollBack();
                return ['ok' => false, 'status' => 'requester_not_authorized', 'error' => 'Requesting device is not active.'];
            }

            $targetStmt = $pdo->prepare(
                'SELECT * FROM license_activations WHERE license_id = ? AND device_uuid = ? LIMIT 1'
            );
            $targetStmt->execute([(int) $license['id'], $targetDeviceUuid]);
            $target = $targetStmt->fetch();
            if (!$target) {
                $pdo->rollBack();
                return ['ok' => false, 'status' => 'device_not_found', 'error' => 'Target device was not found.'];
            }

            $selfRelease = (int) $requester['id'] === (int) $target['id'];
            $requesterRole = strtolower((string) ($requester['device_role'] ?? ''));
            $canManage = in_array($requesterRole, ['manager_server', 'manager_terminal'], true);
            if (!$selfRelease && !$canManage) {
                $pdo->rollBack();
                return ['ok' => false, 'status' => 'permission_denied', 'error' => 'Only a Manager may release another device.'];
            }

            // Permanent revocation and ordinary Unpair are intentionally distinct.
            // A revoked identity remains blocked; release.php never resurrects it.
            if (!empty($target['revoked_at'])) {
                $pdo->rollBack();
                return ['ok' => false, 'status' => 'device_revoked', 'error' => 'This device has been permanently revoked.'];
            }

            if ((int) $target['is_active'] === 1) {
                // Free the seat and the globally unique device UUID. Keep HWID/history so
                // the same license can later reactivate this device without creating a
                // second historical row. Clearing device_uuid also lets a device that was
                // temporarily tested with another license be adopted by the correct Store.
                $pdo->prepare(
                    'UPDATE license_activations
                     SET is_active = 0, device_uuid = NULL, store_uuid = NULL, last_seen_at = CURRENT_TIMESTAMP
                     WHERE id = ?'
                )->execute([(int) $target['id']]);

                $pdo->prepare('UPDATE licenses SET entitlement_version = entitlement_version + 1 WHERE id = ?')
                    ->execute([(int) $license['id']]);
                try {
                    $pdo->prepare('INSERT INTO license_change_notifications (license_key) VALUES (?)')->execute([$licenseKey]);
                } catch (Throwable $ignored) {
                }
                try {
                    $note = 'Released device ' . $targetDeviceUuid . ($selfRelease ? ' (self)' : ' by manager');
                    $pdo->prepare(
                        'INSERT INTO subscription_events (license_id, event_type, note, created_by) VALUES (?, ?, ?, ?)'
                    )->execute([(int) $license['id'], 'device_released_v2', substr($note, 0, 255), 'api_v2']);
                } catch (Throwable $ignored) {
                }
            }

            $pdo->commit();
            return [
                'ok' => true,
                'device_uuid' => $targetDeviceUuid,
                'device_role' => (string) ($target['device_role'] ?? ''),
                'counts_as_terminal' => (bool) ($target['counts_as_terminal'] ?? false),
                'entitlement' => EntitlementV2::entitlementByKey($licenseKey),
            ];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    });

    v2_signed_response($result);
} catch (Throwable $e) {
    v2_exception_response($e);
}
