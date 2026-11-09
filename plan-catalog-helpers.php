<?php
/**
 * plan-catalog-helpers.php
 * --------------------------------------------------------------
 * Règles du catalogue des formules (table asso_plans), communes à la page /fondateur-plans
 * et à l'API de l'appli (api/app-founder-plans.php) :
 *   - slugs de la grille figés : l'inscription cherche « pro-essai », la fin d'essai « demarrage »,
 *     la migration de la grille, cli-rattacher-fondateur.php, les liens ?plan= et les prix de secours
 *     lisent les autres ;
 *   - formules jamais proposées à la souscription (offre fondateur, essai, accès limité) : toujours
 *     masquées, même si la case est cochée ;
 *   - une formule de la grille se masque, elle ne se supprime pas.
 * Aucune lecture ni écriture au chargement du fichier. Fonctionne avant comme après la migration
 * de la grille (colonnes absentes : rien ne bloque).
 * --------------------------------------------------------------
 */

if (!defined('AK_PLAN_FIXED_SLUGS')) {
    /** Lignes de la grille : slug figé, suppression refusée. */
    define('AK_PLAN_FIXED_SLUGS', ['pro-essai', 'demarrage', 'fondateur', 'assokit', 'ak-essentiel', 'ak-equipe', 'sur-mesure']);
}
if (!defined('AK_PLAN_NEVER_VISIBLE')) {
    /** Lignes jamais proposées à la souscription ni affichées comme formules en vente. */
    define('AK_PLAN_NEVER_VISIBLE', ['fondateur', 'pro-essai', 'demarrage']);
}
if (!defined('AK_PLAN_PUBLIC_GRID')) {
    /** Formules de la page Tarifs, dans leur ordre. */
    define('AK_PLAN_PUBLIC_GRID', ['ak-essentiel', 'assokit', 'ak-equipe', 'sur-mesure']);
}

if (!class_exists('AkPlanCatalogException')) {
    /** Refus d'une règle du catalogue : message en français, affichable tel quel. */
    class AkPlanCatalogException extends RuntimeException {}
}

if (!function_exists('ak_plan_slug_fixed')) {
    function ak_plan_slug_fixed(?string $slug): bool {
        return in_array((string)$slug, AK_PLAN_FIXED_SLUGS, true);
    }
}

if (!function_exists('ak_plan_never_visible')) {
    function ak_plan_never_visible(?string $slug): bool {
        return in_array((string)$slug, AK_PLAN_NEVER_VISIBLE, true);
    }
}

if (!function_exists('ak_plan_catalog_role')) {
    /**
     * Rôle d'une ligne du catalogue, affiché sous son nom dans /fondateur-plans (même table que
     * fondateur-pricing.php, « Formules techniques », complétée pour les formules en vente).
     */
    function ak_plan_catalog_role(array $p): string {
        $slug = (string)($p['slug'] ?? '');
        $days = defined('AK_TRIAL_DAYS') ? (int)AK_TRIAL_DAYS : 14;
        if ($slug === 'fondateur') return 'Clients de la première heure : fonctions actuelles, prix garanti 12 mois (cli-rattacher-fondateur.php). Prélevée au prix Stripe de Pro.';
        if ($slug === 'pro-essai' || !empty($p['is_trial'])) return 'Essai gratuit de ' . $days . "\u{00A0}jours (fonctions Pro)";
        if ($slug === 'demarrage') return 'Accès limité : fin d’essai non convertie, résiliation, impayé';
        if ($slug === 'sur-mesure') return 'Formule de la page Tarifs, sur devis (fédérations, collectivités)';
        if (in_array($slug, AK_PLAN_PUBLIC_GRID, true)) return 'Formule de la page Tarifs';
        if (!empty($p['is_custom_quote']) || strpos($slug, 'sur-mesure-') === 0) return 'Sur-mesure propre à un client';
        if (empty($p['is_visible'])) return 'Ancienne formule, masquée';
        return 'Hors grille, proposée à la souscription';
    }
}

