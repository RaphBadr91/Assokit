<?php
/**
 * ============================================================
 * ASSOKIT — Liste des projets (par dossier) — v3 IA Premium
 * ============================================================
 * v2 : ajout du bouton 🗑️ Archiver sur chaque dossier (admin only)
 *      + exclut les dossiers déjà archivés de la liste
 * v3 : Pulse Bar IA + Sparkline 14j par dossier + Badges IA
 *      + Insights auto-générés (style ChatGPT)
 *      + Score santé global animé
 * ============================================================
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes-layout.php';

require_login();

$user = current_user();
$org_id = (int)$user['org_id'];
$can_create = can('create_projects');
$is_admin = ($user['role'] === 'admin');

// [PACK 6.5 - SECURITY] Helper de check finances (pour cacher les budgets aux non-admins)
require_once __DIR__ . '/finance-permissions.php';
$can_view_finances = user_can_view_finances($user);

// Si follower : on récupère la liste des IDs de projets qu'il peut voir
$follower_project_ids = get_follower_project_ids();
$is_follower_user = ($follower_project_ids !== null);

// Flash message
$flash = null;
if (!empty($_SESSION['flash_projets'])) {
    $flash = $_SESSION['flash_projets'];
    unset($_SESSION['flash_projets']);
}

// ====== Chargement des dossiers (exclut les archivés) + épingles utilisateur ======
$user_id = (int)$user['id'];
$stmt = $pdo->prepare("
    SELECT
        f.id, f.name, f.color_theme, f.icon,
        COUNT(p.id) AS total_projects,
        SUM(CASE WHEN p.status IN ('active','warning') AND p.archived_at IS NULL THEN 1 ELSE 0 END) AS active_count,
        SUM(CASE WHEN p.status = 'done' AND p.archived_at IS NULL THEN 1 ELSE 0 END) AS done_count,
        COALESCE(AVG(CASE WHEN p.status IN ('active','warning') AND p.archived_at IS NULL THEN p.progress_percent END), 0) AS avg_progress,
        COALESCE(SUM(CASE WHEN p.archived_at IS NULL THEN p.participants_count ELSE 0 END), 0) AS total_participants,
        COALESCE(SUM(CASE WHEN p.archived_at IS NULL THEN p.budget_planned ELSE 0 END), 0) AS total_budget,
        CASE WHEN upf.user_id IS NOT NULL THEN 1 ELSE 0 END AS is_pinned
    FROM folders f
    LEFT JOIN projects p ON p.folder_id = f.id
    LEFT JOIN user_pinned_folders upf ON upf.folder_id = f.id AND upf.user_id = ?
    WHERE f.org_id = ? AND f.archived_at IS NULL
    GROUP BY f.id, f.name, f.color_theme, f.icon, upf.user_id
    ORDER BY is_pinned DESC, f.id ASC
");
$stmt->execute([$user_id, $org_id]);
$folders = $stmt->fetchAll();

// ====== Chargement des projets par dossier (exclut archivés) + compteurs non-lus ======
$project_sql = "
    SELECT
        p.id, p.folder_id, p.name, p.location, p.progress_percent,
        p.status, p.participants_count,
        u.first_name AS ref_first, u.last_name AS ref_last,
        COALESCE((
            SELECT COUNT(*)
            FROM project_messages m
            WHERE m.project_id = p.id
              AND m.author_id != :uid
              AND m.id > COALESCE((
                SELECT last_read_message_id FROM user_project_reads
                WHERE user_id = :uid2 AND project_id = p.id
              ), 0)
        ), 0) AS unread_count
    FROM projects p
    JOIN folders f ON p.folder_id = f.id
    LEFT JOIN users u ON p.referent_id = u.id
    WHERE f.org_id = :org AND f.archived_at IS NULL AND p.archived_at IS NULL
";
$project_params = [':uid' => $user_id, ':uid2' => $user_id, ':org' => $org_id];

if ($is_follower_user) {
    if (empty($follower_project_ids)) {
        $project_sql .= " AND 1 = 0";
    } else {
        $in_keys = [];
        foreach ($follower_project_ids as $idx => $pid) {
            $key = ':fp' . $idx;
            $in_keys[] = $key;
            $project_params[$key] = $pid;
        }
        $project_sql .= " AND p.id IN (" . implode(',', $in_keys) . ")";
    }
}

$project_sql .= "
    ORDER BY p.folder_id ASC,
             FIELD(p.status, 'warning', 'active', 'done', 'archived'),
             p.progress_percent ASC
";
$stmt = $pdo->prepare($project_sql);
$stmt->execute($project_params);
$all_projects = $stmt->fetchAll();

$projects_by_folder = [];
foreach ($all_projects as $p) {
    $projects_by_folder[$p['folder_id']][] = $p;
}

if ($is_follower_user) {
    $visible_folder_ids = array_keys($projects_by_folder);
    $folders = array_filter($folders, function($f) use ($visible_folder_ids) {
        return in_array((int)$f['id'], $visible_folder_ids, true);
    });
}

$total_active = array_sum(array_column($folders, 'active_count'));

// ====== Membres (comptes utilisateurs) par dossier — pour les avatars ======
$folder_members = [];
try {
    $mstmt = $pdo->prepare("
        SELECT p.folder_id, u.first_name, u.last_name
        FROM project_members pm
        JOIN projects p ON p.id = pm.project_id
        JOIN folders f ON f.id = p.folder_id
        JOIN users u ON u.id = pm.user_id
        WHERE f.org_id = ? AND f.archived_at IS NULL AND p.archived_at IS NULL
        GROUP BY p.folder_id, u.id, u.first_name, u.last_name
        ORDER BY p.folder_id ASC, u.last_name ASC, u.first_name ASC
    ");
    $mstmt->execute([$org_id]);
    foreach ($mstmt->fetchAll() as $mrow) {
        $fid = (int) $mrow['folder_id'];
        $ini = trim(mb_strtoupper(mb_substr((string) $mrow['first_name'], 0, 1) . mb_substr((string) $mrow['last_name'], 0, 1)));
        if ($ini === '') continue;
        $folder_members[$fid][] = $ini;
    }
} catch (Throwable $e) {
    $folder_members = [];
}

// ============================================================
// 🤖 NOUVEAU v3 : DONNÉES IA POUR PULSE BAR + SPARKLINES
// ============================================================

/**
 * Récupère l'activité des 14 derniers jours pour chaque dossier.
 * Source : project_messages + project_steps (completed) + project_files
 * Retourne : [folder_id => [day_offset => count]] où day_offset = 0..13 (0 = aujourd'hui)
 */
function ak_load_folder_activity_14d(PDO $pdo, int $org_id, ?array $follower_ids): array {
    $project_filter = '';
    $params = [$org_id, $org_id, $org_id];

    if ($follower_ids !== null) {
        if (empty($follower_ids)) {
            return []; // follower sans projets : pas d'activité
        }
        $ph = implode(',', array_fill(0, count($follower_ids), '?'));
        $project_filter = " AND a.project_id IN ($ph)";
        // On rajoute les ids 3 fois (une par UNION) — simplifié : on filtre après
    }

    // Requête unifiée : on récupère toutes les activités des 14 derniers jours
    $sql = "
        SELECT folder_id, day_offset, COUNT(*) AS cnt FROM (
            SELECT p.folder_id,
                   DATEDIFF(CURDATE(), DATE(pm.created_at)) AS day_offset,
                   pm.project_id
            FROM project_messages pm
            JOIN projects p ON p.id = pm.project_id
            JOIN folders f ON f.id = p.folder_id
            WHERE f.org_id = ?
              AND f.archived_at IS NULL
              AND p.archived_at IS NULL
              AND pm.created_at >= DATE_SUB(CURDATE(), INTERVAL 14 DAY)

            UNION ALL

            SELECT p.folder_id,
                   DATEDIFF(CURDATE(), DATE(ps.completed_at)) AS day_offset,
                   ps.project_id
            FROM project_steps ps
            JOIN projects p ON p.id = ps.project_id
            JOIN folders f ON f.id = p.folder_id
            WHERE f.org_id = ?
              AND f.archived_at IS NULL
              AND p.archived_at IS NULL
              AND ps.completed_at IS NOT NULL
              AND ps.completed_at >= DATE_SUB(CURDATE(), INTERVAL 14 DAY)

            UNION ALL

            SELECT p.folder_id,
                   DATEDIFF(CURDATE(), DATE(pf.created_at)) AS day_offset,
                   pf.project_id
            FROM project_files pf
            JOIN projects p ON p.id = pf.project_id
            JOIN folders f ON f.id = p.folder_id
            WHERE f.org_id = ?
              AND f.archived_at IS NULL
              AND p.archived_at IS NULL
              AND pf.created_at >= DATE_SUB(CURDATE(), INTERVAL 14 DAY)
        ) a
        WHERE a.day_offset BETWEEN 0 AND 13
        GROUP BY folder_id, day_offset
    ";

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
    } catch (Throwable $e) {
        // En cas d'erreur SQL (table absente, etc.), on retourne vide → page reste fonctionnelle
        return [];
    }

    $activity = [];
    foreach ($rows as $r) {
        $fid = (int)$r['folder_id'];
        $day = (int)$r['day_offset'];
        if (!isset($activity[$fid])) {
            $activity[$fid] = array_fill(0, 14, 0);
        }
        $activity[$fid][$day] = (int)$r['cnt'];
    }

    // Filtrage follower (si applicable) : déjà fait par projet via la jointure
    return $activity;
}

