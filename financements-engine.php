<?php
/**
 * financements-engine.php
 * ------------------------------------------------------------------
 * Moteur du RADAR DE SUBVENTIONS.
 * - Construit le profil d'éligibilité de l'org (auto + saisi).
 * - Score chaque dispositif du catalogue vs le profil (déterministe).
 * - Persiste les correspondances dans grant_matches.
 *
 * Aucune donnée sensible ne transite par un LLM : 100 % déterministe,
 * requêtes préparées, tout est scopé par org_id.
 * ------------------------------------------------------------------
 */

if (!function_exists('fin_dept_from_zip')) {
/** Déduit le code département (2 car., 2A/2B pour la Corse, 3 car. DOM) depuis un code postal. */
function fin_dept_from_zip(?string $zip): ?string {
    if (!$zip) return null;
    $zip = preg_replace('/\s+/', '', $zip);
    if (!preg_match('/^\d{5}$/', $zip)) return null;
    $p2 = substr($zip, 0, 2);
    // DOM/TOM : 971..976 + Saint-* 975/977/978 (on garde 3 chiffres)
    if ($p2 === '97' || $p2 === '98') return substr($zip, 0, 3);
    // Corse : 20xxx -> 2A (Corse-du-Sud) / 2B (Haute-Corse)
    if ($p2 === '20') {
        $n = (int)substr($zip, 0, 3);
        return ($n <= 201 || $n === 200) ? '2A' : '2B';
    }
    return $p2;
}
}

if (!function_exists('fin_region_from_dept')) {
/** Déduit le code région INSEE depuis un code département. */
function fin_region_from_dept(?string $dept): ?string {
    if (!$dept) return null;
    static $map = null;
    if ($map === null) {
        $map = [];
        $regions = [
            '11' => ['75','77','78','91','92','93','94','95'],
            '24' => ['18','28','36','37','41','45'],
            '27' => ['21','25','39','58','70','71','89','90'],
            '28' => ['14','27','50','61','76'],
            '32' => ['02','59','60','62','80'],
            '44' => ['08','10','51','52','54','55','57','67','68','88'],
            '52' => ['44','49','53','72','85'],
            '53' => ['22','29','35','56'],
            '75' => ['16','17','19','23','24','33','40','47','64','79','86','87'],
            '76' => ['09','11','12','30','31','32','34','46','48','65','66','81','82'],
            '84' => ['01','03','07','15','26','38','42','43','63','69','73','74'],
            '93' => ['04','05','06','13','83','84'],
            '94' => ['2A','2B'],
            // DOM (code région = code dept sur 3 chiffres, sauf conventions)
            '01' => ['971'], '02' => ['972'], '03' => ['973'], '04' => ['974'], '06' => ['976'],
        ];
        foreach ($regions as $rc => $depts) {
            foreach ($depts as $d) $map[$d] = $rc;
        }
    }
    return $map[$dept] ?? null;
}
}

