<?php
/**
 * Realtime Mobile Push Notifications for Hercule POS License Server.
 */
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/../vendor/autoload.php';

use Minishlink\WebPush\WebPush;
use Minishlink\WebPush\Subscription;

final class PushNotifier
{
    public static function getSubscriptions(): array
    {
        $stmt = Database::pdo()->query('SELECT * FROM push_subscriptions ORDER BY created_at DESC');
        return $stmt->fetchAll() ?: [];
    }

    public static function subscribe(string $endpoint, string $p256dh, string $auth, $adminUsername): bool
    {
        $stmt = Database::pdo()->prepare("REPLACE INTO push_subscriptions (admin_username, endpoint, p256dh_key, auth_key) VALUES (?, ?, ?, ?)");
        return $stmt->execute([(string)$adminUsername, $endpoint, $p256dh, $auth]);
    }

    public static function unsubscribe(string $endpoint): bool
    {
        $stmt = Database::pdo()->prepare('DELETE FROM push_subscriptions WHERE endpoint = ?');
        return $stmt->execute([$endpoint]);
    }

    public static function notifyActivation(string $licenseKey, string $hwid, ?string $deviceName = null): void
    {
        self::sendPush(
            '⚡ Live POS Terminal Activation',
            "License [{$licenseKey}] activated on device HWID [{$hwid}]" . ($deviceName ? " ({$deviceName})" : ''),
            '/public/admin/licenses.php',
            'activation-' . time()
        );
    }

    public static function notifyRecovery(string $licenseKey, string $hwid, string $username): void
    {
        self::sendPush(
            '🚨 Urgent Store PIN Reset Request',
            "Staff \"{$username}\" on terminal [{$hwid}] requested emergency password recovery.",
            '/public/admin/recovery_requests.php',
            'recovery-' . time()
        );
    }

    public static function sendPush(string $title, string $body, ?string $url = null, ?string $tag = null): array
    {
        $subscriptions = self::getSubscriptions();
        if (empty($subscriptions)) {
            return ['ok' => true, 'subscriptions_count' => 0, 'dispatched' => 0];
        }

        $config = require __DIR__ . '/../config/config.php';
        $vapid = $config['vapid'] ?? [];
        $subject = trim((string)($vapid['subject'] ?? ''));
        $publicKey = trim((string)($vapid['public_key'] ?? ''));
        $privateKey = trim((string)($vapid['private_key'] ?? ''));

        // Never attempt to construct WebPush with incomplete credentials. This
        // makes a missing Azure setting visible to the test endpoint instead of
        // producing a library-level exception or an ambiguous delivery failure.
        if ($subject === '' || $publicKey === '' || $privateKey === '') {
            return [
                'ok' => false,
                'error' => 'VAPID configuration is incomplete. Set VAPID_SUBJECT, VAPID_PUBLIC_KEY and VAPID_PRIVATE_KEY in the server environment.',
                'subscriptions_count' => count($subscriptions),
                'dispatched' => 0,
            ];
        }

        try {
            $webPush = new WebPush([
                'VAPID' => [
                    'subject' => $subject,
                    'publicKey' => $publicKey,
                    'privateKey' => $privateKey,
                ],
            ]);

            $payload = json_encode([
                'title' => $title,
                'body' => $body,
                'message' => $body,
                'url' => $url ?? '/public/admin/index.php',
                'actionUrl' => $url ?? '/public/admin/index.php',
                'tag' => $tag ?? 'hercule-alert-' . time(),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            foreach ($subscriptions as $row) {
                $subscription = Subscription::create([
                    'endpoint' => $row['endpoint'],
                    'publicKey' => $row['p256dh_key'],
                    'authToken' => $row['auth_key'],
                ]);
                $webPush->queueNotification($subscription, $payload);
            }

            $dispatched = 0;
            $failed = 0;
            $expired = 0;
            foreach ($webPush->flush() as $report) {
                $endpoint = $report->getRequest()->getUri()->__toString();
                if ($report->isSuccess()) {
                    $dispatched++;
                    continue;
                }
                $failed++;
                if ($report->isSubscriptionExpired()) {
                    $expired++;
                    $stmt = Database::pdo()->prepare('DELETE FROM push_subscriptions WHERE endpoint = ?');
                    $stmt->execute([$endpoint]);
                }
            }

            return [
                'ok' => $failed === 0 || $dispatched > 0,
                'subscriptions_count' => count($subscriptions),
                'dispatched' => $dispatched,
                'failed' => $failed,
                'expired_removed' => $expired,
            ];
        } catch (Throwable $e) {
            error_log('Web Push dispatch failed: ' . $e->getMessage());
            return [
                'ok' => false,
                'error' => 'Web Push dispatch failed. Check the server VAPID configuration and subscription state.',
                'subscriptions_count' => count($subscriptions),
                'dispatched' => 0,
            ];
        }
    }
}
