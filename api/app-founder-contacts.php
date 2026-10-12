<?php
/**
 * api/app-founder-contacts.php — Demandes de contact du site (Fondateur, natif).
 * Les messages du formulaire de contact (asso_contact_messages) dans l'app.
 * NE MODIFIE PAS le site : dédié à l'application.
 */
ob_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes-layout.php';
ob_end_clean();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (empty($_SESSION['user_id'])) { http_response_code(401); echo json_encode(['ok' => false, 'error' => 'auth']); exit; }
$user = function_exists('current_user') ? current_user() : null;
require_once __DIR__ . '/_app-founder.php';
// Compte désactivé ou supprimé depuis l'ouverture de la session : session fermée, 401
if (!app_session_account_alive($pdo)) { http_response_code(401); echo json_encode(['ok' => false, 'error' => 'auth']); exit; }
if (!app_is_founder($pdo, $user) && !( !empty($user['is_super_admin']) || ($user['role'] ?? '') === 'super_admin')) {
    http_response_code(403); echo json_encode(['ok' => false, 'error' => 'forbidden']); exit;
}

require_once __DIR__ . '/_app-contact-token.php';

try {
    // État « non lue » / « répondue » : même calcul que le compteur du cockpit (app-founder.php),
    // avec ou sans la colonne status (ak_contact_state_sql)
    $unread = ak_contact_unread_count($pdo);   // toutes les demandes, pas seulement les 100 listées
    $s = ak_contact_state_sql($pdo, 'm');
    $rows = [];
    try {
        $rows = $pdo->query("
            SELECT m.id, m.firstname, m.lastname, m.email, m.organization, m.type, m.subject, m.message, m.created_at,
                   {$s['unread']} AS is_new, {$s['replied']} AS is_replied
            FROM asso_contact_messages m ORDER BY m.created_at DESC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        error_log('[app-founder-contacts] ' . $e->getMessage());
    }

    $contacts = [];
    foreach ($rows as $r) {
        $is_new = !empty($r['is_new']);
        $name = trim(($r['firstname'] ?? '') . ' ' . ($r['lastname'] ?? ''));
        $contacts[] = [
            'id'        => (int) $r['id'],
            'name'      => $name !== '' ? $name : (string) $r['email'],
            'email'     => (string) ($r['email'] ?? ''),
            'org'       => (string) ($r['organization'] ?? ''),
            'type'      => (string) ($r['type'] ?? ''),
            'subject'   => (string) ($r['subject'] ?? ''),
            'message'   => (string) ($r['message'] ?? ''),
            'date'      => !empty($r['created_at']) ? date('d/m/Y H:i', strtotime($r['created_at'])) : '',
            'is_new'    => $is_new,
            'replied'   => !empty($r['is_replied']),
        ];
    }

    echo json_encode(['ok' => true, 'unread' => $unread, 'contacts' => $contacts], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[app-founder-contacts] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'server']);
}
