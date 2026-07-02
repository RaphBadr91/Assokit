<?php
/**
 * mon-asso-devis-edit.php — Édition devis + envoi email + conversion
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes-layout.php';
require_once __DIR__ . '/asso-invoice-helpers.php';
require_once __DIR__ . '/asso-quote-helpers.php';

require_login();
$user = current_user();
if (empty($user['org_id'])) { http_response_code(403); die('Aucune asso.'); }
$org_id = (int)$user['org_id'];

$is_admin = false;
try {
    $stmt = $pdo->prepare("SELECT role FROM users WHERE id = :id");
    $stmt->execute([':id' => (int)$user['id']]);
    $is_admin = in_array($stmt->fetchColumn(), ['admin', 'coordinator'], true);
} catch (Throwable $e) {}
if (!$is_admin) { http_response_code(403); die('Accès refusé.'); }

$quote_id = (int)($_GET['id'] ?? 0);
if ($quote_id <= 0) { header('Location: /mon-asso-devis'); exit; }

$stmt = $pdo->prepare("SELECT q.*, c.id AS cli_id, c.* FROM asso_quotes q LEFT JOIN asso_clients c ON c.id = q.client_id WHERE q.id = :id AND q.org_id = :org LIMIT 1");
$stmt->execute([':id' => $quote_id, ':org' => $org_id]);
$quote = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$quote) { http_response_code(404); die('Devis introuvable.'); }

$stmt = $pdo->prepare("SELECT * FROM asso_quote_lines WHERE quote_id = :id ORDER BY line_order");
$stmt->execute([':id' => $quote_id]);
$lines = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

$flash = $_SESSION['flash_asso_devis'] ?? null;
unset($_SESSION['flash_asso_devis']);

$public_link = 'https://' . ($_SERVER['HTTP_HOST'] ?? 'assokit.fr') . '/devis/' . $quote['public_uuid'];
$is_locked = in_array($quote['status'], ['signed', 'converted', 'cancelled'], true);

render_head('Devis ' . $quote['quote_number']);
render_sidebar('devis');
?>

<div class="main">
    <nav class="crumbs">
        <a href="/mon-asso-devis">Mes devis</a>
        <span class="sep">›</span>
        <span class="current"><?= h($quote['quote_number']) ?></span>
    </nav>

    <div class="main-head">
        <div>
            <h1 class="page-title">📝 <?= h($quote['quote_number']) ?></h1>
            <div class="page-sub">
                Statut : <strong><?= h(ak_asso_quote_status_label($quote['status'])) ?></strong>
                <?php if (!empty($quote['signed_at'])): ?>
                    · ✓ Signé le <?= h(date('d/m/Y H:i', strtotime($quote['signed_at']))) ?>
                <?php endif; ?>
                <?php if (!empty($quote['view_count']) && (int)$quote['view_count'] > 0): ?>
                    · 👁 Vu <?= (int)$quote['view_count'] ?> fois
                <?php endif; ?>
                <?php if (!empty($quote['converted_to_invoice_id'])): ?>
                    · → <a href="/mon-asso-facture-edit?id=<?= (int)$quote['converted_to_invoice_id'] ?>" style="color:#7E22CE;">Facture #<?= (int)$quote['converted_to_invoice_id'] ?></a>
                <?php endif; ?>
            </div>
        </div>
        <div style="display:flex; gap:8px;">
            <?php if (!empty($quote['pdf_path'])): ?>
                <a href="<?= h($quote['pdf_path']) ?>" target="_blank" class="btn btn-ghost">📥 PDF</a>
            <?php endif; ?>
            <a href="/mon-asso-devis" class="btn btn-ghost">← Retour</a>
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

    <!-- BLOC ENVOI EMAIL -->
    <?php if (!$is_locked): ?>
    <div class="card" style="padding:22px; margin-bottom:16px; background:linear-gradient(135deg, #FAF5FF 0%, #F3E8FF 100%); border-left:4px solid #7E22CE;">
        <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:14px;">
            <div>
                <h3 style="margin:0 0 4px 0; font-size:15px; color:#581C87;">📧 Envoi du devis</h3>
                <div style="font-size:13px; color:#7E22CE;">
                    <?php if (empty($quote['sent_at'])): ?>
                        Pas encore envoyé à <strong><?= h($quote['email'] ?? '?') ?></strong>
                    <?php else: ?>
                        Envoyé le <strong><?= h(date('d/m/Y H:i', strtotime($quote['sent_at']))) ?></strong>
                    <?php endif; ?>
                </div>
            </div>
            <div style="display:flex; gap:8px;">
                <a href="<?= h($public_link) ?>" target="_blank" class="btn btn-ghost" style="background:white;">🔗 Voir page publique</a>
                <form method="POST" action="/mon-asso-devis-send" style="display:inline;">
                    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                    <input type="hidden" name="quote_id" value="<?= (int)$quote_id ?>">
                    <button type="submit" class="btn btn-primary" style="padding:9px 18px; background:#7E22CE; border-color:#7E22CE;">
                        <?= empty($quote['sent_at']) ? '📤 Envoyer le devis par email' : '🔁 Renvoyer' ?>
                    </button>
                </form>
            </div>
        </div>
        <div style="margin-top:14px; padding:10px 12px; background:white; border-radius:8px; display:flex; align-items:center; gap:10px; font-size:12px;">
            <span style="color:#6B7280;">🔗 Lien à partager :</span>
            <code id="public-link-text" style="flex:1; font-family:monospace; color:#7E22CE; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;"><?= h($public_link) ?></code>
            <button type="button" onclick="copyLink()" class="btn btn-ghost" style="padding:4px 10px; font-size:11px;">📋 Copier</button>
        </div>
    </div>
    <?php endif; ?>

    <!-- CONVERSION -->
    <?php if ($quote['status'] === 'signed' && empty($quote['converted_to_invoice_id'])): ?>
    <div class="card" style="padding:22px; margin-bottom:16px; background:linear-gradient(135deg, #ECFDF5 0%, #D1FAE5 100%); border:2px solid #10B981;">
        <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:14px;">
            <div>
                <h3 style="margin:0 0 4px 0; font-size:15px; color:#065F46;">✓ Devis signé !</h3>
                <div style="font-size:13px; color:#047857;">
                    Tu peux maintenant convertir ce devis en facture en 1 clic.
                </div>
            </div>
            <form method="POST" action="/mon-asso-devis-convert" onsubmit="return confirm('Convertir ce devis en facture ?');">
                <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                <input type="hidden" name="quote_id" value="<?= (int)$quote_id ?>">
                <button type="submit" class="btn btn-primary" style="padding:10px 20px;">→ Convertir en facture</button>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($is_locked): ?>
    <div class="card" style="padding:18px; margin-bottom:16px; background:#FEF3C7; border-left:4px solid #F59E0B;">
        <strong>🔒 Ce devis est <?= h(ak_asso_quote_status_label($quote['status'])) ?> et ne peut plus être modifié.</strong>
    </div>
    <?php endif; ?>

    <form method="POST" action="/mon-asso-devis-save">
        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
        <input type="hidden" name="action" value="edit">
        <input type="hidden" name="quote_id" value="<?= (int)$quote_id ?>">
        <input type="hidden" name="client_id" value="<?= (int)$quote['client_id'] ?>">

        <fieldset <?= $is_locked ? 'disabled' : '' ?> style="border:none; padding:0; margin:0;">

        <div class="card" style="padding:22px; margin-bottom:16px;">
            <h3 style="margin:0 0 16px 0; font-size:15px;">👤 Client</h3>
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:14px;">
                <div><label style="display:block; font-size:12px; color:#6B7280; margin-bottom:6px;">Type</label>
                    <select name="client_type" style="width:100%; padding:10px; border:1px solid #E5E7EB; border-radius:8px;">
                        <option value="company" <?= ($quote['client_type'] ?? 'company') === 'company' ? 'selected' : '' ?>>Entreprise / Asso</option>
                        <option value="individual" <?= ($quote['client_type'] ?? '') === 'individual' ? 'selected' : '' ?>>Particulier</option>
                    </select>
                </div>
                <div><label style="display:block; font-size:12px; color:#6B7280; margin-bottom:6px;">Nom *</label>
                    <input type="text" name="display_name" value="<?= h($quote['display_name'] ?? '') ?>" required style="width:100%; padding:10px; border:1px solid #E5E7EB; border-radius:8px;">
                </div>
                <div><label style="display:block; font-size:12px; color:#6B7280; margin-bottom:6px;">Email *</label>
                    <input type="email" name="email" value="<?= h($quote['email'] ?? '') ?>" required style="width:100%; padding:10px; border:1px solid #E5E7EB; border-radius:8px;">
                </div>
                <div><label style="display:block; font-size:12px; color:#6B7280; margin-bottom:6px;">Téléphone</label>
                    <input type="text" name="phone" value="<?= h($quote['phone'] ?? '') ?>" style="width:100%; padding:10px; border:1px solid #E5E7EB; border-radius:8px;">
                </div>
                <div style="grid-column:1/-1;"><label style="display:block; font-size:12px; color:#6B7280; margin-bottom:6px;">Raison sociale</label>
                    <input type="text" name="legal_name" value="<?= h($quote['legal_name'] ?? '') ?>" style="width:100%; padding:10px; border:1px solid #E5E7EB; border-radius:8px;">
                </div>
                <div><label style="display:block; font-size:12px; color:#6B7280; margin-bottom:6px;">Prénom contact</label>
                    <input type="text" name="contact_first_name" value="<?= h($quote['contact_first_name'] ?? '') ?>" style="width:100%; padding:10px; border:1px solid #E5E7EB; border-radius:8px;">
                </div>
                <div><label style="display:block; font-size:12px; color:#6B7280; margin-bottom:6px;">Nom contact</label>
                    <input type="text" name="contact_last_name" value="<?= h($quote['contact_last_name'] ?? '') ?>" style="width:100%; padding:10px; border:1px solid #E5E7EB; border-radius:8px;">
                </div>
                <div style="grid-column:1/-1; position:relative;"><label style="display:block; font-size:12px; color:#6B7280; margin-bottom:6px;">Adresse <span style="color:#10B981;">— autocomplétion FR active</span></label>
                    <input type="text" name="address_street" id="address_street" value="<?= h($quote['address_street'] ?? '') ?>" placeholder="N° + rue" autocomplete="off" oninput="searchAddress(this.value)" onblur="setTimeout(hideAddressList, 200)" style="width:100%; padding:10px; border:1px solid #E5E7EB; border-radius:8px; margin-bottom:6px;">
                    <div id="address-suggestions" style="display:none; position:absolute; top:80px; left:0; right:0; background:white; border:1px solid #E5E7EB; border-radius:8px; box-shadow:0 4px 12px rgba(0,0,0,0.1); z-index:1000; max-height:300px; overflow-y:auto;"></div>
                    <input type="text" name="address_complement" value="<?= h($quote['address_complement'] ?? '') ?>" placeholder="Complément" style="width:100%; padding:10px; border:1px solid #E5E7EB; border-radius:8px;">
                </div>
                <div><label style="display:block; font-size:12px; color:#6B7280; margin-bottom:6px;">CP</label>
                    <input type="text" name="address_zip" id="address_zip" value="<?= h($quote['address_zip'] ?? '') ?>" style="width:100%; padding:10px; border:1px solid #E5E7EB; border-radius:8px;">
                </div>
                <div><label style="display:block; font-size:12px; color:#6B7280; margin-bottom:6px;">Ville</label>
                    <input type="text" name="address_city" id="address_city" value="<?= h($quote['address_city'] ?? '') ?>" style="width:100%; padding:10px; border:1px solid #E5E7EB; border-radius:8px;">
                </div>
                <div style="grid-column:1/-1;"><label style="display:block; font-size:12px; color:#6B7280; margin-bottom:6px;">SIREN</label>
                    <input type="text" name="siren" value="<?= h($quote['siren'] ?? '') ?>" style="width:100%; padding:10px; border:1px solid #E5E7EB; border-radius:8px;">
                </div>
            </div>
        </div>

        <div class="card" style="padding:22px; margin-bottom:16px;">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px;">
                <h3 style="margin:0; font-size:15px;">📦 Désignations</h3>
                <button type="button" onclick="addLine()" class="btn btn-ghost" style="padding:6px 12px; font-size:13px;">+ Ajouter une ligne</button>
            </div>
            <div id="lines-container"></div>
        </div>

        <div class="card" style="padding:18px; margin-bottom:16px; background:#F9FAFB;">
            <h3 style="margin:0 0 12px 0; font-size:14px; color:#6B7280; text-transform:uppercase;">📊 Total</h3>
            <div style="display:grid; grid-template-columns:1fr 1fr 1fr; gap:14px;">
                <div><div style="font-size:11px; color:#6B7280;">Total HT</div><div id="total-ht" style="font-size:20px; font-weight:700; font-variant-numeric:tabular-nums;">—</div></div>
                <div><div style="font-size:11px; color:#6B7280;">TVA</div><div id="total-vat" style="font-size:20px; font-weight:700; font-variant-numeric:tabular-nums;">—</div></div>
                <div style="background:#7E22CE; padding:10px 14px; border-radius:8px; margin:-10px -14px;">
                    <div style="font-size:11px; color:#E9D5FF;">⭐ Total TTC</div>
                    <div id="total-ttc" style="font-size:20px; font-weight:700; color:#fff; font-variant-numeric:tabular-nums;">—</div>
                </div>
            </div>
        </div>

        <div class="card" style="padding:22px; margin-bottom:16px;">
            <h3 style="margin:0 0 16px 0; font-size:15px;">📅 Dates et conditions</h3>
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:14px;">
                <div><label style="display:block; font-size:12px; color:#6B7280; margin-bottom:6px;">Date d'émission</label>
                    <input type="date" name="issued_at" value="<?= h(date('Y-m-d', strtotime($quote['issued_at']))) ?>" required style="width:100%; padding:10px; border:1px solid #E5E7EB; border-radius:8px;">
                </div>
                <div><label style="display:block; font-size:12px; color:#6B7280; margin-bottom:6px;">Validité (jours)</label>
                    <input type="number" name="validity_days" value="<?= max(1, (int) round((strtotime($quote['expires_at']) - strtotime($quote['issued_at'])) / 86400)) ?>" min="1" max="365" required style="width:100%; padding:10px; border:1px solid #E5E7EB; border-radius:8px;">
                </div>
                <div style="grid-column:1/-1;"><label style="display:block; font-size:12px; color:#6B7280; margin-bottom:6px;">Description</label>
                    <textarea name="description" rows="2" style="width:100%; padding:10px; border:1px solid #E5E7EB; border-radius:8px; resize:vertical; font-family:inherit;"><?= h($quote['description'] ?? '') ?></textarea>
                </div>
                <div style="grid-column:1/-1;"><label style="display:block; font-size:12px; color:#6B7280; margin-bottom:6px;">Conditions</label>
                    <textarea name="terms" rows="3" style="width:100%; padding:10px; border:1px solid #E5E7EB; border-radius:8px; resize:vertical; font-family:inherit;"><?= h($quote['terms'] ?? '') ?></textarea>
                </div>
                <div style="grid-column:1/-1;"><label style="display:block; font-size:12px; color:#6B7280; margin-bottom:6px;">Notes internes</label>
                    <textarea name="internal_notes" rows="2" style="width:100%; padding:10px; border:1px solid #E5E7EB; border-radius:8px; resize:vertical; font-family:inherit;"><?= h($quote['internal_notes'] ?? '') ?></textarea>
                </div>
            </div>
        </div>

        <?php if (!$is_locked): ?>
        <div style="display:flex; gap:10px; justify-content:flex-end; padding:18px; background:white; border:1px solid #E5E7EB; border-radius:12px; position:sticky; bottom:12px;">
            <a href="/mon-asso-devis" class="btn btn-ghost">Annuler</a>
            <button type="submit" class="btn btn-primary" style="padding:10px 20px; background:#7E22CE; border-color:#7E22CE;">💾 Enregistrer + régénérer PDF</button>
        </div>
        <?php endif; ?>

        </fieldset>
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
    return ['designation' => $l['designation'], 'quantity' => (float)$l['quantity'], 'unit_price_ht' => round($l['unit_price_ht_cents'] / 100, 2), 'vat_rate' => $l['vat_rate']];
}, $lines)) ?>;
let lineIdx = 0;

function addLine(prefill = {}) {
    const idx = lineIdx++;
    const vatVal = prefill.vat_rate !== null && prefill.vat_rate !== undefined ? prefill.vat_rate : '';
    const vatOpts = VAT_OPTIONS.map(o => `<option value="${o.value}" ${String(o.value) === String(vatVal) ? 'selected' : ''}>${o.label}</option>`).join('');
    const html = `<div class="invoice-line" data-idx="${idx}" style="display:grid; grid-template-columns: 1fr 70px 100px 120px 100px 30px; gap:8px; padding:10px 0; border-bottom:1px solid #F3F4F6;"><input type="text" name="lines[${idx}][designation]" placeholder="Désignation" required value="${(prefill.designation || '').replace(/"/g,'&quot;')}" style="padding:8px 10px; border:1px solid #E5E7EB; border-radius:6px; font-size:13px;"><input type="number" name="lines[${idx}][quantity]" step="0.01" min="0" value="${prefill.quantity || '1'}" oninput="recalc()" style="padding:8px 10px; border:1px solid #E5E7EB; border-radius:6px; font-size:13px; text-align:right;"><input type="number" name="lines[${idx}][unit_price_ht]" step="0.01" min="0" placeholder="PU HT" value="${prefill.unit_price_ht || ''}" oninput="recalc()" required style="padding:8px 10px; border:1px solid #E5E7EB; border-radius:6px; font-size:13px; text-align:right;"><select name="lines[${idx}][vat_rate]" onchange="recalc()" style="padding:8px 10px; border:1px solid #E5E7EB; border-radius:6px; font-size:13px;">${vatOpts}</select><div class="line-total" style="padding:8px 10px; background:#F9FAFB; border-radius:6px; font-size:13px; text-align:right; font-weight:600; font-variant-numeric:tabular-nums;">0,00 €</div><button type="button" onclick="this.closest('.invoice-line').remove(); recalc();" style="background:transparent; border:none; color:#DC2626; cursor:pointer; font-size:18px;">×</button></div>`;
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
                    return `<div onclick="selectAddress('${(p.name || '').replace(/'/g, "\\'")}', '${p.postcode || ''}', '${(p.city || '').replace(/'/g, "\\'")}')" style="padding:10px 14px; cursor:pointer; border-bottom:1px solid #F3F4F6; font-size:13px;" onmouseover="this.style.background='#F0FDF4'" onmouseout="this.style.background='white'"><div style="font-weight:600; color:#111827;">📍 ${p.name || ''}</div><div style="color:#6B7280; font-size:12px;">${p.postcode || ''} ${p.city || ''}</div></div>`;
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
function hideAddressList() { document.getElementById('address-suggestions').style.display = 'none'; }

function copyLink() {
    const text = document.getElementById('public-link-text').textContent;
    navigator.clipboard.writeText(text).then(() => alert('✅ Lien copié !'));
}

EXISTING_LINES.forEach(l => addLine(l));
if (EXISTING_LINES.length === 0) addLine();
</script>

<?php render_foot(); ?>
