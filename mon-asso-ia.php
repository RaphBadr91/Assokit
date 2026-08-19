<?php
/**
 * mon-asso-ia.php
 * --------------------------------------------------------------
 * Hub Communication IA — Pack PHASE 4.5 (PATCH bandeau compact)
 * Affiche : 6 dossiers thématiques + outils transverses + actions
 * --------------------------------------------------------------
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes-layout.php';
require_once __DIR__ . '/asso-ai-helpers.php';
require_once __DIR__ . '/asso-ai-quotas.php';

// === [PACK 6.2] Helpers du système de plans (chargement silencieux si absent) ===
@require_once __DIR__ . '/plan-helpers.php';

require_login();
$user = current_user();
$org_id = (int)($user['org_id'] ?? 0);
if ($org_id <= 0) { header('Location: /'); exit; }

$can_administer_quotas = ak_ai_quota_can_administer($pdo, $user);

$page_error = null;
$count_month = 0;
$count_total = 0;
$recent = [];
$tables_ready = false;

try {
    $check = $pdo->query("SHOW TABLES LIKE 'asso_ai_generations'");
    $tables_ready = (bool)$check->fetch();
    if ($tables_ready) {
        $count_month = ak_ai_count_this_month($pdo, $org_id);
        $count_total = ak_ai_count_total($pdo, $org_id);
        $st = $pdo->prepare("SELECT id, tool_type, title, created_at FROM asso_ai_generations
                             WHERE org_id = :o AND status = 'success' ORDER BY created_at DESC LIMIT 5");
        $st->execute([':o' => $org_id]);
        $recent = $st->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Throwable $e) {
    $page_error = $e->getMessage();
    error_log('[mon-asso-ia] ' . $page_error);
}

$folders = ak_ai_folders_catalog();
$transverse = ak_ai_tools_transverse();

render_head('Assokit IA');
render_sidebar('ia');
?>

<main class="main">
  <?php
  // === [PACK 6.2] Bandeaux du système de plans (silencieux si pas déployé) ===
  if (function_exists('ak_render_overdue_overlay')) {
      echo ak_render_overdue_overlay($pdo, $org_id);
  }
  if (function_exists('ak_render_grace_banner')) {
      echo ak_render_grace_banner($pdo, $org_id);
  }
  if (function_exists('ak_render_quota_banner')) {
      echo ak_render_quota_banner($pdo, $org_id);
  }
  ?>

  <style>
    .ia-page { font-family: 'Geist', system-ui, sans-serif; color: #0F172A; }

    /* === BANDEAU COMPACT (remplace le gros hero) === */
    .ia-bar {
      display: flex; align-items: center; justify-content: space-between;
      background: white; border: 1px solid #E2E8F0; border-radius: 12px;
      padding: 12px 18px; margin-bottom: 22px; gap: 18px; flex-wrap: wrap;
    }
    .ia-bar-brand { display: flex; align-items: center; gap: 12px; min-width: 0; }
    .ia-bar-logo {
      width: 36px; height: 36px; border-radius: 9px;
      background: linear-gradient(135deg, #0F172A 0%, #7E22CE 100%);
      color: white; display: flex; align-items: center; justify-content: center;
      font-weight: 800; font-size: 15px; letter-spacing: -0.02em; flex-shrink: 0;
      box-shadow: 0 2px 8px rgba(126, 34, 206, 0.25);
    }
    .ia-bar-text { min-width: 0; }
    .ia-bar-title {
      font-size: 16px; font-weight: 700; letter-spacing: -0.01em;
      display: flex; align-items: center; gap: 8px;
    }
    .ia-bar-title .badge {
      font-size: 10px; font-weight: 700; padding: 2px 7px; border-radius: 999px;
      background: linear-gradient(135deg, #7E22CE, #EC4899); color: white;
      letter-spacing: 0.05em; text-transform: uppercase;
    }
    .ia-bar-sub { font-size: 12px; color: #64748B; margin-top: 1px; }

    .ia-bar-stats { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
    .ia-stat {
      padding: 6px 12px; background: #F8FAFC; border: 1px solid #E2E8F0;
      border-radius: 8px; font-size: 12px; color: #64748B;
      display: inline-flex; align-items: center; gap: 6px;
    }
    .ia-stat strong { color: #0F172A; font-size: 14px; font-weight: 700; }

    /* === RESTE DE LA PAGE (inchangé) === */
    .ia-quicknav { display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 24px; }
    .ia-quicknav a { padding: 9px 14px; background: white; border: 1px solid #E2E8F0; border-radius: 10px; text-decoration: none; color: #475569; font-size: 13px; font-weight: 600; display: inline-flex; align-items: center; gap: 6px; transition: all 0.15s; }
    .ia-quicknav a:hover { background: #F8FAFC; border-color: #CBD5E1; color: #0F172A; }
    .ia-quicknav a.primary { background: linear-gradient(135deg,#7E22CE,#EC4899); color: white; border-color: transparent; }
    .ia-quicknav a.primary:hover { filter: brightness(0.95); }

    .ia-section-head { display: flex; justify-content: space-between; align-items: center; margin: 26px 0 14px; }
    .ia-section-head h2 { margin: 0; font-size: 18px; font-weight: 700; }
    .ia-section-head a { color: #7E22CE; text-decoration: none; font-size: 13px; font-weight: 600; }

    .ia-folder { background: white; border: 1px solid #E2E8F0; border-radius: 14px; padding: 20px; margin-bottom: 14px; transition: all 0.15s; }
    .ia-folder:hover { box-shadow: 0 8px 24px rgba(15,23,42,0.06); border-color: var(--c, #CBD5E1); }
    .ia-folder-head { display: flex; align-items: center; gap: 14px; margin-bottom: 14px; }
    .ia-folder-head .ico { width: 44px; height: 44px; border-radius: 11px; display: flex; align-items: center; justify-content: center; font-size: 22px; background: var(--bg); color: var(--c); flex-shrink: 0; }
    .ia-folder-head h3 { margin: 0; font-size: 17px; font-weight: 700; }
    .ia-folder-head .desc { font-size: 13px; color: #64748B; margin-top: 2px; }
    .ia-folder-tools { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 10px; }
    .ia-tool { display: flex; align-items: center; gap: 10px; padding: 11px 12px; background: #F8FAFC; border: 1px solid #E2E8F0; border-radius: 10px; text-decoration: none; color: inherit; transition: all 0.12s; }
    .ia-tool:hover { background: white; border-color: var(--c); transform: translateX(2px); }
    .ia-tool .t-ico { font-size: 18px; flex-shrink: 0; width: 26px; text-align: center; }
    .ia-tool .t-info { min-width: 0; }
    .ia-tool .t-label { font-size: 13px; font-weight: 600; line-height: 1.2; }
    .ia-tool .t-desc { font-size: 11px; color: #94A3B8; line-height: 1.3; margin-top: 2px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

    .ia-transverse { display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 12px; }
    .ia-transverse .ia-tool { padding: 14px 14px; }

    .ia-recent { background: white; border: 1px solid #E2E8F0; border-radius: 14px; overflow: hidden; }
    .ia-recent-item { display: flex; justify-content: space-between; align-items: center; padding: 14px 16px; border-bottom: 1px solid #F1F5F9; text-decoration: none; color: inherit; transition: background 0.1s; }
    .ia-recent-item:last-child { border-bottom: none; }
    .ia-recent-item:hover { background: #F8FAFC; }
    .ia-recent-item .left { display: flex; align-items: center; gap: 12px; min-width: 0; }
    .ia-recent-item .ico-mini { width: 30px; height: 30px; border-radius: 7px; background: #F1F5F9; display: flex; align-items: center; justify-content: center; font-size: 15px; flex-shrink: 0; }
    .ia-recent-item .info { min-width: 0; }
    .ia-recent-item .info .t { font-weight: 600; font-size: 14px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .ia-recent-item .info .s { font-size: 12px; color: #64748B; }
    .ia-recent-item .date { font-size: 12px; color: #94A3B8; flex-shrink: 0; }

    .ia-empty { padding: 40px 20px; text-align: center; color: #64748B; font-size: 14px; }
    .ia-empty .big { font-size: 36px; margin-bottom: 8px; opacity: 0.5; }

    .ia-warning { background: #FEF3C7; border: 1px solid #FDE68A; border-radius: 12px; padding: 14px 16px; color: #92400E; margin-bottom: 18px; font-size: 14px; }

    @media (max-width: 720px) {
      .ia-bar { padding: 10px 14px; }
      .ia-bar-stats { width: 100%; }
    }
  </style>

  <div class="ia-page">

    <!-- Bandeau compact "Assokit IA" -->
    <div class="ia-bar">
      <div class="ia-bar-brand">
        <div class="ia-bar-logo">A</div>
        <div class="ia-bar-text">
          <div class="ia-bar-title">Assokit IA <span class="badge">Beta</span></div>
          <div class="ia-bar-sub">19 outils · 6 dossiers · diffusion email intégrée</div>
        </div>
      </div>
      <div class="ia-bar-stats">
        <div class="ia-stat"><strong><?= (int)$count_month ?></strong> ce mois</div>
        <div class="ia-stat"><strong><?= (int)$count_total ?></strong> au total</div>
      </div>
    </div>

    <div class="ia-quicknav">
      <a href="/mon-asso-ia-diffusion" class="primary"><span style="display:inline-flex; vertical-align:-3px;"><?= ak_icon('send',15) ?></span> Diffusion email</a>
      <a href="/mon-asso-ia-historique"><span style="display:inline-flex; vertical-align:-3px;"><?= ak_icon('folder',15) ?></span> Historique</a>
      <?php if ($can_administer_quotas): ?>
        <a href="/mon-asso-ia-admin-quotas" style="background:#FEF3C7;color:#92400E;border-color:#FDE68A;"><span style="display:inline-flex; vertical-align:-3px;"><?= ak_icon('shield',15) ?></span> Voir les quotas</a>
      <?php endif; ?>
    </div>

    <?php if ($page_error): ?>
      <div class="ia-warning"><span style="display:inline-flex; vertical-align:-3px;"><?= ak_icon('alert-tri',15) ?></span> <?= h($page_error) ?></div>
    <?php endif; ?>
    <?php if (!$tables_ready): ?>
      <div class="ia-warning">
        <span style="display:inline-flex; vertical-align:-3px;"><?= ak_icon('alert-tri',15) ?></span> Migration BDD <code>v43</code> non passée. Exécute <code>migration-v43-ia-folders.sql</code> dans phpMyAdmin.
      </div>
    <?php endif; ?>

    <div class="ia-section-head"><h2 style="display:inline-flex; align-items:center; gap:8px;"><?= ak_icon('folder',18) ?> Dossiers thématiques</h2></div>

    <?php foreach ($folders as $key => $folder):
      $tools_in = ak_ai_tools_by_folder($key);
      if (empty($tools_in)) continue;
    ?>
      <div class="ia-folder" style="--c: <?= h($folder['color']) ?>; --bg: <?= h($folder['color']) ?>1A;">
        <div class="ia-folder-head">
          <div class="ico"><?= $folder['icon'] ?></div>
          <div>
            <h3><?= h($folder['label']) ?></h3>
            <div class="desc"><?= h($folder['desc']) ?></div>
          </div>
        </div>
        <div class="ia-folder-tools">
          <?php foreach ($tools_in as $tkey => $tool): ?>
            <a href="/mon-asso-ia-tool?type=<?= h($tkey) ?>" class="ia-tool" style="--c: <?= h($folder['color']) ?>;">
              <div class="t-ico"><?= $tool['icon'] ?></div>
              <div class="t-info">
                <div class="t-label"><?= h($tool['label']) ?></div>
                <div class="t-desc"><?= h($tool['desc']) ?></div>
              </div>
            </a>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endforeach; ?>

    <?php if (!empty($transverse)): ?>
      <div class="ia-section-head"><h2 style="display:inline-flex; align-items:center; gap:8px;"><?= ak_icon('wrench',18) ?> Outils transverses</h2></div>
      <div class="ia-transverse">
        <?php foreach ($transverse as $tkey => $tool): ?>
          <a href="/mon-asso-ia-tool?type=<?= h($tkey) ?>" class="ia-tool" style="--c: #475569;">
            <div class="t-ico"><?= $tool['icon'] ?></div>
            <div class="t-info">
              <div class="t-label"><?= h($tool['label']) ?></div>
              <div class="t-desc"><?= h($tool['desc']) ?></div>
            </div>
          </a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <div class="ia-section-head">
      <h2 style="display:inline-flex; align-items:center; gap:8px;"><?= ak_icon('sparkle',18) ?> Générations récentes</h2>
      <?php if ($recent): ?><a href="/mon-asso-ia-historique">Tout l'historique →</a><?php endif; ?>
    </div>
    <div class="ia-recent">
      <?php if (empty($recent)): ?>
        <div class="ia-empty">
          <div class="big" style="color:#94A3B8;"><?= ak_icon('inbox',44,'1.5') ?></div>
          Aucune génération pour le moment. Choisissez un outil ci-dessus pour commencer.
        </div>
      <?php else: foreach ($recent as $r): $tool = ak_ai_tool($r['tool_type']); ?>
        <a href="/mon-asso-ia-tool?type=<?= h($r['tool_type']) ?>&gen=<?= (int)$r['id'] ?>" class="ia-recent-item">
          <div class="left">
            <div class="ico-mini"><?= $tool['icon'] ?></div>
            <div class="info">
              <div class="t"><?= h($r['title']) ?: h($tool['label']) ?></div>
              <div class="s"><?= h($tool['label']) ?></div>
            </div>
          </div>
          <div class="date"><?= h(date('d/m/Y H:i', strtotime($r['created_at']))) ?></div>
        </a>
      <?php endforeach; endif; ?>
    </div>

  </div>
</main>

<?php render_foot(); ?>
