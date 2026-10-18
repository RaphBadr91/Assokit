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
 *     convocations d'AG, diffusions IA, et les e-mails de documents :
 *     factures, devis, relances de factures et de cotisations) ;
 *   - 5 000 destinataires par 24 h pour TOUTES les associations en essai
 *     réunies : les comptes d'essai sont gratuits et ouverts sans
 *     vérification, un plafond par association ne borne donc rien ;
 *   - 200 adhérents par import CSV, 300 adhérents ajoutés par 24 h.
 * Les associations abonnées ou validées gardent exactement leur
 * comportement. Toute erreur de base laisse passer (fail-open) : le but
 * est d'amortir les abus, jamais de bloquer un client payant.
 * ============================================================
 */
require_once __DIR__ . '/trial-helpers.php';

if (!defined('AK_TRIAL_MAX_RECIPIENTS_PER_SEND')) define('AK_TRIAL_MAX_RECIPIENTS_PER_SEND', 200);
if (!defined('AK_TRIAL_MAX_RECIPIENTS_PER_DAY'))  define('AK_TRIAL_MAX_RECIPIENTS_PER_DAY', 500);
if (!defined('AK_TRIAL_MAX_RECIPIENTS_PLATFORM_DAY')) define('AK_TRIAL_MAX_RECIPIENTS_PLATFORM_DAY', 5000);
if (!defined('AK_TRIAL_MAX_IMPORT_ROWS'))         define('AK_TRIAL_MAX_IMPORT_ROWS', 200);
if (!defined('AK_TRIAL_MAX_MEMBERS_PER_DAY'))     define('AK_TRIAL_MAX_MEMBERS_PER_DAY', 300);

if (!function_exists('ak_trial_msg')) {
    /**
     * Message de plafond tel qu'affiché. Dans l'application mobile (ak_billing_hidden), aucune
     * invitation à « passer à une formule Assokit » ni mot « gratuit » : ce serait un appel à
     * acheter hors de l'app (Apple 3.1.1 / 3.1.3 f, règles de paiement Google Play).
     * Sur le site, le message reste inchangé.
     */
    function ak_trial_msg(string $m): string {
        if (!function_exists('ak_billing_hidden')) @include_once __DIR__ . '/app-context.php';
        if (!function_exists('ak_billing_hidden') || !ak_billing_hidden()) return $m;
        return str_replace([
            'Réduisez le fichier, réessayez demain ou passez à une formule Assokit pour lever la limite.',
            ' ou passez à une formule Assokit pour lever la limite.',
            ', ou passez à une formule Assokit pour envoyer sans attendre.',
            ' Passez à une formule Assokit pour lever la limite.',
            'essai gratuit',
        ], [
            'Réduisez le fichier ou réessayez demain.',
            '.',
            '.',
            '',
            'essai',
        ], $m);
    }
}

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

if (!function_exists('ak_trial_recipient_sources')) {
    /**
     * Sources des envois des 24 dernières heures : [FROM, colonne org, valeur comptée, condition].
     * Une seule liste pour le compte d'une association et pour le total de la plateforme.
     * Les colonnes de date sont choisies d'après le schéma réel.
     */
    function ak_trial_recipient_sources(PDO $pdo): array {
        $day = 'NOW() - INTERVAL 1 DAY';
        $src = [
            // Diffusions e-mail + invitations aux événements
            ['communication_broadcasts', 'org_id', 'COALESCE(SUM(nb_total), 0)', "created_at > $day AND (status IS NULL OR status <> 'draft')"],
            // Diffusions rédigées avec l'IA
            ['asso_ai_diffusions', 'org_id', 'COALESCE(SUM(recipients_count), 0)', "created_at > $day"],
            // Convocations d'AG
            ['assembly_attendees a JOIN assemblies g ON g.id = a.assembly_id', 'g.org_id', 'COUNT(*)', "a.invitation_sent_at >= $day"],
            // Relances de cotisation (une ligne par e-mail)
            ['asso_membership_reminders', 'org_id', 'COUNT(*)', "sent_at > $day"],
            // Devis envoyés (dernier envoi de chaque devis ; les envois antérieurs écrasés par
            // un renvoi sont comptés à part : ak_trial_quote_resends)
            ['asso_quotes', 'org_id', 'COUNT(*)', "sent_at > $day"],
        ];
        // Factures et relances de factures : une ligne par e-mail (en file, envoyé ; pas les échecs)
        $log = function_exists('ak_db_columns') ? ak_db_columns($pdo, 'asso_invoice_emails_log') : [];
        if ($log) {
            $when = isset($log['created_at']) ? 'COALESCE(sent_at, created_at)' : 'sent_at';
            $src[] = ['asso_invoice_emails_log', 'org_id', 'COUNT(*)', "$when > $day AND (status IS NULL OR status <> 'failed')"];
        }
        return $src;
    }
}

