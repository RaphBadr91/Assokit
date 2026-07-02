<?php
/**
 * ============================================================
 * ASSOKIT — Section /archives
 * ============================================================
 * Consultation des dossiers et projets archivés (admin only).
 * Possibilite de restaurer dans la periode de retention (30j).
 * Affichage du countdown avant purge definitive.
 * ============================================================
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes-layout.php';
require_once __DIR__ . '/archive-helper.php';

require_login();

$user = current_user();
$org_id = (int)$user['org_id'];

// Admin uniquement
if ($user['role'] !== 'admin') {
    http_response_code(403);
    die('Accès refusé — section réservée aux administrateurs.');
}

// Traitement flash
$flash = null;
if (!empty($_SESSION['flash_archives'])) {
    $flash = $_SESSION['flash_archives'];
    unset($_SESSION['flash_archives']);
}

// ====== CHARGEMENT DES DONNÉES ======

// Dossiers archivés
$stmt = $pdo->prepare("
    SELECT
        f.id, f.name, f.color_theme, f.archived_at, f.archived_with_active_projects,
        u.first_name AS admin_first, u.last_name AS admin_last,
        (SELECT COUNT(*) FROM projects WHERE folder_id = f.id AND archived_at IS NOT NULL) AS nb_projects,
        DATEDIFF(DATE_ADD(f.archived_at, INTERVAL ? DAY), NOW()) AS days_left
    FROM folders f
    LEFT JOIN users u ON f.archived_by_user_id = u.id
    WHERE f.org_id = ? AND f.archived_at IS NOT NULL
    ORDER BY f.archived_at DESC
");
$stmt->execute([ARCHIVE_RETENTION_DAYS, $org_id]);
$archived_folders = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

// Projets archivés individuellement (pas via un dossier)
$stmt = $pdo->prepare("
    SELECT
        p.id, p.name, p.progress_percent, p.status, p.archived_at,
        p.archived_via_folder, f.name AS folder_name, f.archived_at AS folder_archived,
        u.first_name AS admin_first, u.last_name AS admin_last,
        DATEDIFF(DATE_ADD(p.archived_at, INTERVAL ? DAY), NOW()) AS days_left
    FROM projects p
    JOIN folders f ON p.folder_id = f.id
    LEFT JOIN users u ON p.archived_by_user_id = u.id
    WHERE f.org_id = ? AND p.archived_at IS NOT NULL AND p.archived_via_folder = 0
    ORDER BY p.archived_at DESC
");
$stmt->execute([ARCHIVE_RETENTION_DAYS, $org_id]);
$archived_projects_solo = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

// Logs récents (20 derniers)
$stmt = $pdo->prepare("
    SELECT action, target_type, target_id, target_name, admin_name, details, created_at
    FROM archive_logs
    WHERE org_id = ?
    ORDER BY created_at DESC
    LIMIT 20
");
$stmt->execute([$org_id]);
$recent_logs = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

render_head('Archives');
render_sidebar('archives');

// Fonction helper pour le countdown
function format_days_left(int $days): array
{
    if ($days <= 0) return ['color' => '#DC2626', 'bg' => '#FEF2F2', 'text' => 'Purge imminente'];
    if ($days <= 1) return ['color' => '#DC2626', 'bg' => '#FEF2F2', 'text' => "J-{$days}"];
    if ($days <= 7) return ['color' => '#DC2626', 'bg' => '#FEF2F2', 'text' => "J-{$days}"];
    if ($days <= 14) return ['color' => '#B45309', 'bg' => '#FFFBEB', 'text' => "J-{$days}"];
    return ['color' => '#166534', 'bg' => '#F0FDF4', 'text' => "J-{$days}"];
}
?>

<div class="main">

  <nav class="crumbs">
    <a href="/dashboard">Dashboard</a>
    <span class="sep">›</span>
    <span class="current">Archives</span>
  </nav>

  <div class="main-head">
    <div>
      <h1 class="page-title">📦 Archives</h1>
      <div class="page-sub">
        Dossiers et projets archivés. Restauration possible pendant <?= ARCHIVE_RETENTION_DAYS ?> jours,
        ensuite purge définitive automatique.
      </div>
    </div>
  </div>

  <?php if ($flash): ?>
    <div class="alert alert-<?= $flash['type'] === 'error' ? 'error' : 'success' ?>">
      <span><?= $flash['type'] === 'error' ? '⚠️' : '✅' ?></span>
      <div><?= h($flash['message']) ?></div>
    </div>
  <?php endif; ?>

  <!-- Stats -->
  <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap:12px; margin-bottom:20px;">
    <div class="metric">
      <div class="metric-lbl">Dossiers archivés</div>
      <div class="metric-val"><?= count($archived_folders) ?></div>
      <div class="metric-sub">Au total</div>
    </div>
    <div class="metric">
      <div class="metric-lbl">Projets archivés</div>
      <div class="metric-val"><?= array_sum(array_column($archived_folders, 'nb_projects')) + count($archived_projects_solo) ?></div>
      <div class="metric-sub">Dans les dossiers + seuls</div>
    </div>
    <div class="metric">
      <div class="metric-lbl">Purge imminente</div>
      <div class="metric-val" style="color:#DC2626;">
        <?= count(array_filter($archived_folders, fn($f) => (int)$f['days_left'] <= 7)) ?>
      </div>
      <div class="metric-sub">Dans les 7 prochains jours</div>
    </div>
  </div>

  <!-- Section Dossiers archivés -->
  <?php if (!empty($archived_folders)): ?>
  <div class="section-head">
    <h2>Dossiers archivés</h2>
  </div>

  <div style="display:flex; flex-direction:column; gap:10px; margin-bottom:24px;">
    <?php foreach ($archived_folders as $f):
      $days_info = format_days_left((int)$f['days_left']);
      $admin_name = trim(($f['admin_first'] ?? '') . ' ' . ($f['admin_last'] ?? ''));
    ?>
      <div style="background:var(--bg); border:1px solid var(--border); border-radius:12px; padding:16px 18px; display:flex; align-items:center; gap:14px; flex-wrap:wrap;">

        <div style="width:44px; height:44px; background:var(--bg-2); border-radius:10px; display:flex; align-items:center; justify-content:center; font-size:20px; flex-shrink:0;">📁</div>

        <div style="flex:1; min-width:200px;">
          <div style="font-size:15px; font-weight:500; margin-bottom:4px;"><?= h($f['name']) ?></div>
          <div style="font-size:12px; color:var(--ink-3); display:flex; gap:10px; flex-wrap:wrap;">
            <span>📅 Archivé <?= date('d/m/Y à H:i', strtotime($f['archived_at'])) ?></span>
            <?php if ($admin_name): ?><span>👤 par <?= h($admin_name) ?></span><?php endif; ?>
            <?php if ((int)$f['nb_projects'] > 0): ?>
              <span>📊 <?= (int)$f['nb_projects'] ?> projet<?= $f['nb_projects'] > 1 ? 's' : '' ?></span>
            <?php endif; ?>
            <?php if (!empty($f['archived_with_active_projects'])): ?>
              <span style="color:#B45309;">⚠️ Avait des projets actifs</span>
            <?php endif; ?>
          </div>
        </div>

        <span style="padding:5px 10px; border-radius:999px; font-size:11.5px; font-weight:500; background:<?= $days_info['bg'] ?>; color:<?= $days_info['color'] ?>;">
          <?= $days_info['text'] ?> avant purge
        </span>

        <?php if ((int)$f['days_left'] > 0): ?>
        <form method="POST" action="/archives-restaurer" style="margin:0;"
              onsubmit="return confirm('Restaurer le dossier « <?= h($f['name']) ?> » ? Cela réactivera aussi ses <?= (int)$f['nb_projects'] ?> projet(s) archivés avec lui.');">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
          <input type="hidden" name="type" value="folder">
          <input type="hidden" name="id" value="<?= (int)$f['id'] ?>">
          <button type="submit" class="btn btn-primary" style="padding:8px 14px; font-size:12.5px;">
            ♻️ Restaurer
          </button>
        </form>
        <?php endif; ?>

      </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <!-- Section Projets archivés (hors dossiers) -->
  <?php if (!empty($archived_projects_solo)): ?>
  <div class="section-head">
    <h2>Projets archivés individuellement</h2>
  </div>

  <div style="display:flex; flex-direction:column; gap:10px; margin-bottom:24px;">
    <?php foreach ($archived_projects_solo as $p):
      $days_info = format_days_left((int)$p['days_left']);
      $admin_name = trim(($p['admin_first'] ?? '') . ' ' . ($p['admin_last'] ?? ''));
    ?>
      <div style="background:var(--bg); border:1px solid var(--border); border-radius:12px; padding:14px 18px; display:flex; align-items:center; gap:14px; flex-wrap:wrap;">
        <div style="width:36px; height:36px; background:var(--bg-2); border-radius:9px; display:flex; align-items:center; justify-content:center; font-size:16px; flex-shrink:0;">📊</div>

        <div style="flex:1; min-width:200px;">
          <div style="font-size:14px; font-weight:500; margin-bottom:3px;"><?= h($p['name']) ?></div>
          <div style="font-size:11.5px; color:var(--ink-3);">
            📁 <?= h($p['folder_name']) ?>
            · Archivé <?= date('d/m/Y', strtotime($p['archived_at'])) ?>
            <?php if ($admin_name): ?>· par <?= h($admin_name) ?><?php endif; ?>
          </div>
        </div>

        <span style="padding:4px 9px; border-radius:999px; font-size:11px; font-weight:500; background:<?= $days_info['bg'] ?>; color:<?= $days_info['color'] ?>;">
          <?= $days_info['text'] ?>
        </span>

        <?php if ((int)$p['days_left'] > 0 && empty($p['folder_archived'])): ?>
        <form method="POST" action="/archives-restaurer" style="margin:0;"
              onsubmit="return confirm('Restaurer ce projet ?');">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
          <input type="hidden" name="type" value="project">
          <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
          <button type="submit" class="btn btn-ghost" style="padding:6px 12px; font-size:12px;">
            ♻️ Restaurer
          </button>
        </form>
        <?php endif; ?>

      </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <!-- Section Logs récents -->
  <div class="section-head">
    <h2>Historique des actions</h2>
    <div class="section-head-meta">20 dernières actions</div>
  </div>

  <?php if (empty($recent_logs)): ?>
    <div class="empty-state">
      <div style="font-size:40px; margin-bottom:10px;">📭</div>
      <div style="font-size:14px; color:var(--ink-2); margin-bottom:4px;">Aucune action d'archivage</div>
      <div style="font-size:12.5px; color:var(--ink-3);">L'historique apparaîtra ici.</div>
    </div>
  <?php else: ?>
    <div style="background:var(--bg); border:1px solid var(--border); border-radius:12px; overflow:hidden;">
      <?php foreach ($recent_logs as $idx => $log):
        $action_label = match($log['action']) {
            'archive_folder' => ['🗑️', 'Dossier archivé', '#6B7280'],
            'archive_folder_with_active' => ['⚠️', 'Dossier archivé (avec projets actifs)', '#B45309'],
            'restore_folder' => ['♻️', 'Dossier restauré', '#059669'],
            'restore_project' => ['♻️', 'Projet restauré', '#059669'],
            'purge_folder' => ['🔥', 'Dossier purgé définitivement', '#DC2626'],
            'purge_project' => ['🔥', 'Projet purgé définitivement', '#DC2626'],
            default => ['📝', $log['action'], '#6B7280'],
        };
        $details = !empty($log['details']) ? json_decode($log['details'], true) : null;
      ?>
        <div style="padding:12px 18px; <?= $idx < count($recent_logs) - 1 ? 'border-bottom:1px solid var(--border);' : '' ?> display:flex; align-items:center; gap:14px;">
          <div style="font-size:18px; line-height:1;"><?= $action_label[0] ?></div>
          <div style="flex:1;">
            <div style="font-size:13px; color:<?= $action_label[2] ?>; font-weight:500;"><?= $action_label[1] ?></div>
            <div style="font-size:11.5px; color:var(--ink-3); margin-top:2px;">
              « <?= h($log['target_name']) ?> »
              <?php if (!empty($log['admin_name'])): ?> · par <?= h($log['admin_name']) ?><?php endif; ?>
              <?php if ($details && !empty($details['active_projects'])): ?>
                · <?= (int)$details['active_projects'] ?> projet(s) actif(s) archivé(s)
              <?php endif; ?>
            </div>
          </div>
          <div style="font-size:11.5px; color:var(--ink-3);">
            <?= date('d/m/Y H:i', strtotime($log['created_at'])) ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php if (empty($archived_folders) && empty($archived_projects_solo) && empty($recent_logs)): ?>
    <div class="empty-state" style="margin-top:30px;">
      <div style="font-size:48px; margin-bottom:14px;">📦</div>
      <div style="font-size:16px; color:var(--ink-2); margin-bottom:6px; font-weight:500;">Aucun dossier ou projet archivé</div>
      <div style="font-size:13px; color:var(--ink-3);">Les éléments archivés apparaîtront ici.</div>
    </div>
  <?php endif; ?>

</div>

<?php render_foot(); ?>
