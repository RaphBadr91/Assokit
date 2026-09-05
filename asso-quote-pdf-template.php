<?php
/**
 * asso-quote-pdf-template.php
 * Template PDF du devis (avec mention "DEVIS" et signature si signé)
 */

$emName = $pdf_emitter['legal_name_effective'] ?? $pdf_emitter['name'] ?? 'Association';
$emForm = $pdf_emitter['legal_form'] ?? '';
$emRna = $pdf_emitter['rna_number'] ?? '';
$emSiren = $pdf_emitter['siren'] ?? '';
$emStreet = $pdf_emitter['billing_address_street'] ?? '';
$emCompl = $pdf_emitter['billing_address_complement'] ?? '';
$emZip = $pdf_emitter['billing_address_zip'] ?? '';
$emCity = $pdf_emitter['billing_address_city'] ?? '';
$emEmail = $pdf_emitter['billing_email_effective'] ?? $pdf_emitter['billing_email'] ?? '';
$emPhone = $pdf_emitter['billing_phone'] ?? '';
$emLogo = $pdf_emitter['logo_path'] ?? '';

$logo_full_path = '';
if (!empty($emLogo)) {
    $candidate = __DIR__ . $emLogo;
    if (file_exists($candidate)) $logo_full_path = $candidate;
}

$clName = $pdf_client['display_name'] ?? 'Client';
$clLegal = $pdf_client['legal_name'] ?? '';
$clStreet = $pdf_client['address_street'] ?? '';
$clCompl = $pdf_client['address_complement'] ?? '';
$clZip = $pdf_client['address_zip'] ?? '';
$clCity = $pdf_client['address_city'] ?? '';
$clSiren = $pdf_client['siren'] ?? '';

$dateIssue = date('d/m/Y', strtotime($pdf_quote['issued_at']));
$dateExp = date('d/m/Y', strtotime($pdf_quote['expires_at']));

$is_signed = !empty($pdf_quote['signed_at']);
$signature_image = '';
if ($is_signed && !empty($pdf_quote['signature_image_path'])) {
    $sig_full = __DIR__ . $pdf_quote['signature_image_path'];
    if (file_exists($sig_full)) $signature_image = $sig_full;
}

