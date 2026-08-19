<?php
/**
 * previsions-engine.php — DASHBOARD PRÉDICTIF
 * ------------------------------------------------------------------
 * Projection déterministe (aucun LLM) de la trésorerie et du CA :
 *   - historique mensuel recettes / dépenses / net ;
 *   - indice de saisonnalité (recettes & adhésions) ;
 *   - entrées futures connues (créances échéancées + récurrences) ;
 *   - prévision N mois (moyenne mobile ajustée de la saisonnalité) ;
 *   - alertes de tendance.
 *
 * Sources (toutes scopées par org_id) :
 *   Recettes : asso_invoices (payées, cents) + cotisation_payments (euros).
 *   Dépenses : project_invoices (validées, euros) via projects.org_id.
 *   Futur    : asso_invoices pending/overdue (échéance) + asso_invoice_recurrences.
 *
 * Tous les montants renvoyés sont en EUROS (float).
 * ⚠️ Ce sont des projections indicatives, pas des garanties.
 * ------------------------------------------------------------------
 */

if (!function_exists('ak_prev_month_keys')) {
/** Liste de N clés 'YYYY-MM' finissant au mois courant (incluses). */
function ak_prev_month_keys(int $n, int $offset = 0): array {
    $keys = [];
    for ($i = $n - 1; $i >= 0; $i--) {
        $keys[] = date('Y-m', strtotime("first day of ".($offset - $i)." month"));
    }
    return $keys;
}
}

if (!function_exists('ak_prev_month_label')) {
function ak_prev_month_label(string $ym): string {
    static $m = ['01'=>'Jan','02'=>'Fév','03'=>'Mar','04'=>'Avr','05'=>'Mai','06'=>'Juin','07'=>'Juil','08'=>'Août','09'=>'Sep','10'=>'Oct','11'=>'Nov','12'=>'Déc'];
    [$y,$mo] = explode('-', $ym);
    return ($m[$mo] ?? $mo).' '.substr($y,2);
}
}

if (!function_exists('ak_prev_eur')) {
function ak_prev_eur(float $v): string { return number_format($v, 0, ',', ' ').' €'; }
}

