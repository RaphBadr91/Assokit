<?php
/**
 * migrations/run.php — Lanceur de migrations en ligne de commande.
 * ------------------------------------------------------------------
 * Applique un fichier .sql du dossier migrations/ via la connexion PDO
 * de l'app (identifiants lus dans config.php — rien à saisir).
 * Réservé à la CLI (pas d'accès web).
 *
 * Usage :
 *   /usr/local/bin/php migrations/run.php 2026-08-19-relances.sql
 *   /usr/local/bin/php migrations/run.php --all      (tous les .sql du dossier)
 *
 * Idempotent : les migrations utilisent IF NOT EXISTS, on peut relancer.
 *
 * Les scripts de lecture (uniquement des SELECT, faits pour phpMyAdmin :
 * diagnostics, index à proposer) ne sont pas des migrations : --all les
 * saute. Nommés explicitement, ils sont exécutés et leurs résultats
 * affichés, comme dans l'onglet SQL de phpMyAdmin.
 *
 * Un UPDATE / DELETE dont la table visée n'existe pas (table que l'app
 * crée à la première utilisation) est compté « sans objet », pas en
 * erreur : il n'y avait rien à corriger. Toute autre erreur reste une
 * erreur, et le code retour vaut 1.
 * ------------------------------------------------------------------
 */

// CLI uniquement (aucune requête web -> REQUEST_METHOD absent).
if (PHP_SAPI !== 'cli' && isset($_SERVER['REQUEST_METHOD'])) { http_response_code(403); die('Forbidden'); }

require_once __DIR__ . '/../config.php';
if (!isset($pdo) || !($pdo instanceof PDO)) { fwrite(STDERR, "PDO indisponible (config.php).\n"); exit(1); }

// La connexion de l'app est parfois en mode non-bufferisé : un SELECT laisse
// alors le curseur ouvert et bloque les requêtes suivantes (erreur 2014).
// On force le mode bufferisé pour le lanceur (best-effort).
try { $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true); } catch (Throwable $e) {}

$arg = $argv[1] ?? '';
if ($arg === '') {
    echo "Usage : php migrations/run.php <fichier.sql> | --all\n";
    echo "Fichiers disponibles :\n";
    foreach (glob(__DIR__ . '/*.sql') as $f) echo '  - ' . basename($f) . "\n";
    exit(0);
}

$files = [];
if ($arg === '--all') {
    $files = glob(__DIR__ . '/*.sql');
    sort($files);
} else {
    $base = basename($arg); // pas de traversée de dossier
    $path = __DIR__ . '/' . $base;
    if (!is_file($path)) { fwrite(STDERR, "Fichier introuvable : $base\n"); exit(1); }
    $files = [$path];
}

/**
 * Découpe un script SQL en instructions et retire les commentaires.
 *
 * Lecture caractère par caractère : un « ; » ou un « -- » placé dans une
 * chaîne ('…', "…") ou un nom entre `…` n'est ni une fin d'instruction ni un
 * commentaire, et une apostrophe dans un commentaire (« -- d'intérêt »)
 * n'ouvre pas de chaîne. Un simple explode(';') coupait les SELECT CONCAT
 * des scripts phpMyAdmin au milieu de leurs chaînes (erreur 1064).
 * Commentaires reconnus : « -- » suivi d'un blanc, « # », et /* … *\/
 * (les commentaires exécutables /*! … *\/ sont conservés).
 */
function mig_split_sql(string $sql): array {
    $out = [];
    $cur = '';
    $n = strlen($sql);
    for ($i = 0; $i < $n; $i++) {
        $c = $sql[$i];
        $next = $i + 1 < $n ? $sql[$i + 1] : '';

        // Chaîne ou identifiant : copié tel quel jusqu'au délimiteur fermant.
        if ($c === "'" || $c === '"' || $c === '`') {
            $cur .= $c;
            for ($i++; $i < $n; $i++) {
                $d = $sql[$i];
                $cur .= $d;
                if ($d === '\\' && $c !== '`' && $i + 1 < $n) { $cur .= $sql[++$i]; continue; }
                if ($d === $c) {
                    if ($i + 1 < $n && $sql[$i + 1] === $c) { $cur .= $sql[++$i]; continue; } // '' doublé
                    break;
                }
            }
            continue;
        }
        // Commentaire de fin de ligne : « -- » + blanc (règle MySQL), ou « # ».
        if (($c === '-' && $next === '-' && ($i + 2 >= $n || ctype_space($sql[$i + 2]))) || $c === '#') {
            $eol = strpos($sql, "\n", $i);
            if ($eol === false) break;
            $i = $eol - 1; // le saut de ligne lui-même est gardé
            continue;
        }
        // Commentaire de bloc ; /*! … */ (et /*M! … */ de MariaDB) est du SQL
        // pour le serveur : gardé.
        if ($c === '/' && $next === '*') {
            $end = strpos($sql, '*/', $i + 2);
            $end = $end === false ? $n : $end + 2;
            if (($sql[$i + 2] ?? '') === '!' || substr($sql, $i + 2, 2) === 'M!') $cur .= substr($sql, $i, $end - $i);
            else $cur .= ' ';
            $i = $end - 1;
            continue;
        }
        if ($c === ';') {
            if (trim($cur) !== '') $out[] = trim($cur);
            $cur = '';
            continue;
        }
        $cur .= $c;
    }
    if (trim($cur) !== '') $out[] = trim($cur);
    return $out;
}

