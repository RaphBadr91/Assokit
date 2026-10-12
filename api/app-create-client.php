<?php
/**
 * api/app-create-client.php — Ajout d'un client (profil TPE) depuis l'app.
 * Reutilise ak_asso_find_or_create_client() du site (logique testee).
 * Avec client_id : modification de la fiche de ce client (comme « Modifier les
 * coordonnées » de mon-asso-client-detail.php) ; seuls les champs envoyés changent.
 * NE MODIFIE PAS le site.
 */
require __DIR__ . '/_app-write-boot.php';
@require_once __DIR__ . '/../asso-invoice-helpers.php';

// Parité site (mon-asso-clients.php, mon-asso-facture-save.php) : le fichier client suit le
// droit Facturation (admins, fondateur, super-admin et coordinateurs).
@require_once __DIR__ . '/../finance-permissions.php';
@require_once __DIR__ . '/../platform-flags.php';
if (function_exists('ak_platform_flags')) $user = ak_platform_flags($user);
if (!function_exists('user_can_access_billing') || !user_can_access_billing($user)) {
    app_fail(403, 'role', 'Réservé aux administrateurs et coordinateurs.');
}

if (!function_exists('ak_asso_find_or_create_client')) {
    app_fail(500, 'unavailable', 'Fonction indisponible.');
}

// ── Modification d'un client existant (de cette association) ──
$client_id = (int) ($input['client_id'] ?? 0);
if ($client_id > 0) {
    if (!function_exists('ak_asso_client_update')) app_fail(500, 'unavailable', 'Fonction indisponible.');
    $fields = ['client_type', 'display_name', 'legal_name', 'contact_first_name', 'contact_last_name', 'email', 'phone',
               'address_street', 'address_complement', 'address_zip', 'address_city', 'address_country',
               'siren', 'siret', 'vat_number'];
    $data = array_intersect_key($input, array_flip($fields));
    if (array_key_exists('email', $data) && !filter_var(trim((string) $data['email']), FILTER_VALIDATE_EMAIL)) {
        app_fail(422, 'invalid', 'Email client invalide.');
    }
    if (array_key_exists('display_name', $data) && trim((string) $data['display_name']) === '') {
        app_fail(422, 'invalid', 'Le nom du client est obligatoire.');
    }
    try {
        if (!ak_asso_client_update($pdo, $org_id, $client_id, $data)) app_fail(404, 'not_found', 'Client introuvable.');
    } catch (Throwable $e) {
        error_log('[app-create-client update] ' . $e->getMessage());
        app_fail(500, 'server', 'Impossible d\'enregistrer le client.');
    }
    echo json_encode(['ok' => true, 'id' => $client_id, 'message' => 'Client mis à jour.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$email = trim((string) ($input['email'] ?? ''));
$name  = trim((string) ($input['display_name'] ?? ''));
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) app_fail(422, 'invalid', 'Email client invalide.');
if ($name === '') app_fail(422, 'invalid', 'Le nom du client est obligatoire.');

$type = ($input['client_type'] ?? 'company') === 'individual' ? 'individual' : 'company';

try {
    $id = ak_asso_find_or_create_client($pdo, $org_id, [
        'client_type'    => $type,
        'display_name'   => $name,
        'email'          => $email,
        'phone'          => trim((string) ($input['phone'] ?? '')) ?: null,
        'address_street' => trim((string) ($input['address_street'] ?? '')) ?: null,
        'address_zip'    => trim((string) ($input['address_zip'] ?? '')) ?: null,
        'address_city'   => trim((string) ($input['address_city'] ?? '')) ?: null,
        'siren'          => trim((string) ($input['siren'] ?? '')) ?: null,
        'vat_number'     => trim((string) ($input['vat_number'] ?? '')) ?: null,
        'created_by_user_id' => $uid,
    ]);
    echo json_encode(['ok' => true, 'id' => (int) $id, 'message' => 'Client enregistré.'], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[app-create-client] ' . $e->getMessage());
    app_fail(500, 'server', 'Impossible d\'enregistrer le client.');
}
