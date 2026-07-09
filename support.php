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

<style>
/* ============================================================
   SUPPORT 2.0 — surcouche premium Liquid Glass (maquette)
   ============================================================ */
.main{max-width:1120px}
.main .page-title{font-size:30px;font-weight:800;letter-spacing:-.03em;line-height:1.1;display:flex;align-items:center;gap:12px;color:var(--ink)}
.main .page-sub{font-size:13.5px;color:var(--ink-2);margin-top:10px}
.sup-tabs{display:flex;gap:8px;margin-bottom:18px;flex-wrap:wrap}
.sup-tabs .btn{padding:9px 14px!important;font-size:12.5px!important;border-radius:999px!important;font-weight:600;background:var(--glass);border:1px solid var(--glass-border);color:var(--ink-2);backdrop-filter:blur(10px);-webkit-backdrop-filter:blur(10px);box-shadow:none}
.sup-tabs .btn-primary{background:var(--ink)!important;color:var(--bg)!important;border-color:transparent!important;box-shadow:var(--shadow-card)!important}
.sup-list{display:flex;flex-direction:column;gap:12px}
.sup-ticket{position:relative;overflow:hidden;display:block;background:var(--glass);backdrop-filter:blur(20px) saturate(1.5);-webkit-backdrop-filter:blur(20px) saturate(1.5);border:1px solid var(--glass-border);border-radius:var(--radius-lg,18px);box-shadow:var(--shadow-card);padding:18px 20px;text-decoration:none;color:inherit;transition:transform .16s ease,box-shadow .16s ease}
.sup-ticket:hover{transform:translateY(-2px);box-shadow:var(--shadow-pop)}
.sup-tk-glow{position:absolute;inset:0 auto 0 0;width:4px}
.sup-tk-top{display:flex;align-items:center;gap:10px;flex-wrap:wrap}
.sup-tk-id{font-size:12px;font-weight:700;color:var(--ink-4);font-variant-numeric:tabular-nums}
.sup-tk-badge,.sup-tk-cat,.sup-tk-unread{font-size:11px;font-weight:700;padding:3px 10px;border-radius:999px}
.sup-tk-cat{background:var(--bg-2);color:var(--ink-3)}
.sup-tk-unread{background:#EF4444;color:#fff}
.sup-tk-status{margin-left:auto;font-size:11.5px;font-weight:700;padding:5px 12px;border-radius:999px}
.sup-tk-title{font-size:16px;font-weight:700;letter-spacing:-.01em;margin:12px 0 6px;color:var(--ink)}
.sup-tk-meta{font-size:12.5px;color:var(--ink-3)}
.sup-empty{background:var(--glass);backdrop-filter:blur(22px) saturate(1.5);-webkit-backdrop-filter:blur(22px) saturate(1.5);border:1px solid var(--glass-border);border-radius:var(--radius-lg,18px);box-shadow:var(--shadow-card);padding:44px 20px;text-align:center}
</style>

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
  <div class="sup-tabs">
    <a href="/support" class="btn <?= !$filter_status && !$filter_mine ? 'btn-primary' : 'btn-ghost' ?>">Tous (<?= $stats['total'] ?>)</a>
    <a href="/support?status=open" class="btn <?= $filter_status === 'open' ? 'btn-primary' : 'btn-ghost' ?>">🟢 Ouverts (<?= $stats['open'] ?>)</a>
    <a href="/support?status=in_progress" class="btn <?= $filter_status === 'in_progress' ? 'btn-primary' : 'btn-ghost' ?>">🔵 En cours (<?= $stats['in_progress'] ?>)</a>
    <a href="/support?status=waiting_user" class="btn <?= $filter_status === 'waiting_user' ? 'btn-primary' : 'btn-ghost' ?>">⏳ Attente (<?= $stats['waiting_user'] ?>)</a>
    <a href="/support?status=resolved" class="btn <?= $filter_status === 'resolved' ? 'btn-primary' : 'btn-ghost' ?>">✅ Résolus (<?= $stats['resolved'] ?>)</a>
    <a href="/support?mine" class="btn <?= $filter_mine ? 'btn-primary' : 'btn-ghost' ?>">👤 Les miens</a>
  </div>

  <?php if (empty($tickets)): ?>
    <div class="sup-empty">
      <div style="font-size:40px; margin-bottom:10px;">💬</div>
      <h2 style="margin:0 0 6px; font-size:18px; font-weight:700;">Aucun ticket pour l'instant</h2>
      <p style="color:var(--ink-3); margin:0 0 18px;">Besoin d'aide ? Notre équipe est là pour vous accompagner.</p>
      <a href="/support/nouveau" class="btn btn-primary">+ Créer un premier ticket</a>
    </div>
  <?php else: ?>
    <div class="sup-list">
      <?php foreach ($tickets as $t):
        $prio_rgb = $t['priority'] === 'urgent' ? '239,68,68' : ($t['priority'] === 'high' ? '245,158,11' : ($t['priority'] === 'low' ? '16,185,129' : '161,161,170'));
        $prio_color = support_priority_color($t['priority']);
        $status_style = match($t['status']) {
            'open'         => 'background:var(--acc-light); color:var(--acc-dark);',
            'in_progress'  => 'background:#EEEDFE; color:#3C3489;',
            'waiting_user' => 'background:#FEF3C7; color:#854F0B;',
            'resolved'     => 'background:var(--acc-light); color:var(--acc-dark);',
            'closed'       => 'background:var(--bg-3); color:var(--ink-3);',
            default        => 'background:var(--bg-3); color:var(--ink-3);',
        };
      ?>
        <a href="/support/ticket/<?= (int) $t['id'] ?>" class="sup-ticket">
          <span class="sup-tk-glow" style="background:<?= h($prio_color) ?>;"></span>
          <div class="sup-tk-top">
            <span class="sup-tk-id">#<?= (int) $t['id'] ?></span>
            <span class="sup-tk-badge" style="background:rgba(<?= $prio_rgb ?>,0.14); color:<?= h($prio_color) ?>;"><?= support_priority_label($t['priority']) ?></span>
            <span class="sup-tk-cat"><?= support_category_label($t['category']) ?></span>
            <?php if ($t['nb_unread'] > 0): ?>
              <span class="sup-tk-unread"><?= $t['nb_unread'] ?> non lu<?= $t['nb_unread'] > 1 ? 's' : '' ?></span>
            <?php endif; ?>
            <span class="sup-tk-status" style="<?= $status_style ?>"><?= support_status_label($t['status']) ?></span>
          </div>
          <div class="sup-tk-title"><?= h($t['title']) ?></div>
          <div class="sup-tk-meta">
            Ouvert par <?= h($t['creator_first'] . ' ' . $t['creator_last']) ?>
            · <?= (int) $t['nb_messages'] ?> message<?= $t['nb_messages'] > 1 ? 's' : '' ?>
            <?php if ($t['last_message_at']): ?>
              · Dernière activité <?= date('d/m/Y H:i', strtotime($t['last_message_at'])) ?>
            <?php else: ?>
              · Créé <?= date('d/m/Y', strtotime($t['created_at'])) ?>
            <?php endif; ?>
          </div>
        </a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

</div>

<?php render_foot(); ?>
