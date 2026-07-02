<?php
/**
 * ============================================================
 * ASSOKIT — super-admin-parametres-societe-save.php
 * Endpoint POST pour sauvegarder les paramètres société
 * ============================================================
 * URL : /fondateur-cockpit/societe/save
 * Méthode : POST uniquement
 * ============================================================
 */

require_once __DIR__ . '/config.php';

require_login();
$user = current_user();

// Fondateur UNIQUEMENT
$is_founder = false;
if ($user) {
    try {
        $stmt = $pdo->prepare("SELECT is_founder FROM users WHERE id = :id");
        $stmt->execute([':id' => (int)$user['id']]);
        $row = $stmt->fetch();
        $is_founder = $row && (int)$row['is_founder'] === 1;
    } catch (Throwable $e) {}
}
if (!$is_founder) {
    http_response_code(403);
    exit('Accès réservé aux Fondateurs.');
}

// Méthode POST obligatoire
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /fondateur-cockpit/societe');
    exit;
}

// CSRF
$csrf_post = $_POST['csrf_token'] ?? '';
$csrf_sess = $_SESSION['csrf_token'] ?? '';
if (empty($csrf_sess) || !hash_equals($csrf_sess, $csrf_post)) {
    http_response_code(419);
    exit('Session expirée. Rechargez la page et réessayez.');
}

// ============================================================
// SANITIZATION DES CHAMPS
// ============================================================

function clean_string($val, $max_len = 255)
{
    $val = trim((string)$val);
    if ($val === '') return null;
    return mb_substr($val, 0, $max_len);
}

function clean_email($val)
{
    $val = trim((string)$val);
    if ($val === '') return null;
    if (!filter_var($val, FILTER_VALIDATE_EMAIL)) return null;
    return mb_substr(mb_strtolower($val), 0, 191);
}

function clean_url($val)
{
    $val = trim((string)$val);
    if ($val === '') return null;
    if (!filter_var($val, FILTER_VALIDATE_URL)) return null;
    return mb_substr($val, 0, 500);
}

function clean_country_code($val)
{
    $val = strtoupper(trim((string)$val));
    if (!preg_match('/^[A-Z]{2}$/', $val)) return null;
    return $val;
}

function clean_color($val)
{
    $val = trim((string)$val);
    if ($val === '') return null;
    if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $val)) return null;
    return strtolower($val);
}

function clean_int_or_null($val)
{
    if ($val === '' || $val === null) return null;
    return (int)$val;
}

// Capital en cents (entrée en euros)
$capital_cents = null;
if (isset($_POST['capital_euros']) && $_POST['capital_euros'] !== '') {
    $euros = (float)str_replace(',', '.', $_POST['capital_euros']);
    if ($euros >= 0) {
        $capital_cents = (int)round($euros * 100);
    }
}

// Préparer les données
$data = [
    'legal_name'         => clean_string($_POST['legal_name'] ?? '', 200),
    'legal_form'         => clean_string($_POST['legal_form'] ?? '', 50),
    'capital_cents'      => $capital_cents,
    'siren'              => clean_string($_POST['siren'] ?? '', 20),
    'siret'              => clean_string($_POST['siret'] ?? '', 20),
    'rcs_city'           => clean_string($_POST['rcs_city'] ?? '', 100),
    'rcs_number'         => clean_string($_POST['rcs_number'] ?? '', 50),
    'ape_code'           => clean_string($_POST['ape_code'] ?? '', 10),
    'vat_subject'        => !empty($_POST['vat_subject']) ? 1 : 0,
    'vat_number'         => clean_string($_POST['vat_number'] ?? '', 30),
    'vat_rate'           => (isset($_POST['vat_rate']) && $_POST['vat_rate'] !== '') ? (float)$_POST['vat_rate'] : null,
    'address_street'     => clean_string($_POST['address_street'] ?? '', 255),
    'address_complement' => clean_string($_POST['address_complement'] ?? '', 255),
    'address_zip'        => clean_string($_POST['address_zip'] ?? '', 20),
    'address_city'       => clean_string($_POST['address_city'] ?? '', 100),
    'address_country'    => clean_country_code($_POST['address_country'] ?? ''),
    'email_billing'      => clean_email($_POST['email_billing'] ?? ''),
    'email_support'      => clean_email($_POST['email_support'] ?? ''),
    'email_legal'        => clean_email($_POST['email_legal'] ?? ''),
    'phone'              => clean_string($_POST['phone'] ?? '', 30),
    'website'            => clean_url($_POST['website'] ?? ''),
    'iban'               => clean_string($_POST['iban'] ?? '', 40),
    'bic'                => clean_string($_POST['bic'] ?? '', 15),
    'bank_name'          => clean_string($_POST['bank_name'] ?? '', 100),
    'legal_rep_first_name' => clean_string($_POST['legal_rep_first_name'] ?? '', 100),
    'legal_rep_last_name'  => clean_string($_POST['legal_rep_last_name'] ?? '', 100),
    'legal_rep_role'       => clean_string($_POST['legal_rep_role'] ?? '', 100),
    'slogan'             => clean_string($_POST['slogan'] ?? '', 255),
    'primary_color'      => clean_color($_POST['primary_color'] ?? ''),
    'secondary_color'    => clean_color($_POST['secondary_color'] ?? ''),
    'cgv_url'            => clean_url($_POST['cgv_url'] ?? ''),
    'privacy_url'        => clean_url($_POST['privacy_url'] ?? ''),
];

