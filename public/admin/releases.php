<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/../../includes/ReleaseManager.php';

Auth::require();
Auth::requirePermission('licenses.manage');

$pdo = Database::pdo();
$schemaReady = true;
try {
    $pdo->query('SELECT id FROM app_releases LIMIT 1');
} catch (Throwable $e) {
    $schemaReady = false;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::guard();
    if (!$schemaReady) {
        flash_set('Release Management migration has not been run yet.', 'error');
        header('Location: /public/admin/releases.php');
        exit;
    }

    $action = $_POST['action'] ?? '';
    try {
        switch ($action) {
            case 'create':
                ReleaseManager::create($_POST, Auth::currentUsername() ?? 'admin');
                flash_set('Release created.');
                break;
            case 'publish':
                ReleaseManager::setPublished((int) ($_POST['release_id'] ?? 0), true);
                flash_set('Release published.');
                break;
            case 'unpublish':
                ReleaseManager::setPublished((int) ($_POST['release_id'] ?? 0), false);
                flash_set('Release unpublished.');
                break;
            case 'mandatory':
                ReleaseManager::setMandatory((int) ($_POST['release_id'] ?? 0), !empty($_POST['value']));
                flash_set('Mandatory update setting changed.');
                break;
            case 'delete':
                ReleaseManager::delete((int) ($_POST['release_id'] ?? 0));
                flash_set('Release deleted.');
                break;
            default:
                throw new InvalidArgumentException('Unknown release action.');
        }
    } catch (Throwable $e) {
        flash_set($e->getMessage(), 'error');
    }
    header('Location: /public/admin/releases.php');
    exit;
}

$releases = $schemaReady ? ReleaseManager::all() : [];
$latest = $schemaReady ? ReleaseManager::latestPublished() : null;

