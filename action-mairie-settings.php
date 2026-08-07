<?php
/**
 * Endpoint POST : paramètres mairie (profil, agents, mot de passe)
 * URL : /action-mairie-settings
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes-layout.php';
require_once __DIR__ . '/includes-permissions.php';
require_once __DIR__ . '/includes-mairie-channels.php';
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
$parent_org_id = (int)$parent_org['id'];
$user_id = (int)$current['id'];
$is_mairie_admin = (($current['parent_org_role'] ?? '') === 'admin') || is_platform_admin($current);
$action = $_POST['action'] ?? '';

try {
    switch ($action) {

        // === Mettre à jour le profil de la collectivité ===
        case 'update_profile':
            if (!$is_mairie_admin) throw new RuntimeException("Accès réservé aux administrateurs.");

            $name = trim($_POST['name'] ?? '');
            if ($name === '') throw new RuntimeException("Le nom est obligatoire.");

            $type = in_array($_POST['type'] ?? '', ['mairie','departement','region','drac','caf','federation','autre'], true)
                  ? $_POST['type'] : 'mairie';

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
            if ($fn === '' || $ln === '' || $email === '') throw new RuntimeException("Prénom, nom et email obligatoires.");
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException("Email invalide.");

            $exists = $pdo->prepare("SELECT id FROM users WHERE email = ?");
            $exists->execute([$email]);
            if ($exists->fetchColumn()) throw new RuntimeException("Cet email est déjà utilisé sur la plateforme.");

            $temp_password = bin2hex(random_bytes(4));
            $hash = password_hash($temp_password, PASSWORD_DEFAULT);
            $pdo->prepare("
                INSERT INTO users (first_name, last_name, email, password_hash, role, parent_org_id, parent_org_role, is_active, created_at)
                VALUES (?, ?, ?, ?, 'admin', ?, ?, 1, NOW())
            ")->execute([$fn, $ln, $email, $hash, $parent_org_id, $role]);
            $new_id = (int)$pdo->lastInsertId();

            // Ajouter le nouvel agent à tous les channels mairie existants
            try { add_mairie_agent_to_channels($pdo, $new_id, $parent_org_id); }
            catch (Throwable $e) { error_log('[settings-add-agent-channel] ' . $e->getMessage()); }

            $_SESSION['flash_success'] = "Agent « $fn $ln » ajouté. Mot de passe temporaire à transmettre : $temp_password";
            break;

        // === Retirer un agent ===
        case 'remove_agent':
            if (!$is_mairie_admin) throw new RuntimeException("Accès réservé aux administrateurs.");

            $agent_id = (int)($_POST['agent_id'] ?? 0);
            if ($agent_id <= 0) throw new RuntimeException("Agent invalide.");
            if ($agent_id === $user_id) throw new RuntimeException("Vous ne pouvez pas vous retirer vous-même.");

            // Vérifier que l'agent appartient bien à cette mairie
            $check = $pdo->prepare("SELECT id, first_name, last_name FROM users WHERE id = ? AND parent_org_id = ? AND deleted_at IS NULL");
            $check->execute([$agent_id, $parent_org_id]);
            $agent = $check->fetch(PDO::FETCH_ASSOC);
            if (!$agent) throw new RuntimeException("Agent introuvable dans votre collectivité.");

            // Soft delete + détacher de la mairie + retirer des channels
            $pdo->prepare("UPDATE users SET deleted_at = NOW(), parent_org_id = NULL, parent_org_role = NULL WHERE id = ?")->execute([$agent_id]);
            $pdo->prepare("DELETE FROM channel_members WHERE user_id = ?")->execute([$agent_id]);

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

            $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?")
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
