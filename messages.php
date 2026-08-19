<?php
/**
 * ============================================================
 * ASSOKIT — Messages (canaux type Slack)
 * ============================================================
 * Page principale de messagerie :
 *   - Sidebar gauche : liste des canaux accessibles
 *   - Zone principale : messages du canal sélectionné
 *   - Actions admin : créer/gérer canaux
 *   - Bouton IA : résumer/compte-rendu du canal
 * ============================================================
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes-layout.php';

require_login();
$current = current_user();
$org_id = (int)$current['org_id'];
$user_id = (int)$current['id'];
$is_admin = ($current['role'] === 'admin');
$is_coord = ($current['role'] === 'coordinator');
$is_admin_or_coord = $is_admin || $is_coord;

// ==========================================================
// Charger tous les canaux accessibles à l'utilisateur
// ==========================================================
// - Canaux publics / annonces : visibles par tous les membres de l'org
// - Canaux privés : visibles uniquement si l'utilisateur est dans channel_members
$stmt = $pdo->prepare("
    SELECT c.*,
           (SELECT COUNT(*) FROM channel_messages cm WHERE cm.channel_id = c.id AND cm.deleted_at IS NULL) AS msg_count,
           (SELECT MAX(id) FROM channel_messages WHERE channel_id = c.id AND deleted_at IS NULL) AS last_msg_id,
           (SELECT last_read_message_id FROM channel_reads WHERE channel_id = c.id AND user_id = ?) AS last_read_id
    FROM channels c
    WHERE c.org_id = ?
      AND c.is_archived = 0
      AND (
        c.type IN ('public', 'announce')
        OR EXISTS (SELECT 1 FROM channel_members cm WHERE cm.channel_id = c.id AND cm.user_id = ?)
      )
    ORDER BY c.position ASC, c.name ASC
");
$stmt->execute([$user_id, $org_id, $user_id]);
$channels = $stmt->fetchAll();

// ==========================================================
// Déterminer le canal actif
// ==========================================================
$active_slug = $_GET['c'] ?? null;
$active_channel = null;
if ($active_slug) {
    foreach ($channels as $c) {
        if ($c['slug'] === $active_slug) { $active_channel = $c; break; }
    }
}
if (!$active_channel && !empty($channels)) {
    $active_channel = $channels[0];
    $active_slug = $active_channel['slug'];
}

// ==========================================================
// Marquer comme lu + charger les messages du canal actif
// ==========================================================
$messages = [];
if ($active_channel) {
    // Charger les 100 derniers messages
    $stmt = $pdo->prepare("
        SELECT cm.*, u.first_name, u.last_name, u.role, u.avatar_color,
               rcm.content AS reply_to_content,
               rcm.deleted_at AS reply_to_deleted,
               ru.first_name AS reply_to_first_name,
               ru.last_name AS reply_to_last_name
        FROM channel_messages cm
        JOIN users u ON cm.user_id = u.id
        LEFT JOIN channel_messages rcm ON cm.reply_to_message_id = rcm.id
        LEFT JOIN users ru ON rcm.user_id = ru.id
        WHERE cm.channel_id = ?
          AND cm.deleted_at IS NULL
        ORDER BY cm.created_at ASC
        LIMIT 100
    ");
    $stmt->execute([$active_channel['id']]);
    $messages = $stmt->fetchAll();

    // Marquer le canal comme lu
    if (!empty($messages)) {
        $last_id = end($messages)['id'];
        $pdo->prepare("
            INSERT INTO channel_reads (channel_id, user_id, last_read_message_id, last_read_at)
            VALUES (?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE last_read_message_id = VALUES(last_read_message_id), last_read_at = NOW()
        ")->execute([$active_channel['id'], $user_id, $last_id]);
    }
}

// ==========================================================
// [NEW] Charger les membres mentionnables (pour autocomplete @)
// ==========================================================
$mention_targets = [];
if ($active_channel) {
    try {
        if ($active_channel['type'] === 'private') {
            // Canal privé : seulement les membres du canal + admins
            $stmt = $pdo->prepare("
                SELECT DISTINCT u.id, u.first_name, u.last_name, u.role, u.avatar_color
                FROM users u
                WHERE u.is_active = 1 AND u.org_id = :org_id
                  AND (u.deleted_at IS NULL OR u.deleted_at = '')
                  AND (
                      u.id IN (SELECT user_id FROM channel_members WHERE channel_id = :cid)
                      OR u.role = 'admin'
                  )
                ORDER BY u.first_name ASC
            ");
            $stmt->execute([':org_id' => $org_id, ':cid' => $active_channel['id']]);
        } else {
            // Canal public/annonces : tous les membres actifs de l'org
            $stmt = $pdo->prepare("
                SELECT id, first_name, last_name, role, avatar_color
                FROM users
                WHERE is_active = 1 AND org_id = ?
                  AND (deleted_at IS NULL OR deleted_at = '')
                ORDER BY first_name ASC
            ");
            $stmt->execute([$org_id]);
        }
        $mention_targets = $stmt->fetchAll();
    } catch (Throwable $e) {
        $mention_targets = [];
    }
}

// ==========================================================
// Déterminer si l'utilisateur peut écrire dans ce canal
// ==========================================================
$can_write = false;
if ($active_channel) {
    if ($active_channel['type'] === 'announce') {
        // Annonces : admin et coordinateur uniquement
        $can_write = $is_admin_or_coord;
    } elseif ($active_channel['type'] === 'private') {
        // Privé : membre du canal ou admin
        if ($is_admin) {
            $can_write = true;
        } else {
            $check = $pdo->prepare("SELECT id FROM channel_members WHERE channel_id = ? AND user_id = ?");
            $check->execute([$active_channel['id'], $user_id]);
            $can_write = (bool)$check->fetch();
        }
    } else {
        // Public : tout le monde peut écrire
        $can_write = true;
    }
}

// ==========================================================
// Helpers
// ==========================================================
function channel_type_icon($type) {
    if ($type === 'private') return '<svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>';
    if ($type === 'announce') return '<svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 11l18-5v12L3 14v-3z"/><path d="M11.6 16.8a3 3 0 1 1-5.8-1.6"/></svg>';
    return '<span style="font-size: 13px;">#</span>';
}

function user_avatar_color($color) {
    $colors = [
        'blue' => '#4F80BD',
        'purple' => '#7F77DD',
        'amber' => '#EF9F27',
        'pink' => '#D77CA0',
        'teal' => '#2AAE89',
        'green' => '#059669',
        'red' => '#B91C1C',
        'gray' => '#78716C',
    ];
    return $colors[$color] ?? '#78716C';
}

function format_msg_time($datetime) {
    $ts = strtotime($datetime);
    $today = strtotime(date('Y-m-d'));
    $yesterday = strtotime('-1 day', $today);

    if ($ts >= $today) return date('H:i', $ts);
    if ($ts >= $yesterday) return 'Hier ' . date('H:i', $ts);
    return date('d/m/Y H:i', $ts);
}

function format_day_separator($datetime) {
    $ts = strtotime($datetime);
    $today = strtotime(date('Y-m-d'));
    $yesterday = strtotime('-1 day', $today);
    $ts_day = strtotime(date('Y-m-d', $ts));

    if ($ts_day === $today) return "Aujourd'hui";
    if ($ts_day === $yesterday) return "Hier";

    $days = ['Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi', 'Dimanche'];
    $months = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
    return $days[(int)date('N', $ts) - 1] . ' ' . (int)date('j', $ts) . ' ' . $months[(int)date('n', $ts) - 1];
}

// Formatage minimal markdown (gras et italique uniquement, le reste échappé)
function format_msg_content($content) {
    $safe = htmlspecialchars($content, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    // **gras**
    $safe = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $safe);
    // *italique*
    $safe = preg_replace('/(?<!\*)\*([^\*\s][^\*]*?)\*(?!\*)/', '<em>$1</em>', $safe);
    // @mentions surlignées (NEW)
    $safe = preg_replace_callback(
        '/(@[a-zA-ZÀ-ÿ][a-zA-ZÀ-ÿ\.\-]+)/u',
        function($m) {
            return '<span style="background:var(--ai-light,#EEF2FF); color:var(--ai-dark,#4338CA); padding:1px 6px; border-radius:5px; font-weight:600;">' . $m[1] . '</span>';
        },
        $safe
    );
    // Liens bruts cliquables
    $safe = preg_replace('#(https?://[^\s<]+)#i', '<a href="$1" target="_blank" rel="noopener" style="color:var(--acc);text-decoration:underline;">$1</a>', $safe);
    return $safe;
}

render_head('Messages' . ($active_channel ? ' · ' . $active_channel['name'] : ''));
render_sidebar('messages');
?>

<main class="main">

  <nav class="crumbs">
    <a href="/dashboard">Dashboard</a>
    <span class="sep">›</span>
    <span class="current">Messages<?php if ($active_channel): ?> <span class="sep">›</span> <?= h($active_channel['name']) ?><?php endif; ?></span>
  </nav>

  <div class="msg-layout">

    <!-- ========== SIDEBAR CANAUX ========== -->
    <aside class="msg-channels">
      <div class="msg-channels-head">
        <div class="msg-channels-title">Canaux</div>
        <?php if ($is_admin): ?>
          <a href="/nouveau-canal" class="msg-btn-new-channel" title="Créer un canal">
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
          </a>
        <?php endif; ?>
      </div>

      <div class="msg-channels-list">
        <?php if (empty($channels)): ?>
          <div style="padding: 20px; text-align: center; color: var(--ink-3); font-size: 12.5px;">
            Aucun canal pour l'instant.
            <?php if ($is_admin): ?>
              <br><a href="/nouveau-canal" style="color: var(--acc); font-weight: 500;">Créer le premier canal</a>
            <?php endif; ?>
          </div>
        <?php else: ?>
          <?php foreach ($channels as $c):
            $is_active = ($active_channel && $c['id'] == $active_channel['id']);
            $unread = 0;
            if (!empty($c['last_msg_id']) && (empty($c['last_read_id']) || $c['last_msg_id'] > $c['last_read_id'])) {
                // Compter les non-lus
                $stmt_u = $pdo->prepare("SELECT COUNT(*) FROM channel_messages WHERE channel_id = ? AND id > ? AND user_id != ? AND deleted_at IS NULL");
                $stmt_u->execute([$c['id'], (int)($c['last_read_id'] ?? 0), $user_id]);
                $unread = (int)$stmt_u->fetchColumn();
            }
          ?>
          <a href="/messages?c=<?= h($c['slug']) ?>" class="msg-channel-link <?= $is_active ? 'active' : '' ?>">
            <span class="msg-channel-icon"><?= $c['icon'] ?: '#' ?></span>
            <span class="msg-channel-name"><?= h($c['name']) ?></span>
            <?php if ($c['type'] !== 'public'): ?>
              <span class="msg-channel-type-icon" title="<?= $c['type'] === 'private' ? 'Privé' : 'Annonces' ?>">
                <?= channel_type_icon($c['type']) ?>
              </span>
            <?php endif; ?>
            <?php if ($unread > 0 && !$is_active): ?>
              <span class="msg-channel-badge"><?= $unread > 99 ? '99+' : $unread ?></span>
            <?php endif; ?>
          </a>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </aside>

    <!-- ========== ZONE CHAT ========== -->
    <section class="msg-main">

      <?php if (!$active_channel): ?>
        <div class="msg-empty">
          <div class="msg-empty-icon" style="color:#94A3B8"><?= ak_icon('message', 44, '1.5') ?></div>
          <div class="msg-empty-title">Aucun canal sélectionné</div>
          <div class="msg-empty-desc">Sélectionnez un canal dans la colonne de gauche pour voir les messages.</div>
        </div>
      <?php else: ?>

        <!-- Header du canal -->
        <header class="msg-head">
          <div class="msg-head-icon"><?= $active_channel['icon'] ?: '💬' ?></div>
          <div class="msg-head-info">
            <div class="msg-head-name">
              <?= h($active_channel['name']) ?>
              <?php if ($active_channel['type'] === 'private'): ?>
                <span style="font-size: 11px; color: var(--ink-3); font-weight: 400; padding: 2px 6px; background: var(--bg-2); border-radius: 4px;">🔒 Privé</span>
              <?php elseif ($active_channel['type'] === 'announce'): ?>
                <span style="font-size: 11px; color: var(--ink-3); font-weight: 400; padding: 2px 6px; background: var(--bg-2); border-radius: 4px;">📣 Annonces</span>
              <?php endif; ?>
            </div>
            <?php if (!empty($active_channel['description'])): ?>
              <div class="msg-head-desc"><?= h($active_channel['description']) ?></div>
            <?php endif; ?>
          </div>
          <div class="msg-head-actions">
            <button type="button" class="msg-head-btn ai" onclick="openAiModal()" title="Demander un compte-rendu IA">
              <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
              Résumer avec IA
            </button>
            <?php if ($is_admin): ?>
              <a href="/canal/<?= h($active_channel['slug']) ?>/parametres" class="msg-head-btn" title="Paramètres du canal">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
              Paramètres
              </a>
            <?php endif; ?>
          </div>
        </header>

        <!-- Liste des messages -->
        <div class="msg-list" id="msgList">
          <?php if (empty($messages)): ?>
            <div class="msg-empty">
              <div class="msg-empty-icon"><?= $active_channel['icon'] ?: '💬' ?></div>
              <div class="msg-empty-title">Bienvenue dans <?= h($active_channel['name']) ?> !</div>
              <div class="msg-empty-desc">Aucun message pour l'instant. Soyez le premier à écrire quelque chose.</div>
            </div>
          <?php else:
            $last_day = null;
            foreach ($messages as $msg):
              $msg_day = date('Y-m-d', strtotime($msg['created_at']));
              if ($msg_day !== $last_day):
                $last_day = $msg_day;
          ?>
            <div class="msg-day-sep">
              <span class="msg-day-sep-label"><?= h(format_day_separator($msg['created_at'])) ?></span>
            </div>
          <?php endif; ?>

            <div class="msg-item" data-msg-id="<?= (int)$msg['id'] ?>">
              <div class="msg-avatar" style="background: <?= user_avatar_color($msg['avatar_color'] ?? 'gray') ?>">
                <?= h(strtoupper(mb_substr($msg['first_name'], 0, 1) . mb_substr($msg['last_name'], 0, 1))) ?>
              </div>
              <div class="msg-body">
                <div class="msg-header">
                  <span class="msg-author"><?= h($msg['first_name'] . ' ' . $msg['last_name']) ?></span>
                  <?php if ($msg['role'] === 'admin'): ?>
                    <span class="msg-author-role">admin</span>
                  <?php elseif ($msg['role'] === 'coordinator'): ?>
                    <span class="msg-author-role">coord</span>
                  <?php endif; ?>
                  <span class="msg-time"><?= h(format_msg_time($msg['created_at'])) ?></span>
                  <?php if ($msg['is_edited']): ?>
                    <span class="msg-edited">(modifié)</span>
                  <?php endif; ?>
                </div>
                <?php if (!empty($msg['reply_to_message_id']) && !empty($msg['reply_to_content']) && empty($msg['reply_to_deleted'])): ?>
                  <div class="msg-quote" onclick="scrollToMsg(<?= (int)$msg['reply_to_message_id'] ?>)" title="Voir le message d'origine">
                    <div class="msg-quote-author">↩ <?= h(trim(($msg['reply_to_first_name'] ?? '') . ' ' . ($msg['reply_to_last_name'] ?? ''))) ?></div>
                    <div class="msg-quote-content"><?= h(mb_substr($msg['reply_to_content'], 0, 120)) ?><?= mb_strlen($msg['reply_to_content']) > 120 ? '…' : '' ?></div>
                  </div>
                <?php elseif (!empty($msg['reply_to_message_id'])): ?>
                  <div class="msg-quote msg-quote-deleted">↩ Message supprime</div>
                <?php endif; ?>
                <div class="msg-content"><?= format_msg_content($msg['content']) ?></div>
              </div>

              <div class="msg-actions-inline">
                <button type="button" class="msg-action-btn"
                        onclick='setReplyTo(<?= (int)$msg["id"] ?>, <?= htmlspecialchars(json_encode(trim($msg["first_name"] . " " . $msg["last_name"])), ENT_QUOTES) ?>, <?= htmlspecialchars(json_encode(mb_substr($msg["content"], 0, 120)), ENT_QUOTES) ?>)'
                        title="Repondre">
                  <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 17 4 12 9 7"/><path d="M20 18v-2a4 4 0 0 0-4-4H4"/></svg>
                </button>
                <?php if ($msg['user_id'] == $user_id || $is_admin): ?>
                  <form method="POST" action="/action-message-canal" style="margin:0;display:inline;"
                        onsubmit="return confirm('Supprimer ce message ?');">
                    <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="message_id" value="<?= (int)$msg['id'] ?>">
                    <input type="hidden" name="channel_slug" value="<?= h($active_channel['slug']) ?>">
                    <button type="submit" class="msg-action-btn danger" title="Supprimer">
                      <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/></svg>
                    </button>
                  </form>
                <?php endif; ?>
              </div>
            </div>
          <?php endforeach; endif; ?>
        </div>

        <!-- Zone d'envoi -->
        <div class="msg-compose">
          <?php if (!$can_write): ?>
            <?php if ($active_channel['type'] === 'announce'): ?>
              <div class="msg-compose-announce-notice">📣 Ce canal est en mode « Annonces ». Seuls les admins et coordinateurs peuvent y écrire.</div>
            <?php else: ?>
              <div class="msg-compose-announce-notice">🔒 Vous n'êtes pas membre de ce canal privé.</div>
            <?php endif; ?>
          <?php else: ?>
            <form method="POST" action="/action-message-canal" class="msg-compose-form" id="composeForm">
              <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
              <input type="hidden" name="action" value="send">
              <input type="hidden" name="channel_id" value="<?= (int)$active_channel['id'] ?>">
              <input type="hidden" name="channel_slug" value="<?= h($active_channel['slug']) ?>">
              
              <div id="replyPreview" class="msg-reply-preview" style="display:none;">
                <div class="msg-reply-preview-info">
                  <span class="msg-reply-preview-icon">↩</span>
                  <div style="flex:1;min-width:0;">
                    <div class="msg-reply-preview-author">Repondre a <span id="replyPreviewAuthor"></span></div>
                    <div class="msg-reply-preview-content" id="replyPreviewContent"></div>
                  </div>
                </div>
                <button type="button" onclick="clearReplyTo()" class="msg-reply-preview-close" title="Annuler">✕</button>
              </div>
              <input type="hidden" name="reply_to_message_id" id="replyToMessageId" value="">

              <textarea name="content" class="msg-compose-input" id="composeInput"
                        placeholder="Écrire dans #<?= h($active_channel['name']) ?>… Tape @ pour mentionner"
                        rows="1" required maxlength="8000" autocomplete="off"></textarea>
              <button type="submit" class="msg-compose-send" id="sendBtn">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
                Envoyer
              </button>
            </form>
            
            <!-- Dropdown autocomplete @mentions (position fixed, calculé en JS) -->
            <div id="canalMentionDropdown" style="display:none; position:fixed; background:#fff; border:1px solid #e5e7eb; border-radius:10px; box-shadow:0 -4px 24px rgba(0,0,0,0.12); max-height:280px; overflow-y:auto; z-index:9999; min-width:280px;">
              <div style="font-size:11px; color:#6B7280; padding:10px 12px 6px; font-weight:600; text-transform:uppercase; letter-spacing:0.04em; border-bottom:1px solid #f3f4f6;">
                👥 Mentionner un membre
              </div>
              <div id="canalMentionList"></div>
            </div>
            
            <!-- Données JSON pour autocomplete -->
            <script id="canalMentionDataJson" type="application/json"><?= json_encode(array_map(function($u) {
                return [
                    'id' => (int)$u['id'],
                    'first' => $u['first_name'],
                    'last' => $u['last_name'],
                    'name' => trim($u['first_name'] . ' ' . $u['last_name']),
                    'role' => $u['role'],
                    'color' => $u['avatar_color'] ?? 'blue',
                    'initials' => mb_strtoupper(mb_substr($u['first_name'], 0, 1) . mb_substr($u['last_name'], 0, 1)),
                    'tag' => mb_strtolower($u['first_name']),
                ];
            }, $mention_targets), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
            
            <div class="msg-compose-hint">
              Appuyez sur <kbd style="padding:1px 5px;background:var(--bg-2);border:1px solid var(--border);border-radius:3px;font-size:10px;">Entrée</kbd> pour envoyer · <kbd style="padding:1px 5px;background:var(--bg-2);border:1px solid var(--border);border-radius:3px;font-size:10px;">Maj+Entrée</kbd> pour une nouvelle ligne · **gras** *italique* · @prenom pour mentionner
            </div>
          <?php endif; ?>
        </div>

      <?php endif; ?>

    </section>
  </div>

</main>

<!-- Modal IA compte-rendu (sera géré en Phase 2) -->
<div class="msg-ai-modal-backdrop" id="aiModal">
  <div class="msg-ai-modal">
    <div class="msg-ai-modal-head">
      <div class="msg-ai-modal-title">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--ai)" stroke-width="2"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
        Demander un compte-rendu IA
      </div>
      <button type="button" class="msg-ai-modal-close" onclick="closeAiModal()">✕</button>
    </div>
    <div class="msg-ai-modal-body">
      <p style="font-size: 13px; color: var(--ink-2); margin-bottom: 14px;">🚧 <strong>Fonctionnalité IA à venir en Phase 2</strong> — L'IA analysera les messages récents de ce canal et produira un compte-rendu structuré (synthèse, décisions, actions à faire).</p>
      <div style="background: var(--ai-light); border: 1px solid var(--ai); border-radius: 10px; padding: 14px 16px; font-size: 12.5px; color: var(--ink-2); line-height: 1.6;">
        <strong>Aperçu de ce qui sera disponible :</strong><br>
        • Résumé des 50 derniers messages<br>
        • Compte-rendu des dernières 24h / semaine / mois<br>
        • Extraction des décisions prises<br>
        • Liste des actions à faire<br>
        • Historique des comptes-rendus conservé
      </div>
    </div>
    <div class="msg-ai-modal-foot">
      <button type="button" class="btn btn-ghost" onclick="closeAiModal()">Fermer</button>
    </div>
  </div>
</div>

<script>
// Auto-scroll vers le bas au chargement
(function() {
  var list = document.getElementById('msgList');
  if (list) list.scrollTop = list.scrollHeight;
})();

// Auto-resize du textarea
var input = document.getElementById('composeInput');
if (input) {
  input.addEventListener('input', function() {
    this.style.height = 'auto';
    this.style.height = Math.min(this.scrollHeight, 150) + 'px';
  });
  input.addEventListener('keydown', function(e) {
    if (e.key === 'Enter' && !e.shiftKey) {
      // Ne pas soumettre si le dropdown @mention est ouvert
      var dropdown = document.getElementById('canalMentionDropdown');
      if (dropdown && dropdown.style.display === 'block') {
        return; // Le handler du dropdown gère
      }
      e.preventDefault();
      var form = document.getElementById('composeForm');
      if (form && this.value.trim()) form.submit();
    }
  });
  input.focus();
}

// ============================================================
// AUTOCOMPLETE @MENTIONS DANS LES CANAUX
// ============================================================
(function() {
    var inputEl = document.getElementById('composeInput');
    var dropdown = document.getElementById('canalMentionDropdown');
    var listEl = document.getElementById('canalMentionList');
    var dataEl = document.getElementById('canalMentionDataJson');
    if (!inputEl || !dropdown || !listEl || !dataEl) return;
    
    var members = [];
    try { members = JSON.parse(dataEl.textContent || '[]'); } catch (e) { return; }
    if (!members.length) return;
    
    var COLORS = {
        'blue': '#3B82F6', 'purple': '#8B5CF6', 'amber': '#F59E0B',
        'pink': '#EC4899', 'teal': '#14B8A6', 'green': '#10B981',
        'red': '#EF4444', 'indigo': '#6366F1'
    };
    var ROLE_LABELS = {
        'admin': '🛡️ Admin', 'coordinator': '🧭 Coord',
        'referent': '🎯 Référent', 'member': '👤 Membre',
        'follower': '👀 Suiveur'
    };
    
    var currentSearch = '';
    var currentMatchStart = -1;
    var selectedIdx = 0;
    var filtered = [];
    
    function detectMentionContext() {
        var pos = inputEl.selectionStart;
        var text = inputEl.value;
        var beforeCursor = text.substring(0, pos);
        var match = beforeCursor.match(/(?:^|\s)@([a-zA-ZÀ-ÿ\.\-]*)$/u);
        if (match) {
            return { search: match[1], start: pos - match[1].length - 1 };
        }
        return null;
    }
    
    function escapeHtml(s) {
        var div = document.createElement('div');
        div.textContent = s;
        return div.innerHTML;
    }
    
    function positionDropdown() {
        // Position du dropdown : au-dessus du textarea, aligné à gauche
        var rect = inputEl.getBoundingClientRect();
        dropdown.style.left = rect.left + 'px';
        dropdown.style.bottom = (window.innerHeight - rect.top + 6) + 'px';
        dropdown.style.maxWidth = Math.min(rect.width, 360) + 'px';
        dropdown.style.minWidth = Math.min(rect.width, 280) + 'px';
    }
    
    function renderDropdown() {
        if (!filtered.length) { dropdown.style.display = 'none'; return; }
        var html = '';
        filtered.forEach(function(m, idx) {
            var color = COLORS[m.color] || '#3B82F6';
            var isActive = idx === selectedIdx;
            html += '<div class="mention-item" data-idx="' + idx + '" style="display:flex; align-items:center; gap:10px; padding:9px 12px; cursor:pointer;' +
                'background:' + (isActive ? '#F3F4F6' : 'transparent') + ';' +
                'border-left:3px solid ' + (isActive ? color : 'transparent') + ';">' +
                '<span style="width:30px; height:30px; border-radius:50%; background:' + color + '; color:#fff; display:inline-flex; align-items:center; justify-content:center; font-size:11px; font-weight:600; flex-shrink:0;">' + 
                escapeHtml(m.initials || '?') + '</span>' +
                '<div style="flex:1; min-width:0;">' +
                '<div style="font-size:13px; font-weight:500; color:#111827; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">' +
                escapeHtml(m.name) + '</div>' +
                '<div style="font-size:11px; color:#6B7280; margin-top:1px;">@' + escapeHtml(m.tag) + ' · ' + (ROLE_LABELS[m.role] || m.role) + '</div>' +
                '</div>' +
                '</div>';
        });
        listEl.innerHTML = html;
        dropdown.style.display = 'block';
        positionDropdown();
        
        // Utilisation de mousedown au lieu de click pour intercepter AVANT le blur du textarea
        // Délégation : listeners attachés au parent qui ne change pas
        if (!listEl._akMentionBound) {
            listEl._akMentionBound = true;
            listEl.addEventListener('mouseover', function(e) {
                var item = e.target.closest('.mention-item');
                if (!item) return;
                var idx = parseInt(item.dataset.idx);
                if (idx === selectedIdx) return;
                selectedIdx = idx;
                listEl.querySelectorAll('.mention-item').forEach(function(el, i) {
                    el.style.background = (i === selectedIdx) ? '#F3F4F6' : 'transparent';
                });
            });
            listEl.addEventListener('mousedown', function(e) {
                var item = e.target.closest('.mention-item');
                if (!item) return;
                e.preventDefault();
                e.stopPropagation();
                selectedIdx = parseInt(item.dataset.idx);
                insertMention();
            });
        }
    }
    
    function filterMembers(search) {
        var s = (search || '').toLowerCase();
        if (!s) return members.slice(0, 8);
        return members.filter(function(m) {
            return m.tag.toLowerCase().indexOf(s) === 0 
                || m.name.toLowerCase().indexOf(s) >= 0;
        }).slice(0, 8);
    }
    
    function updateAutocomplete() {
        var ctx = detectMentionContext();
        if (!ctx) {
            dropdown.style.display = 'none';
            currentMatchStart = -1;
            return;
        }
        currentSearch = ctx.search;
        currentMatchStart = ctx.start;
        filtered = filterMembers(currentSearch);
        selectedIdx = 0;
        renderDropdown();
    }
    
    function insertMention() {
        if (!filtered.length || currentMatchStart < 0) return;
        var member = filtered[selectedIdx];
        if (!member) return;
        
        var text = inputEl.value;
        var pos = inputEl.selectionStart;
        var before = text.substring(0, currentMatchStart);
        var after = text.substring(pos);
        var insertion = '@' + member.tag + ' ';
        inputEl.value = before + insertion + after;
        var newPos = currentMatchStart + insertion.length;
        
        dropdown.style.display = 'none';
        currentMatchStart = -1;
        
        // Replacer le focus + curseur
        setTimeout(function() {
            inputEl.focus();
            inputEl.setSelectionRange(newPos, newPos);
            // Trigger resize
            inputEl.dispatchEvent(new Event('input'));
        }, 10);
    }
    
    inputEl.addEventListener('input', updateAutocomplete);
    inputEl.addEventListener('click', updateAutocomplete);
    inputEl.addEventListener('keyup', function(e) {
        if (['ArrowDown', 'ArrowUp', 'Enter', 'Escape', 'Tab'].indexOf(e.key) >= 0) return;
        updateAutocomplete();
    });
    
    inputEl.addEventListener('keydown', function(e) {
        if (dropdown.style.display !== 'block') return;
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            selectedIdx = (selectedIdx + 1) % filtered.length;
            renderDropdown();
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            selectedIdx = (selectedIdx - 1 + filtered.length) % filtered.length;
            renderDropdown();
        } else if (e.key === 'Enter' || e.key === 'Tab') {
            if (filtered.length > 0) {
                e.preventDefault();
                e.stopPropagation();
                insertMention();
            }
        } else if (e.key === 'Escape') {
            e.preventDefault();
            dropdown.style.display = 'none';
            currentMatchStart = -1;
        }
    }, true);
    
    // Repositionner au scroll/resize
    window.addEventListener('scroll', function() {
        if (dropdown.style.display === 'block') positionDropdown();
    }, true);
    window.addEventListener('resize', function() {
        if (dropdown.style.display === 'block') positionDropdown();
    });
    
    document.addEventListener('mousedown', function(e) {
        if (!dropdown.contains(e.target) && e.target !== inputEl) {
            dropdown.style.display = 'none';
        }
    });
})();

// Modal IA (placeholder pour Phase 2)
function openAiModal() { document.getElementById('aiModal').classList.add('open'); }
function closeAiModal() { document.getElementById('aiModal').classList.remove('open'); }
var aiModalEl = document.getElementById('aiModal');
if (aiModalEl) aiModalEl.addEventListener('click', function(e) {
  if (e.target === this) closeAiModal();
});
</script>

<?php render_foot(); ?>

<!-- [reply-to] CSS + JS injectes -->
<style>
.msg-quote {
  display: block;
  background: #F3F4F6;
  border-left: 3px solid #10B981;
  padding: 6px 10px;
  border-radius: 6px;
  margin-bottom: 6px;
  cursor: pointer;
  font-size: 12px;
  transition: background 0.15s;
  max-width: 480px;
}
.msg-quote:hover { background: #E5E7EB; }
.msg-quote-author { font-weight: 600; color: #065F46; margin-bottom: 2px; font-size: 11px; }
.msg-quote-content { color: #6B7280; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.msg-quote-deleted { color: #9CA3AF; font-style: italic; border-left-color: #D1D5DB; }
.msg-reply-preview {
  display: flex !important;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  background: #ECFDF5;
  border-left: 3px solid #10B981;
  padding: 8px 12px;
  margin-bottom: 8px;
  border-radius: 8px;
}
.msg-reply-preview[style*="none"] { display: none !important; }
.msg-reply-preview-info { display: flex; gap: 8px; flex: 1; min-width: 0; align-items: center; }
.msg-reply-preview-icon { color: #10B981; font-size: 18px; flex-shrink: 0; font-weight: 700; }
.msg-reply-preview-author { font-size: 11px; font-weight: 600; color: #065F46; }
.msg-reply-preview-content { font-size: 12px; color: #6B7280; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 500px; }
.msg-reply-preview-close { padding: 4px 10px; background: transparent; border: none; color: #6B7280; cursor: pointer; font-size: 16px; border-radius: 4px; line-height: 1; }
.msg-reply-preview-close:hover { background: #fff; color: #ef4444; }
.msg-item.highlight { animation: msg-flash 1.8s ease-out; border-radius: 8px; }
@keyframes msg-flash {
  0%, 100% { background: transparent; }
  20%, 60% { background: #FEF3C7; }
}

/* ============================================================
   MESSAGES 2.0 — surcouche premium Liquid Glass (maquette)
   ============================================================ */
