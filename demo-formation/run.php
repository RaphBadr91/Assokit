<?php
/**
 * demo-formation/run.php — Reconstruit l'association DEMO F de A à Z.
 * ------------------------------------------------------------------
 * Appelé par seed-demo-formation.php (à la main, en SSH) et par
 * cron-demo-reset.php (chaque nuit). Ne fait rien à l'inclusion :
 * il faut appeler df_run().
 *
 * Les modules sont les fichiers « NN-nom.php » de ce dossier, chargés
 * dans l'ordre de leur numéro. Chacun définit :
 *   df_seed_<nom>()   obligatoire — insère les données du module ;
 *   df_pre_<nom>()    facultatif  — crée une table que l'application
 *                     crée d'habitude à sa première utilisation. Appelé
 *                     AVANT la transaction (un CREATE TABLE la validerait).
 * Le nom est celui du fichier, tirets changés en soulignés :
 *   « 30-notes-de-frais.php » → df_seed_notes_de_frais().
 *
 * Un module qui échoue est consigné dans le rapport ; les autres
 * continuent. Une démo à 95 % vaut mieux qu'une démo absente.
 * ------------------------------------------------------------------
 */

require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/purge.php';
require_once __DIR__ . '/00-organisation.php';
require_once __DIR__ . '/catalogue.php';

/** Fichiers de modules, triés : [nom => chemin]. */
function df_modules(): array
{
    $mods = [];
    foreach (glob(__DIR__ . '/[0-9][0-9]-*.php') ?: [] as $f) {
        $base = basename($f, '.php');
        if (strpos($base, '00-') === 0) continue;
        $nom = str_replace('-', '_', substr($base, 3));
        $mods[$nom] = $f;
    }
    // Ordre des numéros de fichier, pas des noms.
    uasort($mods, fn($a, $b) => strcmp(basename($a), basename($b)));
    // Mise au point : DF_MODULES=projets,agenda ne joue que ces modules
    // (l'abonnement est toujours joué). Jamais utilisé en production.
    $filtre = getenv('DF_MODULES');
    if ($filtre) {
        $garder = array_flip(array_merge(['abonnement'], array_map('trim', explode(',', $filtre))));
        $mods = array_intersect_key($mods, $garder);
    }
    return $mods;
}

/**
 * @param PDO      $pdo
 * @param callable $log  function(string $ligne): void
 * @return array   le rapport (voir DF::$rapport) + 'org' + 'purge' + 'restes'
 */
function df_run(PDO $pdo, callable $log): array
{
    DF::$pdo = $pdo;
    DF::$log = $log;
    DF::$t0 = strtotime('today');
    DF::$u = [];
    DF::$g = [];
    DF::$ids = [];
    DF::$rapport = [
        'inseres' => [], 'tables_absentes' => [], 'colonnes_ignorees' => [],
        'enum' => [], 'erreurs' => [], 'modules' => [], 'purge_notes' => [], 'notes' => [],
    ];
    DF::reset_caches();
    df_graine();

    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    try { $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true); } catch (Throwable $e) {}
    // Hors mode strict, comme les autres seeders du projet. Les ENUM sont
    // validés par df_ligne() : rien ne sera tronqué en silence.
    $pdo->exec("SET SESSION sql_mode = 'NO_ENGINE_SUBSTITUTION'");
    $pdo->exec("SET NAMES utf8mb4");

    $mods = df_modules();
    foreach ($mods as $f) require_once $f;

    // Tables créées d'ordinaire par l'application à la première visite.
    foreach ($mods as $nom => $f) {
        $pre = 'df_pre_' . $nom;
        if (function_exists($pre)) {
            try { $pre(); } catch (Throwable $e) { df_erreur("pré-module $nom : " . $e->getMessage()); }
        }
    }
    DF::reset_caches();

    $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
    $pdo->beginTransaction();
    try {
        $log('• Organisation');
        DF::$org = df_organisation();
        $log('  DEMO F = org #' . DF::$org);

        $log('• Purge des données de la veille');
        $purge = df_purger(DF::$org);
        $log('  ' . array_sum($purge) . ' lignes effacées dans ' . count($purge) . ' tables');

        $log('• Personnes');
        df_seed_personnes();
        $log('  ' . (DF::$rapport['inseres']['users'] ?? 0) . ' comptes et adhérents');

        foreach ($mods as $nom => $f) {
            $fn = 'df_seed_' . $nom;
            if (!function_exists($fn)) {
                DF::$rapport['modules'][$nom] = "fonction $fn absente";
                continue;
            }
            $avant = array_sum(DF::$rapport['inseres']);
            df_graine(crc32($nom) % 100000);
            try {
                $fn();
                $n = array_sum(DF::$rapport['inseres']) - $avant;
                DF::$rapport['modules'][$nom] = 'ok';
                $log(sprintf('• %-22s %5d lignes', $nom, $n));
            } catch (Throwable $e) {
                DF::$rapport['modules'][$nom] = $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')';
                df_erreur("module $nom : " . DF::$rapport['modules'][$nom]);
            }
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
        throw $e;
    }
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");

    $r = DF::$rapport;
    $r['org'] = DF::$org;
    $r['purge'] = $purge;
    $r['restes'] = [];
    try { $r['restes'] = df_purge_restes(DF::$org); } catch (Throwable $e) {}
    $r['personnages'] = DF::$u;
    return $r;
}