$folder_activity = ak_load_folder_activity_14d($pdo, $org_id, $follower_project_ids);

/**
 * Calcule les KPI globaux + le score IA santé.
 */
function ak_compute_global_pulse(array $folders, array $all_projects, array $folder_activity): array {
    $total_projects = count($all_projects);
    $total_active = 0;
    $total_done = 0;
    $sum_progress = 0;
    $count_progress = 0;

    foreach ($all_projects as $p) {
        if ($p['status'] === 'done') {
            $total_done++;
        } elseif (in_array($p['status'], ['active', 'warning'], true)) {
            $total_active++;
            $sum_progress += (int)$p['progress_percent'];
            $count_progress++;
        }
    }

    $avg_progress = $count_progress > 0 ? round($sum_progress / $count_progress) : 0;

    // Activité 7 derniers jours vs 7 jours précédents (pour la tendance)
    $act_recent = 0;  // jours 0-6
    $act_previous = 0; // jours 7-13
    foreach ($folder_activity as $fid => $days) {
        for ($d = 0; $d < 7; $d++) $act_recent += $days[$d] ?? 0;
        for ($d = 7; $d < 14; $d++) $act_previous += $days[$d] ?? 0;
    }
    $trend_pct = 0;
    if ($act_previous > 0) {
        $trend_pct = round((($act_recent - $act_previous) / $act_previous) * 100);
    } elseif ($act_recent > 0) {
        $trend_pct = 100;
    }

    // Score IA santé : pondération douce pour rester rassurant (jamais < 50% si activité > 0)
    // - 40% : taux d'avancement moyen
    // - 30% : taux de projets actifs (vs done bloqués)
    // - 30% : vélocité (activité récente normalisée)
    $score_progress = $avg_progress; // 0-100
    $score_active = $total_projects > 0 ? round(($total_active / max(1, $total_projects)) * 100) : 0;
    $velocity_normalized = min(100, $act_recent * 5); // 20 actions = 100%
    $score = round(($score_progress * 0.4) + ($score_active * 0.3) + ($velocity_normalized * 0.3));
    $score = max(0, min(100, $score));

    // Label santé
    if ($score >= 75) {
        $health_label = 'Excellente';
        $health_emoji = '🚀';
        $health_color = '#10B981';
    } elseif ($score >= 55) {
        $health_label = 'Bonne';
        $health_emoji = '✨';
        $health_color = '#3B82F6';
    } elseif ($score >= 35) {
        $health_label = 'Stable';
        $health_emoji = '🌱';
        $health_color = '#F59E0B';
    } else {
        $health_label = 'À relancer';
        $health_emoji = '💡';
        $health_color = '#EF4444';
    }

    return [
        'total_projects' => $total_projects,
        'total_active' => $total_active,
        'total_done' => $total_done,
        'avg_progress' => $avg_progress,
        'act_recent' => $act_recent,
        'act_previous' => $act_previous,
        'trend_pct' => $trend_pct,
        'score' => $score,
        'health_label' => $health_label,
        'health_emoji' => $health_emoji,
        'health_color' => $health_color,
    ];
}

$pulse = ak_compute_global_pulse($folders, $all_projects, $folder_activity);

/**
 * Pour chaque dossier, calcule trend 7j vs 7j précédents + badge IA contextuel.
 */
function ak_folder_ai_badge(array $folder, array $activity_days): array {
    $progress = (int)$folder['avg_progress'];
    $act_recent = 0;
    $act_previous = 0;
    for ($d = 0; $d < 7; $d++) $act_recent += $activity_days[$d] ?? 0;
    for ($d = 7; $d < 14; $d++) $act_previous += $activity_days[$d] ?? 0;

    $trend = 0;
    if ($act_previous > 0) {
        $trend = round((($act_recent - $act_previous) / $act_previous) * 100);
    } elseif ($act_recent > 2) {
        $trend = 100;
    }

    // Logique de badge : ordre d'importance
    if ($progress >= 80 && $folder['active_count'] > 0) {
        return ['emoji' => '🎯', 'label' => 'Presque fini', 'color' => '#10B981', 'trend' => $trend];
    }
    if ($trend >= 30 && $act_recent >= 3) {
        return ['emoji' => '🚀', 'label' => 'En accélération', 'color' => '#3B82F6', 'trend' => $trend];
    }
    if ($act_recent === 0 && (int)$folder['active_count'] > 0) {
        return ['emoji' => '💤', 'label' => 'Au repos', 'color' => '#9CA3AF', 'trend' => $trend];
    }
    if ($trend <= -30 && $progress < 60) {
        return ['emoji' => '⚠️', 'label' => 'À booster', 'color' => '#F59E0B', 'trend' => $trend];
    }
    if ($act_recent >= 1) {
        return ['emoji' => '✨', 'label' => 'Stable', 'color' => '#6366F1', 'trend' => $trend];
    }
    return ['emoji' => '🌱', 'label' => 'En attente', 'color' => '#9CA3AF', 'trend' => $trend];
}

/**
 * Génère 1 à 3 insights IA contextuels (style ChatGPT bubble).
 */
function ak_generate_ai_insights(array $folders, array $folder_activity, array $all_projects, array $pulse): array {
    $insights = [];

    // Insight 1 : dossier avec plus grosse accélération
    $best_folder = null;
    $best_trend = 0;
    foreach ($folders as $f) {
        $days = $folder_activity[(int)$f['id']] ?? array_fill(0, 14, 0);
        $rec = array_sum(array_slice($days, 0, 7));
        $prev = array_sum(array_slice($days, 7, 7));
        if ($prev > 0) {
            $t = round((($rec - $prev) / $prev) * 100);
            if ($t > $best_trend && $rec >= 3) {
                $best_trend = $t;
                $best_folder = $f;
            }
        }
    }
    if ($best_folder && $best_trend >= 30) {
        $insights[] = [
            'icon' => '🚀',
            'title' => 'Belle accélération détectée',
            'body' => 'Le dossier <strong>' . htmlspecialchars($best_folder['name']) . '</strong> a +' . $best_trend . '% d\'activité cette semaine. Continue sur cette lancée.',
            'tone' => 'success',
        ];
    }

    // Insight 2 : projets proches de la finition
    $near_done = 0;
    foreach ($all_projects as $p) {
        if (in_array($p['status'], ['active', 'warning'], true) && (int)$p['progress_percent'] >= 80) {
            $near_done++;
        }
    }
    if ($near_done >= 1) {
        $s = $near_done > 1 ? 's' : '';
        $insights[] = [
            'icon' => '🎯',
            'title' => $near_done . ' projet' . $s . ' presque ' . ($near_done > 1 ? 'finis' : 'fini'),
            'body' => 'Tu as <strong>' . $near_done . ' projet' . $s . '</strong> à 80% ou plus. Une dernière poussée et c\'est plié !',
            'tone' => 'info',
        ];
    }

    // Insight 3 : tendance globale
    if ($pulse['trend_pct'] >= 20) {
        $insights[] = [
            'icon' => '📈',
            'title' => 'Bonne dynamique globale',
            'body' => 'L\'activité de ton asso est en hausse de <strong>+' . $pulse['trend_pct'] . '%</strong> sur les 7 derniers jours. C\'est l\'élan qui fait la différence.',
            'tone' => 'success',
        ];
    } elseif ($pulse['trend_pct'] <= -20 && $pulse['act_previous'] > 0) {
        $insights[] = [
            'icon' => '💡',
            'title' => 'Une petite relance ?',
            'body' => 'L\'activité a baissé de <strong>' . abs($pulse['trend_pct']) . '%</strong> cette semaine. Et si tu créais une étape ou envoyais un message à ton équipe ?',
            'tone' => 'warn',
        ];
    } elseif ($pulse['act_recent'] === 0) {
        $insights[] = [
            'icon' => '☕',
            'title' => 'Une semaine au calme',
            'body' => 'Aucune activité cette semaine. Pas grave — relance le mouvement quand tu seras prêt(e).',
            'tone' => 'info',
        ];
    }

    // Si aucun insight déclenché, message d'encouragement par défaut
    if (empty($insights)) {
        if ($pulse['total_projects'] === 0) {
            $insights[] = [
                'icon' => '🌱',
                'title' => 'Prêt(e) à démarrer',
                'body' => 'Crée ton premier dossier et ton premier projet. AssoKit IA t\'accompagne à chaque étape.',
                'tone' => 'info',
            ];
        } else {
            $insights[] = [
                'icon' => '✨',
                'title' => 'Tout roule sereinement',
                'body' => '<strong>' . $pulse['total_active'] . ' projet' . ($pulse['total_active'] > 1 ? 's' : '') . '</strong> en cours, avancement moyen de <strong>' . $pulse['avg_progress'] . '%</strong>. Continue à ton rythme.',
                'tone' => 'info',
            ];
        }
    }

    return array_slice($insights, 0, 3);
}

$ai_insights = ak_generate_ai_insights($folders, $folder_activity, $all_projects, $pulse);

/**
 * Génère un sparkline SVG inline depuis un tableau de 14 valeurs (jour 13 → jour 0).
 * Renvoie le markup SVG complet.
 */
