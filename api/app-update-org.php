<?php
/**
 * api/app-update-org.php — Enregistre les infos de l'organisation depuis l'app.
 *
 * Parité avec action-parametres.php (action `update_organization`) : même
 * réservation aux administrateurs, mêmes champs, mêmes défauts de couleur.
 * Le logo garde son propre point d'entrée (app-upload-logo.php) car il est
 * envoyé en multipart ; il n'est donc pas touché ici.
 */
require_once __DIR__ . '/_app-write-boot.php';

$role = (string) ($user['role'] ?? 'member');
if ($role !== 'admin' && empty($user['is_super_admin'])) {
    app_fail(403, 'role', 'Réservé aux administrateurs.');
}

$s = static function ($key) use ($input) {
    $v = trim((string) ($input[$key] ?? ''));
    return $v === '' ? null : mb_substr($v, 0, 190);
};

$org_name = trim((string) ($input['org_name'] ?? ''));
if ($org_name === '') app_fail(422, 'invalid', 'Le nom de l’organisation est obligatoire.');
$org_name = mb_substr($org_name, 0, 190);

// Hors format hexadécimal, on retombe sur les couleurs par défaut du site.
$color_pri = trim((string) ($input['branding_primary_color'] ?? '#10B981'));
$color_sec = trim((string) ($input['branding_secondary_color'] ?? '#6366F1'));
if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $color_pri)) $color_pri = '#10B981';
if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $color_sec)) $color_sec = '#6366F1';

try {
    $pdo->prepare("
        UPDATE organizations SET
            name = ?, legal_name = ?, legal_form = ?, siren = ?, siret = ?, rna_number = ?,
            billing_address_street = ?, billing_address_complement = ?, billing_address_zip = ?,
            billing_address_city = ?, billing_address_country = ?,
            vat_subject = ?, vat_number = ?, billing_email = ?, billing_phone = ?,
            branding_primary_color = ?, branding_secondary_color = ?, billing_updated_at = NOW()
        WHERE id = ?
    ")->execute([
        $org_name, $s('legal_name'), $s('legal_form'), $s('siren'), $s('siret'), $s('rna_number'),
        $s('billing_address_street'), $s('billing_address_complement'), $s('billing_address_zip'),
        $s('billing_address_city'), $s('billing_address_country'),
        !empty($input['vat_subject']) ? 1 : 0, $s('vat_number'), $s('billing_email'), $s('billing_phone'),
        $color_pri, $color_sec, $org_id,
    ]);
} catch (Throwable $e) {
    error_log('[app-update-org] ' . $e->getMessage());
    app_fail(500, 'server', 'Enregistrement impossible.');
}

echo json_encode(['ok' => true, 'message' => 'Organisation mise à jour.'], JSON_UNESCAPED_UNICODE);
