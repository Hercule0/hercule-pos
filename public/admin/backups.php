<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/../../includes/BackupManager.php';
Auth::require();

if (Auth::currentRole() !== 'owner') {
    http_response_code(403);
    exit('Forbidden');
}

$status = BackupManager::status();
$latestHealthy = $status['latest_age_hours'] !== null
    && $status['latest_age_hours'] <= 26
    && !empty($status['latest_authenticated'])
    && !empty($status['operational']);

render_header('Backups');
?>
<div class="page-container backup-page">
    <section class="page-hero">
        <div>
            <p class="eyebrow">Resilience</p>
            <h1>Database Backups</h1>
            <p class="page-subtitle">Verified encrypted backups are created off-server, restore-tested, then synchronized to Azure persistent storage for operational recovery.</p>
        </div>
    </section>

    <section class="detail-facts" aria-label="Backup summary">
        <article>
            <span>Encryption</span>
            <strong class="<?= !empty($status['encryption_configured']) ? 'text-emerald' : 'danger-text' ?>"><?= !empty($status['encryption_configured']) ? 'Configured' : 'Missing' ?></strong>
            <small><?= !empty($status['encryption_configured']) ? 'BACKUP_ENCRYPTION_KEY is available' : 'Add a 32+ character BACKUP_ENCRYPTION_KEY in Azure App Settings' ?></small>
        </article>
        <article>
            <span>Latest backup</span>
            <strong><?= $status['latest_at'] ? htmlspecialchars(gmdate('M j, Y H:i', strtotime($status['latest_at']))) . ' UTC' : 'None' ?></strong>
            <small><?= $status['latest_age_hours'] !== null ? htmlspecialchars((string)$status['latest_age_hours']) . ' hours ago' : 'No encrypted backup detected in Azure persistent storage' ?></small>
        </article>
        <article>
            <span>Health</span>
            <strong class="<?= $latestHealthy ? 'text-emerald' : 'danger-text' ?>"><?= $latestHealthy ? 'Healthy' : 'Attention' ?></strong>
            <small><?= $latestHealthy ? 'Fresh archive with verified SHA-256 and HMAC' : 'Storage, freshness or archive authentication needs attention' ?></small>
        </article>
    </section>

    <?php if (empty($status['encryption_configured'])): ?>
        <section class="device-migration-warning">
            <strong>Backup encryption key is missing</strong>
            <p>Add <code>BACKUP_ENCRYPTION_KEY</code> in Azure App Settings. The daily workflow reads that same server secret and will refuse to create a backup if it is missing or shorter than 32 characters.</p>
        </section>
    <?php elseif (!$status['configured']): ?>
        <section class="device-migration-warning">
            <strong>Persistent backup storage is unavailable</strong>
            <p>The application could not resolve an Azure persistent backup directory. Expected default: <code>/home/backups/hercule-pos</code>.</p>
        </section>
    <?php elseif (!$status['readable'] || !$status['writable']): ?>
        <section class="device-migration-warning">
            <strong>Backup directory is not fully accessible</strong>
            <p>Current path: <code><?= htmlspecialchars((string)$status['directory']) ?></code>. The web process needs read and write access to display and validate synchronized archives.</p>
        </section>
    <?php elseif (!$status['latest_authenticated'] && !empty($status['files'])): ?>
        <section class="device-migration-warning">
            <strong>Latest archive is not fully authenticated</strong>
            <p>The newest backup is missing a valid SHA-256 or keyed HMAC, or it was created with a different encryption key. Do not rely on it for recovery until the next verified run succeeds.</p>
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
                <div><strong>No synchronized backups found</strong><p>The scheduled GitHub workflow creates and restore-verifies the archive, then synchronizes it into <code>/home/backups/hercule-pos</code>.</p></div>
            </div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="data-table">
                    <thead><tr><th>File</th><th>Created</th><th>Size</th><th>SHA-256</th><th>HMAC</th></tr></thead>
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
                                    <span class="badge">Missing</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (($file['hmac_status'] ?? '') === 'verified'): ?>
                                    <span class="badge badge-ok">Authenticated</span>
                                <?php elseif (in_array(($file['hmac_status'] ?? ''), ['mismatch','invalid'], true)): ?>
                                    <span class="badge badge-expired">Invalid</span>
                                <?php elseif (($file['hmac_status'] ?? '') === 'key-unavailable'): ?>
                                    <span class="badge">Key unavailable</span>
                                <?php elseif (($file['hmac_status'] ?? '') === 'deferred'): ?>
                                    <span class="badge">Deferred</span>
                                <?php else: ?>
                                    <span class="badge">Missing</span>
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
        <div class="section-heading"><div><p class="eyebrow">Schedule</p><h2>How the backup pipeline works</h2></div></div>
        <div class="backup-checklist">
            <article><span class="timeline-dot"></span><div><strong>03:30 Iraq time every day</strong><p>GitHub Actions signs into Azure using OIDC, reads the current production database and backup settings, and creates one encrypted dump.</p></div></article>
            <article><span class="timeline-dot"></span><div><strong>Restore verification before storage</strong><p>The encrypted archive is decrypted only inside the isolated runner and restored into a disposable MySQL instance. A failed restore stops the pipeline.</p></div></article>
            <article><span class="timeline-dot"></span><div><strong>Seven Azure recovery copies</strong><p>Only after verification, the encrypted archive plus SHA-256 and HMAC sidecars are synchronized to <code>/home/backups/hercule-pos</code>. The newest seven are retained.</p></div></article>
            <article><span class="timeline-dot"></span><div><strong>Fourteen-day off-server copy</strong><p>The same verified encrypted files are retained as a GitHub Actions artifact for 14 days, giving recovery options outside the Web App host.</p></div></article>
        </div>
    </section>

    <section class="detail-section">
        <div class="section-heading"><div><p class="eyebrow">Recovery readiness</p><h2>Operational checklist</h2></div></div>
        <div class="backup-checklist">
            <article><span class="timeline-dot"></span><div><strong>Encrypted at rest</strong><p><code>scripts/backup_database.sh</code> encrypts every dump with AES-256-CBC + PBKDF2 before the archive leaves the runner.</p></div></article>
            <article><span class="timeline-dot"></span><div><strong>Integrity + authentication</strong><p>SHA-256 detects archive corruption while the keyed HMAC detects unauthorized replacement. This page verifies both for normal-size archives.</p></div></article>
            <article><span class="timeline-dot"></span><div><strong>Guarded production restore</strong><p>Production recovery remains CLI-only. <code>restore_database.sh</code> validates the archive and creates an encrypted pre-restore safety snapshot before importing anything.</p></div></article>
        </div>
    </section>
</div>
<?php render_footer(); ?>
