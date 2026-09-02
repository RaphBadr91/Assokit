<?php
/**
 * ============================================================
 * ASSOKIT — Dashboard Premium (cockpit) — v5
 * ============================================================
 * v5 : Refonte complète style cockpit Stripe/Notion
 *      - Hero header avec salutation + heure
 *      - 4 KPI cards animées (compteurs 0→valeur)
 *      - Graphique d'activité 30 jours (line chart SVG)
 *      - Donut chart répartition projets par statut
 *      - Quick actions grid (avec NOUVEAU DOSSIER 🎯)
 *      - Activity feed récent
 * 
 * Conserve toutes les fonctionnalités v4 :
 *      - Aujourd'hui (Copilote IA)
 *      - Guide onboarding
 *      - Widget relances factures
 *      - Mes projets en cours
 *      - Dossiers
 * ============================================================
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes-layout.php';

// Widget notifications de relances factures
if (file_exists(__DIR__ . '/asso-invoice-notifications-widget.php')) {
    require_once __DIR__ . '/asso-invoice-notifications-widget.php';
}

require_login();

$user = current_user();

// Permissions finances
$role = $user['role'] ?? '';
$can_view_finances = (
    in_array($role, ['admin', 'founder', 'super_admin'], true)
    || !empty($user['is_founder'])
    || !empty($user['is_super_admin'])
);

$org_id = (int)$user['org_id'];
$user_id = (int)$user['id'];

if (is_follower()) {
    header('Location: /projets');
    exit;
}

// ====== Logo de la structure (carte admin sur l'accueil) ======
// Visible + modifiable uniquement par les admins de l'asso.
$logo_admin = ($role === 'admin');
$org_logo = null;
$org_logo_at = null;
if ($logo_admin) {
    try {
        $stmt = $pdo->prepare("SELECT logo_path, logo_uploaded_at FROM organizations WHERE id = :id");
        $stmt->execute([':id' => $org_id]);
        if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $org_logo = $row['logo_path'] ?: null;
            $org_logo_at = $row['logo_uploaded_at'] ?: null;
        }
    } catch (Throwable $e) {}
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
}
$logo_flash = $_SESSION['flash_asso_logo'] ?? null;
unset($_SESSION['flash_asso_logo']);

// ====== Salutation contextuelle selon l'heure ======
$hour = (int)date('H');
if ($hour < 6) $greeting = 'Vous êtes matinal';
elseif ($hour < 12) $greeting = 'Bonjour';
elseif ($hour < 14) $greeting = 'Bon midi';
elseif ($hour < 18) $greeting = 'Bel après-midi';
elseif ($hour < 22) $greeting = 'Bonne soirée';
else $greeting = 'Bonne nuit';

// Date française formatée
$days = ['Dimanche', 'Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi'];
$months = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
$today_fr = $days[(int)date('w')] . ' ' . (int)date('j') . ' ' . $months[(int)date('n') - 1];

// ====== DONNÉES : Mes projets en cours ======
$stmt = $pdo->prepare("
    SELECT
        p.id, p.name, p.progress_percent, p.status,
        p.budget_planned, p.budget_used,
        f.name AS folder_name, f.color_theme
    FROM projects p
    JOIN folders f ON p.folder_id = f.id
    WHERE p.referent_id = ?
      AND p.status IN ('active', 'warning')
      AND p.archived_at IS NULL
      AND f.archived_at IS NULL
    ORDER BY p.status = 'warning' DESC, p.updated_at DESC
    LIMIT 6
");
$stmt->execute([$user_id]);
$my_projects = $stmt->fetchAll();

// ====== DONNÉES : Dossiers ======
$stmt = $pdo->prepare("
    SELECT
        f.id, f.name, f.color_theme,
        COUNT(p.id) AS project_count,
        COALESCE(AVG(p.progress_percent), 0) AS avg_progress,
        COALESCE(SUM(p.budget_planned), 0) AS total_budget,
        COALESCE(SUM(p.participants_count), 0) AS total_participants
    FROM folders f
    LEFT JOIN projects p ON p.folder_id = f.id 
        AND p.status IN ('active', 'warning')
        AND p.archived_at IS NULL
    WHERE f.org_id = ?
      AND f.archived_at IS NULL
    GROUP BY f.id, f.name, f.color_theme
    ORDER BY f.id ASC
");
$stmt->execute([$org_id]);
$folders = $stmt->fetchAll();

// ====== DONNÉES : Métriques globales ======
$stmt = $pdo->prepare("
    SELECT COUNT(*) FROM projects p
    JOIN folders f ON p.folder_id = f.id
    WHERE f.org_id = ? 
      AND p.status IN ('active','warning')
      AND p.archived_at IS NULL
      AND f.archived_at IS NULL
");
$stmt->execute([$org_id]);
$active_projects = (int) $stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE org_id = ? AND (deleted_at IS NULL OR deleted_at = '') AND is_active = 1");
$stmt->execute([$org_id]);
$total_users = (int) $stmt->fetchColumn();

$stmt = $pdo->prepare("
    SELECT
        COALESCE(SUM(p.budget_used), 0) AS used,
        COALESCE(SUM(p.budget_planned), 0) AS planned
    FROM projects p
    JOIN folders f ON p.folder_id = f.id
    WHERE f.org_id = ? 
      AND p.status IN ('active','warning')
      AND p.archived_at IS NULL
      AND f.archived_at IS NULL
");
$stmt->execute([$org_id]);
$row = $stmt->fetch();
$budget_used = (float)$row['used'];
$budget_planned = (float)$row['planned'];

$stmt = $pdo->prepare("
    SELECT COUNT(*) FROM users
    WHERE org_id = ? 
      AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
      AND is_active = 1
      AND (deleted_at IS NULL OR deleted_at = '')
");
$stmt->execute([$org_id]);
$new_users = (int) $stmt->fetchColumn();

// ====== DONNÉES NOUVELLES POUR LES GRAPHIQUES ======

// 1) Activité 30 derniers jours (line chart) — depuis project_activity_log
$activity_data = array_fill(0, 30, 0);
$activity_dates = [];
for ($i = 29; $i >= 0; $i--) {
    $activity_dates[] = date('Y-m-d', strtotime("-$i days"));
}
try {
    $stmt = $pdo->prepare("
        SELECT DATE(pal.created_at) AS day, COUNT(*) AS cnt
        FROM project_activity_log pal
        JOIN projects p ON pal.project_id = p.id
        JOIN folders f ON p.folder_id = f.id
        WHERE f.org_id = ?
          AND pal.created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
        GROUP BY DATE(pal.created_at)
        ORDER BY day ASC
    ");
    $stmt->execute([$org_id]);
    foreach ($stmt->fetchAll() as $row) {
        $idx = array_search($row['day'], $activity_dates, true);
        if ($idx !== false) $activity_data[$idx] = (int)$row['cnt'];
    }
} catch (Throwable $e) {
    // Fallback : utiliser les messages projet
    try {
        $stmt = $pdo->prepare("
            SELECT DATE(m.created_at) AS day, COUNT(*) AS cnt
            FROM project_messages m
            JOIN projects p ON m.project_id = p.id
            JOIN folders f ON p.folder_id = f.id
            WHERE f.org_id = ?
              AND m.created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
            GROUP BY DATE(m.created_at)
        ");
        $stmt->execute([$org_id]);
        foreach ($stmt->fetchAll() as $row) {
            $idx = array_search($row['day'], $activity_dates, true);
            if ($idx !== false) $activity_data[$idx] = (int)$row['cnt'];
        }
    } catch (Throwable $e2) { /* skip */ }
}
$total_activity = array_sum($activity_data);
$max_activity = max($activity_data) ?: 1;

