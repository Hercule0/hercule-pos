<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/../../includes/BackupManager.php';
Auth::require();

if (Auth::currentRole() !== 'owner') {
    http_response_code(403);
    exit('Forbidden');
}

$status = BackupManager::status();
$latestHealthy = $status['latest_age_hours'] !== null && $status['latest_age_hours'] <= 26;

render_header('Backups');
?>
<div class="page-container">
    <section class="page-hero">
        <div>
            <p class="eyebrow">Resilience</p>
            <h1>Database Backups</h1>
            <p class="page-subtitle">Read-only visibility into encrypted database backup health. Backup creation remains a server-side scheduled task.</p>
        </div>
    </section>

    <section class="detail-facts" aria-label="Backup summary">
        <article>
            <span>Configuration</span>
            <strong><?= $status['configured'] ? 'Configured' : 'Missing' ?></strong>
            <small><?= $status['configured'] ? ($status['readable'] ? 'Directory readable' : 'Directory unavailable') : 'Set BACKUP_DIR on the server' ?></small>
        </article>
        <article>
            <span>Latest backup</span>
            <strong><?= $status['latest_at'] ? htmlspecialchars(gmdate('M j, Y H:i', strtotime($status['latest_at']))) . ' UTC' : 'None' ?></strong>
            <small><?= $status['latest_age_hours'] !== null ? htmlspecialchars((string)$status['latest_age_hours']) . ' hours ago' : 'No encrypted backup detected' ?></small>
        </article>
        <article>
            <span>Health</span>
            <strong class="<?= $latestHealthy ? 'text-emerald' : 'danger-text' ?>"><?= $latestHealthy ? 'Healthy' : 'Attention' ?></strong>
            <small><?= $latestHealthy ? 'A backup exists within the last 26 hours' : 'Latest backup is missing or older than 26 hours' ?></small>
        </article>
    </section>

    <?php if (!$status['configured']): ?>
        <section class="device-migration-warning">
            <strong>Backup directory is not configured</strong>
            <p>Set <code>BACKUP_DIR</code> and <code>BACKUP_ENCRYPTION_KEY</code> on the server, then schedule <code>scripts/backup_database.sh</code>.</p>
        </section>
    <?php elseif (!$status['readable']): ?>
        <section class="device-migration-warning">
            <strong>Backup directory cannot be read</strong>
            <p>Check the server path and filesystem permissions. The web process only needs read access for this page.</p>
        </section>
    <?php endif; ?>

    <section class="detail-section">
        <div class="section-heading">
            <div><p class="eyebrow">Encrypted archives</p><h2>Recent backups</h2></div>
            <span class="section-count"><?= (int)$status['count'] ?></span>
        </div>

        <?php if (empty($status['files'])): ?>
            <div class="empty-state compact">
                <span class="empty-icon">—</span>
                <div><strong>No backups found</strong><p>Encrypted <code>.sql.enc</code> files will appear here after the scheduled backup job runs.</p></div>
            </div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="data-table">
                    <thead><tr><th>File</th><th>Created</th><th>Size</th><th>Checksum</th></tr></thead>
                    <tbody>
                    <?php foreach ($status['files'] as $file): ?>
                        <tr>
                            <td><code dir="ltr"><?= htmlspecialchars($file['name']) ?></code></td>
                            <td><?= htmlspecialchars(gmdate('M j, Y H:i', strtotime($file['modified_at']))) ?> UTC</td>
                            <td><?= htmlspecialchars(BackupManager::formatBytes((int)$file['size_bytes'])) ?></td>
                            <td>
                                <?php if ($file['checksum_ok'] === true): ?>
                                    <span class="badge badge-ok">Verified</span>
                                <?php elseif ($file['checksum_ok'] === false): ?>
                                    <span class="badge badge-expired">Mismatch</span>
                                <?php else: ?>
                                    <span class="badge">No checksum</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

    <section class="detail-section">
        <div class="section-heading"><div><p class="eyebrow">Recovery readiness</p><h2>Operational checklist</h2></div></div>
        <div class="event-timeline">
            <article><span class="timeline-dot"></span><div><strong>Encrypted at rest</strong><p>Backups are expected to be encrypted by <code>scripts/backup_database.sh</code> before storage.</p></div></article>
            <article><span class="timeline-dot"></span><div><strong>Checksum verification</strong><p>This page recalculates SHA-256 for recent archives and compares it with the sidecar checksum file.</p></div></article>
            <article><span class="timeline-dot"></span><div><strong>Restore testing</strong><p>Run <code>scripts/verify_backup.sh</code> from a trusted server shell on a schedule. The admin page intentionally does not decrypt or restore backups.</p></div></article>
        </div>
    </section>
</div>
<?php render_footer(); ?>
