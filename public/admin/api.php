<?php
require_once __DIR__ . '/includes/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function admin_api_reply(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (!Auth::check()) {
    admin_api_reply(['ok' => false, 'error' => 'Unauthorized'], 401);
}

/**
 * Every state-changing legacy admin API action requires both the explicit
 * role/permission check and the authenticated session CSRF token. There is no
 * Origin/Sec-Fetch fallback: same-origin alone is not authorization to mutate.
 */
function admin_api_require_mutation(?string $permission = null): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        header('Allow: POST');
        admin_api_reply(['ok' => false, 'error' => 'POST required'], 405);
    }

    if ($permission !== null && !Auth::can($permission)) {
        admin_api_reply(['ok' => false, 'error' => 'Permission denied'], 403);
    }

    if (!Csrf::check(Csrf::submittedToken())) {
        admin_api_reply(['ok' => false, 'error' => 'Invalid or expired CSRF token'], 403);
    }
}

$pdo = Database::pdo();
$action = (string)($_GET['action'] ?? ($_POST['action'] ?? ''));
$method = (string)($_SERVER['REQUEST_METHOD'] ?? 'GET');

try {
    switch ($action) {
        case 'bootstrap':
            if ($method !== 'GET') {
                header('Allow: GET');
                admin_api_reply(['ok' => false, 'error' => 'GET required'], 405);
            }

            $licenses = $pdo->query(
                "SELECT l.*, c.name AS customer_name, c.company, c.contact_email
                 FROM licenses l
                 LEFT JOIN customers c ON c.id = l.customer_id
                 ORDER BY l.created_at DESC"
            )->fetchAll();

            $activations = $pdo->query(
                "SELECT a.*, l.license_key
                 FROM license_activations a
                 JOIN licenses l ON l.id = a.license_id
                 WHERE a.is_active = 1
                 ORDER BY a.activated_at DESC"
            )->fetchAll();

            $activationsByLicense = [];
            foreach ($activations as $act) {
                $activationsByLicense[$act['license_id']][] = [
                    'id' => (int)$act['id'],
                    'license_id' => (int)$act['license_id'],
                    'hwid' => $act['hwid'],
                    'ip_address' => $act['ip_address'] ?? '127.0.0.1',
                    'hostname' => $act['hostname'] ?? 'POS-TERMINAL',
                    'activated_at' => $act['activated_at'],
                    'last_seen_at' => $act['last_seen_at'] ?? $act['activated_at'],
                    'status' => $act['is_active'] ? 'active' : 'inactive',
                    'app_version' => $act['app_version'] ?? 'unknown',
                ];
            }

            $formattedLicenses = [];
            foreach ($licenses as $l) {
                $lId = (int)$l['id'];
                $formattedLicenses[] = [
                    'id' => $lId,
                    'key' => $l['license_key'],
                    'license_key' => $l['license_key'],
                    'customer_id' => (int)$l['customer_id'],
                    'customer_name' => $l['customer_name'] ?? 'Direct Client',
                    'customer_company' => $l['company'] ?? '',
                    'plan' => $l['plan'] ?? 'pro',
                    'status' => $l['status'],
                    'max_activations' => (int)($l['max_activations'] ?? 1),
                    'current_activations' => count($activationsByLicense[$lId] ?? []),
                    'activations' => $activationsByLicense[$lId] ?? [],
                    'expires_at' => $l['expires_at'],
                    'created_at' => $l['created_at'],
                    'last_verified_at' => $l['last_verified_at'] ?? null,
                    'features' => json_decode($l['features'] ?? '[]', true) ?? ['pos', 'offline_mode'],
                    'notes' => $l['notes'] ?? '',
                ];
            }

            $customers = $pdo->query(
                "SELECT c.*, COUNT(l.id) AS total_licenses,
                        SUM(CASE WHEN l.status = 'active' THEN 1 ELSE 0 END) AS active_licenses
                 FROM customers c
                 LEFT JOIN licenses l ON l.customer_id = c.id
                 GROUP BY c.id
                 ORDER BY c.created_at DESC"
            )->fetchAll();

            $verifications = $pdo->query(
                "SELECT * FROM verification_log ORDER BY created_at DESC LIMIT 50"
            )->fetchAll();

            $recoveryRequests = $pdo->query(
                "SELECT * FROM password_recovery_requests ORDER BY created_at DESC LIMIT 30"
            )->fetchAll();

            $currentUser = [
                'id' => Auth::currentUserId(),
                'username' => Auth::currentUsername(),
                'role' => Auth::currentRole(),
                'mfa_enabled' => Auth::mfaEnabled(),
            ];

            admin_api_reply([
                'ok' => true,
                'csrf_token' => Csrf::token(),
                'user' => $currentUser,
                'licenses' => $formattedLicenses,
                'customers' => $customers,
                'verifications' => $verifications,
                'recovery_requests' => $recoveryRequests,
                'stats' => [
                    'total_licenses' => count($formattedLicenses),
                    'active_licenses' => count(array_filter($formattedLicenses, static fn($x) => $x['status'] === 'active')),
                    'total_customers' => count($customers),
                    'pending_recoveries' => count(array_filter($recoveryRequests, static fn($x) => ($x['status'] ?? '') === 'pending')),
                ],
            ]);

        case 'create_license':
            admin_api_require_mutation('licenses.manage');
            $data = json_decode(file_get_contents('php://input'), true) ?? $_POST;

            $customerId = (int)($data['customer_id'] ?? 0);
            $plan = trim((string)($data['plan'] ?? 'pro'));
            $maxActivations = max(1, min(100, (int)($data['max_activations'] ?? 1)));
            $expiresAt = !empty($data['expires_at']) ? (string)$data['expires_at'] : null;
            $notes = mb_substr(trim((string)($data['notes'] ?? '')), 0, 10000);

            if ($customerId <= 0) throw new InvalidArgumentException('A valid customer is required');
            if (!preg_match('/^[A-Za-z0-9_-]{1,32}$/', $plan)) throw new InvalidArgumentException('Invalid plan');

            $key = License::generateKey('HRC');
            $stmt = $pdo->prepare(
                "INSERT INTO licenses (customer_id, license_key, plan, max_activations, expires_at, status, notes, created_at)
                 VALUES (?, ?, ?, ?, ?, 'active', ?, NOW())"
            );
            $stmt->execute([$customerId, $key, $plan, $maxActivations, $expiresAt, $notes]);
            admin_api_reply(['ok' => true, 'id' => (int)$pdo->lastInsertId(), 'key' => $key]);

        case 'update_license_status':
            admin_api_require_mutation('licenses.manage');
            $data = json_decode(file_get_contents('php://input'), true) ?? $_POST;
            $licenseId = (int)($data['license_id'] ?? 0);
            $status = strtolower(trim((string)($data['status'] ?? '')));
            if ($licenseId <= 0 || !in_array($status, ['active', 'suspended', 'expired', 'revoked'], true)) {
                throw new InvalidArgumentException('Invalid license status request');
            }

            $stmt = $pdo->prepare('UPDATE licenses SET status = ? WHERE id = ?');
            $stmt->execute([$status, $licenseId]);
            admin_api_reply(['ok' => true]);

        case 'reset_activation':
            admin_api_require_mutation('licenses.manage');
            $data = json_decode(file_get_contents('php://input'), true) ?? $_POST;
            $licenseId = (int)($data['license_id'] ?? 0);
            $activationId = (int)($data['activation_id'] ?? 0);
            if ($licenseId <= 0 && $activationId <= 0) throw new InvalidArgumentException('A license or activation is required');

            if ($activationId > 0) {
                $stmt = $pdo->prepare('DELETE FROM license_activations WHERE id = ?');
                $stmt->execute([$activationId]);
            } else {
                $stmt = $pdo->prepare('DELETE FROM license_activations WHERE license_id = ?');
                $stmt->execute([$licenseId]);
            }
            admin_api_reply(['ok' => true]);

        case 'create_customer':
            admin_api_require_mutation('customers.manage');
            $data = json_decode(file_get_contents('php://input'), true) ?? $_POST;
            $name = mb_substr(trim((string)($data['name'] ?? '')), 0, 255);
            $company = mb_substr(trim((string)($data['company'] ?? '')), 0, 255);
            $email = mb_substr(trim((string)($data['contact_email'] ?? $data['email'] ?? '')), 0, 255);
            $notes = mb_substr(trim((string)($data['notes'] ?? '')), 0, 10000);

            if ($name === '') throw new InvalidArgumentException('Customer name is required');
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('Invalid customer email');

            $stmt = $pdo->prepare('INSERT INTO customers (name, company, contact_email, notes, created_at) VALUES (?, ?, ?, ?, NOW())');
            $stmt->execute([$name, $company, $email, $notes]);
            admin_api_reply(['ok' => true, 'id' => (int)$pdo->lastInsertId()]);

        case 'handle_recovery':
            admin_api_require_mutation('recovery.review');
            // Legacy recovery decisions used to update the table directly and
            // bypass identity verification plus the hardened recovery state
            // machine. Keep the action name only to fail closed for old clients.
            admin_api_reply([
                'ok' => false,
                'error' => 'Recovery decisions must be completed from the hardened recovery review screen.',
                'code' => 'RECOVERY_REVIEW_REQUIRED',
            ], 410);

        case 'push_subscribe':
            admin_api_require_mutation();
            $data = json_decode(file_get_contents('php://input'), true) ?? [];
            $endpoint = trim((string)($data['endpoint'] ?? ''));
            $p256dh = trim((string)($data['keys']['p256dh'] ?? ''));
            $auth = trim((string)($data['keys']['auth'] ?? ''));
            $deviceId = trim((string)($data['device_id'] ?? ''));
            $userAgent = mb_substr(trim((string)($data['user_agent'] ?? ($_SERVER['HTTP_USER_AGENT'] ?? ''))), 0, 255);
            $adminUsername = (string)Auth::currentUsername();

            if ($endpoint === '' || $p256dh === '' || $auth === '') throw new InvalidArgumentException('Incomplete browser push subscription');
            if (!filter_var($endpoint, FILTER_VALIDATE_URL) || stripos($endpoint, 'https://') !== 0) throw new InvalidArgumentException('Invalid push endpoint');
            if (!preg_match('/^[A-Za-z0-9_-]{16,64}$/', $deviceId)) throw new InvalidArgumentException('Invalid push device identity');
            if (strlen($endpoint) > 2048 || strlen($p256dh) > 512 || strlen($auth) > 512) throw new InvalidArgumentException('Push subscription is too large');

            try {
                $pdo->beginTransaction();
                $delete = $pdo->prepare('DELETE FROM push_subscriptions WHERE admin_username = ? AND device_id = ? AND endpoint <> ?');
                $delete->execute([$adminUsername, $deviceId, $endpoint]);

                $stmt = $pdo->prepare(
                    "INSERT INTO push_subscriptions
                        (admin_username, device_id, endpoint, p256dh_key, auth_key, user_agent, created_at, last_seen_at, updated_at)
                     VALUES (?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
                     ON DUPLICATE KEY UPDATE
                        admin_username = VALUES(admin_username),
                        device_id = VALUES(device_id),
                        p256dh_key = VALUES(p256dh_key),
                        auth_key = VALUES(auth_key),
                        user_agent = VALUES(user_agent),
                        last_seen_at = CURRENT_TIMESTAMP,
                        updated_at = CURRENT_TIMESTAMP"
                );
                $stmt->execute([$adminUsername, $deviceId, $endpoint, $p256dh, $auth, $userAgent]);
                $pdo->exec("DELETE FROM push_subscriptions WHERE last_seen_at < DATE_SUB(NOW(), INTERVAL 45 DAY)");
                $pdo->commit();
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                if (stripos($e->getMessage(), 'device_id') === false && stripos($e->getMessage(), 'last_seen_at') === false) throw $e;
                $stmt = $pdo->prepare('REPLACE INTO push_subscriptions (admin_username, endpoint, p256dh_key, auth_key) VALUES (?, ?, ?, ?)');
                $stmt->execute([$adminUsername, $endpoint, $p256dh, $auth]);
            }

            admin_api_reply(['ok' => true, 'saved' => true, 'device_id' => $deviceId]);

        default:
            admin_api_reply(['ok' => false, 'error' => 'Unknown action'], 404);
    }
} catch (InvalidArgumentException $e) {
    admin_api_reply(['ok' => false, 'error' => $e->getMessage()], 400);
} catch (Throwable $e) {
    ErrorHandler::report($e, 'admin_api_failure', ['action' => $action]);
    admin_api_reply(['ok' => false, 'error' => 'The admin request could not be completed'], 500);
}
