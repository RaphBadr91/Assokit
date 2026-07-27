<?php
/**
 * facturx-helpers.php — Génération Factur-X (norme DGFiP / EN 16931).
 *
 * Produit le XML CII (Cross Industry Invoice) au profil MINIMUM — le profil
 * d'entrée accepté par l'administration fiscale française pour la facturation
 * électronique — puis fournit les métadonnées XMP nécessaires à l'embarquement
 * dans un PDF/A-3 (Factur-X).
 *
 * Le XML est ensuite embarqué dans le PDF par mPDF (voir asso-invoice-helpers.php).
 * Un PDF Factur-X reste un PDF lisible normalement : le XML est un attachement.
 *
 * Profil MINIMUM : en-tête + identités (SIREN) + totaux. Suffisant et robuste.
 * (On pourra passer à BASIC/EN16931 avec le détail des lignes lors du branchement PDP.)
 */

if (!defined('AK_FACTURX_ENABLED')) define('AK_FACTURX_ENABLED', true);

if (!function_exists('ak_fx_x')) {
    function ak_fx_x($s): string { return htmlspecialchars((string) $s, ENT_XML1 | ENT_QUOTES, 'UTF-8'); }
}

if (!function_exists('ak_fx_country')) {
    /** Normalise un pays en code ISO 3166-1 alpha-2 (défaut FR). */
    function ak_fx_country(?string $c): string {
        $c = trim((string) $c);
        if ($c === '') return 'FR';
        if (preg_match('/^[A-Za-z]{2}$/', $c)) return strtoupper($c);
        $map = ['france' => 'FR', 'belgique' => 'BE', 'suisse' => 'CH', 'luxembourg' => 'LU', 'allemagne' => 'DE', 'espagne' => 'ES', 'italie' => 'IT'];
        return $map[mb_strtolower($c)] ?? 'FR';
    }
}

if (!function_exists('ak_fx_amount')) {
    /** Centimes -> montant décimal (point, 2 décimales) pour le XML. */
    function ak_fx_amount(int $cents): string { return number_format($cents / 100, 2, '.', ''); }
}

if (!function_exists('ak_fx_siren')) {
    /** Extrait un SIREN (9 chiffres) depuis un SIREN ou un SIRET (14). */
    function ak_fx_siren(?string $siren, ?string $siret): ?string {
        $s = preg_replace('/\D/', '', (string) $siren);
        if (strlen($s) >= 9) return substr($s, 0, 9);
        $t = preg_replace('/\D/', '', (string) $siret);
        if (strlen($t) >= 9) return substr($t, 0, 9);
        return null;
    }
}