.msg-channels{background:var(--glass)!important;backdrop-filter:blur(22px) saturate(1.5);-webkit-backdrop-filter:blur(22px) saturate(1.5);border:1px solid var(--glass-border)!important;border-radius:var(--radius-lg,18px)!important;box-shadow:var(--shadow-card)}
.msg-channels-title{font-size:11px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:var(--ink-3)}
.msg-channel-link{border-radius:11px;font-weight:500}
.msg-channel-link.active{background:var(--acc-light)!important;color:var(--acc-dark)!important;font-weight:650}
.msg-main{background:var(--glass)!important;backdrop-filter:blur(22px) saturate(1.5);-webkit-backdrop-filter:blur(22px) saturate(1.5);border:1px solid var(--glass-border)!important;border-radius:var(--radius-lg,18px)!important;box-shadow:var(--shadow-card)}
.msg-head-name{font-weight:700}
.msg-head-btn.ai{color:var(--ai)!important;background:var(--ai-light)!important;border:1px solid rgba(99,102,241,.2)!important;font-weight:650;border-radius:11px}
.msg-author{font-weight:650}
.msg-author-role{font-size:10px;font-weight:700;background:var(--bg-2);color:var(--ink-3);border-radius:6px;padding:2px 7px}
.msg-quote{background:var(--bg-2);border-left:3px solid var(--brand-2,#10B981);border-radius:0 10px 10px 0}
.msg-quote:hover{background:var(--bg-3)}
.msg-quote-author{color:var(--brand-ink,#065F46)}
.msg-quote-content{color:var(--ink-2)}
.msg-compose-input{border:1.5px solid var(--acc,#059669)!important;border-radius:14px!important;box-shadow:0 0 0 4px var(--acc-light,rgba(5,150,105,.12))}
.msg-compose-send{background:linear-gradient(140deg,#10B981,#059669)!important;border:0!important;border-radius:12px!important;box-shadow:0 10px 22px -8px rgba(5,150,105,.6)!important}
</style>
<script>
function setReplyTo(msgId, author, content) {
  document.getElementById('replyToMessageId').value = msgId;
  document.getElementById('replyPreviewAuthor').textContent = author;
  document.getElementById('replyPreviewContent').textContent = content;
  document.getElementById('replyPreview').style.display = 'flex';
  var ta = document.getElementById('composeInput');
  if (ta) ta.focus();
}
function clearReplyTo() {
  document.getElementById('replyToMessageId').value = '';
  document.getElementById('replyPreview').style.display = 'none';
}
function scrollToMsg(msgId) {
  var el = document.querySelector('[data-msg-id="' + msgId + '"]');
  if (!el) return;
  el.scrollIntoView({ behavior: 'smooth', block: 'center' });
  el.classList.add('highlight');
  setTimeout(function() { el.classList.remove('highlight'); }, 1800);
}
</script>

<?php if ($active_channel): ?>
<script>
(function() {
  const channelId = <?= (int)$active_channel['id'] ?>;
  const selfUserId = <?= (int)$user_id ?>;
  let lastId = <?= !empty($messages) ? (int)end($messages)['id'] : 0 ?>;
  let pollTimer = null;
  const POLL_INTERVAL_ACTIVE = 4000;
  const POLL_INTERVAL_IDLE = 30000;
  const msgList = document.getElementById('msgList');
  if (!msgList) return;

  const AVATAR_COLORS = {
    'blue': '#4F80BD', 'purple': '#7F77DD', 'amber': '#EF9F27', 'pink': '#D77CA0',
    'teal': '#2AAE89', 'green': '#059669', 'red': '#B91C1C', 'gray': '#78716C'
  };

  function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  }

  function formatContent(content) {
    let safe = escapeHtml(content);
    safe = safe.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
    safe = safe.replace(/(?<!\*)\*([^\*\s][^\*]*?)\*(?!\*)/g, '<em>$1</em>');
    safe = safe.replace(/(@[a-zA-ZÀ-ÿ][a-zA-ZÀ-ÿ.\-]+)/gu, '<span style="background:#DBEAFE; color:#1E40AF; padding:1px 6px; border-radius:4px; font-weight:500;">$1</span>');
    safe = safe.replace(/(https?:\/\/[^\s<]+)/gi, '<a href="$1" target="_blank" rel="noopener" style="color:var(--acc);text-decoration:underline;">$1</a>');
    return safe;
  }

  function isScrolledToBottom() {
    return (msgList.scrollHeight - msgList.scrollTop - msgList.clientHeight) < 80;
  }

  function appendMessage(m) {
    if (document.querySelector('[data-msg-id="' + m.id + '"]')) return;
    const avatarColor = AVATAR_COLORS[m.avatar_color] || '#78716C';
    const roleBadge = (m.author_role === 'admin') ? '<span class="msg-author-role">admin</span>'
                    : (m.author_role === 'coordinator') ? '<span class="msg-author-role">coord</span>' : '';
    const editedBadge = m.is_edited ? '<span class="msg-edited">(modifié)</span>' : '';

    const div = document.createElement('div');
    div.className = 'msg-item';
    div.dataset.msgId = m.id;
    div.innerHTML =
      '<div class="msg-avatar" style="background:' + avatarColor + '">' + escapeHtml(m.avatar_initials) + '</div>' +
      '<div class="msg-body">' +
        '<div class="msg-header">' +
          '<span class="msg-author">' + escapeHtml(m.author_name) + '</span>' +
          roleBadge +
          '<span class="msg-time">' + escapeHtml(m.time_short) + '</span>' +
          editedBadge +
        '</div>' +
        '<div class="msg-content">' + formatContent(m.content) + '</div>' +
      '</div>';

    const empty = msgList.querySelector('.msg-empty');
    if (empty) empty.remove();
    msgList.appendChild(div);
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

      data.messages.forEach(appendMessage);
      lastId = data.last_id;
      if (wasAtBottom) msgList.scrollTop = msgList.scrollHeight;
    } catch (e) {
      console.warn('[polling]', e);
    }
  }

  function startPolling(interval) {
    if (pollTimer) clearInterval(pollTimer);
    pollTimer = setInterval(poll, interval);
  }

  msgList.scrollTop = msgList.scrollHeight;
  startPolling(POLL_INTERVAL_ACTIVE);
  document.addEventListener('visibilitychange', () => {
    if (document.hidden) startPolling(POLL_INTERVAL_IDLE);
    else { poll(); startPolling(POLL_INTERVAL_ACTIVE); }
  });
  setTimeout(poll, 1000);
})();
</script>
<?php endif; ?>
