<?php
/**
 * includes-plan-usage.php — Carte « Ce que comprend votre formule » de l'espace client (site).
 * --------------------------------------------------------------
 * ak_render_plan_usage_card($pdo, $org_id, $compact, $opts) : formule courante (ak_get_current_plan),
 * utilisation du mois (ak_get_usage, lecture seule), phrases de la formule (ak_plan_public_summary) et
 * fonctions incluses (ak_plan_feature_keys à 1, sans le domaine personnel, pas encore livré).
 *
 *   - 4 jauges : Membres (bureau compris), Gestionnaires, Crédits IA ce mois-ci, E-mails groupés ce
 *     mois-ci ; « repart à zéro le 1er … » sous les compteurs mensuels (ak_plan_next_month_label) ;
 *   - jamais « illimité » pour l'IA ni les e-mails (limite vide : le compteur seul) ; jauge masquée
 *     si la limite vaut 0 (fonction non incluse) ;
 *   - offre fondateur : garantie jusqu'au … (ak_pc_price_locked_until) ;
 *   - AUCUN prix. Lien « Mon abonnement » (version compacte) pour un administrateur seulement, sur
 *     le site (jamais dans l'appli ni pour le compte d'examen : ak_billing_hidden, Apple 3.1.1).
 *
 * Ne dépend pas d'api/_app-plan.php. Fonctionne avant et après la migration de la grille : colonne
 * absente = jauge ou fonction non affichée, toute erreur = carte vide ('').
 * $opts : 'guarantee' => false (la page affiche déjà la garantie), 'link' => false (pas de lien),
 *         'title' => titre de la carte, 'status_note' => false (Démarrage : la page dit déjà où en est
 *         l'association).
 * --------------------------------------------------------------
 */

