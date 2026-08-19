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

<style>
/* ============================================================
   ADHÉRENTS 2.0 — surcouche premium Liquid Glass (maquette)
   ============================================================ */
.main{max-width:1200px}
.main .page-title{font-size:32px;font-weight:800;letter-spacing:-.03em;line-height:1;color:var(--ink)}
.main .page-sub{font-size:13.5px;color:var(--ink-2);margin-top:10px;display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.main .page-sub::before{content:"";width:6px;height:6px;border-radius:50%;flex:none;background:#10B981;box-shadow:0 0 0 4px var(--acc-light,rgba(5,150,105,.12))}

/* KPI en verre + liseré coloré */
.stats-bar{display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:18px}
.stats-bar .metric{position:relative;overflow:hidden;background:var(--glass);backdrop-filter:blur(22px) saturate(1.5);-webkit-backdrop-filter:blur(22px) saturate(1.5);border:1px solid var(--glass-border);border-radius:var(--radius-lg,18px);padding:16px 18px;box-shadow:var(--shadow-card);transition:transform .18s ease,box-shadow .18s ease}
.stats-bar .metric:hover{transform:translateY(-2px);box-shadow:var(--shadow-pop)}
.stats-bar .metric::before{content:"";position:absolute;inset:0 0 auto 0;height:3px;border-radius:3px 3px 0 0}
.stats-bar .metric:nth-child(1)::before{background:linear-gradient(90deg,#34D399,#059669)}
.stats-bar .metric:nth-child(2)::before{background:linear-gradient(90deg,#FBBF24,#E0850C)}
.stats-bar .metric:nth-child(3)::before{background:linear-gradient(90deg,#8B5CF6,#6366F1)}
.stats-bar .metric:nth-child(4)::before{background:linear-gradient(90deg,#60A5FA,#2F73E8)}
.stats-bar .metric-lbl{font-size:12px;color:var(--ink-3);font-weight:600}
.stats-bar .metric-val{font-size:32px;font-weight:800;letter-spacing:-.03em;line-height:1;margin-top:10px;color:var(--ink);font-variant-numeric:tabular-nums}
.stats-bar .metric-sub{font-size:11.5px;color:var(--ink-3);margin-top:8px}
.stats-bar .metric-sub.up{color:var(--acc,#059669);font-weight:600}

/* Recherche + filtres en verre */
.toolbar{display:flex;align-items:center;gap:12px;margin-bottom:16px;flex-wrap:wrap}
.toolbar .search-wrap{flex:1;min-width:220px;display:flex;align-items:center;gap:10px;padding:12px 15px;border-radius:var(--radius,12px);background:var(--glass);border:1px solid var(--glass-border);color:var(--ink-3);backdrop-filter:blur(12px);-webkit-backdrop-filter:blur(12px);box-shadow:var(--shadow-card)}
.toolbar .search-wrap svg{stroke:var(--ink-3);flex:none}
.toolbar .search-input{border:0;background:transparent;outline:none;font:inherit;font-size:13.5px;color:var(--ink);width:100%}
.toolbar .filter-chips{display:flex;gap:8px;flex-wrap:wrap}
.toolbar .filter-chips .chip{padding:9px 15px;border-radius:999px;font-size:12.5px;font-weight:600;text-decoration:none;background:var(--glass);border:1px solid var(--glass-border);color:var(--ink-2);backdrop-filter:blur(10px);-webkit-backdrop-filter:blur(10px)}
.toolbar .filter-chips .chip.active{background:var(--ink);border-color:transparent;color:var(--bg);box-shadow:var(--shadow-card)}

/* Liste en verre */
.list{background:var(--glass);backdrop-filter:blur(22px) saturate(1.5);-webkit-backdrop-filter:blur(22px) saturate(1.5);border:1px solid var(--glass-border);border-radius:var(--radius-lg,18px);box-shadow:var(--shadow-card);padding:6px;overflow:hidden}
.list .adh-row{border-radius:14px;transition:background .15s ease}
.list .adh-row:hover{background:var(--bg-2)}
.list .list-row-header{background:transparent}
.adh-avatar{border-radius:50%!important}
.adh-role-badge{font-size:11px;font-weight:700;padding:4px 11px;border-radius:999px}
.adh-role-badge.role-admin{background:rgba(224,133,12,.14);color:#B45309}
.adh-role-badge.role-coordinator{background:var(--ai-light,rgba(99,102,241,.12));color:var(--ai-dark,#4338CA)}
.adh-role-badge.role-referent{background:var(--acc-light,rgba(5,150,105,.12));color:var(--acc-dark,#047857)}
.adh-role-badge.role-member{background:rgba(47,115,232,.12);color:#2F73E8}
@media (prefers-color-scheme:dark){.adh-role-badge.role-admin{color:#F5B342}.adh-role-badge.role-member{color:#6AA1FF}}
@media (max-width:900px){.stats-bar{grid-template-columns:1fr 1fr}}
</style>

<div class="main">

  <nav class="crumbs">
    <a href="/dashboard">Dashboard</a>
    <span class="sep">›</span>
    <span class="current">Adhérents</span>
  </nav>

  <?php if ($flash): ?>
    <div class="alert <?= $flash['type'] === 'success' ? 'alert-success' : 'alert-error' ?>" style="margin-bottom: 18px;">
      <span><?= $flash['type'] === 'success' ? ak_icon('check-circle',16) : ak_icon('alert-tri',16) ?></span>
      <div><?= h($flash['message']) ?></div>
    </div>
  <?php endif; ?>

  <div class="main-head">
    <div>
      <h1 class="page-title">Vos adhérents</h1>
      <div class="page-sub">
        <?= (int)$s['total'] ?> membre<?= $s['total'] > 1 ? 's' : '' ?> · <?= (int)$s['new_30d'] ?> nouveau<?= $s['new_30d'] > 1 ? 'x' : '' ?> ce mois
        <?php if ($nb_trash > 0): ?>
          · <a href="/adherents-corbeille" style="color:var(--ink-3); text-decoration:underline; display:inline-flex; align-items:center; gap:5px;"><?= ak_icon('trash',13) ?> <?= $nb_trash ?> dans la corbeille</a>
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
