<?php
/**
 * founder-prospects-enrich.php — Enrichit les prospects importés avec leur
 * localisation officielle (nom, ville, département, région) via les données
 * publiques de l'État (recherche-entreprises.api.gouv.fr), et les référence
 * dans l'annuaire (asso_directory : région → département → catégorie).
 *
 * Corrige aussi les colonnes mélangées à l'import (ville/nom inversés) en
 * réalignant sur le nom officiel trouvé.
 *
 * SÉCURITÉ : CLI de préférence, ou navigateur avec ?key=.
 *   php founder-prospects-enrich.php              # enrichit les prospects non encore enrichis
 *   php founder-prospects-enrich.php --all        # ré-enrichit TOUT (même déjà fait)
 *   php founder-prospects-enrich.php --limit=50   # borne le nombre traité
 *
 * Idempotent, courtois avec l'API (≈5 req/s). Le serveur O2switch a accès internet.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/api/_app-prospect.php';
require_once __DIR__ . '/api/_app-directory.php';

const ENRICH_SECRET = 'assokit-enrich-5c2b90';

$is_cli = (PHP_SAPI === 'cli');
if (!$is_cli) {
    header('Content-Type: text/plain; charset=utf-8');
    if (($_GET['key'] ?? '') !== ENRICH_SECRET) { http_response_code(403); exit("403 — clé invalide.\n"); }
}
function say($m) { echo $m . "\n"; @flush(); }

ak_prospect_tables_ensure($pdo);
ak_directory_tables_ensure($pdo);

// Options
$argsAll = $is_cli ? $argv : array_map(fn($k, $v) => "--$k=$v", array_keys($_GET), array_values($_GET));
$reAll = in_array('--all', $argsAll, true) || (($_GET['all'] ?? '') === '1');
$limit = 1000;
foreach ($argsAll as $a) { if (preg_match('/^--limit=(\d+)/', (string) $a, $m)) $limit = (int) $m[1]; }

$API = 'https://recherche-entreprises.api.gouv.fr/search';
$CATS = ak_directory_categories();
// Table inverse NAF → catégorie
$NAF2CAT = [];
foreach ($CATS as $key => $conf) foreach ($conf['naf'] as $naf) if (!isset($NAF2CAT[$naf])) $NAF2CAT[$naf] = $key;

function api_get(string $url): ?array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 25, CURLOPT_USERAGENT => 'Assokit-enrich/1.0']);
    $r = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    if ($code !== 200 || !$r) return null;
    $j = json_decode($r, true);
    return is_array($j) ? $j : null;
}

// Sélection des prospects à enrichir
$where = $reAll ? '1=1' : 'enriched_at IS NULL';
$rows = [];
try {
    $st = $pdo->query("SELECT id, name, org_name, type, email, city FROM asso_prospects WHERE $where ORDER BY id ASC LIMIT " . (int) $limit);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { say('Erreur select : ' . $e->getMessage()); exit(1); }

say("=== Enrichissement de " . count($rows) . " prospect(s) ===");

$upP = $pdo->prepare("UPDATE asso_prospects SET org_name=?, city=?, dept_code=?, dept_name=?, region=?, category=COALESCE(?, category), enriched_at=NOW() WHERE id=?");
$upDir = $pdo->prepare("INSERT INTO asso_directory (siren, org_name, category, naf, city, zip, dept_code, dept_name, region, address, email, source, created_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'prospect_enrich', NOW())
                        ON DUPLICATE KEY UPDATE org_name=VALUES(org_name), category=VALUES(category), city=VALUES(city),
                            zip=VALUES(zip), dept_code=VALUES(dept_code), dept_name=VALUES(dept_name), region=VALUES(region), email=VALUES(email)");

$ok = 0; $miss = 0;
foreach ($rows as $p) {
    $id = (int) $p['id'];
    // Requête : on combine les textes disponibles (le nom réel de l'asso est parfois
    // dans 'name', la ville dans 'org_name' — l'API full-text retrouve quand même).
    $q = trim(preg_replace('/\s+/', ' ', (string) $p['name'] . ' ' . (string) $p['org_name'] . ' ' . (string) $p['city']));
    if ($q === '') { $miss++; continue; }
    $isAsso = (($p['type'] ?? 'asso') !== 'tpe');
    $params = ['q' => $q, 'per_page' => 5, 'page' => 1, 'etat_administratif' => 'A'];
    if ($isAsso) $params['nature_juridique'] = '9220';
    $data = api_get($API . '?' . http_build_query($params));
    // Repli sans filtre association si rien
    if ((!$data || empty($data['results'])) && $isAsso) {
        unset($params['nature_juridique']);
        $data = api_get($API . '?' . http_build_query($params));
    }
    if (!$data || empty($data['results'])) { $miss++; say("  ✗ #$id : introuvable ($q)"); usleep(180000); continue; }

    $e = $data['results'][0];
    $siege = $e['siege'] ?? [];
    $siren = (string) ($e['siren'] ?? '');
    $officialName = (string) ($e['nom_complet'] ?? $e['nom_raison_sociale'] ?? $p['name'] ?? $p['org_name']);
    $city = (string) ($siege['libelle_commune'] ?? $p['city'] ?? '');
    $zip  = (string) ($siege['code_postal'] ?? '');
    $naf  = (string) ($e['activite_principale'] ?? $siege['activite_principale'] ?? '');
    // Département : champ direct si présent, sinon dérivé du code postal
    $deptCode = (string) ($siege['departement'] ?? '');
    if ($deptCode === '' && $zip !== '') $deptCode = (strpos($zip, '97') === 0 || strpos($zip, '98') === 0) ? substr($zip, 0, 3) : substr($zip, 0, 2);
    [$deptName, $region] = ak_dept_region($deptCode);
    $cat = $NAF2CAT[$naf] ?? null;

    try {
        $upP->execute([$officialName, $city ?: null, $deptCode ?: null, $deptName ?: null, $region ?: null, $cat, $id]);
        if ($siren !== '') {
            $upDir->execute([$siren, $officialName, $cat ?: 'autre', $naf ?: null, $city ?: null, $zip ?: null,
                             $deptCode ?: null, $deptName ?: null, $region ?: null, trim((string) ($siege['adresse'] ?? '')) ?: null, (string) $p['email']]);
        }
        $ok++;
        say("  ✓ #$id : $officialName — $city ($deptCode · $region)");
    } catch (Throwable $ex) { $miss++; say("  ! #$id : " . $ex->getMessage()); }
    usleep(170000); // ~6 req/s
}

say("\n===================================================");
say("  ENRICHISSEMENT TERMINÉ ✅  ($ok enrichi(s), $miss sans correspondance)");
say("  Localisation (ville/département/région) mise à jour sur les prospects,");
say("  et référencée dans l'annuaire (région → département → catégorie).");
say("===================================================");
