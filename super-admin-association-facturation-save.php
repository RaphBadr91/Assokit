<?php
/**
 * ============================================================
 * ASSOKIT — super-admin-association-facturation-save.php
 * Endpoint POST pour sauvegarder les infos facturation d'une asso
 * ============================================================
 * URL : /super-admin/associations/facturation/save
 * ============================================================
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/org-billing-helpers.php';

require_login();
$user = current_user();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /super-admin/associations');
    exit;
}

// CSRF
$csrf_post = $_POST['csrf_token'] ?? '';
$csrf_sess = $_SESSION['csrf_token'] ?? '';
if (empty($csrf_sess) || !hash_equals($csrf_sess, $csrf_post)) {
    http_response_code(419);
    exit('Session expirée. Rechargez la page et réessayez.');
}

$org_id = (int)($_POST['org_id'] ?? 0);
if ($org_id <= 0) {
    http_response_code(400);
    exit('ID organisation invalide.');
}

// Vérif droits
$can_edit = can_edit_org_billing($pdo, (int)$user['id'], $org_id);
if (!$can_edit) {
    http_response_code(403);
    exit('Vous n\'avez pas les droits pour modifier cette association.');
}

// Vérif Fondateur pour les champs privés
$is_founder = false;
try {
    $stmt = $pdo->prepare("SELECT is_founder FROM users WHERE id = :id");
    $stmt->execute([':id' => (int)$user['id']]);
    $row = $stmt->fetch();
    $is_founder = $row && (int)$row['is_founder'] === 1;
} catch (Throwable $e) {}

// ----- URL de retour (selon d'où vient le post)
$return_url = $_POST['return_url'] ?? null;
if (empty($return_url)) {
    // Par défaut : même page
    $return_url = '/super-admin/associations/facturation?id=' . $org_id;
}

// ============================================================
// SANITIZATION
// ============================================================

function ob_clean_string($val, $max_len = 255)
{
    $val = trim((string)$val);
    if ($val === '') return null;
    return mb_substr($val, 0, $max_len);
}

function ob_clean_email($val)
{
    $val = trim((string)$val);
    if ($val === '') return null;
    if (!filter_var($val, FILTER_VALIDATE_EMAIL)) return null;
    return mb_substr(mb_strtolower($val), 0, 191);
}

function ob_clean_country($val)
{
    $val = strtoupper(trim((string)$val));
    if (!preg_match('/^[A-Z]{2}$/', $val)) return null;
    return $val;
}

// ----- Préparer les données
$data = [
    'legal_name'                 => ob_clean_string($_POST['legal_name'] ?? '', 200),
    'legal_form'                 => ob_clean_string($_POST['legal_form'] ?? '', 50),
    'siren'                      => ob_clean_string($_POST['siren'] ?? '', 20),
    'siret'                      => ob_clean_string($_POST['siret'] ?? '', 20),
    'rna_number'                 => ob_clean_string($_POST['rna_number'] ?? '', 20),
    'vat_subject'                => !empty($_POST['vat_subject']) ? 1 : 0,
    'vat_number'                 => ob_clean_string($_POST['vat_number'] ?? '', 30),
    'billing_address_street'     => ob_clean_string($_POST['billing_address_street'] ?? '', 255),
    'billing_address_complement' => ob_clean_string($_POST['billing_address_complement'] ?? '', 255),
    'billing_address_zip'        => ob_clean_string($_POST['billing_address_zip'] ?? '', 20),
    'billing_address_city'       => ob_clean_string($_POST['billing_address_city'] ?? '', 100),
    'billing_address_country'    => ob_clean_country($_POST['billing_address_country'] ?? ''),
    'billing_email'              => ob_clean_email($_POST['billing_email'] ?? ''),
    'billing_phone'              => ob_clean_string($_POST['billing_phone'] ?? '', 30),
    'president_first_name'       => ob_clean_string($_POST['president_first_name'] ?? '', 100),
    'president_last_name'        => ob_clean_string($_POST['president_last_name'] ?? '', 100),
    'president_role'             => ob_clean_string($_POST['president_role'] ?? '', 100),
];

// Champs Fondateur uniquement
if ($is_founder) {
    $data['external_ref']   = ob_clean_string($_POST['external_ref'] ?? '', 100);
    $data['internal_notes'] = isset($_POST['internal_notes']) ? mb_substr(trim($_POST['internal_notes']), 0, 5000) : null;
    if ($data['internal_notes'] === '') $data['internal_notes'] = null;
}

// ============================================================
// SAUVEGARDE BDD
// ============================================================

$sets = [];
$params = [':id' => $org_id];
foreach ($data as $col => $val) {
    $sets[] = "`{$col}` = :{$col}";
    $params[":{$col}"] = $val;
}
$sets[] = "`billing_updated_at` = NOW()";
$sets[] = "`billing_updated_by_user_id` = :uid";
$params[':uid'] = (int)$user['id'];

$sql = "UPDATE organizations SET " . implode(', ', $sets) . " WHERE id = :id";

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $_SESSION['flash_orgbill'] = [
        'type' => 'success',
        'message' => 'Infos de facturation enregistrées avec succès.'
    ];
} catch (Throwable $e) {
    error_log('[ORG BILLING] Save error : ' . $e->getMessage());
    $_SESSION['flash_orgbill'] = [
        'type' => 'error',
        'message' => 'Erreur : ' . $e->getMessage()
    ];
}

header('Location: ' . $return_url);
exit;
