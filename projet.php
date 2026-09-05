<?php
/**
 * ============================================================
 * ASSOKIT — Fiche Projet v2 (avec onglets)
 * ============================================================
 * URL : /projet/42/messages
 * 4 onglets : Vue / Messages / Fichiers / Assistant IA
 * ============================================================
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes-layout.php';
require_once __DIR__ . '/ai-helper.php';
require_once __DIR__ . '/includes-permissions.php';

require_login();

$current = current_user();
$org_id = (int)$current['org_id'];

// Mode mairie/super admin : accès lecture seule aux projets d'asso liées
$is_mairie_viewer = is_parent_org_user($current) || is_platform_admin($current);
$project_id = (int)($_GET['id'] ?? 0);
$active_tab = $_GET['tab'] ?? 'overview';

// [PACK 6.5 - SECURITY] Helper de check finances (cache budgets + onglet factures aux non-financiers)
require_once __DIR__ . '/finance-permissions.php';
$can_view_finances = user_can_view_finances($current);

// Protection follower : vérifier qu'il peut accéder à ce projet (sauf mode mairie)
if (!$is_mairie_viewer && !follower_can_access_project($project_id)) {
    header('Location: /projets?error=forbidden');
    exit;
}

// Pour les followers : onglets limités (pas de factures, pas d'IA interne)
$is_follower_user = is_follower();
if ($is_follower_user) {
    $valid_tabs = ['overview', 'messages'];
} else {
    $valid_tabs = ['overview', 'messages', 'fichiers', 'ia'];
    // [PACK 6.5 - SECURITY] Onglet "factures" réservé aux personnes habilitées finances
    if ($can_view_finances) {
        $valid_tabs[] = 'factures';
    }
    // Historique : admins uniquement
    if ($current['role'] === 'admin') {
        $valid_tabs[] = 'historique';
    }
}
if (!in_array($active_tab, $valid_tabs, true)) $active_tab = 'overview';

if ($project_id <= 0) {
    header('Location: /projets');
    exit;
}

// Chargement du projet (mode mairie : pas de filtre org au SELECT, vérif après)
if ($is_mairie_viewer) {
    $stmt = $pdo->prepare("
        SELECT p.*, f.name AS folder_name, f.color_theme, f.org_id AS folder_org_id,
               u.id AS ref_id, u.first_name AS ref_first, u.last_name AS ref_last,
               u.avatar_color AS ref_color
        FROM projects p
        JOIN folders f ON p.folder_id = f.id
        LEFT JOIN users u ON p.referent_id = u.id
        WHERE p.id = ?
    ");
    $stmt->execute([$project_id]);
    $project = $stmt->fetch();
    // Vérifier que la mairie/super admin peut voir cette asso
    if ($project && !user_can_view_org((int)$project['folder_org_id'], $current)) {
        $project = false;
    }
    if ($project) {
        $org_id = (int)$project['folder_org_id']; // pour la suite du code (messages, fichiers, etc.)
    }
} else {
    $stmt = $pdo->prepare("
        SELECT p.*, f.name AS folder_name, f.color_theme,
               u.id AS ref_id, u.first_name AS ref_first, u.last_name AS ref_last,
               u.avatar_color AS ref_color
        FROM projects p
        JOIN folders f ON p.folder_id = f.id
        LEFT JOIN users u ON p.referent_id = u.id
        WHERE p.id = ? AND f.org_id = ?
    ");
    $stmt->execute([$project_id, $org_id]);
    $project = $stmt->fetch();
}

if (!$project) {
    render_head('Projet introuvable');
    render_sidebar('projets');
    echo '<main class="main"><div class="empty-state" style="margin-top: 60px;">Ce projet n\'existe pas ou ne fait pas partie de votre organisation.</div></main>';
    render_foot();
    exit;
}

// Étapes
$stmt = $pdo->prepare("
    SELECT s.*, u.first_name AS by_first, u.last_name AS by_last, u.avatar_color AS by_color
    FROM project_steps s
    LEFT JOIN users u ON s.completed_by = u.id
    WHERE s.project_id = ?
    ORDER BY s.position ASC, s.id ASC
");
$stmt->execute([$project_id]);
$steps = $stmt->fetchAll();

$total_steps = count($steps);
$done_steps = 0;
foreach ($steps as $s) if ($s['is_completed']) $done_steps++;
$computed_progress = $total_steps > 0 ? (int)round(($done_steps / $total_steps) * 100) : (int)$project['progress_percent'];

// Permissions
$is_admin = ($current['role'] === 'admin');
$is_coord = ($current['role'] === 'coordinator');
$is_referent = ($project['referent_id'] == $current['id']);
// Édition des étapes : admin, coord, référent (comme avant)
$can_edit_steps = $is_admin || $is_coord || $is_referent;
// Édition du projet lui-même : admin + référent UNIQUEMENT
$can_edit_project = $is_admin || $is_referent;
// Partage public : admin + référent + coordinateur
$can_share_public = $is_admin || $is_referent || $is_coord;

// [NEW] Charger les membres potentiellement mentionnables (équipe + référent + admins de l'org)
// pour le dropdown d'autocomplete @
$mention_targets = [];
try {
    $stmt = $pdo->prepare("
        SELECT DISTINCT u.id, u.first_name, u.last_name, u.role, u.avatar_color
        FROM users u
        WHERE u.is_active = 1
          AND u.org_id = :org_id
          AND (u.deleted_at IS NULL OR u.deleted_at = '')
          AND (
              u.id IN (SELECT user_id FROM project_members WHERE project_id = :pid1)
              OR u.id = :referent_id
              OR u.role = 'admin'
          )
        ORDER BY u.first_name ASC, u.last_name ASC
    ");
    $stmt->execute([
        ':org_id' => (int)$current['org_id'],
        ':pid1' => $project_id,
        ':referent_id' => (int)($project['referent_id'] ?? 0),
    ]);
    $mention_targets = $stmt->fetchAll();
} catch (Throwable $e) {
    $mention_targets = [];
}

// Helper pour surligner les @mentions dans un message
function ak_highlight_mentions(string $content): string {
    // Échappe HTML d'abord
    $escaped = htmlspecialchars($content, ENT_QUOTES, 'UTF-8');
    // Puis surligne les @prenom (avec point/tiret optionnel pour prenom.nom)
    $highlighted = preg_replace_callback(
        '/(@[a-zA-ZÀ-ÿ][a-zA-ZÀ-ÿ\.\-]+)/u',
        function($m) {
            return '<span style="background:#DBEAFE; color:#1E40AF; padding:1px 6px; border-radius:4px; font-weight:500;">' . $m[1] . '</span>';
        },
        $escaped
    );
    return nl2br($highlighted);
}

// Messages
$stmt = $pdo->prepare("
    SELECT m.id, m.content, m.created_at, u.id AS user_id,
           u.first_name, u.last_name, u.avatar_color
    FROM project_messages m
    JOIN users u ON m.author_id = u.id
    WHERE m.project_id = ? AND m.message_type = 'text'
    ORDER BY m.created_at ASC
");
$stmt->execute([$project_id]);
$messages = $stmt->fetchAll();

// Fichiers
$stmt = $pdo->prepare("
    SELECT f.*, u.first_name, u.last_name
    FROM project_files f
    JOIN users u ON f.uploaded_by = u.id
    WHERE f.project_id = ?
    ORDER BY f.created_at DESC
");
$stmt->execute([$project_id]);
$files = $stmt->fetchAll();

// Factures liées au projet
$stmt = $pdo->prepare("
    SELECT i.*,
           u1.first_name AS up_first, u1.last_name AS up_last,
           u2.first_name AS val_first, u2.last_name AS val_last
    FROM project_invoices i
    LEFT JOIN users u1 ON i.uploaded_by = u1.id
    LEFT JOIN users u2 ON i.validated_by = u2.id
    WHERE i.project_id = ?
    ORDER BY
        FIELD(i.status, 'pending', 'validated', 'rejected'),
        i.invoice_date DESC
");
$stmt->execute([$project_id]);
$invoices = $stmt->fetchAll();

// Calcul des totaux factures
$total_validated = 0;
$total_validated_ht = 0;
$total_pending = 0;
$count_pending = 0;
foreach ($invoices as $inv) {
    if ($inv['status'] === 'validated') {
        $total_validated += (float)$inv['amount_ttc'];
        $total_validated_ht += (float)($inv['amount_ht'] ?? $inv['amount_ttc']);
    } elseif ($inv['status'] === 'pending') {
        $total_pending += (float)$inv['amount_ttc'];
        $count_pending++;
    }
}
$total_vat = $total_validated - $total_validated_ht;
$budget_remaining = (float)$project['budget_planned'] - $total_validated;

// Conversation IA active
$active_conv_id = (int)($_GET['conv'] ?? 0);
$active_conv_messages = [];
if ($active_conv_id > 0) {
    $stmt = $pdo->prepare("SELECT role, content FROM ai_messages WHERE conversation_id = ? ORDER BY created_at ASC, id ASC");
    $stmt->execute([$active_conv_id]);
    $active_conv_messages = $stmt->fetchAll();
}

// Documents IA générés
$stmt = $pdo->prepare("
    SELECT g.*, u.first_name, u.last_name
    FROM ai_generated_docs g
    JOIN users u ON g.user_id = u.id
    WHERE g.project_id = ?
    ORDER BY g.created_at DESC
");
$stmt->execute([$project_id]);
$generated_docs = $stmt->fetchAll();

// Helpers
function status_info_p($s) {
    return [
        'active' => ['label' => 'En cours', 'class' => 'badge-ok'],
        'warning' => ['label' => 'À surveiller', 'class' => 'badge-warn'],
        'done' => ['label' => 'Terminé', 'class' => 'badge-done'],
        'draft' => ['label' => 'Brouillon', 'class' => 'badge-done'],
        'archived' => ['label' => 'Archivé', 'class' => 'badge-done'],
    ][$s] ?? ['label' => 'En cours', 'class' => 'badge-ok'];
}
function format_date_p($d) {
    if (!$d) return '—';
    $m = ['janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'];
    $t = strtotime($d);
    return (int)date('j', $t) . ' ' . $m[(int)date('n', $t) - 1] . ' ' . date('Y', $t);
}
function format_time_p($d) {
    if (!$d) return '';
    $today = date('Y-m-d');
    $dstr = date('Y-m-d', strtotime($d));
    if ($dstr === $today) return "Aujourd'hui, " . date('H:i', strtotime($d));
    if ($dstr === date('Y-m-d', strtotime('-1 day'))) return 'Hier, ' . date('H:i', strtotime($d));
    return format_date_p($d) . ' à ' . date('H:i', strtotime($d));
}
function file_icon_class($mime, $filename) {
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    if ($ext === 'pdf') return 'pdf';
    if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) return 'img';
    if (in_array($ext, ['doc', 'docx', 'odt', 'txt'])) return 'doc';
    return '';
}
function format_filesize($b) {
    if ($b < 1024) return $b . ' o';
    if ($b < 1048576) return round($b / 1024) . ' Ko';
    return round($b / 1048576, 1) . ' Mo';
}

$budget_pct = $project['budget_planned'] > 0
    ? min(100, (int)round(($project['budget_used'] / $project['budget_planned']) * 100))
    : 0;
$ref_color = in_array($project['ref_color'], ['blue','purple','amber','pink','teal'], true)
    ? 'av-' . $project['ref_color'] : 'av-blue';
$status = status_info_p($project['status']);
$ai_ready = is_ai_enabled();

// ============================================================
// 🤖 PACK GO — Cockpit IA pour la page projet
// ============================================================

/** Activité par jour sur 14 derniers jours pour ce projet (sources : messages, étapes complétées, fichiers) */
function ak_proj_activity_14d(PDO $pdo, int $project_id): array {
    $out = array_fill(0, 14, 0);
    try {
        $sql = "SELECT day_offset, SUM(c) AS total FROM (
                    SELECT DATEDIFF(CURDATE(), DATE(created_at)) AS day_offset, COUNT(*) AS c
                    FROM project_messages WHERE project_id = ? AND created_at >= DATE_SUB(CURDATE(), INTERVAL 14 DAY)
                    GROUP BY DATEDIFF(CURDATE(), DATE(created_at))
                    UNION ALL
                    SELECT DATEDIFF(CURDATE(), DATE(completed_at)), COUNT(*)
                    FROM project_steps WHERE project_id = ? AND completed_at IS NOT NULL AND completed_at >= DATE_SUB(CURDATE(), INTERVAL 14 DAY)
                    GROUP BY DATEDIFF(CURDATE(), DATE(completed_at))
                    UNION ALL
                    SELECT DATEDIFF(CURDATE(), DATE(created_at)), COUNT(*)
                    FROM project_files WHERE project_id = ? AND created_at >= DATE_SUB(CURDATE(), INTERVAL 14 DAY)
                    GROUP BY DATEDIFF(CURDATE(), DATE(created_at))
                ) a WHERE day_offset BETWEEN 0 AND 13 GROUP BY day_offset";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$project_id, $project_id, $project_id]);
        foreach ($stmt->fetchAll() as $r) {
            $out[(int)$r['day_offset']] = (int)$r['total'];
        }
    } catch (Throwable $e) {}
    return $out;
}

/** Score santé projet 0-100 : avancement (40%) + activité récente (30%) + complétude data (30%) */
function ak_proj_health_score(int $progress, array $activity_14d, array $project, array $files, array $invoices): array {
    $rec = array_sum(array_slice($activity_14d, 0, 7));
    $velocity_norm = min(100, $rec * 10);

    // Complétude des données (anti-bilan vide)
    $completeness = 0;
    if (!empty(trim($project['description'] ?? ''))) $completeness += 25;
    if (!empty(trim($project['objective'] ?? ''))) $completeness += 25;
    if (count($files) >= 1) $completeness += 20;
    if (count($files) >= 5) $completeness += 10;
    if (count($invoices) >= 1) $completeness += 10;
    if (!empty($project['location'])) $completeness += 10;
    $completeness = min(100, $completeness);

    $score = round($progress * 0.4 + $velocity_norm * 0.3 + $completeness * 0.3);
    $score = max(0, min(100, $score));

    if ($score >= 75) { $lbl = 'Excellent'; $emoji = '🚀'; $color = '#10B981'; }
    elseif ($score >= 55) { $lbl = 'Bon'; $emoji = '✨'; $color = '#3B82F6'; }
    elseif ($score >= 35) { $lbl = 'Stable'; $emoji = '🌱'; $color = '#F59E0B'; }
    else { $lbl = 'À relancer'; $emoji = '💡'; $color = '#EF4444'; }

    return ['score' => $score, 'label' => $lbl, 'emoji' => $emoji, 'color' => $color, 'velocity_7d' => $rec, 'completeness' => $completeness];
}

/** Détecte ce qui manque pour un bilan complet (nudges incitatifs) */
function ak_proj_missing_for_bilan(array $project, array $files, array $invoices, array $messages, array $steps): array {
    $missing = [];
    if (empty(trim($project['description'] ?? ''))) {
        $missing[] = ['icon' => '📝', 'label' => 'Description du projet', 'why' => 'Contexte essentiel pour le bilan', 'link' => '/modifier-projet/' . (int)$project['id']];
    }
    if (empty(trim($project['objective'] ?? ''))) {
        $missing[] = ['icon' => '🎯', 'label' => 'Objectif du projet', 'why' => 'Permet de mesurer la réussite', 'link' => '/modifier-projet/' . (int)$project['id']];
    }
    if (count($files) === 0) {
        $missing[] = ['icon' => '📎', 'label' => 'Aucun fichier', 'why' => 'Photos, PDF, contrats… enrichissent le bilan', 'link' => '/projet/' . (int)$project['id'] . '/fichiers'];
    } elseif (count($files) < 5) {
        $missing[] = ['icon' => '📸', 'label' => 'Peu de fichiers (' . count($files) . ')', 'why' => 'Plus de photos = bilan plus vivant', 'link' => '/projet/' . (int)$project['id'] . '/fichiers'];
    }
    if (count($invoices) === 0 && function_exists('can') && can('manage_finances')) {
        $missing[] = ['icon' => '💰', 'label' => 'Aucune facture', 'why' => 'Pour l\'analyse financière du bilan', 'link' => '/projet/' . (int)$project['id'] . '/factures'];
    }
    if (count($messages) < 3) {
        $missing[] = ['icon' => '💬', 'label' => 'Peu d\'échanges (' . count($messages) . ')', 'why' => 'Les messages racontent l\'histoire du projet', 'link' => '/projet/' . (int)$project['id'] . '/messages'];
    }
    if (empty($project['location'])) {
        $missing[] = ['icon' => '📍', 'label' => 'Lieu non renseigné', 'why' => 'Précision utile dans le bilan', 'link' => '/modifier-projet/' . (int)$project['id']];
    }
    return $missing;
}

/** Next Best Action : la seule action prioritaire à mettre en avant */
function ak_proj_next_best_action(array $project, array $steps, array $messages, array $files, int $progress, array $activity_14d): array {
    $project_id = (int)$project['id'];
    $rec = array_sum(array_slice($activity_14d, 0, 7));

    // 1) Étape la plus ancienne non complétée
    foreach ($steps as $s) {
        if (empty($s['is_completed'])) {
            return [
                'icon' => '🎯',
                'tone' => 'primary',
                'title' => 'Avance sur la prochaine étape',
                'body' => '<strong>' . htmlspecialchars($s['title']) . '</strong> attend d\'être complétée.',
                'cta' => 'Voir l\'étape',
                'link' => '/projet/' . $project_id,
                'alts' => [],
            ];
        }
    }

    // 2) Toutes les étapes faites mais projet pas done
    if ($progress >= 100 && $project['status'] !== 'done') {
        return [
            'icon' => '🏁',
            'tone' => 'success',
            'title' => 'Toutes les étapes sont terminées',
            'body' => 'Tu peux clôturer ce projet ou générer son bilan final.',
            'cta' => 'Modifier le projet',
            'link' => '/modifier-projet/' . $project_id,
            'alts' => ['Génère le bilan final ↓', 'Archive le projet'],
        ];
    }

    // 3) Aucune activité depuis 7j
    if ($rec === 0 && count($messages) > 0) {
        return [
            'icon' => '💬',
            'tone' => 'warn',
            'title' => 'Relance la dynamique',
            'body' => 'Pas d\'activité depuis 7 jours. Un message à l\'équipe ?',
            'cta' => 'Envoyer un message',
            'link' => '/projet/' . $project_id . '/messages',
            'alts' => ['Ajoute un fichier', 'Crée une nouvelle étape'],
        ];
    }

    // 4) Aucun fichier
    if (count($files) === 0) {
        return [
            'icon' => '📎',
            'tone' => 'info',
            'title' => 'Ajoute le premier fichier',
            'body' => 'Photos, PDF, contrat… le bilan sera plus riche.',
            'cta' => 'Ajouter un fichier',
            'link' => '/projet/' . $project_id . '/fichiers',
            'alts' => [],
        ];
    }

    // 5) Description manquante
    if (empty(trim($project['description'] ?? ''))) {
        return [
            'icon' => '📝',
            'tone' => 'info',
            'title' => 'Précise le contexte',
            'body' => 'Une description du projet aide à structurer le bilan.',
            'cta' => 'Compléter',
            'link' => '/modifier-projet/' . $project_id,
            'alts' => [],
        ];
    }

    // 6) Tout est en ordre
    return [
        'icon' => '✨',
        'tone' => 'info',
        'title' => 'Tout roule sur ce projet',
        'body' => 'Continue à publier des messages et compléter les étapes au rythme actuel.',
        'cta' => 'Voir les étapes',
        'link' => '/projet/' . $project_id,
        'alts' => ['Génère un point d\'étape avec l\'IA'],
    ];
}

/** Team pulse : engagement de chaque membre sur 30j */
function ak_proj_team_pulse(PDO $pdo, int $project_id): array {
    try {
        $sql = "SELECT pm.user_id, u.first_name, u.last_name,
                       (SELECT COUNT(*) FROM project_messages m WHERE m.project_id = ? AND m.author_id = pm.user_id AND m.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)) AS msg_count,
                       (SELECT COUNT(*) FROM project_steps s WHERE s.project_id = ? AND s.completed_by = pm.user_id AND s.completed_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)) AS step_count,
                       (SELECT COUNT(*) FROM project_files f WHERE f.project_id = ? AND f.uploaded_by = pm.user_id AND f.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)) AS file_count
                FROM project_members pm
                JOIN users u ON u.id = pm.user_id
                WHERE pm.project_id = ?
                LIMIT 8";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$project_id, $project_id, $project_id, $project_id]);
        $rows = $stmt->fetchAll();

        $items = [];
        $max = 1;
        foreach ($rows as $r) {
            $score = (int)$r['msg_count'] + ((int)$r['step_count'] * 3) + ((int)$r['file_count'] * 2);
            $items[] = [
                'name' => trim($r['first_name'] . ' ' . $r['last_name']),
                'first' => $r['first_name'], 'last' => $r['last_name'],
                'score' => $score,
                'msg' => (int)$r['msg_count'],
                'step' => (int)$r['step_count'],
                'file' => (int)$r['file_count'],
            ];
            if ($score > $max) $max = $score;
        }

        // Normaliser sur 0-100
        foreach ($items as &$it) {
            $it['pct'] = $max > 0 ? min(100, round(($it['score'] / $max) * 100)) : 0;
        }
        unset($it);

        // Tri par score desc
        usort($items, fn($a, $b) => $b['score'] - $a['score']);
        return $items;
    } catch (Throwable $e) {
        return [];
    }
}

