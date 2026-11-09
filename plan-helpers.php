<?php
/**
 * plan-helpers.php
 * --------------------------------------------------------------
 * Système de gestion des plans tarifaires Assokit
 *
 * Helpers principaux :
 *   ak_get_current_plan($pdo, $org_id)          → array plan + subscription
 *   ak_get_usage($pdo, $org_id)                 → array usage actuel
 *   ak_can_create_invoice($pdo, $org_id)        → ['ok'=>bool, 'reason'=>string]
 *   ak_can_create_quote($pdo, $org_id)
 *   ak_can_add_adherent($pdo, $org_id)
 *   ak_can_add_contact($pdo, $org_id)
 *   ak_can_add_user($pdo, $org_id)
 *   ak_can_use_ai_text($pdo, $org_id)
 *   ak_can_use_ai_image($pdo, $org_id)
 *   ak_can_send_email($pdo, $org_id, $count)
 *   ak_can_use_feature($pdo, $org_id, $feature)
 *   ak_render_quota_banner($pdo, $org_id)       → HTML bandeau si limite proche
 *   ak_render_overdue_overlay($pdo, $org_id)    → HTML overlay régularisation
 *
 * Grille de novembre 2026 (Essentiel, Pro, Équipe, Sur-mesure ; section 3b) :
 *   ak_plan_feature_keys() / ak_plan_limit_keys()   colonnes feature_* / limit_* et leurs libellés
 *   ak_plan_by_slug($pdo, $slug)                    ligne asso_plans
 *   ak_plan_has($pdo, $org_id, 'cle')               drapeau feature_* de la formule courante
 *   ak_plan_required_label / ak_feature_gate / ak_render_feature_lock / ak_feature_denied_json
 *   ak_manager_roles / ak_count_managers / ak_can_add_manager / ak_role_change_allowed
 *   ak_plan_is_readonly / ak_readonly_message       Démarrage = accès limité (consultation, export)
 *   ak_ai_credits_used_month / ak_ai_quota_block    crédits IA (registre ai_usage_ledger)
 *   ak_mail_ai_batch_allowed                        lots de tri IA de la boîte mail
 *   ak_emails_sent_month / ak_email_quota_block     e-mails groupés du mois civil
 *   ak_plan_public_summary / ak_plan_badge          phrases sans prix, badge « Le plus choisi »
 *   ak_fondateur_candidates($pdo)                   clients à rattacher à l'offre fondateur
 * Règle d'appel pour les autres fichiers : toujours derrière function_exists() ; une fonction
 * absente ou une erreur laisse passer (comportement d'avant la grille).
 * --------------------------------------------------------------
 */

