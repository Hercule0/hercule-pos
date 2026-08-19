<?php
/**
 * Realtime Mobile Push Notifications for Hercule POS License Server.
 *
 * Dispatches instant push notifications to registered admin devices & PWA
 * lockscreens when POS hardware activations or urgent password recovery
 * requests occur.
 */

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/../vendor/autoload.php';

use Minishlink\WebPush\WebPush;
use Minishlink\WebPush\Subscription;

final class PushNotifier
{
    /**
     * Get active subscriptions list.
     */
    public static function getSubscriptions(): array
    {
        $stmt = Database::pdo()->query('SELECT * FROM push_subscriptions ORDER BY created_at DESC');
        return $stmt->fetchAll() ?: [];
    }

    /**
     * Subscribe a new device for push notifications.
     */
    public static function subscribe(string $endpoint, string $p256dh, string $auth, $adminUsername): bool
    {
        $stmt = Database::pdo()->prepare("
            REPLACE INTO push_subscriptions (admin_username, endpoint, p256dh_key, auth_key)
            VALUES (?, ?, ?, ?)
        ");
        return $stmt->execute([(string)$adminUsername, $endpoint, $p256dh, $auth]);
    }

    /**
     * Unsubscribe a device.
     */
    public static function unsubscribe(string $endpoint): bool
    {
        $stmt = Database::pdo()->prepare('DELETE FROM push_subscriptions WHERE endpoint = ?');
        return $stmt->execute([$endpoint]);
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
     * Dispatch push payload to all registered mobile devices using VAPID.
     */
    public static function sendPush(string $title, string $body, ?string $url = null, ?string $tag = null): array
    {
        $subscriptions = self::getSubscriptions();
        if (empty($subscriptions)) {
            return ['ok' => true, 'subscriptions_count' => 0, 'dispatched' => 0];
        }

        $config = require __DIR__ . '/../config/config.php';
        $auth = [
            'VAPID' => [
                'subject' => $config['vapid']['subject'],
                'publicKey' => $config['vapid']['public_key'],
                'privateKey' => $config['vapid']['private_key'],
            ],
        ];

        $webPush = new WebPush($auth);
        
        $payload = json_encode([
            'title' => $title,
            'message' => $body,
            'actionUrl' => $url ?? '/public/admin/index.php',
            'tag' => $tag ?? 'hercule-alert-' . time(),
        ]);

        foreach ($subscriptions as $row) {
            $subscription = Subscription::create([
                'endpoint' => $row['endpoint'],
                'publicKey' => $row['p256dh_key'],
                'authToken' => $row['auth_key'],
            ]);
            $webPush->queueNotification($subscription, $payload);
        }

        $dispatched = 0;
        foreach ($webPush->flush() as $report) {
            $endpoint = $report->getRequest()->getUri()->__toString();
            if ($report->isSuccess()) {
                $dispatched++;
            } else {
                if ($report->isSubscriptionExpired()) {
                    $stmt = Database::pdo()->prepare('DELETE FROM push_subscriptions WHERE endpoint = ?');
                    $stmt->execute([$endpoint]);
                }
            }
        }

        return ['ok' => true, 'subscriptions_count' => count($subscriptions), 'dispatched' => $dispatched];
    }
}
