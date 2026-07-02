<?php
/**
 * ============================================================
 * ASSOKIT — Fiche Adhérent (détail) — v3
 * ============================================================
 * v3 : ajout bouton Supprimer (admin only) + filtre deleted_at
 * ============================================================
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes-layout.php';

require_login();

$current = current_user();
$org_id = (int)$current['org_id'];
$id = (int)($_GET['id'] ?? 0);

if ($id <= 0) {
    header('Location: /adherents');
    exit;
}

// Flash message
$flash = $_SESSION['flash_adherent'] ?? null;
unset($_SESSION['flash_adherent']);

// Chargement de l'adhérent (non supprimé)
$stmt = $pdo->prepare("
    SELECT id, email, first_name, last_name, role, phone, city, avatar_color,
           adhesion_date, adhesion_valid_until, is_active, created_at, last_login_at,
           can_create_projects, deleted_at
    FROM users
    WHERE id = ? AND org_id = ? AND deleted_at IS NULL
    LIMIT 1
");
$stmt->execute([$id, $org_id]);
$adherent = $stmt->fetch();

if (!$adherent) {
    render_head('Adhérent introuvable');
    render_sidebar('adherents');
    echo '<div class="main"><div class="empty-state" style="margin-top: 60px;">Adhérent introuvable ou hors de votre organisation.</div></div>';
    render_foot();
    exit;
}

// Projets dont il est référent
$stmt = $pdo->prepare("
    SELECT p.id, p.name, p.progress_percent, p.status, f.name AS folder_name, f.color_theme
    FROM projects p
    JOIN folders f ON p.folder_id = f.id
    WHERE p.referent_id = ?
    ORDER BY FIELD(p.status, 'warning', 'active', 'done', 'archived'), p.progress_percent ASC
");
$stmt->execute([$id]);
$ref_projects = $stmt->fetchAll();

$color_class = in_array($adherent['avatar_color'], ['blue','purple','amber','pink','teal'], true)
    ? 'av-' . $adherent['avatar_color'] : 'av-blue';

if (!function_exists('format_date_fr_long')) {
    function format_date_fr_long($date_str) {
        if (!$date_str) return '—';
        $months = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
        $t = strtotime($date_str);
        return (int)date('j', $t) . ' ' . $months[(int)date('n', $t) - 1] . ' ' . date('Y', $t);
    }
}

$is_admin_or_coord = ($current['role'] === 'admin' || $current['role'] === 'coordinator');
$can_edit = $is_admin_or_coord && $adherent['id'] !== $current['id'];

// Seul un ADMIN (pas coordinator) peut supprimer, et pas soi-meme
$can_delete = ($current['role'] === 'admin') && $adherent['id'] !== $current['id'];

render_head($adherent['first_name'] . ' ' . $adherent['last_name']);
render_sidebar('adherents');
?>

<div class="main">

  <nav class="crumbs">
    <a href="/dashboard">Dashboard</a>
    <span class="sep">›</span>
    <a href="/adherents">Adhérents</a>
    <span class="sep">›</span>
    <span class="current"><?= h($adherent['first_name'] . ' ' . $adherent['last_name']) ?></span>
  </nav>

  <?php if ($flash): ?>
    <div class="alert <?= $flash['type'] === 'success' ? 'alert-success' : 'alert-error' ?>" style="margin-bottom: 18px;">
      <span><?= $flash['type'] === 'success' ? '✅' : '⚠️' ?></span>
      <div><?= h($flash['message']) ?></div>
    </div>
  <?php endif; ?>

  <!-- En-tête fiche -->
  <div style="display: flex; gap: 20px; align-items: center; margin-bottom: 32px; flex-wrap: wrap;">
    <span class="adh-avatar <?= $color_class ?>" style="width: 72px; height: 72px; font-size: 22px;">
      <?= h(user_initials($adherent['first_name'], $adherent['last_name'])) ?>
    </span>
    <div style="flex: 1; min-width: 0;">
      <h1 class="page-title" style="margin-bottom: 6px;"><?= h($adherent['first_name'] . ' ' . $adherent['last_name']) ?></h1>
      <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
        <span class="adh-role-badge role-<?= h($adherent['role']) ?>"><?= h(role_label($adherent['role'])) ?></span>
        <?php if ($adherent['can_create_projects']): ?>
          <span class="adh-role-badge" style="background: var(--acc-light); color: var(--acc-dark);">Peut créer des projets</span>
        <?php endif; ?>
        <?php if (!$adherent['is_active']): ?>
          <span class="adh-role-badge status-rejected">Inactif</span>
        <?php endif; ?>
      </div>
    </div>
    <?php if ($can_edit || $can_delete): ?>
    <div class="head-actions" style="display:flex; gap:8px; flex-wrap:wrap; position:relative;">
      <?php if ($can_edit): ?>
      <a href="/modifier-adherent/<?= (int)$adherent['id'] ?>" class="btn btn-ghost">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
        Modifier
      </a>
      <!-- Bouton Message avec menu déroulant -->
      <div style="position:relative; display:inline-block;" id="msg-menu-wrap">
        <button type="button" class="btn btn-ghost" onclick="toggleMsgMenu(event)">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16v14H5.17L4 19.17z"/></svg>
          Message
          <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
        </button>
        <div id="msg-menu" style="display:none; position:absolute; right:0; top:calc(100% + 4px); background:var(--bg); border:1px solid var(--border); border-radius:10px; box-shadow:0 6px 20px rgba(0,0,0,0.08); min-width:200px; z-index:100; overflow:hidden;">
          <a href="/messages?user=<?= (int)$adherent['id'] ?>" style="display:flex; align-items:center; gap:10px; padding:10px 14px; text-decoration:none; color:var(--ink); font-size:13px; border-bottom:1px solid var(--border);" onmouseover="this.style.background='var(--bg-2)'" onmouseout="this.style.background=''">
            <span>💬</span>
            <div>
              <div style="font-weight:500;">Message interne</div>
              <div style="font-size:11px; color:var(--ink-3);">Dans Assokit</div>
            </div>
          </a>
          <a href="mailto:<?= h($adherent['email']) ?>?subject=<?= rawurlencode('Message de la part de ' . $current['first_name'] . ' ' . $current['last_name']) ?>" style="display:flex; align-items:center; gap:10px; padding:10px 14px; text-decoration:none; color:var(--ink); font-size:13px;" onmouseover="this.style.background='var(--bg-2)'" onmouseout="this.style.background=''">
            <span>📧</span>
            <div>
              <div style="font-weight:500;">Email direct</div>
              <div style="font-size:11px; color:var(--ink-3);">Ouvre votre app mail</div>
            </div>
          </a>
        </div>
      </div>
      <?php endif; ?>

      <?php if ($can_delete): ?>
      <a href="/supprimer-adherent/<?= (int)$adherent['id'] ?>" class="btn" style="background:#FEE2E2; color:#991B1B; border:1px solid #FECACA;">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
        Supprimer
      </a>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </div>

  <!-- Infos de contact -->
  <div class="section-head">
    <h2>Coordonnées</h2>
  </div>

  <div class="list" style="margin-bottom: 32px;">
    <div class="list-row" style="grid-template-columns: 140px 1fr; cursor: default;">
      <span style="font-size: 12px; color: var(--ink-3);">Email</span>
      <a href="mailto:<?= h($adherent['email']) ?>" style="color: var(--acc); font-size: 14px; font-weight: 500;"><?= h($adherent['email']) ?></a>
    </div>
    <?php if ($adherent['phone']): ?>
    <div class="list-row" style="grid-template-columns: 140px 1fr; cursor: default;">
      <span style="font-size: 12px; color: var(--ink-3);">Téléphone</span>
      <a href="tel:<?= h(preg_replace('/\s+/', '', $adherent['phone'])) ?>" style="color: var(--acc); font-size: 14px; font-weight: 500;"><?= h($adherent['phone']) ?></a>
    </div>
    <?php endif; ?>
    <?php if ($adherent['city']): ?>
    <div class="list-row" style="grid-template-columns: 140px 1fr; cursor: default;">
      <span style="font-size: 12px; color: var(--ink-3);">Ville</span>
      <span style="font-size: 14px;"><?= h($adherent['city']) ?></span>
    </div>
    <?php endif; ?>
  </div>

  <!-- Adhésion -->
  <div class="section-head">
    <h2>Adhésion</h2>
  </div>

  <div class="stats-bar" style="margin-bottom: 32px;">
    <div class="metric">
      <div class="metric-lbl">Adhérent depuis</div>
      <div class="metric-val" style="font-size: 18px;"><?= h(format_date_fr_long($adherent['adhesion_date'])) ?></div>
    </div>
    <div class="metric">
      <div class="metric-lbl">Valide jusqu'au</div>
      <div class="metric-val" style="font-size: 18px;"><?= h(format_date_fr_long($adherent['adhesion_valid_until'])) ?></div>
    </div>
    <div class="metric">
      <div class="metric-lbl">Dernière connexion</div>
      <div class="metric-val" style="font-size: 18px;">
        <?= $adherent['last_login_at'] ? h(format_date_fr_long($adherent['last_login_at'])) : 'Jamais' ?>
      </div>
    </div>
  </div>

  <!-- Projets dont il est référent -->
  <?php if (!empty($ref_projects)): ?>
  <div class="section-head">
    <h2>Projets dont <?= h($adherent['first_name']) ?> est référent</h2>
    <div class="section-head-meta"><?= count($ref_projects) ?> projet<?= count($ref_projects) > 1 ? 's' : '' ?></div>
  </div>

  <div class="my-proj-grid" style="margin-bottom: 32px;">
    <?php foreach ($ref_projects as $p): ?>
    <a href="/projet/<?= (int)$p['id'] ?>" class="mp-card">
      <div class="mp-head">
        <div class="mp-title-wrap">
          <div class="mp-folder-tag"><?= h($p['folder_name']) ?></div>
          <div class="mp-title"><?= h($p['name']) ?></div>
        </div>
        <?php
          $status_class = $p['status'] === 'warning' ? 'badge-warn' : ($p['status'] === 'done' ? 'badge-done' : 'badge-ok');
          $status_label = ['warning' => 'À surveiller', 'done' => 'Terminé', 'active' => 'En cours', 'archived' => 'Archivé'][$p['status']] ?? 'En cours';
        ?>
        <span class="project-badge <?= $status_class ?>"><?= h($status_label) ?></span>
      </div>
      <div class="mp-progress">
        <div class="mp-bar-bg"><div class="mp-bar" style="width:<?= (int)$p['progress_percent'] ?>%"></div></div>
        <span class="mp-pct"><?= (int)$p['progress_percent'] ?> %</span>
      </div>
    </a>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

</div>

<script>
function toggleMsgMenu(e) {
  e.stopPropagation();
  var menu = document.getElementById('msg-menu');
  if (menu) { menu.style.display = menu.style.display === 'none' ? 'block' : 'none'; }
}
document.addEventListener('click', function(e) {
  var wrap = document.getElementById('msg-menu-wrap');
  var menu = document.getElementById('msg-menu');
  if (wrap && menu && !wrap.contains(e.target)) { menu.style.display = 'none'; }
});
</script>

<?php render_foot(); ?>
