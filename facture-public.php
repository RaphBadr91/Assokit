<?php
/**
 * facture-public.php
 * Page publique de visualisation d'une facture (sans login).
 * URL : /facture/{public_uuid}
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/asso-invoice-helpers.php';

$uuid = $_GET['uuid'] ?? '';
if (empty($uuid) || !preg_match('/^[a-f0-9-]{36}$/i', $uuid)) {
    http_response_code(404);
    die('Facture introuvable.');
}

// Charger facture
$stmt = $pdo->prepare("
    SELECT i.*, c.display_name AS client_name, c.email AS client_email,
           o.name AS org_name, o.billing_email AS org_billing_email
    FROM asso_invoices i
    LEFT JOIN asso_clients c ON c.id = i.client_id
    LEFT JOIN organizations o ON o.id = i.org_id
    WHERE i.public_uuid = :u LIMIT 1
");
$stmt->execute([':u' => $uuid]);
$inv = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$inv) {
    http_response_code(404);
    die('Facture introuvable.');
}

// Récup paramètres
$stmt = $pdo->prepare("SELECT * FROM org_invoice_settings WHERE org_id = :id LIMIT 1");
$stmt->execute([':id' => $inv['org_id']]);
$settings = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

// Tracker la consultation
try {
    $pdo->prepare("UPDATE asso_invoices SET last_viewed_at = NOW(), view_count = view_count + 1 WHERE id = :id")
        ->execute([':id' => $inv['id']]);
} catch (Throwable $e) {}

// Flash
if (empty($_SESSION['csrf_token_public'])) {
    $_SESSION['csrf_token_public'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token_public'];
$flash = $_SESSION['flash_public'] ?? null;
unset($_SESSION['flash_public']);

$h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$is_paid = !empty($inv['paid_at']) || !empty($inv['client_marked_paid_at']);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Facture <?= $h($inv['invoice_number']) ?> — <?= $h($inv['org_name']) ?></title>
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
body {
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
    background: #F9FAFB;
    color: #1F2937;
    line-height: 1.5;
    min-height: 100vh;
    padding: 20px;
}
.wrapper { max-width: 800px; margin: 0 auto; }
.card {
    background: white;
    border-radius: 12px;
    box-shadow: 0 4px 20px rgba(0,0,0,0.05);
    overflow: hidden;
    margin-bottom: 16px;
}
.header {
    background: linear-gradient(135deg, #059669 0%, #10B981 100%);
    color: white;
    padding: 30px 28px;
}
.header h1 {
    font-size: 22px;
    font-weight: 700;
    margin-bottom: 4px;
}
.header .number {
    font-size: 14px;
    opacity: 0.9;
    letter-spacing: 0.5px;
}
.header .org {
    margin-top: 14px;
    font-size: 13px;
    opacity: 0.85;
}
.status-banner {
    padding: 14px 28px;
    font-size: 14px;
    font-weight: 600;
}
.status-paid    { background: #D1FAE5; color: #065F46; }
.status-pending { background: #FEF3C7; color: #92400E; }
.status-overdue { background: #FEE2E2; color: #991B1B; }
.body { padding: 28px; }
.section { margin-bottom: 24px; }
.section h2 {
    font-size: 12px;
    text-transform: uppercase;
    color: #6B7280;
    letter-spacing: 0.5px;
    font-weight: 600;
    margin-bottom: 8px;
}
.grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; }
.label { color: #6B7280; font-size: 13px; }
.value { color: #111827; font-weight: 500; font-size: 14px; min-width: 0; overflow-wrap: anywhere; word-break: break-word; }
.section:has(> table) { overflow-x: auto; -webkit-overflow-scrolling: touch; }
@media (max-width: 540px) { .grid-2 { grid-template-columns: 1fr; gap: 14px; } }
.amount {
    font-size: 32px;
    font-weight: 700;
    color: #059669;
    font-variant-numeric: tabular-nums;
    margin: 4px 0;
}
table {
    width: 100%;
    border-collapse: collapse;
    margin-top: 8px;
    font-size: 13px;
}
table thead {
    background: #F3F4F6;
}
table th {
    padding: 10px 12px;
    text-align: left;
    font-size: 11px;
    color: #6B7280;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    font-weight: 600;
}
table th.num { text-align: right; }
table td {
    padding: 12px;
    border-bottom: 1px solid #F3F4F6;
}
table td.num { text-align: right; font-variant-numeric: tabular-nums; }
.totals {
    margin-top: 14px;
    padding: 14px;
    background: #F9FAFB;
    border-radius: 8px;
}
.totals-row {
    display: flex;
    justify-content: space-between;
    padding: 4px 0;
    font-size: 14px;
}
.totals-row.ttc {
    background: #059669;
    color: white;
    margin: 8px -14px -14px;
    padding: 14px;
    border-radius: 0 0 8px 8px;
    font-size: 16px;
    font-weight: 700;
}
.actions {
    padding: 22px 28px;
    border-top: 1px solid #E5E7EB;
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
}
.btn {
    padding: 12px 22px;
    border-radius: 8px;
    border: none;
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 6px;
}
.btn-primary { background: #059669; color: white; }
.btn-primary:hover { background: #047857; }
.btn-secondary { background: #F3F4F6; color: #374151; }
.btn-secondary:hover { background: #E5E7EB; }
.alert {
    padding: 14px 18px;
    border-radius: 10px;
    margin-bottom: 16px;
    font-size: 14px;
}
.alert-success { background: #D1FAE5; color: #065F46; border-left: 4px solid #10B981; }
.alert-error { background: #FEE2E2; color: #991B1B; border-left: 4px solid #DC2626; }
.modal-bg {
    display: none;
    position: fixed;
    top: 0; left: 0; right: 0; bottom: 0;
    background: rgba(0,0,0,0.5);
    z-index: 1000;
    align-items: center;
    justify-content: center;
    padding: 20px;
}
.modal-bg.active { display: flex; }
.modal {
    background: white;
    border-radius: 12px;
    max-width: 500px;
    width: 100%;
    padding: 24px;
}
.modal h3 { margin-bottom: 14px; font-size: 18px; }
.modal label { display: block; font-size: 12px; color: #6B7280; margin: 12px 0 4px; }
.modal input, .modal select, .modal textarea {
    width: 100%;
    padding: 10px;
    border: 1px solid #E5E7EB;
    border-radius: 8px;
    font-size: 14px;
    font-family: inherit;
}
.modal-actions {
    margin-top: 18px;
    display: flex;
    gap: 10px;
    justify-content: flex-end;
}
.footer {
    text-align: center;
    color: #6B7280;
    font-size: 12px;
    margin-top: 30px;
    padding: 14px;
}
</style>
</head>
<body>
<div class="wrapper">

    <div class="card">
        <div class="header">
            <h1>Facture <?= $h($inv['invoice_number']) ?></h1>
            <div class="number">N° <?= $h($inv['invoice_number']) ?></div>
            <div class="org">Émise par <strong><?= $h($inv['org_name']) ?></strong></div>
        </div>

        <?php
        $is_overdue = !$is_paid && strtotime($inv['due_at']) < time();
        if ($is_paid):
        ?>
            <div class="status-banner status-paid">✅ Cette facture a été payée — Merci !</div>
        <?php elseif ($is_overdue): ?>
            <div class="status-banner status-overdue">⚠ Facture en retard de paiement</div>
        <?php else: ?>
            <div class="status-banner status-pending">⏳ En attente de paiement — échéance le <?= $h(date('d/m/Y', strtotime($inv['due_at']))) ?></div>
        <?php endif; ?>

        <div class="body">

            <?php if ($flash): ?>
                <div class="alert <?= $flash['type'] === 'success' ? 'alert-success' : 'alert-error' ?>">
                    <?= $h($flash['message']) ?>
                </div>
            <?php endif; ?>

            <div class="section grid-2">
                <div>
                    <h2>📅 Dates</h2>
                    <div class="label">Émise le</div>
                    <div class="value"><?= $h(date('d/m/Y', strtotime($inv['issued_at']))) ?></div>
                    <div class="label" style="margin-top:8px;">Échéance</div>
                    <div class="value"><?= $h(date('d/m/Y', strtotime($inv['due_at']))) ?></div>
                </div>
                <div>
                    <h2>👤 Adressée à</h2>
                    <div class="value"><?= $h($inv['client_name']) ?></div>
                    <div class="label"><?= $h($inv['client_email']) ?></div>
                </div>
            </div>

            <div class="section">
                <h2>💶 Montant</h2>
                <div class="amount"><?= $h(ak_asso_fmt_cents((int)$inv['amount_ttc_cents'])) ?></div>
                <div class="label">TTC</div>
            </div>

            <?php
            $stmt = $pdo->prepare("SELECT * FROM asso_invoice_lines WHERE invoice_id = :id ORDER BY line_order");
            $stmt->execute([':id' => $inv['id']]);
            $lines = $stmt->fetchAll(PDO::FETCH_ASSOC);
            ?>
            <?php if (!empty($lines)): ?>
            <div class="section">
                <h2>📦 Désignations</h2>
                <table>
                    <thead>
                        <tr>
                            <th>Description</th>
                            <th class="num">Qté</th>
                            <th class="num">PU HT</th>
                            <th class="num">TVA</th>
                            <th class="num">Total HT</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($lines as $line): ?>
                        <tr>
                            <td><?= $h($line['designation']) ?></td>
                            <td class="num"><?= $h(rtrim(rtrim(number_format((float)$line['quantity'], 2, ',', ' '), '0'), ',')) ?></td>
                            <td class="num"><?= $h(ak_asso_fmt_cents((int)$line['unit_price_ht_cents'])) ?></td>
                            <td class="num"><?= !empty($line['vat_rate']) ? $h(number_format((float)$line['vat_rate'], 1, ',', '')) . '%' : '—' ?></td>
                            <td class="num"><?= $h(ak_asso_fmt_cents((int)$line['total_ht_cents'])) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <div class="totals">
                    <div class="totals-row"><span>Total HT</span><span><?= $h(ak_asso_fmt_cents((int)$inv['amount_ht_cents'])) ?></span></div>
                    <?php if ((int)$inv['amount_vat_cents'] > 0): ?>
                        <div class="totals-row"><span>TVA</span><span><?= $h(ak_asso_fmt_cents((int)$inv['amount_vat_cents'])) ?></span></div>
                    <?php else: ?>
                        <div class="totals-row" style="color:#6B7280;font-size:12px;"><span>TVA non applicable (art. 293 B)</span><span>0,00 €</span></div>
                    <?php endif; ?>
                    <div class="totals-row ttc"><span>⭐ TOTAL TTC</span><span><?= $h(ak_asso_fmt_cents((int)$inv['amount_ttc_cents'])) ?></span></div>
                </div>
            </div>
            <?php endif; ?>

            <?php if (!empty($settings['bank_iban'])): ?>
            <div class="section">
                <h2>💳 Coordonnées bancaires</h2>
                <div style="background:#F0FDF4; padding:14px; border-radius:8px; border:1px solid #86EFAC;">
                    <?php if (!empty($settings['bank_name'])): ?>
                        <div><span class="label">Banque :</span> <span class="value"><?= $h($settings['bank_name']) ?></span></div>
                    <?php endif; ?>
                    <div><span class="label">IBAN :</span> <span class="value" style="font-variant-numeric:tabular-nums;"><?= $h($settings['bank_iban']) ?></span></div>
                    <?php if (!empty($settings['bank_bic'])): ?>
                        <div><span class="label">BIC :</span> <span class="value"><?= $h($settings['bank_bic']) ?></span></div>
                    <?php endif; ?>
                    <div style="margin-top:8px; font-size:12px; color:#065F46;">
                        ⚠ Indiquez la référence <strong><?= $h($inv['invoice_number']) ?></strong> lors du règlement
                    </div>
                </div>
            </div>
            <?php endif; ?>

        </div>

        <?php if (!$is_paid): ?>
        <div class="actions">
            <?php if (!empty($inv['pdf_path'])): ?>
                <a href="<?= $h($inv['pdf_path']) ?>" target="_blank" class="btn btn-secondary">📥 Télécharger le PDF</a>
            <?php endif; ?>
            <button onclick="document.getElementById('modal-paid').classList.add('active')" class="btn btn-primary">
                ✅ J'ai payé cette facture
            </button>
        </div>
        <?php else: ?>
        <div class="actions">
            <?php if (!empty($inv['pdf_path'])): ?>
                <a href="<?= $h($inv['pdf_path']) ?>" target="_blank" class="btn btn-secondary">📥 Télécharger le PDF</a>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>

    <div class="footer">
        Facture sécurisée par <strong>Assokit</strong> · 
        <a href="https://assokit.fr" style="color:#059669;">assokit.fr</a>
    </div>
</div>

<?php if (!$is_paid): ?>
<div id="modal-paid" class="modal-bg">
    <div class="modal">
        <h3>✅ Confirmer le paiement</h3>
        <p style="font-size:13px; color:#6B7280;">
            Vous attestez avoir payé cette facture. <?= $h($inv['org_name']) ?> sera notifiée et confirmera la réception.
        </p>
        <form method="POST" action="/facture-paid">
            <input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>">
            <input type="hidden" name="uuid" value="<?= $h($uuid) ?>">

            <label>Méthode de paiement</label>
            <select name="payment_method" required>
                <option value="">— Choisir —</option>
                <option value="virement">Virement bancaire</option>
                <option value="cheque">Chèque</option>
                <option value="especes">Espèces</option>
                <option value="cb">Carte bancaire</option>
                <option value="autre">Autre</option>
            </select>

            <label>Date du paiement</label>
            <input type="date" name="paid_at" value="<?= date('Y-m-d') ?>" required>

            <label>Référence (optionnel)</label>
            <input type="text" name="reference" placeholder="N° de virement, n° chèque...">

            <label>Note (optionnel)</label>
            <textarea name="note" rows="2" placeholder="Information complémentaire..."></textarea>

            <div class="modal-actions">
                <button type="button" onclick="document.getElementById('modal-paid').classList.remove('active')" class="btn btn-secondary">Annuler</button>
                <button type="submit" class="btn btn-primary">✅ Confirmer le paiement</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

</body>
</html>
