<?php
/**
 * ============================================================
 * ASSOKIT — Handler POST pour /parametres
 * ============================================================
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes-layout.php';
require_login();
$current = current_user();
$user_id = (int)$current['id'];
$org_id = (int)($current['org_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /parametres'); exit;
}

// CSRF
$csrf_ok = isset($_POST['csrf']) && $_POST['csrf'] === ($_SESSION['csrf_token'] ?? '');
if (!$csrf_ok) {
    header('Location: /parametres?flash=' . urlencode('Token de sécurité invalide.') . '&ft=error'); exit;
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
        header('Location: /parametres?tab=compte&flash=' . urlencode('Champs obligatoires manquants ou email invalide.') . '&ft=error'); exit;
    }

    // Email unique
    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ? LIMIT 1");
    $stmt->execute([$email, $user_id]);
    if ($stmt->fetch()) {
        header('Location: /parametres?tab=compte&flash=' . urlencode('Cet email est déjà utilisé.') . '&ft=error'); exit;
    }

    try {
        $stmt = $pdo->prepare("UPDATE users SET first_name=?, last_name=?, email=?, phone=?, city=? WHERE id=?");
        $stmt->execute([$first_name, $last_name, $email, $phone, $city, $user_id]);
        header('Location: /parametres?tab=compte&flash=' . urlencode('✓ Informations mises à jour.') . '&ft=success'); exit;
    } catch (Throwable $e) {
        header('Location: /parametres?tab=compte&flash=' . urlencode('Erreur : ' . $e->getMessage()) . '&ft=error'); exit;
    }
}

// ============================================================
// 2. UPDATE ORGANIZATION (Organisation + logo + couleurs)
// ============================================================
if ($action === 'update_organization') {
    if ($current['role'] !== 'admin') {
        header('Location: /parametres?tab=organisation&flash=' . urlencode('Accès refusé.') . '&ft=error'); exit;
    }

    $org_name = trim($_POST['org_name'] ?? '');
    $siren = trim($_POST['siren'] ?? '') ?: null;
    $rna = trim($_POST['rna_number'] ?? '') ?: null;
    // [v2-assofields] Champs adresse + TVA + contact facturation
    $legal_name = trim($_POST['legal_name'] ?? '') ?: null;
    $legal_form = trim($_POST['legal_form'] ?? '') ?: null;
    $siret = trim($_POST['siret'] ?? '') ?: null;
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
        header('Location: /parametres?tab=organisation&flash=' . urlencode('Nom de l\'organisation obligatoire.') . '&ft=error'); exit;
    }
    if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $color_pri)) $color_pri = '#10B981';
    if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $color_sec)) $color_sec = '#6366F1';

    // Upload logo
    $logo_path = null;
    if (!empty($_FILES['logo']['tmp_name']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
        $size = (int)$_FILES['logo']['size'];
        if ($size > 2 * 1024 * 1024) {
            header('Location: /parametres?tab=organisation&flash=' . urlencode('Logo trop lourd (max 2 Mo).') . '&ft=error'); exit;
        }
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($_FILES['logo']['tmp_name']);
        $allowed_mimes = ['image/png'=>'png', 'image/jpeg'=>'jpg', 'image/svg+xml'=>'svg'];
        if (!isset($allowed_mimes[$mime])) {
            header('Location: /parametres?tab=organisation&flash=' . urlencode('Format de logo non supporté (PNG/JPG/SVG uniquement).') . '&ft=error'); exit;
        }
        $ext = $allowed_mimes[$mime];
        $dir = __DIR__ . '/uploads/logos';
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        $filename = 'org-' . $org_id . '-' . time() . '.' . $ext;
        $dest = $dir . '/' . $filename;
        if (move_uploaded_file($_FILES['logo']['tmp_name'], $dest)) {
            $logo_path = '/uploads/logos/' . $filename;
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
        header('Location: /parametres?tab=organisation&flash=' . urlencode('✓ Organisation mise à jour.') . '&ft=success'); exit;
    } catch (Throwable $e) {
        header('Location: /parametres?tab=organisation&flash=' . urlencode('Erreur : ' . $e->getMessage()) . '&ft=error'); exit;
    }
}

// ============================================================
// 3. CHANGE PASSWORD (Sécurité)
// ============================================================
if ($action === 'change_password') {
    $current_pw = $_POST['current_password'] ?? '';
    $new_pw = $_POST['new_password'] ?? '';
    $confirm_pw = $_POST['confirm_password'] ?? '';

    if (strlen($new_pw) < 8) {
        header('Location: /parametres?tab=securite&flash=' . urlencode('Nouveau mot de passe trop court (min 8 caractères).') . '&ft=error'); exit;
    }
    if ($new_pw !== $confirm_pw) {
        header('Location: /parametres?tab=securite&flash=' . urlencode('Les nouveaux mots de passe ne correspondent pas.') . '&ft=error'); exit;
    }

    // Vérifier mot de passe actuel
    $stmt = $pdo->prepare("SELECT password_hash FROM users WHERE id=? LIMIT 1");
    $stmt->execute([$user_id]);
    $row = $stmt->fetch();
    if (!$row || !password_verify($current_pw, $row['password_hash'])) {
        header('Location: /parametres?tab=securite&flash=' . urlencode('Mot de passe actuel incorrect.') . '&ft=error'); exit;
    }

    try {
        $new_hash = password_hash($new_pw, PASSWORD_BCRYPT);
        $stmt = $pdo->prepare("UPDATE users SET password_hash=?, must_change_password=0 WHERE id=?");
        $stmt->execute([$new_hash, $user_id]);
        header('Location: /parametres?tab=securite&flash=' . urlencode('✓ Mot de passe mis à jour.') . '&ft=success'); exit;
    } catch (Throwable $e) {
        header('Location: /parametres?tab=securite&flash=' . urlencode('Erreur : ' . $e->getMessage()) . '&ft=error'); exit;
    }
}

// Action inconnue
header('Location: /parametres'); exit;
