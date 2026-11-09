<?php
/**
 * stripe-plan-change-helpers.php
 * --------------------------------------------------------------
 * Choix et changement de formule (grille 2026 : Essentiel, Pro, Équipe ; Sur-mesure sur devis),
 * avec ou sans Stripe configuré. Utilisé par mon-asso-plan.php (cartes), stripe-change-plan.php
 * (confirmation et exécution), mon-asso-paiement.php et fondateur-pricing.php.
 *
 * Règles :
 *   - aucune formule à souscrire : la souscription passe par /mon-asso-paiement (pendant l'essai : carte
 *     enregistrée, premier prélèvement à la fin de l'essai ; trial_end, pending_setup_intent) ;
 *   - souscription faite pendant l'essai (abonnement Stripe en période d'essai) : la formule choisie est
 *     remplacée chez Stripe sans prorata (rien n'est prélevé avant la fin de l'essai) ;
 *   - abonnement par carte en cours : montée immédiate au prorata (proration_behavior=create_prorations,
 *     la différence est ajoutée à la prochaine facture) ; baisse à l'échéance (nouveau prix posé chez
 *     Stripe sans prorata, formule actuelle gardée jusqu'à la fin de la période payée : pending_plan_id) ;
 *   - paiement sur facture ou virement : baisse programmée à l'échéance ; montée sur demande au support
 *     (ticket créé, le Fondateur l'applique et ajuste la facture) ;
 *   - formule mise à jour localement par le webhook (stripe-sync-helpers.php, ak_stripe_plan_change_decision).
 * Option domaine personnel : retirée de la vente tant que la fonction n'est pas livrée
 * (ak_addon_domain_offer) ; jamais sur Essentiel, incluse dans les formules qui ont feature_custom_domain.
 * Application mobile : jamais de prix ni de paiement (ak_billing_hidden : pages bloquées).
 * --------------------------------------------------------------
 */

@require_once __DIR__ . '/stripe-config-helpers.php';
@require_once __DIR__ . '/stripe-helpers.php';
require_once __DIR__ . '/trial-helpers.php';
require_once __DIR__ . '/stripe-sync-helpers.php';
if (!function_exists('ak_price_split') && is_file(__DIR__ . '/includes-pricing.php')) require_once __DIR__ . '/includes-pricing.php';
if (!defined('AK_PLAN_NEVER_VISIBLE') && is_file(__DIR__ . '/plan-catalog-helpers.php')) require_once __DIR__ . '/plan-catalog-helpers.php';

if (!function_exists('ak_pc_price_label')) {
    /**
     * « 29,99 € HT/mois », toujours « HT » (même sans TVA) ; « 29,99 € HT/mois (35,99 € TTC) » si une TVA
     * s'applique. Indépendant des pages du site (includes-pricing.php peut évoluer).
     */
    function ak_pc_price_label(int $cents, ?float $vat_rate): string {
        $fmt = function (int $c): string { return number_format($c / 100, 2, ',', "\u{00A0}") . "\u{00A0}€"; };
        $rate = max(0.0, (float)$vat_rate);
        $s = $fmt($cents) . "\u{00A0}HT/mois";
        if ($rate > 0) $s .= ' (' . $fmt($cents + (int)round($cents * $rate / 100)) . "\u{00A0}TTC)";
        return $s;
    }
}

if (!function_exists('ak_pc_vat_note')) {
    /** Note sous les prix : « TVA non applicable (art. 293 B du CGI) : le prix HT est le prix payé. » tant que le taux vaut 0. */
    function ak_pc_vat_note(?float $vat_rate): string {
        if ($vat_rate === null) return 'Prix HT.';
        if ($vat_rate > 0) return 'Prix HT, TVA de ' . rtrim(rtrim(number_format($vat_rate, 2, ',', ''), '0'), ',') . "\u{00A0}% en sus.";
        return 'TVA non applicable (art. 293 B du CGI) : le prix HT est le prix payé.';
    }
}

if (!function_exists('ak_pc_plan_is_quote')) {
    function ak_pc_plan_is_quote(?array $p): bool {
        return $p && (($p['slug'] ?? '') === 'sur-mesure' || !empty($p['is_custom_quote']));
    }
}

