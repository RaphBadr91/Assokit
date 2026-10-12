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
require_once __DIR__ . '/_app-read-boot.php';   // compte actif garanti : $user, $uid, $org_id
require_once __DIR__ . '/../includes-attendance.php';   // ak_event_visibility_sql()
require_once __DIR__ . '/../includes-grants.php';       // gr_days_between()

/** Le site compose ses libellés en HTML (<strong>…</strong>) : l'app veut du texte. */
function adf_plain(string $html): string
{
    return trim(html_entity_decode(strip_tags($html), ENT_QUOTES, 'UTF-8'));
}

try {
    $role   = (string) ($user['role'] ?? 'member');
    $is_admin = ($role === 'admin') || !empty($user['is_super_admin']);
    $is_coord = $is_admin || ($role === 'coordinator');

    // Suiveur (financeur externe) : uniquement ses projets suivis, comme /projets et /agenda
    // (le site le renvoie d'ailleurs vers /projets au lieu du tableau de bord).
    $fpids = function_exists('get_follower_project_ids') ? get_follower_project_ids()
           : ((($user['role'] ?? '') === 'follower') ? [] : null);
    $is_follower = ($fpids !== null);
    $fol_sql = function (string $col) use ($fpids): string {
        if ($fpids === null) return '';
        return $fpids ? ' AND ' . $col . ' IN (' . implode(',', array_map('intval', $fpids)) . ')' : ' AND 1 = 0';
    };

    /* ── Compteurs de base (parité dashboard.php) ───────────────────────── */
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM projects p JOIN folders f ON p.folder_id = f.id
        WHERE f.org_id = ? AND p.status IN ('active','warning')
          AND p.archived_at IS NULL AND f.archived_at IS NULL" . $fol_sql('p.id') . "
    ");
    $stmt->execute([$org_id]);
    $active_projects = (int) $stmt->fetchColumn();

    // Adhérents : pas pour un suiveur (null = non autorisé, la clé reste présente)
    $total_users = null; $new_users = null;
    if (!$is_follower) {
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
    }

    // Événements à venir : ceux que l'agenda montre à ce compte (api/app-events.php, /agenda) —
    // « Équipe du projet uniquement » réservé à l'équipe du projet, suiveur limité à ses projets.
    $upcoming_events = 0;
    try {
        [$ev_vis_sql, $ev_vis_params] = ak_event_visibility_sql($pdo, $user, 'events');
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM events WHERE org_id = ? AND starts_at >= CURDATE() AND deleted_at IS NULL" . $ev_vis_sql);
        $stmt->execute(array_merge([$org_id], $ev_vis_params));
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
            WHERE f.org_id = ? AND pal.created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)" . $fol_sql('p.id') . "
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
                WHERE f.org_id = ? AND m.created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)" . $fol_sql('p.id') . "
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
    // Un projet terminé porte le statut 'done' en base : il compte dans « Terminés ».
    $status_counts = ['active' => 0, 'warning' => 0, 'completed' => 0, 'paused' => 0];
    try {
        $stmt = $pdo->prepare("
            SELECT p.status, COUNT(*) AS cnt FROM projects p JOIN folders f ON p.folder_id = f.id
            WHERE f.org_id = ? AND p.archived_at IS NULL AND f.archived_at IS NULL" . $fol_sql('p.id') . "
            GROUP BY p.status
        ");
        $stmt->execute([$org_id]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $k = ($r['status'] === 'done') ? 'completed' : (string) $r['status'];
            if (isset($status_counts[$k])) $status_counts[$k] += (int) $r['cnt'];
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

    /* ── Actions à mener (mêmes requêtes que ak_dash_coach_actions du site) ── */
    // Les nombres affichés sont de vrais COUNT : avec un LIMIT, ils plafonnaient (5 ici, 3 sur
    // le site) et les deux écrans ne donnaient pas le même chiffre.
    $actions = [];
    try {
        $from = "
            FROM project_steps ps
            JOIN projects p ON ps.project_id = p.id
            JOIN folders f ON p.folder_id = f.id
            WHERE f.org_id = ? AND ps.assigned_to_user_id = ? AND ps.is_completed = 0
              AND p.archived_at IS NULL AND f.archived_at IS NULL" . $fol_sql('p.id');
        $st = $pdo->prepare("SELECT ps.title, p.id AS pid, p.name AS pname" . $from . " ORDER BY ps.created_at ASC LIMIT 1");
        $st->execute([$org_id, $uid]);
        $first = $st->fetch(PDO::FETCH_ASSOC);
        if ($first) {
            $st = $pdo->prepare("SELECT COUNT(*)" . $from);
            $st->execute([$org_id, $uid]);
            $n = max(1, (int) $st->fetchColumn());
            if ($n === 1) {
                $actions[] = ['tone' => 'warn', 'icon' => 'flag', 'title' => 'Une étape vous attend',
                    'body' => adf_plain($first['title']) . ' — sur ' . adf_plain($first['pname']),
                    'type' => 'project', 'id' => (int) $first['pid']];
            } else {
                $actions[] = ['tone' => 'warn', 'icon' => 'flag', 'title' => $n . ' étapes assignées en attente',
                    'body' => 'Elles vous sont assignées et ne sont pas encore terminées.',
                    'type' => 'project', 'id' => (int) $first['pid']];
            }
        }
    } catch (Throwable $e) {}
    try {
        // « Presque fini » : de 80 à 99 %, comme sur le site
        $from = "
            FROM projects p JOIN folders f ON p.folder_id = f.id
            WHERE f.org_id = ? AND p.status IN ('active','warning')
              AND p.progress_percent >= 80 AND p.progress_percent < 100
              AND p.archived_at IS NULL AND f.archived_at IS NULL" . $fol_sql('p.id');
        $st = $pdo->prepare("SELECT p.id, p.name, p.progress_percent" . $from . " ORDER BY p.progress_percent DESC LIMIT 1");
        $st->execute([$org_id]);
        $first = $st->fetch(PDO::FETCH_ASSOC);
        if ($first) {
            $st = $pdo->prepare("SELECT COUNT(*)" . $from);
            $st->execute([$org_id]);
            $n = max(1, (int) $st->fetchColumn());
            if ($n === 1) {
                $actions[] = ['tone' => 'success', 'icon' => 'rocket', 'title' => 'Un projet à terminer',
                    'body' => adf_plain($first['name']) . ' est à ' . (int) $first['progress_percent'] . ' %. Une dernière poussée.',
                    'type' => 'project', 'id' => (int) $first['id']];
            } else {
                $actions[] = ['tone' => 'success', 'icon' => 'rocket', 'title' => $n . ' projets presque finis',
                    'body' => 'Ils sont à 80 % d’avancement ou plus. Les boucler dégagerait le tableau.',
                    'type' => 'project', 'id' => (int) $first['id']];
            }
        }
    } catch (Throwable $e) {}
    try {
        // Projet au repos : aucune activité (message, étape close, fichier) depuis 14 jours
        $st = $pdo->prepare("
            SELECT p.id, p.name,
                   GREATEST(
                     COALESCE((SELECT MAX(created_at) FROM project_messages WHERE project_id = p.id), '2000-01-01'),
                     COALESCE((SELECT MAX(completed_at) FROM project_steps WHERE project_id = p.id AND completed_at IS NOT NULL), '2000-01-01'),
                     COALESCE((SELECT MAX(created_at) FROM project_files WHERE project_id = p.id), '2000-01-01'),
                     p.created_at
                   ) AS last_activity
            FROM projects p JOIN folders f ON p.folder_id = f.id
            WHERE f.org_id = ? AND p.status IN ('active','warning')
              AND p.archived_at IS NULL AND f.archived_at IS NULL" . $fol_sql('p.id') . "
            HAVING last_activity < DATE_SUB(NOW(), INTERVAL 14 DAY)
            ORDER BY last_activity ASC LIMIT 1
        ");
        $st->execute([$org_id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row && count($actions) < 3) {
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
    // Assemblées : réservées à l'admin, comme la fiche /assemblee et le bloc du site.
    $next_ag = null; $attendance = []; $grants_urgent = [];
    if ($role === 'admin') {
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
            // Dépôt : seul un dossier pas encore déposé a une échéance (gr_days_to_apply(), /subventions)
            $st = $pdo->prepare("
                SELECT id, name, funder, deadline_apply, deadline_report, status FROM grants
                WHERE org_id = ? AND archived_at IS NULL
                  AND ((status = 'draft' AND submitted_at IS NULL AND deadline_apply IS NOT NULL
                        AND DATEDIFF(deadline_apply, CURDATE()) BETWEEN -7 AND 14)
                    OR (status = 'granted' AND deadline_report IS NOT NULL AND reported_at IS NULL
                        AND DATEDIFF(deadline_report, CURDATE()) BETWEEN -7 AND 30))
                ORDER BY COALESCE(deadline_apply, deadline_report) ASC LIMIT 4
            ");
            $st->execute([$org_id]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $g) {
                $is_report = ($g['status'] === 'granted' && !empty($g['deadline_report']));
                $due = $is_report ? $g['deadline_report'] : $g['deadline_apply'];
                // Écart en jours calendaires, juste aussi au passage à l'heure d'été
                $days = (int) (gr_days_between(date('Y-m-d'), (string) $due) ?? 0);
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
              AND p.archived_at IS NULL AND f.archived_at IS NULL" . $fol_sql('p.id') . "
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
