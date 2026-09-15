<?php
/**
 * nettoyer-prospection-imports.php — Repartir d'une prospection vierge.
 * ------------------------------------------------------------------
 * Efface les fiches de prospection issues d'un import, pour pouvoir
 * réimporter les mêmes fichiers en les qualifiant correctement.
 *
 * Pourquoi un script plutôt que le bouton « Retirer cet import » : les
 * fiches versées avant le suivi des imports n'appartiennent à aucun lot.
 * Elles n'apparaissent donc pas dans la liste des fichiers et ne peuvent
 * pas être retirées depuis la page.
 *
 * Ce qui est effacé : les fiches dont la provenance est « import », leur
 * historique, et les lots d'import.
 * Ce qui ne l'est pas : les fiches saisies à la main et celles collectées
 * par code QR. Ce sont des contacts qu'on a rencontrés, pas un fichier
 * qu'on peut recharger.
 *
 * Usage :
 *     php nettoyer-prospection-imports.php              (montre, n'efface rien)
 *     php nettoyer-prospection-imports.php --confirmer  (efface)
 *     php nettoyer-prospection-imports.php --org=23 --confirmer
 *
 * Sans --confirmer, rien n'est touché : le script se contente de dire ce
 * qu'il ferait. Une suppression définitive mérite d'être lue avant d'être
 * lancée.
 * ------------------------------------------------------------------
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); die('CLI uniquement.'); }

require_once __DIR__ . '/config.php';
if (!isset($pdo) || !($pdo instanceof PDO)) { fwrite(STDERR, "PDO indisponible (config.php).\n"); exit(1); }
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
try { $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true); } catch (Throwable $e) {}

$confirme = in_array('--confirmer', $argv, true);
$org = 0;
foreach ($argv as $a) {
    if (preg_match('/^--org=(\d+)$/', $a, $m)) $org = (int) $m[1];
}

$filtreOrg = $org > 0 ? ' AND org_id = ' . $org : '';

try {
    $pdo->query("SELECT 1 FROM asso_prospection LIMIT 1")->closeCursor();
} catch (Throwable $e) {
    fwrite(STDERR, "La table asso_prospection n'existe pas. Passez d'abord :\n"
        . "  php migrations/2026-09-14-prospection-tables-dediees.php\n");
    exit(1);
}

// ------------------------------------------------------------------
// Ce qu'il y a, avant de toucher à quoi que ce soit
// ------------------------------------------------------------------
echo "État actuel" . ($org > 0 ? " (association $org)" : " (toutes associations)") . "\n";

$parOrigine = $pdo->query("
    SELECT org_id,
           SUM(source = 'import') AS importees,
           SUM(source = 'qr')     AS qr,
           SUM(source NOT IN ('import','qr') OR source IS NULL) AS manuelles,
           COUNT(*) AS total
    FROM asso_prospection
    WHERE 1=1 $filtreOrg
    GROUP BY org_id ORDER BY org_id")->fetchAll(PDO::FETCH_ASSOC);

if (!$parOrigine) { echo "  Aucune fiche de prospection.\n"; exit(0); }

printf("  %-8s %10s %10s %10s %8s\n", 'asso', 'importées', 'QR', 'manuelles', 'total');
$aEffacer = 0;
foreach ($parOrigine as $r) {
    printf("  %-8s %10d %10d %10d %8d\n", $r['org_id'],
           $r['importees'], $r['qr'], $r['manuelles'], $r['total']);
    $aEffacer += (int) $r['importees'];
}

$lots = 0;
try {
    $lots = (int) $pdo->query("SELECT COUNT(*) FROM asso_prospection_imports WHERE 1=1 $filtreOrg")->fetchColumn();
} catch (Throwable $e) { /* migration des lots pas encore passée */ }

echo "\n";
echo "À effacer : $aEffacer fiche(s) importée(s)" . ($lots ? " et $lots lot(s) d'import" : '') . ".\n";
echo "Conservées : les fiches saisies à la main et celles venues d'un code QR.\n";

if ($aEffacer === 0 && $lots === 0) { echo "\nRien à faire.\n"; exit(0); }

if (!$confirme) {
    echo "\nRien n'a été effacé. Pour le faire :\n";
    echo "  php nettoyer-prospection-imports.php" . ($org > 0 ? " --org=$org" : '') . " --confirmer\n";
    exit(0);
}

// ------------------------------------------------------------------
// Suppression
// ------------------------------------------------------------------
echo "\nSuppression…\n";
$pdo->beginTransaction();
try {
    // L'historique d'abord : le supprimer après laisserait, entre les deux
    // requêtes, des événements pointant vers des fiches disparues.
    $st = $pdo->prepare("DELETE e FROM asso_prospection_events e
                         JOIN asso_prospection p ON p.id = e.prospect_id
                         WHERE p.source = 'import'"
                         . ($org > 0 ? ' AND p.org_id = ' . $org : ''));
    $st->execute();
    echo '  historique : ' . $st->rowCount() . " événement(s)\n";

    $st = $pdo->prepare("DELETE FROM asso_prospection WHERE source = 'import'" . $filtreOrg);
    $st->execute();
    echo '  fiches     : ' . $st->rowCount() . " effacée(s)\n";

    try {
        $st = $pdo->prepare("DELETE FROM asso_prospection_imports WHERE 1=1" . $filtreOrg);
        $st->execute();
        echo '  lots       : ' . $st->rowCount() . " effacé(s)\n";
    } catch (Throwable $e) { /* table absente : rien à nettoyer */ }

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, "\nÉchec, rien n'a été modifié : " . $e->getMessage() . "\n");
    exit(1);
}

// ------------------------------------------------------------------
// Vérification
// ------------------------------------------------------------------
$reste = (int) $pdo->query("SELECT COUNT(*) FROM asso_prospection WHERE source = 'import'" . $filtreOrg)->fetchColumn();
$orphelins = (int) $pdo->query("SELECT COUNT(*) FROM asso_prospection_events e
                                LEFT JOIN asso_prospection p ON p.id = e.prospect_id
                                WHERE p.id IS NULL")->fetchColumn();
$garde = (int) $pdo->query("SELECT COUNT(*) FROM asso_prospection WHERE 1=1" . $filtreOrg)->fetchColumn();

echo "\nVérification\n";
echo "  fiches importées restantes : $reste\n";
echo "  événements orphelins       : $orphelins\n";
echo "  fiches conservées          : $garde\n";

if ($reste > 0 || $orphelins > 0) {
    fwrite(STDERR, "\nNettoyage incomplet.\n");
    exit(1);
}

echo "\nTerminé. Réimportez vos fichiers en choisissant leur nature dans la liste\n";
echo "déroulante à côté du bouton Importer.\n";
exit(0);
