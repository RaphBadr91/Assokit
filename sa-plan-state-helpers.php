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
        // Ancien montant mensuel des comptes historiques sans ligne d'abonnement (libellé et MRR)
        $extra[] = function_exists('ak_org_monthly_price_col') ? ak_org_monthly_price_col($pdo)
                 : (isset(ak_db_columns($pdo, 'organizations')['monthly_price_cents']) ? 'o.monthly_price_cents' : 'NULL') . ' AS monthly_price_cents';
        // Compte historique facturé par le renouvellement ? (ni démo, échéance renseignée : sa_plan_state_mrr_cents)
        if (function_exists('ak_org_legacy_bill_cols')) $extra[] = ak_org_legacy_bill_cols($pdo);
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
        if ($st['badge_tone'] !== 'active') return 0;
        // Compte historique sans ligne d'abonnement, rattaché à une formule payante (ak_legacy_plan_row) :
        // son ancien montant mensuel (organizations.monthly_price_cents), seulement s'il est facturé par le
        // renouvellement (cron-job-renouvellements : ni démo « demo-… », échéance renseignée)
        if (empty($st['plan_id'])) {
            return (string)($st['legacy_slug'] ?? '') !== '' && in_array((string)$st['org_status'], ['active', ''], true)
                && ($st['legacy_billed'] ?? true) !== false
                ? max(0, (int)($st['legacy_cents'] ?? 0)) : 0;
        }
        if ($st['sub_status'] !== 'active') return 0;
        if (($st['payment_mode'] ?? null) === 'free_grant') return 0;
        return max(0, (int)$st['price_cents']);
    }
}