if (!function_exists('ak_trial_recipients_24h')) {
    /**
     * Destinataires d'envois sur les dernières 24 h, tous canaux confondus.
     * Chaque source est lue à part : une table absente n'empêche pas de compter les autres.
     * null si rien n'a pu être compté.
     */
    function ak_trial_recipients_24h(PDO $pdo, int $org_id): ?int {
        $total = 0;
        $counted = false;
        foreach (ak_trial_recipient_sources($pdo) as [$from, $org_col, $val, $where]) {
            try {
                $q = $pdo->prepare("SELECT $val FROM $from WHERE $org_col = ? AND $where");
                $q->execute([$org_id]);
                $total += (int)$q->fetchColumn();
                $counted = true;
            } catch (Throwable $e) {}
        }
        return $counted ? $total + (ak_trial_quote_resends()[$org_id] ?? 0) : null;
    }
}

if (!function_exists('ak_trial_platform_recipients_24h')) {
    /**
     * Destinataires des dernières 24 h pour l'ensemble des associations EN ESSAI
     * (même définition que les plafonds : ak_org_is_trial). Lu une fois par requête,
     * puis tenu à jour par ak_trial_send_block pendant la requête (envois en boucle).
     * null si rien n'a pu être compté.
     */
    function ak_trial_platform_recipients_24h(PDO $pdo, int $add = 0): ?int {
        static $total = false;
        if ($total === false) {
            $by_org = [];
            $counted = false;
            foreach (ak_trial_recipient_sources($pdo) as [$from, $org_col, $val, $where]) {
                try {
                    foreach ($pdo->query("SELECT $org_col AS org_id, $val AS n FROM $from WHERE $where GROUP BY $org_col")->fetchAll(PDO::FETCH_ASSOC) as $r) {
                        $by_org[(int)$r['org_id']] = ($by_org[(int)$r['org_id']] ?? 0) + (int)$r['n'];
                    }
                    $counted = true;
                } catch (Throwable $e) {}
            }
            foreach (ak_trial_quote_resends() as $oid => $n) $by_org[$oid] = ($by_org[$oid] ?? 0) + $n;
            $total = null;
            if ($counted) {
                $total = 0;
                foreach ($by_org as $oid => $n) if ($n > 0 && ak_trial_limited($pdo, $oid)) $total += $n;
            }
        }
        if ($total !== null) $total += max(0, $add);
        return $total;
    }
}

if (!function_exists('ak_trial_send_block')) {
    /**
     * Contrôle un envoi de $n destinataires (envoi groupé, ou un e-mail de document : $n = 1).
     * null : l'envoi peut partir. Sinon : le message à afficher (texte brut).
     * Jamais de blocage pour une association abonnée (ak_trial_limited) ; toute erreur laisse passer.
     */
    function ak_trial_send_block(PDO $pdo, int $org_id, int $n): ?string {
        if ($n <= 0 || !ak_trial_limited($pdo, $org_id)) return null;
        $per_send = AK_TRIAL_MAX_RECIPIENTS_PER_SEND;
        $per_day  = AK_TRIAL_MAX_RECIPIENTS_PER_DAY;

        if ($n > $per_send) {
            return ak_trial_msg("Pendant l’essai gratuit, les envois sont limités à $per_send destinataires à la fois "
                 . "(celui-ci en compte $n). Passez à une formule Assokit pour lever la limite.");
        }
        $done = ak_trial_recipients_24h($pdo, $org_id);
        if ($done !== null && $done + $n > $per_day) {
            $left = max(0, $per_day - $done);
            if ($left === 0) {
                return ak_trial_msg("Pendant l’essai gratuit, les envois sont limités à $per_day destinataires par 24 h "
                     . "(diffusions, convocations, factures, devis et relances compris), et cette limite est atteinte. "
                     . "Réessayez demain ou passez à une formule Assokit pour lever la limite.");
            }
            return ak_trial_msg("Pendant l’essai gratuit, les envois sont limités à $per_day destinataires par 24 h : "
                 . "il en reste $left aujourd’hui, pour $n prévus. "
                 . "Réessayez demain ou passez à une formule Assokit pour lever la limite.");
        }
        // Budget commun à tous les comptes d'essai
        $all = ak_trial_platform_recipients_24h($pdo);
        if ($all !== null && $all + $n > AK_TRIAL_MAX_RECIPIENTS_PLATFORM_DAY) {
            error_log('[trial-limits] budget plateforme des essais atteint (' . $all . ' destinataires en 24 h) — org ' . $org_id);
            return ak_trial_msg("Les envois des comptes en essai gratuit sont momentanément suspendus : la limite quotidienne "
                 . "commune à tous les essais est atteinte. Réessayez plus tard, ou passez à une formule Assokit pour envoyer sans attendre.");
        }
        ak_trial_platform_recipients_24h($pdo, $n);   // l'envoi accepté compte pour la suite de la requête
        return null;
    }
}

