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
        "SELECT i.*, c.display_name client_name, c.email client_email, c.legal_name c_legal,
                c.address_street c_street, c.address_zip c_zip, c.address_city c_city,
                c.address_country c_country, c.siren c_siren, c.siret c_siret, c.vat_number c_vat,
                o.name org_name, o.legal_name org_legal, o.siret o_siret, o.siren o_siren, o.vat_number o_vat,
                o.vat_subject o_vat_subject,
                o.billing_address_street o_street, o.billing_address_zip o_zip,
                o.billing_address_city o_city, o.billing_address_country o_country
         FROM asso_invoices i
         LEFT JOIN asso_clients c ON c.id = i.client_id
         LEFT JOIN organizations o ON o.id = i.org_id
         WHERE i.id = :id AND i.org_id = :o LIMIT 1");
    $st->execute([':id'=>$invoice_id, ':o'=>$org_id]);
    $inv = $st->fetch(PDO::FETCH_ASSOC);
    if (!$inv) return null;

    // Avoir : facture d'origine (référencée dans le XML)
    if (($inv['invoice_type'] ?? 'invoice') === 'credit_note' && !empty($inv['parent_invoice_id'])) {
        try {
            $ps = $pdo->prepare("SELECT invoice_number, issued_at FROM asso_invoices WHERE id = ? AND org_id = ? LIMIT 1");
            $ps->execute([(int)$inv['parent_invoice_id'], $org_id]);
            if ($p = $ps->fetch(PDO::FETCH_ASSOC)) {
                $inv['parent_invoice_number'] = $p['invoice_number'];
                $inv['parent_issued_at'] = $p['issued_at'];
            }
        } catch (Throwable $e) {}
    }

    // Identités figées à l'émission (snapshots), comme le XML embarqué dans le PDF
    // (facturx-helpers.php) et le PDF lui-même : une facture émise ne change pas quand la
    // fiche de l'association ou du client est modifiée. Fiche actuelle seulement pour un
    // champ absent du snapshot (anciennes factures).
    $em = json_decode((string)($inv['emitter_snapshot'] ?? ''), true);
    $cl = json_decode((string)($inv['client_snapshot'] ?? ''), true);
    $em = is_array($em) ? $em : [];
    $cl = is_array($cl) ? $cl : [];
    $pick = function (array $snap, string $key, $live) {
        $v = $snap[$key] ?? null;
        return ($v !== null && trim((string)$v) !== '') ? $v : $live;
    };
    foreach (['org_name' => 'name', 'org_legal' => 'legal_name', 'o_siret' => 'siret', 'o_siren' => 'siren',
              'o_vat' => 'vat_number', 'o_street' => 'billing_address_street', 'o_zip' => 'billing_address_zip',
              'o_city' => 'billing_address_city', 'o_country' => 'billing_address_country'] as $k => $sk) {
        $inv[$k] = $pick($em, $sk, $inv[$k] ?? null);
    }
    if (array_key_exists('vat_subject', $em) && $em['vat_subject'] !== null && $em['vat_subject'] !== '') {
        $inv['o_vat_subject'] = $em['vat_subject'];
    }
    foreach (['client_name' => 'display_name', 'c_legal' => 'legal_name', 'c_street' => 'address_street',
              'c_zip' => 'address_zip', 'c_city' => 'address_city', 'c_country' => 'address_country',
              'c_siren' => 'siren', 'c_siret' => 'siret', 'c_vat' => 'vat_number'] as $k => $sk) {
        $inv[$k] = $pick($cl, $sk, $inv[$k] ?? null);
    }

    // Lignes
    $lines = [];
    try {
        $ls = $pdo->prepare("SELECT designation, quantity, unit_price_ht_cents, vat_rate,
                                    total_ht_cents AS amount_ht_cents, total_ttc_cents AS amount_ttc_cents
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
    require_once __DIR__ . '/facturx-helpers.php';   // ak_fx_siren(), ak_fx_country() : mêmes règles que le XML du PDF
    $inv = $data['inv']; $lines = $data['lines']; $tax = $data['tax'];
    $cur = 'EUR';
    // Avoir : type 381, montants positifs (le signe est porté par le type de document,
    // les montants de l'avoir sont enregistrés négatifs en base) et facture d'origine (BT-25)
    $isCredit = ($inv['invoice_type'] ?? 'invoice') === 'credit_note';
    if ($isCredit) {
        foreach ($lines as &$l) {
            foreach (['unit_price_ht_cents', 'amount_ht_cents', 'amount_ttc_cents'] as $k) {
                if (isset($l[$k])) $l[$k] = abs((int)$l[$k]);
            }
        }
        unset($l);
        foreach ($tax as &$t) { $t['basis_cents'] = abs((int)$t['basis_cents']); $t['tax_cents'] = abs((int)$t['tax_cents']); }
        unset($t);
    }
    $sellerName = $inv['org_legal'] ?: $inv['org_name'] ?: 'Vendeur';
    $buyerName  = ($inv['c_legal'] ?? '') ?: ($inv['client_name'] ?: 'Client');
    $country    = ak_fx_country($inv['o_country'] ?? null);        // code ISO 3166-1 (« France » → FR)
    $buyerCountry = ak_fx_country($inv['c_country'] ?? null);
    // Identifiant légal (BT-30 / BT-47) : SIREN, schéma 0002 (un SIRET à 14 chiffres y était
    // déclaré tel quel, ce que rejettent les contrôles)
    $sellerSiren = ak_fx_siren($inv['o_siren'] ?? null, $inv['o_siret'] ?? null);
    $buyerSiren  = ak_fx_siren($inv['c_siren'] ?? null, $inv['c_siret'] ?? null);
    // Régime TVA du vendeur : n° de TVA et motif d'exonération des lignes à 0 %
    $vatSubject = !empty($inv['o_vat_subject']);
    $sellerVat  = $vatSubject ? strtoupper(preg_replace('/\s+/', '', (string)($inv['o_vat'] ?? ''))) : '';
    $exemptReason = $vatSubject
        ? 'Exonération de TVA, article 261 du CGI'
        : 'TVA non applicable, art. 293 B du CGI';
    $exemptCode = $vatSubject ? '' : 'VATEX-FR-FRANCHISE';

    $x  = '<?xml version="1.0" encoding="UTF-8"?>'."\n";
    $x .= '<rsm:CrossIndustryInvoice'
        . ' xmlns:rsm="urn:un:unece:uncefact:data:standard:CrossIndustryInvoice:100"'
        . ' xmlns:ram="urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100"'
        . ' xmlns:qdt="urn:un:unece:uncefact:data:standard:QualifiedDataType:100"'
        . ' xmlns:udt="urn:un:unece:uncefact:data:standard:UnqualifiedDataType:100">'."\n";

    // Contexte
    $x .= '  <rsm:ExchangedDocumentContext><ram:GuidelineSpecifiedDocumentContextParameter>'
        . '<ram:ID>urn:cen.eu:en16931:2017</ram:ID>'
        . '</ram:GuidelineSpecifiedDocumentContextParameter></rsm:ExchangedDocumentContext>'."\n";

    // Document
    $x .= '  <rsm:ExchangedDocument>'
        . '<ram:ID>'.fx_esc($inv['invoice_number']).'</ram:ID>'
        . '<ram:TypeCode>'.($isCredit ? '381' : '380').'</ram:TypeCode>'
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
    if ($sellerSiren) {
        $x .= '<ram:SpecifiedLegalOrganization><ram:ID schemeID="0002">'.fx_esc($sellerSiren).'</ram:ID></ram:SpecifiedLegalOrganization>';
    }
    $x .= '<ram:PostalTradeAddress>'
        . '<ram:PostcodeCode>'.fx_esc($inv['o_zip'] ?? '').'</ram:PostcodeCode>'
        . '<ram:LineOne>'.fx_esc($inv['o_street'] ?? '').'</ram:LineOne>'
        . '<ram:CityName>'.fx_esc($inv['o_city'] ?? '').'</ram:CityName>'
        . '<ram:CountryID>'.fx_esc($country).'</ram:CountryID>'
        . '</ram:PostalTradeAddress>';
    if ($sellerVat !== '') {
        $x .= '<ram:SpecifiedTaxRegistration><ram:ID schemeID="VA">'.fx_esc($sellerVat).'</ram:ID></ram:SpecifiedTaxRegistration>';
    }
    $x .= '</ram:SellerTradeParty>'."\n";
    // Acheteur
    $x .= '      <ram:BuyerTradeParty>'
        . '<ram:Name>'.fx_esc($buyerName).'</ram:Name>';
    if ($buyerSiren) {
        $x .= '<ram:SpecifiedLegalOrganization><ram:ID schemeID="0002">'.fx_esc($buyerSiren).'</ram:ID></ram:SpecifiedLegalOrganization>';
    }
    $x .= '<ram:PostalTradeAddress>'
        . '<ram:PostcodeCode>'.fx_esc($inv['c_zip'] ?? '').'</ram:PostcodeCode>'
        . '<ram:LineOne>'.fx_esc($inv['c_street'] ?? '').'</ram:LineOne>'
        . '<ram:CityName>'.fx_esc($inv['c_city'] ?? '').'</ram:CityName>'
        . '<ram:CountryID>'.fx_esc($buyerCountry).'</ram:CountryID>'
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
            . ($cat === 'E' ? '<ram:ExemptionReason>'.fx_esc($exemptReason).'</ram:ExemptionReason>' : '')
            . '<ram:BasisAmount>'.fx_num($t['basis_cents']).'</ram:BasisAmount>'
            . '<ram:CategoryCode>'.$cat.'</ram:CategoryCode>'
            . ($cat === 'E' && $exemptCode !== '' ? '<ram:ExemptionReasonCode>'.$exemptCode.'</ram:ExemptionReasonCode>' : '')
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
    if ($isCredit && !empty($inv['parent_invoice_number'])) {
        $x .= '      <ram:InvoiceReferencedDocument><ram:IssuerAssignedID>'.fx_esc($inv['parent_invoice_number']).'</ram:IssuerAssignedID>'
            . (!empty($inv['parent_issued_at']) ? '<ram:FormattedIssueDateTime><qdt:DateTimeString format="102">'.fx_date102($inv['parent_issued_at']).'</qdt:DateTimeString></ram:FormattedIssueDateTime>' : '')
            . '</ram:InvoiceReferencedDocument>'."\n";
    }
    $x .= '    </ram:ApplicableHeaderTradeSettlement>'."\n";

    $x .= '  </rsm:SupplyChainTradeTransaction>'."\n";
    $x .= '</rsm:CrossIndustryInvoice>'."\n";
    return $x;
}
}

