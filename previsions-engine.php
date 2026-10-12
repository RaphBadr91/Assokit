<?php
/**
 * previsions-engine.php — DASHBOARD PRÉDICTIF
 * ------------------------------------------------------------------
 * Projection déterministe (aucun LLM) de la trésorerie et du CA :
 *   - historique mensuel recettes / dépenses / net ;
 *   - indice de saisonnalité (recettes & adhésions) ;
 *   - entrées futures connues (créances échéancées + récurrences) ;
 *   - prévision : reste du mois en cours puis N mois (moyenne mobile
 *     ajustée de la saisonnalité) ;
 *   - alertes de tendance.
 *
 * Sources (toutes scopées par org_id) :
 *   Recettes : asso_invoices (payées, cents) + cotisation_payments (euros).
 *   Dépenses : project_invoices (validées, euros) via folders.org_id (projects n'a pas d'org_id).
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
             JOIN folders f ON f.id = p.folder_id
             WHERE f.org_id = :o AND pi.status = 'validated' AND pi.invoice_date >= :s
             GROUP BY ym");
        $st->execute([':o'=>$org_id, ':s'=>$since]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) if (isset($series[$r['ym']])) $series[$r['ym']]['exp'] += (float)$r['v'];
    } catch (Throwable $e) {}

    foreach ($series as $k=>$v) $series[$k]['net'] = $v['rev'] - $v['exp'];
    return $series;
}
}

if (!function_exists('ak_prev_active_months')) {
/**
 * Mois PLEINS de l'historique (sans le mois en cours), à partir du premier
 * mois avec une recette ou une dépense.
 */
function ak_prev_active_months(array $history): array {
    unset($history[date('Y-m')]);
    $out = []; $started = false;
    foreach ($history as $ym=>$v) {
        if (!$started && (float)$v['rev'] == 0.0 && (float)$v['exp'] == 0.0) continue;
        $started = true;
        $out[$ym] = $v;
    }
    return $out;
}
}

if (!function_exists('ak_prev_seasonal_index')) {
/**
 * Indice de saisonnalité des recettes par mois calendaire (1..12).
 * 1.0 = mois moyen ; >1 mois fort ; <1 mois faible. Neutre si trop peu de données.
 * @return array<int,float>
 */
function ak_prev_seasonal_index(array $history): array {
    // Mois pleins depuis la première activité : le mois en cours n'est pas
    // terminé, et les mois d'avant l'arrivée sur Assokit ne sont pas des
    // mois sans recettes. Moins d'un an de données : indices neutres (sinon
    // un mois sans historique passait pour le « plus faible »).
    $full = ak_prev_active_months($history);
    $idx = array_fill(1, 12, 1.0);
    if (count($full) < 12) return $idx;
    $byMonth = array_fill(1, 12, []);
    foreach ($full as $ym=>$v) {
        $mo = (int)substr($ym, 5, 2);
        $byMonth[$mo][] = $v['rev'];
    }
    $monthAvg = []; $all = [];
    foreach ($byMonth as $mo=>$vals) {
        if ($vals) { $monthAvg[$mo] = array_sum($vals)/count($vals); $all = array_merge($all, $vals); }
        else $monthAvg[$mo] = null;
    }
    $global = $all ? array_sum($all)/count($all) : 0.0;
    if ($global > 0) {
        foreach ($monthAvg as $mo=>$avg) $idx[$mo] = ($avg === null) ? 1.0 : round($avg / $global, 3);
    }
    return $idx;
}
}

if (!function_exists('ak_prev_horizon_keys')) {
/** Mois projetés : le mois en cours (son reste) puis les $horizon suivants. */
function ak_prev_horizon_keys(int $horizon): array {
    $keys = [date('Y-m')];
    for ($i = 1; $i <= $horizon; $i++) $keys[] = date('Y-m', strtotime("first day of +$i month"));
    return $keys;
}
}

