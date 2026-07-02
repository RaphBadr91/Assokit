<?php
/**
 * ============================================================
 * ASSOKIT — cron.php
 * Orchestrateur CRON principal
 * ============================================================
 * Déposer à la RACINE du site (public_html/cron.php)
 *
 * Appel cPanel (O2Switch) :
 *   wget -q -O - "https://assokit.fr/cron.php?token=XXX&job=all" >/dev/null 2>&1
 *
 * Jobs disponibles :
 *   - impayes          → Relances factures J+3 / J+7 / J+15
 *   - essai            → Rappels fin d'essai J-7 / J-3 / J-0
 *   - renouvellements  → Génère factures mensuelles + emails
 *   - all              → Les 3 ci-dessus, dans l'ordre
 *
 * Sécurité :
 *   - Token obligatoire (CRON_TOKEN défini dans config.php)
 *   - Lock fichier pour empêcher double exécution
 *   - try/catch complet
 * ============================================================
 */

define('CRON_EXECUTING', true);

// --- Exécution CLI ou HTTP
$isHttp = (PHP_SAPI !== 'cli');

// --- Limites d'exécution généreuses
set_time_limit(300);           // 5 minutes max
ini_set('memory_limit', '256M');

// --- Log minimal vers error_log en cas de fatal
register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        error_log('[CRON FATAL] ' . $e['message'] . ' @ ' . $e['file'] . ':' . $e['line']);
    }
});

require_once __DIR__ . '/cron-includes.php';

// ============================================================
// 1) Vérification du TOKEN
// ============================================================

$expectedToken = defined('CRON_TOKEN') ? CRON_TOKEN : '';
$providedToken = $_GET['token'] ?? ($argv[1] ?? '');

if (empty($expectedToken)) {
    http_response_code(500);
    echo "CRON_TOKEN non configuré dans config.php\n";
    exit(1);
}

if (!hash_equals($expectedToken, (string) $providedToken)) {
    http_response_code(403);
    echo "Forbidden — token invalide\n";
    exit(1);
}

// ============================================================
// 2) Détermination du JOB à exécuter
// ============================================================

$job = strtolower(trim($_GET['job'] ?? ($argv[2] ?? 'all')));
$validJobs = ['impayes', 'essai', 'renouvellements', 'all'];

if (!in_array($job, $validJobs, true)) {
    http_response_code(400);
    echo "Job invalide. Valeurs acceptées : " . implode(', ', $validJobs) . "\n";
    exit(1);
}

// Détection déclenchement manuel (via admin-cron-run-manual.php)
$triggeredBy = 'cron';
$triggeredByUserId = null;
if (!empty($_GET['manual']) && cron_is_super_admin()) {
    $triggeredBy = 'manual';
    $triggeredByUserId = (int)($_SESSION['user']['id'] ?? $_SESSION['user_id'] ?? 0) ?: null;
}

// ============================================================
// 3) Anti-double exécution : LOCK
// ============================================================

$lockFp = cron_try_lock($job);
if ($lockFp === false) {
    // Un autre run du même job est déjà en cours
    $runId = cron_log_start($pdo, $job, $triggeredBy, $triggeredByUserId);
    cron_log_end($pdo, $runId, 'locked', [], 'Un autre run du même job est déjà en cours');
    echo "Locked — un autre run est déjà en cours\n";
    exit(0);
}

// ============================================================
// 4) Dispatch vers le(s) job(s)
// ============================================================

$summary = [];

try {
    if ($job === 'all') {
        foreach (['impayes', 'essai', 'renouvellements'] as $subJob) {
            $summary[$subJob] = run_single_job($pdo, $subJob, $triggeredBy, $triggeredByUserId);
        }
    } else {
        $summary[$job] = run_single_job($pdo, $job, $triggeredBy, $triggeredByUserId);
    }
} catch (Throwable $e) {
    error_log('[CRON] Exception orchestrateur : ' . $e->getMessage());
    cron_unlock($lockFp);
    if ($isHttp) {
        http_response_code(500);
    }
    echo "Erreur : " . $e->getMessage() . "\n";
    exit(1);
}

cron_unlock($lockFp);

// ============================================================
// 5) Affichage résumé (utile pour debug)
// ============================================================

if ($isHttp) {
    header('Content-Type: text/plain; charset=utf-8');
}

echo "=== ASSOKIT CRON — " . date('Y-m-d H:i:s') . " ===\n";
echo "Job demandé : {$job}\n";
echo "Déclenché par : {$triggeredBy}\n\n";

foreach ($summary as $jobName => $stats) {
    echo "--- {$jobName} ---\n";
    echo "  Statut    : " . ($stats['status']    ?? '?') . "\n";
    echo "  Traités   : " . ($stats['processed'] ?? 0)  . "\n";
    echo "  Succès    : " . ($stats['succeeded'] ?? 0)  . "\n";
    echo "  Échecs    : " . ($stats['failed']    ?? 0)  . "\n";
    echo "  Emails    : " . ($stats['emails']    ?? 0)  . "\n";
    echo "  Durée ms  : " . ($stats['duration']  ?? 0)  . "\n";
    if (!empty($stats['error'])) {
        echo "  Erreur    : " . $stats['error'] . "\n";
    }
    echo "\n";
}

echo "=== Fin du run ===\n";
exit(0);

// ============================================================
// FONCTION : exécute un job unique avec log + try/catch
// ============================================================

function run_single_job(PDO $pdo, string $jobName, string $triggeredBy, ?int $triggeredByUserId): array
{
    $runId = cron_log_start($pdo, $jobName, $triggeredBy, $triggeredByUserId);
    $tStart = microtime(true);
    $stats = ['processed' => 0, 'succeeded' => 0, 'failed' => 0, 'emails' => 0, 'details' => []];

    try {
        $jobFile = __DIR__ . '/cron-job-' . $jobName . '.php';
        if (!is_file($jobFile)) {
            throw new RuntimeException("Fichier de job introuvable : {$jobFile}");
        }

        $stats = call_user_func(function () use ($pdo, $runId, $jobFile, $stats) {
            $result = require $jobFile;  // Chaque job retourne un array de stats
            if (is_array($result)) {
                return array_merge($stats, $result);
            }
            return $stats;
        });

        cron_log_end($pdo, $runId, 'success', $stats);
        $stats['status'] = 'success';

    } catch (Throwable $e) {
        error_log("[CRON {$jobName}] Exception : " . $e->getMessage());
        cron_log_end($pdo, $runId, 'error', $stats, $e->getMessage());
        $stats['status'] = 'error';
        $stats['error']  = $e->getMessage();
    }

    $stats['duration'] = (int) round((microtime(true) - $tStart) * 1000);
    $stats['run_id']   = $runId;
    return $stats;
}
