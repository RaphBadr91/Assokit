<?php
/**
 * ============================================================
 * ASSOKIT — Téléchargement PDF d'un document IA généré
 * ============================================================
 * GET /download-bilan.php?id=NN
 *
 * v3 — Mise en page « rapport » :
 *   - En-tête sobre (logo, association, type de document, date d'édition)
 *   - Fiche d'identité du projet + 4 indicateurs clés
 *   - Tableau des étapes (validée le / par), budget, activité 6 mois
 *   - Corps Markdown avec sections numérotées et vrais tableaux
 *
 * mPDF ne gère ni display:table sur des <div>, ni les emojis avec
 * DejaVu : toute la mise en page passe par des <table>, et les
 * pictogrammes couleur sont retirés du texte.
 * ============================================================
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/vendor/autoload.php';
require_login();

$user = current_user();
$doc_id = (int)($_GET['id'] ?? 0);
if ($doc_id <= 0) {
    http_response_code(400);
    die('Document invalide.');
}

$stmt = $pdo->prepare("
    SELECT g.id, g.doc_type, g.title, g.content, g.created_at,
           p.id AS project_id, p.name AS project_name, p.location AS project_location,
           p.start_date, p.end_date, p.budget_planned, p.budget_used,
           p.progress_percent, p.participants_count,
           f.name AS folder_name,
           o.name AS org_name, o.id AS org_id, o.logo_path,
           u.first_name AS author_first, u.last_name AS author_last,
           r.first_name AS ref_first, r.last_name AS ref_last
    FROM ai_generated_docs g
    JOIN projects p ON p.id = g.project_id
    JOIN folders f ON f.id = p.folder_id
    JOIN organizations o ON o.id = f.org_id
    LEFT JOIN users u ON u.id = g.user_id
    LEFT JOIN users r ON r.id = p.referent_id
    WHERE g.id = ? AND f.org_id = ?
");
$stmt->execute([$doc_id, $user['org_id']]);
$doc = $stmt->fetch();
if (!$doc) {
    http_response_code(404);
    die('Document introuvable ou accès refusé.');
}

$project_id = (int)$doc['project_id'];

// ============================================================
// DONNÉES
// ============================================================

function ak_pdf_load_steps(PDO $pdo, int $project_id): array {
    $stmt = $pdo->prepare("
        SELECT s.id, s.title, s.is_completed, s.completed_at, s.position,
               u.first_name AS by_first, u.last_name AS by_last
        FROM project_steps s
        LEFT JOIN users u ON u.id = s.completed_by
        WHERE s.project_id = ?
        ORDER BY s.position ASC, s.id ASC
    ");
    $stmt->execute([$project_id]);
    return $stmt->fetchAll();
}

function ak_pdf_load_budget(PDO $pdo, int $project_id, array $project): array {
    $planned = (float)($project['budget_planned'] ?? 0);
    $used_db = (float)($project['budget_used'] ?? 0);
    $invoiced = 0;
    try {
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount_total), 0) AS total FROM project_invoices WHERE project_id = ?");
        $stmt->execute([$project_id]);
        $row = $stmt->fetch();
        if ($row) $invoiced = (float)$row['total'];
    } catch (Throwable $e) {}
    $used = max($invoiced, $used_db);
    $pct = $planned > 0 ? round(($used / $planned) * 100) : 0;
    return ['planned' => $planned, 'used' => $used, 'pct' => $pct, 'remaining' => max(0, $planned - $used)];
}

function ak_pdf_load_activity_6m(PDO $pdo, int $project_id): array {
    $months = [];
    for ($i = 5; $i >= 0; $i--) {
        $key = date('Y-m', strtotime(date('Y-m-01') . " -$i months"));
        $months[$key] = ['key' => $key, 'label' => '', 'msg' => 0, 'step' => 0, 'file' => 0, 'total' => 0];
    }
    $mois_short = ['', 'janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'];
    foreach ($months as $k => &$m) {
        list($y, $mo) = explode('-', $k);
        $m['label'] = $mois_short[(int)$mo];
    }
    unset($m);
    try {
        $stmt = $pdo->prepare("SELECT DATE_FORMAT(created_at, '%Y-%m') AS k, COUNT(*) AS c FROM project_messages WHERE project_id = ? AND created_at >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH) GROUP BY k");
        $stmt->execute([$project_id]);
        foreach ($stmt->fetchAll() as $r) if (isset($months[$r['k']])) $months[$r['k']]['msg'] = (int)$r['c'];
        $stmt = $pdo->prepare("SELECT DATE_FORMAT(completed_at, '%Y-%m') AS k, COUNT(*) AS c FROM project_steps WHERE project_id = ? AND completed_at IS NOT NULL AND completed_at >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH) GROUP BY k");
        $stmt->execute([$project_id]);
        foreach ($stmt->fetchAll() as $r) if (isset($months[$r['k']])) $months[$r['k']]['step'] = (int)$r['c'];
        $stmt = $pdo->prepare("SELECT DATE_FORMAT(created_at, '%Y-%m') AS k, COUNT(*) AS c FROM project_files WHERE project_id = ? AND created_at >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH) GROUP BY k");
        $stmt->execute([$project_id]);
        foreach ($stmt->fetchAll() as $r) if (isset($months[$r['k']])) $months[$r['k']]['file'] = (int)$r['c'];
    } catch (Throwable $e) {}
    foreach ($months as &$m) $m['total'] = $m['msg'] + $m['step'] + $m['file'];
    unset($m);
    return array_values($months);
}

/** Date SQL valide ? (écarte NULL, '0000-00-00' et les dates absurdes) */
function ak_pdf_date_ok($d): bool {
    if (empty($d) || strncmp((string)$d, '0000', 4) === 0) return false;
    $t = strtotime((string)$d);
    return $t !== false && (int)date('Y', $t) >= 1990;
}

