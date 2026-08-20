<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/../../includes/NotificationPreferences.php';
Auth::require();

$username = Auth::currentUsername() ?? 'admin';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::guard();

    $mute = $_POST['mute'] ?? 'off';
    $mutedUntil = null;
    if ($mute === '1h') $mutedUntil = date('Y-m-d H:i:s', time() + 3600);
    if ($mute === '8h') $mutedUntil = date('Y-m-d H:i:s', time() + 8 * 3600);
    if ($mute === '24h') $mutedUntil = date('Y-m-d H:i:s', time() + 24 * 3600);

    NotificationPreferences::save($username, [
        'activation' => isset($_POST['activation']),
        'recovery' => isset($_POST['recovery']),
        'expiry' => isset($_POST['expiry']),
        'security' => isset($_POST['security']),
        'system' => isset($_POST['system']),
    ], $mutedUntil);

    flash_set('Notification settings updated.');
    header('Location: /public/admin/notification_settings.php');
    exit;
}

$prefs = NotificationPreferences::get($username);
$muted = !empty($prefs['muted_until']) && strtotime((string) $prefs['muted_until']) > time();

render_header('Notification settings');
flash_render();
?>

<div class="settings-page">
    <div class="page-heading">
        <div><p class="eyebrow">Preferences</p><h1>Notification settings</h1><p>Choose which operational alerts should reach this administrator account.</p></div>
    </div>

    <form method="post" class="detail-section" style="max-width:760px">
        <?= Csrf::field() ?>
        <div class="section-heading"><div><p class="eyebrow">Push categories</p><h2>Alert types</h2></div></div>

        <?php foreach ([
            'activation' => ['New device activation', 'Notify when a POS terminal activates a license.'],
            'recovery' => ['Password recovery', 'Notify for emergency password recovery requests.'],
            'expiry' => ['License expiry', 'Notify for upcoming and completed license expirations.'],
            'security' => ['Security alerts', 'Notify for authentication or suspicious-access events.'],
            'system' => ['System alerts', 'Notify for server, database, backup or runtime issues.'],
        ] as $key => [$title, $desc]): ?>
            <label style="display:flex;gap:12px;align-items:flex-start;padding:14px 0;border-bottom:1px solid var(--border-color,#263040)">
                <input type="checkbox" name="<?= $key ?>" value="1" <?= !empty($prefs[$key]) ? 'checked' : '' ?> style="margin-top:4px">
                <span><strong><?= htmlspecialchars($title) ?></strong><small style="display:block;opacity:.75;margin-top:4px"><?= htmlspecialchars($desc) ?></small></span>
            </label>
        <?php endforeach; ?>

        <div style="margin-top:22px">
            <label><span style="display:block;margin-bottom:8px">Mute all notifications temporarily</span>
                <select name="mute">
                    <option value="off" <?= !$muted ? 'selected' : '' ?>>Not muted</option>
                    <option value="1h">Mute for 1 hour</option>
                    <option value="8h">Mute for 8 hours</option>
                    <option value="24h">Mute for 24 hours</option>
                </select>
            </label>
            <?php if ($muted): ?><p style="margin-top:8px;opacity:.75">Muted until <?= htmlspecialchars(date('M j, Y H:i', strtotime($prefs['muted_until']))) ?></p><?php endif; ?>
        </div>

        <div class="dialog-actions" style="margin-top:24px">
            <button type="submit" class="primary-btn">Save settings</button>
        </div>
    </form>
</div>

<?php render_footer(); ?>
