<?php
/**
 * ============================================================
 * ASSOKIT — Plafonds d'envoi pendant l'essai gratuit
 * ============================================================
 * Avec l'inscription publique, n'importe qui obtient en une minute une
 * association qui fonctionne. Sans plafond, un compte d'essai pourrait
 * importer des milliers d'adresses puis écrire à tout le monde depuis le
 * domaine Assokit (relais de spam, réputation d'envoi grillée).
 *
 * Ces plafonds ne visent QUE les associations en essai (ak_org_is_trial) :
 *   - 200 destinataires par envoi groupé, 500 par 24 h et par association,
 *     tous canaux confondus (diffusions, invitations aux événements,
 *     convocations d'AG, diffusions IA) ;
 *   - 200 adhérents par import CSV, 300 adhérents ajoutés par 24 h.
 * Les associations abonnées ou validées gardent exactement leur
 * comportement. Toute erreur de base laisse passer (fail-open) : le but
 * est d'amortir les abus, jamais de bloquer un client payant.
 * ============================================================
 */
require_once __DIR__ . '/trial-helpers.php';

if (!defined('AK_TRIAL_MAX_RECIPIENTS_PER_SEND')) define('AK_TRIAL_MAX_RECIPIENTS_PER_SEND', 200);
if (!defined('AK_TRIAL_MAX_RECIPIENTS_PER_DAY'))  define('AK_TRIAL_MAX_RECIPIENTS_PER_DAY', 500);
if (!defined('AK_TRIAL_MAX_IMPORT_ROWS'))         define('AK_TRIAL_MAX_IMPORT_ROWS', 200);
if (!defined('AK_TRIAL_MAX_MEMBERS_PER_DAY'))     define('AK_TRIAL_MAX_MEMBERS_PER_DAY', 300);

if (!function_exists('ak_trial_limited')) {
    /** Les plafonds d'essai s'appliquent-ils à cette association ? false en cas de doute. */
    function ak_trial_limited(PDO $pdo, int $org_id): bool {
        try {
            return $org_id > 0 && function_exists('ak_org_is_trial') && ak_org_is_trial($pdo, $org_id);
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('ak_trial_recipients_24h')) {
    /**
     * Destinataires d'envois groupés sur les dernières 24 h, tous canaux confondus.
     * Chaque source est lue à part : une table absente n'empêche pas de compter les autres.
     * null si rien n'a pu être compté.
     */
    function ak_trial_recipients_24h(PDO $pdo, int $org_id): ?int {
        $queries = [
            // Diffusions e-mail + invitations aux événements
            "SELECT COALESCE(SUM(nb_total), 0) FROM communication_broadcasts
              WHERE org_id = ? AND created_at > NOW() - INTERVAL 1 DAY AND (status IS NULL OR status <> 'draft')",
            // Diffusions rédigées avec l'IA
            "SELECT COALESCE(SUM(recipients_count), 0) FROM asso_ai_diffusions
              WHERE org_id = ? AND created_at > NOW() - INTERVAL 1 DAY",
            // Convocations d'AG
            "SELECT COUNT(*) FROM assembly_attendees a JOIN assemblies g ON g.id = a.assembly_id
              WHERE g.org_id = ? AND a.invitation_sent_at >= NOW() - INTERVAL 1 DAY",
        ];
        $total = 0;
        $counted = false;
        foreach ($queries as $sql) {
            try {
                $q = $pdo->prepare($sql);
                $q->execute([$org_id]);
                $total += (int)$q->fetchColumn();
                $counted = true;
            } catch (Throwable $e) {}
        }
        return $counted ? $total : null;
    }
}

if (!function_exists('ak_trial_send_block')) {
    /**
     * Contrôle un envoi groupé de $n destinataires.
     * null : l'envoi peut partir. Sinon : le message à afficher (texte brut).
     */
    function ak_trial_send_block(PDO $pdo, int $org_id, int $n): ?string {
        if ($n <= 0 || !ak_trial_limited($pdo, $org_id)) return null;
        $per_send = AK_TRIAL_MAX_RECIPIENTS_PER_SEND;
        $per_day  = AK_TRIAL_MAX_RECIPIENTS_PER_DAY;

        if ($n > $per_send) {
            return "Pendant l’essai gratuit, les envois sont limités à $per_send destinataires à la fois "
                 . "(celui-ci en compte $n). Passez à une formule Assokit pour lever la limite.";
        }
        $done = ak_trial_recipients_24h($pdo, $org_id);
        if ($done !== null && $done + $n > $per_day) {
            $left = max(0, $per_day - $done);
            if ($left === 0) {
                return "Pendant l’essai gratuit, les envois sont limités à $per_day destinataires par 24 h, "
                     . "et cette limite est atteinte. Réessayez demain ou passez à une formule Assokit pour lever la limite.";
            }
            return "Pendant l’essai gratuit, les envois sont limités à $per_day destinataires par 24 h : "
                 . "il en reste $left aujourd’hui, pour $n prévus. "
                 . "Réessayez demain ou passez à une formule Assokit pour lever la limite.";
        }
        return null;
    }
}

if (!function_exists('ak_trial_members_24h')) {
    /** Comptes créés pour l'association sur les dernières 24 h (null si users.created_at manque ou en cas d'erreur). */
    function ak_trial_members_24h(PDO $pdo, int $org_id): ?int {
        try {
            $cols = function_exists('ak_db_columns') ? ak_db_columns($pdo, 'users') : [];
            if ($cols && !isset($cols['created_at'])) return null;
            $q = $pdo->prepare("SELECT COUNT(*) FROM users WHERE org_id = ? AND created_at > NOW() - INTERVAL 1 DAY");
            $q->execute([$org_id]);
            return (int)$q->fetchColumn();
        } catch (Throwable $e) {
            return null;
        }
    }
}

if (!function_exists('ak_trial_members_block')) {
    /**
     * Contrôle l'ajout de $n adhérents ($import : import CSV, plafonné en plus par fichier).
     * null : l'ajout est possible. Sinon : le message à afficher (texte brut).
     */
    function ak_trial_members_block(PDO $pdo, int $org_id, int $n, bool $import = false): ?string {
        if ($n <= 0 || !ak_trial_limited($pdo, $org_id)) return null;
        $per_import = AK_TRIAL_MAX_IMPORT_ROWS;
        $per_day    = AK_TRIAL_MAX_MEMBERS_PER_DAY;

        if ($import && $n > $per_import) {
            return "Pendant l’essai gratuit, un import est limité à $per_import adhérents à la fois "
                 . "(ce fichier en contient $n à créer). Découpez-le en plusieurs fichiers "
                 . "ou passez à une formule Assokit pour lever la limite.";
        }
        $done = ak_trial_members_24h($pdo, $org_id);
        if ($done !== null && $done + $n > $per_day) {
            $left = max(0, $per_day - $done);
            if ($left === 0) {
                return "Pendant l’essai gratuit, vous pouvez ajouter jusqu’à $per_day adhérents par 24 h, "
                     . "et cette limite est atteinte. Réessayez demain ou passez à une formule Assokit pour lever la limite.";
            }
            // $n > $left >= 1 : n'arrive qu'avec un import (ajout unitaire : $n = 1).
            return "Pendant l’essai gratuit, vous pouvez ajouter jusqu’à $per_day adhérents par 24 h : "
                 . "il en reste $left aujourd’hui, pour $n à créer. Réduisez le fichier, réessayez demain "
                 . "ou passez à une formule Assokit pour lever la limite.";
        }
        return null;
    }
}
