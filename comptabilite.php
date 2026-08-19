<?php
/**
 * comptabilite.php — Balance, Grand livre & Journaux (crédibilité comptable)
 * ------------------------------------------------------------------
 * Vue comptable dérivée des mêmes écritures que l'export FEC. Aucune
 * nouvelle donnée : reconstitution en partie double des flux Assokit.
 * Réservé aux rôles de gestion financière.
 * ------------------------------------------------------------------
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes-layout.php';
require_once __DIR__ . '/fec-engine.php';
require_login();

$user = current_user();
$org_id = (int)($user['org_id'] ?? 0);
if ($org_id <= 0 || !can('manage_finances')) {
    $_SESSION['flash_error'] = "La comptabilité est réservée aux rôles de gestion financière.";
    header('Location: /dashboard'); exit;
}

$year = (int)($_GET['year'] ?? date('Y'));
if ($year < 2015 || $year > (int)date('Y') + 1) $year = (int)date('Y');
$tab = in_array(($_GET['tab'] ?? ''), ['balance','grandlivre','journaux'], true) ? $_GET['tab'] : 'balance';

$rows = []; $ready = true;
try { $rows = fec_build($pdo, $org_id, $year)['rows']; }
catch (Throwable $e) { $ready = false; error_log('[compta] '.$e->getMessage()); }

$eur = fn($v) => number_format((float)$v, 2, ',', ' ') . ' €';

render_head('Comptabilité');
echo render_sidebar('comptabilite');
?>
<main class="main">
  <div class="cpt-page">
    <h1 class="cpt-title"><?= ak_icon_badge('book', '#059669', 36) ?><span>Comptabilité</span></h1>
    <p class="cpt-sub">Balance, grand livre et journaux reconstitués depuis vos factures, cotisations et achats — les mêmes écritures que l'export FEC.</p>

    <div class="cpt-bar">
      <form method="GET" action="/comptabilite" class="cpt-year">
        <input type="hidden" name="tab" value="<?= h($tab) ?>">
        <label>Exercice
          <select name="year" onchange="this.form.submit()">
            <?php for ($y = (int)date('Y'); $y >= (int)date('Y') - 6; $y--): ?>
              <option value="<?= $y ?>" <?= $y === $year ? 'selected' : '' ?>><?= $y ?></option>
            <?php endfor; ?>
          </select>
        </label>
      </form>
      <a href="/export-fec?year=<?= $year ?>" class="cpt-fec"><?= ak_icon('download',14) ?> Export FEC</a>
    </div>

    <div class="cpt-tabs">
      <a href="/comptabilite?tab=balance&amp;year=<?= $year ?>" class="cpt-tab <?= $tab==='balance'?'on':'' ?>"><?= ak_icon('list',14) ?> Balance</a>
      <a href="/comptabilite?tab=grandlivre&amp;year=<?= $year ?>" class="cpt-tab <?= $tab==='grandlivre'?'on':'' ?>"><?= ak_icon('book',14) ?> Grand livre</a>
      <a href="/comptabilite?tab=journaux&amp;year=<?= $year ?>" class="cpt-tab <?= $tab==='journaux'?'on':'' ?>"><?= ak_icon('layers',14) ?> Journaux</a>
    </div>

    <?php if (!$ready): ?>
      <div class="cpt-empty"><?= ak_icon('alert-tri',44,'1.5') ?><p>Données comptables indisponibles.</p></div>
    <?php elseif (empty($rows)): ?>
      <div class="cpt-empty"><?= ak_icon('inbox',44,'1.5') ?><p>Aucune écriture pour l'exercice <?= $year ?>.</p></div>
    <?php elseif ($tab === 'balance'):
      $bal = fec_balance($rows); $td=0;$tc=0;
    ?>
      <div class="cpt-tablewrap">
        <table class="cpt-table">
          <thead><tr><th>Compte</th><th>Libellé</th><th class="r">Débit</th><th class="r">Crédit</th><th class="r">Solde</th></tr></thead>
          <tbody>
          <?php foreach ($bal as $b): $td+=$b['debit'];$tc+=$b['credit']; ?>
            <tr>
              <td class="mono"><?= h($b['compte']) ?></td>
              <td><?= h($b['lib']) ?></td>
              <td class="r"><?= $b['debit']>0?$eur($b['debit']):'—' ?></td>
              <td class="r"><?= $b['credit']>0?$eur($b['credit']):'—' ?></td>
              <td class="r <?= $b['solde']<0?'neg':'' ?>"><strong><?= $eur($b['solde']) ?></strong></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
          <tfoot><tr><td colspan="2">Totaux</td><td class="r"><strong><?= $eur($td) ?></strong></td><td class="r"><strong><?= $eur($tc) ?></strong></td><td class="r"><?= abs($td-$tc)<0.01 ? '<span class="ok">équilibré</span>' : '<span class="neg">écart '.$eur($td-$tc).'</span>' ?></td></tr></tfoot>
        </table>
      </div>
    <?php elseif ($tab === 'grandlivre'):
      $led = fec_ledger($rows);
    ?>
      <?php foreach ($led as $compte => $g): ?>
        <div class="cpt-ledger">
          <div class="cpt-ledger-head"><span class="mono"><?= h($compte) ?></span> · <?= h($g['lib']) ?>
            <span class="cpt-ledger-tot">D <?= $eur($g['debit']) ?> · C <?= $eur($g['credit']) ?> · Solde <strong><?= $eur($g['debit']-$g['credit']) ?></strong></span>
          </div>
          <div class="cpt-tablewrap">
            <table class="cpt-table sm">
              <thead><tr><th>Date</th><th>Journal</th><th>Pièce</th><th>Libellé</th><th class="r">Débit</th><th class="r">Crédit</th></tr></thead>
              <tbody>
              <?php foreach ($g['lines'] as $l): ?>
                <tr>
                  <td class="mono"><?= h(substr($l['EcritureDate'],6,2).'/'.substr($l['EcritureDate'],4,2).'/'.substr($l['EcritureDate'],0,4)) ?></td>
                  <td><?= h($l['JournalCode']) ?></td>
                  <td class="mono"><?= h($l['PieceRef']) ?></td>
                  <td><?= h($l['EcritureLib']) ?></td>
                  <td class="r"><?= fec_parse_amount($l['Debit'])>0?$eur(fec_parse_amount($l['Debit'])):'' ?></td>
                  <td class="r"><?= fec_parse_amount($l['Credit'])>0?$eur(fec_parse_amount($l['Credit'])):'' ?></td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      <?php endforeach; ?>
    <?php else: // journaux
      $jour = [];
      foreach ($rows as $r) { $jour[$r['JournalCode']]['lib'] = $r['JournalLib']; $jour[$r['JournalCode']]['lines'][] = $r; }
      ksort($jour);
    ?>
      <?php foreach ($jour as $jc => $j): $jd=0;$jcr=0; ?>
        <div class="cpt-ledger">
          <div class="cpt-ledger-head"><span class="mono"><?= h($jc) ?></span> · <?= h($j['lib']) ?> <span class="cpt-ledger-tot"><?= count($j['lines']) ?> ligne(s)</span></div>
          <div class="cpt-tablewrap">
            <table class="cpt-table sm">
              <thead><tr><th>Date</th><th>N°</th><th>Compte</th><th>Pièce</th><th>Libellé</th><th class="r">Débit</th><th class="r">Crédit</th></tr></thead>
              <tbody>
              <?php foreach ($j['lines'] as $l): $jd+=fec_parse_amount($l['Debit']);$jcr+=fec_parse_amount($l['Credit']); ?>
                <tr>
                  <td class="mono"><?= h(substr($l['EcritureDate'],6,2).'/'.substr($l['EcritureDate'],4,2)) ?></td>
                  <td class="mono"><?= h($l['EcritureNum']) ?></td>
                  <td class="mono"><?= h($l['CompteNum']) ?></td>
                  <td class="mono"><?= h($l['PieceRef']) ?></td>
                  <td><?= h($l['EcritureLib']) ?></td>
                  <td class="r"><?= fec_parse_amount($l['Debit'])>0?$eur(fec_parse_amount($l['Debit'])):'' ?></td>
                  <td class="r"><?= fec_parse_amount($l['Credit'])>0?$eur(fec_parse_amount($l['Credit'])):'' ?></td>
                </tr>
              <?php endforeach; ?>
              </tbody>
              <tfoot><tr><td colspan="5">Total <?= h($jc) ?></td><td class="r"><strong><?= $eur($jd) ?></strong></td><td class="r"><strong><?= $eur($jcr) ?></strong></td></tr></tfoot>
            </table>
          </div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>

    <p class="cpt-note"><?= ak_icon('info',13) ?> Reconstitution automatique en partie double (à faire valider par votre expert-comptable). Assokit n'est pas un logiciel de comptabilité complet ; ces états facilitent le dialogue avec votre comptable et l'export FEC.</p>
  </div>
</main>

<style>
.cpt-page { max-width:1080px; margin:0 auto; padding:24px 22px; }
.cpt-title { font-size:23px; margin:0 0 4px; color:#0F172A; display:flex; align-items:center; gap:11px; }
.cpt-sub { color:#64748B; font-size:14px; margin:0 0 16px; max-width:680px; }
.cpt-bar { display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap; margin-bottom:14px; }
.cpt-year { font-size:14px; color:#334155; }
.cpt-year select { margin-left:6px; padding:7px 10px; border:1px solid #E2E8F0; border-radius:9px; font-family:inherit; font-size:14px; }
.cpt-fec { display:inline-flex; align-items:center; gap:6px; padding:8px 14px; border:1px solid #E2E8F0; border-radius:9px; background:#fff; color:#334155; text-decoration:none; font-size:13px; font-weight:650; }
.cpt-fec:hover { background:#F8FAFC; } .cpt-fec svg { flex:none; }
.cpt-tabs { display:flex; gap:6px; margin-bottom:16px; flex-wrap:wrap; }
.cpt-tab { display:inline-flex; align-items:center; gap:6px; padding:8px 15px; border-radius:9px; background:#fff; border:1px solid #E5E7EB; color:#475569; text-decoration:none; font-size:13px; font-weight:650; }
.cpt-tab svg { flex:none; } .cpt-tab:hover { background:#F8FAFC; }
.cpt-tab.on { background:#0F172A; color:#fff; border-color:#0F172A; }
.cpt-tablewrap { overflow-x:auto; background:#fff; border:1px solid #E5E7EB; border-radius:12px; }
.cpt-table { width:100%; border-collapse:collapse; font-size:13px; min-width:560px; }
.cpt-table th { text-align:left; padding:9px 12px; border-bottom:2px solid #E2E8F0; color:#64748B; font-size:11px; text-transform:uppercase; letter-spacing:.03em; background:#F8FAFC; position:sticky; top:0; }
.cpt-table td { padding:8px 12px; border-bottom:1px solid #F1F5F9; }
.cpt-table.sm th, .cpt-table.sm td { padding:6px 10px; font-size:12.5px; }
.cpt-table .r { text-align:right; } .cpt-table .mono { font-variant-numeric:tabular-nums; font-family:ui-monospace,Menlo,monospace; font-size:12px; color:#475569; }
.cpt-table tfoot td { padding:10px 12px; border-top:2px solid #E2E8F0; background:#F8FAFC; font-size:13px; }
.cpt-table .neg { color:#EF4444; } .cpt-table .ok { color:#10B981; font-weight:700; }
.cpt-ledger { margin-bottom:16px; }
.cpt-ledger-head { font-weight:750; color:#0F172A; font-size:13.5px; margin-bottom:8px; display:flex; align-items:baseline; gap:8px; flex-wrap:wrap; }
.cpt-ledger-tot { font-weight:500; color:#64748B; font-size:12px; margin-left:auto; }
.cpt-empty { text-align:center; padding:44px 20px; background:#fff; border:2px dashed #E5E7EB; border-radius:14px; color:#94A3B8; }
.cpt-empty p { color:#64748B; margin:10px 0 0; font-size:14px; }
.cpt-note { font-size:12px; color:#94A3B8; margin-top:18px; line-height:1.5; display:flex; align-items:flex-start; gap:6px; }
.cpt-note svg { flex:none; margin-top:2px; }
</style>
<?php render_foot(); ?>
