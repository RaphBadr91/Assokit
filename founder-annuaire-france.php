<?php
/**
 * founder-annuaire-france.php — Construit l'ANNUAIRE NATIONAL des associations
 * dans le dashboard fondateur (table asso_directory), rangé Région → Département → Catégorie.
 *
 * Source : recherche-entreprises.api.gouv.fr (données ouvertes de l'État, LÉGALES).
 *   → nom, catégorie, commune, département, région, SIREN, NAF.
 *   → PAS d'email (absent des données publiques) : colonne vide, à remplir légalement.
 *
 * Le serveur O2switch a accès internet : lancer en SSH.
 *
 * Exemples :
 *   php founder-annuaire-france.php                 # TOUTE la France, toutes catégories
 *   php founder-annuaire-france.php 91              # un seul département (Essonne)
 *   php founder-annuaire-france.php 75,92,93,94 sport,culture   # départements + catégories choisis
 *   php founder-annuaire-france.php idf             # raccourci : Île-de-France
 *
 * Idempotent : réexécutable, ne crée pas de doublons (clé unique SIREN).
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/api/_app-directory.php';

ak_directory_tables_ensure($pdo);

$API = 'https://recherche-entreprises.api.gouv.fr/search';
$CATS = ak_directory_categories();
$DEPT_MAP = ak_dept_region_map();

// --- Résolution des arguments -------------------------------------------------
$arg_depts = $argv[1] ?? 'all';
$arg_cats  = $argv[2] ?? 'all';

$SHORTCUTS = [
    'idf' => ['75','77','78','91','92','93','94','95'],
    'all' => array_keys($DEPT_MAP),
];

if (isset($SHORTCUTS[$arg_depts])) {
    $depts = $SHORTCUTS[$arg_depts];
} else {
    $depts = array_values(array_filter(array_map('trim', explode(',', $arg_depts))));
    $depts = array_values(array_intersect($depts, array_keys($DEPT_MAP)));
}
if (!$depts) { fwrite(STDERR, "Aucun département valide.\n"); exit(1); }

if ($arg_cats === 'all') {
    $cats = array_keys($CATS);
} else {
    $cats = array_values(array_filter(array_map('trim', explode(',', $arg_cats))));
    $cats = array_values(array_intersect($cats, array_keys($CATS)));
}
if (!$cats) { fwrite(STDERR, "Aucune catégorie valide.\n"); exit(1); }

function api_get(string $url): ?array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_USERAGENT => 'Assokit-annuaire/1.0']);
    $r = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    if ($code !== 200 || !$r) return null;
    $j = json_decode($r, true);
    return is_array($j) ? $j : null;
}

$ins = $pdo->prepare("INSERT INTO asso_directory
    (siren, org_name, category, naf, city, zip, dept_code, dept_name, region, address, source, created_at)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'rna_open_data', NOW())
    ON DUPLICATE KEY UPDATE
        org_name = VALUES(org_name), category = VALUES(category), naf = VALUES(naf),
        city = VALUES(city), zip = VALUES(zip), dept_code = VALUES(dept_code),
        dept_name = VALUES(dept_name), region = VALUES(region), address = VALUES(address)");

$grand_total = 0;
$t0 = time();

foreach ($depts as $dept) {
    [$dept_name, $region] = ak_dept_region($dept);
    $dept_added = 0;
    foreach ($cats as $cat) {
        $conf = $CATS[$cat];
        $seen = [];
        foreach ($conf['naf'] as $naf) {
            for ($page = 1; $page <= 40; $page++) { // 40 x 25 = 1000 max / NAF / dept
                $qs = http_build_query([
                    'departement'         => $dept,
                    'nature_juridique'    => '9220',        // Association déclarée
                    'activite_principale' => $naf,
                    'etat_administratif'  => 'A',           // actives
                    'per_page'            => 25,
                    'page'                => $page,
                ]);
                $data = api_get("$API?$qs");
                if (!$data || empty($data['results'])) break;
                foreach ($data['results'] as $e) {
                    $siren = (string) ($e['siren'] ?? '');
                    if ($siren === '' || isset($seen[$siren])) continue;
                    $seen[$siren] = 1;
                    $siege = $e['siege'] ?? [];
                    try {
                        $ins->execute([
                            $siren,
                            mb_substr((string) ($e['nom_complet'] ?? $e['nom_raison_sociale'] ?? '—'), 0, 220),
                            $cat,
                            $naf,
                            mb_substr((string) ($siege['libelle_commune'] ?? ''), 0, 140),
                            (string) ($siege['code_postal'] ?? ''),
                            $dept,
                            $dept_name,
                            $region,
                            mb_substr(trim((string) ($siege['adresse'] ?? '')), 0, 255),
                        ]);
                        $dept_added++; $grand_total++;
                    } catch (Throwable $ex) {}
                }
                if (count($data['results']) < 25) break;
                usleep(160000); // ~6 req/s : courtois avec l'API publique
            }
        }
    }
    echo sprintf("• %-3s %-26s [%s] : %d assos\n", $dept, $dept_name, $region, $dept_added);
}

$mins = round((time() - $t0) / 60, 1);
echo "\n===================================================\n";
echo "  ANNUAIRE CONSTRUIT ✅  ($grand_total lignes, {$mins} min)\n";
echo "  Départements : " . count($depts) . " · Catégories : " . implode(', ', $cats) . "\n";
echo "  Table : asso_directory  →  visible dans le dashboard fondateur.\n";
echo "  Rappel : la colonne email est VIDE (données publiques sans email).\n";
echo "  À remplir légalement (contact@ publiés + opt-out) avant tout envoi.\n";
echo "===================================================\n";
