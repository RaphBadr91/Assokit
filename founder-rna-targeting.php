<?php
/**
 * founder-rna-targeting.php — Génère une liste ciblée d'associations depuis l'API publique
 * de l'État (recherche-entreprises.api.gouv.fr) — données LÉGALES et publiques (nom, ville,
 * adresse, SIREN, activité). NE CONTIENT PAS d'emails (à collecter ensuite de façon conforme).
 *
 * Usage (Terminal O2switch, le serveur a accès internet) :
 *   php founder-rna-targeting.php            # les 3 familles (sport/culture/social) en Essonne
 *   php founder-rna-targeting.php sport      # une seule famille
 *
 * Sortie : fichiers CSV cibles-91-<famille>.csv  (email;prenom;nom;organisation;ville)
 *          -> colonne email VIDE, à remplir légalement (contact@ publiés / fournisseur B2B conforme).
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }

$DEPT = '91'; // Essonne
$API  = 'https://recherche-entreprises.api.gouv.fr/search';

// Familles → codes NAF (activité principale). Les assos ont souvent 94.99Z (adhésion volontaire) :
// on combine codes thématiques + ce code générique filtré par mot-clé.
$FAMILIES = [
    'sport'   => ['naf' => ['93.12Z', '93.19Z', '93.11Z', '85.51Z'], 'kw' => 'sport'],
    'culture' => ['naf' => ['90.01Z', '90.04Z', '90.03B', '90.02Z', '91.03Z'], 'kw' => 'culture'],
    'social'  => ['naf' => ['88.99B', '88.99A', '88.10C', '88.91A'], 'kw' => 'social'],
];

$only = $argv[1] ?? null;
$targets = $only && isset($FAMILIES[$only]) ? [$only => $FAMILIES[$only]] : $FAMILIES;

function api_get(string $url): ?array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 25, CURLOPT_USERAGENT => 'Assokit-targeting/1.0']);
    $r = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    if ($code !== 200 || !$r) return null;
    $j = json_decode($r, true);
    return is_array($j) ? $j : null;
}

foreach ($targets as $fam => $conf) {
    echo "=== Famille : $fam (Essonne $DEPT) ===\n";
    $seen = []; $rows = [];
    foreach ($conf['naf'] as $naf) {
        for ($page = 1; $page <= 20; $page++) { // jusqu'à 20 pages x 25 = 500 / code NAF
            $qs = http_build_query([
                'departement'         => $DEPT,
                'nature_juridique'    => '9220', // Association déclarée
                'activite_principale' => $naf,
                'per_page'            => 25,
                'page'                => $page,
                'etat_administratif'  => 'A', // actives seulement
            ]);
            $data = api_get("$API?$qs");
            if (!$data || empty($data['results'])) break;
            foreach ($data['results'] as $e) {
                $siren = (string) ($e['siren'] ?? '');
                if ($siren === '' || isset($seen[$siren])) continue;
                $seen[$siren] = 1;
                $siege = $e['siege'] ?? [];
                $rows[] = [
                    'nom'   => (string) ($e['nom_complet'] ?? $e['nom_raison_sociale'] ?? ''),
                    'ville' => (string) ($siege['libelle_commune'] ?? ''),
                    'cp'    => (string) ($siege['code_postal'] ?? ''),
                    'adr'   => trim((string) ($siege['adresse'] ?? '')),
                    'siren' => $siren,
                ];
            }
            if (count($data['results']) < 25) break;
            usleep(180000); // ~5 req/s : on reste courtois avec l'API
        }
    }
    // Écrit le CSV au format d'import de l'app (email vide à compléter)
    $file = __DIR__ . "/cibles-91-$fam.csv";
    $fh = fopen($file, 'w');
    fputcsv($fh, ['email', 'prenom', 'nom', 'organisation', 'ville'], ';');
    foreach ($rows as $r) {
        fputcsv($fh, ['', '', '', $r['nom'], $r['ville']], ';');
    }
    fclose($fh);
    echo "  → " . count($rows) . " associations trouvées · fichier : cibles-91-$fam.csv\n";
}
echo "\nTerminé. La colonne 'email' est VIDE : à remplir légalement (contact@ publiés ou fournisseur B2B conforme) avant import dans l'app.\n";
