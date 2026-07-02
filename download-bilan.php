<?php
/**
 * ============================================================
 * ASSOKIT — Téléchargement PDF d'un document IA généré
 * ============================================================
 * GET /download-bilan.php?id=NN
 *
 * v2 — Avec graphiques SVG analytiques :
 *   - Donut d'avancement (étapes)
 *   - Barre budget prévu vs dépensé
 *   - Timeline chronologique des étapes
 *   - Histogramme activité 6 mois
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
           o.name AS org_name, o.id AS org_id, o.logo_path,
           u.first_name AS author_first, u.last_name AS author_last
    FROM ai_generated_docs g
    JOIN projects p ON p.id = g.project_id
    JOIN folders f ON f.id = p.folder_id
    JOIN organizations o ON o.id = f.org_id
    LEFT JOIN users u ON u.id = g.user_id
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
// 📊 ANALYTICS POUR GRAPHIQUES
// ============================================================

function ak_pdf_load_steps(PDO $pdo, int $project_id): array {
    $stmt = $pdo->prepare("SELECT id, title, is_completed, completed_at, position FROM project_steps WHERE project_id = ? ORDER BY position ASC, id ASC");
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
    $pct = $planned > 0 ? min(100, round(($used / $planned) * 100, 1)) : 0;
    return ['planned' => $planned, 'used' => $used, 'pct' => $pct, 'remaining' => max(0, $planned - $used)];
}

function ak_pdf_load_activity_6m(PDO $pdo, int $project_id): array {
    $months = [];
    for ($i = 5; $i >= 0; $i--) {
        $key = date('Y-m', strtotime("-$i months"));
        $months[$key] = ['key' => $key, 'label' => '', 'msg' => 0, 'step' => 0, 'file' => 0, 'total' => 0];
    }
    $mois_short = ['', 'Jan','Fév','Mar','Avr','Mai','Juin','Juil','Aoû','Sep','Oct','Nov','Déc'];
    foreach ($months as $k => &$m) {
        list($y, $mo) = explode('-', $k);
        $m['label'] = $mois_short[(int)$mo] . ' ' . substr($y, 2);
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

$steps = ak_pdf_load_steps($pdo, $project_id);
$total_steps = count($steps);
$done_steps = 0;
foreach ($steps as $s) if ($s['is_completed']) $done_steps++;
$progress_pct = $total_steps > 0 ? round(($done_steps / $total_steps) * 100) : (int)$doc['progress_percent'];
$budget = ak_pdf_load_budget($pdo, $project_id, $doc);
$activity_6m = ak_pdf_load_activity_6m($pdo, $project_id);

// ============================================================
// 🎨 GÉNÉRATEURS SVG
// ============================================================

function ak_pdf_svg_donut(int $pct, int $done, int $total): string {
    $r = 50; $cx = 70; $cy = 70; $sw = 12;
    $circ = 2 * M_PI * $r;
    $offset = $circ - ($pct / 100) * $circ;
    $color = $pct >= 75 ? '#10B981' : ($pct >= 40 ? '#3B82F6' : '#F59E0B');
    $label_done = htmlspecialchars($done . ' / ' . $total, ENT_QUOTES);
    return '<svg width="140" height="140" viewBox="0 0 140 140" xmlns="http://www.w3.org/2000/svg">'
         . '<circle cx="' . $cx . '" cy="' . $cy . '" r="' . $r . '" fill="none" stroke="#F3F4F6" stroke-width="' . $sw . '"/>'
         . '<circle cx="' . $cx . '" cy="' . $cy . '" r="' . $r . '" fill="none" stroke="' . $color . '" stroke-width="' . $sw . '" '
         . 'stroke-linecap="round" stroke-dasharray="' . round($circ, 2) . '" stroke-dashoffset="' . round($offset, 2) . '" '
         . 'transform="rotate(-90 ' . $cx . ' ' . $cy . ')"/>'
         . '<text x="' . $cx . '" y="' . ($cy + 4) . '" text-anchor="middle" font-family="DejaVu Sans" font-size="22" font-weight="bold" fill="#111827">' . $pct . '%</text>'
         . '<text x="' . $cx . '" y="' . ($cy + 22) . '" text-anchor="middle" font-family="DejaVu Sans" font-size="10" fill="#6B7280">' . $label_done . '</text>'
         . '</svg>';
}

function ak_pdf_svg_budget(array $b): string {
    if ($b['planned'] <= 0) {
        return '<div style="font-size:10pt;color:#6b7280;font-style:italic;padding:10px 0;">Aucun budget défini pour ce projet.</div>';
    }
    $pct = $b['pct'];
    $color = $pct >= 90 ? '#EF4444' : ($pct >= 70 ? '#F59E0B' : '#10B981');
    $bar_w = 360; $bar_h = 22;
    $fill_w = round(($pct / 100) * $bar_w);
    $fmt = function($n) {
        if ($n >= 1000) return number_format($n / 1000, 1, ',', ' ') . ' k€';
        return number_format($n, 0, ',', ' ') . ' €';
    };
    return '<svg width="' . $bar_w . '" height="46" viewBox="0 0 ' . $bar_w . ' 46" xmlns="http://www.w3.org/2000/svg">'
         . '<rect x="0" y="14" width="' . $bar_w . '" height="' . $bar_h . '" rx="6" fill="#F3F4F6"/>'
         . '<rect x="0" y="14" width="' . $fill_w . '" height="' . $bar_h . '" rx="6" fill="' . $color . '"/>'
         . '<text x="' . ($fill_w > 50 ? $fill_w - 6 : $fill_w + 6) . '" y="29" '
         . 'text-anchor="' . ($fill_w > 50 ? 'end' : 'start') . '" font-family="DejaVu Sans" font-size="10" font-weight="bold" '
         . 'fill="' . ($fill_w > 50 ? '#FFFFFF' : '#111827') . '">' . $pct . ' %</text>'
         . '<text x="0" y="10" font-family="DejaVu Sans" font-size="9" font-weight="bold" fill="#6B7280">DÉPENSÉ</text>'
         . '<text x="' . $bar_w . '" y="10" text-anchor="end" font-family="DejaVu Sans" font-size="9" font-weight="bold" fill="#6B7280">BUDGET PRÉVU</text>'
         . '<text x="0" y="44" font-family="DejaVu Sans" font-size="11" font-weight="bold" fill="#111827">' . htmlspecialchars($fmt($b['used']), ENT_QUOTES) . '</text>'
         . '<text x="' . $bar_w . '" y="44" text-anchor="end" font-family="DejaVu Sans" font-size="11" font-weight="bold" fill="#111827">' . htmlspecialchars($fmt($b['planned']), ENT_QUOTES) . '</text>'
         . '</svg>';
}

function ak_pdf_svg_timeline(array $steps): string {
    $n = count($steps);
    if ($n === 0) {
        return '<div style="font-size:10pt;color:#6b7280;font-style:italic;padding:10px 0;">Aucune étape définie.</div>';
    }
    $w = 480; $h = 80;
    $pad = 30;
    $usable = $w - 2 * $pad;
    $step_x = $n > 1 ? $usable / ($n - 1) : 0;
    $svg = '<svg width="' . $w . '" height="' . $h . '" viewBox="0 0 ' . $w . ' ' . $h . '" xmlns="http://www.w3.org/2000/svg">';
    $svg .= '<line x1="' . $pad . '" y1="32" x2="' . ($w - $pad) . '" y2="32" stroke="#E5E7EB" stroke-width="2"/>';
    $last_done = -1;
    foreach ($steps as $i => $s) if ($s['is_completed']) $last_done = $i;
    if ($last_done >= 0) {
        $end_x = $pad + ($last_done * $step_x);
        $svg .= '<line x1="' . $pad . '" y1="32" x2="' . round($end_x) . '" y2="32" stroke="#10B981" stroke-width="3"/>';
    }
    foreach ($steps as $i => $s) {
        $x = $pad + ($i * $step_x);
        $done = !empty($s['is_completed']);
        $color = $done ? '#10B981' : '#D1D5DB';
        $svg .= '<circle cx="' . round($x) . '" cy="32" r="7" fill="#fff" stroke="' . $color . '" stroke-width="2.5"/>';
        if ($done) $svg .= '<circle cx="' . round($x) . '" cy="32" r="3.5" fill="' . $color . '"/>';
        $svg .= '<text x="' . round($x) . '" y="18" text-anchor="middle" font-family="DejaVu Sans" font-size="8" font-weight="bold" fill="#6B7280">' . ($i + 1) . '</text>';
        $label = mb_substr($s['title'], 0, 12);
        if (mb_strlen($s['title']) > 12) $label .= '…';
        $svg .= '<text x="' . round($x) . '" y="55" text-anchor="middle" font-family="DejaVu Sans" font-size="7" fill="' . ($done ? '#065F46' : '#9CA3AF') . '">' . htmlspecialchars($label, ENT_QUOTES) . '</text>';
        if ($done && !empty($s['completed_at'])) {
            $d = date('d/m', strtotime($s['completed_at']));
            $svg .= '<text x="' . round($x) . '" y="68" text-anchor="middle" font-family="DejaVu Sans" font-size="6.5" fill="#6B7280">' . $d . '</text>';
        }
    }
    return $svg . '</svg>';
}

function ak_pdf_svg_activity(array $months): string {
    $w = 480; $h = 130;
    $pad_l = 32; $pad_b = 28; $pad_t = 14; $pad_r = 8;
    $chart_w = $w - $pad_l - $pad_r;
    $chart_h = $h - $pad_b - $pad_t;
    $max = 1;
    foreach ($months as $m) if ($m['total'] > $max) $max = $m['total'];
    $bar_w = ($chart_w / count($months)) * 0.62;
    $gap = ($chart_w / count($months)) - $bar_w;
    $svg = '<svg width="' . $w . '" height="' . $h . '" viewBox="0 0 ' . $w . ' ' . $h . '" xmlns="http://www.w3.org/2000/svg">';
    for ($g = 0; $g <= 4; $g++) {
        $y = $pad_t + ($chart_h * $g / 4);
        $val = round($max * (4 - $g) / 4);
        $svg .= '<line x1="' . $pad_l . '" y1="' . $y . '" x2="' . ($w - $pad_r) . '" y2="' . $y . '" stroke="#F3F4F6" stroke-width="0.8" stroke-dasharray="2 3"/>';
        $svg .= '<text x="' . ($pad_l - 4) . '" y="' . ($y + 3) . '" text-anchor="end" font-family="DejaVu Sans" font-size="7" fill="#9CA3AF">' . $val . '</text>';
    }
    foreach ($months as $i => $m) {
        $x = $pad_l + ($i * ($bar_w + $gap)) + ($gap / 2);
        $y_base = $pad_t + $chart_h;
        $h_msg = $max > 0 ? ($m['msg'] / $max) * $chart_h : 0;
        $h_step = $max > 0 ? ($m['step'] / $max) * $chart_h : 0;
        $h_file = $max > 0 ? ($m['file'] / $max) * $chart_h : 0;
        $y_msg = $y_base - $h_msg;
        $y_step = $y_msg - $h_step;
        $y_file = $y_step - $h_file;
        if ($h_msg > 0) $svg .= '<rect x="' . round($x) . '" y="' . round($y_msg) . '" width="' . round($bar_w) . '" height="' . round($h_msg) . '" fill="#3B82F6" rx="1"/>';
        if ($h_step > 0) $svg .= '<rect x="' . round($x) . '" y="' . round($y_step) . '" width="' . round($bar_w) . '" height="' . round($h_step) . '" fill="#10B981" rx="1"/>';
        if ($h_file > 0) $svg .= '<rect x="' . round($x) . '" y="' . round($y_file) . '" width="' . round($bar_w) . '" height="' . round($h_file) . '" fill="#8B5CF6" rx="1"/>';
        if ($m['total'] > 0) {
            $svg .= '<text x="' . round($x + $bar_w / 2) . '" y="' . round($y_file - 3) . '" text-anchor="middle" font-family="DejaVu Sans" font-size="7" font-weight="bold" fill="#374151">' . $m['total'] . '</text>';
        }
        $svg .= '<text x="' . round($x + $bar_w / 2) . '" y="' . ($h - 14) . '" text-anchor="middle" font-family="DejaVu Sans" font-size="8" fill="#6B7280">' . htmlspecialchars($m['label'], ENT_QUOTES) . '</text>';
    }
    $lg_y = $h - 4;
    $svg .= '<rect x="' . $pad_l . '" y="' . ($lg_y - 7) . '" width="8" height="8" fill="#3B82F6" rx="1"/>';
    $svg .= '<text x="' . ($pad_l + 11) . '" y="' . ($lg_y - 1) . '" font-family="DejaVu Sans" font-size="7.5" fill="#374151">Messages</text>';
    $svg .= '<rect x="' . ($pad_l + 64) . '" y="' . ($lg_y - 7) . '" width="8" height="8" fill="#10B981" rx="1"/>';
    $svg .= '<text x="' . ($pad_l + 75) . '" y="' . ($lg_y - 1) . '" font-family="DejaVu Sans" font-size="7.5" fill="#374151">Étapes</text>';
    $svg .= '<rect x="' . ($pad_l + 116) . '" y="' . ($lg_y - 7) . '" width="8" height="8" fill="#8B5CF6" rx="1"/>';
    $svg .= '<text x="' . ($pad_l + 127) . '" y="' . ($lg_y - 1) . '" font-family="DejaVu Sans" font-size="7.5" fill="#374151">Fichiers</text>';
    return $svg . '</svg>';
}

// ============================================================
// 📝 Markdown → HTML
// ============================================================
function ak_md_to_html(string $md): string {
    $lines = explode("\n", $md);
    $html = ''; $in_list = false; $in_para = false; $pending = '';
    $flush_para = function() use (&$pending, &$html, &$in_para) {
        if ($in_para && trim($pending) !== '') $html .= '<p>' . trim($pending) . '</p>';
        $pending = ''; $in_para = false;
    };
    $close_list = function() use (&$in_list, &$html) {
        if ($in_list) { $html .= '</ul>'; $in_list = false; }
    };
    foreach ($lines as $line) {
        $trimmed = rtrim($line);
        $process_inline = function($text) {
            $text = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
            $text = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $text);
            $text = preg_replace('/(?<![\*\w])\*(?!\s)([^\*]+?)\*(?!\w)/', '<em>$1</em>', $text);
            $text = preg_replace('/`([^`]+)`/', '<code>$1</code>', $text);
            return $text;
        };
        if (preg_match('/^##\s+(.+)$/', $trimmed, $m)) { $flush_para(); $close_list(); $html .= '<h2>' . $process_inline($m[1]) . '</h2>'; continue; }
        if (preg_match('/^###\s+(.+)$/', $trimmed, $m)) { $flush_para(); $close_list(); $html .= '<h3>' . $process_inline($m[1]) . '</h3>'; continue; }
        if (preg_match('/^#\s+(.+)$/', $trimmed, $m)) { $flush_para(); $close_list(); $html .= '<h1>' . $process_inline($m[1]) . '</h1>'; continue; }
        if (preg_match('/^---+$/', $trimmed)) { $flush_para(); $close_list(); $html .= '<hr/>'; continue; }
        if (preg_match('/^[-*]\s+(.+)$/', $trimmed, $m)) {
            $flush_para();
            if (!$in_list) { $html .= '<ul>'; $in_list = true; }
            $html .= '<li>' . $process_inline($m[1]) . '</li>'; continue;
        }
        if (preg_match('/^\d+\.\s+(.+)$/', $trimmed, $m)) {
            $flush_para();
            if (!$in_list) { $html .= '<ul>'; $in_list = true; }
            $html .= '<li>' . $process_inline($m[1]) . '</li>'; continue;
        }
        if ($trimmed === '') { $flush_para(); $close_list(); continue; }
        $close_list();
        if ($in_para) $pending .= ' ' . $process_inline($trimmed);
        else { $pending = $process_inline($trimmed); $in_para = true; }
    }
    $flush_para(); $close_list();
    return $html;
}

$body_html = ak_md_to_html($doc['content']);

$today_fr = date('d/m/Y');
$author_full = trim(($doc['author_first'] ?? '') . ' ' . ($doc['author_last'] ?? '')) ?: 'AssoKit IA';
$logo_full_path = '';
if (!empty($doc['logo_path'])) {
    $candidate = __DIR__ . $doc['logo_path'];
    if (file_exists($candidate)) $logo_full_path = $candidate;
}
$h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

$svg_donut = ak_pdf_svg_donut($progress_pct, $done_steps, $total_steps);
$svg_budget = ak_pdf_svg_budget($budget);
$svg_timeline = ak_pdf_svg_timeline($steps);
$svg_activity = ak_pdf_svg_activity($activity_6m);

$has_activity = false;
foreach ($activity_6m as $m) if ($m['total'] > 0) { $has_activity = true; break; }

ob_start();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<style>
@page { margin: 28mm 18mm 22mm 18mm; }
body { font-family: 'DejaVu Sans', sans-serif; font-size: 10.5pt; line-height: 1.55; color: #1f2937; }
.pdf-head { padding-bottom: 14px; border-bottom: 2px solid #10B981; margin-bottom: 18px; }
.pdf-head-row { width: 100%; display: table; }
.pdf-head-l { display: table-cell; vertical-align: middle; width: 70%; }
.pdf-head-r { display: table-cell; vertical-align: middle; text-align: right; width: 30%; }
.pdf-logo { max-width: 110px; max-height: 50px; }
.pdf-org { font-size: 9pt; color: #6b7280; text-transform: uppercase; letter-spacing: 0.05em; font-weight: bold; }
.pdf-doctitle { font-size: 18pt; font-weight: bold; color: #111827; margin: 4px 0 0; }
.pdf-projname { font-size: 11pt; color: #4b5563; margin-top: 2px; }
.pdf-meta-tag { display: inline-block; padding: 4px 10px; background: #ECFDF5; color: #065F46; font-size: 9pt; font-weight: bold; border-radius: 4px; }
.pdf-meta-date { font-size: 9pt; color: #6b7280; margin-top: 4px; }
.pdf-meta-row { display: table; width: 100%; margin: 14px 0 18px; padding: 10px 14px; background: #F9FAFB; border-radius: 6px; border-left: 3px solid #10B981; }
.pdf-meta-cell { display: table-cell; padding: 0 8px; font-size: 9pt; }
.pdf-meta-lbl { color: #6b7280; text-transform: uppercase; letter-spacing: 0.04em; font-size: 8pt; font-weight: bold; }
.pdf-meta-val { color: #111827; font-weight: bold; margin-top: 2px; }

.pdf-charts { margin: 0 0 22px; padding: 16px 18px; background: #F9FAFB; border-radius: 8px; border: 1px solid #E5E7EB; }
.pdf-charts-title { font-size: 11pt; font-weight: bold; color: #065F46; margin: 0 0 14px; padding-bottom: 6px; border-bottom: 1px solid #D1FAE5; }
.pdf-chart-row { display: table; width: 100%; margin-bottom: 16px; }
.pdf-chart-cell { display: table-cell; vertical-align: middle; }
.pdf-chart-cell-l { width: 35%; padding-right: 14px; text-align: center; }
.pdf-chart-cell-r { width: 65%; vertical-align: middle; }
.pdf-chart-block { margin-bottom: 16px; }
.pdf-chart-block:last-child { margin-bottom: 0; }
.pdf-chart-lbl { font-size: 9pt; font-weight: bold; color: #374151; margin-bottom: 6px; text-transform: uppercase; letter-spacing: 0.04em; }
.pdf-chart-help { font-size: 8.5pt; color: #6b7280; margin-top: 4px; line-height: 1.4; }

.pdf-body h1 { font-size: 16pt; color: #111827; margin: 18px 0 10px; padding-bottom: 4px; border-bottom: 1px solid #e5e7eb; }
.pdf-body h2 { font-size: 13pt; color: #065F46; margin: 16px 0 8px; padding: 6px 0 6px 10px; border-left: 3px solid #10B981; background: #F0FDF4; }
.pdf-body h3 { font-size: 11pt; color: #1f2937; margin: 12px 0 6px; font-weight: bold; }
.pdf-body p { margin: 0 0 8px; text-align: justify; }
.pdf-body ul { margin: 4px 0 10px; padding-left: 18px; }
.pdf-body li { margin: 2px 0; }
.pdf-body strong { color: #111827; }
.pdf-body code { font-family: 'DejaVu Sans Mono', monospace; background: #F3F4F6; padding: 1px 4px; border-radius: 3px; font-size: 9.5pt; }
.pdf-body hr { border: 0; border-top: 1px dashed #d1d5db; margin: 16px 0; }
</style>
</head>
<body>

<div class="pdf-head">
  <div class="pdf-head-row">
    <div class="pdf-head-l">
      <?php if ($logo_full_path): ?>
        <img src="<?= $h($logo_full_path) ?>" class="pdf-logo" alt="Logo">
      <?php else: ?>
        <div class="pdf-org"><?= $h($doc['org_name']) ?></div>
      <?php endif; ?>
      <div class="pdf-doctitle"><?= $h($doc['title']) ?></div>
      <div class="pdf-projname"><?= $h($doc['project_name']) ?></div>
    </div>
    <div class="pdf-head-r">
      <div class="pdf-meta-tag">📋 BILAN</div>
      <div class="pdf-meta-date">Édité le <?= $h($today_fr) ?></div>
    </div>
  </div>
</div>

<div class="pdf-meta-row">
  <div class="pdf-meta-cell">
    <div class="pdf-meta-lbl">Association</div>
    <div class="pdf-meta-val"><?= $h($doc['org_name']) ?></div>
  </div>
  <?php if (!empty($doc['project_location'])): ?>
  <div class="pdf-meta-cell">
    <div class="pdf-meta-lbl">Lieu</div>
    <div class="pdf-meta-val"><?= $h($doc['project_location']) ?></div>
  </div>
  <?php endif; ?>
  <?php if (!empty($doc['start_date'])): ?>
  <div class="pdf-meta-cell">
    <div class="pdf-meta-lbl">Démarrage</div>
    <div class="pdf-meta-val"><?= $h(date('d/m/Y', strtotime($doc['start_date']))) ?></div>
  </div>
  <?php endif; ?>
  <div class="pdf-meta-cell">
    <div class="pdf-meta-lbl">Auteur</div>
    <div class="pdf-meta-val"><?= $h($author_full) ?></div>
  </div>
</div>

<div class="pdf-charts">
  <div class="pdf-charts-title">📊 Synthèse visuelle du projet</div>

  <div class="pdf-chart-row">
    <div class="pdf-chart-cell pdf-chart-cell-l">
      <div class="pdf-chart-lbl">Avancement</div>
      <?= $svg_donut ?>
    </div>
    <div class="pdf-chart-cell pdf-chart-cell-r">
      <div class="pdf-chart-lbl">Budget</div>
      <?= $svg_budget ?>
      <?php if ($budget['planned'] > 0): ?>
      <div class="pdf-chart-help">
        <?php if ($budget['pct'] >= 90): ?>
          ⚠️ Budget proche de la limite — vigilance recommandée.
        <?php elseif ($budget['pct'] >= 70): ?>
          Budget bien engagé — reste à dépenser mesuré.
        <?php else: ?>
          Budget maîtrisé — marge confortable.
        <?php endif; ?>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($total_steps > 0): ?>
  <div class="pdf-chart-block">
    <div class="pdf-chart-lbl">Timeline des étapes (<?= $done_steps ?> validées sur <?= $total_steps ?>)</div>
    <?= $svg_timeline ?>
  </div>
  <?php endif; ?>

  <?php if ($has_activity): ?>
  <div class="pdf-chart-block">
    <div class="pdf-chart-lbl">Activité du projet — 6 derniers mois</div>
    <?= $svg_activity ?>
  </div>
  <?php endif; ?>
</div>

<div class="pdf-body">
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
        'default_font' => 'DejaVuSans',
        'margin_top' => 18,
        'margin_bottom' => 14,
    ]);
    $footer = '<table width="100%" style="border-top: 1px solid #e5e7eb; padding-top: 4px; font-size: 8pt; color: #9CA3AF;"><tr>'
            . '<td>' . $h($doc['org_name']) . ' · ' . $h($doc['project_name']) . '</td>'
            . '<td align="right">{PAGENO} / {nbpg}</td>'
            . '</tr></table>';
    $mpdf->SetHTMLFooter($footer);
    $mpdf->WriteHTML($html);
    $safe_proj = preg_replace('/[^a-zA-Z0-9_-]/', '_', $doc['project_name']);
    $safe_title = preg_replace('/[^a-zA-Z0-9_-]/', '_', $doc['title']);
    $filename = 'Bilan_' . $safe_proj . '_' . $safe_title . '_' . date('Ymd') . '.pdf';
    $mpdf->Output($filename, \Mpdf\Output\Destination::DOWNLOAD);
    exit;
} catch (Throwable $e) {
    http_response_code(500);
    die('Erreur génération PDF : ' . htmlspecialchars($e->getMessage()));
}