/** Instruction de lecture pure (SELECT, SHOW…) : ne modifie rien. */
function mig_is_read_only(string $stmt): bool {
    return (bool) preg_match('/^\(*\s*(SELECT|SHOW|EXPLAIN|DESCRIBE|DESC)\b/i', $stmt)
        && !preg_match('/\bINTO\s+(OUTFILE|DUMPFILE)\b/i', $stmt);
}

/** Table visée par un UPDATE / DELETE FROM (null pour toute autre instruction). */
function mig_dml_target(string $stmt): ?string {
    $re = '/^(?:UPDATE(?:\s+LOW_PRIORITY)?(?:\s+IGNORE)?|DELETE(?:\s+LOW_PRIORITY)?(?:\s+QUICK)?(?:\s+IGNORE)?\s+FROM)\s+`?([A-Za-z0-9_$]+)`?(?:\s|$)/i';
    return preg_match($re, $stmt, $m) ? $m[1] : null;
}

function mig_table_exists(PDO $pdo, string $table): bool {
    $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES
                          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
    $st->execute([$table]);
    return (int) $st->fetchColumn() > 0;
}

$totalOk = 0; $totalErr = 0; $totalVide = 0; $skipped = [];
foreach ($files as $path) {
    $sql = file_get_contents($path);
    if ($sql === false) { echo "\n=== " . basename($path) . " ===\n  ! lecture impossible\n"; $totalErr++; continue; }
    $stmts = mig_split_sql($sql);
    $lecture = $stmts !== [] && count(array_filter($stmts, 'mig_is_read_only')) === count($stmts);
    if ($lecture && $arg === '--all') { $skipped[] = basename($path); continue; }

    echo "\n=== " . basename($path) . " ===\n";
    if ($lecture) {
        echo "  (script de lecture : ne modifie rien, résultats affichés ci-dessous)\n";
        $companion = preg_replace('/\.sql$/', '.php', $path);
        if (is_file($companion)) echo "  (pour appliquer : php migrations/" . basename($companion) . ")\n";
    }
    foreach ($stmts as $stmt) {
        $label = preg_replace('/\s+/', ' ', mb_substr($stmt, 0, 60));
        $rows = null;
        try {
            // query() + closeCursor() libère tout résultat (SELECT) pour ne pas
            // bloquer l'instruction suivante en connexion non-bufferisée.
            $res = $pdo->query($stmt);
            $cols = [];
            if ($res instanceof PDOStatement) {
                // Script de lecture : on montre ce que phpMyAdmin aurait montré.
                if ($lecture && $res->columnCount() > 0) {
                    for ($k = 0; $k < $res->columnCount(); $k++) {
                        $meta = $res->getColumnMeta($k);
                        $cols[] = (string) ($meta['name'] ?? ('#' . ($k + 1)));
                    }
                    $rows = $res->fetchAll(PDO::FETCH_NUM);
                }
                $res->closeCursor(); $res = null;
            }
            // Accolades obligatoires : PHP accepte les octets ≥ 0x80 dans un
            // nom de variable, donc « $label… » désignait une variable nommée
            // « label… », jamais définie — d'où un avertissement à chaque
            // instruction, et un libellé toujours vide.
            echo "  ✓ {$label}…\n";
            if ($rows !== null) {
                echo '    → ' . count($rows) . " ligne(s)\n";
                if ($rows) echo '      ' . implode(' | ', $cols) . "\n";
                foreach ($rows as $r) {
                    echo '      ' . implode(' | ', array_map(fn($v) => $v === null ? 'NULL' : (string) $v, $r)) . "\n";
                }
            }
            $totalOk++;
        } catch (Throwable $e) {
            // Réparation de données (UPDATE / DELETE) sur une table que l'app
            // crée à la première utilisation et qui n'existe pas encore ici :
            // il n'y a aucune ligne à corriger, ce n'est pas une erreur.
            // Seule la table visée compte : une table jointe absente alors que
            // la table visée existe reste une vraie erreur.
            $target = mig_dml_target($stmt);
            $tableAbsente = $e instanceof PDOException
                && ((int) ($e->errorInfo[1] ?? 0) === 1146 || (string) $e->getCode() === '42S02');
            try {
                $vide = $tableAbsente && $target !== null && !mig_table_exists($pdo, $target);
            } catch (Throwable $e2) {
                $vide = false;
            }
            if ($vide) {
                echo "  – {$label}… : table {$target} absente, rien à modifier\n";
                $totalVide++;
                continue;
            }
            echo "  ✗ {$label}… : " . $e->getMessage() . "\n";
            $totalErr++;
        }
    }
}

if ($skipped) {
    echo "\nScripts de lecture ignorés (ne modifient rien ; à lancer un par un pour voir leurs résultats) :\n";
    foreach ($skipped as $s) echo "  - $s\n";
}
echo "\nTerminé : $totalOk instruction(s) OK, $totalErr erreur(s)"
   . ($totalVide ? ", $totalVide sans objet (table absente)" : '') . ".\n";
exit($totalErr > 0 ? 1 : 0);
