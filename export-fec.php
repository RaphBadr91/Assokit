<?php
/**
 * export-fec.php — Export FEC (Fichier des Écritures Comptables)
 * ------------------------------------------------------------------
 * Réservé aux rôles de gestion financière. Génère et télécharge le FEC
 * de l'exercice choisi (format A47 A-1 LPF).
 * ------------------------------------------------------------------
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/fec-engine.php';

// Session + garde d'accès (avant tout output)
if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }
require_once __DIR__ . '/includes-layout.php';
require_login();
$user = current_user();
$org_id = (int)($user['org_id'] ?? 0);
if ($org_id <= 0 || !can('manage_finances')) {
    $_SESSION['flash_error'] = "L'export FEC est réservé aux rôles de gestion financière.";
    header('Location: /dashboard'); exit;
}

$year = (int)($_GET['year'] ?? date('Y'));
if ($year < 2015 || $year > (int)date('Y') + 1) $year = (int)date('Y');

// ── Téléchargement du fichier ────────────────────────────────────────
if (isset($_GET['download'])) {
    // CSRF léger via jeton en GET (le lien est généré server-side avec le token)
    if (!isset($_GET['t']) || !hash_equals($_SESSION['csrf_token'] ?? '', (string)$_GET['t'])) {
        http_response_code(403); exit('Jeton invalide.');
    }
    $build = fec_build($pdo, $org_id, $year);
    $body = fec_render($build['rows']);
    $fname = fec_filename($pdo, $org_id, $year);
    header('Content-Type: text/plain; charset=UTF-8');
    header('Content-Disposition: attachment; filename="'.$fname.'"');
    header('Content-Length: '.strlen($body));
    header('X-Content-Type-Options: nosniff');
    echo $body;
    exit;
}

// ── Aperçu (stats) ───────────────────────────────────────────────────
$stats = ['lines'=>0,'sales'=>0,'cotis'=>0,'buys'=>0,'debit'=>0,'credit'=>0,'balanced'=>true];
$ready = true;
try { $stats = fec_build($pdo, $org_id, $year)['stats']; }
catch (Throwable $e) { $ready = false; error_log('[export-fec] '.$e->getMessage()); }

$csrf = h($_SESSION['csrf_token'] ?? '');
render_head('Export FEC');
echo render_sidebar('export-fec');
?>
<main class="main">
  <div class="fec-page">
    <h1 class="fec-title"><?= ak_icon_badge('file-text', '#059669', 36) ?><span>Export FEC — Fichier des Écritures Comptables</span></h1>
    <p class="fec-sub">Le FEC est le fichier normalisé exigé par l'administration fiscale lors d'un contrôle (article A47 A-1 du LPF). Assokit le reconstitue à partir de vos factures, cotisations et achats.</p>

    <form method="GET" action="/export-fec" class="fec-yearbar">
      <label>Exercice comptable :
        <select name="year" onchange="this.form.submit()">
          <?php for ($y = (int)date('Y'); $y >= (int)date('Y') - 6; $y--): ?>
            <option value="<?= $y ?>" <?= $y === $year ? 'selected' : '' ?>><?= $y ?></option>
          <?php endfor; ?>
        </select>
      </label>
    </form>

    <?php if (!$ready): ?>
      <div class="fec-card fec-warn"><?= ak_icon('alert-tri',16) ?> Impossible de générer l'aperçu (données indisponibles).</div>
    <?php else: ?>
      <div class="fec-kpis">
        <div class="fec-kpi"><div class="fec-kpi-lbl">Lignes d'écriture</div><div class="fec-kpi-val"><?= (int)$stats['lines'] ?></div></div>
        <div class="fec-kpi"><div class="fec-kpi-lbl">Ventes</div><div class="fec-kpi-val"><?= (int)$stats['sales'] ?></div></div>
        <div class="fec-kpi"><div class="fec-kpi-lbl">Cotisations</div><div class="fec-kpi-val"><?= (int)$stats['cotis'] ?></div></div>
        <div class="fec-kpi"><div class="fec-kpi-lbl">Achats</div><div class="fec-kpi-val"><?= (int)$stats['buys'] ?></div></div>
        <div class="fec-kpi"><div class="fec-kpi-lbl">Total débit / crédit</div><div class="fec-kpi-val" style="font-size:15px;"><?= number_format($stats['debit'],2,',',' ') ?> € / <?= number_format($stats['credit'],2,',',' ') ?> €</div></div>
      </div>

      <div class="fec-card <?= $stats['balanced'] ? 'fec-ok' : 'fec-warn' ?>">
        <?php if ($stats['balanced']): ?>
          <?= ak_icon('check-circle',16) ?> Écritures équilibrées (débit = crédit). Le fichier est prêt.
        <?php else: ?>
          <?= ak_icon('alert-tri',16) ?> Déséquilibre débit/crédit détecté — à faire vérifier par votre expert-comptable avant transmission.
        <?php endif; ?>
      </div>

      <?php if ($stats['lines'] > 0): ?>
        <a href="/export-fec?download=1&amp;year=<?= $year ?>&amp;t=<?= $csrf ?>" class="fec-btn"><?= ak_icon('download',15) ?> Télécharger le FEC <?= $year ?></a>
      <?php else: ?>
        <p class="fec-empty">Aucune écriture pour l'exercice <?= $year ?>.</p>
      <?php endif; ?>
    <?php endif; ?>

    <div class="fec-note">
      <p><strong><?= ak_icon('info',14) ?> À savoir</strong></p>
      <ul>
        <li>Assokit n'est pas un logiciel de comptabilité en partie double : ce FEC est une <strong>reconstitution fidèle des flux</strong> (ventes 411/706/44571, encaissements 512/411, cotisations 512/756, achats 606/44566/401). Faites-le <strong>valider par votre expert-comptable</strong>.</li>
        <li>Format : 18 champs obligatoires, séparateur tabulation, encodage UTF-8, conforme à l'arrêté du 29 juillet 2013.</li>
        <li>Nom du fichier : <code>&lt;SIREN&gt;FEC&lt;AAAAMMJJ&gt;.txt</code> (clôture au 31/12). Renseignez votre SIREN dans les paramètres pour un nom conforme.</li>
      </ul>
    </div>
  </div>
</main>

<style>
.fec-page { max-width:860px; margin:0 auto; padding:24px 22px; }
.fec-title { font-size:23px; margin:0 0 4px; color:#0F172A; display:flex; align-items:center; gap:11px; }
.fec-sub { color:#64748B; font-size:14px; margin:0 0 18px; }
.fec-yearbar { margin-bottom:16px; font-size:14px; color:#334155; }
.fec-yearbar select { margin-left:8px; padding:7px 10px; border:1px solid #E2E8F0; border-radius:9px; font-family:inherit; font-size:14px; }
.fec-kpis { display:grid; grid-template-columns:repeat(auto-fit,minmax(130px,1fr)); gap:10px; margin-bottom:14px; }
.fec-kpi { background:#fff; border:1px solid #E5E7EB; border-radius:12px; padding:12px 14px; }
.fec-kpi-lbl { font-size:10.5px; color:#64748B; text-transform:uppercase; letter-spacing:.04em; font-weight:700; margin-bottom:4px; }
.fec-kpi-val { font-size:20px; font-weight:750; color:#0F172A; }
.fec-card { display:flex; align-items:center; gap:8px; padding:12px 15px; border-radius:11px; font-size:13.5px; font-weight:600; margin-bottom:14px; }
.fec-card svg { flex:none; }
.fec-ok { background:#D1FAE5; color:#065F46; }
.fec-warn { background:#FEF3C7; color:#92400E; }
.fec-btn { display:inline-flex; align-items:center; gap:8px; padding:12px 20px; background:linear-gradient(135deg,#10B981,#059669); color:#fff; text-decoration:none; border-radius:11px; font-weight:700; font-size:14.5px; }
.fec-btn:hover { filter:brightness(1.05); }
.fec-btn svg { flex:none; }
.fec-empty { color:#64748B; font-size:14px; }
.fec-note { margin-top:22px; background:#F8FAFC; border:1px solid #E2E8F0; border-radius:12px; padding:14px 18px; }
.fec-note p { margin:0 0 8px; font-weight:700; color:#334155; font-size:13.5px; display:flex; align-items:center; gap:6px; }
.fec-note ul { margin:0; padding-left:18px; color:#475569; font-size:13px; line-height:1.6; }
.fec-note code { background:#EEF2F7; padding:1px 6px; border-radius:5px; font-size:12px; }
</style>
<?php render_foot(); ?>
