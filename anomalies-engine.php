<?php
/**
 * anomalies-engine.php — DÉTECTION D'ANOMALIES
 * ------------------------------------------------------------------
 * Audit déterministe (aucun LLM) des données comptables d'une org :
 * factures et cotisations. Repère les incohérences qui menacent la
 * fiabilité des chiffres et la piste d'audit.
 *
 * Chaque anomalie = [
 *   'severity' => 'high'|'medium'|'low',
 *   'category' => slug,
 *   'title'    => libellé court,
 *   'detail'   => explication + éléments concernés,
 *   'route'    => lien vers l'écran concerné (ou null),
 * ]
 * Tout est scopé par org_id, requêtes préparées, lecture seule.
 * ------------------------------------------------------------------
 */

if (!function_exists('ak_anom_eur')) {
function ak_anom_eur($cents): string { return number_format(((int)$cents)/100, 2, ',', ' ').' €'; }
}

if (!function_exists('ak_anom_sev_meta')) {
function ak_anom_sev_meta(string $s): array {
    return [
        'high'   => ['Critique', '#991B1B', '#FEE2E2', '🔴'],
        'medium' => ['À vérifier', '#92400E', '#FEF3C7', '🟠'],
        'low'    => ['Info', '#3730A3', '#E0E7FF', '🔵'],
    ][$s] ?? ['—', '#374151', '#F3F4F6', '·'];
}
}

if (!function_exists('ak_anom_hash')) {
/** Empreinte stable d'une anomalie (pour l'ignorer durablement). */
function ak_anom_hash(array $f): string {
    return sha1(($f['category'] ?? '').'|'.($f['title'] ?? ''));
}
}

if (!function_exists('ak_anom_dismissed_set')) {
/** Ensemble des empreintes ignorées par l'org. */
function ak_anom_dismissed_set(PDO $pdo, int $org_id): array {
    try {
        $st = $pdo->prepare("SELECT finding_hash FROM anomaly_dismissed WHERE org_id = ?");
        $st->execute([$org_id]);
        return array_flip($st->fetchAll(PDO::FETCH_COLUMN) ?: []);
    } catch (Throwable $e) { return []; }
}
}

if (!function_exists('ak_anom_scan')) {
/**
 * Lance tous les contrôles et retourne la liste des anomalies,
 * triée par gravité décroissante.
 */
function ak_anom_scan(PDO $pdo, int $org_id, bool $include_dismissed = false): array {
    $f = [];
    foreach ([
        'ak_anom_invoices_numbering',
        'ak_anom_invoices_duplicates',
        'ak_anom_invoices_vat',
        'ak_anom_invoices_amounts',
        'ak_anom_invoices_dates',
        'ak_anom_invoices_status',
        'ak_anom_invoices_outliers',
        'ak_anom_cotis_double',
    ] as $fn) {
        try { $f = array_merge($f, $fn($pdo, $org_id)); }
        catch (Throwable $e) { error_log("[anomalies] $fn: ".$e->getMessage()); }
    }
    $dismissed = ak_anom_dismissed_set($pdo, $org_id);
    $res = [];
    foreach ($f as $item) {
        $item['hash'] = ak_anom_hash($item);
        $item['dismissed'] = isset($dismissed[$item['hash']]);
        if (!$include_dismissed && $item['dismissed']) continue;
        $res[] = $item;
    }
    $rank = ['high'=>0, 'medium'=>1, 'low'=>2];
    usort($res, fn($a,$b) => ($rank[$a['severity']] <=> $rank[$b['severity']]));
    return $res;
}
}

