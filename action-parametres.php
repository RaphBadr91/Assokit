<?php
/**
 * ============================================================
 * ASSOKIT — Handler POST pour /parametres
 * ============================================================
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/demo-guard.php';
require_once __DIR__ . '/activity-tracker.php';  // journal fondateur : qui a fait quoi, à la minute près
activity_log_request();
require_once __DIR__ . '/includes-layout.php';
require_login();
$current = current_user();
$user_id = (int)$current['id'];
$org_id = (int)($current['org_id'] ?? 0);

/**
 * Retour vers /parametres avec un message. Le message passe par la session, jamais par l'URL :
 * un lien piégé (/parametres?flash=…) ne peut pas afficher un faux avis sur assokit.fr.
 */
function pa_redirect(string $tab, string $msg, string $type = 'error'): void
{
    $_SESSION['flash_parametres'] = ['msg' => $msg, 'type' => $type];
    header('Location: /parametres' . ($tab !== '' ? '?tab=' . rawurlencode($tab) : ''));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /parametres'); exit;
}

// CSRF
$csrf_ok = isset($_POST['csrf']) && $_POST['csrf'] === ($_SESSION['csrf_token'] ?? '');
if (!$csrf_ok) {
    pa_redirect('', 'Token de sécurité invalide.');
}

$action = $_POST['action'] ?? '';

