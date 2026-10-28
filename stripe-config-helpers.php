<?php
/**
 * stripe-config-helpers.php
 * --------------------------------------------------------------
 * Helpers pour gérer la configuration Stripe stockée en BDD.
 *
 * Architecture : les clés API et la config sont stockées dans la
 * table asso_stripe_config plutôt que dans config.php pour :
 *   - Permettre la modification depuis l'interface admin
 *   - Sécuriser (pas de fichier source à modifier en prod)
 *   - Faciliter le passage test/live
 *
 * Toutes les fonctions sont SAFE :
 *   - Retournent null/false si table absente
 *   - Aucune fatale si Stripe non configuré
 * --------------------------------------------------------------
 */

if (!function_exists('ak_stripe_api_base')) {
    /**
     * Adresse de l'API Stripe, sans « /v1 » : https://api.stripe.com en production.
     * AK_STRIPE_API_BASE n'est défini QUE par le fichier de préchargement du banc de test (faux
     * Stripe local, auto_prepend_file) : jamais dans config.php ni en production.
     */
    function ak_stripe_api_base(): string {
        return defined('AK_STRIPE_API_BASE') ? rtrim((string)AK_STRIPE_API_BASE, '/') : 'https://api.stripe.com';
    }
}

if (!function_exists('ak_stripe_key_mode')) {
    /** Mode d'une clé Stripe : 'live' (sk_live_, rk_live_, pk_live_), 'test' (…_test_), '' si inconnue. */
    function ak_stripe_key_mode(?string $key): string {
        $key = (string)$key;
        if (preg_match('/^(sk|rk|pk)_live_/', $key)) return 'live';
        if (preg_match('/^(sk|rk|pk)_test_/', $key)) return 'test';
        return '';
    }
}

if (!defined('AK_STRIPE_WEBHOOK_EVENTS')) {
    /**
     * Événements Stripe traités par stripe-webhook.php : exactement ceux auxquels le point de
     * terminaison est abonné par « Configurer Stripe automatiquement » (stripe-setup-helpers.php).
     * Un seul endroit : la liste du webhook et celle de la configuration ne peuvent pas diverger.
     */
    define('AK_STRIPE_WEBHOOK_EVENTS', [
        'customer.subscription.created',
        'customer.subscription.updated',
        'customer.subscription.deleted',
        'invoice.created',
        'invoice.finalized',
        'invoice.updated',
        'invoice.paid',
        'invoice.payment_failed',
        'invoice.payment_action_required',
        'invoice.voided',
        'invoice.marked_uncollectible',
        'customer.updated',
        'customer.deleted',
        'charge.refunded',
        'charge.dispute.created',
    ]);
}

if (!function_exists('ak_stripe_config_get')) {
    /**
     * Récupère une valeur de configuration Stripe.
     *
     * @param PDO $pdo
     * @param string $key Clé de config (ex: 'stripe_enabled')
     * @param mixed $default Valeur par défaut si absente
     * @return mixed
     */
    function ak_stripe_config_get(PDO $pdo, string $key, $default = null) {
        try {
            $st = $pdo->prepare("SELECT config_value FROM asso_stripe_config WHERE config_key = :k LIMIT 1");
            $st->execute([':k' => $key]);
            $val = $st->fetchColumn();
            return ($val === false || $val === null) ? $default : $val;
        } catch (Throwable $e) {
            return $default;
        }
    }
}

