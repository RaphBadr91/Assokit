<?php
/**
 * mon-asso-devis-new.php — Création devis
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

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

$stmt = $pdo->prepare("SELECT * FROM asso_clients WHERE org_id = :org AND deleted_at IS NULL ORDER BY display_name");
$stmt->execute([':org' => $org_id]);
$clients = $stmt->fetchAll(PDO::FETCH_ASSOC);

ak_asso_invoice_settings($pdo, $org_id);
$slug = ak_asso_ensure_slug($pdo, $org_id);

render_head('Nouveau devis');
render_sidebar('devis');
?>

<div class="main">
    <nav class="crumbs">
        <a href="/mon-asso-devis">Mes devis</a>
        <span class="sep">›</span>
        <span class="current">Nouveau devis</span>
    </nav>

    <div class="main-head">
        <div>
            <h1 class="page-title">+ Nouveau devis</h1>
            <div class="page-sub">Préfixe : <strong>DEVIS-<?= h($slug) ?>-<?= date('Y') ?>-XXXXXX</strong></div>
        </div>
        <div><a href="/mon-asso-devis" class="btn btn-ghost">← Annuler</a></div>
    </div>

    <form method="POST" action="/mon-asso-devis-save" id="quote-form">
        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
        <input type="hidden" name="action" value="create">

        <!-- CLIENT -->
        <div class="card" style="padding:22px; margin-bottom:16px;">
            <h3 style="margin:0 0 16px 0; font-size:15px;">👤 Client</h3>

            <?php if (!empty($clients)): ?>
                <div style="margin-bottom:14px;">
                    <label style="display:block; font-size:12px; color:#6B7280; margin-bottom:6px; font-weight:500;">Choisir un client existant</label>
                    <select id="client-existing" onchange="loadClient(this.value)" style="width:100%; padding:10px; border:1px solid #E5E7EB; border-radius:8px; font-size:14px;">
                        <option value="">— Nouveau client —</option>
                        <?php foreach ($clients as $c): ?>
                            <option value="<?= (int)$c['id'] ?>"
                                data-email="<?= h($c['email']) ?>"
                                data-legal="<?= h($c['legal_name'] ?? '') ?>"
                                data-fn="<?= h($c['contact_first_name'] ?? '') ?>"
                                data-ln="<?= h($c['contact_last_name'] ?? '') ?>"
                                data-phone="<?= h($c['phone'] ?? '') ?>"
                                data-street="<?= h($c['address_street'] ?? '') ?>"
                                data-compl="<?= h($c['address_complement'] ?? '') ?>"
                                data-zip="<?= h($c['address_zip'] ?? '') ?>"
                                data-city="<?= h($c['address_city'] ?? '') ?>"
                                data-siren="<?= h($c['siren'] ?? '') ?>"
                                data-type="<?= h($c['client_type']) ?>"
                                data-name="<?= h($c['display_name']) ?>">
                                <?= h($c['display_name']) ?> <?= !empty($c['email']) ? ' · ' . h($c['email']) : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>

            <input type="hidden" name="client_id" id="client_id" value="">

            <div style="display:grid; grid-template-columns:1fr 1fr; gap:14px;">
                <div>
                    <label style="display:block; font-size:12px; color:#6B7280; margin-bottom:6px;">Type</label>
                    <select name="client_type" id="client_type" style="width:100%; padding:10px; border:1px solid #E5E7EB; border-radius:8px;">
                        <option value="company">Entreprise / Asso</option>
                        <option value="individual">Particulier</option>
                    </select>
                </div>
                <div>
                    <label style="display:block; font-size:12px; color:#6B7280; margin-bottom:6px;">Nom affiché *</label>
                    <input type="text" name="display_name" id="display_name" required style="width:100%; padding:10px; border:1px solid #E5E7EB; border-radius:8px;">
                </div>
                <div>
                    <label style="display:block; font-size:12px; color:#6B7280; margin-bottom:6px;">Email *</label>
                    <input type="email" name="email" id="client_email" required style="width:100%; padding:10px; border:1px solid #E5E7EB; border-radius:8px;">
                </div>
                <div>
                    <label style="display:block; font-size:12px; color:#6B7280; margin-bottom:6px;">Téléphone</label>
                    <input type="text" name="phone" id="client_phone" style="width:100%; padding:10px; border:1px solid #E5E7EB; border-radius:8px;">
                </div>
                <div style="grid-column:1/-1;">
                    <label style="display:block; font-size:12px; color:#6B7280; margin-bottom:6px;">Raison sociale</label>
                    <input type="text" name="legal_name" id="legal_name" style="width:100%; padding:10px; border:1px solid #E5E7EB; border-radius:8px;">
                </div>
                <div>
                    <label style="display:block; font-size:12px; color:#6B7280; margin-bottom:6px;">Prénom contact</label>
                    <input type="text" name="contact_first_name" id="contact_first_name" style="width:100%; padding:10px; border:1px solid #E5E7EB; border-radius:8px;">
                </div>
                <div>
                    <label style="display:block; font-size:12px; color:#6B7280; margin-bottom:6px;">Nom contact</label>
                    <input type="text" name="contact_last_name" id="contact_last_name" style="width:100%; padding:10px; border:1px solid #E5E7EB; border-radius:8px;">
                </div>
                <div style="grid-column:1/-1; position:relative;">
                    <label style="display:block; font-size:12px; color:#6B7280; margin-bottom:6px;">Adresse <span style="color:#10B981;">— autocomplétion FR active</span></label>
                    <input type="text" name="address_street" id="address_street" placeholder="ex: 15 rue de la Paix" autocomplete="off" oninput="searchAddress(this.value)" onblur="setTimeout(hideAddressList, 200)" style="width:100%; padding:10px; border:1px solid #E5E7EB; border-radius:8px; margin-bottom:6px;">
                    <div id="address-suggestions" style="display:none; position:absolute; top:80px; left:0; right:0; background:white; border:1px solid #E5E7EB; border-radius:8px; box-shadow:0 4px 12px rgba(0,0,0,0.1); z-index:1000; max-height:300px; overflow-y:auto;"></div>
                    <input type="text" name="address_complement" id="address_complement" placeholder="Complément" style="width:100%; padding:10px; border:1px solid #E5E7EB; border-radius:8px;">
                </div>
                <div>
                    <label style="display:block; font-size:12px; color:#6B7280; margin-bottom:6px;">CP</label>
                    <input type="text" name="address_zip" id="address_zip" maxlength="10" style="width:100%; padding:10px; border:1px solid #E5E7EB; border-radius:8px;">
                </div>
                <div>
                    <label style="display:block; font-size:12px; color:#6B7280; margin-bottom:6px;">Ville</label>
                    <input type="text" name="address_city" id="address_city" style="width:100%; padding:10px; border:1px solid #E5E7EB; border-radius:8px;">
                </div>
                <div style="grid-column:1/-1;">
                    <label style="display:block; font-size:12px; color:#6B7280; margin-bottom:6px;">SIREN</label>
                    <input type="text" name="siren" id="siren" maxlength="14" style="width:100%; padding:10px; border:1px solid #E5E7EB; border-radius:8px;">
                </div>
            </div>
        </div>

        <!-- LIGNES -->
        <div class="card" style="padding:22px; margin-bottom:16px;">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
                <h3 style="margin:0; font-size:15px;">📦 Désignations</h3>
                <button type="button" onclick="addLine()" class="btn btn-ghost" style="padding:6px 12px; font-size:13px;">+ Ajouter une ligne</button>
            </div>
            <div id="lines-container"></div>
        </div>

        <!-- RÉCAP -->
        <div class="card" style="padding:18px; margin-bottom:16px; background:#F9FAFB;">
            <h3 style="margin:0 0 12px 0; font-size:14px; color:#6B7280; text-transform:uppercase;">📊 Total devis</h3>
            <div style="display:grid; grid-template-columns:1fr 1fr 1fr; gap:14px;">
                <div>
                    <div style="font-size:11px; color:#6B7280;">Total HT</div>
                    <div id="total-ht" style="font-size:20px; font-weight:700; font-variant-numeric:tabular-nums;">0,00 €</div>
                </div>
                <div>
                    <div style="font-size:11px; color:#6B7280;">TVA</div>
                    <div id="total-vat" style="font-size:20px; font-weight:700; font-variant-numeric:tabular-nums;">0,00 €</div>
                </div>
                <div style="background:#7E22CE; padding:10px 14px; border-radius:8px; margin:-10px -14px;">
                    <div style="font-size:11px; color:#E9D5FF;">⭐ Total TTC</div>
                    <div id="total-ttc" style="font-size:20px; font-weight:700; color:#fff; font-variant-numeric:tabular-nums;">0,00 €</div>
                </div>
            </div>
        </div>

        <!-- DATES + DESC + CONDITIONS -->
        <div class="card" style="padding:22px; margin-bottom:16px;">
            <h3 style="margin:0 0 16px 0; font-size:15px;">📅 Dates et conditions</h3>
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:14px;">
                <div>
                    <label style="display:block; font-size:12px; color:#6B7280; margin-bottom:6px;">Date d'émission</label>
                    <input type="date" name="issued_at" value="<?= date('Y-m-d') ?>" required style="width:100%; padding:10px; border:1px solid #E5E7EB; border-radius:8px;">
                </div>
                <div>
                    <label style="display:block; font-size:12px; color:#6B7280; margin-bottom:6px;">Validité (jours)</label>
                    <input type="number" name="validity_days" value="30" min="1" max="365" required style="width:100%; padding:10px; border:1px solid #E5E7EB; border-radius:8px;">
                </div>
                <div style="grid-column:1/-1;">
                    <label style="display:block; font-size:12px; color:#6B7280; margin-bottom:6px;">Description</label>
                    <textarea name="description" rows="2" placeholder="Description du projet..." style="width:100%; padding:10px; border:1px solid #E5E7EB; border-radius:8px; resize:vertical; font-family:inherit;"></textarea>
                </div>
                <div style="grid-column:1/-1;">
                    <label style="display:block; font-size:12px; color:#6B7280; margin-bottom:6px;">Conditions générales</label>
                    <textarea name="terms" rows="3" placeholder="Conditions de paiement, délais, mentions légales..." style="width:100%; padding:10px; border:1px solid #E5E7EB; border-radius:8px; resize:vertical; font-family:inherit;"></textarea>
                </div>
                <div style="grid-column:1/-1;">
                    <label style="display:block; font-size:12px; color:#6B7280; margin-bottom:6px;">Notes internes</label>
                    <textarea name="internal_notes" rows="2" placeholder="Notes privées..." style="width:100%; padding:10px; border:1px solid #E5E7EB; border-radius:8px; resize:vertical; font-family:inherit;"></textarea>
                </div>
            </div>
        </div>

        <div style="display:flex; gap:10px; justify-content:flex-end; padding:18px; background:white; border:1px solid #E5E7EB; border-radius:12px; position:sticky; bottom:12px;">
            <a href="/mon-asso-devis" class="btn btn-ghost">Annuler</a>
            <button type="submit" name="save_draft" value="1" class="btn btn-ghost" style="padding:10px 20px;">💾 Brouillon</button>
            <button type="submit" class="btn btn-primary" style="padding:10px 20px; background:#7E22CE; border-color:#7E22CE;">📤 Créer le devis</button>
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
let lineIdx = 0;

function addLine(prefill = {}) {
    const idx = lineIdx++;
    const html = `
        <div class="invoice-line" data-idx="${idx}" style="display:grid; grid-template-columns: 1fr 70px 100px 120px 100px 30px; gap:8px; padding:10px 0; border-bottom:1px solid #F3F4F6;">
            <input type="text" name="lines[${idx}][designation]" placeholder="Désignation" required value="${prefill.designation || ''}" style="padding:8px 10px; border:1px solid #E5E7EB; border-radius:6px; font-size:13px;">
            <input type="number" name="lines[${idx}][quantity]" step="0.01" min="0" value="${prefill.quantity || '1'}" oninput="recalc()" style="padding:8px 10px; border:1px solid #E5E7EB; border-radius:6px; font-size:13px; text-align:right;">
            <input type="number" name="lines[${idx}][unit_price_ht]" step="0.01" min="0" placeholder="PU HT" value="${prefill.unit_price_ht || ''}" oninput="recalc()" required style="padding:8px 10px; border:1px solid #E5E7EB; border-radius:6px; font-size:13px; text-align:right;">
            <select name="lines[${idx}][vat_rate]" onchange="recalc()" style="padding:8px 10px; border:1px solid #E5E7EB; border-radius:6px; font-size:13px;">
                ${VAT_OPTIONS.map(o => `<option value="${o.value}">${o.label}</option>`).join('')}
            </select>
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

function loadClient(id) {
    if (!id) {
        document.getElementById('client_id').value = '';
        ['display_name','client_email','client_phone','legal_name','contact_first_name','contact_last_name','address_street','address_complement','address_zip','address_city','siren']
            .forEach(n => { const el = document.getElementById(n); if (el) el.value = ''; });
        document.getElementById('client_type').value = 'company';
        return;
    }
    const opt = document.querySelector(`#client-existing option[value="${id}"]`);
    if (!opt) return;
    document.getElementById('client_id').value = id;
    document.getElementById('display_name').value = opt.dataset.name || '';
    document.getElementById('client_email').value = opt.dataset.email || '';
    document.getElementById('client_phone').value = opt.dataset.phone || '';
    document.getElementById('legal_name').value = opt.dataset.legal || '';
    document.getElementById('contact_first_name').value = opt.dataset.fn || '';
    document.getElementById('contact_last_name').value = opt.dataset.ln || '';
    document.getElementById('address_street').value = opt.dataset.street || '';
    document.getElementById('address_complement').value = opt.dataset.compl || '';
    document.getElementById('address_zip').value = opt.dataset.zip || '';
    document.getElementById('address_city').value = opt.dataset.city || '';
    document.getElementById('siren').value = opt.dataset.siren || '';
    document.getElementById('client_type').value = opt.dataset.type || 'company';
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
                    return `<div onclick="selectAddress('${(p.name || '').replace(/'/g, "\\'")}', '${p.postcode || ''}', '${(p.city || '').replace(/'/g, "\\'")}')" style="padding:10px 14px; cursor:pointer; border-bottom:1px solid #F3F4F6; font-size:13px;" onmouseover="this.style.background='#F0FDF4'" onmouseout="this.style.background='white'"><div style="color:#7E22CE; font-size:12px;">📍</div><div style="font-weight:600; color:#111827;">${p.name || ''}</div><div style="color:#6B7280; font-size:12px;">${p.postcode || ''} ${p.city || ''}</div></div>`;
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

addLine();
</script>

<?php render_foot(); ?>
