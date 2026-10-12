<?php
/**
 * api/_app-founder-plan.php — Plan et essai d'une association, pour les écrans Fondateur de l'app.
 *
 * Une seule vérité : ak_org_plan_state() (trial-helpers.php), la même que le cockpit web,
 * le bandeau d'essai et mon-asso-plan. Ce fichier ne fait que :
 *   - la mettre en forme pour le JSON (fd_plan_fields / fd_org_plan) ;
 *   - compter les associations et calculer le MRR en UNE requête (fd_plan_counts), avec
 *     exactement les règles d'ak_org_plan_state (fd_tone_of en est le miroir : à garder aligné) ;
 *   - lister les plans réels pour le sélecteur de formule (fd_plan_choices).
 * Inclus uniquement par les endpoints api/app-founder*.php (aucune sortie).
 */
if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) { http_response_code(404); exit; }

require_once __DIR__ . '/../trial-helpers.php';
// État de PLUSIEURS associations en une requête (le même calcul que les listes du cockpit web)
if (is_file(__DIR__ . '/../sa-plan-state-helpers.php')) require_once __DIR__ . '/../sa-plan-state-helpers.php';

if (!function_exists('fd_plan_fields')) {
    /** Champs JSON communs (liste, fiche, cockpit) à partir d'ak_org_plan_state(). */
    function fd_plan_fields(array $st): array {
        $end = !empty($st['trial_ends_at']) ? strtotime((string) $st['trial_ends_at']) : false;
        $show_end = $end && ($st['is_trial'] || $st['trial_expired']);
        return [
            'plan_id'       => $st['plan_id'] !== null ? (int) $st['plan_id'] : null,
            'plan_slug'     => (string) $st['plan_slug'],
            'plan_label'    => (string) $st['plan_label'],
            'sub_status'    => (string) $st['sub_status'],
            'is_trial'      => (bool) $st['is_trial'],
            'trial_expired' => (bool) $st['trial_expired'],
            'trial_paused'  => !empty($st['trial_paused']),   // suspendue à la main pendant l'essai (fin encore à venir)
            'trial_ends_at' => $end ? date('Y-m-d H:i:s', $end) : null,
            // Ancien champ (dates d/m/Y) : seulement quand l'essai est en cours ou terminé,
            // pour qu'une association passée sur un plan payant n'affiche plus « essai → … ».
            'trial_ends'    => $show_end ? date('d/m/Y', $end) : '',
            'days_left'     => $st['days_left'] !== null ? (int) $st['days_left'] : null,
            'price_cents'   => (int) $st['price_cents'],
            'badge'         => (string) $st['badge'],
            'badge_tone'    => (string) $st['badge_tone'],
        ];
    }
}

if (!function_exists('fd_org_plan')) {
    function fd_org_plan(PDO $pdo, int $org_id): array {
        return fd_plan_fields(ak_org_plan_state($pdo, $org_id));
    }
}

if (!function_exists('fd_org_plans')) {
    /**
     * fd_org_plan() pour une LISTE d'associations : [org_id => champs], sans 2 requêtes par ligne.
     * sa_org_plan_states() (sa-plan-state-helpers.php, utilisé par les listes du cockpit web) rend
     * exactement ak_org_plan_state() ; sans lui, on retombe sur ak_org_plan_state() ligne à ligne.
     */
    function fd_org_plans(PDO $pdo, array $org_ids): array {
        $ids = array_values(array_unique(array_filter(array_map('intval', $org_ids), fn($i) => $i > 0)));
        $out = [];
        if (!$ids) return $out;
        $states = function_exists('sa_org_plan_states') ? sa_org_plan_states($pdo, $ids) : [];
        foreach ($ids as $id) $out[$id] = fd_plan_fields($states[$id] ?? ak_org_plan_state($pdo, $id));
        return $out;
    }
}

if (!function_exists('fd_live_status')) {
    /**
     * Statut à donner à une association qu'on (ré)active : 'trial' si son plan courant est
     * un essai (la fin d'essai reste gérée par le cron), sinon 'active'. Passer « active »
     * une association en essai lui donnait le plan PRO sans limite de durée.
     * (« Réactiver » passe par ak_org_reactivate(), qui applique la même règle.)
     */
    function fd_live_status(PDO $pdo, array $st): string {
        if (empty($st['is_trial'])) return 'active';
        $cols = ak_db_columns($pdo, 'organizations');
        return (!$cols || ak_db_accepts($pdo, 'organizations', 'status', 'trial')) ? 'trial' : 'active';
    }
}

