<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/../../includes/LicenseLifecycle.php';
require_once __DIR__ . '/../../includes/EntitlementV2.php';
require_once __DIR__ . '/../../includes/MultiEntitlementAdmin.php';

Auth::require();
Auth::requirePermission('licenses.manage');

$pdo = Database::pdo();
$licenseId = max(0, (int) ($_GET['id'] ?? 0));
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

    try {
        switch ($action) {
            case 'extend_days':
                LicenseLifecycle::extendDays($licenseId, (int) ($_POST['days'] ?? 0), $admin);
                flash_set('License expiry extended.');
                break;
            case 'change_plan':
                $plan = (string) ($_POST['plan'] ?? '');
                $customDays = $plan === 'custom' ? (int) ($_POST['custom_days'] ?? 0) : null;
                LicenseLifecycle::changePlan($licenseId, $plan, $admin, $customDays);
                flash_set('License plan updated.');
                break;
            case 'activation_limit':
                LicenseLifecycle::updateActivationLimit($licenseId, (int) ($_POST['max_activations'] ?? 0), $admin);
                flash_set('Device activation limit updated.');
                break;
            case 'multi_entitlement':
                MultiEntitlementAdmin::update(
                    $licenseId,
                    isset($_POST['multi_cashier']) && $_POST['multi_cashier'] === '1',
                    (int) ($_POST['max_terminals'] ?? 1),
                    (int) ($_POST['max_management_devices'] ?? 1),
                    $admin
                );
                flash_set('Multi-Cashier entitlement updated.');
                break;
            case 'transfer_customer':
                LicenseLifecycle::transferCustomer($licenseId, (int) ($_POST['customer_id'] ?? 0), $admin);
                flash_set('License transferred to the selected customer.');
                break;
            case 'update_notes':
                LicenseLifecycle::updateNotes($licenseId, $_POST['notes'] ?? null, $admin);
                flash_set('License notes updated.');
                break;
            default:
                throw new InvalidArgumentException('Unknown lifecycle action.');
        }
    } catch (Throwable $e) {
        flash_set($e->getMessage(), 'error');
    }

    header('Location: /public/admin/license_lifecycle.php?id=' . $licenseId);
    exit;
}

$license = License::findById($licenseId);
$customerStmt = $pdo->prepare('SELECT id, name FROM customers WHERE id = ?');
$customerStmt->execute([(int) $license['customer_id']]);
$currentCustomer = $customerStmt->fetch();
$customers = $pdo->query('SELECT id, name FROM customers ORDER BY name ASC')->fetchAll();

$activeStmt = $pdo->prepare('SELECT COUNT(*) FROM license_activations WHERE license_id = ? AND is_active = 1');
$activeStmt->execute([$licenseId]);
$activeDevices = (int) $activeStmt->fetchColumn();

$multiSchemaReady = EntitlementV2::schemaReady();
$activeTerminals = $activeDevices;
$activeManagementDevices = 0;
if ($multiSchemaReady) {
    $terminalStmt = $pdo->prepare(
        'SELECT COUNT(*) FROM license_activations
         WHERE license_id = ? AND is_active = 1 AND revoked_at IS NULL AND counts_as_terminal = 1'
    );
    $terminalStmt->execute([$licenseId]);
    $activeTerminals = (int) $terminalStmt->fetchColumn();

    $managementStmt = $pdo->prepare(
        'SELECT COUNT(*) FROM license_activations
         WHERE license_id = ? AND is_active = 1 AND revoked_at IS NULL AND counts_as_terminal = 0'
    );
    $managementStmt->execute([$licenseId]);
    $activeManagementDevices = (int) $managementStmt->fetchColumn();
}

$eventsStmt = $pdo->prepare('SELECT event_type, note, created_by, created_at FROM subscription_events WHERE license_id = ? ORDER BY created_at DESC LIMIT 12');
$eventsStmt->execute([$licenseId]);
$events = $eventsStmt->fetchAll();