// ============================================================
// 1. UPDATE ACCOUNT (Compte)
// ============================================================
if ($action === 'update_account') {
    $first_name = trim($_POST['first_name'] ?? '');
    $last_name = trim($_POST['last_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '') ?: null;
    $city = trim($_POST['city'] ?? '') ?: null;

    if ($first_name === '' || $last_name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        pa_redirect('compte', 'Champs obligatoires manquants ou email invalide.');
    }

    // Anti-escalade : une adresse réservée (Fondateur, boîte des alertes d'inscription) ne peut pas
    // être adoptée par un autre compte. Celui qui l'a déjà la garde (simple mise à jour du nom).
    @require_once __DIR__ . '/api/_app-founder.php';
    require_once __DIR__ . '/trial-helpers.php';
    if (in_array(strtolower($email), ak_founder_emails(), true)) {
        $cur = $pdo->prepare("SELECT email FROM users WHERE id = ?"); $cur->execute([$user_id]);
        if (strtolower(trim((string)$cur->fetchColumn())) !== strtolower($email)
            && (!function_exists('app_is_founder') || !app_is_founder($pdo, function_exists('current_user') ? current_user() : null))) {
            pa_redirect('compte', 'Cette adresse email est réservée.');
        }
    }

    // Démo : l'e-mail de connexion d'un compte partagé ne change pas
    if (ak_demo_protected_account($user_id)) {
        $cur = $pdo->prepare("SELECT email FROM users WHERE id = ?"); $cur->execute([$user_id]);
        if (strtolower($email) !== strtolower((string)$cur->fetchColumn())) {
            pa_redirect('compte', ak_demo_message());
        }
    }

    // Email unique
    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ? LIMIT 1");
    $stmt->execute([$email, $user_id]);
    if ($stmt->fetch()) {
        pa_redirect('compte', 'Cet email est déjà utilisé.');
    }

    // Changer l'adresse de connexion, c'est pouvoir ensuite faire « mot de passe oublié » avec la
    // nouvelle : on exige le mot de passe actuel (une session restée ouverte ne suffit pas).
    $stmt = $pdo->prepare("SELECT email, password_hash FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([$user_id]);
    $me = $stmt->fetch() ?: [];
    $old_email = (string)($me['email'] ?? '');
    $email_changed = strtolower($old_email) !== strtolower($email);
    if ($email_changed) {
        require_once __DIR__ . '/rate-limit-helper.php';
        $pw = (string)($_POST['current_password'] ?? '');
        if ($pw === '') {
            pa_redirect('compte', 'Pour changer d’adresse e-mail, saisissez votre mot de passe actuel.');
        }
        if (!ak_rate_limit('email_change_pw', 8, 600, (string)$user_id)) {
            pa_redirect('compte', 'Trop de tentatives. Merci de patienter quelques minutes.');
        }
        if (empty($me['password_hash']) || !password_verify($pw, (string)$me['password_hash'])) {
            pa_redirect('compte', 'Mot de passe actuel incorrect : adresse e-mail non modifiée.');
        }
    }

    try {
        $stmt = $pdo->prepare("UPDATE users SET first_name=?, last_name=?, email=?, phone=?, city=? WHERE id=?");
        $stmt->execute([$first_name, $last_name, $email, $phone, $city, $user_id]);
    } catch (Throwable $e) {
        error_log('[action-parametres update_account] ' . $e->getMessage());
        pa_redirect('compte', 'Enregistrement impossible, vérifiez les champs.');
    }
    $msg = '✓ Informations mises à jour.';
    if ($email_changed) {
        // Le journal d'activité et l'export RGPD suivent l'adresse de la session
        $_SESSION['user_email'] = $email;
        require_once __DIR__ . '/password-token-helper.php';
        ak_account_email_changed($pdo, $user_id, $old_email, $email, $first_name);
        $msg = '✓ Informations mises à jour. Un e-mail de confirmation a été envoyé à votre nouvelle adresse, et l’ancienne a été prévenue.';
    }
    pa_redirect('compte', $msg, 'success');
}

// ============================================================
// 2. UPDATE ORGANIZATION (Organisation + logo + couleurs)
// ============================================================
if ($action === 'update_organization') {
    if ($current['role'] !== 'admin') {
        pa_redirect('organisation', 'Accès refusé.');
    }

    $org_name = trim($_POST['org_name'] ?? '');
    // SIREN / SIRET : chiffres seuls (« 123 456 789 », comme l'exemple du champ, est accepté)
    $siren = preg_replace('/\D/', '', (string)($_POST['siren'] ?? '')) ?: null;
    $rna = trim($_POST['rna_number'] ?? '') ?: null;
    // [v2-assofields] Champs adresse + TVA + contact facturation
    $legal_name = trim($_POST['legal_name'] ?? '') ?: null;
    $legal_form = trim($_POST['legal_form'] ?? '') ?: null;
    $siret = preg_replace('/\D/', '', (string)($_POST['siret'] ?? '')) ?: null;
    $bill_street = trim($_POST['billing_address_street'] ?? '') ?: null;
    $bill_comp = trim($_POST['billing_address_complement'] ?? '') ?: null;
    $bill_zip = trim($_POST['billing_address_zip'] ?? '') ?: null;
    $bill_city = trim($_POST['billing_address_city'] ?? '') ?: null;
    $bill_country = trim($_POST['billing_address_country'] ?? '') ?: null;
    $vat_subject = !empty($_POST['vat_subject']) ? 1 : 0;
    $vat_number = trim($_POST['vat_number'] ?? '') ?: null;
    $bill_email = trim($_POST['billing_email'] ?? '') ?: null;
    $bill_phone = trim($_POST['billing_phone'] ?? '') ?: null;
    $color_pri = trim($_POST['branding_primary_color_hex'] ?? $_POST['branding_primary_color'] ?? '#10B981');
    $color_sec = trim($_POST['branding_secondary_color_hex'] ?? $_POST['branding_secondary_color'] ?? '#6366F1');

    if ($org_name === '') {
        pa_redirect('organisation', 'Nom de l\'organisation obligatoire.');
    }
    if ($siren !== null && strlen($siren) !== 9) {
        pa_redirect('organisation', 'SIREN invalide : 9 chiffres attendus.');
    }
    if ($siret !== null && strlen($siret) !== 14) {
        pa_redirect('organisation', 'SIRET invalide : 14 chiffres attendus.');
    }
    if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $color_pri)) $color_pri = '#10B981';
    if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $color_sec)) $color_sec = '#6366F1';

    // Upload logo. Un logo refusé n'empêche pas d'enregistrer le reste du formulaire : seul le
    // logo est ignoré (l'ancien reste en place) et le message le dit.
    $logo_path = null;
    $logo_warning = null;
    $logo_err = (int)($_FILES['logo']['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($logo_err === UPLOAD_ERR_INI_SIZE || $logo_err === UPLOAD_ERR_FORM_SIZE) {
        $logo_warning = 'le logo est refusé : 2 Mo maximum.';
    } elseif ($logo_err !== UPLOAD_ERR_OK && $logo_err !== UPLOAD_ERR_NO_FILE) {
        $logo_warning = 'le logo n’a pas pu être reçu. Réessayez.';
    } elseif ($logo_err === UPLOAD_ERR_OK && !empty($_FILES['logo']['tmp_name'])) {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($_FILES['logo']['tmp_name']);
        // SVG retiré : contenu actif (XSS stocké possible). PNG/JPG/GIF uniquement.
        $allowed_mimes = ['image/png'=>'png', 'image/jpeg'=>'jpg', 'image/gif'=>'gif'];
        if ((int)$_FILES['logo']['size'] > 2 * 1024 * 1024) {
            $logo_warning = 'le logo est refusé : 2 Mo maximum.';
        } elseif (!isset($allowed_mimes[$mime])) {
            $logo_warning = 'le logo est refusé : format PNG, JPG ou GIF uniquement.';
        } else {
            $ext = $allowed_mimes[$mime];
            $dir = __DIR__ . '/uploads/logos';
            if (!is_dir($dir)) @mkdir($dir, 0755, true);
            $filename = 'org-' . $org_id . '-' . time() . '.' . $ext;
            $dest = $dir . '/' . $filename;
            if (move_uploaded_file($_FILES['logo']['tmp_name'], $dest)) {
                $logo_path = '/uploads/logos/' . $filename;
            } else {
                error_log('[action-parametres update_organization] logo non déplacé vers ' . $dest);
                $logo_warning = 'le logo n’a pas pu être enregistré. Réessayez.';
            }
        }
    }

    try {
        if ($logo_path) {
            $stmt = $pdo->prepare("UPDATE organizations SET name=?, legal_name=?, legal_form=?, siren=?, siret=?, rna_number=?, billing_address_street=?, billing_address_complement=?, billing_address_zip=?, billing_address_city=?, billing_address_country=?, vat_subject=?, vat_number=?, billing_email=?, billing_phone=?, branding_primary_color=?, branding_secondary_color=?, logo_path=?, logo_uploaded_at=NOW(), billing_updated_at=NOW() WHERE id=?");
            $stmt->execute([$org_name, $legal_name, $legal_form, $siren, $siret, $rna, $bill_street, $bill_comp, $bill_zip, $bill_city, $bill_country, $vat_subject, $vat_number, $bill_email, $bill_phone, $color_pri, $color_sec, $logo_path, $org_id]);
        } else {
            $stmt = $pdo->prepare("UPDATE organizations SET name=?, legal_name=?, legal_form=?, siren=?, siret=?, rna_number=?, billing_address_street=?, billing_address_complement=?, billing_address_zip=?, billing_address_city=?, billing_address_country=?, vat_subject=?, vat_number=?, billing_email=?, billing_phone=?, branding_primary_color=?, branding_secondary_color=?, billing_updated_at=NOW() WHERE id=?");
            $stmt->execute([$org_name, $legal_name, $legal_form, $siren, $siret, $rna, $bill_street, $bill_comp, $bill_zip, $bill_city, $bill_country, $vat_subject, $vat_number, $bill_email, $bill_phone, $color_pri, $color_sec, $org_id]);
        }
    } catch (Throwable $e) {
        error_log('[action-parametres update_organization] ' . $e->getMessage());
        pa_redirect('organisation', 'Enregistrement impossible, vérifiez les champs.');
    }
    if ($logo_warning !== null) {
        pa_redirect('organisation', 'Organisation mise à jour, mais ' . $logo_warning, 'warning');
    }
    pa_redirect('organisation', '✓ Organisation mise à jour.', 'success');
}

