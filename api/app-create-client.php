<?php
/**
 * api/app-create-client.php — Ajout d'un client (profil TPE) depuis l'app.
 * Reutilise ak_asso_find_or_create_client() du site (logique testee).
 * NE MODIFIE PAS le site.
 */
require __DIR__ . '/_app-write-boot.php';
@require_once __DIR__ . '/../asso-invoice-helpers.php';

// Parité site (mon-asso-clients.php) : le fichier client est réservé aux administrateurs.
if (!in_array($user['role'] ?? '', ['admin', 'super_admin'], true) && empty($user['is_founder']) && empty($user['is_super_admin'])) {
    app_fail(403, 'role', 'Réservé aux administrateurs.');
}

if (!function_exists('ak_asso_find_or_create_client')) {
    app_fail(500, 'unavailable', 'Fonction indisponible.');
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