/** Mini sparkline pour cockpit */
function ak_proj_sparkline(array $values_14d, string $color = '#10B981', int $w = 200, int $h = 30): string {
    $ordered = [];
    for ($i = 13; $i >= 0; $i--) $ordered[] = $values_14d[$i] ?? 0;
    $max = max($ordered); if ($max === 0) {
        return '<svg viewBox="0 0 ' . $w . ' ' . $h . '" preserveAspectRatio="none" style="width:100%;height:' . $h . 'px"><line x1="0" y1="' . ($h-2) . '" x2="' . $w . '" y2="' . ($h-2) . '" stroke="#E5E7EB" stroke-dasharray="2 3" stroke-width="1.5"/></svg>';
    }
    $n = count($ordered); $step = $w / max(1, $n - 1);
    $pts = []; $area = ['0,' . $h];
    foreach ($ordered as $i => $v) {
        $x = round($i * $step, 1);
        $y = round($h - 2 - (($v / $max) * ($h - 4)), 1);
        $pts[] = "$x,$y";
        $area[] = "$x,$y";
    }
    $area[] = "$w,$h";
    $color_safe = htmlspecialchars($color, ENT_QUOTES);
    $uid = 'sp' . substr(md5(implode(',', $pts) . $color), 0, 6);
    return '<svg viewBox="0 0 ' . $w . ' ' . $h . '" preserveAspectRatio="none" style="width:100%;height:' . $h . 'px" aria-hidden="true">'
         . '<defs><linearGradient id="' . $uid . '" x1="0" x2="0" y1="0" y2="1">'
         . '<stop offset="0%" stop-color="' . $color_safe . '" stop-opacity="0.25"/>'
         . '<stop offset="100%" stop-color="' . $color_safe . '" stop-opacity="0"/>'
         . '</linearGradient></defs>'
         . '<polygon points="' . implode(' ', $area) . '" fill="url(#' . $uid . ')"/>'
         . '<polyline points="' . implode(' ', $pts) . '" fill="none" stroke="' . $color_safe . '" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>'
         . '</svg>';
}

// Calculs cockpit
$proj_activity = ak_proj_activity_14d($pdo, (int)$project['id']);
$proj_health = ak_proj_health_score($computed_progress, $proj_activity, $project, $files, $invoices);
$proj_missing = ak_proj_missing_for_bilan($project, $files, $invoices, $messages, $steps);
$proj_nba = ak_proj_next_best_action($project, $steps, $messages, $files, $computed_progress, $proj_activity);
$proj_team = ak_proj_team_pulse($pdo, (int)$project['id']);

// 🔗 Token de partage public actif (s'il existe)
$proj_share_token = null;
try {
    $stmt = $pdo->prepare("SELECT token, view_count, last_viewed_at FROM project_share_tokens WHERE project_id = ? AND revoked_at IS NULL AND (expires_at IS NULL OR expires_at > NOW()) ORDER BY id DESC LIMIT 1");
    $stmt->execute([(int)$project['id']]);
    $proj_share_token = $stmt->fetch() ?: null;
} catch (Throwable $e) {}

render_head($project['name']);
render_sidebar('projets');
?>

