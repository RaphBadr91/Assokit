<?php
/**
 * super-admin-facture-edit.php
 * v3 : Saisie en TTC + radio TVA + calcul auto live
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes-layout.php';
require_once __DIR__ . '/company-helpers.php';
require_once __DIR__ . '/org-billing-helpers.php';
require_once __DIR__ . '/invoice-helpers.php';

require_login();
$user = current_user();

$is_founder = false;
try {
    $stmt = $pdo->prepare("SELECT is_founder FROM users WHERE id = :id");
    $stmt->execute([':id' => (int)$user['id']]);
    $row = $stmt->fetch();
    $is_founder = $row && (int)$row['is_founder'] === 1;
} catch (Throwable $e) {}

if (!$is_founder) {
    http_response_code(403);
    die('Accès réservé au Fondateur.');
}

$invoice_id = (int)($_GET['id'] ?? 0);
if ($invoice_id <= 0) {
    header('Location: /super-admin-factures.php');
    exit;
}

$stmt = $pdo->prepare("
    SELECT i.*, o.name AS org_name
    FROM invoices i
    LEFT JOIN organizations o ON o.id = i.org_id
    WHERE i.id = :id LIMIT 1
");
$stmt->execute([':id' => $invoice_id]);
$invoice = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$invoice) {
    http_response_code(404);
    die('Facture introuvable.');
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

$flash = $_SESSION['flash_invoice_edit'] ?? null;
unset($_SESSION['flash_invoice_edit']);

$is_test_mode = (int)$invoice['is_test_mode'] === 1;

// Détermine le taux TVA pour pré-cocher le radio
$current_vat = $invoice['vat_rate'] !== null ? (float)$invoice['vat_rate'] : 0;
$current_ttc = (float)$invoice['amount_ttc_cents'] / 100;

render_head('Modifier facture ' . $invoice['invoice_number']);
render_sidebar('abonnements');
?>

<div class="main">

    <nav class="crumbs">
        <a href="/super-admin">Super Admin</a>
        <span class="sep">›</span>
        <a href="/super-admin-factures.php">Factures</a>
        <span class="sep">›</span>
        <span class="current">Modifier <?= h($invoice['invoice_number']) ?></span>
    </nav>

    <div class="main-head">
        <div>
            <h1 class="page-title">✏️ <?= h($invoice['invoice_number']) ?></h1>
            <div class="page-sub">
                <span style="display:inline-block; background:linear-gradient(135deg, #FCD34D 0%, #F59E0B 100%); color:#78350F; padding:3px 10px; border-radius:5px; font-size:11px; font-weight:700; margin-right:8px;">🏗️ FONDATEUR</span>
                Édition · <strong><?= h($invoice['org_name'] ?? 'N/A') ?></strong>
            </div>
        </div>
        <div>
            <a href="/super-admin-factures.php" class="btn btn-ghost">← Retour liste</a>
        </div>
    </div>

    <?php if ($flash): ?>
        <div class="alert <?= $flash['type'] === 'success' ? 'alert-success' : 'alert-error' ?>" style="margin-bottom:16px;">
            <?= h($flash['message']) ?>
        </div>
    <?php endif; ?>

    <?php if ($is_test_mode): ?>
        <div class="alert" style="margin-bottom:16px; background:#FEE2E2; border-left:3px solid #DC2626; color:#991B1B; padding:12px 16px; border-radius:6px;">
            ⚠ <strong>Mode TEST</strong> — Cette facture est un spécimen, modifications libres.
        </div>
    <?php else: ?>
        <div class="alert alert-info" style="margin-bottom:16px;">
            ℹ️ <strong>Mode production.</strong> Une facture émise est en théorie immuable. Modifie avec précaution.
        </div>
    <?php endif; ?>

    <!-- Infos immuables -->
    <div class="card" style="margin-bottom:16px; padding:18px;">
        <h3 style="margin:0 0 12px 0; font-size:14px; color:#6B7280; text-transform:uppercase; letter-spacing:0.5px;">📌 Infos immuables</h3>
        <div style="display:grid; grid-template-columns: repeat(3, 1fr); gap:14px; font-size:13px;">
            <div>
                <div style="color:#6B7280; font-size:11px; text-transform:uppercase; margin-bottom:3px;">Numéro</div>
                <div style="font-weight:700; color:#111827;"><?= h($invoice['invoice_number']) ?></div>
            </div>
            <div>
                <div style="color:#6B7280; font-size:11px; text-transform:uppercase; margin-bottom:3px;">Émise le</div>
                <div style="color:#111827;"><?= h(date('d/m/Y H:i', strtotime($invoice['issued_at']))) ?></div>
            </div>
            <div>
                <div style="color:#6B7280; font-size:11px; text-transform:uppercase; margin-bottom:3px;">Mode</div>
                <div>
                    <?php if ($is_test_mode): ?>
                        <span style="background:#FEE2E2; color:#991B1B; padding:2px 8px; border-radius:4px; font-size:11px; font-weight:600;">TEST</span>
                    <?php else: ?>
                        <span style="background:#D1FAE5; color:#065F46; padding:2px 8px; border-radius:4px; font-size:11px; font-weight:600;">PROD</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <form method="POST" action="/super-admin-facture-edit-save.php">
        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
        <input type="hidden" name="invoice_id" value="<?= (int)$invoice_id ?>">

        <div class="card" style="padding:22px; margin-bottom:16px;">
            <h3 style="margin:0 0 16px 0; font-size:15px;">💶 Montants</h3>

            <div style="display:grid; grid-template-columns:1fr; gap:18px; margin-bottom:18px;">
                <div>
                    <label style="display:block; font-size:12px; color:#374151; margin-bottom:6px; font-weight:600;">Montant TTC (€) — le prix payé par l'asso</label>
                    <input type="number" name="amount_ttc" id="inp-ttc" step="0.01" min="0" value="<?= h(number_format($current_ttc, 2, '.', '')) ?>" required style="width:100%; padding:11px 14px; border:1px solid #E5E7EB; border-radius:8px; font-size:16px; font-variant-numeric:tabular-nums; font-weight:600;" oninput="recalcEdit()">
                </div>
            </div>

            <div style="margin-bottom:18px;">
                <label style="display:block; font-size:12px; color:#374151; margin-bottom:8px; font-weight:600;">📋 TVA applicable</label>
                <div style="display:flex; gap:8px; flex-wrap:wrap;">
                    <?php
                    $tva_options = [
                        ['value' => 0,    'label' => 'Pas de TVA',    'note' => 'art. 293 B'],
                        ['value' => 5.5,  'label' => 'TVA 5,5%',      'note' => 'taux réduit'],
                        ['value' => 10,   'label' => 'TVA 10%',       'note' => 'taux intermédiaire'],
                        ['value' => 20,   'label' => 'TVA 20%',       'note' => 'taux normal'],
                    ];
                    foreach ($tva_options as $opt):
                        $checked = abs($current_vat - $opt['value']) < 0.01;
                    ?>
                        <label class="tva-radio-edit" style="flex:1; min-width:140px; cursor:pointer;">
                            <input type="radio" name="vat_rate" value="<?= $opt['value'] ?>" <?= $checked ? 'checked' : '' ?> onchange="recalcEdit()" style="display:none;">
                            <div class="tva-radio-card-edit" style="padding:12px; border:2px solid #E5E7EB; border-radius:8px; background:white; text-align:center; transition:all 0.15s;">
                                <div style="font-size:14px; font-weight:700; color:#111827;"><?= h($opt['label']) ?></div>
                                <div style="font-size:10px; color:#6B7280; margin-top:2px;"><?= h($opt['note']) ?></div>
                            </div>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Récap calcul live -->
            <div style="background:#F9FAFB; border:1px solid #E5E7EB; border-radius:10px; padding:14px 16px;">
                <div style="font-size:11px; color:#6B7280; text-transform:uppercase; letter-spacing:0.5px; margin-bottom:8px; font-weight:600;">📊 Récapitulatif calculé</div>
                <div style="display:grid; grid-template-columns: 1fr 1fr 1fr; gap:14px;">
                    <div>
                        <div style="font-size:11px; color:#6B7280;">Total HT</div>
                        <div id="calc-ht-edit" style="font-size:18px; font-weight:700; color:#111827; font-variant-numeric:tabular-nums;">—</div>
                    </div>
                    <div>
                        <div style="font-size:11px; color:#6B7280;">TVA <span id="calc-vat-rate-label-edit"></span></div>
                        <div id="calc-vat-edit" style="font-size:18px; font-weight:700; color:#111827; font-variant-numeric:tabular-nums;">—</div>
                    </div>
                    <div style="background:#10B981; margin:-14px -16px -14px 0; padding:14px 16px; border-radius:0 10px 10px 0;">
                        <div style="font-size:11px; color:#D1FAE5;">⭐ Total TTC</div>
                        <div id="calc-ttc-edit" style="font-size:18px; font-weight:700; color:#fff; font-variant-numeric:tabular-nums;">—</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card" style="padding:22px; margin-bottom:16px;">
            <h3 style="margin:0 0 16px 0; font-size:15px;">📝 Détails facture</h3>
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px;">
                <div style="grid-column:1/-1;">
                    <label style="display:block; font-size:12px; color:#6B7280; margin-bottom:6px; font-weight:500;">Description</label>
                    <input type="text" name="description" value="<?= h($invoice['description'] ?? '') ?>" placeholder="Abonnement Assokit" style="width:100%; padding:10px 12px; border:1px solid #E5E7EB; border-radius:8px; font-size:14px;">
                </div>

                <div>
                    <label style="display:block; font-size:12px; color:#6B7280; margin-bottom:6px; font-weight:500;">Période début</label>
                    <input type="date" name="period_start" value="<?= h($invoice['period_start'] ?? '') ?>" style="width:100%; padding:10px 12px; border:1px solid #E5E7EB; border-radius:8px; font-size:14px;">
                </div>

                <div>
                    <label style="display:block; font-size:12px; color:#6B7280; margin-bottom:6px; font-weight:500;">Période fin</label>
                    <input type="date" name="period_end" value="<?= h($invoice['period_end'] ?? '') ?>" style="width:100%; padding:10px 12px; border:1px solid #E5E7EB; border-radius:8px; font-size:14px;">
                </div>

                <div>
                    <label style="display:block; font-size:12px; color:#6B7280; margin-bottom:6px; font-weight:500;">Échéance</label>
                    <input type="date" name="due_at" value="<?= h(date('Y-m-d', strtotime($invoice['due_at']))) ?>" required style="width:100%; padding:10px 12px; border:1px solid #E5E7EB; border-radius:8px; font-size:14px;">
                </div>

                <div>
                    <label style="display:block; font-size:12px; color:#6B7280; margin-bottom:6px; font-weight:500;">Statut</label>
                    <select name="status" style="width:100%; padding:10px 12px; border:1px solid #E5E7EB; border-radius:8px; font-size:14px;">
                        <?php foreach (['draft' => 'Brouillon', 'pending' => 'En attente', 'paid' => 'Payée', 'overdue' => 'En retard', 'cancelled' => 'Annulée', 'refunded' => 'Remboursée'] as $k => $v): ?>
                            <option value="<?= h($k) ?>" <?= $invoice['status'] === $k ? 'selected' : '' ?>><?= h($v) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div style="grid-column:1/-1;">
                    <label style="display:block; font-size:12px; color:#6B7280; margin-bottom:6px; font-weight:500;">Notes internes (Fondateur uniquement)</label>
                    <textarea name="internal_notes" rows="3" placeholder="Notes privées..." style="width:100%; padding:10px 12px; border:1px solid #E5E7EB; border-radius:8px; font-size:14px; font-family:inherit; resize:vertical;"><?= h($invoice['internal_notes'] ?? '') ?></textarea>
                </div>
            </div>
        </div>

        <div style="display:flex; gap:12px; justify-content:space-between; align-items:center; padding:18px; background:white; border:1px solid #E5E7EB; border-radius:12px; position:sticky; bottom:12px;">
            <div>
                <?php if ($is_test_mode): ?>
                <button type="button" onclick="if(confirm('Supprimer définitivement cette facture ?\n\nAttention : action IRRÉVERSIBLE.')) { document.getElementById('form-delete').submit(); }" class="btn" style="background:#FEE2E2; color:#991B1B; padding:10px 16px; font-size:13px; border:1px solid #FCA5A5;">
                    🗑 Supprimer définitivement
                </button>
                <?php endif; ?>
            </div>
            <div style="display:flex; gap:10px;">
                <a href="/super-admin-factures.php" class="btn btn-ghost">Annuler</a>
                <button type="submit" class="btn btn-primary" style="padding:10px 20px;">💾 Enregistrer + régénérer PDF</button>
            </div>
        </div>
    </form>

    <?php if ($is_test_mode): ?>
    <form id="form-delete" method="POST" action="/super-admin-facture-delete.php" style="display:none;">
        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
        <input type="hidden" name="invoice_id" value="<?= (int)$invoice_id ?>">
    </form>
    <?php endif; ?>

</div>

<style>
    .tva-radio-edit input:checked + .tva-radio-card-edit {
        border-color: #10B981;
        background: linear-gradient(135deg, #10B981 0%, #059669 100%);
    }
    .tva-radio-edit input:checked + .tva-radio-card-edit div {
        color: white !important;
    }
    .tva-radio-edit:hover .tva-radio-card-edit {
        border-color: #34D399;
    }
</style>

<script>
function recalcEdit() {
    const ttc = parseFloat(document.getElementById('inp-ttc').value) || 0;
    const rateInput = document.querySelector('input[name="vat_rate"]:checked');
    const rate = rateInput ? parseFloat(rateInput.value) : 0;

    let ht, tva;
    if (rate > 0) {
        ht = ttc / (1 + rate / 100);
        tva = ttc - ht;
    } else {
        ht = ttc;
        tva = 0;
    }

    const fmt = n => n.toFixed(2).replace('.', ',') + ' €';
    document.getElementById('calc-ht-edit').textContent = fmt(ht);
    document.getElementById('calc-vat-edit').textContent = fmt(tva);
    document.getElementById('calc-ttc-edit').textContent = fmt(ttc);
    document.getElementById('calc-vat-rate-label-edit').textContent = rate > 0 ? '(' + rate.toString().replace('.', ',') + '%)' : '(non applicable)';
}
recalcEdit();
</script>

<?php render_foot(); ?>