if (!function_exists('ak_plan_catalog_guard')) {
    /**
     * Applique les règles du catalogue avant une écriture dans asso_plans.
     *   $old    : ligne actuelle (null en création) ;
     *   $data   : valeurs à écrire (slug, is_visible…), corrigées sur place (is_visible forcé à 0) ;
     *   $action : 'create', 'update' ou 'delete'.
     * Lève AkPlanCatalogException (message en français) si l'opération est refusée. Une erreur de
     * lecture de la base laisse passer l'exception PDO (l'appelant la journalise).
     */
    function ak_plan_catalog_guard(PDO $pdo, ?array $old, array &$data, string $action): void {
        $old_slug = $old !== null ? (string)($old['slug'] ?? '') : null;
        if ($action === 'delete') {
            if ($old_slug !== null && ak_plan_slug_fixed($old_slug)) {
                throw new AkPlanCatalogException('Formule de la grille : masquez-la plutôt que de la supprimer.');
            }
            return;
        }
        if ($action !== 'create' && $action !== 'update') return;
        if ($action === 'update' && $old === null) throw new AkPlanCatalogException('Formule introuvable.');
        $slug = (string)($data['slug'] ?? ($old_slug ?? ''));
        if ($action === 'update' && ak_plan_slug_fixed($old_slug) && $slug !== $old_slug) {
            throw new AkPlanCatalogException('Le slug d’une formule de la grille ne peut pas changer.');
        }
        if ($action === 'create' || $slug !== $old_slug) {
            $q = $pdo->prepare("SELECT COUNT(*) FROM asso_plans WHERE slug = ? AND id <> ?");
            $q->execute([$slug, $old !== null ? (int)($old['id'] ?? 0) : 0]);
            if ((int)$q->fetchColumn() > 0) throw new AkPlanCatalogException('Ce slug existe déjà.');
            // Slug de la grille pas encore en base (avant la migration) : c'est la migration qui crée
            // cette ligne avec ses limites et ses fonctions ; une ligne faite à la main serait reprise telle quelle
            if (ak_plan_slug_fixed($slug)) {
                throw new AkPlanCatalogException('Ce slug est réservé à la grille : la migration crée cette formule elle-même.');
            }
        }
        // Offre fondateur : prix garanti 12 mois aux clients rattachés, et prélevé au prix Stripe de Pro.
        // Changer price_cents ici ne changerait que l'affichage et le MRR, pas ce que paie la carte.
        if ($action === 'update' && $old_slug === 'fondateur' && array_key_exists('price_cents', $data)
            && (int)$data['price_cents'] !== (int)($old['price_cents'] ?? 0)) {
            throw new AkPlanCatalogException('Offre fondateur : le prix est garanti 12 mois aux clients rattachés et prélevé au prix Stripe de Pro. Il ne se modifie pas ici.');
        }
        if (ak_plan_never_visible($slug) || ak_plan_never_visible($old_slug)) $data['is_visible'] = 0;
    }
}

if (!function_exists('ak_plan_catalog_price_warning')) {
    /**
     * Prix changé sur une ligne qui a déjà un prix Stripe : le prix Stripe ne suit pas tout seul.
     * @return string message à ajouter au retour ('' si rien à signaler)
     */
    function ak_plan_catalog_price_warning(?array $old, array $data): string {
        if (!$old || !array_key_exists('price_cents', $data)) return '';
        if (trim((string)($old['stripe_price_id_monthly'] ?? '')) === '') return '';
        if ((int)$data['price_cents'] === (int)($old['price_cents'] ?? 0)) return '';
        return 'Prix modifié : relancez Configuration Stripe › Configurer Stripe automatiquement pour créer le nouveau prix Stripe (les abonnements par carte restent au prix Stripe actuel d’ici là).';
    }
}

if (!function_exists('ak_plan_slug_rename')) {
    /** L'ancien libellé organizations.plan suit le slug renommé, comme ak_org_set_plan(). */
    function ak_plan_slug_rename(PDO $pdo, string $old, string $new): void {
        if ($old === '' || $new === '' || $old === $new) return;
        $pdo->prepare("UPDATE organizations SET plan = ? WHERE plan = ?")->execute([$new, $old]);
    }
}
