<?php
/**
 * support-ticket.php — Fiche ticket + fil de messages cote ASSO
 * ================================================================
 * URL : /support/ticket/{id}
 * L'asso peut : lire les messages, repondre, marquer comme resolu.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes-layout.php';
require_once __DIR__ . '/support-helper.php';
@require_once __DIR__ . '/sa-permissions.php';
@require_once __DIR__ . '/resend-helper.php';
require_login();

$user = current_user();
$ticket_id = (int) ($_GET['id'] ?? 0);

if ($ticket_id <= 0) {
    header('Location: /support');
    exit;
}

$ticket = support_get_ticket($ticket_id);
if (!$ticket) {
    header('Location: /support');
    exit;
}

// Verif permissions
if (!support_user_can_see_ticket($user, $ticket)) {
    http_response_code(403);
    die('403 — Vous n\'avez pas accès à ce ticket.');
}

$error = null;

// =====================================================================
// POST : ajouter message OU marquer comme resolu
// =====================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && check_csrf($_POST['csrf_token'] ?? '')) {
    $action = $_POST['action'] ?? 'reply';

    if ($action === 'reply') {
        $body = trim($_POST['body'] ?? '');
        if ($body === '' || strlen($body) < 2) {
            $error = 'Le message ne peut pas être vide.';
        } else {
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO support_messages
                        (ticket_id, author_user_id, author_side, body, read_by_org, created_at)
                    VALUES (?, ?, 'org', ?, 1, NOW())
                ");
                $stmt->execute([$ticket_id, (int) $user['id'], $body]);

                $new_status = $ticket['status'] === 'waiting_user' ? 'in_progress' : $ticket['status'];
                if ($ticket['status'] === 'resolved' || $ticket['status'] === 'closed') {
                    $new_status = 'open';
                }
                $stmt = $pdo->prepare("
                    UPDATE support_tickets
                    SET last_message_at = NOW(), last_message_by = 'org', status = ?
                    WHERE id = ?
                ");
                $stmt->execute([$new_status, $ticket_id]);

                try {
                    support_notify_new_message($ticket_id, (int) $user['id'], 'org', $body);
                } catch (Throwable $e) {}

                header('Location: /support/ticket/' . $ticket_id);
                exit;
            } catch (Throwable $e) {
                $error = 'Erreur : ' . $e->getMessage();
            }
        }
    } elseif ($action === 'mark_resolved') {
        try {
            $stmt = $pdo->prepare("UPDATE support_tickets SET status = 'resolved', resolved_at = NOW(), resolved_by_user_id = ? WHERE id = ?");
            $stmt->execute([(int) $user['id'], $ticket_id]);
            support_log_event($ticket_id, (int) $user['id'], 'resolved', $ticket['status'], 'resolved', 'Résolu par l\'asso');
            header('Location: /support/ticket/' . $ticket_id);
            exit;
        } catch (Throwable $e) {
            $error = 'Erreur : ' . $e->getMessage();
        }
    }
}

// Marquer tous les messages support comme lus par l'asso
try {
    $stmt = $pdo->prepare("UPDATE support_messages SET read_by_org = 1 WHERE ticket_id = ? AND author_side = 'support' AND read_by_org = 0 AND is_internal_note = 0");
    $stmt->execute([$ticket_id]);
} catch (Throwable $e) {}

// Recharger le ticket (statut a pu changer)
$ticket = support_get_ticket($ticket_id);

// Charger les messages (hors notes internes)
$stmt = $pdo->prepare("
    SELECT m.*, u.first_name, u.last_name
    FROM support_messages m
    LEFT JOIN users u ON m.author_user_id = u.id
    WHERE m.ticket_id = ? AND m.is_internal_note = 0
    ORDER BY m.created_at ASC
");
$stmt->execute([$ticket_id]);
$messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

render_head('Ticket #' . $ticket_id);
render_sidebar('support');
?>

<div class="main">

  <div style="font-size:12px; margin-bottom:10px; color:var(--ink-3);">
    <a href="/support" style="color:var(--ink-3); text-decoration:none;">Support</a>
    <span style="color:var(--ink-4);"> › </span>
    <span>Ticket #<?= $ticket_id ?></span>
  </div>

  <!-- Entete ticket -->
  <div style="background:var(--bg); border:1px solid var(--border); border-radius:12px; padding:20px; margin-bottom:16px;">
    <div style="display:flex; align-items:flex-start; justify-content:space-between; gap:14px; flex-wrap:wrap;">
      <div style="flex:1; min-width:0;">
        <div style="font-size:11px; color:var(--ink-4); font-weight:500; letter-spacing:0.05em; margin-bottom:4px;">#<?= (int) $ticket['id'] ?></div>
        <h1 style="margin:0 0 8px; font-size:20px; font-weight:500; letter-spacing:-0.01em;"><?= h($ticket['title']) ?></h1>
        <div style="display:flex; gap:6px; flex-wrap:wrap;">
          <span style="background:var(--bg-3); color:var(--ink-2); font-size:11px; padding:3px 8px; border-radius:999px; font-weight:500;">
            <?= support_category_label($ticket['category']) ?>
          </span>
          <span style="background:rgba(<?= $ticket['priority'] === 'urgent' ? '239,68,68' : ($ticket['priority'] === 'high' ? '245,158,11' : ($ticket['priority'] === 'low' ? '16,185,129' : '161,161,170')) ?>, 0.12); color:<?= support_priority_color($ticket['priority']) ?>; font-size:11px; padding:3px 8px; border-radius:999px; font-weight:500;">
            <?= support_priority_label($ticket['priority']) ?>
          </span>
          <span style="font-size:11px; padding:3px 8px; border-radius:999px; font-weight:500;
                       <?= match($ticket['status']) {
                            'open'         => 'background:var(--acc-light); color:var(--acc-dark);',
                            'in_progress'  => 'background:#EEEDFE; color:#3C3489;',
                            'waiting_user' => 'background:#FEF3C7; color:#854F0B;',
                            'resolved'     => 'background:var(--acc-light); color:var(--acc-dark);',
                            'closed'       => 'background:var(--bg-3); color:var(--ink-3);',
                            default        => 'background:var(--bg-3); color:var(--ink-3);',
                         } ?>">
            <?= support_status_label($ticket['status']) ?>
          </span>
        </div>
      </div>
      <?php if (in_array($ticket['status'], ['open','in_progress','waiting_user'], true)): ?>
        <form method="POST" style="display:inline">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
          <input type="hidden" name="action" value="mark_resolved">
          <button type="submit" class="btn btn-ghost" style="padding:6px 12px; font-size:12.5px;" onclick="return confirm('Marquer ce ticket comme résolu ?')">
            ✓ C'est résolu
          </button>
        </form>
      <?php endif; ?>
    </div>
    <div style="font-size:12px; color:var(--ink-3); margin-top:12px; padding-top:12px; border-top:1px solid var(--border);">
      Ouvert par <strong><?= h($ticket['creator_first'] . ' ' . $ticket['creator_last']) ?></strong>
      le <?= date('d/m/Y à H:i', strtotime($ticket['created_at'])) ?>
    </div>
  </div>

  <?php if ($error): ?>
    <div class="alert alert-error">
      <span>⚠️</span>
      <div><?= h($error) ?></div>
    </div>
  <?php endif; ?>

  <!-- Fil messages -->
  <div style="display:flex; flex-direction:column; gap:12px; margin-bottom:20px;">
    <?php foreach ($messages as $m): ?>
      <?php $is_support = $m['author_side'] === 'support'; ?>
      <div style="display:flex; gap:10px; <?= $is_support ? 'flex-direction:row;' : 'flex-direction:row-reverse;' ?>">
        <!-- Avatar -->
        <div style="width:36px; height:36px; border-radius:50%; flex-shrink:0; display:flex; align-items:center; justify-content:center; font-size:12px; font-weight:600;
                    <?= $is_support
                        ? 'background:linear-gradient(135deg, #7F77DD, #5B52A6); color:white;'
                        : 'background:var(--acc-light); color:var(--acc-dark);' ?>">
          <?= $is_support ? '👑' : h(strtoupper(mb_substr($m['first_name'] ?? '?', 0, 1))) ?>
        </div>
        <!-- Bulle -->
        <div style="max-width:75%; min-width:0;">
          <div style="display:flex; align-items:baseline; gap:8px; margin-bottom:3px; <?= !$is_support ? 'justify-content:flex-end;' : '' ?>">
            <strong style="font-size:13px; color:<?= $is_support ? '#5B52A6' : 'var(--ink)' ?>;">
              <?= $is_support
                  ? 'Support Assokit'
                  : h(($m['first_name'] ?? '') . ' ' . ($m['last_name'] ?? '')) ?>
            </strong>
            <span style="font-size:11px; color:var(--ink-4);">
              <?= date('d/m/Y H:i', strtotime($m['created_at'])) ?>
            </span>
          </div>
          <div style="padding:12px 14px; border-radius:12px; font-size:13.5px; line-height:1.55;
                      <?= $is_support
                          ? 'background:#EEEDFE; color:#3C3489; border:1px solid rgba(127, 119, 221, 0.2);'
                          : 'background:var(--acc-light); color:var(--acc-dark); border:1px solid rgba(5, 150, 105, 0.15);' ?>">
            <?= nl2br(h($m['body'])) ?>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>

  <!-- Zone de reponse -->
  <?php if ($ticket['status'] !== 'closed'): ?>
    <form method="POST"
          style="background:var(--bg); border:1px solid var(--border); border-radius:12px; padding:16px;">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
      <input type="hidden" name="action" value="reply">
      <label for="body" style="display:block; font-size:13px; font-weight:500; margin-bottom:6px;">
        Votre réponse
      </label>
      <textarea id="body" name="body" required minlength="2" rows="4"
                placeholder="Tapez votre message..."
                style="width:100%; padding:10px 12px; background:var(--bg); border:1px solid var(--border-strong); border-radius:8px; font-family:inherit; font-size:14px; color:var(--ink); resize:vertical; margin-bottom:10px;"></textarea>
      <div style="display:flex; justify-content:flex-end;">
        <button type="submit" class="btn btn-primary">Envoyer →</button>
      </div>
    </form>
  <?php else: ?>
    <div style="background:var(--bg); border:1px solid var(--border); border-radius:12px; padding:20px; text-align:center; color:var(--ink-3);">
      🔒 Ce ticket est fermé.
      <a href="/support/nouveau" style="color:var(--acc); margin-left:6px;">Ouvrir un nouveau ticket →</a>
    </div>
  <?php endif; ?>

</div>

<?php render_foot(); ?>