if (!function_exists('ak_pc_grid_ready')) {
    /**
     * La grille de novembre 2026 est-elle en base (migrations/2026-11-09-grille-tarifaire.sql passée :
     * colonne grille_version et au moins une ligne marquée) ? Avant, les lignes visibles sont celles de
     * l'ancienne grille, à d'autres prix que /tarifs : rien ne se vend en ligne.
     */
    function ak_pc_grid_ready(PDO $pdo): bool {
        static $memo = [];
        $k = spl_object_id($pdo);
        if (isset($memo[$k])) return $memo[$k];
        $v = defined('AK_GRID_VERSION') ? (int)AK_GRID_VERSION : 202611;
        try {
            $cols = function_exists('ak_db_columns') ? ak_db_columns($pdo, 'asso_plans') : null;
            if (is_array($cols) && $cols && !isset($cols['grille_version'])) return $memo[$k] = false;
            return $memo[$k] = (bool)$pdo->query("SELECT 1 FROM asso_plans WHERE COALESCE(grille_version, 0) >= $v LIMIT 1")->fetchColumn();
        } catch (Throwable $e) {
            return $memo[$k] = false;
        }
    }
}

if (!function_exists('ak_pc_plan_grid_pending')) {
    /**
     * Formule pas encore à son prix de la grille : toute ligne tant que la grille n'est pas en base, puis
     * la ligne assokit (Pro) tant qu'elle n'est pas transformée (étape 6e de la migration, après
     * cli-rattacher-fondateur.php). Elle porte encore l'ancien prix (49,99 € HT) alors que /tarifs, les
     * CGU et les e-mails annoncent 29,99 € HT : elle n'est ni affichée avec un prix, ni vendue.
     */
    function ak_pc_plan_grid_pending(?PDO $pdo, ?array $p): bool {
        if (!$p) return false;
        if (!$pdo) $pdo = (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) ? $GLOBALS['pdo'] : null;
        if (!$pdo) return false;
        if (!ak_pc_grid_ready($pdo)) return true;
        $v = defined('AK_GRID_VERSION') ? (int)AK_GRID_VERSION : 202611;
        return ($p['slug'] ?? '') === 'assokit' && (int)($p['grille_version'] ?? 0) < $v;
    }
}

if (!function_exists('ak_pc_plan_is_sellable')) {
    /**
     * Formule proposée à la souscription en ligne : visible, payante, ni essai, ni sur devis, et à son
     * prix de la grille (ak_pc_plan_grid_pending) : jamais un montant différent de celui de /tarifs.
     * Offre fondateur, essai et accès limité (AK_PLAN_NEVER_VISIBLE) : jamais vendus, même rendus
     * visibles par erreur (l'offre fondateur s'attribue depuis le cockpit, ak_org_set_plan).
     */
    function ak_pc_plan_is_sellable(?array $p): bool {
        if (!$p) return false;
        $never = defined('AK_PLAN_NEVER_VISIBLE') ? AK_PLAN_NEVER_VISIBLE : ['fondateur', 'pro-essai', 'demarrage'];
        if (in_array((string)($p['slug'] ?? ''), $never, true)) return false;
        if (empty($p['is_visible']) || (int)($p['price_cents'] ?? 0) <= 0) return false;
        if (!empty($p['is_trial']) || ($p['slug'] ?? '') === 'pro-essai') return false;
        if (ak_pc_plan_grid_pending(null, $p)) return false;
        return !ak_pc_plan_is_quote($p);
    }
}

