<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/../../includes/ReleaseManager.php';
require_once __DIR__ . '/../../includes/ReleaseStorage.php';

Auth::require();
Auth::requirePermission('releases.manage');

$isAjax = isset($_GET['ajax']) && $_GET['ajax'] === '1';
$schemaReady = ReleaseManager::schemaV2Ready();

$finish = static function (bool $ok, string $message) use ($isAjax): void {
    if ($isAjax) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code($ok ? 200 : 400);
        echo json_encode(['ok'=>$ok,'message'=>$message], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
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
                $finish(true, 'Release Management V2 is ready.');
                break;
            case 'upload_bundle':
                ReleaseManager::ensureSchemaV2();
                $result = ReleaseManager::createFromBundle($_FILES['bundle'] ?? [], $_POST, Auth::currentUsername() ?? 'admin');
                $finish(true, 'Update bundle v' . $result['version'] . ' uploaded successfully' . ($result['published'] ? ' and published.' : ' as a draft.'));
                break;
            case 'publish':
                ReleaseManager::setPublished((int)($_POST['release_id'] ?? 0), true);
                $finish(true, 'Release published.');
                break;
            case 'unpublish':
                ReleaseManager::setPublished((int)($_POST['release_id'] ?? 0), false);
                $finish(true, 'Release unpublished.');
                break;
            case 'pause':
                ReleaseManager::setPaused((int)($_POST['release_id'] ?? 0), true);
                $finish(true, 'Release rollout paused.');
                break;
            case 'resume':
                ReleaseManager::setPaused((int)($_POST['release_id'] ?? 0), false);
                $finish(true, 'Release rollout resumed.');
                break;
            case 'mandatory':
                ReleaseManager::setMandatory((int)($_POST['release_id'] ?? 0), !empty($_POST['value']));
                $finish(true, 'Mandatory update setting changed.');
                break;
            case 'release_all':
                ReleaseManager::setTargets((int)($_POST['release_id'] ?? 0), 'all', []);
                $finish(true, 'Release is now available to all eligible users.');
                break;
            case 'delete':
                ReleaseManager::delete((int)($_POST['release_id'] ?? 0));
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

$maxUploadMb = (int)round(ReleaseStorage::maxUploadBytes() / 1024 / 1024);
render_header('Releases');
flash_render();
?>
<style>
.releases-page{display:grid;gap:16px}.release-grid{display:grid;grid-template-columns:minmax(330px,430px) minmax(0,1fr);gap:14px}.release-panel{padding:18px;border:1px solid rgba(148,163,184,.12);border-radius:16px;background:rgba(16,24,36,.72);min-width:0}.release-panel h2{margin:0 0 12px;color:#f7f9fc;font-size:15px}.release-form{display:grid;gap:11px}.release-form label{display:grid;gap:6px;color:#91a2b6;font-size:11px;font-weight:650}.release-form input[type=text],.release-form textarea,.release-form select{width:100%;box-sizing:border-box;border:1px solid rgba(148,163,184,.14);border-radius:10px;background:#0b111b;color:#e8edf4;padding:10px 11px;min-height:40px}.release-checkbox{display:flex!important;align-items:center;gap:8px!important}.release-list{display:grid;gap:10px}.release-card{padding:14px;border:1px solid rgba(148,163,184,.1);border-radius:13px;background:rgba(148,163,184,.045);display:grid;gap:10px;min-width:0}.release-card header{display:flex;align-items:flex-start;justify-content:space-between;gap:10px;flex-wrap:wrap}.release-card h3{margin:0;color:#f7f9fc;font-size:14px}.release-card small{color:#788aa0}.release-badges{display:flex;gap:6px;flex-wrap:wrap}.release-badge{padding:4px 7px;border-radius:999px;background:rgba(148,163,184,.09);color:#93a6bc;font-size:9px;font-weight:700}.release-badge.live{background:rgba(52,211,153,.1);color:#6ee7b7}.release-badge.force{background:rgba(251,113,133,.1);color:#fda4af}.release-badge.paused{background:rgba(251,191,36,.1);color:#fcd34d}.release-notes{white-space:pre-wrap;overflow-wrap:anywhere;color:#94a4b7;font-size:11px;line-height:1.55;margin:0}.release-actions{display:flex;gap:7px;flex-wrap:wrap}.release-actions form{margin:0}.release-actions button{border:1px solid rgba(148,163,184,.14);border-radius:8px;background:rgba(148,163,184,.06);color:#c5d1de;padding:7px 9px;font-size:10px;cursor:pointer}.release-actions .danger{color:#fda4af;border-color:rgba(251,113,133,.18)}.release-warning{padding:14px;border:1px solid rgba(251,191,36,.22);border-radius:14px;background:rgba(251,191,36,.06);color:#d7c27a;font-size:12px}.release-drop{border:1px dashed rgba(96,165,250,.35);border-radius:12px;padding:13px;background:rgba(59,130,246,.04)}.release-drop input{max-width:100%;color:#cbd5e1}.target-box{display:grid;gap:8px;border:1px solid rgba(148,163,184,.1);border-radius:12px;padding:10px;background:rgba(2,6,23,.18)}.target-search{width:100%;box-sizing:border-box;border:1px solid rgba(148,163,184,.14);border-radius:9px;background:#0b111b;color:#e8edf4;padding:9px}.target-list{max-height:260px;overflow-y:auto;overflow-x:hidden;display:grid;gap:6px;padding-inline-end:3px}.target-row{display:grid;grid-template-columns:auto minmax(0,1fr);gap:8px;align-items:start;padding:8px;border-radius:9px;background:rgba(148,163,184,.045);color:#cbd5e1;font-size:10px}.target-row span{overflow-wrap:anywhere}.target-row em{display:block;color:#718399;font-style:normal;margin-top:2px}.upload-progress{display:none;gap:7px}.upload-progress.show{display:grid}.progress-track{height:8px;border-radius:999px;background:rgba(148,163,184,.1);overflow:hidden}.progress-bar{height:100%;width:0;background:#60a5fa;transition:width .12s linear}.release-meta{display:flex;gap:12px;flex-wrap:wrap;color:#7f91a7;font-size:10px}.storage-info{font-size:10px;color:#718399;overflow-wrap:anywhere}@media(max-width:980px){.release-grid{grid-template-columns:1fr}}
</style>
<div class="releases-page">
  <section class="page-hero">
    <div><p class="eyebrow">Desktop delivery V2</p><h1>Release Management</h1><p class="page-subtitle">Upload Hercule Update Bundles, target everyone or selected licenses, pause rollouts, and deliver secured resumable downloads.</p></div>
    <?php if ($latest): ?><span class="badge badge-ok">Latest public: <?= htmlspecialchars((string)$latest['version']) ?></span><?php endif; ?>
  </section>

  <?php if (!$schemaReady): ?>
    <div class="release-warning"><strong>Release Management V2 needs initialization.</strong><br>The setup is additive and keeps existing release records.</div>
    <form method="post"><?= Csrf::field() ?><button class="primary-btn" name="action" value="setup_v2">Initialize Release Management V2</button></form>
  <?php else: ?>
  <section class="release-grid">
    <div class="release-panel">
      <h2>Upload update bundle</h2>
      <form method="post" enctype="multipart/form-data" class="release-form" id="release-upload-form">
        <?= Csrf::field() ?><input type="hidden" name="action" value="upload_bundle">
        <div class="release-drop"><label><span>Bundle generated by <code>npm run build</code></span><input type="file" name="bundle" accept=".zip,application/zip" required></label><small>Maximum configured bundle size: <?= $maxUploadMb ?> MB</small></div>
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
        <div class="upload-progress" id="upload-progress"><div class="progress-track"><div class="progress-bar" id="progress-bar"></div></div><small id="progress-text">Preparing upload…</small></div>
        <button class="primary-btn" type="submit" id="upload-btn">Upload and verify bundle</button>
      </form>
      <p class="storage-info">Storage: <?= htmlspecialchars(ReleaseStorage::baseDir()) ?></p>
    </div>

    <div class="release-panel">
      <h2>Release history</h2>
      <div class="release-list">
      <?php if (!$releases): ?><div class="empty-state compact"><div><strong>No releases yet</strong><p>Build an Update Bundle and upload it here.</p></div></div>
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
          <div class="release-meta"><span><?= !empty($r['installer_size']) ? number_format(((int)$r['installer_size'])/1024/1024,1).' MB' : 'Legacy link' ?></span><span>Installed events: <?= (int)($r['installed_count'] ?? 0) ?></span><span>Failed: <?= (int)($r['failed_count'] ?? 0) ?></span><?php if (!empty($r['minimum_supported_version'])): ?><span>Min <?= htmlspecialchars((string)$r['minimum_supported_version']) ?></span><?php endif; ?></div>
          <div class="release-actions">
            <form method="post"><?= Csrf::field() ?><input type="hidden" name="release_id" value="<?= (int)$r['id'] ?>"><button name="action" value="<?= !empty($r['is_published']) ? 'unpublish' : 'publish' ?>"><?= !empty($r['is_published']) ? 'Unpublish' : 'Publish' ?></button></form>
            <?php if (!empty($r['is_published'])): ?><form method="post"><?= Csrf::field() ?><input type="hidden" name="release_id" value="<?= (int)$r['id'] ?>"><button name="action" value="<?= !empty($r['is_paused']) ? 'resume' : 'pause' ?>"><?= !empty($r['is_paused']) ? 'Resume' : 'Pause rollout' ?></button></form><?php endif; ?>
            <form method="post"><?= Csrf::field() ?><input type="hidden" name="release_id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="value" value="<?= !empty($r['is_mandatory']) ? '0' : '1' ?>"><button name="action" value="mandatory"><?= !empty($r['is_mandatory']) ? 'Make optional' : 'Force update' ?></button></form>
            <?php if (($r['target_mode'] ?? 'all') !== 'all'): ?><form method="post" onsubmit="return confirm('Release this version to all eligible users?');"><?= Csrf::field() ?><input type="hidden" name="release_id" value="<?= (int)$r['id'] ?>"><button name="action" value="release_all">Release to everyone</button></form><?php endif; ?>
            <form method="post" onsubmit="return confirm('Delete this release and its stored update files?');"><?= Csrf::field() ?><input type="hidden" name="release_id" value="<?= (int)$r['id'] ?>"><button class="danger" name="action" value="delete">Delete</button></form>
          </div>
        </article>
      <?php endforeach; endif; ?>
      </div>
    </div>
  </section>
  <?php endif; ?>
</div>
<script>
(()=>{
 const mode=document.getElementById('target-mode'),box=document.getElementById('target-box'),search=document.getElementById('target-search');
 const sync=()=>{if(box) box.hidden=!mode||mode.value!=='licenses'}; if(mode){mode.addEventListener('change',sync);sync()}
 if(search){search.addEventListener('input',()=>{const q=search.value.trim().toLowerCase();document.querySelectorAll('#target-list .target-row').forEach(r=>{r.hidden=q&&!r.dataset.search.includes(q)})})}
 const form=document.getElementById('release-upload-form'); if(!form) return;
 form.addEventListener('submit',e=>{
   if(!window.XMLHttpRequest||!form.reportValidity()) return;
   e.preventDefault();
   const progress=document.getElementById('upload-progress'),bar=document.getElementById('progress-bar'),text=document.getElementById('progress-text'),btn=document.getElementById('upload-btn');
   progress.classList.add('show');btn.disabled=true;text.textContent='Uploading…';
   const xhr=new XMLHttpRequest();xhr.open('POST',form.action+(form.action.includes('?')?'&':'?')+'ajax=1');xhr.setRequestHeader('X-Requested-With','XMLHttpRequest');
   xhr.upload.onprogress=ev=>{if(ev.lengthComputable){const pct=Math.round(ev.loaded/ev.total*100);bar.style.width=pct+'%';text.textContent=`Uploading ${pct}% · ${(ev.loaded/1048576).toFixed(1)} / ${(ev.total/1048576).toFixed(1)} MB`}};
   xhr.onload=()=>{let data={};try{data=JSON.parse(xhr.responseText)}catch(_){data={ok:false,message:'Server returned an invalid upload response.'}};if(xhr.status>=200&&xhr.status<300&&data.ok){bar.style.width='100%';text.textContent=data.message||'Upload complete';setTimeout(()=>location.reload(),500)}else{btn.disabled=false;text.textContent=data.message||'Upload failed';alert(data.message||'Upload failed')}};
   xhr.onerror=()=>{btn.disabled=false;text.textContent='Network error during upload';alert('Network error during update bundle upload. You can retry without rebuilding the bundle.')};
   xhr.send(new FormData(form));
 });
})();
</script>
<?php render_footer(); ?>
