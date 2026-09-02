<?php
/**
 * ============================================================
 * ASSOKIT — Espace projets public (lecture seule) pour WordPress
 * ============================================================
 * GET /espace-public.php?t=TOKEN
 *   - AUCUNE authentification, AUCUNE session ouverte.
 *   - Affiche la liste des projets actifs de l'organisation + KPI.
 *   - Lecture seule : aucune donnée privée, aucune action possible.
 *   - Conçu pour être embarqué dans un iframe WordPress.
 * ============================================================
 */
require_once __DIR__ . '/config.php';

$token = trim($_GET['t'] ?? '');
if (!preg_match('/^[a-f0-9]{32,64}$/i', $token)) { http_response_code(404); die('Lien invalide.'); }

// Vérifier le jeton org (non révoqué).
$stmt = $pdo->prepare("
    SELECT t.id AS token_id, t.org_id, t.revoked_at,
           o.name AS org_name, o.logo_path
    FROM org_espace_tokens t
    JOIN organizations o ON o.id = t.org_id
    WHERE t.token = ? LIMIT 1
");
$stmt->execute([$token]);
$ctx = $stmt->fetch();
if (!$ctx) { http_response_code(404); die('Lien introuvable.'); }
if (!empty($ctx['revoked_at'])) { http_response_code(410); die('Ce lien a été révoqué par l\'association.'); }

$org_id = (int)$ctx['org_id'];

// Compteur de vues (silencieux).
try {
    $pdo->prepare("UPDATE org_espace_tokens SET view_count = view_count + 1, last_viewed_at = NOW() WHERE id = ?")
        ->execute([(int)$ctx['token_id']]);
} catch (Throwable $e) {}

$h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

// KPI : projets actifs.
$st = $pdo->prepare("SELECT COUNT(*) FROM projects p JOIN folders f ON p.folder_id = f.id
    WHERE f.org_id = ? AND p.status IN ('active','warning') AND p.archived_at IS NULL AND f.archived_at IS NULL");
$st->execute([$org_id]);
$kpi_projects = (int)$st->fetchColumn();

// KPI : membres actifs.
$kpi_members = 0;
try {
    $st = $pdo->prepare("SELECT COUNT(*) FROM users WHERE org_id = ? AND (deleted_at IS NULL OR deleted_at = '') AND is_active = 1");
    $st->execute([$org_id]);
    $kpi_members = (int)$st->fetchColumn();
} catch (Throwable $e) {}

// KPI : événements à venir.
$kpi_events = 0;
try {
    $st = $pdo->prepare("SELECT COUNT(*) FROM events WHERE org_id = ? AND starts_at >= CURDATE() AND deleted_at IS NULL");
    $st->execute([$org_id]);
    $kpi_events = (int)$st->fetchColumn();
} catch (Throwable $e) {}

// Liste des projets actifs (lecture seule).
$st = $pdo->prepare("
    SELECT p.name, p.status, p.progress_percent, p.location, p.start_date, p.end_date,
           p.participants_count, f.name AS folder_name, f.color_theme
    FROM projects p
    JOIN folders f ON p.folder_id = f.id
    WHERE f.org_id = ? AND p.status IN ('active','warning') AND p.archived_at IS NULL AND f.archived_at IS NULL
    ORDER BY p.status = 'warning' DESC, p.updated_at DESC
    LIMIT 60
");
$st->execute([$org_id]);
$projects = $st->fetchAll();

$logo_url = !empty($ctx['logo_path']) ? $h($ctx['logo_path']) : '';
$theme_of = fn($c) => ['blue'=>'#3B82F6','purple'=>'#8B5CF6','amber'=>'#F59E0B','pink'=>'#EC4899','teal'=>'#14B8A6','emerald'=>'#10B981'][$c] ?? '#10B981';

$mois = ['','janv.','févr.','mars','avr.','mai','juin','juil.','août','sept.','oct.','nov.','déc.'];
$fmt_date = function($iso) use ($mois) {
    if (!$iso) return '';
    $t = strtotime($iso); if (!$t) return '';
    return date('j', $t) . ' ' . $mois[(int)date('n', $t)] . ' ' . date('Y', $t);
};
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Projets — <?= $h($ctx['org_name']) ?></title>
<style>
* { box-sizing: border-box; }
body { margin: 0; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f9fafb; color: #1f2937; line-height: 1.5; }
.ep { max-width: 980px; margin: 0 auto; padding: 22px 16px 48px; }

.ep-head { display: flex; align-items: center; gap: 13px; margin-bottom: 18px; }
.ep-logo { width: 46px; height: 46px; border-radius: 10px; object-fit: contain; background: #fff; border: 1px solid #e5e7eb; padding: 3px; }
.ep-logo-fb { width: 46px; height: 46px; border-radius: 10px; background: #10B98122; color: #059669; display: flex; align-items: center; justify-content: center; font-size: 18px; font-weight: 800; }
.ep-title { font-size: 12px; color: #6b7280; text-transform: uppercase; letter-spacing: .06em; font-weight: 700; }
.ep-org { font-size: 19px; font-weight: 800; color: #111827; }

.ep-kpis { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; margin-bottom: 18px; }
.ep-kpi { background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; padding: 14px 16px; }
.ep-kpi-num { font-size: 26px; font-weight: 800; color: #111827; line-height: 1; }
.ep-kpi-lbl { font-size: 12px; color: #6b7280; margin-top: 5px; font-weight: 600; }

.ep-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 12px; }
.ep-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 14px; padding: 16px 18px; }
.ep-card-top { display: flex; align-items: flex-start; justify-content: space-between; gap: 10px; }
.ep-folder { font-size: 11px; font-weight: 700; }
.ep-name { font-size: 15.5px; font-weight: 700; color: #111827; margin: 3px 0 8px; line-height: 1.3; }
.ep-status { display: inline-block; padding: 2px 9px; border-radius: 999px; font-size: 10.5px; font-weight: 700; text-transform: uppercase; letter-spacing: .03em; white-space: nowrap; }
.ep-status.active { background: #ECFDF5; color: #065F46; }
.ep-status.warning { background: #FEF3C7; color: #92400E; }
.ep-meta { font-size: 12.5px; color: #6b7280; display: flex; flex-wrap: wrap; gap: 4px 12px; margin-bottom: 10px; }
.ep-bar { height: 7px; background: #f3f4f6; border-radius: 999px; overflow: hidden; }
.ep-bar-fill { height: 100%; border-radius: 999px; }
.ep-pct { font-size: 11.5px; color: #6b7280; margin-top: 5px; font-weight: 600; }
.ep-empty { background: #fff; border: 1px dashed #d1d5db; border-radius: 14px; padding: 34px; text-align: center; color: #9ca3af; font-size: 14px; }
.ep-foot { text-align: center; padding: 26px 12px 0; font-size: 12px; color: #9ca3af; }
.ep-foot a { color: #6b7280; text-decoration: none; font-weight: 600; }
@media (max-width: 560px) { .ep-kpis { grid-template-columns: 1fr 1fr; } }
</style>
</head>
<body>
<div class="ep">
  <header class="ep-head">
    <?php if ($logo_url): ?><img src="<?= $logo_url ?>" alt="" class="ep-logo">
    <?php else: ?><div class="ep-logo-fb"><?= $h(mb_strtoupper(mb_substr($ctx['org_name'],0,2))) ?></div><?php endif; ?>
    <div>
      <div class="ep-title">Projets de l'association</div>
      <div class="ep-org"><?= $h($ctx['org_name']) ?></div>
    </div>
  </header>

  <div class="ep-kpis">
    <div class="ep-kpi"><div class="ep-kpi-num"><?= $kpi_projects ?></div><div class="ep-kpi-lbl">Projets actifs</div></div>
    <div class="ep-kpi"><div class="ep-kpi-num"><?= $kpi_members ?></div><div class="ep-kpi-lbl">Membres actifs</div></div>
    <div class="ep-kpi"><div class="ep-kpi-num"><?= $kpi_events ?></div><div class="ep-kpi-lbl">Événements à venir</div></div>
  </div>

  <?php if (empty($projects)): ?>
    <div class="ep-empty">Aucun projet en cours pour le moment.</div>
  <?php else: ?>
  <div class="ep-grid">
    <?php foreach ($projects as $p):
      $c = $theme_of($p['color_theme']);
      $pct = max(0, min(100, (int)$p['progress_percent']));
      $st_lbl = $p['status'] === 'warning' ? 'À surveiller' : 'En cours';
    ?>
    <div class="ep-card">
      <div class="ep-card-top">
        <div class="ep-folder" style="color:<?= $c ?>"><?= $h($p['folder_name']) ?></div>
        <span class="ep-status <?= $h($p['status']) ?>"><?= $st_lbl ?></span>
      </div>
      <div class="ep-name"><?= $h($p['name']) ?></div>
      <div class="ep-meta">
        <?php if (!empty($p['location'])): ?><span><?= $h($p['location']) ?></span><?php endif; ?>
        <?php if (!empty($p['start_date'])): ?><span><?= $h($fmt_date($p['start_date'])) ?><?php if (!empty($p['end_date'])): ?> → <?= $h($fmt_date($p['end_date'])) ?><?php endif; ?></span><?php endif; ?>
        <?php if ((int)$p['participants_count'] > 0): ?><span><?= (int)$p['participants_count'] ?> participants</span><?php endif; ?>
      </div>
      <div class="ep-bar"><div class="ep-bar-fill" style="width:<?= $pct ?>%;background:<?= $c ?>"></div></div>
      <div class="ep-pct"><?= $pct ?>% d'avancement</div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <footer class="ep-foot">Propulsé par <a href="https://assokit.fr" target="_blank" rel="noopener">Assokit</a></footer>
</div>
</body>
</html>
