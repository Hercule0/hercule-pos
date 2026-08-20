<?php
require_once __DIR__ . '/includes/bootstrap.php';

// Allow session authentication or JSON API consumption
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

$pdo = Database::pdo();
$action = $_GET['action'] ?? ($_POST['action'] ?? '');
$method = $_SERVER['REQUEST_METHOD'];

try {
    switch ($action) {
        case 'bootstrap':
            // 1. Licenses & Customers
            $licenses = $pdo->query(
                "SELECT l.*, c.name AS customer_name, c.company, c.contact_email 
                 FROM licenses l 
                 LEFT JOIN customers c ON c.id = l.customer_id 
                 ORDER BY l.created_at DESC"
            )->fetchAll();

            // 2. Activations
            $activations = $pdo->query(
                "SELECT a.*, l.license_key 
                 FROM license_activations a 
                 JOIN licenses l ON l.id = a.license_id 
                 WHERE a.is_active = 1
                 ORDER BY a.activated_at DESC"
            )->fetchAll();

            // Attach activations to licenses
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
                    'app_version' => $act['app_version'] ?? 'v2.4.0'
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
                    'notes' => $l['notes'] ?? ''
                ];
            }

            // 3. Customers
            $customers = $pdo->query(
                "SELECT c.*, COUNT(l.id) AS total_licenses,
                        SUM(CASE WHEN l.status = 'active' THEN 1 ELSE 0 END) AS active_licenses
                 FROM customers c
                 LEFT JOIN licenses l ON l.customer_id = c.id
                 GROUP BY c.id
                 ORDER BY c.created_at DESC"
            )->fetchAll();

            // 4. Verifications
            $verifications = $pdo->query(
                "SELECT * FROM verification_log ORDER BY created_at DESC LIMIT 50"
            )->fetchAll();

            // 5. Recovery Requests
            $recoveryRequests = $pdo->query(
                "SELECT * FROM password_recovery_requests ORDER BY created_at DESC LIMIT 30"
            )->fetchAll();

            // 6. Current Admin User
            $currentUser = [
                'id' => Auth::currentUserId(),
                'username' => Auth::currentUsername(),
                'role' => Auth::currentRole(),
                'mfa_enabled' => true
            ];

            echo json_encode([
                'ok' => true,
                'user' => $currentUser,
                'licenses' => $formattedLicenses,
                'customers' => $customers,
                'verifications' => $verifications,
                'recovery_requests' => $recoveryRequests,
                'stats' => [
                    'total_licenses' => count($formattedLicenses),
                    'active_licenses' => count(array_filter($formattedLicenses, fn($x) => $x['status'] === 'active')),
                    'total_customers' => count($customers),
                    'pending_recoveries' => count(array_filter($recoveryRequests, fn($x) => ($x['status'] ?? '') === 'pending'))
                ]
            ]);
            break;

        case 'create_license':
            if ($method !== 'POST') throw new Exception('POST required');
            $data = json_decode(file_get_contents('php://input'), true) ?? $_POST;
            
            $customerId = (int)($data['customer_id'] ?? 0);
            $plan = $data['plan'] ?? 'pro';
            $maxActivations = (int)($data['max_activations'] ?? 1);
            $expiresAt = !empty($data['expires_at']) ? $data['expires_at'] : null;
            $notes = $data['notes'] ?? '';

            // Generate Key
            $key = License::generateKey('HRC');

            $stmt = $pdo->prepare(
                "INSERT INTO licenses (customer_id, license_key, plan, max_activations, expires_at, status, notes, created_at)
                 VALUES (?, ?, ?, ?, ?, 'active', ?, NOW())"
            );
            $stmt->execute([$customerId, $key, $plan, $maxActivations, $expiresAt, $notes]);
            $newId = (int)$pdo->lastInsertId();

            echo json_encode(['ok' => true, 'id' => $newId, 'key' => $key]);
            break;

        case 'update_license_status':
            if ($method !== 'POST') throw new Exception('POST required');
            $data = json_decode(file_get_contents('php://input'), true) ?? $_POST;
            $licenseId = (int)($data['license_id'] ?? 0);
            $status = $data['status'] ?? 'active';

            $stmt = $pdo->prepare("UPDATE licenses SET status = ? WHERE id = ?");
            $stmt->execute([$status, $licenseId]);
            echo json_encode(['ok' => true]);
            break;

        case 'reset_activation':
            if ($method !== 'POST') throw new Exception('POST required');
            $data = json_decode(file_get_contents('php://input'), true) ?? $_POST;
            $licenseId = (int)($data['license_id'] ?? 0);
            $activationId = (int)($data['activation_id'] ?? 0);

            if ($activationId > 0) {
                $stmt = $pdo->prepare("DELETE FROM license_activations WHERE id = ?");
                $stmt->execute([$activationId]);
            } else {
                $stmt = $pdo->prepare("DELETE FROM license_activations WHERE license_id = ?");
                $stmt->execute([$licenseId]);
            }
            echo json_encode(['ok' => true]);
            break;

        case 'create_customer':
            if ($method !== 'POST') throw new Exception('POST required');
            $data = json_decode(file_get_contents('php://input'), true) ?? $_POST;
            $name = trim($data['name'] ?? '');
            $company = trim($data['company'] ?? '');
            $email = trim($data['contact_email'] ?? $data['email'] ?? '');
            $notes = trim($data['notes'] ?? '');

            if (!$name) throw new Exception('Customer name is required');

            $stmt = $pdo->prepare("INSERT INTO customers (name, company, contact_email, notes, created_at) VALUES (?, ?, ?, ?, NOW())");
            $stmt->execute([$name, $company, $email, $notes]);
            $customerId = (int)$pdo->lastInsertId();

            echo json_encode(['ok' => true, 'id' => $customerId]);
            break;

        case 'handle_recovery':
            if ($method !== 'POST') throw new Exception('POST required');
            $data = json_decode(file_get_contents('php://input'), true) ?? $_POST;
            $requestId = (int)($data['request_id'] ?? 0);
            $status = $data['status'] ?? 'approved'; // approved or rejected

            $stmt = $pdo->prepare("UPDATE password_recovery_requests SET status = ?, resolved_at = NOW() WHERE id = ?");
            $stmt->execute([$status, $requestId]);
            echo json_encode(['ok' => true]);
            break;

        case 'push_subscribe':
            if ($method !== 'POST') throw new Exception('POST required');
            $data = json_decode(file_get_contents('php://input'), true) ?? [];
            $endpoint = trim((string)($data['endpoint'] ?? ''));
            $p256dh = trim((string)($data['keys']['p256dh'] ?? ''));
            $auth = trim((string)($data['keys']['auth'] ?? ''));
            $deviceId = trim((string)($data['device_id'] ?? ''));
            $userAgent = mb_substr(trim((string)($data['user_agent'] ?? ($_SERVER['HTTP_USER_AGENT'] ?? ''))), 0, 255);
            $adminUsername = (string)Auth::currentUsername();

            if ($endpoint === '' || $p256dh === '' || $auth === '') {
                throw new Exception('Incomplete browser push subscription');
            }
            if (!filter_var($endpoint, FILTER_VALIDATE_URL) || stripos($endpoint, 'https://') !== 0) {
                throw new Exception('Invalid push endpoint');
            }
            if (!preg_match('/^[A-Za-z0-9_-]{16,64}$/', $deviceId)) {
                throw new Exception('Invalid push device identity');
            }

            try {
                $pdo->beginTransaction();
                // One row per administrator/browser profile. If the push service
                // rotates the endpoint, the old row is removed before the new one
                // is saved instead of accumulating another apparent "device".
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

                // Remove abandoned browser profiles after 45 days without a sync.
                $pdo->exec("DELETE FROM push_subscriptions WHERE last_seen_at < DATE_SUB(NOW(), INTERVAL 45 DAY)");
                $pdo->commit();
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                // Keep rollout non-breaking until the additive migration is run.
                if (stripos($e->getMessage(), 'device_id') === false && stripos($e->getMessage(), 'last_seen_at') === false) {
                    throw $e;
                }
                $stmt = $pdo->prepare("REPLACE INTO push_subscriptions (admin_username, endpoint, p256dh_key, auth_key) VALUES (?, ?, ?, ?)");
                $stmt->execute([$adminUsername, $endpoint, $p256dh, $auth]);
            }

            echo json_encode(['ok' => true, 'saved' => true, 'device_id' => $deviceId]);
            break;

        default:
            echo json_encode(['ok' => false, 'error' => 'Unknown action']);
            break;
    }
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