<main class="main">

  <nav class="crumbs">
    <a href="/dashboard">Dashboard</a>
    <span class="sep">›</span>
    <a href="/projets">Projets</a>
    <span class="sep">›</span>
    <a href="/projets#f<?= (int)$project['folder_id'] ?>"><?= h($project['folder_name']) ?></a>
    <span class="sep">›</span>
    <span class="current"><?= h($project['name']) ?></span>
  </nav>

  <?php if (isset($_GET['updated'])): ?>
    <div class="alert alert-success">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
      ✅ Projet mis à jour. Les modifications sont enregistrées dans l'historique.
    </div>
  <?php elseif (isset($_GET['duplicated'])): ?>
    <div class="alert alert-success">
      ✨ Projet dupliqué avec succès. Tu peux maintenant l'adapter.
    </div>
  <?php elseif (isset($_GET['error'])):
    $err_labels = [
      'permission' => 'Seuls l\'administrateur et le référent du projet peuvent modifier ce projet.',
      'csrf' => 'Session expirée, réessayez.',
      'forbidden' => 'Vous n\'avez pas accès à ce projet.',
    ];
    $err_msg = $err_labels[$_GET['error']] ?? $_GET['error'];
  ?>
    <div class="alert alert-error">⚠️ <?= h($err_msg) ?></div>
  <?php endif; ?>

  <div class="proj-header">
    <div class="proj-header-icon <?= folder_icon_class($project['color_theme']) ?>">
      <?= folder_icon_svg($project['color_theme']) ?>
    </div>
    <div class="proj-header-info">
      <div class="proj-header-tag"><?= h($project['folder_name']) ?></div>
      <h1 class="proj-header-title"><?= h($project['name']) ?></h1>
      <div class="proj-header-meta">
        <span class="project-badge <?= $status['class'] ?>"><?= h($status['label']) ?></span>
        <?php if ($project['location']): ?>
          <span class="dot">·</span><span><?= h($project['location']) ?></span>
        <?php endif; ?>
        <?php if ($project['ref_first']): ?>
          <span class="dot">·</span>
          <span class="referent-tag">
            <span class="ref-avatar <?= $ref_color ?>"><?= h(user_initials($project['ref_first'], $project['ref_last'])) ?></span>
            <?= h($project['ref_first'] . ' ' . $project['ref_last']) ?>, référent
          </span>
        <?php endif; ?>
      </div>
    </div>
    <?php if ($can_edit_project || $is_admin): ?>
    <div class="head-actions" style="display:flex; gap:8px; flex-wrap:wrap;">
      <?php if ($can_edit_project): ?>
      <a href="/modifier-projet/<?= (int)$project['id'] ?>" class="btn btn-ghost">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
        Modifier
      </a>
      <?php endif; ?>
      <?php if ($is_admin && empty($project['archived_at'])): ?>
      <a href="/supprimer-projet/<?= (int)$project['id'] ?>"
         style="display:inline-flex; align-items:center; gap:6px; padding:8px 14px; font-size:13px; color:#991B1B; background:transparent; border:1px solid #FCA5A5; border-radius:8px; text-decoration:none; font-weight:500; transition:all 0.15s; font-family:inherit;"
         onmouseover="this.style.background='#FEF2F2'; this.style.borderColor='#DC2626'"
         onmouseout="this.style.background='transparent'; this.style.borderColor='#FCA5A5'"
         title="Archiver ce projet (restaurable 30 jours)">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-2 14a2 2 0 0 1-2 2H9a2 2 0 0 1-2-2L5 6"/></svg>
        Archiver
      </a>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </div>

  <?php // ============================================================ ?>
  <?php // 🤖 PACK GO — Cockpit IA + Bouton Bilan + Nudges               ?>
  <?php // ============================================================ ?>
  <?php
    $today_iso = date('Y-m-d');
    $today_fr = date('d/m/Y');
    $score = (int)$proj_health['score'];
    $r = 32; $cx = 40; $cy = 40; $circ = 2 * M_PI * $r;
    $offset_score = $circ - ($score / 100) * $circ;
    $r2 = 32; $offset_prog = $circ - ($computed_progress / 100) * $circ;
    $tone_class = 'tone-' . $proj_nba['tone'];
  ?>

  <section class="ck" aria-label="Cockpit du projet">

    <!-- HERO COCKPIT -->
    <div class="ck-hero">
      <div class="ck-hero-bg"></div>

      <!-- Donut avancement -->
      <div class="ck-donut-block">
        <div class="ck-donut-wrap">
          <svg viewBox="0 0 80 80" class="ck-donut">
            <circle cx="<?= $cx ?>" cy="<?= $cy ?>" r="<?= $r2 ?>" fill="none" stroke="#f3f4f6" stroke-width="6"/>
            <circle class="ck-donut-fg" cx="<?= $cx ?>" cy="<?= $cy ?>" r="<?= $r2 ?>" fill="none"
                    stroke="<?= h($proj_health['color']) ?>" stroke-width="6" stroke-linecap="round"
                    stroke-dasharray="<?= round($circ, 2) ?>"
                    stroke-dashoffset="<?= round($circ, 2) ?>"
                    data-final="<?= round($offset_prog, 2) ?>"/>
          </svg>
          <div class="ck-donut-num" data-counter-to="<?= $computed_progress ?>">0</div>
        </div>
        <div class="ck-donut-lbl">Avancement</div>
      </div>

      <!-- KPI mini -->
      <div class="ck-kpi-grid">
        <div class="ck-kpi">
          <div class="ck-kpi-lbl">Étapes</div>
          <div class="ck-kpi-val"><span data-counter-to="<?= $done_steps ?>">0</span><span class="ck-kpi-tot">/<?= $total_steps ?></span></div>
        </div>
        <div class="ck-kpi">
          <div class="ck-kpi-lbl">Vélocité 7j</div>
          <div class="ck-kpi-val"><span data-counter-to="<?= (int)$proj_health['velocity_7d'] ?>">0</span><span class="ck-kpi-unit">actions</span></div>
        </div>
        <div class="ck-kpi">
          <div class="ck-kpi-lbl">Santé IA</div>
          <div class="ck-kpi-val ck-kpi-health" style="color:<?= h($proj_health['color']) ?>">
            <span><?= h($proj_health['emoji']) ?></span>
            <span data-counter-to="<?= $score ?>">0</span>
          </div>
          <div class="ck-kpi-sub" style="color:<?= h($proj_health['color']) ?>"><?= h($proj_health['label']) ?></div>
        </div>
      </div>

      <!-- Sparkline 14j -->
      <div class="ck-spark">
        <div class="ck-spark-lbl">Activité 14 jours</div>
        <?= ak_proj_sparkline($proj_activity, $proj_health['color'], 200, 30) ?>
      </div>

      <div class="ck-actions-bar">
      <!-- Bouton Générer le bilan -->
      <?php if ($can_edit_project || $is_admin): ?>
      <div class="ck-bilan">
        <button type="button" class="ck-bilan-btn" id="ck-bilan-toggle">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
          <span>Générer le bilan</span>
          <span class="ck-bilan-date"><?= h($today_fr) ?></span>
        </button>
        <form id="ck-bilan-pop" class="ck-bilan-pop" method="POST" action="/action-ia" hidden>
          <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token'] ?? '') ?>">
          <input type="hidden" name="project_id" value="<?= (int)$project['id'] ?>">
          <input type="hidden" name="mode" value="generate">
          <input type="hidden" name="doc_type" value="bilan_date">
          <div class="ck-bilan-pop-title">📋 Bilan du projet</div>
          <div class="ck-bilan-pop-row">
            <label for="ck-bilan-input">Date du bilan</label>
            <input type="date" id="ck-bilan-input" name="bilan_date" value="<?= h($today_iso) ?>" max="<?= h($today_iso) ?>" required/>
          </div>
          <?php if (!empty($proj_missing)): ?>
          <div class="ck-bilan-pop-warn">
            <strong>⚠️ Bilan incomplet :</strong> il manque <?= count($proj_missing) ?> élément<?= count($proj_missing) > 1 ? 's' : '' ?> pour un bilan riche.
            <a href="#ck-nudges" onclick="document.getElementById('ck-bilan-pop').hidden=true">Voir ↓</a>
          </div>
          <?php endif; ?>
          <?php if (!$ai_ready): ?>
          <div class="ck-bilan-pop-warn" style="background:#FEE2E2;color:#991B1B;">
            <strong>⚠️ IA non configurée.</strong> L'admin doit ajouter la clé <code>ANTHROPIC_API_KEY</code> dans config.php.
          </div>
          <?php endif; ?>
          <div class="ck-bilan-pop-actions">
            <button type="button" class="ck-btn-ghost" onclick="document.getElementById('ck-bilan-pop').hidden=true">Annuler</button>
            <button type="submit" class="ck-btn-primary" id="ck-bilan-submit" <?= !$ai_ready ? 'disabled' : '' ?>>
              <span class="ck-bilan-submit-lbl">Générer maintenant →</span>
              <span class="ck-bilan-submit-loading" style="display:none;">⏳ Génération...</span>
            </button>
          </div>
        </form>
      </div>
      <?php endif; ?>

      <?php if ($can_edit_project || $is_admin): ?>
      <div class="ck-exports">
        <button type="button" class="ck-exports-btn" aria-haspopup="true" onclick="var m=this.parentNode.querySelector('.ck-exports-menu'); m.hidden=!m.hidden;">
          <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
          <span>Exports</span>
          <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" style="margin-left:2px;opacity:.7"><polyline points="6 9 12 15 18 9"/></svg>
        </button>
        <div class="ck-exports-menu" hidden>
          <a href="/download-bilan-analytique?project=<?= (int)$project['id'] ?>" target="_blank" rel="noopener"><span class="mi">📊</span> Bilan analytique</a>
          <a href="/download-justificatifs?project=<?= (int)$project['id'] ?>" target="_blank" rel="noopener"><span class="mi">📎</span> Justificatifs</a>
          <a href="/download-bilan-analytique-xlsx?project=<?= (int)$project['id'] ?>" target="_blank" rel="noopener"><span class="mi">📗</span> Export Excel</a>
        </div>
      </div>
      <?php endif; ?>

      <?php // 🔗 PARTAGE PUBLIC (admin / référent / coordinateur) ?>
      <?php if ($can_share_public): ?>
      <div class="ck-share">
        <button type="button" class="ck-share-btn <?= $proj_share_token ? 'is-active' : '' ?>" id="ck-share-toggle" title="<?= $proj_share_token ? 'Lien public actif' : 'Créer un lien public partageable' ?>">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><line x1="8.59" y1="13.51" x2="15.42" y2="17.49"/><line x1="15.41" y1="6.51" x2="8.59" y2="10.49"/></svg>
          <span><?= $proj_share_token ? 'Lien public actif' : 'Partager publiquement' ?></span>
          <?php if ($proj_share_token && (int)$proj_share_token['view_count'] > 0): ?>
            <span class="ck-share-views"><?= (int)$proj_share_token['view_count'] ?> vue<?= $proj_share_token['view_count'] > 1 ? 's' : '' ?></span>
          <?php endif; ?>
        </button>
        <div class="ck-share-pop" id="ck-share-pop" hidden>
          <div class="ck-share-pop-title">🔗 Lien public partageable</div>
          <div class="ck-share-pop-desc">Créer un lien que vous pouvez envoyer à des partenaires, financeurs ou parents. Ils verront le projet en lecture seule (avancement, photos, étapes) sans messages ni données privées.</div>

          <div id="ck-share-state-empty" <?= $proj_share_token ? 'hidden' : '' ?>>
            <button type="button" class="ck-btn-primary" id="ck-share-create" style="width:100%;">
              <span class="lbl">Générer le lien</span>
              <span class="ld" hidden>⏳ Création...</span>
            </button>
          </div>

          <div id="ck-share-state-active" <?= $proj_share_token ? '' : 'hidden' ?>>
            <div class="ck-share-url-row">
              <input type="text" id="ck-share-url" readonly value="<?= $proj_share_token ? h((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'] . '/projet-public.php?t=' . $proj_share_token['token']) : '' ?>"/>
              <button type="button" class="ck-share-copy" id="ck-share-copy" title="Copier">📋</button>
            </div>
            <div class="ck-share-stats">
              <?php if ($proj_share_token): ?>
                <span><strong><?= (int)$proj_share_token['view_count'] ?></strong> vue<?= $proj_share_token['view_count'] > 1 ? 's' : '' ?></span>
                <?php if (!empty($proj_share_token['last_viewed_at'])): ?>
                  <span>· Dernière : <?= h(format_date_p($proj_share_token['last_viewed_at'])) ?></span>
                <?php endif; ?>
              <?php endif; ?>
            </div>
            <div class="ck-share-actions">
              <a href="<?= $proj_share_token ? '/projet-public.php?t=' . h($proj_share_token['token']) : '#' ?>" target="_blank" class="ck-btn-ghost"><?= ak_icon('eye',14) ?>Aperçu</a>
              <button type="button" class="ck-btn-ghost ck-share-revoke" id="ck-share-revoke"><?= ak_icon('trash',14) ?>Révoquer</button>
            </div>
          </div>
        </div>
      </div>
      <?php endif; ?>
      </div><!-- /.ck-actions-bar -->

    </div>

    <!-- NEXT BEST ACTION -->
    <a href="<?= htmlspecialchars($proj_nba['link'], ENT_QUOTES) ?>" class="ck-nba <?= $tone_class ?>">
      <div class="ck-nba-emoji"><?= h($proj_nba['icon']) ?></div>
      <div class="ck-nba-body">
        <div class="ck-nba-pretitle">Prochaine action recommandée</div>
        <div class="ck-nba-title"><?= h($proj_nba['title']) ?></div>
        <div class="ck-nba-text"><?= $proj_nba['body'] ?></div>
        <?php if (!empty($proj_nba['alts'])): ?>
        <div class="ck-nba-alts">
          <?php foreach ($proj_nba['alts'] as $alt): ?>
            <span class="ck-nba-alt"><?= h($alt) ?></span>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div>
      <div class="ck-nba-cta">
        <span><?= h($proj_nba['cta']) ?></span>
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
      </div>
    </a>

  </section>

  <style>
  /* ============================================================
     COCKPIT IA — namespace .ck-*
     ============================================================ */
  .ck { display: flex; flex-direction: column; gap: 14px; margin: 0 0 24px; }

  /* HERO */
  .ck-hero {
    position: relative;
    background: #ffffff;
    border: 1px solid #e5e7eb;
    border-radius: 16px;
    padding: 22px 24px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.03), 0 8px 24px rgba(99,102,241,0.05);
    display: grid;
    grid-template-columns: auto 1fr;
    grid-template-rows: auto auto;
    gap: 18px 22px;
    align-items: center;
  }
  .ck-hero-bg {
    position: absolute; top: 0; left: 0; right: 0; height: 3px;
    border-radius: 16px 16px 0 0;
    background: linear-gradient(90deg, #10B981, #3B82F6, #6366F1, #8B5CF6);
    background-size: 300% 100%;
    animation: ck-grad 8s ease infinite;
  }
  @keyframes ck-grad { 0%,100%{background-position:0% 50%} 50%{background-position:100% 50%} }

  /* Donut */
  .ck-donut-block {
    grid-row: 1 / 3;
    display: flex; flex-direction: column; align-items: center; gap: 6px;
  }
  .ck-donut-wrap { position: relative; width: 90px; height: 90px; }
  .ck-donut { width: 100%; height: 100%; transform: rotate(-90deg); }
  .ck-donut-fg { transition: stroke-dashoffset 1.4s cubic-bezier(0.4, 0, 0.2, 1); }
  .ck-donut-num {
    position: absolute; inset: 0;
    display: flex; align-items: center; justify-content: center;
    font-size: 22px; font-weight: 700; color: #111827;
    font-variant-numeric: tabular-nums;
  }
  .ck-donut-num::after { content: '%'; font-size: 13px; font-weight: 500; color: #6b7280; margin-left: 2px; }
  .ck-donut-lbl { font-size: 10px; color: #6B7280; text-transform: uppercase; letter-spacing: 0.05em; font-weight: 600; }

  /* KPI grid */
  .ck-kpi-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 14px;
  }
  .ck-kpi {
    padding: 10px 14px;
    background: #fafbff;
    border: 1px solid #f3f4f6;
    border-radius: 10px;
    transition: transform 0.18s ease, box-shadow 0.18s ease;
  }
  .ck-kpi:hover { transform: translateY(-2px); box-shadow: 0 4px 10px rgba(0,0,0,0.04); }
  .ck-kpi-lbl { font-size: 10px; color: #6B7280; text-transform: uppercase; letter-spacing: 0.04em; font-weight: 600; margin-bottom: 4px; }
  .ck-kpi-val { font-size: 22px; font-weight: 700; color: #111827; line-height: 1; font-variant-numeric: tabular-nums; display: flex; align-items: baseline; gap: 4px; }
  .ck-kpi-tot { font-size: 13px; color: #6B7280; font-weight: 500; }
  .ck-kpi-unit { font-size: 11px; color: #6B7280; font-weight: 500; }
  .ck-kpi-health { gap: 6px; }
  .ck-kpi-sub { font-size: 11px; color: #6b7280; margin-top: 4px; font-weight: 500; }

  /* Sparkline + bilan ligne */
  .ck-spark { grid-column: 2; padding: 0 4px; }
  .ck-spark-lbl { font-size: 10px; color: #6B7280; text-transform: uppercase; letter-spacing: 0.04em; font-weight: 600; margin-bottom: 4px; }

  /* Bouton Générer le bilan */
  .ck-bilan { grid-column: 1 / 3; position: relative; padding-top: 12px; border-top: 1px dashed #e5e7eb; display: flex; justify-content: flex-end; }
  .ck-bilan-btn {
    display: inline-flex; align-items: center; gap: 10px;
    padding: 10px 18px;
    background: linear-gradient(135deg, #6366F1, #8B5CF6);
    color: #fff;
    border: 0; border-radius: 10px;
    font-size: 13px; font-weight: 600;
    cursor: pointer;
    box-shadow: 0 4px 12px rgba(99,102,241,0.3);
    transition: transform 0.15s ease, box-shadow 0.15s ease;
    font-family: inherit;
  }
  .ck-bilan-btn:hover { transform: translateY(-1px); box-shadow: 0 6px 16px rgba(99,102,241,0.4); }
  .ck-bilan-date { padding: 2px 8px; background: rgba(255,255,255,0.2); border-radius: 999px; font-size: 11px; font-weight: 500; }
  .ck-bilan-pop {
    position: absolute; right: 0; top: calc(100% + 6px);
    background: #fff; border: 1px solid #e5e7eb;
    border-radius: 12px; padding: 16px;
    box-shadow: 0 10px 30px rgba(0,0,0,0.12);
    width: 320px; max-width: calc(100vw - 32px); z-index: 1000;
  }
  .ck-bilan-pop-title { font-size: 13px; font-weight: 700; color: #111827; margin-bottom: 12px; }
  .ck-bilan-pop-row { margin-bottom: 12px; }
  .ck-bilan-pop-row label { display: block; font-size: 11px; color: #6b7280; font-weight: 600; margin-bottom: 4px; }
  .ck-bilan-pop-row input[type=date] { width: 100%; padding: 8px 10px; border: 1px solid #e5e7eb; border-radius: 8px; font-family: inherit; font-size: 13px; }
  .ck-bilan-pop-warn { font-size: 12px; color: #92400E; background: #FEF3C7; border-radius: 8px; padding: 8px 10px; margin-bottom: 12px; line-height: 1.5; }
  .ck-bilan-pop-warn a { color: #92400E; font-weight: 600; }
  .ck-bilan-pop-actions { display: flex; gap: 8px; justify-content: flex-end; }
  .ck-btn-ghost { padding: 7px 12px; background: transparent; border: 1px solid #e5e7eb; border-radius: 7px; cursor: pointer; font-size: 12px; font-family: inherit; color: #6b7280; }
  .ck-btn-primary { padding: 7px 14px; background: #6366F1; color: #fff; border-radius: 7px; text-decoration: none; font-size: 12px; font-weight: 600; }
  .ck-btn-primary:hover { background: #4F46E5; }

  /* PARTAGE PUBLIC */
  .ck-share { grid-column: 1 / 3; position: relative; padding-top: 12px; display: flex; justify-content: flex-end; }
  .ck-share-btn {
    display: inline-flex; align-items: center; gap: 8px;
    padding: 8px 14px;
    background: #ffffff; color: #4b5563;
    border: 1px solid #e5e7eb; border-radius: 8px;
    font-size: 12px; font-weight: 500;
    cursor: pointer; font-family: inherit;
    transition: all 0.15s ease;
  }
  .ck-share-btn:hover { border-color: #6366F1; color: #6366F1; }
  .ck-share-btn.is-active { background: #ECFDF5; border-color: #10B981; color: #065F46; }
  .ck-share-views { padding: 1px 7px; background: rgba(16,185,129,0.15); border-radius: 999px; font-size: 10.5px; font-weight: 700; }
  .ck-share-pop {
    position: absolute; right: 0; top: calc(100% + 6px);
    background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; padding: 16px;
    box-shadow: 0 10px 30px rgba(0,0,0,0.12);
    width: 360px; max-width: calc(100vw - 32px); z-index: 1000;
  }
  .ck-share-pop-title { font-size: 13px; font-weight: 700; color: #111827; margin-bottom: 8px; }
  .ck-share-pop-desc { font-size: 11.5px; color: #6b7280; line-height: 1.5; margin-bottom: 14px; }
  .ck-share-url-row { display: flex; gap: 6px; margin-bottom: 10px; }
  .ck-share-url-row input { flex: 1; min-width: 0; padding: 8px 10px; border: 1px solid #e5e7eb; border-radius: 7px; font-size: 11.5px; font-family: ui-monospace, monospace; color: #4b5563; background: #f9fafb; }
  .ck-share-copy { padding: 8px 10px; background: #6366F1; color: #fff; border: 0; border-radius: 7px; cursor: pointer; font-size: 13px; transition: background 0.15s; }
  .ck-share-copy:hover { background: #4F46E5; }
  .ck-share-copy.copied { background: #10B981; }
  .ck-share-stats { font-size: 11px; color: #6b7280; margin-bottom: 12px; }
  .ck-share-actions { display: flex; gap: 6px; }
  .ck-share-actions .ck-btn-ghost { flex: 1; text-align: center; text-decoration: none; display: inline-flex; align-items: center; justify-content: center; gap: 6px; }
  .ck-share-revoke { color: #DC2626 !important; }
  .ck-share-revoke:hover { background: #FEF2F2 !important; border-color: #DC2626 !important; }

  /* NEXT BEST ACTION */
  .ck-nba {
    display: flex; align-items: center; gap: 16px;
    padding: 16px 20px;
    background: #ffffff;
    border: 1px solid #e5e7eb;
    border-left: 4px solid #6366F1;
    border-radius: 12px;
    text-decoration: none; color: inherit;
    transition: transform 0.18s ease, box-shadow 0.18s ease, border-color 0.18s ease;
  }
  .ck-nba:hover { transform: translateY(-2px); box-shadow: 0 6px 18px rgba(0,0,0,0.06); }
  .ck-nba.tone-success { border-left-color: #10B981; background: linear-gradient(135deg, #f0fdf4, #ffffff); }
  .ck-nba.tone-warn { border-left-color: #F59E0B; background: linear-gradient(135deg, #fffbeb, #ffffff); }
  .ck-nba.tone-info { border-left-color: #3B82F6; background: linear-gradient(135deg, #eff6ff, #ffffff); }
  .ck-nba.tone-primary { border-left-color: #6366F1; background: linear-gradient(135deg, #f5f3ff, #ffffff); }
  .ck-nba-emoji {
    width: 48px; height: 48px; border-radius: 12px;
    background: rgba(99,102,241,0.1);
    display: flex; align-items: center; justify-content: center;
    font-size: 24px; flex-shrink: 0;
  }
  .ck-nba.tone-success .ck-nba-emoji { background: rgba(16,185,129,0.1); }
  .ck-nba.tone-warn .ck-nba-emoji { background: rgba(245,158,11,0.1); }
  .ck-nba.tone-info .ck-nba-emoji { background: rgba(59,130,246,0.1); }
  .ck-nba-body { flex: 1; min-width: 0; }
  .ck-nba-pretitle { font-size: 10px; color: #6B7280; text-transform: uppercase; letter-spacing: 0.06em; font-weight: 700; margin-bottom: 4px; }
  .ck-nba-title { font-size: 16px; font-weight: 700; color: #111827; margin-bottom: 3px; }
  .ck-nba-text { font-size: 13px; color: #4b5563; line-height: 1.5; }
  .ck-nba-text strong { color: #111827; }
  .ck-nba-alts { display: flex; gap: 6px; margin-top: 8px; flex-wrap: wrap; }
  .ck-nba-alt { font-size: 11px; padding: 3px 8px; background: #f3f4f6; color: #6b7280; border-radius: 6px; }
  .ck-nba-cta {
    display: flex; align-items: center; gap: 6px;
    padding: 9px 14px; border-radius: 8px;
    background: rgba(99,102,241,0.08);
    color: #6366F1; font-size: 12.5px; font-weight: 600;
    flex-shrink: 0;
    transition: background 0.18s ease, transform 0.18s ease;
  }
  .ck-nba.tone-success .ck-nba-cta { background: rgba(16,185,129,0.1); color: #10B981; }
  .ck-nba.tone-warn .ck-nba-cta { background: rgba(245,158,11,0.1); color: #B45309; }
  .ck-nba.tone-info .ck-nba-cta { background: rgba(59,130,246,0.1); color: #3B82F6; }
  .ck-nba:hover .ck-nba-cta { transform: translateX(4px); }

  /* ROW 2 col */
  .ck-row-2col { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }

  /* CARD générique */
  .ck-card {
    background: #ffffff;
    border: 1px solid #e5e7eb;
    border-radius: 12px;
    padding: 16px 18px;
  }
  .ck-card-head { display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; }
  .ck-card-title { font-size: 13px; font-weight: 700; color: #111827; display: inline-flex; align-items: center; gap: 8px; }
  .ck-card-sub { font-size: 11px; color: #6B7280; text-transform: uppercase; letter-spacing: 0.04em; font-weight: 600; }
  .ck-card-empty { text-align: center; color: #6b7280; font-size: 12px; padding: 16px 8px; }

  /* Team pulse */
  .ck-team-list { display: flex; flex-direction: column; gap: 10px; }
  .ck-team-row { display: flex; align-items: center; gap: 10px; }
  .ck-team-av { width: 32px; height: 32px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 11px; font-weight: 700; flex-shrink: 0; }
  .ck-team-info { flex: 1; min-width: 0; }
  .ck-team-name { font-size: 13px; font-weight: 600; color: #111827; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
  .ck-team-meta { font-size: 11px; color: #6B7280; margin-top: 1px; }
  .ck-team-bar { width: 80px; height: 5px; background: #f3f4f6; border-radius: 999px; overflow: hidden; flex-shrink: 0; }
  .ck-team-fill { height: 100%; border-radius: 999px; transition: width 1s cubic-bezier(0.4,0,0.2,1); }
  .ck-team-score { font-size: 12px; font-weight: 700; color: #111827; min-width: 24px; text-align: right; font-variant-numeric: tabular-nums; }

  /* Nudges */
  .ck-nudges-card { background: linear-gradient(135deg, #fffbeb, #ffffff); border-color: #fef3c7; }
  .ck-nudges-card.is-ok { background: linear-gradient(135deg, #f0fdf4, #ffffff); border-color: #d1fae5; }
  .ck-nudges-intro { font-size: 12px; color: #92400E; margin-bottom: 10px; line-height: 1.4; }
  .ck-nudges-list { display: flex; flex-direction: column; gap: 6px; }
  .ck-nudge {
    display: flex; align-items: center; gap: 10px;
    padding: 8px 10px;
    background: #ffffff;
    border: 1px solid #fef3c7;
    border-radius: 8px;
    text-decoration: none; color: inherit;
    transition: transform 0.15s ease, border-color 0.15s ease;
  }
  .ck-nudge:hover { transform: translateX(3px); border-color: #F59E0B; }
  .ck-nudge-icon { font-size: 18px; flex-shrink: 0; width: 28px; height: 28px; display: flex; align-items: center; justify-content: center; background: #FEF3C7; border-radius: 7px; }
  .ck-nudge-body { flex: 1; min-width: 0; }
  .ck-nudge-label { font-size: 12.5px; font-weight: 600; color: #111827; }
  .ck-nudge-why { font-size: 11px; color: #92400E; margin-top: 1px; }
  .ck-nudge-arrow { color: #B45309; flex-shrink: 0; }
  .ck-nudges-ok { text-align: center; padding: 16px 8px; }

  /* Animations */
  .ck { animation: ck-fadein 0.5s ease; }
  @keyframes ck-fadein { from { opacity: 0; transform: translateY(8px); } to { opacity: 1; transform: translateY(0); } }

  /* Mobile */
  @media (max-width: 860px) {
    .ck-hero { grid-template-columns: 1fr; text-align: center; }
    .ck-donut-block { grid-row: auto; flex-direction: row; justify-content: center; gap: 14px; }
    .ck-donut-lbl { display: none; }
    .ck-spark { grid-column: 1; padding: 0; }
    .ck-bilan { grid-column: 1; justify-content: center; }
    .ck-bilan-pop { right: auto; left: 50%; transform: translateX(-50%); }
    .ck-kpi-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; }
    .ck-row-2col { grid-template-columns: 1fr; }
    .ck-nba { flex-direction: column; text-align: center; align-items: stretch; }
    .ck-nba-emoji { margin: 0 auto; }
    .ck-nba-cta { justify-content: center; }
  }
  @media (max-width: 540px) {
    .ck { overflow-x: hidden; }
    .ck-hero { padding: 14px 14px; gap: 12px; }
    .ck-donut-wrap { width: 72px; height: 72px; }
    .ck-donut-num { font-size: 18px; }
    .ck-kpi { padding: 8px 10px; min-width: 0; }
    .ck-kpi-val { font-size: 18px; }
    .ck-kpi-lbl { font-size: 9px; }
    .ck-kpi-sub { font-size: 10px; }
    .ck-bilan-btn { width: 100%; justify-content: center; }
    .ck-bilan-pop { width: calc(100vw - 32px); max-width: 320px; }
    .ck-nba { padding: 14px; }
    .ck-nba-title { font-size: 14px; }
    .ck-nba-text { font-size: 12px; }
    .ck-card { padding: 12px 14px; }
  }
  @media (max-width: 380px) {
    .ck-kpi-grid { grid-template-columns: 1fr; }
  }
  /* Sécurité globale anti-overflow sur le cockpit */
  .ck, .ck-hero, .ck-kpi-grid, .ck-kpi, .ck-row-2col, .ck-card { min-width: 0; max-width: 100%; box-sizing: border-box; }

  /* Étapes : couleur lisible (override toujours, desktop + mobile + dark) */
  .step-item .step-title { color: #111827 !important; }
  .step-item.done .step-title { color: #6b7280 !important; }
  .step-item .step-desc { color: #4b5563 !important; }
  .step-item .step-meta { color: #6b7280 !important; }

  /* ANTI-OVERFLOW MOBILE GLOBAL */
  @media (max-width: 720px) {
    html, body { overflow-x: hidden !important; max-width: 100vw; }
    .main {
      overflow-x: hidden !important;
      width: 100% !important;
      max-width: 100vw !important;
      margin-left: 0 !important;
      margin-right: 0 !important;
      box-sizing: border-box !important;
      padding-left: 12px !important;
      padding-right: 12px !important;
    }
    /* Pas de max-width sur tous les enfants : casserait le scroll des tabs */
    .ck-hero, .ck-nba, .ck-card, .ck-bilan-btn,
    .ov2-layout, .ov2-card, .ov2-prog, .ov2-side-card, .ov2-side {
      width: 100% !important;
      max-width: 100% !important;
      box-sizing: border-box !important;
    }
    /* Tabs : scroll horizontal natif préservé */
    .tabs { width: 100% !important; max-width: 100% !important; }
    .tabs > .tab { flex-shrink: 0 !important; white-space: nowrap !important; max-width: none !important; }
    .ck-bilan-btn { width: 100%; }
    /* head-actions à droite du header : compact sur mobile */
    .head-actions { width: 100%; justify-content: flex-start; flex-wrap: wrap; }
    .proj-header { flex-direction: column; align-items: stretch; }
  }
  </style>

  <script>
  (function() {
    // Counters animés
    var akRunCounters = function() {
    document.querySelectorAll('[data-counter-to]').forEach(function(el) {
      var target = parseInt(el.dataset.counterTo, 10) || 0;
      if (target === 0) { el.textContent = '0'; return; }
      var dur = 1100, start = performance.now();
      function step(now) {
        var t = Math.min(1, (now - start) / dur);
        var eased = 1 - Math.pow(1 - t, 3);
        el.textContent = Math.round(target * eased);
        if (t < 1) requestAnimationFrame(step);
        else el.textContent = target;
      }
      requestAnimationFrame(step);
    });
    };
    if (document.readyState !== 'loading') { akRunCounters(); } else { document.addEventListener('DOMContentLoaded', akRunCounters); }

    // Donut animé
    setTimeout(function() {
      document.querySelectorAll('.ck-donut-fg[data-final]').forEach(function(c) {
        c.style.strokeDashoffset = c.dataset.final;
      });
    }, 80);

    // Bouton Générer le bilan : popover form
    var btn = document.getElementById('ck-bilan-toggle');
    var pop = document.getElementById('ck-bilan-pop');
    var submit = document.getElementById('ck-bilan-submit');
    if (btn && pop) {
      btn.addEventListener('click', function(e) {
        e.stopPropagation();
        pop.hidden = !pop.hidden;
      });
      document.addEventListener('click', function(e) {
        if (!pop.contains(e.target) && e.target !== btn && !btn.contains(e.target)) {
          pop.hidden = true;
        }
      });
      // Spinner pendant génération (Claude met 5-15s)
      pop.addEventListener('submit', function() {
        if (submit) {
          submit.disabled = true;
          var lbl = submit.querySelector('.ck-bilan-submit-lbl');
          var ld = submit.querySelector('.ck-bilan-submit-loading');
          if (lbl) lbl.style.display = 'none';
          if (ld) ld.style.display = 'inline';
        }
      });
    }

    // 🔗 PARTAGE PUBLIC
    var sBtn = document.getElementById('ck-share-toggle');
    var sPop = document.getElementById('ck-share-pop');
    var sCreate = document.getElementById('ck-share-create');
    var sCopy = document.getElementById('ck-share-copy');
    var sUrl = document.getElementById('ck-share-url');
    var sRevoke = document.getElementById('ck-share-revoke');
    var sStateEmpty = document.getElementById('ck-share-state-empty');
    var sStateActive = document.getElementById('ck-share-state-active');
    var csrfTok = '<?= h($_SESSION['csrf_token'] ?? '') ?>';

    if (sBtn && sPop) {
      sBtn.addEventListener('click', function(e) {
        e.stopPropagation();
        sPop.hidden = !sPop.hidden;
      });
      document.addEventListener('click', function(e) {
        if (!sPop.contains(e.target) && e.target !== sBtn && !sBtn.contains(e.target)) {
          sPop.hidden = true;
        }
      });
    }

    if (sCreate) {
      sCreate.addEventListener('click', function() {
        var lbl = sCreate.querySelector('.lbl');
        var ld = sCreate.querySelector('.ld');
        sCreate.disabled = true;
        if (lbl) lbl.hidden = true; if (ld) ld.hidden = false;
        var fd = new FormData();
        fd.append('csrf_token', csrfTok);
        fd.append('project_id', '<?= (int)$project['id'] ?>');
        fd.append('action', 'create');
        fetch('/action-share-public', { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' }})
          .then(r => r.json())
          .then(d => {
            if (d.ok) {
              var fullUrl = window.location.origin + d.url;
              sUrl.value = fullUrl;
              sStateEmpty.hidden = true;
              sStateActive.hidden = false;
              sBtn.classList.add('is-active');
              sBtn.querySelector('span').textContent = 'Lien public actif';
            } else {
              alert('Erreur : ' + (d.error || 'inconnue'));
              sCreate.disabled = false;
              if (lbl) lbl.hidden = false; if (ld) ld.hidden = true;
            }
          })
          .catch(() => { alert('Erreur réseau.'); sCreate.disabled = false; });
      });
    }

    if (sCopy && sUrl) {
      sCopy.addEventListener('click', function() {
        sUrl.select();
        try { document.execCommand('copy'); } catch(e) {}
        if (navigator.clipboard) navigator.clipboard.writeText(sUrl.value).catch(()=>{});
        sCopy.classList.add('copied');
        sCopy.textContent = '✓';
        setTimeout(function() { sCopy.classList.remove('copied'); sCopy.textContent = '📋'; }, 1500);
      });
    }

    if (sRevoke) {
      sRevoke.addEventListener('click', function() {
        if (!confirm('Révoquer ce lien ? Il ne sera plus accessible.')) return;
        var fd = new FormData();
        fd.append('csrf_token', csrfTok);
        fd.append('project_id', '<?= (int)$project['id'] ?>');
        fd.append('action', 'revoke');
        fetch('/action-share-public', { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' }})
          .then(r => r.json())
          .then(d => {
            if (d.ok) {
              sStateActive.hidden = true;
              sStateEmpty.hidden = false;
              sBtn.classList.remove('is-active');
              sBtn.querySelector('span').textContent = 'Partager publiquement';
              var sCreateLbl = sCreate.querySelector('.lbl'), sCreateLd = sCreate.querySelector('.ld');
              sCreate.disabled = false;
              if (sCreateLbl) sCreateLbl.hidden = false; if (sCreateLd) sCreateLd.hidden = true;
            } else { alert('Erreur : ' + (d.error || 'inconnue')); }
          });
      });
    }
  })();
  </script>

  <!-- Onglets -->
  <div class="tabs">
    <a href="/projet/<?= $project_id ?>" class="tab <?= $active_tab === 'overview' ? 'active' : '' ?>">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="3" width="7" height="9"/><rect x="14" y="3" width="7" height="5"/><rect x="14" y="12" width="7" height="9"/><rect x="3" y="16" width="7" height="5"/></svg>
      Vue d'ensemble
    </a>
    <a href="/projet/<?= $project_id ?>/messages" class="tab <?= $active_tab === 'messages' ? 'active' : '' ?>">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 4h16v14H5.17L4 19.17z"/></svg>
      Messages
      <?php if (count($messages) > 0): ?><span class="tab-badge"><?= count($messages) ?></span><?php endif; ?>
    </a>
    <?php if (!$is_follower_user): ?>
    <a href="/projet/<?= $project_id ?>/fichiers" class="tab <?= $active_tab === 'fichiers' ? 'active' : '' ?>">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M13 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"/><polyline points="13 2 13 9 20 9"/></svg>
      Fichiers
      <?php if (count($files) > 0): ?><span class="tab-badge"><?= count($files) ?></span><?php endif; ?>
    </a>
    <?php if (can('manage_finances')): ?>
    <a href="/projet/<?= $project_id ?>/factures" class="tab <?= $active_tab === 'factures' ? 'active' : '' ?>">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M9 11H3v8h6v-8z"/><path d="M21 3H9v16h12V3z"/><line x1="13" y1="7" x2="17" y2="7"/><line x1="13" y1="11" x2="17" y2="11"/></svg>
      Factures
      <?php if (count($invoices) > 0): ?><span class="tab-badge"><?= count($invoices) ?></span><?php endif; ?>
    </a>
    <?php endif; ?>
    <a href="/projet/<?= $project_id ?>/ia" class="tab tab-ai <?= $active_tab === 'ia' ? 'active' : '' ?>">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
      Assistant IA
    </a>
    <?php if ($is_admin): ?>
    <a href="/projet/<?= $project_id ?>/historique" class="tab <?= $active_tab === 'historique' ? 'active' : '' ?>" style="margin-left: auto;" title="Historique des modifications (visible par les administrateurs uniquement)">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
      Historique
    </a>
    <?php endif; ?>
    <?php endif; ?>
  </div>

  <?php if ($active_tab === 'overview'): ?>
    <!-- ===== VUE D'ENSEMBLE 2.0 ===== -->
    <div class="ov2-layout">
      <div class="ov2-main">

        <!-- PROGRESSION HERO -->
        <?php
          $r_ov = 36; $cx_ov = 44; $cy_ov = 44; $circ_ov = 2 * M_PI * $r_ov;
          $offset_ov = $circ_ov - ($computed_progress / 100) * $circ_ov;
          $prog_color = $project['status'] === 'warning' ? '#F59E0B' : '#10B981';
          $remaining = max(0, $total_steps - $done_steps);
        ?>
        <!-- ENGAGEMENT ÉQUIPE + BILAN (regroupés sous les onglets) -->
        <div class="ck-row-2col ov2-row-2col">
          <!-- TEAM PULSE -->
          <div class="ck-card">
            <div class="ck-card-head">
              <div class="ck-card-title"><?= ak_icon('users',16) ?>Engagement de l'équipe</div>
              <div class="ck-card-sub">30 derniers jours</div>
            </div>
            <?php if (empty($proj_team)): ?>
              <div class="ck-card-empty">Aucun membre dans l'équipe pour l'instant.</div>
            <?php else: ?>
              <div class="ck-team-list">
                <?php foreach ($proj_team as $i => $t):
                  $pct = (int)$t['pct'];
                  $color = $i === 0 ? '#10B981' : ($i === 1 ? '#6366F1' : ($i === 2 ? '#F59E0B' : '#9CA3AF'));
                ?>
                <div class="ck-team-row">
                  <div class="ck-team-av" style="background:<?= $color ?>22;color:<?= $color ?>"><?= h(user_initials($t['first'], $t['last'])) ?></div>
                  <div class="ck-team-info">
                    <div class="ck-team-name"><?= h($t['name']) ?></div>
                    <div class="ck-team-meta">
                      <?= $t['msg'] ?> msg · <?= $t['step'] ?> étape<?= $t['step'] > 1 ? 's' : '' ?> · <?= $t['file'] ?> fichier<?= $t['file'] > 1 ? 's' : '' ?>
                    </div>
                  </div>
                  <div class="ck-team-bar"><div class="ck-team-fill" style="width:<?= $pct ?>%;background:<?= $color ?>"></div></div>
                  <div class="ck-team-score"><?= (int)$t['score'] ?></div>
                </div>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>

          <!-- NUDGES INCITATIFS POUR BILAN -->
          <div class="ck-card ck-nudges-card" id="ck-nudges">
            <div class="ck-card-head">
              <div class="ck-card-title"><?= ak_icon('clipboard',16) ?>Pour un bilan complet</div>
              <div class="ck-card-sub"><?= empty($proj_missing) ? '✅ Prêt' : count($proj_missing) . ' à compléter' ?></div>
            </div>
            <?php if (empty($proj_missing)): ?>
              <div class="ck-nudges-ok">
                <div style="font-size:32px; margin-bottom:8px;">🎉</div>
                <div style="font-size:13px; font-weight:600; color:#065F46; margin-bottom:4px;">Tout est en place !</div>
                <div style="font-size:12px; color:#6b7280;">Ton bilan sera complet et professionnel. Clique sur « Générer le bilan » pour le créer.</div>
              </div>
            <?php else: ?>
              <div class="ck-nudges-intro">Ajoute ces éléments pour un bilan riche, lisible et exportable :</div>
              <div class="ck-nudges-list">
                <?php foreach ($proj_missing as $m): ?>
                <a href="<?= htmlspecialchars($m['link'], ENT_QUOTES) ?>" class="ck-nudge">
                  <span class="ck-nudge-icon"><?= h($m['icon']) ?></span>
                  <div class="ck-nudge-body">
                    <div class="ck-nudge-label"><?= h($m['label']) ?></div>
                    <div class="ck-nudge-why"><?= h($m['why']) ?></div>
                  </div>
                  <svg class="ck-nudge-arrow" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>
                </a>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>
        </div>

        <!-- DESCRIPTION -->
        <?php if ($project['description']): ?>
        <div class="ov2-card ov2-desc">
          <div class="ov2-card-head">
            <span class="ov2-card-icon" style="background:linear-gradient(135deg,#dbeafe,#bfdbfe);color:#1e40af;">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
            </span>
            <span class="ov2-card-title">Description</span>
          </div>
          <div class="ov2-card-text ov2-clamp"><?= nl2br(h($project['description'])) ?></div>
          <button type="button" class="ov2-more" hidden>Voir plus ▾</button>
        </div>
        <?php else: ?>
        <?php if ($can_edit_project): ?>
        <a href="/modifier-projet/<?= (int)$project['id'] ?>" class="ov2-card ov2-card-empty-link">
          <div class="ov2-card-head">
            <span class="ov2-card-icon" style="background:#f3f4f6;color:#6b7280;"><?= ak_icon('edit',14) ?></span>
            <span class="ov2-card-title" style="color:#6b7280;">Aucune description — clique pour en ajouter une</span>
          </div>
        </a>
        <?php endif; ?>
        <?php endif; ?>

        <!-- OBJECTIF -->
        <?php if ($project['objective']): ?>
        <div class="ov2-card ov2-objective">
          <div class="ov2-card-head">
            <span class="ov2-card-icon" style="background:linear-gradient(135deg,#d1fae5,#a7f3d0);color:#065f46;"><?= ak_icon('target',14) ?></span>
            <span class="ov2-card-title">Objectif</span>
          </div>
          <div class="ov2-card-text ov2-objective-text"><?= nl2br(h($project['objective'])) ?></div>
        </div>
        <?php else: ?>
        <?php if ($can_edit_project): ?>
        <a href="/modifier-projet/<?= (int)$project['id'] ?>" class="ov2-card ov2-card-empty-link">
          <div class="ov2-card-head">
            <span class="ov2-card-icon" style="background:#f3f4f6;color:#6b7280;"><?= ak_icon('target',14) ?></span>
            <span class="ov2-card-title" style="color:#6b7280;">Aucun objectif défini — précise ce que tu veux atteindre</span>
          </div>
        </a>
        <?php endif; ?>
        <?php endif; ?>

        <!-- ÉTAPES -->
        <div class="ov2-card">
          <div class="ov2-card-head">
            <span class="ov2-card-icon" style="background:linear-gradient(135deg,#ede9fe,#ddd6fe);color:#5b21b6;">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
            </span>
            <span class="ov2-card-title">Étapes du projet</span>
            <?php if ($total_steps > 0): ?>
              <span class="ov2-step-counter"><?= $done_steps ?> / <?= $total_steps ?></span>
            <?php endif; ?>
            <?php if ($can_edit_steps): ?>
              <a href="/modifier-etapes?id=<?= (int)$project['id'] ?>" class="ov2-card-action"><?= ak_icon('gear',13) ?>Modifier</a>
            <?php endif; ?>
          </div>
          <?php if ($total_steps > 0): ?>
          <div class="step-progress"><div class="step-progress-fill" style="width:<?= round(($done_steps / max(1, $total_steps)) * 100) ?>%"></div></div>
          <?php endif; ?>
          <?php if (empty($steps)): ?>
            <div class="ov2-card-empty">
              <div style="font-size: 28px; margin-bottom: 8px;">🌱</div>
              <div>Aucune étape définie pour ce projet.</div>
              <?php if ($can_edit_steps): ?>
                <a href="/modifier-etapes?id=<?= (int)$project['id'] ?>" style="display:inline-block; margin-top:10px; color:#6366f1; font-weight:600; text-decoration:none;">+ Ajouter la première étape</a>
              <?php endif; ?>
            </div>
          <?php else: ?>
            <div class="step-list">
              <?php foreach ($steps as $step):
                $by_color = in_array($step['by_color'], ['blue','purple','amber','pink','teal'], true)
                  ? 'av-' . $step['by_color'] : 'av-blue';
              ?>
              <div class="step-item <?= $step['is_completed'] ? 'done' : '' ?>">
                <?php if ($can_edit_steps): ?>
                  <form method="POST" action="/action-etape" style="display: inline; margin: 0;">
                    <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
                    <input type="hidden" name="step_id" value="<?= (int)$step['id'] ?>">
                    <input type="hidden" name="project_id" value="<?= (int)$project['id'] ?>">
                    <button type="submit" class="step-check <?= $step['is_completed'] ? 'done' : '' ?>">
                      <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg>
                    </button>
                  </form>
                <?php else: ?>
                  <span class="step-check readonly <?= $step['is_completed'] ? 'done' : '' ?>">
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg>
                  </span>
                <?php endif; ?>
                <div class="step-body">
                  <div class="step-title"><?= h($step['title']) ?></div>
                  <?php if ($step['description']): ?><div class="step-desc"><?= nl2br(h($step['description'])) ?></div><?php endif; ?>
                  <?php if ($step['is_completed'] && $step['by_first']): ?>
                    <div class="step-meta">
                      <span class="<?= $by_color ?>" style="width:16px;height:16px;font-size:8px;display:inline-flex;align-items:center;justify-content:center;border-radius:50%;font-weight:500;"><?= h(user_initials($step['by_first'], $step['by_last'])) ?></span>
                      Validée par <?= h($step['by_first'] . ' ' . $step['by_last']) ?> · <?= h(format_date_p($step['completed_at'])) ?>
                    </div>
                  <?php endif; ?>
                </div>
              </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <!-- ===== SIDEBAR 2.0 ===== -->
      <aside class="ov2-side">
        <?php if ($can_view_finances): ?>
        <?php
          $budget_color = $budget_pct >= 90 ? '#EF4444' : ($budget_pct >= 70 ? '#F59E0B' : '#10B981');
        ?>
        <div class="ov2-side-card">
          <div class="ov2-side-head">
            <span class="ov2-side-icon" style="background:linear-gradient(135deg,#fef3c7,#fde68a);color:#92400e;">💰</span>
            <span class="ov2-side-label">Budget</span>
          </div>
          <div class="ov2-side-value"><?= h(format_budget($project['budget_used'])) ?></div>
          <div class="ov2-side-sub">sur <?= h(format_budget($project['budget_planned'])) ?> prévus</div>
          <div class="ov2-side-bar"><div class="ov2-side-fill" style="width:<?= $budget_pct ?>%; background:<?= $budget_color ?>;"></div></div>
          <div class="ov2-side-bar-meta" style="color:<?= $budget_color ?>;"><?= $budget_pct ?> % engagé</div>
        </div>
        <?php endif; ?>

        <?php if ($project['participants_count'] > 0): ?>
        <?php $pf = (int)$project['participants_female']; $pm = (int)$project['participants_male']; $th = $pf + $pm; ?>
        <div class="ov2-side-card">
          <div class="ov2-side-head">
            <span class="ov2-side-icon" style="background:linear-gradient(135deg,#fce7f3,#fbcfe8);color:#9d174d;">👥</span>
            <span class="ov2-side-label">Participants</span>
          </div>
          <div class="ov2-side-value"><?= (int)$project['participants_count'] ?></div>
          <?php if ($th > 0): ?>
            <div class="ov2-pp-bars">
              <div class="ov2-pp-f" style="width: <?= ($pf / $th) * 100 ?>%" title="<?= $pf ?> femmes"></div>
              <div class="ov2-pp-m" style="width: <?= ($pm / $th) * 100 ?>%" title="<?= $pm ?> hommes"></div>
            </div>
            <div class="ov2-pp-legend">
              <span><span class="ov2-pp-dot ov2-pp-dot-f"></span> ♀ <?= $pf ?></span>
              <span><span class="ov2-pp-dot ov2-pp-dot-m"></span> ♂ <?= $pm ?></span>
            </div>
          <?php endif; ?>
        </div>
        <?php endif; ?>

        <div class="ov2-side-card">
          <div class="ov2-side-head">
            <span class="ov2-side-icon" style="background:linear-gradient(135deg,#dbeafe,#bfdbfe);color:#1e40af;">📅</span>
            <span class="ov2-side-label">Calendrier</span>
          </div>
          <div class="ov2-side-tl">
            <?php if ($project['start_date']): ?>
            <div class="ov2-tl-item">
              <span class="ov2-tl-dot" style="background:#10B981;"></span>
              <div class="ov2-tl-info">
                <div class="ov2-tl-label">Démarrage</div>
                <div class="ov2-tl-value"><?= h(format_date_p($project['start_date'])) ?></div>
              </div>
            </div>
            <?php endif; ?>
            <?php if ($project['end_date']): ?>
            <div class="ov2-tl-item">
              <span class="ov2-tl-dot" style="background:#F59E0B;"></span>
              <div class="ov2-tl-info">
                <div class="ov2-tl-label">Clôture prévue</div>
                <div class="ov2-tl-value"><?= h(format_date_p($project['end_date'])) ?></div>
              </div>
            </div>
            <?php endif; ?>
            <div class="ov2-tl-item">
              <span class="ov2-tl-dot" style="background:#9CA3AF;"></span>
              <div class="ov2-tl-info">
                <div class="ov2-tl-label">Créé le</div>
                <div class="ov2-tl-value"><?= h(format_date_p($project['created_at'])) ?></div>
              </div>
            </div>
          </div>
        </div>
      </aside>
    </div>

    <style>
    /* ============================================================
       VUE D'ENSEMBLE 2.0 — namespace .ov2-*
       ============================================================ */
    .ov2-layout {
      display: grid;
      grid-template-columns: 1fr 320px;
      gap: 18px;
      margin-bottom: 32px;
    }
    .ov2-main { display: flex; flex-direction: column; gap: 14px; min-width: 0; }

    /* PROGRESSION HERO */
    .ov2-prog {
      position: relative;
      background: linear-gradient(135deg, #ffffff 0%, #f0fdf4 100%);
      border: 1px solid #d1fae5;
      border-radius: 16px;
      padding: 22px 26px;
      display: flex;
      align-items: center;
      gap: 22px;
      box-shadow: 0 1px 3px rgba(0,0,0,0.03), 0 4px 16px rgba(16,185,129,0.06);
    }
    .ov2-prog-bg {
      position: absolute; top: 0; left: 0; right: 0; height: 3px;
      border-radius: 16px 16px 0 0;
      background: linear-gradient(90deg, #10B981, #34D399, #6EE7B7);
      background-size: 200% 100%;
      animation: ov2-grad 6s ease infinite;
    }
    @keyframes ov2-grad { 0%,100%{background-position:0% 50%} 50%{background-position:100% 50%} }
    .ov2-prog-donut { position: relative; width: 88px; height: 88px; flex-shrink: 0; }
    .ov2-prog-donut svg { width: 100%; height: 100%; }
    .ov2-prog-ring { transition: stroke-dashoffset 1.4s cubic-bezier(0.4, 0, 0.2, 1); }
    .ov2-prog-pct {
      position: absolute; inset: 0;
      display: flex; align-items: center; justify-content: center;
      font-size: 22px; font-weight: 700; color: #111827;
      font-variant-numeric: tabular-nums;
    }
    .ov2-prog-pct::after { content: '%'; font-size: 12px; font-weight: 500; color: #6b7280; margin-left: 1px; }
    .ov2-prog-info { flex: 1; min-width: 0; }
    .ov2-prog-title { font-size: 11px; color: #065F46; text-transform: uppercase; letter-spacing: 0.06em; font-weight: 700; margin-bottom: 8px; }
    .ov2-prog-bar-bg { height: 8px; background: #ffffff; border-radius: 999px; overflow: hidden; box-shadow: inset 0 1px 2px rgba(0,0,0,0.05); }
    .ov2-prog-bar { height: 100%; border-radius: 999px; transition: width 1.2s cubic-bezier(0.4,0,0.2,1); }
    .ov2-prog-meta { font-size: 13px; color: #4b5563; margin-top: 8px; }
    .ov2-prog-meta strong { color: #111827; font-weight: 700; font-variant-numeric: tabular-nums; }
    .ov2-prog-remaining { color: #B45309; font-weight: 500; }
    .ov2-prog-done { color: #059669; font-weight: 600; }

    /* CARD générique */
    .ov2-card {
      background: #ffffff;
      border: 1px solid #e5e7eb;
      border-radius: 14px;
      padding: 18px 22px;
      transition: box-shadow 0.2s ease, border-color 0.2s ease;
    }
    .ov2-card:hover { box-shadow: 0 4px 14px rgba(0,0,0,0.04); }
    .ov2-card-head {
      display: flex; align-items: center; gap: 10px;
      margin-bottom: 12px;
    }
    .ov2-card-icon {
      width: 28px; height: 28px;
      border-radius: 8px;
      display: inline-flex; align-items: center; justify-content: center;
      font-size: 14px;
      flex-shrink: 0;
    }
    .ov2-card-title { font-size: 14px; font-weight: 700; color: #111827; flex: 1; min-width: 0; }
    .ov2-step-counter {
      font-size: 11px; font-weight: 700;
      padding: 3px 9px; border-radius: 999px;
      background: #ede9fe; color: #5b21b6;
      font-variant-numeric: tabular-nums;
    }
    .ov2-card-action {
      font-size: 11px; color: #6b7280; text-decoration: none;
      display: inline-flex; align-items: center; gap: 6px;
      padding: 4px 10px; border-radius: 6px;
      transition: background 0.15s ease, color 0.15s ease;
    }
    .ov2-card-action:hover { background: #f3f4f6; color: #111827; }
    .ov2-card-text {
      font-size: 13.5px; color: #374151; line-height: 1.65;
    }
    .ov2-card-empty {
      text-align: center; color: #6b7280;
      padding: 24px 12px; font-size: 13px; line-height: 1.5;
    }
    .ov2-card-empty-link {
      display: block; text-decoration: none; color: inherit;
      background: #fafbfc;
      border: 1px dashed #e5e7eb;
    }
    .ov2-card-empty-link:hover { background: #f3f4f6; border-color: #d1d5db; }

    /* OBJECTIF — mise en valeur */
    .ov2-objective {
      background: linear-gradient(135deg, #ffffff 0%, #f0fdf4 100%);
      border-color: #d1fae5;
    }
    .ov2-objective-text {
      padding: 12px 16px;
      background: rgba(16,185,129,0.08);
      border-left: 3px solid #10B981;
      border-radius: 0 8px 8px 0;
      color: #065F46;
      font-weight: 500;
    }

    /* SIDEBAR */
    .ov2-side {
      display: flex; flex-direction: column; gap: 14px;
      min-width: 0;
    }
    .ov2-side-card {
      background: #ffffff;
      border: 1px solid #e5e7eb;
      border-radius: 14px;
      padding: 16px 18px;
      transition: box-shadow 0.2s ease;
    }
    .ov2-side-card:hover { box-shadow: 0 4px 14px rgba(0,0,0,0.04); }
    .ov2-side-head {
      display: flex; align-items: center; gap: 10px;
      margin-bottom: 12px;
    }
    .ov2-side-icon {
      width: 30px; height: 30px;
      border-radius: 8px;
      display: inline-flex; align-items: center; justify-content: center;
      font-size: 14px;
    }
    .ov2-side-label {
      font-size: 11px; color: #6b7280;
      text-transform: uppercase; letter-spacing: 0.05em;
      font-weight: 700;
    }
    .ov2-side-value {
      font-size: 26px; font-weight: 700;
      color: #111827; line-height: 1;
      font-variant-numeric: tabular-nums;
    }
    .ov2-side-sub { font-size: 12px; color: #6b7280; margin-top: 4px; }
    .ov2-side-bar {
      height: 6px; background: #f3f4f6; border-radius: 999px;
      overflow: hidden; margin-top: 12px;
      box-shadow: inset 0 1px 2px rgba(0,0,0,0.05);
    }
    .ov2-side-fill { height: 100%; border-radius: 999px; transition: width 1.2s cubic-bezier(0.4,0,0.2,1); }
    .ov2-side-bar-meta {
      font-size: 11px; font-weight: 700; margin-top: 6px;
      text-align: right; font-variant-numeric: tabular-nums;
    }

    /* Participants — barres genre */
    .ov2-pp-bars {
      display: flex; height: 8px; border-radius: 999px;
      overflow: hidden; margin-top: 12px;
      background: #f3f4f6;
    }
    .ov2-pp-f { background: linear-gradient(90deg, #ec4899, #f472b6); }
    .ov2-pp-m { background: linear-gradient(90deg, #3b82f6, #60a5fa); }
    .ov2-pp-legend {
      display: flex; justify-content: space-between;
      font-size: 11.5px; color: #6b7280; margin-top: 8px;
      font-variant-numeric: tabular-nums;
    }
    .ov2-pp-dot {
      display: inline-block; width: 8px; height: 8px;
      border-radius: 50%; margin-right: 4px;
      vertical-align: middle;
    }
    .ov2-pp-dot-f { background: #ec4899; }
    .ov2-pp-dot-m { background: #3b82f6; }

    /* Timeline calendrier */
    .ov2-side-tl {
      position: relative;
      padding-left: 12px;
    }
    .ov2-side-tl::before {
      content: ''; position: absolute;
      left: 4px; top: 6px; bottom: 6px;
      width: 1.5px;
      background: linear-gradient(180deg, #10B981, #F59E0B, #9CA3AF);
      border-radius: 2px;
    }
    .ov2-tl-item {
      position: relative;
      display: flex; align-items: flex-start; gap: 10px;
      padding: 6px 0;
    }
    .ov2-tl-dot {
      position: absolute; left: -12px; top: 10px;
      width: 9px; height: 9px;
      border-radius: 50%;
      box-shadow: 0 0 0 3px #ffffff, 0 0 0 4px rgba(0,0,0,0.05);
    }
    .ov2-tl-info { padding-left: 4px; flex: 1; min-width: 0; }
    .ov2-tl-label { font-size: 10px; color: #6B7280; text-transform: uppercase; letter-spacing: 0.04em; font-weight: 600; }
    .ov2-tl-value { font-size: 13px; color: #111827; font-weight: 600; margin-top: 2px; }

    /* Animations */
    .ov2-layout { animation: ov2-fadein 0.5s ease; }
    @keyframes ov2-fadein {
      from { opacity: 0; transform: translateY(6px); }
      to { opacity: 1; transform: translateY(0); }
    }

    /* ============================================================
       RESPONSIVE MOBILE — TOUTE la page projet
       ============================================================ */
    @media (max-width: 1100px) {
      .ov2-layout { grid-template-columns: 1fr 280px; gap: 14px; }
    }
    @media (max-width: 860px) {
      /* Layout général */
      .ov2-layout { grid-template-columns: 1fr; }
      .ov2-side {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 12px;
      }
      /* Header projet : icone + texte sur 2 lignes */
      .proj-header { flex-wrap: wrap; }
      /* Onglets : scrollable horizontalement */
      .tabs { overflow-x: auto; -webkit-overflow-scrolling: touch; flex-wrap: nowrap; padding-bottom: 4px; }
      .tabs::-webkit-scrollbar { height: 0; }
      .tab { white-space: nowrap; flex-shrink: 0; }
      /* Progression Hero : passe en colonne */
      .ov2-prog { flex-direction: column; align-items: stretch; text-align: center; gap: 14px; padding: 18px 18px; }
      .ov2-prog-donut { margin: 0 auto; }
      .ov2-prog-info { text-align: center; }
    }
    @media (max-width: 640px) {
      .ov2-side { grid-template-columns: 1fr; }
      .ov2-card { padding: 14px 16px; }
      .ov2-card-text { font-size: 13px; }
      .ov2-card-action { display: none; }
      .ov2-side-card { padding: 14px 16px; }
      .ov2-side-value { font-size: 22px; }
      /* Onglets compacts */
      .tab { padding: 8px 10px; font-size: 12px; }
      .tab-badge { font-size: 10px; padding: 1px 6px; }
      .proj-header-title { font-size: 22px !important; line-height: 1.25; }
      .proj-header-tag { font-size: 10px !important; }
      .proj-header-meta { font-size: 12px !important; flex-wrap: wrap; gap: 6px; }
      /* Head actions : seulement icônes */
      .head-actions .btn span,
      .head-actions a:not(.btn) {
        font-size: 12px;
        padding: 6px 10px !important;
      }
    }
    @media (max-width: 420px) {
      .ov2-prog { padding: 14px; }
      .ov2-prog-pct { font-size: 18px; }
      .ov2-side-value { font-size: 19px; }
      .ck-bilan-btn { padding: 9px 14px; font-size: 12px; }
      .ck-bilan-date { display: none; }
    }
    </style>

    <script>
    // Animation donut + counter pour la vue d'ensemble 2.0
    (function() {
      setTimeout(function() {
        document.querySelectorAll('.ov2-prog-ring[data-final]').forEach(function(c) {
          c.style.strokeDashoffset = c.dataset.final;
        });
      }, 100);
    })();
    </script>

  <?php elseif ($active_tab === 'messages'): ?>
    <!-- ===== MESSAGES (temps réel via polling) ===== -->
    <div class="chat-wrap" data-project-id="<?= (int)$project_id ?>">
      
      <!-- Indicateur "x personnes connectées" / "Mise à jour en cours..." -->
      <div id="chatStatus" style="display:flex; align-items:center; gap:8px; padding:6px 12px; font-size:11.5px; color:var(--ink-3); background:var(--bg-2); border-radius:6px; margin-bottom:8px;">
        <span class="status-dot" style="display:inline-block; width:7px; height:7px; border-radius:50%; background:#10B981; box-shadow:0 0 0 0 rgba(16,185,129,0.5); animation:statusPulse 2s infinite;"></span>
        <span id="statusText">Synchronisation active · vérifie toutes les 5 sec</span>
      </div>
      <style>
        @keyframes statusPulse {
          0%   { box-shadow: 0 0 0 0 rgba(16,185,129,0.4); }
          70%  { box-shadow: 0 0 0 6px rgba(16,185,129,0); }
          100% { box-shadow: 0 0 0 0 rgba(16,185,129,0); }
        }
        .chat-msg-new {
          animation: msgFadeIn 0.4s ease-out;
        }
        @keyframes msgFadeIn {
          from { opacity: 0; transform: translateY(8px); }
          to   { opacity: 1; transform: translateY(0); }
        }
      </style>
      
      <div class="chat-list" id="chatList">
        <?php if (empty($messages)): ?>
          <div class="empty-state" id="chatEmpty">Aucun message pour l'instant. Démarrez la conversation d'équipe ci-dessous.</div>
        <?php else: foreach ($messages as $m):
          $color = in_array($m['avatar_color'], ['blue','purple','amber','pink','teal'], true) ? 'av-' . $m['avatar_color'] : 'av-blue';
          $is_self = ($m['user_id'] == $current['id']);
        ?>
        <div class="chat-msg" data-msg-id="<?= (int)$m['id'] ?>">
          <span class="chat-avatar <?= $color ?>"><?= h(user_initials($m['first_name'], $m['last_name'])) ?></span>
          <div class="chat-bubble">
            <div class="chat-head-line">
              <span class="chat-author"><?= h($m['first_name'] . ' ' . $m['last_name']) ?><?= $is_self ? ' (vous)' : '' ?></span>
              <span class="chat-time"><?= h(format_time_p($m['created_at'])) ?></span>
            </div>
            <div class="chat-content"><?= ak_highlight_mentions($m['content']) ?></div>
          </div>
        </div>
        <?php endforeach; endif; ?>
      </div>
      <form method="POST" action="/action-message" class="chat-form">
        <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
        <input type="hidden" name="project_id" value="<?= $project_id ?>">
        
        <textarea name="content" class="chat-input" placeholder="Écrire un message… Tape @ pour mentionner un membre (il recevra un email)" required maxlength="5000" rows="1" id="chatInput" autocomplete="off"></textarea>
        <button type="submit" class="chat-send">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
          Envoyer
        </button>
      </form>
      
      <!-- Dropdown autocomplete @mentions (position fixed, calculé en JS) -->
      <div id="mentionDropdown" style="display:none; position:fixed; background:#fff; border:1px solid #e5e7eb; border-radius:10px; box-shadow:0 -4px 24px rgba(0,0,0,0.12); max-height:280px; overflow-y:auto; z-index:9999; min-width:280px;">
        <div style="font-size:11px; color:#6B7280; padding:10px 12px 6px; font-weight:600; text-transform:uppercase; letter-spacing:0.04em; border-bottom:1px solid #f3f4f6;">
          👥 Mentionner un membre
        </div>
        <div id="mentionList"></div>
      </div>
      
      <!-- Données JSON pour le JS autocomplete -->
      <script id="mentionDataJson" type="application/json"><?= json_encode(array_map(function($u) {
        return [
            'id' => (int)$u['id'],
            'first' => $u['first_name'],
            'last' => $u['last_name'],
            'name' => trim($u['first_name'] . ' ' . $u['last_name']),
            'role' => $u['role'],
            'color' => $u['avatar_color'] ?? 'blue',
            'initials' => mb_strtoupper(mb_substr($u['first_name'], 0, 1) . mb_substr($u['last_name'], 0, 1)),
            'tag' => mb_strtolower($u['first_name']),
        ];
      }, $mention_targets), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
    </div>

  <?php elseif ($active_tab === 'fichiers'): ?>
    <!-- ===== FICHIERS ===== -->
    <form method="POST" action="/action-fichier" enctype="multipart/form-data" class="drop-zone" onclick="document.getElementById('fileInput').click();" style="margin-bottom: 20px;">
      <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
      <input type="hidden" name="project_id" value="<?= $project_id ?>">
      <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
      <div class="drop-zone-title">Ajouter des fichiers au projet</div>
      <div class="drop-zone-sub">PDF, images, documents Word/Excel · jusqu'à 10 Mo par fichier · sélection multiple ⌘+clic</div>
      <div class="drop-zone-ai">
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
        <span>L'IA utilisera ces fichiers pour rédiger vos bilans</span>
      </div>
      <input type="file" id="fileInput" name="files[]" multiple style="display:none;" onchange="this.form.submit();" accept=".pdf,.jpg,.jpeg,.png,.gif,.webp,.doc,.docx,.xls,.xlsx,.txt,.csv,.ppt,.pptx,.odt,.ods">
    </form>

    <?php if (!empty($_SESSION['flash']) && $active_tab === 'fichiers'): 
        $f = $_SESSION['flash']; unset($_SESSION['flash']);
    ?>
      <div class="alert alert-<?= $f['type'] === 'error' ? 'error' : 'success' ?>"><?= h($f['message']) ?></div>
    <?php endif; ?>

    <?php if (isset($_GET['err'])): ?>
      <div class="alert alert-error">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/></svg>
        <?php
          $errs = ['size' => 'Fichier trop gros (max 10 Mo).', 'type' => 'Type non autorisé.', 'upload' => 'Erreur d\'upload.', 'mkdir' => 'Impossible de créer le dossier.', 'move' => 'Impossible de sauvegarder.'];
          echo h($errs[$_GET['err']] ?? 'Erreur inconnue');
        ?>
      </div>
    <?php endif; ?>

    <?php if (empty($files)): ?>
      <div class="panel"><div class="empty-state">Aucun fichier pour l'instant.</div></div>
    <?php else: ?>
      <div class="files-grid">
        <?php foreach ($files as $f): 
            $ic = file_icon_class($f['mime_type'], $f['filename']);
            // Permission de suppression : admin OU référent OU uploader
            $can_delete_file = $is_admin || $is_referent || ((int)$f['uploaded_by'] === (int)$current['id']);
        ?>
        <div class="file-card" style="position:relative;">
          <a href="/fichier-projet?type=file&amp;id=<?= (int)$f['id'] ?>" target="_blank" rel="noopener" style="display:flex; align-items:center; gap:12px; flex:1; text-decoration:none; color:inherit;">
            <div class="file-icon <?= $ic ?>">
              <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M13 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"/><polyline points="13 2 13 9 20 9"/></svg>
            </div>
            <div class="file-info">
              <div class="file-name"><?= h($f['filename']) ?></div>
              <div class="file-meta"><?= h(format_filesize($f['filesize_bytes'])) ?> · <?= h(format_date_p($f['created_at'])) ?><br><?= h($f['first_name'] . ' ' . $f['last_name']) ?></div>
            </div>
          </a>
          <?php if ($can_delete_file): ?>
            <form method="POST" action="/action-fichier-supprimer" style="margin:0;"
                  onsubmit="return confirm('Supprimer définitivement « <?= h(addslashes($f['filename'])) ?> » ?');">
              <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
              <input type="hidden" name="file_id" value="<?= (int)$f['id'] ?>">
              <input type="hidden" name="project_id" value="<?= $project_id ?>">
              <button type="submit" 
                      style="background:transparent; border:none; color:#DC2626; cursor:pointer; padding:6px; border-radius:6px; transition:background 0.15s;"
                      onmouseover="this.style.background='#FEF2F2'"
                      onmouseout="this.style.background='transparent'"
                      title="Supprimer ce fichier">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-2 14a2 2 0 0 1-2 2H9a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6M14 11v6"/></svg>
              </button>
            </form>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

  <?php elseif ($active_tab === 'factures'): ?>
    <!-- ===== FACTURES DU PROJET ===== -->
    <?php
      $can_validate = $is_admin || $is_coord;
      $can_add = true; // tous les membres de l'org peuvent ajouter une facture
    ?>

    <!-- Messages de confirmation -->
    <?php if (isset($_GET['added'])): ?>
      <div class="alert alert-success"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>Facture ajoutée avec succès.</div>
    <?php elseif (isset($_GET['validated'])): ?>
      <div class="alert alert-success"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>Facture validée. Le budget a été mis à jour automatiquement.</div>
    <?php elseif (isset($_GET['rejected'])): ?>
      <div class="alert alert-success"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>Facture rejetée.</div>
    <?php elseif (isset($_GET['deleted'])): ?>
      <div class="alert alert-success"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>Facture supprimée.</div>
    <?php elseif (isset($_GET['err'])): ?>
      <div class="alert alert-error"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/></svg><?php
        $errs = ['invalid' => 'Merci de remplir le fournisseur, le montant et la date.', 'filetype' => 'Format de fichier non autorisé (PDF, JPG, PNG uniquement).', 'filesize' => 'Fichier trop gros (max 10 Mo).'];
        echo h($errs[$_GET['err']] ?? 'Une erreur est survenue.');
      ?></div>
    <?php endif; ?>

    <!-- Résumé financier live -->
    <div class="inv-summary">
      <div class="inv-summary-card primary">
        <div class="inv-summary-lbl">Budget utilisé</div>
        <div class="inv-summary-val"><?= h(format_budget($total_validated)) ?> <span style="font-size: 11px; color: var(--ink-4); font-weight: 400;">TTC</span></div>
        <?php if ($total_vat > 0.01): ?>
          <div class="inv-summary-sub"><?= h(format_budget($total_validated_ht)) ?> HT · <?= h(format_budget($total_vat)) ?> TVA</div>
        <?php else: ?>
          <div class="inv-summary-sub">sur <?= h(format_budget($project['budget_planned'])) ?> prévus</div>
        <?php endif; ?>
      </div>
      <div class="inv-summary-card">
        <div class="inv-summary-lbl">Reste disponible</div>
        <div class="inv-summary-val" style="color: <?= $budget_remaining >= 0 ? 'var(--acc-dark)' : '#B91C1C' ?>"><?= h(format_budget($budget_remaining)) ?></div>
        <div class="inv-summary-sub"><?= $budget_remaining >= 0 ? 'Sur budget prévu TTC' : 'Dépassement' ?></div>
      </div>
      <?php if ($count_pending > 0): ?>
      <div class="inv-summary-card warn">
        <div class="inv-summary-lbl">En attente</div>
        <div class="inv-summary-val"><?= h(format_budget($total_pending)) ?></div>
        <div class="inv-summary-sub"><?= $count_pending ?> facture<?= $count_pending > 1 ? 's' : '' ?> à valider</div>
      </div>
      <?php endif; ?>
      <div class="inv-summary-card">
        <div class="inv-summary-lbl">Total factures</div>
        <div class="inv-summary-val"><?= count($invoices) ?></div>
        <div class="inv-summary-sub">sur ce projet</div>
      </div>
    </div>

    <!-- Formulaire d'ajout -->
    <?php if ($can_add): ?>
    <div class="inv-add-form">
      <h3 class="inv-add-title" style="display:inline-flex;align-items:center;gap:8px;"><?= ak_icon('plus',18) ?>Ajouter une facture à ce projet</h3>

      <!-- Zone scan IA (visible si l'IA est configurée) -->
      <?php if ($ai_ready): ?>
      <div id="scanZone" style="background: linear-gradient(135deg, var(--ai-light) 0%, var(--bg) 100%); border: 1px dashed var(--ai); border-radius: 12px; padding: 18px 20px; margin-bottom: 18px;">
        <div style="display: flex; gap: 14px; align-items: flex-start;">
          <div style="width: 38px; height: 38px; border-radius: 10px; background: var(--ai); color: #fff; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
          </div>
          <div style="flex: 1;">
            <div style="font-size: 14.5px; font-weight: 500; margin-bottom: 3px;">Laissez l'IA lire votre facture</div>
            <div style="font-size: 12.5px; color: var(--ink-2); line-height: 1.5; margin-bottom: 10px;">Glissez votre PDF ou photo de facture, l'IA extrait automatiquement le fournisseur, les montants HT/TVA/TTC, la date et la catégorie.</div>
            <input type="file" id="scanFileInput" accept=".pdf,.jpg,.jpeg,.png" style="display:none;">
            <button type="button" id="scanBtn" class="btn btn-primary" style="padding: 8px 14px;">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
              Choisir une facture à scanner
            </button>
            <div id="scanStatus" style="margin-top: 10px; font-size: 12.5px; display: none;"></div>
          </div>
        </div>
      </div>

      <div style="text-align: center; margin: 0 0 18px; font-size: 11px; color: var(--ink-4); letter-spacing: 0.05em; text-transform: uppercase;">— ou remplir à la main —</div>
      <?php endif; ?>

      <form method="POST" action="/action-facture" enctype="multipart/form-data" id="invoiceForm">
        <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
        <input type="hidden" name="project_id" value="<?= $project_id ?>">
        <input type="hidden" name="action" value="add">
        <input type="hidden" name="temp_file" id="tempFileInput" value="">

        <div class="form-cols">
          <div class="form-row">
            <label class="form-label">Fournisseur <span class="required">*</span></label>
            <input type="text" name="supplier_name" class="form-input-lg" required placeholder="Ex : Fnac Pro" maxlength="120">
          </div>
          <div class="form-row">
            <label class="form-label">Catégorie</label>
            <select name="category" class="form-select-lg">
              <option value="">— Choisir —</option>
              <option value="Matériel vidéo">Matériel vidéo</option>
              <option value="Matériel audio">Matériel audio</option>
              <option value="Matériel informatique">Matériel informatique</option>
              <option value="Fournitures">Fournitures</option>
              <option value="Alimentation">Alimentation</option>
              <option value="Transport">Transport</option>
              <option value="Location">Location</option>
              <option value="Télécom">Télécom</option>
              <option value="Livres / Matériel pédagogique">Livres / Matériel pédagogique</option>
              <option value="Frais administratifs">Frais administratifs</option>
              <option value="Prestations externes">Prestations externes</option>
              <option value="Autre">Autre</option>
            </select>
          </div>
        </div>

        <!-- Mode de saisie HT / TTC / Pas de TVA -->
        <div class="form-row">
          <label class="form-label">Mode de saisie du montant</label>
          <div class="filter-chips" style="gap: 6px;">
            <label class="chip amount-mode-chip active" data-mode="ttc">
              <input type="radio" name="amount_mode" value="ttc" checked style="display:none;">
              Je connais le TTC
            </label>
            <label class="chip amount-mode-chip" data-mode="ht">
              <input type="radio" name="amount_mode" value="ht" style="display:none;">
              Je connais le HT
            </label>
            <label class="chip amount-mode-chip" data-mode="no_vat">
              <input type="radio" name="amount_mode" value="no_vat" style="display:none;">
              Pas de TVA (asso non assujettie)
            </label>
          </div>
          <div class="form-hint">Choisissez selon ce qui est indiqué sur votre facture. Le calcul se fait automatiquement.</div>
        </div>

        <!-- Champs de montant : TTC + TVA -->
        <div class="form-cols amount-fields" id="modeTtc">
          <div class="form-row">
            <label class="form-label">Montant TTC <span class="required">*</span></label>
            <div class="num-suffix-wrap">
              <input type="text" name="amount_ttc" id="inputTtc" class="form-input-lg" placeholder="0,00" inputmode="decimal">
              <span class="suffix">€</span>
            </div>
            <div class="form-hint" id="hintHtComputed">HT calculé : <b>—</b></div>
          </div>
          <div class="form-row">
            <label class="form-label">Taux de TVA</label>
            <select name="vat_rate" id="vatRateTtc" class="form-select-lg">
              <option value="20" selected>20 % (taux normal)</option>
              <option value="10">10 % (taux intermédiaire)</option>
              <option value="5.5">5,5 % (taux réduit)</option>
              <option value="2.1">2,1 % (taux super réduit)</option>
              <option value="0">0 % (non assujetti / exonéré)</option>
            </select>
          </div>
        </div>

        <!-- Champs de montant : HT + TVA (caché par défaut) -->
        <div class="form-cols amount-fields" id="modeHt" style="display:none;">
          <div class="form-row">
            <label class="form-label">Montant HT <span class="required">*</span></label>
            <div class="num-suffix-wrap">
              <input type="text" name="amount_ht" id="inputHt" class="form-input-lg" placeholder="0,00" inputmode="decimal">
              <span class="suffix">€</span>
            </div>
            <div class="form-hint" id="hintTtcComputed">TTC calculé : <b>—</b></div>
          </div>
          <div class="form-row">
            <label class="form-label">Taux de TVA</label>
            <select id="vatRateHt" class="form-select-lg">
              <option value="20" selected>20 % (taux normal)</option>
              <option value="10">10 % (taux intermédiaire)</option>
              <option value="5.5">5,5 % (taux réduit)</option>
              <option value="2.1">2,1 % (taux super réduit)</option>
            </select>
          </div>
        </div>

        <!-- Champs de montant : juste un montant (pas de TVA) -->
        <div class="form-row amount-fields" id="modeNoVat" style="display:none;">
          <label class="form-label">Montant <span class="required">*</span></label>
          <div class="num-suffix-wrap" style="max-width: 280px;">
            <input type="text" name="amount_ttc" id="inputNoVat" class="form-input-lg" placeholder="0,00" inputmode="decimal" disabled>
            <span class="suffix">€</span>
          </div>
          <div class="form-hint">Votre association n'est pas assujettie à la TVA. HT = TTC.</div>
        </div>

        <div class="form-cols">
          <div class="form-row">
            <label class="form-label">Date de la facture <span class="required">*</span></label>
            <input type="date" name="invoice_date" class="form-input-lg" required value="<?= h(date('Y-m-d')) ?>">
          </div>
          <div class="form-row">
            <label class="form-label">N° de facture (optionnel)</label>
            <input type="text" name="invoice_number" class="form-input-lg" placeholder="Ex : 2026-04-123" maxlength="60">
          </div>
        </div>

        <div class="form-row">
          <label class="form-label">Description / détail (optionnel)</label>
          <input type="text" name="description" class="form-input-lg" placeholder="Ex : Caméra Sony FX3 + 2 objectifs pour tournage du 4-6 mai" maxlength="500">
        </div>

        <div class="form-row">
          <label class="form-label" style="display: flex; align-items: center; gap: 8px;">
            <a href="#" id="toggleManualFile" style="color: var(--acc); font-weight: 500; font-size: 12.5px; text-decoration: none; display: inline-flex; align-items: center; gap: 5px;">
              <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"/></svg>
              <span id="toggleManualLabel">Joindre un justificatif manuellement</span>
            </a>
          </label>
          <div id="manualFileZone" style="display: none; margin-top: 8px;">
            <input type="file" name="invoice_file" accept=".pdf,.jpg,.jpeg,.png" class="form-input-lg">
            <div class="form-hint">PDF, JPG ou PNG — max 10 Mo. Utile si le scan IA n'a pas fonctionné ou si vous voulez ajouter un justificatif en plus.</div>
          </div>
        </div>

        <?php if ($can_validate): ?>
          <div class="form-hint" style="margin-bottom: 12px;">ℹ️ En tant qu'<?= h(role_label($current['role'])) ?>, votre facture sera <strong>automatiquement validée</strong> et déduite du budget.</div>
        <?php else: ?>
          <div class="form-hint" style="margin-bottom: 12px;">ℹ️ Votre facture sera <strong>en attente de validation</strong> par un admin ou coordinateur avant d'impacter le budget.</div>
        <?php endif; ?>

        <div style="display: flex; justify-content: flex-end; gap: 10px;">
          <button type="submit" class="btn btn-primary">Ajouter la facture</button>
        </div>
      </form>
    </div>
    <?php endif; ?>

    <!-- Liste des factures -->
    <?php if (empty($invoices)): ?>
      <div class="panel"><div class="empty-state">Aucune facture pour ce projet. Ajoutez la première ci-dessus.</div></div>
    <?php else: ?>
      <div class="inv-list-project">
        <div class="inv-row-project inv-row-header-proj">
          <span>Facture</span>
          <span>Montant</span>
          <span>Statut</span>
          <span></span>
        </div>
        <?php foreach ($invoices as $inv):
          $st = [
            'validated' => ['label' => 'Validée', 'class' => 'status-validated'],
            'pending' => ['label' => 'En attente', 'class' => 'status-pending'],
            'rejected' => ['label' => 'Rejetée', 'class' => 'status-rejected'],
          ][$inv['status']] ?? ['label' => '—', 'class' => ''];
        ?>
        <div class="inv-row-project">
          <div class="inv-row-main">
            <div class="inv-row-supplier"><?= h($inv['supplier_name']) ?></div>
            <div class="inv-row-details">
              <?php if ($inv['category']): ?>
                <span><?= h($inv['category']) ?></span>
                <span class="dot">·</span>
              <?php endif; ?>
              <span><?= h(format_date_p($inv['invoice_date'])) ?></span>
              <?php if ($inv['description']): ?>
                <span class="dot">·</span>
                <span><?= h($inv['description']) ?></span>
              <?php endif; ?>
              <?php if ($inv['file_path']): ?>
                <span class="dot">·</span>
                <a href="/fichier-projet?type=invoice&amp;id=<?= (int)$inv['id'] ?>" target="_blank" rel="noopener" class="inv-file-link">
                  <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M13 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"/><polyline points="13 2 13 9 20 9"/></svg>
                  PDF
                </a>
              <?php endif; ?>
              <span class="dot">·</span>
              <span>par <?= h($inv['up_first'] . ' ' . $inv['up_last']) ?></span>
            </div>
          </div>
          <div class="inv-row-amount" style="min-width: 110px; text-align: right;">
            <div style="font-size: 14.5px; font-weight: 500; font-variant-numeric: tabular-nums; line-height: 1.2;"><?= h(format_budget($inv['amount_ttc'])) ?> <span style="font-size: 10px; color: var(--ink-4); font-weight: 400;">TTC</span></div>
            <?php if (!empty($inv['amount_ht']) && $inv['amount_ht'] != $inv['amount_ttc']): ?>
              <div style="font-size: 11.5px; color: var(--ink-3); font-variant-numeric: tabular-nums;"><?= h(format_budget($inv['amount_ht'])) ?> HT <span style="color: var(--ink-4);">· TVA <?= rtrim(rtrim(number_format((float)$inv['vat_rate'], 2, ',', ''), '0'), ',') ?> %</span></div>
            <?php elseif (!empty($inv['amount_ht']) && (float)$inv['vat_rate'] === 0.0): ?>
              <div style="font-size: 11.5px; color: var(--ink-4);">Sans TVA</div>
            <?php endif; ?>
          </div>
          <span class="inv-row-status inv-status <?= $st['class'] ?>"><?= h($st['label']) ?></span>
          <div class="inv-actions-inline">
            <?php if ($inv['status'] === 'pending' && $can_validate): ?>
              <form method="POST" action="/action-facture" style="margin:0;">
                <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="project_id" value="<?= $project_id ?>">
                <input type="hidden" name="invoice_id" value="<?= (int)$inv['id'] ?>">
                <input type="hidden" name="action" value="validate">
                <button type="submit" class="inv-btn-sm inv-btn-validate" title="Valider">
                  <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                  Valider
                </button>
              </form>
              <form method="POST" action="/action-facture" style="margin:0;">
                <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="project_id" value="<?= $project_id ?>">
                <input type="hidden" name="invoice_id" value="<?= (int)$inv['id'] ?>">
                <input type="hidden" name="action" value="reject">
                <button type="submit" class="inv-btn-sm inv-btn-reject" title="Rejeter">
                  <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                </button>
              </form>
            <?php endif; ?>
            <?php if ($is_admin): ?>
              <form method="POST" action="/action-facture" style="margin:0;" onsubmit="return confirm('Supprimer définitivement cette facture ?');">
                <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="project_id" value="<?= $project_id ?>">
                <input type="hidden" name="invoice_id" value="<?= (int)$inv['id'] ?>">
                <input type="hidden" name="action" value="delete">
                <button type="submit" class="inv-btn-sm inv-btn-delete" title="Supprimer">
                  <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-2 14a2 2 0 0 1-2 2H9a2 2 0 0 1-2-2L5 6"/></svg>
                </button>
              </form>
            <?php endif; ?>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

  <?php elseif ($active_tab === 'ia'): ?>
    <!-- ===== ASSISTANT IA ===== -->
    <?php if (!$ai_ready): ?>
      <div class="alert alert-error">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/></svg>
        <div>
          <strong>L'Assistant IA n'est pas encore configuré.</strong><br>
          L'administrateur doit ajouter la clé API Anthropic dans <code>config.php</code> (variable <code>ANTHROPIC_API_KEY</code>).<br>
          Obtenir une clé : <a href="https://console.anthropic.com" target="_blank" style="color: var(--acc);">console.anthropic.com</a>
        </div>
      </div>
    <?php endif; ?>

    <?php if (!empty($_GET['err'])): ?>
      <div class="alert alert-error">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/></svg>
        <?= h($_GET['err']) ?>
      </div>
    <?php endif; ?>

    <?php if (isset($_GET['generated'])): ?>
      <div class="alert alert-success">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
        Document généré avec succès ! Vous le retrouvez ci-dessous.
      </div>
    <?php endif; ?>

    <div class="ai-hero">
      <div class="ai-hero-icon">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
      </div>
      <div class="ai-hero-body">
        <h2 class="ai-hero-title">Votre copilote pour ce projet <span class="ai-hero-badge">Claude</span></h2>
        <p class="ai-hero-desc">L'IA connaît toutes les infos de <?= h($project['name']) ?> : ses étapes, les <?= count($messages) ?> messages de l'équipe, les <?= count($files) ?> fichiers et <?= count($invoices) ?> facture<?= count($invoices) > 1 ? 's' : '' ?>. Elle peut rédiger vos bilans avec le détail financier intégré.</p>
      </div>
    </div>

    <div class="section-head">
      <h2>Aide à la rédaction</h2>
      <div class="section-head-meta">Un clic pour générer un document</div>
    </div>

    <form method="POST" action="/action-ia" style="margin-bottom: 24px;">
      <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
      <input type="hidden" name="project_id" value="<?= $project_id ?>">
      <input type="hidden" name="mode" value="generate">
      <div class="ai-actions-grid">
        <button type="submit" name="doc_type" value="bilan_ag" class="ai-action-card" <?= !$ai_ready ? 'disabled' : '' ?>>
          <span class="ai-action-emoji"><?= ak_icon('chart',20) ?></span>
          <span class="ai-action-title">Bilan pour l'AG</span>
          <span class="ai-action-desc">Rapport pour l'Assemblée Générale</span>
        </button>
        <button type="submit" name="doc_type" value="email_parents" class="ai-action-card" <?= !$ai_ready ? 'disabled' : '' ?>>
          <span class="ai-action-emoji"><?= ak_icon('mail',20) ?></span>
          <span class="ai-action-title">Email aux parents</span>
          <span class="ai-action-desc">Message d'information aux familles</span>
        </button>
        <button type="submit" name="doc_type" value="rapport_subvention" class="ai-action-card" <?= !$ai_ready ? 'disabled' : '' ?>>
          <span class="ai-action-emoji"><?= ak_icon('clipboard',20) ?></span>
          <span class="ai-action-title">Rapport de subvention</span>
          <span class="ai-action-desc">Pour un financeur public</span>
        </button>
        <button type="submit" name="doc_type" value="fiche_com" class="ai-action-card" <?= !$ai_ready ? 'disabled' : '' ?>>
          <span class="ai-action-emoji"><?= ak_icon('megaphone',20) ?></span>
          <span class="ai-action-title">Fiche de com'</span>
          <span class="ai-action-desc">Pour vos réseaux sociaux</span>
        </button>
        <button type="submit" name="doc_type" value="synthese_etape" class="ai-action-card" <?= !$ai_ready ? 'disabled' : '' ?>>
          <span class="ai-action-emoji"><?= ak_icon('pin',20) ?></span>
          <span class="ai-action-title">Synthèse d'avancement</span>
          <span class="ai-action-desc">Point interne rapide</span>
        </button>
      </div>
    </form>

    <?php if (!empty($generated_docs)): ?>
    <div class="section-head">
      <h2>Documents générés</h2>
      <div class="section-head-meta"><?= count($generated_docs) ?> document<?= count($generated_docs) > 1 ? 's' : '' ?></div>
    </div>
    <div style="margin-bottom: 28px;">
      <?php foreach (array_slice($generated_docs, 0, 5) as $d):
        $preview = mb_substr(strip_tags(preg_replace('/\n+/', ' ', $d['content'])), 0, 220);
      ?>
      <div class="gen-doc">
        <div class="gen-doc-head">
          <div>
            <h3 class="gen-doc-title"><?= h($d['title']) ?></h3>
            <div class="gen-doc-meta">Par <?= h($d['first_name'] . ' ' . $d['last_name']) ?> · <?= h(format_date_p($d['created_at'])) ?></div>
          </div>
          <span class="gen-doc-tag">IA</span>
        </div>
        <div class="gen-doc-preview"><?= h($preview) ?>…</div>
        <div class="gen-doc-actions">
          <a href="#" onclick="showDoc(<?= (int)$d['id'] ?>); return false;">Voir en entier</a>
          <a href="#" onclick="copyDoc(<?= (int)$d['id'] ?>); return false;">Copier</a>
          <a href="/download-bilan.php?id=<?= (int)$d['id'] ?>" target="_blank" style="color:#6366F1; font-weight:600;">📄 Télécharger PDF</a>
        </div>
        <div id="doc-full-<?= (int)$d['id'] ?>" style="display:none; margin-top: 14px; padding-top: 14px; border-top: 1px solid var(--border);">
          <div class="ai-msg-content"><?= ai_markdown_to_html($d['content']) ?></div>
        </div>
        <textarea id="doc-raw-<?= (int)$d['id'] ?>" style="position:absolute;left:-9999px;"><?= h($d['content']) ?></textarea>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <div class="section-head">
      <h2>Parler avec l'IA</h2>
      <div class="section-head-meta">Posez-lui des questions sur ce projet</div>
    </div>

    <div class="ai-chat-wrap">
      <div class="ai-chat-head">
        <div class="ai-chat-head-left">
          <span class="ai-chat-dot"></span>
          <span>Assistant IA</span>
        </div>
        <span class="ai-chat-model">Propulsé par Claude</span>
      </div>

      <div class="ai-chat-list" id="aiChatList">
        <?php if (empty($active_conv_messages)): ?>
          <div class="empty-state" style="padding: 20px;">
            Posez votre première question sur ce projet.<br>
            L'IA connaît toutes les infos : étapes, messages, fichiers.
          </div>
        <?php else: foreach ($active_conv_messages as $msg): ?>
          <div class="ai-msg <?= h($msg['role']) ?>">
            <div class="ai-msg-avatar">
              <?= $msg['role'] === 'user' ? h(user_initials($current['first_name'], $current['last_name'])) : 'IA' ?>
            </div>
            <div class="ai-msg-content">
              <?= $msg['role'] === 'assistant' ? ai_markdown_to_html($msg['content']) : nl2br(h($msg['content'])) ?>
            </div>
          </div>
        <?php endforeach; endif; ?>
      </div>

      <?php if (empty($active_conv_messages)): ?>
      <div class="ai-suggestions">
        <button type="button" class="ai-suggestion" onclick="fillAiInput('Quel est l&apos;état actuel de ce projet ?')">Où en est le projet ?</button>
        <button type="button" class="ai-suggestion" onclick="fillAiInput('Quels sont les points de vigilance à suivre ?')">Points de vigilance ?</button>
        <button type="button" class="ai-suggestion" onclick="fillAiInput('Propose-moi 3 idées pour accélérer ce projet.')">3 idées pour accélérer</button>
        <button type="button" class="ai-suggestion" onclick="fillAiInput('Fais-moi un résumé court pour le président.')">Résumé président</button>
      </div>
      <?php endif; ?>

      <form method="POST" action="/action-ia" class="ai-input-wrap">
        <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
        <input type="hidden" name="project_id" value="<?= $project_id ?>">
        <input type="hidden" name="mode" value="chat">
        <input type="hidden" name="conversation_id" value="<?= $active_conv_id ?>">
        <textarea name="message" class="ai-input" id="aiInput" placeholder="Posez une question à l'IA…" required maxlength="4000" rows="1" <?= !$ai_ready ? 'disabled' : '' ?>></textarea>
        <button type="submit" class="ai-send" <?= !$ai_ready ? 'disabled' : '' ?>>
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
          Envoyer
        </button>
      </form>
    </div>
    <p class="ai-disclaimer">L'IA peut faire des erreurs. Vérifiez les informations importantes. Ses réponses ne remplacent pas un avis professionnel.</p>

  <?php elseif ($active_tab === 'historique' && $is_admin): ?>
    <!-- ===== HISTORIQUE DU PROJET (admin uniquement) ===== -->
    <?php
    // Filtres
    $filter_action = $_GET['type'] ?? 'all';
    $filter_user = (int)($_GET['user'] ?? 0);
    $filter_since = $_GET['since'] ?? 'all';

    // Construction de la requête
    $where_parts = ['pal.project_id = ?'];
    $history_params = [$project_id];

    if ($filter_action !== 'all') {
        // On regroupe par catégorie
        $action_filters = [
            'project' => ['project_created', 'project_updated'],
            'status' => ['status_changed'],
            'budget' => ['budget_changed'],
            'team' => ['referent_changed', 'follower_added', 'follower_removed'],
            'steps' => ['step_added', 'step_updated', 'step_deleted'],
            'invoices' => ['invoice_added', 'invoice_updated', 'invoice_deleted'],
            'messages' => ['message_deleted'],
        ];
        if (isset($action_filters[$filter_action])) {
            $types = $action_filters[$filter_action];
            $placeholders = implode(',', array_fill(0, count($types), '?'));
            $where_parts[] = 'pal.action_type IN (' . $placeholders . ')';
            $history_params = array_merge($history_params, $types);
        }
    }

    if ($filter_user > 0) {
        $where_parts[] = 'pal.user_id = ?';
        $history_params[] = $filter_user;
    }

    if ($filter_since !== 'all') {
        $intervals = [
            '24h' => '1 DAY',
            '7d' => '7 DAY',
            '30d' => '30 DAY',
            '90d' => '90 DAY',
        ];
        if (isset($intervals[$filter_since])) {
            $where_parts[] = "pal.created_at >= DATE_SUB(NOW(), INTERVAL " . $intervals[$filter_since] . ")";
        }
    }

    $where_clause = implode(' AND ', $where_parts);

    $history_stmt = $pdo->prepare("
        SELECT pal.*,
               u.first_name, u.last_name, u.avatar_color, u.role AS user_role
        FROM project_activity_log pal
        JOIN users u ON pal.user_id = u.id
        WHERE $where_clause
        ORDER BY pal.created_at DESC
        LIMIT 500
    ");
    $history_stmt->execute($history_params);
    $activities = $history_stmt->fetchAll();

    // Liste des utilisateurs qui ont laissé des traces (pour le filtre)
    $users_filter_stmt = $pdo->prepare("
        SELECT DISTINCT u.id, u.first_name, u.last_name
        FROM project_activity_log pal
        JOIN users u ON pal.user_id = u.id
        WHERE pal.project_id = ?
        ORDER BY u.first_name
    ");
    $users_filter_stmt->execute([$project_id]);
    $users_who_acted = $users_filter_stmt->fetchAll();

    // Stats
    $total_stmt = $pdo->prepare("SELECT COUNT(*) FROM project_activity_log WHERE project_id = ?");
    $total_stmt->execute([$project_id]);
    $total_activities = (int)$total_stmt->fetchColumn();

    $avatar_colors_hex = [
        'blue' => '#4F80BD', 'purple' => '#7F77DD', 'amber' => '#EF9F27',
        'pink' => '#D77CA0', 'teal' => '#2AAE89', 'green' => '#059669',
        'red' => '#B91C1C', 'gray' => '#78716C'
    ];

    // Helpers d'affichage
    function action_icon($type) {
        $icons = [
            'project_created' => '✨',
            'project_updated' => '✏️',
            'status_changed' => '🔄',
            'budget_changed' => '💰',
            'referent_changed' => '👤',
            'follower_added' => '👁️',
            'follower_removed' => '🚪',
            'step_added' => '➕',
            'step_updated' => '📝',
            'step_deleted' => '🗑️',
            'invoice_added' => '🧾',
            'invoice_updated' => '📋',
            'invoice_deleted' => '🗑️',
            'message_deleted' => '💬',
        ];
        return $icons[$type] ?? '•';
    }
    function action_category($type) {
        $cats = [
            'project_created' => 'project',
            'project_updated' => 'project',
            'status_changed' => 'status',
            'budget_changed' => 'budget',
            'referent_changed' => 'team',
            'follower_added' => 'team',
            'follower_removed' => 'team',
            'step_added' => 'steps',
            'step_updated' => 'steps',
            'step_deleted' => 'steps',
            'invoice_added' => 'invoices',
            'invoice_updated' => 'invoices',
            'invoice_deleted' => 'invoices',
            'message_deleted' => 'messages',
        ];
        return $cats[$type] ?? 'other';
    }
    ?>

    <div class="history-wrapper">

      <!-- Header historique -->
      <div class="history-head">
        <div>
          <h2 class="history-title">📜 Historique du projet</h2>
          <p class="history-sub">
            <?= $total_activities ?> action<?= $total_activities > 1 ? 's' : '' ?> enregistrée<?= $total_activities > 1 ? 's' : '' ?> depuis la création.
            <span style="color: var(--ink-4);">· Visible par les administrateurs uniquement.</span>
          </p>
        </div>
      </div>

      <!-- Filtres -->
      <form method="GET" action="/projet/<?= $project_id ?>/historique" class="history-filters">
        <input type="hidden" name="tab" value="historique">

        <select name="type" onchange="this.form.submit()" class="history-filter-select">
          <option value="all" <?= $filter_action === 'all' ? 'selected' : '' ?>>Toutes les actions</option>
          <option value="project" <?= $filter_action === 'project' ? 'selected' : '' ?>>✏️ Modifications du projet</option>
          <option value="status" <?= $filter_action === 'status' ? 'selected' : '' ?>>🔄 Changements de statut</option>
          <option value="budget" <?= $filter_action === 'budget' ? 'selected' : '' ?>>💰 Budget</option>
          <option value="team" <?= $filter_action === 'team' ? 'selected' : '' ?>>👥 Équipe et suivi</option>
          <option value="steps" <?= $filter_action === 'steps' ? 'selected' : '' ?>>📝 Étapes</option>
          <option value="invoices" <?= $filter_action === 'invoices' ? 'selected' : '' ?>>🧾 Factures</option>
          <option value="messages" <?= $filter_action === 'messages' ? 'selected' : '' ?>>💬 Messages</option>
        </select>

        <?php if (count($users_who_acted) > 1): ?>
        <select name="user" onchange="this.form.submit()" class="history-filter-select">
          <option value="0" <?= $filter_user === 0 ? 'selected' : '' ?>>Tous les utilisateurs</option>
          <?php foreach ($users_who_acted as $u): ?>
            <option value="<?= (int)$u['id'] ?>" <?= $filter_user === (int)$u['id'] ? 'selected' : '' ?>>
              <?= h($u['first_name'] . ' ' . $u['last_name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
        <?php endif; ?>

        <select name="since" onchange="this.form.submit()" class="history-filter-select">
          <option value="all" <?= $filter_since === 'all' ? 'selected' : '' ?>>Toute l'historique</option>
          <option value="24h" <?= $filter_since === '24h' ? 'selected' : '' ?>>Dernières 24h</option>
          <option value="7d" <?= $filter_since === '7d' ? 'selected' : '' ?>>7 derniers jours</option>
          <option value="30d" <?= $filter_since === '30d' ? 'selected' : '' ?>>30 derniers jours</option>
          <option value="90d" <?= $filter_since === '90d' ? 'selected' : '' ?>>90 derniers jours</option>
        </select>

        <?php if ($filter_action !== 'all' || $filter_user > 0 || $filter_since !== 'all'): ?>
          <a href="/projet/<?= $project_id ?>/historique" class="history-filter-reset">Réinitialiser</a>
        <?php endif; ?>
      </form>

      <!-- Timeline -->
      <?php if (empty($activities)): ?>
        <div class="history-empty">
          <div style="color:#64748B; margin-bottom: 10px;"><?= ak_icon('inbox',44,'1.5') ?></div>
          <div style="font-size: 15px; color: var(--ink-2); margin-bottom: 4px;">Aucune activité à afficher</div>
          <div style="font-size: 13px; color: var(--ink-3);">Essayez d'ajuster les filtres ou reviens plus tard.</div>
        </div>
      <?php else:
        $last_date = null;
        foreach ($activities as $act):
          $act_date = date('Y-m-d', strtotime($act['created_at']));
          $show_separator = ($act_date !== $last_date);
          $last_date = $act_date;

          $cat = action_category($act['action_type']);
          $avatar_hex = $avatar_colors_hex[$act['avatar_color'] ?? 'gray'] ?? '#78716C';

          // Format date lisible
          $ts = strtotime($act['created_at']);
          $today = strtotime(date('Y-m-d'));
          $yesterday = strtotime('-1 day', $today);
          $act_day_ts = strtotime($act_date);

          if ($act_day_ts === $today) $day_label = "Aujourd'hui";
          elseif ($act_day_ts === $yesterday) $day_label = "Hier";
          else {
            $days_fr = ['Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi', 'Dimanche'];
            $months_fr = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
            $day_label = $days_fr[(int)date('N', $ts) - 1] . ' ' . (int)date('j', $ts) . ' ' . $months_fr[(int)date('n', $ts) - 1] . ' ' . date('Y', $ts);
          }
      ?>
        <?php if ($show_separator): ?>
          <div class="history-day-separator"><?= h($day_label) ?></div>
        <?php endif; ?>

        <div class="history-item history-cat-<?= $cat ?>">
          <div class="history-time"><?= h(date('H:i:s', $ts)) ?></div>

          <div class="history-avatar" style="background: <?= $avatar_hex ?>;" title="<?= h($act['first_name'] . ' ' . $act['last_name']) ?>">
            <?= h(strtoupper(mb_substr($act['first_name'], 0, 1) . mb_substr($act['last_name'], 0, 1))) ?>
          </div>

          <div class="history-icon"><?= action_icon($act['action_type']) ?></div>

          <div class="history-body">
            <div class="history-text">
              <strong><?= h($act['first_name'] . ' ' . $act['last_name']) ?></strong>
              <?= h($act['action_label']) ?>
            </div>
            <?php if (!empty($act['changes'])):
              $changes_data = json_decode($act['changes'], true);
              if (is_array($changes_data) && !empty($changes_data['field'])): ?>
                <div class="history-detail">
                  <span class="history-detail-field"><?= h($changes_data['field']) ?></span>
                </div>
            <?php endif; endif; ?>
          </div>

          <div class="history-fulldate" title="<?= h($act['created_at']) ?>">
            <?= h(date('d/m/Y H:i:s', $ts)) ?>
          </div>
        </div>
      <?php endforeach; endif; ?>

      <?php if (count($activities) >= 500): ?>
        <div style="padding: 14px; text-align: center; color: var(--ink-3); font-size: 12.5px;">
          Seules les 500 actions les plus récentes sont affichées.
        </div>
      <?php endif; ?>

    </div>

    <style>
    .history-wrapper { max-width: 1000px; }
    .history-head { margin-bottom: 20px; }
    .history-title { font-size: 18px; font-weight: 500; letter-spacing: -0.01em; margin-bottom: 4px; }
    .history-sub { font-size: 13px; color: var(--ink-3); line-height: 1.5; }

    .history-filters { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; margin-bottom: 20px; padding: 12px; background: var(--bg-2); border: 1px solid var(--border); border-radius: 10px; }
    .history-filter-select { padding: 7px 12px; border: 1px solid var(--border); border-radius: 8px; background: var(--bg); font-size: 12.5px; color: var(--ink-2); cursor: pointer; font-family: inherit; }
    .history-filter-reset { font-size: 11.5px; color: var(--ink-3); text-decoration: none; padding: 7px 10px; border-radius: 6px; }
    .history-filter-reset:hover { background: var(--bg); color: var(--ink); }

    .history-day-separator { font-size: 11px; color: var(--ink-3); font-weight: 600; letter-spacing: 0.06em; text-transform: uppercase; padding: 20px 0 10px; border-bottom: 1px solid var(--border); margin-bottom: 10px; }

    .history-item { display: grid; grid-template-columns: 60px 32px 28px 1fr auto; gap: 12px; align-items: center; padding: 10px 12px; border: 1px solid var(--border); border-radius: 10px; margin-bottom: 6px; background: var(--bg); transition: border-color 0.12s ease; }
    .history-item:hover { border-color: var(--border-strong); }
    .history-item.history-cat-status { border-left: 3px solid #4F80BD; }
    .history-item.history-cat-budget { border-left: 3px solid #EF9F27; }
    .history-item.history-cat-team { border-left: 3px solid #7F77DD; }
    .history-item.history-cat-steps { border-left: 3px solid #2AAE89; }
    .history-item.history-cat-invoices { border-left: 3px solid #059669; }
    .history-item.history-cat-messages { border-left: 3px solid #D77CA0; }
    .history-item.history-cat-project { border-left: 3px solid #78716C; }

    .history-time { font-size: 11.5px; color: var(--ink-3); font-variant-numeric: tabular-nums; font-weight: 500; }
    .history-avatar { width: 32px; height: 32px; border-radius: 50%; color: #fff; display: flex; align-items: center; justify-content: center; font-size: 11px; font-weight: 600; flex-shrink: 0; }
    .history-icon { font-size: 16px; text-align: center; }
    .history-body { min-width: 0; }
    .history-text { font-size: 13px; color: var(--ink); line-height: 1.5; }
    .history-text strong { font-weight: 500; }
    .history-detail { font-size: 11px; color: var(--ink-4); margin-top: 3px; }
    .history-detail-field { padding: 1px 6px; background: var(--bg-2); border-radius: 4px; font-family: monospace; }
    .history-fulldate { font-size: 11px; color: var(--ink-4); font-variant-numeric: tabular-nums; white-space: nowrap; }

    .history-empty { padding: 60px 20px; text-align: center; }

    @media (max-width: 720px) {
      .history-item { grid-template-columns: 32px 28px 1fr; gap: 10px; }
      .history-time, .history-fulldate { grid-column: 1 / -1; font-size: 10.5px; color: var(--ink-3); }
      .history-item .history-time { display: none; }
    }
    </style>

  <?php endif; ?>

</main>

<script>
(function () {
  var chat = document.getElementById('chatList');
  if (chat) chat.scrollTop = chat.scrollHeight;
  var aiChat = document.getElementById('aiChatList');
  if (aiChat) aiChat.scrollTop = aiChat.scrollHeight;
})();

function autoExpand(el) {
  el.style.height = 'auto';
  el.style.height = Math.min(el.scrollHeight, 160) + 'px';
}
var chatInput = document.getElementById('chatInput');
if (chatInput) chatInput.addEventListener('input', function () { autoExpand(this); });
var aiInput = document.getElementById('aiInput');
if (aiInput) aiInput.addEventListener('input', function () { autoExpand(this); });

[chatInput, aiInput].filter(Boolean).forEach(function (el) {
  el.addEventListener('keydown', function (e) {
    if (e.key === 'Enter' && !e.shiftKey) {
      // Ne pas soumettre si dropdown @mention est ouvert (laisse le dropdown gérer)
      var dropdown = document.getElementById('mentionDropdown');
      if (dropdown && dropdown.style.display === 'block' && this.id === 'chatInput') {
        return; // Le handler du dropdown gère
      }
      e.preventDefault();
      this.form.submit();
    }
  });
});

// ============================================================
// AUTOCOMPLETE @MENTIONS DANS LES MESSAGES PROJET
// ============================================================
(function() {
    var input = document.getElementById('chatInput');
    var dropdown = document.getElementById('mentionDropdown');
    var listEl = document.getElementById('mentionList');
    var dataEl = document.getElementById('mentionDataJson');
    if (!input || !dropdown || !listEl || !dataEl) return;
    
    var members = [];
    try {
        members = JSON.parse(dataEl.textContent || '[]');
    } catch (e) { 
        return; 
    }
    if (!members.length) return;
    
    var COLORS = {
        'blue': '#3B82F6', 'purple': '#8B5CF6', 'amber': '#F59E0B',
        'pink': '#EC4899', 'teal': '#14B8A6', 'green': '#10B981',
        'red': '#EF4444', 'indigo': '#6366F1'
    };
    var ROLE_LABELS = {
        'admin': '🛡️ Admin',
        'coordinator': '🧭 Coord',
        'referent': '🎯 Référent',
        'member': '👤 Membre',
        'follower': '👀 Suiveur'
    };
    
    var currentSearch = '';
    var currentMatchStart = -1;
    var selectedIdx = 0;
    var filtered = [];
    
    function detectMentionContext() {
        var pos = input.selectionStart;
        var text = input.value;
        var beforeCursor = text.substring(0, pos);
        // Cherche le dernier @ avant le curseur, qui n'est PAS précédé par un caractère alphanumérique
        var match = beforeCursor.match(/(?:^|\s)@([a-zA-ZÀ-ÿ\.\-]*)$/u);
        if (match) {
            return {
                search: match[1],
                start: pos - match[1].length - 1, // position du @
            };
        }
        return null;
    }
    
    function renderDropdown() {
        if (!filtered.length) {
            dropdown.style.display = 'none';
            return;
        }
        var html = '';
        filtered.forEach(function(m, idx) {
            var color = COLORS[m.color] || '#3B82F6';
            var isActive = idx === selectedIdx;
            html += '<div class="mention-item" data-idx="' + idx + '" style="display:flex; align-items:center; gap:10px; padding:9px 12px; cursor:pointer; ' +
                'background:' + (isActive ? '#F3F4F6' : 'transparent') + ';' +
                'border-left:3px solid ' + (isActive ? color : 'transparent') + ';' +
                '">' +
                '<span style="width:30px; height:30px; border-radius:50%; background:' + color + '; color:#fff; display:inline-flex; align-items:center; justify-content:center; font-size:11px; font-weight:600; flex-shrink:0;">' + 
                (m.initials || '?') + '</span>' +
                '<div style="flex:1; min-width:0;">' +
                '<div style="font-size:13px; font-weight:500; color:#111827; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">' +
                escapeHtml(m.name) + '</div>' +
                '<div style="font-size:11px; color:#6B7280; margin-top:1px;">@' + escapeHtml(m.tag) + ' · ' + (ROLE_LABELS[m.role] || m.role) + '</div>' +
                '</div>' +
                '</div>';
        });
        listEl.innerHTML = html;
        dropdown.style.display = 'block';
        positionDropdown();
        
        // Mousedown au lieu de click pour éviter blur du textarea
        listEl.querySelectorAll('.mention-item').forEach(function(el) {
            el.addEventListener('mouseenter', function() {
                selectedIdx = parseInt(this.dataset.idx);
                renderDropdown();
            });
            el.addEventListener('mousedown', function(e) {
                e.preventDefault();
                e.stopPropagation();
                selectedIdx = parseInt(this.dataset.idx);
                insertMention();
            });
        });
    }
    
    function positionDropdown() {
        // Position du dropdown : au-dessus du textarea, aligné à gauche
        var rect = input.getBoundingClientRect();
        dropdown.style.left = rect.left + 'px';
        dropdown.style.bottom = (window.innerHeight - rect.top + 6) + 'px';
        dropdown.style.maxWidth = Math.min(rect.width, 360) + 'px';
        dropdown.style.minWidth = Math.min(rect.width, 280) + 'px';
    }
    
    function escapeHtml(s) {
        var div = document.createElement('div');
        div.textContent = s;
        return div.innerHTML;
    }
    
    function filterMembers(search) {
        var s = (search || '').toLowerCase();
        if (!s) return members.slice(0, 8);
        return members.filter(function(m) {
            return m.tag.toLowerCase().indexOf(s) === 0 
                || m.name.toLowerCase().indexOf(s) >= 0;
        }).slice(0, 8);
    }
    
    function updateAutocomplete() {
        var ctx = detectMentionContext();
        if (!ctx) {
            dropdown.style.display = 'none';
            currentMatchStart = -1;
            return;
        }
        currentSearch = ctx.search;
        currentMatchStart = ctx.start;
        filtered = filterMembers(currentSearch);
        selectedIdx = 0;
        renderDropdown();
    }
    
    function insertMention() {
        if (!filtered.length || currentMatchStart < 0) return;
        var member = filtered[selectedIdx];
        if (!member) return;
        
        var text = input.value;
        var pos = input.selectionStart;
        // Remplacer @search par @prenom (espace après pour faciliter la suite)
        var before = text.substring(0, currentMatchStart);
        var after = text.substring(pos);
        var insertion = '@' + member.tag + ' ';
        input.value = before + insertion + after;
        var newPos = currentMatchStart + insertion.length;
        
        dropdown.style.display = 'none';
        currentMatchStart = -1;
        
        // Replacer le focus + curseur après un petit délai
        setTimeout(function() {
            input.focus();
            input.setSelectionRange(newPos, newPos);
            autoExpand(input);
        }, 10);
    }
    
    // Listeners
    input.addEventListener('input', updateAutocomplete);
    input.addEventListener('click', updateAutocomplete);
    input.addEventListener('keyup', function(e) {
        if (['ArrowDown', 'ArrowUp', 'Enter', 'Escape', 'Tab'].indexOf(e.key) >= 0) return;
        updateAutocomplete();
    });
    
    input.addEventListener('keydown', function(e) {
        if (dropdown.style.display !== 'block') return;
        
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            selectedIdx = (selectedIdx + 1) % filtered.length;
            renderDropdown();
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            selectedIdx = (selectedIdx - 1 + filtered.length) % filtered.length;
            renderDropdown();
        } else if (e.key === 'Enter' || e.key === 'Tab') {
            if (filtered.length > 0) {
                e.preventDefault();
                e.stopPropagation();
                insertMention();
            }
        } else if (e.key === 'Escape') {
            e.preventDefault();
            dropdown.style.display = 'none';
            currentMatchStart = -1;
        }
    }, true);
    
    // Repositionner au scroll/resize
    window.addEventListener('scroll', function() {
        if (dropdown.style.display === 'block') positionDropdown();
    }, true);
    window.addEventListener('resize', function() {
        if (dropdown.style.display === 'block') positionDropdown();
    });
    
    // Fermer si on clique en dehors (mousedown au lieu de click pour intercepter avant)
    document.addEventListener('mousedown', function(e) {
        if (!dropdown.contains(e.target) && e.target !== input) {
            dropdown.style.display = 'none';
        }
    });
})();

// ============================================================
// TEMPS RÉEL — Polling AJAX pour nouveaux messages
// ============================================================
(function() {
    var chatWrap = document.querySelector('.chat-wrap[data-project-id]');
    var chatList = document.getElementById('chatList');
    var chatForm = document.querySelector('.chat-form');
    var chatInput = document.getElementById('chatInput');
    var statusText = document.getElementById('statusText');
    var statusDot = document.querySelector('#chatStatus .status-dot');
    var emptyEl = document.getElementById('chatEmpty');
    
    if (!chatWrap || !chatList || !chatForm || !chatInput) return;
    
    var projectId = parseInt(chatWrap.dataset.projectId);
    if (!projectId) return;
    
    // Trouver le dernier ID de message actuellement affiché
    var lastId = 0;
    chatList.querySelectorAll('.chat-msg[data-msg-id]').forEach(function(el) {
        var id = parseInt(el.dataset.msgId);
        if (id > lastId) lastId = id;
    });
    
    var pollInterval = null;
    var pollDelay = 5000; // 5 secondes
    var POLL_URL = '/api-messages.php';
    
    var COLORS = ['blue','purple','amber','pink','teal','green','red','indigo'];
    
    // Couleurs pastel pour avatars (cohérent avec le CSS .av-* existant)
    function colorClass(color) {
        return COLORS.indexOf(color) >= 0 ? 'av-' + color : 'av-blue';
    }
    
    // Échappement HTML
    function esc(s) {
        if (s === null || s === undefined) return '';
        return String(s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }
    
    // Surligner les @mentions (même regex que le PHP)
    function highlightMentions(content) {
        var escaped = esc(content);
        return escaped.replace(/(@[a-zA-ZÀ-ÿ][a-zA-ZÀ-ÿ\.\-]+)/gu, function(m) {
            return '<span style="background:#DBEAFE; color:#1E40AF; padding:1px 6px; border-radius:4px; font-weight:500;">' + m + '</span>';
        }).replace(/\n/g, '<br>');
    }
    
    function appendMessage(msg, isAnimated) {
        // Supprimer le placeholder "Aucun message" si présent
        if (emptyEl && emptyEl.parentNode) {
            emptyEl.parentNode.removeChild(emptyEl);
            emptyEl = null;
        }
        
        // Ne pas afficher 2x le même message (sécurité)
        if (chatList.querySelector('.chat-msg[data-msg-id="' + msg.id + '"]')) return;
        
        var div = document.createElement('div');
        div.className = 'chat-msg' + (isAnimated ? ' chat-msg-new' : '');
        div.setAttribute('data-msg-id', msg.id);
        
        var authorLabel = esc(msg.first_name + ' ' + msg.last_name) + (msg.is_self ? ' (vous)' : '');
        
        div.innerHTML = 
            '<span class="chat-avatar ' + colorClass(msg.avatar_color) + '">' + esc(msg.initials || '?') + '</span>' +
            '<div class="chat-bubble">' +
                '<div class="chat-head-line">' +
                    '<span class="chat-author">' + authorLabel + '</span>' +
                    '<span class="chat-time">' + esc(msg.time_label) + '</span>' +
                '</div>' +
                '<div class="chat-content">' + highlightMentions(msg.content) + '</div>' +
            '</div>';
        
        chatList.appendChild(div);
        
        // Mettre à jour le lastId
        if (msg.id > lastId) lastId = msg.id;
        
        // Scroll vers le bas
        scrollChatBottom();
    }
    
    function scrollChatBottom() {
        // Le scroll dépend de ton CSS, on essaie plusieurs stratégies
        chatList.scrollTop = chatList.scrollHeight;
        // Si la zone scroll est ailleurs (sur window)
        var rect = chatList.getBoundingClientRect();
        if (rect.bottom > window.innerHeight) {
            chatList.lastElementChild?.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }
    }
    
    function setStatus(label, color) {
        if (statusText) statusText.textContent = label;
        if (statusDot && color) statusDot.style.background = color;
    }
    
    var consecutiveErrors = 0;
    
    function poll() {
        // Pause si la page est cachée (économise les requêtes)
        if (document.hidden) return;
        
        fetch(POLL_URL + '?project_id=' + projectId + '&since=' + lastId, {
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(function(r) { 
            if (!r.ok) throw new Error('HTTP ' + r.status); 
            return r.json(); 
        })
        .then(function(data) {
            consecutiveErrors = 0;
            if (data.ok && Array.isArray(data.messages) && data.messages.length > 0) {
                data.messages.forEach(function(m) { appendMessage(m, true); });
                
                // Notification visuelle si on est pas focus sur l'input
                if (document.activeElement !== chatInput) {
                    setStatus('💬 ' + data.messages.length + ' nouveau' + (data.messages.length > 1 ? 'x' : '') + ' message' + (data.messages.length > 1 ? 's' : ''), '#3B82F6');
                    setTimeout(function() {
                        setStatus('Synchronisation active · vérifie toutes les 5 sec', '#10B981');
                    }, 3000);
                }
            }
        })
        .catch(function(err) {
            consecutiveErrors++;
            if (consecutiveErrors >= 3) {
                setStatus('⚠️ Connexion interrompue', '#EF4444');
            }
        });
    }
    
    // Démarrer le polling au chargement
    function startPolling() {
        if (pollInterval) clearInterval(pollInterval);
        pollInterval = setInterval(poll, pollDelay);
    }
    
    function stopPolling() {
        if (pollInterval) {
            clearInterval(pollInterval);
            pollInterval = null;
        }
    }
    
    // Gestion focus/blur de la page
    document.addEventListener('visibilitychange', function() {
        if (document.hidden) {
            stopPolling();
            setStatus('⏸️ En pause (onglet inactif)', '#94A3B8');
        } else {
            // Quand on revient sur l'onglet, poll immédiat puis reprend
            poll();
            startPolling();
            setStatus('Synchronisation active · vérifie toutes les 5 sec', '#10B981');
        }
    });
    
    // ============================================================
    // ENVOI AJAX du message (pas de redirect)
    // ============================================================
    chatForm.addEventListener('submit', function(e) {
        e.preventDefault();
        
        var content = (chatInput.value || '').trim();
        if (!content) return;
        
        var sendBtn = chatForm.querySelector('.chat-send');
        if (sendBtn) sendBtn.disabled = true;
        chatInput.disabled = true;
        
        var formData = new FormData(chatForm);
        
        fetch('/action-message', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: formData
        })
        .then(function(r) { 
            if (!r.ok) throw new Error('HTTP ' + r.status); 
            return r.json(); 
        })
        .then(function(data) {
            if (data.ok && data.message) {
                // Afficher immédiatement le message
                appendMessage(data.message, true);
                chatInput.value = '';
                chatInput.style.height = 'auto';
                
                if (data.mentions_sent > 0) {
                    setStatus('✉️ ' + data.mentions_sent + ' mention' + (data.mentions_sent > 1 ? 's' : '') + ' notifiée' + (data.mentions_sent > 1 ? 's' : '') + ' par email', '#8B5CF6');
                    setTimeout(function() {
                        setStatus('Synchronisation active · vérifie toutes les 5 sec', '#10B981');
                    }, 3000);
                }
            } else {
                alert('Erreur lors de l\'envoi : ' + (data.error || 'inconnue'));
            }
        })
        .catch(function(err) {
            // Fallback : laisser le formulaire submit normalement
            alert('Erreur réseau, le message va être envoyé en mode classique.');
            chatForm.submit();
        })
        .finally(function() {
            if (sendBtn) sendBtn.disabled = false;
            chatInput.disabled = false;
            chatInput.focus();
        });
    });
    
    // Lancer le polling au chargement
    startPolling();
    // Premier scroll vers le bas
    scrollChatBottom();
})();

function fillAiInput(text) {
  var el = document.getElementById('aiInput');
  if (!el) return;
  el.value = text;
  el.focus();
  autoExpand(el);
}

function showDoc(id) {
  var el = document.getElementById('doc-full-' + id);
  if (el) el.style.display = el.style.display === 'none' ? 'block' : 'none';
}

function copyDoc(id) {
  var ta = document.getElementById('doc-raw-' + id);
  if (!ta) return;
  ta.select();
  try {
    document.execCommand('copy');
    alert('Document copié dans le presse-papiers !');
  } catch (e) {
    alert('Impossible de copier automatiquement. Sélectionnez le texte manuellement.');
  }
}

// ===== Gestion des modes de saisie TVA pour les factures =====
(function () {
  var chips = document.querySelectorAll('.amount-mode-chip');
  if (chips.length === 0) return;

  var modeTtc = document.getElementById('modeTtc');
  var modeHt = document.getElementById('modeHt');
  var modeNoVat = document.getElementById('modeNoVat');
  var inputTtc = document.getElementById('inputTtc');
  var inputHt = document.getElementById('inputHt');
  var inputNoVat = document.getElementById('inputNoVat');
  var vatRateTtc = document.getElementById('vatRateTtc');
  var vatRateHt = document.getElementById('vatRateHt');
  var hintHtComputed = document.getElementById('hintHtComputed');
  var hintTtcComputed = document.getElementById('hintTtcComputed');

  function parseFr(v) {
    if (!v) return 0;
    return parseFloat(String(v).replace(/\s/g, '').replace(',', '.')) || 0;
  }
  function formatEur(v) {
    return v.toFixed(2).replace('.', ',') + ' €';
  }

  function switchMode(mode) {
    chips.forEach(function (c) { c.classList.toggle('active', c.dataset.mode === mode); });
    if (modeTtc) modeTtc.style.display = mode === 'ttc' ? '' : 'none';
    if (modeHt) modeHt.style.display = mode === 'ht' ? '' : 'none';
    if (modeNoVat) modeNoVat.style.display = mode === 'no_vat' ? '' : 'none';
    // Coche le bon radio
    var radio = document.querySelector('input[name="amount_mode"][value="' + mode + '"]');
    if (radio) radio.checked = true;
    // Active/désactive les champs pour éviter les doublons
    if (inputTtc) inputTtc.disabled = (mode !== 'ttc' && mode !== 'no_vat');
    if (inputHt) inputHt.disabled = (mode !== 'ht');
    if (inputNoVat) inputNoVat.disabled = (mode !== 'no_vat');
    // En mode no_vat, inputNoVat et inputTtc ont le même name "amount_ttc" — on doit renommer
    if (inputTtc) inputTtc.name = (mode === 'ttc') ? 'amount_ttc' : '';
    if (inputNoVat) inputNoVat.name = (mode === 'no_vat') ? 'amount_ttc' : '';
    recalc();
  }

  function recalc() {
    var mode = document.querySelector('input[name="amount_mode"]:checked');
    if (!mode) return;
    mode = mode.value;

    if (mode === 'ttc' && inputTtc && vatRateTtc && hintHtComputed) {
      var ttc = parseFr(inputTtc.value);
      var rate = parseFr(vatRateTtc.value);
      if (ttc > 0) {
        var ht = ttc / (1 + rate / 100);
        hintHtComputed.innerHTML = 'HT calculé : <b>' + formatEur(ht) + '</b> · TVA : <b>' + formatEur(ttc - ht) + '</b>';
      } else {
        hintHtComputed.innerHTML = 'HT calculé : <b>—</b>';
      }
    } else if (mode === 'ht' && inputHt && vatRateHt && hintTtcComputed) {
      var ht2 = parseFr(inputHt.value);
      var rate2 = parseFr(vatRateHt.value);
      if (ht2 > 0) {
        var ttc2 = ht2 * (1 + rate2 / 100);
        hintTtcComputed.innerHTML = 'TTC calculé : <b>' + formatEur(ttc2) + '</b> · TVA : <b>' + formatEur(ttc2 - ht2) + '</b>';
        // Sync le champ vat_rate (celui envoyé au serveur, qui est dans le modeTtc)
        if (vatRateTtc) vatRateTtc.value = vatRateHt.value;
      } else {
        hintTtcComputed.innerHTML = 'TTC calculé : <b>—</b>';
      }
    }
  }

  chips.forEach(function (chip) {
    chip.addEventListener('click', function (e) {
      e.preventDefault();
      switchMode(chip.dataset.mode);
    });
  });

  [inputTtc, inputHt, vatRateTtc, vatRateHt].filter(Boolean).forEach(function (el) {
    el.addEventListener('input', recalc);
    el.addEventListener('change', recalc);
  });

  // Style pour les chips actifs
  document.querySelectorAll('.amount-mode-chip').forEach(function (c) {
    c.style.cursor = 'pointer';
  });
})();

// ===== Scan IA d'une facture =====
(function () {
  var scanBtn = document.getElementById('scanBtn');
  var scanFileInput = document.getElementById('scanFileInput');
  var scanStatus = document.getElementById('scanStatus');
  var invoiceForm = document.getElementById('invoiceForm');
  var tempFileInput = document.getElementById('tempFileInput');

  if (!scanBtn || !scanFileInput || !invoiceForm) return;

  scanBtn.addEventListener('click', function () { scanFileInput.click(); });

  scanFileInput.addEventListener('change', function () {
    var file = scanFileInput.files[0];
    if (!file) return;
    scanFile(file);
  });

  // Permettre aussi le glisser-déposer sur la zone
  var scanZone = document.getElementById('scanZone');
  if (scanZone) {
    ['dragenter', 'dragover'].forEach(function (evt) {
      scanZone.addEventListener(evt, function (e) {
        e.preventDefault();
        scanZone.style.borderColor = 'var(--ai-dark)';
        scanZone.style.backgroundColor = 'var(--ai-light)';
      });
    });
    ['dragleave', 'drop'].forEach(function (evt) {
      scanZone.addEventListener(evt, function (e) {
        e.preventDefault();
        scanZone.style.borderColor = '';
        scanZone.style.backgroundColor = '';
      });
    });
    scanZone.addEventListener('drop', function (e) {
      e.preventDefault();
      var file = e.dataTransfer.files[0];
      if (file) scanFile(file);
    });
  }

  function scanFile(file) {
    var allowed = ['application/pdf', 'image/jpeg', 'image/png', 'image/jpg'];
    if (allowed.indexOf(file.type) === -1) {
      showStatus('❌ Format non supporté. Utilisez PDF, JPG ou PNG.', 'error');
      return;
    }
    if (file.size > 10 * 1024 * 1024) {
      showStatus('❌ Fichier trop gros (max 10 Mo).', 'error');
      return;
    }

    showStatus('<span class="ai-typing"><span></span><span></span><span></span></span> L\'IA lit votre facture…', 'loading');
    scanBtn.disabled = true;

    var fd = new FormData();
    fd.append('csrf_token', '<?= h($_SESSION['csrf_token']) ?>');
    fd.append('project_id', '<?= $project_id ?>');
    fd.append('invoice_file', file);

    fetch('/action-scan-facture', { method: 'POST', body: fd })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        scanBtn.disabled = false;
        if (!data.success) {
          showStatus('❌ ' + (data.error || 'Erreur inconnue'), 'error');
          return;
        }
        fillFormFromScan(data);
        var msg = '✅ Facture analysée ! Vérifiez les informations ci-dessous et ajustez si nécessaire.';
        if (data.confidence === 'low') msg = '⚠️ Analyse avec faible confiance. Vérifiez bien les informations ci-dessous.';
        if (data.warnings && data.warnings.length > 0) msg += '<br><span style="color: var(--ink-3); font-size: 11.5px;">Points à vérifier : ' + data.warnings.join(' · ') + '</span>';
        showStatus(msg, 'success');

        // Scroll doux vers le formulaire
        invoiceForm.scrollIntoView({ behavior: 'smooth', block: 'start' });
      })
      .catch(function (err) {
        scanBtn.disabled = false;
        showStatus('❌ Erreur réseau : ' + err.message, 'error');
      });
  }

  function showStatus(html, type) {
    scanStatus.style.display = 'block';
    scanStatus.innerHTML = html;
    if (type === 'success') scanStatus.style.color = 'var(--acc-dark)';
    else if (type === 'error') scanStatus.style.color = '#B91C1C';
    else scanStatus.style.color = 'var(--ai-dark)';
  }

  function fillFormFromScan(data) {
    // Fournisseur
    setVal('supplier_name', data.supplier_name);
    // Catégorie
    var catSelect = invoiceForm.querySelector('select[name="category"]');
    if (catSelect && data.category) {
      for (var i = 0; i < catSelect.options.length; i++) {
        if (catSelect.options[i].value === data.category) {
          catSelect.selectedIndex = i;
          break;
        }
      }
    }
    // Description
    setVal('description', data.description);
    // Date
    setVal('invoice_date', data.invoice_date);
    // N° de facture
    setVal('invoice_number', data.invoice_number);

    // On bascule en mode TTC et on remplit TTC + TVA
    var chipTtc = document.querySelector('.amount-mode-chip[data-mode="ttc"]');
    if (chipTtc) chipTtc.click();

    if (data.amount_ttc) {
      var inputTtc = document.getElementById('inputTtc');
      if (inputTtc) {
        inputTtc.value = String(data.amount_ttc).replace('.', ',');
      }
    }
    if (data.vat_rate !== null && data.vat_rate !== undefined) {
      var vatSelect = document.getElementById('vatRateTtc');
      if (vatSelect) {
        var rate = String(data.vat_rate);
        // Normaliser 5.5 / 2.1
        if (rate === '5.50') rate = '5.5';
        if (rate === '2.10') rate = '2.1';
        if (rate === '20.00') rate = '20';
        if (rate === '10.00') rate = '10';
        for (var j = 0; j < vatSelect.options.length; j++) {
          if (vatSelect.options[j].value === rate) {
            vatSelect.selectedIndex = j;
            break;
          }
        }
      }
    }

    // Mémoriser le chemin du fichier temporaire
    if (data.temp_file && tempFileInput) {
      tempFileInput.value = data.temp_file;
    }

    // Forcer le recalcul pour afficher le HT
    var inputTtc2 = document.getElementById('inputTtc');
    if (inputTtc2) {
      inputTtc2.dispatchEvent(new Event('input', { bubbles: true }));
    }
  }

  function setVal(name, value) {
    if (value === null || value === undefined) return;
    var el = invoiceForm.querySelector('[name="' + name + '"]');
    if (el) el.value = value;
  }
})();

// ===== Toggle zone de pièce jointe manuelle =====
(function () {
  var toggle = document.getElementById('toggleManualFile');
  var zone = document.getElementById('manualFileZone');
  var label = document.getElementById('toggleManualLabel');
  if (!toggle || !zone) return;

  toggle.addEventListener('click', function (e) {
    e.preventDefault();
    var isOpen = zone.style.display !== 'none';
    if (isOpen) {
      zone.style.display = 'none';
      if (label) label.textContent = 'Joindre un justificatif manuellement';
    } else {
      zone.style.display = 'block';
      if (label) label.textContent = 'Masquer la pièce jointe manuelle';
      // Focus sur le champ pour guider
      var input = zone.querySelector('input[type="file"]');
      if (input) setTimeout(function () { input.click(); }, 100);
    }
  });
})();
</script>

<style>
/* ============================================================
   PROJET 2.0 — harmonisation Liquid Glass (même look partout)
   Uniquement l'apparence : aucune logique ni permission modifiée.
   ============================================================ */
.main{max-width:1200px}

/* Cartes principales → verre dépoli cohérent */
.ck-hero, .ov2-card, .ov2-side-card, .inv-summary-card, .ai-action-card{
  background:var(--glass)!important;
  backdrop-filter:blur(20px) saturate(1.5);-webkit-backdrop-filter:blur(20px) saturate(1.5);
  border:1px solid var(--glass-border)!important;
  border-radius:var(--radius-lg,18px)!important;
  box-shadow:var(--shadow-card)!important;
}
.ck-hero:hover, .ov2-card:hover, .ov2-side-card:hover, .inv-summary-card:hover, .ai-action-card:hover{
  box-shadow:var(--shadow-pop)!important;
}

/* Cellules internes (KPI cockpit) → fond doux */
.ck-kpi{background:var(--bg-2)!important;border:1px solid var(--hairline,rgba(12,40,28,.06))!important;border-radius:12px!important}

/* Titres / valeurs → encre (mode sombre géré) */
.ck-donut-num, .ov2-card-title, .ov2-side-value, .ck-kpi-val, .ck-card-title, .ck-nba-title{color:var(--ink)!important}
.ov2-card-text{color:var(--ink-2)!important}
.ck-kpi-lbl, .ov2-side-label, .ck-donut-lbl, .ck-kpi-sub, .ov2-card-action, .ck-card-sub, .ck-nba-text{color:var(--ink-3)!important}

/* Objectif — identité verte conservée, harmonisée */
.ov2-objective{background:var(--glass)!important;border:1px solid var(--glass-border)!important}
.ov2-objective-text{background:var(--brand-soft,rgba(16,185,129,.08))!important;border-left:3px solid var(--brand-2,#10B981)!important;color:var(--brand-ink,#065F46)!important;border-radius:0 12px 12px 0!important}

/* Compteur d'étapes / pastille */
.ov2-step-counter{background:var(--ai-light,#ede9fe)!important;color:var(--ai-dark,#5b21b6)!important}

/* Icônes d'en-tête de carte : léger relief */
.ov2-card-icon, .ov2-side-icon{box-shadow:inset 0 1px 0 var(--glass-hi,rgba(255,255,255,.6))}

/* Boutons / onglets */
.ck-bilan-btn{border-radius:13px!important}
.tab{font-weight:600}
.tab-badge{font-weight:700}

/* ===== Cockpit COMPACT : 1 bloc (donut + 3 KPI en haut, barre d'actions en bas) ===== */
.ck-hero{
  overflow:visible!important;
  display:grid!important;
  grid-template-columns:auto 1fr!important;
  gap:16px 24px!important;
  align-items:center!important;
  padding:20px 24px!important;
  position:relative;z-index:30;
}
/* la carte "prochaine action" et la suite passent SOUS le menu Exports ouvert */
.ck-nba{position:relative;z-index:1}
.ck-hero-bg{border-radius:var(--radius-lg,18px) var(--radius-lg,18px) 0 0!important}
.ck-donut-block{grid-column:1!important;grid-row:auto!important}
.ck-kpi-grid{grid-column:2!important;grid-row:auto!important;grid-template-columns:repeat(3,1fr)!important;gap:12px!important}
.ck-spark{display:none!important}
/* Barre d'actions : une seule ligne, pleine largeur */
.ck-actions-bar{grid-column:1 / -1!important;display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin:0!important;padding-top:14px;border-top:1px solid var(--hairline-2,rgba(12,40,28,.05))}
.ck-bilan{order:0;margin:0!important;padding-top:0!important;border-top:0!important}
.ck-exports{order:1;margin:0!important;position:relative}
.ck-share{order:2;margin:0 0 0 auto!important;padding-top:0!important;border-top:0!important}
/* Bouton + menu Exports */
.ck-exports-btn{display:inline-flex;align-items:center;gap:8px;padding:11px 16px;border-radius:12px;border:1px solid var(--glass-border);background:var(--surface);color:var(--ink-2);font-size:13.5px;font-weight:650;cursor:pointer;backdrop-filter:blur(10px);-webkit-backdrop-filter:blur(10px);box-shadow:var(--shadow-sm);font-family:inherit}
.ck-exports-btn:hover{color:var(--ink)}
.ck-exports-menu{position:absolute;top:calc(100% + 8px);left:0;min-width:220px;background:var(--solid,#fff);border:1px solid var(--border);border-radius:15px;box-shadow:var(--shadow-pop);padding:6px;z-index:50}
.ck-exports-menu a{display:flex;align-items:center;gap:11px;padding:11px 12px;border-radius:10px;text-decoration:none;color:var(--ink);font-size:13.5px;font-weight:550}
.ck-exports-menu a:hover{background:var(--bg-2)}
.ck-exports-menu .mi{width:28px;height:28px;border-radius:8px;display:grid;place-items:center;font-size:15px;flex:none;background:var(--bg-2)}
@media (max-width:760px){
  .ck-hero{grid-template-columns:1fr!important;justify-items:center;text-align:center}
  .ck-kpi-grid{grid-column:1!important;grid-template-columns:1fr 1fr!important;width:100%}
  .ck-actions-bar{justify-content:center}
  .ck-share{margin-left:0!important}
}

/* ===== Cartes Engagement/Bilan déplacées sous les onglets → verre cohérent ===== */
.ov2-row-2col{display:grid!important;grid-template-columns:1fr 1fr!important;gap:16px!important;margin-bottom:16px!important}
.ov2-row-2col .ck-card{background:var(--glass)!important;backdrop-filter:blur(20px) saturate(1.5);-webkit-backdrop-filter:blur(20px) saturate(1.5);border:1px solid var(--glass-border)!important;border-radius:var(--radius-lg,18px)!important;box-shadow:var(--shadow-card)!important;padding:18px 20px!important}
.ov2-row-2col .ck-card:hover{box-shadow:var(--shadow-pop)!important}
.ov2-row-2col .ck-nudges-card{box-shadow:var(--shadow-card), inset 3px 0 0 var(--amber,#E0850C)!important}
.ov2-row-2col .ck-card-title{color:var(--ink)!important;font-weight:700}
.ov2-row-2col .ck-card-sub,.ov2-row-2col .ck-card-empty,.ov2-row-2col .ck-nudges-intro{color:var(--ink-3)!important}
.ov2-row-2col .ck-team-name{color:var(--ink)!important;font-weight:650}
.ov2-row-2col .ck-team-meta,.ov2-row-2col .ck-team-score{color:var(--ink-3)!important}
.ov2-row-2col .ck-nudge{background:var(--bg-2)!important;border:1px solid var(--hairline,rgba(12,40,28,.06))!important;border-radius:12px!important}
.ov2-row-2col .ck-nudge:hover{border-color:rgba(224,133,12,.35)!important}
.ov2-row-2col .ck-nudge-label{color:var(--ink)!important;font-weight:650}
.ov2-row-2col .ck-nudge-why{color:var(--ink-3)!important}
@media (max-width:860px){.ov2-row-2col{grid-template-columns:1fr!important}}

/* ===== Vue d'ensemble : plus compacte, fluide et lisible ===== */
.ov2-card-text{line-height:1.6}
.ov2-desc .ov2-clamp{display:-webkit-box;-webkit-line-clamp:5;-webkit-box-orient:vertical;overflow:hidden}
.ov2-desc.is-open .ov2-clamp{-webkit-line-clamp:unset;overflow:visible}
.ov2-more{margin-top:12px;background:transparent;border:0;color:var(--ai,#6366F1);font-weight:650;font-size:13px;cursor:pointer;font-family:inherit;padding:2px 0;display:inline-flex;align-items:center;gap:5px}
.ov2-more:hover{text-decoration:underline}
/* Étapes : lignes plus ramassées */
.ov2-card .step-item{padding-top:11px!important;padding-bottom:11px!important}
.ov2-card .step-title{font-weight:600}
/* Étapes : validation premium + facile (toute la ligne cliquable) */
.step-progress{height:6px;border-radius:5px;background:var(--hairline,rgba(12,40,28,.08));overflow:hidden;margin:2px 0 14px}
.step-progress-fill{height:100%;border-radius:5px;background:linear-gradient(90deg,var(--brand-2,#10B981),var(--acc,#059669));transition:width .6s cubic-bezier(.4,0,.2,1)}
.ov2-card .step-item{border-radius:12px}
.ov2-card .step-check{width:24px;height:24px}
.ov2-card .step-item:hover .step-check:not(.done):not(.readonly){border-color:var(--acc,#059669);background:var(--acc-light,rgba(5,150,105,.12))}
.ov2-card .step-item:hover .step-check:not(.done):not(.readonly) svg{opacity:.55;color:var(--acc,#059669)}
.ov2-card .step-check svg{transition:opacity .15s ease}
.ov2-card .step-check.done{animation:stepPop .28s ease}
@keyframes stepPop{0%{transform:scale(.82)}60%{transform:scale(1.14)}100%{transform:scale(1)}}

/* ===== Fichiers / Factures / Messages : premium + fix débordement des noms ===== */
.file-card{background:var(--glass)!important;backdrop-filter:blur(18px) saturate(1.5);-webkit-backdrop-filter:blur(18px) saturate(1.5);border:1px solid var(--glass-border)!important;border-radius:16px!important;box-shadow:var(--shadow-card)!important;overflow:hidden!important;transition:transform .16s ease,box-shadow .16s ease}
.file-card:hover{transform:translateY(-3px);box-shadow:var(--shadow-pop)!important}
.file-info{min-width:0!important;overflow:hidden}
.file-name{overflow:hidden!important;text-overflow:ellipsis!important;white-space:nowrap!important;color:var(--ink)!important;max-width:100%}
.file-meta{color:var(--ink-3)!important}
.drop-zone{background:var(--glass)!important;backdrop-filter:blur(18px) saturate(1.4);-webkit-backdrop-filter:blur(18px) saturate(1.4);border:2px dashed var(--glass-border)!important;border-radius:var(--radius-lg,18px)!important}
.drop-zone:hover{border-color:var(--acc)!important;background:var(--acc-light,rgba(5,150,105,.08))!important}
.drop-zone-title{color:var(--ink)!important;font-weight:650}
/* Formulaire facture : champs adaptés au thème */
.form-input-lg,.form-select-lg{background:var(--bg)!important;color:var(--ink)!important;border-color:var(--border)!important;border-radius:12px!important}
.form-input-lg:focus,.form-select-lg:focus{border-color:var(--acc)!important;box-shadow:0 0 0 4px var(--acc-light,rgba(5,150,105,.12))!important}
.form-label{color:var(--ink-2)!important;font-weight:600}
/* Messages : bandeau sync + zone de saisie */
#chatInput{border-radius:14px!important}
</style>

<script>
/* Ferme le menu Exports au clic en dehors */
document.addEventListener('click', function(e){
  document.querySelectorAll('.ck-exports-menu:not([hidden])').forEach(function(m){
    if (!m.parentNode.contains(e.target)) m.hidden = true;
  });
});
/* Description repliable : « Voir plus » seulement si le texte dépasse */
document.querySelectorAll('.ov2-desc').forEach(function(card){
  var txt = card.querySelector('.ov2-clamp'), btn = card.querySelector('.ov2-more');
  if (!txt || !btn) return;
  if (txt.scrollHeight - txt.clientHeight > 4){
    btn.hidden = false;
    btn.addEventListener('click', function(){
      var open = card.classList.toggle('is-open');
      btn.textContent = open ? 'Voir moins ▴' : 'Voir plus ▾';
    });
  }
});
/* Étapes : cliquer n'importe où sur la ligne valide / dévalide */
document.querySelectorAll('.ov2-card .step-item').forEach(function(item){
  var btn = item.querySelector('button.step-check');
  if (!btn) return;
  item.style.cursor = 'pointer';
  item.addEventListener('click', function(e){
    if (e.target.closest('a, form')) return; // le bouton (dans le form) et les liens gardent leur comportement
    btn.click();
  });
});
</script>

<?php render_foot(); ?>
