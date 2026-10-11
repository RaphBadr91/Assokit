<?php
/**
 * sa-plan-state-helpers.php — État du plan de PLUSIEURS associations (cockpit)
 * ============================================================================
 * Les listes du cockpit (associations, dashboard, abonnements, statistiques)
 * affichent le plan et le statut de dizaines d'associations : appeler
 * ak_org_plan_state() pour chacune ferait 2 requêtes par ligne.
 *
 * sa_org_plan_states() lit tout en UNE requête et rend, pour chaque association,
 * EXACTEMENT le même tableau que ak_org_plan_state() (trial-helpers.php) :
 * même ligne d'abonnement courante (asso_subscriptions + asso_plans, ordre de
 * ak_get_current_plan), même règle d'essai, même badge. Si la requête groupée
 * échoue (schéma différent), on retombe sur ak_org_plan_state() ligne à ligne.
 *
 * Fichier de fonctions : aucun affichage s'il est appelé directement.
 */

if (basename((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === basename(__FILE__)) {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/trial-helpers.php';

if (!function_exists('sa_plan_state_from_row')) {
    /**
     * Même calcul que ak_org_plan_state(), à partir d'une ligne déjà lue (colonnes : org_status,
     * org_plan, trial_ends_at, sub_status, plan_id, slug, name, is_trial, price_cents,
     * stripe_subscription_id). Le calcul lui-même est ak_plan_state_compute() (trial-helpers.php) :
     * une seule règle pour la fiche, les listes du cockpit et l'appli.
     */
    function sa_plan_state_from_row(array $r): array {
        return ak_plan_state_compute($r);
    }
}

if (!function_exists('sa_org_plan_states')) {
    /**
     * État du plan de plusieurs associations : [org_id => tableau de ak_org_plan_state()].
     * Une association introuvable est absente du résultat.
     */
    function sa_org_plan_states(PDO $pdo, array $org_ids): array {
        $ids = array_values(array_unique(array_filter(array_map('intval', $org_ids), fn($i) => $i > 0)));
        $out = [];
        if (!$ids) return $out;
        try {
            foreach (array_chunk($ids, 500) as $chunk) {
                $in = implode(',', $chunk); // entiers uniquement
                // Ligne courante : même ordre que ak_get_current_plan / ak_org_plan_state (ak_sub_order)
                $rows = $pdo->query("
                    SELECT o.id AS org_id, o.status AS org_status, o.plan AS org_plan, o.trial_ends_at,
                           " . ak_sub_state_cols($pdo) . "
                    FROM organizations o
                    LEFT JOIN asso_subscriptions s ON s.id = (
                        SELECT s2.id FROM asso_subscriptions s2 JOIN asso_plans p2 ON p2.id = s2.plan_id
                        WHERE s2.org_id = o.id
                        ORDER BY " . ak_sub_order('s2') . " LIMIT 1)
                    LEFT JOIN asso_plans p ON p.id = s.plan_id
                    WHERE o.id IN ($in)
                ")->fetchAll(PDO::FETCH_ASSOC);
                foreach ($rows as $r) $out[(int)$r['org_id']] = sa_plan_state_from_row($r);
            }
        } catch (Throwable $e) {
            error_log('[sa_org_plan_states] ' . get_class($e) . ' ' . $e->getMessage());
            $out = [];
            foreach ($ids as $id) $out[$id] = ak_org_plan_state($pdo, $id);
        }
        return $out;
    }
}

if (!function_exists('sa_plan_state_short')) {
    /** Libellé court pour une colonne « Statut » : « Essai · J-12 », « Essai terminé », « Active »… */
    function sa_plan_state_short(array $st): string {
        if ($st['badge_tone'] === 'trial') {
            $d = $st['days_left'];
            return 'Essai' . ($d === null ? '' : ' · ' . ($d <= 0 ? 'dernier jour' : 'J-' . $d));
        }
        if ($st['badge_tone'] === 'expired') return 'Essai terminé';
        if (!empty($st['trial_paused'])) return 'Essai suspendu';
        return (string)$st['badge'];
    }
}

if (!function_exists('sa_plan_tone_icon')) {
    function sa_plan_tone_icon(string $tone): string {
        return ['trial' => '⏱', 'expired' => '⌛', 'active' => '●', 'suspended' => '⏸', 'cancelled' => '✕', 'pending' => '⏳'][$tone] ?? '•';
    }
}

if (!function_exists('sa_plan_tone_class')) {
    /** Classe de badge du cockpit (superadmin-layout.php) pour un badge_tone. */
    function sa_plan_tone_class(string $tone): string {
        return ['trial' => 'sa-badge-violet', 'expired' => 'sa-badge-amber', 'active' => 'sa-badge-green',
                'suspended' => 'sa-badge-red', 'cancelled' => 'sa-badge-gray', 'pending' => 'sa-badge-amber'][$tone] ?? 'sa-badge-gray';
    }
}

if (!function_exists('sa_plan_price_label')) {
    /** Prix lisible d'un plan : « 49,99 €/mois », « Gratuit », « Sur devis ». */
    function sa_plan_price_label(int $price_cents, string $slug = '', bool $is_trial = false): string {
        if ($is_trial) return 'Gratuit pendant l’essai';
        if ($price_cents > 0) return number_format($price_cents / 100, 2, ',', ' ') . ' €/mois';
        return $slug === 'sur-mesure' ? 'Sur devis' : 'Gratuit';
    }
}

if (!function_exists('sa_plan_state_price')) {
    /**
     * Prix de l'état courant : 0 € pendant un essai (en cours ou terminé), sinon le prix du plan.
     * Sans ligne asso_subscriptions (ancien compte), il n'y a pas de prix connu.
     */
    function sa_plan_state_price(array $st): string {
        if ($st['is_trial'] || $st['trial_expired']) return '0 € (essai gratuit)';
        if (empty($st['plan_id'])) return 'Aucun abonnement (ancien plan)';
        return sa_plan_price_label((int)$st['price_cents'], (string)$st['plan_slug']);
    }
}

if (!function_exists('sa_trial_exit_cleanup')) {
    /**
     * Après une sortie d'essai (ak_org_set_plan vers un plan hors essai), sur la ligne d'abonnement
     * courante (même ordre que ak_get_current_plan) :
     *   - la fin d'essai ne reste pas comme « échéance » du nouveau plan ($clear_period_end) ;
     *   - le mode « Offert » (free_grant) posé par l'inscription à l'essai passe en « manuel » si le
     *     nouveau plan est payant ou sur devis : le client paiera, il ne doit plus voir « Plan offert ».
     * ak_org_set_plan() le fait désormais lui-même (appli, API, toutes les pages) : cette fonction
     * n'est plus qu'un filet pour les appelants existants, sans effet de plus (idempotente).
     * $before / $after : ak_org_plan_state() avant / après le changement.
     */
    function sa_trial_exit_cleanup(PDO $pdo, int $org_id, array $before, array $after, bool $clear_period_end = true): void {
        if (!($before['is_trial'] || $before['trial_expired']) || $after['is_trial'] || empty($after['plan_id'])) return;
        $cols = ak_db_columns($pdo, 'asso_subscriptions');
        $sets = [];
        if ($clear_period_end && isset($cols['current_period_end'])) $sets[] = 'current_period_end = NULL';
        $paid = (int) $after['price_cents'] > 0 || $after['plan_slug'] === 'sur-mesure';
        if ($paid && isset($cols['payment_mode']) && ak_db_accepts($pdo, 'asso_subscriptions', 'payment_mode', 'manual')) {
            $sets[] = "payment_mode = IF(payment_mode = 'free_grant', 'manual', payment_mode)";
        }
        if (!$sets) return;
        $pdo->prepare("UPDATE asso_subscriptions SET " . implode(', ', $sets) . " WHERE id = (
                           SELECT id FROM (SELECT s.id FROM asso_subscriptions s JOIN asso_plans p ON p.id = s.plan_id
                                           WHERE s.org_id = ? ORDER BY " . ak_sub_order('s') . " LIMIT 1) t)")
            ->execute([$org_id]);
    }
}

if (!function_exists('sa_plans_list')) {
    /**
     * Plans réels (asso_plans) pour les listes déroulantes, dans l'ordre d'affichage :
     * [['id', 'slug', 'label', 'price_cents', 'is_trial', 'is_visible', 'option']].
     * « option » = libellé prêt à afficher : « Assokit — 49,99 €/mois ».
     */
    function sa_plans_list(PDO $pdo): array {
        $cols = ak_db_columns($pdo, 'asso_plans');
        $sel = "id, slug, name, COALESCE(price_cents, 0) AS price_cents"
             . (isset($cols['is_trial']) ? ", COALESCE(is_trial, 0) AS is_trial" : ", 0 AS is_trial")
             . (isset($cols['is_visible']) ? ", COALESCE(is_visible, 1) AS is_visible" : ", 1 AS is_visible");
        $order = isset($cols['display_order']) ? "display_order ASC, id ASC" : "id ASC";
        $out = [];
        try {
            foreach ($pdo->query("SELECT $sel FROM asso_plans ORDER BY $order")->fetchAll(PDO::FETCH_ASSOC) as $p) {
                $is_trial = !empty($p['is_trial']) || $p['slug'] === 'pro-essai';
                $label = ak_plan_label($p['slug'], $p['name']);
                if ($is_trial) $price = 'essai gratuit de ' . (int)AK_TRIAL_DAYS . ' jours';
                elseif ((int)$p['price_cents'] > 0) $price = sa_plan_price_label((int)$p['price_cents'], (string)$p['slug']);
                else $price = $p['slug'] === 'sur-mesure' ? 'sur devis' : 'gratuit';
                $out[] = [
                    'id' => (int)$p['id'], 'slug' => (string)$p['slug'], 'label' => $label,
                    'price_cents' => (int)$p['price_cents'], 'is_trial' => $is_trial, 'is_visible' => !empty($p['is_visible']),
                    'option' => $label . ' — ' . $price . (!$is_trial && empty($p['is_visible']) ? ' (masqué)' : ''),
                ];
            }
        } catch (Throwable $e) {
            error_log('[sa_plans_list] ' . get_class($e));
        }
        return $out;
    }
}
