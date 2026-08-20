<?php
require_once __DIR__ . '/includes/bootstrap.php';
Auth::require();

$role = Auth::currentRole();
$isOwner = $role === 'owner';
$canManageLicenses = Auth::can('licenses.manage');
$canManageAdmins = Auth::can('admins.manage');
$canManageReleases = Auth::can('releases.manage');

$tools = [
    [
        'show' => $canManageLicenses,
        'title' => 'Device Management',
        'description' => 'Inspect devices, app versions, block state, notes, and activation slots.',
        'href' => '/public/admin/devices.php',
        'badge' => 'Licensing',
    ],
    [
        'show' => $canManageLicenses,
        'title' => 'System Monitoring',
        'description' => 'Review database health, API activity, devices, recovery signals, and validation failures.',
        'href' => '/public/admin/monitoring.php',
        'badge' => 'Operations',
    ],
    [
        'show' => $canManageReleases,
        'title' => 'Release Management',
        'description' => 'Publish desktop releases, minimum supported versions, mandatory updates, and download metadata.',
        'href' => '/public/admin/releases.php',
        'badge' => 'Desktop',
    ],
    [
        'show' => true,
        'title' => 'Remembered Sessions',
        'description' => 'Review and revoke remembered browser sessions for your account.',
        'href' => '/public/admin/sessions.php',
        'badge' => 'Security',
    ],
    [
        'show' => true,
        'title' => 'Notification Settings',
        'description' => 'Choose which activation, recovery, expiry, security, and system alerts you receive.',
        'href' => '/public/admin/notification_settings.php',
        'badge' => 'Push',
    ],
    [
        'show' => $canManageAdmins,
        'title' => 'Audit Log',
        'description' => 'Review administrator security events, actors, targets, IP addresses, and timestamps.',
        'href' => '/public/admin/audit_log.php',
        'badge' => 'Audit',
    ],
    [
        'show' => $isOwner,
        'title' => 'Permission Overrides',
        'description' => 'Owner-only granular permission controls for administrator accounts.',
        'href' => '/public/admin/admin_permissions.php',
        'badge' => 'Owner',
    ],
    [
        'show' => $isOwner,
        'title' => 'Backup Health',
        'description' => 'Owner-only encrypted backup readiness, freshness, checksum, and restore-health view.',
        'href' => '/public/admin/backups.php',
        'badge' => 'Owner',
    ],
];

$visibleTools = array_values(array_filter($tools, static fn(array $tool): bool => !empty($tool['show'])));

render_header('Admin Tools');
?>
<section class="page-hero">
    <div>
        <p class="eyebrow">Operations & security</p>
        <h1>Admin Tools</h1>
        <p class="page-subtitle">Permission-aware shortcuts to operational, release, session, notification, audit, and backup tools.</p>
    </div>
</section>

<section class="grid-cards-wrapper" aria-label="Admin tools">
    <?php foreach ($visibleTools as $tool): ?>
        <a class="grid-card" href="<?= htmlspecialchars($tool['href'], ENT_QUOTES) ?>" style="text-decoration:none; color:inherit;">
            <div class="grid-card-header">
                <div class="grid-card-title-group">
                    <span class="badge badge-active"><?= htmlspecialchars($tool['badge']) ?></span>
                    <h2 class="grid-card-title" style="margin-top:10px;"><?= htmlspecialchars($tool['title']) ?></h2>
                </div>
            </div>
            <div class="grid-card-body">
                <p class="grid-card-note"><?= htmlspecialchars($tool['description']) ?></p>
            </div>
            <div class="grid-card-footer">
                <span class="grid-card-btn">Open tool →</span>
            </div>
        </a>
    <?php endforeach; ?>
</section>

<?php if (!$visibleTools): ?>
<section class="empty-state">
    <strong>No additional tools are available for this account.</strong>
</section>
<?php endif; ?>

<?php render_footer(); ?>