function ak_pdf_date_fr($d, bool $long = true): string {
    if (!ak_pdf_date_ok($d)) return '';
    $t = strtotime((string)$d);
    if (!$long) return date('d/m/Y', $t);
    $mois = ['', 'janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
    return (int)date('j', $t) . ($t && date('j', $t) === '1' ? 'er' : '') . ' ' . $mois[(int)date('n', $t)] . ' ' . date('Y', $t);
}

function ak_pdf_eur(float $n): string {
    return number_format($n, 0, ',', "\u{202F}") . "\u{00A0}€";
}

/** Retire les pictogrammes couleur que la police du PDF ne sait pas dessiner */
function ak_pdf_strip_emoji(string $s): string {
    $s = preg_replace('/[\x{1F000}-\x{1FAFF}\x{1F900}-\x{1F9FF}\x{2B50}\x{2B55}\x{2614}\x{2615}\x{2648}-\x{2653}\x{267F}\x{2693}\x{26A1}\x{26AA}\x{26AB}\x{26BD}\x{26BE}\x{26C4}\x{26C5}\x{26CE}\x{26D4}\x{26EA}\x{26F2}-\x{26F5}\x{26FA}\x{26FD}\x{2705}\x{270A}\x{270B}\x{2728}\x{274C}\x{274E}\x{2753}-\x{2755}\x{2757}\x{2795}-\x{2797}\x{27B0}\x{27BF}\x{231A}\x{231B}\x{23E9}-\x{23FA}\x{FE0F}\x{200D}\x{20E3}]/u', '', $s);
    // Espaces orphelins laissés en tête de ligne ou de titre
    $s = preg_replace('/^([#>*\-\d.| \t]*?)[ \t]{2,}/m', '$1 ', $s);
    return preg_replace('/[ \t]+([.,])/', '$1', $s);
}

$steps = ak_pdf_load_steps($pdo, $project_id);
$total_steps = count($steps);
$done_steps = 0;
foreach ($steps as $s) if ($s['is_completed']) $done_steps++;
$progress_pct = $total_steps > 0 ? (int)round(($done_steps / $total_steps) * 100) : (int)$doc['progress_percent'];
$budget = ak_pdf_load_budget($pdo, $project_id, $doc);
$activity_6m = ak_pdf_load_activity_6m($pdo, $project_id);
$activity_total = array_sum(array_column($activity_6m, 'total'));

// ============================================================
// PALETTE
// ============================================================
const AKP_INK    = '#0F172A';
const AKP_TEXT   = '#334155';
const AKP_MUTED  = '#64748B';
const AKP_LINE   = '#E2E8F0';
const AKP_SOFT   = '#F8FAFC';
const AKP_ACCENT = '#0F766E';
const AKP_ACC_2  = '#14B8A6';
const AKP_ACC_3  = '#99F6E4';
const AKP_WARN   = '#B45309';
const AKP_ALERT  = '#B91C1C';

// ============================================================
// GRAPHIQUES (SVG — rendus nativement par mPDF)
// ============================================================

function ak_pdf_svg_progress(int $pct, string $color, int $w = 150): string {
    $pct = max(0, min(100, $pct));
    $fill = (int)round($w * $pct / 100);
    return '<svg width="' . $w . '" height="6" viewBox="0 0 ' . $w . ' 6" xmlns="http://www.w3.org/2000/svg">'
         . '<rect x="0" y="0" width="' . $w . '" height="6" rx="3" fill="' . AKP_LINE . '"/>'
         . ($fill > 0 ? '<rect x="0" y="0" width="' . max(6, $fill) . '" height="6" rx="3" fill="' . $color . '"/>' : '')
         . '</svg>';
}

function ak_pdf_svg_activity(array $months): string {
    $w = 300; $h = 120;
    $pad_l = 22; $pad_b = 22; $pad_t = 14; $pad_r = 4;
    $chart_w = $w - $pad_l - $pad_r;
    $chart_h = $h - $pad_b - $pad_t;
    $max = 1;
    foreach ($months as $m) if ($m['total'] > $max) $max = $m['total'];
    $slot = $chart_w / count($months);
    $bar_w = $slot * 0.56;
    $svg = '<svg width="' . $w . '" height="' . $h . '" viewBox="0 0 ' . $w . ' ' . $h . '" xmlns="http://www.w3.org/2000/svg">';
    for ($g = 0; $g <= 2; $g++) {
        $y = $pad_t + ($chart_h * $g / 2);
        $val = round($max * (2 - $g) / 2);
        $svg .= '<line x1="' . $pad_l . '" y1="' . $y . '" x2="' . ($w - $pad_r) . '" y2="' . $y . '" stroke="' . AKP_LINE . '" stroke-width="0.6"/>';
        $svg .= '<text x="' . ($pad_l - 5) . '" y="' . ($y + 2.5) . '" text-anchor="end" font-family="DejaVu Sans" font-size="7" fill="' . AKP_MUTED . '">' . $val . '</text>';
    }
    foreach ($months as $i => $m) {
        $x = $pad_l + $i * $slot + ($slot - $bar_w) / 2;
        $y = $pad_t + $chart_h;
        foreach ([['msg', AKP_ACCENT], ['step', AKP_ACC_2], ['file', AKP_ACC_3]] as [$k, $c]) {
            $hh = $m[$k] / $max * $chart_h;
            if ($hh <= 0) continue;
            $y -= $hh;
            $svg .= '<rect x="' . round($x, 1) . '" y="' . round($y, 1) . '" width="' . round($bar_w, 1) . '" height="' . round($hh, 1) . '" fill="' . $c . '"/>';
        }
        if ($m['total'] > 0) {
            $svg .= '<text x="' . round($x + $bar_w / 2, 1) . '" y="' . round($y - 3, 1) . '" text-anchor="middle" font-family="DejaVu Sans" font-size="7" font-weight="bold" fill="' . AKP_INK . '">' . $m['total'] . '</text>';
        }
        $svg .= '<text x="' . round($x + $bar_w / 2, 1) . '" y="' . ($h - 8) . '" text-anchor="middle" font-family="DejaVu Sans" font-size="7.5" fill="' . AKP_MUTED . '">' . htmlspecialchars($m['label'], ENT_QUOTES) . '</text>';
    }
    return $svg . '</svg>';
}

// ============================================================
// Markdown → HTML (titres numérotés, listes, tableaux)
// ============================================================

function ak_pdf_inline(string $text): string {
    $text = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    $text = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $text);
    $text = preg_replace('/(?<![\*\w])\*(?!\s)([^\*]+?)\*(?!\w)/u', '<em>$1</em>', $text);
    $text = preg_replace('/`([^`]+)`/', '<code>$1</code>', $text);
    return $text;
}

