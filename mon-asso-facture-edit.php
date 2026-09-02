<?php
/**
 * mon-asso-facture-edit.php
 * Édition d'une facture existante
 * v2 — Sous-pack 2 : ajout bouton "Envoyer par email" + lien public + statut envoi
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes-layout.php';
require_once __DIR__ . '/asso-invoice-helpers.php';

require_login();
$user = current_user();

if (empty($user['org_id'])) { http_response_code(403); die('Aucune asso.'); }
$org_id = (int)$user['org_id'];

// [PACK 6.5 - SECURITY] Accès finances obligatoire
require_once __DIR__ . '/finance-permissions.php';
require_finance_access('factures', 'l\'édition de factures');

$invoice_id = (int)($_GET['id'] ?? 0);
if ($invoice_id <= 0) { header('Location: /mon-asso-factures-client'); exit; }

// Charger facture
$stmt = $pdo->prepare("
    SELECT i.*, c.id AS cli_id, c.* FROM asso_invoices i
    LEFT JOIN asso_clients c ON c.id = i.client_id
    WHERE i.id = :id AND i.org_id = :org LIMIT 1
");
$stmt->execute([':id' => $invoice_id, ':org' => $org_id]);
$invoice = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$invoice) { http_response_code(404); die('Facture introuvable.'); }

// Lignes
$stmt = $pdo->prepare("SELECT * FROM asso_invoice_lines WHERE invoice_id = :id ORDER BY line_order");
$stmt->execute([':id' => $invoice_id]);
$lines = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Logs emails (Sous-pack 2)
$emails_log = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM asso_invoice_emails_log WHERE invoice_id = :id ORDER BY id DESC LIMIT 10");
    $stmt->execute([':id' => $invoice_id]);
    $emails_log = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

$flash = $_SESSION['flash_asso_factures'] ?? null;
unset($_SESSION['flash_asso_factures']);

$public_link = 'https://' . ($_SERVER['HTTP_HOST'] ?? 'assokit.fr') . '/facture/' . $invoice['public_uuid'];

render_head('Modifier ' . $invoice['invoice_number']);
render_sidebar('factures');
?>

<div class="main">
    <nav class="crumbs">
        <a href="/mon-asso-factures-client">Mes factures</a>
        <span class="sep">›</span>
        <span class="current">Modifier <?= h($invoice['invoice_number']) ?></span>
    </nav>

    <div class="main-head">
        <div>
            <h1 class="page-title" style="display:flex;align-items:center;gap:11px;"><?= ak_icon_badge('edit','#059669',36) ?><span><?= h($invoice['invoice_number']) ?></span></h1>
            <div class="page-sub">
                Statut : <strong><?= h(ak_asso_invoice_status_label($invoice['status'])) ?></strong>
                <?php if (!empty($invoice['sent_at'])): ?>
                    · 📧 Envoyée le <?= h(date('d/m/Y H:i', strtotime($invoice['sent_at']))) ?>
                <?php endif; ?>
                <?php if (!empty($invoice['view_count']) && (int)$invoice['view_count'] > 0): ?>
                    · 👁 Vue <?= (int)$invoice['view_count'] ?> fois
                <?php endif; ?>
                <?php if (!empty($invoice['client_marked_paid_at'])): ?>
                    · <span style="color:#10B981; font-weight:600;">💚 Client a déclaré avoir payé le <?= h(date('d/m/Y', strtotime($invoice['client_marked_paid_at']))) ?></span>
                <?php endif; ?>
            </div>
        </div>
        <div style="display:flex; gap:8px; flex-wrap:wrap;">
            <?php if (!empty($invoice['pdf_path'])): ?>
                <a href="<?= h($invoice['pdf_path']) ?>" target="_blank" class="btn btn-ghost"><?= ak_icon('inbox',14) ?>PDF</a>
            <?php endif; ?>
            <a href="/mon-asso-recurrence-new?from_invoice=<?= (int)$invoice_id ?>"
               class="btn btn-ghost"
               title="Créer une récurrence à partir de cette facture (auto-génération mensuelle, trimestrielle…)"
               style="border-color:#059669; color:#059669;">
                <?= ak_icon('refresh',14) ?>Rendre récurrente
            </a>
            <a href="/mon-asso-factures-client" class="btn btn-ghost">← Retour</a>
        </div>
    </div>

    <?php if ($flash): ?>
    <div class="alert <?= $flash['type'] === 'success' ? 'alert-success' : 'alert-error' ?>" style="margin-bottom:16px;">
        <?= h($flash['message']) ?>
        <?php if (!empty($flash['pdf_link'])): ?>
            — <a href="<?= h($flash['pdf_link']) ?>" target="_blank" style="font-weight:600;">📥 Télécharger</a>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- ────── BLOC ENVOI EMAIL ────── -->
    <div class="card" style="padding:22px; margin-bottom:16px; background:linear-gradient(135deg, #ECFDF5 0%, #D1FAE5 100%); border-left:4px solid #10B981;">
        <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:14px;">
            <div>
                <h3 style="margin:0 0 4px 0; font-size:15px; color:#065F46;">📧 Envoi de la facture</h3>
                <div style="font-size:13px; color:#047857;">
                    <?php if (empty($invoice['sent_at'])): ?>
                        Pas encore envoyée à <strong><?= h($invoice['email'] ?? '?') ?></strong>
                    <?php else: ?>
                        Envoyée le <strong><?= h(date('d/m/Y H:i', strtotime($invoice['sent_at']))) ?></strong> à <strong><?= h($invoice['sent_to_email'] ?? $invoice['email']) ?></strong>
                    <?php endif; ?>
                </div>
            </div>
            <div style="display:flex; gap:8px;">
                <a href="<?= h($public_link) ?>" target="_blank" class="btn btn-ghost" style="background:white;"><?= ak_icon('link',14) ?>Voir page publique</a>
                <form method="POST" action="/mon-asso-facture-send" style="display:inline;">
                    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                    <input type="hidden" name="invoice_id" value="<?= (int)$invoice_id ?>">
                    <input type="hidden" name="email_type" value="<?= empty($invoice['sent_at']) ? 'initial' : 'manual' ?>">
                    <button type="submit" class="btn btn-primary" style="padding:9px 18px;">
                        <?= empty($invoice['sent_at']) ? '📤 Envoyer la facture par email' : '🔁 Renvoyer (rappel)' ?>
                    </button>
                </form>
            </div>
        </div>

        <!-- Lien public partageable -->
        <div style="margin-top:14px; padding:10px 12px; background:white; border-radius:8px; display:flex; align-items:center; gap:10px; font-size:12px;">
            <span style="color:#6B7280;">🔗 Lien à partager :</span>
            <code id="public-link-text" style="flex:1; font-family:monospace; color:#059669; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;"><?= h($public_link) ?></code>
            <button type="button" onclick="copyLink()" class="btn btn-ghost" style="padding:4px 10px; font-size:11px;"><?= ak_icon('clipboard',13) ?>Copier</button>
        </div>
    </div>

    <!-- ────── HISTORIQUE EMAILS ────── -->
    <?php if (!empty($emails_log)): ?>
    <details class="card" style="padding:0; margin-bottom:16px; overflow:hidden;">
        <summary style="padding:14px 22px; cursor:pointer; font-size:14px; font-weight:600; background:#F9FAFB; display:flex; align-items:center; gap:8px;">
            <?= ak_icon('send',16) ?>Historique des envois (<?= count($emails_log) ?>)
        </summary>
        <table style="width:100%; border-collapse:collapse;">
            <thead style="background:#F9FAFB;">
                <tr>
                    <th style="text-align:left; padding:10px 14px; font-size:11px; color:#6B7280; text-transform:uppercase;">Date</th>
                    <th style="text-align:left; padding:10px 14px; font-size:11px; color:#6B7280; text-transform:uppercase;">Type</th>
                    <th style="text-align:left; padding:10px 14px; font-size:11px; color:#6B7280; text-transform:uppercase;">Destinataire</th>
                    <th style="text-align:center; padding:10px 14px; font-size:11px; color:#6B7280; text-transform:uppercase;">Statut</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($emails_log as $log):
                    $statusColors = [
                        'sent' => ['#D1FAE5', '#065F46', '✅ Envoyé'],
                        'queued' => ['#FEF3C7', '#92400E', '⏳ En attente'],
                        'failed' => ['#FEE2E2', '#991B1B', '❌ Échec'],
                        'bounced' => ['#FEE2E2', '#991B1B', '↩ Rejeté'],
                    ];
                    $col = $statusColors[$log['status']] ?? ['#E5E7EB', '#374151', $log['status']];
                    $type_labels = [
                        'initial' => '📤 Envoi initial',
                        'dunning1' => '🔔 Relance 1',
                        'dunning2' => '🔔 Relance 2',
                        'dunning3' => '⚠ Mise en demeure',
                        'manual' => '✋ Manuel',
                    ];
                ?>
                <tr style="border-top:1px solid #E5E7EB;">
                    <td style="padding:10px 14px; font-size:13px;"><?= h(date('d/m/Y H:i', strtotime($log['sent_at'] ?? $log['created_at']))) ?></td>
                    <td style="padding:10px 14px; font-size:13px;"><?= h($type_labels[$log['email_type']] ?? $log['email_type']) ?></td>
                    <td style="padding:10px 14px; font-size:13px; color:#6B7280;"><?= h($log['recipient_email']) ?></td>
                    <td style="padding:10px 14px; text-align:center;">
                        <span style="display:inline-block; padding:3px 10px; border-radius:12px; font-size:11px; font-weight:600; background:<?= $col[0] ?>; color:<?= $col[1] ?>;">
                            <?= h($col[2]) ?>
                        </span>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </details>
    <?php endif; ?>

    <!-- ────── FORMULAIRE ÉDITION ────── -->
    <form method="POST" action="/mon-asso-facture-save">
        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
        <input type="hidden" name="action" value="edit">
        <input type="hidden" name="invoice_id" value="<?= (int)$invoice_id ?>">
        <input type="hidden" name="client_id" value="<?= (int)$invoice['client_id'] ?>">

        <!-- Client -->
        <div class="card" style="padding:22px; margin-bottom:16px;">
            <h3 style="margin:0 0 16px 0; font-size:15px; display:inline-flex; align-items:center; gap:8px;"><?= ak_icon('user',18) ?>Client</h3>
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:14px;">
                <div>
                    <label style="display:block; font-size:12px; color:#6B7280; margin-bottom:6px;">Type</label>
                    <select name="client_type" style="width:100%; padding:10px; border:1px solid #E5E7EB; border-radius:8px;">
                        <option value="company" <?= ($invoice['client_type'] ?? 'company') === 'company' ? 'selected' : '' ?>>Entreprise / Asso</option>
                        <option value="individual" <?= ($invoice['client_type'] ?? '') === 'individual' ? 'selected' : '' ?>>Particulier</option>
                    </select>
                </div>
                <div>
                    <label style="display:block; font-size:12px; color:#6B7280; margin-bottom:6px;">Nom affiché *</label>
                    <input type="text" name="display_name" value="<?= h($invoice['display_name'] ?? '') ?>" required style="width:100%; padding:10px; border:1px solid #E5E7EB; border-radius:8px;">
                </div>
                <div>
                    <label style="display:block; font-size:12px; color:#6B7280; margin-bottom:6px;">Email *</label>
                    <input type="email" name="email" value="<?= h($invoice['email'] ?? '') ?>" required style="width:100%; padding:10px; border:1px solid #E5E7EB; border-radius:8px;">
                </div>
                <div>
                    <label style="display:block; font-size:12px; color:#6B7280; margin-bottom:6px;">Téléphone</label>
                    <input type="text" name="phone" value="<?= h($invoice['phone'] ?? '') ?>" style="width:100%; padding:10px; border:1px solid #E5E7EB; border-radius:8px;">
                </div>
                <div style="grid-column:1/-1;">
                    <label style="display:block; font-size:12px; color:#6B7280; margin-bottom:6px;">Raison sociale</label>
                    <input type="text" name="legal_name" value="<?= h($invoice['legal_name'] ?? '') ?>" style="width:100%; padding:10px; border:1px solid #E5E7EB; border-radius:8px;">
                </div>
                <div>
                    <label style="display:block; font-size:12px; color:#6B7280; margin-bottom:6px;">Prénom contact</label>
                    <input type="text" name="contact_first_name" value="<?= h($invoice['contact_first_name'] ?? '') ?>" style="width:100%; padding:10px; border:1px solid #E5E7EB; border-radius:8px;">
                </div>
                <div>
                    <label style="display:block; font-size:12px; color:#6B7280; margin-bottom:6px;">Nom contact</label>
                    <input type="text" name="contact_last_name" value="<?= h($invoice['contact_last_name'] ?? '') ?>" style="width:100%; padding:10px; border:1px solid #E5E7EB; border-radius:8px;">
                </div>

                <!-- Adresse avec autocomplétion -->
                <div style="grid-column:1/-1; position:relative;">
                    <label style="display:block; font-size:12px; color:#6B7280; margin-bottom:6px;">
                        Adresse <span style="color:#10B981;">— autocomplétion FR active</span>
                    </label>
                    <input type="text" name="address_street" id="address_street" value="<?= h($invoice['address_street'] ?? '') ?>" placeholder="N° + rue" autocomplete="off" oninput="searchAddress(this.value)" onblur="setTimeout(hideAddressList, 200)" style="width:100%; padding:10px; border:1px solid #E5E7EB; border-radius:8px; margin-bottom:6px;">
                    <div id="address-suggestions" style="display:none; position:absolute; top:80px; left:0; right:0; background:white; border:1px solid #E5E7EB; border-radius:8px; box-shadow:0 4px 12px rgba(0,0,0,0.1); z-index:1000; max-height:300px; overflow-y:auto;"></div>
                    <input type="text" name="address_complement" value="<?= h($invoice['address_complement'] ?? '') ?>" placeholder="Complément" style="width:100%; padding:10px; border:1px solid #E5E7EB; border-radius:8px;">
                </div>
                <div>
                    <label style="display:block; font-size:12px; color:#6B7280; margin-bottom:6px;">CP</label>
                    <input type="text" name="address_zip" id="address_zip" value="<?= h($invoice['address_zip'] ?? '') ?>" style="width:100%; padding:10px; border:1px solid #E5E7EB; border-radius:8px;">
                </div>
                <div>
                    <label style="display:block; font-size:12px; color:#6B7280; margin-bottom:6px;">Ville</label>
                    <input type="text" name="address_city" id="address_city" value="<?= h($invoice['address_city'] ?? '') ?>" style="width:100%; padding:10px; border:1px solid #E5E7EB; border-radius:8px;">
                </div>
                <div style="grid-column:1/-1;">
                    <label style="display:block; font-size:12px; color:#6B7280; margin-bottom:6px;">SIREN</label>
                    <input type="text" name="siren" value="<?= h($invoice['siren'] ?? '') ?>" style="width:100%; padding:10px; border:1px solid #E5E7EB; border-radius:8px;">
                </div>
            </div>
        </div>

        <!-- Lignes -->
        <div class="card" style="padding:22px; margin-bottom:16px;">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px;">
                <h3 style="margin:0; font-size:15px;">📦 Désignations</h3>
                <button type="button" onclick="addLine()" class="btn btn-ghost" style="padding:6px 12px; font-size:13px;">+ Ajouter une ligne</button>
            </div>
            <div id="lines-container"></div>
        </div>

        <!-- Récap -->
        <div class="card" style="padding:18px; margin-bottom:16px; background:#F9FAFB;">
            <h3 style="margin:0 0 12px 0; font-size:14px; color:#6B7280; text-transform:uppercase; display:inline-flex; align-items:center; gap:8px;"><?= ak_icon('chart',16) ?>Total</h3>
            <div style="display:grid; grid-template-columns:1fr 1fr 1fr; gap:14px;">
                <div>
                    <div style="font-size:11px; color:#6B7280;">Total HT</div>
                    <div id="total-ht" style="font-size:20px; font-weight:700; font-variant-numeric:tabular-nums;">—</div>
                </div>
                <div>
                    <div style="font-size:11px; color:#6B7280;">TVA</div>
                    <div id="total-vat" style="font-size:20px; font-weight:700; font-variant-numeric:tabular-nums;">—</div>
                </div>
                <div style="background:#10B981; padding:10px 14px; border-radius:8px; margin:-10px -14px;">
                    <div style="font-size:11px; color:#D1FAE5;">⭐ Total TTC</div>
                    <div id="total-ttc" style="font-size:20px; font-weight:700; color:#fff; font-variant-numeric:tabular-nums;">—</div>
                </div>
            </div>
        </div>

        <!-- Dates -->
        <div class="card" style="padding:22px; margin-bottom:16px;">
            <h3 style="margin:0 0 16px 0; font-size:15px; display:inline-flex; align-items:center; gap:8px;"><?= ak_icon('calendar',18) ?>Dates et statut</h3>
            <div style="display:grid; grid-template-columns:1fr 1fr 1fr; gap:14px;">
                <div>
                    <label style="display:block; font-size:12px; color:#6B7280; margin-bottom:6px;">Date d'émission</label>
                    <input type="date" name="issued_at" value="<?= h(date('Y-m-d', strtotime($invoice['issued_at']))) ?>" required style="width:100%; padding:10px; border:1px solid #E5E7EB; border-radius:8px;">
                </div>
                <div>
                    <label style="display:block; font-size:12px; color:#6B7280; margin-bottom:6px;">Échéance (jours)</label>
                    <input type="number" name="due_days" value="<?= max(1, (int) floor((strtotime($invoice['due_at']) - strtotime($invoice['issued_at'])) / 86400)) ?>" min="0" max="365" required style="width:100%; padding:10px; border:1px solid #E5E7EB; border-radius:8px;">
                </div>
                <div>
                    <label style="display:block; font-size:12px; color:#6B7280; margin-bottom:6px;">Statut actuel</label>
                    <input type="text" value="<?= h(ak_asso_invoice_status_label($invoice['status'])) ?>" disabled style="width:100%; padding:10px; border:1px solid #E5E7EB; border-radius:8px; background:#F9FAFB;">
                </div>
                <div style="grid-column:1/-1;">
                    <label style="display:block; font-size:12px; color:#6B7280; margin-bottom:6px;">Description</label>
                    <textarea name="description" rows="2" style="width:100%; padding:10px; border:1px solid #E5E7EB; border-radius:8px; resize:vertical; font-family:inherit;"><?= h($invoice['description'] ?? '') ?></textarea>
                </div>
                <div style="grid-column:1/-1;">
                    <label style="display:block; font-size:12px; color:#6B7280; margin-bottom:6px;">Notes internes</label>
                    <textarea name="internal_notes" rows="2" style="width:100%; padding:10px; border:1px solid #E5E7EB; border-radius:8px; resize:vertical; font-family:inherit;"><?= h($invoice['internal_notes'] ?? '') ?></textarea>
                </div>
            </div>
        </div>

        <!-- Actions -->
        <div style="display:flex; gap:10px; justify-content:flex-end; padding:18px; background:white; border:1px solid #E5E7EB; border-radius:12px; position:sticky; bottom:12px;">
            <a href="/mon-asso-factures-client" class="btn btn-ghost">Annuler</a>
            <button type="submit" class="btn btn-primary" style="padding:10px 20px;">💾 Enregistrer + régénérer PDF</button>
        </div>
    </form>
</div>

<script>
const VAT_OPTIONS = [
    {value: '', label: 'Pas de TVA'},
    {value: '5.5', label: 'TVA 5,5%'},
    {value: '10', label: 'TVA 10%'},
    {value: '20', label: 'TVA 20%'},
];
const EXISTING_LINES = <?= json_encode(array_map(function($l){
    return [
        'designation' => $l['designation'],
        'quantity'    => (float)$l['quantity'],
        'unit_price_ht' => round($l['unit_price_ht_cents'] / 100, 2),
        'vat_rate'    => $l['vat_rate'],
    ];
}, $lines)) ?>;
let lineIdx = 0;

function addLine(prefill = {}) {
    const idx = lineIdx++;
    const vatVal = prefill.vat_rate !== null && prefill.vat_rate !== undefined ? prefill.vat_rate : '';
    const vatOpts = VAT_OPTIONS.map(o => `<option value="${o.value}" ${String(o.value) === String(vatVal) ? 'selected' : ''}>${o.label}</option>`).join('');
    const html = `
        <div class="invoice-line" data-idx="${idx}" style="display:grid; grid-template-columns: 1fr 70px 100px 120px 100px 30px; gap:8px; padding:10px 0; border-bottom:1px solid #F3F4F6;">
            <input type="text" name="lines[${idx}][designation]" placeholder="Désignation" required value="${(prefill.designation || '').replace(/"/g,'&quot;')}" style="padding:8px 10px; border:1px solid #E5E7EB; border-radius:6px; font-size:13px;">
            <input type="number" name="lines[${idx}][quantity]" step="0.01" min="0" value="${prefill.quantity || '1'}" oninput="recalc()" style="padding:8px 10px; border:1px solid #E5E7EB; border-radius:6px; font-size:13px; text-align:right;">
            <input type="number" name="lines[${idx}][unit_price_ht]" step="0.01" min="0" placeholder="PU HT" value="${prefill.unit_price_ht || ''}" oninput="recalc()" required style="padding:8px 10px; border:1px solid #E5E7EB; border-radius:6px; font-size:13px; text-align:right;">
            <select name="lines[${idx}][vat_rate]" onchange="recalc()" style="padding:8px 10px; border:1px solid #E5E7EB; border-radius:6px; font-size:13px;">${vatOpts}</select>
            <div class="line-total" style="padding:8px 10px; background:#F9FAFB; border-radius:6px; font-size:13px; text-align:right; font-weight:600; font-variant-numeric:tabular-nums;">0,00 €</div>
            <button type="button" onclick="this.closest('.invoice-line').remove(); recalc();" style="background:transparent; border:none; color:#DC2626; cursor:pointer; font-size:18px;">×</button>
        </div>
    `;
    document.getElementById('lines-container').insertAdjacentHTML('beforeend', html);
    recalc();
}

function recalc() {
    let totHT = 0, totVAT = 0;
    document.querySelectorAll('.invoice-line').forEach(line => {
        const q = parseFloat(line.querySelector('[name*="[quantity]"]').value) || 0;
        const p = parseFloat(line.querySelector('[name*="[unit_price_ht]"]').value) || 0;
        const r = parseFloat(line.querySelector('[name*="[vat_rate]"]').value) || 0;
        const ht = q * p;
        const vat = r > 0 ? ht * r / 100 : 0;
        line.querySelector('.line-total').textContent = ht.toFixed(2).replace('.', ',') + ' €';
        totHT += ht;
        totVAT += vat;
    });
    const fmt = n => n.toFixed(2).replace('.', ',') + ' €';
    document.getElementById('total-ht').textContent = fmt(totHT);
    document.getElementById('total-vat').textContent = fmt(totVAT);
    document.getElementById('total-ttc').textContent = fmt(totHT + totVAT);
}

// AUTOCOMPLÉTION ADRESSE FRANÇAISE
let addressTimer = null;
function searchAddress(query) {
    clearTimeout(addressTimer);
    if (!query || query.length < 3) { hideAddressList(); return; }
    addressTimer = setTimeout(() => {
        fetch('https://api-adresse.data.gouv.fr/search/?q=' + encodeURIComponent(query) + '&limit=5&autocomplete=1')
            .then(r => r.json())
            .then(data => {
                const list = document.getElementById('address-suggestions');
                if (!data.features || data.features.length === 0) { hideAddressList(); return; }
                list.innerHTML = data.features.map(f => {
                    const p = f.properties;
                    return `
                        <div onclick="selectAddress('${(p.name || '').replace(/'/g, "\\'")}', '${p.postcode || ''}', '${(p.city || '').replace(/'/g, "\\'")}')"
                             style="padding:10px 14px; cursor:pointer; border-bottom:1px solid #F3F4F6; font-size:13px;"
                             onmouseover="this.style.background='#F0FDF4'"
                             onmouseout="this.style.background='white'">
                            <div style="color:#059669; font-size:12px;">📍</div>
                            <div style="font-weight:600; color:#111827;">${p.name || ''}</div>
                            <div style="color:#6B7280; font-size:12px;">${p.postcode || ''} ${p.city || ''}</div>
                        </div>
                    `;
                }).join('');
                list.style.display = 'block';
            })
            .catch(() => hideAddressList());
    }, 250);
}
function selectAddress(street, zip, city) {
    document.getElementById('address_street').value = street;
    document.getElementById('address_zip').value = zip;
    document.getElementById('address_city').value = city;
    hideAddressList();
}
function hideAddressList() {
    document.getElementById('address-suggestions').style.display = 'none';
}

// COPY LIEN PUBLIC
function copyLink() {
    const text = document.getElementById('public-link-text').textContent;
    navigator.clipboard.writeText(text).then(() => {
        alert('✅ Lien copié dans le presse-papier !');
    }, () => {
        alert('Impossible de copier. Sélectionne le texte manuellement.');
    });
}

// Init
EXISTING_LINES.forEach(l => addLine(l));
if (EXISTING_LINES.length === 0) addLine();
</script>

<?php render_foot(); ?>
