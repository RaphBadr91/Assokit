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
 *   1. Envoie les alertes aux admins : J-30, J-14, J-7, J-1
 *   2. Purge DEFINITIVE des dossiers/projets archivés depuis > 30 jours
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
    if (!defined('CRON_TOKEN') || $_GET['cron_token'] !== CRON_TOKEN) {
        http_response_code(403);
        die('Invalid token');
    }
} else {
    require_once __DIR__ . '/config.php';
}

require_once __DIR__ . '/archive-helper.php';
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

cron_log('========== DÉMARRAGE CRON ARCHIVES ==========');

// =====================================================================
// ÉTAPE 1 : ENVOI DES ALERTES (J-30, J-14, J-7, J-1)
// =====================================================================
$alert_days = [30, 14, 7, 1];

foreach ($alert_days as $alert_day) {
    cron_log("--- Vérification alertes J-{$alert_day} ---");

    // Dossiers à alerter : archived_at + (RETENTION - alert_day) jours <= maintenant < +1j
    $stmt = $pdo->prepare("
        SELECT
            f.id, f.name, f.archived_at, f.org_id,
            o.name AS org_name,
            (SELECT u.email FROM users u WHERE u.org_id = f.org_id AND u.role = 'admin' AND u.deleted_at IS NULL ORDER BY u.id LIMIT 1) AS admin_email,
            (SELECT u.first_name FROM users u WHERE u.org_id = f.org_id AND u.role = 'admin' AND u.deleted_at IS NULL ORDER BY u.id LIMIT 1) AS admin_name,
            DATEDIFF(DATE_ADD(f.archived_at, INTERVAL ? DAY), NOW()) AS days_left
        FROM folders f
        JOIN organizations o ON f.org_id = o.id
        WHERE f.archived_at IS NOT NULL
          AND DATEDIFF(DATE_ADD(f.archived_at, INTERVAL ? DAY), NOW()) = ?
    ");
    $stmt->execute([ARCHIVE_RETENTION_DAYS, ARCHIVE_RETENTION_DAYS, $alert_day]);
    $folders_to_alert = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($folders_to_alert as $f) {
        // Verifier si alerte deja envoyee
        $check = $pdo->prepare("
            SELECT id FROM archive_purge_alerts
            WHERE target_type = 'folder' AND target_id = ? AND alert_day = ?
        ");
        $check->execute([(int)$f['id'], $alert_day]);
        if ($check->fetchColumn()) {
            cron_log("  ⏭ Alerte J-{$alert_day} déjà envoyée pour dossier #{$f['id']}");
            continue;
        }

        if (empty($f['admin_email'])) {
            cron_log("  ⚠ Pas d'admin email pour org {$f['org_name']} (dossier #{$f['id']})");
            continue;
        }

        $urgency = match(true) {
            $alert_day <= 1 => '🚨 Dernière chance',
            $alert_day <= 7 => '⚠️ Urgent',
            $alert_day <= 14 => '🔔 Bientôt purgé',
            default => '📅 Information',
        };

        $email_ok = false;
        if (function_exists('send_transactional_email')) {
            $url_archives = 'https://assokit.fr/archives';
            $content = '<h1 style="font-size:22px;margin:0 0 14px;font-weight:500;color:#1C1917">' . $urgency . ' : Purge d\'archive dans ' . $alert_day . ' jour' . ($alert_day > 1 ? 's' : '') . '</h1>'
                     . '<p>Bonjour ' . htmlspecialchars($f['admin_name'] ?: 'Administrateur') . ',</p>'
                     . '<p>Le dossier <strong>« ' . htmlspecialchars($f['name']) . ' »</strong> de votre association <strong>' . htmlspecialchars($f['org_name']) . '</strong> est archivé depuis le <strong>' . date('d/m/Y', strtotime($f['archived_at'])) . '</strong>.</p>'
                     . '<div style="background:' . ($alert_day <= 7 ? '#FEF2F2' : '#FFFBEB') . ';border-left:3px solid ' . ($alert_day <= 7 ? '#DC2626' : '#F59E0B') . ';padding:12px 16px;border-radius:6px;margin:16px 0;font-size:13px;line-height:1.6;">'
                     . '⏰ <strong>Il sera définitivement supprimé dans ' . $alert_day . ' jour' . ($alert_day > 1 ? 's' : '') . '</strong>, soit le <strong>' . date('d/m/Y', strtotime($f['archived_at']) + ARCHIVE_RETENTION_DAYS * 86400) . '</strong>.<br>'
                     . 'Après cette date, aucune restauration ne sera possible.'
                     . '</div>'
                     . '<p>Si vous souhaitez conserver ce dossier, vous pouvez encore le <strong>restaurer depuis votre espace Archives</strong>.</p>'
                     . '<p style="font-size:12px;color:#78716C;margin-top:18px;">Vous recevez cet email parce que vous êtes administrateur de ' . htmlspecialchars($f['org_name']) . ' sur Assokit.</p>';

            $html = function_exists('email_wrap')
                ? email_wrap($urgency . ' - Purge d\'archive imminente', $content, '📦 Voir mes archives', $url_archives)
                : $content . '<p><a href="' . $url_archives . '">Voir mes archives</a></p>';

            try {
                $result = send_transactional_email(
                    $f['admin_email'],
                    '[' . $f['org_name'] . '] ' . $urgency . ' : Purge du dossier "' . $f['name'] . '" dans ' . $alert_day . 'j',
                    $html,
                    ['tag' => 'archive_alert_j' . $alert_day]
                );
                $email_ok = !empty($result['success']);
            } catch (Throwable $e) {
                cron_log("  ❌ Erreur envoi email: " . $e->getMessage());
            }
        }

        // Enregistrer l'alerte comme envoyee
        try {
            $ins = $pdo->prepare("
                INSERT INTO archive_purge_alerts (target_type, target_id, alert_day, admin_email)
                VALUES ('folder', ?, ?, ?)
            ");
            $ins->execute([(int)$f['id'], $alert_day, $f['admin_email']]);
            cron_log($email_ok ? "  ✅ Alerte J-{$alert_day} envoyée pour dossier « {$f['name']} » ({$f['admin_email']})" : "  ⚠ Alerte J-{$alert_day} loggée mais email KO pour dossier #{$f['id']}");
        } catch (Throwable $e) {
            cron_log("  ❌ Erreur SQL alert log: " . $e->getMessage());
        }
    }
}

// =====================================================================
// ÉTAPE 2 : PURGE DÉFINITIVE (> 30 jours archivés)
// =====================================================================
cron_log('--- Purge définitive (> ' . ARCHIVE_RETENTION_DAYS . ' jours) ---');

// Purger les projets d'abord (respecter les FK)
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
foreach ($to_purge_projects as $p) {
    try {
        // Log avant suppression
        archive_log($pdo, (int)$p['org_id'], 'purge_project', 'project', (int)$p['id'], $p['name'], 0, ['cron_auto' => true]);

        // DELETE (cascade sur projets_references, etc. si FK bien configurees)
        $del = $pdo->prepare("DELETE FROM projects WHERE id = ?");
        $del->execute([(int)$p['id']]);
        $nb_purged_projects++;
    } catch (Throwable $e) {
        cron_log("  ❌ Échec purge projet #{$p['id']}: " . $e->getMessage());
    }
}
cron_log("  ✅ {$nb_purged_projects} projet(s) purgé(s) définitivement");

// Puis purger les dossiers
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
        archive_log($pdo, (int)$f['org_id'], 'purge_folder', 'folder', (int)$f['id'], $f['name'], 0, ['cron_auto' => true]);
        $del = $pdo->prepare("DELETE FROM folders WHERE id = ?");
        $del->execute([(int)$f['id']]);
        $nb_purged_folders++;
    } catch (Throwable $e) {
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