function ak_pdf_table(array $rows): string {
    $cells = function ($line) {
        $line = trim($line);
        $line = preg_replace('/^\||\|$/', '', $line);
        return array_map('trim', explode('|', $line));
    };
    $head = $cells(array_shift($rows));
    $align = array_fill(0, count($head), 'left');
    if ($rows && preg_match('/^\|?\s*:?-{2,}/', trim($rows[0]))) {
        foreach ($cells(array_shift($rows)) as $i => $spec) {
            if (preg_match('/^-+:$/', $spec)) $align[$i] = 'right';
            elseif (preg_match('/^:-+:$/', $spec)) $align[$i] = 'center';
        }
    }
    // Colonnes chiffrées alignées à droite même sans « ---: »
    foreach ($head as $i => $_) {
        if ($align[$i] !== 'left' || $i === 0) continue;
        $num = 0; $all = 0;
        foreach ($rows as $r) {
            $v = strip_tags(str_replace('**', '', $cells($r)[$i] ?? ''));
            if ($v === '') continue;
            $all++;
            if (preg_match('/^[-+]?[\d\s\x{202F}\x{00A0}.,]+\s*(€|%|k€)?$/u', $v)) $num++;
        }
        if ($all && $num === $all) $align[$i] = 'right';
    }
    $html = '<table class="md-table" cellspacing="0"><thead><tr>';
    foreach ($head as $i => $c) $html .= '<th style="text-align:' . $align[$i] . '">' . ak_pdf_inline($c) . '</th>';
    $html .= '</tr></thead><tbody>';
    foreach ($rows as $n => $r) {
        $cs = $cells($r);
        $is_total = preg_match('/^\*\*?\s*total/i', $cs[0] ?? '');
        $html .= '<tr class="' . ($is_total ? 'tot' : ($n % 2 ? 'alt' : '')) . '">';
        foreach ($head as $i => $_) $html .= '<td style="text-align:' . $align[$i] . '">' . ak_pdf_inline($cs[$i] ?? '') . '</td>';
        $html .= '</tr>';
    }
    return $html . '</tbody></table>';
}

