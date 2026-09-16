<?php
/**
 * diagnostic-charge.php — Où en est la base, et jusqu'où elle tient.
 * ------------------------------------------------------------------
 * Répond à une seule question : « est-ce qu'Assokit encaisse 5 000 puis
 * 10 000 associations ? »
 *
 * Personne ne peut y répondre depuis le code seul, parce que ce qui
 * coûte cher ne dépend pas du nombre de lignes écrites mais du nombre
 * de lignes LUES pour en afficher une. Ce script mesure les deux sur la
 * base réelle : ce qu'elle contient aujourd'hui, ce que ça donnera au
 * volume visé, et combien de lignes MySQL doit parcourir pour servir les
 * pages les plus chargées.
 *
 * Lecture seule. Aucune écriture, aucune modification de schéma.
 *
 * Usage :
 *   php diagnostic-charge.php            (projection à 10 000 associations)
 *   php diagnostic-charge.php 5000       (projection à 5 000)
 *
 * Réservé à la ligne de commande : le fichier est dans la racine web,
 * donc accessible en HTTP, et il expose la taille et la structure de la
 * base. Le garde ci-dessous est ce qui empêche de le servir.
 * ------------------------------------------------------------------
 */

if (PHP_SAPI !== 'cli' && isset($_SERVER['REQUEST_METHOD'])) { http_response_code(403); die('Forbidden'); }

require_once __DIR__ . '/config.php';
if (!isset($pdo) || !($pdo instanceof PDO)) { fwrite(STDERR, "PDO indisponible (config.php).\n"); exit(1); }
try { $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true); } catch (Throwable $e) {}

$cible = max(1, (int) ($argv[1] ?? 10000));
$base  = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();

function mo(float $octets): string
{
    if ($octets >= 1073741824) return number_format($octets / 1073741824, 1, ',', ' ') . ' Go';
    if ($octets >= 1048576)    return number_format($octets / 1048576, 0, ',', ' ') . ' Mo';
    return number_format($octets / 1024, 0, ',', ' ') . ' Ko';
}
function nb(float $n): string { return number_format($n, 0, ',', ' '); }

/**
 * Cale une chaîne à gauche sur $n caractères.
 * printf() compte des octets : « activité » lui en fait 9 pour 8
 * caractères, et toute la colonne se décale. On aligne donc à la main.
 */
function cale(string $s, int $n): string
{
    $l = mb_strlen($s, 'UTF-8');
    return $l >= $n ? mb_substr($s, 0, $n, 'UTF-8') : $s . str_repeat(' ', $n - $l);
}

/**
 * Compteurs de lignes lues de la session.
 * On lit SHOW SESSION STATUS avant/après plutôt que de remettre les
 * compteurs à zéro : FLUSH STATUS exige le privilège RELOAD, que
 * l'utilisateur MySQL d'une appli n'a pas sur un hébergement mutualisé.
 */