if (!function_exists('ak_stripe_config_set')) {
    /**
     * Met à jour une valeur de configuration Stripe.
     *
     * @param PDO $pdo
     * @param string $key
     * @param string|null $value
     * @return bool
     */
    function ak_stripe_config_set(PDO $pdo, string $key, ?string $value): bool {
        try {
            $st = $pdo->prepare("
                INSERT INTO asso_stripe_config (config_key, config_value)
                VALUES (:k, :v)
                ON DUPLICATE KEY UPDATE config_value = VALUES(config_value), updated_at = NOW()
            ");
            $st->execute([':k' => $key, ':v' => $value]);
            return true;
        } catch (Throwable $e) {
            error_log('[ak_stripe_config_set] ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('ak_stripe_is_configured')) {
    /**
     * Vérifie si Stripe est configuré ET activé.
     *
     * @param PDO $pdo
     * @return bool
     */
    function ak_stripe_is_configured(PDO $pdo): bool {
        try {
            $enabled = (int)ak_stripe_config_get($pdo, 'stripe_enabled', 0);
            if ($enabled !== 1) return false;

            $pk = ak_stripe_config_get($pdo, 'stripe_publishable_key', null);
            $sk = ak_stripe_config_get($pdo, 'stripe_secret_key', null);

            return !empty($pk) && !empty($sk);
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('ak_stripe_get_publishable_key')) {
    /**
     * Récupère la clé publique Stripe (utilisée côté JS).
     *
     * @param PDO $pdo
     * @return string|null
     */
    function ak_stripe_get_publishable_key(PDO $pdo): ?string {
        $key = ak_stripe_config_get($pdo, 'stripe_publishable_key', null);
        return $key ?: null;
    }
}

if (!function_exists('ak_stripe_get_secret_key')) {
    /**
     * Récupère la clé secrète Stripe (utilisée côté serveur uniquement).
     *
     * @param PDO $pdo
     * @return string|null
     */
    function ak_stripe_get_secret_key(PDO $pdo): ?string {
        $key = ak_stripe_config_get($pdo, 'stripe_secret_key', null);
        return $key ?: null;
    }
}

if (!function_exists('ak_stripe_get_webhook_secret')) {
    /**
     * Récupère le secret de webhook Stripe.
     *
     * @param PDO $pdo
     * @return string|null
     */
    function ak_stripe_get_webhook_secret(PDO $pdo): ?string {
        $secret = ak_stripe_config_get($pdo, 'stripe_webhook_secret', null);
        return $secret ?: null;
    }
}

if (!function_exists('ak_stripe_get_mode')) {
    /**
     * Retourne le mode Stripe actuel : 'test' ou 'live'.
     *
     * @param PDO $pdo
     * @return string
     */
    function ak_stripe_get_mode(PDO $pdo): string {
        // La clé secrète enregistrée fait foi (stripe_mode n'en est qu'une copie, gardée pour l'affichage)
        $from_key = ak_stripe_key_mode(ak_stripe_config_get($pdo, 'stripe_secret_key', null));
        if ($from_key !== '') return $from_key;
        $mode = ak_stripe_config_get($pdo, 'stripe_mode', 'test');
        return ($mode === 'live') ? 'live' : 'test';
    }
}

if (!function_exists('ak_vat_is_enabled')) {
    /**
     * La TVA est-elle activée ?
     * (Désactivée tant que RBPS n'est pas immatriculée + assujettie TVA)
     *
     * @param PDO $pdo
     * @return bool
     */
    function ak_vat_is_enabled(PDO $pdo): bool {
        return (int)ak_stripe_config_get($pdo, 'vat_enabled', 0) === 1;
    }
}

if (!function_exists('ak_vat_get_rate')) {
    /**
     * Taux de TVA en % (par défaut 20).
     *
     * @param PDO $pdo
     * @return float
     */
    function ak_vat_get_rate(PDO $pdo): float {
        return (float)ak_stripe_config_get($pdo, 'vat_rate', 20);
    }
}

if (!function_exists('ak_vat_applicable_rate')) {
    /**
     * Taux de TVA réellement appliqué aux abonnements Assokit, en % : celui qui est affiché (prix HT +
     * TVA = TTC) ET celui que Stripe facture (stripe-create-payment-intent.php). 0 quand la TVA est
     * désactivée (franchise en base, « TVA non applicable, art. 293 B du CGI ») ou que le taux vaut 0.
     */
    function ak_vat_applicable_rate(PDO $pdo): float {
        if (!ak_vat_is_enabled($pdo)) return 0.0;
        $rate = round(ak_vat_get_rate($pdo), 2);
        return ($rate > 0 && $rate < 100) ? $rate : 0.0;
    }
}

if (!function_exists('ak_stripe_test_connection')) {
    /**
     * Teste la connexion à l'API Stripe avec les clés actuelles.
     *
     * @param PDO $pdo
     * @return array ['ok' => bool, 'message' => string]
     */
    function ak_stripe_test_connection(PDO $pdo): array {
        $sk = ak_stripe_get_secret_key($pdo);
        if (empty($sk)) {
            return ['ok' => false, 'message' => 'Clé secrète absente'];
        }

        // Test simple : appel à /v1/balance (endpoint léger qui requiert juste auth)
        $ch = curl_init(ak_stripe_api_base() . '/v1/balance');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $sk,
            'Stripe-Version: 2024-06-20',
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($err) {
            return ['ok' => false, 'message' => 'Erreur réseau : ' . $err];
        }
        if ($http_code === 200) {
            // Détection du mode test/live à partir de la clé
            $detected_mode = ak_stripe_key_mode($sk) === 'live' ? 'live' : 'test';
            return ['ok' => true, 'message' => 'Connexion OK (mode ' . ($detected_mode === 'live' ? 'réel' : 'test') . ')', 'mode' => $detected_mode];
        }
        if ($http_code === 401) {
            return ['ok' => false, 'message' => 'Clé secrète refusée par Stripe (401) : recopiez-la depuis Développeurs › Clés API'];
        }
        if ($http_code === 403) {
            return ['ok' => false, 'message' => 'Clé restreinte sans le droit de lire le solde (403) : utilisez la clé secrète standard (sk_…)'];
        }
        return ['ok' => false, 'message' => 'Erreur HTTP ' . $http_code];
    }
}