/** Factures non-brouillon de l'org (base commune des contrôles). */
if (!function_exists('ak_anom_load_invoices')) {
function ak_anom_load_invoices(PDO $pdo, int $org_id): array {
    $st = $pdo->prepare(
        "SELECT id, invoice_number, amount_ht_cents, amount_vat_cents, amount_ttc_cents,
                status, DATE(issued_at) issued, DATE(due_at) due, paid_at, client_id
         FROM asso_invoices
         WHERE org_id = :o AND status <> 'draft'
         ORDER BY issued_at ASC, id ASC");
    $st->execute([':o'=>$org_id]);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}
}

// ── A. Numérotation : doublons + trous de séquence ──────────────────
if (!function_exists('ak_anom_invoices_numbering')) {
function ak_anom_invoices_numbering(PDO $pdo, int $org_id): array {
    $rows = ak_anom_load_invoices($pdo, $org_id);
    if (!$rows) return [];
    $out = [];
    $seen = []; $groups = [];
    foreach ($rows as $r) {
        $num = trim((string)$r['invoice_number']);
        if ($num === '') continue;
        // doublon strict de numéro
        $seen[$num] = ($seen[$num] ?? 0) + 1;
        // décomposition préfixe + digits finaux pour la détection de trous
        if (preg_match('/^(.*?)(\d+)$/', $num, $m)) {
            $prefix = $m[1]; $n = (int)$m[2]; $width = strlen($m[2]);
            $groups[$prefix][] = ['n'=>$n, 'w'=>$width, 'raw'=>$num];
        }
    }
    foreach ($seen as $num => $c) {
        if ($c > 1) {
            $out[] = ['severity'=>'high', 'category'=>'numbering-duplicate',
                'title'=>"Numéro de facture en double : $num",
                'detail'=>"$c factures portent le numéro « $num ». Un numéro doit être unique et séquentiel (obligation légale + piste d'audit).",
                'route'=>'/mon-asso-factures'];
        }
    }
    foreach ($groups as $prefix => $items) {
        $ns = array_map(fn($x)=>$x['n'], $items);
        $min = min($ns); $max = max($ns);
        if ($max - $min > 2000) continue; // garde-fou
        $present = array_flip($ns);
        $missing = [];
        for ($i = $min; $i <= $max; $i++) if (!isset($present[$i])) $missing[] = $i;
        if ($missing) {
            $w = $items[0]['w'];
            $sample = array_slice(array_map(fn($n)=>$prefix.str_pad((string)$n, $w, '0', STR_PAD_LEFT), $missing), 0, 12);
            $more = count($missing) > 12 ? ' … (+'.(count($missing)-12).')' : '';
            $out[] = ['severity'=>'medium', 'category'=>'numbering-gap',
                'title'=>count($missing)." numéro(s) manquant(s) dans la série « {$prefix} »",
                'detail'=>"Trous de séquence : ".implode(', ', $sample).$more.". Une numérotation continue est requise ; vérifiez qu'aucune facture n'a été supprimée.",
                'route'=>'/mon-asso-factures'];
        }
    }
    return $out;
}
}

// ── B. Doublons potentiels (même client, même montant, dates proches) ─
if (!function_exists('ak_anom_invoices_duplicates')) {
function ak_anom_invoices_duplicates(PDO $pdo, int $org_id): array {
    $st = $pdo->prepare(
        "SELECT a.invoice_number n1, b.invoice_number n2, a.amount_ttc_cents amt,
                DATE(a.issued_at) d1, DATE(b.issued_at) d2, c.display_name client
         FROM asso_invoices a
         JOIN asso_invoices b ON b.org_id = a.org_id AND b.client_id = a.client_id
              AND b.amount_ttc_cents = a.amount_ttc_cents AND b.id > a.id
              AND b.status <> 'draft' AND a.status <> 'draft'
              AND ABS(DATEDIFF(b.issued_at, a.issued_at)) <= 7
         LEFT JOIN asso_clients c ON c.id = a.client_id AND c.org_id = a.org_id
         WHERE a.org_id = :o AND a.amount_ttc_cents > 0 AND a.client_id IS NOT NULL
         ORDER BY a.issued_at DESC
         LIMIT 100");
    $st->execute([':o'=>$org_id]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[] = ['severity'=>'medium', 'category'=>'invoice-duplicate',
            'title'=>"Doublon possible : {$r['n1']} et {$r['n2']}",
            'detail'=>"Même client (".($r['client'] ?: '—').") et même montant (".ak_anom_eur($r['amt']).") à ".($r['d1']===$r['d2']?"la même date ({$r['d1']})":"{$r['d1']} et {$r['d2']}")." — vérifiez qu'il ne s'agit pas d'une facture émise deux fois.",
            'route'=>'/mon-asso-factures'];
    }
    return $out;
}
}

// ── C. TVA incohérente (taux implicite hors barème + somme HT+TVA≠TTC) ─
if (!function_exists('ak_anom_invoices_vat')) {
function ak_anom_invoices_vat(PDO $pdo, int $org_id): array {
    $rows = ak_anom_load_invoices($pdo, $org_id);
    $out = [];
    $legal = [0.0, 2.1, 5.5, 10.0, 20.0];
    foreach ($rows as $r) {
        $ht = (int)$r['amount_ht_cents']; $vat = (int)$r['amount_vat_cents']; $ttc = (int)$r['amount_ttc_cents'];
        if ($ht === 0 && $vat === 0 && $ttc === 0) continue;
        // arithmétique : HT + TVA doit égaler TTC (tolérance 2 c d'arrondi)
        if ($ht > 0 && abs(($ht + $vat) - $ttc) > 2) {
            $out[] = ['severity'=>'high', 'category'=>'vat-arithmetic',
                'title'=>"Incohérence de calcul : {$r['invoice_number']}",
                'detail'=>"HT (".ak_anom_eur($ht).") + TVA (".ak_anom_eur($vat).") = ".ak_anom_eur($ht+$vat)." ≠ TTC (".ak_anom_eur($ttc)."). Le total ne correspond pas.",
                'route'=>'/mon-asso-factures'];
            continue;
        }
        // taux implicite
        if ($ht > 0 && $vat >= 0) {
            $rate = round($vat / $ht * 100, 1);
            $ok = false;
            foreach ($legal as $lr) if (abs($rate - $lr) <= 0.3) { $ok = true; break; }
            if (!$ok) {
                $out[] = ['severity'=>'medium', 'category'=>'vat-rate',
                    'title'=>"Taux de TVA inhabituel ({$rate} %) : {$r['invoice_number']}",
                    'detail'=>"Le taux implicite ({$rate} %) ne correspond à aucun taux légal courant (0 / 2,1 / 5,5 / 10 / 20 %). À vérifier.",
                    'route'=>'/mon-asso-factures'];
            }
        }
    }
    return array_slice($out, 0, 60);
}
}

// ── D. Montants nuls / négatifs ─────────────────────────────────────
if (!function_exists('ak_anom_invoices_amounts')) {
function ak_anom_invoices_amounts(PDO $pdo, int $org_id): array {
    $st = $pdo->prepare(
        "SELECT invoice_number, amount_ttc_cents FROM asso_invoices
         WHERE org_id = :o AND status <> 'draft' AND amount_ttc_cents <= 0 LIMIT 60");
    $st->execute([':o'=>$org_id]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[] = ['severity'=>'medium', 'category'=>'amount-zero',
            'title'=>"Montant nul ou négatif : {$r['invoice_number']}",
            'detail'=>"Cette facture émise a un montant TTC de ".ak_anom_eur($r['amount_ttc_cents']).". Une facture validée devrait avoir un montant positif.",
            'route'=>'/mon-asso-factures'];
    }
    return $out;
}
}

// ── E. Dates incohérentes (échéance avant émission) ─────────────────
if (!function_exists('ak_anom_invoices_dates')) {
function ak_anom_invoices_dates(PDO $pdo, int $org_id): array {
    $st = $pdo->prepare(
        "SELECT invoice_number, DATE(issued_at) issued, DATE(due_at) due FROM asso_invoices
         WHERE org_id = :o AND status <> 'draft' AND due_at IS NOT NULL AND issued_at IS NOT NULL
           AND due_at < issued_at LIMIT 60");
    $st->execute([':o'=>$org_id]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[] = ['severity'=>'medium', 'category'=>'date-incoherent',
            'title'=>"Échéance avant émission : {$r['invoice_number']}",
            'detail'=>"Échéance ({$r['due']}) antérieure à la date d'émission ({$r['issued']}).",
            'route'=>'/mon-asso-factures'];
    }
    return $out;
}
}

// ── F. Statuts incohérents (payé sans date / impayé avec date) ──────
if (!function_exists('ak_anom_invoices_status')) {
function ak_anom_invoices_status(PDO $pdo, int $org_id): array {
    $out = [];
    $st = $pdo->prepare(
        "SELECT invoice_number FROM asso_invoices
         WHERE org_id = :o AND status = 'paid' AND paid_at IS NULL LIMIT 60");
    $st->execute([':o'=>$org_id]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $num) {
        $out[] = ['severity'=>'high', 'category'=>'status-paid-nodate',
            'title'=>"Facture « payée » sans date de paiement : $num",
            'detail'=>"Statut « payée » mais aucune date d'encaissement enregistrée. Renseignez la date pour la fiabilité de la piste d'audit.",
            'route'=>'/mon-asso-factures'];
    }
    $st = $pdo->prepare(
        "SELECT invoice_number FROM asso_invoices
         WHERE org_id = :o AND status IN ('pending','overdue') AND paid_at IS NOT NULL LIMIT 60");
    $st->execute([':o'=>$org_id]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $num) {
        $out[] = ['severity'=>'medium', 'category'=>'status-unpaid-withdate',
            'title'=>"Facture impayée mais datée comme payée : $num",
            'detail'=>"Une date d'encaissement est renseignée alors que le statut reste « en attente / en retard ». Passez-la en « payée » si elle l'est.",
            'route'=>'/mon-asso-factures'];
    }
    return $out;
}
}

// ── G. Montants aberrants (statistique, faible gravité) ─────────────
if (!function_exists('ak_anom_invoices_outliers')) {
function ak_anom_invoices_outliers(PDO $pdo, int $org_id): array {
    $st = $pdo->prepare(
        "SELECT invoice_number, amount_ttc_cents FROM asso_invoices
         WHERE org_id = :o AND status <> 'draft' AND amount_ttc_cents > 0");
    $st->execute([':o'=>$org_id]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if (count($rows) < 8) return []; // pas assez de données pour une médiane fiable
    $amts = array_map(fn($r)=>(int)$r['amount_ttc_cents'], $rows);
    sort($amts);
    $n = count($amts);
    $median = ($n % 2) ? $amts[intdiv($n,2)] : (int)(($amts[$n/2 - 1] + $amts[$n/2]) / 2);
    if ($median <= 0) return [];
    $thr = $median * 8;
    $out = [];
    foreach ($rows as $r) {
        if ((int)$r['amount_ttc_cents'] > $thr) {
            $out[] = ['severity'=>'low', 'category'=>'amount-outlier',
                'title'=>"Montant inhabituellement élevé : {$r['invoice_number']}",
                'detail'=>"Montant (".ak_anom_eur($r['amount_ttc_cents']).") très supérieur à votre facture médiane (".ak_anom_eur($median)."). Simple vérification — pas forcément une erreur.",
                'route'=>'/mon-asso-factures'];
        }
    }
    return array_slice($out, 0, 20);
}
}

// ── H. Cotisations encaissées deux fois ─────────────────────────────
if (!function_exists('ak_anom_cotis_double')) {
function ak_anom_cotis_double(PDO $pdo, int $org_id): array {
    $out = [];
    // même adhérent + même campagne + statut payé, deux paiements rapprochés
    $st = $pdo->prepare(
        "SELECT a.adherent_id, a.campaign_id, a.amount, a.paid_at p1, b.paid_at p2,
                a.payer_name, a.reference r1, b.reference r2
         FROM cotisation_payments a
         JOIN cotisation_payments b ON b.org_id = a.org_id AND b.campaign_id = a.campaign_id
              AND b.adherent_id = a.adherent_id AND b.id > a.id
              AND COALESCE(b.status,'') = 'paid' AND COALESCE(a.status,'') = 'paid'
              AND ABS(DATEDIFF(b.paid_at, a.paid_at)) <= 30
         WHERE a.org_id = :o AND a.adherent_id IS NOT NULL
         ORDER BY a.paid_at DESC
         LIMIT 80");
    $st->execute([':o'=>$org_id]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $who = $r['payer_name'] ?: ('adhérent #'.$r['adherent_id']);
        $out[] = ['severity'=>'high', 'category'=>'cotis-double',
            'title'=>"Cotisation payée 2× : $who",
            'detail'=>"Deux paiements « payés » pour la même campagne et le même adhérent à moins de 30 jours d'écart (".substr((string)$r['p1'],0,10)." et ".substr((string)$r['p2'],0,10)."). Vérifiez un éventuel double encaissement à rembourser.",
            'route'=>'/cotisations'];
    }
    return $out;
}
}
