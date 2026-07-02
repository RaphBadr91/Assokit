<?php
/**
 * support.php — Liste des tickets cote ASSO
 * ===========================================
 * Affiche tous les tickets de l'asso du user connecte.
 * Filtres : statut (onglets).
 * Action : creer nouveau ticket (bouton primary).
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes-layout.php';
require_once __DIR__ . '/support-helper.php';
require_login();

$user = current_user();
$org_id = (int) ($user['org_id'] ?? 0);

// Un super admin pur (sans org) n'a rien a faire ici
if ($org_id <= 0) {
    header('Location: /super-admin/support');
    exit;
}

// Filtres
$filter_status = $_GET['status'] ?? '';
$filter_mine = isset($_GET['mine']);

$where = ['t.org_id = ?'];
$params = [$org_id];

if ($filter_status !== '') {
    $where[] = 't.status = ?';
    $params[] = $filter_status;
}
if ($filter_mine) {
    $where[] = 't.created_by_user_id = ?';
    $params[] = (int) $user['id'];
}

$where_sql = 'WHERE ' . implode(' AND ', $where);

$stmt = $pdo->prepare("
    SELECT
        t.*,
        creator.first_name AS creator_first, creator.last_name AS creator_last,
        (SELECT COUNT(*) FROM support_messages m WHERE m.ticket_id = t.id AND m.is_internal_note = 0) AS nb_messages,
        (SELECT COUNT(*) FROM support_messages m WHERE m.ticket_id = t.id AND m.is_internal_note = 0 AND m.author_side = 'support' AND m.read_by_org = 0) AS nb_unread
    FROM support_tickets t
    LEFT JOIN users creator ON t.created_by_user_id = creator.id
    $where_sql
    ORDER BY
        CASE WHEN t.status IN ('open','in_progress','waiting_user') THEN 0 ELSE 1 END,
        t.last_message_at DESC, t.created_at DESC
");
$stmt->execute($params);
$tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Stats rapides
$stats = [
    'open' => 0, 'in_progress' => 0, 'waiting_user' => 0, 'resolved' => 0, 'closed' => 0, 'total' => 0,
];
try {
    $stmt = $pdo->prepare("SELECT status, COUNT(*) AS nb FROM support_tickets WHERE org_id = ? GROUP BY status");
    $stmt->execute([$org_id]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $stats[$r['status']] = (int) $r['nb'];
        $stats['total'] += (int) $r['nb'];
    }
} catch (Throwable $e) {}

render_head('Support');
render_sidebar('support');
?>

<div class="main">

  <div class="main-head">
    <div>
      <h1 class="page-title">💬 Support</h1>
      <div class="page-sub">
        <?= $stats['total'] ?> ticket<?= $stats['total'] > 1 ? 's' : '' ?> au total
        <?php if ($stats['open'] + $stats['in_progress'] + $stats['waiting_user'] > 0): ?>
            · <strong style="color:var(--acc)"><?= $stats['open'] + $stats['in_progress'] + $stats['waiting_user'] ?> actif<?= ($stats['open'] + $stats['in_progress'] + $stats['waiting_user']) > 1 ? 's' : '' ?></strong>
        <?php endif; ?>
      </div>
    </div>
    <div>
      <a href="/support/nouveau" class="btn btn-primary">+ Nouveau ticket</a>
    </div>
  </div>

  <!-- Onglets filtre -->
  <div style="display:flex; gap:6px; margin-bottom:16px; flex-wrap:wrap;">
    <a href="/support" class="btn <?= !$filter_status && !$filter_mine ? 'btn-primary' : 'btn-ghost' ?>" style="padding:6px 12px; font-size:12.5px;">
      Tous (<?= $stats['total'] ?>)
    </a>
    <a href="/support?status=open" class="btn <?= $filter_status === 'open' ? 'btn-primary' : 'btn-ghost' ?>" style="padding:6px 12px; font-size:12.5px;">
      🟢 Ouverts (<?= $stats['open'] ?>)
    </a>
    <a href="/support?status=in_progress" class="btn <?= $filter_status === 'in_progress' ? 'btn-primary' : 'btn-ghost' ?>" style="padding:6px 12px; font-size:12.5px;">
      🔵 En cours (<?= $stats['in_progress'] ?>)
    </a>
    <a href="/support?status=waiting_user" class="btn <?= $filter_status === 'waiting_user' ? 'btn-primary' : 'btn-ghost' ?>" style="padding:6px 12px; font-size:12.5px;">
      ⏳ Attente (<?= $stats['waiting_user'] ?>)
    </a>
    <a href="/support?status=resolved" class="btn <?= $filter_status === 'resolved' ? 'btn-primary' : 'btn-ghost' ?>" style="padding:6px 12px; font-size:12.5px;">
      ✅ Résolus (<?= $stats['resolved'] ?>)
    </a>
    <a href="/support?mine" class="btn <?= $filter_mine ? 'btn-primary' : 'btn-ghost' ?>" style="padding:6px 12px; font-size:12.5px;">
      👤 Les miens
    </a>
  </div>

  <?php if (empty($tickets)): ?>
    <div style="background:var(--bg); border:1px solid var(--border); border-radius:12px; padding:40px 20px; text-align:center;">
      <div style="font-size:40px; margin-bottom:10px;">💬</div>
      <h2 style="margin:0 0 6px; font-size:18px; font-weight:500;">Aucun ticket pour l'instant</h2>
      <p style="color:var(--ink-3); margin:0 0 18px;">
        Besoin d'aide ? Notre équipe est là pour vous accompagner.
      </p>
      <a href="/support/nouveau" class="btn btn-primary">+ Créer un premier ticket</a>
    </div>
  <?php else: ?>
    <div style="background:var(--bg); border:1px solid var(--border); border-radius:12px; overflow:hidden;">
      <?php foreach ($tickets as $t): ?>
        <a href="/support/ticket/<?= (int) $t['id'] ?>"
           style="display:grid; grid-template-columns: 1fr auto; gap:14px; padding:16px 20px; border-bottom:1px solid var(--border); text-decoration:none; color:inherit; transition:background .15s; <?= $t['nb_unread'] > 0 ? 'background:rgba(5, 150, 105, 0.04);' : '' ?>">
          <div style="min-width:0;">
            <div style="display:flex; align-items:center; gap:8px; margin-bottom:4px; flex-wrap:wrap;">
              <div style="font-size:10px; color:var(--ink-4); font-weight:500; letter-spacing:0.05em;">#<?= (int) $t['id'] ?></div>
              <span style="background:rgba(<?= $t['priority'] === 'urgent' ? '239,68,68' : ($t['priority'] === 'high' ? '245,158,11' : ($t['priority'] === 'low' ? '16,185,129' : '161,161,170')) ?>, 0.12); color:<?= support_priority_color($t['priority']) ?>; font-size:10.5px; padding:2px 7px; border-radius:999px; font-weight:500;">
                <?= support_priority_label($t['priority']) ?>
              </span>
              <span style="background:var(--bg-3); color:var(--ink-3); font-size:10.5px; padding:2px 7px; border-radius:999px; font-weight:500;">
                <?= support_category_label($t['category']) ?>
              </span>
              <?php if ($t['nb_unread'] > 0): ?>
                <span style="background:#EF4444; color:white; font-size:10px; padding:2px 7px; border-radius:999px; font-weight:600;">
                  <?= $t['nb_unread'] ?> non lu<?= $t['nb_unread'] > 1 ? 's' : '' ?>
                </span>
              <?php endif; ?>
            </div>
            <div style="font-size:14.5px; font-weight:<?= $t['nb_unread'] > 0 ? '600' : '500' ?>; margin-bottom:4px; letter-spacing:-0.01em;">
              <?= h($t['title']) ?>
            </div>
            <div style="font-size:12px; color:var(--ink-3);">
              Ouvert par <?= h($t['creator_first'] . ' ' . $t['creator_last']) ?>
              · <?= (int) $t['nb_messages'] ?> message<?= $t['nb_messages'] > 1 ? 's' : '' ?>
              <?php if ($t['last_message_at']): ?>
                · Dernière activité <?= date('d/m/Y H:i', strtotime($t['last_message_at'])) ?>
              <?php else: ?>
                · Créé <?= date('d/m/Y', strtotime($t['created_at'])) ?>
              <?php endif; ?>
            </div>
          </div>
          <div style="display:flex; align-items:center;">
            <span style="padding:4px 10px; border-radius:999px; font-size:11.5px; font-weight:500;
                         <?= match($t['status']) {
                              'open'         => 'background:var(--acc-light); color:var(--acc-dark);',
                              'in_progress'  => 'background:#EEEDFE; color:#3C3489;',
                              'waiting_user' => 'background:#FEF3C7; color:#854F0B;',
                              'resolved'     => 'background:var(--acc-light); color:var(--acc-dark);',
                              'closed'       => 'background:var(--bg-3); color:var(--ink-3);',
                              default        => 'background:var(--bg-3); color:var(--ink-3);',
                           } ?>">
              <?= support_status_label($t['status']) ?>
            </span>
          </div>
        </a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

</div>

<?php render_foot(); ?>
