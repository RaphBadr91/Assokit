<?php
/**
 * mon-asso-ia-action.php
 * --------------------------------------------------------------
 * Endpoint POST — toggle_fav / delete une génération
 * --------------------------------------------------------------
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/asso-ai-helpers.php';

require_login();
$user = current_user();
$org_id = (int)($user['org_id'] ?? 0);
if ($org_id <= 0) { header('Location: /'); exit; }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /mon-asso-ia-historique');
    exit;
}

if (!check_csrf($_POST['csrf_token'] ?? '')) {
    header('Location: /mon-asso-ia-historique?error=csrf');
    exit;
}

$action = (string)($_POST['action'] ?? '');
$id     = (int)($_POST['id'] ?? 0);

if ($id <= 0) { header('Location: /mon-asso-ia-historique'); exit; }

// Vérifier appartenance
$gen = ak_ai_load_generation($pdo, $id, $org_id);
if (!$gen) { header('Location: /mon-asso-ia-historique'); exit; }

try {
    if ($action === 'toggle_fav') {
        $new = ((int)$gen['is_favorite'] === 1) ? 0 : 1;
        $pdo->prepare("UPDATE asso_ai_generations SET is_favorite = :f WHERE id = :id AND org_id = :o")
            ->execute([':f' => $new, ':id' => $id, ':o' => $org_id]);
    } elseif ($action === 'delete') {
        $pdo->prepare("DELETE FROM asso_ai_generations WHERE id = :id AND org_id = :o")
            ->execute([':id' => $id, ':o' => $org_id]);
    }
} catch (Throwable $e) {
    error_log('[mon-asso-ia-action] ' . $e->getMessage());
}

header('Location: /mon-asso-ia-historique');
exit;