function ak_md_to_html(string $md): string {
    $lines = explode("\n", str_replace("\r\n", "\n", $md));
    $html = ''; $list = null; $para = []; $table = []; $h2 = 0;
    $flush_para = function () use (&$para, &$html) {
        // Retours à la ligne conservés (signatures, adresses de courrier)
        if ($para) $html .= '<p>' . implode('<br/>', $para) . '</p>';
        $para = [];
    };
    $close_list = function () use (&$list, &$html) {
        if ($list) { $html .= '</' . $list . '>'; $list = null; }
    };
    $flush_table = function () use (&$table, &$html) {
        if ($table) $html .= ak_pdf_table($table);
        $table = [];
    };
    foreach ($lines as $line) {
        $t = trim($line);
        if ($t !== '' && $t[0] === '|') { $flush_para(); $close_list(); $table[] = $t; continue; }
        $flush_table();
        if (preg_match('/^#{1,2}\s+(.+)$/', $t, $m)) {
            $flush_para(); $close_list(); $h2++;
            $html .= '<h2><span class="num">' . str_pad((string)$h2, 2, '0', STR_PAD_LEFT) . '</span>&nbsp;&nbsp;' . ak_pdf_inline($m[1]) . '</h2>';
            continue;
        }
        if (preg_match('/^#{3,}\s+(.+)$/', $t, $m)) { $flush_para(); $close_list(); $html .= '<h3>' . ak_pdf_inline($m[1]) . '</h3>'; continue; }
        if (preg_match('/^(---+|\*\*\*+)$/', $t)) { $flush_para(); $close_list(); $html .= '<hr/>'; continue; }
        $tag = null;
        if (preg_match('/^[-*•]\s+(.+)$/u', $t, $m)) $tag = 'ul';
        elseif (preg_match('/^\d+[.)]\s+(.+)$/', $t, $m)) $tag = 'ol';
        if ($tag) {
            $flush_para();
            if ($list !== $tag) { $close_list(); $html .= '<' . $tag . '>'; $list = $tag; }
            $html .= '<li>' . ak_pdf_inline($m[1]) . '</li>';
            continue;
        }
        if ($t === '') { $flush_para(); $close_list(); continue; }
        if (preg_match('/^>\s?(.*)$/', $t, $m)) { $flush_para(); $close_list(); $html .= '<blockquote>' . ak_pdf_inline($m[1]) . '</blockquote>'; continue; }
        $close_list();
        $para[] = ak_pdf_inline($t);
    }
    $flush_table(); $flush_para(); $close_list();
    return $html;
}

// ============================================================
// MISE EN FORME
// ============================================================