function compteurs(PDO $pdo): array
{
    $h = [];
    $r = $pdo->query("SHOW SESSION STATUS WHERE Variable_name IN
            ('Handler_read_rnd_next','Handler_read_next','Handler_read_key','Sort_rows')")
             ->fetchAll(PDO::FETCH_NUM);
    foreach ($r as [$k, $v]) $h[$k] = (int) $v;
    return $h;
}

echo "\n  DIAGNOSTIC DE MONTÉE EN CHARGE — $base\n";
echo '  ', date('d/m/Y H:i'), "\n";
echo '  ', str_repeat('=', 74), "\n\n";

// ------------------------------------------------------------------
// 1. Combien d'associations aujourd'hui
// ------------------------------------------------------------------
$orgs = 0;
try {
    $orgs = (int) $pdo->query("SELECT COUNT(*) FROM organizations WHERE deleted_at IS NULL")->fetchColumn();
} catch (Throwable $e) {
    try { $orgs = (int) $pdo->query("SELECT COUNT(*) FROM organizations")->fetchColumn(); } catch (Throwable $e2) {}
}
if ($orgs < 1) { echo "  Aucune association en base : projection impossible.\n\n"; exit(1); }

$facteur = $cible / $orgs;
printf("  Associations aujourd'hui : %s\n", nb($orgs));
printf("  Cible                    : %s  (×%s)\n\n", nb($cible), number_format($facteur, 1, ',', ' '));

// ------------------------------------------------------------------
// 2. Le poids des tables, et ce qu'il devient à la cible
// ------------------------------------------------------------------
// table_rows d'InnoDB est une estimation (échantillonnage de l'index),
// elle peut dériver de quelques pour cent. Suffisant pour un ordre de
// grandeur, et bien moins coûteux qu'un COUNT(*) sur chaque table.
$st = $pdo->prepare("SELECT table_name, table_rows, data_length, index_length
                     FROM information_schema.tables
                     WHERE table_schema = ? AND engine IS NOT NULL
                     ORDER BY (data_length + index_length) DESC");
$st->execute([$base]);
$tables = $st->fetchAll(PDO::FETCH_ASSOC);

$totalNow = $totalCible = 0.0;
echo "  LES 15 PLUS GROSSES TABLES\n";
printf("  %-38s %10s %10s %12s\n", 'table', 'lignes', 'taille', "à $cible assos");
echo '  ', str_repeat('-', 74), "\n";

$n = 0;
foreach ($tables as $t) {
    $taille = (float) $t['data_length'] + (float) $t['index_length'];
    $totalNow   += $taille;
    $totalCible += $taille * $facteur;
    if ($n++ < 15) {
        printf("  %-38s %10s %10s %12s\n",
            substr($t['table_name'], 0, 38), nb((float) $t['table_rows']),
            mo($taille), mo($taille * $facteur));
    }
}
echo '  ', str_repeat('-', 74), "\n";
printf("  %-38s %10s %10s %12s\n", 'TOTAL', '', mo($totalNow), mo($totalCible));
echo "\n";
echo "  La projection suppose que les tables grossissent proportionnellement\n";
echo "  au nombre d'associations. C'est vrai des tables liées à une\n";
echo "  association, faux des catalogues partagés (subventions, plans),\n";
echo "  qui sont donc surestimés — la vraie valeur est un peu plus basse.\n\n";

// ------------------------------------------------------------------
// 3. Ce que MySQL doit LIRE pour servir une page
// ------------------------------------------------------------------
// Le chiffre qui compte. Une requête qui lit 300 lignes pour en
// afficher 8 reste rapide à n'importe quelle échelle. Une requête qui
// en lit 3 millions s'écroule dès que la base grossit, même si elle
// paraît instantanée aujourd'hui sur une base vide.
echo "  LIGNES LUES PAR REQUÊTE (sur une association réelle)\n";
echo '  ', str_repeat('-', 74), "\n";

$orgTest = null;
try {
    $orgTest = (int) $pdo->query("SELECT f.org_id FROM folders f JOIN projects p ON p.folder_id = f.id
                                  GROUP BY f.org_id ORDER BY COUNT(*) DESC LIMIT 1")->fetchColumn();
} catch (Throwable $e) {}
if (!$orgTest) {
    try { $orgTest = (int) $pdo->query("SELECT id FROM organizations ORDER BY id LIMIT 1")->fetchColumn(); }
    catch (Throwable $e) {}
}

$sondes = [
    'Nombre d’adhérents' =>
        ["SELECT COUNT(*) FROM users WHERE org_id = ? AND (deleted_at IS NULL OR deleted_at = '') AND is_active = 1", 1],
    'Projets actifs' =>
        ["SELECT COUNT(*) FROM projects p JOIN folders f ON p.folder_id = f.id
          WHERE f.org_id = ? AND p.status IN ('active','warning')
            AND p.archived_at IS NULL AND f.archived_at IS NULL", 1],
    'Fil d’activité (8 lignes)' =>
        ["SELECT pal.id FROM project_activity_log pal
            JOIN projects p ON pal.project_id = p.id
            JOIN folders f ON p.folder_id = f.id
          WHERE f.org_id = ? ORDER BY pal.created_at DESC LIMIT 8", 1],
    'Courbe d’activité 30 jours' =>
        ["SELECT DATE(pal.created_at) d, COUNT(*) c FROM project_activity_log pal
            JOIN projects p ON pal.project_id = p.id
            JOIN folders f ON p.folder_id = f.id
          WHERE f.org_id = ? AND pal.created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
          GROUP BY DATE(pal.created_at)", 1],
    'Journal fondateur d’une asso' =>
        ["SELECT id FROM assokit_activity_log WHERE organization_id = ?
          ORDER BY created_at DESC LIMIT 50", 1],
];

$alertes = [];
$mesurees = 0;
foreach ($sondes as $nom => [$sql, $nbParams]) {
    try {
        $avant = compteurs($pdo);
        $d  = microtime(true);
        $s  = $pdo->prepare($sql);
        $s->execute(array_fill(0, $nbParams, $orgTest));
        $ret = count($s->fetchAll());
        $ms  = (microtime(true) - $d) * 1000;
        $apres = compteurs($pdo);

        $delta = fn(string $k) => max(0, ($apres[$k] ?? 0) - ($avant[$k] ?? 0));
        $lues   = $delta('Handler_read_rnd_next') + $delta('Handler_read_next');
        $triees = $delta('Sort_rows');

        printf("  %s %8s lues  %9s triées  %6s affichée(s)  %7.1f ms\n",
            cale($nom, 30), nb($lues), nb($triees), nb($ret), $ms);
        $mesurees++;

        // Seuil : au-delà de 1 000 lignes lues pour une page, la requête
        // ne tient que parce que la base est petite.
        if ($lues > 1000 || $triees > 1000) $alertes[] = $nom;
    } catch (Throwable $e) {
        printf("  %s  (impossible à mesurer : %s)\n", cale($nom, 30), substr($e->getMessage(), 0, 60));
    }
}
echo "\n";

// ------------------------------------------------------------------
// 4. Verdict
// ------------------------------------------------------------------
echo '  ', str_repeat('=', 74), "\n";
if ($alertes) {
    echo "  À CORRIGER AVANT DE MONTER EN CHARGE\n\n";
    foreach ($alertes as $a) echo "    · $a lit beaucoup trop de lignes pour ce qu'elle affiche.\n";
    echo "\n  Ces requêtes-là ne ralentissent pas progressivement : elles\n";
    echo "  tiennent, puis elles lâchent d'un coup quand la table ne rentre\n";
    echo "  plus en mémoire. Lancer :\n\n";
    echo "    php migrations/2026-09-16-index-montee-en-charge.php\n\n";
    echo "  (simulation d'abord, puis --appliquer)\n";
} elseif ($mesurees === 0) {
    // Ne jamais conclure « tout va bien » quand on n'a rien pu mesurer :
    // c'est exactement comme ça qu'on rate un problème.
    echo "  AUCUNE MESURE N'A PU ÊTRE FAITE.\n";
    echo "  Les tables sondées n'existent pas sous ces noms dans cette base.\n";
    echo "  Ce diagnostic ne dit donc rien, ni en bien ni en mal.\n";
} else {
    printf("  Aucune des %d requêtes mesurées ne lit un volume anormal.\n", $mesurees);
    echo "  La base n'est pas le facteur limitant à ce volume.\n";
}
echo '  ', str_repeat('=', 74), "\n\n";