if (!function_exists('ak_facturx_build_xml')) {
    /**
     * Construit le XML Factur-X (profil MINIMUM) d'une facture.
     * Renvoie le XML, ou null si l'émetteur n'a pas de SIREN (XML non conforme sinon).
     */
    function ak_facturx_build_xml(array $inv, array $emitter, array $client): ?string {
        $sellerSiren = ak_fx_siren($emitter['siren'] ?? null, $emitter['siret'] ?? null);
        if (!$sellerSiren) return null; // sans identifiant légal, on n'émet pas de XML (mieux que non conforme)

        $num    = (string) ($inv['invoice_number'] ?? '');
        $issued = date('Ymd', strtotime((string) ($inv['issued_at'] ?? 'now')));
        $curr   = (string) ($inv['currency'] ?? 'EUR') ?: 'EUR';

        $sellerName = trim((string) ($emitter['legal_name'] ?? '')) ?: trim((string) ($emitter['name'] ?? 'Émetteur'));
        $sellerCountry = ak_fx_country($emitter['billing_address_country'] ?? null);
        $sellerVat = (!empty($emitter['vat_subject']) && !empty($emitter['vat_number'])) ? trim((string) $emitter['vat_number']) : null;

        $buyerName = trim((string) ($client['legal_name'] ?? '')) ?: trim((string) ($client['display_name'] ?? 'Client'));
        $buyerCountry = ak_fx_country($client['address_country'] ?? null);
        $buyerSiren = ak_fx_siren($client['siren'] ?? null, $client['siret'] ?? null);
        $buyerRef = trim((string) ($client['siret'] ?? '')) ?: null; // BT-10 (routage), optionnel

        $ht  = ak_fx_amount((int) ($inv['amount_ht_cents'] ?? 0));
        $vat = ak_fx_amount((int) ($inv['amount_vat_cents'] ?? 0));
        $ttc = ak_fx_amount((int) ($inv['amount_ttc_cents'] ?? 0));

        $sellerLegal = '<ram:SpecifiedLegalOrganization><ram:ID schemeID="0002">' . ak_fx_x($sellerSiren) . '</ram:ID></ram:SpecifiedLegalOrganization>';
        $sellerVatXml = $sellerVat ? '<ram:SpecifiedTaxRegistration><ram:ID schemeID="VA">' . ak_fx_x($sellerVat) . '</ram:ID></ram:SpecifiedTaxRegistration>' : '';
        $buyerLegal = $buyerSiren ? '<ram:SpecifiedLegalOrganization><ram:ID schemeID="0002">' . ak_fx_x($buyerSiren) . '</ram:ID></ram:SpecifiedLegalOrganization>' : '';
        $buyerRefXml = $buyerRef ? '<ram:BuyerReference>' . ak_fx_x($buyerRef) . '</ram:BuyerReference>' : '';

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<rsm:CrossIndustryInvoice'
            . ' xmlns:rsm="urn:un:unece:uncefact:data:standard:CrossIndustryInvoice:100"'
            . ' xmlns:ram="urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100"'
            . ' xmlns:udt="urn:un:unece:uncefact:data:standard:UnqualifiedDataType:100"'
            . ' xmlns:qdt="urn:un:unece:uncefact:data:standard:QualifiedDataType:100">'
            . '<rsm:ExchangedDocumentContext>'
            . '<ram:BusinessProcessSpecifiedDocumentContextParameter><ram:ID>A1</ram:ID></ram:BusinessProcessSpecifiedDocumentContextParameter>'
            . '<ram:GuidelineSpecifiedDocumentContextParameter><ram:ID>urn:factur-x.eu:1p0:minimum</ram:ID></ram:GuidelineSpecifiedDocumentContextParameter>'
            . '</rsm:ExchangedDocumentContext>'
            . '<rsm:ExchangedDocument>'
            . '<ram:ID>' . ak_fx_x($num) . '</ram:ID>'
            . '<ram:TypeCode>380</ram:TypeCode>'
            . '<ram:IssueDateTime><udt:DateTimeString format="102">' . ak_fx_x($issued) . '</udt:DateTimeString></ram:IssueDateTime>'
            . '</rsm:ExchangedDocument>'
            . '<rsm:SupplyChainTradeTransaction>'
            . '<ram:ApplicableHeaderTradeAgreement>'
            . $buyerRefXml
            . '<ram:SellerTradeParty>'
            . '<ram:Name>' . ak_fx_x($sellerName) . '</ram:Name>'
            . $sellerLegal
            . '<ram:PostalTradeAddress><ram:CountryID>' . ak_fx_x($sellerCountry) . '</ram:CountryID></ram:PostalTradeAddress>'
            . $sellerVatXml
            . '</ram:SellerTradeParty>'
            . '<ram:BuyerTradeParty>'
            . '<ram:Name>' . ak_fx_x($buyerName) . '</ram:Name>'
            . $buyerLegal
            . '<ram:PostalTradeAddress><ram:CountryID>' . ak_fx_x($buyerCountry) . '</ram:CountryID></ram:PostalTradeAddress>'
            . '</ram:BuyerTradeParty>'
            . '</ram:ApplicableHeaderTradeAgreement>'
            . '<ram:ApplicableHeaderTradeDelivery/>'
            . '<ram:ApplicableHeaderTradeSettlement>'
            . '<ram:InvoiceCurrencyCode>' . ak_fx_x($curr) . '</ram:InvoiceCurrencyCode>'
            . '<ram:SpecifiedTradeSettlementHeaderMonetarySummation>'
            . '<ram:TaxBasisTotalAmount>' . $ht . '</ram:TaxBasisTotalAmount>'
            . '<ram:TaxTotalAmount currencyID="' . ak_fx_x($curr) . '">' . $vat . '</ram:TaxTotalAmount>'
            . '<ram:GrandTotalAmount>' . $ttc . '</ram:GrandTotalAmount>'
            . '<ram:DuePayableAmount>' . $ttc . '</ram:DuePayableAmount>'
            . '</ram:SpecifiedTradeSettlementHeaderMonetarySummation>'
            . '</ram:ApplicableHeaderTradeSettlement>'
            . '</rsm:SupplyChainTradeTransaction>'
            . '</rsm:CrossIndustryInvoice>';

        return $xml;
    }
}

