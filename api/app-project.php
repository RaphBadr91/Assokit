<?php
/**
 * api/app-project.php — Detail d'un projet pour l'ecran natif de l'app.
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

function ini2(string $a, string $b): string {
    $x = (function_exists('mb_substr') ? mb_substr($a, 0, 1) : substr($a, 0, 1))
       . (function_exists('mb_substr') ? mb_substr($b, 0, 1) : substr($b, 0, 1));
    $x = strtoupper(trim($x));
    return $x !== '' ? $x : '?';
}

try {
    $user = function_exists('current_user') ? current_user() : null;
    $org_id = (int) ($user['org_id'] ?? ($_SESSION['org_id'] ?? 0));
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) { http_response_code(400); echo json_encode(['ok' => false, 'error' => 'id']); exit; }

    // Parité site (projet.php) : un « follower » ne peut ouvrir que ses projets autorisés.
    if (function_exists('follower_can_access_project') && !follower_can_access_project($id)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'forbidden', 'message' => 'Accès refusé à ce projet.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $stmt = $pdo->prepare("
        SELECT p.*, f.name AS folder_name, f.org_id AS folder_org_id,
               u.first_name AS ref_first, u.last_name AS ref_last, u.avatar_color AS ref_color
        FROM projects p
        JOIN folders f ON p.folder_id = f.id
        LEFT JOIN users u ON p.referent_id = u.id
        WHERE p.id = ? LIMIT 1
    ");
    $stmt->execute([$id]);
    $p = $stmt->fetch(PDO::FETCH_ASSOC);

    // Scope strict a l'organisation du user
    if (!$p || (int) $p['folder_org_id'] !== $org_id) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'not_found']);
        exit;
    }

    // Etapes (checklist)
    $steps = [];
    $done = 0;
    try {
        $st = $pdo->prepare("SELECT id, title, description, is_completed FROM project_steps WHERE project_id = ? ORDER BY position ASC, id ASC");
        $st->execute([$id]);
        foreach (($st->fetchAll(PDO::FETCH_ASSOC) ?: []) as $s) {
            $d = !empty($s['is_completed']);
            if ($d) $done++;
            $steps[] = [
                'id'    => (int) $s['id'],
                'title' => (string) ($s['title'] ?? ''),
                'desc'  => (string) ($s['description'] ?? ''),
                'done'  => $d,
            ];
        }
    } catch (Throwable $e) {}
    $total_steps = count($steps);
    $progress = $total_steps > 0 ? (int) round(($done / $total_steps) * 100) : (int) round((float) ($p['progress_percent'] ?? 0));

    // Membres du projet
    $members = [];
    try {
        $st = $pdo->prepare("
            SELECT DISTINCT u.id, u.first_name, u.last_name, u.role, u.avatar_color
            FROM users u
            WHERE u.id IN (SELECT user_id FROM project_members WHERE project_id = :pid)
               OR u.id = :ref
            ORDER BY u.last_name ASC, u.first_name ASC
        ");
        $st->execute([':pid' => $id, ':ref' => (int) ($p['referent_id'] ?? 0)]);
        foreach (($st->fetchAll(PDO::FETCH_ASSOC) ?: []) as $m) {
            $fn = trim((string) ($m['first_name'] ?? ''));
            $ln = trim((string) ($m['last_name'] ?? ''));
            $members[] = [
                'id'       => (int) $m['id'],
                'name'     => trim($fn . ' ' . $ln),
                'initials' => ini2($fn, $ln),
                'role'     => (string) ($m['role'] ?? 'member'),
            ];
        }
    } catch (Throwable $e) {}

    $budget_planned = (float) ($p['budget_planned'] ?? 0);
    $budget_used    = (float) ($p['budget_used'] ?? 0);
    $budget_pct     = $budget_planned > 0 ? min(100, (int) round(($budget_used / $budget_planned) * 100)) : 0;

    $ref_first = trim((string) ($p['ref_first'] ?? ''));
    $ref_last  = trim((string) ($p['ref_last'] ?? ''));
    $referent  = ($ref_first !== '' || $ref_last !== '')
        ? ['name' => trim($ref_first . ' ' . $ref_last), 'initials' => ini2($ref_first, $ref_last)]
        : null;

    echo json_encode([
        'ok' => true,
        'project' => [
            'id'             => (int) $p['id'],
            'name'           => (string) ($p['name'] ?? ''),
            'folder'         => (string) ($p['folder_name'] ?? ''),
            'status'         => (string) ($p['status'] ?? 'active'),
            'progress'       => $progress,
            'description'    => (string) ($p['description'] ?? ''),
            'objective'      => (string) ($p['objective'] ?? ''),
            'location'       => (string) ($p['location'] ?? ''),
            'budget_used'    => $budget_used,
            'budget_planned' => $budget_planned,
            'budget_pct'     => $budget_pct,
            'referent'       => $referent,
            'steps_done'     => $done,
            'steps_total'    => $total_steps,
        ],
        'steps'   => $steps,
        'members' => $members,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[api/app-project] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'server']);
}
