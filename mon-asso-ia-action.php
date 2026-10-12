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
if (!ak_ai_user_has_access($user)) { header('Location: /projets'); exit; }

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

// Vérifier appartenance : à l'association, et à l'auteur sauf pour
// admin / coordinateur (un membre ne touche pas aux générations des autres)
$gen = ak_ai_load_generation($pdo, $id, $org_id, $user);
if (!$gen) { header('Location: /mon-asso-ia-historique'); exit; }

// Même règle dans la requête elle-même
$own = ak_ai_can_manage_all($user) ? '' : ' AND user_id = :u';
$own_param = $own ? [':u' => (int)$user['id']] : [];

try {
    if ($action === 'toggle_fav') {
        $new = ((int)$gen['is_favorite'] === 1) ? 0 : 1;
        $pdo->prepare("UPDATE asso_ai_generations SET is_favorite = :f WHERE id = :id AND org_id = :o" . $own)
            ->execute([':f' => $new, ':id' => $id, ':o' => $org_id] + $own_param);
    } elseif ($action === 'delete') {
        // Suppression « douce » : le texte et la demande sont effacés et la génération disparaît de
        // l'historique, mais la ligne reste (statut « deleted ») et compte toujours dans les quotas du
        // mois et du jour. Supprimer une génération ne rend donc pas de crédit IA.
        require_once __DIR__ . '/trial-helpers.php';
        if (ak_db_accepts($pdo, 'asso_ai_generations', 'status', 'deleted')) {
            $pdo->prepare("UPDATE asso_ai_generations SET status = 'deleted', output_text = '', input_data = '', is_favorite = 0
                           WHERE id = :id AND org_id = :o" . $own)
                ->execute([':id' => $id, ':o' => $org_id] + $own_param);
        } else {
            // Statut en ENUM sans « deleted » (ancien schéma) : suppression définitive, comme avant
            $pdo->prepare("DELETE FROM asso_ai_generations WHERE id = :id AND org_id = :o" . $own)
                ->execute([':id' => $id, ':o' => $org_id] + $own_param);
        }
    }
} catch (Throwable $e) {
    error_log('[mon-asso-ia-action] ' . $e->getMessage());
}

header('Location: /mon-asso-ia-historique');
exit;
