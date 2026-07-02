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
        <div class="page-sub"><?= count($folders) ?> dossier<?= count($folders) > 1 ? 's' : '' ?> · <?= (int)$total_active ?> projet<?= $total_active > 1 ? 's' : '' ?> en cours</div>
      <?php endif; ?>
    </div>
    <?php if ($user['role'] === 'admin' || $can_create): ?>
    <div class="head-actions" style="display:flex; gap:8px; flex-wrap:wrap;">
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
    </div>
    <?php endif; ?>
  </div>

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

  <?php // 🤖 INSIGHTS IA AUTO-GÉNÉRÉS ?>
  <?php if (!empty($ai_insights)): ?>
  <div class="ai-insights" aria-label="Suggestions IA">
    <?php foreach ($ai_insights as $ins): ?>
      <div class="ai-insight tone-<?= h($ins['tone']) ?>">
        <div class="ai-ins-icon"><?= h($ins['icon']) ?></div>
        <div class="ai-ins-body">
          <div class="ai-ins-title"><?= h($ins['title']) ?></div>
          <div class="ai-ins-text"><?= $ins['body'] /* déjà échappé sauf <strong> intentionnels */ ?></div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <?php endif; // !empty($folders) ?>

  <?php if (empty($folders)): ?>
    <div class="empty-state">
      <?php if ($is_follower_user): ?>
        <div style="font-size: 40px; margin-bottom: 12px;">👁️</div>
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

    <?php foreach ($folders as $idx => $f):
      $projects = $projects_by_folder[$f['id']] ?? [];
      $is_pinned = !empty($f['is_pinned']);
      // 🤖 Données IA pour ce dossier
      $f_activity = $folder_activity[(int)$f['id']] ?? array_fill(0, 14, 0);
      $f_badge = ak_folder_ai_badge($f, $f_activity);
      $f_total_act = array_sum($f_activity);
      $spark_color = $f_badge['color'];
      // Classe de tendance pour le badge
      $badge_class = 'is-trend-flat';
      if ($f_badge['trend'] >= 30) $badge_class = 'is-trend-up';
      elseif ($f_badge['trend'] <= -30) $badge_class = 'is-trend-warn';
      if (in_array($f_badge['emoji'], ['⚠️'], true)) $badge_class = 'is-trend-warn';
      if (in_array($f_badge['emoji'], ['🎯', '🚀'], true)) $badge_class = 'is-trend-up';
    ?>
    <div class="folder<?= $is_pinned ? ' is-pinned' : '' ?>" id="f<?= (int)$f['id'] ?>" data-folder-id="<?= (int)$f['id'] ?>">
      <button class="folder-btn" type="button">
        <div class="folder-icon <?= folder_icon_class($f['color_theme']) ?>">
          <?= folder_icon_svg($f['color_theme'], $f['icon'] ?? null) ?>
        </div>
        <div class="folder-info">
          <div class="folder-name">
            <?= h($f['name']) ?>
            <?php if ($is_pinned): ?>
              <span title="Dossier épinglé" style="font-size:13px; margin-left:6px;">📌</span>
            <?php endif; ?>
            <?php // 🤖 Badge IA contextuel ?>
            <span class="ak-folder-badge <?= $badge_class ?>" style="margin-left:8px;" title="<?= $f_total_act ?> action<?= $f_total_act > 1 ? 's' : '' ?> sur 14 jours<?= $f_badge['trend'] != 0 ? ' · tendance ' . ($f_badge['trend'] > 0 ? '+' : '') . $f_badge['trend'] . '%' : '' ?>">
              <span class="emoji"><?= h($f_badge['emoji']) ?></span>
              <span><?= h($f_badge['label']) ?></span>
            </span>
          </div>
          <div class="folder-meta">
            <span><?= (int)$f['active_count'] + (int)$f['done_count'] ?> projet<?= ((int)$f['active_count'] + (int)$f['done_count']) > 1 ? 's' : '' ?></span>
            <?php if ($f['done_count'] > 0): ?>
              <span class="dot">·</span>
              <span><?= (int)$f['done_count'] ?> terminé<?= $f['done_count'] > 1 ? 's' : '' ?></span>
            <?php endif; ?>
            <span class="dot">·</span>
            <span><?= (int)$f['total_participants'] ?> participants</span>
            <?php if ($can_view_finances): ?>
            <span class="dot">·</span>
            <span>Budget <?= h(format_budget($f['total_budget'])) ?></span>
            <?php endif; ?>
          </div>
        </div>
        <div class="folder-stats">
          <?php // 🤖 Sparkline 14 jours ?>
          <div class="ak-folder-spark">
            <div class="ak-folder-spark-lbl">Activité 14j</div>
            <?= ak_render_sparkline($f_activity, $spark_color) ?>
          </div>
          <div class="folder-pct-wrap">
            <div class="folder-pct-lbl">Moyenne</div>
            <div class="folder-pct-big"><?= (int)$f['avg_progress'] ?> %</div>
          </div>
          <svg class="folder-toggle" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
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
              📌 <span class="pin-label"><?= $is_pinned ? 'Épinglé' : 'Épingler' ?></span>
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

  <?php endif; ?>

</div>

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
