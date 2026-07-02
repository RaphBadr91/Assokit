<?php
/**
 * ============================================================
 * ASSOKIT — report-charts-helper.php
 * Graphiques PDF (mPDF) pour le rapport d'activité.
 * Compatible mPDF (pas de flex/grid/JS) : KPI, barres CSS, donut SVG.
 * ============================================================
 */
if (!function_exists('ak_rc_h')) {
    function ak_rc_h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}

if (!function_exists('ak_report_collect_stats')) {
function ak_report_collect_stats(PDO $pdo, int $org_id): array {
    $s = ['members'=>0,'projects_by_status'=>[],'projects_total'=>0,'budget_planned'=>0.0,'budget_used'=>0.0,
          'participants'=>0,'grants_by_status'=>[],'grants_total'=>0,'grant_requested'=>0.0,'grant_granted'=>0.0,
          'events_total'=>0,'events_by_type'=>[]];
    try { $s['members'] = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE org_id=$org_id AND deleted_at IS NULL")->fetchColumn(); } catch (Throwable $e) {}
    try {
        foreach ($pdo->query("SELECT p.status st, COUNT(*) c, COALESCE(SUM(p.budget_planned),0) bp, COALESCE(SUM(p.budget_used),0) bu, COALESCE(SUM(p.participants_count),0) pc FROM projects p JOIN folders f ON p.folder_id=f.id WHERE f.org_id=$org_id GROUP BY p.status") as $r) {
            $s['projects_by_status'][$r['st']] = (int)$r['c']; $s['projects_total'] += (int)$r['c'];
            $s['budget_planned'] += (float)$r['bp']; $s['budget_used'] += (float)$r['bu']; $s['participants'] += (int)$r['pc'];
        }
    } catch (Throwable $e) {}
    try {
        foreach ($pdo->query("SELECT COALESCE(status,'?') st, COUNT(*) c, COALESCE(SUM(amount_requested),0) req, COALESCE(SUM(amount_granted),0) acc FROM grants WHERE org_id=$org_id GROUP BY status") as $r) {
            $s['grants_by_status'][$r['st']] = (int)$r['c']; $s['grants_total'] += (int)$r['c'];
            $s['grant_requested'] += (float)$r['req']; $s['grant_granted'] += (float)$r['acc'];
        }
    } catch (Throwable $e) {}
    try {
        $s['events_total'] = (int)$pdo->query("SELECT COUNT(*) FROM events WHERE org_id=$org_id AND deleted_at IS NULL")->fetchColumn();
        foreach ($pdo->query("SELECT event_type t, COUNT(*) c FROM events WHERE org_id=$org_id AND deleted_at IS NULL GROUP BY event_type ORDER BY c DESC") as $r) { $s['events_by_type'][$r['t']] = (int)$r['c']; }
    } catch (Throwable $e) {}
    return $s;
}
}

if (!function_exists('ak_fmt_eur')) {
function ak_fmt_eur(float $n): string {
    if ($n >= 1000) return number_format($n/1000, $n >= 10000 ? 0 : 1, ',', ' ') . ' k€';
    return number_format($n, 0, ',', ' ') . ' €';
}
}

if (!function_exists('ak_svg_donut')) {
function ak_svg_donut(array $segments, int $size = 120): string {
    $total = 0; foreach ($segments as $s) { $total += max(0, (float)$s['value']); }
    if ($total <= 0) return '';
    $cx = $size/2; $cy = $size/2; $r = $size/2 - 2; $rin = $r * 0.56;
    $angle = -90.0; $paths = '';
    foreach ($segments as $seg) {
        $v = max(0, (float)$seg['value']); if ($v <= 0) continue;
        $frac = $v/$total; $sweep = $frac*360.0;
        $steps = ($frac >= 0.999) ? [[$angle,$angle+180],[$angle+180,$angle+360]] : [[$angle,$angle+$sweep]];
        foreach ($steps as $st) {
            $a0 = deg2rad($st[0]); $a1 = deg2rad($st[1]);
            $x0 = round($cx+$r*cos($a0),2); $y0 = round($cy+$r*sin($a0),2);
            $x1 = round($cx+$r*cos($a1),2); $y1 = round($cy+$r*sin($a1),2);
            $xi1 = round($cx+$rin*cos($a1),2); $yi1 = round($cy+$rin*sin($a1),2);
            $xi0 = round($cx+$rin*cos($a0),2); $yi0 = round($cy+$rin*sin($a0),2);
            $large = (($st[1]-$st[0]) > 180) ? 1 : 0;
            $paths .= '<path d="M '.$x0.' '.$y0.' A '.$r.' '.$r.' 0 '.$large.' 1 '.$x1.' '.$y1.' L '.$xi1.' '.$yi1.' A '.$rin.' '.$rin.' 0 '.$large.' 0 '.$xi0.' '.$yi0.' Z" fill="'.$seg['color'].'"/>';
        }
        $angle += $sweep;
    }
    return '<svg width="'.$size.'" height="'.$size.'" viewBox="0 0 '.$size.' '.$size.'">'.$paths.'</svg>';
}
}

if (!function_exists('ak_legend')) {
function ak_legend(array $segments): string {
    $rows = '';
    foreach ($segments as $seg) {
        if ((float)$seg['value'] <= 0) continue;
        $rows .= '<tr><td style="width:12pt;"><div style="width:8pt;height:8pt;background:'.$seg['color'].';border-radius:2pt;">&nbsp;</div></td>'
            . '<td style="font-size:8.5pt;color:#3F3F46;">'.ak_rc_h($seg['label']).'</td>'
            . '<td style="font-size:8.5pt;font-weight:700;color:#0A0A0B;text-align:right;">'.(int)$seg['value'].'</td></tr>';
    }
    return '<table style="width:100%;">'.$rows.'</table>';
}
}

if (!function_exists('ak_bar_row')) {
function ak_bar_row(string $label, int $value, int $max, string $color): string {
    $pct = $max > 0 ? max(2, round($value/$max*100)) : 2;
    return '<table style="width:100%;margin-bottom:5pt;"><tr>'
        . '<td style="width:34%;font-size:9pt;color:#3F3F46;vertical-align:middle;">'.ak_rc_h($label).'</td>'
        . '<td style="width:54%;vertical-align:middle;"><div style="background:#F1F5F9;border-radius:3pt;"><div style="background:'.$color.';width:'.$pct.'%;height:10pt;border-radius:3pt;">&nbsp;</div></div></td>'
        . '<td style="width:12%;text-align:right;font-size:9.5pt;font-weight:700;color:#0A0A0B;vertical-align:middle;">'.$value.'</td></tr></table>';
}
}

if (!function_exists('ak_kpi_cards')) {
function ak_kpi_cards(array $kpis): string {
    $tds = '';
    foreach ($kpis as $k) {
        $tds .= '<td style="width:25%;padding:0 3pt;"><div style="background:'.$k['bg'].';border-radius:8pt;padding:11pt 6pt;text-align:center;">'
            . '<div style="font-size:19pt;font-weight:700;color:'.$k['color'].';line-height:1;">'.ak_rc_h($k['value']).'</div>'
            . '<div style="font-size:7pt;color:#52525B;text-transform:uppercase;letter-spacing:0.04em;margin-top:5pt;">'.ak_rc_h($k['label']).'</div></div></td>';
    }
    return '<table style="width:100%;margin-bottom:16pt;"><tr>'.$tds.'</tr></table>';
}
}

if (!function_exists('ak_report_dashboard')) {
function ak_report_dashboard(PDO $pdo, int $org_id): string {
    $s = ak_report_collect_stats($pdo, $org_id);
    $kpis = [
        ['value'=>$s['members'],'label'=>'Adhérents actifs','color'=>'#059669','bg'=>'#F0FDF4'],
        ['value'=>$s['projects_total'],'label'=>'Projets menés','color'=>'#2563EB','bg'=>'#EFF6FF'],
        ['value'=>ak_fmt_eur($s['grant_granted']),'label'=>'Subventions obtenues','color'=>'#7C3AED','bg'=>'#F5F3FF'],
        ['value'=>$s['events_total'],'label'=>'Événements','color'=>'#D97706','bg'=>'#FFFBEB'],
    ];
    $kpi_html = ak_kpi_cards($kpis);

    $grant_labels = ['granted'=>'Obtenues','in_review'=>'En cours','submitted'=>'Soumises','rejected'=>'Refusées','draft'=>'Brouillon'];
    $grant_colors = ['granted'=>'#10B981','in_review'=>'#3B82F6','submitted'=>'#F59E0B','rejected'=>'#94A3B8','draft'=>'#D1D5DB'];
    $grant_segments = [];
    foreach ($s['grants_by_status'] as $st => $c) { $grant_segments[] = ['label'=>$grant_labels[$st] ?? $st,'value'=>$c,'color'=>$grant_colors[$st] ?? '#CBD5E1']; }
    $donut = ak_svg_donut($grant_segments, 118);
    $legend = ak_legend($grant_segments);

    $proj_labels = ['active'=>'Actifs','done'=>'Terminés','warning'=>'En vigilance','draft'=>'Brouillon','archived'=>'Archivés'];
    $proj_colors = ['active'=>'#10B981','done'=>'#3B82F6','warning'=>'#F59E0B','draft'=>'#A78BFA','archived'=>'#94A3B8'];
    $pmax = $s['projects_by_status'] ? max($s['projects_by_status']) : 1;
    $proj_bars = '';
    foreach (['active','done','warning','draft','archived'] as $st) {
        if (!empty($s['projects_by_status'][$st])) { $proj_bars .= ak_bar_row($proj_labels[$st], $s['projects_by_status'][$st], $pmax, $proj_colors[$st]); }
    }

    $bmax = max($s['budget_planned'], $s['budget_used'], 1);
    $budget_html = '<div style="font-size:9pt;color:#3F3F46;margin-bottom:4pt;">Budget prévisionnel <strong style="color:#0A0A0B;">'.ak_fmt_eur($s['budget_planned']).'</strong></div>'
        . '<div style="background:#F1F5F9;border-radius:3pt;margin-bottom:8pt;"><div style="background:#2563EB;width:'.max(2,round($s['budget_planned']/$bmax*100)).'%;height:9pt;border-radius:3pt;">&nbsp;</div></div>'
        . '<div style="font-size:9pt;color:#3F3F46;margin-bottom:4pt;">Budget engagé <strong style="color:#0A0A0B;">'.ak_fmt_eur($s['budget_used']).'</strong></div>'
        . '<div style="background:#F1F5F9;border-radius:3pt;"><div style="background:#10B981;width:'.max(2,round($s['budget_used']/$bmax*100)).'%;height:9pt;border-radius:3pt;">&nbsp;</div></div>';

    $section_title = '<div style="font-size:13pt;font-weight:700;color:#059669;border-bottom:2pt solid #D1FAE5;padding-bottom:5pt;margin:6pt 0 14pt;">Tableau de bord de l\'activité</div>';

    $two_cols = '<table style="width:100%;margin-bottom:16pt;"><tr>'
        . '<td style="width:42%;vertical-align:top;padding-right:10pt;"><div style="font-size:9pt;font-weight:700;color:#3F3F46;text-transform:uppercase;letter-spacing:0.05em;margin-bottom:8pt;">Subventions ('.$s['grants_total'].')</div>'
        . '<table style="width:100%;"><tr><td style="width:120pt;vertical-align:middle;">'.$donut.'</td><td style="vertical-align:middle;padding-left:6pt;">'.$legend.'</td></tr></table></td>'
        . '<td style="width:58%;vertical-align:top;padding-left:10pt;border-left:0.5pt solid #E5E7EB;"><div style="font-size:9pt;font-weight:700;color:#3F3F46;text-transform:uppercase;letter-spacing:0.05em;margin-bottom:8pt;">Projets par statut ('.$s['projects_total'].')</div>'.$proj_bars.'</td>'
        . '</tr></table>';

    $budget_block = '<table style="width:100%;margin-bottom:18pt;"><tr>'
        . '<td style="width:60%;vertical-align:top;padding-right:14pt;"><div style="font-size:9pt;font-weight:700;color:#3F3F46;text-transform:uppercase;letter-spacing:0.05em;margin-bottom:8pt;">Budget des projets</div>'.$budget_html.'</td>'
        . '<td style="width:40%;vertical-align:top;padding-left:14pt;border-left:0.5pt solid #E5E7EB;"><div style="font-size:9pt;font-weight:700;color:#3F3F46;text-transform:uppercase;letter-spacing:0.05em;margin-bottom:8pt;">En un coup d\'œil</div>'
        . '<div style="font-size:9pt;color:#3F3F46;line-height:1.7;"><strong style="color:#0A0A0B;">'.number_format((float)$s['participants'],0,',',' ').'</strong> participants cumulés<br>'
        . '<strong style="color:#0A0A0B;">'.ak_fmt_eur($s['grant_requested']).'</strong> de subventions demandées<br>'
        . 'Taux de réussite : <strong style="color:#0A0A0B;">'.($s['grants_total'] ? round(($s['grants_by_status']['granted'] ?? 0)/$s['grants_total']*100) : 0).'%</strong></div></td>'
        . '</tr></table>';

    return '<div class="rep-dashboard">'.$section_title.$kpi_html.$two_cols.$budget_block.'</div>';
}
}
