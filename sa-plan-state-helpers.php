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
     * État du plan de plusieurs associations : [org_id => tableau de ak_org_plan_state()], plus
     * « payment_mode » (mode de paiement de la ligne courante, null si inconnu) et « cancel_scheduled »
     * (résiliation Stripe programmée : fin d'accès 'd/m/Y' ou '', voir sa_sub_cancel_scheduled ; null sinon).
     * Une association introuvable est absente du résultat.
     */
    function sa_org_plan_states(PDO $pdo, array $org_ids): array {
        $ids = array_values(array_unique(array_filter(array_map('intval', $org_ids), fn($i) => $i > 0)));
        $out = [];
        if (!$ids) return $out;
        // Mode de paiement de la ligne courante (« Offert » ne rapporte rien : sa_plan_state_mrr_cents),
        // et de quoi repérer une résiliation Stripe programmée (sa_sub_cancel_scheduled)
        $sc = ak_db_columns($pdo, 'asso_subscriptions');
        $pay = isset($sc['payment_mode']) ? 's.payment_mode' : 'NULL';
        $extra = [];
        foreach (['cancel_at_period_end', 'current_period_end', 'cancelled_at'] as $c) {
            $extra[] = (isset($sc[$c]) ? 's.' . $c : 'NULL') . ' AS sub_' . $c;
        }
        $extra[] = (isset(ak_db_columns($pdo, 'organizations')['current_period_end']) ? 'o.current_period_end' : 'NULL') . ' AS org_period_end';
        try {
            foreach (array_chunk($ids, 500) as $chunk) {
                $in = implode(',', $chunk); // entiers uniquement
                // Ligne courante : même ordre que ak_get_current_plan / ak_org_plan_state (ak_sub_order)
                $rows = $pdo->query("
                    SELECT o.id AS org_id, o.status AS org_status, o.plan AS org_plan, o.trial_ends_at, " . ak_org_payment_pending_col($pdo) . ", " . ak_org_trial_card_cols($pdo) . ",
                           " . ak_sub_state_cols($pdo) . ", $pay AS payment_mode, " . implode(', ', $extra) . "
                    FROM organizations o
                    LEFT JOIN asso_subscriptions s ON s.id = (
                        SELECT s2.id FROM asso_subscriptions s2 JOIN asso_plans p2 ON p2.id = s2.plan_id
                        WHERE s2.org_id = o.id
                        ORDER BY " . ak_sub_order('s2') . " LIMIT 1)
                    LEFT JOIN asso_plans p ON p.id = s.plan_id
                    WHERE o.id IN ($in)
                ")->fetchAll(PDO::FETCH_ASSOC);
                foreach ($rows as $r) {
                    $st = sa_plan_state_from_row($r);
                    $out[(int)$r['org_id']] = $st + [
                        'payment_mode'     => $r['payment_mode'] ?? null,
                        'cancel_scheduled' => $st['is_trial'] || $st['trial_expired'] ? null : sa_sub_cancel_scheduled([
                            'status' => $r['sub_status'] ?? null, 'cancel_at_period_end' => $r['sub_cancel_at_period_end'] ?? null,
                            'current_period_end' => $r['sub_current_period_end'] ?? null, 'cancelled_at' => $r['sub_cancelled_at'] ?? null,
                        ], $r['org_period_end'] ?? null),
                    ];
                }
            }
        } catch (Throwable $e) {
            error_log('[sa_org_plan_states] ' . get_class($e) . ' ' . $e->getMessage());
            $out = [];
            foreach ($ids as $id) $out[$id] = ak_org_plan_state($pdo, $id);
        }
        return $out;
    }
}

if (!function_exists('sa_sub_cancel_scheduled')) {
    /**
     * Résiliation Stripe programmée sur une ligne d'abonnement (« active » avec cancel_at_period_end = 1,
     * jusqu'à ce que Stripe arrête l'abonnement à l'échéance) : fin d'accès 'd/m/Y' ('' si elle n'est pas
     * datée), null s'il n'y en a pas. Même règle que l'espace de l'association (ak_plan_trial_info) ; une
     * résiliation hors Stripe passe la ligne en « cancelled » et s'affiche déjà « Résiliée ».
     * $row : status, cancel_at_period_end, current_period_end, cancelled_at (les colonnes absentes valent null) ;
     * $org_period_end : organizations.current_period_end, repli quand la ligne n'a pas d'échéance (ak_sub_access_end).
     */
    function sa_sub_cancel_scheduled(array $row, ?string $org_period_end = null): ?string {
        if (($row['status'] ?? '') !== 'active' || empty($row['cancel_at_period_end'])) return null;
        $end = function_exists('ak_sub_access_end')
            ? ak_sub_access_end($row['current_period_end'] ?? null, $row['cancelled_at'] ?? null, $org_period_end)
            : ($row['current_period_end'] ?? null);
        return $end && strtotime((string)$end) ? date('d/m/Y', strtotime((string)$end)) : '';
    }
}

if (!function_exists('sa_plan_state_mrr_cents')) {
    /**
     * Ce qu'une association rapporte chaque mois (centimes HT), pour le MRR du cockpit : abonnement
     * courant actif, association cliente (ni essai, ni fin d'essai, ni suspendue, ni résiliée),
     * et jamais un plan « Offert » (payment_mode = free_grant), qui ne rapporte rien.
     * $st : un élément de sa_org_plan_states().
     */
    function sa_plan_state_mrr_cents(array $st): int {
        if ($st['badge_tone'] !== 'active' || $st['sub_status'] !== 'active') return 0;
        if (($st['payment_mode'] ?? null) === 'free_grant') return 0;
        return max(0, (int)$st['price_cents']);
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
    /** Prix lisible d'un plan (asso_plans.price_cents est HT) : « 49,99 € HT/mois », « Gratuit », « Sur devis ». */
    function sa_plan_price_label(int $price_cents, string $slug = '', bool $is_trial = false): string {
        if ($is_trial) return 'Gratuit pendant l’essai';
        if ($price_cents > 0) return number_format($price_cents / 100, 2, ',', ' ') . ' € HT/mois';
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
     * « option » = libellé prêt à afficher : « Assokit — 49,99 € HT/mois ».
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

if (!function_exists('sa_stripe_live_subs')) {
    /**
     * Abonnements Stripe encore vivants d'une association : lignes asso_subscriptions payées par
     * carte (payment_mode = stripe), avec un identifiant Stripe, pas annulées.
     * @return array<int, string> [id de ligne => identifiant de l'abonnement Stripe]
     */
    function sa_stripe_live_subs(PDO $pdo, int $org_id): array {
        $cols = ak_db_columns($pdo, 'asso_subscriptions');
        if (!isset($cols['stripe_subscription_id'], $cols['payment_mode'])) return [];
        try {
            $st = $pdo->prepare("SELECT id, stripe_subscription_id FROM asso_subscriptions
                                 WHERE org_id = ? AND payment_mode = 'stripe'
                                   AND TRIM(COALESCE(stripe_subscription_id, '')) <> ''
                                   AND COALESCE(status, '') <> 'cancelled'
                                 ORDER BY id");
            $st->execute([$org_id]);
            $out = [];
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $out[(int)$r['id']] = trim((string)$r['stripe_subscription_id']);
            return $out;
        } catch (Throwable $e) {
            error_log('[sa_stripe_live_subs] ' . get_class($e) . ' ' . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('sa_stripe_cancel_pending_attempts')) {
    /**
     * Abonnements Stripe du client de l'association qui n'ont pas (encore) de ligne locale : premier
     * paiement en attente (« incomplete », prélèvement SEPA en cours de vérification pendant plusieurs
     * jours) ou payé sans être encore connu ici. Fermer l'espace les arrête aussi : sinon le prélèvement
     * confirmé plus tard démarrerait un abonnement mensuel sur un espace fermé. Une erreur de lecture
     * (Stripe non configuré, client de l'autre mode) ne bloque pas la fermeture : le webhook arrête de
     * toute façon un abonnement payé pour une association fermée (ak_stripe_apply_subscription).
     */
    function sa_stripe_cancel_pending_attempts(PDO $pdo, int $org_id, array &$out): void {
        if (!isset(ak_db_columns($pdo, 'organizations')['stripe_customer_id'])) return;
        try {
            $q = $pdo->prepare("SELECT stripe_customer_id FROM organizations WHERE id = ?");
            $q->execute([$org_id]);
            $cid = trim((string)$q->fetchColumn());
        } catch (Throwable $e) {
            return;
        }
        if ($cid === '') return;
        if (!function_exists('ak_stripe_api_call') && is_file(__DIR__ . '/stripe-helpers.php')) require_once __DIR__ . '/stripe-helpers.php';
        if (!function_exists('ak_stripe_api_call') || !function_exists('ak_stripe_get_secret_key') || !ak_stripe_get_secret_key($pdo)) return;
        $known = array_values(sa_stripe_live_subs($pdo, $org_id));
        $r = ak_stripe_api_call($pdo, 'GET', '/subscriptions', ['customer' => $cid, 'status' => 'all', 'limit' => 20]);
        if (empty($r['ok'])) {
            error_log('[sa_stripe_cancel_pending_attempts] org #' . $org_id . ' : ' . ($r['error'] ?? '?'));
            return;
        }
        foreach (($r['data']['data'] ?? []) as $s) {
            $sid = (string)($s['id'] ?? '');
            if ($sid === '' || in_array($sid, $known, true)) continue;   // lignes locales : traitées par l'appelant
            if (!in_array((string)($s['status'] ?? ''), ['incomplete', 'active', 'trialing', 'past_due', 'unpaid'], true)) continue;
            $d = ak_stripe_api_call($pdo, 'DELETE', '/subscriptions/' . rawurlencode($sid));
            if (!empty($d['ok']) || (int)($d['http_code'] ?? 0) === 404) $out['cancelled'][] = $sid;
            else $out['failed'][$sid] = trim((string)($d['error'] ?? '')) !== '' ? (string)$d['error'] : 'refus de Stripe';
        }
    }
}

if (!function_exists('sa_stripe_cancel_for_org')) {
    /**
     * Association résiliée ou supprimée depuis le cockpit (ou l'appli) : ses abonnements Stripe ne
     * doivent plus prélever le client. Chacun est annulé tout de suite dans Stripe
     * (DELETE /v1/subscriptions/{id} ; un abonnement qui n'existe plus chez Stripe compte comme
     * annulé), puis sa ligne passe en « cancelled » avec cancelled_at.
     * Stripe non configuré ou refus : la ligne n'est PAS touchée. Elle reste « vivante » pour que
     * la fiche continue d'avertir le Fondateur (sa_stripe_live_subs) jusqu'à l'annulation.
     * $only_mark : ne pas appeler Stripe, seulement noter l'annulation faite à la main dans Stripe.
     * @return array{cancelled: string[], failed: array<string, string>} identifiants annulés / en échec => raison
     */
    function sa_stripe_cancel_for_org(PDO $pdo, int $org_id, bool $only_mark = false): array {
        $out = ['cancelled' => [], 'failed' => []];
        if (!$only_mark) sa_stripe_cancel_pending_attempts($pdo, $org_id, $out);
        $subs = sa_stripe_live_subs($pdo, $org_id);
        if (!$subs) return $out;
        if (!$only_mark && !function_exists('ak_stripe_api_call') && is_file(__DIR__ . '/stripe-helpers.php')) {
            require_once __DIR__ . '/stripe-helpers.php';
        }
        foreach ($subs as $row_id => $sid) {
            if (!$only_mark) {
                if (!function_exists('ak_stripe_api_call')) { $out['failed'][$sid] = 'Stripe n’est pas configuré sur ce serveur'; continue; }
                $r = ak_stripe_api_call($pdo, 'DELETE', '/subscriptions/' . rawurlencode($sid));
                $gone = empty($r['ok']) && (int)($r['http_code'] ?? 0) === 404;
                if (empty($r['ok']) && !$gone) {
                    $out['failed'][$sid] = trim((string)($r['error'] ?? '')) !== '' ? (string)$r['error'] : 'refus de Stripe';
                    continue;
                }
            }
            try {
                ak_db_update($pdo, 'asso_subscriptions', (int)$row_id, ['status' => 'cancelled'],
                             ['cancelled_at' => 'NOW()', 'updated_at' => 'NOW()']);
                $out['cancelled'][] = $sid;
            } catch (Throwable $e) {
                error_log('[sa_stripe_cancel_for_org] org #' . $org_id . ' : ' . get_class($e) . ' ' . $e->getMessage());
                // Annulé chez Stripe : le webhook customer.subscription.deleted mettra la ligne à jour.
                // Simple note sans appel à Stripe : rien n'a changé.
                if ($only_mark) $out['failed'][$sid] = 'erreur technique, rien n’a été noté';
                else $out['cancelled'][] = $sid;
            }
        }
        return $out;
    }
}

if (!function_exists('sa_stripe_cancelled_note')) {
    /** Fin du message de succès quand un ou des abonnements Stripe ont été annulés (« » sinon). */
    function sa_stripe_cancelled_note(array $sids): string {
        if (!$sids) return '';
        return count($sids) > 1
            ? ' Abonnements Stripe ' . implode(', ', $sids) . ' annulés : la carte du client n’est plus prélevée.'
            : ' Abonnement Stripe ' . $sids[0] . ' annulé : la carte du client n’est plus prélevée.';
    }
}

if (!function_exists('sa_stripe_still_billing_message')) {
    /** Avertissement : abonnement(s) Stripe toujours actif(s) sur une association résiliée ou supprimée. */
    function sa_stripe_still_billing_message(array $failed): string {
        $parts = [];
        foreach ($failed as $sid => $why) $parts[] = $sid . ($why !== '' ? ' (' . $why . ')' : '');
        return (count($parts) > 1 ? 'Abonnements Stripe ' . implode(', ', $parts) . ' toujours actifs' : 'Abonnement Stripe ' . $parts[0] . ' toujours actif')
             . ' : l’association n’a plus accès à Assokit, mais Stripe continuera de prélever la carte du client tant que '
             . (count($parts) > 1 ? 'ces abonnements ne sont pas annulés' : 'cet abonnement n’est pas annulé')
             . ' dans le tableau de bord Stripe (Abonnements).';
    }
}
