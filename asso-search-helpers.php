<?php
/**
 * asso-search-helpers.php
 * Helpers communs pour recherche, filtres période, tags
 */

if (!function_exists('ak_search_period_dates')) {

/**
 * Renvoie [start, end] selon la période demandée
 */
function ak_search_period_dates(string $period, ?string $custom_from = null, ?string $custom_to = null): array {
    $now = time();
    switch ($period) {
        case 'this_month':
            return [date('Y-m-01 00:00:00', $now), date('Y-m-t 23:59:59', $now)];
        case 'last_month':
            $lm = strtotime('first day of last month', $now);
            return [date('Y-m-01 00:00:00', $lm), date('Y-m-t 23:59:59', $lm)];
        case 'this_quarter':
            $q = ceil(date('n', $now) / 3);
            $start_month = ($q - 1) * 3 + 1;
            return [date(sprintf('Y-%02d-01 00:00:00', $start_month)), date(sprintf('Y-%02d-t 23:59:59', $start_month + 2))];
        case 'this_year':
            return [date('Y-01-01 00:00:00', $now), date('Y-12-31 23:59:59', $now)];
        case 'last_year':
            $ly = (int)date('Y') - 1;
            return ["$ly-01-01 00:00:00", "$ly-12-31 23:59:59"];
        case 'last_30':
            return [date('Y-m-d 00:00:00', strtotime('-30 days', $now)), date('Y-m-d 23:59:59', $now)];
        case 'last_90':
            return [date('Y-m-d 00:00:00', strtotime('-90 days', $now)), date('Y-m-d 23:59:59', $now)];
        case 'custom':
            return [
                $custom_from ? $custom_from . ' 00:00:00' : null,
                $custom_to ? $custom_to . ' 23:59:59' : null,
            ];
        case 'all':
        default:
            return [null, null];
    }
}

function ak_search_period_label(string $period): string {
    return [
        'all' => 'Tout',
        'this_month' => 'Ce mois',
        'last_month' => 'Mois dernier',
        'this_quarter' => 'Ce trimestre',
        'this_year' => 'Cette année',
        'last_year' => 'Année dernière',
        'last_30' => '30 derniers jours',
        'last_90' => '90 derniers jours',
        'custom' => 'Personnalisé',
    ][$period] ?? 'Tout';
}

function ak_search_render_period_chips(string $current = 'all'): string {
    $periods = ['all','this_month','last_month','this_quarter','this_year','last_30','custom'];
    $html = '<div style="display:flex; gap:6px; flex-wrap:wrap;">';
    foreach ($periods as $p) {
        $active = ($current === $p);
        $url = '?' . http_build_query(array_merge($_GET, ['period' => $p]));
        $html .= '<a href="' . htmlspecialchars($url) . '" style="padding:6px 12px; border-radius:6px; font-size:12px; text-decoration:none; ' . ($active ? 'background:#059669; color:#fff;' : 'background:#F3F4F6; color:#374151;') . '">' . htmlspecialchars(ak_search_period_label($p)) . '</a>';
    }
    $html .= '</div>';
    return $html;
}

/**
 * Renvoie un livesearch JS qui filtre les lignes <tr> du tableau cible
 */
function ak_search_render_livesearch(string $input_id = 'live-search', string $table_selector = 'table tbody tr'): string {
    return <<<JS
<script>
(function(){
    const input = document.getElementById('$input_id');
    if (!input) return;
    input.addEventListener('input', function(){
        const q = this.value.toLowerCase().trim();
        document.querySelectorAll('$table_selector').forEach(row => {
            if (!q) { row.style.display = ''; return; }
            const text = row.innerText.toLowerCase();
            row.style.display = text.includes(q) ? '' : 'none';
        });
    });
})();
</script>
JS;
}

}
