<?php
/**
 * ============================================================
 * ASSOKIT — Page Adhérents (v3 avec soft delete)
 * ============================================================
 * v3 :
 *   - Filtre deleted_at IS NULL partout
 *   - Lien "Corbeille" vers /adherents-corbeille (admin only)
 * ============================================================
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes-layout.php';

require_login();

$user = current_user();
$org_id = (int)$user['org_id'];

// ====== Filtres depuis l'URL ======
$search = trim($_GET['q'] ?? '');
$filter_role = $_GET['role'] ?? 'all';
$valid_roles = ['all', 'admin', 'coordinator', 'referent', 'member'];
if (!in_array($filter_role, $valid_roles, true)) {
    $filter_role = 'all';
}

// ====== Flash message ======
$flash = $_SESSION['flash_adherents'] ?? null;
unset($_SESSION['flash_adherents']);

// ====== Chargement des adhérents actifs (non supprimés) ======
$sql = "
    SELECT u.id, u.email, u.first_name, u.last_name, u.role, u.phone, u.city,
           u.avatar_color, u.adhesion_date, u.adhesion_valid_until, u.is_active,
           (SELECT COUNT(*) FROM projects WHERE referent_id = u.id AND status IN ('active','warning')) AS active_projects_count
    FROM users u
    WHERE u.org_id = :org_id AND u.deleted_at IS NULL
";
$params = [':org_id' => $org_id];

if ($search !== '') {
    $sql .= " AND (u.first_name LIKE :q OR u.last_name LIKE :q OR u.email LIKE :q OR u.city LIKE :q)";
    $params[':q'] = '%' . $search . '%';
}
if ($filter_role !== 'all') {
    $sql .= " AND u.role = :role";
    $params[':role'] = $filter_role;
}
$sql .= " ORDER BY
    CASE u.role
        WHEN 'admin' THEN 1
        WHEN 'coordinator' THEN 2
        WHEN 'referent' THEN 3
        ELSE 4
    END,
    u.last_name ASC, u.first_name ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$adherents = $stmt->fetchAll();

// ====== Statistiques globales (non supprimés) ======
$stats = $pdo->prepare("
    SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN role = 'admin' THEN 1 ELSE 0 END) AS admins,
        SUM(CASE WHEN role = 'coordinator' THEN 1 ELSE 0 END) AS coordinators,
        SUM(CASE WHEN role = 'referent' THEN 1 ELSE 0 END) AS referents,
        SUM(CASE WHEN role = 'member' THEN 1 ELSE 0 END) AS members,
        SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) AS new_30d
    FROM users WHERE org_id = ? AND deleted_at IS NULL
");
$stats->execute([$org_id]);
$s = $stats->fetch();

// Nb dans la corbeille (admin only)
$nb_trash = 0;
if ($user['role'] === 'admin') {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE org_id = ? AND deleted_at IS NOT NULL AND deleted_at > DATE_SUB(NOW(), INTERVAL 30 DAY)");
    $stmt->execute([$org_id]);
    $nb_trash = (int) $stmt->fetchColumn();
}

$can_invite = ($user['role'] === 'admin' || $user['role'] === 'coordinator');

if (!function_exists('format_date_fr')) {
    function format_date_fr($date_str) {
        if (!$date_str) return '—';
        $months = ['janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'];
        $t = strtotime($date_str);
        return (int)date('j', $t) . ' ' . $months[(int)date('n', $t) - 1] . ' ' . date('Y', $t);
    }
}

render_head('Adhérents');
render_sidebar('adherents');
?>

<div class="main">

  <nav class="crumbs">
    <a href="/dashboard">Dashboard</a>
    <span class="sep">›</span>
    <span class="current">Adhérents</span>
  </nav>

  <?php if ($flash): ?>
    <div class="alert <?= $flash['type'] === 'success' ? 'alert-success' : 'alert-error' ?>" style="margin-bottom: 18px;">
      <span><?= $flash['type'] === 'success' ? '✅' : '⚠️' ?></span>
      <div><?= h($flash['message']) ?></div>
    </div>
  <?php endif; ?>

  <div class="main-head">
    <div>
      <h1 class="page-title">Vos adhérents</h1>
      <div class="page-sub">
        <?= (int)$s['total'] ?> membre<?= $s['total'] > 1 ? 's' : '' ?> · <?= (int)$s['new_30d'] ?> nouveau<?= $s['new_30d'] > 1 ? 'x' : '' ?> ce mois
        <?php if ($nb_trash > 0): ?>
          · <a href="/adherents-corbeille" style="color:var(--ink-3); text-decoration:underline;">🗑️ <?= $nb_trash ?> dans la corbeille</a>
        <?php endif; ?>
      </div>
    </div>
    <?php if ($can_invite): ?>
    <div class="head-actions" style="display:flex; gap:8px; flex-wrap:wrap;">
      <a href="/adherents-export<?= $search ? '?q=' . urlencode($search) : '' ?><?= $filter_role !== 'all' ? ($search ? '&' : '?') . 'role=' . urlencode($filter_role) : '' ?>" class="btn btn-ghost">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
        Exporter CSV
      </a>
      <a href="/adherents-import" class="btn btn-ghost">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
        Importer CSV
      </a>
      <a href="/nouveau-adherent" class="btn btn-primary">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
        Ajouter un adhérent
      </a>
    </div>
    <?php endif; ?>
  </div>

  <!-- Statistiques en haut -->
  <section class="stats-bar">
    <div class="metric">
      <div class="metric-lbl">Total</div>
      <div class="metric-val"><?= (int)$s['total'] ?></div>
      <div class="metric-sub up">+<?= (int)$s['new_30d'] ?> ce mois</div>
    </div>
    <div class="metric">
      <div class="metric-lbl">Coordinateurs</div>
      <div class="metric-val"><?= (int)$s['coordinators'] ?></div>
      <div class="metric-sub">équipe d'encadrement</div>
    </div>
    <div class="metric">
      <div class="metric-lbl">Référents</div>
      <div class="metric-val"><?= (int)$s['referents'] ?></div>
      <div class="metric-sub">responsables projet</div>
    </div>
    <div class="metric">
      <div class="metric-lbl">Membres</div>
      <div class="metric-val"><?= (int)$s['members'] ?></div>
      <div class="metric-sub">bénévoles actifs</div>
    </div>
  </section>

  <!-- Recherche + filtres -->
  <form method="GET" action="/adherents" class="toolbar">
    <div class="search-wrap">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
      <input type="search" name="q" class="search-input" placeholder="Rechercher par nom, email, ville…" value="<?= h($search) ?>">
    </div>
    <div class="filter-chips">
      <a href="/adherents<?= $search ? '?q=' . urlencode($search) : '' ?>" class="chip <?= $filter_role === 'all' ? 'active' : '' ?>">Tous</a>
      <a href="/adherents?role=admin<?= $search ? '&q=' . urlencode($search) : '' ?>" class="chip <?= $filter_role === 'admin' ? 'active' : '' ?>">Admins</a>
      <a href="/adherents?role=coordinator<?= $search ? '&q=' . urlencode($search) : '' ?>" class="chip <?= $filter_role === 'coordinator' ? 'active' : '' ?>">Coordinateurs</a>
      <a href="/adherents?role=referent<?= $search ? '&q=' . urlencode($search) : '' ?>" class="chip <?= $filter_role === 'referent' ? 'active' : '' ?>">Référents</a>
      <a href="/adherents?role=member<?= $search ? '&q=' . urlencode($search) : '' ?>" class="chip <?= $filter_role === 'member' ? 'active' : '' ?>">Membres</a>
    </div>
  </form>

  <!-- Liste des adhérents -->
  <?php if (empty($adherents)): ?>
    <div class="list">
      <div class="empty-state">
        <?php if ($search || $filter_role !== 'all'): ?>
          Aucun adhérent ne correspond à votre recherche.
        <?php else: ?>
          Aucun adhérent pour l'instant.
          <?php if ($can_invite): ?>
            <br><br>
            <a href="/nouveau-adherent" class="btn btn-primary" style="margin-top:10px;">+ Ajouter le premier adhérent</a>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    </div>
  <?php else: ?>
    <div class="list">
      <div class="list-row list-row-header adh-row-header">
        <span></span>
        <span>Nom / email</span>
        <span class="adh-city">Ville</span>
        <span>Rôle</span>
        <span class="adh-adh-date">Adhésion</span>
      </div>
      <?php foreach ($adherents as $a):
        $color_class = in_array($a['avatar_color'], ['blue','purple','amber','pink','teal'], true)
            ? 'av-' . $a['avatar_color'] : 'av-blue';
      ?>
      <a href="/adherent?id=<?= (int)$a['id'] ?>" class="list-row adh-row">
        <span class="adh-avatar <?= $color_class ?>"><?= h(user_initials($a['first_name'], $a['last_name'])) ?></span>
        <div class="adh-main">
          <div class="adh-name"><?= h($a['first_name'] . ' ' . $a['last_name']) ?></div>
          <div class="adh-email"><?= h($a['email']) ?></div>
        </div>
        <span class="adh-city"><?= h($a['city'] ?: '—') ?></span>
        <span class="adh-role-badge role-<?= h($a['role']) ?>"><?= h(role_label($a['role'])) ?></span>
        <span class="adh-adh-date"><?= h(format_date_fr($a['adhesion_date'])) ?></span>
      </a>
      <?php endforeach; ?>
    </div>

    <?php if (count($adherents) > 5): ?>
    <div style="margin-top: 14px; font-size: 12px; color: var(--ink-4); text-align: center;">
      <?= count($adherents) ?> adhérent<?= count($adherents) > 1 ? 's' : '' ?> affiché<?= count($adherents) > 1 ? 's' : '' ?>
    </div>
    <?php endif; ?>
  <?php endif; ?>

</div>

<?php render_foot(); ?>
