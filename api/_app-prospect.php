<?php
/**
 * api/_app-prospect.php — Socle du moteur de prospection CONFORME (RGPD).
 * Modèle de données, jetons de tracking, séquences de relance, liens personnalisés.
 * Principes de conformité intégrés :
 *   - lien de désinscription obligatoire dans chaque email,
 *   - respect du statut 'unsubscribed' (plus jamais recontacté),
 *   - montée en charge progressive (warm-up) + plafond quotidien,
 *   - envoi réellement actif UNIQUEMENT si AK_PROSPECT_SENDING_ENABLED = true.
 * App-only. NE MODIFIE PAS le site.
 */

// Envoi réel désactivé par défaut : à activer volontairement APRÈS mise en place
// d'un domaine d'envoi dédié + warm-up. Tant que false, le cron tourne en DRY-RUN (aucun email).
if (!defined('AK_PROSPECT_SENDING_ENABLED')) define('AK_PROSPECT_SENDING_ENABLED', false);
if (!defined('AK_PROSPECT_DAILY_CAP'))       define('AK_PROSPECT_DAILY_CAP', 40);   // plafond/jour (warm-up : monter doucement)
if (!defined('AK_PROSPECT_DOMAIN'))          define('AK_PROSPECT_DOMAIN', 'assokit.fr');

/** Séquence : étape => délai (jours) avant la relance suivante. 5 contacts max, ~2-3 semaines d'écart. */
if (!function_exists('ak_prospect_sequence')) {
    function ak_prospect_sequence(): array {
        return [
            0 => ['delay' => 14, 'label' => 'Premier contact'],
            1 => ['delay' => 18, 'label' => 'Relance 1'],
            2 => ['delay' => 21, 'label' => 'Relance 2'],
            3 => ['delay' => 25, 'label' => 'Relance 3'],
            4 => ['delay' => 0,  'label' => 'Dernier contact'],
        ];
    }
}

if (!function_exists('ak_prospect_secret')) {
    function ak_prospect_secret(): string {
        if (defined('AK_CONTACT_SECRET') && AK_CONTACT_SECRET) return (string) AK_CONTACT_SECRET;
        if (defined('RESEND_API_KEY') && RESEND_API_KEY) return hash('sha256', 'akpro|' . RESEND_API_KEY);
        return 'ak-prospect-fallback-secret';
    }
}

if (!function_exists('ak_prospect_token')) {
    function ak_prospect_token(int $id, string $email): string {
        return substr(hash_hmac('sha256', 'p' . $id . '|' . strtolower(trim($email)), ak_prospect_secret()), 0, 20);
    }
}

if (!function_exists('ak_prospect_link')) {
    /** Lien personnalisé de tracking → redirige vers la landing selon le type, en loggant le clic. */
    function ak_prospect_link(int $id, string $email): string {
        return 'https://' . AK_PROSPECT_DOMAIN . '/p/' . $id . '.' . ak_prospect_token($id, $email);
    }
}
if (!function_exists('ak_prospect_unsub_link')) {
    function ak_prospect_unsub_link(int $id, string $email): string {
        return 'https://' . AK_PROSPECT_DOMAIN . '/desinscription/' . $id . '.' . ak_prospect_token($id, $email);
    }
}
if (!function_exists('ak_prospect_landing_path')) {
    function ak_prospect_landing_path(string $type): string {
        return $type === 'tpe' ? '/pour-tpe' : '/pour-associations';
    }
}

if (!function_exists('ak_prospect_tables_ensure')) {
    function ak_prospect_tables_ensure(PDO $pdo): void {
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS asso_prospects (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(160) DEFAULT NULL,
                org_name VARCHAR(200) DEFAULT NULL,
                type VARCHAR(8) NOT NULL DEFAULT 'asso',
                email VARCHAR(255) NOT NULL,
                city VARCHAR(120) DEFAULT NULL,
                source VARCHAR(80) DEFAULT NULL,
                status VARCHAR(16) NOT NULL DEFAULT 'new',
                step TINYINT NOT NULL DEFAULT 0,
                consent_basis VARCHAR(40) DEFAULT 'b2b_legitimate_interest',
                next_send_at DATETIME DEFAULT NULL,
                last_sent_at DATETIME DEFAULT NULL,
                created_at DATETIME NOT NULL,
                UNIQUE KEY uq_email (email),
                INDEX idx_status (status),
                INDEX idx_next (next_send_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (Throwable $e) {}
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS asso_prospect_events (
                id INT AUTO_INCREMENT PRIMARY KEY,
                prospect_id INT NOT NULL,
                type VARCHAR(16) NOT NULL,
                step TINYINT DEFAULT NULL,
                meta VARCHAR(255) DEFAULT NULL,
                created_at DATETIME NOT NULL,
                INDEX idx_prospect (prospect_id),
                INDEX idx_type (type)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (Throwable $e) {}
    }
}

if (!function_exists('ak_prospect_event')) {
    function ak_prospect_event(PDO $pdo, int $pid, string $type, ?int $step = null, ?string $meta = null): void {
        try {
            ak_prospect_tables_ensure($pdo);
            $pdo->prepare("INSERT INTO asso_prospect_events (prospect_id, type, step, meta, created_at) VALUES (?, ?, ?, ?, NOW())")
                ->execute([$pid, $type, $step, $meta !== null ? mb_substr($meta, 0, 255) : null]);
        } catch (Throwable $e) {}
    }
}

if (!function_exists('ak_prospect_parse')) {
    /** Extrait [id, token] d'un segment "123.abcdef..." ; vérifie le jeton contre l'email en base. */
    function ak_prospect_parse(PDO $pdo, string $seg): ?array {
        if (!preg_match('/^(\d+)\.([a-f0-9]{12,40})$/i', trim($seg), $m)) return null;
        $id = (int) $m[1]; $tok = strtolower($m[2]);
        try {
            $st = $pdo->prepare("SELECT id, email, type FROM asso_prospects WHERE id = ? LIMIT 1");
            $st->execute([$id]);
            $p = $st->fetch(PDO::FETCH_ASSOC);
            if (!$p) return null;
            if (!hash_equals(ak_prospect_token($id, (string) $p['email']), $tok)) return null;
            return $p;
        } catch (Throwable $e) { return null; }
    }
}
