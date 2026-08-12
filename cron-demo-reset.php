<?php
/**
 * cron-demo-reset.php — Reset automatique des données DEMO chaque nuit
 * 
 * À configurer dans cPanel cron :
 *    Heure : 0 0 * * *  (minuit chaque nuit)
 *    Cmd   : php -f /home/pura7044/public_html/cron-demo-reset.php
 * 
 * Ou en URL secrète :
 *    https://assokit.fr/cron-demo-reset.php?token=AssokitDemoReset2026Secret
 * 
 * ⚠️ IMPORTANT : ce script attend les 6 fichiers SQL dans /demo-sql/ :
 *    01-demo-seed-v2.sql        — Fondations (4 orgs, équipes, sélecteur)
 *    02-demo-data-massive.sql   — Adhérents, projets, factures, devis
 *    03-demo-enrichissement.sql — Étapes, fichiers, messages projets
 *    04-demo-communications.sql — Campagnes, broadcasts, RSVP
 *    05-demo-bilans-ia.sql      — Bilans IA + bilans annuels MASSIFS
 *    06-demo-project-invoices.sql — Factures projet (dépenses)
 */

// =============================================================
// CONFIGURATION
// =============================================================
$CRON_TOKEN = defined('CRON_TOKEN_DEMO') ? CRON_TOKEN_DEMO : (defined('CRON_TOKEN') ? CRON_TOKEN : '');
$LOG_FILE = __DIR__ . '/cron-demo-reset.log';

// Liste ORDONNÉE des fichiers SQL à exécuter (l'ordre est critique !)
$SQL_FILES = [
    __DIR__ . '/demo-sql/01-demo-seed-v2.sql',
    __DIR__ . '/demo-sql/02-demo-data-massive.sql',
    __DIR__ . '/demo-sql/03-demo-enrichissement.sql',
    __DIR__ . '/demo-sql/04-demo-communications.sql',
    __DIR__ . '/demo-sql/05-demo-bilans-ia.sql',
    __DIR__ . '/demo-sql/06-demo-project-invoices.sql',
];
// --- Override AssoKit: charge tous les .sql du dossier (ordre alpha) ---
$SQL_FILES = glob(__DIR__ . '/demo-sql/*.sql') ?: [];
sort($SQL_FILES);

// =============================================================
// SÉCURITÉ
// =============================================================
$is_cli = php_sapi_name() === 'cli';
$token = $_GET['token'] ?? ($_SERVER['HTTP_X_CRON_TOKEN'] ?? '');

if (!$is_cli && ($CRON_TOKEN === '' || !hash_equals($CRON_TOKEN, (string)$token))) {
    http_response_code(403);
    die('Forbidden');
}

require_once __DIR__ . '/config.php';
$pdo->exec("SET SESSION sql_mode=''"); // AssoKit: seed permissif

// =============================================================
// LOG HELPER
// =============================================================
function log_msg(string $msg): void {
    global $LOG_FILE;
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n";
    file_put_contents($LOG_FILE, $line, FILE_APPEND);
    if (php_sapi_name() === 'cli') echo $line;
    else echo $line . '<br>';
}

log_msg('===== RESET DEMO START =====');

// =============================================================
// VÉRIFIER QUE TOUS LES FICHIERS SQL EXISTENT
// =============================================================
$missing = [];
foreach ($SQL_FILES as $file) {
    if (!file_exists($file)) {
        $missing[] = basename($file);
    }
}

if (!empty($missing)) {
    log_msg('❌ Fichiers SQL manquants : ' . implode(', ', $missing));
    log_msg('   Ils doivent être dans : ' . __DIR__ . '/demo-sql/');
    die('SQL files missing : ' . implode(', ', $missing));
}

log_msg('✅ Tous les fichiers SQL trouvés (' . count($SQL_FILES) . ' fichiers)');

// =============================================================
// PARSER SQL — Découpe un gros fichier SQL en requêtes individuelles
// =============================================================
function parse_sql_statements(string $sql): array {
    $statements = [];
    $current = '';
    $in_string = false;
    $string_char = null;
    
    for ($i = 0; $i < strlen($sql); $i++) {
        $c = $sql[$i];
        $prev = $i > 0 ? $sql[$i - 1] : '';
        
        if (!$in_string && ($c === "'" || $c === '"')) {
            $in_string = true;
            $string_char = $c;
        } elseif ($in_string && $c === $string_char && $prev !== '\\') {
            $in_string = false;
            $string_char = null;
        }
        
        if (!$in_string && $c === ';') {
            $current .= $c;
            $trimmed = trim($current);
            if ($trimmed !== '' && $trimmed !== ';') {
                $statements[] = $trimmed;
            }
            $current = '';
        } else {
            $current .= $c;
        }
    }
    
    // Filtrer commentaires en début de chaque statement
    $clean_statements = [];
    foreach ($statements as $stmt) {
        $lines = explode("\n", $stmt);
        $kept = [];
        foreach ($lines as $line) {
            if (!preg_match('/^\s*--/', $line)) {
                $kept[] = $line;
            }
        }
        $cleaned = trim(implode("\n", $kept));
        if ($cleaned !== '' && $cleaned !== ';') {
            $clean_statements[] = $cleaned;
        }
    }
    
    return $clean_statements;
}

