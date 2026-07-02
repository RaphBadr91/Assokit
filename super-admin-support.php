<?php
/**
 * super-admin-support.php — Liste des tickets cote COCKPIT
 * ============================================================
 * Pool commun : tous les SA+Fondateur voient tous les tickets.
 * Filtres : Tous / Non assignes / Mes tickets / Par statut / Par priorite.
 * Action : cliquer sur un ticket ouvre la fiche de gestion.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes-layout.php';
require_once __DIR__ . '/superadmin-layout.php';
require_once __DIR__ . '/sa-permissions.php';
require_once __DIR__ . '/support-helper.php';
require_login();
$user = sa_require_super_admin();
$ctx = sa_get_permissions_context();

// Filtres
$filter_view = $_GET['view'] ?? 'open';  // open | unassigned | mine | all | resolved
$filter_priority = $_GET['priority'] ?? '';
$filter_category = $_GET['category'] ?? '';
$search = trim($_GET['q'] ?? '');

$where = [];
$params = [];

switch ($filter_view) {
    case 'unassigned':
        $where[] = "t.assigned_to_user_id IS NULL";
        $where[] = "t.status IN ('open','in_progress','waiting_user')";
        break;
    case 'mine':
        $where[] = "t.assigned_to_user_id = ?";
        $params[] = (int) $user['id'];
        break;
    case 'open':
        $where[] = "t.status IN ('open','in_progress','waiting_user')";
        break;
    case 'resolved':
        $where[] = "t.status IN ('resolved','closed')";
        break;
    case 'all':
        // pas de filtre
        break;
}

if ($filter_priority !== '') {
    $where[] = "t.priority = ?";
    $params[] = $filter_priority;
}
if ($filter_category !== '') {
    $where[] = "t.category = ?";
    $params[] = $filter_category;
}
if ($search !== '') {
    $where[] = "(t.title LIKE ? OR o.name LIKE ?)";
    $params[] = '%' . $search . '%';
    $params[] = '%' . $search . '%';
}

$where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$stmt = $pdo->prepare("
    SELECT
        t.*,
        o.name AS org_name,
        creator.first_name AS creator_first, creator.last_name AS creator_last,
        assigned.first_name AS assigned_first, assigned.last_name AS assigned_last,
        (SELECT COUNT(*) FROM support_messages m WHERE m.ticket_id = t.id AND m.is_internal_note = 0) AS nb_messages,
        (SELECT COUNT(*) FROM support_messages m WHERE m.ticket_id = t.id AND m.is_internal_note = 0 AND m.author_side = 'org' AND m.read_by_support = 0) AS nb_unread
    FROM support_tickets t
    JOIN organizations o ON t.org_id = o.id
    LEFT JOIN users creator ON t.created_by_user_id = creator.id
    LEFT JOIN users assigned ON t.assigned_to_user_id = assigned.id
    $where_sql
    ORDER BY
        CASE WHEN t.priority = 'urgent' THEN 0 WHEN t.priority = 'high' THEN 1 WHEN t.priority = 'normal' THEN 2 ELSE 3 END,
        t.last_message_at DESC, t.created_at DESC
    LIMIT 200
");
$stmt->execute($params);
$tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Stats pour les onglets
$counts = ['open' => 0, 'unassigned' => 0, 'mine' => 0, 'all' => 0, 'resolved' => 0];
try {
    $row = $pdo->query("
        SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN status IN ('open','in_progress','waiting_user') THEN 1 ELSE 0 END) AS open_count,
            SUM(CASE WHEN assigned_to_user_id IS NULL AND status IN ('open','in_progress','waiting_user') THEN 1 ELSE 0 END) AS unassigned_count,
            SUM(CASE WHEN status IN ('resolved','closed') THEN 1 ELSE 0 END) AS resolved_count
        FROM support_tickets
    ")->fetch(PDO::FETCH_ASSOC);
    $counts['all'] = (int) $row['total'];
    $counts['open'] = (int) $row['open_count'];
    $counts['unassigned'] = (int) $row['unassigned_count'];
    $counts['resolved'] = (int) $row['resolved_count'];

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM support_tickets WHERE assigned_to_user_id = ? AND status IN ('open','in_progress','waiting_user')");
    $stmt->execute([(int) $user['id']]);
    $counts['mine'] = (int) $stmt->fetchColumn();
} catch (Throwable $e) {}

sa_render_head('Support');
sa_render_sidebar('support');
?>

<div class="sa-page-head">
  <div>
    <h1 class="sa-page-title">💬 Support tickets</h1>
    <div class="sa-page-sub">
      <?= $counts['all'] ?> ticket<?= $counts['all'] > 1 ? 's' : '' ?> au total
      <?php if ($counts['unassigned'] > 0): ?>
        · <strong style="color:#FCA5A5"><?= $counts['unassigned'] ?> non assigné<?= $counts['unassigned'] > 1 ? 's' : '' ?></strong>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- Onglets -->
<div style="display:flex; gap:6px; margin-bottom:14px; flex-wrap:wrap;">
  <a href="/super-admin/support?view=open" class="sa-btn <?= $filter_view === 'open' ? 'sa-btn-violet' : 'sa-btn-ghost' ?> sa-btn-sm">
    🔥 Ouverts (<?= $counts['open'] ?>)
  </a>
  <a href="/super-admin/support?view=unassigned" class="sa-btn <?= $filter_view === 'unassigned' ? 'sa-btn-violet' : 'sa-btn-ghost' ?> sa-btn-sm"
     style="<?= $filter_view !== 'unassigned' && $counts['unassigned'] > 0 ? 'border-color:rgba(239,68,68,0.3); color:#FCA5A5;' : '' ?>">
    👥 Pool non assignés (<?= $counts['unassigned'] ?>)
  </a>
  <a href="/super-admin/support?view=mine" class="sa-btn <?= $filter_view === 'mine' ? 'sa-btn-violet' : 'sa-btn-ghost' ?> sa-btn-sm">
    👤 Mes tickets (<?= $counts['mine'] ?>)
  </a>
  <a href="/super-admin/support?view=resolved" class="sa-btn <?= $filter_view === 'resolved' ? 'sa-btn-violet' : 'sa-btn-ghost' ?> sa-btn-sm">
    ✅ Résolus (<?= $counts['resolved'] ?>)
  </a>
  <a href="/super-admin/support?view=all" class="sa-btn <?= $filter_view === 'all' ? 'sa-btn-violet' : 'sa-btn-ghost' ?> sa-btn-sm">
    📋 Tous (<?= $counts['all'] ?>)
  </a>
</div>

<!-- Filtres secondaires -->
<form method="GET" style="display:flex; gap:10px; margin-bottom:16px; flex-wrap:wrap;">
  <input type="hidden" name="view" value="<?= h($filter_view) ?>">
  <input type="text" name="q" value="<?= h($search) ?>" placeholder="🔍 Rechercher (titre ou asso)..."
         style="flex:1; min-width:220px; padding:8px 12px; background:var(--sa-bg-2); border:1px solid var(--sa-border-strong); border-radius:8px; color:var(--sa-ink); font-family:inherit;">
  <select name="priority" style="padding:8px 12px; background:var(--sa-bg-2); border:1px solid var(--sa-border-strong); border-radius:8px; color:var(--sa-ink); font-family:inherit;">
    <option value="">Toutes priorités</option>
    <option value="urgent" <?= $filter_priority === 'urgent' ? 'selected' : '' ?>>🔴 Urgente</option>
    <option value="high" <?= $filter_priority === 'high' ? 'selected' : '' ?>>🟠 Haute</option>
    <option value="normal" <?= $filter_priority === 'normal' ? 'selected' : '' ?>>⚪ Normale</option>
    <option value="low" <?= $filter_priority === 'low' ? 'selected' : '' ?>>🟢 Basse</option>
  </select>
  <select name="category" style="padding:8px 12px; background:var(--sa-bg-2); border:1px solid var(--sa-border-strong); border-radius:8px; color:var(--sa-ink); font-family:inherit;">
    <option value="">Toutes catégories</option>
    <option value="question" <?= $filter_category === 'question' ? 'selected' : '' ?>>❓ Question</option>
    <option value="bug" <?= $filter_category === 'bug' ? 'selected' : '' ?>>🐛 Bug</option>
    <option value="feature_request" <?= $filter_category === 'feature_request' ? 'selected' : '' ?>>💡 Feature</option>
    <option value="billing" <?= $filter_category === 'billing' ? 'selected' : '' ?>>💳 Facturation</option>
    <option value="account" <?= $filter_category === 'account' ? 'selected' : '' ?>>👤 Compte</option>
  </select>
  <button type="submit" class="sa-btn sa-btn-ghost">Filtrer</button>
</form>

<?php if (empty($tickets)): ?>
  <div class="sa-card">
    <div class="sa-empty">
      <div class="sa-empty-icon">💬</div>
      <div class="sa-empty-title">Aucun ticket trouvé</div>
      <div>Pas de ticket correspondant à ces filtres.</div>
    </div>
  </div>
<?php else: ?>
  <div class="sa-table-wrap">
    <table class="sa-table">
      <thead>
        <tr>
          <th>Ticket</th>
          <th>Asso</th>
          <th>Priorité</th>
          <th>Statut</th>
          <th>Assigné à</th>
          <th>Dernière activité</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($tickets as $t): ?>
          <tr style="cursor:pointer; <?= $t['nb_unread'] > 0 ? 'background:rgba(127,119,221,0.04);' : '' ?>"
              onclick="window.location='/super-admin/support/<?= (int) $t['id'] ?>'">
            <td>
              <div class="sa-main-col">
                #<?= (int) $t['id'] ?> — <?= h($t['title']) ?>
                <?php if ($t['nb_unread'] > 0): ?>
                  <span style="display:inline-block; margin-left:6px; background:#EF4444; color:white; font-size:10px; padding:1px 6px; border-radius:999px; font-weight:600;"><?= $t['nb_unread'] ?></span>
                <?php endif; ?>
              </div>
              <div class="sa-sub-col">
                <?= support_category_label($t['category']) ?>
                · Ouvert par <?= h($t['creator_first'] . ' ' . $t['creator_last']) ?>
                · <?= (int) $t['nb_messages'] ?> msg
              </div>
            </td>
            <td><?= h($t['org_name']) ?></td>
            <td>
              <span class="sa-badge" style="background:rgba(<?= $t['priority'] === 'urgent' ? '239,68,68' : ($t['priority'] === 'high' ? '245,158,11' : ($t['priority'] === 'low' ? '16,185,129' : '161,161,170')) ?>, 0.18); color:<?= support_priority_color($t['priority']) ?>;">
                <?= support_priority_label($t['priority']) ?>
              </span>
            </td>
            <td>
              <span class="sa-badge <?= support_status_badge_class($t['status']) ?>">
                <?= support_status_label($t['status']) ?>
              </span>
            </td>
            <td style="font-size:12.5px;">
              <?php if ($t['assigned_first']): ?>
                <?= h($t['assigned_first'] . ' ' . $t['assigned_last']) ?>
              <?php else: ?>
                <span style="color:var(--sa-ink-4)">— pool —</span>
              <?php endif; ?>
            </td>
            <td style="color:var(--sa-ink-3); font-size:12.5px;">
              <?= $t['last_message_at'] ? date('d/m H:i', strtotime($t['last_message_at'])) : '—' ?>
              <?php if ($t['last_message_by'] === 'org'): ?>
                <div style="font-size:10px; color:#FCA5A5;">↙ asso</div>
              <?php elseif ($t['last_message_by'] === 'support'): ?>
                <div style="font-size:10px; color:#6EE7B7;">↗ support</div>
              <?php endif; ?>
            </td>
            <td onclick="event.stopPropagation()">
              <a href="/super-admin/support/<?= (int) $t['id'] ?>" class="sa-btn sa-btn-ghost sa-btn-sm">Ouvrir →</a>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>

<?php sa_render_foot(); ?>