if (!defined('AK_PLAN_HELPERS_LOADED')) {
    define('AK_PLAN_HELPERS_LOADED', true);

// Auto-load Stripe helpers (silent si absent — Stripe optionnel)
@require_once __DIR__ . '/stripe-config-helpers.php';
@require_once __DIR__ . '/stripe-helpers.php';
// État du plan et de l'essai (ak_org_plan_state, ak_plan_label) : même vérité que le cockpit fondateur
if (is_file(__DIR__ . '/trial-helpers.php')) require_once __DIR__ . '/trial-helpers.php';

/**
 * Nom affiché d'un plan (ligne asso_plans ou ak_get_current_plan) :
 * « Essai Pro » pour le plan d'essai, jamais le slug brut.
 */
function ak_plan_display_name(?array $plan): string {
    if (!$plan) return '—';
    if (function_exists('ak_plan_label')) return ak_plan_label($plan['slug'] ?? '', $plan['name'] ?? null);
    return (string)($plan['name'] ?? ($plan['slug'] ?? '—'));
}

/**
 * Nom de l'essai en cours : « Essai Pro » (plan d'essai), même quand la ligne courante est
 * un paiement Stripe en attente pris pendant l'essai (sinon on lirait « Essai Assokit »).
 * Sans plan d'essai dédié (ak_trial_plan retombe sur la formule payante) : « Essai <formule> ».
 */
function ak_plan_trial_name(PDO $pdo, string $plan_label): string {
    if (stripos($plan_label, 'essai') !== false) return $plan_label;
    $tp = function_exists('ak_trial_plan') ? ak_trial_plan($pdo) : null;
    if ($tp && $tp['is_trial']) {
        $l = ak_plan_label($tp['slug']);
        return stripos($l, 'essai') !== false ? $l : 'Essai ' . $l;
    }
    return ($plan_label === '' || $plan_label === '—') ? 'Essai Pro' : 'Essai ' . $plan_label;
}

/**
 * Essai de l'association, côté association (pages, appli, IA), d'après ak_org_plan_state() :
 *   in_trial       essai EN COURS (ni terminé, ni suspendu)
 *   trial_expired  essai terminé (association suspendue par la fin d'essai ou date dépassée)
 *   trial_paused   essai suspendu à la main par le Fondateur avant sa fin (badge « Essai suspendu »)
 *   trial_ends_at  'Y-m-d H:i:s' — seulement en essai (en cours, suspendu ou terminé), sinon null
 *   days_left      jours restants (même calcul que le cockpit) — mêmes cas
 *   end_fr / end_short  'd/m/Y' / 'd/m' ('' sans date) ; ends_today : la fin tombe aujourd'hui
 *   plan_label, plan_slug, badge, badge_tone : repris tels quels
 *   trial_name     « Essai Pro » pendant l'essai en cours, sinon ''
 *   label          texte unique : « Essai Pro — jusqu’au 23/10 (J-14) », « Essai terminé le 08/10/2026 »,
 *                  « Essai suspendu · fin prévue le 23/10/2026 », ou le nom du plan
 *   trial_subscribed  abonnement souscrit pendant l'essai (carte ou mandat SEPA enregistré) : premier
 *                  prélèvement le first_charge_fr (fin de l'essai), first_charge_cents (TTC), pm_type
 */
function ak_plan_trial_info(PDO $pdo, int $org_id): array {
    $info = ['in_trial' => false, 'trial_expired' => false, 'trial_paused' => false, 'trial_ends_at' => null, 'days_left' => null,
             'end_fr' => '', 'end_short' => '', 'ends_today' => false,
             'plan_label' => '', 'plan_slug' => '', 'badge' => '', 'badge_tone' => 'gray', 'trial_name' => '', 'label' => '',
             'cancel_scheduled' => false, 'access_until' => null, 'access_until_fr' => '',
             'trial_subscribed' => false, 'first_charge_at' => null, 'first_charge_fr' => '', 'first_charge_cents' => null, 'pm_type' => ''];
    if ($org_id <= 0 || !function_exists('ak_org_plan_state')) return $info;
    // Ligne d'abonnement courante garantie (essai ou Démarrage, comme pour les quotas) : sans elle,
    // ak_org_plan_state() retomberait sur l'ancien libellé organizations.plan (« Association »…)
    // alors que les fonctionnalités appliquées sont celles d'ak_get_current_plan().
    $cur = ak_get_current_plan($pdo, $org_id);
    $st = ak_org_plan_state($pdo, $org_id);
    // Compte historique sans ligne (ak_legacy_plan_row) : la formule appliquée, pas l'ancien libellé
    if ($cur && !empty($cur['legacy_plan']) && empty($st['is_trial'])) {
        $st['plan_label'] = ak_plan_display_name($cur);
        $st['plan_slug'] = (string)$cur['slug'];
    }
    $info['trial_expired'] = !empty($st['trial_expired']);
    // Essai suspendu à la main : ni « en cours » (fonctionnalités, achat) ni « terminé », comme le cockpit
    $info['trial_paused']  = !empty($st['trial_paused']) && !$info['trial_expired'];
    $info['in_trial']      = !empty($st['is_trial']) && !$info['trial_expired'] && !$info['trial_paused'];
    $info['plan_label']    = (string)$st['plan_label'];
    $info['plan_slug']     = (string)$st['plan_slug'];
    $info['badge']         = (string)$st['badge'];
    $info['badge_tone']    = (string)$st['badge_tone'];
    // Abonnement souscrit pendant l'essai : premier prélèvement à la fin de l'essai (ak_plan_state_compute)
    if (!empty($st['trial_subscribed'])) {
        $charge = !empty($st['trial_charge_at']) ? strtotime((string)$st['trial_charge_at']) : false;
        $info['trial_subscribed']   = true;
        $info['first_charge_at']    = $charge ? date('Y-m-d H:i:s', $charge) : null;
        $info['first_charge_fr']    = $charge ? date('d/m/Y', $charge) : '';
        $info['first_charge_cents'] = $st['trial_charge_cents'] !== null ? (int)$st['trial_charge_cents'] : null;
        $info['pm_type']            = (string)$st['trial_pm_type'];
    }
    $end = !empty($st['trial_ends_at']) ? strtotime((string)$st['trial_ends_at']) : false;
    if (($info['in_trial'] || $info['trial_expired'] || $info['trial_paused']) && $end) {
        $info['trial_ends_at'] = date('Y-m-d H:i:s', $end);
        $info['end_fr']        = date('d/m/Y', $end);
        $info['end_short']     = date('d/m', $end);
        $info['days_left']     = $st['days_left'] !== null ? (int)$st['days_left'] : null;
        $info['ends_today']    = date('Y-m-d', $end) === date('Y-m-d');
    }
    if ($info['trial_expired']) {
        $info['label'] = 'Essai terminé' . ($info['end_fr'] !== '' ? ' le ' . $info['end_fr'] : '');
    } elseif ($info['trial_paused']) {
        $info['label'] = 'Essai suspendu' . ($info['end_fr'] !== '' ? ' · fin prévue le ' . $info['end_fr'] : '');
    } elseif ($info['in_trial']) {
        $info['trial_name'] = ak_plan_trial_name($pdo, $info['plan_label']);
        $d = $info['days_left'];
        $info['label'] = $info['trial_name']
            . ($info['end_short'] !== '' ? ' — jusqu’au ' . $info['end_short'] : '')
            . ($d !== null ? ' (' . ($d <= 0 ? 'dernier jour' : 'J-' . $d) . ')' : '');
    } else {
        $info['label'] = $info['plan_label'];
        // Résiliation : la formule reste jusqu'à la fin de la période (ak_sub_access_end), puis Démarrage.
        // « Résiliée » (paiement hors Stripe) ou « Résiliation programmée » (Stripe), avec la date, partout.
        if ($cur && (($cur['status'] ?? '') === 'cancelled' || !empty($cur['cancel_at_period_end']))
            && ($cur['slug'] ?? '') !== 'demarrage' && empty($cur['is_trial']) && ($cur['slug'] ?? '') !== 'pro-essai'
            && function_exists('ak_sub_row_access_end')) {
            $until = ak_sub_row_access_end($pdo, $org_id, $cur);
            $info['cancel_scheduled'] = true;
            $info['access_until']     = $until;
            $info['access_until_fr']  = $until ? date('d/m/Y', strtotime($until)) : '';
            $info['badge'] = ($cur['status'] ?? '') === 'cancelled'
                ? 'Résiliée' . ($until ? ' · accès jusqu’au ' . $info['access_until_fr'] : '')
                : 'Résiliation programmée' . ($until ? ' le ' . $info['access_until_fr'] : '');
            $info['badge_tone'] = 'cancelled';
        }
    }
    return $info;
}

/**
 * Annulation d'abonnement refusée pour cause d'essai ? $sub = la ligne qu'on annulerait
 * (ak_get_org_active_subscription ou ak_get_current_plan), null si aucune.
 *   - ligne d'essai (statut 'trial' ou plan d'essai) : oui, rien à annuler (l'annuler la sortait
 *     de la bascule de fin d'essai et l'association gardait le plan d'essai) ;
 *   - abonnement Stripe réel (même souscrit pendant l'essai, paiement en cours) : non, il doit
 *     rester annulable, sinon le client continuerait d'être débité ;
 *   - sinon : oui pendant l'essai, ou après un essai terminé sur un plan gratuit.
 */
function ak_trial_blocks_cancel(PDO $pdo, int $org_id, ?array $sub, ?array $ti = null): bool {
    $ti = $ti ?? ak_plan_trial_info($pdo, $org_id);
    if ($sub) {
        $slug = (string)($sub['plan_slug'] ?? ($sub['slug'] ?? ''));
        $trial_plan = function_exists('ak_trial_plan') ? ak_trial_plan($pdo) : null;
        $plan_id = (int)($sub['plan_id'] ?? 0);
        if (($sub['status'] ?? '') === 'trial' || $slug === 'pro-essai' || !empty($sub['is_trial'])
            || ($trial_plan && $trial_plan['is_trial'] && $plan_id > 0 && $trial_plan['id'] === $plan_id)) {
            return true;
        }
        if (!empty($sub['stripe_subscription_id'])) return false;
    }
    $price = (int)($sub['price_cents_monthly'] ?? ($sub['price_cents'] ?? 0));
    return !empty($ti['in_trial']) || !empty($ti['trial_paused']) || (!empty($ti['trial_expired']) && $price === 0);
}

// ============================================================
// 1. Plan + abonnement actuel
// ============================================================

function ak_get_current_plan(PDO $pdo, int $org_id): ?array {
    static $cache = [];
    if (isset($cache[$org_id])) return $cache[$org_id];

    try {
        // Ligne courante : active, puis l'essai en cours, puis la plus récente (ak_sub_order, trial-helpers.php).
        // Un paiement Stripe en attente ou refusé ne passe jamais devant l'essai.
        $order = function_exists('ak_sub_order') ? ak_sub_order('s') : "(s.status = 'active') DESC, (s.status = 'trial') DESC, s.id DESC";
        // Mode de paiement de la ligne courante (mon-asso-plan) : lu seulement si les colonnes existent
        $scols = function_exists('ak_db_columns') ? ak_db_columns($pdo, 'asso_subscriptions') : [];
        $pay = (isset($scols['payment_mode']) ? ", s.payment_mode" : "")
             . (isset($scols['stripe_subscription_id']) ? ", s.stripe_subscription_id" : "")
             . (isset($scols['cancel_at_period_end']) ? ", COALESCE(s.cancel_at_period_end, 0) AS cancel_at_period_end" : "");
        $st = $pdo->prepare("
            SELECT
              s.id AS subscription_id, s.status, s.started_at, s.current_period_end,
              s.grace_period_end, s.downgraded_at, s.cancelled_at, s.previous_plan_id, s.notes$pay,
              p.*,
              p.id AS plan_id
            FROM asso_subscriptions s
            INNER JOIN asso_plans p ON p.id = s.plan_id
            WHERE s.org_id = :o
            ORDER BY $order
            LIMIT 1
        ");
        $st->execute([':o' => $org_id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);

        // Abonnement résilié dont la période est finie : la formule s'arrête, l'association passe au plan
        // gratuit Démarrage (ak_sub_expire_cancelled, trial-helpers.php) dès cette lecture, cron ou pas.
        if ($row && (($row['status'] ?? '') === 'cancelled' || !empty($row['cancel_at_period_end']))
            && ($row['slug'] ?? '') !== 'demarrage' && empty($row['is_trial']) && ($row['slug'] ?? '') !== 'pro-essai'
            && function_exists('ak_sub_expire_cancelled')) {
            $end = function_exists('ak_sub_access_end') ? ak_sub_access_end($row['current_period_end'] ?? null, null) : null;
            if (($end === null || strtotime($end) <= time()) && ak_sub_expire_cancelled($pdo, $org_id, 1) > 0) {
                $st->execute([':o' => $org_id]);
                $row = $st->fetch(PDO::FETCH_ASSOC);
            }
        }

        if (!$row) {
            // Association en essai sans ligne d'abonnement : on crée la ligne d'ESSAI (jamais
            // un Démarrage, qui la priverait des fonctionnalités de l'essai et contredirait le cockpit)
            if (function_exists('ak_trial_plan') && function_exists('ak_db_insert')) {
                $o = $pdo->prepare("SELECT status, trial_ends_at FROM organizations WHERE id = :o");
                $o->execute([':o' => $org_id]);
                $org = $o->fetch(PDO::FETCH_ASSOC) ?: [];
                $trial = ($org['status'] ?? '') === 'trial' ? ak_trial_plan($pdo) : null;
                if ($trial) {
                    $data = ['org_id' => $org_id, 'plan_id' => $trial['id'],
                             'status' => ak_db_pick($pdo, 'asso_subscriptions', 'status', ['trial', 'active']) ?? 'active'];
                    if (!empty($org['trial_ends_at'])) $data['current_period_end'] = $org['trial_ends_at'];
                    ak_db_insert($pdo, 'asso_subscriptions', $data, ['started_at' => 'NOW()', 'created_at' => 'NOW()', 'updated_at' => 'NOW()']);
                    return ak_get_current_plan($pdo, $org_id);
                }
            }
            // Compte historique (créé avant les abonnements, ancien libellé organizations.plan payant) :
            // il garde la formule équivalente, sans ligne créée, jamais une rétrogradation en Démarrage
            $legacy = ak_legacy_plan_row($pdo, $org_id);
            if ($legacy) {
                $cache[$org_id] = $legacy;
                return $legacy;
            }
            // Pas d'abonnement → on auto-crée un Démarrage
            $defaultPlanId = (int)$pdo->query("SELECT id FROM asso_plans WHERE slug='demarrage' LIMIT 1")->fetchColumn();
            if ($defaultPlanId > 0) {
                $pdo->prepare("INSERT INTO asso_subscriptions (org_id, plan_id, status) VALUES (:o, :p, 'active')")
                    ->execute([':o' => $org_id, ':p' => $defaultPlanId]);
                return ak_get_current_plan($pdo, $org_id); // récursif sécurisé une seule fois
            }
            return null;
        }

        $row = ak_plan_demarrage_overlay($row);
        $cache[$org_id] = $row;
        return $row;
    } catch (Throwable $e) {
        error_log('[ak_get_current_plan] ' . $e->getMessage());
        return null;
    }
}

/**
 * Accès limité de Démarrage en service : le préavis de 30 jours aux comptes concernés est écoulé
 * (cgu.php § 12) et le Fondateur a ajouté define('AK_DEMARRAGE_READONLY_LIVE', true); dans config.php.
 */
function ak_demarrage_restricted(): bool {
    return defined('AK_DEMARRAGE_READONLY_LIVE') && AK_DEMARRAGE_READONLY_LIVE;
}

/**
 * Ligne Démarrage : limites et fonctions de l'accès limité (toutes les fonctions à 0, plafonds de
 * membres, de gestionnaires, d'IA et d'e-mails à 0) appliquées SEULEMENT une fois l'accès limité en
 * service (ak_demarrage_restricted). Avant, la ligne garde exactement ses valeurs d'avant la grille :
 * la migration ne les touche pas, rien ne change sans préavis pour les comptes existants.
 */
function ak_plan_demarrage_overlay(array $row): array {
    if (($row['slug'] ?? '') !== 'demarrage' || !ak_demarrage_restricted()) return $row;
    foreach (array_keys($row) as $k) {
        if (strpos($k, 'feature_') === 0) $row[$k] = 0;
    }
    foreach (['limit_adherents', 'limit_managers', 'limit_ai_text_per_month', 'limit_ai_image_per_month',
              'limit_emails_per_month', 'limit_mail_ai_batches_per_month', 'bonus_ai_first_week'] as $k) {
        if (array_key_exists($k, $row)) $row[$k] = 0;
    }
    return $row;
}

/**
 * Formule d'une association active SANS ligne d'abonnement (compte historique) d'après l'ancien libellé
 * organizations.plan : un plan existant du même slug, sinon « association » / « pro » → Assokit,
 * « organisation » (199 €/mois, Sur-mesure dans les démos) → Sur-mesure, ou un ancien montant mensuel
 * payant → Assokit (« essentiel », l'ancienne offre gratuite, seulement avec un tel montant). Aucune ligne n'est créée : le Fondateur régularise depuis le cockpit (« Plan »),
 * le cron de renouvellement continue de la signaler « à facturer à la main », et rien ne change dans ce
 * qui est facturé. Ligne virtuelle au format d'ak_get_current_plan (subscription_id null, legacy_plan =
 * ancien libellé). null : libellé gratuit, d'essai ou inconnu sans montant, association non active.
 */
function ak_legacy_plan_row(PDO $pdo, int $org_id): ?array {
    try {
        $o = $pdo->prepare("SELECT * FROM organizations WHERE id = :o");
        $o->execute([':o' => $org_id]);
        $org = $o->fetch(PDO::FETCH_ASSOC);
        if (!$org || !in_array((string)($org['status'] ?? ''), ['active', ''], true)) return null;
        $label = strtolower(trim((string)($org['plan'] ?? '')));
        $paid_amount = (int)($org['monthly_price_cents'] ?? 0) > 0;
        if (in_array($label, ['demarrage', 'gratuit', 'free', 'trial', 'essai', 'pro-essai'], true)) return null;
        // « essentiel » = l'offre gratuite (« Essentiel (gratuit) » à la création par le Fondateur, DÉMARRAGE
        // dans les démos) : Démarrage, sauf ancien montant mensuel payant
        if ($label === 'essentiel' && !$paid_amount) return null;
        $map = ['association' => 'assokit', 'pro' => 'assokit', 'organisation' => 'sur-mesure'];
        $trial = function_exists('ak_plan_trial_sql') ? " AND NOT (" . ak_plan_trial_sql($pdo) . ")" : " AND p.slug <> 'pro-essai'";
        $find = $pdo->prepare("SELECT p.* FROM asso_plans p WHERE p.slug = ?$trial LIMIT 1");
        $plan = null;
        foreach (array_filter([$label, $map[$label] ?? null, $paid_amount ? 'assokit' : null]) as $slug) {
            $find->execute([$slug]);
            if ($plan = $find->fetch(PDO::FETCH_ASSOC)) break;
        }
        if (!$plan || $plan['slug'] === 'demarrage') return null;
        return array_merge([
            'subscription_id' => null, 'status' => 'active', 'started_at' => null, 'current_period_end' => null,
            'grace_period_end' => null, 'downgraded_at' => null, 'cancelled_at' => null, 'previous_plan_id' => null,
            'notes' => null, 'payment_mode' => 'manual', 'stripe_subscription_id' => null, 'cancel_at_period_end' => 0,
        ], $plan, ['plan_id' => (int)$plan['id'], 'legacy_plan' => $label]);
    } catch (Throwable $e) {
        error_log('[ak_legacy_plan_row] org #' . $org_id . ' : ' . get_class($e));
        return null;
    }
}

// ============================================================
// 2. Usage actuel d'une organisation
// ============================================================

function ak_get_usage(PDO $pdo, int $org_id): array {
    $usage = [
        'adherents'        => 0,
        'invoices_total'   => 0,
        'quotes_total'     => 0,
        'contacts'         => 0,
        'users'            => 0,
        'ai_text_month'    => 0,
        'ai_image_month'   => 0,
        'emails_month'     => 0,
        'managers'         => 0,
        'mail_ai_batches_month' => 0,
    ];

    try {
        // Adhérents (users actifs liés à l'org via memberships ou table dédiée)
        $st = $pdo->prepare("
            SELECT COUNT(*) FROM users
            WHERE org_id = :o AND deleted_at IS NULL AND is_active = 1
        ");
        $st->execute([':o' => $org_id]);
        $usage['users'] = (int)$st->fetchColumn();

        // Si table asso_adherents existe
        if (ak_table_exists($pdo, 'asso_adherents')) {
            $st = $pdo->prepare("SELECT COUNT(*) FROM asso_adherents WHERE org_id = :o AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00')");
            try { $st->execute([':o' => $org_id]); $usage['adherents'] = (int)$st->fetchColumn(); } catch (Throwable $e) {}
        } else {
            $usage['adherents'] = $usage['users']; // fallback
        }

        // Factures
        $st = $pdo->prepare("SELECT COUNT(*) FROM asso_invoices WHERE org_id = :o");
        $st->execute([':o' => $org_id]);
        $usage['invoices_total'] = (int)$st->fetchColumn();

        // Devis (si table existe)
        if (ak_table_exists($pdo, 'asso_quotes')) {
            $st = $pdo->prepare("SELECT COUNT(*) FROM asso_quotes WHERE org_id = :o");
            try { $st->execute([':o' => $org_id]); $usage['quotes_total'] = (int)$st->fetchColumn(); } catch (Throwable $e) {}
        }

        // Contacts (clients)
        if (ak_table_exists($pdo, 'asso_clients')) {
            $st = $pdo->prepare("SELECT COUNT(*) FROM asso_clients WHERE org_id = :o");
            try { $st->execute([':o' => $org_id]); $usage['contacts'] = (int)$st->fetchColumn(); } catch (Throwable $e) {}
        }

        // IA texte : crédits du mois (registre unifié ai_usage_ledger, au moins les générations
        // du hub déjà enregistrées : ak_ai_credits_used_month). IA image : générations « image-… »
        // (aucun outil d'image n'existe aujourd'hui : reste à 0).
        $usage['ai_text_month']  = ak_ai_credits_used_month($pdo, $org_id);
        $usage['ai_image_month'] = ak_ai_generations_month($pdo, $org_id, true);

        // E-mails groupés du mois civil (diffusions, invitations, convocations, relances ; jamais
        // les factures ni les devis envoyés un par un)
        $usage['emails_month'] = ak_emails_sent_month($pdo, $org_id);

        // Gestionnaires (admin, coordinateur, référent) et lots de tri IA de la boîte mail
        $usage['managers'] = ak_count_managers($pdo, $org_id);
        $usage['mail_ai_batches_month'] = ak_mail_ai_batches_month($pdo, $org_id);

    } catch (Throwable $e) {
        error_log('[ak_get_usage] ' . $e->getMessage());
    }

    return $usage;
}

// Helper : table existe-t-elle ?
function ak_table_exists(PDO $pdo, string $tableName): bool {
    static $cache = [];
    if (isset($cache[$tableName])) return $cache[$tableName];
    try {
        $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :t");
        $st->execute([':t' => $tableName]);
        $cache[$tableName] = (int)$st->fetchColumn() > 0;
    } catch (Throwable $e) { $cache[$tableName] = false; }
    return $cache[$tableName];
}

// ============================================================
// 3. Vérifications de quotas (booléen + raison)
// ============================================================

/**
 * Vérifie si l'organisation peut créer une nouvelle facture.
 * Doux : retourne false si limite atteinte mais on peut éventuellement l'autoriser.
 *
 * @return array ['ok' => bool, 'reason' => string, 'limit' => int|null, 'current' => int]
 */
function ak_can_create_invoice(PDO $pdo, int $org_id): array {
    $plan = ak_get_current_plan($pdo, $org_id);
    if (!$plan) return ['ok' => false, 'reason' => 'Aucun plan actif', 'limit' => 0, 'current' => 0];

    if ($plan['limit_invoices_total'] === null) return ['ok' => true, 'reason' => '', 'limit' => null, 'current' => 0];

    $usage = ak_get_usage($pdo, $org_id);
    $current = (int)$usage['invoices_total'];
    $limit = (int)$plan['limit_invoices_total'];

    if ($current >= $limit) {
        return ['ok' => false, 'reason' => "Limite atteinte : {$current}/{$limit} factures sur le plan " . ak_plan_display_name($plan), 'limit' => $limit, 'current' => $current];
    }
    return ['ok' => true, 'reason' => '', 'limit' => $limit, 'current' => $current];
}

function ak_can_create_quote(PDO $pdo, int $org_id): array {
    $plan = ak_get_current_plan($pdo, $org_id);
    if (!$plan) return ['ok' => false, 'reason' => 'Aucun plan actif', 'limit' => 0, 'current' => 0];

    if ($plan['limit_quotes_total'] === null) return ['ok' => true, 'reason' => '', 'limit' => null, 'current' => 0];

    $usage = ak_get_usage($pdo, $org_id);
    $current = (int)$usage['quotes_total'];
    $limit = (int)$plan['limit_quotes_total'];

    if ($current >= $limit) {
        return ['ok' => false, 'reason' => "Limite atteinte : {$current}/{$limit} devis sur le plan " . ak_plan_display_name($plan), 'limit' => $limit, 'current' => $current];
    }
    return ['ok' => true, 'reason' => '', 'limit' => $limit, 'current' => $current];
}

function ak_can_add_adherent(PDO $pdo, int $org_id): array {
    $plan = ak_get_current_plan($pdo, $org_id);
    if (!$plan) return ['ok' => false, 'reason' => 'Aucun plan actif', 'limit' => 0, 'current' => 0];

    if ($plan['limit_adherents'] === null) return ['ok' => true, 'reason' => '', 'limit' => null, 'current' => 0];

    $usage = ak_get_usage($pdo, $org_id);
    $current = (int)$usage['adherents'];
    $limit = (int)$plan['limit_adherents'];

    if ($current >= $limit) {
        return ['ok' => false, 'reason' => "Limite atteinte : {$current}/{$limit} membres (bureau compris) avec la formule " . ak_plan_display_name($plan), 'limit' => $limit, 'current' => $current];
    }
    return ['ok' => true, 'reason' => '', 'limit' => $limit, 'current' => $current];
}

function ak_can_add_contact(PDO $pdo, int $org_id): array {
    $plan = ak_get_current_plan($pdo, $org_id);
    if (!$plan) return ['ok' => false, 'reason' => 'Aucun plan actif', 'limit' => 0, 'current' => 0];

    if ($plan['limit_contacts'] === null) return ['ok' => true, 'reason' => '', 'limit' => null, 'current' => 0];

    $usage = ak_get_usage($pdo, $org_id);
    $current = (int)$usage['contacts'];
    $limit = (int)$plan['limit_contacts'];

    if ($limit === 0) {
        return ['ok' => false, 'reason' => "Les contacts ne sont pas inclus dans le plan " . ak_plan_display_name($plan), 'limit' => 0, 'current' => $current];
    }
    if ($current >= $limit) {
        return ['ok' => false, 'reason' => "Limite atteinte : {$current}/{$limit} contacts sur le plan " . ak_plan_display_name($plan), 'limit' => $limit, 'current' => $current];
    }
    return ['ok' => true, 'reason' => '', 'limit' => $limit, 'current' => $current];
}

function ak_can_add_user(PDO $pdo, int $org_id): array {
    $plan = ak_get_current_plan($pdo, $org_id);
    if (!$plan) return ['ok' => false, 'reason' => 'Aucun plan actif', 'limit' => 0, 'current' => 0];

    if ($plan['limit_users'] === null) return ['ok' => true, 'reason' => '', 'limit' => null, 'current' => 0];

    $usage = ak_get_usage($pdo, $org_id);
    $current = (int)$usage['users'];
    $limit = (int)$plan['limit_users'];

    if ($current >= $limit) {
        return ['ok' => false, 'reason' => "Limite atteinte : {$current}/{$limit} utilisateurs sur le plan " . ak_plan_display_name($plan), 'limit' => $limit, 'current' => $current];
    }
    return ['ok' => true, 'reason' => '', 'limit' => $limit, 'current' => $current];
}

/**
 * STRICT - Vérifie si l'IA texte peut être utilisée : crédits IA du mois (registre unifié
 * ai_usage_ledger, ak_ai_credits_used_month), avec gestion bonus première semaine.
 * $credits : crédits que l'appel va consommer (1 par défaut).
 */
function ak_can_use_ai_text(PDO $pdo, int $org_id, int $credits = 1): array {
    $plan = ak_get_current_plan($pdo, $org_id);
    if (!$plan) return ['ok' => false, 'reason' => 'Aucun plan actif', 'limit' => 0, 'current' => 0];

    if (!isset($plan['limit_ai_text_per_month']) || $plan['limit_ai_text_per_month'] === '') return ['ok' => true, 'reason' => '', 'limit' => null, 'current' => 0];
    $credits = max(0, $credits);

    $usage = ak_get_usage($pdo, $org_id);
    $current = (int)$usage['ai_text_month'];
    $limit = (int)$plan['limit_ai_text_per_month'];

    // Bonus première semaine (si abonnement < 7 jours)
    $bonus = 0;
    if (!empty($plan['bonus_ai_first_week']) && !empty($plan['started_at'])) {
        $days_since = (time() - strtotime($plan['started_at'])) / 86400;
        if ($days_since <= 7) {
            $bonus = (int)$plan['bonus_ai_first_week'];
        }
    }
    $effective_limit = $limit + $bonus;

    if ($limit === 0 && $bonus === 0) {
        return ['ok' => false, 'reason' => "L'IA de rédaction n'est pas incluse dans la formule " . ak_plan_display_name($plan) . '.', 'limit' => 0, 'current' => $current];
    }
    if ($current >= $effective_limit || ($credits > 0 && $current + $credits > $effective_limit)) {
        $msg = "Limite atteinte : {$current}/{$effective_limit} crédits IA utilisés ce mois-ci";
        if ($bonus > 0) $msg .= " (bonus de la première semaine inclus)";
        return ['ok' => false, 'reason' => $msg . '.', 'limit' => $effective_limit, 'current' => $current];
    }
    return ['ok' => true, 'reason' => '', 'limit' => $effective_limit, 'current' => $current, 'bonus' => $bonus];
}

function ak_can_use_ai_image(PDO $pdo, int $org_id): array {
    $plan = ak_get_current_plan($pdo, $org_id);
    if (!$plan) return ['ok' => false, 'reason' => 'Aucun plan actif', 'limit' => 0, 'current' => 0];

    if ($plan['limit_ai_image_per_month'] === null) return ['ok' => true, 'reason' => '', 'limit' => null, 'current' => 0];

    $usage = ak_get_usage($pdo, $org_id);
    $current = (int)$usage['ai_image_month'];
    $limit = (int)$plan['limit_ai_image_per_month'];

    if ($limit === 0) {
        return ['ok' => false, 'reason' => "L'IA image n'est pas incluse dans le plan " . ak_plan_display_name($plan), 'limit' => 0, 'current' => $current];
    }
    if ($current >= $limit) {
        return ['ok' => false, 'reason' => "Limite atteinte : {$current}/{$limit} générations IA image ce mois", 'limit' => $limit, 'current' => $current];
    }
    return ['ok' => true, 'reason' => '', 'limit' => $limit, 'current' => $current];
}

/**
 * STRICT - Vérifie si N emails peuvent être envoyés
 */
function ak_can_send_email(PDO $pdo, int $org_id, int $count = 1): array {
    $plan = ak_get_current_plan($pdo, $org_id);
    if (!$plan) return ['ok' => false, 'reason' => 'Aucun plan actif', 'limit' => 0, 'current' => 0];

    // Si la feature email_diffusion est désactivée → bloqué (colonne absente : permis, comme avant)
    if (array_key_exists('feature_email_diffusion', $plan) && empty($plan['feature_email_diffusion'])) {
        return ['ok' => false, 'reason' => "Les envois groupés d'e-mails ne sont pas inclus dans la formule " . ak_plan_display_name($plan) . '.', 'limit' => 0, 'current' => 0, 'feature_disabled' => true];
    }

    if (!isset($plan['limit_emails_per_month']) || $plan['limit_emails_per_month'] === '') return ['ok' => true, 'reason' => '', 'limit' => null, 'current' => 0];

    $usage = ak_get_usage($pdo, $org_id);
    $current = (int)$usage['emails_month'];
    $limit = (int)$plan['limit_emails_per_month'];

    if ($limit === 0) {
        return ['ok' => false, 'reason' => "Les envois groupés d'e-mails ne sont pas inclus dans la formule " . ak_plan_display_name($plan) . '.', 'limit' => 0, 'current' => $current, 'feature_disabled' => true];
    }
    if ($current + $count > $limit) {
        $left = max(0, $limit - $current);
        return ['ok' => false, 'reason' => 'Votre formule inclut ' . ak_plan_fmt_int($limit) . ' e-mails par mois : '
            . ($left === 0 ? 'ils sont tous utilisés ce mois-ci.' : 'il en reste ' . ak_plan_fmt_int($left) . ' ce mois-ci, pour ' . ak_plan_fmt_int($count) . ' prévus.'),
            'limit' => $limit, 'current' => $current];
    }
    return ['ok' => true, 'reason' => '', 'limit' => $limit, 'current' => $current];
}

function ak_can_use_feature(PDO $pdo, int $org_id, string $feature): bool {
    $plan = ak_get_current_plan($pdo, $org_id);
    if (!$plan) return false;
    $key = 'feature_' . $feature;
    return !empty($plan[$key]);
}

// ====== Features capees (ex: Bilan analytique en essai) ======
function ak_feature_usage_count(PDO $pdo, int $org_id, string $key): int {
    try {
        // Somme : une table créée à la main sans clé unique a une ligne par usage
        // (migrations/2026-11-09-grille-tarifaire.sql n'ajoute la clé que sans doublon)
        $st = $pdo->prepare("SELECT COALESCE(SUM(used_count), 0) FROM org_feature_usage WHERE org_id = :o AND feature_key = :k");
        $st->execute([':o' => $org_id, ':k' => $key]);
        $v = $st->fetchColumn();
        return $v === false ? 0 : (int)$v;
    } catch (Throwable $e) { error_log('[ak_feature_usage_count] ' . $e->getMessage()); return 0; }
}
function ak_feature_usage_record(PDO $pdo, int $org_id, string $key): void {
    try {
        $pdo->prepare("INSERT INTO org_feature_usage (org_id, feature_key, used_count, first_used_at, last_used_at)
                       VALUES (:o, :k, 1, NOW(), NOW())
                       ON DUPLICATE KEY UPDATE used_count = used_count + 1, last_used_at = NOW()")
            ->execute([':o' => $org_id, ':k' => $key]);
    } catch (Throwable $e) { error_log('[ak_feature_usage_record] ' . $e->getMessage()); }
}
function ak_trial_gate(PDO $pdo, int $org_id, string $key, int $cap = 1): array {
    $plan = ak_get_current_plan($pdo, $org_id);
    if (!$plan || empty($plan['is_trial'])) return ['blocked'=>false,'trial'=>false,'used'=>0,'cap'=>$cap,'plan'=>$plan];
    $used = ak_feature_usage_count($pdo, $org_id, $key);
    if ($used >= $cap) return ['blocked'=>true,'trial'=>true,'used'=>$used,'cap'=>$cap,'plan'=>$plan];
    ak_feature_usage_record($pdo, $org_id, $key);
    return ['blocked'=>false,'trial'=>true,'used'=>$used+1,'cap'=>$cap,'plan'=>$plan];
}

// Gate compta analytique : illimite pour les plans avec feature_advanced_stats
// (Pro, Sur-mesure) ; 1 seule generation pour les autres (Essentiel, Demarrage,
// Demo/pro-essai) via le compteur org_feature_usage 'bilan_analytique'.
function ak_bilan_analytique_gate(PDO $pdo, int $org_id): array {
    $plan = ak_get_current_plan($pdo, $org_id);
    if (!empty($plan['feature_advanced_stats'])) {
        return ['blocked'=>false,'unlimited'=>true,'used'=>0,'cap'=>null,'plan'=>$plan];
    }
    $used = ak_feature_usage_count($pdo, $org_id, 'bilan_analytique');
    if ($used >= 1) {
        return ['blocked'=>true,'unlimited'=>false,'used'=>$used,'cap'=>1,'plan'=>$plan];
    }
    ak_feature_usage_record($pdo, $org_id, 'bilan_analytique');
    return ['blocked'=>false,'unlimited'=>false,'used'=>$used+1,'cap'=>1,'plan'=>$plan];
}

// ============================================================
// 3b. Grille de novembre 2026 : formules, fonctions, gestionnaires, crédits IA, e-mails
// ============================================================
// Lignes asso_plans de la grille (migrations/2026-11-09-grille-tarifaire.sql) :
//   ak-essentiel (Essentiel, 19,99 € HT/mois), assokit (Pro, 29,99 €), ak-equipe (Équipe, 59,99 €),
//   sur-mesure (sur devis), fondateur (masquée), pro-essai (Essai Pro), demarrage (accès limité).
// Tout ce qui suit laisse passer en cas de doute (colonne absente, table absente, erreur) :
// chaque chantier fonctionne avec ou sans les autres, avec ou sans Stripe.

/** Fonctions des formules, dans l'ordre de la grille : ['feature_xxx' => libellé]. */
function ak_plan_feature_keys(): array {
    return [
        'feature_email_diffusion'    => 'Envois groupés d’e-mails (diffusions, invitations, convocations)',
        'feature_advanced_stats'     => 'Prévisions de trésorerie et comptabilité analytique illimitée',
        'feature_recurring_invoices' => 'Relances automatiques et factures récurrentes',
        'feature_signature_quotes'   => 'Devis signés en ligne',
        'feature_ai_assistant'       => 'Copilote IA, priorités du jour, assistant de projet, lecture des justificatifs',
        'feature_mail_inbox'         => 'Boîte Gmail intégrée et brouillons IA',
        'feature_mail_ai_sort'       => 'Tri IA de la boîte mail',
        'feature_ai_daily_all'       => 'Priorités IA du jour pour chaque membre',
        'feature_anomalies'          => 'Détection des anomalies',
        'feature_team_planning'      => 'Planning d’équipe et absences',
        'feature_prospection'        => 'Prospection partagée',
        'feature_activity_log'       => 'Journal d’activité de l’équipe',
        'feature_shared_inbox'       => 'Boîte mail partagée (attribution des conversations)',
        'feature_treasurer_digest'   => 'Point du lundi du trésorier',
        'feature_priority_support'   => 'Support prioritaire',
        'feature_custom_domain'      => 'Domaine personnel (pas encore disponible)',
        'feature_dedicated_support'  => 'Interlocuteur unique',
    ];
}

/** Limites des formules : ['limit_xxx' => libellé]. Vide = illimité, 0 = bloqué. */
function ak_plan_limit_keys(): array {
    return [
        'limit_adherents'                 => 'Membres (bureau compris)',
        'limit_managers'                  => 'Gestionnaires (administrateurs, coordinateurs, référents)',
        'limit_users'                     => 'Comptes utilisateurs',
        'limit_invoices_total'            => 'Factures (total)',
        'limit_quotes_total'              => 'Devis (total)',
        'limit_contacts'                  => 'Contacts',
        'limit_ai_text_per_month'         => 'Crédits IA par mois',
        'limit_ai_image_per_month'        => 'IA image par mois',
        'limit_emails_per_month'          => 'E-mails par mois',
        'limit_mail_ai_batches_per_month' => 'Lots de tri IA de la boîte mail par mois',
    ];
}

/** Fonctions apparues avec la grille : 1 par défaut (colonne absente = permis, comme avant). */
function ak_plan_default_on_keys(): array {
    return ['feature_ai_assistant', 'feature_mail_inbox', 'feature_mail_ai_sort', 'feature_ai_daily_all',
            'feature_anomalies', 'feature_team_planning', 'feature_prospection', 'feature_activity_log',
            'feature_shared_inbox', 'feature_treasurer_digest'];
}

/** 'mail_inbox' ou 'feature_mail_inbox' → 'feature_mail_inbox' (null si le nom est invalide). */
function ak_plan_feature_col(string $feature): ?string {
    $f = strtolower(trim($feature));
    if (strncmp($f, 'feature_', 8) !== 0) $f = 'feature_' . $f;
    return preg_match('/^feature_[a-z0-9_]+$/', $f) ? $f : null;
}

/** Entier au format français : « 2 000 » (espace insécable). */
function ak_plan_fmt_int($n): string {
    return number_format((int)$n, 0, ',', "\u{00A0}");
}

/** « 1er décembre » : date à laquelle les quotas mensuels repartent. */
function ak_plan_next_month_label(): string {
    $mois = [1 => 'janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
    return '1er ' . $mois[(int)date('n', strtotime('first day of next month'))];
}

/** Lignes asso_plans, lues une fois par requête. */
function ak_plan_rows(PDO $pdo): array {
    static $rows = null;
    if ($rows !== null) return $rows;
    try {
        $rows = $pdo->query("SELECT * FROM asso_plans ORDER BY COALESCE(price_cents, 0) ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('[ak_plan_rows] ' . get_class($e));
        $rows = [];
    }
    return $rows;
}

/** Ligne asso_plans d'après son slug, ou null. */
function ak_plan_by_slug(PDO $pdo, string $slug): ?array {
    foreach (ak_plan_rows($pdo) as $p) {
        if ((string)($p['slug'] ?? '') === $slug) return $p;
    }
    return null;
}

/**
 * La formule courante de l'association a-t-elle cette fonction ? $feature : 'mail_inbox' ou
 * 'feature_mail_inbox'. S'appuie sur ak_can_use_feature. Laisse passer en cas de doute : pas
 * d'association (compte plateforme), formule illisible, colonne absente d'une fonction apparue
 * avec la grille (ak_plan_default_on_keys).
 */
function ak_plan_has(PDO $pdo, int $org_id, string $feature): bool {
    $col = ak_plan_feature_col($feature);
    if ($col === null) return false;
    if ($org_id <= 0) return true;
    try {
        $plan = ak_get_current_plan($pdo, $org_id);
    } catch (Throwable $e) {
        return true;
    }
    if (!$plan) return true;
    if (!array_key_exists($col, $plan)) return in_array($col, ak_plan_default_on_keys(), true) || $col === 'feature_email_diffusion';
    return ak_can_use_feature($pdo, $org_id, substr($col, 8));
}

/**
 * Nom de la formule la moins chère, proposée, payante, hors essai et hors devis, qui a la
 * fonction (« Pro », « Équipe ») ; « Sur-mesure » à défaut.
 */
function ak_plan_required_label(PDO $pdo, string $feature): string {
    $col = ak_plan_feature_col($feature);
    if ($col !== null) {
        foreach (ak_plan_rows($pdo) as $p) {
            if (empty($p['is_visible']) || (int)($p['price_cents'] ?? 0) <= 0) continue;
            if (!empty($p['is_trial']) || ($p['slug'] ?? '') === 'pro-essai' || !empty($p['is_custom_quote'])) continue;
            if (!empty($p[$col])) return ak_plan_display_name($p);
        }
    }
    $sm = ak_plan_by_slug($pdo, 'sur-mesure');
    return $sm ? ak_plan_display_name($sm) : 'Sur-mesure';
}

/** Libellé lisible d'une fonction (ak_plan_feature_keys). */
function ak_plan_feature_label(string $feature): string {
    $col = ak_plan_feature_col($feature);
    $keys = ak_plan_feature_keys();
    return ($col !== null && isset($keys[$col])) ? $keys[$col] : 'Cette fonction';
}

/**
 * Contrôle d'une fonction pour une association.
 * @return array{ok:bool, message:string, required:string}
 *   message (vide si ok) : sur le site « Cette fonction est incluse à partir de la formule Équipe. » ;
 *   dans l'appli (ak_billing_hidden) « Cette fonction n'est pas incluse dans votre formule. »
 */
function ak_feature_gate(PDO $pdo, int $org_id, string $feature): array {
    if (ak_plan_has($pdo, $org_id, $feature)) return ['ok' => true, 'message' => '', 'required' => ''];
    $required = ak_plan_required_label($pdo, $feature);
    $hidden = function_exists('ak_billing_hidden') && ak_billing_hidden();
    return [
        'ok' => false,
        'message' => $hidden ? 'Cette fonction n’est pas incluse dans votre formule.'
                             : 'Cette fonction est incluse à partir de la formule ' . $required . '.',
        'required' => $required,
    ];
}

/**
 * Carte HTML « fonction non incluse » à afficher à la place d'une page. Bouton « Voir les
 * formules » (/mon-asso-plan) seulement pour un administrateur, hors appli et hors compte
 * d'examen (ak_billing_hidden). '' si la fonction est incluse.
 */
function ak_render_feature_lock(PDO $pdo, int $org_id, string $feature, string $title = ''): string {
    $g = ak_feature_gate($pdo, $org_id, $feature);
    if ($g['ok']) return '';
    $e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    $title = trim($title) !== '' ? $title : ak_plan_feature_label($feature);
    $u = function_exists('current_user') ? (current_user() ?: []) : [];
    $is_admin = (($u['role'] ?? ($_SESSION['role'] ?? '')) === 'admin');
    $hidden = function_exists('ak_billing_hidden') && ak_billing_hidden();
    $html = '<div class="card ak-feature-lock" style="max-width:640px;margin:24px auto;padding:28px 26px;text-align:center;border:1px solid #E2E8F0;border-radius:16px;background:#fff;">'
          . '<div style="font-size:40px;line-height:1;margin-bottom:12px;" aria-hidden="true">🔒</div>'
          . '<h2 style="margin:0 0 8px;font-size:19px;color:#0F172A;">' . $e($title) . '</h2>'
          . '<p style="margin:0;color:#475569;line-height:1.6;font-size:15px;">' . $e($g['message']) . '</p>';
    if ($is_admin && !$hidden) {
        $html .= '<p style="margin:18px 0 0;"><a href="/mon-asso-plan" class="btn btn-primary">Voir les formules</a></p>';
    } elseif (!$hidden) {
        $html .= '<p style="margin:14px 0 0;color:#64748B;font-size:13px;">Un administrateur de votre structure peut changer de formule.</p>';
    }
    return $html . '</div>';
}

/**
 * Réponse JSON d'une API quand la fonction n'est pas incluse (HTTP 403), message neutre
 * (aucune incitation à payer : l'appli l'affiche tel quel).
 * @return array{ok:false, error:string, feature:string, message:string}
 */
function ak_feature_denied_json(PDO $pdo, int $org_id, string $feature): array {
    if (!headers_sent()) http_response_code(403);
    return ['ok' => false, 'error' => 'plan_feature', 'feature' => (string)ak_plan_feature_col($feature),
            'message' => 'Cette fonction n’est pas incluse dans votre formule.'];
}

// ---------- Gestionnaires ----------

/** Rôles comptés comme gestionnaires (limit_managers). */
function ak_manager_roles(): array {
    return ['admin', 'coordinator', 'referent'];
}

/** Comptes actifs, non supprimés, de rôle gestionnaire (comptes plateforme exclus). */
function ak_count_managers(PDO $pdo, int $org_id): int {
    if ($org_id <= 0) return 0;
    try {
        $cols = function_exists('ak_db_columns') ? ak_db_columns($pdo, 'users') : [];
        $where = "org_id = ? AND role IN ('admin', 'coordinator', 'referent')";
        if (!$cols || isset($cols['deleted_at']))     $where .= " AND deleted_at IS NULL";
        if (!$cols || isset($cols['is_active']))      $where .= " AND is_active = 1";
        if (isset($cols['is_super_admin']))           $where .= " AND COALESCE(is_super_admin, 0) = 0";
        if (isset($cols['is_founder']))               $where .= " AND COALESCE(is_founder, 0) = 0";
        if (isset($cols['is_platform_admin']))        $where .= " AND COALESCE(is_platform_admin, 0) = 0";
        $st = $pdo->prepare("SELECT COUNT(*) FROM users WHERE $where");
        $st->execute([$org_id]);
        return (int)$st->fetchColumn();
    } catch (Throwable $e) {
        error_log('[ak_count_managers] ' . get_class($e));
        return 0;
    }
}

/**
 * Peut-on ajouter $n gestionnaires (création d'un compte admin / coordinateur / référent,
 * passage d'un compte à un de ces rôles, réactivation) ?
 * @return array{ok:bool, reason:string, limit:?int, current:int}
 */
function ak_can_add_manager(PDO $pdo, int $org_id, int $n = 1): array {
    $ok = ['ok' => true, 'reason' => '', 'limit' => null, 'current' => 0];
    if ($org_id <= 0 || $n <= 0) return $ok;
    try {
        $plan = ak_get_current_plan($pdo, $org_id);
    } catch (Throwable $e) {
        return $ok;
    }
    if (!$plan || !isset($plan['limit_managers']) || $plan['limit_managers'] === '') return $ok;
    $limit = (int)$plan['limit_managers'];
    $current = ak_count_managers($pdo, $org_id);
    if ($current + $n <= $limit) return ['ok' => true, 'reason' => '', 'limit' => $limit, 'current' => $current];
    $reason = $limit <= 0
        ? 'Votre formule ne permet pas d’ajouter de gestionnaire (administrateur, coordinateur, référent).'
        : 'Votre formule permet ' . ak_plan_fmt_int($limit) . ' gestionnaire' . ($limit > 1 ? 's' : '')
          . ' (administrateurs, coordinateurs, référents) : limite atteinte.';
    return ['ok' => false, 'reason' => $reason, 'limit' => $limit, 'current' => $current];
}

/**
 * Changement de rôle d'un compte ACTIF : seul le passage d'un rôle non gestionnaire à un rôle
 * gestionnaire est contrôlé (un admin qui devient coordinateur ne change pas le compte).
 * @return array{ok:bool, reason:string}
 */
function ak_role_change_allowed(PDO $pdo, int $org_id, string $old_role, string $new_role): array {
    $m = ak_manager_roles();
    if (in_array($old_role, $m, true) || !in_array($new_role, $m, true)) return ['ok' => true, 'reason' => ''];
    $c = ak_can_add_manager($pdo, $org_id, 1);
    return ['ok' => (bool)$c['ok'], 'reason' => (string)$c['reason']];
}

// ---------- Démarrage : accès limité ----------

/**
 * L'association est-elle en accès limité (formule Démarrage : fin d'essai non convertie,
 * résiliation, impayé) ? Faux pendant un essai en cours ou suspendu, et pour une suspension
 * à la main (gérée à part par ak_org_access, app-context.php). Faux en cas de doute.
 */
function ak_plan_is_readonly(PDO $pdo, int $org_id): bool {
    static $cache = [];
    if ($org_id <= 0) return false;
    if (isset($cache[$org_id])) return $cache[$org_id];
    $ro = false;
    try {
        $plan = ak_get_current_plan($pdo, $org_id);
        if ($plan && ($plan['slug'] ?? '') === 'demarrage') {
            $ti = ak_plan_trial_info($pdo, $org_id);
            $ro = empty($ti['in_trial']) && empty($ti['trial_paused']);
            if ($ro) {
                $st = $pdo->prepare("SELECT * FROM organizations WHERE id = ? LIMIT 1");
                $st->execute([$org_id]);
                $o = $st->fetch(PDO::FETCH_ASSOC) ?: [];
                // Suspension à la main (motif renseigné) : déjà coupée par ak_org_access
                if (($o['status'] ?? '') === 'suspended' && trim((string)($o['suspended_reason'] ?? '')) !== '') $ro = false;
            }
        }
    } catch (Throwable $e) {
        error_log('[ak_plan_is_readonly] org #' . $org_id . ' : ' . get_class($e));
        $ro = false;
    }
    return $cache[$org_id] = $ro;
}

/** Texte du bandeau d'accès limité (sans prix ni lien : le bouton est ajouté par la page, hors appli). */
function ak_readonly_message(): string {
    return 'Votre espace est en accès limité : vos données restent consultables et exportables.';
}

// ---------- Crédits IA ----------

/**
 * Générations IA réussies du mois enregistrées par le hub (asso_ai_generations, ou l'ancienne
 * asso_ai_logs) ; $image : générations « image-… » seulement, sinon le texte.
 */
function ak_ai_generations_month(PDO $pdo, int $org_id, bool $image = false): int {
    if ($org_id <= 0) return 0;
    $ai_table = ak_table_exists($pdo, 'asso_ai_generations') ? 'asso_ai_generations'
              : (ak_table_exists($pdo, 'asso_ai_logs') ? 'asso_ai_logs' : null);
    if ($ai_table === null) return 0;
    // Générations supprimées comprises (suppression douce, statut « deleted ») : supprimer une
    // génération ne rend pas de quota du mois (comme asso-ai-quotas.php et ak_ai_count_this_month).
    $ok = $ai_table === 'asso_ai_generations' ? " AND status IN ('success', 'deleted')" : '';
    $type = $image ? "tool_type LIKE 'image-%'" : "(tool_type IS NULL OR tool_type NOT LIKE 'image-%')";
    try {
        $st = $pdo->prepare("SELECT COUNT(*) FROM {$ai_table}
                             WHERE org_id = :o{$ok} AND created_at >= DATE_FORMAT(NOW(), '%Y-%m-01 00:00:00') AND $type");
        $st->execute([':o' => $org_id]);
        return (int)$st->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

/**
 * Crédits IA consommés ce mois-ci : le plus grand de la somme des crédits du registre
 * ai_usage_ledger (tous les usages : hub, générateur, copilote, justificatifs…) et du nombre de
 * générations du hub déjà enregistrées (asso_ai_generations) — pas de remise à zéro le mois de
 * la mise en service du registre.
 */
function ak_ai_credits_used_month(PDO $pdo, int $org_id): int {
    if ($org_id <= 0) return 0;
    $ledger = 0;
    if (ak_table_exists($pdo, 'ai_usage_ledger')) {
        try {
            $st = $pdo->prepare("SELECT COALESCE(SUM(credits), 0) FROM ai_usage_ledger
                                 WHERE org_id = ? AND created_at >= DATE_FORMAT(NOW(), '%Y-%m-01 00:00:00')");
            $st->execute([$org_id]);
            $ledger = (int)$st->fetchColumn();
        } catch (Throwable $e) {
            $ledger = 0;
        }
    }
    return max($ledger, ak_ai_generations_month($pdo, $org_id, false));
}

/**
 * Appel IA lancé par un utilisateur et qui va consommer $credits : null = autorisé, sinon le
 * message à afficher (texte brut). Toute erreur laisse passer.
 */
function ak_ai_quota_block(PDO $pdo, int $org_id, int $credits = 1): ?string {
    if ($org_id <= 0 || $credits <= 0) return null;
    try {
        $plan = ak_get_current_plan($pdo, $org_id);
        if (!$plan) return null;
        $chk = ak_can_use_ai_text($pdo, $org_id, $credits);
    } catch (Throwable $e) {
        return null;
    }
    if (!empty($chk['ok'])) return null;
    $limit = (int)($chk['limit'] ?? 0);
    if ($limit <= 0) return 'L’IA de rédaction n’est pas incluse dans votre formule.';
    return 'Votre formule inclut ' . ak_plan_fmt_int($limit) . ' crédits IA par mois : ils sont tous utilisés ce mois-ci. '
         . 'Le compteur repart à zéro le ' . ak_plan_next_month_label() . '.';
}

/** Lots de tri IA de la boîte mail ce mois-ci (lignes « mail-tri » du registre). */
function ak_mail_ai_batches_month(PDO $pdo, int $org_id): int {
    if ($org_id <= 0 || !ak_table_exists($pdo, 'ai_usage_ledger')) return 0;
    try {
        $st = $pdo->prepare("SELECT COUNT(*) FROM ai_usage_ledger
                             WHERE org_id = ? AND path = 'mail-tri' AND created_at >= DATE_FORMAT(NOW(), '%Y-%m-01 00:00:00')");
        $st->execute([$org_id]);
        return (int)$st->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

/**
 * Un nouveau lot de tri IA de la boîte mail (15 conversations) est-il permis ? Fonction
 * feature_mail_ai_sort, et lots du mois sous limit_mail_ai_batches_per_month (vide = illimité).
 * Sinon, la boîte est triée par règles (mail_priority_fallback).
 */
function ak_mail_ai_batch_allowed(PDO $pdo, int $org_id): bool {
    if ($org_id <= 0) return true;
    if (!ak_plan_has($pdo, $org_id, 'mail_ai_sort')) return false;
    try {
        $plan = ak_get_current_plan($pdo, $org_id);
    } catch (Throwable $e) {
        return true;
    }
    if (!$plan || !isset($plan['limit_mail_ai_batches_per_month']) || $plan['limit_mail_ai_batches_per_month'] === '') return true;
    return ak_mail_ai_batches_month($pdo, $org_id) < (int)$plan['limit_mail_ai_batches_per_month'];
}

// ---------- E-mails groupés ----------

/**
 * Destinataires d'e-mails groupés du mois civil : diffusions et invitations
 * (communication_broadcasts, hors brouillons), diffusions rédigées avec l'IA, convocations d'AG,
 * relances de cotisation. Les factures et devis envoyés un par un ne sont jamais comptés.
 * Chaque source est lue à part : une table absente n'empêche pas de compter les autres.
 */
function ak_emails_sent_month(PDO $pdo, int $org_id): int {
    if ($org_id <= 0) return 0;
    $m = "DATE_FORMAT(NOW(), '%Y-%m-01 00:00:00')";
    $src = [
        ['communication_broadcasts', 'org_id', 'COALESCE(SUM(nb_total), 0)', "created_at >= $m AND (status IS NULL OR status <> 'draft')"],
        ['asso_ai_diffusions', 'org_id', 'COALESCE(SUM(recipients_count), 0)', "created_at >= $m"],
        ['assembly_attendees a JOIN assemblies g ON g.id = a.assembly_id', 'g.org_id', 'COUNT(*)', "a.invitation_sent_at >= $m"],
        ['asso_membership_reminders', 'org_id', 'COUNT(*)', "sent_at >= $m"],
    ];
    $total = 0;
    foreach ($src as [$from, $org_col, $val, $where]) {
        try {
            $q = $pdo->prepare("SELECT $val FROM $from WHERE $org_col = ? AND $where");
            $q->execute([$org_id]);
            $total += (int)$q->fetchColumn();
        } catch (Throwable $e) {}
    }
    return $total;
}

/**
 * Envoi groupé de $n destinataires : null = autorisé, sinon le message (texte brut). À appeler à
 * côté de ak_trial_send_block, jamais pour une facture ou un devis envoyé à l'unité. Toute
 * erreur laisse passer.
 */
function ak_email_quota_block(PDO $pdo, int $org_id, int $n): ?string {
    if ($org_id <= 0 || $n <= 0) return null;
    try {
        $plan = ak_get_current_plan($pdo, $org_id);
        if (!$plan) return null;
        $chk = ak_can_send_email($pdo, $org_id, $n);
    } catch (Throwable $e) {
        return null;
    }
    if (!empty($chk['ok'])) return null;
    if (!empty($chk['feature_disabled'])) {
        return ($plan['slug'] ?? '') === 'demarrage' ? ak_readonly_message()
             : 'Les envois groupés d’e-mails ne sont pas inclus dans votre formule.';
    }
    $limit = (int)($chk['limit'] ?? 0);
    $left = max(0, $limit - (int)($chk['current'] ?? 0));
    return 'Votre formule inclut ' . ak_plan_fmt_int($limit) . ' e-mails groupés par mois : '
         . ($left === 0 ? 'ils sont tous utilisés ce mois-ci.' : 'il en reste ' . ak_plan_fmt_int($left) . ', pour ' . ak_plan_fmt_int($n) . ' destinataires prévus.')
         . ' Le compteur repart à zéro le ' . ak_plan_next_month_label() . '.';
}

// ---------- Affichage sans prix (appli, Mon abonnement) ----------

/**
 * Phrases qui décrivent une formule, SANS prix : « Jusqu'à 2 000 membres (bureau compris) »,
 * « 25 gestionnaires », « 500 crédits IA par mois », « 8 000 e-mails par mois ».
 * Jamais « illimité » pour l'IA ni les e-mails (une limite vide n'est pas citée).
 */
function ak_plan_public_summary(?array $plan): array {
    if (!$plan) return [];
    if (($plan['slug'] ?? '') === 'demarrage') return ['Accès limité : vos données restent consultables et exportables'];
    $v = function ($k) use ($plan) {
        return (!array_key_exists($k, $plan) || $plan[$k] === null || $plan[$k] === '') ? null : (int)$plan[$k];
    };
    $out = [];
    $ad = $v('limit_adherents');
    $mg = $v('limit_managers');
    if ($ad === null && $mg === null && array_key_exists('limit_managers', $plan)) {
        $out[] = 'Sans plafond de membres ni de gestionnaires';
    } else {
        if ($ad === null && array_key_exists('limit_adherents', $plan)) $out[] = 'Membres sans plafond';
        elseif ($ad > 0) $out[] = 'Jusqu’à ' . ak_plan_fmt_int($ad) . ' membres (bureau compris)';
        if ($mg !== null && $mg > 0) $out[] = ak_plan_fmt_int($mg) . ' gestionnaire' . ($mg > 1 ? 's' : '');
        elseif ($mg === null && array_key_exists('limit_managers', $plan)) $out[] = 'Gestionnaires sans plafond';
    }
    $ai = $v('limit_ai_text_per_month');
    if ($ai !== null && $ai > 0) $out[] = ak_plan_fmt_int($ai) . ' crédit' . ($ai > 1 ? 's' : '') . ' IA par mois';
    $em = $v('limit_emails_per_month');
    if ($em !== null && $em > 0 && (!array_key_exists('feature_email_diffusion', $plan) || !empty($plan['feature_email_diffusion']))) {
        $out[] = ak_plan_fmt_int($em) . ' e-mails par mois';
    }
    return $out;
}

/**
 * Badge d'une formule : « Le plus choisi » seulement si c'est vrai (strictement le plus
 * d'abonnements réellement payants et choisis, et au moins 5 en tout) ; sinon « Recommandé »
 * pour la formule mise en avant (is_featured) ; sinon null. Allégation factuelle (art. L121-2
 * du Code de la consommation) : jamais « Le plus choisi » en dur.
 * Ne sont PAS comptés : les essais, les démos (slug « demo… »), l'association de l'éditeur,
 * les abonnements « Offert » (payment_mode = free_grant), les associations qui ne sont pas
 * actives (résiliées, suspendues…) et l'offre fondateur (anciens clients basculés
 * automatiquement : ils n'ont pas choisi Pro).
 */
function ak_plan_badge(PDO $pdo, string $slug): ?string {
    static $counts = null;
    if ($counts === null) {
        $counts = [];
        try {
            $paid = function_exists('ak_paid_sub_sql') ? ak_paid_sub_sql($pdo, 's', 'p')
                  : "(s.status = 'active' AND COALESCE(p.price_cents, 0) > 0 AND p.slug <> 'pro-essai')";
            $order = function_exists('ak_sub_order') ? ak_sub_order('s2') : "(s2.status = 'active') DESC, (s2.status = 'trial') DESC, s2.id DESC";
            $scols = function_exists('ak_db_columns') ? ak_db_columns($pdo, 'asso_subscriptions') : [];
            $not_free = isset($scols['payment_mode']) ? " AND COALESCE(s.payment_mode, '') <> 'free_grant'" : '';
            $rows = $pdo->query("
                SELECT p.slug, COUNT(*) AS n
                FROM organizations o
                JOIN asso_subscriptions s ON s.id = (SELECT s2.id FROM asso_subscriptions s2 JOIN asso_plans p2 ON p2.id = s2.plan_id
                                                     WHERE s2.org_id = o.id ORDER BY $order LIMIT 1)
                JOIN asso_plans p ON p.id = s.plan_id
                WHERE o.deleted_at IS NULL AND o.status = 'active'
                  AND COALESCE(o.slug, '') NOT LIKE 'demo%'
                  AND COALESCE(o.slug, '') NOT IN ('assokit', 'assokit-editeur')
                  AND p.slug <> 'fondateur'
                  AND $paid$not_free
                GROUP BY p.slug")->fetchAll(PDO::FETCH_KEY_PAIR);
            foreach ($rows as $s => $n) {
                $counts[(string)$s] = (int)$n;
            }
        } catch (Throwable $e) {
            error_log('[ak_plan_badge] ' . get_class($e));
            $counts = [];
        }
    }
    $key = $slug;
    $mine = ($slug === 'fondateur') ? 0 : ($counts[$key] ?? 0);
    if (array_sum($counts) >= 5 && $mine > 0) {
        $others = $counts;
        unset($others[$key]);
        if ($mine > ($others ? max($others) : 0)) return 'Le plus choisi';
    }
    $p = ak_plan_by_slug($pdo, $slug);
    return ($p && !empty($p['is_featured'])) ? 'Recommandé' : null;
}

// ---------- Offre fondateur ----------

/**
 * Associations à rattacher à l'offre fondateur avant la transformation de la ligne assokit en
 * Pro : MÊME règle que l'étape 6e de migrations/2026-11-09-grille-tarifaire.sql (pour
 * cli-rattacher-fondateur.php). Hors démos (slug « demo… »), hors supprimées, hors essais, hors
 * associations résiliées ou fermées (organizations.status 'cancelled' / 'closed') :
 *   - ligne d'abonnement courante (ordre ak_sub_order) sur assokit, en cours (active, overdue,
 *     past_due, pending_payment), ou résiliée mais dont la période payée court encore
 *     (status 'cancelled' et current_period_end à venir : l'accès reste jusqu'à cette date) ;
 *   - ou aucune ligne d'abonnement, association active, et un ancien libellé que
 *     ak_legacy_plan_row rattache à assokit.
 * Un ancien client résilié (période terminée) n'est jamais repris : il n'est pas rattaché,
 * et sa ligne ne bloque pas la transformation d'assokit en Pro.
 * Lecture seule : rien n'est écrit.
 * @return array<int, array{org_id:int, name:string, slug:string, source:string, subscription_id:?int, monthly_price_cents:?int}>
 */
function ak_fondateur_candidates(PDO $pdo): array {
    $out = [];
    $assokit = ak_plan_by_slug($pdo, 'assokit');
    if (!$assokit) return [];
    $aid = (int)$assokit['id'];
    try {
        $order = function_exists('ak_sub_order') ? ak_sub_order('s2') : "(s2.status = 'active') DESC, (s2.status = 'trial') DESC, s2.id DESC";
        $orgs = $pdo->query("
            SELECT o.id, o.name, o.slug, o.status, o.monthly_price_cents,
                   (SELECT s2.id FROM asso_subscriptions s2 JOIN asso_plans p2 ON p2.id = s2.plan_id
                     WHERE s2.org_id = o.id ORDER BY $order LIMIT 1) AS cur_sub_id
            FROM organizations o
            WHERE o.deleted_at IS NULL AND COALESCE(o.slug, '') NOT LIKE 'demo%'
            ORDER BY o.id")->fetchAll(PDO::FETCH_ASSOC);
        $scols = function_exists('ak_db_columns') ? ak_db_columns($pdo, 'asso_subscriptions') : [];
        $sub = $pdo->prepare("SELECT id, plan_id, status, "
            . (!$scols || isset($scols['current_period_end']) ? "(current_period_end IS NOT NULL AND current_period_end > NOW())" : "0")
            . " AS period_running FROM asso_subscriptions WHERE id = ?");
        foreach ($orgs as $o) {
            $ostatus = (string)($o['status'] ?? '');
            if (in_array($ostatus, ['cancelled', 'closed', 'trial'], true)) continue;
            $row = null;
            if (!empty($o['cur_sub_id'])) {
                $sub->execute([(int)$o['cur_sub_id']]);
                $row = $sub->fetch(PDO::FETCH_ASSOC) ?: null;
            }
            $base = ['org_id' => (int)$o['id'], 'name' => (string)$o['name'], 'slug' => (string)$o['slug'],
                     'monthly_price_cents' => $o['monthly_price_cents'] === null ? null : (int)$o['monthly_price_cents']];
            if ($row) {
                $sst = (string)($row['status'] ?? '');
                $live = in_array($sst, ['active', 'overdue', 'past_due', 'pending_payment'], true)
                     || ($sst === 'cancelled' && !empty($row['period_running']));
                if ((int)$row['plan_id'] === $aid && $live) {
                    $out[] = $base + ['source' => 'abonnement' . ($sst !== 'active' ? ' « ' . $sst . ' »' : ''),
                                      'subscription_id' => (int)$row['id'], 'subscription_status' => $sst];
                }
                continue;
            }
            if (!in_array($ostatus, ['active', ''], true)) continue;
            $legacy = ak_legacy_plan_row($pdo, (int)$o['id']);
            if ($legacy && ($legacy['slug'] ?? '') === 'assokit') {
                $out[] = $base + ['source' => 'ancien libellé « ' . (string)$legacy['legacy_plan'] . ' »', 'subscription_id' => null];
            }
        }
    } catch (Throwable $e) {
        error_log('[ak_fondateur_candidates] ' . get_class($e) . ' ' . $e->getMessage());
    }
    return $out;
}

// ============================================================
// 4. Statut abonnement (overdue, grâce, etc.)
// ============================================================

/**
 * Statut commercial de l'abonnement
 *
 * @return array ['status', 'is_overdue', 'in_grace_period', 'days_remaining', 'show_overlay']
 */
function ak_get_subscription_status(PDO $pdo, int $org_id): array {
    $plan = ak_get_current_plan($pdo, $org_id);
    // Essai (en cours / terminé) : même lecture que le cockpit fondateur (ak_org_plan_state)
    $ti = ak_plan_trial_info($pdo, $org_id);
    $trial = [
        'is_trial'        => $ti['in_trial'],
        'trial_expired'   => $ti['trial_expired'],
        'trial_paused'    => $ti['trial_paused'],
        'trial_ends_at'   => $ti['trial_ends_at'],
        'trial_days_left' => $ti['days_left'],
        'trial_label'     => $ti['label'],
        'badge'           => $ti['badge'],
        'badge_tone'      => $ti['badge_tone'],
        // Résiliation en cours : formule gardée jusqu'à access_until, puis Démarrage
        'cancel_scheduled' => $ti['cancel_scheduled'],
        'access_until'     => $ti['access_until'],
        'access_until_fr'  => $ti['access_until_fr'],
    ];
    if (!$plan) {
        return ['status' => 'none', 'is_overdue' => false, 'in_grace_period' => false, 'days_remaining' => 0, 'show_overlay' => false,
                'plan_label' => $ti['plan_label']] + $trial;
    }

    $status = (string)$plan['status'];
    // La ligne d'essai est écrite 'active' quand l'ENUM du statut n'a pas 'trial' :
    // c'est quand même un essai (seul ce cas change, les autres statuts restent tels quels).
    if ($status === 'active' && ($ti['in_trial'] || $ti['trial_paused']) && (!empty($plan['is_trial']) || ($plan['slug'] ?? '') === 'pro-essai')) {
        $status = 'trial';
    }
    $now = new DateTime();
    $in_grace = false;
    $days_remaining = 0;
    $show_overlay = false;
    $is_pending = ($status === 'pending_payment');

    // === [PACK 6.2 fix] pending_payment → afficher le bandeau orange ===
    // Si le paiement est en attente, on affiche le bandeau de rappel.
    // - Si current_period_end existe et est dépassée → calcul des jours restants
    // - Sinon → on affiche un message générique "paiement en attente"
    if ($status === 'pending_payment') {
        $in_grace = true;
        if (!empty($plan['current_period_end'])) {
            $period_end = new DateTime($plan['current_period_end']);
            if ($period_end > $now) {
                $days_remaining = max(1, (int)$period_end->diff($now)->format('%a'));
            } else {
                // Période expirée mais pas encore basculé en overdue par le CRON
                // → on affiche quand même 15 jours par défaut (en attendant le CRON)
                $days_remaining = 15;
            }
        } else {
            $days_remaining = 15; // valeur par défaut
        }
    }

    // === Cas overdue (grâce 15 jours en cours OU expirée) ===
    if ($status === 'overdue' && !empty($plan['grace_period_end'])) {
        $grace_end = new DateTime($plan['grace_period_end']);
        if ($grace_end > $now) {
            $in_grace = true;
            $days_remaining = max(1, (int)$grace_end->diff($now)->format('%a'));
        } else {
            // Grâce expirée → on AFFICHE l'overlay flou bloquant
            $show_overlay = true;
            $in_grace = false;
        }
    }

    return [
        'status' => $status,
        'is_overdue' => $status === 'overdue',
        'is_pending' => $is_pending,
        'in_grace_period' => $in_grace,
        'days_remaining' => $days_remaining,
        'show_overlay' => $show_overlay,
        'plan_name' => ak_plan_display_name($plan),   // « Essai Pro » pour le plan d'essai (asso_plans.name vaut « Essai »)
        'plan_slug' => $plan['slug'],
        'plan_label' => ak_plan_display_name($plan),
    ] + $trial;
}

// ============================================================
// 5. UI : Bandeau de quota (à afficher dans le dashboard)
// ============================================================

function ak_render_quota_banner(PDO $pdo, int $org_id): string {
    $plan = ak_get_current_plan($pdo, $org_id);
    if (!$plan) return '';

    $usage = ak_get_usage($pdo, $org_id);
    $alerts = [];

    // Vérifier chaque quota et alerter à 80%+
    $checks = [
        ['adherents', 'limit_adherents', 'membres (bureau compris)', '👥'],
        ['managers', 'limit_managers', 'gestionnaires', '🧑‍💼'],
        ['invoices_total', 'limit_invoices_total', 'factures', '📋'],
        ['quotes_total', 'limit_quotes_total', 'devis', '📝'],
        ['ai_text_month', 'limit_ai_text_per_month', 'crédits IA ce mois-ci', '🤖'],
        ['emails_month', 'limit_emails_per_month', 'e-mails groupés ce mois-ci', '📨'],
    ];

    foreach ($checks as [$usage_key, $limit_key, $label, $emoji]) {
        if (!isset($plan[$limit_key]) || $plan[$limit_key] === '') continue;
        $limit = (int)$plan[$limit_key];
        if ($limit <= 0) continue;
        $current = (int)($usage[$usage_key] ?? 0);
        $pct = ($current / $limit) * 100;

        if ($pct >= 100) {
            $alerts[] = ['level' => 'red', 'msg' => "{$emoji} <strong>Limite atteinte</strong> : {$current}/{$limit} {$label}", 'pct' => $pct];
        } elseif ($pct >= 90) {
            $alerts[] = ['level' => 'orange', 'msg' => "{$emoji} <strong>Quasi-limite</strong> : {$current}/{$limit} {$label} (" . round($pct) . "%)", 'pct' => $pct];
        } elseif ($pct >= 80) {
            $alerts[] = ['level' => 'yellow', 'msg' => "{$emoji} {$current}/{$limit} {$label} (" . round($pct) . "%)", 'pct' => $pct];
        }
    }

    if (empty($alerts)) return '';

    // Trier par sévérité (red > orange > yellow)
    usort($alerts, fn($a, $b) => $b['pct'] <=> $a['pct']);
    $top = $alerts[0];

    $colors = [
        'red'    => ['bg' => '#FEE2E2', 'border' => '#DC2626', 'color' => '#991B1B'],
        'orange' => ['bg' => '#FED7AA', 'border' => '#EA580C', 'color' => '#9A3412'],
        'yellow' => ['bg' => '#FEF3C7', 'border' => '#F59E0B', 'color' => '#92400E'],
    ];
    $c = $colors[$top['level']];

    $html = '<div style="background:' . $c['bg'] . ';border-left:4px solid ' . $c['border'] . ';color:' . $c['color'] . ';padding:14px 18px;border-radius:10px;margin-bottom:18px;display:flex;align-items:center;gap:14px;flex-wrap:wrap;">';
    $html .= '<div style="flex:1;min-width:200px;">';
    $html .= '<div style="font-size:14px;line-height:1.5;">' . $top['msg'] . '</div>';
    if (count($alerts) > 1) {
        $html .= '<div style="font-size:12px;opacity:0.85;margin-top:4px;">+ ' . (count($alerts) - 1) . ' autre' . (count($alerts) > 2 ? 's' : '') . ' quota' . (count($alerts) > 2 ? 's' : '') . ' à surveiller</div>';
    }
    $html .= '</div>';

    // Application mobile, compte d'examen : aucun lien vers les formules (Apple 3.1.1)
    if (function_exists('ak_billing_hidden') && ak_billing_hidden()) {
        // rien
    } elseif ($plan['slug'] === 'demarrage') {
        $html .= '<a href="/mon-asso-plan" style="background:' . $c['border'] . ';color:white;padding:8px 16px;border-radius:8px;font-size:13px;font-weight:600;text-decoration:none;white-space:nowrap;">Voir les formules</a>';
    } elseif (!empty($plan['is_trial']) || $plan['slug'] === 'pro-essai') {
        $html .= '<a href="/mon-asso-plan" style="background:' . $c['border'] . ';color:white;padding:8px 16px;border-radius:8px;font-size:13px;font-weight:600;text-decoration:none;white-space:nowrap;">⚡ Choisir une formule</a>';
    } else {
        $html .= '<a href="/mon-asso-plan" style="background:' . $c['border'] . ';color:white;padding:8px 16px;border-radius:8px;font-size:13px;font-weight:600;text-decoration:none;white-space:nowrap;">Voir mon plan</a>';
    }

    $html .= '</div>';
    return $html;
}

// ============================================================
// 6. UI : Overlay régularisation (15j passés sans paiement)
// ============================================================

function ak_render_overdue_overlay(PDO $pdo, int $org_id): string {
    $status = ak_get_subscription_status($pdo, $org_id);
    if (!$status['show_overlay']) return '';

    // Application mobile (Apple 3.1.1, règles de paiement de Google Play) : la fonctionnalité reste
    // bloquée, mais sans aucun mot ni lien de paiement
    if (function_exists('ak_billing_hidden') && ak_billing_hidden()) {
        return '<div id="ak-overdue-overlay" style="position:fixed;inset:0;background:rgba(15,23,42,0.85);z-index:9999;display:flex;align-items:center;justify-content:center;padding:20px;">'
             . '<div style="background:white;max-width:480px;width:100%;border-radius:20px;padding:32px 26px;text-align:center;">'
             . '<div style="font-size:48px;margin-bottom:12px;">🔒</div>'
             . '<h2 style="margin:0 0 10px;font-size:20px;color:#0F172A;">Fonctionnalité indisponible</h2>'
             . '<p style="color:#475569;line-height:1.6;margin:0;font-size:15px;">Cette fonctionnalité est momentanément indisponible pour votre association. Vos données sont en sécurité et le reste de votre espace reste accessible. L’administrateur de votre association peut nous contacter.</p>'
             . '</div></div>';
    }

    $html = '<div id="ak-overdue-overlay" style="position:fixed;inset:0;background:rgba(15,23,42,0.85);backdrop-filter:blur(8px);z-index:9999;display:flex;align-items:center;justify-content:center;padding:20px;overflow-y:auto;">';
    $html .= '<div style="background:white;max-width:560px;width:100%;border-radius:20px;padding:40px 32px;box-shadow:0 25px 60px rgba(0,0,0,0.4);text-align:center;">';
    $html .= '<div style="font-size:64px;margin-bottom:16px;">⚠️</div>';
    $html .= '<h2 style="margin:0 0 12px;font-size:24px;color:#0F172A;">Régularisez votre facture</h2>';
    $html .= '<p style="color:#475569;line-height:1.6;margin:0 0 8px;font-size:16px;">';
    $html .= 'Votre période de tolérance de 15 jours est écoulée. Pour continuer à utiliser le plan <strong>' . htmlspecialchars($status['plan_name'], ENT_QUOTES, 'UTF-8') . '</strong>, merci de régulariser votre paiement.';
    $html .= '</p>';
    $html .= '<p style="color:#64748B;font-size:13px;margin:0 0 24px;">🔒 Vos données sont en sécurité et restent accessibles.</p>';

    $html .= '<div style="display:flex;flex-direction:column;gap:10px;">';
    // URL régulariser dynamique : Stripe direct si configuré
    $regularize_url = function_exists('ak_get_regularize_url') ? ak_get_regularize_url($pdo, $org_id) : '/contact?subject=billing';
    // 1. CTA principal — Régulariser
    $html .= '<a href="' . htmlspecialchars($regularize_url, ENT_QUOTES, 'UTF-8') . '" style="background:linear-gradient(180deg,#059669 0%,#047857 100%);color:white;padding:14px 24px;border-radius:12px;text-decoration:none;font-weight:600;font-size:15px;box-shadow:0 4px 14px rgba(5,150,105,0.30);">💳 Régulariser le paiement</a>';
    // 2. CTA secondaire — Contact (NOUVEAU)
    $html .= '<a href="/contact?subject=billing" style="background:#F0F9FF;color:#0C4A6E;padding:13px 24px;border-radius:12px;text-decoration:none;font-weight:600;font-size:14px;border:1px solid #BAE6FD;display:inline-flex;align-items:center;justify-content:center;gap:8px;">📞 Nous contacter — On va trouver une solution</a>';
    // 3. CTA tertiaire — Arrêt (en mode discret) : page de confirmation (POST + CSRF), qui arrête
    // l'abonnement impayé ; l'association passe en accès limité (Démarrage, formule qui n'est plus proposée)
    $html .= '<a href="/mon-asso-annuler-abonnement" style="background:transparent;color:#64748B;padding:10px 24px;border-radius:12px;text-decoration:none;font-size:13px;border:1px solid #E2E8F0;">↓ Arrêter mon abonnement</a>';
    $html .= '</div>';

    $html .= '<p style="color:#DC2626;font-size:12px;margin:18px 0 0;line-height:1.5;">';
    $html .= '⚠️ <strong>Attention</strong> : en arrêtant votre abonnement, votre association passe en accès limité : vos données restent intactes, consultables et exportables, mais les fonctions de votre formule ne sont plus disponibles.';
    $html .= '</p>';
    $html .= '</div></div>';

    return $html;
}

/**
 * Bandeau "régularisez avant J-X" pendant la période de grâce
 */
function ak_render_grace_banner(PDO $pdo, int $org_id): string {
    $status = ak_get_subscription_status($pdo, $org_id);
    if (!$status['in_grace_period']) return '';
    // Application mobile : aucun rappel de paiement (Apple 3.1.1, règles de paiement de Google Play)
    if (function_exists('ak_billing_hidden') && ak_billing_hidden()) return '';

    $days = max(1, (int)$status['days_remaining']);
    $is_pending = !empty($status['is_pending']);

    // Message adapté selon le statut
    if ($is_pending) {
        $main_text = '⏳ <strong>Paiement en attente</strong> — Votre prochain règlement pour le plan <strong>' . htmlspecialchars($status['plan_name'], ENT_QUOTES, 'UTF-8') . '</strong> sera bientôt traité.';
        $sub_text = 'Vous bénéficiez de <strong>' . $days . ' jour' . ($days > 1 ? 's' : '') . '</strong> de tolérance pour régulariser. Vos accès restent complets pendant cette période.';
    } else {
        $main_text = '⏳ <strong>À régulariser sous ' . $days . ' jour' . ($days > 1 ? 's' : '') . '</strong> — Votre paiement est en attente. Pour conserver le plan <strong>' . htmlspecialchars($status['plan_name'], ENT_QUOTES, 'UTF-8') . '</strong>, merci de régulariser.';
        $sub_text = '';
    }

    $html = '<div style="background:linear-gradient(135deg,#FED7AA 0%,#FEF3C7 100%);border:1px solid #FB923C;border-left:4px solid #EA580C;color:#9A3412;padding:16px 20px;border-radius:12px;margin-bottom:18px;display:flex;align-items:center;gap:16px;flex-wrap:wrap;box-shadow:0 2px 8px rgba(234,88,12,0.10);">';
    $html .= '<div style="flex:1;min-width:240px;font-size:14px;line-height:1.55;">';
    $html .= '<div>' . $main_text . '</div>';
    if ($sub_text) {
        $html .= '<div style="font-size:13px;margin-top:4px;color:#7C2D12;">' . $sub_text . '</div>';
    }
    $html .= '</div>';
    $html .= '<div style="display:flex;gap:8px;flex-wrap:wrap;">';
    // URL régulariser dynamique : Stripe direct si configuré, sinon /contact
    $regularize_url = function_exists('ak_get_regularize_url') ? ak_get_regularize_url($pdo, $org_id) : '/contact?subject=billing';
    $html .= '<a href="' . htmlspecialchars($regularize_url, ENT_QUOTES, 'UTF-8') . '" style="background:#EA580C;color:white;padding:9px 18px;border-radius:8px;font-size:13px;font-weight:600;text-decoration:none;white-space:nowrap;display:inline-flex;align-items:center;gap:6px;">💳 Régulariser</a>';
    $html .= '<a href="/contact?subject=billing" style="background:white;color:#9A3412;padding:9px 16px;border-radius:8px;font-size:13px;font-weight:600;text-decoration:none;white-space:nowrap;border:1px solid #FDBA74;">📞 Nous contacter</a>';
    $html .= '</div>';
    $html .= '</div>';

    return $html;
}

} // end define guard
