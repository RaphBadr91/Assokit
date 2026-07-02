<?php
/**
 * ============================================================
 * ASSOKIT — Génération PDF facture abonnement
 * URL : /facture-abonnement-pdf?id=X
 * ============================================================
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/rate-limit-helper.php';
ak_rate_limit_or_die('pdf_facture', 15, 60, (string)($_SESSION['user_id'] ?? ($_SERVER['REMOTE_ADDR'] ?? 'anon')));
require_once __DIR__ . '/includes-layout.php';
require_once __DIR__ . '/vendor/autoload.php';
require_login();

$current = current_user();
$org_id = (int)($current['org_id'] ?? 0);
$invoice_id = (int)($_GET['id'] ?? 0);

if ($invoice_id <= 0 || $org_id <= 0) { http_response_code(400); die('ID invalide'); }

$stmt = $pdo->prepare("SELECT * FROM subscription_invoices WHERE id = ? AND org_id = ? LIMIT 1");
$stmt->execute([$invoice_id, $org_id]);
$inv = $stmt->fetch();
if (!$inv) { http_response_code(404); die('Facture introuvable.'); }

$stmt = $pdo->prepare("SELECT * FROM organizations WHERE id = ? LIMIT 1");
$stmt->execute([$org_id]);
$org = $stmt->fetch();

function fpdf_date($d) { return $d ? date('d/m/Y', strtotime($d)) : '—'; }
function fpdf_amount($a) { return number_format((float)$a, 2, ',', ' ') . ' €'; }
function fpdf_country($code) {
    static $map = ['FR'=>'France','BE'=>'Belgique','CH'=>'Suisse','LU'=>'Luxembourg','CA'=>'Canada','MA'=>'Maroc','DZ'=>'Algerie','TN'=>'Tunisie','SN'=>'Senegal','CI'=>'Cote d\'Ivoire','DE'=>'Allemagne','ES'=>'Espagne','IT'=>'Italie','PT'=>'Portugal','GB'=>'Royaume-Uni','US'=>'Etats-Unis'];
    return $map[strtoupper((string)$code)] ?? $code;
}
// Logo Assokit en base64 (embarque dans le PDF, plus fiable que chemin)
$logo_assokit_path = __DIR__ . '/assets/logo-assokit.png';
$logo_assokit_b64 = '';
if (file_exists($logo_assokit_path)) {
    $logo_assokit_b64 = 'data:image/png;base64,' . base64_encode(file_get_contents($logo_assokit_path));
}

$status_label = [
  'draft' => 'Brouillon', 'sent' => 'Envoyée', 'paid' => 'Payée',
  'overdue' => 'En retard', 'cancelled' => 'Annulée',
][$inv['status']] ?? $inv['status'];

$html = '<style>
body { font-family: sans-serif; font-size: 11px; color: #0A0A0B; }
h1 { font-size: 24px; color: #059669; margin: 0 0 4px; letter-spacing: 0.02em; }
.meta { font-size: 10px; color: #71717A; line-height: 1.6; }
.section { margin-top: 22px; }
.section h3 { font-size: 11px; margin: 0 0 6px; color: #71717A; text-transform: uppercase; letter-spacing: 0.06em; }
table.items { width: 100%; border-collapse: collapse; margin-top: 18px; }
table.items th { background: #FAFAF9; padding: 10px; text-align: left; border-bottom: 2px solid #059669; font-size: 11px; }
table.items td { padding: 12px 10px; border-bottom: 1px solid #F4F4F5; }
table.totals { width: 40%; margin-left: auto; margin-top: 16px; }
table.totals td { padding: 6px 12px; font-size: 12px; }
.total-final td { font-size: 16px; font-weight: 700; color: #059669; border-top: 2px solid #059669; padding-top: 10px; }
.badge { display: inline-block; padding: 4px 10px; border-radius: 999px; font-size: 11px; font-weight: 600; }
.badge-paid { background: #D1FAE5; color: #065F46; }
.badge-other { background: #F4F4F5; color: #3F3F46; }
</style>

<table style="width:100%"><tr>
  <td style="vertical-align:top;">
    <h1>FACTURE</h1>
    <div class="meta"><strong>N°</strong> ' . htmlspecialchars($inv['invoice_number']) . '</div>
    <div class="meta"><strong>Émise le</strong> ' . fpdf_date($inv['issued_at']) . '</div>
    <div class="meta"><strong>Échéance</strong> ' . fpdf_date($inv['due_date']) . '</div>
  </td>
  <td style="vertical-align:top;text-align:right;">
    ' . ($logo_assokit_b64 ? '<img src="' . $logo_assokit_b64 . '" style="height:38px;margin-bottom:6px;">' : '<div style="font-weight:700;font-size:20px;color:#059669;">Assokit</div>') . '
    <div class="meta" style="font-weight:600;color:#3F3F46;">Logiciel de Gestion intégré avec IA<br>pour association et TPE</div>
    <div class="meta" style="margin-top:4px;">contact@assokit.fr</div>
    <div class="meta">https://assokit.fr</div>
  </td>
</tr></table>

<div class="section">
  <h3>Facturé à</h3>
  <div style="font-weight:700;font-size:14px;">' . htmlspecialchars(!empty($org['legal_name']) ? $org['legal_name'] : ($org['name'] ?? '—')) . '</div>
  ' . (!empty($org['legal_form']) ? '<div class="meta" style="font-style:italic;">' . htmlspecialchars($org['legal_form']) . '</div>' : '') . '
  ' . (!empty($org['billing_address_street']) ? '<div class="meta" style="margin-top:6px;">' . htmlspecialchars($org['billing_address_street']) . '</div>' : '') . '
  ' . (!empty($org['billing_address_complement']) ? '<div class="meta">' . htmlspecialchars($org['billing_address_complement']) . '</div>' : '') . '
  ' . (!empty($org['billing_address_zip']) || !empty($org['billing_address_city']) ? '<div class="meta">' . htmlspecialchars(trim(($org['billing_address_zip'] ?? '') . ' ' . ($org['billing_address_city'] ?? ''))) . '</div>' : '') . '
  ' . (!empty($org['billing_address_country']) ? '<div class="meta">' . htmlspecialchars(fpdf_country($org['billing_address_country'])) . '</div>' : '') . '
  ' . (!empty($org['siren']) ? '<div class="meta" style="margin-top:6px;">SIREN : ' . htmlspecialchars($org['siren']) . '</div>' : '') . '
  ' . (!empty($org['siret']) ? '<div class="meta">SIRET : ' . htmlspecialchars($org['siret']) . '</div>' : '') . '
  ' . (!empty($org['rna_number']) ? '<div class="meta">RNA : ' . htmlspecialchars($org['rna_number']) . '</div>' : '') . '
  ' . (!empty($org['vat_subject']) && !empty($org['vat_number']) ? '<div class="meta">N° TVA intracommunautaire : ' . htmlspecialchars($org['vat_number']) . '</div>' : '') . '
  ' . (empty($org['billing_address_street']) ? '<div class="meta" style="margin-top:8px;font-style:italic;color:#A1A1AA;">Adresse de facturation à compléter dans Paramètres → Association</div>' : '') . '
</div>

<table class="items">
  <thead><tr><th>Description</th><th style="text-align:right;">Montant HT</th></tr></thead>
  <tbody>
    <tr>
      <td>
        <strong>Abonnement Assokit</strong><br>
        <span class="meta">Période du ' . fpdf_date($inv['period_start']) . ' au ' . fpdf_date($inv['period_end']) . '</span>
      </td>
      <td style="text-align:right;font-weight:600;">' . fpdf_amount($inv['amount_ht']) . '</td>
    </tr>
  </tbody>
</table>

<table class="totals">
  <tr><td>Sous-total HT</td><td style="text-align:right;">' . fpdf_amount($inv['amount_ht']) . '</td></tr>
  <tr><td>TVA (' . number_format((float)$inv['tva_rate'], 2, ',', '') . ' %)</td><td style="text-align:right;">' . fpdf_amount($inv['amount_tva']) . '</td></tr>
  <tr class="total-final"><td>Total TTC</td><td style="text-align:right;">' . fpdf_amount($inv['amount_ttc']) . '</td></tr>
</table>

<div class="section">
  <h3>Statut du paiement</h3>
  <span class="badge ' . ($inv['status'] === 'paid' ? 'badge-paid' : 'badge-other') . '">' . htmlspecialchars($status_label) . '</span>
  ' . (!empty($inv['paid_at']) ? '<div class="meta" style="margin-top:6px;">Payée le ' . fpdf_date($inv['paid_at']) . (!empty($inv['payment_method']) ? ' — ' . htmlspecialchars($inv['payment_method']) : '') . '</div>' : '') . '
</div>

<div style="margin-top:40px;font-size:9px;color:#A1A1AA;border-top:1px solid #E4E4E7;padding-top:12px;text-align:center;">
  Facture générée par Assokit · ' . date('d/m/Y H:i') . '
</div>';

try {
    $mpdf = new \Mpdf\Mpdf([
        'mode' => 'utf-8', 'format' => 'A4',
        'margin_left' => 15, 'margin_right' => 15,
        'margin_top' => 15, 'margin_bottom' => 15,
    ]);
    $mpdf->SetTitle('Facture ' . $inv['invoice_number']);
    $mpdf->WriteHTML($html);
    $filename = 'facture-' . preg_replace('/[^A-Za-z0-9\-]/', '-', $inv['invoice_number']) . '.pdf';
    $mpdf->Output($filename, 'I');
} catch (\Throwable $e) {
    http_response_code(500);
    die('Erreur génération PDF : ' . htmlspecialchars($e->getMessage()));
}
