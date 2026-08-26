<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/../../includes/ReleaseManager.php';
require_once __DIR__ . '/../../includes/ReleaseStorage.php';
require_once __DIR__ . '/../../includes/AuditLog.php';

Auth::require();
Auth::requirePermission('releases.manage');

$isAjax = isset($_GET['ajax']) && $_GET['ajax'] === '1';

$finish = static function (bool $ok, string $message) use ($isAjax): void {
    if ($isAjax) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code($ok ? 200 : 400);
        echo json_encode(['ok' => $ok, 'message' => $message], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
    flash_set($message, $ok ? 'success' : 'error');
    header('Location: /public/admin/releases.php');
    exit;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::guard();
    $action = (string)($_POST['action'] ?? '');
    try {
        switch ($action) {
            case 'setup_v2':
                ReleaseManager::ensureSchemaV2();
                AuditLog::adminAction('release_v2_setup', null, 'Release Management V2 initialized');
                $finish(true, 'Release Management V2 is ready.');
                break;
            case 'upload_bundle':
                // Non-JavaScript fallback only. The normal browser path uses
                // release_upload_fast.php with small parallel chunks.
                @set_time_limit(0);
                ReleaseManager::ensureSchemaV2();
                $result = ReleaseManager::createFromBundle($_FILES['bundle'] ?? [], $_POST, Auth::currentUsername() ?? 'admin');
                AuditLog::adminAction(
                    'release_bundle_uploaded',
                    (int)$result['id'],
                    'version=' . $result['version'] . '; published=' . ($result['published'] ? '1' : '0') . '; target=' . $result['target_mode']
                );
                $finish(true, 'Update bundle v' . $result['version'] . ' uploaded successfully' . ($result['published'] ? ' and published.' : ' as a draft.'));
                break;
            case 'publish':
                $releaseId = (int)($_POST['release_id'] ?? 0);
                ReleaseManager::setPublished($releaseId, true);
                AuditLog::adminAction('release_published', $releaseId, 'Desktop release published');
                $finish(true, 'Release published.');
                break;
            case 'unpublish':
                $releaseId = (int)($_POST['release_id'] ?? 0);
                ReleaseManager::setPublished($releaseId, false);
                AuditLog::adminAction('release_unpublished', $releaseId, 'Desktop release unpublished');
                $finish(true, 'Release unpublished.');
                break;
            case 'pause':
                $releaseId = (int)($_POST['release_id'] ?? 0);
                ReleaseManager::setPaused($releaseId, true);
                AuditLog::adminAction('release_paused', $releaseId, 'Desktop release rollout paused');
                $finish(true, 'Release rollout paused.');
                break;
            case 'resume':
                $releaseId = (int)($_POST['release_id'] ?? 0);
                ReleaseManager::setPaused($releaseId, false);
                AuditLog::adminAction('release_resumed', $releaseId, 'Desktop release rollout resumed');
                $finish(true, 'Release rollout resumed.');
                break;
            case 'mandatory':
                $releaseId = (int)($_POST['release_id'] ?? 0);
                $mandatory = !empty($_POST['value']);
                ReleaseManager::setMandatory($releaseId, $mandatory);
                AuditLog::adminAction('release_mandatory_changed', $releaseId, 'mandatory=' . ($mandatory ? '1' : '0'));
                $finish(true, 'Mandatory update setting changed.');
                break;
            case 'release_all':
                $releaseId = (int)($_POST['release_id'] ?? 0);
                ReleaseManager::setTargets($releaseId, 'all', []);
                AuditLog::adminAction('release_audience_all', $releaseId, 'Release audience expanded to all eligible users');
                $finish(true, 'Release is now available to all eligible users.');
                break;
            case 'delete':
                $releaseId = (int)($_POST['release_id'] ?? 0);
                $releaseBeforeDelete = ReleaseManager::find($releaseId);
                ReleaseManager::delete($releaseId);
                AuditLog::adminAction('release_deleted', $releaseId, 'version=' . (string)($releaseBeforeDelete['version'] ?? 'unknown'));
                $finish(true, 'Release and stored files deleted.');
                break;
            default:
                throw new InvalidArgumentException('Unknown release action.');
        }
    } catch (Throwable $e) {
        $finish(false, $e->getMessage());
    }
}

$schemaReady = ReleaseManager::schemaV2Ready();
$releases = [];
$licenses = [];
$latest = null;
if ($schemaReady) {
    try {
        $releases = ReleaseManager::all();
        $licenses = ReleaseManager::listTargetLicenses();
        $latest = ReleaseManager::latestPublished();
    } catch (Throwable $e) {
        flash_set('Release data could not be loaded: ' . $e->getMessage(), 'error');
    }
}

$maxUploadBytes = ReleaseStorage::maxUploadBytes();
$maxUploadMb = (int)round($maxUploadBytes / 1024 / 1024);
render_header('Releases');
flash_render();
?>
<div class="releases-page">
  <section class="page-hero">
    <div>
      <p class="eyebrow">Desktop delivery V2</p>
      <h1>Release Management</h1>
      <p class="page-subtitle">Upload Hercule Update Bundles, target everyone or selected licenses, pause rollouts, and deliver secured resumable downloads.</p>
    </div>
    <?php if ($latest): ?><span class="badge badge-ok">Latest public: <?= htmlspecialchars((string)$latest['version']) ?></span><?php endif; ?>
  </section>

  <?php if (!$schemaReady): ?>
    <div class="release-warning"><strong>Release Management V2 needs initialization.</strong><br>The setup is additive and keeps existing release records.</div>
    <form method="post"><?= Csrf::field() ?><button class="primary-btn" name="action" value="setup_v2">Initialize Release Management V2</button></form>
  <?php else: ?>
  <section class="release-grid">
    <div class="release-panel">
      <h2>Upload update bundle</h2>
      <form method="post" enctype="multipart/form-data" class="release-form" id="release-upload-form" data-max-upload-bytes="<?= (int)$maxUploadBytes ?>">
        <?= Csrf::field() ?><input type="hidden" name="action" value="upload_bundle">
        <div class="release-drop">
          <label><span>Bundle generated by <code>npm run build</code></span><input type="file" id="release-bundle-file" name="bundle" accept=".zip,application/zip" required></label>
          <small>Maximum total bundle size: <?= $maxUploadMb ?> MB. Large files are uploaded in parallel 512 KB parts with automatic fallback to smaller parts when required by Azure.</small>
        </div>
        <label><span>Minimum supported version (optional)</span><input type="text" name="minimum_supported_version" maxlength="50" placeholder="e.g. 1.1.0"></label>
        <label><span>Release channel</span><select name="channel"><option value="stable">Stable</option><option value="beta">Beta / Test</option></select></label>
        <label><span>Release notes</span><textarea name="release_notes" rows="6" maxlength="10000" placeholder="What changed in this release?"></textarea></label>
        <label><span>Audience</span><select name="target_mode" id="target-mode"><option value="all">All eligible users</option><option value="licenses">Selected licenses only</option></select></label>
        <div class="target-box" id="target-box" hidden>
          <input class="target-search" id="target-search" type="search" placeholder="Search customer, license or version">
          <div class="target-list" id="target-list">
          <?php foreach ($licenses as $l): $key=(string)$l['license_key']; $masked=mb_substr($key,0,8).'…'.mb_substr($key,-4); ?>
            <label class="target-row" data-search="<?= htmlspecialchars(mb_strtolower((string)$l['customer_name'].' '.$key.' '.($l['app_version'] ?? ''))) ?>">
              <input type="checkbox" name="target_license_ids[]" value="<?= (int)$l['id'] ?>">
              <span><strong><?= htmlspecialchars((string)$l['customer_name']) ?></strong> · <?= htmlspecialchars($masked) ?><em><?= htmlspecialchars((string)$l['status']) ?> · app <?= htmlspecialchars((string)($l['app_version'] ?: 'unknown')) ?> · devices <?= (int)$l['activation_count'] ?></em></span>
            </label>
          <?php endforeach; ?>
          </div>
        </div>
        <label class="release-checkbox"><input type="checkbox" name="is_mandatory" value="1"><span>Mandatory update</span></label>
        <label class="release-checkbox"><input type="checkbox" name="is_published" value="1"><span>Publish immediately after verification</span></label>
        <div class="upload-progress" id="upload-progress">
          <progress class="progress-track progress-native" id="progress-bar" max="100" value="0" aria-label="Upload progress" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0">0%</progress>
          <div class="progress-stage"><strong id="progress-text">Preparing upload…</strong><span id="progress-detail"></span></div>
        </div>
        <div class="upload-help" id="upload-help">Fast upload is ready.</div>
        <button class="primary-btn" type="submit" id="upload-btn">Upload and verify bundle</button>
      </form>
      <p class="storage-info">Storage: <?= htmlspecialchars(ReleaseStorage::baseDir()) ?></p>
    </div>

    <div class="release-panel">
      <h2>Release history</h2>
      <div class="release-list">
      <?php if (!$releases): ?>
        <div class="empty-state compact"><div><strong>No releases yet</strong><p>Build an Update Bundle and upload it here.</p></div></div>
      <?php else: foreach ($releases as $r): ?>
        <article class="release-card">
          <header>
            <div><h3>v<?= htmlspecialchars((string)$r['version']) ?></h3><small><?= htmlspecialchars((string)$r['created_at']) ?> · <?= htmlspecialchars((string)($r['created_by'] ?? 'system')) ?></small></div>
            <div class="release-badges">
              <span class="release-badge <?= !empty($r['is_published']) ? 'live' : '' ?>"><?= !empty($r['is_published']) ? 'Published' : 'Draft' ?></span>
              <?php if (!empty($r['is_paused'])): ?><span class="release-badge paused">Paused</span><?php endif; ?>
              <?php if (!empty($r['is_mandatory'])): ?><span class="release-badge force">Mandatory</span><?php endif; ?>
              <span class="release-badge"><?= htmlspecialchars((string)($r['channel'] ?? 'stable')) ?></span>
              <span class="release-badge"><?= ($r['target_mode'] ?? 'all') === 'all' ? 'All users' : ((int)($r['target_count'] ?? 0) . ' targeted') ?></span>
            </div>
          </header>
          <?php if (!empty($r['release_notes'])): ?><p class="release-notes"><?= htmlspecialchars((string)$r['release_notes']) ?></p><?php endif; ?>
          <div class="release-meta">
            <span><?= !empty($r['installer_size']) ? number_format(((int)$r['installer_size'])/1024/1024,1).' MB' : 'Legacy link' ?></span>
            <span>Installed events: <?= (int)($r['installed_count'] ?? 0) ?></span>
            <span>Failed: <?= (int)($r['failed_count'] ?? 0) ?></span>
            <?php if (!empty($r['minimum_supported_version'])): ?><span>Min <?= htmlspecialchars((string)$r['minimum_supported_version']) ?></span><?php endif; ?>
          </div>
          <div class="release-actions">
            <form method="post"><?= Csrf::field() ?><input type="hidden" name="release_id" value="<?= (int)$r['id'] ?>"><button name="action" value="<?= !empty($r['is_published']) ? 'unpublish' : 'publish' ?>"><?= !empty($r['is_published']) ? 'Unpublish' : 'Publish' ?></button></form>
            <?php if (!empty($r['is_published'])): ?><form method="post"><?= Csrf::field() ?><input type="hidden" name="release_id" value="<?= (int)$r['id'] ?>"><button name="action" value="<?= !empty($r['is_paused']) ? 'resume' : 'pause' ?>"><?= !empty($r['is_paused']) ? 'Resume' : 'Pause rollout' ?></button></form><?php endif; ?>
            <form method="post"><?= Csrf::field() ?><input type="hidden" name="release_id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="value" value="<?= !empty($r['is_mandatory']) ? '0' : '1' ?>"><button name="action" value="mandatory"><?= !empty($r['is_mandatory']) ? 'Make optional' : 'Force update' ?></button></form>
            <?php if (($r['target_mode'] ?? 'all') !== 'all'): ?><form method="post" data-confirm="Release this version to all eligible users?"><?= Csrf::field() ?><input type="hidden" name="release_id" value="<?= (int)$r['id'] ?>"><button name="action" value="release_all">Release to everyone</button></form><?php endif; ?>
            <form method="post" data-confirm="Delete this release and its stored files?"><?= Csrf::field() ?><input type="hidden" name="release_id" value="<?= (int)$r['id'] ?>"><button class="danger" name="action" value="delete">Delete</button></form>
          </div>
        </article>
      <?php endforeach; endif; ?>
      </div>
    </div>
  </section>
  <?php endif; ?>
</div>
<?php render_footer(); ?>