render_header('Releases');
flash_render();
?>
<style>
.releases-page{display:grid;gap:16px}.release-grid{display:grid;grid-template-columns:360px minmax(0,1fr);gap:14px}.release-panel{padding:18px;border:1px solid rgba(148,163,184,.12);border-radius:16px;background:rgba(16,24,36,.72)}.release-panel h2{margin:0 0 12px;color:#f7f9fc;font-size:15px}.release-form{display:grid;gap:11px}.release-form label{display:grid;gap:6px;color:#91a2b6;font-size:11px;font-weight:650}.release-form input,.release-form textarea{width:100%;box-sizing:border-box;border:1px solid rgba(148,163,184,.14);border-radius:10px;background:#0b111b;color:#e8edf4;padding:10px 11px}.release-checkbox{display:flex!important;grid-template-columns:auto 1fr!important;align-items:center;gap:8px!important}.release-list{display:grid;gap:10px}.release-card{padding:14px;border:1px solid rgba(148,163,184,.1);border-radius:13px;background:rgba(148,163,184,.045);display:grid;gap:10px}.release-card header{display:flex;align-items:flex-start;justify-content:space-between;gap:10px}.release-card h3{margin:0;color:#f7f9fc;font-size:14px}.release-card small{color:#788aa0}.release-badges{display:flex;gap:6px;flex-wrap:wrap}.release-badge{padding:4px 7px;border-radius:999px;background:rgba(148,163,184,.09);color:#93a6bc;font-size:9px;font-weight:700}.release-badge.live{background:rgba(52,211,153,.1);color:#6ee7b7}.release-badge.force{background:rgba(251,113,133,.1);color:#fda4af}.release-notes{white-space:pre-wrap;color:#94a4b7;font-size:11px;line-height:1.55;margin:0}.release-actions{display:flex;gap:7px;flex-wrap:wrap}.release-actions form{margin:0}.release-actions button{border:1px solid rgba(148,163,184,.14);border-radius:8px;background:rgba(148,163,184,.06);color:#c5d1de;padding:6px 9px;font-size:10px;cursor:pointer}.release-actions .danger{color:#fda4af;border-color:rgba(251,113,133,.18)}.release-warning{padding:14px;border:1px solid rgba(251,191,36,.22);border-radius:14px;background:rgba(251,191,36,.06);color:#d7c27a;font-size:12px}@media(max-width:900px){.release-grid{grid-template-columns:1fr}}
</style>

<div class="releases-page">
    <section class="page-hero">
        <div>
            <p class="eyebrow">Desktop delivery</p>
            <h1>Release Management</h1>
            <p class="page-subtitle">Publish desktop versions, define minimum supported builds, release notes, download links, and forced updates.</p>
        </div>
        <?php if ($latest): ?><span class="badge badge-ok">Latest: <?= htmlspecialchars($latest['version']) ?></span><?php endif; ?>
    </section>

    <?php if (!$schemaReady): ?>
        <div class="release-warning"><strong>Migration required.</strong> Run <code>php db/migrate_release_management.php</code> before creating releases.</div>
    <?php endif; ?>

    <section class="release-grid">
        <div class="release-panel">
            <h2>Create release</h2>
            <form method="post" class="release-form">
                <?= Csrf::field() ?>
                <label><span>Version</span><input name="version" maxlength="50" placeholder="1.0.0" required></label>
                <label><span>Minimum supported version</span><input name="minimum_supported_version" maxlength="50" placeholder="Optional, e.g. 0.9.0"></label>
                <label><span>HTTPS download URL</span><input type="url" name="download_url" maxlength="2048" placeholder="https://..."></label>
                <label><span>Release notes</span><textarea name="release_notes" rows="6" maxlength="10000" placeholder="What changed in this release?"></textarea></label>
                <label class="release-checkbox"><input type="checkbox" name="is_mandatory" value="1"><span>Force this update</span></label>
                <label class="release-checkbox"><input type="checkbox" name="is_published" value="1"><span>Publish immediately</span></label>
                <button class="primary-btn" type="submit" name="action" value="create" <?= !$schemaReady ? 'disabled' : '' ?>>Create release</button>
            </form>
        </div>

        <div class="release-panel">
            <h2>Release history</h2>
            <div class="release-list">
                <?php if (!$releases): ?>
                    <div class="empty-state compact"><div><strong>No releases yet</strong><p>Create the first desktop release from the form.</p></div></div>
                <?php else: foreach ($releases as $r): ?>
                    <article class="release-card">
                        <header>
                            <div><h3>v<?= htmlspecialchars($r['version']) ?></h3><small><?= htmlspecialchars($r['created_at']) ?> · <?= htmlspecialchars($r['created_by'] ?? 'system') ?></small></div>
                            <div class="release-badges">
                                <span class="release-badge <?= $r['is_published'] ? 'live' : '' ?>"><?= $r['is_published'] ? 'Published' : 'Draft' ?></span>
                                <?php if ($r['is_mandatory']): ?><span class="release-badge force">Mandatory</span><?php endif; ?>
                                <?php if ($r['minimum_supported_version']): ?><span class="release-badge">Min <?= htmlspecialchars($r['minimum_supported_version']) ?></span><?php endif; ?>
                            </div>
                        </header>
                        <?php if ($r['release_notes']): ?><p class="release-notes"><?= htmlspecialchars($r['release_notes']) ?></p><?php endif; ?>
                        <?php if ($r['download_url']): ?><a href="<?= htmlspecialchars($r['download_url'], ENT_QUOTES) ?>" target="_blank" rel="noopener" class="back-link">Open download</a><?php endif; ?>
                        <div class="release-actions">
                            <form method="post"><?= Csrf::field() ?><input type="hidden" name="release_id" value="<?= (int)$r['id'] ?>"><button name="action" value="<?= $r['is_published'] ? 'unpublish' : 'publish' ?>"><?= $r['is_published'] ? 'Unpublish' : 'Publish' ?></button></form>
                            <form method="post"><?= Csrf::field() ?><input type="hidden" name="release_id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="value" value="<?= $r['is_mandatory'] ? '0' : '1' ?>"><button name="action" value="mandatory"><?= $r['is_mandatory'] ? 'Make optional' : 'Force update' ?></button></form>
                            <form method="post" onsubmit="return confirm('Delete this release record?');"><?= Csrf::field() ?><input type="hidden" name="release_id" value="<?= (int)$r['id'] ?>"><button class="danger" name="action" value="delete">Delete</button></form>
                        </div>
                    </article>
                <?php endforeach; endif; ?>
            </div>
        </div>
    </section>

    <section class="detail-section">
        <div class="section-heading"><div><p class="eyebrow">Client endpoint</p><h2>Update API</h2></div></div>
        <p class="page-subtitle">Desktop clients can call <code>/public/api/release.php?version=1.2.3</code>. The response reports whether an update exists, whether it is mandatory, the minimum supported version, download URL, notes, and publish timestamp.</p>
    </section>
</div>
<?php render_footer(); ?>
