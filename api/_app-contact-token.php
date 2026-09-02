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

if (!function_exists('ak_contact_clean_reply')) {
    /** Retire l'historique cité d'une réponse email (best-effort). */
    function ak_contact_clean_reply(string $body): string {
        $body = str_replace("\r\n", "\n", $body);
        $parts = preg_split('/^\s*(Le .+ a écrit\s*:|On .+ wrote:|-{3,} ?Original Message|De\s*:\s|From:\s|Envoyé de mon|Sent from my)/mu', $body, 2);
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
        $from = trim($from);
        if ($from !== '' && !filter_var($from, FILTER_VALIDATE_EMAIL)) $from = '';

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
            try { $pdo->prepare("UPDATE asso_contact_messages SET status = 'new' WHERE id = ?")->execute([(int) $c['id']]); } catch (Throwable $e) {}

            return ['ok' => true, 'msg' => 'stored'];
        } catch (Throwable $e) {
            error_log('[ak_contact_store_inbound] ' . $e->getMessage());
            return ['ok' => false, 'msg' => 'server'];
        }
    }
}
