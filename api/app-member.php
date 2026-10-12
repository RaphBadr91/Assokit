<?php
/**
 * api/app-member.php — Detail d'un membre (adherent) pour l'ecran natif.
 * JSON, authentifie par session, scope a l'org du user. NE MODIFIE PAS le site.
 */
require_once __DIR__ . '/_app-read-boot.php';   // session, compte actif (sinon 401 'auth'), en-têtes JSON, $user, $uid, $org_id

// Mêmes libellés que le site (role_label())
$ROLE_LABELS = ['admin' => 'Administrateur', 'coordinator' => 'Coordinateur', 'referent' => 'Référent',
                'member' => 'Membre', 'follower' => 'Suiveur', 'super_admin' => 'Super admin'];

function fdate($v) {
    if (empty($v) || $v === '0000-00-00' || $v === '0000-00-00 00:00:00') return '';
    $ts = strtotime($v);
    return $ts ? date('d/m/Y', $ts) : '';
}

try {
    if ($org_id <= 0) $org_id = -1;   // compte sans association (agent de collectivité) : aucune ligne « org 0 », réponses vides
    if (function_exists('ak_platform_flags')) $user = ak_platform_flags($user);
    $role_me = (string) ($user['role'] ?? '');
    // Coordonnées : gestionnaires seulement (admin, coordinateur, droit « Gérer les adhérents »
    // donné par l'admin, fondateur, super-admin), même règle que la fiche /adherent du site.
    $is_admin = in_array($role_me, ['admin', 'coordinator', 'super_admin'], true)
        || ($role_me !== 'follower' && function_exists('can') && can('manage_members'))
        || !empty($user['is_super_admin']) || !empty($user['is_founder']);
    // Un membre peut toujours consulter sa propre fiche complète.
    $self = ((int) ($user['id'] ?? 0) === (int) ($_GET['id'] ?? 0));
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) { http_response_code(400); echo json_encode(['ok' => false, 'error' => 'id']); exit; }
    // Suiveur (financeur externe) : pas d'annuaire, comme sur le site ; seule sa propre fiche.
    if ($role_me === 'follower' && !$self) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'allowed' => false, 'error' => 'forbidden', 'message' => 'Fiche réservée aux membres de l’association.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $stmt = $pdo->prepare("
        SELECT id, email, first_name, last_name, role, phone, city, avatar_color,
               adhesion_date, adhesion_valid_until, is_active, last_login_at
        FROM users
        WHERE id = ? AND org_id = ? AND (deleted_at IS NULL OR deleted_at = '')
        LIMIT 1
    ");
    $stmt->execute([$id, $org_id]);
    $m = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$m) { http_response_code(404); echo json_encode(['ok' => false, 'error' => 'not_found']); exit; }

    $fn = trim((string) ($m['first_name'] ?? ''));
    $ln = trim((string) ($m['last_name'] ?? ''));
    $ini = strtoupper(
        (function_exists('mb_substr') ? mb_substr($fn, 0, 1) : substr($fn, 0, 1))
      . (function_exists('mb_substr') ? mb_substr($ln, 0, 1) : substr($ln, 0, 1))
    );
    if ($ini === '') $ini = '?';

    // Statut cotisation
    $up_to_date = true;
    $vu = $m['adhesion_valid_until'] ?? null;
    if (!empty($vu) && $vu !== '0000-00-00' && $vu !== '0000-00-00 00:00:00') {
        // Valable jusqu'au soir de la date de fin incluse (même règle que les relances).
        $up_to_date = (substr((string) $vu, 0, 10) >= date('Y-m-d'));
    }

    // Projets dont il est referent (hors archives, comme la fiche du site)
    $projects = [];
    try {
        $st = $pdo->prepare("
            SELECT p.id, p.name, p.progress_percent, p.status, f.name AS folder_name
            FROM projects p JOIN folders f ON p.folder_id = f.id
            WHERE p.referent_id = ? AND f.org_id = ?
              AND p.archived_at IS NULL AND f.archived_at IS NULL
            ORDER BY FIELD(p.status,'warning','active','done','archived'), p.progress_percent ASC
            LIMIT 50
        ");
        $st->execute([$id, $org_id]);
        foreach (($st->fetchAll(PDO::FETCH_ASSOC) ?: []) as $p) {
            $projects[] = [
                'id'       => (int) $p['id'],
                'name'     => (string) $p['name'],
                'folder'   => (string) $p['folder_name'],
                'progress' => (int) round((float) $p['progress_percent']),
                'status'   => (string) $p['status'],
            ];
        }
    } catch (Throwable $e) {}

    $role = (string) ($m['role'] ?? 'member');
    $can = $is_admin || $self;
    echo json_encode([
        'ok' => true,
        'admin' => $can,
        'member' => [
            'id'          => (int) $m['id'],
            'name'        => trim($fn . ' ' . $ln) ?: ($can ? (string) $m['email'] : 'Membre'),
            'initials'    => $ini,
            'email'       => $can ? (string) ($m['email'] ?? '') : '',
            'phone'       => $can ? (string) ($m['phone'] ?? '') : '',
            'city'        => $can ? (string) ($m['city'] ?? '') : '',
            'role'        => $role,
            'role_label'  => $ROLE_LABELS[$role] ?? ucfirst($role),
            'color'       => function_exists('folder_color_hex') ? folder_color_hex((string) ($m['avatar_color'] ?? 'blue')) : '#3B82F6',
            'up_to_date'  => $up_to_date,
            'is_active'   => !empty($m['is_active']),
            'adhesion_since' => fdate($m['adhesion_date'] ?? null),
            'adhesion_until' => fdate($vu),
            'last_login'  => $can ? fdate($m['last_login_at'] ?? null) : '',
        ],
        'projects' => $projects,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[api/app-member] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'server']);
}
