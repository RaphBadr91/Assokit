<?php
/**
 * mon-asso-ia-recipients-count.php
 * --------------------------------------------------------------
 * Endpoint AJAX — Compte le nombre de destinataires uniques
 * selon les filtres (rôles + projets + emails manuels)
 * --------------------------------------------------------------
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/asso-ai-helpers.php';

header('Content-Type: application/json; charset=utf-8');

require_login();
$user = current_user();
$org_id = (int)($user['org_id'] ?? 0);
if ($org_id <= 0) { echo json_encode(['ok' => false, 'count' => 0]); exit; }

$sources = [
    'roles'       => $_POST['roles']       ?? [],
    'project_ids' => $_POST['project_ids'] ?? [],
    'manual'      => (string)($_POST['manual_emails'] ?? ''),
];

try {
    $list = ak_ai_collect_recipients($pdo, $org_id, $sources);
    echo json_encode(['ok' => true, 'count' => count($list)]);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'count' => 0, 'error' => $e->getMessage()]);
}