$htF = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title>Devis <?= $htF($pdf_quote['quote_number']) ?></title>
<style>
@page { margin: 15mm; }
body { font-family: 'DejaVu Sans', sans-serif; font-size: 10pt; color: #1F2937; line-height: 1.5; }
.header { width: 100%; margin-bottom: 18px; }
.header td { vertical-align: top; }
.logo-block { text-align: left; }
.brand-name { font-size: 22pt; font-weight: bold; color: #7E22CE; letter-spacing: -0.5px; }
.legal-block { font-size: 8.5pt; color: #4B5563; line-height: 1.6; margin-top: 8px; }
.quote-title { text-align: right; }
.quote-title h1 { font-size: 24pt; color: #7E22CE; margin: 0 0 6px 0; font-weight: 700; letter-spacing: 1px; }
.quote-title .number { font-size: 12pt; color: #1F2937; font-weight: 600; }
.signed-badge { background: #10B981; color: white; padding: 4px 12px; border-radius: 4px; font-size: 10pt; font-weight: bold; display: inline-block; margin-top: 4px; }
.dates td { padding: 3px 0; font-size: 9.5pt; }
.dates .k { color: #6B7280; width: 90px; }
.dates .v { color: #1F2937; font-weight: 600; }
.client-box { background: #F3F4F6; border-left: 3px solid #7E22CE; padding: 12px 14px; border-radius: 4px; font-size: 9.5pt; }
.client-box .label { font-size: 8pt; color: #6B7280; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 4px; }
.client-box .name { font-size: 11pt; font-weight: 700; color: #111827; margin-bottom: 4px; }
table.lines { width: 100%; border-collapse: collapse; margin: 14px 0; }
table.lines thead th { background: #7E22CE; color: #fff; padding: 9px 8px; font-size: 8.5pt; text-align: left; text-transform: uppercase; font-weight: 600; letter-spacing: 0.5px; }
table.lines thead th.num { text-align: right; }
table.lines tbody td { padding: 10px 8px; border-bottom: 1px solid #E5E7EB; font-size: 9.5pt; }
table.lines tbody td.num { text-align: right; font-variant-numeric: tabular-nums; }
.totals { width: 100%; margin-top: 4px; }
.totals td { padding: 4px 8px; font-size: 9.5pt; }
.totals .lbl { text-align: right; color: #6B7280; }
.totals .val { text-align: right; font-variant-numeric: tabular-nums; width: 110px; font-weight: 500; }
.totals .ttc .lbl, .totals .ttc .val { background: #7E22CE; color: #fff; font-size: 11.5pt; font-weight: 700; padding: 9px; }
.terms-box { margin-top: 18px; padding: 12px 14px; background: #FEF3C7; border-left: 3px solid #F59E0B; font-size: 9pt; color: #78350F; border-radius: 4px; }
.signature-box { margin-top: 18px; padding: 14px; background: #F0FDF4; border: 2px solid #10B981; border-radius: 8px; }
.signature-box .title { font-size: 11pt; font-weight: bold; color: #065F46; margin-bottom: 8px; }
.signature-box img { max-height: 30mm; max-width: 80mm; }
.footer { margin-top: 22px; padding-top: 10px; border-top: 1px solid #E5E7EB; font-size: 7.5pt; color: #6B7280; text-align: center; }
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
            <?php if ($emForm): ?><div style="font-size:9pt; color:#6B7280;"><?= $htF($emForm) ?></div><?php endif; ?>
            <div class="legal-block">
                <?php if ($emStreet): ?><?= $htF($emStreet) ?><br><?php endif; ?>
                <?php if ($emCompl): ?><?= $htF($emCompl) ?><br><?php endif; ?>
                <?php if ($emZip || $emCity): ?><?= $htF(trim($emZip . ' ' . $emCity)) ?><br><?php endif; ?>
                <?php if ($emRna): ?>RNA : <?= $htF($emRna) ?><br><?php endif; ?>
                <?php if ($emSiren): ?>SIREN : <?= $htF($emSiren) ?><br><?php endif; ?>
                <?php if ($emEmail): ?><?= $htF($emEmail) ?><?php endif; ?>
                <?php if ($emPhone): ?> · <?= $htF($emPhone) ?><?php endif; ?>
            </div>
        </td>
        <td class="quote-title">
            <h1>DEVIS</h1>
            <div class="number"><?= $htF($pdf_quote['quote_number']) ?></div>
            <?php if ($is_signed): ?>
                <div class="signed-badge">✓ SIGNÉ le <?= $htF(date('d/m/Y', strtotime($pdf_quote['signed_at']))) ?></div>
            <?php endif; ?>
        </td>
    </tr>
</table>

<div style="margin: 18px 0;">
    <table style="width:100%; border-collapse:collapse;">
        <tr>
            <td style="width:42%; vertical-align:top;">
                <table class="dates">
                    <tr><td class="k">Émis le</td><td class="v"><?= $htF($dateIssue) ?></td></tr>
                    <tr><td class="k">Valable jusqu'au</td><td class="v"><?= $htF($dateExp) ?></td></tr>
                </table>
            </td>
            <td style="width:58%; vertical-align:top;">
                <div class="client-box">
                    <div class="label">Adressé à</div>
                    <div class="name"><?= $htF($clName) ?></div>
                    <?php if ($clLegal && $clLegal !== $clName): ?><?= $htF($clLegal) ?><br><?php endif; ?>
                    <?php if ($clStreet): ?><?= $htF($clStreet) ?><br><?php endif; ?>
                    <?php if ($clCompl): ?><?= $htF($clCompl) ?><br><?php endif; ?>
                    <?php if ($clZip || $clCity): ?><?= $htF(trim($clZip . ' ' . $clCity)) ?><br><?php endif; ?>
                    <?php if ($clSiren): ?><span style="color:#6B7280; font-size:8.5pt;">SIREN <?= $htF($clSiren) ?></span><?php endif; ?>
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
                <td class="num"><?= !empty($line['vat_rate']) && (float)$line['vat_rate'] > 0 ? $htF(number_format((float)$line['vat_rate'], 1, ',', ' ')) . '%' : '—' ?></td>
                <td class="num"><?= $htF(ak_asso_fmt_cents((int)$line['total_ht_cents'])) ?></td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>

<table class="totals">
    <tr><td class="lbl">Total HT</td><td class="val"><?= $htF(ak_asso_fmt_cents((int)$pdf_quote['amount_ht_cents'])) ?></td></tr>
    <?php if ((int)$pdf_quote['amount_vat_cents'] > 0): ?>
        <tr><td class="lbl">TVA</td><td class="val"><?= $htF(ak_asso_fmt_cents((int)$pdf_quote['amount_vat_cents'])) ?></td></tr>
    <?php else: ?>
        <tr><td class="lbl">TVA non applicable</td><td class="val">0,00 €</td></tr>
    <?php endif; ?>
    <tr class="ttc"><td class="lbl">TOTAL TTC</td><td class="val"><?= $htF(ak_asso_fmt_cents((int)$pdf_quote['amount_ttc_cents'])) ?></td></tr>
</table>

<?php if (!empty($pdf_quote['terms'])): ?>
<div class="terms-box">
    <strong>Conditions :</strong> <?= $htF($pdf_quote['terms']) ?>
</div>
<?php endif; ?>

<?php if ($is_signed): ?>
<div class="signature-box">
    <div class="title">✓ DEVIS ACCEPTÉ ET SIGNÉ</div>
    <table style="width:100%;">
        <tr>
            <td style="vertical-align:top;">
                <strong><?= $htF($pdf_quote['signature_name']) ?></strong><br>
                <span style="font-size:9pt; color:#065F46;">
                    Signé le <?= $htF(date('d/m/Y à H:i', strtotime($pdf_quote['signed_at']))) ?>
                    <?php if (!empty($pdf_quote['signature_ip'])): ?>
                        <br>IP : <?= $htF($pdf_quote['signature_ip']) ?>
                    <?php endif; ?>
                </span>
            </td>
            <td style="text-align:right; vertical-align:top;">
                <?php if ($signature_image): ?>
                    <img src="<?= $htF($signature_image) ?>" alt="Signature">
                <?php else: ?>
                    <span style="font-size:9pt; color:#065F46;">Signature électronique</span>
                <?php endif; ?>
            </td>
        </tr>
    </table>
</div>
<?php else: ?>
<div style="margin-top:18px; padding:14px; background:#F3F4F6; border:1px dashed #9CA3AF; border-radius:8px; text-align:center; font-size:9pt; color:#6B7280;">
    Pour accepter ce devis, signez-le en ligne sur la page :<br>
    <strong>https://assokit.fr/devis/<?= $htF($pdf_quote['public_uuid']) ?></strong>
</div>
<?php endif; ?>

<div class="footer">
    Devis émis le <?= $htF(date('d/m/Y à H:i', strtotime($pdf_quote['created_at'] ?? 'now'))) ?>
    <?php if ($emName): ?> par <?= $htF($emName) ?><?php endif; ?>
</div>

</body>
</html>