$doc_labels = [
    'bilan_date'         => 'Bilan de projet',
    'bilan_ag'           => 'Bilan pour l’Assemblée générale',
    'rapport_subvention' => 'Rapport de subvention',
    'synthese_etape'     => 'Point d’avancement',
    'email_parents'      => 'Courrier d’information',
    'fiche_com'          => 'Fiche de communication',
];
$doc_label = $doc_labels[$doc['doc_type']] ?? 'Document de projet';
// Les indicateurs n'ont pas leur place dans un courrier ou une fiche de com
$with_dashboard = !in_array($doc['doc_type'], ['email_parents', 'fiche_com'], true);

$body_html = ak_md_to_html(ak_pdf_strip_emoji((string)$doc['content']));
$doc_title = trim(ak_pdf_strip_emoji((string)$doc['title']));

$today_long = ak_pdf_date_fr(date('Y-m-d'));
$author_full = trim(($doc['author_first'] ?? '') . ' ' . ($doc['author_last'] ?? '')) ?: 'Assokit';
$referent = trim(($doc['ref_first'] ?? '') . ' ' . ($doc['ref_last'] ?? ''));
$period = '';
if (ak_pdf_date_ok($doc['start_date']) && ak_pdf_date_ok($doc['end_date'])) {
    $period = ak_pdf_date_fr($doc['start_date'], false) . ' → ' . ak_pdf_date_fr($doc['end_date'], false);
} elseif (ak_pdf_date_ok($doc['start_date'])) {
    $period = 'Depuis le ' . ak_pdf_date_fr($doc['start_date'], false);
}
$logo_full_path = '';
if (!empty($doc['logo_path'])) {
    $candidate = __DIR__ . '/' . ltrim($doc['logo_path'], '/');
    if (is_file($candidate)) $logo_full_path = $candidate;
}
$h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

$facts = [['Association', $doc['org_name']]];
if (!empty($doc['folder_name'])) $facts[] = ['Programme', $doc['folder_name']];
if (!empty($doc['project_location'])) $facts[] = ['Lieu', $doc['project_location']];
if ($period) $facts[] = ['Période', $period];
if ($referent) $facts[] = ['Référent', $referent];
$facts[] = ['Rédigé par', $author_full];

$budget_color = $budget['pct'] >= 95 ? AKP_ALERT : ($budget['pct'] >= 80 ? AKP_WARN : AKP_ACCENT);
$kpis = [
    ['Avancement', $progress_pct . ' %', $total_steps ? $done_steps . ' étape' . ($done_steps > 1 ? 's' : '') . ' sur ' . $total_steps : 'Aucune étape définie', ak_pdf_svg_progress($progress_pct, AKP_ACCENT)],
    ['Budget engagé', $budget['planned'] > 0 ? $budget['pct'] . ' %' : '—', $budget['planned'] > 0 ? ak_pdf_eur($budget['used']) . ' sur ' . ak_pdf_eur($budget['planned']) : 'Budget non renseigné', $budget['planned'] > 0 ? ak_pdf_svg_progress((int)$budget['pct'], $budget_color) : ''],
    ['Participants', (int)$doc['participants_count'] > 0 ? (string)(int)$doc['participants_count'] : '—', 'personnes accompagnées', ''],
    ['Activité', (string)$activity_total, 'actions sur 6 mois', ''],
];

// Étape « en cours » = première non validée
$current_idx = null;
foreach ($steps as $i => $s) if (!$s['is_completed']) { $current_idx = $i; break; }

ob_start();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<style>
body { font-family: 'DejaVu Sans', sans-serif; font-size: 9.5pt; line-height: 1.55; color: <?= AKP_TEXT ?>; }
table { border-collapse: collapse; }

.top { width: 100%; }
.top td { vertical-align: middle; padding: 0; }
.top-logo { width: 16mm; padding-right: 4mm !important; }
.top-org { font-size: 10pt; font-weight: bold; color: <?= AKP_INK ?>; }
.top-r { text-align: right; }
.top-type { font-size: 7.5pt; font-weight: bold; color: <?= AKP_ACCENT ?>; letter-spacing: 1.2pt; text-transform: uppercase; }
.top-date { font-size: 8pt; color: <?= AKP_MUTED ?>; margin-top: 1mm; }
.rule { height: 0; border-top: 0.6pt solid <?= AKP_LINE ?>; margin: 5mm 0 7mm; }