if (!function_exists('ak_prev_recurrence_amount')) {
/**
 * Montant TTC (euros) d'une facture générée par une récurrence : mêmes
 * sources que la génération (asso-recurrence-helpers.php) — les lignes de la
 * facture source, sinon template_data (lignes saisies HT ou TTC).
 */
function ak_prev_recurrence_amount(PDO $pdo, array $rec): float {
    $src = (int)($rec['source_invoice_id'] ?? 0);
    if ($src > 0) {
        try {
            $st = $pdo->prepare("SELECT amount_ttc_cents FROM asso_invoices WHERE id = ? AND org_id = ?");
            $st->execute([$src, (int)$rec['org_id']]);
            $v = $st->fetchColumn();
            if ($v !== false && (int)$v > 0) return (int)$v / 100;
        } catch (Throwable $e) {}
    }
    $td = json_decode((string)($rec['template_data'] ?? ''), true);
    if (!is_array($td) || empty($td['lines']) || !is_array($td['lines'])) return 0.0;
    $vm = (string)($rec['vat_mode'] ?? 'none');
    $rec_rate = in_array($vm, ['5.5', '10', '20'], true) ? (float)$vm : null;
    $cents = 0;
    foreach ($td['lines'] as $l) {
        if (!is_array($l)) continue;
        $qty = round((float)($l['quantity'] ?? 1), 2);
        if (isset($l['unit_price_ht_cents']) || isset($l['unit_price_ht'])) {
            $vat = array_key_exists('vat_rate', $l)
                ? ($l['vat_rate'] === null || $l['vat_rate'] === '' ? null : (float)$l['vat_rate'])
                : $rec_rate;
            $up = isset($l['unit_price_ht_cents']) ? (int)$l['unit_price_ht_cents'] : (int)round((float)$l['unit_price_ht'] * 100);
            $ht = (int)round($qty * $up);
            $cents += $ht + (($vat !== null && $vat > 0) ? (int)round($ht * $vat / 100) : 0);
        } else {
            $cents += (int)round($qty * (int)($l['unit_price_cents_ttc'] ?? 0));
        }
    }
    return max(0, $cents) / 100;
}
}

