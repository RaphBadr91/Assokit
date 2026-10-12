<?php
/**
 * api/app-org-settings.php — Fiche « Infos de l'organisation » pour l'app.
 * Lecture seule ; l'écriture passe par app-update-org.php.
 * Réservé aux administrateurs, comme l'onglet du site.
 */
require_once __DIR__ . '/_app-read-boot.php';   // compte actif garanti : $user, $uid, $org_id

try {
    $is_admin = (($user['role'] ?? '') === 'admin') || !empty($user['is_super_admin']);

    if (!$is_admin) {
        echo json_encode(['ok' => true, 'allowed' => false,
            'message' => 'Les informations de l’organisation sont réservées aux administrateurs.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $stmt = $pdo->prepare("
        SELECT name, legal_name, legal_form, siren, siret, rna_number,
               billing_address_street, billing_address_complement, billing_address_zip,
               billing_address_city, billing_address_country,
               vat_subject, vat_number, billing_email, billing_phone,
               branding_primary_color, branding_secondary_color
        FROM organizations WHERE id = ? LIMIT 1
    ");
    $stmt->execute([$org_id]);
    $o = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    echo json_encode([
        'ok' => true,
        'allowed' => true,
        'org' => [
            'org_name'   => (string) ($o['name'] ?? ''),
            'legal_name' => (string) ($o['legal_name'] ?? ''),
            'legal_form' => (string) ($o['legal_form'] ?? ''),
            'siren'      => (string) ($o['siren'] ?? ''),
            'siret'      => (string) ($o['siret'] ?? ''),
            'rna_number' => (string) ($o['rna_number'] ?? ''),
            'billing_address_street'     => (string) ($o['billing_address_street'] ?? ''),
            'billing_address_complement' => (string) ($o['billing_address_complement'] ?? ''),
            'billing_address_zip'        => (string) ($o['billing_address_zip'] ?? ''),
            'billing_address_city'       => (string) ($o['billing_address_city'] ?? ''),
            'billing_address_country'    => (string) ($o['billing_address_country'] ?? ''),
            'vat_subject' => !empty($o['vat_subject']),
            'vat_number'  => (string) ($o['vat_number'] ?? ''),
            'billing_email' => (string) ($o['billing_email'] ?? ''),
            'billing_phone' => (string) ($o['billing_phone'] ?? ''),
            'branding_primary_color'   => (string) ($o['branding_primary_color'] ?? '#10B981'),
            'branding_secondary_color' => (string) ($o['branding_secondary_color'] ?? '#6366F1'),
        ],
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[api/app-org-settings] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'server']);
}
