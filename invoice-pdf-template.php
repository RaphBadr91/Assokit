<?php
/**
 * invoice-pdf-template.php
 * Template HTML utilisé pour générer le PDF de la facture.
 *
 * Variables attendues :
 *   $pdf_invoice  : array (row de invoices)
 *   $pdf_emitter  : array (company_settings snapshot)
 *   $pdf_client   : array (org billing info snapshot)
 */

$is_test = (int)($pdf_invoice['is_test_mode'] ?? 0) === 1;

$commercial = $pdf_emitter['commercial_name'] ?? 'Assokit';
$legal_name = $pdf_emitter['legal_name'] ?? '';
$legal_form = $pdf_emitter['legal_form'] ?? '';
$capital = (int)($pdf_emitter['capital_cents'] ?? 0);
$siren = $pdf_emitter['siren'] ?? '';
$rcs_city = $pdf_emitter['rcs_city'] ?? '';
$rcs_num = $pdf_emitter['rcs_number'] ?? '';
$vat_subject = (int)($pdf_emitter['vat_subject'] ?? 0) === 1;
$vat_number = $pdf_emitter['vat_number'] ?? '';
$em_street = $pdf_emitter['address_street'] ?? '';
$em_zip = $pdf_emitter['address_zip'] ?? '';
$em_city = $pdf_emitter['address_city'] ?? '';
$em_email = $pdf_emitter['email_support'] ?? $pdf_emitter['email_billing'] ?? '';
$em_phone = $pdf_emitter['phone'] ?? '';
$em_website = $pdf_emitter['website'] ?? '';
$em_iban = $pdf_emitter['iban'] ?? '';
$em_bic = $pdf_emitter['bic'] ?? '';

$client_name = !empty($pdf_client['legal_name_effective'])
    ? $pdf_client['legal_name_effective']
    : ($pdf_client['name'] ?? 'Client');
$cl_street = $pdf_client['billing_address_street'] ?? '';
$cl_compl  = $pdf_client['billing_address_complement'] ?? '';
$cl_zip    = $pdf_client['billing_address_zip'] ?? '';
$cl_city   = $pdf_client['billing_address_city'] ?? '';
$cl_siren  = $pdf_client['siren'] ?? '';
$cl_rna    = $pdf_client['rna_number'] ?? '';
$cl_form   = $pdf_client['legal_form'] ?? '';
$cl_presi  = trim(($pdf_client['president_first_name'] ?? '') . ' ' . ($pdf_client['president_last_name'] ?? ''));

$dateIssue = date('d/m/Y', strtotime($pdf_invoice['issued_at']));
$dateDue   = date('d/m/Y', strtotime($pdf_invoice['due_at']));