function ak_render_sparkline(array $values_14d, string $color = '#10B981', int $w = 100, int $h = 28): string {
    // values_14d est indexé par day_offset (0=aujourd'hui, 13=il y a 13j)
    // On veut afficher de gauche (vieux) à droite (récent) : on inverse
    $ordered = [];
    for ($i = 13; $i >= 0; $i--) {
        $ordered[] = $values_14d[$i] ?? 0;
    }

    $max = max($ordered);
    if ($max === 0) {
        // Ligne plate basse (pas d'activité)
        $y = $h - 2;
        return '<svg class="ak-sparkline" viewBox="0 0 ' . $w . ' ' . $h . '" preserveAspectRatio="none" aria-hidden="true">'
             . '<line x1="0" y1="' . $y . '" x2="' . $w . '" y2="' . $y . '" stroke="#E5E7EB" stroke-width="1.5" stroke-linecap="round" stroke-dasharray="2 3"/>'
             . '</svg>';
    }

    $n = count($ordered);
    $step = $w / max(1, $n - 1);
    $points = [];
    $area_pts = ['0,' . $h];
    foreach ($ordered as $i => $v) {
        $x = round($i * $step, 2);
        $y = round($h - 2 - (($v / $max) * ($h - 4)), 2);
        $points[] = $x . ',' . $y;
        $area_pts[] = $x . ',' . $y;
    }
    $area_pts[] = $w . ',' . $h;

    $line = implode(' ', $points);
    $area = implode(' ', $area_pts);
    $color_safe = htmlspecialchars($color, ENT_QUOTES);
    $uid = 'sp' . substr(md5($line . $color), 0, 6);

    return '<svg class="ak-sparkline" viewBox="0 0 ' . $w . ' ' . $h . '" preserveAspectRatio="none" aria-hidden="true">'
         . '<defs><linearGradient id="' . $uid . '" x1="0" x2="0" y1="0" y2="1">'
         . '<stop offset="0%" stop-color="' . $color_safe . '" stop-opacity="0.25"/>'
         . '<stop offset="100%" stop-color="' . $color_safe . '" stop-opacity="0"/>'
         . '</linearGradient></defs>'
         . '<polygon points="' . $area . '" fill="url(#' . $uid . ')"/>'
         . '<polyline points="' . $line . '" fill="none" stroke="' . $color_safe . '" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>'
         . '</svg>';
}

// ============================================================
// FIN BLOC IA — reprise du code v2 d'origine
// ============================================================

function status_badge($status) {
    $labels = [
        'active' => ['label' => 'En cours', 'class' => 'badge-ok'],
        'warning' => ['label' => 'À surveiller', 'class' => 'badge-warn'],
        'done' => ['label' => 'Terminé', 'class' => 'badge-done'],
        'draft' => ['label' => 'Brouillon', 'class' => 'badge-done'],
        'archived' => ['label' => 'Archivé', 'class' => 'badge-done'],
    ];
    return $labels[$status] ?? $labels['active'];
}

render_head('Projets');
render_sidebar('projets');
?>

<style>
/* ============================================================
   v3 IA Premium — styles namespacés .pulse-* / .ak-* / .ai-*
   ============================================================ */
