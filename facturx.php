<?php
/**
 * facturx.php — Conformité facture électronique (Factur-X)
 * ------------------------------------------------------------------
 * Liste les factures de l'exercice et permet de télécharger le XML
 * Factur-X (CII EN 16931) de chacune, avec diagnostic de complétude.
 * Réservé aux rôles de gestion financière.
 * ------------------------------------------------------------------
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes-layout.php';
require_once __DIR__ . '/facturx-engine.php';
require_login();
$user = current_user();
$org_id = (int)($user['org_id'] ?? 0);
if ($org_id <= 0 || !can('manage_finances')) {
    $_SESSION['flash_error'] = "La conformité e-facture est réservée aux rôles de gestion financière.";
    header('Location: /dashboard'); exit;
}
$csrf = h($_SESSION['csrf_token'] ?? '');
$year = (int)($_GET['year'] ?? date('Y'));
if ($year < 2015 || $year > (int)date('Y') + 1) $year = (int)date('Y');

$invoices = []; $ready = true;
try {
    $st = $pdo->prepare(
        "SELECT id, invoice_number, amount_ttc_cents, DATE(issued_at) issued, status
         FROM asso_invoices
         WHERE org_id = :o AND status <> 'draft' AND YEAR(issued_at) = :y
         ORDER BY issued_at DESC, id DESC LIMIT 400");
    $st->execute([':o'=>$org_id, ':y'=>$year]);
    $invoices = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) { $ready = false; error_log('[facturx] '.$e->getMessage()); }

render_head('Conformité e-facture');
echo render_sidebar('facturx');
?>
<main class="main">
  <div class="fx-page">
    <h1 class="fx-title"><?= ak_icon_badge('shield', '#059669', 36) ?><span>Conformité facture électronique</span></h1>
    <p class="fx-sub">Générez le XML <strong>Factur-X</strong> (norme européenne EN 16931) de vos factures — le format structuré exigé par la réforme française 2026. Idéal pour valider vos données dès aujourd'hui.</p>

    <div class="fx-info">
      <div class="fx-info-h"><?= ak_icon('info',15) ?> Où en est la réforme ?</div>
      <p>À partir de 2026, les entreprises devront <strong>recevoir</strong> puis <strong>émettre</strong> des factures électroniques via une <strong>Plateforme Agréée (PDP)</strong>, au format Factur-X (PDF + XML) ou équivalent. Cette page produit déjà le <strong>XML structuré</strong> conforme EN 16931 : c'est le cœur technique. Restent deux étapes : l'<em>intégration PDF/A-3</em> et le <em>raccordement à une PDP</em> (choix stratégique en cours).</p>
    </div>

    <?php $rd_org = facturx_org_readiness($pdo, $org_id); ?>
    <div class="fx-ready">
      <div class="fx-ready-h">
        <span><?= ak_icon('shield',15) ?> Préparation e-facture 2026</span>
        <span class="fx-ready-score <?= $rd_org['score']>=100?'ok':($rd_org['score']>=60?'mid':'low') ?>"><?= $rd_org['score'] ?>%</span>
      </div>
      <ul class="fx-ready-list">
        <?php foreach ($rd_org['items'] as $it): ?>
          <li class="<?= $it['ok']?'ok':'ko' ?>">
            <?= $it['ok'] ? ak_icon('check-circle',15,'2.2') : ak_icon('alert-tri',15,'2') ?>
            <span><?= h($it['label']) ?><?php if (!$it['ok']): ?> <em>— <?= h($it['hint']) ?></em><?php endif; ?></span>
          </li>
        <?php endforeach; ?>
      </ul>
    </div>

    <form method="GET" action="/facturx" class="fx-year">
      <label>Exercice
        <select name="year" onchange="this.form.submit()">
          <?php for ($y = (int)date('Y'); $y >= (int)date('Y') - 6; $y--): ?>
            <option value="<?= $y ?>" <?= $y === $year ? 'selected' : '' ?>><?= $y ?></option>
          <?php endfor; ?>
        </select>
      </label>
    </form>

    <?php if (!$ready): ?>
      <div class="fx-empty"><?= ak_icon('alert-tri',44,'1.5') ?><p>Données indisponibles.</p></div>
    <?php elseif (empty($invoices)): ?>
      <div class="fx-empty"><?= ak_icon('inbox',44,'1.5') ?><p>Aucune facture émise en <?= $year ?>.</p></div>
    <?php else: ?>
      <div class="fx-tablewrap">
        <table class="fx-table">
          <thead><tr><th>Facture</th><th>Date</th><th class="r">Montant TTC</th><th>Données</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($invoices as $iv):
            $d = facturx_build_data($pdo, $org_id, (int)$iv['id']);
            $rd = $d ? facturx_readiness($d['inv']) : ['ok'=>false,'missing'=>['facture illisible']];
          ?>
            <tr>
              <td class="mono"><?= h($iv['invoice_number']) ?></td>
              <td><?= h(date('d/m/Y', strtotime($iv['issued']))) ?></td>
              <td class="r"><?= number_format(((int)$iv['amount_ttc_cents'])/100, 2, ',', ' ') ?> €</td>
              <td>
                <?php if ($rd['ok']): ?>
                  <span class="fx-badge ok"><?= ak_icon('check-circle',12,'2.4') ?> Complètes</span>
                <?php else: ?>
                  <span class="fx-badge warn" title="<?= h(implode(' · ', $rd['missing'])) ?>"><?= ak_icon('alert-tri',12,'2.2') ?> À compléter</span>
                <?php endif; ?>
              </td>
              <td class="r"><a href="/facture-facturx?id=<?= (int)$iv['id'] ?>&amp;t=<?= $csrf ?>" class="fx-dl"><?= ak_icon('download',13) ?> XML</a></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <p class="fx-hint"><?= ak_icon('info',13) ?> « À compléter » signale des données manquantes (SIRET, adresses) pour un XML pleinement conforme — le fichier reste généré, mais complétez ces champs dans les Paramètres et la fiche client pour un rendu valide au contrôle.</p>
    <?php endif; ?>
  </div>
</main>

<style>
.fx-page { max-width:940px; margin:0 auto; padding:24px 22px; }
.fx-title { font-size:23px; margin:0 0 4px; color:#0F172A; display:flex; align-items:center; gap:11px; }
.fx-sub { color:#64748B; font-size:14px; margin:0 0 16px; max-width:700px; }
.fx-info { background:#EEF2FF; border:1px solid #C7D2FE; border-radius:12px; padding:14px 18px; margin-bottom:18px; }
.fx-info-h { font-weight:750; color:#3730A3; font-size:13.5px; display:flex; align-items:center; gap:6px; margin-bottom:6px; }
.fx-info p { margin:0; font-size:13px; color:#3730A3; line-height:1.55; }
.fx-info svg { flex:none; }
.fx-ready { background:#fff; border:1px solid #E5E7EB; border-radius:12px; padding:14px 18px; margin-bottom:18px; }
.fx-ready-h { display:flex; justify-content:space-between; align-items:center; font-weight:750; color:#0F172A; font-size:14px; margin-bottom:10px; }
.fx-ready-h > span:first-child { display:inline-flex; align-items:center; gap:7px; }
.fx-ready-score { font-size:13px; font-weight:800; padding:2px 10px; border-radius:999px; }
.fx-ready-score.ok { background:#D1FAE5; color:#065F46; } .fx-ready-score.mid { background:#FEF3C7; color:#92400E; } .fx-ready-score.low { background:#FEE2E2; color:#991B1B; }
.fx-ready-list { list-style:none; margin:0; padding:0; display:flex; flex-direction:column; gap:7px; }
.fx-ready-list li { display:flex; align-items:center; gap:8px; font-size:13px; color:#334155; }
.fx-ready-list li.ok { color:#065F46; } .fx-ready-list li.ko { color:#92400E; }
.fx-ready-list li svg { flex:none; }
.fx-ready-list em { color:#64748B; font-style:normal; }
.fx-year { margin-bottom:14px; font-size:14px; color:#334155; }
.fx-year select { margin-left:6px; padding:7px 10px; border:1px solid #E2E8F0; border-radius:9px; font-family:inherit; font-size:14px; }
.fx-tablewrap { overflow-x:auto; background:#fff; border:1px solid #E5E7EB; border-radius:12px; }
.fx-table { width:100%; border-collapse:collapse; font-size:13px; min-width:560px; }
.fx-table th { text-align:left; padding:9px 12px; border-bottom:2px solid #E2E8F0; color:#64748B; font-size:11px; text-transform:uppercase; letter-spacing:.03em; background:#F8FAFC; }
.fx-table td { padding:9px 12px; border-bottom:1px solid #F1F5F9; }
.fx-table .r { text-align:right; } .fx-table .mono { font-family:ui-monospace,Menlo,monospace; font-size:12px; color:#475569; }
.fx-badge { display:inline-flex; align-items:center; gap:5px; font-size:11.5px; font-weight:700; padding:3px 9px; border-radius:999px; }
.fx-badge.ok { background:#D1FAE5; color:#065F46; } .fx-badge.warn { background:#FEF3C7; color:#92400E; }
.fx-dl { display:inline-flex; align-items:center; gap:5px; color:#059669; font-weight:700; text-decoration:none; font-size:12.5px; }
.fx-dl:hover { text-decoration:underline; }
.fx-empty { text-align:center; padding:44px 20px; background:#fff; border:2px dashed #E5E7EB; border-radius:14px; color:#64748B; }
.fx-empty p { color:#64748B; margin:10px 0 0; font-size:14px; }
.fx-hint { font-size:12px; color:#64748B; margin-top:14px; line-height:1.5; display:flex; align-items:flex-start; gap:6px; }
.fx-hint svg { flex:none; margin-top:2px; }
</style>
<?php render_foot(); ?>
