<?php
/**
 * domain-helpers.php
 * --------------------------------------------------------------
 * Helpers pour le système de domaines personnalisés
 *
 * Phase 1 (actuelle) : préparation, fonctions retournent des valeurs par défaut
 * Phase 2 : activation des sous-domaines *.assokit.fr
 * Phase 3 : domaines perso clients (adherents.tonasso.fr)
 * Phase 4 : emails depuis domaine client (contact@tonasso.fr)
 *
 * Toutes les fonctions sont SAFE (ne plantent pas si tables absentes)
 * --------------------------------------------------------------
 */

if (!function_exists('ak_get_subdomain_slug')) {
    /**
     * Récupère le slug de sous-domaine d'une org.
     *
     * @param PDO $pdo
     * @param int $org_id
     * @return string|null Le slug (ex: "latitude91") ou null
     */
    function ak_get_subdomain_slug(PDO $pdo, int $org_id): ?string {
        try {
            $st = $pdo->prepare("SELECT subdomain_slug FROM organizations WHERE id = :id LIMIT 1");
            $st->execute([':id' => $org_id]);
            $slug = $st->fetchColumn();
            return $slug ?: null;
        } catch (Throwable $e) {
            // Colonne n'existe pas encore → migration v47 pas passée
            return null;
        }
    }
}

if (!function_exists('ak_get_subdomain_url')) {
    /**
     * Génère l'URL complète du sous-domaine *.assokit.fr d'une org.
     *
     * @param PDO $pdo
     * @param int $org_id
     * @return string|null L'URL (ex: "https://latitude91.assokit.fr") ou null
     */
    function ak_get_subdomain_url(PDO $pdo, int $org_id): ?string {
        $slug = ak_get_subdomain_slug($pdo, $org_id);
        if (!$slug) return null;
        return "https://{$slug}.assokit.fr";
    }
}

if (!function_exists('ak_get_custom_domain')) {
    /**
     * Récupère le domaine personnalisé actif d'une org (Phase 3).
     *
     * @param PDO $pdo
     * @param int $org_id
     * @return array|null Les données du domaine ou null si pas de domaine actif
     */
    function ak_get_custom_domain(PDO $pdo, int $org_id): ?array {
        try {
            $st = $pdo->prepare("
                SELECT * FROM asso_custom_domains
                WHERE org_id = :id AND status = 'active'
                LIMIT 1
            ");
            $st->execute([':id' => $org_id]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (Throwable $e) {
            // Table n'existe pas encore → migration v47 pas passée
            return null;
        }
    }
}

if (!function_exists('ak_get_active_domain_url')) {
    /**
     * Retourne la meilleure URL disponible pour une org :
     *   1. Domaine personnalisé si actif (Phase 3)
     *   2. Sinon sous-domaine *.assokit.fr (Phase 2)
     *   3. Sinon URL générique assokit.fr (par défaut)
     *
     * @param PDO $pdo
     * @param int $org_id
     * @return string L'URL active
     */
    function ak_get_active_domain_url(PDO $pdo, int $org_id): string {
        // 1. Domaine perso ?
        $custom = ak_get_custom_domain($pdo, $org_id);
        if ($custom && !empty($custom['domain'])) {
            return 'https://' . $custom['domain'];
        }
        // 2. Sous-domaine ?
        $sub_url = ak_get_subdomain_url($pdo, $org_id);
        if ($sub_url) {
            return $sub_url;
        }
        // 3. Fallback générique
        return 'https://assokit.fr';
    }
}

if (!function_exists('ak_get_branding_colors')) {
    /**
     * Récupère les couleurs de branding d'une org.
     *
     * @param PDO $pdo
     * @param int $org_id
     * @return array ['primary' => '#xxx', 'secondary' => '#xxx']
     */
    function ak_get_branding_colors(PDO $pdo, int $org_id): array {
        $defaults = ['primary' => '#059669', 'secondary' => '#FCD34D'];

        try {
            $st = $pdo->prepare("
                SELECT branding_primary_color, branding_secondary_color
                FROM organizations
                WHERE id = :id LIMIT 1
            ");
            $st->execute([':id' => $org_id]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                return [
                    'primary'   => $row['branding_primary_color']   ?: $defaults['primary'],
                    'secondary' => $row['branding_secondary_color'] ?: $defaults['secondary'],
                ];
            }
        } catch (Throwable $e) {
            // Colonnes n'existent pas encore
        }

        return $defaults;
    }
}

if (!function_exists('ak_should_hide_assokit_branding')) {
    /**
     * Détermine si l'org a payé pour cacher les mentions Assokit (Phase 3).
     *
     * @param PDO $pdo
     * @param int $org_id
     * @return bool
     */
    function ak_should_hide_assokit_branding(PDO $pdo, int $org_id): bool {
        try {
            $st = $pdo->prepare("SELECT hide_assokit_branding FROM organizations WHERE id = :id LIMIT 1");
            $st->execute([':id' => $org_id]);
            return (bool)$st->fetchColumn();
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('ak_validate_subdomain_slug')) {
    /**
     * Valide un slug de sous-domaine.
     * Règles : 3-30 caractères, [a-z0-9-], pas de tirets en début/fin.
     *
     * @param string $slug
     * @return array ['valid' => bool, 'error' => ?string]
     */
    function ak_validate_subdomain_slug(string $slug): array {
        $slug = strtolower(trim($slug));

        if (strlen($slug) < 3) {
            return ['valid' => false, 'error' => 'Le sous-domaine doit faire au moins 3 caractères.'];
        }
        if (strlen($slug) > 30) {
            return ['valid' => false, 'error' => 'Le sous-domaine ne peut dépasser 30 caractères.'];
        }
        if (!preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/', $slug)) {
            return ['valid' => false, 'error' => 'Le sous-domaine ne peut contenir que des lettres minuscules, chiffres et tirets (pas en début ou fin).'];
        }

        // Liste des slugs réservés
        $reserved = ['www', 'admin', 'api', 'mail', 'webmail', 'cpanel',
                     'app', 'dashboard', 'login', 'signup', 'support',
                     'help', 'docs', 'blog', 'contact', 'pro', 'shop',
                     'store', 'demo', 'test', 'staging', 'dev', 'beta',
                     'assokit', 'rbps', 'security', 'webhooks', 'cdn'];
        if (in_array($slug, $reserved, true)) {
            return ['valid' => false, 'error' => 'Ce sous-domaine est réservé. Choisissez-en un autre.'];
        }

        return ['valid' => true, 'error' => null];
    }
}

if (!function_exists('ak_is_subdomain_available')) {
    /**
     * Vérifie si un slug de sous-domaine est disponible.
     *
     * @param PDO $pdo
     * @param string $slug
     * @param int $exclude_org_id Org à exclure (pour édition)
     * @return bool
     */
    function ak_is_subdomain_available(PDO $pdo, string $slug, int $exclude_org_id = 0): bool {
        try {
            $st = $pdo->prepare("
                SELECT COUNT(*) FROM organizations
                WHERE subdomain_slug = :s AND id != :id
            ");
            $st->execute([':s' => $slug, ':id' => $exclude_org_id]);
            return (int)$st->fetchColumn() === 0;
        } catch (Throwable $e) {
            return true; // Fail-safe : si table pas migrée, considérer comme dispo
        }
    }
}