if (!function_exists('ak_render_plan_usage_card')) {

if (!function_exists('ak_get_current_plan') && is_file(__DIR__ . '/plan-helpers.php')) require_once __DIR__ . '/plan-helpers.php';

/**
 * Données de la carte (sans HTML), ou null (pas d'association, pas de formule, erreur).
 * @return array{name:string, slug:string, in_trial:bool, gauges:array, summary:array, features:array,
 *               guarantee_fr:string, readonly:bool, demarrage:bool, reset_label:string}|null
 */
function ak_plan_usage_data(PDO $pdo, int $org_id): ?array {
    if ($org_id <= 0 || !function_exists('ak_get_current_plan') || !function_exists('ak_get_usage')) return null;
    try {
        $plan = ak_get_current_plan($pdo, $org_id);
        if (!$plan) return null;
        $usage = ak_get_usage($pdo, $org_id);
        $ti = function_exists('ak_plan_trial_info') ? ak_plan_trial_info($pdo, $org_id) : [];
    } catch (Throwable $e) {
        error_log('[ak_plan_usage_data] org #' . $org_id . ' : ' . get_class($e));
        return null;
    }
    $slug = (string)($plan['slug'] ?? '');
    $in_trial = !empty($ti['in_trial']);
    // Essai terminé ou suspendu (ligne d'essai encore en place, avant le passage du cron) : plus de formule
    // à présenter, la carte n'est pas affichée (le bandeau d'essai dit quoi faire)
    $trial_row = $slug === 'pro-essai' || !empty($plan['is_trial']);
    $trial_over = !$in_trial && (!empty($ti['trial_expired']) || !empty($ti['trial_paused']) || $trial_row);
    $name = $in_trial && !empty($ti['trial_name']) ? (string)$ti['trial_name']
          : (function_exists('ak_plan_display_name') ? ak_plan_display_name($plan) : (string)($plan['name'] ?? $slug));
    // App iPhone : aucun essai n'y est présenté (règle 3.1.1 d'Apple, comme l'appli native)
    if (($in_trial || $trial_row) && function_exists('ak_is_ios_app') && ak_is_ios_app()) $name = 'Votre formule actuelle';
    // Limite : null = colonne absente (jauge non affichée), '' = vide (sans plafond), sinon l'entier
    $lim = function (string $k) use ($plan) {
        if (!array_key_exists($k, $plan)) return null;
        return ($plan[$k] === null || $plan[$k] === '') ? '' : (int)$plan[$k];
    };
    $fmt = function ($n): string {
        return function_exists('ak_plan_fmt_int') ? ak_plan_fmt_int($n) : number_format((int)$n, 0, ',', "\u{00A0}");
    };
    // Bonus de crédits IA de la première semaine d'abonnement (même règle qu'ak_can_use_ai_text)
    $ai_bonus = 0;
    if (!empty($plan['bonus_ai_first_week']) && !empty($plan['started_at'])
        && (time() - strtotime((string)$plan['started_at'])) / 86400 <= 7) {
        $ai_bonus = (int)$plan['bonus_ai_first_week'];
    }
    $mail_on = !array_key_exists('feature_email_diffusion', $plan) || !empty($plan['feature_email_diffusion']);
    $rows = [
        // [clé d'usage, colonne de limite, libellé, mensuel, « sans plafond » permis]
        ['adherents',     'limit_adherents',         'Membres (bureau compris)', false, true],
        ['managers',      'limit_managers',          'Gestionnaires',            false, true],
        ['ai_text_month', 'limit_ai_text_per_month', 'Crédits IA ce mois-ci',    true,  false],
        ['emails_month',  'limit_emails_per_month',  'E-mails groupés ce mois-ci', true, false],
    ];
    $gauges = [];
    foreach ($rows as [$uk, $lk, $label, $monthly, $uncapped_ok]) {
        if ($lk === 'limit_emails_per_month' && !$mail_on) continue;
        $limit = $lim($lk);
        if ($limit === null) continue;                    // colonne absente
        if ($limit !== '' && $limit <= 0) continue;       // 0 : non incluse, jauge masquée
        if ($lk === 'limit_ai_text_per_month' && $limit !== '') $limit += $ai_bonus;
        $used = max(0, (int)($usage[$uk] ?? 0));
        $g = ['key' => $uk, 'label' => $label, 'used' => $used, 'used_fr' => $fmt($used), 'monthly' => $monthly,
              'limit' => $limit === '' ? null : $limit, 'limit_fr' => $limit === '' ? '' : $fmt($limit),
              'pct' => null, 'flag' => '', 'note' => ''];
        if ($limit === '') {
            // Membres, gestionnaires : « sans plafond » ; IA et e-mails : jamais « illimité », le compteur seul
            $g['note'] = $uncapped_ok ? 'sans plafond' : '';
        } else {
            $g['pct'] = (int)min(100, round($used * 100 / max(1, $limit)));
            $g['flag'] = $used >= $limit ? 'full' : ($used * 100 >= 90 * $limit ? 'near' : '');
            if ($lk === 'limit_ai_text_per_month' && $ai_bonus > 0) $g['note'] = 'dont ' . $fmt($ai_bonus) . ' de bonus la 1re semaine';
        }
        $gauges[] = $g;
    }
    // Fonctions incluses (comme ak_plan_has : colonne absente d'une fonction apparue avec la grille = incluse)
    $features = [];
    if (function_exists('ak_plan_feature_keys')) {
        $default_on = function_exists('ak_plan_default_on_keys') ? ak_plan_default_on_keys() : [];
        foreach (ak_plan_feature_keys() as $col => $txt) {
            if ($col === 'feature_custom_domain') continue;   // pas encore disponible
            $on = array_key_exists($col, $plan) ? !empty($plan[$col]) : (in_array($col, $default_on, true) || $col === 'feature_email_diffusion');
            if ($on) $features[$col] = $txt;
        }
    }
    $demarrage = $slug === 'demarrage';
    // Formule pas encore passée à la grille (base d'avant la migration, ou ligne assokit à l'ancien prix
    // avant sa transformation en Pro) : ni le nom de la nouvelle formule, ni ses fonctions, seulement les
    // compteurs. Règle d'ak_pc_plan_grid_pending (une fois la grille en base, seule la ligne assokit pas
    // encore transformée est concernée : une ancienne formule masquée ou une formule créée ensuite depuis
    // Formules garde son nom et ses fonctions). Une ligne de devis Sur-mesure n'est jamais concernée.
    if (!function_exists('ak_pc_plan_grid_pending') && is_file(__DIR__ . '/stripe-plan-change-helpers.php')) {
        try { require_once __DIR__ . '/stripe-plan-change-helpers.php'; } catch (Throwable $e) {}
    }
    $quote = $slug === 'sur-mesure' || strncmp($slug, 'sur-mesure-', 11) === 0 || !empty($plan['is_custom_quote']);
    $grid_in_db = array_key_exists('grille_version', $plan)
        && (!function_exists('ak_pc_grid_ready') || ak_pc_grid_ready($pdo));
    $row_pending = function_exists('ak_pc_plan_grid_pending') ? ak_pc_plan_grid_pending($pdo, $plan) : !$grid_in_db;
    $grid_pending = !$in_trial && !$demarrage && !$quote && !$trial_row && $row_pending;
    if ($grid_pending) $name = 'Votre formule actuelle';
    // Fonctions de la grille : seulement une fois la grille en base
    if ($grid_pending || !$grid_in_db) $features = [];
    $readonly = $demarrage && function_exists('akc_readonly') && akc_readonly($pdo, $org_id, false);
    $summary = function_exists('ak_plan_public_summary') ? ak_plan_public_summary($plan) : [];
    if ($demarrage || $grid_pending) $summary = [];   // phrase dédiée (accès limité, passage à la grille)
    // Offre fondateur : fin de la garantie (seulement tant que la ligne est sur l'offre fondateur)
    $guarantee = '';
    if ($slug === 'fondateur' && !empty($plan['subscription_id'])) {
        if (!function_exists('ak_pc_price_locked_until') && is_file(__DIR__ . '/stripe-plan-change-helpers.php')) {
            try { require_once __DIR__ . '/stripe-plan-change-helpers.php'; } catch (Throwable $e) {}
        }
        if (function_exists('ak_pc_price_locked_until')) {
            try { $guarantee = ak_pc_price_locked_until($pdo, (int)$plan['subscription_id']); } catch (Throwable $e) { $guarantee = ''; }
        }
    }
    return ['name' => $name, 'slug' => $slug, 'in_trial' => $in_trial, 'gauges' => $gauges, 'summary' => $summary,
            'features' => $features, 'guarantee_fr' => $guarantee, 'readonly' => $readonly, 'demarrage' => $demarrage,
            'grid_pending' => $grid_pending, 'trial_over' => $trial_over,
            'reset_label' => function_exists('ak_plan_next_month_label') ? ak_plan_next_month_label() : ''];
}

/**
 * Carte HTML « Ce que comprend votre formule » ($compact = false, page Mon abonnement) ou « Ma formule »
 * ($compact = true : tableau de bord, Paramètres). '' si rien à afficher.
 */
function ak_render_plan_usage_card(PDO $pdo, int $org_id, bool $compact = false, array $opts = []): string {
    $d = ak_plan_usage_data($pdo, $org_id);
    if (!$d || !empty($d['trial_over'])) return '';
    $e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    $hidden = function_exists('ak_billing_hidden') && ak_billing_hidden();
    $u = function_exists('current_user') ? (current_user() ?: []) : [];
    $is_admin = (($u['role'] ?? '') === 'admin');
    $show_link = $compact && $is_admin && !$hidden && ($opts['link'] ?? true) !== false;
    $show_guarantee = ($opts['guarantee'] ?? true) !== false && $d['guarantee_fr'] !== '';
    $title = (string)($opts['title'] ?? ($compact ? 'Ma formule'
           : ($d['features'] || $d['summary'] ? 'Ce que comprend votre formule' : 'Utilisation de votre formule')));

    $h = '<div class="ak-pu' . ($compact ? ' ak-pu-compact' : '') . '" style="background:#fff;border:1px solid #E2E8F0;border-radius:14px;padding:' . ($compact ? '16px 18px' : '22px 24px') . ';margin-bottom:' . ($compact ? '18px' : '24px') . ';">';
    $h .= '<div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:12px;">';
    $h .= '<div style="min-width:0;"><div style="font-size:11px;font-weight:700;letter-spacing:0.05em;text-transform:uppercase;color:#64748B;">' . $e($title) . '</div>';
    $h .= '<div style="font-size:' . ($compact ? '16px' : '18px') . ';font-weight:700;color:#0F172A;margin-top:2px;">' . $e($d['name']) . '</div></div>';
    if ($show_link) {
        $h .= '<a href="/mon-asso-plan" style="font-size:13px;font-weight:600;color:#047857;text-decoration:none;white-space:nowrap;">Mon abonnement →</a>';
    }
    $h .= '</div>';
    $head = $h;

    if ($d['demarrage'] && ($opts['status_note'] ?? true) !== false) {
        $txt = $d['readonly'] ? 'Accès limité : vos données restent consultables et exportables.'
             : ($hidden ? 'Formule Démarrage, sans abonnement payant.'
                        : 'Formule Démarrage, sans abonnement payant : choisissez une formule pour profiter de toutes les fonctions.');
        $h .= '<p style="margin:0 0 12px;font-size:13px;color:#475569;line-height:1.55;">' . $e($txt) . '</p>';
    } elseif ($d['grid_pending'] && ($opts['status_note'] ?? true) !== false) {
        $h .= '<p style="margin:0 0 12px;font-size:13px;color:#475569;line-height:1.55;">Votre formule passe à la nouvelle grille très prochainement : rien ne change d’ici là.</p>';
    } elseif (!$compact && $d['summary']) {
        $h .= '<p style="margin:0 0 14px;font-size:13px;color:#475569;line-height:1.55;">' . $e(implode(' · ', $d['summary'])) . '</p>';
    }

    if ($d['gauges']) {
        $h .= '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(' . ($compact ? '150px' : '180px') . ',1fr));gap:12px;">';
        $monthly = false;
        foreach ($d['gauges'] as $g) {
            $monthly = $monthly || $g['monthly'];
            $color = $g['flag'] === 'full' ? '#DC2626' : ($g['flag'] === 'near' ? '#EA580C' : '#059669');
            $h .= '<div style="min-width:0;">';
            $h .= '<div style="font-size:12px;color:#64748B;line-height:1.4;">' . $e($g['label']) . '</div>';
            $h .= '<div style="font-size:15px;font-weight:700;color:' . ($g['flag'] !== '' ? $color : '#0F172A') . ';margin:2px 0 4px;">'
                . $e($g['used_fr']) . ($g['limit'] !== null ? '<span style="font-weight:500;color:#64748B;"> / ' . $e($g['limit_fr']) . '</span>' : '')
                . ($g['flag'] === 'full' ? ' <span style="font-size:11px;font-weight:700;">· limite atteinte</span>' : ($g['flag'] === 'near' ? ' <span style="font-size:11px;font-weight:700;">· ' . (int)$g['pct'] . '&nbsp;%</span>' : ''))
                . '</div>';
            if ($g['limit'] !== null) {
                $h .= '<div role="progressbar" aria-label="' . $e($g['label']) . '" aria-valuemin="0" aria-valuemax="' . (int)$g['limit'] . '" aria-valuenow="' . (int)$g['used'] . '" style="height:6px;background:#F1F5F9;border-radius:999px;overflow:hidden;">'
                    . '<div style="height:100%;width:' . (int)$g['pct'] . '%;background:' . $color . ';border-radius:999px;"></div></div>';
            }
            if ($g['note'] !== '') $h .= '<div style="font-size:11.5px;color:#64748B;margin-top:3px;">' . $e($g['note']) . '</div>';
            $h .= '</div>';
        }
        $h .= '</div>';
        if ($monthly && $d['reset_label'] !== '') {
            $h .= '<div style="font-size:11.5px;color:#64748B;margin-top:10px;">Les compteurs du mois repartent à zéro le ' . $e($d['reset_label']) . '.</div>';
        }
    }

    if (!$compact && $d['features']) {
        $h .= '<div style="font-size:13px;font-weight:700;color:#0F172A;margin:16px 0 6px;">Fonctions incluses</div>';
        $h .= '<ul style="list-style:none;padding:0;margin:0;display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:4px 16px;font-size:12.5px;color:#334155;line-height:1.5;">';
        foreach ($d['features'] as $txt) {
            $h .= '<li><span style="color:#059669;font-weight:700;">✓</span> ' . $e($txt) . '</li>';
        }
        $h .= '</ul>';
    }

    if ($show_guarantee) {
        // Dans l'appli : la garantie, sans parler de prix
        $h .= '<div style="margin-top:12px;padding:9px 12px;background:#EFF6FF;border:1px solid #BFDBFE;border-radius:10px;font-size:12.5px;color:#1E3A8A;line-height:1.5;">'
            . '🤝 Offre fondateur : ' . ($hidden ? 'fonctions garanties' : 'prix et fonctions garantis') . ' jusqu’au <strong>' . $e($d['guarantee_fr']) . '</strong>.</div>';
    }
    // Rien sous le titre (accès limité en service sur Mon abonnement : ni jauge, ni fonction, ni phrase) :
    // pas de carte vide
    if ($h === $head) return '';
    return $h . '</div>';
}

}
