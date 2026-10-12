<?php
/**
 * asso-invoice-pdf-template.php
 * v2 — Avec logo asso en haut à gauche
 *
 * Variables disponibles :
 *   $pdf_invoice  (array)
 *   $pdf_lines    (array)
 *   $pdf_emitter  (array - snapshot organization)
 *   $pdf_client   (array - snapshot asso_clients)
 *   $pdf_settings (array - org_invoice_settings)
 *   $pdf_parent   (?array - avoir : facture d'origine, invoice_number + issued_at)
 */

$pdf_emitter = is_array($pdf_emitter ?? null) ? $pdf_emitter : [];
$pdf_client  = is_array($pdf_client ?? null) ? $pdf_client : [];
// Identité légale identique au vendeur du XML Factur-X (raison sociale, SIREN, n° TVA)
$emId = ak_asso_emitter_identity($pdf_emitter);
$emName = $emId['name'];
$emForm = $pdf_emitter['legal_form'] ?? '';
$emRna  = $pdf_emitter['rna_number'] ?? '';
$emSiren = $emId['siren'];
$emSiret = $emId['siret'];
$emVat   = $emId['vat_number'];
$emStreet = $pdf_emitter['billing_address_street'] ?? '';
$emCompl  = $pdf_emitter['billing_address_complement'] ?? '';
$emZip = $pdf_emitter['billing_address_zip'] ?? '';
$emCity = $pdf_emitter['billing_address_city'] ?? '';
$emEmail = $pdf_emitter['billing_email_effective'] ?? $pdf_emitter['billing_email'] ?? '';
$emPhone = $pdf_emitter['billing_phone'] ?? '';
$emLogo = $pdf_emitter['logo_path'] ?? '';

// Logo en chemin absolu pour mPDF
$logo_full_path = '';
if (!empty($emLogo)) {
    $candidate = __DIR__ . $emLogo;
    if (file_exists($candidate)) {
        $logo_full_path = $candidate;
    }
}

$clName = $pdf_client['display_name'] ?? 'Client';
$clLegal = $pdf_client['legal_name'] ?? '';
$clStreet = $pdf_client['address_street'] ?? '';
$clCompl  = $pdf_client['address_complement'] ?? '';
$clZip = $pdf_client['address_zip'] ?? '';
$clCity = $pdf_client['address_city'] ?? '';
$clSiren = $pdf_client['siren'] ?? '';

$dateIssue = date('d/m/Y', strtotime($pdf_invoice['issued_at']));
$dateDue   = date('d/m/Y', strtotime($pdf_invoice['due_at']));

// Avoir : titre « AVOIR », référence de la facture annulée, ni échéance ni règlement attendu
$isCredit  = function_exists('ak_asso_is_credit_note') && ak_asso_is_credit_note($pdf_invoice);
$docTitle  = $isCredit ? 'AVOIR' : 'FACTURE';
$pdf_parent = is_array($pdf_parent ?? null) ? $pdf_parent : null;

// Mentions TVA selon le régime de l'émetteur, conditions de règlement (clients professionnels)
$vatMentions = ak_asso_vat_mentions($pdf_emitter, abs((int)$pdf_invoice['amount_vat_cents']), $pdf_lines);
$payTerms = $isCredit ? null : ak_asso_payment_terms($pdf_client);

$iban = $pdf_settings['bank_iban'] ?? '';
$bic = $pdf_settings['bank_bic'] ?? '';
$bank = $pdf_settings['bank_name'] ?? '';

