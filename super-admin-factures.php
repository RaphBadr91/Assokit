<?php
/**
 * super-admin-factures.php
 * Liste des factures (Fondateur) + bouton Générer facture test
 * v3 : saisie en TTC + radio TVA + calcul auto live
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes-layout.php';
require_once __DIR__ . '/company-helpers.php';
require_once __DIR__ . '/org-billing-helpers.php';
require_once __DIR__ . '/invoice-helpers.php';

require_login();
$user = current_user();

// Vérif Super Admin ou Fondateur
$is_sa = false;
$is_founder = false;
try {
    $stmt = $pdo->prepare("SELECT is_super_admin, is_founder FROM users WHERE id = :id");
    $stmt->execute([':id' => (int)$user['id']]);
    $row = $stmt->fetch();
    $is_sa = $row && (int)$row['is_super_admin'] === 1;
    $is_founder = $row && (int)$row['is_founder'] === 1;
} catch (Throwable $e) {}

if (!$is_sa && !$is_founder) {
    http_response_code(403);
    die('Accès réservé aux Super Admins et Fondateurs.');
}

$flash = $_SESSION['flash_factures'] ?? null;
unset($_SESSION['flash_factures']);

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

// Liste factures
$invoices = [];
try {
    $stmt = $pdo->query("
        SELECT i.*, o.name AS org_name
        FROM invoices i
        LEFT JOIN organizations o ON o.id = i.org_id
        ORDER BY i.id DESC
        LIMIT 100
    ");
    $invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

$stats = ['total' => 0, 'pending' => 0, 'paid' => 0, 'test' => 0];
try {
    $stmt = $pdo->query("
        SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending,
            SUM(CASE WHEN status = 'paid' THEN 1 ELSE 0 END) AS paid,
            SUM(CASE WHEN is_test_mode = 1 THEN 1 ELSE 0 END) AS test
        FROM invoices
    ");
    $row = $stmt->fetch();
    if ($row) $stats = array_map('intval', $row);
} catch (Throwable $e) {}

$orgs = [];
try {
    $stmt = $pdo->query("SELECT id, name, plan FROM organizations WHERE status = 'active' ORDER BY name");
    $orgs = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

$is_test_mode = ak_invoice_is_test_mode();

render_head('Factures — Super Admin');
render_sidebar('abonnements');
?>

<div class="main">
    <nav class="crumbs">
        <a href="/super-admin">Super Admin</a>
        <span class="sep">›</span>
        <span class="current">Factures</span>
    </nav>

    <div class="main-head">
        <div>
            <h1 class="page-title">💳 Factures</h1>
            <div class="page-sub">
                <?php if ($is_test_mode): ?>
                    <span style="display:inline-block; background:#DC2626; color:#fff; padding:3px 10px; border-radius:5px; font-size:11px; font-weight:700; margin-right:8px;">⚠ MODE TEST</span>
                    Société non immatriculée — les factures émises sont des spécimens.
                <?php else: ?>
                    <span style="display:inline-block; background:#10B981; color:#fff; padding:3px 10px; border-radius:5px; font-size:11px; font-weight:700; margin-right:8px;">✅ MODE PROD</span>
                    Société immatriculée — les factures sont légalement valides.
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php if ($flash): ?>
    <div class="alert <?= $flash['type'] === 'success' ? 'alert-success' : 'alert-error' ?>" style="margin-bottom:16px;">
        <?= h($flash['message']) ?>
        <?php if (!empty($flash['pdf_link'])): ?>
            — <a href="<?= h($flash['pdf_link']) ?>" target="_blank" style="font-weight:600;">📥 Télécharger le PDF</a>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- KPIs -->
    <div style="display:grid; grid-template-columns: repeat(4, 1fr); gap:12px; margin-bottom:20px;">
        <div class="card" style="padding:16px;">
            <div style="font-size:11px; color:#6B7280; text-transform:uppercase; letter-spacing:0.5px;">Total factures</div>
            <div style="font-size:24px; font-weight:700; color:#111827; margin-top:4px;"><?= (int)$stats['total'] ?></div>
        </div>
        <div class="card" style="padding:16px;">
            <div style="font-size:11px; color:#6B7280; text-transform:uppercase; letter-spacing:0.5px;">En attente</div>
            <div style="font-size:24px; font-weight:700; color:#F59E0B; margin-top:4px;"><?= (int)$stats['pending'] ?></div>
        </div>
        <div class="card" style="padding:16px;">
            <div style="font-size:11px; color:#6B7280; text-transform:uppercase; letter-spacing:0.5px;">Payées</div>
            <div style="font-size:24px; font-weight:700; color:#10B981; margin-top:4px;"><?= (int)$stats['paid'] ?></div>
        </div>
        <div class="card" style="padding:16px;">
            <div style="font-size:11px; color:#6B7280; text-transform:uppercase; letter-spacing:0.5px;">Tests</div>
            <div style="font-size:24px; font-weight:700; color:#DC2626; margin-top:4px;"><?= (int)$stats['test'] ?></div>
        </div>
    </div>

    <!-- Bouton générer (TTC + radio TVA + calcul live) -->
    <div class="card" style="margin-bottom:20px; padding:24px; background:linear-gradient(135deg, #F0FDF4 0%, #D1FAE5 100%); border-color:#10B981;">
        <h3 style="margin:0 0 8px 0; font-size:16px;">🧪 Générer une facture test</h3>
        <p style="margin:0 0 18px 0; font-size:13px; color:#065F46;">
            Saisis le <strong>montant TTC</strong> (le prix que paie l'asso). Le système calcule HT et TVA automatiquement.
        </p>

        <form method="POST" action="/super-admin-facture-generate" id="form-generate">
            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">

            <div style="display:grid; grid-template-columns: 1fr 1fr; gap:18px; margin-bottom:18px;">
                <div>
                    <label style="display:block; font-size:12px; color:#065F46; margin-bottom:6px; font-weight:600;">Association</label>
                    <select name="org_id" required style="width:100%; padding:10px 12px; border-radius:8px; border:1px solid #A7F3D0; font-size:14px; background:white;">
                        <?php foreach ($orgs as $o): ?>
                            <option value="<?= (int)$o['id'] ?>"><?= h($o['name']) ?> (<?= h($o['plan']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label style="display:block; font-size:12px; color:#065F46; margin-bottom:6px; font-weight:600;">💶 Montant TTC (€)</label>
                    <input type="number" name="amount_ttc" id="inp-ttc" step="0.01" min="0" value="49.99" required style="width:100%; padding:10px 12px; border-radius:8px; border:1px solid #A7F3D0; font-size:14px; font-variant-numeric:tabular-nums;" oninput="recalcGen()">
                </div>
            </div>

            <div style="margin-bottom:18px;">
                <label style="display:block; font-size:12px; color:#065F46; margin-bottom:8px; font-weight:600;">📋 TVA applicable</label>
                <div style="display:flex; gap:8px; flex-wrap:wrap;">
                    <?php
                    $tva_options = [
                        ['value' => 0,    'label' => 'Pas de TVA',    'note' => 'art. 293 B'],
                        ['value' => 5.5,  'label' => 'TVA 5,5%',      'note' => 'taux réduit'],
                        ['value' => 10,   'label' => 'TVA 10%',       'note' => 'taux intermédiaire'],
                        ['value' => 20,   'label' => 'TVA 20%',       'note' => 'taux normal'],
                    ];
                    foreach ($tva_options as $i => $opt): ?>
                        <label class="tva-radio" style="flex:1; min-width:140px; cursor:pointer;">
                            <input type="radio" name="vat_rate" value="<?= $opt['value'] ?>" <?= $i === 0 ? 'checked' : '' ?> onchange="recalcGen()" style="display:none;">
                            <div class="tva-radio-card" style="padding:12px; border:2px solid #A7F3D0; border-radius:8px; background:white; text-align:center; transition:all 0.15s;">
                                <div style="font-size:14px; font-weight:700; color:#065F46;"><?= h($opt['label']) ?></div>
                                <div style="font-size:10px; color:#6B7280; margin-top:2px;"><?= h($opt['note']) ?></div>
                            </div>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Récap calcul live -->
            <div style="background:white; border:1px solid #A7F3D0; border-radius:10px; padding:14px 16px; margin-bottom:18px;">
                <div style="font-size:11px; color:#065F46; text-transform:uppercase; letter-spacing:0.5px; margin-bottom:8px; font-weight:600;">📊 Récapitulatif calculé</div>
                <div style="display:grid; grid-template-columns: 1fr 1fr 1fr; gap:14px;">
                    <div>
                        <div style="font-size:11px; color:#6B7280;">Total HT</div>
                        <div id="calc-ht" style="font-size:18px; font-weight:700; color:#111827; font-variant-numeric:tabular-nums;">49,99 €</div>
                    </div>
                    <div>
                        <div style="font-size:11px; color:#6B7280;">TVA <span id="calc-vat-rate-label"></span></div>
                        <div id="calc-vat" style="font-size:18px; font-weight:700; color:#111827; font-variant-numeric:tabular-nums;">0,00 €</div>
                    </div>
                    <div style="background:#10B981; margin:-14px -16px -14px 0; padding:14px 16px; border-radius:0 10px 10px 0;">
                        <div style="font-size:11px; color:#D1FAE5;">⭐ Total TTC</div>
                        <div id="calc-ttc" style="font-size:18px; font-weight:700; color:#fff; font-variant-numeric:tabular-nums;">49,99 €</div>
                    </div>
                </div>
            </div>

            <div style="display:flex; justify-content:flex-end;">
                <button type="submit" class="btn btn-primary" style="padding:11px 24px; font-size:14px; font-weight:600;">💾 Générer la facture</button>
            </div>
        </form>
    </div>

    <!-- Liste factures -->
    <?php if (empty($invoices)): ?>
        <div class="card" style="padding:40px; text-align:center; color:#6B7280;">
            Aucune facture pour l'instant. Utilise le bouton ci-dessus pour en créer une.
        </div>
    <?php else: ?>
        <div class="card" style="padding:0; overflow:hidden;">
            <table style="width:100%; border-collapse:collapse;">
                <thead style="background:#F9FAFB;">
                    <tr>
                        <th style="text-align:left; padding:12px 14px; font-size:11px; color:#6B7280; text-transform:uppercase; letter-spacing:0.5px;">N° facture</th>
                        <th style="text-align:left; padding:12px 14px; font-size:11px; color:#6B7280; text-transform:uppercase; letter-spacing:0.5px;">Association</th>
                        <th style="text-align:left; padding:12px 14px; font-size:11px; color:#6B7280; text-transform:uppercase; letter-spacing:0.5px;">Émise le</th>
                        <th style="text-align:right; padding:12px 14px; font-size:11px; color:#6B7280; text-transform:uppercase; letter-spacing:0.5px;">Montant TTC</th>
                        <th style="text-align:center; padding:12px 14px; font-size:11px; color:#6B7280; text-transform:uppercase; letter-spacing:0.5px;">Statut</th>
                        <th style="text-align:right; padding:12px 14px; font-size:11px; color:#6B7280; text-transform:uppercase; letter-spacing:0.5px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($invoices as $inv): ?>
                        <tr style="border-top:1px solid #E5E7EB;">
                            <td style="padding:12px 14px; font-size:13px; font-variant-numeric:tabular-nums;">
                                <strong><?= h($inv['invoice_number']) ?></strong>
                                <?php if ((int)$inv['is_test_mode'] === 1): ?>
                                    <span style="display:inline-block; background:#FEE2E2; color:#991B1B; padding:2px 6px; border-radius:3px; font-size:10px; font-weight:600; margin-left:6px;">TEST</span>
                                <?php endif; ?>
                            </td>
                            <td style="padding:12px 14px; font-size:13px;"><?= h($inv['org_name'] ?? 'N/A') ?></td>
                            <td style="padding:12px 14px; font-size:13px; color:#6B7280;"><?= h(date('d/m/Y H:i', strtotime($inv['issued_at']))) ?></td>
                            <td style="padding:12px 14px; font-size:13px; text-align:right; font-variant-numeric:tabular-nums; font-weight:600;">
                                <?= h(ak_format_cents_eur((int)$inv['amount_ttc_cents'])) ?>
                            </td>
                            <td style="padding:12px 14px; text-align:center;">
                                <?php
                                $statusColors = [
                                    'draft' => ['#E5E7EB', '#374151'],
                                    'pending' => ['#FEF3C7', '#92400E'],
                                    'paid' => ['#D1FAE5', '#065F46'],
                                    'overdue' => ['#FEE2E2', '#991B1B'],
                                    'cancelled' => ['#E5E7EB', '#6B7280'],
                                    'refunded' => ['#DBEAFE', '#1E40AF'],
                                ];
                                $colors = $statusColors[$inv['status']] ?? ['#E5E7EB', '#374151'];
                                ?>
                                <span style="display:inline-block; padding:3px 10px; border-radius:12px; font-size:11px; font-weight:600; background:<?= $colors[0] ?>; color:<?= $colors[1] ?>;">
                                    <?= h(ak_invoice_status_label($inv['status'])) ?>
                                </span>
                            </td>
                            <td style="padding:12px 14px; text-align:right; white-space:nowrap;">
                                <?php if (!empty($inv['pdf_path'])): ?>
                                    <a href="<?= h($inv['pdf_path']) ?>" target="_blank" class="btn btn-ghost" style="padding:5px 10px; font-size:12px; margin-right:4px;" title="Télécharger PDF">📥 PDF</a>
                                <?php endif; ?>
                                <a href="/super-admin-facture-edit.php?id=<?= (int)$inv['id'] ?>" class="btn btn-ghost" style="padding:5px 10px; font-size:12px; background:#FEF3C7; color:#92400E; border-color:#FCD34D;" title="Modifier">✏️ Modifier</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

</div>

<style>
    .tva-radio input:checked + .tva-radio-card {
        border-color: #10B981;
        background: linear-gradient(135deg, #10B981 0%, #059669 100%);
    }
    .tva-radio input:checked + .tva-radio-card div {
        color: white !important;
    }
    .tva-radio:hover .tva-radio-card {
        border-color: #34D399;
    }
</style>

<script>
function recalcGen() {
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
    document.getElementById('calc-ht').textContent = fmt(ht);
    document.getElementById('calc-vat').textContent = fmt(tva);
    document.getElementById('calc-ttc').textContent = fmt(ttc);
    document.getElementById('calc-vat-rate-label').textContent = rate > 0 ? '(' + rate.toString().replace('.', ',') + '%)' : '(non applicable)';
}
recalcGen();
</script>

<?php render_foot(); ?>
