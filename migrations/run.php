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

/** Découpe un script SQL en instructions (retire les commentaires -- …). */
function mig_split_sql(string $sql): array {
    $lines = preg_split('/\r?\n/', $sql);
    $clean = [];
    foreach ($lines as $l) {
        $t = ltrim($l);
        if (str_starts_with($t, '--')) continue; // ligne de commentaire
        $clean[] = $l;
    }
    $joined = implode("\n", $clean);
    $parts = array_map('trim', explode(';', $joined));
    return array_values(array_filter($parts, fn($s) => $s !== ''));
}

$totalOk = 0; $totalErr = 0;
foreach ($files as $path) {
    echo "\n=== " . basename($path) . " ===\n";
    $sql = file_get_contents($path);
    if ($sql === false) { echo "  ! lecture impossible\n"; $totalErr++; continue; }
    foreach (mig_split_sql($sql) as $stmt) {
        $label = preg_replace('/\s+/', ' ', mb_substr($stmt, 0, 60));
        try {
            // query() + closeCursor() libère tout résultat (SELECT) pour ne pas
            // bloquer l'instruction suivante en connexion non-bufferisée.
            $res = $pdo->query($stmt);
            if ($res instanceof PDOStatement) { $res->closeCursor(); $res = null; }
            echo "  ✓ $label…\n";
            $totalOk++;
        } catch (Throwable $e) {
            echo "  ✗ $label… : " . $e->getMessage() . "\n";
            $totalErr++;
        }
    }
}

echo "\nTerminé : $totalOk instruction(s) OK, $totalErr erreur(s).\n";
exit($totalErr > 0 ? 1 : 0);
