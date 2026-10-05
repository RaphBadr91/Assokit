<?php
/**
 * ============================================================
 * ASSOKIT — Garde-fous de l'espace de démonstration DEMO F
 * ============================================================
 * Les identifiants de la démo sont partagés avec des prospects. On évite donc :
 *   - tout e-mail réel : vers une adresse de démo (domaine fictif sous
 *     assokit.fr, rebonds = réputation d'envoi abîmée), ou envoyé depuis une
 *     session de la démo (sinon n'importe qui pourrait écrire à n'importe qui
 *     avec l'adresse d'Assokit). L'envoi est simulé et réussit à l'écran.
 *   - toute modification des comptes de connexion partagés (mot de passe,
 *     e-mail, 2FA, suppression), qui bloquerait la démo pour tout le monde.
 *
 * Tout est réinitialisé chaque nuit (seed-demo-formation.php).
 * ============================================================
 */

if (!defined('AK_DEMO_SLUG')) define('AK_DEMO_SLUG', 'demo-formation');
if (!defined('AK_DEMO_DOMAIN')) define('AK_DEMO_DOMAIN', 'demo-f.assokit.fr');

if (!function_exists('ak_demo_org_id')) {
    /** id de l'organisation de démo (0 si absente). */
    function ak_demo_org_id(): int {
        static $id = null;
        if ($id !== null) return $id;
        $id = 0;
        global $pdo;
        if ($pdo instanceof PDO) {
            try {
                $s = $pdo->prepare("SELECT id FROM organizations WHERE slug = ? LIMIT 1");
                $s->execute([AK_DEMO_SLUG]);
                $id = (int)$s->fetchColumn();
            } catch (Throwable $e) {}
        }
        return $id;
    }
}

if (!function_exists('ak_is_demo_org')) {
    function ak_is_demo_org($org_id): bool {
        $demo = ak_demo_org_id();
        if ($demo > 0 && (int)$org_id === $demo) return true;
        // Associations de démo commerciales (identifiants publics) : e-mails et SMS simulés aussi
        static $extra = null;
        if ($extra === null) {
            $extra = [];
            global $pdo;
            if ($pdo instanceof PDO) {
                try {
                    $extra = array_map('intval', $pdo->query("SELECT id FROM organizations WHERE BINARY slug IN ('demo-evry', 'demo-corbeil', 'demo-paris', 'demo-tpe')")->fetchAll(PDO::FETCH_COLUMN));
                } catch (Throwable $e) {}
            }
        }
        return in_array((int)$org_id, $extra, true);
    }
}

if (!function_exists('ak_demo_session')) {
    /** La personne connectée navigue-t-elle dans la démo ? */
    function ak_demo_session(): bool {
        if (session_status() !== PHP_SESSION_ACTIVE && empty($_SESSION)) return false;
        $u = function_exists('current_user') ? current_user() : null;
        return is_array($u) && ak_is_demo_org($u['org_id'] ?? 0);
    }
}

if (!function_exists('ak_demo_address')) {
    /** Adresse fictive (domaine de démo ou domaines réservés aux exemples). */
    function ak_demo_address(string $email): bool {
        $email = strtolower(trim($email));
        if (preg_match('/<([^>]+)>/', $email, $m)) $email = trim($m[1]);
        $dom = substr(strrchr($email, '@') ?: '', 1);
        if ($dom === '') return false;
        return $dom === AK_DEMO_DOMAIN
            || substr($dom, -strlen('.' . AK_DEMO_DOMAIN)) === '.' . AK_DEMO_DOMAIN
            || in_array($dom, ['example.org', 'example.com', 'example.net'], true)
            || preg_match('/\.(invalid|test|example)$/', $dom);
    }
}

if (!function_exists('ak_demo_mail_blocked')) {
    /**
     * TRUE = ne pas envoyer (et faire comme si l'envoi avait réussi).
     * @param string|array $to
     */
    function ak_demo_mail_blocked($to): bool {
        if (ak_demo_session()) {
            error_log('[demo] e-mail simulé (session DEMO F)');
            return true;
        }
        $list = is_array($to) ? $to : [$to];
        if (!$list) return false;
        foreach ($list as $a) {
            if (!ak_demo_address((string)$a)) return false;
        }
        error_log('[demo] e-mail simulé vers adresse(s) de démo');
        return true;
    }
}

if (!function_exists('ak_demo_protected_account')) {
    /**
     * Compte de connexion partagé de la démo (admin@, salarie@…) : on n'en
     * change ni le mot de passe, ni l'e-mail, ni la 2FA, et on ne le supprime pas.
     * @param array|int $user  ligne users (avec email) ou id
     */
    function ak_demo_protected_account($user): bool {
        if (!is_array($user)) {
            global $pdo;
            if (!($pdo instanceof PDO) || (int)$user <= 0) return false;
            try {
                $s = $pdo->prepare("SELECT email, org_id FROM users WHERE id = ?");
                $s->execute([(int)$user]);
                $user = $s->fetch(PDO::FETCH_ASSOC) ?: [];
            } catch (Throwable $e) { return false; }
        }
        $email = strtolower((string)($user['email'] ?? ''));
        // Comptes de démo / de validation Apple-Google : mot de passe connu, donc figés
        if (in_array($email, ['demo@assokit.fr', 'apple.review@assokit.fr', 'demo-review@assokit.fr'], true)) return true;
        return in_array($email, array_map(fn($p) => $p . '@' . AK_DEMO_DOMAIN,
            ['admin', 'salarie', 'benevole', 'membre', 'financeur']), true);
    }
}

if (!function_exists('ak_demo_message')) {
    /** Message standard affiché quand une action est neutralisée dans la démo. */
    function ak_demo_message(): string {
        return 'Action désactivée dans l’espace de démonstration : ce compte est partagé. Tout est remis à zéro chaque nuit.';
    }
}
