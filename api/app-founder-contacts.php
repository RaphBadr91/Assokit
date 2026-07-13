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
if (!app_is_founder($pdo, $user) && !( !empty($user['is_super_admin']) || ($user['role'] ?? '') === 'super_admin')) {
    http_response_code(403); echo json_encode(['ok' => false, 'error' => 'forbidden']); exit;
}

// Détecte les colonnes de statut disponibles (défensif)
$hasStatus = false;
try { $pdo->query("SELECT status FROM asso_contact_messages LIMIT 1"); $hasStatus = true; } catch (Throwable $e) {}

try {
    $rows = [];
    try {
        $rows = $pdo->query("
            SELECT id, firstname, lastname, email, organization, type, subject, message, created_at" . ($hasStatus ? ", status" : "") . "
            FROM asso_contact_messages ORDER BY created_at DESC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {}

    $contacts = []; $unread = 0;
    foreach ($rows as $r) {
        $st = $hasStatus ? (string) ($r['status'] ?? 'new') : 'new';
        $is_new = !in_array($st, ['read', 'replied', 'closed', 'archived'], true);
        if ($is_new) $unread++;
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
            'replied'   => ($st === 'replied'),
        ];
    }

    echo json_encode(['ok' => true, 'unread' => $unread, 'contacts' => $contacts], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[app-founder-contacts] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'server']);
}