if (!function_exists('ak_prev_history')) {
/**
 * Série mensuelle historique sur $months mois.
 * @return array<string,array{rev:float,exp:float,net:float}>
 */
function ak_prev_history(PDO $pdo, int $org_id, int $months = 24): array {
    $keys = ak_prev_month_keys($months);
    $series = [];
    foreach ($keys as $k) $series[$k] = ['rev'=>0.0, 'exp'=>0.0, 'net'=>0.0];
    $since = $keys[0].'-01';

    // Recettes : factures payées (cents -> euros), par mois d'encaissement.
    try {
        $st = $pdo->prepare(
            "SELECT DATE_FORMAT(COALESCE(paid_at, issued_at), '%Y-%m') ym, COALESCE(SUM(amount_ttc_cents),0)/100 v
             FROM asso_invoices
             WHERE org_id = :o AND status = 'paid' AND COALESCE(paid_at, issued_at) >= :s
             GROUP BY ym");
        $st->execute([':o'=>$org_id, ':s'=>$since]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) if (isset($series[$r['ym']])) $series[$r['ym']]['rev'] += (float)$r['v'];
    } catch (Throwable $e) {}

    // Recettes : cotisations payées (euros), par mois de paiement.
    try {
        $st = $pdo->prepare(
            "SELECT DATE_FORMAT(paid_at, '%Y-%m') ym, COALESCE(SUM(amount),0) v
             FROM cotisation_payments
             WHERE org_id = :o AND COALESCE(status,'paid') = 'paid' AND paid_at >= :s
             GROUP BY ym");
        $st->execute([':o'=>$org_id, ':s'=>$since]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) if (isset($series[$r['ym']])) $series[$r['ym']]['rev'] += (float)$r['v'];
    } catch (Throwable $e) {}

    // Dépenses : factures fournisseurs validées (euros), par date de facture.
    try {
        $st = $pdo->prepare(
            "SELECT DATE_FORMAT(pi.invoice_date, '%Y-%m') ym, COALESCE(SUM(pi.amount_ttc),0) v
             FROM project_invoices pi JOIN projects p ON p.id = pi.project_id
             WHERE p.org_id = :o AND pi.status = 'validated' AND pi.invoice_date >= :s
             GROUP BY ym");
        $st->execute([':o'=>$org_id, ':s'=>$since]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) if (isset($series[$r['ym']])) $series[$r['ym']]['exp'] += (float)$r['v'];
    } catch (Throwable $e) {}

    foreach ($series as $k=>$v) $series[$k]['net'] = $v['rev'] - $v['exp'];
    return $series;
}
}

if (!function_exists('ak_prev_seasonal_index')) {
/**
 * Indice de saisonnalité des recettes par mois calendaire (1..12).
 * 1.0 = mois moyen ; >1 mois fort ; <1 mois faible. Neutre si trop peu de données.
 * @return array<int,float>
 */
function ak_prev_seasonal_index(array $history): array {
    $byMonth = array_fill(1, 12, []);
    foreach ($history as $ym=>$v) {
        $mo = (int)substr($ym, 5, 2);
        $byMonth[$mo][] = $v['rev'];
    }
    $monthAvg = []; $all = [];
    foreach ($byMonth as $mo=>$vals) {
        if ($vals) { $monthAvg[$mo] = array_sum($vals)/count($vals); $all = array_merge($all, $vals); }
        else $monthAvg[$mo] = null;
    }
    $global = $all ? array_sum($all)/count($all) : 0.0;
    $idx = array_fill(1, 12, 1.0);
    if ($global > 0) {
        foreach ($monthAvg as $mo=>$avg) $idx[$mo] = ($avg === null) ? 1.0 : round($avg / $global, 3);
    }
    return $idx;
}
}

if (!function_exists('ak_prev_known_inflows')) {
/**
 * Entrées futures « connues » par mois, sur $horizon mois à venir :
 *   - créances (factures pending/overdue) rattachées à leur échéance ;
 *   - factures récurrentes programmées (next_date).
 * @return array<string,array{receivables:float,recurring:float}>
 */
function ak_prev_known_inflows(PDO $pdo, int $org_id, int $horizon = 6): array {
    // Mois à venir, à partir du mois prochain.
    $keys = [];
    for ($i = 1; $i <= $horizon; $i++) $keys[] = date('Y-m', strtotime("first day of +$i month"));
    $out = [];
    foreach ($keys as $k) $out[$k] = ['receivables'=>0.0, 'recurring'=>0.0];

    // Créances échéancées dans la fenêtre (impayées : on les attend à leur échéance).
    try {
        $st = $pdo->prepare(
            "SELECT DATE_FORMAT(due_at, '%Y-%m') ym, COALESCE(SUM(amount_ttc_cents),0)/100 v
             FROM asso_invoices
             WHERE org_id = :o AND status IN ('pending','overdue') AND due_at IS NOT NULL
               AND DATE_FORMAT(due_at,'%Y-%m') IN (".implode(',', array_map(fn($k)=>$pdo->quote($k), $keys)).")
             GROUP BY ym");
        $st->execute([':o'=>$org_id]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) if (isset($out[$r['ym']])) $out[$r['ym']]['receivables'] += (float)$r['v'];
    } catch (Throwable $e) {}

    // Factures récurrentes programmées : le montant est dans template_data (JSON).
    try {
        $st = $pdo->prepare(
            "SELECT DATE_FORMAT(next_date, '%Y-%m') ym, template_data
             FROM asso_invoice_recurrences
             WHERE org_id = :o AND next_date IS NOT NULL AND COALESCE(status,'active') = 'active'");
        $st->execute([':o'=>$org_id]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if (!isset($out[$r['ym']])) continue;
            $td = json_decode((string)$r['template_data'], true);
            $sum = 0.0;
            if (isset($td['lines']) && is_array($td['lines'])) {
                foreach ($td['lines'] as $l) {
                    $sum += ((float)($l['quantity'] ?? 1)) * ((int)($l['unit_price_cents_ttc'] ?? 0)) / 100;
                }
            }
            $out[$r['ym']]['recurring'] += $sum;
        }
    } catch (Throwable $e) { /* structure différente : on ignore */ }

    return $out;
}
}

if (!function_exists('ak_prev_forecast')) {
/**
 * Prévision sur $horizon mois.
 * Méthode : moyenne mobile (6 derniers mois) des recettes et dépenses,
 * ajustée par l'indice de saisonnalité du mois visé ; les recettes projetées
 * sont plancherées par les entrées connues (créances + récurrences).
 * Cumul de trésorerie à partir de $start_balance (0 si inconnu).
 *
 * @return array{months:array,rows:array,method:string}
 */
function ak_prev_forecast(PDO $pdo, int $org_id, int $horizon = 6, float $start_balance = 0.0): array {
    $history = ak_prev_history($pdo, $org_id, 24);
    $idx = ak_prev_seasonal_index($history);
    $known = ak_prev_known_inflows($pdo, $org_id, $horizon);

    // Moyenne mobile 6 mois (hors mois courant incomplet).
    $vals = array_values($history);
    $tail = array_slice($vals, -7, 6); // 6 mois pleins précédents
    $avgRev = $tail ? array_sum(array_map(fn($x)=>$x['rev'], $tail))/count($tail) : 0.0;
    $avgExp = $tail ? array_sum(array_map(fn($x)=>$x['exp'], $tail))/count($tail) : 0.0;

    $rows = []; $cum = $start_balance;
    for ($i = 1; $i <= $horizon; $i++) {
        $ym = date('Y-m', strtotime("first day of +$i month"));
        $mo = (int)substr($ym, 5, 2);
        $season = $idx[$mo] ?? 1.0;
        $baseRev = $avgRev * $season;
        $baseExp = $avgExp; // dépenses moins saisonnières : on garde la moyenne
        $knownIn = ($known[$ym]['receivables'] ?? 0) + ($known[$ym]['recurring'] ?? 0);
        $projRev = max($baseRev, $knownIn); // plancher par les entrées certaines
        $net = $projRev - $baseExp;
        $cum += $net;
        $rows[] = [
            'ym' => $ym, 'label' => ak_prev_month_label($ym),
            'rev' => round($projRev, 0), 'exp' => round($baseExp, 0), 'net' => round($net, 0),
            'cum' => round($cum, 0), 'known' => round($knownIn, 0), 'season' => $season,
        ];
    }
    return [
        'rows' => $rows,
        'avg_rev' => round($avgRev, 0), 'avg_exp' => round($avgExp, 0),
        'method' => "Moyenne des 6 derniers mois pleins, ajustée de la saisonnalité, plancher = entrées connues (créances + récurrences).",
    ];
}
}

if (!function_exists('ak_prev_membership_seasonality')) {
/** Nombre d'adhésions (paiements de cotisation) par mois calendaire. */
function ak_prev_membership_seasonality(PDO $pdo, int $org_id): array {
    $out = array_fill(1, 12, 0);
    try {
        $st = $pdo->prepare(
            "SELECT MONTH(paid_at) mo, COUNT(*) n FROM cotisation_payments
             WHERE org_id = :o AND COALESCE(status,'paid')='paid' AND paid_at IS NOT NULL
               AND paid_at >= DATE_SUB(CURDATE(), INTERVAL 36 MONTH)
             GROUP BY mo");
        $st->execute([':o'=>$org_id]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $out[(int)$r['mo']] = (int)$r['n'];
    } catch (Throwable $e) {}
    return $out;
}
}

if (!function_exists('ak_prev_trend_alerts')) {
/**
 * Alertes de tendance à partir de l'historique et de la prévision.
 * @return array<array{level:string,text:string}>
 */
function ak_prev_trend_alerts(array $history, array $forecast, float $start_balance): array {
    $alerts = [];
    $keys = array_keys($history);
    $n = count($keys);
    if ($n >= 2) {
        // Mois précédent complet vs moyenne des 6 mois d'avant.
        $vals = array_values($history);
        $last = $vals[$n-2]; // dernier mois plein
        $prev6 = array_slice($vals, max(0,$n-8), 6);
        $avg = $prev6 ? array_sum(array_map(fn($x)=>$x['rev'], $prev6))/count($prev6) : 0;
        if ($avg > 0) {
            $delta = ($last['rev'] - $avg) / $avg * 100;
            $lbl = ak_prev_month_label($keys[$n-2]);
            if ($delta <= -20) $alerts[] = ['level'=>'warn', 'text'=>"Recettes de $lbl en baisse de ".round(abs($delta))." % vs votre moyenne récente."];
            elseif ($delta >= 20) $alerts[] = ['level'=>'good', 'text'=>"Recettes de $lbl en hausse de ".round($delta)." % vs votre moyenne récente."];
        }
        // Année N vs N-1 même mois.
        $lastKey = $keys[$n-2];
        $yoyKey = date('Y-m', strtotime($lastKey.'-01 -1 year'));
        if (isset($history[$yoyKey]) && $history[$yoyKey]['rev'] > 0) {
            $d = ($last['rev'] - $history[$yoyKey]['rev']) / $history[$yoyKey]['rev'] * 100;
            $alerts[] = ['level'=> $d>=0?'good':'warn', 'text'=>"Sur un an, ".ak_prev_month_label($lastKey)." est ".($d>=0?'+':'').round($d)." % vs l'an dernier."];
        }
    }
    // Trésorerie projetée négative.
    if ($start_balance != 0.0 || array_sum(array_map(fn($r)=>$r['net'], $forecast['rows'])) != 0) {
        foreach ($forecast['rows'] as $r) {
            if ($start_balance != 0.0 && $r['cum'] < 0) {
                $alerts[] = ['level'=>'danger', 'text'=>"Trésorerie projetée négative en {$r['label']} (".ak_prev_eur($r['cum'])."). Anticipez (relances, subventions, étalement des dépenses)."];
                break;
            }
        }
    }
    // Mois futurs à net négatif.
    $negMonths = array_filter($forecast['rows'], fn($r)=>$r['net'] < 0);
    if (count($negMonths) >= 2 && $start_balance == 0.0) {
        $alerts[] = ['level'=>'warn', 'text'=>count($negMonths)." mois à venir projetés en déficit de flux (dépenses > recettes)."];
    }
    return $alerts;
}
}