// 2) Donut chart : répartition projets par statut
$status_counts = ['active' => 0, 'warning' => 0, 'completed' => 0, 'paused' => 0];
try {
    $stmt = $pdo->prepare("
        SELECT p.status, COUNT(*) AS cnt
        FROM projects p
        JOIN folders f ON p.folder_id = f.id
        WHERE f.org_id = ? AND p.archived_at IS NULL AND f.archived_at IS NULL
        GROUP BY p.status
    ");
    $stmt->execute([$org_id]);
    foreach ($stmt->fetchAll() as $row) {
        if (isset($status_counts[$row['status']])) {
            $status_counts[$row['status']] = (int)$row['cnt'];
        }
    }
} catch (Throwable $e) {}
$status_total = array_sum($status_counts);

// 3) Activity feed (8 dernières actions)
$activity_feed = [];
try {
    $stmt = $pdo->prepare("
        SELECT pal.id, pal.action_type, pal.created_at,
               p.id AS project_id, p.name AS project_name,
               u.first_name, u.last_name, u.avatar_color
        FROM project_activity_log pal
        JOIN projects p ON pal.project_id = p.id
        JOIN folders f ON p.folder_id = f.id
        LEFT JOIN users u ON pal.user_id = u.id
        WHERE f.org_id = ?
        ORDER BY pal.created_at DESC
        LIMIT 8
    ");
    $stmt->execute([$org_id]);
    $activity_feed = $stmt->fetchAll();
} catch (Throwable $e) {
    try {
        $stmt = $pdo->prepare("
            SELECT m.id, m.created_at,
                   p.id AS project_id, p.name AS project_name,
                   u.first_name, u.last_name, u.avatar_color
            FROM project_messages m
            JOIN projects p ON m.project_id = p.id
            JOIN folders f ON p.folder_id = f.id
            JOIN users u ON m.author_id = u.id
            WHERE f.org_id = ? AND m.message_type = 'text'
            ORDER BY m.created_at DESC
            LIMIT 8
        ");
        $stmt->execute([$org_id]);
        $activity_feed = $stmt->fetchAll();
    } catch (Throwable $e2) { $activity_feed = []; }
}

// 4) Événements à venir
$upcoming_events_count = 0;
try {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM events
        WHERE org_id = ? AND starts_at >= CURDATE() AND deleted_at IS NULL
    ");
    $stmt->execute([$org_id]);
    $upcoming_events_count = (int)$stmt->fetchColumn();
} catch (Throwable $e) {}

// Helper : format relatif de temps
function ak_dash_time_ago(string $datetime): string {
    try {
        $dt = new DateTime($datetime);
        $now = new DateTime();
        $diff = $now->getTimestamp() - $dt->getTimestamp();
        if ($diff < 60) return "à l'instant";
        if ($diff < 3600) return "il y a " . floor($diff / 60) . " min";
        if ($diff < 86400) return "il y a " . floor($diff / 3600) . " h";
        if ($diff < 604800) return "il y a " . floor($diff / 86400) . " j";
        return $dt->format('d/m');
    } catch (Throwable $e) {
        return $datetime;
    }
}

// ============================================================
// 🤖 PACK ESSENTIEL IA v3 — Coach + Top Movers + Échéances
// ============================================================

/**
 * Top 3 projets avec la plus grosse accélération (7j vs 7j précédents).
 * Renvoie : [['id','name','folder_name','recent','previous','trend','spark'(14d)], ...]
 */
function ak_dash_top_movers(PDO $pdo, int $org_id, ?array $follower_ids): array {
    try {
        // Activité unifiée par project_id sur 14 jours
        $sql = "
            SELECT a.project_id, a.day_offset, COUNT(*) AS cnt FROM (
                SELECT pm.project_id, DATEDIFF(CURDATE(), DATE(pm.created_at)) AS day_offset
                FROM project_messages pm
                JOIN projects p ON p.id = pm.project_id
                JOIN folders f ON f.id = p.folder_id
                WHERE f.org_id = ? AND f.archived_at IS NULL AND p.archived_at IS NULL
                  AND pm.created_at >= DATE_SUB(CURDATE(), INTERVAL 14 DAY)
                UNION ALL
                SELECT ps.project_id, DATEDIFF(CURDATE(), DATE(ps.completed_at)) AS day_offset
                FROM project_steps ps
                JOIN projects p ON p.id = ps.project_id
                JOIN folders f ON f.id = p.folder_id
                WHERE f.org_id = ? AND f.archived_at IS NULL AND p.archived_at IS NULL
                  AND ps.completed_at IS NOT NULL
                  AND ps.completed_at >= DATE_SUB(CURDATE(), INTERVAL 14 DAY)
                UNION ALL
                SELECT pf.project_id, DATEDIFF(CURDATE(), DATE(pf.created_at)) AS day_offset
                FROM project_files pf
                JOIN projects p ON p.id = pf.project_id
                JOIN folders f ON f.id = p.folder_id
                WHERE f.org_id = ? AND f.archived_at IS NULL AND p.archived_at IS NULL
                  AND pf.created_at >= DATE_SUB(CURDATE(), INTERVAL 14 DAY)
            ) a
            WHERE a.day_offset BETWEEN 0 AND 13
            GROUP BY a.project_id, a.day_offset
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$org_id, $org_id, $org_id]);
        $rows = $stmt->fetchAll();

        // Bucket par projet
        $by_project = [];
        foreach ($rows as $r) {
            $pid = (int)$r['project_id'];
            if (!isset($by_project[$pid])) $by_project[$pid] = array_fill(0, 14, 0);
            $by_project[$pid][(int)$r['day_offset']] = (int)$r['cnt'];
        }

        if (empty($by_project)) return [];

        // Filtre follower
        if ($follower_ids !== null) {
            $by_project = array_intersect_key($by_project, array_flip($follower_ids));
            if (empty($by_project)) return [];
        }

        // Calcul trend
        $scored = [];
        foreach ($by_project as $pid => $days) {
            $rec = array_sum(array_slice($days, 0, 7));
            $prev = array_sum(array_slice($days, 7, 7));
            if ($rec < 2) continue; // ignore les projets quasi-inactifs
            $trend = $prev > 0 ? round((($rec - $prev) / $prev) * 100) : 100;
            $scored[] = ['id' => $pid, 'recent' => $rec, 'previous' => $prev, 'trend' => $trend, 'spark' => $days];
        }

        // Tri : trend desc, puis recent desc
        usort($scored, function($a, $b) {
            if ($a['trend'] === $b['trend']) return $b['recent'] - $a['recent'];
            return $b['trend'] - $a['trend'];
        });
        $top = array_slice($scored, 0, 3);
        if (empty($top)) return [];

        // Récupère les noms
        $ids = array_column($top, 'id');
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare("SELECT p.id, p.name, p.progress_percent, p.status, f.name AS folder_name FROM projects p LEFT JOIN folders f ON f.id = p.folder_id WHERE p.id IN ($ph)");
        $stmt->execute($ids);
        $infos = [];
        foreach ($stmt->fetchAll() as $r) $infos[(int)$r['id']] = $r;

        $out = [];
        foreach ($top as $t) {
            if (!isset($infos[$t['id']])) continue;
            $out[] = array_merge($t, $infos[$t['id']]);
        }
        return $out;
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Génère 3-5 suggestions d'action concrètes pour le coach IA.
 * Priorité : étapes assignées en attente > projets quasi-finis > projets dormants > encouragement.
 */
function ak_dash_coach_actions(PDO $pdo, int $user_id, int $org_id, ?array $follower_ids): array {
    $actions = [];
    $project_filter_sql = '';
    $extra_params = [];
    if ($follower_ids !== null) {
        if (empty($follower_ids)) return [['icon' => '🌱', 'tone' => 'info', 'title' => 'Bienvenue', 'body' => 'Aucun projet à suivre pour l\'instant. Tes coordinateurs vont t\'associer dès qu\'un projet est prêt.', 'link' => null]];
        $ph = implode(',', array_fill(0, count($follower_ids), '?'));
        $project_filter_sql = " AND p.id IN ($ph) ";
        $extra_params = $follower_ids;
    }

    // 1) Étapes assignées à moi, non complétées (les + anciennes en premier)
    try {
        $sql = "SELECT ps.id, ps.title, p.id AS pid, p.name AS pname
                FROM project_steps ps
                JOIN projects p ON p.id = ps.project_id
                JOIN folders f ON f.id = p.folder_id
                WHERE f.org_id = ? AND f.archived_at IS NULL AND p.archived_at IS NULL
                  AND ps.assigned_to_user_id = ? AND ps.is_completed = 0
                  $project_filter_sql
                ORDER BY ps.created_at ASC LIMIT 3";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_merge([$org_id, $user_id], $extra_params));
        $rows = $stmt->fetchAll();
        if (!empty($rows)) {
            $n = count($rows);
            if ($n === 1) {
                $r = $rows[0];
                $actions[] = ['icon' => '🎯', 'tone' => 'warn', 'title' => 'Une étape t\'attend', 'body' => 'Étape <strong>' . htmlspecialchars($r['title']) . '</strong> sur <strong>' . htmlspecialchars($r['pname']) . '</strong> — prête à clore ?', 'link' => '/projet/' . (int)$r['pid']];
            } else {
                $actions[] = ['icon' => '🎯', 'tone' => 'warn', 'title' => $n . ' étapes assignées en attente', 'body' => 'Tu as <strong>' . $n . ' étapes</strong> à toi qui attendent d\'être complétées. Un petit coup de collier ?', 'link' => '/projet/' . (int)$rows[0]['pid']];
            }
        }
    } catch (Throwable $e) {}

    // 2) Projets ≥ 80% (presque finis) sur l'org
    try {
        $sql = "SELECT p.id, p.name, p.progress_percent
                FROM projects p
                JOIN folders f ON f.id = p.folder_id
                WHERE f.org_id = ? AND f.archived_at IS NULL AND p.archived_at IS NULL
                  AND p.status IN ('active','warning')
                  AND p.progress_percent >= 80 AND p.progress_percent < 100
                  $project_filter_sql
                ORDER BY p.progress_percent DESC LIMIT 3";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_merge([$org_id], $extra_params));
        $rows = $stmt->fetchAll();
        if (!empty($rows)) {
            $n = count($rows);
            $first = $rows[0];
            if ($n === 1) {
                $actions[] = ['icon' => '🚀', 'tone' => 'success', 'title' => 'Un projet à terminer', 'body' => '<strong>' . htmlspecialchars($first['name']) . '</strong> est à <strong>' . (int)$first['progress_percent'] . '%</strong>. Une dernière poussée et c\'est plié !', 'link' => '/projet/' . (int)$first['id']];
            } else {
                $actions[] = ['icon' => '🚀', 'tone' => 'success', 'title' => $n . ' projets presque finis', 'body' => '<strong>' . $n . ' projets</strong> sont à 80%+ d\'avancement. Concentre-toi dessus pour les boucler.', 'link' => '/projets'];
            }
        }
    } catch (Throwable $e) {}

    // 3) Projets dormants (aucune activité depuis 14j)
    try {
        $sql = "SELECT p.id, p.name,
                       GREATEST(
                         COALESCE((SELECT MAX(created_at) FROM project_messages WHERE project_id = p.id), '2000-01-01'),
                         COALESCE((SELECT MAX(completed_at) FROM project_steps WHERE project_id = p.id AND completed_at IS NOT NULL), '2000-01-01'),
                         COALESCE((SELECT MAX(created_at) FROM project_files WHERE project_id = p.id), '2000-01-01'),
                         p.created_at
                       ) AS last_activity
                FROM projects p
                JOIN folders f ON f.id = p.folder_id
                WHERE f.org_id = ? AND f.archived_at IS NULL AND p.archived_at IS NULL
                  AND p.status IN ('active','warning')
                  $project_filter_sql
                HAVING last_activity < DATE_SUB(NOW(), INTERVAL 14 DAY)
                ORDER BY last_activity ASC LIMIT 1";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_merge([$org_id], $extra_params));
        $row = $stmt->fetch();
        if ($row && count($actions) < 3) {
            $actions[] = ['icon' => '💤', 'tone' => 'info', 'title' => 'Un projet au repos', 'body' => '<strong>' . htmlspecialchars($row['name']) . '</strong> n\'a plus bougé depuis 14 jours. Un message à l\'équipe pour relancer ?', 'link' => '/projet/' . (int)$row['id']];
        }
    } catch (Throwable $e) {}

    // 4) Toujours au moins une action positive si rien d'urgent
    if (count($actions) < 3) {
        $actions[] = ['icon' => '✨', 'tone' => 'info', 'title' => 'Tout est sous contrôle', 'body' => 'Pas d\'urgence détectée. Profites-en pour planifier les prochaines étapes ou créer un nouveau projet.', 'link' => '/nouveau-projet'];
    }

    return array_slice($actions, 0, 3);
}

/**
 * Échéances 30 prochains jours : events de l'org + projets à 75%+ (signaux de fin proche).
 * Renvoie : [['date','label','tone','link','icon'], ...] triées par date asc, max 5.
 */
function ak_dash_upcoming_deadlines(PDO $pdo, int $org_id, ?array $follower_ids): array {
    $items = [];

    // Palette calendrier (couleurs Google) — pour donner à chaque événement sa vraie couleur
    $GPAL = ['#7986CB', '#33B679', '#8E24AA', '#E67C73', '#F6BF26', '#039BE5', '#3F51B5', '#0B8043', '#D50000', '#F4511E'];
    $evt_color = function ($title, $color_theme, $sync_origin) use ($GPAL) {
        $ct = trim((string) $color_theme);
        if ($ct !== '' && $ct[0] === '#') return $ct;               // couleur Google explicite
        $b = function_exists('mb_strtolower') ? mb_strtolower(trim((string) $title)) : strtolower(trim((string) $title));
        if (($sync_origin ?? '') === 'google') {
            return $b === '' ? $GPAL[0] : $GPAL[abs(crc32($b)) % count($GPAL)];
        }
        if ($ct !== '' && function_exists('folder_color_hex')) return folder_color_hex($ct);
        // fallback : couleur stable dérivée du titre (aspect calendrier coloré)
        return $b === '' ? $GPAL[0] : $GPAL[abs(crc32($b)) % count($GPAL)];
    };

    // Source 1 : table events (si elle existe)
    try {
        $sql = "SELECT id, title, starts_at, ends_at, is_all_day, color_theme, sync_origin FROM events
                WHERE org_id = ? AND deleted_at IS NULL
                  AND starts_at >= NOW() AND starts_at <= DATE_ADD(NOW(), INTERVAL 7 DAY)
                ORDER BY starts_at ASC LIMIT 20";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$org_id]);
        $seen_keys = [];
        $event_rows = $stmt->fetchAll();
        // Groupe events identiques sur jours consécutifs (titre + heure égaux)
        $grouped = [];
        foreach ($event_rows as $e) {
            $start_d = substr($e['starts_at'], 0, 10);
            $end_d = substr($e['ends_at'], 0, 10);
            $is_multi = ($start_d !== $end_d);
            $key = $e['title'] . '|' . substr($e['starts_at'], 11, 5);
            if (isset($grouped[$key])) {
                // Étend la plage si jour consécutif
                $prev_end = $grouped[$key]['end'];
                if (strtotime($start_d) - strtotime($prev_end) <= 86400) {
                    $grouped[$key]['end'] = $end_d ?: $start_d;
                    $grouped[$key]['multi'] = true;
                    continue;
                }
            }
            $grouped[$key] = [
                'title' => $e['title'],
                'start' => $start_d,
                'end' => $is_multi ? $end_d : $start_d,
                'multi' => $is_multi,
                'id' => $e['id'],
                'color' => $evt_color($e['title'], $e['color_theme'] ?? '', $e['sync_origin'] ?? ''),
            ];
        }
        foreach ($grouped as $g) {
            $multi = ($g['multi'] && $g['start'] !== $g['end']);
            $items[] = [
                'date' => $g['start'],
                'date_end' => $multi ? $g['end'] : null,
                'label' => $g['title'],
                'tone' => 'event',
                'color' => $g['color'],
                'icon' => ak_dash_date_badge($g['start'], $g['color']),
                'link' => '/agenda',
            ];
        }
    } catch (Throwable $e) {
        // Table events absente ou colonnes différentes : fallback silencieux
    }

    // Source 2 : projets à ≥75% (signaux de finition proche)
    try {
        $project_filter_sql = '';
        $extra_params = [];
        if ($follower_ids !== null) {
            if (empty($follower_ids)) return $items;
            $ph = implode(',', array_fill(0, count($follower_ids), '?'));
            $project_filter_sql = " AND p.id IN ($ph) ";
            $extra_params = $follower_ids;
        }
        $sql = "SELECT p.id, p.name, p.progress_percent
                FROM projects p
                JOIN folders f ON f.id = p.folder_id
                WHERE f.org_id = ? AND f.archived_at IS NULL AND p.archived_at IS NULL
                  AND p.status IN ('active','warning') AND p.progress_percent >= 75
                  $project_filter_sql
                ORDER BY p.progress_percent DESC LIMIT 5";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_merge([$org_id], $extra_params));
        foreach ($stmt->fetchAll() as $p) {
            // Estimation visuelle d'urgence : plus le % est haut, plus c'est "proche"
            $pct = (int)$p['progress_percent'];
            $tone = $pct >= 90 ? 'urgent' : ($pct >= 80 ? 'soon' : 'planned');
            $items[] = [
                'date' => null, // pas de vraie deadline → on affichera le %
                'pct' => $pct,
                'label' => $p['name'],
                'tone' => $tone,
                'icon' => $pct >= 90 ? '🔥' : '🎯',
                'link' => '/projet/' . (int)$p['id'],
            ];
        }
    } catch (Throwable $e) {}

    // Source 3 : Assemblées planifiées dans 7 jours
    try {
        $sql = "SELECT id, title, scheduled_at, status FROM assemblies
                WHERE org_id = ? AND archived_at IS NULL
                  AND status IN ('draft','sent','in_progress')
                  AND scheduled_at >= NOW() AND scheduled_at <= DATE_ADD(NOW(), INTERVAL 7 DAY)
                ORDER BY scheduled_at ASC LIMIT 5";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$org_id]);
        foreach ($stmt->fetchAll() as $a) {
            $items[] = [
                'date' => substr($a['scheduled_at'], 0, 10),
                'label' => $a['title'],
                'tone' => 'event',
                'icon' => ak_dash_date_badge(substr($a['scheduled_at'], 0, 10)),
                'link' => '/assemblee/' . (int)$a['id'],
            ];
        }
    } catch (Throwable $e) {}

    // Source 4 : Sessions émargement à venir 7j
    try {
        $sql = "SELECT id, title, starts_at FROM attendance_sessions
                WHERE org_id = ? AND archived_at IS NULL
                  AND starts_at >= NOW() AND starts_at <= DATE_ADD(NOW(), INTERVAL 7 DAY)
                ORDER BY starts_at ASC LIMIT 5";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$org_id]);
        foreach ($stmt->fetchAll() as $a) {
            $items[] = [
                'date' => substr($a['starts_at'], 0, 10),
                'label' => $a['title'],
                'tone' => 'event',
                'icon' => ak_dash_date_badge(substr($a['starts_at'], 0, 10)),
                'link' => '/emargement/' . (int)$a['id'],
            ];
        }
    } catch (Throwable $e) {}

    // Source 5 : Subventions deadline 7j (dépôt ou bilan)
    try {
        $sql = "SELECT id, name, deadline_apply, deadline_report, status FROM grants
                WHERE org_id = ? AND archived_at IS NULL
                  AND ((status IN ('draft','submitted','in_review') AND deadline_apply IS NOT NULL AND deadline_apply BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY))
                    OR (status = 'granted' AND deadline_report IS NOT NULL AND reported_at IS NULL AND deadline_report BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)))
                ORDER BY COALESCE(deadline_apply, deadline_report) ASC LIMIT 5";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$org_id]);
        foreach ($stmt->fetchAll() as $g) {
            $is_report = ($g['status'] === 'granted' && $g['deadline_report']);
            $items[] = [
                'date' => $is_report ? $g['deadline_report'] : $g['deadline_apply'],
                'label' => ($is_report ? '[Bilan] ' : '') . $g['name'],
                'tone' => 'urgent',
                'icon' => ak_dash_date_badge($is_report ? $g['deadline_report'] : $g['deadline_apply']),
                'link' => '/subvention/' . (int)$g['id'],
            ];
        }
    } catch (Throwable $e) {}

    // Tri : ceux avec date d'abord, puis projets par % décroissant
    usort($items, function($a, $b) {
        $ad = $a['date'] ?? null;
        $bd = $b['date'] ?? null;
        if ($ad && $bd) return strcmp($ad, $bd);
        if ($ad && !$bd) return -1;
        if (!$ad && $bd) return 1;
        return ($b['pct'] ?? 0) - ($a['pct'] ?? 0);
    });

    return array_slice($items, 0, 8);
}

/** Helper formatage date FR courte (ex: "Mar 12") */
function ak_dash_format_short_date(string $date_iso): string {
    try {
        $dt = new DateTime($date_iso);
        $jours = ['Dim', 'Lun', 'Mar', 'Mer', 'Jeu', 'Ven', 'Sam'];
        return $jours[(int)$dt->format('w')] . ' ' . $dt->format('j');
    } catch (Throwable $e) {
        return $date_iso;
    }
}

function ak_dash_date_badge(string $date_iso, string $color = '#DC2626'): string {
    try {
        $color = (is_string($color) && $color !== '' && $color[0] === '#') ? $color : '#DC2626';
        $dt = new DateTime($date_iso);
        $day = $dt->format('j');
        $mois = ['','JAN','FÉV','MAR','AVR','MAI','JUIN','JUIL','AOÛ','SEP','OCT','NOV','DÉC'];
        $m = $mois[(int)$dt->format('n')] ?? '';
        return '<svg width="34" height="34" viewBox="0 0 34 34" xmlns="http://www.w3.org/2000/svg" style="display:block;">'
            . '<rect x="2" y="4" width="30" height="28" rx="5" fill="#fff" stroke="#e5e7eb" stroke-width="1"/>'
            . '<rect x="2" y="4" width="30" height="8" rx="5" fill="' . htmlspecialchars($color) . '"/>'
            . '<rect x="2" y="9" width="30" height="3" fill="' . htmlspecialchars($color) . '"/>'
            . '<text x="17" y="10.5" text-anchor="middle" fill="#fff" font-family="-apple-system,sans-serif" font-size="6.5" font-weight="700">' . htmlspecialchars($m) . '</text>'
            . '<text x="17" y="26" text-anchor="middle" fill="#111827" font-family="-apple-system,sans-serif" font-size="13" font-weight="800">' . htmlspecialchars($day) . '</text>'
            . '</svg>';
    } catch (Throwable $e) {
        return '📅';
    }
}

function ak_dash_format_date_range(string $start, string $end): string {
    $mois_court = ['','janv.','févr.','mars','avr.','mai','juin','juil.','août','sept.','oct.','nov.','déc.'];
    try {
        $s = new DateTime($start);
        $e = new DateTime($end);
        $sm = (int)$s->format('n');
        $em = (int)$e->format('n');
        if ($sm === $em) {
            return 'du ' . $s->format('j') . ' au ' . $e->format('j') . ' ' . $mois_court[$em];
        }
        return 'du ' . $s->format('j') . ' ' . $mois_court[$sm] . ' au ' . $e->format('j') . ' ' . $mois_court[$em];
    } catch (Throwable $ex) {
        return $start;
    }
}

/** Sparkline mini (40x14) pour Top movers */
function ak_dash_mini_sparkline(array $values_14d, string $color = '#10B981'): string {
    $ordered = [];
    for ($i = 13; $i >= 0; $i--) $ordered[] = $values_14d[$i] ?? 0;
    $max = max($ordered); if ($max === 0) $max = 1;
    $w = 50; $h = 16; $n = count($ordered);
    $step = $w / max(1, $n - 1);
    $pts = [];
    foreach ($ordered as $i => $v) {
        $x = round($i * $step, 1);
        $y = round($h - 1 - (($v / $max) * ($h - 2)), 1);
        $pts[] = $x . ',' . $y;
    }
    $color_safe = htmlspecialchars($color, ENT_QUOTES);
    return '<svg width="' . $w . '" height="' . $h . '" viewBox="0 0 ' . $w . ' ' . $h . '" preserveAspectRatio="none" aria-hidden="true">'
         . '<polyline points="' . implode(' ', $pts) . '" fill="none" stroke="' . $color_safe . '" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>'
         . '</svg>';
}

// Chargement des 3 modules
$_dash_follower_ids = function_exists('get_follower_project_ids') ? get_follower_project_ids() : null;
$dash_top_movers = ak_dash_top_movers($pdo, $org_id, $_dash_follower_ids);

// === Pack Asso : AG, sessions émargement, subventions urgentes ===
$asso_next_ag = null; $asso_open_attendance = []; $asso_grants_urgent = [];
$_is_admin_dash = (($user['role'] ?? '') === 'admin');
$_is_coord_dash = (($user['role'] ?? '') === 'coordinator');
if ($_is_admin_dash) {
    try {
        $st = $pdo->prepare("SELECT id, title, type, scheduled_at, status, location FROM assemblies
            WHERE org_id = ? AND archived_at IS NULL AND status IN ('draft','sent','in_progress')
            ORDER BY scheduled_at ASC LIMIT 1");
        $st->execute([$org_id]);
        $asso_next_ag = $st->fetch() ?: null;
    } catch (Throwable $e) {}
    try {
        $st = $pdo->prepare("SELECT g.id, g.name, g.funder, g.deadline_apply, g.deadline_report, g.status, g.amount_requested,
            DATEDIFF(g.deadline_apply, CURDATE()) jours_apply,
            DATEDIFF(g.deadline_report, CURDATE()) jours_report
            FROM grants g WHERE g.org_id = ? AND g.archived_at IS NULL
              AND ((g.status IN ('draft','submitted','in_review') AND g.deadline_apply IS NOT NULL AND DATEDIFF(g.deadline_apply, CURDATE()) BETWEEN -7 AND 14)
                OR (g.status = 'granted' AND g.deadline_report IS NOT NULL AND g.reported_at IS NULL AND DATEDIFF(g.deadline_report, CURDATE()) BETWEEN -7 AND 30))
            ORDER BY COALESCE(g.deadline_apply, g.deadline_report) ASC LIMIT 3");
        $st->execute([$org_id]);
        $asso_grants_urgent = $st->fetchAll();
    } catch (Throwable $e) {}
}
if ($_is_admin_dash || $_is_coord_dash) {
    try {
        $st = $pdo->prepare("SELECT s.id, s.title, s.starts_at, s.ends_at, s.location,
            (SELECT COUNT(*) FROM attendance_records WHERE session_id = s.id) AS nb_signed
            FROM attendance_sessions s WHERE s.org_id = ? AND s.archived_at IS NULL AND s.is_open = 1
            ORDER BY s.starts_at DESC LIMIT 3");
        $st->execute([$org_id]);
        $asso_open_attendance = $st->fetchAll();
    } catch (Throwable $e) {}
}
$dash_coach = ak_dash_coach_actions($pdo, (int)$user['id'], $org_id, $_dash_follower_ids);
$dash_deadlines = ak_dash_upcoming_deadlines($pdo, $org_id, $_dash_follower_ids);

render_head('Tableau de bord');
render_sidebar('accueil');
?>

<style>
/* ============================================================
   DASHBOARD PREMIUM — Cockpit style Stripe/Notion
============================================================ */

/* Hero header avec gradient subtil */
.dash-hero {
    background: linear-gradient(135deg, #ffffff 0%, #f9fafb 100%);
    border: 1px solid #e5e7eb;
    border-radius: 18px;
    padding: 28px 32px;
    margin-bottom: 24px;
    position: relative;
    overflow: hidden;
}
.dash-hero::before {
    content: '';
    position: absolute;
    top: -100px; right: -100px;
    width: 300px; height: 300px;
    background: radial-gradient(circle, rgba(16,185,129,0.08) 0%, transparent 70%);
    pointer-events: none;
}
@media (prefers-color-scheme: dark) {
    .dash-hero { background: linear-gradient(135deg, #1f2937 0%, #111827 100%); border-color: #374151; }
}
.dash-hero-row { display: flex; justify-content: space-between; align-items: flex-start; gap: 20px; flex-wrap: wrap; position: relative; z-index: 1; }
.dash-hero-greet { flex: 1; min-width: 0; }
.dash-hero-title {
    font-size: 30px; font-weight: 600; letter-spacing: -0.03em;
    margin: 0 0 8px;
    background: linear-gradient(135deg, #111827 0%, #4b5563 100%);
    -webkit-background-clip: text;
    background-clip: text;
    -webkit-text-fill-color: transparent;
}
@media (prefers-color-scheme: dark) {
    .dash-hero-title { background: linear-gradient(135deg, #f3f4f6 0%, #d1d5db 100%); -webkit-background-clip: text; background-clip: text; -webkit-text-fill-color: transparent; }
}
.dash-hero-sub {
    display: flex; align-items: center; gap: 8px;
    font-size: 14px; color: #6b7280;
}
.dash-hero-sub-dot {
    display: inline-block; width: 6px; height: 6px; border-radius: 50%;
    background: #10B981; box-shadow: 0 0 0 0 rgba(16,185,129,0.5);
    animation: dashPulse 2s infinite;
}
@keyframes dashPulse {
    0%, 100% { box-shadow: 0 0 0 0 rgba(16,185,129,0.5); }
    50% { box-shadow: 0 0 0 6px rgba(16,185,129,0); }
}
.dash-hero-meta { display: flex; gap: 12px; flex-wrap: wrap; }
.dash-hero-meta-item {
    display: flex; flex-direction: column; gap: 2px;
    padding: 8px 14px; background: rgba(255,255,255,0.6);
    border: 1px solid #e5e7eb; border-radius: 10px;
}
@media (prefers-color-scheme: dark) {
    .dash-hero-meta-item { background: rgba(31,41,55,0.6); border-color: #374151; }
}
.dash-hero-meta-lbl { font-size: 10.5px; color: #6b7280; text-transform: uppercase; letter-spacing: 0.05em; font-weight: 600; }
.dash-hero-meta-val { font-size: 14px; font-weight: 600; color: #111827; }
@media (prefers-color-scheme: dark) {
    .dash-hero-meta-val { color: #f3f4f6; }
}

/* KPI Cards grid */
.dash-kpis {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 14px;
    margin-bottom: 24px;
}
.dash-kpi {
    background: var(--glass, rgba(255,255,255,0.72));
    backdrop-filter: blur(22px) saturate(1.5);
    -webkit-backdrop-filter: blur(22px) saturate(1.5);
    border: 1px solid var(--glass-border, rgba(255,255,255,0.65));
    border-radius: var(--radius-lg, 18px);
    padding: 18px 18px 16px;
    position: relative;
    overflow: hidden;
    box-shadow: var(--shadow-card, 0 1px 2px rgba(9,30,22,0.04), 0 14px 34px -16px rgba(9,30,22,0.16));
    transition: transform 0.2s ease, box-shadow 0.2s ease;
}
.dash-kpi::before {
    content: ''; position: absolute; inset: 0 0 auto 0; height: 42%;
    background: linear-gradient(180deg, rgba(255,255,255,0.9), transparent);
    opacity: 0.5; pointer-events: none;
}
.dash-kpi:hover {
    transform: translateY(-2px);
    box-shadow: var(--shadow-pop, 0 2px 8px rgba(9,30,22,0.06), 0 26px 56px -20px rgba(9,30,22,0.24));
}
.dash-kpi-glow {
    position: absolute; inset: 0 0 auto 0; height: 3px;
    border-radius: 3px 3px 0 0;
}
.dash-kpi-head {
    display: flex; align-items: center; justify-content: space-between;
    margin-bottom: 14px; position: relative;
}
.dash-kpi-icon {
    width: 38px; height: 38px; border-radius: 12px;
    display: flex; align-items: center; justify-content: center;
    box-shadow: inset 0 1px 0 rgba(255,255,255,0.6);
}
.dash-kpi-trend {
    font-size: 11px; font-weight: 700; padding: 3px 9px; border-radius: 20px;
    background: rgba(5,150,105,0.10); color: #047857;
}
.dash-kpi-trend.down { background: rgba(229,72,77,0.12); color: #b42318; }
.dash-kpi-val {
    font-size: 34px; font-weight: 800; letter-spacing: -0.03em;
    color: var(--ink, #0B1A13); line-height: 1;
    font-variant-numeric: tabular-nums; position: relative;
}
.dash-kpi-val-unit { font-size: 18px; font-weight: 700; color: var(--ink-3, #7C8983); letter-spacing: 0; }
.dash-kpi-lbl { font-size: 13.5px; color: var(--ink, #0B1A13); font-weight: 600; margin-top: 5px; }
.dash-kpi-sub { font-size: 12px; color: var(--ink-3, #78857F); margin-top: 2px; }
/* variantes couleur (liséré + icône + tag) */
.dash-kpi.k-green .dash-kpi-glow { background: linear-gradient(90deg,#10B981,#059669); }
.dash-kpi.k-green .dash-kpi-icon { background: rgba(5,150,105,0.10); color: #059669; }
.dash-kpi.k-green .dash-kpi-trend { background: rgba(5,150,105,0.10); color: #047857; }
.dash-kpi.k-blue .dash-kpi-glow { background: linear-gradient(90deg,#60A5FA,#2F73E8); }
.dash-kpi.k-blue .dash-kpi-icon { background: rgba(47,115,232,0.12); color: #2F73E8; }
.dash-kpi.k-blue .dash-kpi-trend { background: rgba(47,115,232,0.12); color: #2F73E8; }
.dash-kpi.k-amber .dash-kpi-glow { background: linear-gradient(90deg,#FBBF24,#E0850C); }
.dash-kpi.k-amber .dash-kpi-icon { background: rgba(224,133,12,0.12); color: #E0850C; }
.dash-kpi.k-amber .dash-kpi-trend { background: rgba(224,133,12,0.12); color: #b45309; }
.dash-kpi.k-ai .dash-kpi-glow { background: linear-gradient(90deg,#8B5CF6,#6366F1); }
.dash-kpi.k-ai .dash-kpi-icon { background: rgba(99,102,241,0.10); color: #6366F1; }
.dash-kpi.k-ai .dash-kpi-trend { background: rgba(99,102,241,0.10); color: #6366F1; }

/* Charts row */
.dash-charts-row {
    display: grid;
    grid-template-columns: 2fr 1fr;
    gap: 14px;
    margin-bottom: 24px;
}
.dash-card {
    background: var(--bg, #fff);
    border: 1px solid var(--border, #e5e7eb);
    border-radius: 14px;
    padding: 22px 24px;
}
.dash-card-head {
    display: flex; justify-content: space-between; align-items: flex-start;
    margin-bottom: 18px; gap: 12px;
}
.dash-card-title {
    font-size: 15px; font-weight: 600; color: var(--ink, #111827);
    display: flex; align-items: center; gap: 8px;
}
.dash-card-sub { font-size: 12px; color: var(--ink-3, #6b7280); margin-top: 3px; }
.dash-card-actions { font-size: 12.5px; color: var(--acc, #10B981); font-weight: 500; }

/* Activity chart (line) */
.dash-chart-svg { width: 100%; height: 200px; display: block; }
.dash-chart-stats {
    display: flex; gap: 24px;
    padding-top: 16px; border-top: 1px solid var(--border, #e5e7eb);
    margin-top: 16px;
    flex-wrap: wrap;
}
.dash-chart-stat-lbl { font-size: 11px; color: var(--ink-3, #6b7280); text-transform: uppercase; letter-spacing: 0.04em; font-weight: 600; }
.dash-chart-stat-val { font-size: 18px; font-weight: 700; color: var(--ink, #111827); margin-top: 2px; font-variant-numeric: tabular-nums; }

/* Donut chart */
.dash-donut-wrap { display: flex; flex-direction: column; align-items: center; gap: 16px; }
.dash-donut-center { position: relative; width: 160px; height: 160px; }
.dash-donut-svg { width: 100%; height: 100%; transform: rotate(-90deg); }
.dash-donut-text {
    position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%);
    text-align: center;
}
.dash-donut-num { font-size: 28px; font-weight: 700; color: var(--ink, #111827); line-height: 1; }
.dash-donut-lbl { font-size: 11px; color: var(--ink-3, #6b7280); text-transform: uppercase; letter-spacing: 0.04em; margin-top: 2px; }
.dash-donut-legend { width: 100%; display: flex; flex-direction: column; gap: 8px; }
.dash-donut-item { display: flex; align-items: center; gap: 10px; padding: 6px 8px; border-radius: 6px; transition: background 0.15s; }
.dash-donut-item:hover { background: var(--bg-2, #f9fafb); }
.dash-donut-dot { width: 10px; height: 10px; border-radius: 50%; flex-shrink: 0; }
.dash-donut-name { font-size: 13px; color: var(--ink-2, #374151); flex: 1; }
.dash-donut-count { font-size: 13px; font-weight: 600; color: var(--ink, #111827); font-variant-numeric: tabular-nums; }

/* Quick actions grid */
.dash-quick-actions {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 12px;
    margin-bottom: 24px;
}
.dash-action {
    display: flex; align-items: center; gap: 14px;
    background: var(--bg, #fff);
    border: 1px solid var(--border, #e5e7eb);
    border-radius: 14px;
    padding: 18px 20px;
    text-decoration: none;
    transition: all 0.2s ease;
    position: relative;
    overflow: hidden;
}
.dash-action:hover {
    transform: translateY(-3px);
    box-shadow: 0 12px 28px rgba(0,0,0,0.1);
    border-color: transparent;
}
.dash-action::after {
    content: '→';
    position: absolute;
    right: 18px; top: 50%; transform: translateY(-50%);
    font-size: 18px; color: var(--ink-3, #9ca3af);
    transition: transform 0.2s ease, color 0.2s;
}
.dash-action:hover::after {
    transform: translateY(-50%) translateX(4px);
    color: var(--acc, #10B981);
}
.dash-action-icon {
    width: 44px; height: 44px; border-radius: 12px;
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0;
}
.dash-action-body { flex: 1; min-width: 0; padding-right: 20px; }
.dash-action-title { font-size: 14px; font-weight: 600; color: var(--ink, #111827); line-height: 1.3; }
.dash-action-desc { font-size: 12px; color: var(--ink-3, #6b7280); margin-top: 2px; line-height: 1.3; }

/* Activity feed */
.dash-feed-list { display: flex; flex-direction: column; gap: 2px; margin: 0; padding: 0; list-style: none; }
.dash-feed-item {
    display: flex; gap: 14px; padding: 12px 8px; border-radius: 8px;
    transition: background 0.15s;
}
.dash-feed-item:hover { background: var(--bg-2, #f9fafb); }
.dash-feed-avatar {
    width: 32px; height: 32px; border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    color: #fff; font-size: 11px; font-weight: 600;
    flex-shrink: 0;
}
.dash-feed-body { flex: 1; min-width: 0; }
.dash-feed-text { font-size: 13px; color: var(--ink-2, #374151); line-height: 1.4; word-break: break-word; }
.dash-feed-text strong { color: var(--ink, #111827); font-weight: 600; }
.dash-feed-time { font-size: 11px; color: var(--ink-3, #9ca3af); margin-top: 3px; }

/* Animation count-up des KPI */
.kpi-counter { display: inline-block; }

/* Responsive */
@media (max-width: 900px) {
    .dash-kpis { grid-template-columns: repeat(2, 1fr); }
    .dash-charts-row { grid-template-columns: 1fr; }
    .dash-quick-actions { grid-template-columns: repeat(2, 1fr); }
}
@media (max-width: 540px) {
    .dash-hero { padding: 20px 18px; }
    .dash-hero-title { font-size: 22px; }
    .dash-hero-meta { gap: 8px; }
    .dash-hero-meta-item { padding: 6px 10px; }
    .dash-kpis { grid-template-columns: 1fr 1fr; gap: 10px; }
    .dash-kpi { padding: 14px 16px; }
    .dash-kpi-val { font-size: 22px; }
    .dash-card { padding: 16px; }
    .dash-quick-actions { grid-template-columns: 1fr; }
    .dash-action { padding: 14px 16px; }
}
</style>

<main class="main">

  <!-- ====== HERO HEADER ====== -->
  <div class="dash-hero">
    <div class="dash-hero-row">
      <div class="dash-hero-greet">
        <h1 class="dash-hero-title"><?= h($greeting) ?>, <?= h($user['first_name']) ?> 👋</h1>
        <div class="dash-hero-sub">
          <span class="dash-hero-sub-dot"></span>
          <span><?= h($today_fr) ?> · <strong style="color:var(--ink, #111827);"><?= $active_projects ?></strong> projet<?= $active_projects > 1 ? 's' : '' ?> en cours</span>
        </div>
      </div>
      <div class="dash-hero-meta">
        <div class="dash-hero-meta-item">
          <span class="dash-hero-meta-lbl">Heure</span>
          <span class="dash-hero-meta-val" id="dashClock"><?= date('H:i') ?></span>
        </div>
        <?php require __DIR__ . '/_guide-onboarding.php'; ?>
      </div>
    </div>

    <!-- 🔍 Barre de recherche inline -->
    <div class="dash-search">
      <div class="dash-search-wrap">
        <svg class="dash-search-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
        <input type="text" id="dash-search-input" placeholder="Rechercher un projet, dossier, adhérent, facture…" autocomplete="off" spellcheck="false"/>
        <span class="dash-search-spinner" id="dash-search-spinner" hidden></span>
      </div>
      <div class="dash-search-results" id="dash-search-results" hidden></div>
    </div>
  </div>

  <?php if ($logo_admin): ?>
  <!-- ====== LOGO DE LA STRUCTURE (admin uniquement) ====== -->
  <section class="dash-logo" aria-label="Logo de la structure">
    <div class="dash-logo-preview">
      <?php if (!empty($org_logo)): ?>
        <img src="<?= h($org_logo) ?>" alt="Logo de la structure">
      <?php else: ?>
        <div class="dash-logo-empty">
          <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2.5"/><circle cx="8.5" cy="8.5" r="1.6"/><path d="M21 15l-5-5L5 21"/></svg>
        </div>
      <?php endif; ?>
    </div>
    <div class="dash-logo-body">
      <div class="dash-logo-title">Logo de la structure</div>
      <div class="dash-logo-sub">
        <?php if (!empty($org_logo)): ?>
          Affiché sur vos factures &amp; devis PDF · mis à jour le <?= h(date('d/m/Y', strtotime($org_logo_at))) ?>
        <?php else: ?>
          Ajoutez le logo de votre association ou TPE — il apparaîtra sur vos factures &amp; devis.
        <?php endif; ?>
      </div>
      <?php if ($logo_flash): ?>
        <div class="dash-logo-flash <?= $logo_flash['type'] === 'success' ? 'ok' : 'err' ?>"><?= h($logo_flash['message']) ?></div>
      <?php endif; ?>
    </div>
    <div class="dash-logo-actions">
      <form method="POST" action="/mon-asso-logo" enctype="multipart/form-data" id="dashLogoForm">
        <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
        <input type="hidden" name="action" value="upload">
        <input type="hidden" name="redirect" value="/dashboard">
        <input type="file" name="logo" id="dashLogoFile" accept="image/png,image/jpeg,image/jpg,image/gif" hidden onchange="document.getElementById('dashLogoForm').submit()">
        <button type="button" class="dash-logo-btn primary" onclick="document.getElementById('dashLogoFile').click()">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="M17 8l-5-5-5 5M12 3v12"/></svg>
          <?= !empty($org_logo) ? 'Changer' : 'Ajouter un logo' ?>
        </button>
      </form>
      <?php if (!empty($org_logo)): ?>
      <form method="POST" action="/mon-asso-logo" onsubmit="return confirm('Supprimer le logo de la structure ?')">
        <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="redirect" value="/dashboard">
        <button type="submit" class="dash-logo-btn ghost" title="Supprimer le logo">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/></svg>
        </button>
      </form>
      <?php endif; ?>
    </div>
  </section>
  <?php endif; ?>

  <style>
  .dash-logo {
    display: flex; align-items: center; gap: 18px; margin-top: 16px;
    padding: 16px 18px; border-radius: var(--radius-lg, 18px);
    background: var(--glass, rgba(255,255,255,0.72));
    border: 1px solid var(--glass-border, rgba(255,255,255,0.65));
    backdrop-filter: blur(22px) saturate(1.5); -webkit-backdrop-filter: blur(22px) saturate(1.5);
    box-shadow: var(--shadow-card, 0 1px 2px rgba(9,30,22,0.04), 0 14px 34px -16px rgba(9,30,22,0.16));
    position: relative; overflow: hidden;
  }
  .dash-logo::before { content: ""; position: absolute; inset: 0 0 auto 0; height: 3px; background: linear-gradient(90deg,#34D399,#6366F1); }
  .dash-logo-preview {
    width: 120px; height: 106px; flex: none; border-radius: 14px;
    background: #fff; border: 1px solid var(--border, rgba(12,40,28,0.07));
    display: grid; place-items: center; overflow: hidden; padding: 0; box-sizing: border-box;
    box-shadow: inset 0 1px 3px rgba(9,30,22,0.06);
  }
  /* L'image n'occupe que ~72% du cadre => marge garantie sur TOUS les côtés,
     et object-fit:contain garde le logo entier (ni coupé, ni déformé). */
  .dash-logo-preview img { width: 72%; height: 72%; object-fit: contain; object-position: center; display: block; }
  .dash-logo-empty { color: var(--ink-4, #A6B0AA); display: grid; place-items: center; }
  .dash-logo-body { flex: 1; min-width: 0; }
  .dash-logo-title { font-size: 15px; font-weight: 750; letter-spacing: -0.01em; color: var(--ink, #0B1A13); }
  .dash-logo-sub { font-size: 12.5px; color: var(--ink-3, #78857F); margin-top: 3px; line-height: 1.45; }
  .dash-logo-flash { margin-top: 8px; font-size: 12px; font-weight: 600; padding: 5px 10px; border-radius: 8px; display: inline-block; }
  .dash-logo-flash.ok { background: var(--acc-light, #D1FAE5); color: var(--acc-dark, #047857); }
  .dash-logo-flash.err { background: #FEE2E2; color: #991B1B; }
  .dash-logo-actions { display: flex; align-items: center; gap: 8px; flex: none; }
  .dash-logo-btn { display: inline-flex; align-items: center; gap: 7px; padding: 9px 14px; border-radius: 11px; font-size: 13px; font-weight: 650; font-family: inherit; cursor: pointer; border: 0; transition: transform .12s ease, box-shadow .12s ease; }
  .dash-logo-btn.primary { background: linear-gradient(140deg,#10B981,#059669); color: #fff; box-shadow: 0 8px 18px -8px rgba(5,150,105,.6), inset 0 1px 0 rgba(255,255,255,.35); }
  .dash-logo-btn.primary:hover { transform: translateY(-1px); }
  .dash-logo-btn.ghost { background: var(--bg-2, #EDF2EF); color: var(--ink-3, #78857F); padding: 9px 11px; }
  .dash-logo-btn.ghost:hover { background: #FEE2E2; color: #991B1B; }
  @media (max-width: 640px) {
    .dash-logo { flex-wrap: wrap; }
    .dash-logo-actions { width: 100%; }
    .dash-logo-actions form:first-child { flex: 1; }
    .dash-logo-btn.primary { width: 100%; justify-content: center; }
  }
  </style>

  <style>
  .dash-search { position: relative; margin-top: 18px; }
  .dash-search-wrap {
    display: flex; align-items: center; gap: 10px;
    padding: 10px 14px;
    background: rgba(255,255,255,0.95);
    border: 1px solid #e5e7eb;
    border-radius: 10px;
    transition: border-color 0.15s ease, box-shadow 0.15s ease;
  }
  .dash-search-wrap:focus-within { border-color: #10B981; box-shadow: 0 0 0 3px rgba(16,185,129,0.12); }
  .dash-search-icon { color: #9ca3af; flex-shrink: 0; }
  .dash-search-wrap input {
    flex: 1; min-width: 0; border: 0; outline: none; background: transparent;
    font-size: 13.5px; font-family: inherit; color: #111827;
  }
  .dash-search-wrap input::placeholder { color: #9ca3af; }
  .dash-search-spinner { width: 14px; height: 14px; border: 2px solid #e5e7eb; border-top-color: #10B981; border-radius: 50%; animation: dash-spin 0.8s linear infinite; }
  @keyframes dash-spin { to { transform: rotate(360deg); } }
  .dash-search-results {
    position: absolute; left: 0; right: 0; top: calc(100% + 4px);
    background: #fff; border: 1px solid #e5e7eb; border-radius: 10px;
    box-shadow: 0 10px 30px rgba(0,0,0,0.08), 0 4px 12px rgba(0,0,0,0.04);
    max-height: 420px; overflow-y: auto; z-index: 50;
  }
  .dash-search-group { padding: 6px 0 2px; }
  .dash-search-group + .dash-search-group { border-top: 1px solid #f3f4f6; }
  .dash-search-group-title { font-size: 10px; font-weight: 700; color: #9ca3af; padding: 6px 14px 4px; text-transform: uppercase; letter-spacing: 0.06em; }
  .dash-search-item { display: flex; align-items: center; gap: 10px; padding: 8px 14px; text-decoration: none; color: inherit; transition: background 0.12s ease; }
  .dash-search-item:hover, .dash-search-item.is-active { background: #f0fdf4; }
  .dash-search-item-icon { width: 28px; height: 28px; border-radius: 7px; background: #f3f4f6; flex-shrink: 0; display: flex; align-items: center; justify-content: center; font-size: 14px; }
  .dash-search-item-body { flex: 1; min-width: 0; }
  .dash-search-item-label { font-size: 13px; font-weight: 600; color: #111827; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
  .dash-search-item-sub { font-size: 11px; color: #6b7280; margin-top: 1px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
  .dash-search-empty { padding: 18px 14px; text-align: center; color: #9ca3af; font-size: 12.5px; }
  </style>

  <script>
  (function() {
    const input = document.getElementById('dash-search-input');
    const results = document.getElementById('dash-search-results');
    const spinner = document.getElementById('dash-search-spinner');
    if (!input || !results) return;
    const groupLabels = { projet: 'Projets', dossier: 'Dossiers', adherent: 'Adhérents', facture: 'Factures' };
    let debounceTimer = null, lastQuery = '', activeIdx = 0, items = [];
    const escapeHtml = s => String(s||'').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    function setActive(idx) { items.forEach(el => el.classList.remove('is-active')); if (items[idx]) { items[idx].classList.add('is-active'); items[idx].scrollIntoView({block:'nearest'}); activeIdx = idx; } }
    function render(data) {
      if (!data || data.length === 0) { results.innerHTML = '<div class="dash-search-empty">Aucun résultat pour "' + escapeHtml(lastQuery) + '"</div>'; results.hidden = false; items = []; return; }
      const groups = {};
      data.forEach(r => { (groups[r.type] = groups[r.type] || []).push(r); });
      let html = '';
      Object.keys(groups).forEach(type => {
        html += '<div class="dash-search-group"><div class="dash-search-group-title">' + (groupLabels[type] || type) + '</div>';
        groups[type].forEach(r => {
          html += '<a class="dash-search-item" href="' + escapeHtml(r.url) + '"><div class="dash-search-item-icon">' + escapeHtml(r.icon) + '</div><div class="dash-search-item-body"><div class="dash-search-item-label">' + escapeHtml(r.label) + '</div><div class="dash-search-item-sub">' + escapeHtml(r.sub) + '</div></div></a>';
        });
        html += '</div>';
      });
      results.innerHTML = html; results.hidden = false;
      items = Array.from(results.querySelectorAll('.dash-search-item'));
      activeIdx = 0; if (items[0]) items[0].classList.add('is-active');
    }
    input.addEventListener('input', function() {
      const q = input.value.trim(); lastQuery = q;
      clearTimeout(debounceTimer);
      if (q.length < 2) { results.hidden = true; spinner.hidden = true; return; }
      spinner.hidden = false;
      debounceTimer = setTimeout(() => {
        fetch('/api-search.php?q=' + encodeURIComponent(q), { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
          .then(r => r.json())
          .then(d => { if (q === lastQuery) { render(d.results || []); spinner.hidden = true; } })
          .catch(() => { results.innerHTML = '<div class="dash-search-empty">Erreur de recherche.</div>'; results.hidden = false; spinner.hidden = true; });
      }, 200);
    });
    input.addEventListener('keydown', function(e) {
      if (e.key === 'Escape') { input.value = ''; results.hidden = true; input.blur(); return; }
      if (items.length === 0) return;
      if (e.key === 'ArrowDown') { e.preventDefault(); setActive((activeIdx + 1) % items.length); }
      else if (e.key === 'ArrowUp') { e.preventDefault(); setActive((activeIdx - 1 + items.length) % items.length); }
      else if (e.key === 'Enter') { e.preventDefault(); if (items[activeIdx]) window.location.href = items[activeIdx].href; }
    });
    document.addEventListener('click', function(e) {
      if (!e.target.closest('.dash-search')) results.hidden = true;
    });
  })();
  </script>

  <!-- ====== WIDGET RELANCES FACTURES ====== -->
  <?php
  if (function_exists('ak_render_invoice_notifications')
      && in_array($user['role'] ?? '', ['admin', 'coordinator'], true)) {
      ak_render_invoice_notifications($pdo, $org_id);
  }
  ?>

  <?php
  /* ---- Score de santé IA (heuristique locale, 0-100, sans appel externe) ---- */
  $__completed = (int) ($status_counts['completed'] ?? 0);
  $health_score = 55;
  $health_score += min(20, (int) round(($total_activity ?? 0) * 1.6)); // activité 30j
  $health_score += ($upcoming_events_count > 0) ? 8 : 0;               // agenda alimenté
  $health_score += ($active_projects > 0) ? 8 : 0;                     // projets vivants
  $health_score += ($new_users > 0) ? 5 : 0;                           // recrutement
  $health_score += min(4, $__completed);                               // livraisons
  $health_score = max(0, min(100, $health_score));
  $health_label = $health_score >= 85 ? 'Excellent' : ($health_score >= 70 ? 'Bon' : ($health_score >= 55 ? 'Correct' : 'À surveiller'));
  ?>
  <!-- ====== KPI CARDS ANIMÉES ====== -->
  <div class="dash-kpis">
    <div class="dash-kpi k-green">
      <span class="dash-kpi-glow"></span>
      <div class="dash-kpi-head">
        <div class="dash-kpi-icon">
          <svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/></svg>
        </div>
        <span class="dash-kpi-trend">Actif</span>
      </div>
      <div class="dash-kpi-val"><span class="kpi-counter" data-target="<?= $active_projects ?>">0</span></div>
      <div class="dash-kpi-lbl">Projets actifs</div>
      <div class="dash-kpi-sub">en cours ce mois</div>
    </div>

    <div class="dash-kpi k-blue">
      <span class="dash-kpi-glow"></span>
      <div class="dash-kpi-head">
        <div class="dash-kpi-icon">
          <svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
        </div>
        <?php if ($new_users > 0): ?>
          <span class="dash-kpi-trend">+<?= $new_users ?></span>
        <?php endif; ?>
      </div>
      <div class="dash-kpi-val"><span class="kpi-counter" data-target="<?= $total_users ?>">0</span></div>
      <div class="dash-kpi-lbl">Membres actifs</div>
      <div class="dash-kpi-sub"><?= $new_users ?> nouveau<?= $new_users > 1 ? 'x' : '' ?> en 30j</div>
    </div>

    <div class="dash-kpi k-amber">
      <span class="dash-kpi-glow"></span>
      <div class="dash-kpi-head">
        <div class="dash-kpi-icon">
          <svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
        </div>
        <?php if ($upcoming_events_count > 0): ?>
        <span class="dash-kpi-trend">À venir</span>
        <?php endif; ?>
      </div>
      <div class="dash-kpi-val"><span class="kpi-counter" data-target="<?= $upcoming_events_count ?>">0</span></div>
      <div class="dash-kpi-lbl">Événements</div>
      <div class="dash-kpi-sub">à venir cette saison</div>
    </div>

    <div class="dash-kpi k-ai">
      <span class="dash-kpi-glow"></span>
      <div class="dash-kpi-head">
        <div class="dash-kpi-icon">
          <svg width="19" height="19" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2l2.2 6.1L20.5 10l-6.3 1.9L12 18l-2.2-6.1L3.5 10l6.3-1.9L12 2z"/></svg>
        </div>
        <span class="dash-kpi-trend"><?= h($health_label) ?></span>
      </div>
      <div class="dash-kpi-val"><span class="kpi-counter" data-target="<?= $health_score ?>">0</span><span class="dash-kpi-val-unit">/100</span></div>
      <div class="dash-kpi-lbl">Score de santé IA</div>
      <div class="dash-kpi-sub">activité · vélocité · engagement</div>
    </div>
  </div>

  <!-- ====== AUJOURD'HUI (Copilote IA) ====== -->
  <?php require __DIR__ . '/_today-suggestions.php'; ?>

  <!-- ====== GRAPHIQUES ROW : Activité 30j + Donut statuts ====== -->
  <div class="dash-charts-row">
    
    <!-- Activity chart 30 days -->
    <div class="dash-card">
      <div class="dash-card-head">
        <div>
          <div class="dash-card-title"><?= ak_icon('trending-up',18) ?> Activité de l'association</div>
          <div class="dash-card-sub">30 derniers jours</div>
        </div>
        <div class="dash-card-actions"><?= $total_activity ?> actions</div>
      </div>
      
      <?php
      $svg_w = 700; $svg_h = 180; $padding = 20;
      $point_count = count($activity_data);
      $step = ($svg_w - 2*$padding) / max(1, ($point_count - 1));
      $path_points = [];
      foreach ($activity_data as $i => $val) {
          $x = $padding + ($i * $step);
          $y = $svg_h - $padding - (($val / $max_activity) * ($svg_h - 2*$padding));
          $path_points[] = round($x) . ',' . round($y);
      }
      ?>
      <svg class="dash-chart-svg" viewBox="0 0 <?= $svg_w ?> <?= $svg_h ?>" preserveAspectRatio="none">
        <defs>
          <linearGradient id="activityGrad" x1="0" y1="0" x2="0" y2="1">
            <stop offset="0%" stop-color="#10B981" stop-opacity="0.3"/>
            <stop offset="100%" stop-color="#10B981" stop-opacity="0"/>
          </linearGradient>
        </defs>
        
        <?php for ($g = 1; $g <= 3; $g++): 
          $gy = $padding + (($svg_h - 2*$padding) * $g / 4); ?>
          <line x1="<?= $padding ?>" y1="<?= $gy ?>" x2="<?= $svg_w - $padding ?>" y2="<?= $gy ?>" 
                stroke="#e5e7eb" stroke-width="1" stroke-dasharray="2,4"/>
        <?php endfor; ?>
        
        <path d="M <?= $padding ?>,<?= $svg_h - $padding ?> L <?= implode(' L ', $path_points) ?> L <?= $svg_w - $padding ?>,<?= $svg_h - $padding ?> Z" 
              fill="url(#activityGrad)"/>
        
        <polyline points="<?= implode(' ', $path_points) ?>" 
                  fill="none" stroke="#10B981" stroke-width="2.5" 
                  stroke-linejoin="round" stroke-linecap="round"/>
        
        <?php foreach ($activity_data as $i => $val): 
          $x = $padding + ($i * $step);
          $y = $svg_h - $padding - (($val / $max_activity) * ($svg_h - 2*$padding));
          if ($val > 0):
        ?>
          <circle cx="<?= round($x) ?>" cy="<?= round($y) ?>" r="3" fill="#fff" stroke="#10B981" stroke-width="2">
            <title><?= date('d/m', strtotime($activity_dates[$i])) ?> : <?= $val ?> action<?= $val > 1 ? 's' : '' ?></title>
          </circle>
        <?php endif; endforeach; ?>
      </svg>
      
      <div class="dash-chart-stats">
        <div>
          <div class="dash-chart-stat-lbl">Total 30j</div>
          <div class="dash-chart-stat-val"><?= $total_activity ?></div>
        </div>
        <div>
          <div class="dash-chart-stat-lbl">Moyenne / jour</div>
          <div class="dash-chart-stat-val"><?= number_format($total_activity / 30, 1, ',', ' ') ?></div>
        </div>
        <div>
          <div class="dash-chart-stat-lbl">Pic d'activité</div>
          <div class="dash-chart-stat-val"><?= $max_activity ?></div>
        </div>
      </div>
    </div>
    
    <!-- Donut chart statuts -->
    <div class="dash-card">
      <div class="dash-card-head">
        <div>
          <div class="dash-card-title"><?= ak_icon('target',18) ?> Répartition projets</div>
          <div class="dash-card-sub">par statut</div>
        </div>
      </div>
      
      <div class="dash-donut-wrap">
        <div class="dash-donut-center">
          <?php 
          $r = 65; $cx = 80; $cy = 80; $stroke_w = 16;
          $circumference = 2 * M_PI * $r;
          $offset = 0;
          $colors = ['active' => '#10B981', 'warning' => '#F59E0B', 'completed' => '#3B82F6', 'paused' => '#94a3b8'];
          ?>
          <svg class="dash-donut-svg" viewBox="0 0 160 160">
            <circle cx="<?= $cx ?>" cy="<?= $cy ?>" r="<?= $r ?>" fill="none" stroke="#f3f4f6" stroke-width="<?= $stroke_w ?>"/>
            
            <?php if ($status_total > 0): foreach ($status_counts as $status => $count): 
              if ($count == 0) continue;
              $portion = $count / $status_total;
              $dash = $portion * $circumference;
              $color = $colors[$status] ?? '#94a3b8';
            ?>
              <circle cx="<?= $cx ?>" cy="<?= $cy ?>" r="<?= $r ?>" 
                      fill="none" stroke="<?= $color ?>" 
                      stroke-width="<?= $stroke_w ?>"
                      stroke-dasharray="<?= round($dash, 2) ?> <?= round($circumference - $dash, 2) ?>"
                      stroke-dashoffset="<?= round(-$offset, 2) ?>"
                      stroke-linecap="round"/>
            <?php $offset += $dash; endforeach; endif; ?>
          </svg>
          
          <div class="dash-donut-text">
            <div class="dash-donut-num"><?= $status_total ?></div>
            <div class="dash-donut-lbl">Projets</div>
          </div>
        </div>
        
        <div class="dash-donut-legend">
          <?php 
          $status_labels = [
              'active' => '🟢 En cours',
              'warning' => '🟠 À surveiller',
              'completed' => '🔵 Terminés',
              'paused' => '⚪ En pause',
          ];
          $shown_any = false;
          foreach ($status_counts as $status => $count): 
            if ($count == 0) continue;
            $shown_any = true;
          ?>
            <div class="dash-donut-item">
              <span class="dash-donut-dot" style="background: <?= $colors[$status] ?>;"></span>
              <span class="dash-donut-name"><?= h($status_labels[$status] ?? ucfirst($status)) ?></span>
              <span class="dash-donut-count"><?= $count ?></span>
            </div>
          <?php endforeach; ?>
          <?php if (!$shown_any): ?>
            <div style="text-align:center; color:var(--ink-3, #9ca3af); font-size:12px; padding:20px 0;">
              Aucun projet pour l'instant
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>

  <!-- ============================================================ -->
  <!-- 🤖 PACK ESSENTIEL IA — Coach + Top movers + Échéances        -->
  <!-- ============================================================ -->
  <section class="ai-pack" aria-label="Suggestions IA">

    <!-- Coach IA (full-width) -->
    <?php if (!empty($dash_coach)): ?>
    <div class="ai-coach">
      <div class="ai-coach-band" aria-hidden="true"></div>
      <div class="ai-coach-head">
        <div class="ai-coach-title">
          <span class="ai-coach-icon">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2l2.5 6.5L21 11l-6.5 2.5L12 20l-2.5-6.5L3 11l6.5-2.5L12 2z"/></svg>
          </span>
          <div class="ai-coach-title-txt">
            <b>Votre coach AssoKit IA</b>
            <small><?= count($dash_coach) ?> conseil<?= count($dash_coach) > 1 ? 's' : '' ?> pour faire avancer vos projets</small>
          </div>
        </div>
        <span class="ai-coach-tag">✦ Suggestions personnalisées</span>
      </div>
      <div class="ai-coach-list">
        <?php foreach ($dash_coach as $a): ?>
          <?php if (!empty($a['link'])): ?>
            <a href="<?= htmlspecialchars($a['link'], ENT_QUOTES) ?>" class="ai-coach-item tone-<?= h($a['tone']) ?>">
          <?php else: ?>
            <div class="ai-coach-item tone-<?= h($a['tone']) ?>">
          <?php endif; ?>
            <div class="ai-coach-emoji"><?= h($a['icon']) ?></div>
            <div class="ai-coach-body">
              <div class="ai-coach-item-title"><?= h($a['title']) ?></div>
              <div class="ai-coach-item-text"><?= $a['body'] /* HTML safe : sources contrôlées + htmlspecialchars sur valeurs dynamiques */ ?></div>
            </div>
            <?php if (!empty($a['link'])): ?>
              <svg class="ai-coach-arrow" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
            <?php endif; ?>
          <?php if (!empty($a['link'])): ?></a><?php else: ?></div><?php endif; ?>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

    <!-- Row : Top movers + Échéances (2 colonnes) -->
    <div class="ai-row-2col">

      <!-- Top movers -->
      <div class="ai-card">
        <div class="ai-card-head">
          <div class="ai-card-title">
            <span class="ai-card-emoji">🚀</span>
            Top 3 qui bougent
          </div>
          <span class="ai-card-sub">7 derniers jours</span>
        </div>
        <?php if (empty($dash_top_movers)): ?>
          <div class="ai-card-empty">
            <div style="font-size:24px; margin-bottom:6px;">🌱</div>
            <div>L'activité va décoller bientôt — continue à publier des messages et clore des étapes.</div>
          </div>
        <?php else: ?>
          <div class="ai-mover-list">
            <?php foreach ($dash_top_movers as $i => $m):
              $trend_pos = $m['trend'] >= 0;
              $trend_lbl = ($trend_pos ? '+' : '') . $m['trend'] . '%';
            ?>
            <a href="/projet/<?= (int)$m['id'] ?>" class="ai-mover">
              <div class="ai-mover-rank"><?= $i + 1 ?></div>
              <div class="ai-mover-info">
                <div class="ai-mover-name"><?= h($m['name']) ?></div>
                <div class="ai-mover-meta">
                  <?php if (!empty($m['folder_name'])): ?><span><?= h($m['folder_name']) ?></span> · <?php endif; ?>
                  <span><?= (int)$m['recent'] ?> action<?= $m['recent'] > 1 ? 's' : '' ?></span>
                </div>
              </div>
              <div class="ai-mover-spark"><?= ak_dash_mini_sparkline($m['spark'], '#10B981') ?></div>
              <span class="ai-mover-pill <?= $trend_pos ? 'is-up' : 'is-down' ?>"><?= $trend_pos ? '↗' : '↘' ?> <?= h($trend_lbl) ?></span>
            </a>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>

      <!-- Échéances -->
      <div class="ai-card">
        <div class="ai-card-head">
          <div class="ai-card-title">
            <span class="ai-card-emoji" style="background:transparent;padding:0;"><?= ak_dash_date_badge(date('Y-m-d')) ?></span>
            Échéances J+7
          </div>
          <span class="ai-card-sub">À garder en vue</span>
        </div>
        <?php if (empty($dash_deadlines)): ?>
          <div class="ai-card-empty">
            <div style="font-size:24px; margin-bottom:6px;">☕</div>
            <div>Aucune échéance dans les 7 prochains jours. Profites-en pour planifier la suite.</div>
          </div>
        <?php else: ?>
          <div class="ai-dl-list">
            <?php foreach ($dash_deadlines as $d):
              $tone = $d['tone'] ?? 'planned';
              $tone_color = [
                'urgent' => '#EF4444',
                'soon' => '#F59E0B',
                'planned' => '#3B82F6',
                'event' => '#6366F1',
              ][$tone] ?? '#6B7280';
              // Couleur réelle de l'événement (agenda) si disponible → aspect calendrier coloré
              if (!empty($d['color']) && is_string($d['color']) && $d['color'][0] === '#') {
                $tone_color = $d['color'];
              }
            ?>
            <a href="<?= htmlspecialchars($d['link'], ENT_QUOTES) ?>" class="ai-dl-row">
              <span class="ai-dl-icon"><?= $d['icon'] /* SVG ou emoji safe */ ?></span>
              <div class="ai-dl-info">
                <div class="ai-dl-name"><?= h($d['label']) ?></div>
                <div class="ai-dl-sub" style="color:<?= $tone_color ?>;">
                  <?php if (!empty($d['date_end'])): ?>
                    <?= h(ak_dash_format_date_range($d['date'], $d['date_end'])) ?>
                  <?php elseif (!empty($d['date'])): ?>
                    <?= h(ak_dash_format_short_date($d['date'])) ?>
                  <?php elseif (isset($d['pct'])): ?>
                    <?= (int)$d['pct'] ?>% — <?= $d['pct'] >= 90 ? 'À clore très bientôt' : ($d['pct'] >= 80 ? 'Presque fini' : 'En vue de fin') ?>
                  <?php endif; ?>
                </div>
              </div>
              <span class="ai-dl-dot" style="background:<?= $tone_color ?>;"></span>
            </a>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>

    </div>
  </section>

  <?php if (($_is_admin_dash || $_is_coord_dash) && ($asso_next_ag || !empty($asso_open_attendance) || !empty($asso_grants_urgent))): ?>
  <section class="dash-asso" aria-label="Vie associative">
    <div class="dash-asso-grid">
      <?php if ($asso_next_ag && $_is_admin_dash):
        $jrs = (int)((strtotime($asso_next_ag['scheduled_at']) - time()) / 86400);
        $hrs = (int)((strtotime($asso_next_ag['scheduled_at']) - time()) / 3600);
        $is_progress = $asso_next_ag['status'] === 'in_progress';
        $countdown = $is_progress ? '🟢 En cours' : ($jrs === 0 ? "Aujourd'hui dans " . max(0,$hrs) . 'h' : ($jrs > 0 ? "J-{$jrs}" : "Passée"));
        $tone = $is_progress ? '#10B981' : ($jrs <= 7 ? '#F59E0B' : '#3B82F6');
      ?>
      <a href="/assemblee/<?= (int)$asso_next_ag['id'] ?>" class="dash-asso-card" style="--asso-tone:<?= $tone ?>;">
        <div class="dash-asso-card-head"><span class="dash-asso-emoji">🏛️</span><span class="dash-asso-tag" style="background:<?= $tone ?>20;color:<?= $tone ?>;"><?= h($countdown) ?></span></div>
        <div class="dash-asso-card-title"><?= h($asso_next_ag['title']) ?></div>
        <div class="dash-asso-card-meta">📅 <?= date('d/m/Y H:i', strtotime($asso_next_ag['scheduled_at'])) ?><?php if ($asso_next_ag['location']): ?> · 📍 <?= h(mb_substr($asso_next_ag['location'], 0, 30)) ?><?php endif; ?></div>
        <div class="dash-asso-card-cta">Ouvrir →</div>
      </a>
      <?php endif; ?>
      <?php if (!empty($asso_open_attendance)): ?>
      <div class="dash-asso-card" style="--asso-tone:#10B981;">
        <div class="dash-asso-card-head"><span class="dash-asso-emoji">✍️</span><span class="dash-asso-tag" style="background:#ECFDF5;color:#065F46;">🟢 <?= count($asso_open_attendance) ?> ouverte<?= count($asso_open_attendance) > 1 ? 's' : '' ?></span></div>
        <div class="dash-asso-card-title">Émargements en cours</div>
        <div class="dash-asso-em-list">
          <?php foreach ($asso_open_attendance as $sx): ?>
          <a href="/emargement/<?= (int)$sx['id'] ?>" class="dash-asso-em-row"><span class="dash-asso-em-name"><?= h(mb_substr($sx['title'], 0, 36)) ?></span><span class="dash-asso-em-stat"><?= (int)$sx['nb_signed'] ?> ✍️</span></a>
          <?php endforeach; ?>
        </div>
        <a href="/emargement" class="dash-asso-card-cta">Tout voir →</a>
      </div>
      <?php endif; ?>
      <?php if (!empty($asso_grants_urgent) && $_is_admin_dash): ?>
      <div class="dash-asso-card" style="--asso-tone:#F59E0B;">
        <div class="dash-asso-card-head"><span class="dash-asso-emoji">💶</span><span class="dash-asso-tag" style="background:#FEF3C7;color:#92400E;">⚠️ <?= count($asso_grants_urgent) ?> urgente<?= count($asso_grants_urgent) > 1 ? 's' : '' ?></span></div>
        <div class="dash-asso-card-title">Subventions à traiter</div>
        <div class="dash-asso-em-list">
          <?php foreach ($asso_grants_urgent as $gx):
            if ($gx['status'] === 'granted' && $gx['deadline_report']) { $j = (int)$gx['jours_report']; $lbl = 'Bilan'; }
            else { $j = (int)$gx['jours_apply']; $lbl = 'Dépôt'; }
            $jcls = $j < 0 ? '#DC2626' : ($j <= 3 ? '#F59E0B' : '#6b7280');
          ?>
          <a href="/subvention/<?= (int)$gx['id'] ?>" class="dash-asso-em-row"><span class="dash-asso-em-name"><?= h(mb_substr($gx['name'], 0, 32)) ?></span><span class="dash-asso-em-stat" style="color:<?= $jcls ?>;"><?= $lbl ?> <?= $j < 0 ? abs($j).'j retard' : 'J-'.$j ?></span></a>
          <?php endforeach; ?>
        </div>
        <a href="/subventions" class="dash-asso-card-cta">Tout voir →</a>
      </div>
      <?php endif; ?>
    </div>
  </section>
  <style>
  .dash-asso { margin: 0 0 28px; }
  .dash-asso-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 14px; }
  .dash-asso-card { display: block; background: #fff; border: 1px solid #e5e7eb; border-left: 3px solid var(--asso-tone, #10B981); border-radius: 12px; padding: 16px 18px; text-decoration: none; color: inherit; transition: all 0.15s; }
  .dash-asso-card:hover { transform: translateY(-2px); box-shadow: 0 6px 16px rgba(0,0,0,0.06); }
  .dash-asso-card-head { display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px; }
  .dash-asso-emoji { font-size: 22px; }
  .dash-asso-tag { font-size: 11px; padding: 3px 10px; border-radius: 999px; font-weight: 700; }
  .dash-asso-card-title { font-size: 14.5px; font-weight: 700; color: #111827; margin-bottom: 4px; }
  .dash-asso-card-meta { font-size: 12px; color: #6b7280; }
  .dash-asso-card-cta { font-size: 12px; color: var(--asso-tone, #10B981); font-weight: 600; margin-top: 10px; display: inline-block; }
  .dash-asso-em-list { display: flex; flex-direction: column; gap: 4px; margin-top: 6px; }
  .dash-asso-em-row { display: flex; justify-content: space-between; align-items: center; gap: 8px; padding: 6px 8px; border-radius: 7px; text-decoration: none; color: inherit; transition: background 0.12s; }
  .dash-asso-em-row:hover { background: #f9fafb; }
  .dash-asso-em-name { font-size: 12.5px; color: #111827; flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
  .dash-asso-em-stat { font-size: 11.5px; color: #6b7280; font-weight: 600; flex-shrink: 0; }
  </style>
  <?php endif; ?>

  <style>
  /* ============================================================
     PACK ESSENTIEL IA — styles namespacés .ai-*
     ============================================================ */
  .ai-pack { margin: 0 0 28px; display: flex; flex-direction: column; gap: 16px; }

  /* Coach IA */
  .ai-coach {
    background: var(--glass, rgba(255,255,255,0.72));
    backdrop-filter: blur(22px) saturate(1.5);
    -webkit-backdrop-filter: blur(22px) saturate(1.5);
    border: 1px solid var(--glass-border, rgba(255,255,255,0.65));
    border-radius: var(--radius-lg, 18px);
    padding: 18px 20px 16px;
    box-shadow: var(--shadow-card, 0 1px 2px rgba(9,30,22,0.04), 0 14px 34px -16px rgba(9,30,22,0.16));
    position: relative;
    overflow: hidden;
  }
  .ai-coach-band {
    position: absolute; inset: 0; pointer-events: none;
    background:
      radial-gradient(120% 100% at 0% 0%, rgba(99,102,241,0.16), transparent 55%),
      radial-gradient(120% 120% at 100% 0%, rgba(139,92,246,0.14), transparent 55%);
  }
  .ai-coach-head {
    display: flex; align-items: center; justify-content: space-between;
    margin-bottom: 14px; gap: 10px; flex-wrap: wrap; position: relative;
  }
  .ai-coach-title {
    display: flex; align-items: center; gap: 11px;
  }
  .ai-coach-icon {
    width: 34px; height: 34px; border-radius: 11px;
    background: linear-gradient(140deg, #8B5CF6, #6366F1);
    color: #fff; flex: none;
    display: inline-flex; align-items: center; justify-content: center;
    box-shadow: 0 8px 20px -6px rgba(99,102,241,0.6), inset 0 1px 0 rgba(255,255,255,0.4);
    position: relative; overflow: hidden;
  }
  .ai-coach-icon svg { position: relative; z-index: 1; }
  .ai-coach-icon::after {
    content: ''; position: absolute; inset: 0;
    background: linear-gradient(115deg, transparent 30%, rgba(255,255,255,0.55) 50%, transparent 70%);
    transform: translateX(-120%);
    animation: ai-coach-sheen 4.5s ease-in-out infinite;
  }
  @keyframes ai-coach-sheen { 0%,70%{transform:translateX(-120%)} 85%,100%{transform:translateX(120%)} }
  .ai-coach-title-txt b { font-size: 15.5px; font-weight: 700; letter-spacing: -0.01em; color: var(--ink, #0B1A13); display: block; }
  .ai-coach-title-txt small { display: block; color: var(--ink-3, #7C8983); font-size: 11.5px; font-weight: 500; margin-top: 1px; }
  .ai-coach-tag {
    font-size: 11.5px; font-weight: 600; color: var(--ai, #6366F1);
    background: var(--ai-light, rgba(99,102,241,0.10)); padding: 6px 12px; border-radius: 999px;
    border: 1px solid rgba(99,102,241,0.2); letter-spacing: 0.01em;
  }
  .ai-coach-list { display: flex; flex-direction: column; gap: 8px; position: relative; }
  .ai-coach-item {
    display: flex; align-items: center; gap: 14px;
    padding: 14px 16px;
    background: var(--bg-2, rgba(255,255,255,0.55));
    border: 1px solid var(--border, rgba(12,40,28,0.06));
    border-radius: 15px;
    text-decoration: none;
    transition: transform 0.18s ease, box-shadow 0.18s ease, border-color 0.18s ease;
    position: relative;
  }
  a.ai-coach-item { color: inherit; }
  .ai-coach-item:hover {
    transform: translateY(-1px);
    box-shadow: var(--shadow-card, 0 1px 2px rgba(9,30,22,0.04), 0 14px 34px -16px rgba(9,30,22,0.16));
    border-color: rgba(99,102,241,0.3);
  }
  .ai-coach-emoji {
    width: 40px; height: 40px; border-radius: 12px;
    background: var(--ai-light, rgba(99,102,241,0.10));
    border: 1px solid rgba(99,102,241,0.18);
    display: flex; align-items: center; justify-content: center;
    font-size: 19px; flex-shrink: 0;
  }
  .ai-coach-item.tone-success .ai-coach-emoji { background: linear-gradient(135deg, #d1fae5, #a7f3d0); border-color: rgba(16,185,129,0.2); }
  .ai-coach-item.tone-warn .ai-coach-emoji { background: linear-gradient(135deg, #fef3c7, #fde68a); border-color: rgba(224,133,12,0.2); }
  .ai-coach-item.tone-info .ai-coach-emoji { background: linear-gradient(135deg, #dbeafe, #bfdbfe); border-color: rgba(47,115,232,0.2); }
  .ai-coach-body { flex: 1; min-width: 0; }
  .ai-coach-item-title { font-size: 14px; font-weight: 650; color: var(--ink, #0B1A13); margin-bottom: 2px; letter-spacing: -0.01em; }
  .ai-coach-item-text { font-size: 12.5px; color: var(--ink-2, #46554E); line-height: 1.5; }
  .ai-coach-item-text strong { color: var(--ink, #0B1A13); font-weight: 600; }
  .ai-coach-arrow { color: var(--ai, #6366F1); flex-shrink: 0; transition: transform 0.18s ease, color 0.18s ease; }
  .ai-coach-item:hover .ai-coach-arrow { transform: translateX(3px); color: #4338CA; }

  /* Row 2 colonnes */
  .ai-row-2col {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 14px;
  }
  @media (max-width: 860px) {
    .ai-row-2col { grid-template-columns: 1fr; }
  }

  /* Card générique */
  .ai-card {
    background: #ffffff;
    border: 1px solid #e5e7eb;
    border-radius: 14px;
    padding: 16px 18px;
    transition: box-shadow 0.2s ease;
  }
  .ai-card:hover { box-shadow: 0 4px 12px rgba(0,0,0,0.04); }
  .ai-card-head {
    display: flex; align-items: center; justify-content: space-between;
    margin-bottom: 12px;
  }
  .ai-card-title {
    font-size: 13px; font-weight: 700; color: #111827;
    display: flex; align-items: center; gap: 8px;
  }
  .ai-card-emoji { font-size: 16px; }
  .ai-card-sub {
    font-size: 11px; color: #9ca3af; text-transform: uppercase;
    letter-spacing: 0.04em; font-weight: 600;
  }
  .ai-card-empty {
    text-align: center; color: #6b7280;
    font-size: 12.5px; padding: 20px 12px;
    line-height: 1.5;
  }

  /* Top movers */
  .ai-mover-list { display: flex; flex-direction: column; gap: 8px; }
  .ai-mover {
    display: flex; align-items: center; gap: 10px;
    padding: 9px 10px;
    background: #fafbff;
    border: 1px solid #f3f4f6;
    border-radius: 10px;
    text-decoration: none;
    color: inherit;
    transition: transform 0.18s ease, background 0.18s ease, border-color 0.18s ease;
  }
  .ai-mover:hover {
    transform: translateX(2px);
    background: #ffffff;
    border-color: #d1d5db;
  }
  .ai-mover-rank {
    width: 22px; height: 22px;
    background: linear-gradient(135deg, #10B981, #059669);
    color: #fff;
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: 11px; font-weight: 700;
    flex-shrink: 0;
  }
  .ai-mover:nth-child(2) .ai-mover-rank { background: linear-gradient(135deg, #6366F1, #4F46E5); }
  .ai-mover:nth-child(3) .ai-mover-rank { background: linear-gradient(135deg, #F59E0B, #D97706); }
  .ai-mover-info { flex: 1; min-width: 0; }
  .ai-mover-name {
    font-size: 13px; font-weight: 600; color: #111827;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
  }
  .ai-mover-meta {
    font-size: 11px; color: #6b7280; margin-top: 2px;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
  }
  .ai-mover-spark { flex-shrink: 0; }
  .ai-mover-pill {
    font-size: 11px; font-weight: 700;
    padding: 3px 8px; border-radius: 999px;
    flex-shrink: 0;
    font-variant-numeric: tabular-nums;
  }
  .ai-mover-pill.is-up { background: #D1FAE5; color: #065F46; }
  .ai-mover-pill.is-down { background: #FEE2E2; color: #991B1B; }

  /* Échéances */
  .ai-dl-list { display: flex; flex-direction: column; gap: 6px; }
  .ai-dl-row {
    display: flex; align-items: center; gap: 10px;
    padding: 9px 10px;
    border-radius: 8px;
    text-decoration: none;
    color: inherit;
    transition: background 0.18s ease;
  }
  .ai-dl-row:hover { background: #f9fafb; }
  .ai-dl-icon {
    width: 32px; height: 32px;
    background: #f3f4f6;
    border-radius: 8px;
    display: flex; align-items: center; justify-content: center;
    font-size: 15px; flex-shrink: 0;
  }
  .ai-dl-info { flex: 1; min-width: 0; }
  .ai-dl-name {
    font-size: 13px; font-weight: 500; color: #111827;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
  }
  .ai-dl-sub {
    font-size: 11px; font-weight: 600; margin-top: 2px;
  }
  .ai-dl-dot {
    width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0;
    box-shadow: 0 0 0 3px rgba(0,0,0,0.04);
  }

  /* Animation */
  .ai-pack { animation: ai-fadein 0.6s ease; }
  @keyframes ai-fadein {
    from { opacity: 0; transform: translateY(8px); }
    to { opacity: 1; transform: translateY(0); }
  }

  /* Mobile */
  @media (max-width: 540px) {
    .ai-coach { padding: 14px 16px; }
    .ai-coach-emoji { width: 32px; height: 32px; font-size: 16px; }
    .ai-coach-item-title { font-size: 12.5px; }
    .ai-coach-item-text { font-size: 11.5px; }
    .ai-card { padding: 14px 14px; }
  }
  </style>

  <!-- ====== QUICK ACTIONS (avec NOUVEAU DOSSIER 🎯) ====== -->
  <div class="section-head">
    <h2>Actions rapides</h2>
  </div>
  <div class="dash-quick-actions">
    <?php if ($user['role'] === 'admin' || !empty($user['can_create_folders'])): ?>
    <a href="/nouveau-dossier" class="dash-action">
      <div class="dash-action-icon" style="background: linear-gradient(135deg, #FEF3C7, #FDE68A); color: #B45309;">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/><line x1="12" y1="11" x2="12" y2="17"/><line x1="9" y1="14" x2="15" y2="14"/></svg>
      </div>
      <div class="dash-action-body">
        <div class="dash-action-title">Nouveau dossier</div>
        <div class="dash-action-desc">Organiser vos projets par thématique</div>
      </div>
    </a>
    <?php endif; ?>
    
    <?php if ($user['can_create_projects'] || $user['role'] === 'admin'): ?>
    <a href="/nouveau-projet" class="dash-action">
      <div class="dash-action-icon" style="background: linear-gradient(135deg, #D1FAE5, #A7F3D0); color: #065F46;">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="12" y1="11" x2="12" y2="17"/><line x1="9" y1="14" x2="15" y2="14"/></svg>
      </div>
      <div class="dash-action-body">
        <div class="dash-action-title">Nouveau projet</div>
        <div class="dash-action-desc">Lancer un nouveau projet</div>
      </div>
    </a>
    <?php endif; ?>
    
    <?php if (in_array($user['role'] ?? '', ['admin', 'coordinator'], true)): ?>
    <a href="/nouveau-adherent" class="dash-action">
      <div class="dash-action-icon" style="background: linear-gradient(135deg, #DBEAFE, #BFDBFE); color: #1E40AF;">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="8.5" cy="7" r="4"/><line x1="20" y1="8" x2="20" y2="14"/><line x1="23" y1="11" x2="17" y2="11"/></svg>
      </div>
      <div class="dash-action-body">
        <div class="dash-action-title">Nouvel adhérent</div>
        <div class="dash-action-desc">Ajouter un membre à l'association</div>
      </div>
    </a>
    <?php endif; ?>
    
    <?php if (function_exists('can') && can('manage_events')): ?>
    <a href="/agenda" class="dash-action">
      <div class="dash-action-icon" style="background: linear-gradient(135deg, #FEE2E2, #FECACA); color: #991B1B;">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/><line x1="12" y1="14" x2="12" y2="18"/><line x1="9" y1="16" x2="15" y2="16"/></svg>
      </div>
      <div class="dash-action-body">
        <div class="dash-action-title">Agenda</div>
        <div class="dash-action-desc">Voir et créer des événements</div>
      </div>
    </a>
    <?php endif; ?>
    
    <?php if ($can_view_finances): ?>
    <a href="/mon-asso-facture-new" class="dash-action">
      <div class="dash-action-icon" style="background: linear-gradient(135deg, #F3E8FF, #E9D5FF); color: #6B21A8;">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
      </div>
      <div class="dash-action-body">
        <div class="dash-action-title">Nouvelle facture</div>
        <div class="dash-action-desc">Créer un devis ou une facture</div>
      </div>
    </a>
    <?php endif; ?>
    
    <a href="/messages" class="dash-action">
      <div class="dash-action-icon" style="background: linear-gradient(135deg, #CFFAFE, #A5F3FC); color: #0E7490;">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
      </div>
      <div class="dash-action-body">
        <div class="dash-action-title">Espace de discussion</div>
        <div class="dash-action-desc">Échanger avec votre équipe</div>
      </div>
    </a>
  </div>

  <!-- ====== MES PROJETS EN COURS ====== -->
  <?php if (!empty($my_projects)): ?>
  <section class="my-projects">
    <div class="section-head">
      <h2>Mes projets en cours</h2>
      <div class="section-head-meta">
        Vous êtes référent sur <?= count($my_projects) ?> projet<?= count($my_projects) > 1 ? 's' : '' ?>
      </div>
    </div>
    <div class="my-proj-grid">
      <?php foreach ($my_projects as $mp): ?>
      <div class="mp-card">
        <div class="mp-head">
          <div class="mp-title-wrap">
            <div class="mp-folder-tag"><?= h($mp['folder_name']) ?></div>
            <div class="mp-title"><?= h($mp['name']) ?></div>
          </div>
          <span class="mp-role">Référent</span>
        </div>
        <div class="mp-progress">
          <div class="mp-bar-bg"><div class="mp-bar" style="width:<?= (int)$mp['progress_percent'] ?>%"></div></div>
          <span class="mp-pct"><?= (int)$mp['progress_percent'] ?> %</span>
        </div>
        <div class="mp-foot">
          <span class="mp-meta">
            <?= $mp['status'] === 'warning' ? 'MAJ à faire' : 'À jour' ?>
          </span>
          <a href="/projet/<?= (int)$mp['id'] ?>" class="mp-update-btn">Mettre à jour →</a>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </section>
  <?php endif; ?>

  <!-- ====== DOSSIERS ====== -->
  <div class="section-head">
    <h2>Vos dossiers de projets</h2>
    <div class="section-head-meta"><a href="/projets">Tout voir →</a></div>
  </div>

  <div class="folder-grid">
    <?php foreach ($folders as $f): ?>
    <a href="/projets#f<?= (int)$f['id'] ?>" class="folder-card">
      <div class="folder-head">
        <div class="folder-icon <?= folder_icon_class($f['color_theme']) ?>">
          <?= folder_icon_svg($f['color_theme']) ?>
        </div>
        <div class="folder-title-wrap">
          <div class="folder-title"><?= h($f['name']) ?></div>
          <div class="folder-count"><?= (int)$f['project_count'] ?> projet<?= $f['project_count'] > 1 ? 's' : '' ?> en cours</div>
        </div>
      </div>
      <div class="folder-progress">
        <div class="folder-bar-bg"><div class="folder-bar" style="width:<?= (int)$f['avg_progress'] ?>%"></div></div>
        <span class="folder-pct"><?= (int)$f['avg_progress'] ?> %</span>
      </div>
      <div class="folder-foot">
        <span><?= (int)$f['total_participants'] ?> participants</span>
        <?php if ($can_view_finances): ?>
          <span><span class="dot">·</span>Budget <?= h(format_budget($f['total_budget'])) ?></span>
        <?php else: ?>
          <span><span class="dot">·</span><?= (int)$f['project_count'] ?> projet<?= $f['project_count'] > 1 ? 's' : '' ?></span>
        <?php endif; ?>
      </div>
    </a>
    <?php endforeach; ?>
  </div>

  <!-- ====== ACTIVITY FEED ====== -->
  <?php if (!empty($activity_feed)): ?>
  <div class="dash-card" style="margin-top:24px;">
    <div class="dash-card-head">
      <div>
        <div class="dash-card-title"><?= ak_icon('bolt',18) ?> Dernière activité</div>
        <div class="dash-card-sub">Ce qui s'est passé récemment dans vos projets</div>
      </div>
    </div>
    <ul class="dash-feed-list">
      <?php 
      $feed_colors = ['blue' => '#3B82F6', 'purple' => '#8B5CF6', 'amber' => '#F59E0B', 'pink' => '#EC4899', 'teal' => '#14B8A6', 'green' => '#10B981', 'red' => '#EF4444'];
      foreach ($activity_feed as $a): 
        $color = $feed_colors[$a['avatar_color'] ?? 'blue'] ?? '#3B82F6';
        $initials = mb_strtoupper(mb_substr($a['first_name'] ?? '?', 0, 1) . mb_substr($a['last_name'] ?? '', 0, 1));
        $author_name = trim(($a['first_name'] ?? '') . ' ' . ($a['last_name'] ?? ''));
        $action_label = '';
        if (isset($a['action_type'])) {
            $labels = [
                'project_created' => 'a créé le projet',
                'step_completed' => 'a complété une étape de',
                'message_sent' => 'a écrit dans',
                'file_uploaded' => 'a ajouté un fichier à',
                'member_added' => 'a ajouté un membre à',
            ];
            $action_label = $labels[$a['action_type']] ?? 'a modifié';
        } else {
            $action_label = 'a écrit dans';
        }
      ?>
      <li class="dash-feed-item">
        <div class="dash-feed-avatar" style="background: <?= $color ?>;"><?= h($initials) ?></div>
        <div class="dash-feed-body">
          <div class="dash-feed-text">
            <strong><?= h($author_name) ?></strong>
            <?= h($action_label) ?>
            <a href="/projet/<?= (int)$a['project_id'] ?>" style="color: var(--acc, #10B981); text-decoration: none; font-weight: 500;"><?= h($a['project_name']) ?></a>
          </div>
          <div class="dash-feed-time"><?= h(ak_dash_time_ago($a['created_at'])) ?></div>
        </div>
      </li>
      <?php endforeach; ?>
    </ul>
  </div>
  <?php endif; ?>

</main>

<script>
// ============================================================
// Animations dashboard premium
// ============================================================

// Compteurs animés (counter 0 → valeur)
document.querySelectorAll('.kpi-counter').forEach(function(el) {
    var target = parseInt(el.dataset.target) || 0;
    if (target === 0) { el.textContent = '0'; return; }
    
    var duration = 1200;
    var start = performance.now();
    
    function easeOutQuart(t) { return 1 - Math.pow(1 - t, 4); }
    
    function update(now) {
        var elapsed = now - start;
        var progress = Math.min(elapsed / duration, 1);
        var eased = easeOutQuart(progress);
        var current = Math.round(target * eased);
        el.textContent = current.toLocaleString('fr-FR');
        if (progress < 1) requestAnimationFrame(update);
    }
    
    setTimeout(function() { requestAnimationFrame(update); }, 100);
});

// Horloge live (mise à jour toutes les 30s)
(function updateClock() {
    var clock = document.getElementById('dashClock');
    if (!clock) return;
    var now = new Date();
    var h = now.getHours().toString().padStart(2, '0');
    var m = now.getMinutes().toString().padStart(2, '0');
    clock.textContent = h + ':' + m;
    setTimeout(updateClock, 30000);
})();
</script>

<?php render_foot(); ?>
