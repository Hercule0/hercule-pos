<?php
/**
 * Realtime Mobile Push Notifications for Hercule POS License Server.
 *
 * Dispatches instant push notifications to registered admin devices & PWA
 * lockscreens when POS hardware activations or urgent password recovery
 * requests occur.
 */

require_once __DIR__ . '/Database.php';

final class PushNotifier
{
    /**
     * Save or update a mobile WebPush subscription endpoint.
     */
    public static function subscribe(
        string $endpoint,
        ?string $p256dh = null,
        ?string $auth = null,
        ?int $adminId = null,
        ?string $userAgent = null
    ): bool {
        if (trim($endpoint) === '') {
            return false;
        }

        $pdo = Database::pdo();
        $isDriverSqlite = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';

        if ($isDriverSqlite) {
            $stmt = $pdo->prepare(
                'INSERT INTO push_subscriptions (admin_id, endpoint, p256dh, auth, user_agent)
                 VALUES (?, ?, ?, ?, ?)
                 ON CONFLICT(endpoint) DO UPDATE SET
                   admin_id = excluded.admin_id,
                   p256dh = excluded.p256dh,
                   auth = excluded.auth,
                   user_agent = excluded.user_agent,
                   created_at = CURRENT_TIMESTAMP'
            );
        } else {
            $stmt = $pdo->prepare(
                'INSERT INTO push_subscriptions (admin_id, endpoint, p256dh, auth, user_agent)
                 VALUES (?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                   admin_id = VALUES(admin_id),
                   p256dh = VALUES(p256dh),
                   auth = VALUES(auth),
                   user_agent = VALUES(user_agent),
                   created_at = CURRENT_TIMESTAMP'
            );
        }

        return $stmt->execute([$adminId, $endpoint, $p256dh, $auth, $userAgent]);
    }

    /**
     * Remove a mobile WebPush subscription endpoint.
     */
    public static function unsubscribe(string $endpoint): bool
    {
        $stmt = Database::pdo()->prepare('DELETE FROM push_subscriptions WHERE endpoint = ?');
        return $stmt->execute([$endpoint]);
    }

    /**
     * Get active subscriptions list.
     */
    public static function getSubscriptions(): array
    {
        $stmt = Database::pdo()->query('SELECT * FROM push_subscriptions ORDER BY created_at DESC');
        return $stmt->fetchAll() ?: [];
    }

    /**
     * Trigger activation push alert when a new POS terminal activates a key.
     */
    public static function notifyActivation(string $licenseKey, string $hwid, ?string $deviceName = null): void
    {
        $title = '⚡ Live POS Terminal Activation';
        $body = "License [{$licenseKey}] activated on device HWID [{$hwid}]" . ($deviceName ? " ({$deviceName})" : '');
        $url = '/public/admin/licenses.php';
        $tag = 'activation-' . time();

        self::sendPush($title, $body, $url, $tag);
    }

    /**
     * Trigger urgent password recovery alert when a store submits a PIN reset request.
     */
    public static function notifyRecovery(string $licenseKey, string $hwid, string $username): void
    {
        $title = '🚨 Urgent Store PIN Reset Request';
        $body = "Staff \"{$username}\" on terminal [{$hwid}] requested emergency password recovery.";
        $url = '/public/admin/recovery_requests.php';
        $tag = 'recovery-' . time();

        self::sendPush($title, $body, $url, $tag);
    }

    /**
     * Dispatch push payload to all registered mobile devices.
     */
    public static function sendPush(string $title, string $body, ?string $url = null, ?string $tag = null): array
    {
        $subscriptions = self::getSubscriptions();
        $payload = json_encode([
            'title' => $title,
            'body' => $body,
            'url' => $url ?? '/public/admin/index.php',
            'tag' => $tag ?? 'hercule-alert-' . time(),
            'timestamp' => date('c'),
        ]);

        $dispatched = 0;
        foreach ($subscriptions as $sub) {
            $endpoint = $sub['endpoint'] ?? '';
            if (empty($endpoint)) continue;

            // Dispatch via curl HTTP request if external WebPush gateway endpoint
            if (filter_var($endpoint, FILTER_VALIDATE_URL)) {
                $ch = curl_init($endpoint);
                curl_setopt_array($ch, [
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => $payload,
                    CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'TTL: 60'],
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 3,
                ]);
                @curl_exec($ch);
                curl_close($ch);
                $dispatched++;
            }
        }

        return ['ok' => true, 'subscriptions_count' => count($subscriptions), 'dispatched' => $dispatched];
    }
}