$htF = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title>Facture <?= $htF($pdf_invoice['invoice_number']) ?></title>
<style>
    @page { margin: 15mm; }
    body {
        font-family: 'DejaVu Sans', sans-serif;
        font-size: 10pt;
        color: #1F2937;
        line-height: 1.5;
    }

    .test-banner {
        background: #DC2626;
        color: #fff;
        padding: 8px 14px;
        font-size: 9pt;
        font-weight: bold;
        text-align: center;
        margin-bottom: 16px;
        border-radius: 4px;
        letter-spacing: 0.5px;
    }

    .header { width: 100%; margin-bottom: 20px; }
    .header td { vertical-align: top; }

    .brand { font-size: 22pt; font-weight: bold; color: #059669; letter-spacing: -0.5px; }
    .tagline { font-size: 9pt; color: #6B7280; margin-top: 2px; }
    .emitter-legal { font-size: 8.5pt; color: #4B5563; line-height: 1.6; margin-top: 12px; }

    .invoice-title { text-align: right; }
    .invoice-title h1 {
        font-size: 24pt;
        color: #059669;
        margin: 0 0 6px 0;
        letter-spacing: 1px;
        font-weight: 700;
    }
    .invoice-title .number { font-size: 11pt; color: #1F2937; font-weight: 600; }

    .meta-box {
        display: inline-block;
        width: 100%;
        margin-top: 20px;
        margin-bottom: 20px;
    }
    .meta-box table { width: 100%; border-collapse: collapse; }
    .meta-box td { vertical-align: top; padding: 0; }
    .meta-box .dates td { padding: 3px 0; font-size: 9.5pt; }
    .meta-box .dates .k { color: #6B7280; width: 90px; }
    .meta-box .dates .v { color: #1F2937; font-weight: 600; }
    .client-box {
        background: #F3F4F6;
        border-left: 3px solid #059669;
        padding: 12px 16px;
        border-radius: 4px;
        font-size: 9.5pt;
    }
    .client-box .label { font-size: 8pt; color: #6B7280; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 4px; }
    .client-box .name { font-size: 11pt; font-weight: 700; color: #111827; margin-bottom: 6px; }

    table.lines {
        width: 100%;
        border-collapse: collapse;
        margin: 18px 0;
    }
    table.lines thead th {
        background: #059669;
        color: #fff;
        padding: 10px;
        font-size: 9pt;
        text-align: left;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        font-weight: 600;
    }
    table.lines thead th.num { text-align: right; width: 100px; }
    table.lines tbody td {
        padding: 12px 10px;
        border-bottom: 1px solid #E5E7EB;
        font-size: 10pt;
    }
    table.lines tbody td.num { text-align: right; font-variant-numeric: tabular-nums; }
    table.lines tbody .desc-sub { font-size: 8.5pt; color: #6B7280; margin-top: 3px; }

    .totals { width: 100%; margin-top: 4px; }
    .totals td { padding: 4px 10px; font-size: 10pt; }
    .totals .lbl { text-align: right; color: #6B7280; }
    .totals .val { text-align: right; font-variant-numeric: tabular-nums; width: 120px; font-weight: 500; }
    .totals .ttc .lbl, .totals .ttc .val {
        background: #059669;
        color: #fff;
        font-size: 12pt;
        font-weight: 700;
        padding: 10px;
    }

    .notice {
        margin-top: 18px;
        padding: 10px 14px;
        background: #FFFBEB;
        border-left: 3px solid #F59E0B;
        font-size: 8.5pt;
        color: #78350F;
        border-radius: 4px;
    }

    .payment-info {
        margin-top: 20px;
        padding: 14px 16px;
        background: #F0FDF4;
        border: 1px solid #86EFAC;
        border-radius: 6px;
        font-size: 9pt;
    }
    .payment-info .title { font-size: 10pt; font-weight: bold; color: #065F46; margin-bottom: 6px; }
    .payment-info td { padding: 2px 0; }
    .payment-info .k { color: #065F46; width: 110px; font-weight: 500; }
    .payment-info .v { color: #111827; font-variant-numeric: tabular-nums; }

    .footer {
        margin-top: 28px;
        padding-top: 12px;
        border-top: 1px solid #E5E7EB;
        font-size: 7.5pt;
        color: #9CA3AF;
        text-align: center;
        line-height: 1.5;
    }
</style>
</head>
<body>

<?php if ($is_test): ?>
<div class="test-banner">
    ⚠ DOCUMENT DE TEST — SOCIÉTÉ EN COURS D'IMMATRICULATION — NE PAS UTILISER POUR VOTRE COMPTABILITÉ
</div>
<?php endif; ?>

<table class="header">
    <tr>
        <td style="width: 55%;">
            <div class="brand"><?= $htF($commercial) ?></div>
            <?php if (!empty($pdf_emitter['slogan'])): ?>
                <div class="tagline"><?= $htF($pdf_emitter['slogan']) ?></div>
            <?php endif; ?>

            <div class="emitter-legal">
                <?php if ($legal_name): ?>
                    <strong><?= $htF($legal_name) ?></strong><?php if ($legal_form): ?> — <?= $htF($legal_form) ?><?php endif; ?>
                    <?php if ($capital > 0): ?>
                        au capital de <?= number_format($capital/100, 0, ',', ' ') ?> €
                    <?php endif; ?>
                    <br>
                <?php endif; ?>

                <?php if ($em_street): ?><?= $htF($em_street) ?><br><?php endif; ?>
                <?php if ($em_zip || $em_city): ?>
                    <?= $htF(trim($em_zip . ' ' . $em_city)) ?><br>
                <?php endif; ?>

                <?php if ($rcs_city && $rcs_num): ?>
                    RCS <?= $htF($rcs_city) ?> <?= $htF($rcs_num) ?><br>
                <?php endif; ?>

                <?php if ($siren): ?>
                    SIREN : <?= $htF($siren) ?><br>
                <?php endif; ?>

                <?php if ($vat_subject && $vat_number): ?>
                    TVA intracom. : <?= $htF($vat_number) ?><br>
                <?php endif; ?>

                <?php if ($em_email): ?><?= $htF($em_email) ?><?php endif; ?>
                <?php if ($em_phone): ?> · <?= $htF($em_phone) ?><?php endif; ?>
            </div>
        </td>

        <td class="invoice-title">
            <h1>FACTURE</h1>
            <div class="number"><?= $htF($pdf_invoice['invoice_number']) ?></div>
        </td>
    </tr>
</table>

<div class="meta-box">
    <table>
        <tr>
            <td style="width: 45%;">
                <table class="dates">
                    <tr><td class="k">Émise le</td><td class="v"><?= $htF($dateIssue) ?></td></tr>
                    <tr><td class="k">Échéance</td><td class="v"><?= $htF($dateDue) ?></td></tr>
                    <?php if (!empty($pdf_invoice['period_start']) && !empty($pdf_invoice['period_end'])): ?>
                    <tr><td class="k">Période</td>
                        <td class="v"><?= $htF(date('d/m/Y', strtotime($pdf_invoice['period_start']))) ?>
                        → <?= $htF(date('d/m/Y', strtotime($pdf_invoice['period_end']))) ?></td>
                    </tr>
                    <?php endif; ?>
                </table>
            </td>
            <td style="width: 55%;">
                <div class="client-box">
                    <div class="label">Facturé à</div>
                    <div class="name"><?= $htF($client_name) ?></div>
                    <?php if ($cl_form): ?><?= $htF($cl_form) ?><br><?php endif; ?>
                    <?php if ($cl_street): ?><?= $htF($cl_street) ?><br><?php endif; ?>
                    <?php if ($cl_compl): ?><?= $htF($cl_compl) ?><br><?php endif; ?>
                    <?php if ($cl_zip || $cl_city): ?>
                        <?= $htF(trim($cl_zip . ' ' . $cl_city)) ?><br>
                    <?php endif; ?>
                    <?php if ($cl_siren): ?>
                        <span style="color:#6B7280; font-size:8.5pt;">SIREN <?= $htF($cl_siren) ?></span><br>
                    <?php elseif ($cl_rna): ?>
                        <span style="color:#6B7280; font-size:8.5pt;">RNA <?= $htF($cl_rna) ?></span><br>
                    <?php endif; ?>
                    <?php if ($cl_presi): ?>
                        <span style="color:#6B7280; font-size:8.5pt; margin-top:4px; display:inline-block;">À l'att. de <?= $htF($cl_presi) ?></span>
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
            <th class="num">Qté</th>
            <th class="num">PU HT</th>
            <th class="num">Total HT</th>
        </tr>
    </thead>
    <tbody>
        <tr>
            <td>
                <strong><?= $htF($pdf_invoice['description'] ?? 'Abonnement Assokit') ?></strong>
                <?php if (!empty($pdf_invoice['period_start']) && !empty($pdf_invoice['period_end'])): ?>
                    <div class="desc-sub">
                        Période du <?= $htF(date('d/m/Y', strtotime($pdf_invoice['period_start']))) ?>
                        au <?= $htF(date('d/m/Y', strtotime($pdf_invoice['period_end']))) ?>
                    </div>
                <?php endif; ?>
            </td>
            <td class="num">1</td>
            <td class="num"><?= $htF(ak_format_cents_eur((int)$pdf_invoice['amount_ht_cents'])) ?></td>
            <td class="num"><?= $htF(ak_format_cents_eur((int)$pdf_invoice['amount_ht_cents'])) ?></td>
        </tr>
    </tbody>
</table>

<table class="totals">
    <tr>
        <td class="lbl">Total HT</td>
        <td class="val"><?= $htF(ak_format_cents_eur((int)$pdf_invoice['amount_ht_cents'])) ?></td>
    </tr>
    <?php if ((int)$pdf_invoice['amount_vat_cents'] > 0): ?>
    <tr>
        <td class="lbl">TVA (<?= $htF(number_format((float)$pdf_invoice['vat_rate'], 2, ',', ' ')) ?>%)</td>
        <td class="val"><?= $htF(ak_format_cents_eur((int)$pdf_invoice['amount_vat_cents'])) ?></td>
    </tr>
    <?php else: ?>
    <tr>
        <td class="lbl">TVA non applicable</td>
        <td class="val">0,00 €</td>
    </tr>
    <?php endif; ?>
    <tr class="ttc">
        <td class="lbl">TOTAL TTC</td>
        <td class="val"><?= $htF(ak_format_cents_eur((int)$pdf_invoice['amount_ttc_cents'])) ?></td>
    </tr>
</table>

<?php if (!$vat_subject): ?>
<div class="notice">
    <strong>TVA non applicable — article 293 B du Code Général des Impôts.</strong>
</div>
<?php endif; ?>

<?php if ($em_iban || $em_bic): ?>
<div class="payment-info">
    <div class="title">💳 Informations de paiement</div>
    <table>
        <?php if ($em_iban): ?>
        <tr><td class="k">IBAN</td><td class="v"><?= $htF($em_iban) ?></td></tr>
        <?php endif; ?>
        <?php if ($em_bic): ?>
        <tr><td class="k">BIC / SWIFT</td><td class="v"><?= $htF($em_bic) ?></td></tr>
        <?php endif; ?>
        <?php if (!empty($pdf_emitter['bank_name'])): ?>
        <tr><td class="k">Banque</td><td class="v"><?= $htF($pdf_emitter['bank_name']) ?></td></tr>
        <?php endif; ?>
    </table>
</div>
<?php endif; ?>

<?php if ($is_test): ?>
<div class="notice" style="background:#FEE2E2; border-left-color:#DC2626; color:#991B1B;">
    <strong>⚠ Document émis en mode test.</strong>
    Les informations légales seront complétées dès l'immatriculation de la société émettrice.
    Ce document ne constitue pas une facture légale valide.
</div>
<?php endif; ?>

<div class="footer">
    <?php if ($legal_name): ?>
        <?= $htF($legal_name) ?>
        <?php if ($siren): ?> · SIREN <?= $htF($siren) ?><?php endif; ?>
        <?php if ($em_website): ?> · <?= $htF(preg_replace('#^https?://#', '', $em_website)) ?><?php endif; ?>
    <?php else: ?>
        Facture générée par Assokit le <?= $htF(date('d/m/Y à H:i', strtotime($pdf_invoice['created_at'] ?? 'now'))) ?>
    <?php endif; ?>
</div>

</body>
</html>