// ============================================================
// 3. CHANGE PASSWORD (Sécurité)
// ============================================================
if ($action === 'change_password') {
    $current_pw = $_POST['current_password'] ?? '';
    $new_pw = $_POST['new_password'] ?? '';
    $confirm_pw = $_POST['confirm_password'] ?? '';

    if (ak_demo_protected_account($user_id)) {
        pa_redirect('securite', ak_demo_message());
    }
    if (strlen($new_pw) < 8) {
        pa_redirect('securite', 'Nouveau mot de passe trop court (min 8 caractères).');
    }
    if ($new_pw !== $confirm_pw) {
        pa_redirect('securite', 'Les nouveaux mots de passe ne correspondent pas.');
    }

    // Vérifier mot de passe actuel
    $stmt = $pdo->prepare("SELECT password_hash FROM users WHERE id=? LIMIT 1");
    $stmt->execute([$user_id]);
    $row = $stmt->fetch();
    if (!$row || !password_verify($current_pw, $row['password_hash'])) {
        pa_redirect('securite', 'Mot de passe actuel incorrect.');
    }

    try {
        $new_hash = password_hash($new_pw, PASSWORD_BCRYPT);
        $stmt = $pdo->prepare("UPDATE users SET password_hash=?, must_change_password=0 WHERE id=?");
        $stmt->execute([$new_hash, $user_id]);
        pa_redirect('securite', '✓ Mot de passe mis à jour.', 'success');
    } catch (Throwable $e) {
        error_log('[action-parametres change_password] ' . $e->getMessage());
        pa_redirect('securite', 'Changement impossible pour le moment. Merci de réessayer.');
    }
}

// Action inconnue
header('Location: /parametres'); exit;