if (!function_exists('fin_build_profile')) {
/**
 * Construit le profil d'éligibilité de l'org :
 *   - part de org_grant_profile (valeurs saisies),
 *   - complète avec l'auto-dérivation (dept/région depuis le CP de l'org),
 *   - détecte asso vs TPE.
 * Retourne un tableau normalisé.
 */
function fin_build_profile(PDO $pdo, int $org_id): array {
    $prof = [
        'org_id' => $org_id,
        'region_code' => null, 'dept_code' => null,
        'sectors' => [], 'is_qpv' => null, 'is_zrr' => null,
        'members_count' => null, 'annual_budget' => null, 'is_interet_general' => null,
        'org_type' => 'association', 'zip' => null, 'city' => null,
        'has_saved_profile' => false,
    ];

    // 1) org (type + adresse)
    try {
        $st = $pdo->prepare("SELECT name, siret, vat_subject, billing_address_zip, billing_address_city FROM organizations WHERE id = ?");
        $st->execute([$org_id]);
        if ($o = $st->fetch()) {
            $is_company = (!empty($o['vat_subject']) || (!empty($o['siret']) && trim((string)$o['siret']) !== ''));
            $prof['org_type'] = $is_company ? 'tpe' : 'association';
            $prof['zip'] = $o['billing_address_zip'] ?? null;
            $prof['city'] = $o['billing_address_city'] ?? null;
            $prof['dept_code'] = fin_dept_from_zip($prof['zip']);
            $prof['region_code'] = fin_region_from_dept($prof['dept_code']);
        }
    } catch (Throwable $e) {}

    // 2) profil saisi (prioritaire sur l'auto)
    try {
        $st = $pdo->prepare("SELECT * FROM org_grant_profile WHERE org_id = ?");
        $st->execute([$org_id]);
        if ($p = $st->fetch()) {
            $prof['has_saved_profile'] = true;
            if (!empty($p['region_code'])) $prof['region_code'] = $p['region_code'];
            if (!empty($p['dept_code']))   $prof['dept_code']   = $p['dept_code'];
            if (isset($p['sectors']) && $p['sectors'] !== '' && $p['sectors'] !== null) {
                $prof['sectors'] = array_values(array_filter(array_map('trim', explode(',', $p['sectors']))));
            }
            $prof['is_qpv']            = isset($p['is_qpv']) ? (is_null($p['is_qpv']) ? null : (int)$p['is_qpv']) : null;
            $prof['is_zrr']            = isset($p['is_zrr']) ? (is_null($p['is_zrr']) ? null : (int)$p['is_zrr']) : null;
            $prof['is_interet_general']= isset($p['is_interet_general']) ? (is_null($p['is_interet_general']) ? null : (int)$p['is_interet_general']) : null;
            $prof['members_count']     = isset($p['members_count']) ? $p['members_count'] : null;
            $prof['annual_budget']     = isset($p['annual_budget']) ? $p['annual_budget'] : null;
        }
    } catch (Throwable $e) {}

    return $prof;
}
}

if (!function_exists('fin_score')) {
/**
 * Score déterministe d'un dispositif ($c = ligne grant_catalog) vs $prof.
 * Retourne ['score'=>0..100, 'eligibility'=>enum, 'reasons'=>[..]].
 */
function fin_score(array $c, array $prof): array {
    $score = 0; $reasons = []; $hard_out = false; $unknown = false;

    // ── Bénéficiaire (asso / tpe) : filtre dur ──────────────────────
    $benef = array_filter(array_map('trim', explode(',', (string)($c['beneficiary'] ?? 'association'))));
    if ($benef && !in_array($prof['org_type'], $benef, true)) {
        return [
            'score' => 0, 'eligibility' => 'ineligible',
            'reasons' => ['Réservé aux ' . implode(' / ', array_map(fn($b) => $b === 'tpe' ? 'TPE/PME' : ($b === 'collectivite' ? 'collectivités' : 'associations'), $benef)) . '.'],
        ];
    }
    $score += 25; // bénéficiaire OK

    // ── Géographie ──────────────────────────────────────────────────
    $geo = $c['geo_scope'] ?? 'national';
    if ($geo === 'national' || $geo === 'europe') {
        $score += 25;
        $reasons[] = $geo === 'europe' ? 'Dispositif européen.' : 'Dispositif national.';
    } else {
        // Dispositif territorial : code NULL = « votre territoire » (générique).
        $catRegion = $c['region_code'] ?? null;
        $catDept   = $c['dept_code'] ?? null;
        if ($geo === 'region') {
            if ($catRegion === null) { $score += 22; $reasons[] = 'S\'applique à votre région.'; }
            elseif ($prof['region_code'] === null) { $unknown = true; $score += 8; $reasons[] = 'Région de l\'asso inconnue — à confirmer.'; }
            elseif ($catRegion === $prof['region_code']) { $score += 25; $reasons[] = 'Votre région est concernée.'; }
            else { $hard_out = true; $reasons[] = 'Autre région.'; }
        } elseif ($geo === 'departement') {
            if ($catDept === null) { $score += 22; $reasons[] = 'S\'applique à votre département.'; }
            elseif ($prof['dept_code'] === null) { $unknown = true; $score += 8; $reasons[] = 'Département de l\'asso inconnu — à confirmer.'; }
            elseif ($catDept === $prof['dept_code']) { $score += 25; $reasons[] = 'Votre département est concerné.'; }
            else { $hard_out = true; $reasons[] = 'Autre département.'; }
        } elseif ($geo === 'commune') {
            $score += 20; $reasons[] = 'À solliciter auprès de votre commune / interco.';
        }
    }

    // ── Secteurs d'activité ─────────────────────────────────────────
    $catSectors = array_filter(array_map('trim', explode(',', (string)($c['sectors'] ?? ''))));
    if (!$catSectors) {
        $score += 25; $reasons[] = 'Tous secteurs.';
    } elseif (!$prof['sectors']) {
        $unknown = true; $score += 10;
        $reasons[] = 'Renseignez vos secteurs pour affiner le score.';
    } else {
        $inter = array_intersect($prof['sectors'], $catSectors);
        if ($inter) {
            $score += 25;
            $reasons[] = 'Correspond à votre secteur (' . implode(', ', $inter) . ').';
        } else {
            $score += 3;
            $reasons[] = 'Secteur a priori différent (' . implode(', ', $catSectors) . ').';
        }
    }

    // ── QPV requis ──────────────────────────────────────────────────
    if (!empty($c['req_qpv'])) {
        if ($prof['is_qpv'] === 1) { $score += 15; $reasons[] = 'Action en QPV : éligible.'; }
        elseif ($prof['is_qpv'] === 0) { $hard_out = true; $reasons[] = 'Réservé aux projets en quartier prioritaire (QPV).'; }
        else { $unknown = true; $reasons[] = 'Vérifiez si votre action se situe en QPV.'; }
    }

    // ── Intérêt général requis (reçu fiscal) ────────────────────────
    if (!empty($c['req_interet_general'])) {
        if ($prof['is_interet_general'] === 1) { $score += 10; $reasons[] = 'Caractère d\'intérêt général confirmé.'; }
        elseif ($prof['is_interet_general'] === 0) { $score += 0; $reasons[] = 'Exige le caractère d\'intérêt général (à sécuriser).'; }
        else { $unknown = true; $reasons[] = 'Exige le caractère d\'intérêt général.'; }
    }

    if ($score > 100) $score = 100;

    // ── Verdict ─────────────────────────────────────────────────────
    if ($hard_out) {
        return ['score' => 0, 'eligibility' => 'ineligible', 'reasons' => $reasons];
    }
    if ($score >= 70 && !$unknown)      $elig = 'eligible';
    elseif ($score >= 55)               $elig = 'probable';
    else                                $elig = 'a_verifier';

    return ['score' => $score, 'eligibility' => $elig, 'reasons' => $reasons];
}
}

