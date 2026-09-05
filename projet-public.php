<?php
/**
 * ============================================================
 * ASSOKIT — Vue publique d'un projet (lien partageable)
 * ============================================================
 * GET /projet-public.php?t=TOKEN
 *   - Pas d'authentification requise
 *   - Vérifie le token (existe, non révoqué, non expiré)
 *   - Affiche : KPI, étapes, photos publiques, description
 *   - Cache messages, factures, données privées
 * ============================================================
 */
require_once __DIR__ . '/config.php';

$token = trim($_GET['t'] ?? '');
if (!preg_match('/^[a-f0-9]{32,64}$/i', $token)) {
    http_response_code(404);
    die('Lien invalide.');
}

// Vérifier token
$stmt = $pdo->prepare("
    SELECT t.id AS token_id, t.project_id, t.revoked_at, t.expires_at,
           p.name AS project_name, p.location, p.start_date, p.end_date,
           p.description, p.objective, p.progress_percent, p.participants_count,
           p.status,
           f.name AS folder_name, f.color_theme,
           o.name AS org_name, o.logo_path
    FROM project_share_tokens t
    JOIN projects p ON p.id = t.project_id
    JOIN folders f ON f.id = p.folder_id
    JOIN organizations o ON o.id = f.org_id
    WHERE t.token = ? AND p.archived_at IS NULL AND f.archived_at IS NULL
    LIMIT 1
");
$stmt->execute([$token]);
$ctx = $stmt->fetch();

if (!$ctx) {
    http_response_code(404);
    die('Lien introuvable.');
}
if (!empty($ctx['revoked_at'])) {
    http_response_code(410);
    die('Ce lien a été révoqué par l\'association.');
}
if (!empty($ctx['expires_at']) && strtotime($ctx['expires_at']) < time()) {
    http_response_code(410);
    die('Ce lien a expiré.');
}

$project_id = (int)$ctx['project_id'];

// Incrémenter compteur de vue (silent fail)
try {
    $pdo->prepare("UPDATE project_share_tokens SET view_count = view_count + 1, last_viewed_at = NOW() WHERE id = ?")
        ->execute([(int)$ctx['token_id']]);
} catch (Throwable $e) {}

// Charger étapes
$stmt = $pdo->prepare("SELECT title, description, is_completed, completed_at FROM project_steps WHERE project_id = ? ORDER BY position ASC, id ASC");
$stmt->execute([$project_id]);
$steps = $stmt->fetchAll();
$total_steps = count($steps);
$done_steps = 0;
foreach ($steps as $s) if ($s['is_completed']) $done_steps++;
$progress_pct = $total_steps > 0 ? round(($done_steps / $total_steps) * 100) : (int)$ctx['progress_percent'];

// Charger photos (uniquement images, derniers 12)
$photos = [];
try {
    $stmt = $pdo->prepare("SELECT file_name, file_path, mime_type FROM project_files
                           WHERE project_id = ? AND mime_type LIKE 'image/%'
                           ORDER BY created_at DESC LIMIT 12");
    $stmt->execute([$project_id]);
    $photos = $stmt->fetchAll();
} catch (Throwable $e) {}

// Logo
$logo_url = '';
if (!empty($ctx['logo_path'])) $logo_url = htmlspecialchars($ctx['logo_path'], ENT_QUOTES);

$h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

// Couleur dossier (fallback vert)
$theme_color = ['blue'=>'#3B82F6','purple'=>'#8B5CF6','amber'=>'#F59E0B','pink'=>'#EC4899','teal'=>'#14B8A6','emerald'=>'#10B981'][$ctx['color_theme']] ?? '#10B981';

$mois = ['','janvier','février','mars','avril','mai','juin','juillet','août','septembre','octobre','novembre','décembre'];
$fmt_date = function($iso) use ($mois) {
    if (!$iso) return '';
    $t = strtotime($iso); if (!$t) return '';
    return date('j', $t) . ' ' . $mois[(int)date('n', $t)] . ' ' . date('Y', $t);
};

// SVG Donut
$r=42;$cx=56;$cy=56;$circ=2*M_PI*$r;
$offset=$circ-($progress_pct/100)*$circ;

?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= $h($ctx['project_name']) ?> — <?= $h($ctx['org_name']) ?></title>
<style>
* { box-sizing: border-box; }
body { margin: 0; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f9fafb; color: #1f2937; line-height: 1.55; }
.pp { max-width: 880px; margin: 0 auto; padding: 24px 18px 60px; }

/* Header */
.pp-head { display: flex; align-items: center; gap: 14px; margin-bottom: 22px; padding-bottom: 18px; border-bottom: 1px solid #e5e7eb; }
.pp-logo { max-width: 60px; max-height: 60px; border-radius: 10px; }
.pp-logo-fallback { width: 50px; height: 50px; background: <?= $theme_color ?>22; color: <?= $theme_color ?>; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 22px; font-weight: 700; flex-shrink: 0; }
.pp-org-info { flex: 1; min-width: 0; }
.pp-org-name { font-size: 12px; color: #6b7280; text-transform: uppercase; letter-spacing: 0.06em; font-weight: 700; }
.pp-folder { font-size: 11px; color: <?= $theme_color ?>; font-weight: 600; margin-top: 4px; }

/* Hero */
.pp-hero { background: #fff; border: 1px solid #e5e7eb; border-radius: 16px; padding: 26px 28px; margin-bottom: 18px; position: relative; overflow: hidden; }
.pp-hero::before { content:''; position: absolute; top: 0; left: 0; right: 0; height: 3px; background: linear-gradient(90deg, <?= $theme_color ?>, #6366F1, #8B5CF6); }
.pp-hero-grid { display: grid; grid-template-columns: auto 1fr; gap: 22px; align-items: center; }
.pp-donut { width: 112px; height: 112px; flex-shrink: 0; }
.pp-donut svg { width: 100%; height: 100%; transform: rotate(-90deg); }
.pp-donut-wrap { position: relative; }
.pp-donut-pct { position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; font-size: 26px; font-weight: 700; color: #111827; }
.pp-hero-info h1 { margin: 0 0 6px; font-size: 26px; font-weight: 700; color: #111827; line-height: 1.25; }
.pp-hero-meta { display: flex; gap: 14px; flex-wrap: wrap; font-size: 13.5px; color: #4b5563; margin-top: 6px; }
.pp-hero-meta span { display: inline-flex; align-items: center; gap: 5px; }
.pp-status { display: inline-block; padding: 3px 10px; border-radius: 999px; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em; }
.pp-status.active { background: #ECFDF5; color: #065F46; }
.pp-status.done { background: #EDE9FE; color: #5B21B6; }
.pp-status.warning { background: #FEF3C7; color: #92400E; }

/* Sections */
.pp-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 14px; padding: 20px 24px; margin-bottom: 14px; }
.pp-card h2 { font-size: 14px; font-weight: 700; color: #111827; margin: 0 0 14px; padding-bottom: 8px; border-bottom: 1px solid #f3f4f6; display: flex; align-items: center; gap: 8px; }
.pp-card h2 .ic { width: 26px; height: 26px; border-radius: 7px; background: <?= $theme_color ?>15; display: inline-flex; align-items: center; justify-content: center; font-size: 13px; }
.pp-text { font-size: 14px; color: #374151; white-space: pre-line; }
.pp-objective { padding: 12px 16px; background: <?= $theme_color ?>10; border-left: 3px solid <?= $theme_color ?>; border-radius: 0 8px 8px 0; color: #111827; font-weight: 500; }

/* Étapes */
.pp-steps { display: flex; flex-direction: column; gap: 10px; }
.pp-step { display: flex; align-items: flex-start; gap: 12px; padding: 10px 0; border-top: 1px solid #f3f4f6; }
.pp-step:first-child { border-top: 0; }
.pp-step-check { width: 22px; height: 22px; border-radius: 50%; flex-shrink: 0; display: flex; align-items: center; justify-content: center; border: 2px solid #d1d5db; }
.pp-step-check.done { background: #10B981; border-color: #10B981; color: #fff; }
.pp-step-check.done::after { content: '✓'; font-size: 13px; font-weight: 700; }
.pp-step-body { flex: 1; min-width: 0; }
.pp-step-title { font-size: 14px; color: #111827; font-weight: 500; }
.pp-step.done .pp-step-title { color: #6b7280; text-decoration: line-through; }
.pp-step-date { font-size: 11.5px; color: #6b7280; margin-top: 2px; }

/* Photos */
.pp-photos { display: grid; grid-template-columns: repeat(auto-fill, minmax(140px, 1fr)); gap: 8px; }
.pp-photo { aspect-ratio: 1; overflow: hidden; border-radius: 8px; background: #f3f4f6; }
.pp-photo img { width: 100%; height: 100%; object-fit: cover; display: block; transition: transform 0.3s ease; }
.pp-photo:hover img { transform: scale(1.05); }

/* Footer */
.pp-foot { text-align: center; padding: 30px 16px 0; font-size: 12px; color: #6b7280; }
.pp-foot a { color: #6b7280; text-decoration: none; font-weight: 500; }

/* Mobile */
@media (max-width: 540px) {
  .pp-hero { padding: 18px 16px; }
  .pp-hero-grid { grid-template-columns: 1fr; text-align: center; }
  .pp-donut { margin: 0 auto; }
  .pp-hero-meta { justify-content: center; }
  .pp-card { padding: 16px 18px; }
  .pp-hero-info h1 { font-size: 22px; }
}
</style>
</head>
<body>
<div class="pp">

  <header class="pp-head">
    <?php if ($logo_url): ?>
      <img src="<?= $logo_url ?>" alt="<?= $h($ctx['org_name']) ?>" class="pp-logo">
    <?php else: ?>
      <div class="pp-logo-fallback"><?= $h(mb_substr($ctx['org_name'], 0, 2)) ?></div>
    <?php endif; ?>
    <div class="pp-org-info">
      <div class="pp-org-name"><?= $h($ctx['org_name']) ?></div>
      <div class="pp-folder">📁 <?= $h($ctx['folder_name']) ?></div>
    </div>
  </header>

  <section class="pp-hero">
    <div class="pp-hero-grid">
      <div class="pp-donut-wrap">
        <div class="pp-donut">
          <svg viewBox="0 0 112 112">
            <circle cx="<?= $cx ?>" cy="<?= $cy ?>" r="<?= $r ?>" fill="none" stroke="#f3f4f6" stroke-width="9"/>
            <circle cx="<?= $cx ?>" cy="<?= $cy ?>" r="<?= $r ?>" fill="none" stroke="<?= $theme_color ?>" stroke-width="9" stroke-linecap="round" stroke-dasharray="<?= round($circ,2) ?>" stroke-dashoffset="<?= round($offset,2) ?>"/>
          </svg>
          <div class="pp-donut-pct"><?= $progress_pct ?>%</div>
        </div>
      </div>
      <div class="pp-hero-info">
        <h1><?= $h($ctx['project_name']) ?></h1>
        <div class="pp-hero-meta">
          <?php if (!empty($ctx['location'])): ?><span>📍 <?= $h($ctx['location']) ?></span><?php endif; ?>
          <?php if (!empty($ctx['start_date'])): ?><span>📅 <?= $h($fmt_date($ctx['start_date'])) ?><?php if (!empty($ctx['end_date'])): ?> → <?= $h($fmt_date($ctx['end_date'])) ?><?php endif; ?></span><?php endif; ?>
          <?php if ($ctx['participants_count'] > 0): ?><span>👥 <?= (int)$ctx['participants_count'] ?> participants</span><?php endif; ?>
          <?php if ($total_steps > 0): ?><span>✅ <?= $done_steps ?> / <?= $total_steps ?> étapes</span><?php endif; ?>
          <?php
            $st_class = $ctx['status']; $st_lbl = ['active'=>'En cours','done'=>'Terminé','warning'=>'À surveiller'][$ctx['status']] ?? 'En cours';
          ?>
          <span class="pp-status <?= $h($st_class) ?>"><?= $h($st_lbl) ?></span>
        </div>
      </div>
    </div>
  </section>

  <?php if (!empty($ctx['objective'])): ?>
  <section class="pp-card">
    <h2><span class="ic">🎯</span> Objectif</h2>
    <div class="pp-objective"><?= nl2br($h($ctx['objective'])) ?></div>
  </section>
  <?php endif; ?>

  <?php if (!empty($ctx['description'])): ?>
  <section class="pp-card">
    <h2><span class="ic">📝</span> Le projet</h2>
    <div class="pp-text"><?= nl2br($h($ctx['description'])) ?></div>
  </section>
  <?php endif; ?>

  <?php if (!empty($photos)): ?>
  <section class="pp-card">
    <h2><span class="ic">📸</span> Photos</h2>
    <div class="pp-photos">
      <?php foreach ($photos as $p):
        $url = $h($p['file_path']);
      ?>
        <div class="pp-photo"><img src="<?= $url ?>" alt="" loading="lazy"></div>
      <?php endforeach; ?>
    </div>
  </section>
  <?php endif; ?>

  <?php if ($total_steps > 0): ?>
  <section class="pp-card">
    <h2><span class="ic">📋</span> Avancement détaillé</h2>
    <div class="pp-steps">
      <?php foreach ($steps as $s): ?>
      <div class="pp-step <?= $s['is_completed'] ? 'done' : '' ?>">
        <div class="pp-step-check <?= $s['is_completed'] ? 'done' : '' ?>"></div>
        <div class="pp-step-body">
          <div class="pp-step-title"><?= $h($s['title']) ?></div>
          <?php if ($s['is_completed'] && !empty($s['completed_at'])): ?>
            <div class="pp-step-date">Validée le <?= $h($fmt_date($s['completed_at'])) ?></div>
          <?php endif; ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </section>
  <?php endif; ?>

  <footer class="pp-foot">
    Page partagée par <strong><?= $h($ctx['org_name']) ?></strong> · Propulsé par <a href="https://assokit.fr" target="_blank">AssoKit</a>
  </footer>

</div>
</body>
</html>
