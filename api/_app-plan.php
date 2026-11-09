<?php
/**
 * api/_app-plan.php — Formule de l'association dans l'application (grille de novembre 2026).
 *
 * Petites fonctions « app_plan_* » qui s'appuient sur les fondations (plan-helpers.php :
 * ak_plan_has, ak_feature_denied_json, ak_plan_is_readonly, ak_ai_quota_block, ak_email_quota_block,
 * ak_can_add_manager, ak_plan_public_summary…), pour les endpoints api/app-*.php.
 *
 * Règles :
 *   - si une fonction des fondations manque ou échoue, on LAISSE PASSER (comportement d'avant la grille) ;
 *   - aucun verrou tant que la grille n'est pas en service en base (asso_plans.grille_version,
 *     posée par migrations/2026-11-09-grille-tarifaire.sql) : mêmes règles que le site
 *     (plan-controls.php, akc_grille_live / akc_readonly_live), sans en dépendre ;
 *   - comptes plateforme (Fondateur, super-admin) jamais verrouillés ;
 *   - règle Apple 3.1.1 : jamais de prix, de lien de paiement ni d'invitation à changer de formule.
 *     Les messages sont neutres (« Cette fonction n'est pas incluse dans votre formule. »).
 *
 * Inclus uniquement par les endpoints de l'appli (aucune sortie).
 */
if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) { http_response_code(404); exit; }