if (!function_exists('fin_compute_matches')) {
/**
 * Recalcule les correspondances de l'org et les persiste dans grant_matches.
 * On ne touche pas aux flags dismissed/saved posés par l'utilisateur.
 * Retourne le nombre de dispositifs évalués (hors inéligibles).
 */
function fin_compute_matches(PDO $pdo, int $org_id): int {
    $prof = fin_build_profile($pdo, $org_id);

    // Catalogue applicable : global (org_id NULL) OU propre à l'org, actif.
    $st = $pdo->prepare("SELECT * FROM grant_catalog WHERE status = 'active' AND (org_id IS NULL OR org_id = ?)");
    $st->execute([$org_id]);
    $rows = $st->fetchAll();

    $upsert = $pdo->prepare(
        "INSERT INTO grant_matches (org_id, catalog_id, score, eligibility, reasons, computed_at)
         VALUES (:org, :cat, :score, :elig, :reasons, NOW())
         ON DUPLICATE KEY UPDATE score=VALUES(score), eligibility=VALUES(eligibility),
             reasons=VALUES(reasons), computed_at=NOW()"
    );

    $kept = 0;
    foreach ($rows as $c) {
        $r = fin_score($c, $prof);
        if ($r['eligibility'] === 'ineligible') {
            // On purge une éventuelle correspondance obsolète (sauf si sauvegardée).
            try {
                $pdo->prepare("DELETE FROM grant_matches WHERE org_id = ? AND catalog_id = ? AND saved = 0")
                    ->execute([$org_id, (int)$c['id']]);
            } catch (Throwable $e) {}
            continue;
        }
        $upsert->execute([
            ':org' => $org_id, ':cat' => (int)$c['id'],
            ':score' => (int)$r['score'], ':elig' => $r['eligibility'],
            ':reasons' => json_encode($r['reasons'], JSON_UNESCAPED_UNICODE),
        ]);
        $kept++;
    }
    return $kept;
}
}

