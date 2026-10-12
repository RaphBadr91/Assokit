<?php
/**
 * ============================================================
 * ASSOKIT — CRON : Purge des archives + alertes
 * ============================================================
 * À configurer dans cPanel → Cron Jobs :
 *   0 2 * * * /usr/bin/php /home/pura7044/public_html/cron-purge-archives.php
 * (Tous les jours à 2h du matin)
 *
 * Actions :
 *   1. Envoie les alertes aux admins actifs : J-30, J-14, J-7, J-1
 *      (dossiers archivés et projets archivés seuls)
 *   2. Purge DEFINITIVE des dossiers/projets archivés depuis > 30 jours :
 *      lignes liées (étapes, fichiers, messages, équipe…) et fichiers du
 *      disque (uploads/projet_<id>/) — sans compter sur des clés étrangères
 *   3. Log dans archive_logs
 * ============================================================
 */

// Securite : refuser acces HTTP (uniquement CLI ou token)
if (PHP_SAPI !== 'cli' && !isset($_GET['cron_token'])) {
    http_response_code(403);
    die('CRON endpoint - CLI only');
}

// Si appel HTTP, verifier le token (a definir dans config.php : CRON_TOKEN)
if (PHP_SAPI !== 'cli') {
    require_once __DIR__ . '/config.php';
    if (!defined('CRON_TOKEN') || (string)CRON_TOKEN === '' || !hash_equals((string)CRON_TOKEN, (string)($_GET['cron_token'] ?? ''))) {
        http_response_code(403);
        die('Invalid token');
    }
} else {
    require_once __DIR__ . '/config.php';
}

require_once __DIR__ . '/archive-helper.php';
require_once __DIR__ . '/trial-helpers.php';   // ak_db_columns() : le schéma de prod n'est pas versionné
@require_once __DIR__ . '/resend-helper.php';

$log_file = __DIR__ . '/logs/cron-archives-' . date('Y-m') . '.log';
@mkdir(__DIR__ . '/logs', 0755, true);

function cron_log(string $msg): void
{
    global $log_file;
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n";
    @file_put_contents($log_file, $line, FILE_APPEND);
    if (PHP_SAPI === 'cli') echo $line;
}

