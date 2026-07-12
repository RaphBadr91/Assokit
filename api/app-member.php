<?php
/**
 * api/app-member.php — Detail d'un membre (adherent) pour l'ecran natif.
 * JSON, authentifie par session, scope a l'org du user. NE MODIFIE PAS le site.
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

$ROLE_LABELS = ['admin' => 'Admin', 'coordinator' => 'Coordinateur', 'referent' => 'Référent', 'member' => 'Membre'];

function fdate($v) {
    if (empty($v) || $v === '0000-00-00' || $v === '0000-00-00 00:00:00') return '';
    $ts = strtotime($v);
    return $ts ? date('d/m/Y', $ts) : '';
}

try {
    $user = function_exists('current_user') ? current_user() : null;
    $org_id = (int) ($user['org_id'] ?? ($_SESSION['org_id'] ?? 0));
    $is_admin = (($user['role'] ?? '') === 'admin')
        || !empty($user['is_super_admin']) || !empty($user['is_founder'])
        || (($user['role'] ?? '') === 'super_admin');
    // Un membre peut toujours consulter sa propre fiche complète.
    $self = ((int) ($user['id'] ?? 0) === (int) ($_GET['id'] ?? 0));
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) { http_response_code(400); echo json_encode(['ok' => false, 'error' => 'id']); exit; }

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
        $up_to_date = (strtotime($vu) >= time());
    }

    // Projets dont il est referent
    $projects = [];
    try {
        $st = $pdo->prepare("
            SELECT p.id, p.name, p.progress_percent, p.status, f.name AS folder_name
            FROM projects p JOIN folders f ON p.folder_id = f.id
            WHERE p.referent_id = ? AND f.org_id = ?
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
            'last_login'  => fdate($m['last_login_at'] ?? null),
        ],
        'projects' => $projects,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[api/app-member] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'server']);
}
