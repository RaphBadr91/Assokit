<?php
/**
 * diag-pack-ux.php — Diagnostic pour identifier le crash 500
 * À supprimer après diagnostic
 */

ini_set('display_errors', 1);
error_reporting(E_ALL);

echo "<h1>Diagnostic Pack UX</h1><pre>";

// === Test 1 : Fichiers helpers présents ? ===
echo "=== FICHIERS ===\n";
$files = [
    'config.php',
    'includes-layout.php',
    'asso-invoice-helpers.php',
    'asso-tags-helpers.php',
    'asso-search-helpers.php',
    'mon-asso-factures.php',
    'mon-asso-devis.php',
    'mon-asso-clients.php',
];
foreach ($files as $f) {
    echo (file_exists(__DIR__.'/'.$f) ? '✅' : '❌') . "  $f\n";
}

// === Test 2 : Charger config ===
echo "\n=== CONFIG ===\n";
try {
    require_once __DIR__ . '/config.php';
    echo "✅  config.php chargé\n";
} catch (Throwable $e) {
    echo "❌  config.php : " . $e->getMessage() . "\n";
    exit;
}

// === Test 3 : PDO connecté ? ===
echo "\n=== PDO ===\n";
try {
    if (isset($pdo) && $pdo instanceof PDO) {
        echo "✅  \$pdo disponible\n";
    } else {
        echo "❌  \$pdo introuvable\n";
        exit;
    }
} catch (Throwable $e) {
    echo "❌  PDO : " . $e->getMessage() . "\n";
}

// === Test 4 : Tables présentes ? ===
echo "\n=== TABLES BDD ===\n";
$tables = ['asso_invoices', 'asso_clients', 'asso_quotes', 'asso_tags', 'asso_tag_links'];
foreach ($tables as $t) {
    try {
        $r = $pdo->query("SELECT COUNT(*) FROM $t LIMIT 1")->fetchColumn();
        echo "✅  $t (count=$r)\n";
    } catch (Throwable $e) {
        echo "❌  $t : " . $e->getMessage() . "\n";
    }
}

// === Test 5 : Charger helpers (le truc qui peut crasher) ===
echo "\n=== HELPERS ===\n";
try {
    require_once __DIR__ . '/asso-invoice-helpers.php';
    echo "✅  asso-invoice-helpers.php\n";
} catch (Throwable $e) {
    echo "❌  asso-invoice-helpers.php : " . $e->getMessage() . "\n";
}

try {
    require_once __DIR__ . '/asso-tags-helpers.php';
    echo "✅  asso-tags-helpers.php\n";
} catch (Throwable $e) {
    echo "❌  asso-tags-helpers.php : " . $e->getMessage() . "\n";
}

try {
    require_once __DIR__ . '/asso-search-helpers.php';
    echo "✅  asso-search-helpers.php\n";
} catch (Throwable $e) {
    echo "❌  asso-search-helpers.php : " . $e->getMessage() . "\n";
}

// === Test 6 : Fonctions définies ? ===
echo "\n=== FONCTIONS ===\n";
$funcs = ['ak_asso_fmt_cents', 'ak_tag_list', 'ak_tag_get_for_entities', 'ak_tag_render_chips', 'ak_search_period_dates', 'ak_search_render_period_chips', 'ak_search_render_livesearch'];
foreach ($funcs as $f) {
    echo (function_exists($f) ? '✅' : '❌') . "  $f()\n";
}

// === Test 7 : Erreurs récentes du log ===
echo "\n=== DERNIÈRES ERREURS PHP ===\n";
$log = __DIR__ . '/error_log';
if (file_exists($log)) {
    $lines = file($log);
    $tail = array_slice($lines, -15);
    foreach ($tail as $l) echo htmlspecialchars($l);
} else {
    echo "(pas de fichier error_log)\n";
}

echo "</pre>";
