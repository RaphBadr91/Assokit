<?php
/**
 * mon-asso-ia-recipients-count.php
 * --------------------------------------------------------------
 * Endpoint AJAX — Compte le nombre de destinataires uniques
 * selon les filtres (rôles + projets + emails manuels)
 * --------------------------------------------------------------
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/app-context.php';   // ak_session_guard() : compte désactivé, association résiliée ou suspendue, mot de passe provisoire
ak_session_guard();
require_once __DIR__ . '/asso-ai-helpers.php';
require_once __DIR__ . '/resend-helper.php';   // désinscription des envois groupés (ak_optout_*)

header('Content-Type: application/json; charset=utf-8');

require_login();
$user = current_user();
$org_id = (int)($user['org_id'] ?? 0);
if ($org_id <= 0) { echo json_encode(['ok' => false, 'count' => 0]); exit; }
// Même règle que la page Diffusion et l'envoi : réservé aux admins et coordinateurs
// (le compte révèle l'effectif par rôle et les désinscriptions d'une adresse donnée)
if (!ak_ai_can_manage_all($user)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'count' => 0]);
    exit;
}

$sources = [
    'roles'       => $_POST['roles']       ?? [],
    'project_ids' => $_POST['project_ids'] ?? [],
    'manual'      => (string)($_POST['manual_emails'] ?? ''),
];

try {
    $list = ak_ai_collect_recipients($pdo, $org_id, $sources);
    // Même filtre qu'à l'envoi : les désinscrits des envois groupés ne sont pas comptés
    $optouts = ak_optout_emails($pdo, $org_id);
    $kept = array_filter($list, fn($r) => !isset($optouts[strtolower(trim((string)$r['email']))]));
    echo json_encode(['ok' => true, 'count' => count($kept), 'optout' => count($list) - count($kept)]);
} catch (Throwable $e) {
    error_log('[mon-asso-ia-recipients-count] ' . $e->getMessage());
    echo json_encode(['ok' => false, 'count' => 0, 'error' => 'server']);
}
