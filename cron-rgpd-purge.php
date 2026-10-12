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

// php-cgi (/usr/bin/php sur O2Switch) : PHP_SAPI vaut « cgi-fcgi », sans REQUEST_METHOD ; c'est aussi la ligne de commande
if (PHP_SAPI !== 'cli' && isset($_SERVER['REQUEST_METHOD'])) { http_response_code(403); exit("CLI only\n"); }
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

/** Règle 4 : compte, puis anonymise si LIVE (colonnes absentes en prod ignorées). */
function anonymise_deleted_users(PDO $pdo, bool $live): void {
    $label = 'Comptes supprimés > 30 jours à anonymiser';
    require_once __DIR__ . '/trial-helpers.php';   // ak_db_columns()
    $cols = ak_db_columns($pdo, 'users');
    if (!isset($cols['deleted_at'], $cols['email'], $cols['first_name'], $cols['last_name'])) {
        echo "  · $label : colonnes absentes, ignoré\n";
        return;
    }
    $where = "deleted_at IS NOT NULL AND deleted_at >= '2000-01-01'"
           . " AND deleted_at < DATE_SUB(NOW(), INTERVAL 30 DAY)"
           . " AND (email IS NULL OR email NOT LIKE '%@anonyme.assokit.local')";
    foreach (['is_founder', 'is_super_admin', 'is_platform_admin'] as $c) {
        if (isset($cols[$c])) $where .= " AND COALESCE($c, 0) = 0";
    }

    $set = ["email = CONCAT('deleted_', id, '@anonyme.assokit.local')",
            "first_name = 'Compte'", "last_name = 'supprimé'"];
    if (isset($cols['is_active']))     $set[] = 'is_active = 0';
    if (isset($cols['password_hash'])) $set[] = "password_hash = ''";
    if (isset($cols['totp_enabled']))  $set[] = 'totp_enabled = 0';
    // Données personnelles et secrets restants : vidés quand la colonne existe et accepte NULL
    foreach (['phone', 'mobile', 'city', 'address', 'postal_code', 'zip_code', 'birth_date', 'birthdate',
              'notes_admin', 'totp_secret', 'totp_backup_codes', 'ics_token'] as $c) {
        if (isset($cols[$c]) && $cols[$c]['null']) $set[] = "$c = NULL";
    }

    try {
        $n = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE $where")->fetchColumn();
        if ($n === 0) { echo "  · $label : rien à anonymiser\n"; return; }
        if ($live) {
            $done = $pdo->exec("UPDATE users SET " . implode(', ', $set) . " WHERE $where");
            echo "  · $label : " . (int) $done . " compte(s) ANONYMISÉ(S)\n";
        } else {
            echo "  · $label : $n compte(s) seraient anonymisés\n";
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

// 3 bis) Carnet des envois des crons > 6 mois.
// Il ne sert qu'à ne pas envoyer deux fois le même message ; passé
// l'échéance concernée, la ligne ne répond plus à aucune question. Sans
// cette purge, la table grossirait d'une ligne par rappel et par
// association, pour toujours.
purge($pdo, $LIVE, 'Carnet d’envois des crons > 6 mois', 'cron_envois',
      'sent_at < DATE_SUB(NOW(), INTERVAL 6 MONTH)');

// 4) Comptes supprimés depuis plus de 30 jours (sortis de la corbeille, plus
//    restaurables) : ANONYMISÉS, jamais effacés — la ligne reste référencée par les
//    paiements, projets et messages. Même anonymisation que la suppression de son
//    propre compte (rgpd-supprimer-compte.php). Fondateur et administrateurs de la
//    plateforme exclus. D'abord leurs jetons (notifications push, liens de mot de passe).
$users_deleted_30d = "SELECT id FROM users WHERE deleted_at IS NOT NULL AND deleted_at >= '2000-01-01'"
                   . " AND deleted_at < DATE_SUB(NOW(), INTERVAL 30 DAY)";
purge($pdo, $LIVE, 'Jetons push des comptes supprimés > 30 jours', 'asso_push_tokens',
      "user_id IN ($users_deleted_30d)");
purge($pdo, $LIVE, 'Liens de mot de passe non utilisés des comptes supprimés > 30 jours', 'user_password_tokens',
      "used_at IS NULL AND user_id IN ($users_deleted_30d)");
anonymise_deleted_users($pdo, $LIVE);

// NB : on ne purge JAMAIS asso_invoices / subscription_invoices ici
// (conservation légale comptable = 10 ans).

echo ($LIVE ? '[LIVE] ' : '[DRY-RUN] ') . "Terminé.\n";
