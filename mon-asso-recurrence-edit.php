<?php
/**
 * mon-asso-recurrence-edit.php
 * --------------------------------------------------------------
 * Édition d'une récurrence existante — Pack PHASE 3
 * v2 — Pattern render_head/sidebar/foot (cohérent dashboard)
 * --------------------------------------------------------------
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes-layout.php';
require_once __DIR__ . '/asso-recurrence-helpers.php';

require_login();

$user = current_user();
$org_id = (int)($user['org_id'] ?? 0);
if ($org_id <= 0) { header('Location: /'); exit; }

require_once __DIR__ . '/finance-permissions.php';
require_finance_access('recurrences', "l'edition de recurrences");

$rec_id = (int)($_GET['id'] ?? 0);
$rec = ak_recurrence_load($pdo, $rec_id, $org_id);
if (!$rec) { header('Location: /mon-asso-recurrences'); exit; }

$tpl = ak_recurrence_decode_template($rec['template_data']);
$lines = $tpl['lines'] ?: [['label' => '', 'quantity' => 1, 'unit_price_cents_ttc' => 0]];

$clients = [];
$runs = [];
$page_error = null;

try {
    $stCli = $pdo->prepare("SELECT id, display_name, email FROM asso_clients WHERE org_id = :o ORDER BY display_name ASC");
    $stCli->execute([':o' => $org_id]);
    $clients = $stCli->fetchAll(PDO::FETCH_ASSOC);

    $stRuns = $pdo->prepare("
        SELECT r.id, r.invoice_id, r.run_date, r.status, r.error_message, i.invoice_number
        FROM asso_invoice_recurrence_runs r
        LEFT JOIN asso_invoices i ON i.id = r.invoice_id
        WHERE r.recurrence_id = :id
        ORDER BY r.created_at DESC
        LIMIT 20
    ");
    $stRuns->execute([':id' => $rec_id]);
    $runs = $stRuns->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $page_error = $e->getMessage();
    error_log('[mon-asso-recurrence-edit] ' . $page_error);
}

render_head('Modifier récurrence');
render_sidebar('recurrences');
?>

<main class="main">

  <style>
    .frm-page-inner { font-family: 'Geist', system-ui, sans-serif; color: #0F172A; max-width: 920px; }
    .frm-page-inner h1 { font-size: 24px; font-weight: 700; margin: 0 0 6px; letter-spacing: -0.02em; }
    .frm-page-inner .sub { color: #64748B; font-size: 14px; margin-bottom: 22px; }
    .rcard { background: white; border: 1px solid #E2E8F0; border-radius: 14px; padding: 22px; margin-bottom: 18px; }
    .rcard h2 { margin: 0 0 14px; font-size: 16px; font-weight: 600; }
    .rrow { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
    .rrow3 { display: grid; grid-template-columns: repeat(3, 1fr); gap: 14px; }
    .rfield { display: flex; flex-direction: column; gap: 6px; margin-bottom: 12px; }
    .rfield label { font-size: 13px; font-weight: 600; color: #334155; }
    .rfield input, .rfield select, .rfield textarea { padding: 10px 12px; border: 1px solid #E2E8F0; border-radius: 8px; font-size: 14px; font-family: inherit; }
    .rfield input:focus, .rfield select:focus, .rfield textarea:focus { outline: none; border-color: #059669; box-shadow: 0 0 0 3px rgba(5,150,105,.12); }
    .rfield .hint { font-size: 12px; color: #64748B; }
    .rvat { display: flex; gap: 8px; flex-wrap: wrap; }
    .rvat label { padding: 8px 12px; border: 1px solid #E2E8F0; border-radius: 8px; cursor: pointer; font-size: 13px; font-weight: 500; }
    .rvat input { display: none; }
    .rvat input:checked + span { background: #D1FAE5; border-color: #059669; color: #065F46; padding: 8px 12px; border-radius: 6px; }
    table.rlines { width: 100%; border-collapse: collapse; }
    table.rlines th { text-align: left; padding: 8px; font-size: 12px; text-transform: uppercase; color: #64748B; font-weight: 600; }
    table.rlines td { padding: 6px 4px; }
    table.rlines input { width: 100%; padding: 9px 10px; border: 1px solid #E2E8F0; border-radius: 8px; font-size: 14px; font-family: inherit; }
    .rbtn-line { background: #FEE2E2; color: #991B1B; border: none; padding: 8px 12px; border-radius: 8px; cursor: pointer; }
    .radd-line { background: #ECFDF5; color: #065F46; border: 1px dashed #059669; padding: 8px 14px; border-radius: 8px; cursor: pointer; font-weight: 600; }
    .rtotals { background: #F8FAFC; border-radius: 10px; padding: 14px 18px; margin-top: 12px; }
    .rtotals div { display: flex; justify-content: space-between; padding: 4px 0; }
    .rtotals .ttc { font-weight: 700; font-size: 16px; color: #059669; padding-top: 8px; margin-top: 8px; border-top: 1px solid #E2E8F0; }
    .ractions { display: flex; gap: 10px; justify-content: flex-end; margin-top: 18px; }
    .rbtn { padding: 11px 18px; border-radius: 10px; font-weight: 600; text-decoration: none; border: none; cursor: pointer; }
    .rbtn-primary { background: #059669; color: white; }
    .rbtn-ghost { background: white; color: #475569; border: 1px solid #E2E8F0; }
    .rswitch-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
    .rswitch-row { display: flex; align-items: center; gap: 10px; padding: 12px; background: #F8FAFC; border-radius: 10px; }
    .rswitch-row input[type=checkbox] { width: 18px; height: 18px; }
    .rruns-table { width:100%; border-collapse: collapse; }
    .rruns-table th, .rruns-table td { padding: 8px; font-size: 13px; text-align: left; border-bottom: 1px solid #F1F5F9; }
    .pill { display:inline-block; padding: 2px 8px; border-radius: 999px; font-size: 11px; font-weight: 600; }
    .pill-ok { background: #D1FAE5; color: #065F46; }
    .pill-err { background: #FEE2E2; color: #991B1B; }
    @media (max-width: 720px) { .rrow, .rrow3, .rswitch-grid { grid-template-columns: 1fr; } }
  </style>

  <div class="frm-page-inner">
    <h1>Modifier la récurrence</h1>
    <div class="sub">
      <strong><?= h(ak_recurrence_status_label($rec['status'])['label']) ?></strong>
      · <?= h(ak_recurrence_frequency_label($rec['frequency'], (int)$rec['interval_count'])) ?>
      · <?= (int)$rec['occurrences_count'] ?> facture(s) générée(s)
    </div>

    <?php if ($page_error): ?>
      <div style="background:#FEF2F2;border:1px solid #FECACA;border-radius:10px;padding:14px;margin-bottom:18px;color:#991B1B;font-size:14px;">
        ⚠️ <?= h($page_error) ?>
      </div>
    <?php endif; ?>

    <form method="post" action="/mon-asso-recurrence-save">
      <input type="hidden" name="id" value="<?= (int)$rec['id'] ?>">
      <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token'] ?? '') ?>">

      <div class="rcard">
        <h2>Informations générales</h2>
        <div class="rfield">
          <label>Titre interne *</label>
          <input type="text" name="title" required maxlength="255" value="<?= h($rec['title']) ?>">
        </div>
        <div class="rrow">
          <div class="rfield">
            <label>Client</label>
            <select name="client_id">
              <option value="">— Aucun —</option>
              <?php foreach ($clients as $c): ?>
                <option value="<?= (int)$c['id'] ?>" <?= ((int)$rec['client_id'] === (int)$c['id'])?'selected':'' ?>>
                  <?= h($c['display_name']) ?><?= $c['email'] ? ' — ' . h($c['email']) : '' ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="rfield">
            <label>TVA *</label>
            <div class="rvat">
              <?php foreach (['none' => 'Pas de TVA', '5.5' => '5,5%', '10' => '10%', '20' => '20%'] as $val => $lbl): ?>
                <label>
                  <input type="radio" name="vat_mode" value="<?= h($val) ?>" <?= $rec['vat_mode']===$val?'checked':'' ?>>
                  <span><?= h($lbl) ?></span>
                </label>
              <?php endforeach; ?>
            </div>
          </div>
        </div>
      </div>

      <div class="rcard">
        <h2>Fréquence et planning</h2>
        <div class="rrow3">
          <div class="rfield">
            <label>Fréquence *</label>
            <select name="frequency" id="freqSel">
              <?php foreach (['daily'=>'Quotidien','weekly'=>'Hebdomadaire','monthly'=>'Mensuel','quarterly'=>'Trimestriel','yearly'=>'Annuel'] as $v => $l): ?>
                <option value="<?= h($v) ?>" <?= $rec['frequency']===$v?'selected':'' ?>><?= h($l) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="rfield">
            <label>Tous les *</label>
            <input type="number" name="interval_count" min="1" max="60" value="<?= (int)$rec['interval_count'] ?>">
          </div>
          <div class="rfield">
            <label>Jour du mois</label>
            <input type="number" name="day_of_month" min="1" max="31" value="<?= h((string)($rec['day_of_month'] ?? '')) ?>">
          </div>
        </div>
        <div class="rrow3">
          <div class="rfield">
            <label>Date de début *</label>
            <input type="date" name="start_date" required value="<?= h($rec['start_date']) ?>">
          </div>
          <div class="rfield">
            <label>Date de fin</label>
            <input type="date" name="end_date" value="<?= h($rec['end_date'] ?? '') ?>">
          </div>
          <div class="rfield">
            <label>Nombre max</label>
            <input type="number" name="max_occurrences" min="1" max="999" value="<?= h((string)($rec['max_occurrences'] ?? '')) ?>">
          </div>
        </div>
        <div class="rrow">
          <div class="rfield">
            <label>Prochaine génération</label>
            <input type="date" name="next_run_date" value="<?= h($rec['next_run_date']) ?>">
            <div class="hint">Modifiable manuellement si besoin de décaler.</div>
          </div>
          <div class="rfield">
            <label>Statut</label>
            <select name="status">
              <?php foreach (['active'=>'Active','paused'=>'En pause','ended'=>'Terminée','cancelled'=>'Annulée'] as $v => $l): ?>
                <option value="<?= h($v) ?>" <?= $rec['status']===$v?'selected':'' ?>><?= h($l) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
      </div>

      <div class="rcard">
        <h2>Lignes de la facture</h2>
        <table class="rlines" id="linesTable">
          <thead>
            <tr><th style="width:55%;">Description</th><th style="width:15%;">Quantité</th><th style="width:20%;">Prix TTC (€)</th><th style="width:10%;"></th></tr>
          </thead>
          <tbody id="linesBody">
            <?php foreach ($lines as $idx => $l):
              $price = number_format(((int)($l['unit_price_cents_ttc'] ?? 0)) / 100, 2, '.', '');
            ?>
            <tr>
              <td><input type="text" name="lines[<?= $idx ?>][label]" required maxlength="500" value="<?= h((string)($l['label'] ?? '')) ?>"></td>
              <td><input type="number" name="lines[<?= $idx ?>][quantity]" step="0.01" min="0" value="<?= h((string)($l['quantity'] ?? 1)) ?>" class="qty"></td>
              <td><input type="number" name="lines[<?= $idx ?>][unit_price]" step="0.01" min="0" value="<?= h($price) ?>" class="price"></td>
              <td><button type="button" class="rbtn-line" onclick="removeLine(this)">✕</button></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <div style="margin-top:10px;"><button type="button" class="radd-line" onclick="addLine()">+ Ajouter une ligne</button></div>
        <div class="rtotals">
          <div><span>Total HT</span><span id="totHT">0,00 €</span></div>
          <div><span>TVA</span><span id="totTVA">0,00 €</span></div>
          <div class="ttc"><span>Total TTC</span><span id="totTTC">0,00 €</span></div>
        </div>
      </div>

      <div class="rcard">
        <h2>Comportement</h2>
        <div class="rswitch-grid">
          <label class="rswitch-row">
            <input type="checkbox" name="auto_send" value="1" <?= (int)$rec['auto_send']===1?'checked':'' ?>>
            <div><div style="font-weight:600;">Envoi auto par email</div><div style="font-size:12px;color:#64748B;">Sinon, brouillon (recommandé).</div></div>
          </label>
          <label class="rswitch-row">
            <input type="checkbox" name="notify_admin" value="1" <?= (int)$rec['notify_admin']===1?'checked':'' ?>>
            <div><div style="font-weight:600;">Me notifier</div><div style="font-size:12px;color:#64748B;">Email à chaque génération.</div></div>
          </label>
        </div>
        <div class="rfield" style="margin-top:14px;">
          <label>Notes</label>
          <textarea name="notes" rows="3"><?= h($rec['notes'] ?? '') ?></textarea>
        </div>
      </div>

      <?php if ($runs): ?>
      <div class="rcard">
        <h2>Historique des générations</h2>
        <table class="rruns-table">
          <thead><tr><th>Date</th><th>Statut</th><th>Facture</th><th>Erreur</th></tr></thead>
          <tbody>
          <?php foreach ($runs as $r): ?>
            <tr>
              <td><?= h(date('d/m/Y', strtotime($r['run_date']))) ?></td>
              <td><span class="pill <?= $r['status']==='success'?'pill-ok':'pill-err' ?>"><?= h($r['status']) ?></span></td>
              <td><?= $r['invoice_number'] ? '<a href="/mon-asso-facture-edit?id=' . (int)$r['invoice_id'] . '" style="color:#059669;">' . h($r['invoice_number']) . '</a>' : '—' ?></td>
              <td style="color:#991B1B;font-size:12px;"><?= h($r['error_message'] ?? '') ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>

      <div class="ractions">
        <a class="rbtn rbtn-ghost" href="/mon-asso-recurrences">Annuler</a>
        <button class="rbtn rbtn-primary" type="submit">Enregistrer</button>
      </div>
    </form>
  </div>

</main>

<script>
let lineIdx = <?= count($lines) ?>;
function addLine() {
  const tbody = document.getElementById('linesBody');
  const tr = document.createElement('tr');
  tr.innerHTML = `
    <td><input type="text" name="lines[${lineIdx}][label]" required maxlength="500"></td>
    <td><input type="number" name="lines[${lineIdx}][quantity]" step="0.01" min="0" value="1" class="qty"></td>
    <td><input type="number" name="lines[${lineIdx}][unit_price]" step="0.01" min="0" value="" class="price"></td>
    <td><button type="button" class="rbtn-line" onclick="removeLine(this)">✕</button></td>`;
  tbody.appendChild(tr);
  lineIdx++;
  bindLineEvents();
}
function removeLine(btn) {
  const rows = document.querySelectorAll('#linesBody tr');
  if (rows.length <= 1) { alert('Au moins une ligne requise.'); return; }
  btn.closest('tr').remove();
  recalc();
}
function recalc() {
  const vat = (document.querySelector('input[name=vat_mode]:checked') || {}).value || 'none';
  let totalTTC = 0;
  document.querySelectorAll('#linesBody tr').forEach(tr => {
    const q = parseFloat(tr.querySelector('.qty')?.value || 0);
    const p = parseFloat(tr.querySelector('.price')?.value || 0);
    if (!isNaN(q) && !isNaN(p)) totalTTC += q * p;
  });
  let rate = 0;
  if (vat === '5.5') rate = 0.055;
  else if (vat === '10') rate = 0.10;
  else if (vat === '20') rate = 0.20;
  const totalHT = rate > 0 ? (totalTTC / (1 + rate)) : totalTTC;
  document.getElementById('totHT').textContent  = totalHT.toFixed(2).replace('.', ',') + ' €';
  document.getElementById('totTVA').textContent = (totalTTC - totalHT).toFixed(2).replace('.', ',') + ' €';
  document.getElementById('totTTC').textContent = totalTTC.toFixed(2).replace('.', ',') + ' €';
}
function bindLineEvents() {
  document.querySelectorAll('#linesBody .qty, #linesBody .price').forEach(i => {
    i.removeEventListener('input', recalc);
    i.addEventListener('input', recalc);
  });
}
document.querySelectorAll('input[name=vat_mode]').forEach(r => r.addEventListener('change', recalc));
bindLineEvents();
recalc();
</script>

<?php render_foot(); ?>