render_header('License Lifecycle');
flash_render();
?>
<div class="lifecycle-page">
    <a href="/public/admin/license_detail.php?id=<?= $licenseId ?>" class="back-link">
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m15 18-6-6 6-6"/></svg>
        Back to license details
    </a>

    <section class="page-hero">
        <div>
            <p class="eyebrow">License lifecycle</p>
            <h1><?= htmlspecialchars($license['license_key']) ?></h1>
            <p class="page-subtitle">Adjust duration, plan, activation capacity, Multi-Cashier seats, ownership, and internal notes without replacing the license key.</p>
        </div>
    </section>

    <section class="lifecycle-summary">
        <article><span>Customer</span><strong><?= htmlspecialchars($currentCustomer['name'] ?? 'Unknown') ?></strong></article>
        <article><span>Plan</span><strong><?= htmlspecialchars(str_replace('_', ' ', $license['plan'])) ?></strong></article>
        <article><span>Expiry</span><strong><?= $license['expires_at'] ? htmlspecialchars(date('M j, Y', strtotime($license['expires_at']))) : 'Lifetime' ?></strong></article>
        <?php if ($multiSchemaReady): ?>
            <article><span>Multi-Cashier</span><strong><?= !empty($license['multi_cashier']) ? 'Enabled' : 'Single' ?></strong><small><?= $activeTerminals ?> / <?= (int) ($license['max_terminals'] ?? 1) ?> terminals</small></article>
        <?php else: ?>
            <article><span>Devices</span><strong><?= $activeDevices ?> / <?= (int) $license['max_activations'] ?></strong></article>
        <?php endif; ?>
    </section>

    <section class="lifecycle-grid">
        <form method="post" class="lifecycle-card">
            <?= Csrf::field() ?>
            <div><h2>Extend expiry</h2><p>Add extra days from the later of today or the current expiry date. Lifetime licenses are protected from accidental conversion.</p></div>
            <label><span>Days to add</span><input type="number" name="days" min="1" max="3650" value="30" required inputmode="numeric"></label>
            <button class="primary-btn" type="submit" name="action" value="extend_days">Extend license</button>
        </form>

        <form method="post" class="lifecycle-card">
            <?= Csrf::field() ?>
            <div><h2>Change plan</h2><p>Changes the plan label while preserving the existing finite expiry. Lifetime removes expiry; moving away from lifetime creates a new expiry from today.</p></div>
            <label><span>Plan</span><select name="plan" id="lifecycle-plan" required>
                <?php foreach (['trial','monthly','semi_annual','annual','custom','lifetime'] as $plan): ?>
                    <option value="<?= $plan ?>" <?= $license['plan'] === $plan ? 'selected' : '' ?>><?= htmlspecialchars(ucwords(str_replace('_', ' ', $plan))) ?></option>
                <?php endforeach; ?>
            </select></label>
            <label id="lifecycle-custom-days" hidden><span>Custom days if expiry must be created</span><input type="number" name="custom_days" min="1" max="3650" value="30" inputmode="numeric"></label>
            <button class="primary-btn" type="submit" name="action" value="change_plan">Update plan</button>
        </form>

        <?php if ($multiSchemaReady): ?>
        <form method="post" class="lifecycle-card lifecycle-wide">
            <?= Csrf::field() ?>
            <div>
                <h2>Multi-Cashier entitlement</h2>
                <p>Controls whether this same license/store may run multiple POS terminals. Management-only devices use a separate seat pool. Reducing a limit below currently active devices is blocked.</p>
            </div>
            <label>
                <span>Multi-Cashier</span>
                <select name="multi_cashier" required>
                    <option value="0" <?= empty($license['multi_cashier']) ? 'selected' : '' ?>>Disabled — Single terminal</option>
                    <option value="1" <?= !empty($license['multi_cashier']) ? 'selected' : '' ?>>Enabled</option>
                </select>
            </label>
            <label>
                <span>Maximum POS terminals</span>
                <input type="number" name="max_terminals" min="<?= max(1, $activeTerminals) ?>" max="100" value="<?= (int) ($license['max_terminals'] ?? 1) ?>" required inputmode="numeric">
                <small><?= $activeTerminals ?> terminal(s) active now</small>
            </label>
            <label>
                <span>Maximum management devices</span>
                <input type="number" name="max_management_devices" min="<?= max(1, $activeManagementDevices) ?>" max="20" value="<?= (int) ($license['max_management_devices'] ?? 1) ?>" required inputmode="numeric">
                <small><?= $activeManagementDevices ?> management device(s) active now</small>
            </label>
            <button class="primary-btn" type="submit" name="action" value="multi_entitlement">Save Multi entitlement</button>
        </form>
        <?php else: ?>
        <section class="lifecycle-card lifecycle-wide">
            <div><h2>Multi-Cashier entitlement</h2><p>Fix408 server migration must be applied before Multi-Cashier seats can be managed.</p></div>
        </section>
        <?php endif; ?>

        <form method="post" class="lifecycle-card">
            <?= Csrf::field() ?>
            <div><h2>Legacy device capacity</h2><p>Compatibility limit for older v1 desktop clients. Under Fix408 this value is synchronized with the POS terminal-seat limit.</p></div>
            <label><span>Maximum active devices</span><input type="number" name="max_activations" min="<?= max(1, $activeDevices) ?>" max="100" value="<?= (int) $license['max_activations'] ?>" required inputmode="numeric"></label>
            <button class="primary-btn" type="submit" name="action" value="activation_limit">Save legacy limit</button>
        </form>

        <form method="post" class="lifecycle-card" data-confirm="Transfer this license to the selected customer?">
            <?= Csrf::field() ?>
            <div><h2>Transfer ownership</h2><p>Move this license to another existing customer. Device bindings and license history remain attached to the license.</p></div>
            <label><span>Customer</span><select name="customer_id" required>
                <?php foreach ($customers as $c): ?>
                    <option value="<?= (int) $c['id'] ?>" <?= (int) $license['customer_id'] === (int) $c['id'] ? 'selected' : '' ?>><?= htmlspecialchars($c['name']) ?></option>
                <?php endforeach; ?>
            </select></label>
            <button class="primary-btn" type="submit" name="action" value="transfer_customer">Transfer license</button>
        </form>

        <form method="post" class="lifecycle-card lifecycle-wide">
            <?= Csrf::field() ?>
            <div><h2>Internal notes</h2><p>Operational notes for administrators. These notes are not returned to the desktop client.</p></div>
            <label><span>Notes</span><textarea name="notes" rows="4" maxlength="2000" placeholder="Support, billing, or customer context"><?= htmlspecialchars($license['notes'] ?? '') ?></textarea></label>
            <button class="primary-btn" type="submit" name="action" value="update_notes">Save notes</button>
        </form>
    </section>

    <section class="detail-section">
        <div class="section-heading"><div><p class="eyebrow">History</p><h2>Recent lifecycle changes</h2></div><span class="section-count"><?= count($events) ?></span></div>
        <div class="lifecycle-history">
            <?php if (!$events): ?>
                <div class="empty-state compact"><div><strong>No lifecycle events</strong><p>Changes made here will appear in the subscription timeline.</p></div></div>
            <?php else: foreach ($events as $event): ?>
                <article class="lifecycle-event">
                    <strong><?= htmlspecialchars(str_replace('_', ' ', $event['event_type'])) ?></strong>
                    <?php if (!empty($event['note'])): ?><p><?= htmlspecialchars($event['note']) ?></p><?php endif; ?>
                    <small><?= htmlspecialchars(date('M j, Y · H:i', strtotime($event['created_at']))) ?> · <?= htmlspecialchars($event['created_by'] ?? 'system') ?></small>
                </article>
            <?php endforeach; endif; ?>
        </div>
    </section>
</div>

<script src="/public/admin/assets/js/license-lifecycle.js?v=20260904-fix408" defer></script>
<?php render_footer(); ?>
