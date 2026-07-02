<?php
/**
 * super-admin-support-ticket.php — Fiche ticket cote COCKPIT
 * ============================================================
 * URL : /super-admin/support/{id}
 * Actions disponibles :
 *   - Repondre au ticket (+ note interne possible)
 *   - S'assigner ou assigner a un autre SA
 *   - Changer le statut (open/in_progress/waiting_user/resolved/closed)
 *   - Changer la priorite
 *   - Voir le journal d'events
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes-layout.php';
require_once __DIR__ . '/superadmin-layout.php';
require_once __DIR__ . '/sa-permissions.php';
require_once __DIR__ . '/support-helper.php';
@require_once __DIR__ . '/resend-helper.php';
require_login();
$user = sa_require_super_admin();

$ticket_id = (int) ($_GET['id'] ?? 0);
if ($ticket_id <= 0) {
    header('Location: /super-admin/support');
    exit;
}

$ticket = support_get_ticket($ticket_id);
if (!$ticket) {
    header('Location: /super-admin/support');
    exit;
}

$error = null;
$success = null;

// =====================================================================
// Actions POST
// =====================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && check_csrf($_POST['csrf_token'] ?? '')) {
    $action = $_POST['action'] ?? '';

    if ($action === 'reply') {
        $body = trim($_POST['body'] ?? '');
        $is_note = !empty($_POST['is_internal_note']);
        if ($body === '' || strlen($body) < 2) {
            $error = 'Le message ne peut pas être vide.';
        } else {
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO support_messages
                        (ticket_id, author_user_id, author_side, body, is_internal_note, read_by_support, created_at)
                    VALUES (?, ?, 'support', ?, ?, 1, NOW())
                ");
                $stmt->execute([$ticket_id, (int) $user['id'], $body, $is_note ? 1 : 0]);

                if (!$is_note) {
                    // Message visible par l'asso : maj du ticket + notif asso
                    $new_status = $ticket['status'] === 'open' ? 'in_progress' : $ticket['status'];
                    if ($ticket['status'] === 'resolved' || $ticket['status'] === 'closed') {
                        $new_status = 'waiting_user';
                    }
                    $stmt = $pdo->prepare("UPDATE support_tickets SET last_message_at = NOW(), last_message_by = 'support', status = ? WHERE id = ?");
                    $stmt->execute([$new_status, $ticket_id]);

                    try {
                        support_notify_new_message($ticket_id, (int) $user['id'], 'support', $body);
                    } catch (Throwable $e) {}
                }

                header('Location: /super-admin/support/' . $ticket_id);
                exit;
            } catch (Throwable $e) {
                $error = 'Erreur : ' . $e->getMessage();
            }
        }
    } elseif ($action === 'assign_self') {
        $stmt = $pdo->prepare("UPDATE support_tickets SET assigned_to_user_id = ? WHERE id = ?");
        $stmt->execute([(int) $user['id'], $ticket_id]);
        support_log_event($ticket_id, (int) $user['id'], 'assigned', null, $user['first_name'] . ' ' . $user['last_name']);
        header('Location: /super-admin/support/' . $ticket_id);
        exit;
    } elseif ($action === 'unassign') {
        $stmt = $pdo->prepare("UPDATE support_tickets SET assigned_to_user_id = NULL WHERE id = ?");
        $stmt->execute([$ticket_id]);
        support_log_event($ticket_id, (int) $user['id'], 'unassigned');
        header('Location: /super-admin/support/' . $ticket_id);
        exit;
    } elseif ($action === 'change_status') {
        $new_status = $_POST['new_status'] ?? '';
        $valid = ['open','in_progress','waiting_user','resolved','closed'];
        if (in_array($new_status, $valid, true)) {
            $stmt = $pdo->prepare("UPDATE support_tickets SET status = ?, resolved_at = ?, resolved_by_user_id = ?, closed_at = ? WHERE id = ?");
            $stmt->execute([
                $new_status,
                $new_status === 'resolved' ? date('Y-m-d H:i:s') : null,
                $new_status === 'resolved' ? (int) $user['id'] : null,
                $new_status === 'closed' ? date('Y-m-d H:i:s') : null,
                $ticket_id,
            ]);
            support_log_event($ticket_id, (int) $user['id'], 'status_changed', $ticket['status'], $new_status);
        }
        header('Location: /super-admin/support/' . $ticket_id);
        exit;
    } elseif ($action === 'change_priority') {
        $new_prio = $_POST['new_priority'] ?? '';
        $valid = ['low','normal','high','urgent'];
        if (in_array($new_prio, $valid, true)) {
            $stmt = $pdo->prepare("UPDATE support_tickets SET priority = ? WHERE id = ?");
            $stmt->execute([$new_prio, $ticket_id]);
            support_log_event($ticket_id, (int) $user['id'], 'priority_changed', $ticket['priority'], $new_prio);
        }
        header('Location: /super-admin/support/' . $ticket_id);
        exit;
    }
}

// Marquer messages asso comme lus par support
try {
    $stmt = $pdo->prepare("UPDATE support_messages SET read_by_support = 1 WHERE ticket_id = ? AND author_side = 'org' AND read_by_support = 0");
    $stmt->execute([$ticket_id]);
} catch (Throwable $e) {}

// Recharger ticket apres updates
$ticket = support_get_ticket($ticket_id);

// Charger les messages (avec notes internes cote support)
$stmt = $pdo->prepare("
    SELECT m.*, u.first_name, u.last_name
    FROM support_messages m
    LEFT JOIN users u ON m.author_user_id = u.id
    WHERE m.ticket_id = ?
    ORDER BY m.created_at ASC
");
$stmt->execute([$ticket_id]);
$messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Events
$stmt = $pdo->prepare("
    SELECT e.*, u.first_name, u.last_name
    FROM support_ticket_events e
    LEFT JOIN users u ON e.user_id = u.id
    WHERE e.ticket_id = ?
    ORDER BY e.created_at DESC
    LIMIT 10
");
$stmt->execute([$ticket_id]);
$events = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Liste des SA pour reassignation
$all_sa = [];
try {
    $stmt = $pdo->query("
        SELECT id, first_name, last_name, is_founder
        FROM users
        WHERE is_active = 1 AND (role = 'super_admin' OR is_super_admin = 1 OR is_founder = 1)
        ORDER BY is_founder DESC, first_name ASC
    ");
    $all_sa = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

sa_render_head('Ticket #' . $ticket_id);
sa_render_sidebar('support');
?>

<div class="sa-breadcrumb">
  <a href="/super-admin/support">Support</a>
  <span class="sep">›</span>
  Ticket #<?= $ticket_id ?>
</div>

<div class="sa-page-head">
  <div>
    <h1 class="sa-page-title">#<?= (int) $ticket['id'] ?> — <?= h($ticket['title']) ?></h1>
    <div class="sa-page-sub">
      Association : <strong><?= h($ticket['org_name']) ?></strong>
      · Ouvert par <?= h($ticket['creator_first'] . ' ' . $ticket['creator_last']) ?>
      le <?= date('d/m/Y H:i', strtotime($ticket['created_at'])) ?>
    </div>
  </div>
</div>

<?php if ($error): ?><div class="sa-alert sa-alert-error">⚠️ <?= h($error) ?></div><?php endif; ?>

<div style="display:grid; grid-template-columns: 2fr 1fr; gap:16px;">

  <!-- ========== COLONNE GAUCHE : fil ========== -->
  <div>

    <!-- Fil messages -->
    <div style="display:flex; flex-direction:column; gap:10px; margin-bottom:16px;">
      <?php foreach ($messages as $m): ?>
        <?php $is_support = $m['author_side'] === 'support'; ?>
        <?php $is_note = !empty($m['is_internal_note']); ?>
        <div style="display:flex; gap:10px; <?= $is_support ? 'flex-direction:row;' : 'flex-direction:row-reverse;' ?>">
          <div style="width:34px; height:34px; border-radius:50%; flex-shrink:0; display:flex; align-items:center; justify-content:center; font-size:11px; font-weight:600;
                      <?= $is_support
                          ? 'background:linear-gradient(135deg, #7F77DD, #5B52A6); color:white;'
                          : 'background:rgba(5, 150, 105, 0.2); color:#6EE7B7;' ?>">
            <?= $is_support ? '👑' : h(strtoupper(mb_substr($m['first_name'] ?? '?', 0, 1))) ?>
          </div>
          <div style="max-width:80%;">
            <div style="display:flex; align-items:baseline; gap:8px; margin-bottom:3px; <?= !$is_support ? 'justify-content:flex-end;' : '' ?>">
              <strong style="font-size:12.5px; color:<?= $is_support ? '#C4B5FD' : '#6EE7B7' ?>;">
                <?= h(($m['first_name'] ?? '') . ' ' . ($m['last_name'] ?? '')) ?>
              </strong>
              <?php if ($is_note): ?>
                <span style="font-size:10px; background:rgba(251, 191, 36, 0.2); color:#FCD34D; padding:1px 6px; border-radius:999px; font-weight:600;">🔒 NOTE INTERNE</span>
              <?php endif; ?>
              <span style="font-size:11px; color:var(--sa-ink-4);">
                <?= date('d/m H:i', strtotime($m['created_at'])) ?>
              </span>
            </div>
            <div style="padding:10px 12px; border-radius:10px; font-size:13px; line-height:1.55;
                        <?= $is_note
                            ? 'background:rgba(251, 191, 36, 0.08); color:#FDE68A; border:1px dashed rgba(251, 191, 36, 0.3);'
                            : ($is_support
                                ? 'background:rgba(127, 119, 221, 0.1); color:#DDD6FE; border:1px solid rgba(127, 119, 221, 0.2);'
                                : 'background:rgba(5, 150, 105, 0.08); color:#A7F3D0; border:1px solid rgba(5, 150, 105, 0.2);') ?>">
              <?= nl2br(h($m['body'])) ?>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>

    <!-- Zone de reponse -->
    <?php if ($ticket['status'] !== 'closed'): ?>
      <div class="sa-card">
        <form method="POST">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
          <input type="hidden" name="action" value="reply">
          <label for="body" style="display:block; font-size:13px; font-weight:500; margin-bottom:6px; color:var(--sa-ink);">Répondre</label>
          <textarea id="body" name="body" required minlength="2" rows="4"
                    placeholder="Votre réponse au ticket..."
                    style="width:100%; padding:10px 12px; background:var(--sa-bg-2); border:1px solid var(--sa-border-strong); border-radius:8px; font-family:inherit; font-size:14px; color:var(--sa-ink); resize:vertical; margin-bottom:10px;"></textarea>
          <div style="display:flex; justify-content:space-between; align-items:center; gap:10px; flex-wrap:wrap;">
            <label style="display:flex; align-items:center; gap:8px; font-size:12px; color:var(--sa-ink-3); cursor:pointer;">
              <input type="checkbox" name="is_internal_note" value="1">
              🔒 Note interne (non visible par l'asso)
            </label>
            <button type="submit" class="sa-btn sa-btn-violet">Envoyer →</button>
          </div>
        </form>
      </div>
    <?php else: ?>
      <div class="sa-card" style="text-align:center; color:var(--sa-ink-3);">
        🔒 Ce ticket est fermé. Pour rouvrir, change le statut dans le panneau de droite.
      </div>
    <?php endif; ?>
  </div>

  <!-- ========== COLONNE DROITE : actions ========== -->
  <div>

    <!-- Statut / Priorite -->
    <div class="sa-card" style="margin-bottom:12px;">
      <div class="sa-card-title">Statut & Priorité</div>
      <div style="margin-top:12px; display:flex; flex-direction:column; gap:10px;">
        <div>
          <div style="font-size:11px; color:var(--sa-ink-3); margin-bottom:4px;">Statut</div>
          <form method="POST" style="display:flex; gap:6px;">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
            <input type="hidden" name="action" value="change_status">
            <select name="new_status" onchange="this.form.submit()"
                    style="flex:1; padding:6px 8px; background:var(--sa-bg-2); border:1px solid var(--sa-border-strong); border-radius:6px; color:var(--sa-ink); font-family:inherit; font-size:12.5px;">
              <option value="open" <?= $ticket['status'] === 'open' ? 'selected' : '' ?>>🟢 Ouvert</option>
              <option value="in_progress" <?= $ticket['status'] === 'in_progress' ? 'selected' : '' ?>>🔵 En cours</option>
              <option value="waiting_user" <?= $ticket['status'] === 'waiting_user' ? 'selected' : '' ?>>⏳ Attente user</option>
              <option value="resolved" <?= $ticket['status'] === 'resolved' ? 'selected' : '' ?>>✅ Résolu</option>
              <option value="closed" <?= $ticket['status'] === 'closed' ? 'selected' : '' ?>>🔒 Fermé</option>
            </select>
          </form>
        </div>
        <div>
          <div style="font-size:11px; color:var(--sa-ink-3); margin-bottom:4px;">Priorité</div>
          <form method="POST" style="display:flex; gap:6px;">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
            <input type="hidden" name="action" value="change_priority">
            <select name="new_priority" onchange="this.form.submit()"
                    style="flex:1; padding:6px 8px; background:var(--sa-bg-2); border:1px solid var(--sa-border-strong); border-radius:6px; color:var(--sa-ink); font-family:inherit; font-size:12.5px;">
              <option value="low" <?= $ticket['priority'] === 'low' ? 'selected' : '' ?>>🟢 Basse</option>
              <option value="normal" <?= $ticket['priority'] === 'normal' ? 'selected' : '' ?>>⚪ Normale</option>
              <option value="high" <?= $ticket['priority'] === 'high' ? 'selected' : '' ?>>🟠 Haute</option>
              <option value="urgent" <?= $ticket['priority'] === 'urgent' ? 'selected' : '' ?>>🔴 Urgente</option>
            </select>
          </form>
        </div>
        <div>
          <div style="font-size:11px; color:var(--sa-ink-3); margin-bottom:4px;">Catégorie</div>
          <div style="padding:6px 10px; background:var(--sa-bg-2); border:1px solid var(--sa-border); border-radius:6px; font-size:12.5px;">
            <?= support_category_label($ticket['category']) ?>
          </div>
        </div>
      </div>
    </div>

    <!-- Assignation -->
    <div class="sa-card" style="margin-bottom:12px;">
      <div class="sa-card-title">Assignation</div>
      <div style="margin-top:12px;">
        <?php if ($ticket['assigned_to_user_id']): ?>
          <div style="padding:10px 12px; background:var(--sa-bg-2); border-radius:8px; margin-bottom:8px;">
            <div style="font-size:11px; color:var(--sa-ink-3);">Assigné à</div>
            <div style="font-weight:500; font-size:13px; margin-top:2px;">
              <?= h($ticket['assigned_first'] . ' ' . $ticket['assigned_last']) ?>
            </div>
          </div>
          <?php if ((int) $ticket['assigned_to_user_id'] !== (int) $user['id']): ?>
            <form method="POST">
              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
              <input type="hidden" name="action" value="assign_self">
              <button type="submit" class="sa-btn sa-btn-violet sa-btn-sm" style="width:100%;">Se l'attribuer</button>
            </form>
          <?php endif; ?>
          <form method="POST" style="margin-top:6px;">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
            <input type="hidden" name="action" value="unassign">
            <button type="submit" class="sa-btn sa-btn-ghost sa-btn-sm" style="width:100%;">Retirer assignation</button>
          </form>
        <?php else: ?>
          <div style="padding:10px 12px; background:rgba(239, 68, 68, 0.08); border:1px solid rgba(239, 68, 68, 0.2); border-radius:8px; margin-bottom:10px; color:#FCA5A5; font-size:12.5px;">
            ⚠️ Aucun assigné — ticket dans le pool
          </div>
          <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
            <input type="hidden" name="action" value="assign_self">
            <button type="submit" class="sa-btn sa-btn-violet" style="width:100%;">👤 Se l'attribuer</button>
          </form>
        <?php endif; ?>
      </div>
    </div>

    <!-- Asso -->
    <div class="sa-card" style="margin-bottom:12px;">
      <div class="sa-card-title">Association</div>
      <div style="margin-top:12px; display:flex; flex-direction:column; gap:6px;">
        <div style="font-weight:500; font-size:13.5px;"><?= h($ticket['org_name']) ?></div>
        <a href="/super-admin/associations?id=<?= (int) $ticket['org_id'] ?>" class="sa-btn sa-btn-ghost sa-btn-sm" style="width:100%;">
          Voir la fiche asso →
        </a>
      </div>
    </div>

    <!-- Historique events -->
    <?php if (!empty($events)): ?>
      <div class="sa-card">
        <div class="sa-card-title">Historique</div>
        <div style="margin-top:12px; display:flex; flex-direction:column; gap:8px;">
          <?php foreach ($events as $e): ?>
            <div style="font-size:11.5px; color:var(--sa-ink-3); padding-bottom:6px; border-bottom:1px solid var(--sa-border);">
              <strong style="color:var(--sa-ink-2);">
                <?= match($e['event_type']) {
                    'created' => '🆕 Créé',
                    'status_changed' => '🔄 Statut : ' . h($e['to_value'] ?? ''),
                    'priority_changed' => '⚡ Priorité : ' . h($e['to_value'] ?? ''),
                    'assigned' => '👤 Assigné : ' . h($e['to_value'] ?? ''),
                    'unassigned' => '👥 Désassigné',
                    'resolved' => '✅ Résolu',
                    'reopened' => '🔁 Rouvert',
                    'closed' => '🔒 Fermé',
                    default => h($e['event_type']),
                } ?>
              </strong><br>
              <span>par <?= h($e['first_name'] ?? '?') ?> · <?= date('d/m H:i', strtotime($e['created_at'])) ?></span>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endif; ?>

  </div>
</div>

<?php sa_render_foot(); ?>
