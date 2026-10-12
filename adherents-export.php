<?php
require_once __DIR__ . '/csv-safe.php';
/**
 * ============================================================
 * ASSOKIT — Export CSV des adhérents
 * ============================================================
 * Génère et télécharge un fichier CSV de tous les adhérents de l'asso.
 * - Séparateur : ; (point-virgule, standard français)
 * - Encodage : UTF-8 avec BOM (pour Excel avec accents)
 * - Respecte les filtres éventuels (q, role)
 * ============================================================
 */
require_once __DIR__ . '/config.php';

require_login();

$user = current_user();
$org_id = (int)$user['org_id'];
if ($org_id <= 0) { header('Location: /dashboard'); exit; }  // compte sans association (agent de collectivité) : pas d'« org 0 » partagée

// Seuls admin/coordinator (ou le droit « Gérer les adhérents » donné par l'admin) peuvent exporter.
// Jamais un suiveur (financeur externe).
$role_me = (string)($user['role'] ?? '');
if (!in_array($role_me, ['admin', 'coordinator'], true)
    && ($role_me === 'follower' || !function_exists('can') || !can('manage_members'))) {
    http_response_code(403);
    die('Accès refusé — rôle insuffisant.');
}

// Filtres
$search = trim((string)preg_replace('/\s+/u', ' ', (string)($_GET['q'] ?? '')));
$filter_role = $_GET['role'] ?? 'all';
$valid_roles = ['all', 'admin', 'coordinator', 'referent', 'member', 'follower'];
if (!in_array($filter_role, $valid_roles, true)) $filter_role = 'all';

// Charger adhérents : la liste affichée sur /adherents, sans ceux de la corbeille
$sql = "
    SELECT first_name, last_name, email, phone, city, role,
           adhesion_date, adhesion_valid_until, is_active, created_at
    FROM users
    WHERE org_id = :org_id AND deleted_at IS NULL
";
$params = [':org_id' => $org_id];

if ($search !== '') {
    // Même recherche que /adherents (nom complet dans les deux sens compris), un marqueur par usage.
    $sql .= " AND (first_name LIKE :q1 OR last_name LIKE :q2 OR email LIKE :q3 OR city LIKE :q4
                   OR CONCAT(first_name, ' ', last_name) LIKE :q5 OR CONCAT(last_name, ' ', first_name) LIKE :q6)";
    $like = '%' . $search . '%';
    $params += [':q1' => $like, ':q2' => $like, ':q3' => $like, ':q4' => $like, ':q5' => $like, ':q6' => $like];
}
if ($filter_role !== 'all') {
    $sql .= " AND role = :role";
    $params[':role'] = $filter_role;
}
$sql .= " ORDER BY last_name ASC, first_name ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$adherents = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Nom de l'asso pour le fichier
$org = $pdo->prepare("SELECT name FROM organizations WHERE id = ?");
$org->execute([$org_id]);
$org_row = $org->fetch();
$org_slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $org_row['name'] ?? 'asso'));
$org_slug = trim($org_slug, '-');

$filename = $org_slug . '-adherents-' . date('Y-m-d') . '.csv';

// Headers HTTP
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-cache, must-revalidate');
header('Pragma: no-cache');

// BOM UTF-8 pour Excel (affiche correctement les accents)
echo "\xEF\xBB\xBF";

// Mapping des rôles
$role_labels = [
    'admin'       => 'Administrateur',
    'coordinator' => 'Coordinateur',
    'referent'    => 'Référent',
    'member'      => 'Membre',
    'follower'    => 'Suiveur',
    'super_admin' => 'Super Admin',
];

// Fonction pour échapper un champ CSV
function csv_escape($value, $separator = ';') {
    $value = ak_csv_safe((string)$value);
    $value = (string) $value;
    // Si contient le séparateur, un guillemet ou un retour ligne → entourer de "
    if (strpos($value, $separator) !== false || strpos($value, '"') !== false || strpos($value, "\n") !== false || strpos($value, "\r") !== false) {
        $value = '"' . str_replace('"', '""', $value) . '"';
    }
    return $value;
}

// En-têtes
$headers = [
    'Prénom', 'Nom', 'Email', 'Téléphone', 'Ville',
    'Rôle', 'Date d\'adhésion', 'Adhésion valide jusqu\'au',
    'Actif', 'Date d\'inscription',
];
echo implode(';', array_map('csv_escape', $headers)) . "\r\n";

// Données
foreach ($adherents as $a) {
    $row = [
        csv_escape($a['first_name']),
        csv_escape($a['last_name']),
        csv_escape($a['email']),
        csv_escape($a['phone']),
        csv_escape($a['city']),
        csv_escape($role_labels[$a['role']] ?? $a['role']),
        csv_escape($a['adhesion_date'] ? date('d/m/Y', strtotime($a['adhesion_date'])) : ''),
        csv_escape($a['adhesion_valid_until'] ? date('d/m/Y', strtotime($a['adhesion_valid_until'])) : ''),
        csv_escape($a['is_active'] ? 'Oui' : 'Non'),
        csv_escape($a['created_at'] ? date('d/m/Y', strtotime($a['created_at'])) : ''),
    ];
    echo implode(';', $row) . "\r\n";
}

exit;
