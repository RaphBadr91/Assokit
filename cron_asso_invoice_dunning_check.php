<?php
/**
 * cron_asso_invoice_dunning_check.php
 * Tourne 1x/jour à 8h.
 * Prépare, pour chaque facture échue et impayée (relances activées), la relance
 * à faire valider par l'admin sur son tableau de bord (widget « Relances en attente
 * de votre validation ») ; cron_asso_invoice_dunning_send.php l'envoie une fois validée.
 *
 * Même cadence que /relances (relances-engine.php), calculée sur les jours de
 * RETARD (depuis l'échéance) et sur les relances déjà parties, tous circuits
 * confondus (journal asso_invoice_emails_log) :
 *   stade 1 « rappel courtois » dès J+1, stade 2 « relance ferme » à partir de J+15,
 *   stade 3 « mise en demeure » à partir de J+45, toujours dans l'ordre 1 → 2 → 3,
 *   et au moins 7 jours entre deux relances.
 * Une seule demande en attente par facture : celles qui ne correspondent plus au
 * stade du moment (déjà envoyé entre-temps, ou prématuré) sont écartées.
 *
 * Appel (tâche cron cPanel), au choix :
 *   /usr/local/bin/php /home/pura7044/public_html/cron_asso_invoice_dunning_check.php
 *   curl -s "https://assokit.fr/cron_asso_invoice_dunning_check?token=XXX"
 */

require_once __DIR__ . '/config.php';

// CLI (binaire php lancé par la tâche cron, aucune requête HTTP) : pas de jeton.
// Appel HTTP : jeton CRON_TOKEN obligatoire.
$is_cli = (PHP_SAPI === 'cli') || !isset($_SERVER['REQUEST_METHOD']);
if (!$is_cli) {
    $token = is_string($_GET['token'] ?? null) ? $_GET['token'] : '';
    $expected_token = defined('CRON_TOKEN') ? (string)CRON_TOKEN : '';
    if ($expected_token === '') { http_response_code(500); echo "CRON_TOKEN non configure\n"; exit; }
    if (!hash_equals($expected_token, $token)) {
        http_response_code(403);
        die('Forbidden');
    }
}

require_once __DIR__ . '/asso-invoice-helpers.php';
require_once __DIR__ . '/relances-engine.php';

if (!$is_cli) header('Content-Type: text/plain; charset=UTF-8');

echo "=== CRON ASSO INVOICE DUNNING CHECK ===\n";
echo "Date : " . date('Y-m-d H:i:s') . "\n\n";

$created = 0;
$skipped = 0;
$dismissed = 0;

try {
    // 1. Demandes devenues sans objet : facture pas (ou plus) échue — échéance repoussée,
    //    ou demandes créées autrefois depuis la date d'émission, avant l'échéance.
    $dismissed += $pdo->exec("
        UPDATE asso_invoice_notifications n
        JOIN asso_invoices i ON i.id = n.invoice_id
        SET n.dismissed_at = NOW()
        WHERE n.responded_at IS NULL AND n.dismissed_at IS NULL
          AND (i.due_at IS NULL OR i.due_at >= CURDATE())
    ") ?: 0;

    // 2. Associations qui ont des factures échues, impayées, relances activées
    //    (ni supprimées, ni suspendues, ni résiliées).
    $orgs = [];   // org_id => [invoice_id => true]
    $rows = $pdo->query("
        SELECT i.org_id, i.id
        FROM asso_invoices i
        JOIN organizations o ON o.id = i.org_id
        WHERE i.status IN ('pending', 'overdue')
          AND i.dunning_enabled = 1
          AND i.paid_at IS NULL
          AND i.due_at IS NOT NULL AND i.due_at < CURDATE()
          AND " . ak_asso_org_active_sql('o') . "
    ")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) $orgs[(int)$r['org_id']][(int)$r['id']] = true;
    echo "Factures à analyser : " . count($rows) . " (" . count($orgs) . " association(s))\n\n";

    $notifs = $pdo->prepare("
        SELECT id, level, responded_at, response, dunning_email_sent_at
        FROM asso_invoice_notifications
        WHERE invoice_id = :inv AND dismissed_at IS NULL
    ");
    $dismiss = $pdo->prepare("UPDATE asso_invoice_notifications SET dismissed_at = NOW() WHERE id = :id AND responded_at IS NULL");
    $ins = $pdo->prepare("
        INSERT INTO asso_invoice_notifications
        (invoice_id, org_id, level, scheduled_at, shown_at)
        VALUES (:inv, :org, :lvl, :sch, NOW())
    ");

    foreach ($orgs as $org_id => $enabled) {
        // Stade et échéance décidés par le moteur commun de /relances.
        foreach (ak_rel_invoice_targets($pdo, $org_id) as $t) {
            $iid = (int)$t['id'];
            if (!isset($enabled[$iid])) continue;   // relances désactivées (« Stop ») ou facture hors périmètre
            if (!empty($t['sepa_pending'])) continue;   // payée par prélèvement SEPA, en attente de la banque : rien à demander

            $max_sent = (int)($t['max_sent'] ?? 0);
            $next     = $max_sent + 1;
            // Seul stade qu'on peut demander aujourd'hui (0 = aucun) : le suivant, s'il est justifié par le retard.
            $allowed  = ($next <= 3 && $next <= ak_rel_target_stage_by_days((int)$t['days_overdue'])) ? $next : 0;

            $notifs->execute([':inv' => $iid]);
            $pending_same = false;   // demande au bon stade déjà en attente (ou validée, envoi en cours)
            foreach ($notifs->fetchAll(PDO::FETCH_ASSOC) as $n) {
                $lvl = (int)$n['level'];
                if (empty($n['responded_at'])) {
                    if ($allowed > 0 && $lvl === $allowed) { $pending_same = true; continue; }
                    // Déjà envoyé par un autre circuit, ou prématuré : on retire la demande.
                    $dismiss->execute([':id' => (int)$n['id']]);
                    $dismissed++;
                } elseif ($n['response'] === 'not_paid' && empty($n['dunning_email_sent_at']) && $lvl > $max_sent) {
                    $pending_same = true;   // validée, cron_asso_invoice_dunning_send s'en charge
                }
            }

            if (!$t['due_now'] || $allowed === 0 || (int)$t['stage'] !== $allowed) continue;
            if ($pending_same) { $skipped++; continue; }

            $ins->execute([
                ':inv' => $iid,
                ':org' => $org_id,
                ':lvl' => $allowed,
                ':sch' => date('Y-m-d H:i:s'),
            ]);
            $created++;
            $label = ak_rel_stage_meta($allowed)[0];
            echo "  ✓ Relance {$allowed} ({$label}) à valider pour {$t['number']} — {$t['days_overdue']} j de retard\n";
        }
    }

    echo "\n=== RÉCAP ===\n";
    echo "Notifications créées : $created\n";
    echo "Skipped (déjà en attente) : $skipped\n";
    echo "Demandes écartées (sans objet) : $dismissed\n";

} catch (Throwable $e) {
    echo "ERREUR : " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
    error_log('[CRON DUNNING CHECK] ' . $e->getMessage());
}

echo "\n=== FIN ===\n";
