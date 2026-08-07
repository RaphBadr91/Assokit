<?php
/**
 * mon-asso-recurrence-new.php
 * --------------------------------------------------------------
 * Création d'une nouvelle récurrence — Pack PHASE 3
 * v2 — Pattern render_head/sidebar/foot (cohérent dashboard)
 * Préremplissage possible depuis ?from_invoice=ID
 * --------------------------------------------------------------
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes-layout.php';
require_once __DIR__ . '/asso-recurrence-helpers.php';

require_login();

$user = current_user();
$org_id = (int)($user['org_id'] ?? 0);
if ($org_id <= 0) { header('Location: /'); exit; }

// [PACK 6.5 - SECURITY] Accès finances obligatoire
require_once __DIR__ . '/finance-permissions.php';
require_finance_access('recurrences', 'la création de récurrences');

$page_error = null;
$clients = [];
$prefill = ['title' => '', 'client_id' => '', 'vat_mode' => 'none', 'lines' => [['label' => '', 'quantity' => 1, 'unit_price' => '']], 'notes' => ''];

try {
    // Charge clients
    $stCli = $pdo->prepare("SELECT id, display_name, email FROM asso_clients WHERE org_id = :o ORDER BY display_name ASC");
    $stCli->execute([':o' => $org_id]);
    $clients = $stCli->fetchAll(PDO::FETCH_ASSOC);

    // Préremplissage depuis facture existante
    $from_invoice_id = (int)($_GET['from_invoice'] ?? 0);
    if ($from_invoice_id > 0) {
        $stInv = $pdo->prepare("
            SELECT i.*, c.display_name AS client_name
            FROM asso_invoices i
            LEFT JOIN asso_clients c ON c.id = i.client_id
            WHERE i.id = :id AND i.org_id = :o LIMIT 1
        ");
        $stInv->execute([':id' => $from_invoice_id, ':o' => $org_id]);
        $inv = $stInv->fetch(PDO::FETCH_ASSOC);
        if ($inv) {
            $prefill['title']     = 'Récurrence - ' . ($inv['client_name'] ?? $inv['display_name'] ?? $inv['invoice_number'] ?? 'facture');
            $prefill['client_id'] = $inv['client_id'] ?? '';
            $prefill['vat_mode']  = $inv['vat_mode'] ?? 'none';
            $prefill['notes']     = $inv['notes'] ?? '';
            // Charge les lignes (essaie position puis line_order pour robustesse)
            try {
                $stLines = $pdo->prepare("SELECT label, quantity, unit_price_cents_ttc FROM asso_invoice_lines WHERE invoice_id = :i ORDER BY line_order ASC, id ASC");
                $stLines->execute([':i' => $from_invoice_id]);
                $rows = $stLines->fetchAll(PDO::FETCH_ASSOC);
            } catch (Throwable $e1) {
                $rows = [];
            }
            if ($rows) {
                $prefill['lines'] = array_map(fn($l) => [
                    'label'      => $l['label'],
                    'quantity'   => (float)$l['quantity'],
                    'unit_price' => number_format(((int)$l['unit_price_cents_ttc']) / 100, 2, '.', ''),
                ], $rows);
            }
        }
    }
} catch (Throwable $e) {
    $page_error = $e->getMessage();
    error_log('[mon-asso-recurrence-new] ' . $page_error);
}

render_head('Nouvelle récurrence');
render_sidebar('recurrences');
?>

<main class="main">

  <style>
    .frm-page-inner { font-family: 'Geist', system-ui, sans-serif; color: #0F172A; max-width: 920px; }
    .frm-page-inner h1 { font-size: 24px; font-weight: 700; margin: 0 0 6px; letter-spacing: -0.02em; }
    .frm-page-inner .sub { color: #64748B; font-size: 14px; margin-bottom: 22px; }
    .rcard { background: white; border: 1px solid #E2E8F0; border-radius: 14px; padding: 22px; margin-bottom: 18px; }
    .rcard h2 { margin: 0 0 14px; font-size: 16px; font-weight: 600; color: #0F172A; }
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
    .rbtn-line { background: #FEE2E2; color: #991B1B; border: none; padding: 8px 12px; border-radius: 8px; cursor: pointer; font-size: 13px; }
    .radd-line { background: #ECFDF5; color: #065F46; border: 1px dashed #059669; padding: 8px 14px; border-radius: 8px; cursor: pointer; font-size: 13px; font-weight: 600; }
    .rtotals { background: #F8FAFC; border-radius: 10px; padding: 14px 18px; margin-top: 12px; }
    .rtotals div { display: flex; justify-content: space-between; padding: 4px 0; font-size: 14px; }
    .rtotals .ttc { font-weight: 700; font-size: 16px; color: #059669; padding-top: 8px; margin-top: 8px; border-top: 1px solid #E2E8F0; }
    .ractions { display: flex; gap: 10px; justify-content: flex-end; margin-top: 18px; }
    .rbtn { padding: 11px 18px; border-radius: 10px; font-size: 14px; font-weight: 600; text-decoration: none; border: none; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; }
    .rbtn-primary { background: #059669; color: white; }
    .rbtn-primary:hover { background: #047857; }
    .rbtn-ghost { background: white; color: #475569; border: 1px solid #E2E8F0; }
    .rswitch-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
    .rswitch-row { display: flex; align-items: center; gap: 10px; padding: 12px; background: #F8FAFC; border-radius: 10px; }
    .rswitch-row input[type=checkbox] { width: 18px; height: 18px; }
    @media (max-width: 720px) { .rrow, .rrow3, .rswitch-grid { grid-template-columns: 1fr; } }
  </style>

  <div class="frm-page-inner">
    <h1>+ Nouvelle récurrence</h1>
    <div class="sub">Une facture sera générée automatiquement selon la fréquence choisie.</div>

    <?php if ($page_error): ?>
      <div style="background:#FEF2F2;border:1px solid #FECACA;border-radius:10px;padding:14px;margin-bottom:18px;color:#991B1B;font-size:14px;">
        ⚠️ <?= h($page_error) ?>
      </div>
    <?php endif; ?>

    <form method="post" action="/mon-asso-recurrence-save" id="recForm">
      <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token'] ?? '') ?>">

      <div class="rcard">
        <h2>Informations générales</h2>
        <div class="rfield">
          <label>Titre interne (visible uniquement par vous) *</label>
          <input type="text" name="title" required maxlength="255" value="<?= h($prefill['title']) ?>" placeholder="Ex: Cotisation mensuelle Dupont">
        </div>
        <div class="rrow">
          <div class="rfield">
            <label>Client</label>
            <select name="client_id">
              <option value="">— Sélectionner un client —</option>
              <?php foreach ($clients as $c): ?>
                <option value="<?= (int)$c['id'] ?>" <?= ($prefill['client_id'] == $c['id'])?'selected':'' ?>>
                  <?= h($c['display_name']) ?><?= $c['email'] ? ' — ' . h($c['email']) : '' ?>
                </option>
              <?php endforeach; ?>
            </select>
            <div class="hint">Optionnel. Vous pouvez créer un client dans <a href="/mon-asso-clients" style="color:#059669;">le carnet</a>.</div>
          </div>
          <div class="rfield">
            <label>TVA *</label>
            <div class="rvat">
              <?php foreach (['none' => 'Pas de TVA', '5.5' => '5,5%', '10' => '10%', '20' => '20%'] as $val => $lbl): ?>
                <label>
                  <input type="radio" name="vat_mode" value="<?= h($val) ?>" <?= $prefill['vat_mode']===$val?'checked':'' ?>>
                  <span><?= h($lbl) ?></span>
                </label>
              <?php endforeach; ?>
            </div>
            <div class="hint">Les prix sont saisis TTC, le HT est calculé automatiquement.</div>
          </div>
        </div>
      </div>

      <div class="rcard">
        <h2>Fréquence et planning</h2>
        <div class="rrow3">
          <div class="rfield">
            <label>Fréquence *</label>
            <select name="frequency" id="freqSel">
              <option value="daily">Quotidien</option>
              <option value="weekly">Hebdomadaire</option>
              <option value="monthly" selected>Mensuel</option>
              <option value="quarterly">Trimestriel</option>
              <option value="yearly">Annuel</option>
            </select>
          </div>
          <div class="rfield">
            <label>Tous les *</label>
            <input type="number" name="interval_count" min="1" max="60" value="1">
            <div class="hint">Ex: tous les 2 mois → mettre 2</div>
          </div>
          <div class="rfield" id="dayOfMonthField">
            <label>Jour du mois</label>
            <input type="number" name="day_of_month" min="1" max="31" placeholder="ex: 1, 15, 28">
            <div class="hint">Optionnel (mensuel/trimestriel/annuel)</div>
          </div>
        </div>
        <div class="rrow3">
          <div class="rfield">
            <label>Date de début *</label>
            <input type="date" name="start_date" required value="<?= h(date('Y-m-d')) ?>">
          </div>
          <div class="rfield">
            <label>Date de fin</label>
            <input type="date" name="end_date">
            <div class="hint">Optionnel</div>
          </div>
          <div class="rfield">
            <label>Nombre max de factures</label>
            <input type="number" name="max_occurrences" min="1" max="999" placeholder="ex: 12">
            <div class="hint">Optionnel</div>
          </div>
        </div>
      </div>

      <div class="rcard">
        <h2>Lignes de la facture</h2>
        <table class="rlines" id="linesTable">
          <thead>
            <tr>
              <th style="width:55%;">Description</th>
              <th style="width:15%;">Quantité</th>
              <th style="width:20%;">Prix TTC (€)</th>
              <th style="width:10%;"></th>
            </tr>
          </thead>
          <tbody id="linesBody">
            <?php foreach ($prefill['lines'] as $idx => $l): ?>
            <tr>
              <td><input type="text" name="lines[<?= $idx ?>][label]" required maxlength="500" value="<?= h($l['label']) ?>" placeholder="Description du service"></td>
              <td><input type="number" name="lines[<?= $idx ?>][quantity]" step="0.01" min="0" value="<?= h((string)$l['quantity']) ?>" class="qty"></td>
              <td><input type="number" name="lines[<?= $idx ?>][unit_price]" step="0.01" min="0" value="<?= h((string)$l['unit_price']) ?>" class="price" placeholder="0.00"></td>
              <td><button type="button" class="rbtn-line" onclick="removeLine(this)">✕</button></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <div style="margin-top:10px;">
          <button type="button" class="radd-line" onclick="addLine()">+ Ajouter une ligne</button>
        </div>
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
            <input type="checkbox" name="auto_send" value="1">
            <div>
              <div style="font-weight:600;">Envoyer automatiquement par email</div>
              <div style="font-size:12px;color:#64748B;">Si décoché, la facture est créée en brouillon (recommandé).</div>
            </div>
          </label>
          <label class="rswitch-row">
            <input type="checkbox" name="notify_admin" value="1" checked>
            <div>
              <div style="font-weight:600;">Me notifier à chaque génération</div>
              <div style="font-size:12px;color:#64748B;">Email d'information à votre adresse d'admin.</div>
            </div>
          </label>
        </div>
        <div class="rfield" style="margin-top:14px;">
          <label>Notes / Conditions de paiement</label>
          <textarea name="notes" rows="3" placeholder="Conditions de paiement, mentions légales…"><?= h($prefill['notes']) ?></textarea>
        </div>
      </div>

      <div class="ractions">
        <a class="rbtn rbtn-ghost" href="/mon-asso-recurrences">Annuler</a>
        <button class="rbtn rbtn-primary" type="submit">Créer la récurrence</button>
      </div>
    </form>
  </div>

</main>

<script>
let lineIdx = <?= count($prefill['lines']) ?>;
function addLine() {
  const tbody = document.getElementById('linesBody');
  const tr = document.createElement('tr');
  tr.innerHTML = `
    <td><input type="text" name="lines[${lineIdx}][label]" required maxlength="500" placeholder="Description du service"></td>
    <td><input type="number" name="lines[${lineIdx}][quantity]" step="0.01" min="0" value="1" class="qty"></td>
    <td><input type="number" name="lines[${lineIdx}][unit_price]" step="0.01" min="0" value="" class="price" placeholder="0.00"></td>
    <td><button type="button" class="rbtn-line" onclick="removeLine(this)">✕</button></td>
  `;
  tbody.appendChild(tr);
  lineIdx++;
  bindLineEvents();
}
function removeLine(btn) {
  const rows = document.querySelectorAll('#linesBody tr');
  if (rows.length <= 1) { alert('Au moins une ligne est requise.'); return; }
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
  let totalHT = rate > 0 ? (totalTTC / (1 + rate)) : totalTTC;
  let totalTVA = totalTTC - totalHT;
  document.getElementById('totHT').textContent  = totalHT.toFixed(2).replace('.', ',') + ' €';
  document.getElementById('totTVA').textContent = totalTVA.toFixed(2).replace('.', ',') + ' €';
  document.getElementById('totTTC').textContent = totalTTC.toFixed(2).replace('.', ',') + ' €';
}
function bindLineEvents() {
  document.querySelectorAll('#linesBody .qty, #linesBody .price').forEach(i => {
    i.removeEventListener('input', recalc);
    i.addEventListener('input', recalc);
  });
}
document.querySelectorAll('input[name=vat_mode]').forEach(r => r.addEventListener('change', recalc));
document.getElementById('freqSel').addEventListener('change', () => {
  const freq = document.getElementById('freqSel').value;
  document.getElementById('dayOfMonthField').style.display = ['monthly','quarterly','yearly'].includes(freq) ? '' : 'none';
});
bindLineEvents();
recalc();
</script>

<?php render_foot(); ?>
