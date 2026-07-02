<?php
/**
 * Actions POST sur les mairies/collectivités
 * URL : /action-mairie.php
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes-layout.php';
require_once __DIR__ . '/includes-permissions.php';
require_once __DIR__ . '/includes-mairie-channels.php';
require_login();
require_platform_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /super-admin-mairies');
    exit;
}
if (!check_csrf($_POST['csrf_token'] ?? '')) {
    http_response_code(403); die('CSRF token invalide');
}

$current = current_user();
$action = $_POST['action'] ?? '';
error_log('[action-mairie] HIT method=' . $_SERVER['REQUEST_METHOD'] . ' action=' . $action . ' mairie_id=' . ($_POST['mairie_id'] ?? 'NULL') . ' csrf=' . (!empty($_POST['csrf_token']) ? 'present' : 'MISSING') . ' user_id=' . ($current['id'] ?? 'NULL'));

try {
    switch ($action) {
        case 'create':
            $name = trim($_POST['name'] ?? '');
            $type = $_POST['type'] ?? 'mairie';
            $contact_email = trim($_POST['contact_email'] ?? '');
            if ($name === '' || $contact_email === '') {
                throw new RuntimeException('Nom et email contact sont obligatoires');
            }
            $quota = max(0, (int)($_POST['validated_quota'] ?? 0));
            $status = in_array($_POST['status'] ?? '', ['active','pending'], true) ? $_POST['status'] : 'active';

            $stmt = $pdo->prepare("
                INSERT INTO parent_orgs
                    (name, type, siret,
                     contact_first_name, contact_last_name, contact_email, contact_phone,
                     address_street, address_zip, address_city, department, region,
                     requested_quota, validated_quota, status,
                     created_by, validated_by, validated_at, notes)
                VALUES
                    (:name, :type, :siret,
                     :cfn, :cln, :cmail, :cphone,
                     :astreet, :azip, :acity, :dept, :region,
                     :quota, :quota, :status,
                     :creator, :validator, :vat, :notes)
            ");
            $stmt->execute([
                ':name'   => $name,
                ':type'   => $type,
                ':siret'  => trim($_POST['siret'] ?? '') ?: null,
                ':cfn'    => trim($_POST['contact_first_name'] ?? '') ?: null,
                ':cln'    => trim($_POST['contact_last_name'] ?? '') ?: null,
                ':cmail'  => $contact_email,
                ':cphone' => trim($_POST['contact_phone'] ?? '') ?: null,
                ':astreet'=> trim($_POST['address_street'] ?? '') ?: null,
                ':azip'   => trim($_POST['address_zip'] ?? '') ?: null,
                ':acity'  => trim($_POST['address_city'] ?? '') ?: null,
                ':dept'   => trim($_POST['department'] ?? '') ?: null,
                ':region' => trim($_POST['region'] ?? '') ?: null,
                ':quota'  => $quota,
                ':status' => $status,
                ':creator'=> $current['id'],
                ':validator' => ($status === 'active') ? $current['id'] : null,
                ':vat'    => ($status === 'active') ? date('Y-m-d H:i:s') : null,
                ':notes'  => trim($_POST['notes'] ?? '') ?: null,
            ]);
            $mairie_id = (int)$pdo->lastInsertId();
            $_SESSION['flash_success'] = "Mairie « $name » créée avec succès (#$mairie_id).";
            header('Location: /super-admin-mairie-detail?id=' . $mairie_id);
            exit;

        case 'validate':
            $mid = (int)($_POST['mairie_id'] ?? 0);
            $stmt = $pdo->prepare("UPDATE parent_orgs SET status='active', validated_by=?, validated_at=NOW() WHERE id=?");
            $stmt->execute([$current['id'], $mid]);
            $_SESSION['flash_success'] = "Mairie validée.";
            header('Location: /super-admin-mairie-detail?id=' . $mid); exit;

        case 'suspend':
            $mid = (int)($_POST['mairie_id'] ?? 0);
            $pdo->prepare("UPDATE parent_orgs SET status='suspended' WHERE id=?")->execute([$mid]);
            $_SESSION['flash_success'] = "Mairie suspendue.";
            header('Location: /super-admin-mairie-detail?id=' . $mid); exit;

        case 'reactivate':
            $mid = (int)($_POST['mairie_id'] ?? 0);
            $pdo->prepare("UPDATE parent_orgs SET status='active' WHERE id=?")->execute([$mid]);
            $_SESSION['flash_success'] = "Mairie réactivée.";
            header('Location: /super-admin-mairie-detail?id=' . $mid); exit;

        case 'update_quota':
            $mid = (int)($_POST['mairie_id'] ?? 0);
            $quota = max(0, (int)($_POST['validated_quota'] ?? 0));
            $pdo->prepare("UPDATE parent_orgs SET validated_quota=? WHERE id=?")->execute([$quota, $mid]);
            $_SESSION['flash_success'] = "Quota mis à jour à $quota.";
            header('Location: /super-admin-mairie-detail?id=' . $mid); exit;

        case 'update_identity':
            $mid = (int)($_POST['mairie_id'] ?? 0);
            if ($mid <= 0) throw new RuntimeException('Mairie invalide');
            $name = trim($_POST['name'] ?? '');
            if ($name === '') throw new RuntimeException('Le nom est obligatoire');
            $type = in_array($_POST['type'] ?? '', ['mairie','departement','region','drac','caf','federation','autre'], true) ? $_POST['type'] : 'mairie';
            $pdo->prepare("
                UPDATE parent_orgs SET
                    name=?, type=?, siret=?,
                    contact_first_name=?, contact_last_name=?, contact_email=?, contact_phone=?,
                    address_street=?, address_zip=?, address_city=?, department=?, region=?,
                    updated_at=NOW()
                WHERE id=?
            ")->execute([
                mb_substr($name,0,255), $type, mb_substr(trim($_POST['siret'] ?? ''),0,20),
                mb_substr(trim($_POST['contact_first_name'] ?? ''),0,100),
                mb_substr(trim($_POST['contact_last_name'] ?? ''),0,100),
                mb_substr(trim($_POST['contact_email'] ?? ''),0,255),
                mb_substr(trim($_POST['contact_phone'] ?? ''),0,30),
                mb_substr(trim($_POST['address_street'] ?? ''),0,255),
                mb_substr(trim($_POST['address_zip'] ?? ''),0,10),
                mb_substr(trim($_POST['address_city'] ?? ''),0,100),
                mb_substr(trim($_POST['department'] ?? ''),0,100),
                mb_substr(trim($_POST['region'] ?? ''),0,100),
                $mid
            ]);
            $_SESSION['flash_success'] = "Identité de la collectivité mise à jour.";
            header('Location: /super-admin-mairie-detail?id=' . $mid); exit;

        case 'link_org':
            $mid = (int)($_POST['mairie_id'] ?? 0);
            $oid = (int)($_POST['org_id'] ?? 0);
            if ($mid <= 0 || $oid <= 0) throw new RuntimeException('Mairie/asso invalide');
            // Vérifier que la mairie n'a pas dépassé son quota
            $mairie = $pdo->prepare("SELECT validated_quota FROM parent_orgs WHERE id=?");
            $mairie->execute([$mid]);
            $quota = (int)$mairie->fetchColumn();
            $current_count = (int)$pdo->prepare("SELECT COUNT(*) FROM organizations WHERE parent_org_id=?")->execute([$mid]) ?: 0;
            $c = $pdo->prepare("SELECT COUNT(*) FROM organizations WHERE parent_org_id=?");
            $c->execute([$mid]);
            $current_count = (int)$c->fetchColumn();
            if ($quota > 0 && $current_count >= $quota) {
                throw new RuntimeException("Quota atteint ($current_count/$quota). Augmente le quota avant de lier.");
            }
            $pdo->prepare("UPDATE organizations SET parent_org_id=?, parent_org_linked_at=NOW() WHERE id=?")->execute([$mid, $oid]);
            // Auto-création du channel mairie ↔ asso (+ ajout membres)
            try { ensure_mairie_channel_for_org($pdo, $oid, $mid); } catch (Throwable $e) { error_log('[mairie-channel] link_org: ' . $e->getMessage()); }
            $org_name = $pdo->prepare("SELECT name FROM organizations WHERE id=?");
            $org_name->execute([$oid]); $name = $org_name->fetchColumn();
            $_SESSION['flash_success'] = "Association « $name » liée à la mairie.";
            header('Location: /super-admin-mairie-detail?id=' . $mid); exit;

        case 'unlink_org':
            $mid = (int)($_POST['mairie_id'] ?? 0);
            $oid = (int)($_POST['org_id'] ?? 0);
            $pdo->prepare("UPDATE organizations SET parent_org_id=NULL, parent_org_linked_at=NULL WHERE id=? AND parent_org_id=?")
                ->execute([$oid, $mid]);
            // Archive le channel mairie + retire les agents mairie
            try { archive_mairie_channel_for_org($pdo, $oid, $mid); } catch (Throwable $e) { error_log('[mairie-channel] unlink_org: ' . $e->getMessage()); }
            $_SESSION['flash_success'] = "Association déliée de la mairie.";
            header('Location: /super-admin-mairie-detail?id=' . $mid); exit;

        case 'add_user':
            $mid = (int)($_POST['mairie_id'] ?? 0);
            $fn = trim($_POST['first_name'] ?? '');
            $ln = trim($_POST['last_name'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $role = in_array($_POST['parent_org_role'] ?? '', ['admin','agent'], true) ? $_POST['parent_org_role'] : 'admin';
            if ($fn === '' || $ln === '' || $email === '') throw new RuntimeException('Prénom, nom et email obligatoires');
            // Vérifier email unique
            $exists = $pdo->prepare("SELECT id FROM users WHERE email=?");
            $exists->execute([$email]);
            if ($exists->fetchColumn()) throw new RuntimeException("Email déjà utilisé sur la plateforme");
            // Génère un mot de passe temporaire à transmettre
            $temp_password = bin2hex(random_bytes(4)); // ex: a1b2c3d4
            $hash = password_hash($temp_password, PASSWORD_DEFAULT);
            $pdo->prepare("INSERT INTO users (first_name, last_name, email, password_hash, role, parent_org_id, parent_org_role, created_at) VALUES (?, ?, ?, ?, 'admin', ?, ?, NOW())")
                ->execute([$fn, $ln, $email, $hash, $mid, $role]);
            $new_id = (int)$pdo->lastInsertId();
            // Ajoute le nouvel agent à tous les channels mairie existants
            try { add_mairie_agent_to_channels($pdo, $new_id, $mid); } catch (Throwable $e) { error_log('[mairie-channel] add_user: ' . $e->getMessage()); }
            $_SESSION['flash_success'] = "Agent « $fn $ln » créé (#$new_id). Mot de passe temporaire à transmettre : $temp_password";
            header('Location: /super-admin-mairie-detail?id=' . $mid); exit;

        case 'remove_user':
            $mid = (int)($_POST['mairie_id'] ?? 0);
            $uid = (int)($_POST['user_id'] ?? 0);
            // Soft : on retire juste le rattachement (le user existe toujours mais désactivé)
            $pdo->prepare("UPDATE users SET parent_org_id=NULL, parent_org_role=NULL WHERE id=? AND parent_org_id=?")
                ->execute([$uid, $mid]);
            $_SESSION['flash_success'] = "Agent retiré de la mairie.";
            header('Location: /super-admin-mairie-detail?id=' . $mid); exit;

        default:
            throw new RuntimeException('Action inconnue : ' . htmlspecialchars($action));
    }
} catch (Throwable $e) {
    $_SESSION['flash_error'] = 'Erreur : ' . $e->getMessage();
    header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? '/super-admin-mairies'));
    exit;
}
