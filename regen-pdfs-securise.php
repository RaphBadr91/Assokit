<?php
/**
 * regen-pdfs-securise.php — Régénère les anciens PDF (factures + devis) avec le
 * NOUVEAU nom de fichier non devinable (suffixe UUID) + Factur-X pour les factures,
 * puis SUPPRIME l'ancien fichier au nom devinable.
 *
 * But : fermer complètement la faille d'énumération sur les documents déjà générés.
 *
 * SÉCURITÉ : CLI uniquement. Idempotent (relançable sans risque).
 *
 * Usage (SSH O2switch) :
 *   php regen-pdfs-securise.php            # simulation (affiche, ne modifie rien)
 *   php regen-pdfs-securise.php --run      # exécute réellement
 *   php regen-pdfs-securise.php --run --only=invoices   # une seule catégorie
 *   (catégories : asso_invoices | asso_quotes | invoices)
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/asso-invoice-helpers.php';
require_once __DIR__ . '/asso-quote-helpers.php';
@require_once __DIR__ . '/invoice-helpers.php';

$RUN  = in_array('--run', $argv, true);
$only = null;
foreach ($argv as $a) { if (preg_match('/^--only=([a-z_]+)/', $a, $m)) $only = $m[1]; }

echo ($RUN ? '[RUN] ' : '[SIMULATION] ') . "Régénération des PDF sécurisés — " . date('Y-m-d H:i') . "\n";
if (!$RUN) echo "  (Ajoute --run pour exécuter réellement.)\n";

function tbl_exists(PDO $pdo, string $t): bool {
    try { $q = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?"); $q->execute([$t]); return (int)$q->fetchColumn() > 0; }
    catch (Throwable $e) { return false; }
}

/** Supprime l'ancien fichier SI dans le dossier uploads attendu et différent du nouveau. */
function del_old(string $old_rel, string $new_rel, string $subdir): bool {
    if ($old_rel === '' || $old_rel === $new_rel) return false;
    $base = realpath(__DIR__ . '/uploads/' . $subdir);
    $target = realpath(__DIR__ . $old_rel);
    if (!$base || !$target) return false;
    if (strpos($target, $base . DIRECTORY_SEPARATOR) !== 0) return false; // hors du dossier -> refuse
    return @unlink($target);
}

/**
 * Traite une catégorie : lit id + ancien pdf_path, régénère, supprime l'ancien fichier.
 */
function process(PDO $pdo, bool $run, string $table, string $subdir, callable $render): void {
    if (!tbl_exists($pdo, $table)) { echo "· $table : table absente, ignoré\n"; return; }
    $rows = $pdo->query("SELECT id, pdf_path FROM $table ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    echo "· $table : " . count($rows) . " document(s)\n";
    $ok = 0; $del = 0; $err = 0;
    foreach ($rows as $r) {
        $id = (int) $r['id'];
        $old = (string) ($r['pdf_path'] ?? '');
        if (!$run) { echo "   [sim] #$id régénérerait (ancien: " . ($old ?: '—') . ")\n"; continue; }
        try {
            $new = (string) $render($pdo, $id);
            $ok++;
            if (del_old($old, $new, $subdir)) $del++;
            echo "   ✓ #$id → $new\n";
        } catch (Throwable $e) { $err++; echo "   ✗ #$id : " . $e->getMessage() . "\n"; }
    }
    echo "  → $ok régénéré(s), $del ancien(s) fichier(s) supprimé(s), $err erreur(s)\n";
}

$targets = [
    'asso_invoices' => ['asso-invoices', 'ak_asso_invoice_render_pdf'],
    'asso_quotes'   => ['asso-quotes',   'ak_asso_quote_render_pdf'],
    'invoices'      => ['invoices',      function_exists('ak_render_invoice_pdf') ? 'ak_render_invoice_pdf' : null],
];

foreach ($targets as $table => [$subdir, $fn]) {
    if ($only && $only !== $table) continue;
    if (!$fn || !function_exists($fn)) { echo "· $table : fonction de rendu absente, ignoré\n"; continue; }
    process($pdo, $RUN, $table, $subdir, $fn);
}

echo ($RUN ? '[RUN] ' : '[SIMULATION] ') . "Terminé.\n";
if (!$RUN) echo "  Relance avec --run pour appliquer.\n";
