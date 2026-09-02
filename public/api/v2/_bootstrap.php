<?php

require_once __DIR__ . '/../_bootstrap.php';
require_once __DIR__ . '/../../../includes/Entitlement.php';
require_once __DIR__ . '/../../../includes/RateLimiter.php';

function v2_input_identity(array $input): array
{
    $licenseKey = trim((string) (
        $input['license_key'] ?? $input['licenseKey'] ?? $input['key'] ?? $input['license'] ?? ''
    ));
    $hwid = trim((string) (
        $input['hwid'] ?? $input['hardware_id'] ?? $input['hardwareId'] ??
        $input['device_id'] ?? $input['deviceId'] ?? $input['machine_id'] ?? ''
    ));
    $appVersion = trim((string) ($input['app_version'] ?? $input['appVersion'] ?? $input['version'] ?? ''));
    $protocolVersion = max(1, min(100000, (int) ($input['protocol_version'] ?? $input['protocolVersion'] ?? 2)));

    if ($licenseKey === '' || $hwid === '') {
        v2_signed_error('invalid_request', 'license_key and hwid are required', 400);
    }
    if (strlen($licenseKey) > 64 || preg_match('/[\x00-\x1F\x7F]/', $licenseKey)) {
        v2_signed_error('invalid_request', 'Invalid license_key.', 400);
    }
    if (strlen($hwid) > 160 || preg_match('/[\x00-\x1F\x7F]/', $hwid)) {
        v2_signed_error('invalid_request', 'Invalid hwid.', 400);
    }
    if (strlen($appVersion) > 50 || preg_match('/[\x00-\x1F\x7F]/', $appVersion)) {
        v2_signed_error('invalid_request', 'Invalid app_version.', 400);
    }

    return [$licenseKey, $hwid, $appVersion, $protocolVersion];
}

function v2_signed_error(string $code, string $message, int $httpStatus = 200): void
{
    $payload = [
        'schema_version' => 2,
        'status' => 'invalid',
        'code' => $code,
        'error' => $message,
        'server_time' => gmdate('Y-m-d\TH:i:s\Z'),
    ];
    json_response(['ok' => false] + RsaSigner::sign($payload), $httpStatus);
}

function v2_signed_success(array $payload): void
{
    json_response(['ok' => true] + RsaSigner::sign($payload));
}

/**
 * Gives legacy v1-created rows their stable v2 identity lazily.
 * This preserves the v1 INSERT contract while guaranteeing that any row
 * exposed through v2 has immutable license/store/device UUIDs.
 */
function v2_ensure_identity(string $licenseKey, string $hwid): void
{
    if (!Entitlement::schemaReady()) {
        return;
    }

    $pdo = Database::pdo();
    $lock = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT * FROM licenses WHERE license_key = ?' . $lock);
        $stmt->execute([$licenseKey]);
        $license = $stmt->fetch();
        if (!$license) {
            $pdo->commit();
            return;
        }

        $licenseUuid = trim((string) ($license['license_uuid'] ?? ''));
        $storeUuid = trim((string) ($license['store_uuid'] ?? ''));
        $maxTerminals = (int) ($license['max_terminals'] ?? 0);
        $features = trim((string) ($license['features_json'] ?? ''));

        if ($licenseUuid === '') $licenseUuid = Entitlement::uuidV4();
        if ($storeUuid === '') $storeUuid = Entitlement::uuidV4();
        if ($maxTerminals < 1) $maxTerminals = !empty($license['multi_cashier']) ? max(1, (int) $license['max_activations']) : 1;
        if ($features === '') {
            $features = json_encode([
                'multi_cashier' => !empty($license['multi_cashier']),
                'offline_sale' => true,
            ], JSON_UNESCAPED_SLASHES);
        }

        $updateLicense = $pdo->prepare(
            'UPDATE licenses
             SET license_uuid = ?, store_uuid = ?, max_terminals = ?, features_json = ?
             WHERE id = ?'
        );
        $updateLicense->execute([$licenseUuid, $storeUuid, $maxTerminals, $features, $license['id']]);

        $activationStmt = $pdo->prepare(
            'SELECT id, device_uuid FROM license_activations WHERE license_id = ? AND hwid = ? LIMIT 1'
        );
        $activationStmt->execute([(int) $license['id'], $hwid]);
        $activation = $activationStmt->fetch();
        if ($activation && trim((string) ($activation['device_uuid'] ?? '')) === '') {
            $updateActivation = $pdo->prepare('UPDATE license_activations SET device_uuid = ? WHERE id = ?');
            $updateActivation->execute([Entitlement::uuidV4(), $activation['id']]);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function v2_rate_limit(string $licenseKey, string $endpoint): void
{
    $config = require __DIR__ . '/../../../config/config.php';
    $security = $config['security'];

    if (!RateLimiter::check(
        client_ip(),
        'v2_' . $endpoint,
        $security['api_rate_limit_max_requests'],
        $security['api_rate_limit_window_minutes']
    )) {
        v2_signed_error('rate_limited', 'Too many requests. Please try again in a few minutes.', 429);
    }

    if (!RateLimiter::check(
        'key:' . $licenseKey,
        'v2_' . $endpoint . '_by_key',
        $security['key_rate_limit_max_requests'],
        $security['key_rate_limit_window_minutes']
    )) {
        v2_signed_error('rate_limited', 'Too many requests for this license key. Please try again in a few minutes.', 429);
    }
}
