<?php
/**
 * Page Messages côté mairie
 * URL : /mairie-messages (et /mairie-messages?channel={id})
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes-layout.php';
require_once __DIR__ . '/includes-permissions.php';
require_login();
require_parent_org_user();

$current = current_user();
$parent_org = current_parent_org();
$parent_org_id = (int)$parent_org['id'];
$user_id = (int)$current['id'];

// CSRF
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$csrf = $_SESSION['csrf_token'];

// === Récupérer la liste des channels mairie ===
$stmt = $pdo->prepare("
    SELECT
        c.id, c.org_id, c.name AS channel_name, c.description,
        o.name AS org_name, o.logo_path, o.billing_address_city,
        (SELECT COUNT(*) FROM channel_messages WHERE channel_id = c.id AND deleted_at IS NULL) AS nb_messages,
        (SELECT MAX(created_at) FROM channel_messages WHERE channel_id = c.id AND deleted_at IS NULL) AS last_message_at,
        (SELECT cm.content FROM channel_messages cm WHERE cm.channel_id = c.id AND cm.deleted_at IS NULL ORDER BY cm.created_at DESC LIMIT 1) AS last_message_content,
        (SELECT u.first_name FROM channel_messages cm INNER JOIN users u ON u.id = cm.user_id WHERE cm.channel_id = c.id AND cm.deleted_at IS NULL ORDER BY cm.created_at DESC LIMIT 1) AS last_message_author
    FROM channels c
    INNER JOIN organizations o ON o.id = c.org_id
    WHERE c.parent_org_id = ?
      AND c.is_mairie_channel = 1
      AND c.is_archived = 0
    ORDER BY (SELECT MAX(created_at) FROM channel_messages WHERE channel_id = c.id AND deleted_at IS NULL) DESC, o.name ASC
");
$stmt->execute([$parent_org_id]);
$channels = $stmt->fetchAll(PDO::FETCH_ASSOC);

// === Channel sélectionné ===
$selected_channel_id = (int)($_GET['channel'] ?? 0);
$selected = null;
$messages = [];

if ($selected_channel_id > 0) {
    foreach ($channels as $c) {
        if ((int)$c['id'] === $selected_channel_id) { $selected = $c; break; }
    }
    if ($selected) {
        // Charger les messages
        $stmt = $pdo->prepare("
            SELECT cm.id, cm.user_id, cm.content, cm.created_at, cm.is_edited,
                   u.first_name, u.last_name, u.parent_org_id AS author_parent_org_id, u.role AS author_role
            FROM channel_messages cm
            INNER JOIN users u ON u.id = cm.user_id
            WHERE cm.channel_id = ?
              AND cm.deleted_at IS NULL
            ORDER BY cm.created_at ASC
        ");
        $stmt->execute([$selected_channel_id]);
        $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

// === Helpers ===
function format_time_ago($datetime): string {
    if (!$datetime) return '';
    $ts = strtotime($datetime);
    $diff = time() - $ts;
    if ($diff < 60) return 'à l\'instant';
    if ($diff < 3600) return floor($diff/60) . ' min';
    if ($diff < 86400) return floor($diff/3600) . ' h';
    if ($diff < 604800) return floor($diff/86400) . ' j';
    return date('d/m/Y', $ts);
}

render_head('Messages · Mairie');
render_sidebar('messages');
?>

<style>
.msg-page { display: flex; gap: 16px; height: calc(100vh - 80px); padding: 16px; box-sizing: border-box; }
.msg-list { width: 340px; background: #fff; border-radius: 14px; box-shadow: 0 1px 3px rgba(0,0,0,.04); overflow-y: auto; flex-shrink: 0; border: 1px solid #e9ecef; }
.msg-list-header { padding: 18px 20px; border-bottom: 1px solid #eef0f3; }
.msg-list-header h1 { font-size: 1.15rem; margin: 0 0 4px; color: #1a1a1a; }
.msg-list-header p { font-size: .82rem; color: #6a6f76; margin: 0; }
.msg-thread-card { display: block; padding: 14px 20px; border-bottom: 1px solid #f3f4f6; color: inherit; text-decoration: none; transition: background .15s; cursor: pointer; }
.msg-thread-card:hover { background: #f9fafb; }
.msg-thread-card.active { background: #fef3c7; border-left: 3px solid #f59e0b; padding-left: 17px; }
.msg-thread-org { font-weight: 600; font-size: .92rem; color: #1a1a1a; margin: 0 0 4px; display: flex; justify-content: space-between; align-items: baseline; gap: 8px; }
.msg-thread-time { font-size: .72rem; color: #6b7280; font-weight: 400; flex-shrink: 0; }
.msg-thread-preview { font-size: .82rem; color: #6b7280; margin: 0; overflow: hidden; text-overflow: ellipsis; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; }
.msg-thread-empty { padding: 40px 20px; text-align: center; color: #6b7280; font-size: .9rem; }
.msg-thread-empty .icon { font-size: 2.5rem; display: block; margin-bottom: 12px; opacity: .4; }

.msg-conv { flex: 1; background: #fff; border-radius: 14px; box-shadow: 0 1px 3px rgba(0,0,0,.04); display: flex; flex-direction: column; overflow: hidden; border: 1px solid #e9ecef; }
.msg-conv-header { padding: 16px 24px; border-bottom: 1px solid #eef0f3; background: linear-gradient(180deg, #fefefe 0%, #fafbfc 100%); }
.msg-conv-header h2 { font-size: 1.05rem; margin: 0 0 2px; color: #1a1a1a; }
.msg-conv-header p { font-size: .82rem; color: #6a6f76; margin: 0; }
.msg-conv-body { flex: 1; overflow-y: auto; padding: 20px 24px; display: flex; flex-direction: column; gap: 14px; background: #f9fafb; }
.msg-conv-footer { padding: 16px 20px; border-top: 1px solid #eef0f3; background: #fff; }

.msg-bubble { max-width: 75%; padding: 12px 16px; border-radius: 14px; word-wrap: break-word; }
.msg-bubble.self { align-self: flex-end; background: linear-gradient(135deg, #1a1a1a 0%, #2d2d2d 100%); color: #fff; border-bottom-right-radius: 4px; }
.msg-bubble.other { align-self: flex-start; background: #fff; color: #1a1a1a; border: 1px solid #e5e7eb; border-bottom-left-radius: 4px; }
.msg-bubble-meta { font-size: .72rem; opacity: .65; margin-bottom: 4px; display: flex; gap: 8px; align-items: baseline; }
.msg-bubble.self .msg-bubble-meta { color: rgba(255,255,255,.85); }
.msg-bubble-author { font-weight: 600; }
.msg-bubble-role { padding: 1px 7px; border-radius: 8px; font-size: .65rem; font-weight: 700; text-transform: uppercase; letter-spacing: .3px; }
.msg-bubble-role.mairie { background: #fbbf24; color: #78350f; }
.msg-bubble-role.asso { background: #34d399; color: #064e3b; }
.msg-bubble.self .msg-bubble-role.mairie { background: #fcd34d; color: #78350f; }
.msg-bubble-content { font-size: .92rem; line-height: 1.5; white-space: pre-wrap; }
.msg-bubble-time { font-size: .68rem; opacity: .55; margin-top: 4px; }

.msg-empty { display: flex; flex-direction: column; align-items: center; justify-content: center; flex: 1; color: #6b7280; padding: 60px 20px; text-align: center; }
.msg-empty .icon { font-size: 3.5rem; opacity: .4; margin-bottom: 16px; }
.msg-empty h3 { color: #4b5563; margin: 0 0 6px; font-size: 1.05rem; }
.msg-empty p { margin: 0; font-size: .9rem; }

.msg-composer { display: flex; gap: 10px; align-items: flex-end; }
.msg-composer textarea { flex: 1; padding: 10px 14px; border: 1px solid #d1d5db; border-radius: 10px; font-size: .92rem; font-family: inherit; resize: none; max-height: 120px; outline: none; transition: border-color .15s; }
.msg-composer textarea:focus { border-color: #f59e0b; box-shadow: 0 0 0 3px rgba(245, 158, 11, .12); }
.msg-composer button { padding: 10px 20px; background: linear-gradient(135deg, #1a1a1a 0%, #2d2d2d 100%); color: #fff; border: 0; border-radius: 10px; font-weight: 600; cursor: pointer; transition: transform .1s; font-size: .9rem; }
.msg-composer button:hover { transform: translateY(-1px); }
.msg-composer button:active { transform: translateY(0); }

@media (max-width: 900px) {
  .msg-page { flex-direction: column; height: auto; }
  .msg-list { width: 100%; max-height: 320px; }
  .msg-conv { min-height: 60vh; }
}
</style>

<div class="msg-page">

  <!-- LISTE DES CHANNELS -->
  <aside class="msg-list">
    <div class="msg-list-header">
      <h1>💬 Conversations</h1>
      <p><?= count($channels) ?> association<?= count($channels) > 1 ? 's' : '' ?> suivie<?= count($channels) > 1 ? 's' : '' ?></p>
    </div>

    <?php if (!$channels): ?>
      <div class="msg-thread-empty">
        <span class="icon">📭</span>
        Aucune association liée à votre mairie pour le moment.
      </div>
    <?php else: foreach ($channels as $c): ?>
      <a href="/mairie-messages?channel=<?= (int)$c['id'] ?>" class="msg-thread-card <?= ((int)$c['id'] === $selected_channel_id) ? 'active' : '' ?>">
        <div class="msg-thread-org">
          <span>🏢 <?= h($c['org_name']) ?></span>
          <?php if ($c['last_message_at']): ?>
            <span class="msg-thread-time"><?= h(format_time_ago($c['last_message_at'])) ?></span>
          <?php endif; ?>
        </div>
        <?php if ($c['last_message_content']): ?>
          <p class="msg-thread-preview"><strong><?= h($c['last_message_author']) ?>:</strong> <?= h($c['last_message_content']) ?></p>
        <?php else: ?>
          <p class="msg-thread-preview" style="color:#6b7280; font-style:italic;">Aucun message — démarrez la conversation</p>
        <?php endif; ?>
      </a>
    <?php endforeach; endif; ?>
  </aside>

  <!-- CONVERSATION -->
  <section class="msg-conv">
    <?php if (!$selected): ?>
      <div class="msg-empty">
        <span class="icon">💭</span>
        <h3>Sélectionnez une conversation</h3>
        <p>Choisissez une association dans la liste pour commencer à échanger.</p>
      </div>
    <?php else: ?>
      <header class="msg-conv-header">
        <h2>🏛️ Suivi · <?= h($selected['org_name']) ?></h2>
        <p>Conversation entre votre mairie et l'association — <?= count($messages) ?> message<?= count($messages) > 1 ? 's' : '' ?></p>
      </header>

      <div class="msg-conv-body" id="msg-body">
        <?php if (!$messages): ?>
          <div style="text-align:center; color:#6b7280; padding:40px 0;">
            <div style="font-size:2.5rem; opacity:.4; margin-bottom:12px;">✍️</div>
            <p style="margin:0; font-size:.92rem;">Aucun message pour le moment.<br>Envoyez le premier !</p>
          </div>
        <?php else: foreach ($messages as $m):
          $is_self = ((int)$m['user_id'] === $user_id);
          $is_mairie = !empty($m['author_parent_org_id']);
          $role_label = $is_mairie ? 'Mairie' : 'Asso';
          $role_class = $is_mairie ? 'mairie' : 'asso';
        ?>
          <div class="msg-bubble <?= $is_self ? 'self' : 'other' ?>">
            <div class="msg-bubble-meta">
              <span class="msg-bubble-author"><?= h($m['first_name'] . ' ' . $m['last_name']) ?></span>
              <span class="msg-bubble-role <?= $role_class ?>"><?= $role_label ?></span>
            </div>
            <div class="msg-bubble-content"><?= nl2br(h($m['content'])) ?></div>
            <div class="msg-bubble-time"><?= h(date('d/m/Y H:i', strtotime($m['created_at']))) ?><?= $m['is_edited'] ? ' · modifié' : '' ?></div>
          </div>
        <?php endforeach; endif; ?>
      </div>

      <footer class="msg-conv-footer">
        <form method="POST" action="/action-mairie-message" class="msg-composer">
          <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
          <input type="hidden" name="channel_id" value="<?= (int)$selected['id'] ?>">
          <textarea name="content" rows="2" placeholder="Écrire un message à <?= h($selected['org_name']) ?>..." required maxlength="5000"></textarea>
          <button type="submit">Envoyer</button>
        </form>
      </footer>
    <?php endif; ?>
  </section>

</div>

<script>
(function() {
  const body = document.getElementById('msg-body');
  const textarea = document.querySelector('.msg-composer textarea');
  if (body) body.scrollTop = body.scrollHeight;

  // Submit sur Enter (Shift+Enter = saut de ligne)
  textarea?.addEventListener('keydown', e => {
    if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); e.target.form.submit(); }
  });

  <?php if ($selected): ?>
  // === POLLING TEMPS RÉEL ===
  const channelId = <?= (int)$selected['id'] ?>;
  const selfUserId = <?= (int)$user_id ?>;
  let lastId = <?= !empty($messages) ? (int)end($messages)['id'] : 0 ?>;
  let pollTimer = null;
  const POLL_INTERVAL_ACTIVE = 4000;
  const POLL_INTERVAL_IDLE = 30000;

  function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  }

  function nl2br(s) { return escapeHtml(s).replace(/\n/g, '<br>'); }

  function isScrolledToBottom() {
    if (!body) return true;
    return (body.scrollHeight - body.scrollTop - body.clientHeight) < 80;
  }

  function appendMessage(m) {
    if (document.querySelector('[data-msg-id="' + m.id + '"]')) return;
    const isSelf = m.is_self;
    const div = document.createElement('div');
    div.className = 'msg-bubble ' + (isSelf ? 'self' : 'other');
    div.dataset.msgId = m.id;
    div.innerHTML =
      '<div class="msg-bubble-meta">' +
        '<span class="msg-bubble-author">' + escapeHtml(m.author_name) + '</span>' +
        '<span class="msg-bubble-role ' + m.role_class + '">' + m.role_label + '</span>' +
      '</div>' +
      '<div class="msg-bubble-content">' + nl2br(m.content) + '</div>' +
      '<div class="msg-bubble-time">' + escapeHtml(m.created_at_display) + (m.is_edited ? ' · modifié' : '') + '</div>';
    body.appendChild(div);
  }

  function removeEmptyState() {
    const empty = body.querySelector('div[style*="text-align:center"]');
    if (empty) empty.remove();
  }

  async function poll() {
    try {
      const wasAtBottom = isScrolledToBottom();
      const res = await fetch('/api-channel-messages?channel_id=' + channelId + '&since=' + lastId, {
        credentials: 'same-origin',
        cache: 'no-cache',
      });
      if (!res.ok) return;
      const data = await res.json();
      if (!data.ok || !data.messages || data.messages.length === 0) return;

      removeEmptyState();
      data.messages.forEach(appendMessage);
      lastId = data.last_id;

      // Update compteur dans le header
      const total = body.querySelectorAll('.msg-bubble').length;
      const headerP = document.querySelector('.msg-conv-header p');
      if (headerP) {
        const newCount = total;
        headerP.textContent = "Conversation entre votre mairie et l'association — " + newCount + " message" + (newCount > 1 ? 's' : '');
      }

      if (wasAtBottom) body.scrollTop = body.scrollHeight;
    } catch (e) {
      console.warn('[polling]', e);
    }
  }

  function startPolling(interval) {
    if (pollTimer) clearInterval(pollTimer);
    pollTimer = setInterval(poll, interval);
  }

  // Polling actif + adapté au focus
  startPolling(POLL_INTERVAL_ACTIVE);
  document.addEventListener('visibilitychange', () => {
    if (document.hidden) startPolling(POLL_INTERVAL_IDLE);
    else { poll(); startPolling(POLL_INTERVAL_ACTIVE); }
  });

  // Premier poll après 1s pour rattraper rapidement les messages très récents
  setTimeout(poll, 1000);
  <?php endif; ?>
})();
</script>

<?php render_foot(); ?>
