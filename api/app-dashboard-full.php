<?php
/**
 * api/app-dashboard-full.php — Tableau de bord COMPLET pour l'écran natif.
 *
 * Reprend les calculs de dashboard.php (cockpit du site) pour que l'app n'ait
 * plus jamais besoin d'ouvrir la page web : mêmes requêtes, mêmes seuils, même
 * score de santé. Le texte est renvoyé en clair (le site l'envoie en HTML).
 *
 * NE MODIFIE PAS le site : fichier dédié à l'application.
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

/** Le site compose ses libellés en HTML (<strong>…</strong>) : l'app veut du texte. */
function adf_plain(string $html): string
{
    return trim(html_entity_decode(strip_tags($html), ENT_QUOTES, 'UTF-8'));
}

try {
    $user = function_exists('current_user') ? current_user() : null;
    $org_id = (int) ($user['org_id'] ?? ($_SESSION['org_id'] ?? 0));
    $uid    = (int) ($user['id'] ?? 0);
    $role   = (string) ($user['role'] ?? 'member');
    $is_admin = ($role === 'admin') || !empty($user['is_super_admin']);
    $is_coord = $is_admin || ($role === 'coordinator');

    /* ── Compteurs de base (parité dashboard.php) ───────────────────────── */
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM projects p JOIN folders f ON p.folder_id = f.id
        WHERE f.org_id = ? AND p.status IN ('active','warning')
          AND p.archived_at IS NULL AND f.archived_at IS NULL
    ");
    $stmt->execute([$org_id]);
    $active_projects = (int) $stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE org_id = ? AND is_active = 1 AND (deleted_at IS NULL OR deleted_at = '')");
    $stmt->execute([$org_id]);
    $total_users = (int) $stmt->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM users WHERE org_id = ? AND is_active = 1
          AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
          AND (deleted_at IS NULL OR deleted_at = '')
    ");
    $stmt->execute([$org_id]);
    $new_users = (int) $stmt->fetchColumn();

    $upcoming_events = 0;
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM events WHERE org_id = ? AND starts_at >= CURDATE() AND deleted_at IS NULL");
        $stmt->execute([$org_id]);
        $upcoming_events = (int) $stmt->fetchColumn();
    } catch (Throwable $e) {}

    /* ── Activité sur 30 jours (courbe) ─────────────────────────────────── */
    $activity = array_fill(0, 30, 0);
    $dates = [];
    for ($i = 29; $i >= 0; $i--) $dates[] = date('Y-m-d', strtotime("-$i days"));
    $filled = false;
    try {
        $stmt = $pdo->prepare("
            SELECT DATE(pal.created_at) AS day, COUNT(*) AS cnt
            FROM project_activity_log pal
            JOIN projects p ON pal.project_id = p.id
            JOIN folders f ON p.folder_id = f.id
            WHERE f.org_id = ? AND pal.created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
            GROUP BY DATE(pal.created_at)
        ");
        $stmt->execute([$org_id]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $idx = array_search($r['day'], $dates, true);
            if ($idx !== false) { $activity[$idx] = (int) $r['cnt']; $filled = true; }
        }
    } catch (Throwable $e) {}
    if (!$filled) {
        // Même repli que le site : les messages de projet à défaut du journal.
        try {
            $stmt = $pdo->prepare("
                SELECT DATE(m.created_at) AS day, COUNT(*) AS cnt
                FROM project_messages m
                JOIN projects p ON m.project_id = p.id
                JOIN folders f ON p.folder_id = f.id
                WHERE f.org_id = ? AND m.created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                GROUP BY DATE(m.created_at)
            ");
            $stmt->execute([$org_id]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
                $idx = array_search($r['day'], $dates, true);
                if ($idx !== false) $activity[$idx] = (int) $r['cnt'];
            }
        } catch (Throwable $e2) {}
    }
    $total_activity = array_sum($activity);

    /* ── Répartition par statut (anneau) ────────────────────────────────── */
    $status_counts = ['active' => 0, 'warning' => 0, 'completed' => 0, 'paused' => 0];
    try {
        $stmt = $pdo->prepare("
            SELECT p.status, COUNT(*) AS cnt FROM projects p JOIN folders f ON p.folder_id = f.id
            WHERE f.org_id = ? AND p.archived_at IS NULL AND f.archived_at IS NULL
            GROUP BY p.status
        ");
        $stmt->execute([$org_id]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            if (isset($status_counts[$r['status']])) $status_counts[$r['status']] = (int) $r['cnt'];
        }
    } catch (Throwable $e) {}

    /* ── Score de santé (formule identique au site) ─────────────────────── */
    $completed = $status_counts['completed'];
    $health = 55;
    $health += min(20, (int) round($total_activity * 1.6));
    $health += ($upcoming_events > 0) ? 8 : 0;
    $health += ($active_projects > 0) ? 8 : 0;
    $health += ($new_users > 0) ? 5 : 0;
    $health += min(4, $completed);
    $health = max(0, min(100, $health));
    $health_label = $health >= 85 ? 'Excellent' : ($health >= 70 ? 'Bon' : ($health >= 55 ? 'Correct' : 'À surveiller'));

    /* ── Actions à mener ────────────────────────────────────────────────── */
    $actions = [];
    try {
        $st = $pdo->prepare("
            SELECT ps.title, p.id AS pid, p.name AS pname
            FROM project_steps ps
            JOIN projects p ON ps.project_id = p.id
            JOIN folders f ON p.folder_id = f.id
            WHERE f.org_id = ? AND ps.assigned_to_user_id = ? AND ps.is_completed = 0
              AND p.archived_at IS NULL AND f.archived_at IS NULL
            ORDER BY ps.position ASC LIMIT 5
        ");
        $st->execute([$org_id, $uid]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if (count($rows) === 1) {
            $actions[] = ['tone' => 'warn', 'icon' => 'flag', 'title' => 'Une étape vous attend',
                'body' => adf_plain($rows[0]['title']) . ' — sur ' . adf_plain($rows[0]['pname']),
                'type' => 'project', 'id' => (int) $rows[0]['pid']];
        } elseif (count($rows) > 1) {
            $actions[] = ['tone' => 'warn', 'icon' => 'flag', 'title' => count($rows) . ' étapes assignées en attente',
                'body' => 'Elles vous sont assignées et ne sont pas encore terminées.',
                'type' => 'project', 'id' => (int) $rows[0]['pid']];
        }
    } catch (Throwable $e) {}
    try {
        $st = $pdo->prepare("
            SELECT p.id, p.name, p.progress_percent FROM projects p JOIN folders f ON p.folder_id = f.id
            WHERE f.org_id = ? AND p.status IN ('active','warning') AND p.progress_percent >= 75
              AND p.archived_at IS NULL AND f.archived_at IS NULL
            ORDER BY p.progress_percent DESC LIMIT 5
        ");
        $st->execute([$org_id]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if (count($rows) === 1) {
            $actions[] = ['tone' => 'success', 'icon' => 'rocket', 'title' => 'Un projet à terminer',
                'body' => adf_plain($rows[0]['name']) . ' est à ' . (int) $rows[0]['progress_percent'] . ' %. Une dernière poussée.',
                'type' => 'project', 'id' => (int) $rows[0]['id']];
        } elseif (count($rows) > 1) {
            $actions[] = ['tone' => 'success', 'icon' => 'rocket', 'title' => count($rows) . ' projets presque finis',
                'body' => 'Ils dépassent 75 % d’avancement. Les boucler dégagerait le tableau.',
                'type' => 'project', 'id' => (int) $rows[0]['id']];
        }
    } catch (Throwable $e) {}
    try {
        $st = $pdo->prepare("
            SELECT p.id, p.name FROM projects p JOIN folders f ON p.folder_id = f.id
            WHERE f.org_id = ? AND p.status IN ('active','warning')
              AND p.updated_at < DATE_SUB(NOW(), INTERVAL 14 DAY)
              AND p.archived_at IS NULL AND f.archived_at IS NULL
            ORDER BY p.updated_at ASC LIMIT 1
        ");
        $st->execute([$org_id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $actions[] = ['tone' => 'info', 'icon' => 'moon', 'title' => 'Un projet au repos',
                'body' => adf_plain($row['name']) . ' n’a plus bougé depuis 14 jours.',
                'type' => 'project', 'id' => (int) $row['id']];
        }
    } catch (Throwable $e) {}
    if (!$actions) {
        $actions[] = ['tone' => 'info', 'icon' => 'sparkles', 'title' => 'Tout est sous contrôle',
            'body' => 'Aucune urgence détectée. Le moment de planifier la suite.', 'type' => null, 'id' => 0];
    }

    /* ── Échéances associatives (AG, émargement, subventions) ───────────── */
    $next_ag = null; $attendance = []; $grants_urgent = [];
    if ($is_coord) {
        try {
            $st = $pdo->prepare("
                SELECT id, title, scheduled_at, location FROM assemblies
                WHERE org_id = ? AND archived_at IS NULL AND status IN ('draft','sent','in_progress')
                  AND scheduled_at >= NOW() ORDER BY scheduled_at ASC LIMIT 1
            ");
            $st->execute([$org_id]);
            $a = $st->fetch(PDO::FETCH_ASSOC);
            if ($a) {
                $ts = strtotime((string) $a['scheduled_at']);
                $next_ag = [
                    'id' => (int) $a['id'], 'title' => (string) $a['title'],
                    'when' => date('d/m/Y', $ts) . ' à ' . date('H:i', $ts),
                    'location' => (string) ($a['location'] ?? ''),
                    'days' => (int) floor(($ts - strtotime('today')) / 86400),
                ];
            }
        } catch (Throwable $e) {}
    }
    if ($is_admin) {
        try {
            $st = $pdo->prepare("
                SELECT id, name, funder, deadline_apply, deadline_report, status FROM grants
                WHERE org_id = ? AND archived_at IS NULL
                  AND ((status IN ('draft','submitted','in_review') AND deadline_apply IS NOT NULL
                        AND DATEDIFF(deadline_apply, CURDATE()) BETWEEN -7 AND 14)
                    OR (status = 'granted' AND deadline_report IS NOT NULL AND reported_at IS NULL
                        AND DATEDIFF(deadline_report, CURDATE()) BETWEEN -7 AND 30))
                ORDER BY COALESCE(deadline_apply, deadline_report) ASC LIMIT 4
            ");
            $st->execute([$org_id]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $g) {
                $is_report = ($g['status'] === 'granted' && !empty($g['deadline_report']));
                $due = $is_report ? $g['deadline_report'] : $g['deadline_apply'];
                $days = (int) floor((strtotime((string) $due) - strtotime('today')) / 86400);
                $grants_urgent[] = [
                    'id' => (int) $g['id'], 'name' => (string) $g['name'],
                    'funder' => (string) ($g['funder'] ?? ''),
                    'what' => $is_report ? 'Bilan à rendre' : 'Dossier à déposer',
                    'due' => date('d/m/Y', strtotime((string) $due)), 'days' => $days,
                ];
            }
        } catch (Throwable $e) {}
    }

    /* ── Projets en cours ───────────────────────────────────────────────── */
    $projects = [];
    try {
        $st = $pdo->prepare("
            SELECT p.id, p.name, p.progress_percent, p.status, f.name AS folder_name
            FROM projects p JOIN folders f ON p.folder_id = f.id
            WHERE f.org_id = ? AND p.status IN ('active','warning')
              AND p.archived_at IS NULL AND f.archived_at IS NULL
            ORDER BY p.status = 'warning' DESC, p.updated_at DESC LIMIT 6
        ");
        $st->execute([$org_id]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $p) {
            $projects[] = [
                'id' => (int) $p['id'], 'name' => (string) $p['name'],
                'folder' => (string) ($p['folder_name'] ?? ''),
                'status' => (string) $p['status'],
                'progress' => (int) ($p['progress_percent'] ?? 0),
            ];
        }
    } catch (Throwable $e) {}

    echo json_encode([
        'ok'      => true,
        'kpis'    => [
            'active_projects' => $active_projects,
            'members'         => $total_users,
            'new_members'     => $new_users,
            'events'          => $upcoming_events,
            'health'          => $health,
            'health_label'    => $health_label,
        ],
        'activity'      => ['days' => array_values($activity), 'total' => $total_activity],
        'status_counts' => $status_counts,
        'actions'       => $actions,
        'next_ag'       => $next_ag,
        'grants_urgent' => $grants_urgent,
        'projects'      => $projects,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[api/app-dashboard-full] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'server']);
}