/** Administrateurs actifs d'une association (destinataires des alertes de purge). */
function purge_org_admins(PDO $pdo, int $org_id): array
{
    $st = $pdo->prepare("
        SELECT email, first_name FROM users
        WHERE org_id = ? AND role = 'admin' AND is_active = 1 AND deleted_at IS NULL
          AND email IS NOT NULL AND email <> ''
        ORDER BY id
    ");
    $st->execute([$org_id]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Alerte J-x avant la purge d'un dossier ou d'un projet archivé seul, envoyée à chaque
 * administrateur actif. Une ligne archive_purge_alerts par (cible, jour) évite les doublons.
 * Le délai annoncé est celui qui reste vraiment ($t['days_left']) : une alerte relancée
 * après un échec d'envoi n'annonce pas « dans 7 jours » à J-5.
 */
function purge_send_alert(PDO $pdo, string $type, array $t, int $alert_day): void
{
    $is_folder = ($type === 'folder');
    $what = $is_folder ? 'dossier' : 'projet';

    $check = $pdo->prepare("SELECT id FROM archive_purge_alerts WHERE target_type = ? AND target_id = ? AND alert_day = ?");
    $check->execute([$type, (int)$t['id'], $alert_day]);
    if ($check->fetchColumn()) {
        cron_log("  ⏭ Alerte J-{$alert_day} déjà envoyée pour {$what} #{$t['id']}");
        return;
    }

    $admins = purge_org_admins($pdo, (int)$t['org_id']);
    if (!$admins) {
        cron_log("  ⚠ Pas d'admin actif pour org {$t['org_name']} ({$what} #{$t['id']})");
        return;
    }

    $left = max(0, (int)($t['days_left'] ?? $alert_day));
    $urgency = match(true) {
        $left <= 1 => '🚨 Dernière chance',
        $left <= 7 => '⚠️ Urgent',
        $left <= 14 => '🔔 Bientôt purgé',
        default => '📅 Information',
    };
    $when = $left > 0 ? 'dans ' . $left . ' jour' . ($left > 1 ? 's' : '') : 'aujourd’hui';
    $purge_date = date('d/m/Y', strtotime($t['archived_at']) + ARCHIVE_RETENTION_DAYS * 86400);
    $url_archives = 'https://assokit.fr/archives';

    $sent = [];
    foreach ($admins as $a) {
        if (!function_exists('send_transactional_email')) break;
        $content = '<h1 style="font-size:22px;margin:0 0 14px;font-weight:500;color:#1C1917">' . $urgency . ' : Purge d\'archive ' . $when . '</h1>'
                 . '<p>Bonjour ' . htmlspecialchars($a['first_name'] ?: 'Administrateur') . ',</p>'
                 . '<p>Le ' . $what . ' <strong>« ' . htmlspecialchars($t['name']) . ' »</strong> de votre association <strong>' . htmlspecialchars($t['org_name']) . '</strong> est archivé depuis le <strong>' . date('d/m/Y', strtotime($t['archived_at'])) . '</strong>.</p>'
                 . '<div style="background:' . ($left <= 7 ? '#FEF2F2' : '#FFFBEB') . ';border-left:3px solid ' . ($left <= 7 ? '#DC2626' : '#F59E0B') . ';padding:12px 16px;border-radius:6px;margin:16px 0;font-size:13px;line-height:1.6;">'
                 . '⏰ <strong>Il sera définitivement supprimé ' . $when . '</strong>, soit le <strong>' . $purge_date . '</strong>, avec ses fichiers et justificatifs.<br>'
                 . 'Après cette date, aucune restauration ne sera possible.'
                 . '</div>'
                 . '<p>Si vous souhaitez conserver ce ' . $what . ', vous pouvez encore le <strong>restaurer depuis votre espace Archives</strong>.</p>'
                 . '<p style="font-size:12px;color:#78716C;margin-top:18px;">Vous recevez cet email parce que vous êtes administrateur de ' . htmlspecialchars($t['org_name']) . ' sur Assokit.</p>';
        $html = function_exists('email_wrap')
            ? email_wrap($urgency . ' - Purge d\'archive imminente', $content, '📦 Voir mes archives', $url_archives)
            : $content . '<p><a href="' . $url_archives . '">Voir mes archives</a></p>';
        try {
            $result = send_transactional_email(
                $a['email'],
                '[' . $t['org_name'] . '] ' . $urgency . ' : Purge du ' . $what . ' "' . $t['name'] . '" ' . ($left > 0 ? 'dans ' . $left . 'j' : 'aujourd’hui'),
                $html,
                ['tag' => 'archive_alert_j' . $alert_day]
            );
            if (!empty($result['success'])) $sent[] = $a['email'];
        } catch (Throwable $e) {
            cron_log("  ❌ Erreur envoi email: " . $e->getMessage());
        }
    }

    // Aucun e-mail parti : rien n'est noté, le passage suivant du cron la renverra (fenêtre J-x).
    if (!$sent) {
        cron_log("  ⚠ Alerte J-{$alert_day} non envoyée (email KO) pour {$what} #{$t['id']} : non notée, à relancer");
        return;
    }
    try {
        $ins = $pdo->prepare("INSERT INTO archive_purge_alerts (target_type, target_id, alert_day, admin_email) VALUES (?, ?, ?, ?)");
        $ins->execute([$type, (int)$t['id'], $alert_day, mb_substr(implode(',', $sent), 0, 255)]);
        cron_log("  ✅ Alerte J-{$alert_day} envoyée pour {$what} « {$t['name']} » (" . implode(', ', $sent) . ")");
    } catch (Throwable $e) {
        cron_log("  ❌ Erreur SQL alert log: " . $e->getMessage());
    }
}

/**
 * Tables filles d'un projet, supprimées avec lui (ordre : petits-enfants d'abord).
 * Chaque table / colonne absente du schéma réel est simplement ignorée.
 */
const PURGE_PROJECT_CHILDREN = [
    'project_steps', 'project_files', 'project_documents', 'project_invoices', 'project_members',
    'project_followers', 'project_messages', 'project_updates', 'project_share_tokens',
    'project_activity_log', 'project_grants', 'ai_generated_docs', 'ai_conversations',
    'user_project_reads', 'user_notifications',
];
/** Données de l'association qui citent le projet : conservées, simplement détachées. */
const PURGE_PROJECT_DETACH = ['grants', 'events', 'attendance_sessions', 'communication_events'];

function purge_has_col(PDO $pdo, string $table, string $col): bool
{
    return isset(ak_db_columns($pdo, $table)[$col]);
}

/** Fichiers d'un projet référencés en base (pièces, justificatifs, documents). */
function purge_project_file_paths(PDO $pdo, int $project_id): array
{
    $paths = [];
    foreach ([['project_files', 'filepath'], ['project_invoices', 'file_path'], ['project_invoices', 'filepath'], ['project_documents', 'file_path']] as [$t, $c]) {
        if (!purge_has_col($pdo, $t, $c) || !purge_has_col($pdo, $t, 'project_id')) continue;
        $st = $pdo->prepare("SELECT `$c` FROM `$t` WHERE project_id = ? AND `$c` IS NOT NULL AND `$c` <> ''");
        $st->execute([$project_id]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $p) $paths[] = (string)$p;
    }
    return array_values(array_unique($paths));
}

/** Supprime un fichier ou un dossier s'il est bien sous uploads/ (jamais en dehors, liens non suivis). */
function purge_remove_path(string $path): int
{
    $uploads = realpath(__DIR__ . '/uploads');
    if ($uploads === false) return 0;
    if (!is_link($path)) {
        $real = realpath($path);
        if ($real === false || strpos($real, $uploads . DIRECTORY_SEPARATOR) !== 0) return 0;
        $path = $real;
    } elseif (strpos(realpath(dirname($path)) ?: '', $uploads) !== 0) {
        return 0;
    }
    if (is_link($path) || is_file($path)) return @unlink($path) ? 1 : 0;
    if (!is_dir($path)) return 0;
    $n = 0;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) {
        if ($f->isDir() && !$f->isLink()) @rmdir($f->getPathname());
        elseif (@unlink($f->getPathname())) $n++;
    }
    @rmdir($path);
    return $n;
}

/**
 * Purge définitive d'un projet : lignes filles + projet dans une transaction, puis,
 * une fois validée, ses fichiers sur le disque (uploads/projet_<id>/ et fichiers référencés).
 * Lève une exception (rien n'est supprimé) si la base refuse.
 * @return int nombre de fichiers effacés
 */
function purge_project(PDO $pdo, array $p): int
{
    $pid = (int)$p['id'];
    $paths = purge_project_file_paths($pdo, $pid);

    $pdo->beginTransaction();
    try {
        archive_log($pdo, (int)$p['org_id'], 'purge_project', 'project', $pid, (string)$p['name'], 0, ['cron_auto' => true]);

        foreach (PURGE_PROJECT_DETACH as $t) {
            $cols = ak_db_columns($pdo, $t);
            if (empty($cols['project_id'])) continue;
            if (!$cols['project_id']['null']) { cron_log("  ⚠ $t.project_id non nullable : lignes du projet #$pid laissées telles quelles"); continue; }
            $pdo->prepare("UPDATE `$t` SET project_id = NULL WHERE project_id = ?")->execute([$pid]);
        }
        if (purge_has_col($pdo, 'ai_messages', 'conversation_id') && purge_has_col($pdo, 'ai_conversations', 'project_id')) {
            $pdo->prepare("DELETE FROM ai_messages WHERE conversation_id IN (SELECT id FROM ai_conversations WHERE project_id = ?)")->execute([$pid]);
        }
        foreach (PURGE_PROJECT_CHILDREN as $t) {
            if (!purge_has_col($pdo, $t, 'project_id')) continue;
            $pdo->prepare("DELETE FROM `$t` WHERE project_id = ?")->execute([$pid]);
        }
        $pdo->prepare("DELETE FROM projects WHERE id = ?")->execute([$pid]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    $n = 0;
    foreach ($paths as $rel) $n += purge_remove_path(__DIR__ . '/' . ltrim($rel, '/'));
    $dir = __DIR__ . '/uploads/projet_' . $pid;
    if (is_dir($dir) || is_link($dir)) $n += purge_remove_path($dir);
    return $n;
}

cron_log('========== DÉMARRAGE CRON ARCHIVES ==========');

// =====================================================================
// ÉTAPE 1 : ENVOI DES ALERTES (J-30, J-14, J-7, J-1)
//   dossiers archivés, et projets archivés seuls (hors archivage de dossier)
// =====================================================================
$alert_days = [30, 14, 7, 1];
if (!isset(ak_db_columns($pdo, 'archive_purge_alerts')['alert_day'])) {
    cron_log("  ❌ Table archive_purge_alerts absente : alertes non envoyées (doublons impossibles à éviter)");
    $alert_days = [];
}

// Chaque alerte couvre les jours jusqu'à la suivante (J-30 : 30 à 15 jours restants, J-14 : 14 à 8,
// J-7 : 7 à 2, J-1 : 1 à 0) : une alerte non partie (e-mail en échec, cron sauté) est retentée
// au passage suivant au lieu d'être perdue. Celles déjà notées ne sont pas reprises.
foreach ($alert_days as $i => $alert_day) {
    $floor = ($alert_days[$i + 1] ?? -1) + 1;
    cron_log("--- Vérification alertes J-{$alert_day} ---");

    $stmt = $pdo->prepare("
        SELECT f.id, f.name, f.archived_at, f.org_id, o.name AS org_name,
               DATEDIFF(DATE_ADD(f.archived_at, INTERVAL ? DAY), NOW()) AS days_left
        FROM folders f
        JOIN organizations o ON f.org_id = o.id
        WHERE f.archived_at IS NOT NULL
          AND DATEDIFF(DATE_ADD(f.archived_at, INTERVAL ? DAY), NOW()) BETWEEN ? AND ?
          AND NOT EXISTS (SELECT 1 FROM archive_purge_alerts a
                          WHERE a.target_type = 'folder' AND a.target_id = f.id AND a.alert_day = ?)
    ");
    $stmt->execute([ARCHIVE_RETENTION_DAYS, ARCHIVE_RETENTION_DAYS, $floor, $alert_day, $alert_day]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $f) purge_send_alert($pdo, 'folder', $f, $alert_day);

    $stmt = $pdo->prepare("
        SELECT p.id, p.name, p.archived_at, f.org_id, o.name AS org_name,
               DATEDIFF(DATE_ADD(p.archived_at, INTERVAL ? DAY), NOW()) AS days_left
        FROM projects p
        JOIN folders f ON p.folder_id = f.id
        JOIN organizations o ON f.org_id = o.id
        WHERE p.archived_at IS NOT NULL AND COALESCE(p.archived_via_folder, 0) = 0
          AND DATEDIFF(DATE_ADD(p.archived_at, INTERVAL ? DAY), NOW()) BETWEEN ? AND ?
          AND NOT EXISTS (SELECT 1 FROM archive_purge_alerts a
                          WHERE a.target_type = 'project' AND a.target_id = p.id AND a.alert_day = ?)
    ");
    $stmt->execute([ARCHIVE_RETENTION_DAYS, ARCHIVE_RETENTION_DAYS, $floor, $alert_day, $alert_day]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $p) purge_send_alert($pdo, 'project', $p, $alert_day);
}

// =====================================================================
// ÉTAPE 2 : PURGE DÉFINITIVE (> 30 jours archivés)
//   projet : données liées + fichiers ; dossier : ses projets, puis lui-même
// =====================================================================
cron_log('--- Purge définitive (> ' . ARCHIVE_RETENTION_DAYS . ' jours) ---');

$stmt = $pdo->prepare("
    SELECT p.id, p.name, f.org_id
    FROM projects p
    JOIN folders f ON p.folder_id = f.id
    WHERE p.archived_at IS NOT NULL
      AND p.archived_at < DATE_SUB(NOW(), INTERVAL ? DAY)
");
$stmt->execute([ARCHIVE_RETENTION_DAYS]);
$to_purge_projects = $stmt->fetchAll(PDO::FETCH_ASSOC);

$nb_purged_projects = 0;
$nb_files = 0;
foreach ($to_purge_projects as $p) {
    try {
        $nb_files += purge_project($pdo, $p);
        $nb_purged_projects++;
    } catch (Throwable $e) {
        cron_log("  ❌ Échec purge projet #{$p['id']}: " . $e->getMessage());
    }
}
cron_log("  ✅ {$nb_purged_projects} projet(s) purgé(s) définitivement ({$nb_files} fichier(s) effacé(s))");

$stmt = $pdo->prepare("
    SELECT id, name, org_id FROM folders
    WHERE archived_at IS NOT NULL
      AND archived_at < DATE_SUB(NOW(), INTERVAL ? DAY)
");
$stmt->execute([ARCHIVE_RETENTION_DAYS]);
$to_purge_folders = $stmt->fetchAll(PDO::FETCH_ASSOC);

$nb_purged_folders = 0;
foreach ($to_purge_folders as $f) {
    try {
        // Projets restés dans le dossier (archivés avec lui) : purgés avec lui. Un projet
        // non archivé dans un dossier archivé serait une anomalie : on ne touche à rien.
        $st = $pdo->prepare("SELECT id, name, archived_at FROM projects WHERE folder_id = ?");
        $st->execute([(int)$f['id']]);
        $inner = $st->fetchAll(PDO::FETCH_ASSOC);
        if (array_filter($inner, fn($p) => empty($p['archived_at']))) {
            cron_log("  ⚠ Dossier #{$f['id']} conservé : il contient un projet non archivé");
            continue;
        }
        foreach ($inner as $p) {
            $nb_files += purge_project($pdo, ['id' => $p['id'], 'name' => $p['name'], 'org_id' => $f['org_id']]);
            $nb_purged_projects++;
        }

        $pdo->beginTransaction();
        archive_log($pdo, (int)$f['org_id'], 'purge_folder', 'folder', (int)$f['id'], $f['name'], 0, ['cron_auto' => true]);
        if (purge_has_col($pdo, 'user_pinned_folders', 'folder_id')) {
            $pdo->prepare("DELETE FROM user_pinned_folders WHERE folder_id = ?")->execute([(int)$f['id']]);
        }
        $pdo->prepare("DELETE FROM folders WHERE id = ?")->execute([(int)$f['id']]);
        $pdo->commit();
        $nb_purged_folders++;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        cron_log("  ❌ Échec purge dossier #{$f['id']}: " . $e->getMessage());
    }
}
cron_log("  ✅ {$nb_purged_folders} dossier(s) purgé(s) définitivement");

// =====================================================================
// ÉTAPE 3 : NETTOYAGE (alertes purgeables anciennes)
// =====================================================================
try {
    $del = $pdo->prepare("DELETE FROM archive_purge_alerts WHERE sent_at < DATE_SUB(NOW(), INTERVAL 60 DAY)");
    $del->execute();
    cron_log("  🧹 Nettoyage alertes anciennes : {$del->rowCount()} ligne(s)");
} catch (Throwable $e) {}

cron_log('========== FIN CRON ARCHIVES ==========');

if (PHP_SAPI !== 'cli') {
    echo "OK\n";
}