if (!function_exists('ak_trial_quote_resends')) {
    /**
     * Envois de devis écrasés par un renvoi, sur les dernières 24 h : [org_id => nombre].
     * asso_quotes.sent_at ne garde que le dernier envoi d'un devis ; un renvoi dans les 24 h
     * ferait donc sortir l'envoi précédent du compte. Chaque envoi écrasé est noté ici, à sa
     * propre date (il sort du compte au bon moment), une seule fois (même devis, même date).
     * Fichier hors préfixe akrl_ : le ménage horaire du limiteur l'effacerait.
     * $note = [org_id, quote_id, horodatage] à ajouter. Toute erreur de fichier laisse passer.
     */
    function ak_trial_quote_resends(?array $note = null): array {
        $fp = @fopen(rtrim(sys_get_temp_dir(), '/') . '/ak_trial_quote_resends', 'c+');
        if ($fp === false) return [];
        $by_org = [];
        if (flock($fp, $note ? LOCK_EX : LOCK_SH)) {
            $since = time() - 86400;
            $sends = [];
            foreach (explode("\n", (string)stream_get_contents($fp)) as $l) {
                [$ts, $org, $quote] = array_map('intval', explode(' ', $l)) + [0, 0, 0];
                if ($ts > $since && $org > 0) $sends["$ts $org $quote"] = $org;
            }
            if ($note) {
                [$org, $quote, $ts] = array_map('intval', $note);
                if ($ts > $since && $org > 0) $sends["$ts $org $quote"] = $org;
                ftruncate($fp, 0);
                rewind($fp);
                fwrite($fp, implode("\n", array_keys($sends)));
            }
            foreach ($sends as $org) $by_org[$org] = ($by_org[$org] ?? 0) + 1;
            flock($fp, LOCK_UN);
        }
        fclose($fp);
        return $by_org;
    }
}

if (!function_exists('ak_trial_quote_send_block')) {
    /**
     * Envoi (ou renvoi) d'un devis : pendant l'essai, compte pour 1 dans le plafond
     * (ak_trial_send_block), sans limite propre aux renvois. Envoi accepté alors que le
     * devis était déjà parti dans les 24 h : l'envoi précédent est noté pour rester compté.
     * null : l'envoi peut partir. Sinon : le message à afficher.
     */
    function ak_trial_quote_send_block(PDO $pdo, int $org_id, int $quote_id): ?string {
        if ($block = ak_trial_send_block($pdo, $org_id, 1)) return $block;
        if (!ak_trial_limited($pdo, $org_id)) return null;
        try {
            $q = $pdo->prepare("SELECT UNIX_TIMESTAMP(sent_at) FROM asso_quotes WHERE id = ? AND org_id = ? AND sent_at > NOW() - INTERVAL 1 DAY");
            $q->execute([$quote_id, $org_id]);
            if ($ts = (int)$q->fetchColumn()) ak_trial_quote_resends([$org_id, $quote_id, $ts]);
        } catch (Throwable $e) {}
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
            return ak_trial_msg("Pendant l’essai gratuit, un import est limité à $per_import adhérents à la fois "
                 . "(ce fichier en contient $n à créer). Découpez-le en plusieurs fichiers "
                 . "ou passez à une formule Assokit pour lever la limite.");
        }
        $done = ak_trial_members_24h($pdo, $org_id);
        if ($done !== null && $done + $n > $per_day) {
            $left = max(0, $per_day - $done);
            if ($left === 0) {
                return ak_trial_msg("Pendant l’essai gratuit, vous pouvez ajouter jusqu’à $per_day adhérents par 24 h, "
                     . "et cette limite est atteinte. Réessayez demain ou passez à une formule Assokit pour lever la limite.");
            }
            // $n > $left >= 1 : n'arrive qu'avec un import (ajout unitaire : $n = 1).
            return ak_trial_msg("Pendant l’essai gratuit, vous pouvez ajouter jusqu’à $per_day adhérents par 24 h : "
                 . "il en reste $left aujourd’hui, pour $n à créer. Réduisez le fichier, réessayez demain "
                 . "ou passez à une formule Assokit pour lever la limite.");
        }
        return null;
    }
}