if (!function_exists('app_plan_load')) {

    /** Charge les fondations (plan-helpers.php) sans polluer le JSON. Vrai si elles sont là. */
    function app_plan_load(): bool {
        if (function_exists('ak_plan_has')) return true;
        $f = __DIR__ . '/../plan-helpers.php';
        if (is_file($f)) {
            ob_start();
            try { require_once $f; } catch (Throwable $e) { error_log('[app_plan_load] ' . get_class($e)); }
            ob_end_clean();
        }
        return function_exists('ak_plan_has');
    }

    /** La grille est-elle en service (au moins une ligne asso_plans portant grille_version) ? */
    function app_plan_live(PDO $pdo): bool {
        static $live = null;
        if ($live !== null) return $live;
        try {
            $live = (int) $pdo->query("SELECT COALESCE(MAX(grille_version), 0) FROM asso_plans")->fetchColumn() >= 202611;
        } catch (Throwable $e) {
            $live = false;   // colonne absente : migration pas encore passée
        }
        return $live;
    }

    /** L'accès limité de Démarrage est-il en service (grille appliquée à la ligne demarrage) ? */
    function app_plan_readonly_live(PDO $pdo): bool {
        static $live = null;
        if ($live !== null) return $live;
        // Même règle que akc_readonly_live (plan-controls.php) : grille appliquée à demarrage ET préavis
        // de 30 jours écoulé (constante AK_DEMARRAGE_READONLY_LIVE posée dans config.php par le Fondateur).
        if (!defined('AK_DEMARRAGE_READONLY_LIVE') || !AK_DEMARRAGE_READONLY_LIVE) return $live = false;
        try {
            $v = $pdo->query("SELECT grille_version FROM asso_plans WHERE slug = 'demarrage' LIMIT 1")->fetchColumn();
            $live = $v !== false && $v !== null && (int) $v >= 202611;
        } catch (Throwable $e) {
            $live = false;
        }
        return $live;
    }

    /** Compte plateforme (Fondateur, super-admin) : jamais verrouillé par la formule d'une association. */
    function app_plan_is_platform(?array $user): bool {
        if (!$user) return false;
        if (function_exists('ak_user_is_platform') && ak_user_is_platform($user)) return true;
        if (!empty($user['is_super_admin']) || !empty($user['is_founder']) || !empty($user['is_platform_admin'])
            || in_array((string) ($user['role'] ?? ''), ['super_admin', 'founder'], true)) return true;
        // current_user() ne porte pas toujours is_founder : même détection que l'espace Fondateur de l'appli
        if (function_exists('app_is_founder') && isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) {
            try { return app_is_founder($GLOBALS['pdo'], $user); } catch (Throwable $e) {}
        }
        return false;
    }

    /**
     * La formule de l'association a-t-elle cette fonction ('mail_inbox' ou 'feature_mail_inbox') ?
     * Vrai en cas de doute : fondations absentes, grille pas en service, erreur, pas d'association,
     * compte plateforme ($user fourni).
     */
    function app_plan_has(PDO $pdo, int $org_id, string $feature, ?array $user = null): bool {
        if ($org_id <= 0) return true;
        if ($user !== null && app_plan_is_platform($user)) return true;
        if (!app_plan_load() || !app_plan_live($pdo)) return true;
        try {
            return (bool) ak_plan_has($pdo, $org_id, $feature);
        } catch (Throwable $e) {
            error_log('[app_plan_has] ' . get_class($e));
            return true;
        }
    }

    /** Message neutre d'une fonction non incluse (jamais de nom de formule supérieure ni de prix). */
    function app_plan_locked_message(): string {
        return 'Cette fonction n’est pas incluse dans votre formule.';
    }

    /**
     * Réponse « fonction non incluse » : ak_feature_denied_json() des fondations (HTTP 403,
     * {ok:false, error:'plan_feature', feature, message}) ou son équivalent.
     */
    function app_plan_denied(PDO $pdo, int $org_id, string $feature): array {
        if (function_exists('ak_feature_denied_json')) {
            try {
                $out = ak_feature_denied_json($pdo, $org_id, $feature);
                if (is_array($out) && !empty($out['message'])) return $out;
            } catch (Throwable $e) {}
        }
        if (!headers_sent()) http_response_code(403);
        $col = function_exists('ak_plan_feature_col') ? (string) ak_plan_feature_col($feature) : $feature;
        return ['ok' => false, 'error' => 'plan_feature', 'feature' => $col, 'message' => app_plan_locked_message()];
    }

    /** Fonctions (feature_*) non incluses dans la formule, pour griser les entrées de l'appli. */
    function app_plan_locked_features(PDO $pdo, int $org_id, ?array $user = null): array {
        if ($org_id <= 0 || ($user !== null && app_plan_is_platform($user))) return [];
        if (!app_plan_load() || !app_plan_live($pdo) || !function_exists('ak_plan_feature_keys')) return [];
        $out = [];
        foreach (array_keys(ak_plan_feature_keys()) as $k) {
            // Domaine personnel et interlocuteur unique : rien à verrouiller dans l'appli
            if (in_array($k, ['feature_custom_domain', 'feature_dedicated_support', 'feature_priority_support'], true)) continue;
            if (!app_plan_has($pdo, $org_id, $k)) $out[] = $k;
        }
        return $out;
    }

    /** Accès limité (formule Démarrage, une fois la grille appliquée) ? Faux en cas de doute. */
    function app_plan_readonly(PDO $pdo, int $org_id, ?array $user = null): bool {
        if ($org_id <= 0) return false;
        if ($user !== null && app_plan_is_platform($user)) return false;
        if (!app_plan_load() || !function_exists('ak_plan_is_readonly')) return false;
        if (!app_plan_readonly_live($pdo)) return false;
        try { return (bool) ak_plan_is_readonly($pdo, $org_id); } catch (Throwable $e) { return false; }
    }

    /** Texte de l'accès limité (sans prix ni bouton). */
    function app_plan_readonly_message(): string {
        if (function_exists('ak_readonly_message')) {
            try { $m = (string) ak_readonly_message(); if ($m !== '') return $m; } catch (Throwable $e) {}
        }
        return 'Votre espace est en accès limité : vos données restent consultables et exportables.';
    }

    /** Crédits IA d'un appel lancé par un utilisateur : null = permis, sinon le message. */
    function app_plan_ai_block(PDO $pdo, int $org_id, int $credits = 1): ?string {
        if ($org_id <= 0 || $credits <= 0) return null;
        if (!app_plan_load() || !function_exists('ak_ai_quota_block') || !app_plan_live($pdo)) return null;
        try { return ak_ai_quota_block($pdo, $org_id, $credits); } catch (Throwable $e) { return null; }
    }

    /** E-mails groupés pour $n destinataires : null = permis, sinon le message. */
    function app_plan_email_block(PDO $pdo, int $org_id, int $n): ?string {
        if ($org_id <= 0 || $n <= 0) return null;
        if (!app_plan_load() || !function_exists('ak_email_quota_block') || !app_plan_live($pdo)) return null;
        try { return ak_email_quota_block($pdo, $org_id, $n); } catch (Throwable $e) { return null; }
    }

    /**
     * Ajout d'un gestionnaire (création d'un compte admin / coordinateur / référent, ou passage d'un
     * compte à l'un de ces rôles) : null = permis, sinon le message des fondations.
     * $old_role = '' pour une création (ou la réinscription d'une fiche supprimée).
     */
    function app_plan_manager_block(PDO $pdo, int $org_id, string $new_role, string $old_role = ''): ?string {
        if ($org_id <= 0) return null;
        if (!app_plan_load() || !app_plan_live($pdo)) return null;
        try {
            if ($old_role !== '' && function_exists('ak_role_change_allowed')) {
                $r = ak_role_change_allowed($pdo, $org_id, $old_role, $new_role);
                return !empty($r['ok']) ? null : ((string) ($r['reason'] ?? '') ?: 'Limite de gestionnaires atteinte.');
            }
            $roles = function_exists('ak_manager_roles') ? ak_manager_roles() : ['admin', 'coordinator', 'referent'];
            if (!in_array($new_role, $roles, true) || !function_exists('ak_can_add_manager')) return null;
            $r = ak_can_add_manager($pdo, $org_id, 1);
            return !empty($r['ok']) ? null : ((string) ($r['reason'] ?? '') ?: 'Limite de gestionnaires atteinte.');
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * L'incarnation en cours (session du site partagée avec l'appli) a-t-elle été lancée par un compte
     * plateforme (Fondateur, super-admin) ? Pendant l'incarnation, current_user() est le compte de la
     * cible : le compte réel est relu en base (impersonation_admin_user_id). Faux en cas de doute.
     */
    function app_plan_impersonator_is_platform(PDO $pdo): bool {
        if (session_status() !== PHP_SESSION_ACTIVE) return false;
        $admin = (int) ($_SESSION['impersonation_admin_user_id'] ?? 0);
        if ($admin <= 0 || empty($_SESSION['impersonation_target_user_id'])) return false;
        try {
            $st = $pdo->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
            $st->execute([$admin]);
            $a = $st->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return false;
        }
        if (!$a) return false;
        if (!empty($a['is_super_admin']) || !empty($a['is_founder']) || !empty($a['is_platform_admin'])
            || in_array((string) ($a['role'] ?? ''), ['super_admin', 'founder'], true)) return true;
        $email = strtolower(trim((string) ($a['email'] ?? '')));
        return $email !== '' && function_exists('app_founder_emails') && in_array($email, (array) app_founder_emails(), true);
    }

    /**
     * Ajout d'un membre (fiche adhérent, bureau compris) : null = permis, sinon le message, neutre et
     * sans nom de formule : « Votre formule permet 50 membres (bureau compris) : limite atteinte. »
     * null tant que la grille n'est pas en service, pour un compte plateforme (Fondateur, super-admin,
     * y compris quand il incarne un compte de l'association) et en cas de doute.
     */
    function app_plan_member_block(PDO $pdo, int $org_id, ?array $user = null): ?string {
        if ($org_id <= 0) return null;
        if ($user !== null && app_plan_is_platform($user)) return null;
        if (app_plan_impersonator_is_platform($pdo)) return null;
        if (!app_plan_load() || !app_plan_live($pdo) || !function_exists('ak_can_add_adherent')) return null;
        try {
            if (function_exists('ak_get_current_plan') && !ak_get_current_plan($pdo, $org_id)) return null;   // formule illisible : on laisse passer
            $r = ak_can_add_adherent($pdo, $org_id);
        } catch (Throwable $e) {
            error_log('[app_plan_member_block] ' . get_class($e));
            return null;
        }
        if (!empty($r['ok'])) return null;
        $l = isset($r['limit']) && $r['limit'] !== null ? (int) $r['limit'] : 0;
        if ($l <= 0) return 'Votre formule ne permet pas d’ajouter de membre.';
        return 'Votre formule permet ' . app_plan_int($l) . ' membre' . ($l > 1 ? 's' : '') . ' (bureau compris) : limite atteinte.';
    }

    /**
     * Fonctions incluses dans une formule (ligne ak_get_current_plan) : libellés d'ak_plan_feature_keys()
     * dont la colonne vaut 1, dans l'ordre de la grille. Jamais le domaine personnel (pas encore
     * disponible). Colonne absente (migration pas encore passée) : fonction non citée.
     */
    function app_plan_included(?array $plan): array {
        if (!$plan || !function_exists('ak_plan_feature_keys')) return [];
        if (($plan['slug'] ?? '') === 'demarrage') return [];
        $out = [];
        foreach (ak_plan_feature_keys() as $k => $label) {
            if ($k === 'feature_custom_domain') continue;
            if (!array_key_exists($k, $plan) || empty($plan[$k])) continue;
            $out[] = (string) $label;
        }
        return $out;
    }

    /** Options du compteur IA unifié (ai_usage_ledger) pour ask_claude() : chemin, association, utilisateur. */
    function app_plan_ai_opts(string $path, int $org_id = 0, int $user_id = 0, array $extra = []): array {
        $o = ['path' => $path];
        if ($org_id > 0) $o['org_id'] = $org_id;
        if ($user_id > 0) $o['user_id'] = $user_id;
        return $extra + $o;
    }

    /** « 1er décembre » : date à laquelle les compteurs mensuels repartent. */
    function app_plan_next_month(): string {
        if (function_exists('ak_plan_next_month_label')) {
            try { return (string) ak_plan_next_month_label(); } catch (Throwable $e) {}
        }
        $mois = [1 => 'janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
        return '1er ' . $mois[(int) date('n', strtotime('first day of next month'))];
    }

    /** Entier au format français (« 2 000 », espace insécable). */
    function app_plan_int($n): string {
        return function_exists('ak_plan_fmt_int') ? ak_plan_fmt_int($n) : number_format((int) $n, 0, ',', "\u{00A0}");
    }

    /**
     * Carte « Ma formule » de l'écran Paramètres : nom de la formule, phrase d'accroche, résumé SANS
     * prix (ak_plan_public_summary), compteurs du mois (gestionnaires seulement) et états
     * particuliers (essai, offre fondateur, accès limité, quotas atteints). Jamais de prix, de
     * bouton de paiement, de « Passer à… » ni de badge « Recommandé ». null si illisible.
     * $show_usage : compteurs du mois (réservés aux gestionnaires de l'association).
     */
    function app_plan_card(PDO $pdo, int $org_id, bool $show_usage = true): ?array {
        if ($org_id <= 0 || !app_plan_load() || !function_exists('ak_get_current_plan')) return null;
        try {
            $plan = ak_get_current_plan($pdo, $org_id);
        } catch (Throwable $e) {
            return null;
        }
        if (!$plan) return null;
        $slug = (string) ($plan['slug'] ?? '');
        $ti = function_exists('ak_plan_trial_info') ? ak_plan_trial_info($pdo, $org_id) : [];
        $in_trial = !empty($ti['in_trial']);
        $name = $in_trial && !empty($ti['trial_name']) ? (string) $ti['trial_name']
              : (function_exists('ak_plan_display_name') ? ak_plan_display_name($plan) : (string) ($plan['name'] ?? $slug));
        $readonly = app_plan_readonly($pdo, $org_id);
        $summary = [];
        if (function_exists('ak_plan_public_summary')) {
            try { $summary = array_values(array_map('strval', ak_plan_public_summary($plan))); } catch (Throwable $e) { $summary = []; }
        }

        $states = [];
        if ($in_trial) {
            $states[] = ['tone' => 'trial', 'text' => 'Essai Pro en cours' . (!empty($ti['end_fr']) ? ', jusqu’au ' . $ti['end_fr'] : '')
                . '. Pendant l’essai, les envois et les imports sont plafonnés.'];
        }
        // Offre fondateur : garantie lue dans asso_subscriptions.price_locked_until (jamais de prix), seulement
        // tant que la ligne est sur l'offre fondateur : la date reste en base après un passage à Pro
        // (garantie posée une seule fois), mais la garantie prend fin en quittant l'offre (ak_pc_price_locked_until)
        if (!empty($plan['subscription_id']) && $slug === 'fondateur') {
            try {
                $q = $pdo->prepare("SELECT s.price_locked_until FROM asso_subscriptions s JOIN asso_plans p ON p.id = s.plan_id
                                    WHERE s.id = ? AND p.slug = 'fondateur' LIMIT 1");
                $q->execute([(int) $plan['subscription_id']]);
                $locked = (string) ($q->fetchColumn() ?: '');
                if ($locked !== '' && strtotime($locked) > time()) {
                    $states[] = ['tone' => 'info', 'text' => 'Vos fonctions sont garanties jusqu’au ' . date('d/m/Y', strtotime($locked)) . '.'];
                }
            } catch (Throwable $e) {}   // colonne absente : migration pas encore passée
        }
        if ($readonly) $states[] = ['tone' => 'limit', 'text' => app_plan_readonly_message()];

        $usage = [];
        if ($show_usage && !$readonly && $slug !== 'demarrage' && function_exists('ak_get_usage')) {
            try { $u = ak_get_usage($pdo, $org_id); } catch (Throwable $e) { $u = []; }
            $lim = function (string $k) use ($plan) {
                if (!array_key_exists($k, $plan)) return false;          // colonne absente : ligne non affichée
                return ($plan[$k] === null || $plan[$k] === '') ? null : (int) $plan[$k];
            };
            $rows = [
                ['members',  'Membres',          'adherents',     'limit_adherents'],
                ['managers', 'Gestionnaires',    'managers',      'limit_managers'],
                ['ai',       'Crédits IA',       'ai_text_month', 'limit_ai_text_per_month'],
                ['emails',   'E-mails envoyés',  'emails_month',  'limit_emails_per_month'],
            ];
            $email_on = !array_key_exists('feature_email_diffusion', $plan) || !empty($plan['feature_email_diffusion']);
            foreach ($rows as [$key, $label, $ukey, $lkey]) {
                $l = $lim($lkey);
                if ($l === false) continue;
                if ($key === 'emails' && !$email_on) continue;
                if ($l === 0) continue;                       // non inclus : jamais affiché comme un compteur
                // Crédits IA et e-mails : jamais « sans plafond » (pas d'« IA illimitée »)
                if ($l === null && in_array($key, ['ai', 'emails'], true)) continue;
                $n = (int) ($u[$ukey] ?? 0);
                $usage[] = [
                    'key'   => $key,
                    'label' => $label,
                    'used'  => $n,
                    'limit' => $l,
                    'full'  => $l !== null && $n >= $l,
                    'text'  => $label . ' : ' . app_plan_int($n) . ($l === null ? ', sans plafond' :' sur ' . app_plan_int($l)),
                ];
                if ($l !== null && $n >= $l) {
                    if ($key === 'ai') {
                        $states[] = ['tone' => 'limit', 'text' => 'Les crédits IA de ce mois sont utilisés. Ils seront de nouveau disponibles le ' . app_plan_next_month() . '.'];
                    } elseif ($key === 'emails') {
                        $states[] = ['tone' => 'limit', 'text' => 'Le volume d’e-mails de ce mois est atteint. Il sera de nouveau disponible le ' . app_plan_next_month()
                            . '. Les factures et devis envoyés un par un ne sont pas concernés.'];
                    } elseif ($key === 'managers') {
                        $states[] = ['tone' => 'limit', 'text' => 'Votre formule permet ' . app_plan_int($l) . ' gestionnaire' . ($l > 1 ? 's' : '')
                            . ' (administrateurs, coordinateurs, référents) : limite atteinte.'];
                    }
                }
            }
        }

        // Démarrage sans l'accès limité en service (AK_DEMARRAGE_READONLY_LIVE) : ni « Accès limité » en
        // accroche, ni le résumé de l'accès limité (même règle que le site, akc_readonly)
        $demarrage_open = $slug === 'demarrage' && !$readonly;
        return [
            'slug'    => $slug,
            'name'    => $name,
            // Accroche de la formule : rien pendant l'essai ni pour la ligne d'essai (texte commercial)
            'tagline' => ($readonly || $in_trial || $slug === 'pro-essai' || $demarrage_open) ? '' : trim((string) ($plan['tagline'] ?? '')),
            'summary' => ($readonly || $demarrage_open) ? [] : $summary,
            // Fonctions incluses (libellés, sans prix ni domaine personnel) : liste « Inclus : … »
            'included' => $readonly ? [] : app_plan_included($plan),
            'trial'   => $in_trial,
            'usage'   => $usage,
            'states'  => $states,
            'readonly' => $readonly,
            'notes'   => $usage ? [
                'Les crédits IA et les e-mails repartent à zéro le 1er de chaque mois.',
                '1 crédit IA = 1 texte rédigé, 1 réponse du copilote ou 1 justificatif lu. Les tâches de fond ne consomment pas de crédit. '
                . 'Les factures et devis envoyés un par un ne sont pas comptés dans les e-mails.',
            ] : [],
            'footer'  => 'Votre formule est gérée par l’administrateur de votre structure. Une question ? Écrivez au support depuis l’onglet Plus, rubrique Support.',
        ];
    }
}
