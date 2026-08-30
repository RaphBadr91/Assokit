<?php
/**
 * asso-stats-helpers.php — Calculs stats avancées
 */

if (!function_exists('ak_stats_revenue_monthly')) {

/**
 * CA mensuel sur N derniers mois
 */
function ak_stats_revenue_monthly(PDO $pdo, int $org_id, int $months = 12): array {
    $months_fr = [1=>'Jan','Fév','Mar','Avr','Mai','Juin','Juil','Aoû','Sep','Oct','Nov','Déc'];
    $labels = []; $paid = []; $pending = []; $overdue = [];

    for ($i = $months - 1; $i >= 0; $i--) {
        $ts = strtotime("-$i months");
        $year = (int)date('Y', $ts);
        $month = (int)date('n', $ts);
        $start = sprintf('%04d-%02d-01 00:00:00', $year, $month);
        $end = date('Y-m-t 23:59:59', $ts);

        $stmt = $pdo->prepare("
            SELECT
                SUM(CASE WHEN status='paid' THEN amount_ttc_cents ELSE 0 END) AS paid,
                SUM(CASE WHEN status='pending' THEN amount_ttc_cents ELSE 0 END) AS pending,
                SUM(CASE WHEN status='overdue' THEN amount_ttc_cents ELSE 0 END) AS overdue
            FROM asso_invoices WHERE org_id = :org AND issued_at BETWEEN :s AND :e
        ");
        $stmt->execute([':org' => $org_id, ':s' => $start, ':e' => $end]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        $labels[] = $months_fr[$month] . ' ' . substr((string)$year, -2);
        $paid[] = round((int)($row['paid'] ?? 0) / 100, 2);
        $pending[] = round((int)($row['pending'] ?? 0) / 100, 2);
        $overdue[] = round((int)($row['overdue'] ?? 0) / 100, 2);
    }
    return ['labels' => $labels, 'paid' => $paid, 'pending' => $pending, 'overdue' => $overdue];
}

/**
 * Comparaison N vs N-1 par mois (pour vue annuelle)
 */
function ak_stats_yearly_compare(PDO $pdo, int $org_id, ?int $year = null): array {
    $year = $year ?? (int)date('Y');
    $prev = $year - 1;
    $months_fr = [1=>'Jan','Fév','Mar','Avr','Mai','Juin','Juil','Aoû','Sep','Oct','Nov','Déc'];
    $labels = []; $current = []; $previous = [];

    for ($m = 1; $m <= 12; $m++) {
        $labels[] = $months_fr[$m];
        // Année courante
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount_ttc_cents),0) FROM asso_invoices WHERE org_id = :org AND status='paid' AND YEAR(issued_at) = :y AND MONTH(issued_at) = :m");
        $stmt->execute([':org' => $org_id, ':y' => $year, ':m' => $m]);
        $current[] = round((int)$stmt->fetchColumn() / 100, 2);
        // Année précédente
        $stmt->execute([':org' => $org_id, ':y' => $prev, ':m' => $m]);
        $previous[] = round((int)$stmt->fetchColumn() / 100, 2);
    }
    return ['labels' => $labels, 'current' => $current, 'previous' => $previous, 'current_year' => $year, 'previous_year' => $prev];
}

/**
 * Top N clients par CA encaissé
 */
function ak_stats_top_clients(PDO $pdo, int $org_id, int $limit = 10): array {
    $stmt = $pdo->prepare("
        SELECT c.id, c.display_name, c.email, c.client_type,
               COUNT(DISTINCT i.id) AS nb_invoices,
               COALESCE(SUM(CASE WHEN i.status='paid' THEN i.amount_ttc_cents ELSE 0 END), 0) AS total_paid_cents,
               COALESCE(SUM(CASE WHEN i.status IN ('pending','overdue') THEN i.amount_ttc_cents ELSE 0 END), 0) AS total_pending_cents
        FROM asso_clients c
        LEFT JOIN asso_invoices i ON i.client_id = c.id
        WHERE c.org_id = :org AND c.deleted_at IS NULL
        GROUP BY c.id, c.display_name, c.email, c.client_type
        HAVING total_paid_cents > 0 OR nb_invoices > 0
        ORDER BY total_paid_cents DESC
        LIMIT $limit
    ");
    $stmt->execute([':org' => $org_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * KPIs globaux pour l'année en cours
 */
function ak_stats_global_kpis(PDO $pdo, int $org_id): array {
    $year = (int)date('Y');
    $start = "$year-01-01 00:00:00";
    $end = "$year-12-31 23:59:59";

    $stmt = $pdo->prepare("
        SELECT
            COUNT(*) AS total_invoices,
            COALESCE(SUM(CASE WHEN status='paid' THEN amount_ttc_cents ELSE 0 END),0) AS revenue_paid,
            COALESCE(SUM(CASE WHEN status IN ('pending','overdue') THEN amount_ttc_cents ELSE 0 END),0) AS revenue_pending,
            COALESCE(SUM(CASE WHEN status IN ('pending','overdue') AND due_at IS NOT NULL AND due_at < NOW() THEN amount_ttc_cents ELSE 0 END),0) AS revenue_overdue,
            SUM(CASE WHEN status='paid' THEN 1 ELSE 0 END) AS nb_paid,
            SUM(CASE WHEN status IN ('pending','overdue') THEN 1 ELSE 0 END) AS nb_pending,
            SUM(CASE WHEN status IN ('pending','overdue') AND due_at IS NOT NULL AND due_at < NOW() THEN 1 ELSE 0 END) AS nb_overdue
        FROM asso_invoices WHERE org_id = :org AND issued_at BETWEEN :s AND :e
    ");
    $stmt->execute([':org' => $org_id, ':s' => $start, ':e' => $end]);
    $kpi_inv = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    // Délai moyen paiement
    $stmt = $pdo->prepare("
        SELECT AVG(DATEDIFF(paid_at, issued_at)) AS avg_days
        FROM asso_invoices
        WHERE org_id = :org AND status='paid' AND paid_at IS NOT NULL AND issued_at BETWEEN :s AND :e
    ");
    $stmt->execute([':org' => $org_id, ':s' => $start, ':e' => $end]);
    $avg_days = (float)($stmt->fetchColumn() ?: 0);

    // Stats devis & taux conversion
    $stmt = $pdo->prepare("
        SELECT
            COUNT(*) AS total_quotes,
            SUM(CASE WHEN status='signed' THEN 1 ELSE 0 END) AS nb_signed,
            SUM(CASE WHEN status='converted' THEN 1 ELSE 0 END) AS nb_converted,
            SUM(CASE WHEN status='refused' THEN 1 ELSE 0 END) AS nb_refused,
            COALESCE(SUM(amount_ttc_cents),0) AS total_quoted_cents
        FROM asso_quotes WHERE org_id = :org AND issued_at BETWEEN :s AND :e
    ");
    $stmt->execute([':org' => $org_id, ':s' => $start, ':e' => $end]);
    $kpi_q = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $total_q = (int)($kpi_q['total_quotes'] ?? 0);
    $accepted = (int)($kpi_q['nb_signed'] ?? 0) + (int)($kpi_q['nb_converted'] ?? 0);
    $conversion_rate = $total_q > 0 ? round(($accepted / $total_q) * 100, 1) : 0;

    return [
        'year' => $year,
        'revenue_paid_cents' => (int)$kpi_inv['revenue_paid'],
        'revenue_pending_cents' => (int)$kpi_inv['revenue_pending'],
        'revenue_overdue_cents' => (int)$kpi_inv['revenue_overdue'],
        'total_invoices' => (int)$kpi_inv['total_invoices'],
        'nb_paid' => (int)$kpi_inv['nb_paid'],
        'nb_pending' => (int)$kpi_inv['nb_pending'],
        'nb_overdue' => (int)$kpi_inv['nb_overdue'],
        'avg_payment_days' => round($avg_days, 1),
        'total_quotes' => $total_q,
        'nb_signed' => (int)$kpi_q['nb_signed'],
        'nb_converted' => (int)$kpi_q['nb_converted'],
        'nb_refused' => (int)$kpi_q['nb_refused'],
        'total_quoted_cents' => (int)$kpi_q['total_quoted_cents'],
        'conversion_rate' => $conversion_rate,
    ];
}

/**
 * Répartition factures par statut
 */
function ak_stats_status_distribution(PDO $pdo, int $org_id): array {
    $year = (int)date('Y');
    $stmt = $pdo->prepare("
        SELECT status, COUNT(*) AS n, COALESCE(SUM(amount_ttc_cents),0) AS total
        FROM asso_invoices
        WHERE org_id = :org AND YEAR(issued_at) = :y
        GROUP BY status
    ");
    $stmt->execute([':org' => $org_id, ':y' => $year]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $labels = []; $values = []; $colors = [];
    $colorMap = [
        'paid' => '#10B981',
        'pending' => '#F59E0B',
        'overdue' => '#DC2626',
        'draft' => '#9CA3AF',
        'cancelled' => '#6B7280',
    ];
    $labelMap = [
        'paid' => 'Payées',
        'pending' => 'En attente',
        'overdue' => 'En retard',
        'draft' => 'Brouillons',
        'cancelled' => 'Annulées',
    ];
    foreach ($rows as $r) {
        $labels[] = $labelMap[$r['status']] ?? $r['status'];
        $values[] = round((int)$r['total'] / 100, 2);
        $colors[] = $colorMap[$r['status']] ?? '#9CA3AF';
    }
    return ['labels' => $labels, 'values' => $values, 'colors' => $colors];
}

/**
 * Stats par tag
 */
function ak_stats_by_tag(PDO $pdo, int $org_id): array {
    $stmt = $pdo->prepare("
        SELECT t.id, t.name, t.color,
               COUNT(DISTINCT l.entity_id) AS nb_invoices,
               COALESCE(SUM(CASE WHEN i.status='paid' THEN i.amount_ttc_cents ELSE 0 END),0) AS revenue_paid,
               COALESCE(SUM(i.amount_ttc_cents),0) AS revenue_total
        FROM asso_tags t
        LEFT JOIN asso_tag_links l ON l.tag_id = t.id AND l.entity_type = 'invoice'
        LEFT JOIN asso_invoices i ON i.id = l.entity_id
        WHERE t.org_id = :org
        GROUP BY t.id, t.name, t.color
        ORDER BY revenue_paid DESC
    ");
    $stmt->execute([':org' => $org_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

}
