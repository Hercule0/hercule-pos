<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/../../includes/SessionManager.php';

Auth::require();
$currentAdminId = Auth::currentUserId() ?? 0;
$isOwner = Auth::currentRole() === 'owner';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::guard();
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'revoke_one') {
            $sessionId = max(0, (int) ($_POST['session_id'] ?? 0));
            $removed = SessionManager::revokeOne($sessionId, $currentAdminId, $isOwner);
            flash_set($removed > 0 ? 'Remembered session revoked.' : 'Session was already gone or is outside your scope.', $removed > 0 ? 'success' : 'error');
        } elseif ($action === 'revoke_all_own') {
            $removed = SessionManager::revokeOwn($currentAdminId);
            flash_set($removed > 0 ? "Revoked {$removed} remembered session(s) for your account." : 'No remembered sessions were found for your account.');
        } elseif ($action === 'revoke_all' && $isOwner) {
            $removed = SessionManager::revokeAll($currentAdminId);
            flash_set($removed > 0 ? "Revoked {$removed} remembered administrator session(s)." : 'No remembered administrator sessions were found.');
        } else {
            throw new RuntimeException('Unsupported session action.');
        }
    } catch (Throwable $e) {
        flash_set($e->getMessage(), 'error');
    }
    header('Location: /public/admin/sessions.php');
    exit;
}

$sessions = SessionManager::visibleFor($currentAdminId, $isOwner);
$activeCount = count(array_filter($sessions, static fn($s) => strtotime($s['expires_at']) >= time()));

render_header('Sessions');
flash_render();
?>
<style>
.sessions-page{display:grid;gap:16px}.sessions-summary{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px}.sessions-summary article{padding:14px;border:1px solid rgba(148,163,184,.1);border-radius:14px;background:rgba(16,24,36,.6)}.sessions-summary span{display:block;color:#75879e;font-size:10px;text-transform:uppercase;letter-spacing:.07em}.sessions-summary strong{display:block;margin-top:6px;color:#f7f9fc;font-size:20px}.session-list{display:grid;gap:10px}.session-card{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:12px;align-items:center;padding:14px;border:1px solid rgba(148,163,184,.1);border-radius:14px;background:rgba(16,24,36,.68)}.session-main{min-width:0;display:grid;gap:5px}.session-main strong{color:#f7f9fc;font-size:13px}.session-main small{color:#7c8ea5;font-size:10px}.session-main code{display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:#aab7c7;font-size:10px}.session-actions form{margin:0}.session-actions button{border:1px solid rgba(251,113,133,.2);border-radius:8px;background:rgba(251,113,133,.07);color:#fda4af;padding:7px 10px;font-size:10px;cursor:pointer}.session-expired{opacity:.55}.session-toolbar{display:flex;gap:8px;flex-wrap:wrap}.session-toolbar form{margin:0}@media(max-width:760px){.sessions-summary{grid-template-columns:1fr}.session-card{grid-template-columns:1fr}.session-actions button{width:100%}}
</style>

<div class="sessions-page">
    <section class="page-hero">
        <div>
            <p class="eyebrow">Security</p>
            <h1>Remembered Sessions</h1>
            <p class="page-subtitle">Review and revoke persistent administrator sign-ins created by “Remember me”. Revoking a record forces that remembered browser to sign in again.</p>
        </div>
    </section>

    <section class="sessions-summary">
        <article><span>Visible sessions</span><strong><?= count($sessions) ?></strong></article>
        <article><span>Not expired</span><strong><?= $activeCount ?></strong></article>
        <article><span>Scope</span><strong style="font-size:14px"><?= $isOwner ? 'All admins' : 'My account' ?></strong></article>
    </section>

    <section class="session-toolbar">
        <form method="post" onsubmit="return confirm('Revoke every remembered session for your account?');">
            <?= Csrf::field() ?>
            <button class="secondary-btn" type="submit" name="action" value="revoke_all_own">Revoke my remembered sessions</button>
        </form>
        <?php if ($isOwner): ?>
            <form method="post" onsubmit="return confirm('Revoke ALL remembered administrator sessions?');">
                <?= Csrf::field() ?>
                <button class="secondary-btn" type="submit" name="action" value="revoke_all">Revoke all admins</button>
            </form>
        <?php endif; ?>
    </section>

    <section class="detail-section">
        <div class="section-heading"><div><p class="eyebrow">Persistent login tokens</p><h2>Sessions</h2></div><span class="section-count"><?= count($sessions) ?></span></div>
        <div class="session-list">
            <?php if (!$sessions): ?>
                <div class="empty-state compact"><div><strong>No remembered sessions</strong><p>Persistent sessions will appear after an administrator signs in with Remember me enabled.</p></div></div>
            <?php else: foreach ($sessions as $s): $expired = strtotime($s['expires_at']) < time(); ?>
                <article class="session-card <?= $expired ? 'session-expired' : '' ?>">
                    <div class="session-main">
                        <strong><?= htmlspecialchars($s['username']) ?> · <?= htmlspecialchars(str_replace('_',' ', $s['role'])) ?></strong>
                        <code><?= htmlspecialchars($s['user_agent']) ?></code>
                        <small>IP <?= htmlspecialchars($s['ip_address']) ?> · Created <?= htmlspecialchars(date('M j, Y · H:i', strtotime($s['created_at']))) ?> · Expires <?= htmlspecialchars(date('M j, Y · H:i', strtotime($s['expires_at']))) ?><?= $expired ? ' · Expired' : '' ?></small>
                    </div>
                    <div class="session-actions">
                        <form method="post" onsubmit="return confirm('Revoke this remembered session?');">
                            <?= Csrf::field() ?>
                            <input type="hidden" name="session_id" value="<?= (int)$s['id'] ?>">
                            <button type="submit" name="action" value="revoke_one">Revoke</button>
                        </form>
                    </div>
                </article>
            <?php endforeach; endif; ?>
        </div>
    </section>
</div>
<?php render_footer(); ?>