if (!function_exists('ak_pc_offer_plans')) {
    /**
     * Formules proposées : ['paid' => lignes vendues en ligne, par prix croissant (Essentiel, Pro, Équipe),
     * 'quote' => ligne Sur-mesure visible ou null, 'pending_pro' => true si Pro n'est pas encore à son prix
     * de la grille (mise en service en cours : la carte Pro s'affiche alors sans prix, « contactez-nous »)].
     * Chaque ligne reçoit 'label' (nom affiché). Seules les lignes de la grille (grille_version) sont
     * vendues : jamais un prix différent de /tarifs.
     */
    function ak_pc_offer_plans(PDO $pdo): array {
        $out = ['paid' => [], 'quote' => null, 'pending_pro' => false];
        try {
            $rows = $pdo->query("SELECT * FROM asso_plans WHERE is_visible = 1 ORDER BY price_cents ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return $out;
        }
        $ready = ak_pc_grid_ready($pdo);
        if (!$ready) $out['pending_pro'] = true;
        foreach ($rows as $p) {
            $p['label'] = ak_plan_label($p['slug'] ?? '', $p['name'] ?? null);
            if (ak_pc_plan_grid_pending($pdo, $p)) {
                if (($p['slug'] ?? '') === 'assokit') $out['pending_pro'] = true;
                continue;
            }
            if (ak_pc_plan_is_sellable($p)) $out['paid'][] = $p;
            elseif (ak_pc_plan_is_quote($p) && !$out['quote'] && ($p['slug'] ?? '') === 'sur-mesure') $out['quote'] = $p;
        }
        return $out;
    }
}

if (!function_exists('ak_pc_plan_by_slug')) {
    function ak_pc_plan_by_slug(PDO $pdo, string $slug): ?array {
        try {
            $q = $pdo->prepare("SELECT * FROM asso_plans WHERE slug = ? LIMIT 1");
            $q->execute([$slug]);
            $p = $q->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (Throwable $e) {
            return null;
        }
        if ($p) $p['label'] = ak_plan_label($p['slug'] ?? '', $p['name'] ?? null);
        return $p;
    }
}

if (!function_exists('ak_addon_domain_offer')) {
    /**
     * Option « domaine personnel » (10 € HT/mois) pour la formule $plan :
     *   'none'     : pas proposée. C'est le cas partout tant que la fonction n'est pas livrée (le domaine
     *                personnel n'est qu'une ébauche : fondateur-domains.php) ; ensuite, jamais sur Essentiel ;
     *   'included' : comprise dans la formule (feature_custom_domain = 1, prévu pour Équipe et Sur-mesure) ;
     *   'addon'    : en option payante (Pro), une fois la fonction livrée (AK_CUSTOM_DOMAIN_LIVE, défini
     *                quand le domaine fonctionne vraiment).
     * Une option déjà souscrite reste facturée et affichée (asso_subscription_addons) : rien n'est retiré.
     */
    function ak_addon_domain_offer(?array $plan): string {
        $live = defined('AK_CUSTOM_DOMAIN_LIVE') && AK_CUSTOM_DOMAIN_LIVE;
        if (!$live || !$plan) return 'none';
        if (!empty($plan['feature_custom_domain'])) return 'included';
        if (($plan['slug'] ?? '') === 'ak-essentiel' || !ak_pc_plan_is_sellable($plan)) return 'none';
        return 'addon';
    }
}

if (!function_exists('ak_pc_usage_blockers')) {
    /**
     * Ce qui empêche de passer à la formule $to (plus petite) : membres (bureau compris) ou gestionnaires
     * au-delà de ses limites. @return string[] phrases à afficher (vide : rien ne bloque)
     */
    function ak_pc_usage_blockers(PDO $pdo, int $org_id, array $to): array {
        $out = [];
        $label = ak_plan_label($to['slug'] ?? '', $to['name'] ?? null);
        $usage = function_exists('ak_get_usage') ? ak_get_usage($pdo, $org_id) : [];
        $fmt = function (int $n): string { return number_format($n, 0, ',', "\u{00A0}"); };
        $lim_m = ($to['limit_adherents'] ?? null);
        if ($lim_m !== null && $lim_m !== '' && (int)$lim_m > 0 && (int)($usage['adherents'] ?? 0) > (int)$lim_m) {
            $out[] = 'Vous avez ' . $fmt((int)$usage['adherents']) . ' membres (bureau compris) : la formule ' . $label . ' en permet ' . $fmt((int)$lim_m) . '.';
        }
        $lim_g = ($to['limit_managers'] ?? null);
        if ($lim_g !== null && $lim_g !== '' && (int)$lim_g >= 0) {
            $n = function_exists('ak_count_managers') ? ak_count_managers($pdo, $org_id) : (int)($usage['managers'] ?? 0);
            if ($n > (int)$lim_g) {
                $out[] = 'Vous avez ' . $fmt($n) . ' gestionnaires (administrateurs, coordinateurs, référents) : la formule ' . $label . ' en permet ' . $fmt((int)$lim_g) . '.';
            }
        }
        return $out;
    }
}

if (!function_exists('ak_pc_price_locked_until')) {
    /** Fin de la garantie de l'offre fondateur (asso_subscriptions.price_locked_until), « JJ/MM/AAAA » ou ''. */
    function ak_pc_price_locked_until(PDO $pdo, int $sub_id): string {
        if ($sub_id <= 0 || !isset(ak_db_columns($pdo, 'asso_subscriptions')['price_locked_until'])) return '';
        try {
            // Seulement tant que la ligne est sur l'offre fondateur (la garantie prend fin en la quittant)
            $q = $pdo->prepare("SELECT s.price_locked_until FROM asso_subscriptions s JOIN asso_plans p ON p.id = s.plan_id
                                WHERE s.id = ? AND s.price_locked_until > NOW() AND p.slug = 'fondateur'");
            $q->execute([$sub_id]);
            $v = (string)$q->fetchColumn();
            return $v !== '' ? date('d/m/Y', strtotime($v)) : '';
        } catch (Throwable $e) {
            return '';
        }
    }
}

if (!function_exists('ak_pc_context')) {
    /**
     * Situation de l'association pour un changement de formule. Clés :
     *   mode : 'subscribe' (aucune formule payante en cours : essai, fin d'essai, accès limité → page de paiement),
     *          'trial_card' (souscrit pendant l'essai, abonnement Stripe en période d'essai),
     *          'stripe' (abonnement par carte en cours), 'manual' (paiement sur facture ou virement),
     *          'support' (offert, compte historique, Sur-mesure, Stripe hors service : sur demande au support),
     *          'blocked' (raison dans 'reason' : retard de paiement, résiliation programmée, prélèvement en cours) ;
     *   cur (ak_get_current_plan), cur_plan_id, cur_price, sub_id, live, trial_card, pending, locked_until_fr,
     *   period_end (échéance de la période payée, 'Y-m-d H:i:s' ou null), stripe_ready.
     */
    function ak_pc_context(PDO $pdo, int $org_id): array {
        $cur = function_exists('ak_get_current_plan') ? ak_get_current_plan($pdo, $org_id) : null;
        $ti = function_exists('ak_plan_trial_info') ? ak_plan_trial_info($pdo, $org_id) : ['in_trial' => false, 'trial_expired' => false, 'trial_paused' => false];
        $live = function_exists('ak_org_live_stripe_subscription') ? ak_org_live_stripe_subscription($pdo, $org_id) : null;
        $trial_card = !$live && function_exists('ak_org_trial_card') ? ak_org_trial_card($pdo, $org_id) : null;
        $stripe_ready = function_exists('ak_stripe_is_configured') && ak_stripe_is_configured($pdo);
        $sub_id = (int)($cur['subscription_id'] ?? 0);
        $ctx = [
            'mode' => 'subscribe', 'reason' => '', 'cur' => $cur, 'cur_plan_id' => (int)($cur['plan_id'] ?? 0),
            'cur_slug' => (string)($cur['slug'] ?? ''), 'cur_price' => (int)($cur['price_cents'] ?? 0),
            'cur_label' => $cur ? ak_plan_label($cur['slug'] ?? '', $cur['name'] ?? null) : '',
            'sub_id' => $sub_id, 'live' => $live, 'trial_card' => $trial_card, 'pending' => null,
            'locked_until_fr' => '', 'period_end' => null, 'stripe_ready' => $stripe_ready, 'trial' => $ti,
        ];
        if ($live) {
            $ctx['sub_id'] = (int)$live['id'];
            // La ligne de l'abonnement par carte fait foi (formule facturée par Stripe)
            $lp = ak_stripe_plan_row($pdo, (int)$live['plan_id']);
            if ($lp) {
                $ctx['cur_plan_id'] = (int)$lp['id']; $ctx['cur_slug'] = (string)$lp['slug'];
                $ctx['cur_price'] = (int)$lp['price_cents']; $ctx['cur_label'] = ak_plan_label($lp['slug'] ?? '', $lp['name'] ?? null);
            }
            $ctx['period_end'] = !empty($live['current_period_end']) ? (string)$live['current_period_end'] : null;
        } elseif ($cur && !empty($cur['current_period_end'])) {
            $ctx['period_end'] = (string)$cur['current_period_end'];
        }
        $ctx['pending'] = $ctx['sub_id'] > 0 ? ak_sub_pending($pdo, $ctx['sub_id']) : null;
        $ctx['locked_until_fr'] = ak_pc_price_locked_until($pdo, $ctx['sub_id']);

        $sepa = function_exists('ak_stripe_payment_pending_until') ? ak_stripe_payment_pending_until($pdo, $org_id) : null;
        if ($sepa) { $ctx['mode'] = 'blocked'; $ctx['reason'] = 'Un prélèvement SEPA est en cours de vérification par votre banque : le changement de formule sera possible dès sa confirmation.'; return $ctx; }
        if ($trial_card) {
            if (!empty($trial_card['charging'])) { $ctx['mode'] = 'blocked'; $ctx['reason'] = 'Votre essai est terminé et le premier prélèvement est en cours : vous pourrez changer de formule dès qu’il aura abouti.'; return $ctx; }
            $ctx['mode'] = $stripe_ready ? 'trial_card' : 'support';
            // Formule choisie à la souscription (prix de l'abonnement Stripe en période d'essai)
            if ($stripe_ready) {
                $err = null;
                $ts = ak_stripe_fetch_subscription($pdo, (string)$trial_card['sub_id'], $err);
                $tp = is_array($ts) ? ak_stripe_plan_row($pdo, (int)(ak_stripe_sub_items($pdo, $ts)['plan_id'] ?? 0)) : null;
                if ($tp) {
                    $ctx['cur_plan_id'] = (int)$tp['id']; $ctx['cur_slug'] = (string)$tp['slug'];
                    $ctx['cur_price'] = (int)$tp['price_cents']; $ctx['cur_label'] = ak_plan_label($tp['slug'] ?? '', $tp['name'] ?? null);
                }
            }
            return $ctx;
        }
        if ($live) {
            if (($live['status'] ?? '') === 'overdue') { $ctx['mode'] = 'blocked'; $ctx['reason'] = 'Le dernier paiement de votre abonnement n’a pas abouti : régularisez-le d’abord, puis changez de formule.'; return $ctx; }
            if (!empty($live['cancel_at_period_end'])) { $ctx['mode'] = 'blocked'; $ctx['reason'] = 'Une résiliation est programmée : pour changer de formule, contactez-nous, nous reprenons votre abonnement sans double prélèvement.'; return $ctx; }
            $ctx['mode'] = $stripe_ready ? 'stripe' : 'support';
            return $ctx;
        }
        // Sans abonnement par carte
        $in_trial = !empty($ti['in_trial']) || !empty($ti['trial_expired']) || !empty($ti['trial_paused']);
        $paid_now = $cur && !$in_trial && empty($cur['is_trial']) && ($cur['slug'] ?? '') !== 'pro-essai'
            && (($cur['status'] ?? '') === 'active') && ((int)($cur['price_cents'] ?? 0) > 0 || ak_pc_plan_is_quote($cur));
        if (!$paid_now) return $ctx;   // essai, fin d'essai, Démarrage, résilié : souscription
        if (!empty($cur['cancel_at_period_end']) || ($cur['status'] ?? '') === 'cancelled') {
            $ctx['mode'] = 'blocked'; $ctx['reason'] = 'Une résiliation est programmée : pour changer de formule, contactez-nous.'; return $ctx;
        }
        $pm = (string)($cur['payment_mode'] ?? 'manual');
        if (ak_pc_plan_is_quote($cur) || $pm === 'free_grant' || empty($cur['subscription_id'])) { $ctx['mode'] = 'support'; return $ctx; }
        $ctx['mode'] = 'manual';
        if (!$ctx['period_end']) {
            try {
                $q = $pdo->prepare("SELECT current_period_end FROM organizations WHERE id = ?");
                $q->execute([$org_id]);
                $e = (string)$q->fetchColumn();
                if ($e !== '') $ctx['period_end'] = strlen($e) <= 10 ? $e . ' 23:59:59' : $e;
            } catch (Throwable $e) {}
        }
        return $ctx;
    }
}

if (!function_exists('ak_pc_kind')) {
    /** 'upgrade' (prix plus élevé : tout de suite) ou 'downgrade' (prix égal ou plus bas : à l'échéance). */
    function ak_pc_kind(int $from_cents, array $to): string {
        return (int)($to['price_cents'] ?? 0) > $from_cents ? 'upgrade' : 'downgrade';
    }
}

if (!function_exists('ak_pc_support_request')) {
    /**
     * Demande de changement de formule transmise au support (paiement sur facture, offert, Sur-mesure, Stripe
     * hors service) : ticket « Facturation » créé quand la table existe, et e-mail au Fondateur. @return int ticket (0 si aucun)
     */
    function ak_pc_support_request(PDO $pdo, int $org_id, array $user, string $from_label, string $to_label, string $detail): int {
        $title = 'Changement de formule : ' . ($from_label !== '' ? $from_label . ' → ' : '') . $to_label;
        $body = 'Demande envoyée depuis « Mon abonnement » : passer à la formule ' . $to_label . ($from_label !== '' ? ' (formule actuelle : ' . $from_label . ')' : '') . '. ' . $detail;
        $ticket = 0;
        try {
            $pdo->beginTransaction();
            // Priorité « normal » (comme support-nouveau.php) ; colonne d'un autre type : valeur par défaut
            try {
                $pdo->prepare("INSERT INTO support_tickets (org_id, created_by_user_id, title, category, priority, status, last_message_at, last_message_by, created_at)
                               VALUES (?, ?, ?, 'billing', 'normal', 'open', NOW(), 'org', NOW())")
                    ->execute([$org_id, (int)($user['id'] ?? 0), mb_substr($title, 0, 250)]);
            } catch (PDOException $e) {
                $pdo->prepare("INSERT INTO support_tickets (org_id, created_by_user_id, title, category, status, last_message_at, last_message_by, created_at)
                               VALUES (?, ?, ?, 'billing', 'open', NOW(), 'org', NOW())")
                    ->execute([$org_id, (int)($user['id'] ?? 0), mb_substr($title, 0, 250)]);
            }
            $ticket = (int)$pdo->lastInsertId();
            $pdo->prepare("INSERT INTO support_messages (ticket_id, author_user_id, author_side, body, read_by_org, created_at) VALUES (?, ?, 'org', ?, 1, NOW())")
                ->execute([$ticket, (int)($user['id'] ?? 0), $body]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $ticket = 0;
            error_log('[plan-change] ticket support org #' . $org_id . ' : ' . get_class($e) . ' ' . $e->getMessage());
        }
        if ($ticket > 0 && is_file(__DIR__ . '/support-helper.php')) {
            try {
                require_once __DIR__ . '/support-helper.php';
                if (function_exists('support_notify_new_message')) support_notify_new_message($ticket, (int)($user['id'] ?? 0), 'org', $body);
            } catch (Throwable $e) { /* la notification est un plus */ }
        }
        ak_stripe_notify_founder($pdo, '🔁 Changement de formule demandé',
            '<p>« ' . ak_stripe_h(ak_stripe_org_name($pdo, $org_id)) . ' » demande à passer à la formule <strong>' . ak_stripe_h($to_label) . '</strong>'
            . ($from_label !== '' ? ' (actuellement ' . ak_stripe_h($from_label) . ')' : '') . '.</p><p>' . ak_stripe_h($detail) . '</p>'
            . '<p>Appliquez-le depuis le cockpit (Associations › la fiche › Plan) et ajustez la prochaine facture.'
            . ($ticket > 0 ? ' Ticket de support n° ' . $ticket . '.' : '') . '</p>', $org_id);
        return $ticket;
    }
}

if (!function_exists('ak_pc_stripe_set_price')) {
    /**
     * Remplace le prix de la formule sur l'abonnement Stripe $sid (élément de la formule, jamais l'option
     * domaine), avec $proration : 'create_prorations' (montée) ou 'none' (baisse à l'échéance, essai).
     * @return array{ok: bool, error: ?string, data: ?array}
     */
    function ak_pc_stripe_set_price(PDO $pdo, string $sid, array $to_plan, string $proration): array {
        $price = trim((string)($to_plan['stripe_price_id_monthly'] ?? ''));
        if ($price === '') return ['ok' => false, 'error' => 'formule sans prix Stripe (« Configurer Stripe automatiquement » à relancer)', 'data' => null];
        if ($why = ak_stripe_price_problem($pdo, $price, (int)$to_plan['price_cents'])) return ['ok' => false, 'error' => 'prix Stripe : ' . $why, 'data' => null];
        $err = null;
        $sub = ak_stripe_fetch_subscription($pdo, $sid, $err);
        if (!is_array($sub)) return ['ok' => false, 'error' => $sub === null ? 'abonnement introuvable chez Stripe' : 'Stripe ne répond pas (' . $err . ')', 'data' => null];
        $items = ak_stripe_sub_items($pdo, $sub);
        if (empty($items['plan_item_id'])) return ['ok' => false, 'error' => 'élément de la formule introuvable sur l’abonnement', 'data' => null];
        if (($items['plan_price_id'] ?? '') === $price) return ['ok' => true, 'error' => null, 'data' => $sub];
        $r = ak_stripe_api_call($pdo, 'POST', '/subscriptions/' . rawurlencode($sid), [
            'items[0][id]' => $items['plan_item_id'],
            'items[0][price]' => $price,
            'proration_behavior' => $proration,
            'metadata[plan_slug]' => (string)$to_plan['slug'],
        ], 'ak-chg-' . $sid . '-' . $price . '-' . $proration . '-' . (int)floor(time() / 600));
        if (empty($r['ok'])) return ['ok' => false, 'error' => (string)($r['error'] ?? 'refus de Stripe'), 'data' => null];
        return ['ok' => true, 'error' => null, 'data' => $r['data']];
    }
}

if (!function_exists('ak_pc_execute')) {
    /**
     * Change la formule de l'association pour $to (ligne asso_plans vendue en ligne). Appelée par
     * stripe-change-plan.php (POST, CSRF vérifié, administrateur). Ne lève pas d'exception.
     * @return array{ok: bool, message: string, redirect: string}
     */
    function ak_pc_execute(PDO $pdo, int $org_id, array $user, array $to): array {
        $back = '/mon-asso-plan';
        $ctx = ak_pc_context($pdo, $org_id);
        $to_label = ak_plan_label($to['slug'] ?? '', $to['name'] ?? null);
        if (!ak_pc_plan_is_sellable($to)) {
            if (ak_pc_plan_is_quote($to)) return ['ok' => false, 'message' => 'Le Sur-mesure est sur devis : écrivez-nous, nous vous recontactons.', 'redirect' => '/contact?plan=sur-mesure'];
            return ['ok' => false, 'message' => 'Cette formule ne se souscrit pas en ligne.', 'redirect' => $back];
        }
        if ($ctx['mode'] === 'blocked') return ['ok' => false, 'message' => $ctx['reason'], 'redirect' => $back];
        if ($ctx['mode'] === 'subscribe') return ['ok' => true, 'message' => '', 'redirect' => '/mon-asso-paiement?plan=' . rawurlencode((string)$to['slug'])];
        $to_id = (int)$to['id'];
        if ($to_id === $ctx['cur_plan_id']) {
            return ['ok' => false, 'message' => 'C’est déjà votre formule.' . ($ctx['pending'] ? ' Pour garder ' . $to_label . ' sans changement, annulez le passage prévu à ' . $ctx['pending']['label'] . '.' : ''), 'redirect' => $back];
        }
        $kind = ak_pc_kind($ctx['cur_price'], $to);
        if ($kind === 'downgrade' && ($block = ak_pc_usage_blockers($pdo, $org_id, $to))) {
            return ['ok' => false, 'message' => implode(' ', $block) . ' Réduisez-les d’abord, ou gardez votre formule.', 'redirect' => $back];
        }
        $from_label = $ctx['cur_label'];

        // Souscription faite pendant l'essai : formule remplacée chez Stripe, rien n'est prélevé avant la fin de l'essai
        if ($ctx['mode'] === 'trial_card') {
            $sid = (string)$ctx['trial_card']['sub_id'];
            $r = ak_pc_stripe_set_price($pdo, $sid, $to, 'none');
            if (!$r['ok']) {
                error_log('[plan-change] org #' . $org_id . ' essai ' . $sid . ' : ' . $r['error']);
                return ['ok' => false, 'message' => 'Le changement de formule n’a pas pu être fait (' . $r['error'] . '). Rien n’a changé ; réessayez ou contactez-nous.', 'redirect' => $back];
            }
            try { ak_stripe_sync_subscription($pdo, $sid, $r['data']); } catch (Throwable $e) { error_log('[plan-change] sync ' . $sid . ' : ' . $e->getMessage()); }
            return ['ok' => true, 'message' => 'C’est noté : votre abonnement commencera avec la formule ' . $to_label . ' à la fin de votre essai'
                . ($ctx['trial_card']['charge_fr'] !== '' ? ', le ' . $ctx['trial_card']['charge_fr'] : '') . '. Rien n’est prélevé d’ici là.', 'redirect' => $back];
        }

        if ($ctx['mode'] === 'stripe') {
            $sid = (string)$ctx['live']['stripe_subscription_id'];
            if ($kind === 'upgrade') {
                $r = ak_pc_stripe_set_price($pdo, $sid, $to, 'create_prorations');
                if (!$r['ok']) {
                    error_log('[plan-change] org #' . $org_id . ' montée ' . $sid . ' : ' . $r['error']);
                    return ['ok' => false, 'message' => 'Le changement de formule n’a pas pu être fait. Rien n’a été prélevé ni changé ; réessayez dans quelques minutes ou contactez-nous.', 'redirect' => $back];
                }
                try { ak_stripe_sync_subscription($pdo, $sid, $r['data']); } catch (Throwable $e) { error_log('[plan-change] sync ' . $sid . ' : ' . $e->getMessage()); }
                return ['ok' => true, 'message' => 'Votre formule est maintenant ' . $to_label . '. Les jours restants de la période en cours sont facturés au prorata sur votre prochaine facture.', 'redirect' => $back];
            }
            // Baisse : notée d'abord (le webhook garde la formule payée), puis nouveau prix chez Stripe sans prorata
            $at = $ctx['period_end'] ?: date('Y-m-d H:i:s', strtotime('+1 month'));
            if (!ak_sub_set_pending($pdo, (int)$ctx['sub_id'], $to_id, $at)) {
                return ['ok' => false, 'message' => 'Le changement de formule n’a pas pu être programmé (base de données à mettre à jour). Contactez-nous.', 'redirect' => $back];
            }
            $r = ak_pc_stripe_set_price($pdo, $sid, $to, 'none');
            if (!$r['ok']) {
                ak_sub_set_pending($pdo, (int)$ctx['sub_id'], null, null);
                error_log('[plan-change] org #' . $org_id . ' baisse ' . $sid . ' : ' . $r['error']);
                return ['ok' => false, 'message' => 'Le changement de formule n’a pas pu être programmé. Rien n’a changé ; réessayez dans quelques minutes ou contactez-nous.', 'redirect' => $back];
            }
            try { ak_stripe_sync_subscription($pdo, $sid, $r['data']); } catch (Throwable $e) { error_log('[plan-change] sync ' . $sid . ' : ' . $e->getMessage()); }
            $at_fr = date('d/m/Y', strtotime($at));
            return ['ok' => true, 'message' => 'C’est noté : vous gardez la formule ' . $from_label . ' jusqu’au ' . $at_fr . ', puis vous passez à ' . $to_label
                . ' (' . ak_pc_price_label((int)$to['price_cents'], ak_vat_applicable_rate($pdo)) . ' à partir de cette échéance). Vous pouvez annuler ce changement d’ici là.', 'redirect' => $back];
        }

        if ($ctx['mode'] === 'manual' && $kind === 'downgrade') {
            $at = $ctx['period_end'] ?: date('Y-m-t 23:59:59');
            if (!ak_sub_set_pending($pdo, (int)$ctx['sub_id'], $to_id, $at)) {
                return ['ok' => false, 'message' => 'Le changement de formule n’a pas pu être programmé. Contactez-nous : nous le faisons pour vous.', 'redirect' => $back];
            }
            ak_stripe_notify_founder($pdo, '🔁 Baisse de formule programmée',
                '<p>« ' . ak_stripe_h(ak_stripe_org_name($pdo, $org_id)) . ' » passera de ' . ak_stripe_h($from_label) . ' à <strong>' . ak_stripe_h($to_label) . '</strong> le '
                . ak_stripe_h(date('d/m/Y', strtotime($at))) . ' (paiement sur facture : appliqué par le renouvellement mensuel).</p>', $org_id);
            return ['ok' => true, 'message' => 'C’est noté : vous gardez la formule ' . $from_label . ' jusqu’au ' . date('d/m/Y', strtotime($at)) . ', puis vous passez à ' . $to_label
                . '. Votre prochaine facture sera de ' . ak_pc_price_label((int)$to['price_cents'], ak_manual_invoice_vat_rate_safe($pdo)) . '. Vous pouvez annuler ce changement d’ici là.', 'redirect' => $back];
        }

        // Montée hors Stripe, ou cas particulier : sur demande au support
        $detail = $ctx['mode'] === 'manual'
            ? 'Abonnement réglé sur facture : montée immédiate demandée, facture à ajuster au prorata.'
            : 'Situation à traiter à la main (' . $ctx['mode'] . ').';
        $ticket = ak_pc_support_request($pdo, $org_id, $user, $from_label, $to_label, $detail);
        return ['ok' => true, 'message' => 'Votre demande de passage à la formule ' . $to_label . ' est transmise au support : nous faisons le changement pour vous et vous écrivons dès qu’il est fait'
            . ($ticket > 0 ? ' (suivi dans Support)' : '') . '. Rien n’est prélevé d’ici là.', 'redirect' => $back];
    }
}

if (!function_exists('ak_manual_invoice_vat_rate_safe')) {
    /** TVA des factures hors Stripe (ak_manual_invoice_vat_rate) ; 0 si indisponible. */
    function ak_manual_invoice_vat_rate_safe(PDO $pdo): float {
        return function_exists('ak_manual_invoice_vat_rate') ? (float)(ak_manual_invoice_vat_rate($pdo) ?? 0.0) : 0.0;
    }
}

if (!function_exists('ak_pc_cancel_pending')) {
    /**
     * Annule la baisse programmée : l'association garde sa formule. Abonnement Stripe : son prix revient à
     * celui de la formule actuelle (sans prorata). @return array{ok: bool, message: string}
     */
    function ak_pc_cancel_pending(PDO $pdo, int $org_id): array {
        $ctx = ak_pc_context($pdo, $org_id);
        if (!$ctx['pending']) return ['ok' => false, 'message' => 'Aucun changement de formule n’est programmé.'];
        if ($ctx['mode'] === 'stripe') {
            $cur = ak_stripe_plan_row($pdo, $ctx['cur_plan_id']);
            if (!$cur) return ['ok' => false, 'message' => 'Formule actuelle introuvable : contactez-nous.'];
            $r = ak_pc_stripe_set_price($pdo, (string)$ctx['live']['stripe_subscription_id'], $cur, 'none');
            if (!$r['ok']) {
                error_log('[plan-change] org #' . $org_id . ' annulation de baisse : ' . $r['error']);
                return ['ok' => false, 'message' => 'L’annulation n’a pas pu être faite. Réessayez dans quelques minutes ou contactez-nous.'];
            }
            ak_sub_set_pending($pdo, $ctx['sub_id'], null, null);
            try { ak_stripe_sync_subscription($pdo, (string)$ctx['live']['stripe_subscription_id'], $r['data']); } catch (Throwable $e) {}
        } else {
            ak_sub_set_pending($pdo, $ctx['sub_id'], null, null);
        }
        return ['ok' => true, 'message' => 'Changement annulé : vous gardez la formule ' . $ctx['cur_label'] . '.'];
    }
}
