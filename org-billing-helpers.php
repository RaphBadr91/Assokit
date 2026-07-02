<?php
/**
 * ============================================================
 * ASSOKIT — org-billing-helpers.php
 * Helpers pour les infos de facturation des associations
 * ============================================================
 *
 * Usage :
 *   $info = get_org_billing_info($pdo, $org_id);
 *   echo $info['legal_name'];
 *   echo $info['billing_email_effective'];  // avec fallback admin
 *   echo $info['completeness_percent'];
 *
 *   if (validate_siren($siren)) { ... }
 *
 * ============================================================
 */

if (!function_exists('get_org_billing_info')) {

/**
 * Retourne toutes les infos de facturation d'une asso.
 * Inclut un fallback intelligent sur l'email de l'admin principal
 * si billing_email est vide.
 */
function get_org_billing_info(PDO $pdo, int $org_id): ?array
{
    $stmt = $pdo->prepare("
        SELECT
            o.id, o.name, o.status, o.plan,
            o.legal_name, o.legal_form, o.siren, o.siret, o.rna_number,
            o.vat_subject, o.vat_number,
            o.billing_address_street, o.billing_address_complement,
            o.billing_address_zip, o.billing_address_city, o.billing_address_country,
            o.billing_email, o.billing_phone,
            o.president_first_name, o.president_last_name, o.president_role,
            o.external_ref, o.internal_notes,
            o.billing_updated_at, o.billing_updated_by_user_id
        FROM organizations o
        WHERE o.id = :id
        LIMIT 1
    ");
    $stmt->execute([':id' => $org_id]);
    $info = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$info) return null;

    // ----- Fallback email : si billing_email vide, prendre l'email de l'admin principal
    $info['billing_email_effective'] = $info['billing_email'];
    if (empty($info['billing_email_effective'])) {
        try {
            $stmt_admin = $pdo->prepare("
                SELECT email
                FROM users
                WHERE org_id = :oid AND is_active = 1 AND deleted_at IS NULL
                  AND role IN ('admin', 'super_admin')
                ORDER BY CASE WHEN role = 'admin' THEN 0 ELSE 1 END, id ASC
                LIMIT 1
            ");
            $stmt_admin->execute([':oid' => $org_id]);
            $info['billing_email_effective'] = (string) $stmt_admin->fetchColumn();
            $info['billing_email_is_fallback'] = true;
        } catch (Throwable $e) {
            $info['billing_email_is_fallback'] = false;
        }
    } else {
        $info['billing_email_is_fallback'] = false;
    }

    // ----- Fallback nom juridique
    $info['legal_name_effective'] = !empty($info['legal_name']) ? $info['legal_name'] : $info['name'];

    // ----- Calcul de la complétude (% champs remplis)
    $checked_fields = [
        'legal_name', 'legal_form', 'siren', 'billing_email',
        'billing_address_street', 'billing_address_zip', 'billing_address_city',
        'president_first_name', 'president_last_name',
    ];
    $filled = 0;
    foreach ($checked_fields as $f) {
        if (!empty($info[$f])) $filled++;
    }
    $info['completeness_filled'] = $filled;
    $info['completeness_total']  = count($checked_fields);
    $info['completeness_percent'] = (int) round(($filled / count($checked_fields)) * 100);

    // ----- Info "qui a mis à jour"
    $info['billing_updated_by_name'] = null;
    if (!empty($info['billing_updated_by_user_id'])) {
        try {
            $stmt_u = $pdo->prepare("SELECT first_name, last_name FROM users WHERE id = :id");
            $stmt_u->execute([':id' => (int)$info['billing_updated_by_user_id']]);
            $u = $stmt_u->fetch();
            if ($u) {
                $info['billing_updated_by_name'] = trim($u['first_name'] . ' ' . $u['last_name']);
            }
        } catch (Throwable $e) {}
    }

    return $info;
}

/**
 * Retourne l'adresse de facturation formatée multiligne.
 */
function format_org_billing_address(array $info): string
{
    $parts = [];
    if (!empty($info['billing_address_street'])) $parts[] = $info['billing_address_street'];
    if (!empty($info['billing_address_complement'])) $parts[] = $info['billing_address_complement'];
    $line = trim(($info['billing_address_zip'] ?? '') . ' ' . ($info['billing_address_city'] ?? ''));
    if (!empty($line)) $parts[] = $line;
    if (!empty($info['billing_address_country']) && $info['billing_address_country'] !== 'FR') {
        $parts[] = $info['billing_address_country'];
    }
    return implode("\n", $parts);
}

/**
 * Validation d'un SIREN français par algorithme de Luhn.
 */
function validate_siren(string $siren): bool
{
    $siren = preg_replace('/\s+/', '', $siren);
    if (!preg_match('/^\d{9}$/', $siren)) return false;

    $sum = 0;
    for ($i = 0; $i < 9; $i++) {
        $digit = (int) $siren[$i];
        if ($i % 2 === 1) { // positions paires (index 1,3,5,7) multipliées par 2
            $digit *= 2;
            if ($digit > 9) $digit -= 9;
        }
        $sum += $digit;
    }
    return ($sum % 10) === 0;
}

/**
 * Validation d'un SIRET (14 chiffres, SIREN + 5 chiffres d'établissement).
 */
function validate_siret(string $siret): bool
{
    $siret = preg_replace('/\s+/', '', $siret);
    if (!preg_match('/^\d{14}$/', $siret)) return false;

    $sum = 0;
    for ($i = 0; $i < 14; $i++) {
        $digit = (int) $siret[$i];
        if ($i % 2 === 0) { // positions impaires (index 0,2,...) multipliées par 2
            $digit *= 2;
            if ($digit > 9) $digit -= 9;
        }
        $sum += $digit;
    }
    return ($sum % 10) === 0;
}

/**
 * Normalise un SIREN/SIRET (supprime les espaces, garde juste les chiffres).
 */
function normalize_siren(string $val): string
{
    return preg_replace('/\s+/', '', $val);
}

/**
 * Formate un SIREN pour affichage : "123 456 789"
 */
function format_siren(string $siren): string
{
    $siren = normalize_siren($siren);
    if (strlen($siren) !== 9) return $siren;
    return substr($siren, 0, 3) . ' ' . substr($siren, 3, 3) . ' ' . substr($siren, 6, 3);
}

/**
 * Formate un SIRET pour affichage : "123 456 789 00012"
 */
function format_siret(string $siret): string
{
    $siret = normalize_siren($siret);
    if (strlen($siret) !== 14) return $siret;
    return substr($siret, 0, 3) . ' ' . substr($siret, 3, 3) . ' ' . substr($siret, 6, 3) . ' ' . substr($siret, 9, 5);
}

/**
 * Vérifie si un user a le droit d'éditer les infos de facturation d'une asso.
 * - Fondateur : accès à toutes les assos
 * - Admin de l'asso : accès à la sienne uniquement
 */
function can_edit_org_billing(PDO $pdo, int $user_id, int $org_id): bool
{
    try {
        $stmt = $pdo->prepare("
            SELECT is_founder, role, org_id
            FROM users
            WHERE id = :uid AND is_active = 1 AND deleted_at IS NULL
            LIMIT 1
        ");
        $stmt->execute([':uid' => $user_id]);
        $u = $stmt->fetch();
        if (!$u) return false;

        // Fondateur : tout autorisé
        if ((int)$u['is_founder'] === 1) return true;

        // Admin de l'asso : uniquement sa propre asso
        if ($u['role'] === 'admin' && (int)$u['org_id'] === $org_id) return true;

        return false;
    } catch (Throwable $e) {
        return false;
    }
}

} // fin if (!function_exists('get_org_billing_info'))
