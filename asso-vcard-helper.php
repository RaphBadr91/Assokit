<?php
/**
 * asso-vcard-helper.php — Fiche vCard d'une association.
 *
 * Un seul générateur pour deux usages qui doivent produire exactement la même
 * carte : le fichier .vcf servi par /qr/<token>.vcf, et le QR « carte de
 * visite » qui embarque la vCard dans son propre contenu. Deux implémentations
 * auraient fini par diverger, et personne ne s'en serait aperçu avant qu'un
 * visiteur enregistre un numéro périmé.
 */

declare(strict_types=1);

if (!function_exists('ak_vcard_escape')) {
    /** Échappe une valeur vCard. L'ordre compte : l'antislash d'abord. */
    function ak_vcard_escape(string $v): string
    {
        return str_replace(
            ["\\", ";", ",", "\r\n", "\n", "\r"],
            ["\\\\", "\\;", "\\,", "\\n", "\\n", "\\n"],
            $v
        );
    }
}

if (!function_exists('ak_org_vcard')) {
    /**
     * @param array $org  Ligne organizations : name, billing_email, billing_phone,
     *                    billing_address_street / _zip / _city / _country.
     * @param string $url Site à joindre à la fiche (facultatif).
     * @return string     vCard 3.0, lignes terminées en CRLF.
     */
    function ak_org_vcard(array $org, string $url = ''): string
    {
        $name  = trim((string) ($org['name'] ?? '')) ?: 'Association';
        $rue   = trim((string) ($org['billing_address_street'] ?? ''));
        $cp    = trim((string) ($org['billing_address_zip'] ?? ''));
        $ville = trim((string) ($org['billing_address_city'] ?? ''));
        $pays  = trim((string) ($org['billing_address_country'] ?? ''));

        $l = ['BEGIN:VCARD', 'VERSION:3.0'];
        // N pour une organisation : le nom en famille, le reste vide. Sans N,
        // certains Android refusent la fiche.
        $l[] = 'N:' . ak_vcard_escape($name) . ';;;;';
        $l[] = 'FN:' . ak_vcard_escape($name);
        $l[] = 'ORG:' . ak_vcard_escape($name);
        if (!empty($org['billing_phone'])) $l[] = 'TEL;TYPE=WORK,VOICE:' . ak_vcard_escape((string) $org['billing_phone']);
        if (!empty($org['billing_email'])) $l[] = 'EMAIL;TYPE=INTERNET,PREF:' . ak_vcard_escape((string) $org['billing_email']);
        if ($rue !== '' || $ville !== '') {
            // ADR : boîte;complément;rue;ville;région;code postal;pays
            $l[] = 'ADR;TYPE=WORK:;;' . ak_vcard_escape($rue) . ';' . ak_vcard_escape($ville)
                 . ';;' . ak_vcard_escape($cp) . ';' . ak_vcard_escape($pays);
        }
        if ($url !== '') $l[] = 'URL:' . ak_vcard_escape($url);
        $l[] = 'END:VCARD';

        // CRLF : imposé par la RFC 6350, et certains Android rejettent
        // silencieusement un fichier en LF seul.
        return implode("\r\n", $l) . "\r\n";
    }
}

if (!function_exists('ak_vcard_filename')) {
    /** Nom de fichier ASCII : « Les Amis du Parc » → « Les-Amis-du-Parc ». */
    function ak_vcard_filename(string $name): string
    {
        $s = $name !== '' ? $name : 'contact';
        if (function_exists('iconv')) {
            $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
            if ($t !== false && $t !== '') $s = $t;
        }
        return trim((string) preg_replace('/[^A-Za-z0-9._-]+/', '-', $s), '-') ?: 'contact';
    }
}