if (!function_exists('ak_facturx_xmp')) {
    /** Métadonnées XMP RDF Factur-X à injecter dans le PDF/A-3 (déclaration du schéma fx). */
    function ak_facturx_xmp(): string {
        return '<rdf:Description rdf:about="" xmlns:fx="urn:factur-x:pdfa:CrossIndustryDocument:invoice:1p0#">'
            . '<fx:DocumentType>INVOICE</fx:DocumentType>'
            . '<fx:DocumentFileName>factur-x.xml</fx:DocumentFileName>'
            . '<fx:Version>1.0</fx:Version>'
            . '<fx:ConformanceLevel>MINIMUM</fx:ConformanceLevel>'
            . '</rdf:Description>'
            . '<rdf:Description rdf:about="" xmlns:pdfaExtension="http://www.aiim.org/pdfa/ns/extension/" xmlns:pdfaSchema="http://www.aiim.org/pdfa/ns/schema#" xmlns:pdfaProperty="http://www.aiim.org/pdfa/ns/property#">'
            . '<pdfaExtension:schemas><rdf:Bag><rdf:li rdf:parseType="Resource">'
            . '<pdfaSchema:schema>Factur-X PDFA Extension Schema</pdfaSchema:schema>'
            . '<pdfaSchema:namespaceURI>urn:factur-x:pdfa:CrossIndustryDocument:invoice:1p0#</pdfaSchema:namespaceURI>'
            . '<pdfaSchema:prefix>fx</pdfaSchema:prefix>'
            . '<pdfaSchema:property><rdf:Seq>'
            . '<rdf:li rdf:parseType="Resource"><pdfaProperty:name>DocumentFileName</pdfaProperty:name><pdfaProperty:valueType>Text</pdfaProperty:valueType><pdfaProperty:category>external</pdfaProperty:category><pdfaProperty:description>Name of the embedded XML invoice file</pdfaProperty:description></rdf:li>'
            . '<rdf:li rdf:parseType="Resource"><pdfaProperty:name>DocumentType</pdfaProperty:name><pdfaProperty:valueType>Text</pdfaProperty:valueType><pdfaProperty:category>external</pdfaProperty:category><pdfaProperty:description>INVOICE</pdfaProperty:description></rdf:li>'
            . '<rdf:li rdf:parseType="Resource"><pdfaProperty:name>Version</pdfaProperty:name><pdfaProperty:valueType>Text</pdfaProperty:valueType><pdfaProperty:category>external</pdfaProperty:category><pdfaProperty:description>The actual version of the Factur-X data</pdfaProperty:description></rdf:li>'
            . '<rdf:li rdf:parseType="Resource"><pdfaProperty:name>ConformanceLevel</pdfaProperty:name><pdfaProperty:valueType>Text</pdfaProperty:valueType><pdfaProperty:category>external</pdfaProperty:category><pdfaProperty:description>The conformance level of the Factur-X data</pdfaProperty:description></rdf:li>'
            . '</rdf:Seq></pdfaSchema:property>'
            . '</rdf:li></rdf:Bag></pdfaExtension:schemas>'
            . '</rdf:Description>';
    }
}