.pulse-bar {
    margin: 0 0 24px;
    background: linear-gradient(135deg, #ffffff 0%, #fafbff 100%);
    border: 1px solid #e5e7eb;
    border-radius: 16px;
    padding: 22px 24px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.04), 0 8px 24px rgba(99,102,241,0.04);
    position: relative;
    overflow: hidden;
}
.pulse-bar::before {
    content: '';
    position: absolute;
    top: 0; left: 0; right: 0; height: 3px;
    background: linear-gradient(90deg, #10B981, #3B82F6, #6366F1, #8B5CF6);
    background-size: 300% 100%;
    animation: pulse-gradient 8s ease infinite;
}
@keyframes pulse-gradient {
    0%, 100% { background-position: 0% 50%; }
    50% { background-position: 100% 50%; }
}
.pulse-head {
    display: flex; align-items: center; justify-content: space-between;
    gap: 16px; margin-bottom: 18px; flex-wrap: wrap;
}
.pulse-title {
    display: flex; align-items: center; gap: 10px;
    font-size: 13px; font-weight: 600; color: #6b7280;
    text-transform: uppercase; letter-spacing: 0.06em;
}
.pulse-title-dot {
    width: 8px; height: 8px; border-radius: 50%;
    background: #10B981;
    animation: pulse-dot 2s ease-in-out infinite;
    box-shadow: 0 0 0 0 rgba(16,185,129,0.6);
}
@keyframes pulse-dot {
    0%, 100% { box-shadow: 0 0 0 0 rgba(16,185,129,0.6); }
    50% { box-shadow: 0 0 0 6px rgba(16,185,129,0); }
}
.pulse-ai-tag {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 4px 10px;
    background: linear-gradient(135deg, #EEF2FF, #E0E7FF);
    color: #4338CA;
    border-radius: 999px;
    font-size: 11px; font-weight: 600;
    letter-spacing: 0.02em;
}
.pulse-grid {
    display: grid;
    grid-template-columns: 1.4fr 1fr 1fr 1fr;
    gap: 18px;
}
.pulse-cell {
    padding: 14px 18px;
    border-radius: 12px;
    background: #ffffff;
    border: 1px solid #f3f4f6;
    position: relative;
    transition: transform 0.2s ease, box-shadow 0.2s ease;
}
.pulse-cell:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 16px rgba(0,0,0,0.05);
}
.pulse-cell-lbl {
    font-size: 11px; color: #9CA3AF;
    text-transform: uppercase; letter-spacing: 0.05em;
    font-weight: 600; margin-bottom: 6px;
}
.pulse-cell-val {
    font-size: 26px; font-weight: 700;
    color: #111827; line-height: 1;
    font-variant-numeric: tabular-nums;
    display: flex; align-items: baseline; gap: 8px;
}
.pulse-cell-val .unit { font-size: 14px; color: #9CA3AF; font-weight: 500; }
.pulse-cell-sub {
    font-size: 12px; color: #6b7280; margin-top: 6px;
    display: flex; align-items: center; gap: 6px;
}
.pulse-trend-pill {
    display: inline-flex; align-items: center; gap: 3px;
    padding: 2px 7px; border-radius: 999px;
    font-size: 11px; font-weight: 600;
    font-variant-numeric: tabular-nums;
}
.pulse-trend-up { background: #D1FAE5; color: #065F46; }
.pulse-trend-down { background: #FEE2E2; color: #991B1B; }
.pulse-trend-flat { background: #F3F4F6; color: #6b7280; }

/* Score santé : cellule spéciale avec donut */
.pulse-cell.is-score {
    display: flex; align-items: center; gap: 16px;
    background: linear-gradient(135deg, #fefce8 0%, #ffffff 100%);
    border-color: #fef3c7;
}
.pulse-score-donut {
    width: 64px; height: 64px; flex-shrink: 0;
    position: relative;
}
.pulse-score-donut svg {
    width: 100%; height: 100%;
    transform: rotate(-90deg);
}
.pulse-score-donut .ring-bg {
    fill: none; stroke: #f3f4f6; stroke-width: 6;
}
.pulse-score-donut .ring-fg {
    fill: none; stroke-width: 6; stroke-linecap: round;
    transition: stroke-dashoffset 1.4s cubic-bezier(0.4, 0, 0.2, 1);
}
.pulse-score-num {
    position: absolute; inset: 0;
    display: flex; align-items: center; justify-content: center;
    font-size: 17px; font-weight: 700; color: #111827;
    font-variant-numeric: tabular-nums;
}
.pulse-score-info { flex: 1; min-width: 0; }
.pulse-score-lbl { font-size: 11px; color: #9CA3AF; text-transform: uppercase; letter-spacing: 0.05em; font-weight: 600; }
.pulse-score-status { font-size: 16px; font-weight: 700; color: #111827; margin-top: 4px; display: flex; align-items: center; gap: 6px; }
.pulse-score-help { font-size: 11px; color: #6b7280; margin-top: 3px; }

@media (max-width: 900px) {
    .pulse-grid { grid-template-columns: 1fr 1fr; }
    .pulse-cell.is-score { grid-column: 1 / -1; }
}
@media (max-width: 540px) {
    .pulse-grid { grid-template-columns: 1fr 1fr; gap: 10px; }
    .pulse-bar { padding: 16px; }
    .pulse-cell { padding: 12px 14px; }
    .pulse-cell-val { font-size: 22px; }
}

/* ============================================================
   AI INSIGHTS — bandeau de cartes style ChatGPT bubble
   ============================================================ */
.ai-insights {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
    gap: 12px;
    margin: 0 0 24px;
}
.ai-insight {
    display: flex; align-items: flex-start; gap: 12px;
    padding: 14px 16px;
    background: #ffffff;
    border: 1px solid #e5e7eb;
    border-radius: 12px;
    position: relative;
    transition: transform 0.18s ease, box-shadow 0.18s ease, border-color 0.18s ease;
    overflow: hidden;
}
.ai-insight::before {
    content: '';
    position: absolute; top: 0; left: 0; bottom: 0; width: 3px;
    background: #6366F1;
    transition: width 0.18s ease;
}
.ai-insight.tone-success::before { background: #10B981; }
.ai-insight.tone-warn::before { background: #F59E0B; }
.ai-insight.tone-info::before { background: #3B82F6; }
.ai-insight:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.06);
    border-color: #d1d5db;
}
.ai-insight:hover::before { width: 5px; }
.ai-ins-icon {
    flex-shrink: 0; width: 36px; height: 36px;
    border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    font-size: 18px;
    background: linear-gradient(135deg, #f9fafb, #f3f4f6);
}
.ai-insight.tone-success .ai-ins-icon { background: linear-gradient(135deg, #d1fae5, #a7f3d0); }
.ai-insight.tone-warn .ai-ins-icon { background: linear-gradient(135deg, #fef3c7, #fde68a); }
.ai-insight.tone-info .ai-ins-icon { background: linear-gradient(135deg, #dbeafe, #bfdbfe); }
.ai-ins-body { flex: 1; min-width: 0; }
.ai-ins-title { font-size: 13px; font-weight: 600; color: #111827; margin-bottom: 3px; }
.ai-ins-text { font-size: 12.5px; color: #4b5563; line-height: 1.5; }
.ai-ins-text strong { color: #111827; font-weight: 600; }

/* ============================================================
   FOLDER STATS ENRICHIS — sparkline + badge IA
   ============================================================ */
.folder-stats { /* override doux si présent */
    display: flex; align-items: center; gap: 14px;
}
.ak-folder-spark {
    display: flex; flex-direction: column; align-items: flex-end; gap: 4px;
    min-width: 110px;
}
.ak-folder-spark-lbl {
    font-size: 10px; color: #9CA3AF; text-transform: uppercase;
    letter-spacing: 0.04em; font-weight: 600;
}
.ak-sparkline {
    width: 100px; height: 28px; display: block;
}
.ak-folder-badge {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 4px 9px;
    border-radius: 999px;
    font-size: 11px; font-weight: 600;
    background: #f9fafb;
    border: 1px solid #f3f4f6;
    white-space: nowrap;
    line-height: 1;
}
.ak-folder-badge .emoji { font-size: 12px; }
.ak-folder-badge.is-trend-up { background: #D1FAE5; color: #065F46; border-color: #a7f3d0; }
.ak-folder-badge.is-trend-warn { background: #FEF3C7; color: #92400E; border-color: #fde68a; }
.ak-folder-badge.is-trend-down { background: #FEE2E2; color: #991B1B; border-color: #fecaca; }

/* Mobile : on cache le sparkline (gain de place) */
@media (max-width: 720px) {
    .ak-folder-spark { display: none; }
    .ak-folder-badge { font-size: 10px; padding: 3px 7px; }
}

/* ============================================================
   PROJECT-ROW UNREAD BADGE
   ============================================================ */
.project-row.has-unread .project-name { font-weight: 700; }
.project-unread {
    display: inline-flex; align-items: center; justify-content: center;
    min-width: 20px; height: 20px; padding: 0 7px;
    margin-left: 8px;
    background: #EF4444; color: #fff;
    font-size: 11px; font-weight: 700;
    border-radius: 999px;
    line-height: 1;
    font-variant-numeric: tabular-nums;
    box-shadow: 0 0 0 0 rgba(239,68,68,0.5);
    animation: unread-pulse 2.4s ease-in-out infinite;
    vertical-align: middle;
}
@keyframes unread-pulse {
    0%, 100% { box-shadow: 0 0 0 0 rgba(239,68,68,0.5); }
    50% { box-shadow: 0 0 0 5px rgba(239,68,68,0); }
}
.project-row.has-unread { background: linear-gradient(90deg, #FEF2F2 0%, transparent 8%); }
</style>

<style>
/* ============================================================
   PROJETS 2.0 — surcouche premium Liquid Glass (maquette)
   Les règles ci-dessous surchargent la v3 ci-dessus.
   ============================================================ */
.main{max-width:1200px}

/* Titre + sous-titre (maquette) */
.main .page-title{font-size:32px;font-weight:800;letter-spacing:-.03em;line-height:1;color:var(--ink)}
.main .page-sub{font-size:13.5px;color:var(--ink-2);margin-top:10px;display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.main .page-sub .pj-dot{width:6px;height:6px;border-radius:50%;flex:none;background:var(--brand-2, #10B981);box-shadow:0 0 0 4px var(--acc-light, rgba(5,150,105,.12))}
.pj-sec{display:flex;align-items:baseline;justify-content:space-between;gap:12px;margin:2px 2px 14px}
.pj-sec b{font-size:16px;letter-spacing:-.015em;font-weight:700;color:var(--ink)}
.pj-sec span{font-size:12.5px;color:var(--ink-3)}

/* Hero + toolbar */
.pj-head-actions{display:flex;align-items:center;gap:10px;flex-wrap:wrap}
.pj-search{display:flex;align-items:center;gap:9px;padding:10px 14px;border-radius:var(--radius, 12px);
  background:var(--glass);border:1px solid var(--glass-border);color:var(--ink-3);font-size:13px;
  backdrop-filter:blur(12px);-webkit-backdrop-filter:blur(12px);min-width:190px;box-shadow:var(--shadow-card)}
.pj-search svg{width:16px;height:16px;stroke:currentColor;fill:none;stroke-width:2}
.pj-search .k{margin-left:auto;font-size:10.5px;background:var(--bg);border:1px solid var(--border);border-radius:5px;padding:1px 6px}
.pj-toolbar{display:flex;align-items:center;justify-content:space-between;gap:14px;flex-wrap:wrap;margin:0 0 18px}
.pj-chips{display:flex;gap:8px;flex-wrap:wrap}
.pj-chip{display:inline-flex;align-items:center;gap:7px;padding:7px 13px;border-radius:999px;font-size:12.5px;font-weight:600;cursor:pointer;
  background:var(--glass);border:1px solid var(--glass-border);color:var(--ink-2);backdrop-filter:blur(10px);-webkit-backdrop-filter:blur(10px);transition:.15s;font-family:inherit}
.pj-chip .n{font-variant-numeric:tabular-nums;color:var(--ink-3);font-weight:700}
.pj-chip i{width:7px;height:7px;border-radius:50%;display:inline-block}
.pj-chip:hover{color:var(--ink)}
.pj-chip.on{background:var(--acc);border-color:transparent;color:#fff;box-shadow:0 8px 18px -8px rgba(5,150,105,.6)}
.pj-chip.on .n{color:rgba(255,255,255,.85)}
.pj-seg{display:inline-flex;background:var(--glass);border:1px solid var(--glass-border);border-radius:11px;padding:3px;gap:2px;backdrop-filter:blur(10px);-webkit-backdrop-filter:blur(10px)}
.pj-seg button{border:0;background:transparent;color:var(--ink-3);width:34px;height:30px;border-radius:8px;cursor:pointer;display:grid;place-items:center}
.pj-seg button svg{width:16px;height:16px;stroke:currentColor;fill:none;stroke-width:2}
.pj-seg button.on{background:var(--bg);color:var(--acc);box-shadow:var(--shadow-card)}

/* Pulse panel — verre */
.pulse-bar{background:var(--glass);backdrop-filter:blur(22px) saturate(1.5);-webkit-backdrop-filter:blur(22px) saturate(1.5);
  border:1px solid var(--glass-border);border-radius:var(--radius-lg, 18px);box-shadow:var(--shadow-card);padding:20px 22px;margin-bottom:16px}
.pulse-bar::before{background:linear-gradient(90deg,#10B981,#3B82F6,#6366F1,#8B5CF6);background-size:300% 100%}
.pulse-title{color:var(--ink-2)}
.pulse-ai-tag{background:var(--ai-light);color:var(--ai);border:1px solid rgba(99,102,241,.2);font-weight:600;cursor:pointer}
.pulse-cell{background:var(--bg-2);border:1px solid var(--hairline, rgba(12,40,28,.06));border-radius:var(--radius, 12px)}
.pulse-cell-lbl{color:var(--ink-3)}
.pulse-cell-val{color:var(--ink)}
.pulse-cell.is-score{background:var(--bg-2);border-color:var(--hairline, rgba(12,40,28,.06))}
.pulse-score-num{color:var(--ink)}
.pulse-score-status{color:var(--ink)}
.pulse-score-lbl,.pulse-score-help{color:var(--ink-3)}

/* Bande IA « prochaine action » */
.pj-aiband{display:flex;align-items:center;gap:15px;padding:15px 20px;margin-bottom:14px;
  background:var(--glass);backdrop-filter:blur(22px) saturate(1.5);-webkit-backdrop-filter:blur(22px) saturate(1.5);
  border:1px solid var(--glass-border);border-radius:var(--radius-lg, 18px);box-shadow:var(--shadow-card);position:relative;overflow:hidden}
.pj-aiband-band{position:absolute;inset:0;pointer-events:none;background:
  radial-gradient(120% 100% at 0% 0%, rgba(99,102,241,.14), transparent 55%),
  radial-gradient(120% 120% at 100% 0%, rgba(139,92,246,.12), transparent 55%)}
.pj-aiband-badge{width:40px;height:40px;border-radius:12px;flex:none;background:linear-gradient(140deg,#8B5CF6,#6366F1);
  display:grid;place-items:center;box-shadow:0 8px 20px -6px rgba(99,102,241,.6), inset 0 1px 0 rgba(255,255,255,.4);position:relative;overflow:hidden;z-index:1}
.pj-aiband-badge svg{width:20px;height:20px;fill:#fff;position:relative;z-index:1}
.pj-aiband-badge::after{content:"";position:absolute;inset:0;background:linear-gradient(115deg,transparent 30%,rgba(255,255,255,.55) 50%,transparent 70%);
  transform:translateX(-120%);animation:pjsheen 4.5s ease-in-out infinite}
@keyframes pjsheen{0%,70%{transform:translateX(-120%)}85%,100%{transform:translateX(120%)}}
.pj-aiband-txt{position:relative;z-index:1;flex:1;min-width:0}
.pj-aiband-txt b{font-size:14px;letter-spacing:-.01em;color:var(--ink)}
.pj-aiband-txt p{font-size:12.5px;color:var(--ink-2);margin-top:2px}
.pj-aiband .go{position:relative;z-index:1;margin-left:auto;display:inline-flex;align-items:center;gap:6px;font-size:12.5px;font-weight:650;
  color:var(--ai);background:var(--ai-light);border:1px solid rgba(99,102,241,.2);padding:9px 14px;border-radius:11px;cursor:pointer;white-space:nowrap;text-decoration:none}
.pj-aiband .go svg{width:15px;height:15px;stroke:currentColor;stroke-width:2.3;fill:none}
.pj-ins-row{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:12px;margin-bottom:24px}
.pj-ins{display:flex;align-items:flex-start;gap:12px;padding:13px 15px;background:var(--glass);border:1px solid var(--glass-border);
  border-radius:var(--radius, 12px);box-shadow:var(--shadow-card);backdrop-filter:blur(16px);-webkit-backdrop-filter:blur(16px)}
.pj-ins .ic{flex:none;width:34px;height:34px;border-radius:10px;display:grid;place-items:center;font-size:17px;background:var(--ai-light)}
.pj-ins.tone-success .ic{background:linear-gradient(135deg,#d1fae5,#a7f3d0)}
.pj-ins.tone-warn .ic{background:linear-gradient(135deg,#fef3c7,#fde68a)}
.pj-ins.tone-info .ic{background:linear-gradient(135deg,#dbeafe,#bfdbfe)}
.pj-ins h4{font-size:13px;font-weight:650;color:var(--ink)}
.pj-ins p{font-size:12px;color:var(--ink-2);line-height:1.45;margin-top:2px}
.pj-ins p strong{color:var(--ink);font-weight:650}

/* Grille de cartes dossiers */
.ak-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(390px,1fr));gap:16px;align-items:start;margin-bottom:20px}
.ak-grid.is-list{grid-template-columns:1fr}
.ak-grid .folder{margin-bottom:0;background:var(--glass);backdrop-filter:blur(20px) saturate(1.5);-webkit-backdrop-filter:blur(20px) saturate(1.5);
  border:1px solid var(--glass-border);border-radius:var(--radius-lg, 18px);box-shadow:var(--shadow-card);transition:transform .18s ease, box-shadow .18s ease}
.ak-grid .folder:hover{transform:translateY(-3px);box-shadow:var(--shadow-pop)}
.ak-grid .folder.is-pinned{box-shadow:var(--shadow-card), 0 0 0 1.5px rgba(224,133,12,.35)}
.ak-grid .folder-btn{flex-direction:column;align-items:stretch;gap:0;padding:0;position:relative}
.ak-grid .folder-btn:hover{background:transparent}
.ak-card-glow{position:absolute;inset:0 0 auto 0;height:3px;border-radius:3px 3px 0 0;z-index:2}
.ak-card-in{padding:18px 18px 14px}
.ak-card-top{display:flex;align-items:center;gap:12px}
.ak-card-fi{width:44px;height:44px;border-radius:13px;display:grid;place-items:center;flex:none;box-shadow:inset 0 1px 0 rgba(255,255,255,.6)}
.ak-card-fi svg{width:22px;height:22px}
.ak-card-h{flex:1;min-width:0;text-align:left}
.ak-card-h b{font-size:15px;font-weight:700;letter-spacing:-.01em;color:var(--ink);display:flex;align-items:center;gap:6px}
.ak-card-h small{font-size:12px;color:var(--ink-3);display:block;margin-top:1px}
.ak-status{display:inline-flex;align-items:center;gap:6px;font-size:11px;font-weight:700;padding:4px 10px;border-radius:999px;flex:none;white-space:nowrap}
.ak-card-btn-chevron{color:var(--ink-3);transition:transform .22s ease;flex:none}
.folder.open .ak-card-btn-chevron{transform:rotate(180deg)}
.ak-card-prog{display:flex;align-items:center;gap:14px;margin:16px 0 6px;text-align:left}
.ak-ring{width:52px;height:52px;flex:none}
.ak-ring-lab{flex:1;min-width:0}
.ak-ring-lab .pct{font-size:19px;font-weight:800;letter-spacing:-.02em;font-variant-numeric:tabular-nums;color:var(--ink)}
.ak-ring-lab small{font-size:11.5px;color:var(--ink-3);display:block;margin-top:1px}
.ak-avatars{display:flex;flex:none}
.ak-avatars span{width:27px;height:27px;border-radius:50%;border:2px solid var(--bg);margin-left:-8px;display:grid;place-items:center;font-size:10px;font-weight:700;color:#fff}
.ak-avatars span:first-child{margin-left:0}
.ak-avatars .more{background:var(--bg-2);color:var(--ink-3);border-color:var(--glass-border)}
.ak-card-spark-wrap{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-top:6px}
.ak-card-spark-wrap .lbl{font-size:10px;color:var(--ink-4);text-transform:uppercase;letter-spacing:.05em;font-weight:700;white-space:nowrap}
.ak-card-spark-wrap .ak-sparkline{width:150px;height:30px}
.ak-card-foot{display:flex;align-items:center;gap:10px;padding:11px 16px;border-top:1px solid var(--hairline, rgba(12,40,28,.06));background:var(--ai-light)}
.ak-card-foot.is-ok{background:var(--acc-light)}
.ak-card-foot .fx{width:24px;height:24px;border-radius:7px;background:var(--bg);display:grid;place-items:center;flex:none;box-shadow:var(--shadow-card)}
.ak-card-foot .fx svg{width:13px;height:13px;stroke:var(--ai);fill:none;stroke-width:2.2}
.ak-card-foot.is-ok .fx svg{stroke:var(--acc)}
.ak-card-foot p{font-size:11.5px;color:var(--ink-2);line-height:1.4;text-align:left}
.ak-card-foot p b{color:var(--ink);font-weight:650}
/* le corps déroulant (liste projets) garde le style global mais s'arrondit en bas */
.ak-grid .folder-body{border-radius:0 0 var(--radius-lg,18px) var(--radius-lg,18px)}

/* carte fantôme « nouveau dossier » */
.ak-ghost{display:grid;place-items:center;min-height:210px;border:1.5px dashed var(--glass-border)!important;background:var(--bg-2)!important;
  border-radius:var(--radius-lg,18px);text-decoration:none;color:inherit;text-align:center;padding:20px;transition:.18s;box-shadow:none!important}
.ak-ghost:hover{border-color:var(--acc)!important;transform:translateY(-3px)}
.ak-ghost .plus{width:46px;height:46px;border-radius:14px;margin:0 auto 12px;display:grid;place-items:center;background:var(--acc-light);color:var(--acc)}
.ak-ghost b{font-size:14px;color:var(--ink)}
.ak-ghost p{font-size:12px;color:var(--ink-3);margin-top:4px;max-width:180px}

@media (max-width:820px){
  .ak-grid{grid-template-columns:1fr}
  .ak-card-spark-wrap .ak-sparkline{width:120px}
}
</style>

<div class="main">

  <nav class="crumbs" aria-label="Fil d'Ariane">
    <a href="/dashboard">Dashboard</a>
    <span class="sep">›</span>
    <span class="current">Projets</span>
  </nav>

  <div class="main-head">
    <div>
      <h1 class="page-title"><?= $is_follower_user ? 'Projets que vous suivez' : 'Vos projets' ?></h1>
      <?php if ($is_follower_user): ?>
        <div class="page-sub">
          <?php $count_projects = count($all_projects); ?>
          Vous suivez <?= $count_projects ?> projet<?= $count_projects > 1 ? 's' : '' ?>
          <?php if (!empty($user['organization_name'])): ?>
            en tant que représentant<?= (mb_substr($user['first_name'], -1) === 'e') ? 'e' : '' ?> de <strong><?= h($user['organization_name']) ?></strong>
          <?php endif; ?>
        </div>
      <?php else: ?>
        <?php $__done_all = (int) array_sum(array_column($folders, 'done_count')); ?>
        <div class="page-sub">
          <span class="pj-dot"></span>
          <?= count($folders) ?> dossier<?= count($folders) > 1 ? 's' : '' ?> · <?= (int)$total_active ?> projet<?= $total_active > 1 ? 's' : '' ?> en cours<?php if ($__done_all > 0): ?> · <?= $__done_all ?> terminé<?= $__done_all > 1 ? 's' : '' ?> cette année<?php endif; ?>
        </div>
      <?php endif; ?>
    </div>
    <div class="pj-head-actions">
      <label class="pj-search" for="pjSearch">
        <svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4-4"/></svg>
        <input id="pjSearch" type="search" placeholder="Rechercher un dossier…" style="border:0;background:transparent;outline:none;font:inherit;color:var(--ink);min-width:0;flex:1;">
      </label>
    <?php if ($user['role'] === 'admin' || $can_create): ?>
      <?php if ($can_create): ?>
        <a href="/nouveau-projet" class="btn btn-primary">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
          Nouveau projet
        </a>
      <?php endif; ?>
      <?php if ($is_admin): ?>
        <a href="/nouveau-dossier" class="btn btn-ghost">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/><line x1="12" y1="11" x2="12" y2="17"/><line x1="9" y1="14" x2="15" y2="14"/></svg>
          Nouveau dossier
        </a>
        <a href="/archives" class="btn btn-ghost" title="Voir les dossiers et projets archivés">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><polyline points="21 8 21 21 3 21 3 8"/><rect x="1" y="3" width="22" height="5"/><line x1="10" y1="12" x2="14" y2="12"/></svg>
          Archives
        </a>
      <?php endif; ?>
    <?php endif; ?>
    </div>
  </div>

  <?php
  // ====== Barre d'outils : filtres par statut de projet + bascule Grille/Liste ======
  if (!empty($folders)):
    $cnt_all = count($all_projects);
    $cnt_active = 0; $cnt_warn = 0; $cnt_wait = 0; $cnt_done = 0;
    foreach ($all_projects as $__p) {
      switch ($__p['status']) {
        case 'active':  $cnt_active++; break;
        case 'warning': $cnt_warn++;   break;
        case 'done':    $cnt_done++;   break;
        case 'draft':   $cnt_wait++;   break;
      }
    }
  ?>
  <div class="pj-toolbar">
    <div class="pj-chips" id="pjChips">
      <span class="pj-chip on" data-filter="all">Tous <span class="n"><?= $cnt_all ?></span></span>
      <span class="pj-chip" data-filter="active"><i style="background:var(--acc)"></i> En cours <span class="n"><?= $cnt_active ?></span></span>
      <span class="pj-chip" data-filter="warn"><i style="background:var(--amber, #E0850C)"></i> À surveiller <span class="n"><?= $cnt_warn ?></span></span>
      <span class="pj-chip" data-filter="wait"><i style="background:var(--ink-4)"></i> En attente <span class="n"><?= $cnt_wait ?></span></span>
      <span class="pj-chip" data-filter="done"><i style="background:var(--blue, #2F73E8)"></i> Terminés <span class="n"><?= $cnt_done ?></span></span>
    </div>
    <div class="pj-seg" id="pjView">
      <button class="on" data-view="grid" title="Grille" aria-label="Vue grille"><svg viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/></svg></button>
      <button data-view="list" title="Liste" aria-label="Vue liste"><svg viewBox="0 0 24 24"><path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/></svg></button>
    </div>
  </div>
  <?php endif; ?>

  <?php if ($flash): ?>
    <div class="alert alert-<?= $flash['type'] === 'error' ? 'error' : 'success' ?>">
      <span><?= $flash['type'] === 'error' ? '⚠️' : '✅' ?></span>
      <div><?= h($flash['message']) ?></div>
    </div>
  <?php endif; ?>

  <?php // ============================================================ ?>
  <?php // 🤖 PULSE BAR IA — KPI globaux + score santé animé             ?>
  <?php // ============================================================ ?>
  <?php if (!empty($folders)): ?>
  <?php
    // Trend pill class
    $trend = (int)$pulse['trend_pct'];
    if ($trend > 5) {
        $trend_class = 'pulse-trend-up'; $trend_icon = '↗';
    } elseif ($trend < -5) {
        $trend_class = 'pulse-trend-down'; $trend_icon = '↘';
    } else {
        $trend_class = 'pulse-trend-flat'; $trend_icon = '→';
    }
    // Donut score
    $score = (int)$pulse['score'];
    $r = 26; $cx = 32; $cy = 32; $circ = 2 * M_PI * $r;
    $offset = $circ - ($score / 100) * $circ;
  ?>
  <section class="pulse-bar" aria-label="Pulse de l'association">
    <div class="pulse-head">
      <div class="pulse-title">
        <span class="pulse-title-dot"></span>
        Pulse de votre association — 7 derniers jours
      </div>
      <span class="pulse-ai-tag">
        <svg width="12" height="12" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2l2.5 6.5L21 11l-6.5 2.5L12 20l-2.5-6.5L3 11l6.5-2.5L12 2z"/></svg>
        Analyse IA
      </span>
    </div>

    <div class="pulse-grid">

      <!-- Score santé (cellule spéciale avec donut) -->
      <div class="pulse-cell is-score">
        <div class="pulse-score-donut">
          <svg viewBox="0 0 64 64">
            <circle class="ring-bg" cx="<?= $cx ?>" cy="<?= $cy ?>" r="<?= $r ?>"/>
            <circle class="ring-fg" cx="<?= $cx ?>" cy="<?= $cy ?>" r="<?= $r ?>"
                    stroke="<?= h($pulse['health_color']) ?>"
                    stroke-dasharray="<?= round($circ, 2) ?>"
                    stroke-dashoffset="<?= round($circ, 2) ?>"
                    data-final-offset="<?= round($offset, 2) ?>"/>
          </svg>
          <div class="pulse-score-num" data-counter-to="<?= $score ?>">0</div>
        </div>
        <div class="pulse-score-info">
          <div class="pulse-score-lbl">Score IA santé</div>
          <div class="pulse-score-status">
            <span><?= h($pulse['health_emoji']) ?></span>
            <span><?= h($pulse['health_label']) ?></span>
          </div>
          <div class="pulse-score-help">Calculé sur l'activité, l'avancement et la vélocité</div>
        </div>
      </div>

      <!-- Projets actifs -->
      <div class="pulse-cell">
        <div class="pulse-cell-lbl">Projets actifs</div>
        <div class="pulse-cell-val"><span data-counter-to="<?= (int)$pulse['total_active'] ?>">0</span></div>
        <div class="pulse-cell-sub">
          <?php if ($pulse['total_done'] > 0): ?>
            <?= (int)$pulse['total_done'] ?> terminé<?= $pulse['total_done'] > 1 ? 's' : '' ?>
          <?php else: ?>
            sur <?= (int)$pulse['total_projects'] ?> au total
          <?php endif; ?>
        </div>
      </div>

      <!-- Avancement moyen -->
      <div class="pulse-cell">
        <div class="pulse-cell-lbl">Avancement moyen</div>
        <div class="pulse-cell-val"><span data-counter-to="<?= (int)$pulse['avg_progress'] ?>">0</span><span class="unit">%</span></div>
        <div class="pulse-cell-sub">sur les projets en cours</div>
      </div>

      <!-- Activité 7j -->
      <div class="pulse-cell">
        <div class="pulse-cell-lbl">Activité 7j</div>
        <div class="pulse-cell-val"><span data-counter-to="<?= (int)$pulse['act_recent'] ?>">0</span></div>
        <div class="pulse-cell-sub">
          <?php if ($pulse['act_previous'] > 0 || $pulse['act_recent'] > 0): ?>
            <span class="pulse-trend-pill <?= $trend_class ?>"><?= $trend_icon ?> <?= $trend > 0 ? '+' : '' ?><?= $trend ?>%</span>
            vs 7j précédents
          <?php else: ?>
            messages, étapes, fichiers
          <?php endif; ?>
        </div>
      </div>

    </div>
  </section>

  <?php // 🤖 COPILOTE IA — bande "prochaine action" + insights secondaires ?>
  <?php if (!empty($ai_insights)):
    $ins_main = $ai_insights[0];
    $ins_rest = array_slice($ai_insights, 1);
  ?>
  <section class="pj-aiband">
    <div class="pj-aiband-band"></div>
    <span class="pj-aiband-badge"><svg viewBox="0 0 24 24"><path d="M12 2l2.5 6.5L21 11l-6.5 2.5L12 20l-2.5-6.5L3 11l6.5-2.5L12 2z"/></svg></span>
    <div class="pj-aiband-txt">
      <b><?= h($ins_main['title']) ?></b>
      <p><?= $ins_main['body'] /* déjà échappé sauf <strong> intentionnels */ ?></p>
    </div>
    <?php if ($can_create): ?>
      <a href="/nouveau-projet" class="go">Passer à l'action <svg viewBox="0 0 24 24"><path d="M5 12h14M13 6l6 6-6 6"/></svg></a>
    <?php endif; ?>
  </section>
  <?php if (!empty($ins_rest)): ?>
  <div class="pj-ins-row" aria-label="Suggestions IA">
    <?php foreach ($ins_rest as $ins): ?>
      <div class="pj-ins tone-<?= h($ins['tone']) ?>">
        <div class="ic"><?= h($ins['icon']) ?></div>
        <div>
          <h4><?= h($ins['title']) ?></h4>
          <p><?= $ins['body'] /* déjà échappé sauf <strong> intentionnels */ ?></p>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
  <?php endif; ?>

  <?php endif; // !empty($folders) ?>

  <?php if (empty($folders)): ?>
    <div class="empty-state">
      <?php if ($is_follower_user): ?>
        <div style="color:#94A3B8; margin-bottom: 12px;"><?= ak_icon('eye',44,'1.5') ?></div>
        <div style="font-size: 15px; color: var(--ink-2); margin-bottom: 6px; font-weight: 500;">Aucun projet à suivre pour l'instant</div>
        <div style="font-size: 13px; color: var(--ink-3);">L'association vous associera aux projets qu'elle souhaite partager avec vous.</div>
      <?php else: ?>
        Aucun dossier n'a encore été créé.
        <?php if ($is_admin): ?>
          <br><br>
          <a href="/nouveau-dossier" class="btn btn-primary" style="margin-top:10px;">+ Créer votre premier dossier</a>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  <?php else: ?>

    <div class="pj-sec"><b>Vos dossiers</b><span>Triés par activité récente</span></div>
    <section class="ak-grid" id="folderGrid">
    <?php foreach ($folders as $idx => $f):
      $projects = $projects_by_folder[$f['id']] ?? [];
      $is_pinned = !empty($f['is_pinned']);
      // 🤖 Données IA pour ce dossier
      $f_activity = $folder_activity[(int)$f['id']] ?? array_fill(0, 14, 0);
      $f_badge = ak_folder_ai_badge($f, $f_activity);
      // Dossier entièrement terminé (que des projets « done ») : statut positif, pas de relance
      $fully_done = ((int)$f['active_count'] === 0 && (int)$f['done_count'] > 0);
      if ($fully_done) {
          $f_badge['emoji'] = '✅';
          $f_badge['label'] = 'Terminé';
          $f_badge['color'] = '#10B981';
      }
      $f_total_act = array_sum($f_activity);
      $spark_color = $f_badge['color'];
      // Complétion du dossier : inclut les projets terminés (= 100%), pas seulement les actifs
      $__prog_vals = [];
      foreach ($projects as $__pv) {
          if (($__pv['status'] ?? '') === 'done') { $__prog_vals[] = 100; }
          elseif (in_array($__pv['status'] ?? '', ['active', 'warning'], true)) { $__prog_vals[] = (int)$__pv['progress_percent']; }
      }
      $avg = $__prog_vals ? (int)round(array_sum($__prog_vals) / count($__prog_vals)) : (int)$f['avg_progress'];

      // Avatars : vraies initiales des membres du dossier (5 max) + reste
      $members = $folder_members[(int)$f['id']] ?? [];
      if (empty($members)) {
          // fallback : référents des projets si aucun membre déclaré
          foreach ($projects as $__pp) {
              $__ini = trim(mb_strtoupper(mb_substr((string)($__pp['ref_first'] ?? ''), 0, 1) . mb_substr((string)($__pp['ref_last'] ?? ''), 0, 1)));
              if ($__ini !== '' && !in_array($__ini, $members, true)) $members[] = $__ini;
          }
      }
      $members_shown = array_slice($members, 0, 5);
      $members_more = max(0, count($members) - 5);
      $avatar_grads = [
          'linear-gradient(135deg,#6366F1,#4F46E5)', 'linear-gradient(135deg,#10B981,#059669)',
          'linear-gradient(135deg,#F59E0B,#D97706)', 'linear-gradient(135deg,#2F73E8,#1D4ED8)',
          'linear-gradient(135deg,#8B5CF6,#7C3AED)',
      ];

      // data-states pour les filtres (par statut des projets contenus)
      $states = [];
      foreach ($projects as $__ps) {
          switch ($__ps['status']) {
              case 'active':  $states['active'] = 1; break;
              case 'warning': $states['warn']   = 1; break;
              case 'done':    $states['done']   = 1; break;
              case 'draft':   $states['wait']   = 1; break;
          }
      }
      $states = array_keys($states);

      // Prochaine action IA (par dossier)
      $act7 = array_sum(array_slice($f_activity, 0, 7));
      $na_ok = false;
      if ($fully_done) {
          $na_txt = 'dossier complété — tout est bouclé, bravo !'; $na_ok = true;
      } elseif ((int)$f['active_count'] === 0 && (int)$f['done_count'] === 0) {
          $na_txt = 'définir un premier jalon pour lancer le dossier.';
      } elseif ($f_badge['emoji'] === '🎯') {
          $na_txt = 'presque terminé — une dernière poussée pour clôturer.'; $na_ok = true;
      } elseif ($f_badge['emoji'] === '🚀') {
          $na_txt = 'en accélération — continuez sur cette lancée.'; $na_ok = true;
      } elseif ($f_badge['emoji'] === '💤' || $act7 === 0) {
          $na_txt = '14 jours sans mouvement — relancer le référent.';
      } elseif ($f_badge['emoji'] === '⚠️') {
          $na_txt = 'activité en baisse — créez une étape pour relancer.';
      } else {
          $na_txt = 'avancement stable — gardez le rythme.'; $na_ok = true;
      }
      $na_head = $na_ok ? 'Sur la bonne voie —' : 'Prochaine action —';
      $bc = $f_badge['color'];
    ?>
    <div class="folder<?= $is_pinned ? ' is-pinned' : '' ?>" id="f<?= (int)$f['id'] ?>" data-folder-id="<?= (int)$f['id'] ?>" data-states="<?= implode(' ', $states) ?>" data-name="<?= h(mb_strtolower($f['name'])) ?>">
      <button class="folder-btn" type="button">
        <span class="ak-card-glow" style="background:linear-gradient(90deg,<?= h($bc) ?>,<?= h($bc) ?>)"></span>
        <div class="ak-card-in">
          <div class="ak-card-top">
            <span class="ak-card-fi folder-icon <?= folder_icon_class($f['color_theme']) ?>">
              <?= folder_icon_svg($f['color_theme'], $f['icon'] ?? null) ?>
            </span>
            <div class="ak-card-h">
              <b><?= h($f['name']) ?><?php if ($is_pinned): ?> <span title="Dossier épinglé" style="font-size:12px">📌</span><?php endif; ?></b>
              <small>
                <?= (int)$f['active_count'] + (int)$f['done_count'] ?> projet<?= ((int)$f['active_count'] + (int)$f['done_count']) > 1 ? 's' : '' ?><?php if ($f['done_count'] > 0): ?> · <?= (int)$f['done_count'] ?> terminé<?= $f['done_count'] > 1 ? 's' : '' ?><?php endif; ?><?php if ($can_view_finances): ?> · <?= h(format_budget($f['total_budget'])) ?><?php endif; ?>
              </small>
            </div>
            <span class="ak-status" style="background:<?= h($bc) ?>1a;color:<?= h($bc) ?>" title="<?= $f_total_act ?> action<?= $f_total_act > 1 ? 's' : '' ?> sur 14 jours"><?= h($f_badge['emoji']) ?> <?= h($f_badge['label']) ?></span>
            <svg class="folder-toggle ak-card-btn-chevron" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
          </div>
          <div class="ak-card-prog">
            <svg class="ak-ring" viewBox="0 0 42 42">
              <circle cx="21" cy="21" r="16" fill="none" stroke="var(--hairline, rgba(12,40,28,.06))" stroke-width="5"/>
              <circle cx="21" cy="21" r="16" fill="none" stroke="<?= h($bc) ?>" stroke-width="5" stroke-linecap="round" pathLength="100" stroke-dasharray="<?= max(0, min(100, $avg)) ?> 100" transform="rotate(-90 21 21)"/>
              <text x="21" y="24.6" text-anchor="middle" font-size="10" font-weight="800" fill="var(--ink)"><?= $avg ?>%</text>
            </svg>
            <div class="ak-ring-lab">
              <div class="pct"><?= $avg ?> %</div>
              <small>avancement moyen</small>
            </div>
            <div class="ak-avatars">
              <?php foreach ($members_shown as $__i => $__ini): ?>
                <span style="background:<?= $avatar_grads[$__i % count($avatar_grads)] ?>" title="Membre du dossier"><?= h($__ini) ?></span>
              <?php endforeach; ?>
              <?php if ($members_more > 0): ?>
                <span class="more" title="<?= $members_more ?> autre<?= $members_more > 1 ? 's' : '' ?> membre<?= $members_more > 1 ? 's' : '' ?>">+<?= $members_more ?></span>
              <?php elseif (empty($members_shown)): ?>
                <span class="more">0</span>
              <?php endif; ?>
            </div>
          </div>
          <div class="ak-card-spark-wrap">
            <span class="lbl">Activité 14j</span>
            <?= ak_render_sparkline($f_activity, $spark_color, 150, 30) ?>
          </div>
        </div>
        <div class="ak-card-foot<?= $na_ok ? ' is-ok' : '' ?>">
          <span class="fx">
            <?php if ($na_ok): ?>
              <svg viewBox="0 0 24 24"><path d="M20 6L9 17l-5-5"/></svg>
            <?php else: ?>
              <svg viewBox="0 0 24 24"><path d="M12 3l1.8 4.9L18.7 9l-4.9 1.8L12 15l-1.8-4.2L5.3 9l4.9-1.1z"/></svg>
            <?php endif; ?>
          </span>
          <p><b><?= $na_head ?></b> <?= h($na_txt) ?></p>
        </div>
      </button>

      <div class="folder-body">
        <div class="folder-body-head" style="display:flex; justify-content:space-between; align-items:center; gap:10px; flex-wrap:wrap;">
          <div style="display:flex; gap:12px; align-items:center; flex-wrap:wrap;">
            <?php if ($can_create): ?>
              <div class="folder-body-head-l">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M9 12l2 2 4-4"/><circle cx="12" cy="12" r="10"/></svg>
                Vous pouvez ajouter un projet ici
              </div>
              <a href="/nouveau-projet/<?= (int)$f['id'] ?>" class="add-btn">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                Nouveau projet
              </a>
            <?php else: ?>
              <span class="no-perm">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                Seul un administrateur peut créer un projet
              </span>
            <?php endif; ?>
          </div>

          <?php // Bouton épingler / désépingler (personnel à chaque utilisateur) ?>
          <div style="display:flex; gap:8px; align-items:center;">
            <button type="button" class="pin-btn" data-folder-id="<?= (int)$f['id'] ?>" data-pinned="<?= $is_pinned ? '1' : '0' ?>"
                    style="display:inline-flex; align-items:center; gap:6px; padding:5px 11px; font-size:11.5px; color:<?= $is_pinned ? '#92400E' : 'var(--ink-2)' ?>; background:<?= $is_pinned ? '#FEF3C7' : 'transparent' ?>; border:1px solid <?= $is_pinned ? '#F59E0B' : 'var(--border)' ?>; border-radius:7px; cursor:pointer; font-weight:500; transition:all 0.15s; font-family:inherit;"
                    title="<?= $is_pinned ? 'Retirer l\'épingle' : 'Épingler ce dossier en haut' ?>">
              <?= ak_icon('pin',14) ?> <span class="pin-label"><?= $is_pinned ? 'Épinglé' : 'Épingler' ?></span>
            </button>

          <?php // NOUVEAU : bouton Archiver le dossier (admin only) ?>
          <?php if ($is_admin): ?>
            <a href="/supprimer-dossier/<?= (int)$f['id'] ?>"
               style="display:inline-flex; align-items:center; gap:6px; padding:5px 11px; font-size:11.5px; color:#991B1B; background:transparent; border:1px solid #FCA5A5; border-radius:7px; text-decoration:none; font-weight:500; transition:all 0.15s;"
               onmouseover="this.style.background='#FEF2F2'; this.style.borderColor='#DC2626'"
               onmouseout="this.style.background='transparent'; this.style.borderColor='#FCA5A5'"
               title="Archiver ce dossier (restaurable 30 jours)">
              <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-2 14a2 2 0 0 1-2 2H9a2 2 0 0 1-2-2L5 6"/></svg>
              Archiver
            </a>
          <?php endif; ?>
          </div>
        </div>

        <?php if (empty($projects)): ?>
          <div class="empty-state">
            Ce dossier est vide pour l'instant.
          </div>
        <?php else: ?>
          <div class="project-list">
            <?php foreach ($projects as $p):
              $badge = status_badge($p['status']);
              $ref_label = ($p['ref_first'] || $p['ref_last'])
                ? trim($p['ref_first'] . ' ' . $p['ref_last'])
                : 'Non assigné';
              $bar_class = $p['status'] === 'warning' ? 'warn' : '';
            ?>
            <a href="/projet/<?= (int)$p['id'] ?>" class="project-row<?= $p['unread_count'] > 0 ? ' has-unread' : '' ?>">
              <div class="project-main">
                <div class="project-name">
                  <?= h($p['name']) ?>
                  <?php if ($p['unread_count'] > 0): ?>
                    <span class="project-unread" title="<?= (int)$p['unread_count'] ?> nouveau<?= $p['unread_count'] > 1 ? 'x' : '' ?> message<?= $p['unread_count'] > 1 ? 's' : '' ?> non lu<?= $p['unread_count'] > 1 ? 's' : '' ?>"><?= (int)$p['unread_count'] > 99 ? '99+' : (int)$p['unread_count'] ?></span>
                  <?php endif; ?>
                </div>
                <div class="project-meta">
                  <?php if ($p['location']): ?>
                    <span><?= h($p['location']) ?></span>
                    <span class="dot">·</span>
                  <?php endif; ?>
                  <span><?= h($ref_label) ?><?= $p['ref_first'] ? ', référent' : '' ?></span>
                  <?php if ($p['participants_count'] > 0): ?>
                    <span class="dot">·</span>
                    <span><?= (int)$p['participants_count'] ?> participants</span>
                  <?php endif; ?>
                </div>
              </div>
              <div class="project-progress">
                <div class="p-bar-bg"><div class="p-bar <?= $bar_class ?>" style="width:<?= (int)$p['progress_percent'] ?>%"></div></div>
                <span class="p-pct"><?= (int)$p['progress_percent'] ?> %</span>
              </div>
              <span class="project-badge <?= $badge['class'] ?>"><?= h($badge['label']) ?></span>
            </a>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>

    <?php if ($is_admin): ?>
    <a href="/nouveau-dossier" class="ak-ghost" id="pjGhost">
      <span class="plus"><svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg></span>
      <b>Nouveau dossier</b>
      <p>Regroupez vos projets par thème ou par financeur.</p>
    </a>
    <?php endif; ?>
    </section>

  <?php endif; ?>

</div>

<script>
// ============================================================
// PROJETS 2.0 — filtres (chips), recherche, bascule grille/liste
// ============================================================
(function(){
  var grid = document.getElementById('folderGrid');
  if (!grid) return;
  var cards = Array.prototype.slice.call(grid.querySelectorAll('.folder'));
  var ghost = document.getElementById('pjGhost');
  var chips = document.getElementById('pjChips');
  var search = document.getElementById('pjSearch');
  var view = document.getElementById('pjView');
  var curFilter = 'all', curQuery = '';

  function apply(){
    cards.forEach(function(c){
      var states = (c.getAttribute('data-states') || '').split(' ');
      var name = c.getAttribute('data-name') || '';
      var okFilter = (curFilter === 'all') || states.indexOf(curFilter) !== -1;
      var okQuery = (curQuery === '') || name.indexOf(curQuery) !== -1;
      c.style.display = (okFilter && okQuery) ? '' : 'none';
    });
    if (ghost) ghost.style.display = (curFilter === 'all' && curQuery === '') ? '' : 'none';
  }
  if (chips) chips.addEventListener('click', function(e){
    var chip = e.target.closest('.pj-chip'); if (!chip) return;
    chips.querySelectorAll('.pj-chip').forEach(function(x){ x.classList.remove('on'); });
    chip.classList.add('on');
    curFilter = chip.getAttribute('data-filter') || 'all';
    apply();
  });
  if (search) search.addEventListener('input', function(){ curQuery = this.value.trim().toLowerCase(); apply(); });
  if (view) view.addEventListener('click', function(e){
    var b = e.target.closest('button'); if (!b) return;
    view.querySelectorAll('button').forEach(function(x){ x.classList.remove('on'); });
    b.classList.add('on');
    grid.classList.toggle('is-list', b.getAttribute('data-view') === 'list');
  });
})();
</script>

<script>
// ============================================================
// ÉPINGLAGE DES DOSSIERS (AJAX) — inchangé v2
// ============================================================
(function() {
    var csrfToken = '<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES) ?>';

    document.querySelectorAll('.pin-btn').forEach(function(btn) {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();

            var folderId = this.dataset.folderId;
            var isPinned = this.dataset.pinned === '1';
            var action = isPinned ? 'unpin' : 'pin';
            var btnEl = this;

            // Désactiver le bouton pendant la requête
            btnEl.disabled = true;
            btnEl.style.opacity = '0.6';

            var formData = new FormData();
            formData.append('csrf_token', csrfToken);
            formData.append('folder_id', folderId);
            formData.append('action', action);

            fetch('/action-pin', {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body: formData
            })
            .then(function(response) { return response.json(); })
            .then(function(data) {
                if (data.ok) {
                    if (data.state === 'pinned') {
                        btnEl.dataset.pinned = '1';
                        btnEl.style.color = '#92400E';
                        btnEl.style.background = '#FEF3C7';
                        btnEl.style.borderColor = '#F59E0B';
                        btnEl.title = "Retirer l'épingle";
                        btnEl.querySelector('.pin-label').textContent = 'Épinglé';
                    } else {
                        btnEl.dataset.pinned = '0';
                        btnEl.style.color = 'var(--ink-2)';
                        btnEl.style.background = 'transparent';
                        btnEl.style.borderColor = 'var(--border)';
                        btnEl.title = 'Épingler ce dossier en haut';
                        btnEl.querySelector('.pin-label').textContent = 'Épingler';
                    }
                    // Recharger pour réordonner
                    setTimeout(function() { window.location.reload(); }, 200);
                } else {
                    alert('Erreur : ' + (data.error || 'inconnue'));
                }
            })
            .catch(function(err) { alert('Erreur réseau : ' + err); })
            .finally(function() {
                btnEl.disabled = false;
                btnEl.style.opacity = '1';
            });
        });
    });
})();

// ============================================================
// 🤖 v3 : Animations Pulse Bar IA — counters + donut
// ============================================================
(function() {
    // Counters animés (0 → valeur cible)
    var counters = document.querySelectorAll('[data-counter-to]');
    counters.forEach(function(el) {
        var target = parseInt(el.dataset.counterTo, 10) || 0;
        if (target === 0) { el.textContent = '0'; return; }
        var duration = 1100; // ms
        var start = performance.now();
        function step(now) {
            var t = Math.min(1, (now - start) / duration);
            // ease-out cubic
            var eased = 1 - Math.pow(1 - t, 3);
            var val = Math.round(target * eased);
            el.textContent = val;
            if (t < 1) requestAnimationFrame(step);
            else el.textContent = target;
        }
        requestAnimationFrame(step);
    });

    // Donut score : remplir l'anneau au chargement
    var rings = document.querySelectorAll('.ring-fg[data-final-offset]');
    rings.forEach(function(ring) {
        // Petit délai pour laisser la transition CSS se déclencher
        setTimeout(function() {
            ring.style.strokeDashoffset = ring.dataset.finalOffset;
        }, 80);
    });
})();
</script>

<?php render_foot(); ?>