.title { font-size: 21pt; font-weight: bold; color: <?= AKP_INK ?>; line-height: 1.2; margin: 1.5mm 0 1.5mm; }
.subtitle { font-size: 11pt; color: <?= AKP_MUTED ?>; margin-bottom: 7mm; }

.facts { width: 100%; border-top: 0.6pt solid <?= AKP_LINE ?>; border-bottom: 0.6pt solid <?= AKP_LINE ?>; margin-bottom: 7mm; }
.facts td { padding: 2.6mm 3mm 2.6mm 0; vertical-align: top; }
.f-lbl { font-size: 7pt; color: <?= AKP_MUTED ?>; text-transform: uppercase; letter-spacing: 0.6pt; font-weight: bold; }
.f-val { font-size: 9pt; color: <?= AKP_INK ?>; font-weight: bold; margin-top: 0.8mm; }

.kpis { width: 100%; margin-bottom: 8mm; }
td.kpi { width: 23.5%; vertical-align: top; background: <?= AKP_SOFT ?>; border-top: 1.4pt solid <?= AKP_ACCENT ?>; padding: 3mm 3.5mm 3.4mm; }
td.kpi-gap { width: 2%; }
.kpi-lbl { font-size: 7pt; color: <?= AKP_MUTED ?>; text-transform: uppercase; letter-spacing: 0.6pt; font-weight: bold; }
.kpi-val { font-size: 18pt; font-weight: bold; color: <?= AKP_INK ?>; line-height: 1.15; margin-top: 1.2mm; }
.kpi-sub { font-size: 7.5pt; color: <?= AKP_MUTED ?>; margin-top: 0.6mm; }
.kpi-bar { margin-top: 2mm; }

