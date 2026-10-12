<?php
/**
 * AssoKit — CRON Relances intelligentes (auto)
 * À LANCER TOUTES LES 10 MINUTES (et non plus 1x par jour) : chaque
 * passage examine un lot d'associations, en commençant par celles vues
 * il y a le plus longtemps.
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
require_once __DIR__ . '/asso-invoice-helpers.php';
require_once __DIR__ . '/relances-engine.php';

// CLI true si SAPI cli OU exécution hors contexte web (binaire CGI lancé en cron :
// aucune requête HTTP -> REQUEST_METHOD absent).
$is_cli = (PHP_SAPI === 'cli') || !isset($_SERVER['REQUEST_METHOD']);
$has_key = is_string($_GET['key'] ?? null) && defined('CRON_SECRET') && (string)CRON_SECRET !== '' && hash_equals((string)CRON_SECRET, $_GET['key']);
if (!$is_cli && !$has_key) { http_response_code(403); die('Forbidden'); }

require_once __DIR__ . '/cron-lots.php';
if (!ak_lot_demarrer($pdo, 'relances')) exit(0);

$orgs = [];
try {
    // Associations supprimées, suspendues ou résiliées : aucun envoi automatique
    // (ni aux clients, ni aux adhérents), même si l'auto-relance était activée.
    $orgs = $pdo->query("SELECT p.org_id, p.auto_invoices, p.auto_memberships, p.max_stage
                         FROM org_relance_prefs p
                         JOIN organizations o ON o.id = p.org_id
                         WHERE (p.auto_invoices = 1 OR p.auto_memberships = 1)
                           AND " . ak_asso_org_active_sql('o'))->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { fwrite(STDERR, $e->getMessage()."\n"); }
echo "→ " . count($orgs) . " org(s) en auto-relance\n";

// Ce qui est fait est noté sur la facture, pas sur l'association : on ne
// peut donc pas demander « celles qu'il reste à voir ». On prend celles
// examinées il y a le plus longtemps, pour que le tour avance.
$parOrg = [];
foreach ($orgs as $o) $parOrg[(int) $o['org_id']] = $o;
$aVoir = ak_tour_prochains($pdo, 'relances', array_keys($parOrg), ak_lot_place());
echo "→ " . count($aVoir) . " examinée(s) ce passage\n";

$total_inv = 0; $total_mem = 0; $fail = 0;

foreach ($aVoir as $org_id) {
    if (!ak_lot_encore()) break;
    ak_lot_fait();
    // Noté dès maintenant : même si cette association n'a rien à
    // relancer, elle a été examinée et doit laisser passer les autres.
    ak_tour_vu($pdo, 'relances', $org_id);

    $o = $parOrg[$org_id];
    $maxStage = max(1, min(3, (int)$o['max_stage']));

    if (!empty($o['auto_invoices'])) {
        try {
            // Factures dont l'admin a coupé les relances (« Stop » sur le tableau de bord).
            $stopped = [];
            $sd = $pdo->prepare("SELECT id FROM asso_invoices WHERE org_id = ? AND dunning_enabled = 0 AND status IN ('pending','overdue')");
            $sd->execute([$org_id]);
            foreach ($sd->fetchAll(PDO::FETCH_COLUMN) as $sid) $stopped[(int)$sid] = true;

            foreach (ak_rel_invoice_targets($pdo, $org_id) as $t) {
                if (!$t['due_now'] || $t['stage'] > $maxStage || isset($stopped[(int)$t['id']])) continue;
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

echo "\n$total_inv facture(s), $total_mem cotisation(s), $fail échec(s)\n";
ak_lot_terminer($pdo, count($aVoir) < count($parOrg));
exit(0);
