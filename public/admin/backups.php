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
<div class="page-container backup-page">
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
            <small><?= $status['configured'] ? ($status['readable'] ? 'Directory readable' : 'Directory unavailable') : 'Backup storage is not configured yet' ?></small>
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
            <strong>Backup storage needs one server secret</strong>
            <p>The app uses <code>/home/backups/hercule-pos</code> as the default Azure backup directory. Add <code>BACKUP_ENCRYPTION_KEY</code> in Azure App Settings, restart the Web App, then schedule <code>scripts/backup_database.sh</code>.</p>
        </section>
    <?php elseif (!$status['readable']): ?>
        <section class="device-migration-warning">
            <strong>Backup directory cannot be read</strong>
            <p>Current path: <code><?= htmlspecialchars((string)$status['directory']) ?></code>. Check that the directory exists and the web process has read access.</p>
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
                                <?php if (($file['checksum_status'] ?? '') === 'verified'): ?>
                                    <span class="badge badge-ok">Verified</span>
                                <?php elseif (($file['checksum_status'] ?? '') === 'mismatch'): ?>
                                    <span class="badge badge-expired">Mismatch</span>
                                <?php elseif (($file['checksum_status'] ?? '') === 'deferred'): ?>
                                    <span class="badge">Server verify required</span>
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
        <div class="section-heading"><div><p class="eyebrow">Load control</p><h2>Retention & schedule</h2></div></div>
        <div class="backup-checklist">
            <article><span class="timeline-dot"></span><div><strong>One production dump per day</strong><p>The automated verified backup runs once daily at 03:30 Iraq time, keeping database load predictable during low traffic.</p></div></article>
            <article><span class="timeline-dot"></span><div><strong>Seven local copies</strong><p><code>scripts/backup_database.sh</code> keeps the newest 7 local encrypted backups by default and removes older normal copies automatically. Override with <code>BACKUP_RETENTION_COUNT</code> if needed.</p></div></article>
            <article><span class="timeline-dot"></span><div><strong>No overlapping jobs</strong><p>A server-side lock prevents a second backup from starting while another backup is still running.</p></div></article>
            <article><span class="timeline-dot"></span><div><strong>Verified off-server history</strong><p>GitHub Actions restores each daily backup into a disposable MySQL instance before keeping the encrypted artifact for 14 days.</p></div></article>
        </div>
    </section>

    <section class="detail-section">
        <div class="section-heading"><div><p class="eyebrow">Recovery readiness</p><h2>Operational checklist</h2></div></div>
        <div class="backup-checklist">
            <article><span class="timeline-dot"></span><div><strong>Encrypted at rest</strong><p>Backups are encrypted by <code>scripts/backup_database.sh</code> before they are stored.</p></div></article>
            <article><span class="timeline-dot"></span><div><strong>Checksum verification</strong><p>Normal-size archives are verified inline. Full restore verification uses <code>scripts/verify_backup.sh</code> against a disposable database.</p></div></article>
            <article><span class="timeline-dot"></span><div><strong>Guarded production restore</strong><p>Production recovery is intentionally CLI-only. Use <code>RESTORE_CONFIRM=RESTORE-PRODUCTION bash scripts/restore_database.sh /home/backups/hercule-pos/&lt;backup&gt;.sql.enc</code>. The script validates the archive and creates a separate encrypted pre-restore safety snapshot before importing anything.</p></div></article>
        </div>
    </section>
</div>
<?php render_footer(); ?>