if (!function_exists('fin_list_matches')) {
/**
 * Liste les correspondances de l'org (join catalog), triées par pertinence.
 * $opts : ['include_dismissed'=>bool, 'eligibility'=>string|null, 'saved_only'=>bool]
 */
function fin_list_matches(PDO $pdo, int $org_id, array $opts = []): array {
    $where = "m.org_id = ?";
    $params = [$org_id];
    if (empty($opts['include_dismissed'])) $where .= " AND m.dismissed = 0";
    if (!empty($opts['saved_only']))       $where .= " AND m.saved = 1";
    if (!empty($opts['eligibility']) && in_array($opts['eligibility'], ['eligible','probable','a_verifier'], true)) {
        $where .= " AND m.eligibility = ?"; $params[] = $opts['eligibility'];
    }
    $sql = "SELECT m.*, c.title, c.funder_name, c.funder_type, c.summary, c.geo_scope,
                   c.sectors, c.amount_min, c.amount_max, c.recurrence, c.deadline_apply,
                   c.next_expected, c.apply_url, c.source_url, c.program_code
            FROM grant_matches m
            JOIN grant_catalog c ON c.id = m.catalog_id
            WHERE $where
            ORDER BY
              FIELD(m.eligibility,'eligible','probable','a_verifier'),
              (c.deadline_apply IS NOT NULL AND c.deadline_apply >= CURDATE()) DESC,
              m.score DESC, c.deadline_apply ASC, c.title ASC";
    $st = $pdo->prepare($sql);
    $st->execute($params);
    return $st->fetchAll();
}
}

if (!function_exists('fin_stats')) {
/** KPIs pour le bandeau du radar. */
function fin_stats(PDO $pdo, int $org_id): array {
    $r = ['total' => 0, 'eligible' => 0, 'probable' => 0, 'saved' => 0, 'deadline_30' => 0, 'potential_max' => 0.0];
    try {
        $st = $pdo->prepare(
            "SELECT
                COUNT(*) t,
                SUM(m.eligibility='eligible') el,
                SUM(m.eligibility='probable') pr,
                SUM(m.saved=1) sv,
                SUM(c.deadline_apply IS NOT NULL AND c.deadline_apply >= CURDATE() AND c.deadline_apply <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)) d30,
                COALESCE(SUM(CASE WHEN m.eligibility IN ('eligible','probable') THEN c.amount_max ELSE 0 END),0) pmax
             FROM grant_matches m JOIN grant_catalog c ON c.id = m.catalog_id
             WHERE m.org_id = ? AND m.dismissed = 0");
        $st->execute([$org_id]);
        if ($x = $st->fetch()) {
            $r['total'] = (int)$x['t']; $r['eligible'] = (int)$x['el']; $r['probable'] = (int)$x['pr'];
            $r['saved'] = (int)$x['sv']; $r['deadline_30'] = (int)$x['d30']; $r['potential_max'] = (float)$x['pmax'];
        }
    } catch (Throwable $e) {}
    return $r;
}
}

if (!function_exists('fin_eligibility_meta')) {
function fin_eligibility_meta(string $e): array {
    return [
        'eligible'   => ['Éligible',    '#065F46', '#D1FAE5', '✅'],
        'probable'   => ['Probable',    '#92400E', '#FEF3C7', '🟡'],
        'a_verifier' => ['À vérifier',  '#3730A3', '#E0E7FF', '🔎'],
        'ineligible' => ['Non éligible','#991B1B', '#FEE2E2', '⛔'],
    ][$e] ?? ['—', '#374151', '#F3F4F6', '·'];
}
}

if (!function_exists('fin_sectors_catalog')) {
/** Référentiel des secteurs proposés dans le formulaire de profil. */
function fin_sectors_catalog(): array {
    return [
        'sport' => '🏅 Sport', 'culture' => '🎭 Culture', 'social' => '🤝 Social / solidarité',
        'sante' => '❤️ Santé', 'education' => '📚 Éducation', 'jeunesse' => '🧒 Jeunesse',
        'environnement' => '🌱 Environnement', 'famille' => '👨‍👩‍👧 Famille / parentalité',
        'emploi' => '💼 Emploi / insertion', 'numerique' => '💻 Numérique', 'patrimoine' => '🏛️ Patrimoine',
    ];
}
}
