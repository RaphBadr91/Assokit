<?php
/**
 * api/_app-contact-token.php — Jetons + stockage pour le double-sens des demandes de contact.
 * Génère une adresse Reply-To unique par prospect et enregistre les réponses entrantes.
 * App-only. NE MODIFIE PAS le site.
 *
 * Adresse de réponse (compatible O2switch, sous-adressage "+") :
 *   reponses+c{contact_id}.{token}@assokit.fr
 *   → délivrée à la boîte "reponses@assokit.fr", puis un filtre cPanel "pipe"
 *     envoie l'email au script api/pipe-inbound-contact.php.
 */

require_once __DIR__ . '/../trial-helpers.php';   // ak_db_columns / ak_db_accepts (schéma variable selon l'installation)

if (!defined('AK_CONTACT_REPLY_MAILBOX')) define('AK_CONTACT_REPLY_MAILBOX', 'reponse'); // partie locale de la boîte (reponse@assokit.fr)
if (!defined('AK_CONTACT_REPLY_DOMAIN'))  define('AK_CONTACT_REPLY_DOMAIN', 'assokit.fr'); // domaine (mail déjà reçu ici)

if (!function_exists('ak_contact_secret')) {
    function ak_contact_secret(): string {
        if (defined('AK_CONTACT_SECRET') && AK_CONTACT_SECRET) return (string) AK_CONTACT_SECRET;
        if (defined('RESEND_API_KEY') && RESEND_API_KEY) return hash('sha256', 'akctc|' . RESEND_API_KEY);
        // Fail-closed : sans secret configuré (AK_CONTACT_SECRET ou RESEND_API_KEY), aucun jeton n'est accepté.
        error_log('[ak_contact_secret] Aucun secret configuré (AK_CONTACT_SECRET / RESEND_API_KEY) : double-sens contact désactivé.');
        return '';
    }
}

if (!function_exists('ak_contact_token')) {
    function ak_contact_token(int $contact_id, string $email): string {
        return substr(hash_hmac('sha256', $contact_id . '|' . strtolower(trim($email)), ak_contact_secret()), 0, 16);
    }
}

if (!function_exists('ak_contact_reply_address')) {
    function ak_contact_reply_address(int $contact_id, string $email): string {
        // Sous-adressage : reponses+c{id}.{token}@domaine → boîte "reponses"
        return AK_CONTACT_REPLY_MAILBOX . '+c' . $contact_id . '.' . ak_contact_token($contact_id, $email) . '@' . AK_CONTACT_REPLY_DOMAIN;
    }
}

if (!function_exists('ak_contact_parse_recipient')) {
    /** Extrait [id, token] depuis n'importe quelle adresse contenant c{id}.{token} ; null si absent. */
    function ak_contact_parse_recipient(string $addr): ?array {
        if (preg_match('/c(\d+)\.([a-f0-9]{8,32})/i', $addr, $m)) {
            return ['id' => (int) $m[1], 'token' => strtolower($m[2])];
        }
        return null;
    }
}

