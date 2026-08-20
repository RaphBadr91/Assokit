<?php
/**
 * facturx-engine.php — Générateur Factur-X (XML CII, profil EN 16931)
 * ------------------------------------------------------------------
 * Produit le XML « Cross Industry Invoice » (UN/CEFACT CII) conforme à
 * la norme EN 16931 (socle de la facture électronique française 2026),
 * à partir d'une facture Assokit (asso_invoices + asso_invoice_lines).
 *
 * ⚠️ Cette brique génère le XML STRUCTURÉ (le cœur normatif). Son
 *    intégration dans un PDF/A-3 (fichier Factur-X complet) et le
 *    routage via une Plateforme Agréée (PDP) sont des étapes distinctes
 *    (choix stratégique + PDF/A-3). Le XML seul permet déjà de VALIDER
 *    le format techniquement (validateurs EN 16931 en ligne).
 *
 * Montants asso_invoices/lines en CENTIMES. Sortie XML : point décimal,
 * 2 décimales, devise EUR.
 * ------------------------------------------------------------------
 */

if (!function_exists('fx_num')) {
function fx_num($cents_or_eur, bool $is_cents = true): string {
    $v = $is_cents ? ((int)$cents_or_eur)/100 : (float)$cents_or_eur;
    return number_format($v, 2, '.', '');
}
}
if (!function_exists('fx_pct')) {
function fx_pct($rate): string { return number_format((float)$rate, 2, '.', ''); }
}
if (!function_exists('fx_esc')) {
function fx_esc($s): string { return htmlspecialchars((string)$s, ENT_XML1 | ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('fx_date102')) {
function fx_date102(?string $d): string { return $d ? date('Ymd', strtotime($d)) : date('Ymd'); }
}

if (!function_exists('facturx_build_data')) {
/**
 * Rassemble les données d'une facture pour le XML. Retourne null si absente
 * ou hors org. Reconstruit une ligne unique depuis l'en-tête si aucune ligne.
 */
function facturx_build_data(PDO $pdo, int $org_id, int $invoice_id): ?array {
    $st = $pdo->prepare(
        "SELECT i.*, c.display_name client_name, c.email client_email,
                c.address_street c_street, c.address_zip c_zip, c.address_city c_city, c.vat_number c_vat,
                o.name org_name, o.legal_name org_legal, o.siret o_siret, o.siren o_siren, o.vat_number o_vat,
                o.billing_address_street o_street, o.billing_address_zip o_zip,
                o.billing_address_city o_city, o.billing_address_country o_country
         FROM asso_invoices i
         LEFT JOIN asso_clients c ON c.id = i.client_id
         LEFT JOIN organizations o ON o.id = i.org_id
         WHERE i.id = :id AND i.org_id = :o LIMIT 1");
    $st->execute([':id'=>$invoice_id, ':o'=>$org_id]);
    $inv = $st->fetch(PDO::FETCH_ASSOC);
    if (!$inv) return null;

    // Lignes
    $lines = [];
    try {
        $ls = $pdo->prepare("SELECT designation, quantity, unit_price_ht_cents, vat_rate, amount_ht_cents, amount_ttc_cents
                             FROM asso_invoice_lines WHERE invoice_id = ? ORDER BY line_order ASC, id ASC");
        $ls->execute([$invoice_id]);
        $lines = $ls->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {}

    if (!$lines) {
        // Reconstruction d'une ligne unique depuis l'en-tête.
        $ht = (int)$inv['amount_ht_cents']; $ttc = (int)$inv['amount_ttc_cents'];
        $rate = ($ht > 0) ? round(((int)$inv['amount_vat_cents']) / $ht * 100, 2) : 0.0;
        $lines = [[
            'designation' => 'Prestation', 'quantity' => 1,
            'unit_price_ht_cents' => $ht, 'vat_rate' => $rate,
            'amount_ht_cents' => $ht, 'amount_ttc_cents' => $ttc,
        ]];
    }

    // Groupes de TVA (par taux)
    $tax = [];
    foreach ($lines as $l) {
        $rate = (float)($l['vat_rate'] ?? 0);
        $key = number_format($rate, 2);
        if (!isset($tax[$key])) $tax[$key] = ['rate'=>$rate, 'basis_cents'=>0, 'tax_cents'=>0];
        $lineHt = (int)$l['amount_ht_cents'];
        $tax[$key]['basis_cents'] += $lineHt;
        $tax[$key]['tax_cents']   += (int)round($lineHt * $rate / 100);
    }

    return ['inv'=>$inv, 'lines'=>$lines, 'tax'=>array_values($tax)];
}
}

if (!function_exists('facturx_cii_xml')) {
/** Construit le XML CII (profil EN 16931) depuis facturx_build_data(). */
function facturx_cii_xml(array $data): string {
    $inv = $data['inv']; $lines = $data['lines']; $tax = $data['tax'];
    $cur = 'EUR';
    $sellerName = $inv['org_legal'] ?: $inv['org_name'] ?: 'Vendeur';
    $buyerName  = $inv['client_name'] ?: 'Client';
    $country    = $inv['o_country'] ?: 'FR';

    $x  = '<?xml version="1.0" encoding="UTF-8"?>'."\n";
    $x .= '<rsm:CrossIndustryInvoice'
        . ' xmlns:rsm="urn:un:unece:uncefact:data:standard:CrossIndustryInvoice:100"'
        . ' xmlns:ram="urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100"'
        . ' xmlns:udt="urn:un:unece:uncefact:data:standard:UnqualifiedDataType:100">'."\n";

    // Contexte
    $x .= '  <rsm:ExchangedDocumentContext><ram:GuidelineSpecifiedDocumentContextParameter>'
        . '<ram:ID>urn:cen.eu:en16931:2017</ram:ID>'
        . '</ram:GuidelineSpecifiedDocumentContextParameter></rsm:ExchangedDocumentContext>'."\n";

    // Document
    $x .= '  <rsm:ExchangedDocument>'
        . '<ram:ID>'.fx_esc($inv['invoice_number']).'</ram:ID>'
        . '<ram:TypeCode>380</ram:TypeCode>'
        . '<ram:IssueDateTime><udt:DateTimeString format="102">'.fx_date102($inv['issued_at']).'</udt:DateTimeString></ram:IssueDateTime>'
        . '</rsm:ExchangedDocument>'."\n";

    $x .= '  <rsm:SupplyChainTradeTransaction>'."\n";

    // Lignes
    $n = 0;
    foreach ($lines as $l) {
        $n++;
        $rate = (float)($l['vat_rate'] ?? 0);
        $cat = $rate > 0 ? 'S' : 'E';
        $x .= '    <ram:IncludedSupplyChainTradeLineItem>'
            . '<ram:AssociatedDocumentLineDocument><ram:LineID>'.$n.'</ram:LineID></ram:AssociatedDocumentLineDocument>'
            . '<ram:SpecifiedTradeProduct><ram:Name>'.fx_esc($l['designation'] ?: 'Article').'</ram:Name></ram:SpecifiedTradeProduct>'
            . '<ram:SpecifiedLineTradeAgreement><ram:NetPriceProductTradePrice><ram:ChargeAmount>'.fx_num($l['unit_price_ht_cents']).'</ram:ChargeAmount></ram:NetPriceProductTradePrice></ram:SpecifiedLineTradeAgreement>'
            . '<ram:SpecifiedLineTradeDelivery><ram:BilledQuantity unitCode="C62">'.number_format((float)($l['quantity'] ?? 1), 2, '.', '').'</ram:BilledQuantity></ram:SpecifiedLineTradeDelivery>'
            . '<ram:SpecifiedLineTradeSettlement>'
            . '<ram:ApplicableTradeTax><ram:TypeCode>VAT</ram:TypeCode><ram:CategoryCode>'.$cat.'</ram:CategoryCode><ram:RateApplicablePercent>'.fx_pct($rate).'</ram:RateApplicablePercent></ram:ApplicableTradeTax>'
            . '<ram:SpecifiedTradeSettlementLineMonetarySummation><ram:LineTotalAmount>'.fx_num($l['amount_ht_cents']).'</ram:LineTotalAmount></ram:SpecifiedTradeSettlementLineMonetarySummation>'
            . '</ram:SpecifiedLineTradeSettlement>'
            . '</ram:IncludedSupplyChainTradeLineItem>'."\n";
    }

    // Accord (vendeur / acheteur)
    $x .= '    <ram:ApplicableHeaderTradeAgreement>'."\n";
    // Vendeur
    $x .= '      <ram:SellerTradeParty>'
        . '<ram:Name>'.fx_esc($sellerName).'</ram:Name>';
    if (!empty($inv['o_siret'])) {
        $x .= '<ram:SpecifiedLegalOrganization><ram:ID schemeID="0002">'.fx_esc(preg_replace('/\s/','',$inv['o_siret'])).'</ram:ID></ram:SpecifiedLegalOrganization>';
    }
    $x .= '<ram:PostalTradeAddress>'
        . '<ram:PostcodeCode>'.fx_esc($inv['o_zip'] ?? '').'</ram:PostcodeCode>'
        . '<ram:LineOne>'.fx_esc($inv['o_street'] ?? '').'</ram:LineOne>'
        . '<ram:CityName>'.fx_esc($inv['o_city'] ?? '').'</ram:CityName>'
        . '<ram:CountryID>'.fx_esc($country).'</ram:CountryID>'
        . '</ram:PostalTradeAddress>';
    if (!empty($inv['o_vat'])) {
        $x .= '<ram:SpecifiedTaxRegistration><ram:ID schemeID="VA">'.fx_esc($inv['o_vat']).'</ram:ID></ram:SpecifiedTaxRegistration>';
    }
    $x .= '</ram:SellerTradeParty>'."\n";
    // Acheteur
    $x .= '      <ram:BuyerTradeParty>'
        . '<ram:Name>'.fx_esc($buyerName).'</ram:Name>'
        . '<ram:PostalTradeAddress>'
        . '<ram:PostcodeCode>'.fx_esc($inv['c_zip'] ?? '').'</ram:PostcodeCode>'
        . '<ram:LineOne>'.fx_esc($inv['c_street'] ?? '').'</ram:LineOne>'
        . '<ram:CityName>'.fx_esc($inv['c_city'] ?? '').'</ram:CityName>'
        . '<ram:CountryID>FR</ram:CountryID>'
        . '</ram:PostalTradeAddress>';
    if (!empty($inv['c_vat'])) {
        $x .= '<ram:SpecifiedTaxRegistration><ram:ID schemeID="VA">'.fx_esc($inv['c_vat']).'</ram:ID></ram:SpecifiedTaxRegistration>';
    }
    $x .= '</ram:BuyerTradeParty>'."\n";
    $x .= '    </ram:ApplicableHeaderTradeAgreement>'."\n";

    // Livraison
    $x .= '    <ram:ApplicableHeaderTradeDelivery/>'."\n";

    // Règlement
    $x .= '    <ram:ApplicableHeaderTradeSettlement>'
        . '<ram:InvoiceCurrencyCode>'.$cur.'</ram:InvoiceCurrencyCode>'."\n";
    foreach ($tax as $t) {
        $cat = $t['rate'] > 0 ? 'S' : 'E';
        $x .= '      <ram:ApplicableTradeTax>'
            . '<ram:CalculatedAmount>'.fx_num($t['tax_cents']).'</ram:CalculatedAmount>'
            . '<ram:TypeCode>VAT</ram:TypeCode>'
            . ($cat === 'E' ? '<ram:ExemptionReason>Non assujetti a la TVA</ram:ExemptionReason>' : '')
            . '<ram:BasisAmount>'.fx_num($t['basis_cents']).'</ram:BasisAmount>'
            . '<ram:CategoryCode>'.$cat.'</ram:CategoryCode>'
            . '<ram:RateApplicablePercent>'.fx_pct($t['rate']).'</ram:RateApplicablePercent>'
            . '</ram:ApplicableTradeTax>'."\n";
    }
    if (!empty($inv['due_at'])) {
        $x .= '      <ram:SpecifiedTradePaymentTerms><ram:DueDateDateTime><udt:DateTimeString format="102">'.fx_date102($inv['due_at']).'</udt:DateTimeString></ram:DueDateDateTime></ram:SpecifiedTradePaymentTerms>'."\n";
    }
    // Totaux dérivés des lignes -> BR-CO-10/13/14/15 respectées par construction
    // (grand total = base HT + total TVA ; insensible aux incohérences d'en-tête).
    $lineTotal = 0; foreach ($lines as $l) $lineTotal += (int)$l['amount_ht_cents'];
    $taxTotal = 0; foreach ($tax as $t) $taxTotal += $t['tax_cents'];
    $grand = $lineTotal + $taxTotal;
    $x .= '      <ram:SpecifiedTradeSettlementHeaderMonetarySummation>'
        . '<ram:LineTotalAmount>'.fx_num($lineTotal).'</ram:LineTotalAmount>'
        . '<ram:TaxBasisTotalAmount>'.fx_num($lineTotal).'</ram:TaxBasisTotalAmount>'
        . '<ram:TaxTotalAmount currencyID="'.$cur.'">'.fx_num($taxTotal).'</ram:TaxTotalAmount>'
        . '<ram:GrandTotalAmount>'.fx_num($grand).'</ram:GrandTotalAmount>'
        . '<ram:DuePayableAmount>'.fx_num($grand).'</ram:DuePayableAmount>'
        . '</ram:SpecifiedTradeSettlementHeaderMonetarySummation>'."\n";
    $x .= '    </ram:ApplicableHeaderTradeSettlement>'."\n";

    $x .= '  </rsm:SupplyChainTradeTransaction>'."\n";
    $x .= '</rsm:CrossIndustryInvoice>'."\n";
    return $x;
}
}

if (!function_exists('facturx_readiness')) {
/**
 * Diagnostic de complétude des données pour une facture Factur-X conforme.
 * @return array{ok:bool, missing:array<string>}
 */
function facturx_readiness(array $inv): array {
    $missing = [];
    if (empty($inv['o_siret']) && empty($inv['o_siren'])) $missing[] = "SIRET/SIREN de votre structure (Paramètres)";
    if (empty($inv['o_street']) || empty($inv['o_zip']) || empty($inv['o_city'])) $missing[] = "Adresse complète de votre structure";
    if (empty($inv['client_name'])) $missing[] = "Nom du client";
    if (empty($inv['c_zip']) || empty($inv['c_city'])) $missing[] = "Adresse du client (CP + ville)";
    return ['ok'=>empty($missing), 'missing'=>$missing];
}
}
