<?php
/**
 * ============================================================
 * AssoKit — CRON Synchronisation Google Calendar
 * ============================================================
 * À exécuter toutes les 5 min via O2switch CRON :
 *   /usr/bin/php /home/pura7044/public_html/cron-google-sync.php
 *
 * Pour chaque organisation connectée à Google Calendar :
 *   1. PULL : récupère les events Google (delta via syncToken)
 *   2. PUSH : envoie events AssoKit non-syncés ou modifiés
 *   3. DELETE : supprime sur Google les events soft-deleted
 *
 * Sécurité : ne tourne qu'en CLI ou avec ?key=CRON_SECRET
 * Lock : un seul cron à la fois
 * ============================================================
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/google-helper.php';

// ============================================================
// SÉCURITÉ : CLI uniquement (ou ?key=CRON_SECRET pour test web)
// ============================================================
// O2switch n'expose pas toujours sapi=cli, on combine plusieurs détections
$is_cli = (php_sapi_name() === 'cli')
       || defined('STDIN')
       || (empty($_SERVER['HTTP_HOST']) && empty($_SERVER['REMOTE_ADDR']))
       ;
$has_key = isset($_GET['key']) && defined('CRON_SECRET') && hash_equals(CRON_SECRET, $_GET['key']);
if (!$is_cli && !$has_key) {
    http_response_code(403);
    die("Forbidden");
}

// ============================================================
// LOCK : éviter chevauchement de crons
// ============================================================
$lock_file = sys_get_temp_dir() . '/assokit-google-sync.lock';
$lock_fp = @fopen($lock_file, 'c');
if (!$lock_fp || !flock($lock_fp, LOCK_EX | LOCK_NB)) {
    echo "[SKIP] Un autre cron est déjà en cours.\n";
    exit(0);
}

$started_at = microtime(true);
$max_duration = 270; // 4 min 30, marge avant timeout 5 min
echo "[" . date('Y-m-d H:i:s') . "] CRON Google Sync démarré\n";

// ============================================================
// CHARGER LES CONNEXIONS ACTIVES
// ============================================================
$stmt = $pdo->query("SELECT * FROM org_google_calendar WHERE sync_enabled = 1");
$connections = $stmt->fetchAll();
echo "→ " . count($connections) . " organisation(s) connectée(s)\n\n";

$total_stats = ['orgs' => 0, 'pull_imported' => 0, 'pull_updated' => 0, 'pull_deleted' => 0, 'push_created' => 0, 'push_updated' => 0, 'push_deleted' => 0, 'errors' => 0];

foreach ($connections as $connection) {
    if (microtime(true) - $started_at > $max_duration) {
        echo "[TIMEOUT] Limite atteinte, arrêt.\n";
        break;
    }

    $org_id = (int)$connection['org_id'];
    echo "── Org #$org_id ──\n";
    $total_stats['orgs']++;

    // ============================================================
    // 1. PULL : Google → AssoKit
    // ============================================================
    try {
        $pull = pull_events_from_google($org_id);
        if (!empty($pull['success'])) {
            echo "  PULL : +{$pull['imported']} importés, ~{$pull['updated']} MAJ, -{$pull['deleted']} supprimés\n";
            $total_stats['pull_imported'] += $pull['imported'];
            $total_stats['pull_updated'] += $pull['updated'];
            $total_stats['pull_deleted'] += $pull['deleted'];
        } else {
            echo "  PULL ERREUR : " . ($pull['error'] ?? 'inconnue') . "\n";
            $total_stats['errors']++;
        }
    } catch (Throwable $e) {
        echo "  PULL EXCEPTION : " . $e->getMessage() . "\n";
        $total_stats['errors']++;
    }

    // ============================================================
    // 2. PUSH : AssoKit → Google (events à syncer)
    // ============================================================
    // Events :
    //   - org_id = celui-ci
    //   - sync_origin != 'google' (pas créé depuis Google)
    //   - deleted_at IS NULL
    //   - (jamais synced OU modifié après dernière sync)
    try {
        $push_stmt = $pdo->prepare("
            SELECT e.id
            FROM events e
            WHERE e.org_id = ?
              AND (e.sync_origin IS NULL OR e.sync_origin != 'google')
              AND e.deleted_at IS NULL
              AND (
                  e.synced_at IS NULL
                  OR (e.updated_at IS NOT NULL AND e.updated_at > e.synced_at)
              )
            ORDER BY e.id ASC
            LIMIT 50
        ");
        $push_stmt->execute([$org_id]);
        $to_push = $push_stmt->fetchAll(PDO::FETCH_COLUMN);

        $pushed_created = $pushed_updated = $push_errors = 0;
        foreach ($to_push as $eid) {
            // Vérifie s'il avait déjà un google_event_id pour distinguer create/update
            $check = $pdo->prepare("SELECT google_event_id FROM events WHERE id = ?");
            $check->execute([$eid]);
            $had_google_id = !empty($check->fetchColumn());

            $r = sync_event_to_google((int)$eid);
            if (!empty($r['success'])) {
                if ($had_google_id) $pushed_updated++;
                else $pushed_created++;
            } elseif (empty($r['skipped'])) {
                $push_errors++;
            }
        }
        echo "  PUSH : +$pushed_created créés, ~$pushed_updated MAJ" . ($push_errors > 0 ? ", $push_errors erreurs" : '') . "\n";
        $total_stats['push_created'] += $pushed_created;
        $total_stats['push_updated'] += $pushed_updated;
        $total_stats['errors'] += $push_errors;
    } catch (Throwable $e) {
        echo "  PUSH EXCEPTION : " . $e->getMessage() . "\n";
        $total_stats['errors']++;
    }

    // ============================================================
    // 3. DELETE : events soft-deleted dans AssoKit → Google
    // ============================================================
    try {
        $del_stmt = $pdo->prepare("
            SELECT id FROM events
            WHERE org_id = ?
              AND deleted_at IS NOT NULL
              AND google_event_id IS NOT NULL
              AND google_event_id != ''
            LIMIT 50
        ");
        $del_stmt->execute([$org_id]);
        $to_delete = $del_stmt->fetchAll(PDO::FETCH_COLUMN);

        $deleted = 0;
        foreach ($to_delete as $eid) {
            $r = delete_event_from_google((int)$eid);
            if (!empty($r['success'])) {
                // Vide le google_event_id pour ne plus retenter
                $pdo->prepare("UPDATE events SET google_event_id = NULL WHERE id = ?")->execute([$eid]);
                $deleted++;
            }
        }
        if ($deleted > 0) echo "  DELETE : -$deleted sur Google\n";
        $total_stats['push_deleted'] += $deleted;
    } catch (Throwable $e) {
        echo "  DELETE EXCEPTION : " . $e->getMessage() . "\n";
        $total_stats['errors']++;
    }
}

// ============================================================
// RÉCAP
// ============================================================
$elapsed = round(microtime(true) - $started_at, 2);
echo "\n[" . date('Y-m-d H:i:s') . "] Terminé en {$elapsed}s\n";
echo "  Orgs traitées      : {$total_stats['orgs']}\n";
echo "  PULL  imp/maj/del  : {$total_stats['pull_imported']} / {$total_stats['pull_updated']} / {$total_stats['pull_deleted']}\n";
echo "  PUSH  new/upd/del  : {$total_stats['push_created']} / {$total_stats['push_updated']} / {$total_stats['push_deleted']}\n";
echo "  Erreurs            : {$total_stats['errors']}\n";

// Log global dans sync_logs
try {
    $msg = sprintf("Cron OK · %d orgs · pull +%d/~%d/-%d · push +%d/~%d/-%d · %.1fs · %d err",
        $total_stats['orgs'],
        $total_stats['pull_imported'], $total_stats['pull_updated'], $total_stats['pull_deleted'],
        $total_stats['push_created'], $total_stats['push_updated'], $total_stats['push_deleted'],
        $elapsed, $total_stats['errors']);
    $pdo->prepare("INSERT INTO sync_logs (org_id, event_id, direction, action, status, message) VALUES (NULL, NULL, 'cron', 'run', ?, ?)")
        ->execute([$total_stats['errors'] > 0 ? 'warning' : 'success', $msg]);
} catch (Throwable $e) {}

// Libère le lock
flock($lock_fp, LOCK_UN);
fclose($lock_fp);
@unlink($lock_file);

exit(0);