if (!function_exists('ak_contact_thread_ensure')) {
    function ak_contact_thread_ensure(PDO $pdo): void {
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS asso_contact_thread (
                id INT AUTO_INCREMENT PRIMARY KEY,
                contact_id INT NOT NULL,
                direction VARCHAR(8) NOT NULL,
                body MEDIUMTEXT,
                from_email VARCHAR(255) DEFAULT NULL,
                read_by_founder TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL,
                INDEX idx_contact (contact_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (Throwable $e) {}
    }
}

if (!function_exists('ak_contact_has_status')) {
    /**
     * La colonne asso_contact_messages.status (new / read / replied) existe-t-elle ?
     * Aucun INSERT du site ne la renseigne : migrations/2026-10-11-L13-api-fondateur.sql l'ajoute
     * avec 'new' par défaut. Tant qu'elle manque, l'état se déduit du fil (ak_contact_state_sql).
     */
    function ak_contact_has_status(PDO $pdo, ?string $value = null): bool {
        if ($value !== null) return ak_db_accepts($pdo, 'asso_contact_messages', 'status', $value);
        return isset(ak_db_columns($pdo, 'asso_contact_messages')['status']);
    }
}

if (!function_exists('ak_contact_set_status')) {
    /** Change l'état d'une demande si la colonne existe et accepte la valeur (sinon : sans effet). */
    function ak_contact_set_status(PDO $pdo, int $contact_id, string $status, ?string $only_from = null): void {
        if (!ak_contact_has_status($pdo, $status)) return;
        try {
            $sql = "UPDATE asso_contact_messages SET status = ? WHERE id = ?" . ($only_from !== null ? " AND COALESCE(status, 'new') = ?" : "");
            $pdo->prepare($sql)->execute($only_from !== null ? [$status, $contact_id, $only_from] : [$status, $contact_id]);
        } catch (Throwable $e) {
            error_log('[ak_contact_set_status] ' . $e->getMessage());
        }
    }
}

if (!function_exists('ak_contact_state_sql')) {
    /**
     * Expressions SQL (0/1) « non lue » et « répondue » d'une demande ($a = alias de asso_contact_messages),
     * partagées par le compteur du cockpit et l'écran Contacts pour afficher le même chiffre.
     *   - colonne status : non lue = ni lue, ni répondue, ni close (le pipe la repasse à 'new'
     *     à chaque réponse du prospect) ;
     *   - sans la colonne : non lue = jamais répondue, ou réponse du prospect pas encore ouverte ;
     *     répondue = le dernier message du fil est la réponse envoyée.
     */
    function ak_contact_state_sql(PDO $pdo, string $a = 'm'): array {
        if (ak_contact_has_status($pdo)) {
            return [
                'unread'  => "(COALESCE($a.status, 'new') NOT IN ('read','replied','closed','archived'))",
                'replied' => "(COALESCE($a.status, 'new') = 'replied')",
            ];
        }
        $has_out = "EXISTS (SELECT 1 FROM asso_contact_thread t1 WHERE t1.contact_id = $a.id AND t1.direction = 'out')";
        $new_in  = "EXISTS (SELECT 1 FROM asso_contact_thread t2 WHERE t2.contact_id = $a.id AND t2.direction = 'in' AND t2.read_by_founder = 0)";
        $last    = "(SELECT t3.direction FROM asso_contact_thread t3 WHERE t3.contact_id = $a.id ORDER BY t3.created_at DESC, t3.id DESC LIMIT 1)";
        return [
            'unread'  => "(NOT $has_out OR $new_in)",
            'replied' => "(COALESCE($last, '') = 'out' AND NOT $new_in)",
        ];
    }
}

if (!function_exists('ak_contact_unread_count')) {
    /** Nombre de demandes de contact non lues (cockpit et écran Contacts). */
    function ak_contact_unread_count(PDO $pdo): int {
        if (!ak_contact_has_status($pdo)) ak_contact_thread_ensure($pdo);
        $s = ak_contact_state_sql($pdo, 'm');
        try {
            return (int) $pdo->query("SELECT COUNT(*) FROM asso_contact_messages m WHERE {$s['unread']}")->fetchColumn();
        } catch (Throwable $e) {
            return 0;
        }
    }
}

if (!function_exists('ak_contact_email_address')) {
    /** Adresse seule d'un en-tête From (« Hélène D. <h@x.fr> », « h@x.fr (Hélène) ») ; '' si invalide. */
    function ak_contact_email_address(string $from): string {
        $from = trim($from);
        if (preg_match('/<\s*([^<>\s]+@[^<>\s]+)\s*>/', $from, $m)) $from = $m[1];
        elseif (preg_match('/[^\s<>"(),;:]+@[^\s<>"(),;:]+/', $from, $m)) $from = $m[0];
        return filter_var($from, FILTER_VALIDATE_EMAIL) ? $from : '';
    }
}

if (!function_exists('ak_contact_clean_reply')) {
    /** Retire l'historique cité d'une réponse email (best-effort). */
    function ak_contact_clean_reply(string $body): string {
        $body = str_replace("\r\n", "\n", $body);
        // (« ____ » : séparateur placé par Outlook au-dessus de l'historique cité)
        $parts = preg_split('/^\s*(Le .+ a écrit\s*:|On .+ wrote:|-{3,} ?Original Message|_{10,}\s*$|De\s*:\s|From:\s|Envoyé de mon|Sent from my)/mu', $body, 2);
        if (is_array($parts) && trim($parts[0]) !== '') $body = $parts[0];
        // Retire les lignes citées "> ..."
        $lines = array_filter(explode("\n", $body), fn($l) => !preg_match('/^\s*>/', $l));
        return trim(implode("\n", $lines));
    }
}

if (!function_exists('ak_contact_store_inbound')) {
    /**
     * Vérifie le jeton d'un destinataire et enregistre la réponse entrante du prospect.
     * @return array ['ok'=>bool, 'msg'=>string]
     */
    function ak_contact_store_inbound(PDO $pdo, string $recipient, string $from, string $body): array {
        if (ak_contact_secret() === '') return ['ok' => false, 'msg' => 'no-secret'];
        $p = ak_contact_parse_recipient($recipient);
        if (!$p) return ['ok' => false, 'msg' => 'no-token'];
        $from = ak_contact_email_address($from);
        // Filet de sécurité : un texte qui n'est pas de l'UTF-8 (client mail en iso-8859-1 /
        // windows-1252) est converti, sinon la base remplace chaque accent par « ? »
        if (!mb_check_encoding($body, 'UTF-8')) $body = mb_convert_encoding($body, 'UTF-8', 'Windows-1252');

        try {
            $st = $pdo->prepare("SELECT id, email FROM asso_contact_messages WHERE id = ? LIMIT 1");
            $st->execute([$p['id']]);
            $c = $st->fetch(PDO::FETCH_ASSOC);
            if (!$c) return ['ok' => false, 'msg' => 'unknown-contact'];

            $expected = ak_contact_token((int) $c['id'], (string) $c['email']);
            if (!hash_equals($expected, $p['token'])) return ['ok' => false, 'msg' => 'bad-token'];

            $clean = ak_contact_clean_reply($body);
            if ($clean === '') $clean = '(message vide)';
            $clean = mb_substr($clean, 0, 8000);

            ak_contact_thread_ensure($pdo);
            $pdo->prepare("INSERT INTO asso_contact_thread (contact_id, direction, body, from_email, read_by_founder, created_at) VALUES (?, 'in', ?, ?, 0, NOW())")
                ->execute([(int) $c['id'], $clean, mb_substr($from, 0, 255)]);
            ak_contact_set_status($pdo, (int) $c['id'], 'new');

            return ['ok' => true, 'msg' => 'stored'];
        } catch (Throwable $e) {
            error_log('[ak_contact_store_inbound] ' . $e->getMessage());
            return ['ok' => false, 'msg' => 'server'];
        }
    }
}
