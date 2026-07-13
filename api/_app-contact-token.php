<?php
/**
 * api/_app-contact-token.php — Jetons pour le double-sens des demandes de contact.
 * Génère une adresse Reply-To unique par prospect et vérifie les réponses entrantes.
 * App-only. NE MODIFIE PAS le site.
 *
 * Adresse de réponse : c{contact_id}.{token}@reply.assokit.fr
 *   → à router (MX / webhook) vers api/inbound-contact.php
 */

if (!defined('AK_CONTACT_REPLY_DOMAIN')) {
    // Sous-domaine dédié à la réception (à configurer côté DNS/inbound).
    define('AK_CONTACT_REPLY_DOMAIN', 'reply.assokit.fr');
}

if (!function_exists('ak_contact_secret')) {
    function ak_contact_secret(): string {
        if (defined('AK_CONTACT_SECRET') && AK_CONTACT_SECRET) return (string) AK_CONTACT_SECRET;
        // Repli : dérive un secret stable de la clé Resend (serveur uniquement).
        if (defined('RESEND_API_KEY') && RESEND_API_KEY) return hash('sha256', 'akctc|' . RESEND_API_KEY);
        return 'ak-contact-fallback-secret';
    }
}

if (!function_exists('ak_contact_token')) {
    function ak_contact_token(int $contact_id, string $email): string {
        return substr(hash_hmac('sha256', $contact_id . '|' . strtolower(trim($email)), ak_contact_secret()), 0, 16);
    }
}

if (!function_exists('ak_contact_reply_address')) {
    function ak_contact_reply_address(int $contact_id, string $email): string {
        return 'c' . $contact_id . '.' . ak_contact_token($contact_id, $email) . '@' . AK_CONTACT_REPLY_DOMAIN;
    }
}

if (!function_exists('ak_contact_parse_recipient')) {
    /** Extrait [contact_id, token] depuis une adresse c{id}.{token}@... ; null si invalide. */
    function ak_contact_parse_recipient(string $addr): ?array {
        if (preg_match('/c(\d+)\.([a-f0-9]{8,32})@/i', $addr, $m)) {
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
