<?php
/**
 * ============================================================
 * ASSOKIT — company-helpers.php
 * Helper universel pour accéder aux paramètres de la société
 * ============================================================
 *
 * Usage :
 *   $name   = ak_company('legal_name');   // Raison sociale
 *   $siren  = ak_company('siren');        // SIREN
 *   $all    = ak_company();               // Tableau complet
 *
 * Tous les champs peuvent être NULL. Utilise ak_company_or() pour
 * avoir un fallback propre :
 *   echo ak_company_or('legal_name', 'Assokit');
 *
 * Performance : un seul SELECT par requête HTTP (cache mémoire).
 * ============================================================
 */

if (!function_exists('ak_company')) {

/**
 * Retourne une valeur ou l'ensemble des paramètres société.
 *
 * @param string|null $key  Clé à récupérer ou null pour tout avoir
 * @return mixed            La valeur demandée, ou null si inexistant
 */
function ak_company(?string $key = null)
{
    static $cache = null;

    if ($cache === null) {
        global $pdo;
        $cache = [];
        try {
            if (isset($pdo) && $pdo instanceof PDO) {
                $stmt = $pdo->query("SELECT * FROM company_settings WHERE id = 1 LIMIT 1");
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($row) {
                    $cache = $row;
                }
            }
        } catch (Throwable $e) {
            error_log('[ak_company] Impossible de charger company_settings : ' . $e->getMessage());
            $cache = [];
        }
    }

    if ($key === null) {
        return $cache;
    }
    return $cache[$key] ?? null;
}

/**
 * Retourne une valeur avec fallback si NULL ou vide.
 *
 * @param string $key       Clé à récupérer
 * @param mixed $default    Valeur par défaut
 * @return mixed
 */
function ak_company_or(string $key, $default = '')
{
    $val = ak_company($key);
    return (empty($val) && $val !== '0' && $val !== 0) ? $default : $val;
}

/**
 * Retourne true si la société a des infos de base configurées.
 * Utile pour savoir s'il faut afficher un onboarding.
 */
function ak_company_is_configured(): bool
{
    $name = ak_company('legal_name');
    return !empty($name);
}

/**
 * Retourne l'adresse formatée sur plusieurs lignes (pour factures).
 */
function ak_company_address_multiline(): string
{
    $parts = [];
    $street = ak_company('address_street');
    $compl  = ak_company('address_complement');
    $zip    = ak_company('address_zip');
    $city   = ak_company('address_city');
    $country = ak_company('address_country');

    if (!empty($street)) $parts[] = $street;
    if (!empty($compl))  $parts[] = $compl;
    $line3 = trim(($zip ?? '') . ' ' . ($city ?? ''));
    if (!empty($line3))  $parts[] = $line3;
    if (!empty($country) && $country !== 'FR') $parts[] = $country;

    return implode("\n", $parts);
}

/**
 * Retourne le bloc de mentions légales obligatoires pour une facture.
 * Exemple :
 *   Latitude91 SAS au capital de 1 000€
 *   RCS Paris 123 456 789
 *   SIREN 123 456 789
 *   TVA FR XX XXXXXXXXX
 */
function ak_company_legal_mentions(): string
{
    $lines = [];

    $name = ak_company('legal_name');
    $form = ak_company('legal_form');
    $capital = ak_company('capital_cents');

    if (!empty($name)) {
        $line1 = $name;
        if (!empty($form)) $line1 .= ' — ' . $form;
        if (!empty($capital)) {
            $line1 .= ' au capital de ' . number_format($capital / 100, 0, ',', ' ') . ' €';
        }
        $lines[] = $line1;
    }

    $rcsCity = ak_company('rcs_city');
    $rcsNum  = ak_company('rcs_number');
    if (!empty($rcsCity) && !empty($rcsNum)) {
        $lines[] = 'RCS ' . $rcsCity . ' ' . $rcsNum;
    }

    $siren = ak_company('siren');
    if (!empty($siren)) {
        $lines[] = 'SIREN ' . $siren;
    }

    if ((int)ak_company('vat_subject') === 1) {
        $vatNum = ak_company('vat_number');
        if (!empty($vatNum)) {
            $lines[] = 'N° TVA intracommunautaire : ' . $vatNum;
        }
    } else {
        $lines[] = 'TVA non applicable — art. 293 B du CGI';
    }

    return implode("\n", $lines);
}

/**
 * Retourne l'email expéditeur par défaut pour tous les envois système.
 * Utilise email_billing > email_support > fallback.
 */
function ak_company_from_email(?string $fallback = null): string
{
    $email = ak_company('email_billing')
          ?? ak_company('email_support')
          ?? $fallback
          ?? (defined('RESEND_FROM') ? RESEND_FROM : 'noreply@assokit.fr');
    return $email;
}

/**
 * Retourne le footer HTML à ajouter dans les emails.
 */
function ak_company_email_footer_html(): string
{
    $name    = ak_company('legal_name');
    $siren   = ak_company('siren');
    $support = ak_company('email_support');
    $website = ak_company('website');

    if (empty($name)) return '';

    $html = '<div style="margin-top:24px; padding-top:16px; border-top:1px solid #E5E7EB; font-size:11px; color:#6B7280; line-height:1.6;">';
    $html .= htmlspecialchars($name);
    if (!empty($siren)) $html .= ' · SIREN ' . htmlspecialchars($siren);
    $html .= '<br>';
    if (!empty($support)) $html .= '<a href="mailto:' . htmlspecialchars($support) . '" style="color:#6B7280;">' . htmlspecialchars($support) . '</a>';
    if (!empty($website)) $html .= ' · <a href="' . htmlspecialchars($website) . '" style="color:#6B7280;">' . htmlspecialchars(preg_replace('#^https?://#', '', $website)) . '</a>';
    $html .= '</div>';

    return $html;
}

} // fin if (!function_exists('ak_company'))