if (!function_exists('sa_mrr_by_plan')) {
    /**
     * MRR par formule (centimes HT) à partir de sa_org_plan_states() : une ligne par formule payante
     * ['key', 'label', 'count', 'mrr_cents'], triée par revenu, puis à part (sans revenu) les essais en
     * cours ou terminés (« trial »), les Sur-mesure sur devis pas encore chiffré (« devis ») et les formules
     * offertes ou gratuites (« offert »).
     * Les lignes « sur-mesure-<client> » sont regroupées sous Sur-mesure ; les comptes historiques sous
     * leur formule équivalente (« … (ancien compte) »).
     * @return array{rows: array, trial: array, devis: array, offert: array, total_cents: int, paying: int}
     */
    function sa_mrr_by_plan(array $states): array {
        $rows = [];
        $trial = ['key' => 'trial', 'label' => 'Essais (en cours ou terminés)', 'count' => 0, 'mrr_cents' => 0];
        $devis = ['key' => 'devis', 'label' => 'Sur-mesure, devis pas encore chiffré', 'count' => 0, 'mrr_cents' => 0];
        $offert = ['key' => 'offert', 'label' => 'Offert ou gratuit', 'count' => 0, 'mrr_cents' => 0];
        $total = 0; $paying = 0;
        foreach ($states as $st) {
            if (!empty($st['is_trial']) || !empty($st['trial_expired'])) {
                if (in_array($st['badge_tone'], ['trial', 'expired'], true)) $trial['count']++;
                continue;
            }
            if (($st['badge_tone'] ?? '') !== 'active') continue;
            $cents = sa_plan_state_mrr_cents($st);
            if ($cents <= 0) {
                $sm_slug = !empty($st['plan_id']) ? (string)($st['plan_slug'] ?? '') : (string)($st['legacy_slug'] ?? '');
                $free_grant = ($st['payment_mode'] ?? null) === 'free_grant';
                if (!$free_grant && strpos($sm_slug, 'sur-mesure') === 0) $devis['count']++; else $offert['count']++;
                continue;
            }
            if (empty($st['plan_id'])) {
                $key = 'ancien:' . (string)$st['legacy_slug'];
                $label = (string)$st['plan_label'];
            } else {
                $slug = (string)$st['plan_slug'];
                $key = strpos($slug, 'sur-mesure') === 0 ? 'sur-mesure' : $slug;
                $label = $key === 'sur-mesure' ? 'Sur-mesure' : (string)$st['plan_label'];
            }
            if (!isset($rows[$key])) $rows[$key] = ['key' => $key, 'label' => $label, 'count' => 0, 'mrr_cents' => 0];
            $rows[$key]['count']++;
            $rows[$key]['mrr_cents'] += $cents;
            $total += $cents;
            $paying++;
        }
        $rows = array_values($rows);
        usort($rows, fn($a, $b) => [$b['mrr_cents'], $b['count']] <=> [$a['mrr_cents'], $a['count']]);
        return ['rows' => $rows, 'trial' => $trial, 'devis' => $devis, 'offert' => $offert, 'total_cents' => $total, 'paying' => $paying];
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
    /**
     * Prix lisible d'un plan (asso_plans.price_cents est HT) : « 29,99 € HT/mois », « Gratuit », « Sur devis ».
     * Un devis Sur-mesure chiffré (ligne « sur-mesure-<client> » à 450 €) affiche son prix ; « Sur devis »
     * seulement à 0 € (Sur-mesure, ou toute ligne sur devis).
     */
    function sa_plan_price_label(int $price_cents, string $slug = '', bool $is_trial = false, bool $is_custom_quote = false): string {
        if ($is_trial) return 'Gratuit pendant l’essai';
        if ($price_cents > 0) return number_format($price_cents / 100, 2, ',', "\u{00A0}") . "\u{00A0}€ HT/mois";
        return ($is_custom_quote || strpos($slug, 'sur-mesure') === 0) ? 'Sur devis' : 'Gratuit';
    }
}

if (!function_exists('sa_plan_state_price')) {
    /**
     * Prix de l'état courant : 0 € pendant un essai (en cours ou terminé), sinon le prix du plan.
     * Sans ligne asso_subscriptions (ancien compte), il n'y a pas de prix connu.
     */
    function sa_plan_state_price(array $st): string {
        if ($st['is_trial'] || $st['trial_expired']) return '0 € (essai gratuit)';
        if (empty($st['plan_id'])) {
            return (string)($st['legacy_slug'] ?? '') !== '' && (int)($st['legacy_cents'] ?? 0) > 0
                ? sa_plan_price_label((int)$st['legacy_cents']) . ' (ancien tarif, sans abonnement)'
                : 'Aucun abonnement (ancien plan)';
        }
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
        $paid = (int) $after['price_cents'] > 0 || strpos((string) $after['plan_slug'], 'sur-mesure') === 0;
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

if (!function_exists('sa_pro_grid_pending')) {
    /**
     * Ligne « assokit » pas encore passée à la grille (ancienne offre à 49,99 €, migration de la grille à
     * relancer) : ak_pc_plan_grid_pending si stripe-plan-change-helpers.php est chargé, sinon même règle
     * sur la colonne grille_version (absente : en attente). Les autres lignes : false.
     */
    function sa_pro_grid_pending(PDO $pdo, array $p): bool {
        if (($p['slug'] ?? '') !== 'assokit') return false;
        if (function_exists('ak_pc_plan_grid_pending')) return ak_pc_plan_grid_pending($pdo, $p);
        $v = defined('AK_GRID_VERSION') ? (int)AK_GRID_VERSION : 202611;
        return !array_key_exists('grille_version', $p) || (int)$p['grille_version'] < $v;
    }
}

if (!function_exists('sa_plans_list')) {
    /**
     * Plans réels (asso_plans) pour les listes déroulantes, dans l'ordre d'affichage :
     * [['id', 'slug', 'label', 'price_cents', 'is_trial', 'is_visible', 'is_custom_quote', 'client_specific', 'option']].
     * « option » = libellé prêt à afficher : « Pro — 29,99 € HT/mois », « Sur-mesure — sur devis »,
     * « Offre fondateur — 29,99 € HT/mois · garantie 12 mois ».
     * client_specific : ligne « sur-mesure-<client> » (devis d'une seule association, sa_custom_quote_plan) ;
     * sa_plans_for_org() ne la propose qu'à cette association.
     */
    function sa_plans_list(PDO $pdo): array {
        $cols = ak_db_columns($pdo, 'asso_plans');
        $sel = "id, slug, name, COALESCE(price_cents, 0) AS price_cents"
             . (isset($cols['is_trial']) ? ", COALESCE(is_trial, 0) AS is_trial" : ", 0 AS is_trial")
             . (isset($cols['is_visible']) ? ", COALESCE(is_visible, 1) AS is_visible" : ", 1 AS is_visible")
             . (isset($cols['is_custom_quote']) ? ", COALESCE(is_custom_quote, 0) AS is_custom_quote" : ", 0 AS is_custom_quote")
             . (isset($cols['grille_version']) ? ", grille_version" : "");
        $order = isset($cols['display_order']) ? "display_order ASC, id ASC" : "id ASC";
        $out = [];
        try {
            foreach ($pdo->query("SELECT $sel FROM asso_plans ORDER BY $order")->fetchAll(PDO::FETCH_ASSOC) as $p) {
                $slug = (string)$p['slug'];
                $is_trial = !empty($p['is_trial']) || $slug === 'pro-essai';
                $quote = !empty($p['is_custom_quote']);
                $client = strpos($slug, 'sur-mesure-') === 0;
                $label = ak_plan_label($slug, $p['name']);
                if ($is_trial) $price = 'essai gratuit de ' . (int)AK_TRIAL_DAYS . ' jours';
                elseif ((int)$p['price_cents'] > 0) $price = sa_plan_price_label((int)$p['price_cents'], $slug, false, $quote);
                else $price = ($quote || strpos($slug, 'sur-mesure') === 0) ? 'sur devis' : ($slug === 'demarrage' ? 'gratuit, accès limité' : 'gratuit');
                $suffix = '';
                if ($slug === 'fondateur') $suffix = ' · garantie 12 mois';
                elseif ($client) $suffix = ' · devis de ce client';
                elseif (!$is_trial && empty($p['is_visible'])) $suffix = ' (masqué)';
                // Période de transition : l'ancienne offre n'est pas présentée comme le Pro de la page Tarifs
                if (sa_pro_grid_pending($pdo, $p)) { $price = 'en attente, ancienne offre ' . number_format((int)$p['price_cents'] / 100, 2, ',', "\u{00A0}") . "\u{00A0}€"; $suffix = ''; }
                $out[] = [
                    'id' => (int)$p['id'], 'slug' => $slug, 'label' => $label,
                    'price_cents' => (int)$p['price_cents'], 'is_trial' => $is_trial, 'is_visible' => !empty($p['is_visible']),
                    'is_custom_quote' => $quote, 'client_specific' => $client,
                    'option' => $label . ' — ' . $price . $suffix,
                ];
            }
        } catch (Throwable $e) {
            error_log('[sa_plans_list] ' . get_class($e));
        }
        return $out;
    }
}

if (!function_exists('sa_plans_for_org')) {
    /**
     * Liste de sa_plans_list() pour le sélecteur de formule d'UNE association : les lignes
     * « sur-mesure-<client> » des autres associations sont retirées (seule la sienne, sa formule actuelle, reste).
     */
    function sa_plans_for_org(array $plans, ?int $current_plan_id = null): array {
        return array_values(array_filter($plans, fn($p) => empty($p['client_specific']) || (int)$p['id'] === (int)$current_plan_id));
    }
}

if (!function_exists('sa_is_custom_quote_slug')) {
    /** Slug Sur-mesure (« sur-mesure » ou « sur-mesure-<client> ») : le champ « Montant du devis » s'applique. */
    function sa_is_custom_quote_slug(string $slug): bool {
        return $slug === 'sur-mesure' || strpos($slug, 'sur-mesure-') === 0;
    }
}

if (!function_exists('sa_quote_cents_from_input')) {
    /**
     * Montant du devis saisi (« 450 », « 450,00 », « 1 250,50 ») en centimes HT ; 0 si vide ; null si invalide.
     */
    function sa_quote_cents_from_input($raw): ?int {
        $v = str_replace(["\u{00A0}", "\u{202F}", ' ', '€'], '', trim((string)$raw));
        if ($v === '') return 0;
        $v = str_replace(',', '.', $v);
        if (!preg_match('/^\d{1,6}(\.\d{1,2})?$/', $v)) return null;
        return (int)round((float)$v * 100);
    }
}

if (!function_exists('sa_custom_quote_plan')) {
    /**
     * Devis Sur-mesure d'une association : crée (ou met à jour) la ligne asso_plans « sur-mesure-<slug du
     * client> », sur devis (is_custom_quote = 1), jamais proposée à la souscription (is_visible = 0), au prix
     * du devis ($cents HT/mois). À la création, limites et fonctions sont copiées de la ligne « sur-mesure » ;
     * ensuite, seul le prix change (les réglages faits pour ce client dans Formules sont gardés).
     * N'attribue PAS la formule : l'appelant passe l'id rendu à ak_org_set_plan().
     * @throws InvalidArgumentException montant invalide ; RuntimeException association ou ligne Sur-mesure absente.
     */
    function sa_custom_quote_plan(PDO $pdo, int $org_id, int $cents): int {
        if ($cents <= 0 || $cents > 100000000) throw new InvalidArgumentException('Montant du devis invalide.');
        $o = $pdo->prepare("SELECT id, slug, name FROM organizations WHERE id = ?");
        $o->execute([$org_id]);
        $org = $o->fetch(PDO::FETCH_ASSOC);
        if (!$org) throw new RuntimeException('Association introuvable.');
        $cslug = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower((string)$org['slug'])), '-');
        if ($cslug === '') $cslug = 'asso-' . (int)$org_id;
        $slug = substr('sur-mesure-' . $cslug, 0, 120);
        $cols = ak_db_columns($pdo, 'asso_plans');
        $find = $pdo->prepare("SELECT * FROM asso_plans WHERE slug = ? LIMIT 1");
        $find->execute([$slug]);
        $row = $find->fetch(PDO::FETCH_ASSOC);
        // Deux slugs d'association peuvent donner le même slug de devis (« LotB_Equipe » et « lotb-equipe ») :
        // une ligne déjà attribuée à une AUTRE association n'est jamais reprise (son prix changerait),
        // celle-ci reçoit alors « sur-mesure-<slug>-<id> »
        if ($row) {
            $other = $pdo->prepare("SELECT COUNT(*) FROM asso_subscriptions WHERE plan_id = ? AND org_id <> ?");
            $other->execute([(int)$row['id'], $org_id]);
            $taken = (int)$other->fetchColumn() > 0;
            if (!$taken) {
                $other = $pdo->prepare("SELECT COUNT(*) FROM organizations WHERE plan = ? AND id <> ?");
                $other->execute([$slug, $org_id]);
                $taken = (int)$other->fetchColumn() > 0;
            }
            if ($taken) {
                $slug = substr('sur-mesure-' . $cslug, 0, 108) . '-' . (int)$org_id;
                $find->execute([$slug]);
                $row = $find->fetch(PDO::FETCH_ASSOC);
            }
        }
        if ($row) {
            $data = ['price_cents' => $cents];
            if (isset($cols['is_custom_quote'])) $data['is_custom_quote'] = 1;
            if (isset($cols['is_visible'])) $data['is_visible'] = 0;
            ak_db_update($pdo, 'asso_plans', (int)$row['id'], $data, isset($cols['updated_at']) ? ['updated_at' => 'NOW()'] : []);
            return (int)$row['id'];
        }
        $find->execute(['sur-mesure']);
        $base = $find->fetch(PDO::FETCH_ASSOC);
        if (!$base) throw new RuntimeException('Formule Sur-mesure absente : passez d’abord la grille tarifaire en base (migrations/2026-11-09-grille-tarifaire.sql).');
        $data = $base;
        foreach (['id', 'created_at', 'updated_at', 'stripe_price_id_monthly', 'stripe_price_id_yearly', 'stripe_product_id'] as $k) unset($data[$k]);
        $data['slug'] = $slug;
        $data['name'] = 'Sur-mesure';
        $data['price_cents'] = $cents;
        if (isset($cols['is_custom_quote'])) $data['is_custom_quote'] = 1;
        if (isset($cols['is_visible'])) $data['is_visible'] = 0;
        if (isset($cols['is_featured'])) $data['is_featured'] = 0;
        if (isset($cols['is_trial'])) $data['is_trial'] = 0;
        if (isset($cols['price_label'])) $data['price_label'] = null;
        if (isset($cols['display_order'])) $data['display_order'] = (int)($base['display_order'] ?? 0) + 50;
        return ak_db_insert($pdo, 'asso_plans', $data, isset($cols['created_at']) ? ['created_at' => 'NOW()'] : []);
    }
}

if (!function_exists('sa_plan_usage_lines')) {
    /**
     * Usage du mois face aux limites de la formule courante, en LECTURE seule (ak_get_usage, plan-helpers.php,
     * et les limit_* de la ligne asso_plans $ps['plan_id'], ou de la formule équivalente d'un compte historique) :
     * membres (bureau compris), gestionnaires, crédits IA du mois, e-mails groupés du mois.
     * Chaque ligne : ['key', 'label', 'used', 'limit' (null = sans plafond, 0 = non inclus), 'pct' (null),
     * 'warn' (≥ 90 %), 'text' (« 47/50 membres », « 12 crédits IA (sans plafond) », « 0 e-mail (non inclus) »)].
     * $ps : un élément de sa_org_plan_states() / ak_org_plan_state() ; il doit porter org_id ou être passé avec.
     */
    function sa_plan_usage_lines(PDO $pdo, array $ps, ?int $org_id = null): array {
        $org_id = (int)($org_id ?? ($ps['org_id'] ?? 0));
        if (!function_exists('ak_get_usage') && is_file(__DIR__ . '/plan-helpers.php')) require_once __DIR__ . '/plan-helpers.php';
        if ($org_id <= 0 || !function_exists('ak_get_usage')) return [];
        $usage = ak_get_usage($pdo, $org_id);
        $plan = [];
        $cols = ak_db_columns($pdo, 'asso_plans');
        $keys = array_values(array_filter(['limit_adherents', 'limit_managers', 'limit_ai_text_per_month', 'limit_emails_per_month'], fn($k) => isset($cols[$k])));
        try {
            if ($keys) {
                if (!empty($ps['plan_id'])) {
                    $q = $pdo->prepare("SELECT slug, " . implode(', ', $keys) . " FROM asso_plans WHERE id = ?");
                    $q->execute([(int)$ps['plan_id']]);
                    $plan = $q->fetch(PDO::FETCH_ASSOC) ?: [];
                } elseif (empty($ps['is_trial']) && empty($ps['trial_expired'])) {
                    // Compte historique : sa formule équivalente, Démarrage si rien de payant n'est rattaché.
                    // Ligne équivalente absente (Sur-mesure avant la grille) : même repli qu'ak_legacy_plan_row
                    // (ancien montant payant → Pro), pour montrer les limites que l'association a vraiment.
                    $q = $pdo->prepare("SELECT slug, " . implode(', ', $keys) . " FROM asso_plans WHERE slug = ? LIMIT 1");
                    $try = (string)($ps['legacy_slug'] ?? '') !== '' ? [(string)$ps['legacy_slug']] : ['demarrage'];
                    if ((string)($ps['legacy_slug'] ?? '') !== '' && (int)($ps['legacy_cents'] ?? 0) > 0) $try[] = 'assokit';
                    foreach ($try as $tslug) {
                        $q->execute([$tslug]);
                        if ($plan = $q->fetch(PDO::FETCH_ASSOC) ?: []) break;
                    }
                }
            }
        } catch (Throwable $e) {
            $plan = [];
        }
        if ($plan && function_exists('ak_plan_demarrage_overlay')) $plan = ak_plan_demarrage_overlay($plan);
        $spec = [
            ['members', 'adherents', 'limit_adherents', 'membre', 'membres'],
            ['managers', 'managers', 'limit_managers', 'gestionnaire', 'gestionnaires'],
            ['ai', 'ai_text_month', 'limit_ai_text_per_month', 'crédit IA', 'crédits IA'],
            ['emails', 'emails_month', 'limit_emails_per_month', 'e-mail', 'e-mails'],
        ];
        $fmt = fn($n) => number_format((int)$n, 0, ',', "\u{00A0}");
        $out = [];
        foreach ($spec as [$key, $ukey, $lkey, $one, $many]) {
            $used = (int)($usage[$ukey] ?? 0);
            $raw = $plan[$lkey] ?? null;
            $limit = ($raw === null || $raw === '') ? null : (int)$raw;
            $word = max($used, (int)$limit) > 1 ? $many : $one;
            $pct = $limit !== null && $limit > 0 ? (int)round($used / $limit * 100) : null;
            if ($limit === null) $text = $fmt($used) . ' ' . $word . ' (sans plafond)';
            elseif ($limit === 0) $text = $fmt($used) . ' ' . $word . ' (non inclus)';
            else $text = $fmt($used) . '/' . $fmt($limit) . ' ' . $word;
            $out[] = ['key' => $key, 'label' => $many, 'used' => $used, 'limit' => $limit, 'pct' => $pct,
                      'warn' => $pct !== null && $pct >= 90, 'text' => $text];
        }
        return $out;
    }
}

if (!function_exists('sa_plan_usage_summary')) {
    /** « 47/50 membres · 3/3 gestionnaires · 12/150 crédits IA · 80/1 000 e-mails » (texte brut). */
    function sa_plan_usage_summary(array $lines): string {
        return implode(' · ', array_column($lines, 'text'));
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
