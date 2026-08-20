<?php
/**
 * notes-frais-engine.php — Notes de frais & barème kilométrique
 * ------------------------------------------------------------------
 * Logique déterministe des notes de frais : indemnités kilométriques
 * (barème administratif) + justificatifs, totaux, listing.
 *
 * ⚠️ BARÈME KILOMÉTRIQUE : indicatif. Le barème officiel est publié
 *    chaque année par arrêté (voitures, selon la puissance fiscale et
 *    la distance annuelle). Vérifiez l'arrêté en vigueur ; le montant
 *    calculé est une ESTIMATION basée sur la distance saisie.
 * ------------------------------------------------------------------
 */

if (!function_exists('nf_bareme_meta')) {
/** Métadonnées du barème utilisé (à mettre à jour chaque année). */
function nf_bareme_meta(): array {
    return ['ref' => '2025 (revenus 2024)', 'note' => 'Barème kilométrique administratif — voitures. Indicatif : vérifiez l\'arrêté en vigueur.'];
}
}

if (!function_exists('nf_bareme_voiture')) {
/**
 * Barème kilométrique « voitures » : coefficients par puissance fiscale (CV)
 * et par tranche de distance (≤5000 ; 5001–20000 ; >20000).
 * Formules : t1 = d*a ; t2 = d*b + c ; t3 = d*e.
 */
function nf_bareme_voiture(): array {
    return [
        3 => ['a'=>0.529, 'b'=>0.316, 'c'=>1065, 'e'=>0.370],  // 3 CV et moins
        4 => ['a'=>0.606, 'b'=>0.340, 'c'=>1330, 'e'=>0.407],
        5 => ['a'=>0.636, 'b'=>0.357, 'c'=>1395, 'e'=>0.427],
        6 => ['a'=>0.665, 'b'=>0.374, 'c'=>1457, 'e'=>0.447],
        7 => ['a'=>0.697, 'b'=>0.394, 'c'=>1515, 'e'=>0.470],  // 7 CV et plus
    ];
}
}

if (!function_exists('nf_cv_key')) {
/** Ramène une puissance fiscale à la clé de barème (3..7). */
function nf_cv_key(int $cv): int {
    if ($cv <= 3) return 3;
    if ($cv >= 7) return 7;
    return $cv;
}
}

if (!function_exists('nf_ik_amount_cents')) {
/**
 * Indemnité kilométrique (en centimes) pour une distance $km et une
 * puissance $cv. La tranche est choisie d'après la distance saisie.
 */
function nf_ik_amount_cents(int $cv, int $km): int {
    if ($km <= 0) return 0;
    $b = nf_bareme_voiture()[nf_cv_key($cv)];
    if ($km <= 5000)       $eur = $km * $b['a'];
    elseif ($km <= 20000)  $eur = $km * $b['b'] + $b['c'];
    else                   $eur = $km * $b['e'];
    return (int) round($eur * 100);
}
}

if (!function_exists('nf_categories')) {
function nf_categories(): array {
    return ['transport'=>'Transport', 'repas'=>'Repas', 'hebergement'=>'Hébergement',
            'fourniture'=>'Fournitures', 'mileage'=>'Indemnité kilométrique', 'autre'=>'Autre'];
}
}

if (!function_exists('nf_status_meta')) {
function nf_status_meta(string $s): array {
    return [
        'draft'      => ['Brouillon',   '#475569', '#F1F5F9'],
        'submitted'  => ['Soumise',     '#3730A3', '#E0E7FF'],
        'approved'   => ['Approuvée',   '#065F46', '#D1FAE5'],
        'rejected'   => ['Refusée',     '#991B1B', '#FEE2E2'],
        'reimbursed' => ['Remboursée',  '#0F766E', '#CCFBF1'],
    ][$s] ?? ['—', '#475569', '#F1F5F9'];
}
}

if (!function_exists('nf_eur')) {
function nf_eur(int $cents): string { return number_format($cents/100, 2, ',', ' ').' €'; }
}

if (!function_exists('nf_recompute_total')) {
/** Recalcule et persiste le total d'une note (somme des lignes). */
function nf_recompute_total(PDO $pdo, int $org_id, int $report_id): int {
    $st = $pdo->prepare("SELECT COALESCE(SUM(amount_ttc_cents),0) FROM expense_report_lines WHERE report_id=? AND org_id=?");
    $st->execute([$report_id, $org_id]);
    $tot = (int)$st->fetchColumn();
    $pdo->prepare("UPDATE expense_reports SET total_cents=? WHERE id=? AND org_id=?")->execute([$tot, $report_id, $org_id]);
    return $tot;
}
}

if (!function_exists('nf_load_report')) {
/** Charge une note + ses lignes, scopée org (et user si non-admin). */
function nf_load_report(PDO $pdo, int $org_id, int $report_id, ?int $restrict_user = null): ?array {
    $sql = "SELECT * FROM expense_reports WHERE id=? AND org_id=?";
    $args = [$report_id, $org_id];
    if ($restrict_user !== null) { $sql .= " AND user_id=?"; $args[] = $restrict_user; }
    $st = $pdo->prepare($sql); $st->execute($args);
    $rep = $st->fetch(PDO::FETCH_ASSOC);
    if (!$rep) return null;
    $ls = $pdo->prepare("SELECT * FROM expense_report_lines WHERE report_id=? AND org_id=? ORDER BY spent_at ASC, id ASC");
    $ls->execute([$report_id, $org_id]);
    $rep['lines'] = $ls->fetchAll(PDO::FETCH_ASSOC) ?: [];
    return $rep;
}
}

if (!function_exists('nf_list_reports')) {
function nf_list_reports(PDO $pdo, int $org_id, ?int $restrict_user = null): array {
    $sql = "SELECT r.*, u.first_name, u.last_name FROM expense_reports r
            LEFT JOIN users u ON u.id = r.user_id
            WHERE r.org_id = ?";
    $args = [$org_id];
    if ($restrict_user !== null) { $sql .= " AND r.user_id = ?"; $args[] = $restrict_user; }
    $sql .= " ORDER BY r.created_at DESC LIMIT 300";
    $st = $pdo->prepare($sql); $st->execute($args);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}
}