if (!function_exists('fd_tone_of')) {
    /**
     * Miroir d'ak_org_plan_state() sur une ligne déjà lue (statut org, fin d'essai, ligne
     * d'abonnement courante) : le calcul est ak_plan_state_compute(), le même que la fiche
     * et les listes du cockpit web. Ce qui suit n'ajoute que « payante » (MRR).
     */
    function fd_tone_of(array $r): array {
        $st = ak_plan_state_compute([
            'org_status'             => $r['org_status'] ?? ($r['status'] ?? ''),
            'org_plan'               => $r['org_plan'] ?? '',
            'trial_ends_at'          => $r['trial_ends_at'] ?? null,
            'plan_id'                => $r['plan_id'] ?? null,
            'slug'                   => $r['slug'] ?? '',
            'name'                   => $r['name'] ?? null,
            'is_trial'               => $r['is_trial'] ?? ($r['plan_is_trial'] ?? 0),
            'price_cents'            => $r['price_cents'] ?? 0,
            'sub_status'             => $r['sub_status'] ?? '',
            'stripe_subscription_id' => $r['stripe_subscription_id'] ?? null,
        ]);
        $plan_trial = $st['plan_id'] !== null && (!empty($r['is_trial'] ?? ($r['plan_is_trial'] ?? 0)) || $st['plan_slug'] === 'pro-essai');
        // Payante = abonnement courant actif, sur un plan payant hors essai, association ni en essai,
        // ni en fin d'essai, ni suspendue, ni résiliée.
        $paying = $st['badge_tone'] === 'active' && $st['sub_status'] === 'active' && !$plan_trial && $st['price_cents'] > 0;
        return ['tone' => $st['badge_tone'], 'is_trial' => $st['is_trial'], 'trial_expired' => $st['trial_expired'],
                'trial_paused' => $st['trial_paused'], 'days_left' => $st['days_left'],
                'paying' => $paying, 'price_cents' => $st['plan_id'] !== null ? $st['price_cents'] : 0];
    }
}

