<?php
/**
 * api/app-members.php — Liste des membres (adherents) pour l'ecran natif de l'app.
 * JSON, authentifie par session. NE MODIFIE PAS le site.
 */
ob_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes-layout.php';
ob_end_clean();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'auth']);
    exit;
}

// Mêmes libellés que le site (role_label())
$ROLE_LABELS = [
    'admin'       => 'Administrateur',
    'coordinator' => 'Coordinateur',
    'referent'    => 'Référent',
    'member'      => 'Membre',
    'follower'    => 'Suiveur',
    'super_admin' => 'Super admin',
];

// Compte désactivé ou supprimé depuis l'ouverture de la session : même règle que
// _app-write-boot.php (session fermée, 401 'auth' → l'appli revient à la connexion).
try {
    $user = function_exists('current_user') ? current_user() : null;
    $st = $pdo->prepare("SELECT is_active, deleted_at FROM users WHERE id = ? LIMIT 1");
    $st->execute([(int) $_SESSION['user_id']]);
    $live = $st->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('[api/app-members] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'server']);
    exit;
}
$gone = !$live || (int) ($live['is_active'] ?? 1) !== 1
    || (!empty($live['deleted_at']) && strpos((string) $live['deleted_at'], '0000-00-00') !== 0);
if ($gone || !$user) {
    if ($gone) {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) @session_destroy();
    }
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'auth']);
    exit;
}

try {
    $org_id = (int) ($user['org_id'] ?? ($_SESSION['org_id'] ?? 0));
    if (function_exists('ak_platform_flags')) $user = ak_platform_flags($user);
    $role_me = (string) ($user['role'] ?? '');

    // Suiveur (financeur externe) : pas d'annuaire des adhérents, comme sur le site (/adherents
    // le renvoie vers /projets). Liste vide plutôt qu'un refus : les sélecteurs de l'appli
    // qui lisent cette liste restent fonctionnels.
    if ($role_me === 'follower') {
        echo json_encode([
            'ok' => true, 'allowed' => false, 'admin' => false, 'members' => [],
            'message' => 'L’annuaire des adhérents n’est pas accessible aux suiveurs de projet.',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Coordonnées (e-mail, ville) : gestionnaires seulement — admin, coordinateur, droit « Gérer
    // les adhérents » donné par l'admin, fondateur, super-admin —, même règle que /adherents et
    // /adherent sur le site.
    $can_add = in_array($role_me, ['admin', 'coordinator'], true)
        || (function_exists('can') && can('manage_members'));
    $is_admin = $can_add || $role_me === 'super_admin'
        || !empty($user['is_super_admin']) || !empty($user['is_founder']);

    // Liste complète par défaut (l'ancienne limite à 500 masquait la fin de l'alphabet
    // des grandes associations). Options : ?q= (nom, e-mail pour les gestionnaires),
    // ?limit= & ?offset= pour paginer ; la réponse indique alors total / has_more.
    $q = trim((string) ($_GET['q'] ?? ''));
    $limit = isset($_GET['limit']) ? max(1, min(500, (int) $_GET['limit'])) : 0;
    $offset = $limit ? max(0, (int) ($_GET['offset'] ?? 0)) : 0;

    $where = "u.org_id = ? AND u.is_active = 1 AND (u.deleted_at IS NULL OR u.deleted_at = '')";
    $params = [$org_id];
    if ($q !== '') {
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
        $where .= " AND (CONCAT_WS(' ', u.first_name, u.last_name) LIKE ? OR CONCAT_WS(' ', u.last_name, u.first_name) LIKE ?"
                . ($is_admin ? " OR u.email LIKE ?" : '') . ")";
        array_push($params, $like, $like);
        if ($is_admin) $params[] = $like;
    }

    $st = $pdo->prepare("SELECT COUNT(*) FROM users u WHERE $where");
    $st->execute($params);
    $total = (int) $st->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT u.id, u.first_name, u.last_name, u.email, u.role,
               u.city, u.avatar_color, u.adhesion_valid_until
        FROM users u
        WHERE $where
        ORDER BY
          CASE u.role
            WHEN 'admin' THEN 1
            WHEN 'coordinator' THEN 2
            WHEN 'referent' THEN 3
            ELSE 4
          END,
          u.last_name ASC, u.first_name ASC, u.id ASC
        " . ($limit ? "LIMIT $limit OFFSET $offset" : '') . "
    ");
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $today = date('Y-m-d');
    $members = [];
    foreach ($rows as $r) {
        $fn = trim((string) ($r['first_name'] ?? ''));
        $ln = trim((string) ($r['last_name'] ?? ''));
        $full = trim($fn . ' ' . $ln);
        $ini = strtoupper((function_exists('mb_substr') ? mb_substr($fn, 0, 1) : substr($fn, 0, 1))
                        . (function_exists('mb_substr') ? mb_substr($ln, 0, 1) : substr($ln, 0, 1)));
        if ($ini === '') $ini = '?';

        // Statut d'adhesion
        $valid = true;
        $vu = $r['adhesion_valid_until'] ?? null;
        if (!empty($vu) && $vu !== '0000-00-00' && $vu !== '0000-00-00 00:00:00') {
            $valid = (substr((string) $vu, 0, 10) >= $today);   // valable jusqu'au soir de la date de fin
        }

        $role = (string) ($r['role'] ?? 'member');
        $members[] = [
            'id'       => (int) $r['id'],
            'name'     => $full !== '' ? $full : ($is_admin ? (string) $r['email'] : 'Membre'),
            'initials' => $ini,
            'email'    => $is_admin ? (string) ($r['email'] ?? '') : '',
            'city'     => $is_admin ? (string) ($r['city'] ?? '') : '',
            'role'     => $role,
            'role_label' => $ROLE_LABELS[$role] ?? ucfirst($role),
            'color'    => function_exists('folder_color_hex') ? folder_color_hex((string) ($r['avatar_color'] ?? 'blue')) : '#3B82F6',
            'up_to_date' => $valid,
        ];
    }

    echo json_encode([
        'ok' => true, 'admin' => $is_admin, 'members' => $members,
        // Ajout d'un adhérent autorisé (mêmes règles que /api/app-create-member.php)
        'can_add' => $can_add,
        'total' => $total,
        'has_more' => $limit ? ($offset + count($members) < $total) : false,
        'next_offset' => ($limit && $offset + count($members) < $total) ? $offset + count($members) : null,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[api/app-members] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'server']);
}
