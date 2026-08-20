<?php
/**
 * Sends one Web Push alert per license at the 30d, 7d, 1d, and expired thresholds.
 * Schedule server-side (for example, hourly or daily).
 *
 * Usage: php scripts/send_expiry_alerts.php
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../includes/Database.php';
require_once __DIR__ . '/../includes/PushNotifier.php';

$pdo = Database::pdo();

$tableCheck = $pdo->prepare(
    'SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
);
$tableCheck->execute(['license_expiry_alerts']);
if ((int) $tableCheck->fetchColumn() === 0) {
    fwrite(STDERR, "license_expiry_alerts table is missing. Run php db/migrate_expiry_alerts.php first.\n");
    exit(1);
}

$stmt = $pdo->query(
    "SELECT l.id, l.license_key, l.expires_at, c.name AS customer_name
     FROM licenses l
     JOIN customers c ON c.id = l.customer_id
     WHERE l.expires_at IS NOT NULL
       AND l.status IN ('active','expired')
       AND l.expires_at <= DATE_ADD(UTC_TIMESTAMP(), INTERVAL 30 DAY)
     ORDER BY l.expires_at ASC"
);
$licenses = $stmt->fetchAll() ?: [];

$sent = 0;
$skipped = 0;
$failed = 0;

foreach ($licenses as $license) {
    $expiresTs = strtotime((string) $license['expires_at']);
    if ($expiresTs === false) {
        $skipped++;
        continue;
    }

    $secondsRemaining = $expiresTs - time();
    $daysRemaining = (int) ceil($secondsRemaining / 86400);

    if ($daysRemaining <= 0) {
        $threshold = 0;
    } elseif ($daysRemaining <= 1) {
        $threshold = 1;
    } elseif ($daysRemaining <= 7) {
        $threshold = 7;
    } else {
        $threshold = 30;
    }

    $check = $pdo->prepare(
        'SELECT 1 FROM license_expiry_alerts
         WHERE license_id = ? AND threshold_days = ? AND expires_at = ? LIMIT 1'
    );
    $check->execute([(int) $license['id'], $threshold, $license['expires_at']]);
    if ($check->fetchColumn()) {
        $skipped++;
        continue;
    }

    if ($threshold === 0) {
        $title = '⛔ License expired';
        $body = sprintf('%s — %s has expired.', $license['customer_name'], $license['license_key']);
    } elseif ($threshold === 1) {
        $title = '⚠️ License expires within 1 day';
        $body = sprintf('%s — %s expires very soon.', $license['customer_name'], $license['license_key']);
    } else {
        $title = "⏳ License expires within {$threshold} days";
        $body = sprintf('%s — %s expires on %s UTC.', $license['customer_name'], $license['license_key'], gmdate('M j, Y H:i', $expiresTs));
    }

    $result = PushNotifier::sendPush(
        $title,
        $body,
        '/public/admin/license_detail.php?id=' . (int) $license['id'],
        'license-expiry-' . (int) $license['id'] . '-' . $threshold,
        'expiry'
    );

    if (empty($result['ok'])) {
        $failed++;
        fwrite(STDERR, "Push failed for license {$license['license_key']} at {$threshold}d threshold.\n");
        continue;
    }

    $insert = $pdo->prepare(
        'INSERT IGNORE INTO license_expiry_alerts (license_id, threshold_days, expires_at) VALUES (?, ?, ?)'
    );
    $insert->execute([(int) $license['id'], $threshold, $license['expires_at']]);
    $sent++;
}

printf("Expiry alerts complete: sent=%d skipped=%d failed=%d\n", $sent, $skipped, $failed);
exit($failed > 0 ? 2 : 0);