// =============================================================
// EXÉCUTER LES 6 FICHIERS SQL DANS L'ORDRE
// =============================================================
$total_executed = 0;
$total_errors = 0;
$file_num = 0;

foreach ($SQL_FILES as $file) {
    $file_num++;
    $basename = basename($file);
    
    log_msg('');
    log_msg('───────────────────────────────────────');
    log_msg("📄 Fichier $file_num/" . count($SQL_FILES) . " : $basename");
    log_msg('───────────────────────────────────────');
    
    try {
        $sql = file_get_contents($file);
        $size_kb = round(strlen($sql) / 1024, 1);
        log_msg("   Taille : {$size_kb} Ko");
        
        $statements = parse_sql_statements($sql);
        log_msg('   Nombre de requêtes : ' . count($statements));
        
        $file_executed = 0;
        $file_errors = 0;
        
        foreach ($statements as $idx => $stmt) {
            try {
                $pdo->exec($stmt);
                $file_executed++;
            } catch (Throwable $e) {
                // Ignorer SELECT (juste un check) et autres warnings non bloquants
                if (stripos($e->getMessage(), 'check') === false) {
                    $file_errors++;
                    log_msg('   ⚠️ Erreur requête #' . ($idx + 1) . ' : ' . substr($e->getMessage(), 0, 180));
                }
            }
        }
        
        log_msg("   ✅ $file_executed requêtes OK · $file_errors erreurs");
        
        $total_executed += $file_executed;
        $total_errors += $file_errors;
        
    } catch (Throwable $e) {
        log_msg('   💥 ERREUR FICHIER : ' . $e->getMessage());
        $total_errors++;
    }
}

// =============================================================
// VÉRIFICATIONS FINALES
// =============================================================
log_msg('');
log_msg('═══════════════════════════════════════');
log_msg('📊 BILAN FINAL');
log_msg('═══════════════════════════════════════');
log_msg("   Total : $total_executed requêtes exécutées · $total_errors erreurs");

try {
    $orgs_count = (int)$pdo->query("SELECT COUNT(*) FROM organizations WHERE slug LIKE 'demo-%'")->fetchColumn();
    log_msg("   🏢 Organisations DEMO : $orgs_count");
    
    $users_count = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE org_id IN (SELECT id FROM organizations WHERE slug LIKE 'demo-%') OR email = 'demo@assokit.fr'")->fetchColumn();
    log_msg("   👥 Comptes DEMO : $users_count");
    
    $projects_count = (int)$pdo->query("SELECT COUNT(*) FROM projects p JOIN folders f ON p.folder_id = f.id WHERE f.org_id IN (SELECT id FROM organizations WHERE slug LIKE 'demo-%')")->fetchColumn();
    log_msg("   📂 Projets DEMO : $projects_count");
    
    $invoices_count = (int)$pdo->query("SELECT COUNT(*) FROM asso_invoices WHERE org_id IN (SELECT id FROM organizations WHERE slug LIKE 'demo-%')")->fetchColumn();
    log_msg("   💰 Factures DEMO : $invoices_count");
    
    $proj_inv_count = (int)$pdo->query("SELECT COUNT(*) FROM project_invoices pi JOIN projects p ON pi.project_id = p.id JOIN folders f ON p.folder_id = f.id WHERE f.org_id IN (SELECT id FROM organizations WHERE slug LIKE 'demo-%')")->fetchColumn();
    log_msg("   🧾 Factures projet DEMO : $proj_inv_count");
    
    $bilans_count = (int)$pdo->query("SELECT COUNT(*) FROM ai_generated_docs WHERE doc_type IN ('bilan_ag', 'fiche_com', 'synthese_etape')")->fetchColumn();
    log_msg("   🤖 Documents IA générés : $bilans_count");
    
} catch (Throwable $e) {
    log_msg('   ⚠️ Impossible de faire les vérifs : ' . $e->getMessage());
}

log_msg('');
log_msg('===== RESET DEMO END =====');
log_msg('');
