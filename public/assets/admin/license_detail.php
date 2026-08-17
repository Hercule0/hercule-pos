<?php
require_once __DIR__ . '/includes/bootstrap.php';
Auth::require();

$pdo = Database::pdo();
$licenseId = (int) ($_GET['id'] ?? 0);
$license = License::findById($licenseId);

if (!$license) {
    flash_set('License not found.', 'error');
    header('Location: /public/admin/licenses.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::guard();
    $action = $_POST['action'] ?? '';
    $admin = Auth::currentUsername() ?? 'admin';

    if ($action === 'delete') {
        Auth::requirePermission('licenses.delete');
    } else {
        Auth::requirePermission('licenses.manage');
    }

    switch ($action) {
        case 'renew':
            $plan = $_POST['plan'] ?? '';
            $customDays = null;
            if ($plan === 'custom') {
                $customDays = (int) ($_POST['renew_custom_days'] ?? 0);
                if ($customDays < 1) {
                    flash_set('Enter a valid number of days (1 or more) for a custom renewal.', 'error');
                    header("Location: /public/admin/license_detail.php?id={$licenseId}");
                    exit;
                }
            }
            License::renew($licenseId, $plan, $admin, $customDays);
            flash_set('License renewed.');
            break;
        case 'suspend':
            License::suspend($licenseId, $admin);
            flash_set('License suspended.');
            break;
        case 'revoke':
            License::revoke($licenseId, $admin);
            flash_set('License revoked.');
            break;
        case 'reactivate':
            License::reactivate($licenseId, $admin);
            flash_set('License reactivated.');
            break;
        case 'deactivate_device':
            License::deactivateDevice((int) ($_POST['activation_id'] ?? 0));
            flash_set('Device deactivated — that slot is now free.');
            break;
        case 'delete':
            $keyForMessage = $license['license_key'];
            License::deleteLicense($licenseId);
            flash_set("License {$keyForMessage} permanently deleted.");
            header('Location: /public/admin/licenses.php');
            exit;
    }
    header("Location: /public/admin/license_detail.php?id={$licenseId}");
    exit;
}

$customerStmt = $pdo->prepare('SELECT * FROM customers WHERE id = ?');
$customerStmt->execute([$license['customer_id']]);
$customer = $customerStmt->fetch();

$activations = License::activationsFor($licenseId);

$eventsStmt = $pdo->prepare('SELECT * FROM subscription_events WHERE license_id = ? ORDER BY created_at DESC');
$eventsStmt->execute([$licenseId]);
$events = $eventsStmt->fetchAll();

$logStmt = $pdo->prepare('SELECT * FROM verification_log WHERE license_id = ? ORDER BY created_at DESC LIMIT 10');
$logStmt->execute([$licenseId]);
$logs = $logStmt->fetchAll();

$activeDeviceCount = count(array_filter($activations, fn($a) => $a['is_active']));
$shortKey = strlen($license['license_key']) > 17 ? '•••• ' . substr($license['license_key'], -13) : $license['license_key'];

render_header('License ' . $license['license_key']);
flash_render();
?>

<div class="license-detail-page">
    <a href="/public/admin/licenses.php" class="back-link">
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m15 18-6-6 6-6"/></svg>
        All licenses
    </a>

    <section class="license-detail-hero">
        <div class="license-detail-owner">
            <span class="license-customer-avatar"><?= strtoupper(htmlspecialchars(substr($customer['name'] ?? 'U', 0, 1))) ?></span>
            <div>
                <p class="eyebrow">License details</p>
                <h1 dir="auto"><?= htmlspecialchars($customer['name'] ?? 'Unknown customer') ?></h1>
                <span><?= htmlspecialchars(str_replace('_', ' ', $license['plan'])) ?> plan</span>
            </div>
        </div>
        <span class="license-status status-<?= htmlspecialchars($license['status']) ?>"><?= htmlspecialchars($license['status']) ?></span>
    </section>

    <section class="detail-key-card">
        <div>
            <span>License key</span>
            <code dir="ltr" title="<?= htmlspecialchars($license['license_key'], ENT_QUOTES) ?>"><?= htmlspecialchars($shortKey) ?></code>
        </div>
        <button type="button" data-copy-value="<?= htmlspecialchars($license['license_key'], ENT_QUOTES) ?>" aria-label="Copy license key">
            <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="8" y="8" width="11" height="11" rx="2"/><path d="M16 8V5H5v11h3"/></svg>
        </button>
    </section>

    <section class="detail-facts" aria-label="License summary">
        <article>
            <span>Expires</span>
            <strong><?= $license['expires_at'] ? htmlspecialchars(date('M j, Y', strtotime($license['expires_at']))) : 'Never' ?></strong>
            <small><?= $license['expires_at'] ? htmlspecialchars(date('H:i', strtotime($license['expires_at']))) : 'Lifetime access' ?></small>
        </article>
        <article>
            <span>Devices</span>
            <strong><?= $activeDeviceCount ?> / <?= (int) $license['max_activations'] ?></strong>
            <small><?= max(0, (int) $license['max_activations'] - $activeDeviceCount) ?> slots available</small>
        </article>
        <article>
            <span>Created</span>
            <strong><?= htmlspecialchars(date('M j, Y', strtotime($license['created_at']))) ?></strong>
            <small><?= count($events) ?> subscription events</small>
        </article>
    </section>

    <section class="license-action-bar" aria-label="License actions">
        <button type="button" class="detail-action primary" data-open-renew-dialog>
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 12a8 8 0 1 0 2.3-5.7L4 8.5M4 4v4.5h4.5"/></svg>
            <span>Renew</span>
        </button>
        <?php if ($license['status'] === 'active'): ?>
            <form method="post" onsubmit="return confirm('Suspend this license?');">
                <?= Csrf::field() ?>
                <button type="submit" name="action" value="suspend" class="detail-action">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="8"/><path d="M9.5 9v6M14.5 9v6"/></svg>
                    <span>Suspend</span>
                </button>
            </form>
        <?php else: ?>
            <form method="post">
                <?= Csrf::field() ?>
                <button type="submit" name="action" value="reactivate" class="detail-action">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m9 7 8 5-8 5z"/></svg>
                    <span>Reactivate</span>
                </button>
            </form>
        <?php endif; ?>
        <button type="button" class="detail-action danger" data-open-danger-dialog>
            <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="8"/><path d="M12 8v5M12 16h.01"/></svg>
            <span>More</span>
        </button>
    </section>

    <div class="license-detail-columns">
        <section class="detail-section">
            <div class="section-heading">
                <div><p class="eyebrow">Activations</p><h2>Devices</h2></div>
                <span class="section-count"><?= $activeDeviceCount ?></span>
            </div>
            <?php if (empty($activations)): ?>
                <div class="empty-state compact">
                    <span class="empty-icon">—</span>
                    <div><strong>No devices yet</strong><p>Activated devices will appear here.</p></div>
                </div>
            <?php else: ?>
                <div class="device-list">
                    <?php foreach ($activations as $a): ?>
                        <article class="device-row <?= $a['is_active'] ? '' : 'inactive' ?>">
                            <span class="device-icon">
                                <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="5" y="3" width="14" height="18" rx="2"/><path d="M10 18h4"/></svg>
                            </span>
                            <div class="device-copy">
                                <strong><?= $a['is_active'] ? 'Active device' : 'Freed device' ?></strong>
                                <code dir="ltr"><?= htmlspecialchars($a['hwid']) ?></code>
                                <small>Last seen <?= htmlspecialchars(date('M j, H:i', strtotime($a['last_seen_at']))) ?> · <?= htmlspecialchars($a['ip_address'] ?? 'No IP') ?></small>
                            </div>
                            <?php if ($a['is_active']): ?>
                                <form method="post" onsubmit="return confirm('Free up this device slot?');">
                                    <?= Csrf::field() ?>
                                    <input type="hidden" name="activation_id" value="<?= $a['id'] ?>">
                                    <button type="submit" name="action" value="deactivate_device">Deactivate</button>
                                </form>
                            <?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <section class="detail-section">
            <div class="section-heading">
                <div><p class="eyebrow">Security</p><h2>Verification activity</h2></div>
                <span class="live-indicator"><i></i>Live</span>
            </div>
            <?php if (empty($logs)): ?>
                <div class="empty-state compact">
                    <span class="empty-icon">—</span>
                    <div><strong>No attempts yet</strong><p>Verification attempts will appear here.</p></div>
                </div>
            <?php else: ?>
                <div class="verification-list">
                    <?php foreach ($logs as $l): ?>
                        <?php $isOk = ($l['result'] ?? '') === 'ok'; ?>
                        <article class="verification-row">
                            <span class="activity-status <?= $isOk ? 'ok' : 'failed' ?>"><?= $isOk ? '✓' : '!' ?></span>
                            <div>
                                <strong><?= $isOk ? 'Successful verification' : htmlspecialchars(str_replace('_', ' ', $l['result'])) ?></strong>
                                <code dir="ltr"><?= htmlspecialchars($l['hwid'] ?? 'No HWID') ?></code>
                                <small><?= htmlspecialchars($l['ip_address'] ?? 'No IP') ?> · <?= htmlspecialchars(date('M j, H:i', strtotime($l['created_at']))) ?></small>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    </div>

    <section class="detail-section event-section">
        <div class="section-heading">
            <div><p class="eyebrow">History</p><h2>Subscription timeline</h2></div>
            <span class="section-count"><?= count($events) ?></span>
        </div>
        <?php if (empty($events)): ?>
            <div class="empty-state compact">
                <span class="empty-icon">—</span>
                <div><strong>No events logged</strong><p>Subscription changes will appear here.</p></div>
            </div>
        <?php else: ?>
            <div class="event-timeline">
                <?php foreach ($events as $e): ?>
                    <article>
                        <span class="timeline-dot"></span>
                        <div>
                            <strong><?= htmlspecialchars(str_replace('_', ' ', $e['event_type'])) ?></strong>
                            <?php if ($e['note']): ?><p dir="auto"><?= htmlspecialchars($e['note']) ?></p><?php endif; ?>
                            <small><?= htmlspecialchars(date('M j, Y · H:i', strtotime($e['created_at']))) ?> · <?= htmlspecialchars($e['created_by'] ?? 'System') ?></small>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
</div>

<dialog class="app-dialog" id="renew-dialog">
    <form method="post" class="license-renew-form">
        <?= Csrf::field() ?>
        <div class="dialog-header">
            <div><p class="eyebrow">Extend access</p><h2>Renew license</h2></div>
            <button type="button" class="dialog-close" data-close-renew-dialog aria-label="Close">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m6 6 12 12M18 6 6 18"/></svg>
            </button>
        </div>
        <div class="dialog-fields">
            <label>
                <span>Renew as</span>
                <select name="plan" id="renew-plan">
                    <option value="monthly">Monthly — 1 month</option>
                    <option value="semi_annual">Semi-Annual — 6 months</option>
                    <option value="annual">Annual — 1 year</option>
                    <option value="custom">Custom duration</option>
                    <option value="lifetime">Lifetime</option>
                </select>
            </label>
            <label id="renew-custom-days-row" hidden>
                <span>Duration in days</span>
                <input type="number" name="renew_custom_days" min="1" step="1" placeholder="Example: 30" inputmode="numeric">
            </label>
        </div>
        <div class="dialog-actions">
            <button type="button" class="secondary-btn" data-close-renew-dialog>Cancel</button>
            <button type="submit" name="action" value="renew" class="primary-btn">Renew license</button>
        </div>
    </form>
</dialog>

<dialog class="app-dialog danger-dialog" id="danger-dialog">
    <div class="danger-dialog-content">
        <div class="dialog-header">
            <div><p class="eyebrow danger-text">Danger zone</p><h2>Destructive actions</h2></div>
            <button type="button" class="dialog-close" data-close-danger-dialog aria-label="Close">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m6 6 12 12M18 6 6 18"/></svg>
            </button>
        </div>
        <div class="danger-options">
            <form method="post" onsubmit="return confirm('Revoke this license?');">
                <?= Csrf::field() ?>
                <div><strong>Revoke license</strong><p>Block future verification until it is reactivated.</p></div>
                <button type="submit" name="action" value="revoke">Revoke</button>
            </form>
            <form method="post" onsubmit="return confirm('PERMANENTLY DELETE this license, its devices, and its event history? This is irreversible.');">
                <?= Csrf::field() ?>
                <div><strong>Delete permanently</strong><p>Remove this license, devices, and event history forever.</p></div>
                <button type="submit" name="action" value="delete">Delete</button>
            </form>
        </div>
    </div>
</dialog>

<script>
(function () {
    function bindDialog(openSelector, dialogId, closeSelector) {
        var dialog = document.getElementById(dialogId);
        var opener = document.querySelector(openSelector);
        if (opener && dialog) opener.addEventListener('click', function () {
            if (typeof dialog.showModal === 'function') dialog.showModal();
            else dialog.setAttribute('open', '');
        });
        document.querySelectorAll(closeSelector).forEach(function (button) {
            button.addEventListener('click', function () {
                if (typeof dialog.close === 'function') dialog.close();
                else dialog.removeAttribute('open');
            });
        });
        if (dialog) dialog.addEventListener('click', function (event) {
            if (event.target === dialog) dialog.close();
        });
    }
    bindDialog('[data-open-renew-dialog]', 'renew-dialog', '[data-close-renew-dialog]');
    bindDialog('[data-open-danger-dialog]', 'danger-dialog', '[data-close-danger-dialog]');

    var plan = document.getElementById('renew-plan');
    var custom = document.getElementById('renew-custom-days-row');
    function syncCustom() {
        if (!plan || !custom) return;
        custom.hidden = plan.value !== 'custom';
        var input = custom.querySelector('input');
        if (input) input.required = plan.value === 'custom';
    }
    if (plan) {
        plan.addEventListener('change', syncCustom);
        syncCustom();
    }

    document.querySelectorAll('[data-copy-value]').forEach(function (button) {
        button.addEventListener('click', function () {
            if (!navigator.clipboard) return;
            navigator.clipboard.writeText(button.dataset.copyValue).then(function () {
                button.classList.add('copied');
                setTimeout(function () { button.classList.remove('copied'); }, 1200);
            });
        });
    });
})();
</script>

<?php render_footer(); ?>
