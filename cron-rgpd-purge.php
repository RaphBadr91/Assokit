<?php
/**
 * cron-rgpd-purge.php — Purge/anonymisation selon la politique de conservation RGPD.
 *
 * SÉCURITÉ : CLI uniquement. DRY-RUN par défaut (n'efface RIEN) : il affiche seulement
 * ce qu'il ferait. Pour activer les suppressions réelles, définir APRÈS REVUE JURIDIQUE :
 *     define('AK_RGPD_PURGE_LIVE', true);   // dans config.php
 *
 * À planifier en CRON (ex. 1x/jour) UNE FOIS validé. Ne touche jamais aux pièces
 * comptables (factures : conservation légale 10 ans).
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }
require_once __DIR__ . '/config.php';

$LIVE = defined('AK_RGPD_PURGE_LIVE') && AK_RGPD_PURGE_LIVE;
echo ($LIVE ? '[LIVE] ' : '[DRY-RUN] ') . "Purge RGPD — " . date('Y-m-d H:i') . "\n";
if (!$LIVE) echo "  (Aucune suppression : mode simulation. Voir docs/RGPD-CONFORMITE.md)\n";

function tbl_exists(PDO $pdo, string $t): bool {
    try { $q = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?"); $q->execute([$t]); return (int)$q->fetchColumn() > 0; }
    catch (Throwable $e) { return false; }
}

/** Applique une règle : compte les lignes concernées, puis supprime si LIVE. */
function purge(PDO $pdo, bool $live, string $label, string $table, string $where, array $args = []): void {
    if (!tbl_exists($pdo, $table)) { echo "  · $label : table absente, ignoré\n"; return; }
    try {
        $c = $pdo->prepare("SELECT COUNT(*) FROM $table WHERE $where");
        $c->execute($args);
        $n = (int) $c->fetchColumn();
        if ($n === 0) { echo "  · $label : rien à purger\n"; return; }
        if ($live) {
            $d = $pdo->prepare("DELETE FROM $table WHERE $where");
            $d->execute($args);
            echo "  · $label : $n ligne(s) SUPPRIMÉE(S)\n";
        } else {
            echo "  · $label : $n ligne(s) seraient supprimées\n";
        }
    } catch (Throwable $e) { echo "  · $label : erreur — " . $e->getMessage() . "\n"; }
}

// --- Règles de conservation (voir docs/RGPD-CONFORMITE.md) ---

// 1) Événements de tracking prospection > 24 mois
purge($pdo, $LIVE, 'Événements prospection > 24 mois', 'asso_prospect_events',
      'created_at < DATE_SUB(NOW(), INTERVAL 24 MONTH)');

// 2) Prospects jamais convertis, sans interaction depuis > 3 ans
//    (on garde les 'unsubscribed' : ils doivent rester en exclusion pour ne plus être recontactés)
purge($pdo, $LIVE, 'Prospects inactifs > 3 ans', 'asso_prospects',
      "status IN ('new','contacted','bounced') AND created_at < DATE_SUB(NOW(), INTERVAL 3 YEAR) "
    . "AND (last_sent_at IS NULL OR last_sent_at < DATE_SUB(NOW(), INTERVAL 3 YEAR))");

// 3) Journaux d'activité > 12 mois (si la table existe)
purge($pdo, $LIVE, 'Journaux activité > 12 mois', 'assokit_activity_log',
      'created_at < DATE_SUB(NOW(), INTERVAL 12 MONTH)');

// NB : on ne purge JAMAIS asso_invoices / subscription_invoices ici
// (conservation légale comptable = 10 ans).

echo ($LIVE ? '[LIVE] ' : '[DRY-RUN] ') . "Terminé.\n";
