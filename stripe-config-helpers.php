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
        $ch = curl_init('https://api.stripe.com/v1/balance');
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
            $detected_mode = (strpos($sk, 'sk_live_') === 0) ? 'live' : 'test';
            return ['ok' => true, 'message' => 'Connexion OK (mode ' . $detected_mode . ')', 'mode' => $detected_mode];
        }
        if ($http_code === 401) {
            return ['ok' => false, 'message' => 'Clé secrète invalide (401)'];
        }
        return ['ok' => false, 'message' => 'Erreur HTTP ' . $http_code];
    }
}
