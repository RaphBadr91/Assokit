<?php
/**
 * Endpoint POST : paramètres mairie (profil, agents, mot de passe)
 * URL : /action-mairie-settings
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/activity-tracker.php';  // journal fondateur : qui a fait quoi, à la minute près
activity_log_request();
require_once __DIR__ . '/includes-layout.php';
require_once __DIR__ . '/includes-permissions.php';
require_once __DIR__ . '/includes-mairie-channels.php';
if (function_exists('ak_session_guard')) ak_session_guard(); // agent retiré depuis sa connexion : session fermée tout de suite
require_login();
require_parent_org_user();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /mairie-parametres'); exit;
}
if (!check_csrf($_POST['csrf_token'] ?? '')) {
    http_response_code(403); die('CSRF token invalide');
}

$current = current_user();
$parent_org = current_parent_org();
if (!$parent_org) {
    // Compte plateforme sans collectivité : la gestion se fait depuis le cockpit
    header('Location: ' . (is_platform_admin() ? '/super-admin-mairies' : '/dashboard'));
    exit;
}
$parent_org_id = (int)$parent_org['id'];
$user_id = (int)$current['id'];
// Rôle relu en base (current_user() ne charge pas forcément parent_org_role)
$is_mairie_admin = ak_mairie_user_role($pdo, $current) === 'admin' || is_platform_admin($current);
$action = $_POST['action'] ?? '';

try {
    switch ($action) {

        // === Mettre à jour le profil de la collectivité ===
        case 'update_profile':
            if (!$is_mairie_admin) throw new RuntimeException("Accès réservé aux administrateurs.");

            $name = trim($_POST['name'] ?? '');
            if ($name === '') throw new RuntimeException("Le nom est obligatoire.");

            $type = in_array($_POST['type'] ?? '', array_keys(ak_parent_org_types()), true) ? $_POST['type'] : 'mairie';

            $pdo->prepare("
                UPDATE parent_orgs SET
                    name = ?, type = ?, siret = ?,
                    contact_first_name = ?, contact_last_name = ?, contact_email = ?, contact_phone = ?,
                    address_street = ?, address_zip = ?, address_city = ?, department = ?, region = ?,
                    updated_at = NOW()
                WHERE id = ?
            ")->execute([
                mb_substr($name, 0, 255), $type, mb_substr(trim($_POST['siret'] ?? ''), 0, 20),
                mb_substr(trim($_POST['contact_first_name'] ?? ''), 0, 100),
                mb_substr(trim($_POST['contact_last_name'] ?? ''), 0, 100),
                mb_substr(trim($_POST['contact_email'] ?? ''), 0, 255),
                mb_substr(trim($_POST['contact_phone'] ?? ''), 0, 30),
                mb_substr(trim($_POST['address_street'] ?? ''), 0, 255),
                mb_substr(trim($_POST['address_zip'] ?? ''), 0, 10),
                mb_substr(trim($_POST['address_city'] ?? ''), 0, 100),
                mb_substr(trim($_POST['department'] ?? ''), 0, 100),
                mb_substr(trim($_POST['region'] ?? ''), 0, 100),
                $parent_org_id
            ]);
            $_SESSION['flash_success'] = "Informations de la collectivité mises à jour.";
            break;

        // === Ajouter un agent ===
        case 'add_agent':
            if (!$is_mairie_admin) throw new RuntimeException("Accès réservé aux administrateurs.");

            $fn = trim($_POST['first_name'] ?? '');
            $ln = trim($_POST['last_name'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $role = in_array($_POST['parent_org_role'] ?? '', ['admin','agent'], true) ? $_POST['parent_org_role'] : 'agent';

            // Email validé, mot de passe temporaire à changer à la 1re connexion, invitation par e-mail,
            // ajout aux channels mairie existants ; un ancien agent retiré est réactivé
            $agent = ak_mairie_agent_create($pdo, $parent_org_id, $fn, $ln, $email, $role, $user_id);

            $_SESSION['flash_success'] = "Agent « $fn $ln » " . ($agent['reactivated'] ? 'réactivé' : 'ajouté') . ". "
                . ($agent['invite_sent']
                    ? "Une invitation lui a été envoyée par email pour créer son mot de passe. Mot de passe temporaire (à changer à la première connexion) : {$agent['temp_password']}"
                    : "L'email d'invitation n'a pas pu être envoyé : mot de passe temporaire à transmettre (à changer à la première connexion) : {$agent['temp_password']}");
            break;

        // === Retirer un agent ===
        case 'remove_agent':
            if (!$is_mairie_admin) throw new RuntimeException("Accès réservé aux administrateurs.");

            $agent_id = (int)($_POST['agent_id'] ?? 0);
            if ($agent_id <= 0) throw new RuntimeException("Agent invalide.");
            if ($agent_id === $user_id) throw new RuntimeException("Vous ne pouvez pas vous retirer vous-même.");

            // L'agent doit appartenir à cette mairie : compte désactivé (plus de connexion),
            // détaché de la mairie et sorti des channels ; il pourra être ré-invité
            $agent = ak_mairie_agent_remove($pdo, $agent_id, $parent_org_id, $user_id);
            if (!$agent) throw new RuntimeException("Agent introuvable dans votre collectivité.");

            $_SESSION['flash_success'] = "Agent « {$agent['first_name']} {$agent['last_name']} » retiré.";
            break;

        // === Changer son mot de passe ===
        case 'change_password':
            $cur = $_POST['current_password'] ?? '';
            $new = $_POST['new_password'] ?? '';
            $conf = $_POST['confirm_password'] ?? '';

            if ($new !== $conf) throw new RuntimeException("Les deux nouveaux mots de passe ne correspondent pas.");
            if (mb_strlen($new) < 8) throw new RuntimeException("Le nouveau mot de passe doit faire au moins 8 caractères.");

            // Vérifier le mot de passe actuel
            $stmt = $pdo->prepare("SELECT password_hash FROM users WHERE id = ?");
            $stmt->execute([$user_id]);
            $hash = $stmt->fetchColumn();
            if (!$hash || !password_verify($cur, $hash)) throw new RuntimeException("Mot de passe actuel incorrect.");

            $pdo->prepare("UPDATE users SET password_hash = ?, must_change_password = 0 WHERE id = ?")
                ->execute([password_hash($new, PASSWORD_DEFAULT), $user_id]);
            $_SESSION['flash_success'] = "Votre mot de passe a été modifié.";
            break;

        default:
            throw new RuntimeException("Action inconnue.");
    }

} catch (Throwable $e) {
    $_SESSION['flash_error'] = $e->getMessage();
}

header('Location: /mairie-parametres');
exit;