if (!function_exists('ak_prev_known_inflows')) {
/**
 * Entrées « connues » par mois, du mois en cours à $horizon mois :
 *   - créances (factures pending/overdue) à leur échéance ; celles déjà
 *     échues ou sans échéance sont attendues dans le mois en cours ;
 *   - chaque facture que les récurrences actives émettront sur la période,
 *     attendue à son échéance (émission + délai de paiement, 30 j par défaut).
 * @return array<string,array{receivables:float,recurring:float}>
 */
function ak_prev_known_inflows(PDO $pdo, int $org_id, int $horizon = 6): array {
    $keys = ak_prev_horizon_keys($horizon);
    $out = [];
    foreach ($keys as $k) $out[$k] = ['receivables'=>0.0, 'recurring'=>0.0];
    $cur = $keys[0];
    $last_day = date('Y-m-t', strtotime(end($keys).'-01'));

    // Créances : même périmètre que le KPI « À encaisser (créances) ».
    try {
        $st = $pdo->prepare(
            "SELECT CASE WHEN due_at IS NULL OR due_at < :m0 THEN :cur ELSE DATE_FORMAT(due_at, '%Y-%m') END ym,
                    COALESCE(SUM(amount_ttc_cents),0)/100 v
             FROM asso_invoices
             WHERE org_id = :o AND status IN ('pending','overdue')
             GROUP BY ym");
        $st->execute([':o'=>$org_id, ':m0'=>$cur.'-01', ':cur'=>$cur]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) if (isset($out[$r['ym']])) $out[$r['ym']]['receivables'] += (float)$r['v'];
    } catch (Throwable $e) {}

    // Récurrences actives : chaque occurrence jusqu'à la fin de l'horizon.
    try {
        if (!function_exists('ak_recurrence_following_date') && is_file(__DIR__ . '/asso-recurrence-helpers.php')) {
            require_once __DIR__ . '/asso-recurrence-helpers.php';
        }
        $st = $pdo->prepare(
            "SELECT * FROM asso_invoice_recurrences
             WHERE org_id = :o AND next_run_date IS NOT NULL AND COALESCE(status,'active') = 'active'");
        $st->execute([':o'=>$org_id]);
        $today = date('Y-m-d');
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $rec) {
            $amount = ak_prev_recurrence_amount($pdo, $rec);
            if ($amount <= 0) continue;
            // Délai de paiement : celui de la facture source, sinon 30 jours.
            $due_days = 30;
            $src = (int)($rec['source_invoice_id'] ?? 0);
            if ($src > 0) {
                try {
                    $s2 = $pdo->prepare("SELECT DATEDIFF(due_at, issued_at) FROM asso_invoices WHERE id = ? AND org_id = ?");
                    $s2->execute([$src, $org_id]);
                    $dd = (int)$s2->fetchColumn();
                    if ($dd > 0 && $dd < 365) $due_days = $dd;
                } catch (Throwable $e) {}
            }
            // Échéance prévue ($planned) et jour d'émission ($run) : en retard,
            // la facture part au prochain passage du cron, et la suivante se
            // calcule depuis l'échéance prévue (comme ak_recurrence_run_due).
            $planned = date('Y-m-d', strtotime((string)$rec['next_run_date']));
            $run = max($planned, $today);
            $count = (int)($rec['occurrences_count'] ?? 0);
            $max = !empty($rec['max_occurrences']) ? (int)$rec['max_occurrences'] : null;
            $end = !empty($rec['end_date']) ? date('Y-m-d', strtotime((string)$rec['end_date'])) : null;
            for ($guard = 0; $guard < 400 && $run <= $last_day; $guard++) {
                if (($max !== null && $count >= $max) || ($end !== null && $run > $end)) break;
                $ym = date('Y-m', strtotime($run . " +$due_days day"));
                if (isset($out[$ym])) $out[$ym]['recurring'] += $amount;
                $count++;
                if (!function_exists('ak_recurrence_following_date')) break;   // une seule occurrence connue
                $next = ak_recurrence_following_date(array_merge($rec, ['next_run_date' => $planned]), $run);
                if ($next <= $run) break;
                $planned = $run = $next;
            }
        }
    } catch (Throwable $e) { /* structure différente : on ignore */ }

    return $out;
}
}

if (!function_exists('ak_prev_forecast')) {
/**
 * Prévision : reste du mois en cours, puis $horizon mois.
 * Méthode : moyenne mobile (6 derniers mois pleins) des recettes et
 * dépenses, ajustée par l'indice de saisonnalité du mois visé ; les
 * recettes projetées sont plancherées par les entrées connues (créances +
 * récurrences). Pour le mois en cours, seule la part restante de la
 * moyenne est projetée (prorata des jours restants).
 * Cumul de trésorerie à partir de $start_balance (0 si inconnu), le solde
 * saisi étant celui d'aujourd'hui.
 *
 * @return array{rows:array,avg_rev:float,avg_exp:float,method:string,season:array}
 */
function ak_prev_forecast(PDO $pdo, int $org_id, int $horizon = 6, float $start_balance = 0.0, ?array $history = null): array {
    $history = $history ?? ak_prev_history($pdo, $org_id, 24);
    $idx = ak_prev_seasonal_index($history);
    $known = ak_prev_known_inflows($pdo, $org_id, $horizon);

    // Moyenne mobile sur les 6 derniers mois pleins, sans compter les mois
    // d'avant la première activité (une structure arrivée il y a 2 mois
    // n'a pas 4 mois à zéro).
    $tail = array_slice(ak_prev_active_months($history), -6, 6, true);
    $n = count($tail);
    $avgRev = $n ? array_sum(array_map(fn($x)=>$x['rev'], $tail))/$n : 0.0;
    $avgExp = $n ? array_sum(array_map(fn($x)=>$x['exp'], $tail))/$n : 0.0;

    $rows = []; $cum = $start_balance;
    $dim = (int)date('t'); $day = (int)date('j');
    foreach (ak_prev_horizon_keys($horizon) as $i => $ym) {
        $partial = ($i === 0);
        $share = $partial ? ($dim - $day + 1) / $dim : 1.0;   // jours restants, aujourd'hui compris
        $mo = (int)substr($ym, 5, 2);
        $season = $idx[$mo] ?? 1.0;
        $baseRev = $avgRev * $season * $share;
        $baseExp = $avgExp * $share; // dépenses moins saisonnières : on garde la moyenne
        $knownIn = ($known[$ym]['receivables'] ?? 0) + ($known[$ym]['recurring'] ?? 0);
        $projRev = max($baseRev, $knownIn); // plancher par les entrées certaines
        $net = $projRev - $baseExp;
        $cum += $net;
        $rows[] = [
            'ym' => $ym, 'label' => ($partial ? 'fin ' : '').ak_prev_month_label($ym), 'partial' => $partial,
            'rev' => round($projRev, 0), 'exp' => round($baseExp, 0), 'net' => round($net, 0),
            'cum' => round($cum, 0), 'known' => round($knownIn, 0), 'season' => $season,
        ];
    }
    return [
        'rows' => $rows,
        'avg_rev' => round($avgRev, 0), 'avg_exp' => round($avgExp, 0),
        'season' => $idx,
        'method' => "Moyenne des 6 derniers mois pleins, ajustée de la saisonnalité, plancher = entrées connues (créances à leur échéance, chaque facture des récurrences actives). Mois en cours : part restante de la moyenne, et les créances échues ou à échoir ce mois-ci.",
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