if (!function_exists('fd_org_tones')) {
    /** [org_id => fd_tone_of(...)] pour toutes les associations non supprimées, en une requête. */
    function fd_org_tones(PDO $pdo): array {
        $out = [];
        try {
            // Ligne courante = même ordre que ak_get_current_plan / ak_org_plan_state (ak_sub_order).
            $rows = $pdo->query("
                SELECT o.id, o.status AS org_status, o.trial_ends_at, " . ak_sub_state_cols($pdo) . "
                FROM organizations o
                LEFT JOIN asso_subscriptions s ON s.id = (
                    SELECT s2.id FROM asso_subscriptions s2 INNER JOIN asso_plans p2 ON p2.id = s2.plan_id
                    WHERE s2.org_id = o.id
                    ORDER BY " . ak_sub_order('s2') . " LIMIT 1)
                LEFT JOIN asso_plans p ON p.id = s.plan_id
                WHERE o.deleted_at IS NULL")->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            // Tables d'abonnement illisibles : ak_org_plan_state retombe aussi sur organizations seule
            try {
                $rows = $pdo->query("SELECT id, status AS org_status, trial_ends_at, NULL AS sub_status, NULL AS plan_id, 0 AS is_trial, 0 AS price_cents
                                     FROM organizations WHERE deleted_at IS NULL")->fetchAll(PDO::FETCH_ASSOC);
            } catch (Throwable $e2) { $rows = []; }
        }
        foreach ($rows as $r) $out[(int) $r['id']] = fd_tone_of($r);
        return $out;
    }
}

if (!function_exists('fd_plan_counts')) {
    /**
     * Compteurs du cockpit + MRR. Les essais (en cours ou terminés) ne sont JAMAIS des revenus.
     *   active  = ni essai, ni fin d'essai, ni suspendue, ni résiliée (= paying + free + paiement en attente)
     *   paying  = compte dans le MRR ; free = plan gratuit ; trial = essai en cours ; trial_expired = essai terminé
     */
    function fd_plan_counts(PDO $pdo): array {
        $c = ['total' => 0, 'active' => 0, 'paying' => 0, 'free' => 0, 'pending_payment' => 0, 'trial' => 0,
              'trial_expired' => 0, 'expiring' => 0, 'suspended' => 0, 'cancelled' => 0, 'mrr_cents' => 0];
        foreach (fd_org_tones($pdo) as $t) {
            $c['total']++;
            switch ($t['tone']) {
                case 'active':
                    $c['active']++;
                    if ($t['paying']) { $c['paying']++; $c['mrr_cents'] += $t['price_cents']; } else { $c['free']++; }
                    break;
                case 'pending':   $c['active']++; $c['pending_payment']++; break;
                case 'trial':
                    $c['trial']++;
                    if ($t['days_left'] !== null && $t['days_left'] <= 7) $c['expiring']++;
                    break;
                case 'expired':   $c['trial_expired']++; break;
                case 'suspended': $c['suspended']++; break;
                case 'cancelled': $c['cancelled']++; break;
            }
        }
        $c['mrr'] = round($c['mrr_cents'] / 100, 2);
        return $c;
    }
}

if (!function_exists('fd_price_text')) {
    function fd_price_text(array $p): string {
        if (!empty($p['is_trial'])) return 'Essai ' . (int) AK_TRIAL_DAYS . ' j';
        $c = (int) ($p['price_cents'] ?? 0);
        // Même règle que le cockpit web (sa_plan_price_label) : Sur-mesure sans prix = sur devis
        if (!empty($p['is_custom_quote']) || ($c <= 0 && ($p['slug'] ?? '') === 'sur-mesure')) return 'Sur devis';
        return $c > 0 ? number_format($c / 100, 2, ',', "\u{202F}") . "\u{00A0}€/mois" : 'Gratuit';
    }
}

if (!function_exists('fd_plan_choices')) {
    /**
     * Plans proposés au Fondateur pour changer la formule d'une association : plans visibles,
     * plan d'essai (même masqué) et plan actuel de l'association (même masqué).
     */
    function fd_plan_choices(PDO $pdo, ?int $current_plan_id = null): array {
        $cols = ak_db_columns($pdo, 'asso_plans');
        $has_trial = !$cols || isset($cols['is_trial']);
        $has_vis   = !$cols || isset($cols['is_visible']);
        $sel = "id, slug, name, COALESCE(price_cents, 0) AS price_cents, "
             . ($has_trial ? "COALESCE(is_trial, 0)" : "(slug = 'pro-essai')") . " AS is_trial, "
             . ($has_vis ? "COALESCE(is_visible, 1)" : "1") . " AS is_visible, "
             . (isset($cols['is_custom_quote']) ? "COALESCE(is_custom_quote, 0)" : "0") . " AS is_custom_quote";
        $where = [];
        if ($has_vis) {
            $where[] = "is_visible = 1";
            $where[] = $has_trial ? "is_trial = 1" : "slug = 'pro-essai'";
            if ($current_plan_id) $where[] = "id = " . (int) $current_plan_id;
        }
        $order = $has_trial ? "is_trial ASC, " : "";
        $order .= isset($cols['display_order']) ? "display_order ASC, price_cents ASC, id ASC" : "price_cents ASC, id ASC";
        $out = [];
        try {
            $rows = $pdo->query("SELECT $sel FROM asso_plans" . ($where ? " WHERE " . implode(' OR ', $where) : "") . " ORDER BY $order")->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) { $rows = []; }
        foreach ($rows as $p) {
            $out[] = [
                'id'          => (int) $p['id'],
                'slug'        => (string) $p['slug'],
                'name'        => (string) $p['name'],
                'label'       => ak_plan_label($p['slug'], $p['name']),
                'price_cents' => (int) $p['price_cents'],
                'price'       => round(((int) $p['price_cents']) / 100, 2),
                'price_text'  => fd_price_text($p),
                'is_trial'    => !empty($p['is_trial']),
                'is_visible'  => !empty($p['is_visible']),
            ];
        }
        return $out;
    }
}

if (!function_exists('fd_unpaid_summary')) {
    /**
     * Impayés : factures d'abonnement envoyées ou en retard des associations NON supprimées,
     * le même périmètre que le filtre « Impayés » des Associations (app-founder-orgs.php).
     * Partagé par le cockpit, les statistiques et la facturation pour afficher les mêmes chiffres.
     *   nb = factures · orgs = associations concernées · total = montant TTC
     */
    function fd_unpaid_summary(PDO $pdo): array {
        $out = ['nb' => 0, 'orgs' => 0, 'total' => 0.0];
        try {
            $r = $pdo->query("SELECT COUNT(*) AS nb, COUNT(DISTINCT i.org_id) AS orgs, COALESCE(SUM(i.amount_ttc),0) AS total
                              FROM subscription_invoices i
                              JOIN organizations o ON o.id = i.org_id AND o.deleted_at IS NULL
                              WHERE i.status IN ('sent','overdue')")->fetch(PDO::FETCH_ASSOC);
            if ($r) $out = ['nb' => (int) $r['nb'], 'orgs' => (int) $r['orgs'], 'total' => round((float) $r['total'], 2)];
        } catch (Throwable $e) {}
        return $out;
    }
}