.blk-title { font-size: 8pt; font-weight: bold; color: <?= AKP_INK ?>; text-transform: uppercase; letter-spacing: 0.8pt; padding-bottom: 2mm; border-bottom: 0.6pt solid <?= AKP_LINE ?>; margin-bottom: 2mm; }
.steps { width: 100%; margin-bottom: 8mm; }
.steps td { padding: 1.9mm 2mm; border-bottom: 0.4pt solid <?= AKP_LINE ?>; font-size: 8.5pt; vertical-align: middle; }
.steps th { padding: 1.6mm 2mm; font-size: 7pt; color: <?= AKP_MUTED ?>; text-transform: uppercase; letter-spacing: 0.5pt; text-align: left; border-bottom: 0.6pt solid #CBD5E1; }
.steps .n { width: 7mm; color: <?= AKP_MUTED ?>; font-weight: bold; }
.steps .st { width: 22mm; }
.steps .when { width: 26mm; color: <?= AKP_MUTED ?>; }
.steps .who { width: 34mm; color: <?= AKP_MUTED ?>; }
.pill { font-size: 7pt; font-weight: bold; padding: 0.6mm 2mm; }
.pill-done { color: <?= AKP_ACCENT ?>; background: #CCFBF1; }
.pill-cur { color: <?= AKP_WARN ?>; background: #FEF3C7; }
.pill-todo { color: <?= AKP_MUTED ?>; background: #F1F5F9; }

.duo { width: 100%; margin-bottom: 4mm; }
.duo td { vertical-align: top; }
.legend { font-size: 7pt; color: <?= AKP_MUTED ?>; margin-top: 1mm; }
.sw { font-size: 9pt; }
.bud td { padding: 1.4mm 0; font-size: 8.5pt; border-bottom: 0.4pt solid <?= AKP_LINE ?>; }
.bud td.r { text-align: right; font-weight: bold; color: <?= AKP_INK ?>; }
.bud-note { font-size: 7.5pt; color: <?= AKP_MUTED ?>; margin-top: 2mm; }

.body h2 { font-size: 12.5pt; color: <?= AKP_INK ?>; margin: 8mm 0 3mm; padding-bottom: 1.8mm; border-bottom: 0.8pt solid <?= AKP_ACCENT ?>; page-break-after: avoid; }
.body h2 .num { color: <?= AKP_ACCENT ?>; }
.body h3 { font-size: 10pt; color: <?= AKP_INK ?>; margin: 4.5mm 0 1.5mm; font-weight: bold; page-break-after: avoid; }
.body p { margin: 0 0 2.6mm; }
.body ul, .body ol { margin: 0 0 3mm 0; padding-left: 6mm; }
.body li { margin: 0 0 1mm; }
.body strong { color: <?= AKP_INK ?>; }
.body code { font-family: 'DejaVu Sans Mono', monospace; font-size: 8.5pt; background: #F1F5F9; }
.body hr { border: 0; height: 0; border-top: 0.6pt solid <?= AKP_LINE ?>; margin: 6mm 0 4mm; }
.body blockquote { margin: 3mm 0; padding: 2mm 4mm; border-left: 1.4pt solid <?= AKP_ACC_2 ?>; background: <?= AKP_SOFT ?>; color: <?= AKP_INK ?>; }
.md-table { width: 100%; margin: 2mm 0 4mm; page-break-inside: avoid; }
.md-table th { background: <?= AKP_INK ?>; color: #FFFFFF; font-size: 7.5pt; font-weight: bold; padding: 2mm 2.5mm; text-transform: uppercase; letter-spacing: 0.4pt; }
.md-table td { font-size: 8.5pt; padding: 1.8mm 2.5mm; border-bottom: 0.4pt solid <?= AKP_LINE ?>; }
.md-table tr.alt td { background: <?= AKP_SOFT ?>; }
.md-table tr.tot td { background: #F0FDFA; border-top: 0.8pt solid <?= AKP_ACCENT ?>; border-bottom: 0; font-weight: bold; color: <?= AKP_INK ?>; }
</style>
</head>
<body>

<table class="top">
  <tr>
    <?php if ($logo_full_path): ?>
    <td class="top-logo"><img src="<?= $h($logo_full_path) ?>" style="height:13mm;" alt=""></td>
    <?php endif; ?>
    <td>
      <div class="top-org"><?= $h($doc['org_name']) ?></div>
    </td>
    <td class="top-r">
      <div class="top-type"><?= $h($doc_label) ?></div>
      <div class="top-date">Édité le <?= $h($today_long) ?></div>
    </td>
  </tr>
</table>
<div class="rule"></div>

<div class="title"><?= $h($doc['project_name']) ?></div>
<?php if ($doc_title !== '' && $doc_title !== $doc['project_name']): ?>
<div class="subtitle"><?= $h($doc_title) ?></div>
<?php else: ?>
<div style="height:5mm"></div>
<?php endif; ?>

<table class="facts">
  <tr>
    <?php foreach ($facts as [$lbl, $val]): ?>
    <td><div class="f-lbl"><?= $h($lbl) ?></div><div class="f-val"><?= $h($val) ?></div></td>
    <?php endforeach; ?>
  </tr>
</table>

<?php if ($with_dashboard): ?>
<table class="kpis">
  <tr>
    <?php foreach ($kpis as $i => [$lbl, $val, $sub, $bar]): ?>
    <?php if ($i > 0): ?><td class="kpi-gap"></td><?php endif; ?>
    <td class="kpi">
      <div class="kpi-lbl"><?= $h($lbl) ?></div>
      <div class="kpi-val"><?= $h($val) ?></div>
      <div class="kpi-sub"><?= $h($sub) ?></div>
      <?php if ($bar): ?><div class="kpi-bar"><?= $bar ?></div><?php endif; ?>
    </td>
    <?php endforeach; ?>
  </tr>
</table>

<?php if ($total_steps > 0): ?>
<div class="blk-title">Étapes du projet — <?= $done_steps ?> validée<?= $done_steps > 1 ? 's' : '' ?> sur <?= $total_steps ?></div>
<table class="steps">
  <thead><tr><th class="n">N°</th><th>Étape</th><th class="st">Statut</th><th class="when">Validée le</th><th class="who">Par</th></tr></thead>
  <tbody>
  <?php foreach ($steps as $i => $s):
      $done = !empty($s['is_completed']);
      $by = trim(($s['by_first'] ?? '') . ' ' . ($s['by_last'] ?? '')); ?>
    <tr>
      <td class="n"><?= str_pad((string)($i + 1), 2, '0', STR_PAD_LEFT) ?></td>
      <td style="color:<?= $done ? AKP_INK : AKP_TEXT ?>"><?= $h(ak_pdf_strip_emoji((string)$s['title'])) ?></td>
      <td class="st">
        <?php if ($done): ?><span class="pill pill-done">VALIDÉE</span>
        <?php elseif ($i === $current_idx): ?><span class="pill pill-cur">EN COURS</span>
        <?php else: ?><span class="pill pill-todo">À VENIR</span><?php endif; ?>
      </td>
      <td class="when"><?= $done ? $h(ak_pdf_date_fr($s['completed_at'], false)) : '—' ?></td>
      <td class="who"><?= $done && $by ? $h($by) : '—' ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php endif; ?>

<table class="duo" style="page-break-inside: avoid;">
  <tr>
    <td style="width:48%; padding-right:6mm;">
      <div class="blk-title">Budget</div>
      <?php if ($budget['planned'] > 0): ?>
      <table class="bud" style="width:100%">
        <tr><td>Budget prévu</td><td class="r"><?= $h(ak_pdf_eur($budget['planned'])) ?></td></tr>
        <tr><td>Dépenses engagées</td><td class="r"><?= $h(ak_pdf_eur($budget['used'])) ?></td></tr>
        <tr><td>Reste à engager</td><td class="r"><?= $h(ak_pdf_eur($budget['remaining'])) ?></td></tr>
        <tr><td>Taux d’exécution</td><td class="r" style="color:<?= $budget_color ?>"><?= (int)$budget['pct'] ?> %</td></tr>
      </table>
      <div class="bud-note">
        <?php if ($budget['pct'] > 100): ?>Dépassement du budget prévu : un arbitrage est nécessaire.
        <?php elseif ($budget['pct'] >= 80): ?>Budget presque entièrement engagé : à surveiller d’ici la fin du projet.
        <?php else: ?>Exécution maîtrisée au regard de l’avancement.<?php endif; ?>
      </div>
      <?php else: ?>
      <div class="bud-note">Aucun budget n’a été renseigné pour ce projet.</div>
      <?php endif; ?>
    </td>
    <td style="width:52%;">
      <div class="blk-title">Activité de l’équipe — 6 derniers mois</div>
      <?= ak_pdf_svg_activity($activity_6m) ?>
      <div class="legend">
        <span class="sw" style="color:<?= AKP_ACCENT ?>">■</span> Messages&nbsp;&nbsp;&nbsp;
        <span class="sw" style="color:<?= AKP_ACC_2 ?>">■</span> Étapes validées&nbsp;&nbsp;&nbsp;
        <span class="sw" style="color:<?= AKP_ACC_3 ?>">■</span> Fichiers déposés
      </div>
    </td>
  </tr>
</table>
<?php endif; ?>

<div class="body">
  <?= $body_html ?>
</div>

</body>
</html>
<?php
$html = ob_get_clean();

try {
    $mpdf = new \Mpdf\Mpdf([
        'mode' => 'utf-8',
        'format' => 'A4',
        'tempDir' => sys_get_temp_dir(),
        'default_font' => 'dejavusans',
        'margin_left' => 18,
        'margin_right' => 18,
        'margin_top' => 16,
        'margin_bottom' => 20,
        'margin_footer' => 9,
    ]);
    $mpdf->SetTitle($doc_label . ' — ' . $doc['project_name']);
    $mpdf->SetAuthor((string)$doc['org_name']);
    $mpdf->SetCreator('Assokit');
    $footer = '<table width="100%" style="border-top: 0.5pt solid ' . AKP_LINE . '; font-family: dejavusans; font-size: 7pt; color: ' . AKP_MUTED . ';"><tr>'
            . '<td style="padding-top: 2mm;">' . $h($doc['org_name']) . ' · ' . $h($doc['project_name']) . '</td>'
            . '<td style="padding-top: 2mm;" align="right">' . $h($doc_label) . ' · page {PAGENO} / {nbpg}</td>'
            . '</tr></table>';
    $mpdf->SetHTMLFooter($footer);
    $mpdf->WriteHTML($html);
    $safe_proj = trim(preg_replace('/[^a-zA-Z0-9_-]+/', '_', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $doc['project_name']) ?: 'projet'), '_');
    $filename = str_replace(' ', '_', $doc_label) . '_' . $safe_proj . '_' . date('Ymd') . '.pdf';
    $filename = preg_replace('/[^a-zA-Z0-9_.-]+/', '', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $filename) ?: 'Bilan.pdf');
    $mpdf->Output($filename, \Mpdf\Output\Destination::DOWNLOAD);
    exit;
} catch (Throwable $e) {
    http_response_code(500);
    die('Erreur génération PDF : ' . htmlspecialchars($e->getMessage()));
}
