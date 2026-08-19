<?php
/**
 * AssoKit — CRON Relances intelligentes (auto)
 * À lancer 1x par jour (matin).
 *   /usr/bin/php /home/pura7044/public_html/cron-relances.php
 *
 * Pour chaque org ayant activé l'auto-relance (org_relance_prefs) :
 *   - envoie les relances de factures dues (dunning1/2/3) ;
 *   - envoie les relances de cotisations dues ;
 *   - ne dépasse jamais le stade max choisi par l'org ;
 *   - respecte la cadence (déjà gérée par le moteur : due_now).
 *
 * L'envoi manuel reste disponible dans /relances. L'auto est opt-in.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/relances-engine.php';

$is_cli = (PHP_SAPI === 'cli');
$has_key = isset($_GET['key']) && defined('CRON_SECRET') && hash_equals(CRON_SECRET, $_GET['key']);
if (!$is_cli && !$has_key) { http_response_code(403); die('Forbidden'); }

$started = microtime(true);
echo "[" . date('Y-m-d H:i:s') . "] CRON Relances auto démarré\n";

$orgs = [];
try {
    $orgs = $pdo->query("SELECT org_id, auto_invoices, auto_memberships, max_stage
                         FROM org_relance_prefs
                         WHERE auto_invoices = 1 OR auto_memberships = 1")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { fwrite(STDERR, $e->getMessage()."\n"); }
echo "→ " . count($orgs) . " org(s) en auto-relance\n";

$total_inv = 0; $total_mem = 0; $fail = 0;

foreach ($orgs as $o) {
    $org_id = (int)$o['org_id'];
    $maxStage = max(1, min(3, (int)$o['max_stage']));

    if (!empty($o['auto_invoices'])) {
        try {
            foreach (ak_rel_invoice_targets($pdo, $org_id) as $t) {
                if (!$t['due_now'] || $t['stage'] > $maxStage) continue;
                $res = ak_asso_invoice_send_email($pdo, (int)$t['id'], 'dunning'.$t['stage'], null);
                if (!empty($res['success'])) { $total_inv++; echo "  ✓ org #$org_id facture {$t['number']} (stade {$t['stage']})\n"; }
                else { $fail++; echo "  ✗ org #$org_id facture {$t['number']} : {$res['message']}\n"; }
                usleep(200000);
            }
        } catch (Throwable $e) { echo "  ! org #$org_id factures : ".$e->getMessage()."\n"; }
    }

    if (!empty($o['auto_memberships'])) {
        try {
            foreach (ak_rel_membership_targets($pdo, $org_id) as $t) {
                if (!$t['due_now'] || $t['stage'] > $maxStage) continue;
                $res = ak_rel_send_membership($pdo, $org_id, (int)$t['id'], (int)$t['stage'], null);
                if (!empty($res['success'])) { $total_mem++; echo "  ✓ org #$org_id cotisation user#{$t['id']} (stade {$t['stage']})\n"; }
                else { $fail++; }
                usleep(200000);
            }
        } catch (Throwable $e) { echo "  ! org #$org_id cotisations : ".$e->getMessage()."\n"; }
    }
}

$elapsed = round(microtime(true) - $started, 2);
echo "\n[" . date('Y-m-d H:i:s') . "] Terminé en {$elapsed}s · $total_inv facture(s), $total_mem cotisation(s), $fail échec(s)\n";
exit(0);