if (!function_exists('facturx_org_readiness')) {
/**
 * Diagnostic de préparation e-facture au niveau de la STRUCTURE (checklist).
 * @return array{items:array<array{ok:bool,label:string,hint:string}>, score:int}
 */
function facturx_org_readiness(PDO $pdo, int $org_id): array {
    $items = [];
    // Identité vendeur
    $o = null;
    try {
        $st = $pdo->prepare("SELECT name, siret, siren, vat_number, billing_address_street, billing_address_zip, billing_address_city FROM organizations WHERE id = ?");
        $st->execute([$org_id]); $o = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) { $o = []; }
    $hasId = !empty($o['siret']) || !empty($o['siren']);
    $items[] = ['ok'=>$hasId, 'label'=>'SIRET / SIREN de la structure', 'hint'=>'Paramètres → informations légales'];
    $hasAddr = !empty($o['billing_address_street']) && !empty($o['billing_address_zip']) && !empty($o['billing_address_city']);
    $items[] = ['ok'=>$hasAddr, 'label'=>'Adresse postale complète', 'hint'=>'Rue + code postal + ville'];

    // Complétude des clients (adresse)
    $tot = 0; $complete = 0;
    try {
        $st = $pdo->prepare("SELECT COUNT(*) t, SUM(address_zip IS NOT NULL AND address_zip <> '' AND address_city IS NOT NULL AND address_city <> '') c
                             FROM asso_clients WHERE org_id = ? AND deleted_at IS NULL");
        $st->execute([$org_id]);
        if ($r = $st->fetch(PDO::FETCH_ASSOC)) { $tot = (int)$r['t']; $complete = (int)$r['c']; }
    } catch (Throwable $e) {}
    $clientsOk = ($tot === 0) || ($complete === $tot);
    $items[] = ['ok'=>$clientsOk, 'label'=>'Adresses clients renseignées'.($tot? " ($complete/$tot)":''), 'hint'=>'Code postal + ville sur chaque fiche client'];

    $ok = count(array_filter($items, fn($i)=>$i['ok']));
    return ['items'=>$items, 'score'=>(int)round($ok / max(1,count($items)) * 100)];
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
