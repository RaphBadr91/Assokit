<?php
/**
 * ============================================================
 * ASSOKIT — CRON : synchronisation des boîtes Gmail
 * ============================================================
 * Toutes les 5 minutes (cPanel → Tâches cron) :
 *   * /5 * * * *  /usr/local/bin/php /home/<compte>/public_html/cron-mail-sync.php
 *
 * Pour chaque boîte reliée : nouveaux e-mails, lus/non-lus, tri
 * (règles puis IA), rattachement aux fiches. Une fois par jour, effacement
 * local des fils plus vieux que la durée de conservation choisie.
 * ============================================================
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/mail-helpers.php';
@require_once __DIR__ . '/ai-helper.php';

$is_cli = (PHP_SAPI === 'cli') || !isset($_SERVER['REQUEST_METHOD']);
$has_key = isset($_GET['key']) && defined('CRON_SECRET') && hash_equals(CRON_SECRET, (string)$_GET['key']);
if (!$is_cli && !$has_key) { http_response_code(403); die('Forbidden'); }
if (!mail_schema_ready($pdo)) { echo "Tables absentes : lancer migrations/2026-10-06-boite-mail.sql\n"; exit(0); }

// Un seul passage à la fois
$lock = fopen(sys_get_temp_dir() . '/assokit-mail-sync.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) { echo "Déjà en cours\n"; exit(0); }
@set_time_limit(280);

$accs = $pdo->query("SELECT * FROM mail_accounts WHERE provider = 'gmail' AND status <> 'revoked' ORDER BY last_sync_at IS NULL DESC, last_sync_at ASC")->fetchAll(PDO::FETCH_ASSOC);
$t0 = microtime(true);
foreach ($accs as $acc) {
    if (microtime(true) - $t0 > 240) break;
    try {
        $r = mail_sync($pdo, $acc, 40.0);
        echo date('H:i:s') . " org #{$acc['org_id']} : +{$r['ajoutes']}" . ($r['encore'] ? ' (suite au prochain passage)' : '') . ($r['erreur'] ? " — {$r['erreur']}" : '') . "\n";
        if ((int)date('G') === 3 && (int)date('i') < 5) {
            $n = mail_purge_old($pdo, $acc);
            if ($n) echo "  conservation : $n fil(s) ancien(s) effacé(s)\n";
        }
    } catch (Throwable $e) {
        echo "org #{$acc['org_id']} : erreur " . $e->getMessage() . "\n";
    }
}