$htF = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title><?= $isCredit ? 'Avoir' : 'Facture' ?> <?= $htF($pdf_invoice['invoice_number']) ?></title>
<style>
@page { margin: 15mm; }
body {
    font-family: 'DejaVu Sans', sans-serif;
    font-size: 10pt;
    color: #1F2937;
    line-height: 1.5;
}
.header { width: 100%; margin-bottom: 18px; }
.header td { vertical-align: top; }
.logo-block { text-align: left; }
.logo-block img {
    max-height: 22mm !important;
    max-width: 50mm !important;
    width: auto;
    height: auto;
    margin-bottom: 4px;
}
.brand-name {
    font-size: 22pt;
    font-weight: bold;
    color: #059669;
    letter-spacing: -0.5px;
}
.legal-block {
    font-size: 8.5pt;
    color: #4B5563;
    line-height: 1.6;
    margin-top: 8px;
}
.invoice-title { text-align: right; }
.invoice-title h1 {
    font-size: 24pt;
    color: #059669;
    margin: 0 0 6px 0;
    font-weight: 700;
    letter-spacing: 1px;
}
.invoice-title .number {
    font-size: 12pt;
    color: #1F2937;
    font-weight: 600;
}
.meta-box { display: inline-block; width: 100%; margin: 18px 0; }
.meta-box table { width: 100%; border-collapse: collapse; }
.dates td { padding: 3px 0; font-size: 9.5pt; }
.dates .k { color: #6B7280; width: 90px; }
.dates .v { color: #1F2937; font-weight: 600; }
.client-box {
    background: #F3F4F6;
    border-left: 3px solid #059669;
    padding: 12px 14px;
    border-radius: 4px;
    font-size: 9.5pt;
}
.client-box .label {
    font-size: 8pt;
    color: #6B7280;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: 4px;
}
.client-box .name {
    font-size: 11pt;
    font-weight: 700;
    color: #111827;
    margin-bottom: 4px;
}
table.lines {
    width: 100%;
    border-collapse: collapse;
    margin: 14px 0;
}
table.lines thead th {
    background: #059669;
    color: #fff;
    padding: 9px 8px;
    font-size: 8.5pt;
    text-align: left;
    text-transform: uppercase;
    font-weight: 600;
    letter-spacing: 0.5px;
}
table.lines thead th.num { text-align: right; }
table.lines tbody td {
    padding: 10px 8px;
    border-bottom: 1px solid #E5E7EB;
    font-size: 9.5pt;
}
table.lines tbody td.num { text-align: right; font-variant-numeric: tabular-nums; }
.totals { width: 100%; margin-top: 4px; }
.totals td { padding: 4px 8px; font-size: 9.5pt; }
.totals .lbl { text-align: right; color: #6B7280; }
.totals .val {
    text-align: right;
    font-variant-numeric: tabular-nums;
    width: 110px;
    font-weight: 500;
}
.totals .ttc .lbl, .totals .ttc .val {
    background: #059669;
    color: #fff;
    font-size: 11.5pt;
    font-weight: 700;
    padding: 9px;
}
.notice {
    margin-top: 14px;
    padding: 9px 12px;
    background: #FFFBEB;
    border-left: 3px solid #F59E0B;
    font-size: 8.5pt;
    color: #78350F;
    border-radius: 4px;
}
.payment-info {
    margin-top: 14px;
    padding: 12px 14px;
    background: #F0FDF4;
    border: 1px solid #86EFAC;
    border-radius: 6px;
    font-size: 9pt;
}
.payment-info .title {
    font-size: 10pt;
    font-weight: bold;
    color: #065F46;
    margin-bottom: 6px;
}
.payment-info td { padding: 2px 0; }
.payment-info .k { color: #065F46; width: 110px; font-weight: 500; }
.payment-info .v { color: #111827; font-variant-numeric: tabular-nums; }
.footer {
    margin-top: 22px;
    padding-top: 10px;
    border-top: 1px solid #E5E7EB;
    font-size: 7.5pt;
    color: #6B7280;
    text-align: center;
}
</style>
</head>
<body>

<table class="header">
    <tr>
        <td style="width:55%;">
            <div class="logo-block">
                <?php if ($logo_full_path): ?>
                    <table style="width:50mm; border-collapse:collapse;"><tr><td style="width:50mm; padding:0;">
                        <img src="<?= $htF($logo_full_path) ?>" alt="Logo" width="180" height="80" style="width:180px; height:auto; max-width:50mm; max-height:22mm;">
                    </td></tr></table>
                <?php else: ?>
                    <div class="brand-name"><?= $htF($emName) ?></div>
                <?php endif; ?>
            </div>
            <?php if ($logo_full_path): ?>
                <div style="font-size:11pt; font-weight:600; color:#1F2937; margin-top:4px;"><?= $htF($emName) ?></div>
            <?php endif; ?>
            <?php if ($emForm): ?>
                <div style="font-size:9pt; color:#6B7280;"><?= $htF($emForm) ?></div>
            <?php endif; ?>
            <div class="legal-block">
                <?php if ($emStreet): ?><?= $htF($emStreet) ?><br><?php endif; ?>
                <?php if ($emCompl):  ?><?= $htF($emCompl) ?><br><?php endif; ?>
                <?php if ($emZip || $emCity): ?>
                    <?= $htF(trim($emZip . ' ' . $emCity)) ?><br>
                <?php endif; ?>
                <?php if ($emRna): ?>RNA : <?= $htF($emRna) ?><br><?php endif; ?>
                <?php if ($emSiren): ?>SIREN : <?= $htF($emSiren) ?><?php if ($emSiret): ?> · SIRET : <?= $htF($emSiret) ?><?php endif; ?><br><?php endif; ?>
                <?php if ($emVat): ?>N° TVA intracommunautaire : <?= $htF($emVat) ?><br><?php endif; ?>
                <?php if ($emEmail): ?><?= $htF($emEmail) ?><?php endif; ?>
                <?php if ($emPhone): ?> · <?= $htF($emPhone) ?><?php endif; ?>
            </div>
        </td>
        <td class="invoice-title">
            <h1><?= $docTitle ?></h1>
            <div class="number"><?= $htF($pdf_invoice['invoice_number']) ?></div>
            <?php if ($isCredit && $pdf_parent): ?>
                <div style="font-size:9pt; color:#4B5563; margin-top:4px;">Annule la facture n° <?= $htF($pdf_parent['invoice_number']) ?><br>du <?= $htF(date('d/m/Y', strtotime($pdf_parent['issued_at']))) ?></div>
            <?php endif; ?>
        </td>
    </tr>
</table>

<div class="meta-box">
    <table>
        <tr>
            <td style="width:42%;">
                <table class="dates">
                    <tr><td class="k">Émis<?= $isCredit ? '' : 'e' ?> le</td><td class="v"><?= $htF($dateIssue) ?></td></tr>
                    <?php if (!$isCredit): ?>
                    <tr><td class="k">Échéance</td><td class="v"><?= $htF($dateDue) ?></td></tr>
                    <?php endif; ?>
                </table>
            </td>
            <td style="width:58%;">
                <div class="client-box">
                    <div class="label"><?= $isCredit ? 'Avoir au profit de' : 'Facturé à' ?></div>
                    <div class="name"><?= $htF($clName) ?></div>
                    <?php if ($clLegal && $clLegal !== $clName): ?><?= $htF($clLegal) ?><br><?php endif; ?>
                    <?php if ($clStreet): ?><?= $htF($clStreet) ?><br><?php endif; ?>
                    <?php if ($clCompl):  ?><?= $htF($clCompl) ?><br><?php endif; ?>
                    <?php if ($clZip || $clCity): ?>
                        <?= $htF(trim($clZip . ' ' . $clCity)) ?><br>
                    <?php endif; ?>
                    <?php if ($clSiren): ?>
                        <span style="color:#6B7280; font-size:8.5pt;">SIREN <?= $htF($clSiren) ?></span>
                    <?php endif; ?>
                </div>
            </td>
        </tr>
    </table>
</div>

<table class="lines">
    <thead>
        <tr>
            <th>Désignation</th>
            <th class="num" style="width:60px;">Qté</th>
            <th class="num" style="width:90px;">PU HT</th>
            <th class="num" style="width:60px;">TVA</th>
            <th class="num" style="width:100px;">Total HT</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($pdf_lines as $line): ?>
            <tr>
                <td><?= $htF($line['designation']) ?></td>
                <td class="num"><?= $htF(rtrim(rtrim(number_format((float)$line['quantity'], 2, ',', ' '), '0'), ',')) ?></td>
                <td class="num"><?= $htF(ak_asso_fmt_cents((int)$line['unit_price_ht_cents'])) ?></td>
                <td class="num">
                    <?php if (!empty($line['vat_rate']) && (float)$line['vat_rate'] > 0): ?>
                        <?= $htF(number_format((float)$line['vat_rate'], 1, ',', ' ')) ?>%
                    <?php else: ?>
                        —
                    <?php endif; ?>
                </td>
                <td class="num"><?= $htF(ak_asso_fmt_cents((int)$line['total_ht_cents'])) ?></td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>

<table class="totals">
    <tr>
        <td class="lbl">Total HT</td>
        <td class="val"><?= $htF(ak_asso_fmt_cents((int)$pdf_invoice['amount_ht_cents'])) ?></td>
    </tr>
    <?php if ((int)$pdf_invoice['amount_vat_cents'] !== 0): ?>
        <tr>
            <td class="lbl">TVA</td>
            <td class="val"><?= $htF(ak_asso_fmt_cents((int)$pdf_invoice['amount_vat_cents'])) ?></td>
        </tr>
    <?php else: ?>
        <tr>
            <td class="lbl"><?= $htF($vatMentions['zero_label']) ?></td>
            <td class="val">0,00 €</td>
        </tr>
    <?php endif; ?>
    <tr class="ttc">
        <td class="lbl">TOTAL TTC</td>
        <td class="val"><?= $htF(ak_asso_fmt_cents((int)$pdf_invoice['amount_ttc_cents'])) ?></td>
    </tr>
</table>

<?php if (!empty($vatMentions['notice'])): ?>
<div class="notice">
    <strong><?= $htF($vatMentions['notice']) ?></strong>
</div>
<?php endif; ?>

<?php if ($payTerms): ?>
<div style="margin-top:12px; font-size:8pt; color:#4B5563; line-height:1.5;">
    <strong>Conditions de règlement :</strong> paiement au plus tard le <?= $htF($dateDue) ?>. <?= $htF($payTerms) ?>
</div>
<?php endif; ?>

<?php if (($iban || $bic) && !$isCredit): ?>
<div class="payment-info">
    <div class="title">💳 Coordonnées bancaires</div>
    <table>
        <?php if ($bank): ?><tr><td class="k">Banque</td><td class="v"><?= $htF($bank) ?></td></tr><?php endif; ?>
        <?php if ($iban): ?><tr><td class="k">IBAN</td><td class="v"><?= $htF($iban) ?></td></tr><?php endif; ?>
        <?php if ($bic):  ?><tr><td class="k">BIC / SWIFT</td><td class="v"><?= $htF($bic) ?></td></tr><?php endif; ?>
    </table>
    <div style="font-size:8pt; color:#065F46; margin-top:6px;">
        Merci d'indiquer la référence <strong><?= $htF($pdf_invoice['invoice_number']) ?></strong> lors du règlement.
    </div>
</div>
<?php endif; ?>

<?php if (!empty($pdf_invoice['description'])): ?>
<div style="margin-top:14px; font-size:9pt; color:#6B7280;">
    <strong>Note :</strong> <?= $htF($pdf_invoice['description']) ?>
</div>
<?php endif; ?>

<div class="footer">
    <?= $isCredit ? 'Avoir émis le' : 'Facture émise le' ?> <?= $htF(date('d/m/Y à H:i', strtotime($pdf_invoice['created_at'] ?? 'now'))) ?>
    <?php if ($emName): ?> par <?= $htF($emName) ?><?php endif; ?>
    <?php if ($emRna || $emSiren): ?>
        ·
        <?php if ($emRna): ?>RNA <?= $htF($emRna) ?><?php endif; ?>
        <?php if ($emSiren): ?>SIREN <?= $htF($emSiren) ?><?php endif; ?>
    <?php endif; ?>
    <?php if ($emVat): ?> · TVA <?= $htF($emVat) ?><?php endif; ?>
</div>

</body>
</html>