// ============================================================
// UPLOAD LOGO (optionnel)
// ============================================================

$logo_url_update = null; // null = pas de changement ; 'value' = mise à jour

if (!empty($_FILES['logo']['name']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
    $tmp = $_FILES['logo']['tmp_name'];
    $size = (int)$_FILES['logo']['size'];
    $origName = $_FILES['logo']['name'];

    // Max 500 Ko
    if ($size > 500 * 1024) {
        $_SESSION['flash_societe'] = ['type' => 'error', 'message' => 'Logo trop lourd (max 500 Ko).'];
        header('Location: /fondateur-cockpit/societe');
        exit;
    }

    // MIME types autorisés
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $tmp);
    finfo_close($finfo);

    $allowed = [
        'image/png'     => 'png',
        'image/jpeg'    => 'jpg',
        'image/svg+xml' => 'svg',
    ];
    if (!isset($allowed[$mime])) {
        $_SESSION['flash_societe'] = ['type' => 'error', 'message' => 'Format de logo non supporté. Utilisez PNG, JPG ou SVG.'];
        header('Location: /fondateur-cockpit/societe');
        exit;
    }

    $ext = $allowed[$mime];

    // Dossier /uploads/company/
    $uploadDir = __DIR__ . '/uploads/company';
    if (!is_dir($uploadDir)) {
        @mkdir($uploadDir, 0755, true);
    }
    if (!is_dir($uploadDir) || !is_writable($uploadDir)) {
        $_SESSION['flash_societe'] = ['type' => 'error', 'message' => 'Dossier /uploads/company/ inaccessible. Créez-le manuellement.'];
        header('Location: /fondateur-cockpit/societe');
        exit;
    }

    // Nom de fichier : logo-{timestamp}.{ext} (pour casser le cache)
    $filename = 'logo-' . time() . '.' . $ext;
    $destPath = $uploadDir . '/' . $filename;

    if (!@move_uploaded_file($tmp, $destPath)) {
        $_SESSION['flash_societe'] = ['type' => 'error', 'message' => 'Erreur lors de l\'upload du logo.'];
        header('Location: /fondateur-cockpit/societe');
        exit;
    }

    $logo_url_update = '/uploads/company/' . $filename;
}

// ============================================================
// SAUVEGARDE EN BDD
// ============================================================

// Construire dynamiquement la requête UPDATE
$sets = [];
$params = [];
foreach ($data as $col => $val) {
    $sets[] = "`{$col}` = :{$col}";
    $params[":{$col}"] = $val;
}
if ($logo_url_update !== null) {
    $sets[] = "`logo_url` = :logo_url";
    $params[':logo_url'] = $logo_url_update;
}
$sets[] = "`updated_by_user_id` = :uid";
$params[':uid'] = (int)$user['id'];

$sql = "UPDATE company_settings SET " . implode(', ', $sets) . " WHERE id = 1";

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    // S'assurer qu'il y a bien 1 ligne (au cas où le INSERT IGNORE de la migration n'a rien fait)
    if ($stmt->rowCount() === 0) {
        $stmt_check = $pdo->query("SELECT COUNT(*) FROM company_settings WHERE id = 1");
        if ((int)$stmt_check->fetchColumn() === 0) {
            // Insérer d'abord
            $pdo->exec("INSERT INTO company_settings (id, created_at, updated_at) VALUES (1, NOW(), NOW())");
            // Réessayer
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
        }
    }

    $_SESSION['flash_societe'] = [
        'type' => 'success',
        'message' => 'Paramètres société enregistrés avec succès. Ils seront automatiquement utilisés dans les factures, emails et mentions légales.'
    ];
} catch (Throwable $e) {
    error_log('[PARAMS SOCIETE] Save error : ' . $e->getMessage());
    $_SESSION['flash_societe'] = [
        'type' => 'error',
        'message' => 'Erreur lors de l\'enregistrement : ' . $e->getMessage()
    ];
}

header('Location: /fondateur-cockpit/societe');
exit;
