<?php
/**
 * ============================================================
 * ASSOKIT — Helper Today AI Copilot
 * ============================================================
 * Fonctions :
 *   - today_collect_context() : rassemble la photo du jour de l'asso
 *   - today_build_prompt()    : construit le prompt selon le profil
 *   - today_call_claude()     : appelle l'API Claude
 *   - today_get_for_display() : ce que le tableau de bord affiche, SANS appeler l'IA
 *   - today_get_or_generate() : lit cache ou génère si besoin (today-ai-refresh)
 *   - today_fallback_suggestions() : suggestions statiques si API KO
 *   - today_render_items()    : HTML des cartes (partiel + réponse AJAX)
 *
 * Le tableau de bord n'attend jamais l'IA : il affiche le cache du jour, ou
 * les suggestions de secours, puis la génération IA part en arrière-plan
 * (POST /today-ai-refresh auto=1). Un échec est gardé 30 min avant de réessayer.
 * ============================================================
 */

// Écritures adaptées au schéma réel (today_suggestions n'est défini nulle part
// dans le dépôt : on n'écrit que les colonnes qui existent en prod)
if (is_file(__DIR__ . '/trial-helpers.php')) require_once __DIR__ . '/trial-helpers.php';

if (!defined('TODAY_AI_MAX_REFRESH_PER_DAY')) {
    define('TODAY_AI_MAX_REFRESH_PER_DAY', 3);
}
if (!defined('TODAY_AI_TIMEOUT_SECONDS')) {
    // Appel fait en arrière-plan (jamais pendant l'affichage du tableau de bord) :
    // on laisse au modèle le temps de répondre (Sonnet dépasse parfois 15 s).
    define('TODAY_AI_TIMEOUT_SECONDS', 30);
}
if (!defined('TODAY_AI_ERROR_RETRY_SECONDS')) {
    // Après un échec, on sert le repli statique sans rappeler l'IA pendant 30 min
    define('TODAY_AI_ERROR_RETRY_SECONDS', 1800);
}
if (!defined('TODAY_AI_API_URL')) {
    define('TODAY_AI_API_URL', defined('ANTHROPIC_API_URL') ? ANTHROPIC_API_URL : 'https://api.anthropic.com/v1/messages');
}
if (!defined('TODAY_AI_MODEL')) {
    // Même modèle que le reste de l'appli (config.php) : l'ancien identifiant
    // « claude-3-5-haiku-latest » n'est plus servi et faisait échouer l'appel.
    define('TODAY_AI_MODEL', defined('ANTHROPIC_MODEL') ? ANTHROPIC_MODEL : 'claude-sonnet-4-5');
}

/**
 * Determine le profil effectif d'un user.
 * - admin / coordinator → 'admin'
 * - si referent de >= 1 projet actif → 'referent'
 * - sinon → 'member'
 */
function today_get_user_profile(PDO $pdo, array $user): string
{
    $role = $user['role'] ?? '';

    // Admin / Founder / Super Admin → profil 'admin' (vue stratégique)
    if (in_array($role, ['admin', 'founder', 'super_admin'], true)
        || !empty($user['is_founder'])
        || !empty($user['is_super_admin'])) {
        return 'admin';
    }

    // Coordinator → profil 'coordinator' (vue opérationnelle, pivot)
    if ($role === 'coordinator') {
        return 'coordinator';
    }

    // Référent de projet(s) actif(s)
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM projects
        WHERE referent_id = ? AND status IN ('active','warning')
          AND archived_at IS NULL
    ");
    $stmt->execute([(int)$user['id']]);
    if ((int)$stmt->fetchColumn() > 0) {
        return 'referent';
    }

    return 'member';
}

/**
 * Collecte la "photo" du contexte pour un user donne.
 * Retourne un tableau avec toutes les donnees utiles au prompt IA.
 */
function today_collect_context(PDO $pdo, array $user, string $profile): array
{
    $org_id = (int)$user['org_id'];
    $user_id = (int)$user['id'];

    $ctx = [
        'profile'     => $profile,
        'user_name'   => ($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''),
        'user_first_name' => $user['first_name'] ?? '',
        'org_name'    => '',
        'today_date'  => date('Y-m-d'),
        'today_fr'    => date('l d F Y'),
        'projects'    => [],
        'events'      => [],
        'members'     => [],
        'messages'    => [],
        'invoices'    => [],
        'subscription'=> null,
        'folders'     => ['total' => 0, 'empty' => 0],
        'clients'     => ['total' => 0],
        'is_first_login' => false,
        'days_since_last_login' => 0,
        // Budgets des projets : mêmes profils que la carte Budget de projet.php
        'can_view_finances' => false,
        // Pages Communication : même règle que can('access_marketing') (sinon 403)
        'can_communication' => ($user['role'] ?? '') === 'admin' || !empty($user['is_super_admin'])
                               || !empty($user['can_access_marketing']),
    ];
    require_once __DIR__ . '/finance-permissions.php';
    $ctx['can_view_finances'] = function_exists('user_can_view_finances') && user_can_view_finances($user);

    // Nom de l'org
    try {
        $stmt = $pdo->prepare("SELECT name FROM organizations WHERE id = ?");
        $stmt->execute([$org_id]);
        $ctx['org_name'] = (string)($stmt->fetchColumn() ?: '');
    } catch (Throwable $e) {}

    // === DÉTECTION DOSSIERS (admin) ===
    if ($profile === 'admin') {
        try {
            $stmt = $pdo->prepare("
                SELECT COUNT(*) AS total,
                       SUM(CASE WHEN (SELECT COUNT(*) FROM projects WHERE folder_id = f.id AND archived_at IS NULL) = 0 THEN 1 ELSE 0 END) AS empty_folders
                FROM folders f
                WHERE f.org_id = ? AND f.archived_at IS NULL
            ");
            $stmt->execute([$org_id]);
            $r = $stmt->fetch(PDO::FETCH_ASSOC);
            $ctx['folders'] = [
                'total' => (int)($r['total'] ?? 0),
                'empty' => (int)($r['empty_folders'] ?? 0),
            ];
        } catch (Throwable $e) {}

        // === DÉTECTION CLIENTS (admin) ===
        try {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM asso_clients WHERE org_id = ? AND deleted_at IS NULL");
            $stmt->execute([$org_id]);
            $ctx['clients'] = ['total' => (int)$stmt->fetchColumn()];
        } catch (Throwable $e) {}

        // === DÉTECTION FIRST LOGIN / inactif ===
        try {
            $stmt = $pdo->prepare("SELECT last_login_at, created_at FROM users WHERE id = ?");
            $stmt->execute([$user_id]);
            $r = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($r) {
                $ctx['is_first_login'] = empty($r['last_login_at']);
                if (!empty($r['last_login_at'])) {
                    $ctx['days_since_last_login'] = (int)((time() - strtotime($r['last_login_at'])) / 86400);
                }
            }
        } catch (Throwable $e) {}
    }

    // === PROJETS ===
    try {
        if ($profile === 'admin' || $profile === 'coordinator') {
            // Admin / Coordinator : tous les projets de l'asso
            $stmt = $pdo->prepare("
                SELECT p.id, p.name, p.progress_percent, p.status, p.updated_at,
                       p.budget_planned, p.budget_used, p.end_date, p.referent_id,
                       u.first_name AS ref_first, u.last_name AS ref_last,
                       f.name AS folder_name,
                       DATEDIFF(NOW(), p.updated_at) AS days_since_update
                FROM projects p
                JOIN folders f ON p.folder_id = f.id
                LEFT JOIN users u ON p.referent_id = u.id
                WHERE f.org_id = ? AND p.status IN ('active','warning')
                  AND p.archived_at IS NULL
                  AND f.archived_at IS NULL
                ORDER BY p.status = 'warning' DESC, days_since_update DESC
                LIMIT 10
            ");
            $stmt->execute([$org_id]);
        } elseif ($profile === 'referent') {
            // Referent : ses projets
            $stmt = $pdo->prepare("
                SELECT p.id, p.name, p.progress_percent, p.status, p.updated_at,
                       p.budget_planned, p.budget_used, p.end_date, p.participants_count,
                       f.name AS folder_name,
                       DATEDIFF(NOW(), p.updated_at) AS days_since_update
                FROM projects p
                JOIN folders f ON p.folder_id = f.id
                WHERE p.referent_id = ? AND p.status IN ('active','warning')
                  AND p.archived_at IS NULL
                  AND f.archived_at IS NULL
                ORDER BY p.status = 'warning' DESC, days_since_update DESC
                LIMIT 10
            ");
            $stmt->execute([$user_id]);
        } else {
            // Member : projets visibles dans l'asso (top 3)
            $stmt = $pdo->prepare("
                SELECT p.id, p.name, p.progress_percent, p.status, f.name AS folder_name
                FROM projects p
                JOIN folders f ON p.folder_id = f.id
                WHERE f.org_id = ? AND p.status IN ('active','warning')
                  AND p.archived_at IS NULL
                  AND f.archived_at IS NULL
                ORDER BY p.updated_at DESC
                LIMIT 5
            ");
            $stmt->execute([$org_id]);
        }
        $ctx['projects'] = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if (!$ctx['can_view_finances']) {
            // Coordinateur, référent : pas de budget dans le prompt (l'IA le citerait)
            foreach ($ctx['projects'] as &$p) unset($p['budget_planned'], $p['budget_used']);
            unset($p);
        }
    } catch (Throwable $e) {
        error_log('Today AI context projects: ' . $e->getMessage());
    }

    // === ÉVÉNEMENTS (30 prochains jours) ===
    // Deux sources : l'Agenda (table events, celle du copilote, du coach et de l'appli)
    // et les événements publiés depuis Communication (communication_events, seuls à avoir des RSVP).
    // 'link' : page de l'événement que CET utilisateur peut ouvrir.
    $events = [];
    try {
        // Mêmes événements que son /agenda : un événement « projet seulement » reste
        // caché à qui n'est ni membre ni référent du projet (ak_event_visibility_sql ;
        // filtre vide pour l'admin et le coordinateur).
        require_once __DIR__ . '/includes-attendance.php';
        [$vis_sql, $vis_params] = function_exists('ak_event_visibility_sql')
            ? ak_event_visibility_sql($pdo, $user, 'e') : ['', []];
        $stmt = $pdo->prepare("
            SELECT e.id, e.title, e.starts_at AS start_date, e.location,
                   DATEDIFF(e.starts_at, NOW()) AS days_until
            FROM events e
            WHERE e.org_id = ? AND e.deleted_at IS NULL" . $vis_sql . "
              AND e.starts_at >= NOW() AND e.starts_at <= DATE_ADD(NOW(), INTERVAL 30 DAY)
            ORDER BY e.starts_at ASC
            LIMIT 5
        ");
        $stmt->execute(array_merge([$org_id], $vis_params));
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $ev) {
            $events[] = $ev + ['source' => 'agenda', 'link' => '/agenda', 'rsvp_enabled' => 0, 'nb_yes' => 0, 'nb_total_rsvp' => 0];
        }
    } catch (Throwable $e) {
        error_log('Today AI context agenda: ' . $e->getMessage());
    }
    try {
        // Sans accès à Communication (403), seuls les événements ayant une page
        // publique (où l'on répond au RSVP) ont un lien à proposer.
        $stmt = $pdo->prepare("
            SELECT e.id, e.title, e.start_date, e.location, e.rsvp_enabled, e.is_public, e.public_slug,
                   (SELECT COUNT(*) FROM communication_event_rsvps WHERE event_id = e.id AND response = 'yes') AS nb_yes,
                   (SELECT COUNT(*) FROM communication_event_rsvps WHERE event_id = e.id) AS nb_total_rsvp,
                   DATEDIFF(e.start_date, NOW()) AS days_until
            FROM communication_events e
            WHERE e.org_id = ? AND e.status = 'published'
              AND e.start_date >= NOW() AND e.start_date <= DATE_ADD(NOW(), INTERVAL 30 DAY)"
            . ($ctx['can_communication'] ? '' : " AND e.is_public = 1 AND e.public_slug IS NOT NULL AND e.public_slug <> ''") . "
            ORDER BY e.start_date ASC
            LIMIT 5
        ");
        $stmt->execute([$org_id]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $ev) {
            $ev['source'] = 'communication';
            $slug = preg_replace('/[^A-Za-z0-9_-]/', '', (string)($ev['public_slug'] ?? ''));
            $ev['link'] = $ctx['can_communication'] ? '/communication?tab=evenements' : '/evenement-public/' . $slug;
            // Page publique : c'est là que l'on répond (le lien Communication sert à gérer)
            $ev['rsvp_link'] = (!empty($ev['is_public']) && $slug !== '') ? '/evenement-public/' . $slug : null;
            unset($ev['public_slug'], $ev['is_public']);
            // Pour member : a-t-il répondu ?
            if ($profile === 'member' && !empty($ev['rsvp_enabled'])) {
                $s2 = $pdo->prepare("SELECT response FROM communication_event_rsvps WHERE event_id = ? AND user_id = ? LIMIT 1");
                $s2->execute([(int)$ev['id'], $user_id]);
                $ev['my_response'] = $s2->fetchColumn() ?: null;
            }
            $events[] = $ev;
        }
    } catch (Throwable $e) {
        error_log('Today AI context events: ' . $e->getMessage());
    }
    usort($events, fn($a, $b) => strcmp((string)$a['start_date'], (string)$b['start_date']));
    $ctx['events'] = array_slice($events, 0, 6);

    // === ADHERENTS (admin / coordinateur) ===
    // L'utilisateur courant n'est pas compté parmi les « nouveaux à accueillir »
    // (sinon l'admin d'une association neuve s'accueille lui-même).
    if ($profile === 'admin' || $profile === 'coordinator') {
        try {
            $stmt = $pdo->prepare("
                SELECT COUNT(*) AS total,
                       SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) AND id <> ? THEN 1 ELSE 0 END) AS new_7d,
                       SUM(CASE WHEN adhesion_valid_until IS NOT NULL AND adhesion_valid_until < NOW() THEN 1 ELSE 0 END) AS expired_cotisations
                FROM users
                WHERE org_id = ? AND (deleted_at IS NULL) AND is_active = 1
            ");
            $stmt->execute([$user_id, $org_id]);
            $r = $stmt->fetch(PDO::FETCH_ASSOC);
            $ctx['members'] = [
                'total' => (int)($r['total'] ?? 0),
                'new_7d' => (int)($r['new_7d'] ?? 0),
                'expired_cotisations' => (int)($r['expired_cotisations'] ?? 0),
            ];

            // Noms des nouveaux
            $stmt = $pdo->prepare("
                SELECT first_name, last_name FROM users
                WHERE org_id = ? AND deleted_at IS NULL AND is_active = 1
                  AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) AND id <> ?
                ORDER BY created_at DESC LIMIT 3
            ");
            $stmt->execute([$org_id, $user_id]);
            $ctx['members']['recent_names'] = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            error_log('Today AI context members: ' . $e->getMessage());
        }
    }

    // === STATS SPÉCIFIQUES COORDINATEUR (pivot opérationnel) ===
    if ($profile === 'coordinator') {
        $ctx['coord_stats'] = [
            'projects_no_referent'  => 0,
            'projects_stale'        => 0,
            'projects_warning'      => 0,
            'projects_low_progress' => 0,
            'available_referents'   => 0,
            'events_no_rsvp'        => 0,
            'recent_messages_24h'   => 0,
        ];

        try {
            // Projets sans référent assigné
            $stmt = $pdo->prepare("
                SELECT COUNT(*) FROM projects p
                JOIN folders f ON p.folder_id = f.id
                WHERE f.org_id = ? AND p.status IN ('active','warning')
                  AND p.archived_at IS NULL AND f.archived_at IS NULL
                  AND (p.referent_id IS NULL OR p.referent_id = 0)
            ");
            $stmt->execute([$org_id]);
            $ctx['coord_stats']['projects_no_referent'] = (int)$stmt->fetchColumn();
        } catch (Throwable $e) {}

        try {
            // Projets sans MAJ depuis 7+ jours
            $stmt = $pdo->prepare("
                SELECT COUNT(*) FROM projects p
                JOIN folders f ON p.folder_id = f.id
                WHERE f.org_id = ? AND p.status = 'active'
                  AND p.archived_at IS NULL AND f.archived_at IS NULL
                  AND DATEDIFF(NOW(), p.updated_at) >= 7
            ");
            $stmt->execute([$org_id]);
            $ctx['coord_stats']['projects_stale'] = (int)$stmt->fetchColumn();
        } catch (Throwable $e) {}

        try {
            // Projets en statut warning (à surveiller)
            $stmt = $pdo->prepare("
                SELECT COUNT(*) FROM projects p
                JOIN folders f ON p.folder_id = f.id
                WHERE f.org_id = ? AND p.status = 'warning'
                  AND p.archived_at IS NULL AND f.archived_at IS NULL
            ");
            $stmt->execute([$org_id]);
            $ctx['coord_stats']['projects_warning'] = (int)$stmt->fetchColumn();
        } catch (Throwable $e) {}

        try {
            // Projets avec progression < 25% démarrés depuis 30+ jours (alerte démarrage lent)
            $stmt = $pdo->prepare("
                SELECT COUNT(*) FROM projects p
                JOIN folders f ON p.folder_id = f.id
                WHERE f.org_id = ? AND p.status = 'active'
                  AND p.archived_at IS NULL AND f.archived_at IS NULL
                  AND p.progress_percent < 25
                  AND DATEDIFF(NOW(), COALESCE(p.start_date, p.created_at)) >= 30
            ");
            $stmt->execute([$org_id]);
            $ctx['coord_stats']['projects_low_progress'] = (int)$stmt->fetchColumn();
        } catch (Throwable $e) {}

        try {
            // Référents potentiels disponibles (pour assigner à des projets)
            $stmt = $pdo->prepare("
                SELECT COUNT(*) FROM users
                WHERE org_id = ? AND is_active = 1 AND deleted_at IS NULL
                  AND role IN ('referent', 'coordinator', 'admin')
            ");
            $stmt->execute([$org_id]);
            $ctx['coord_stats']['available_referents'] = (int)$stmt->fetchColumn();
        } catch (Throwable $e) {}

        try {
            // Événements à venir avec RSVP ouvert mais sans aucune réponse (besoin de relance)
            $stmt = $pdo->prepare("
                SELECT COUNT(*) FROM communication_events e
                WHERE e.org_id = ? AND e.status = 'published' AND e.rsvp_enabled = 1
                  AND e.start_date >= NOW() AND e.start_date <= DATE_ADD(NOW(), INTERVAL 14 DAY)
                  AND (SELECT COUNT(*) FROM communication_event_rsvps WHERE event_id = e.id) = 0
            ");
            $stmt->execute([$org_id]);
            $ctx['coord_stats']['events_no_rsvp'] = (int)$stmt->fetchColumn();
        } catch (Throwable $e) {}

        try {
            // Messages des dernières 24 h dans les canaux de la messagerie (modération éventuelle)
            $stmt = $pdo->prepare("
                SELECT COUNT(*) FROM channel_messages cm
                JOIN channels c ON c.id = cm.channel_id
                WHERE c.org_id = ? AND cm.deleted_at IS NULL
                  AND cm.created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
            ");
            $stmt->execute([$org_id]);
            $ctx['coord_stats']['recent_messages_24h'] = (int)($stmt->fetchColumn() ?: 0);
        } catch (Throwable $e) {
            error_log('Today AI context messages 24h: ' . $e->getMessage());
        }
    }

    // === MESSAGES non lus (tous profils) ===
    // Même règle que la messagerie (messages.php) : canaux publics / annonces de l'asso
    // et canaux privés dont il est membre ; non lu = après son repère channel_reads,
    // hors ses propres messages.
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM channel_messages cm
            JOIN channels c ON c.id = cm.channel_id
            WHERE c.org_id = ? AND c.is_archived = 0
              AND (c.type IN ('public', 'announce')
                   OR EXISTS (SELECT 1 FROM channel_members mb WHERE mb.channel_id = c.id AND mb.user_id = ?))
              AND cm.deleted_at IS NULL AND cm.user_id <> ?
              AND cm.id > COALESCE((SELECT MAX(r.last_read_message_id) FROM channel_reads r
                                    WHERE r.channel_id = c.id AND r.user_id = ?), 0)
        ");
        $stmt->execute([$org_id, $user_id, $user_id, $user_id]);
        $ctx['messages']['unread'] = (int)($stmt->fetchColumn() ?: 0);
    } catch (Throwable $e) {
        error_log('Today AI context unread: ' . $e->getMessage());
        $ctx['messages']['unread'] = 0;
    }

    // Support Assokit (admin) : les tickets sont les demandes de l'association AU support
    // Assokit. Seules les réponses du support non lues appellent une action (même
    // requête que le badge « Support » du menu).
    if ($profile === 'admin') {
        try {
            $stmt = $pdo->prepare("
                SELECT COUNT(DISTINCT t.id)
                FROM support_tickets t
                JOIN support_messages m ON m.ticket_id = t.id
                WHERE t.org_id = ?
                  AND m.author_side = 'support'
                  AND m.read_by_org = 0
                  AND m.is_internal_note = 0
            ");
            $stmt->execute([$org_id]);
            $ctx['messages']['support_unread'] = (int)($stmt->fetchColumn() ?: 0);
        } catch (Throwable $e) {
            $ctx['messages']['support_unread'] = 0;
        }
    }

    // === FACTURES (admin only) ===
    if ($profile === 'admin') {
        try {
            // Factures clients de l'association (asso_invoices, montants en centimes) en retard :
            // marquées « en retard », ou en attente avec une échéance dépassée.
            // (La table invoices est celle des factures d'abonnement Assokit.)
            $stmt = $pdo->prepare("
                SELECT COUNT(*) AS nb, COALESCE(SUM(amount_ttc_cents), 0) / 100 AS amount,
                       COALESCE(SUM(status = 'pending'), 0) AS nb_pending_late
                FROM asso_invoices
                WHERE org_id = ?
                  AND (status = 'overdue' OR (status = 'pending' AND due_at < CURDATE()))
            ");
            $stmt->execute([$org_id]);
            $r = $stmt->fetch(PDO::FETCH_ASSOC);
            $ctx['invoices'] = [
                'overdue_count' => (int)($r['nb'] ?? 0),
                'overdue_amount' => (float)($r['amount'] ?? 0),
                'pending_late' => (int)($r['nb_pending_late'] ?? 0),
            ];
        } catch (Throwable $e) {
            error_log('Today AI context invoices: ' . $e->getMessage());
            $ctx['invoices'] = ['overdue_count' => 0, 'overdue_amount' => 0, 'pending_late' => 0];
        }

        // Abonnement Assokit : formule et essai lus comme partout ailleurs (ak_org_plan_state,
        // via ak_plan_trial_info) — l'IA voit « Essai PRO jusqu'au … », jamais un slug brut.
        //   status     : 'trial' (essai en cours), 'trial_expired' (essai terminé), sinon statut de l'abonnement
        //   plan_type  : nom affiché de la formule (clé gardée pour les anciens appelants)
        //   days_until : jours avant la fin de l'essai, ou avant l'échéance d'une formule payante
        try {
            @require_once __DIR__ . '/plan-helpers.php';
            if (function_exists('ak_plan_trial_info') && function_exists('ak_org_plan_state')) {
                $ti = ak_plan_trial_info($pdo, $org_id);
                $ps = ak_org_plan_state($pdo, $org_id);
                $sub = [
                    'plan_type'     => $ti['plan_label'],
                    'plan_label'    => $ti['plan_label'],
                    'trial_name'    => (string)($ti['trial_name'] ?? ''),
                    'label'         => $ti['label'],
                    'status'        => $ti['trial_expired'] ? 'trial_expired' : ($ti['in_trial'] ? 'trial' : (string)$ps['sub_status']),
                    'trial_ends_fr' => $ti['end_fr'],
                    'ends_today'    => !empty($ti['ends_today']),
                    'days_until'    => null,
                    'price_cents'   => (int)$ps['price_cents'],
                    // Abonnement déjà souscrit pendant l'essai : premier prélèvement à la fin de l'essai
                    'trial_subscribed' => !empty($ti['trial_subscribed']),
                    'first_charge_fr'  => (string)($ti['first_charge_fr'] ?? ''),
                ];
                if ($ti['in_trial'] || $ti['trial_expired']) {
                    $sub['days_until'] = $ti['days_left'];
                } elseif ((int)$ps['price_cents'] > 0) {
                    // Formule payante : prochaine échéance = fin de période de la ligne courante
                    // (même ligne qu'ak_org_plan_state / ak_get_current_plan)
                    $st = $pdo->prepare("SELECT DATEDIFF(current_period_end, NOW()) FROM asso_subscriptions
                                         WHERE org_id = ? ORDER BY (status = 'active') DESC, id DESC LIMIT 1");
                    $st->execute([$org_id]);
                    $d = $st->fetchColumn();
                    if ($d !== false && $d !== null) $sub['days_until'] = (int)$d;
                }
                $ctx['subscription'] = $sub;
            } else {
                $stmt = $pdo->prepare("
                    SELECT next_billing_date, plan_type, status,
                           DATEDIFF(next_billing_date, NOW()) AS days_until
                    FROM organization_subscriptions
                    WHERE org_id = ? AND status IN ('active','trial')
                    ORDER BY id DESC LIMIT 1
                ");
                @$stmt->execute([$org_id]);
                $ctx['subscription'] = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
            }
        } catch (Throwable $e) {}
    }

    return $ctx;
}

/**
 * Construit le prompt à envoyer à Claude selon le profil.
 */
function today_build_prompt(array $ctx): string
{
    $profile = $ctx['profile'];
    $role_label = ['admin' => 'Administrateur', 'coordinator' => 'Coordinateur', 'referent' => 'Référent de projet', 'member' => 'Adhérent'][$profile] ?? 'Membre';

    $prompt = "Tu es \"AssokitCopilote\", un assistant IA intégré à la plateforme Assokit (SaaS pour associations françaises).\n\n";
    $prompt .= "Ton rôle est d'analyser la situation du jour et de proposer des ACTIONS CONCRÈTES ET PERSONNALISÉES à faire aujourd'hui.\n\n";
    $prompt .= "CONTEXTE DU JOUR :\n";
    $prompt .= "- Date : {$ctx['today_date']}\n";
    $prompt .= "- Utilisateur : {$ctx['user_name']}\n";
    $prompt .= "- Rôle : $role_label\n";
    $prompt .= "- Association : {$ctx['org_name']}\n\n";

    // === PROJETS ===
    if (!empty($ctx['projects'])) {
        $prompt .= "📁 PROJETS :\n";
        foreach ($ctx['projects'] as $p) {
            // id : permet à l'IA de lier directement /projet/ID
            $line = "- \"{$p['name']}\" [id " . (int)$p['id'] . "] ({$p['folder_name']}) : {$p['progress_percent']}%";
            if ($p['status'] === 'warning') $line .= ", STATUT WARNING";
            if (!empty($p['days_since_update']) && $p['days_since_update'] > 7) {
                $line .= ", pas de MAJ depuis {$p['days_since_update']} jours";
            }
            if ($profile === 'admin' && !empty($p['ref_first'])) {
                $line .= ", référent {$p['ref_first']} " . ($p['ref_last'] ?? '');
            }
            if (!empty($p['end_date'])) {
                $days = (int) ((strtotime($p['end_date']) - time()) / 86400);
                if ($days >= 0 && $days <= 14) $line .= ", deadline J+{$days}";
            }
            // Budget : profils finances seulement (budget_* retirés du contexte sinon)
            if (!empty($ctx['can_view_finances']) && !empty($p['budget_planned']) && $p['budget_planned'] > 0) {
                $pct = round(((float)($p['budget_used'] ?? 0) / $p['budget_planned']) * 100);
                if ($pct >= 80) $line .= ", budget consommé à {$pct}%";
            }
            $prompt .= $line . "\n";
        }
        $prompt .= "\n";
    }

    // === EVENEMENTS ===
    if (!empty($ctx['events'])) {
        $prompt .= "📅 ÉVÉNEMENTS À VENIR (30 prochains jours) :\n";
        foreach ($ctx['events'] as $e) {
            $date_str = date('d/m/Y', strtotime($e['start_date']));
            $line = "- \"{$e['title']}\" le $date_str (J+{$e['days_until']})";
            if (!empty($e['location'])) $line .= " à {$e['location']}";
            $ev_link = (string)($e['link'] ?? '/agenda');
            if (($e['source'] ?? '') === 'agenda') {
                $line .= " [Agenda, lien $ev_link]";
            } elseif (strpos($ev_link, '/communication') === 0) {
                $line .= " [Communication, lien $ev_link"
                       . (!empty($e['rsvp_link']) ? ", page publique d'inscription {$e['rsvp_link']}" : '') . "]";
            } else {
                $line .= " [page de l'événement, lien $ev_link]";
            }
            if (!empty($e['rsvp_enabled'])) {
                $line .= ", {$e['nb_yes']} RSVP positifs";
                if (!empty($e['nb_total_rsvp'])) $line .= " / {$e['nb_total_rsvp']} réponses";
                // RSVP : seulement pour les événements où la réponse est ouverte
                if ($profile === 'member' && !empty($e['my_response'])) {
                    $line .= ", MA RÉPONSE : {$e['my_response']}";
                } elseif ($profile === 'member') {
                    $line .= ", JE N'AI PAS ENCORE RÉPONDU";
                }
            }
            $prompt .= $line . "\n";
        }
        $prompt .= "\n";
    }

    // === PILOTAGE (coordinateur) ===
    if ($profile === 'coordinator' && !empty($ctx['coord_stats'])) {
        $cs = $ctx['coord_stats'];
        $prompt .= "🎯 PILOTAGE OPÉRATIONNEL :\n";
        $prompt .= "- Projets actifs sans référent : " . (int)$cs['projects_no_referent'] . "\n";
        $prompt .= "- Projets en alerte (statut à surveiller) : " . (int)$cs['projects_warning'] . "\n";
        $prompt .= "- Projets sans mise à jour depuis 7 jours ou plus : " . (int)$cs['projects_stale'] . "\n";
        $prompt .= "- Projets démarrés depuis 30 jours ou plus et avancés à moins de 25 % : " . (int)$cs['projects_low_progress'] . "\n";
        $prompt .= "- Personnes pouvant être référentes (référents, coordinateurs, admins) : " . (int)$cs['available_referents'] . "\n";
        $prompt .= "- Événements des 14 prochains jours avec RSVP ouvert mais sans aucune réponse : " . (int)$cs['events_no_rsvp'] . "\n";
        $prompt .= "- Messages postés dans les canaux ces dernières 24 h : " . (int)$cs['recent_messages_24h'] . "\n\n";
    }

    // === ADHERENTS (admin / coordinateur) ===
    if (in_array($profile, ['admin', 'coordinator'], true) && !empty($ctx['members'])) {
        $prompt .= "👥 ADHÉRENTS :\n";
        $prompt .= "- Total : {$ctx['members']['total']} membres\n";
        if ($ctx['members']['new_7d'] > 0) {
            $prompt .= "- {$ctx['members']['new_7d']} nouveaux cette semaine";
            if (!empty($ctx['members']['recent_names'])) {
                $names = array_map(fn($u) => $u['first_name'] . ' ' . $u['last_name'], $ctx['members']['recent_names']);
                $prompt .= " : " . implode(', ', $names);
            }
            $prompt .= "\n";
        }
        if ($ctx['members']['expired_cotisations'] > 0) {
            $prompt .= "- {$ctx['members']['expired_cotisations']} cotisations EXPIRÉES\n";
        }
        $prompt .= "\n";
    }

    // === MESSAGES ===
    if (!empty($ctx['messages']['unread']) || !empty($ctx['messages']['support_unread'])) {
        $prompt .= "💬 COMMUNICATION :\n";
        if (!empty($ctx['messages']['unread'])) {
            $prompt .= "- {$ctx['messages']['unread']} message(s) non lu(s) dans les canaux de la messagerie interne (lien /messages)\n";
        }
        if (!empty($ctx['messages']['support_unread'])) {
            $prompt .= "- {$ctx['messages']['support_unread']} réponse(s) du support Assokit non lue(s) : ce sont les demandes que l'association a envoyées à Assokit, pas des demandes d'adhérents (lien /support)\n";
        }
        $prompt .= "\n";
    }

    // === FACTURES (admin) ===
    if ($profile === 'admin' && !empty($ctx['invoices']) && $ctx['invoices']['overdue_count'] > 0) {
        $prompt .= "💰 FACTURES CLIENTS :\n";
        $prompt .= "- {$ctx['invoices']['overdue_count']} facture(s) client(s) impayée(s) en retard — " . number_format($ctx['invoices']['overdue_amount'], 2, ',', ' ') . " € (lien /mon-asso-factures-client)\n\n";
    }

    // === ABONNEMENT ASSOKIT (admin) ===
    $sub = $ctx['subscription'] ?? null;
    $sub_status = is_array($sub) ? (string)($sub['status'] ?? '') : '';
    if ($profile === 'admin' && $sub_status === 'trial') {
        // Essai gratuit en cours : toujours dit à l'IA (sinon elle parle d'un « plan gratuit »)
        $days = isset($sub['days_until']) ? (int)$sub['days_until'] : null;
        $prompt .= "🔔 ABONNEMENT ASSOKIT :\n";
        $pl = trim((string)($sub['plan_label'] ?? ''));
        $trial_name = trim((string)($sub['trial_name'] ?? ''));
        if ($trial_name === '') $trial_name = ($pl === '' || $pl === '—') ? 'Essai PRO' : (stripos($pl, 'essai') !== false ? $pl : 'Essai ' . $pl);
        $prompt .= "- Plan actuel : {$trial_name} (essai gratuit de " . (defined('AK_TRIAL_DAYS') ? (int)AK_TRIAL_DAYS : 14) . " jours, toutes les fonctionnalités — ce n'est PAS un plan gratuit)\n";
        if ($days !== null) {
            $prompt .= "- " . ($days <= 0 || !empty($sub['ends_today']) ? "L'essai se termine aujourd'hui" : "Fin de l'essai dans {$days} jour" . ($days > 1 ? 's' : ''))
                     . (!empty($sub['trial_ends_fr']) ? " (le {$sub['trial_ends_fr']})" : '') . "\n";
            if (!empty($sub['trial_subscribed'])) {
                $prompt .= "- Abonnement Assokit DÉJÀ souscrit pendant l'essai (moyen de paiement enregistré) : premier prélèvement automatique à la fin de l'essai"
                         . (!empty($sub['first_charge_fr']) ? " (le {$sub['first_charge_fr']})" : '') . ". Ne PAS inviter à choisir une formule\n";
            } elseif ($days <= 7) {
                $prompt .= "- Rappeler de choisir une formule avant la fin de l'essai (page Mon abonnement)\n";
            }
        }
        $prompt .= "\n";
    } elseif ($profile === 'admin' && $sub_status === 'trial_expired') {
        $prompt .= "🔔 ABONNEMENT ASSOKIT :\n";
        $prompt .= "- " . ($sub['label'] ?? 'Essai terminé') . " : l'association n'a pas encore choisi de formule"
                 // Plan rétrogradé par la fin d'essai (Démarrage) ; tant que le cron n'est pas passé, la ligne est encore l'essai
                 . (!empty($sub['plan_label']) && stripos((string)$sub['plan_label'], 'essai') === false && $sub['plan_label'] !== '—'
                    ? " (plan actuel : {$sub['plan_label']}, fonctionnalités limitées)" : '') . "\n";
        $prompt .= "- Inviter à choisir une formule (page Mon abonnement) ; les données sont conservées\n\n";
    } elseif ($profile === 'admin' && !empty($sub) && isset($sub['days_until'])) {
        $days = (int)$sub['days_until'];
        if ($days >= 0 && $days <= 14) {
            $prompt .= "🔔 ABONNEMENT ASSOKIT :\n";
            $prompt .= "- Prochaine échéance dans {$days} jours\n";
            $prompt .= "- Plan actuel : " . ($sub['plan_type'] ?? 'standard') . "\n\n";
        }
    }

    // === INSTRUCTIONS ===
    $prompt .= "CONSIGNES :\n";

    if ($profile === 'admin') {
        $prompt .= "Propose 3 à 5 actions prioritaires pour cet ADMINISTRATEUR D'ASSOCIATION. Priorise :\n";
        $prompt .= "- Factures clients impayées et abonnement Assokit à renouveler (urgent)\n";
        $prompt .= "- Relances adhérents (RSVP, cotisations expirées)\n";
        $prompt .= "- Projets en retard ou bloqués (coordination équipe)\n";
        $prompt .= "- Communication (messages non lus, réponses du support Assokit à lire, accueil des nouveaux)\n";
    } elseif ($profile === 'coordinator') {
        $prompt .= "Propose 3 à 5 actions prioritaires pour ce COORDINATEUR (pilote opérationnel de l'équipe). Priorise :\n";
        $prompt .= "- Projets sans référent à assigner\n";
        $prompt .= "- Projets en alerte ou sans mise à jour : relancer les référents\n";
        $prompt .= "- Événements à préparer, relance des RSVP sans réponse\n";
        $prompt .= "- Accueil des nouveaux adhérents, cotisations à renouveler, animation des canaux\n";
    } elseif ($profile === 'referent') {
        $prompt .= "Propose 3 à 5 actions prioritaires pour ce RÉFÉRENT DE PROJET. Priorise :\n";
        $prompt .= "- Mise à jour de l'avancement des projets inactifs\n";
        $prompt .= "- Clôture des projets à 100%\n";
        $prompt .= !empty($ctx['can_view_finances']) ? "- Suivi budget et deadlines proches\n" : "- Deadlines proches et étapes à valider\n";
        $prompt .= "- Coordination équipe\n";
    } else {
        $prompt .= "Propose 2 à 3 actions prioritaires pour cet ADHÉRENT. Priorise :\n";
        $prompt .= "- RSVP aux événements à venir\n";
        $prompt .= "- Messages non lus\n";
        $prompt .= "- Projets qui pourraient l'intéresser\n";
        $prompt .= "- Actions simples et accessibles\n";
    }

    $prompt .= "\nCHAQUE suggestion DOIT avoir :\n";
    $prompt .= "- un \"icon\" (emoji pertinent)\n";
    $prompt .= "- un \"title\" court et actionnable (max 80 car)\n";
    $prompt .= "- une \"description\" précise avec chiffres si pertinent (max 120 car)\n";
    // Liens : uniquement des pages qui existent (une URL inventée mène à une 404)
    $links = ['/projets', '/projet/ID (ID = identifiant d\'un projet)', '/agenda', '/messages'];
    // Communication : seulement avec son droit (403 sinon) ; pages publiques des événements listés
    if (!empty($ctx['can_communication'])) {
        array_push($links, '/communication', '/communication?tab=evenements');
    }
    foreach ($ctx['events'] ?? [] as $e) {
        foreach ([$e['link'] ?? null, $e['rsvp_link'] ?? null] as $l) {
            if ($l && !in_array($l, $links, true)) $links[] = $l;
        }
    }
    if ($profile === 'admin' || $profile === 'coordinator') {
        array_push($links, '/adherents', '/nouveau-adherent', '/relances', '/cotisations');
    }
    if ($profile === 'admin') {
        array_push($links, '/mon-asso-factures-client', '/support', '/mon-asso-plan', '/mon-asso-stats');
    }
    $prompt .= "- un \"link\" URL relative, choisie UNIQUEMENT dans cette liste : " . implode(', ', $links) . "\n";
    $prompt .= "- un \"link_label\" bouton court (max 25 car)\n";
    $prompt .= "- une \"priority\" : \"urgent\" | \"important\" | \"info\"\n\n";
    $prompt .= "RÉPONDS UNIQUEMENT AVEC UN JSON VALIDE, aucun autre texte avant ou après.\n";
    $prompt .= "Format : {\"suggestions\": [...]}\n";
    $prompt .= "Ton français, ton naturel et motivant. Évite les généralités.";

    return $prompt;
}

/**
 * Appelle l'API Claude et retourne le JSON parsé.
 * Retourne ['ok' => bool, 'suggestions' => array, 'tokens_in' => int, 'tokens_out' => int, 'error' => string]
 */
function today_call_claude(string $prompt): array
{
    if (!defined('CLAUDE_API_KEY') || CLAUDE_API_KEY === '') {
        return ['ok' => false, 'error' => 'CLAUDE_API_KEY non configurée', 'suggestions' => []];
    }
    if (!today_ai_enabled()) {
        return ['ok' => false, 'error' => 'ai_consent', 'suggestions' => []];
    }
    if (function_exists('set_time_limit')) @set_time_limit(TODAY_AI_TIMEOUT_SECONDS + 30);

    $payload = [
        'model' => TODAY_AI_MODEL,
        'max_tokens' => 1000,
        'messages' => [
            ['role' => 'user', 'content' => $prompt],
        ],
    ];

    $ch = curl_init(TODAY_AI_API_URL);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_TIMEOUT => TODAY_AI_TIMEOUT_SECONDS,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_NOSIGNAL => true,
        CURLOPT_HTTPHEADER => [
            'x-api-key: ' . CLAUDE_API_KEY,
            'anthropic-version: 2023-06-01',
            'content-type: application/json',
        ],
    ]);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_err = curl_error($ch);
    curl_close($ch);

    if ($response === false || $curl_err) {
        return ['ok' => false, 'error' => 'CURL: ' . $curl_err, 'suggestions' => []];
    }

    if ($http_code !== 200) {
        return ['ok' => false, 'error' => "HTTP $http_code: " . mb_substr($response, 0, 200), 'suggestions' => []];
    }

    $data = json_decode($response, true);
    if (!$data || empty($data['content'][0]['text'])) {
        return ['ok' => false, 'error' => 'Réponse API invalide', 'suggestions' => []];
    }

    $text = $data['content'][0]['text'];
    // Nettoyer les ```json ```
    $text = preg_replace('/^```(?:json)?\s*|\s*```$/m', '', trim($text));

    $parsed = json_decode($text, true);
    if (!$parsed || empty($parsed['suggestions'])) {
        return ['ok' => false, 'error' => 'JSON parse: ' . mb_substr($text, 0, 200), 'suggestions' => []];
    }

    return [
        'ok' => true,
        'suggestions' => $parsed['suggestions'],
        'tokens_in' => (int)($data['usage']['input_tokens'] ?? 0),
        'tokens_out' => (int)($data['usage']['output_tokens'] ?? 0),
    ];
}

/**
 * L'IA des suggestions est-elle configurée ? (sinon : repli statique, sans appel)
 */
function today_ai_enabled(): bool
{
    if (!defined('CLAUDE_API_KEY') || CLAUDE_API_KEY === '') return false;
    // Dans l'app, sans l'accord de l'utilisateur pour l'envoi à Anthropic (règle 5.1.2(i)
    // d'Apple) : repli statique, aucun appel (ai-consent-helper.php)
    if (!function_exists('ak_ai_app_gate')) require_once __DIR__ . '/ai-consent-helper.php';
    return ak_ai_app_gate() === null;
}

/**
 * Ligne de cache du jour (la plus récente si plusieurs). SELECT * : les colonnes
 * de today_suggestions ne sont pas garanties en prod (aucune définition versionnée).
 */
function today_cache_row(PDO $pdo, int $user_id, string $day): ?array
{
    try {
        $stmt = $pdo->prepare("
            SELECT * FROM today_suggestions
            WHERE user_id = ? AND generation_date = ?
            ORDER BY id DESC LIMIT 1
        ");
        $stmt->execute([$user_id, $day]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (Throwable $e) {
        error_log('Today AI cache: ' . $e->getMessage());
        return null;
    }
}

/**
 * Une ligne en erreur (repli statique) est-elle encore « fraîche » ?
 * Pendant TODAY_AI_ERROR_RETRY_SECONDS on la sert sans rappeler l'IA.
 */
function today_error_is_recent(array $row): bool
{
    $ts = strtotime((string)($row['last_generated_at'] ?? ''));
    return $ts !== false && $ts > time() - TODAY_AI_ERROR_RETRY_SECONDS;
}

/**
 * Écrit la ligne du jour en n'utilisant que les colonnes présentes en base.
 * $raw : expressions SQL écrites par le code (jamais une saisie).
 */
function today_save_row(PDO $pdo, ?array $cached, array $user, string $profile, array $data, array $raw = []): void
{
    $table = 'today_suggestions';
    if (function_exists('ak_db_accepts') && ak_db_columns($pdo, $table) && !ak_db_accepts($pdo, $table, 'profile', $profile)) {
        unset($data['profile']); // ENUM sans ce profil : on n'empêche pas l'enregistrement pour autant
    } else {
        $data['profile'] = $profile;
    }
    try {
        if (!function_exists('ak_db_insert')) {
            throw new RuntimeException('trial-helpers.php absent');
        }
        if ($cached && !empty($cached['id'])) {
            ak_db_update($pdo, $table, (int)$cached['id'], $data, $raw);
            return;
        }
        try {
            ak_db_insert($pdo, $table, $data + [
                'user_id' => (int)$user['id'],
                'org_id' => (int)$user['org_id'],
                'generation_date' => date('Y-m-d'),
            ], $raw);
        } catch (PDOException $e) {
            // Clé unique (utilisateur, jour) : une autre requête vient d'écrire la ligne
            $row = today_cache_row($pdo, (int)$user['id'], date('Y-m-d'));
            if (!$row) throw $e;
            ak_db_update($pdo, $table, (int)$row['id'], $data, $raw);
        }
    } catch (Throwable $e) {
        error_log('Today AI save: ' . $e->getMessage());
    }
}

/**
 * Ce qu'affiche le tableau de bord, SANS jamais appeler l'IA (la page ne l'attend pas) :
 *   - suggestions IA du jour en cache ;
 *   - repli statique en cache s'il a moins de 30 min (échec récent) ;
 *   - sinon le repli statique calculé maintenant, avec 'pending' => true :
 *     le partiel lance alors la génération IA en arrière-plan.
 */
function today_get_for_display(PDO $pdo, array $user): array
{
    if (($user['role'] ?? '') === 'follower') {
        return ['suggestions' => [], 'from_cache' => false, 'refresh_count' => 0, 'can_refresh' => false];
    }
    $cached = today_cache_row($pdo, (int)$user['id'], date('Y-m-d'));
    $count = (int)($cached['refresh_count'] ?? 0);

    if ($cached && (empty($cached['has_error']) || today_error_is_recent($cached))) {
        $suggestions = json_decode((string)($cached['suggestions_json'] ?? ''), true);
        if (is_array($suggestions) && $suggestions) {
            return [
                'suggestions' => $suggestions,
                'from_cache' => true,
                'refresh_count' => $count,
                'can_refresh' => $count < TODAY_AI_MAX_REFRESH_PER_DAY,
                'has_error' => !empty($cached['has_error']),
            ];
        }
    }

    $profile = today_get_user_profile($pdo, $user);
    $ctx = today_collect_context($pdo, $user, $profile);
    return [
        'suggestions' => today_fallback_suggestions($ctx),
        'from_cache' => false,
        'refresh_count' => $count,
        'can_refresh' => $count < TODAY_AI_MAX_REFRESH_PER_DAY,
        'has_error' => true,
        'pending' => today_ai_enabled(),
    ];
}

/**
 * Lit le cache ou génère les suggestions du jour.
 * Si force = true, régénère même si cache présent (pour refresh manuel).
 * À appeler hors de l'affichage (today-ai-refresh.php, session déjà libérée).
 */
function today_get_or_generate(PDO $pdo, array $user, bool $force = false): array
{
    if (($user['role'] ?? '') === 'follower') {
        return ['suggestions' => [], 'from_cache' => false, 'refresh_count' => 0, 'can_refresh' => false];
    }
    $user_id = (int)$user['id'];
    $today = date('Y-m-d');

    // Lire cache du jour
    $cached = today_cache_row($pdo, $user_id, $today);
    $count = (int)($cached['refresh_count'] ?? 0);
    $cached_suggestions = $cached ? (json_decode((string)($cached['suggestions_json'] ?? ''), true) ?: []) : [];

    // Cache servi tel quel : réponse IA du jour, ou échec récent (pas de nouvel appel avant 30 min)
    if ($cached && !$force && $cached_suggestions
        && (empty($cached['has_error']) || today_error_is_recent($cached))) {
        return [
            'suggestions' => $cached_suggestions,
            'from_cache' => true,
            'refresh_count' => $count,
            'can_refresh' => $count < TODAY_AI_MAX_REFRESH_PER_DAY,
            'has_error' => !empty($cached['has_error']),
        ];
    }

    // Rate-limit refresh
    if ($cached && $force && $count >= TODAY_AI_MAX_REFRESH_PER_DAY) {
        return [
            'suggestions' => $cached_suggestions,
            'from_cache' => true,
            'refresh_count' => $count,
            'can_refresh' => false,
            'rate_limited' => true,
            'has_error' => !empty($cached['has_error']),
        ];
    }

    // Une seule génération à la fois par utilisateur (second onglet, retour sur le
    // tableau de bord pendant « Personnalisation en cours… », double clic sur ↻) :
    // la requête suivante attend la première puis sert son résultat, sans nouvel appel.
    $lock = today_ai_lock($pdo, $user_id);
    if ($lock === null) {
        // Génération encore en cours ailleurs : cache ou repli, aucun appel IA
        $profile = today_get_user_profile($pdo, $user);
        return [
            'suggestions' => $cached_suggestions ?: today_fallback_suggestions(today_collect_context($pdo, $user, $profile)),
            'from_cache' => (bool)$cached_suggestions,
            'refresh_count' => $count,
            'can_refresh' => $count < TODAY_AI_MAX_REFRESH_PER_DAY,
            'has_error' => true,
        ];
    }
    try {
        $row_sig = fn(?array $r): string => $r
            ? implode('|', [$r['id'] ?? '', $r['last_generated_at'] ?? '', $r['refresh_count'] ?? '', $r['has_error'] ?? ''])
            : '';
        $fresh = today_cache_row($pdo, $user_id, $today);
        if ($row_sig($fresh) !== $row_sig($cached)) {
            // Ligne écrite pendant l'attente : c'est le résultat qu'on venait chercher
            $cached = $fresh;
            $count = (int)($cached['refresh_count'] ?? 0);
            $cached_suggestions = json_decode((string)($cached['suggestions_json'] ?? ''), true) ?: [];
            if ($cached_suggestions && (empty($cached['has_error']) || today_error_is_recent($cached))) {
                return [
                    'suggestions' => $cached_suggestions,
                    'from_cache' => true,
                    'refresh_count' => $count,
                    'can_refresh' => $count < TODAY_AI_MAX_REFRESH_PER_DAY,
                    'has_error' => !empty($cached['has_error']),
                ];
            }
        }
        return today_generate_and_save($pdo, $user, $force, $cached, $count, $cached_suggestions);
    } finally {
        today_ai_unlock($pdo, $lock);
    }
}

/**
 * Verrou MySQL par utilisateur autour de la génération IA (GET_LOCK, comme cron-lots.php :
 * nom préfixé de la base, le verrou porte sur tout le serveur). Attend au plus la durée
 * d'un appel. Retourne le nom du verrou, '' si GET_LOCK est indisponible (on génère sans,
 * comme avant), ou null si une autre génération le tient encore.
 */
function today_ai_lock(PDO $pdo, int $user_id): ?string
{
    try {
        $base = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
        $name = mb_substr($base, 0, 40) . ':today_ai:' . $user_id;
        $st = $pdo->prepare('SELECT GET_LOCK(?, ?)');
        $st->execute([$name, TODAY_AI_TIMEOUT_SECONDS + 10]);
        $got = $st->fetchColumn();
        $st->closeCursor();
        if ($got === null || $got === false) return '';
        if ((int)$got !== 1) return null;
        // Libéré aussi en fin de script (arrêt brutal, connexion persistante possible)
        register_shutdown_function(function () use ($pdo, $name): void {
            try { $pdo->prepare('DO RELEASE_LOCK(?)')->execute([$name]); } catch (Throwable $e) {}
        });
        return $name;
    } catch (Throwable $e) {
        error_log('Today AI lock: ' . $e->getMessage());
        return '';
    }
}

function today_ai_unlock(PDO $pdo, string $name): void
{
    if ($name === '') return;
    try { $pdo->prepare('DO RELEASE_LOCK(?)')->execute([$name]); } catch (Throwable $e) {}
}

/**
 * Appel IA + enregistrement de la ligne du jour (verrou de l'utilisateur déjà pris).
 */
function today_generate_and_save(PDO $pdo, array $user, bool $force, ?array $cached, int $count, array $cached_suggestions): array
{
    $profile = today_get_user_profile($pdo, $user);
    $ctx = today_collect_context($pdo, $user, $profile);
    $prompt = today_build_prompt($ctx);
    $result = today_call_claude($prompt);

    // Un rafraîchissement manuel compte dans la limite du jour, même s'il échoue
    // (sinon chaque clic relance un appel payant ou une attente de 30 s).
    // COALESCE : refresh_count NULL (colonne sans défaut) bloquait la limite (NULL + 1 = NULL).
    $new_count = $count + ($force ? 1 : 0);
    if ($cached) {
        $count_raw = $force ? ['refresh_count' => 'COALESCE(refresh_count, 0) + 1', 'last_refreshed_at' => 'NOW()'] : [];
    } else {
        $count_raw = ['refresh_count' => $force ? '1' : '0'] + ($force ? ['last_refreshed_at' => 'NOW()'] : []);
    }

    if (!$result['ok']) {
        error_log('Today AI: ' . mb_substr((string)$result['error'], 0, 300));

        // Rafraîchissement raté alors que les suggestions IA du jour sont en cache :
        // on les garde (la ligne n'est pas écrasée par le repli statique).
        if ($force && $cached && empty($cached['has_error']) && $cached_suggestions) {
            if ($count_raw) today_save_row($pdo, $cached, $user, $profile, [], $count_raw);
            return [
                'suggestions' => $cached_suggestions,
                'from_cache' => true,
                'refresh_count' => $new_count,
                'can_refresh' => $new_count < TODAY_AI_MAX_REFRESH_PER_DAY,
                'has_error' => true,
                'kept_previous' => true,
            ];
        }

        // Sinon : repli statique, mémorisé avec l'heure de l'échec (pas de nouvel essai avant 30 min)
        $fallback = today_fallback_suggestions($ctx);
        today_save_row($pdo, $cached, $user, $profile, [
            'suggestions_json' => json_encode($fallback),
            'has_error' => 1,
            'error_message' => mb_substr((string)$result['error'], 0, 500),
        ], ['last_generated_at' => 'NOW()'] + $count_raw);

        return [
            'suggestions' => $fallback,
            'from_cache' => false,
            'refresh_count' => $new_count,
            'can_refresh' => $new_count < TODAY_AI_MAX_REFRESH_PER_DAY,
            'has_error' => true,
        ];
    }

    // Succès : sauvegarder
    today_save_row($pdo, $cached, $user, $profile, [
        'suggestions_json' => json_encode($result['suggestions']),
        'tokens_input' => (int)($result['tokens_in'] ?? 0),
        'tokens_output' => (int)($result['tokens_out'] ?? 0),
        'has_error' => 0,
        'error_message' => null,
    ], ['last_generated_at' => 'NOW()'] + $count_raw);

    return [
        'suggestions' => $result['suggestions'],
        'from_cache' => false,
        'refresh_count' => $new_count,
        'can_refresh' => $new_count < TODAY_AI_MAX_REFRESH_PER_DAY,
    ];
}

/**
 * HTML des cartes de suggestions (contenu de #today-list) : partagé par le partiel
 * du tableau de bord et par today-ai-refresh (remplacement sans rechargement).
 */
function today_render_items(array $suggestions): string
{
    $colors_by_priority = [
        'urgent'    => ['bg' => '#FEF2F2', 'border' => '#FCA5A5', 'text' => '#991B1B'],
        'important' => ['bg' => '#FFFBEB', 'border' => '#FCD34D', 'text' => '#92400E'],
        'info'      => ['bg' => '#F0FDF4', 'border' => '#86EFAC', 'text' => '#166534'],
    ];
    $e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    // Dans l'application (ak_billing_hidden) : aucune suggestion qui invite à choisir une formule
    // ou à s'abonner, même rédigée par l'IA (Apple 3.1.1 / 3.1.3 f, règles de paiement Google Play).
    $hide_billing = function_exists('ak_billing_hidden') && ak_billing_hidden();
    $html = '';
    foreach ($suggestions as $sugg) {
        if (!is_array($sugg)) continue;
        if ($hide_billing && (
            preg_match('#^/(tarifs|abonnement|mon-asso-plan|mon-asso-paiement|mon-asso-factures(?:[?/]|$)|upgrade)#', (string)($sugg['link'] ?? ''))
            || preg_match('/formule|abonnement|essai/iu', (string)($sugg['title'] ?? '') . ' ' . (string)($sugg['description'] ?? '') . ' ' . (string)($sugg['link_label'] ?? ''))
        )) continue;
        $priority = (string)($sugg['priority'] ?? 'info');
        $colors = $colors_by_priority[$priority] ?? $colors_by_priority['info'];
        $link = (string)($sugg['link'] ?? '#');
        // Lien produit par l'IA : chemin interne uniquement (jamais javascript:, ni autre site)
        if (!preg_match('#^/(?![/\\\\])[A-Za-z0-9/_\-?=&.%\#]*$#', $link)) $link = '#';
        $icon = $sugg['icon'] ?? '✨';
        $label = !empty($sugg['link_label'])
            ? '<span style="font-size:11.5px; color:var(--acc-dark); font-weight:500; white-space:nowrap;">' . $e($sugg['link_label']) . '</span>'
            : '';
        $html .= '<a href="' . $e($link) . '" class="today-item" style="text-decoration:none; color:inherit;">'
            . '<div class="today-icon" style="background:' . $colors['bg'] . '; color:' . $colors['text'] . '; font-size:18px; line-height:1;">' . $e($icon) . '</div>'
            . '<div class="today-body">'
            . '<div class="today-title">' . $e($sugg['title'] ?? '') . '</div>'
            . '<div class="today-meta">' . $e($sugg['description'] ?? '') . '</div>'
            . '</div>'
            . '<div style="display:flex; align-items:center; gap:8px;">' . $label
            . '<svg class="today-arrow" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>'
            . '</div>'
            . '</a>';
    }
    return $html;
}

/**
 * Suggestions statiques en fallback (si API KO).
 * Génération basique à partir du contexte collecté.
 */
function today_fallback_suggestions(array $ctx): array
{
    $out = [];
    $profile = $ctx['profile'];
    $first_name = $ctx['user_first_name'] ?? '';

    // ====================================================
    // CAS 1 : ASSO VIDE (Admin uniquement) — ONBOARDING
    // ====================================================
    if ($profile === 'admin') {
        $folders_total = (int)($ctx['folders']['total'] ?? 0);
        $members_total = (int)($ctx['members']['total'] ?? 0);
        $clients_total = (int)($ctx['clients']['total'] ?? 0);
        $projects_total = count($ctx['projects'] ?? []);

        // 1.A — Aucun dossier → encourager création
        if ($folders_total === 0) {
            $out[] = [
                'icon' => '📁',
                'title' => 'Crée ton premier dossier de projets',
                'description' => 'Les dossiers regroupent tes projets par thématique (Événements, Social, Culturel…)',
                'link' => '/nouveau-dossier',
                'link_label' => '+ Créer un dossier',
                'priority' => 'important',
            ];
        }

        // 1.B — Dossiers existants mais aucun projet
        if ($folders_total > 0 && $projects_total === 0) {
            $out[] = [
                'icon' => '🚀',
                'title' => 'Lance ton premier projet',
                'description' => 'Tu as ' . $folders_total . ' dossier' . ($folders_total > 1 ? 's' : '') . ' prêt' . ($folders_total > 1 ? 's' : '') . '. Démarre un projet maintenant !',
                'link' => '/nouveau-projet',
                'link_label' => '+ Nouveau projet',
                'priority' => 'important',
            ];
        }

        // 1.C — Dossiers vides à remplir
        if ($folders_total > 0 && ($ctx['folders']['empty'] ?? 0) > 0 && $projects_total > 0) {
            $empty = (int)$ctx['folders']['empty'];
            $out[] = [
                'icon' => '📂',
                'title' => $empty . ' dossier' . ($empty > 1 ? 's vides' : ' vide') . ' à remplir',
                'description' => 'Ajoute des projets pour donner vie à tes dossiers',
                'link' => '/projets',
                'link_label' => 'Voir mes dossiers',
                'priority' => 'info',
            ];
        }

        // 1.D — Aucun adhérent → invite à inviter
        if ($members_total <= 1) {
            $out[] = [
                'icon' => '👥',
                'title' => 'Invite tes premiers adhérents',
                'description' => 'Construis ta communauté. Ajoute des membres pour collaborer ensemble.',
                // Formulaire d'ajout d'adhérent (envoie l'e-mail de création du mot de passe)
                'link' => '/nouveau-adherent',
                'link_label' => '+ Inviter un membre',
                'priority' => 'important',
            ];
        }

        // 1.E — Aucun client / 0 facturation → suggérer onboarding facturation
        if ($clients_total === 0) {
            $out[] = [
                'icon' => '🧾',
                'title' => 'Configure ta facturation',
                'description' => 'Émets ta première facture conforme (loi 1901 / TPE) : le client se crée dans le formulaire.',
                'link' => '/mon-asso-facture-new',
                'link_label' => 'Créer une facture',
                'priority' => 'info',
            ];
        }
    }

    // ====================================================
    // CAS 2 : ALERTES URGENTES (Admin)
    // ====================================================
    if ($profile === 'admin') {
        // Factures impayées en retard
        $overdue = (int)($ctx['invoices']['overdue_count'] ?? 0);
        if ($overdue > 0) {
            $amount = (float)($ctx['invoices']['overdue_amount'] ?? 0);
            $amount_str = number_format($amount, 0, ',', ' ') . ' €';
            $out[] = [
                'icon' => '⚠️',
                'title' => $overdue . ' facture' . ($overdue > 1 ? 's' : '') . ' client' . ($overdue > 1 ? 's' : '') . ' en retard',
                'description' => 'Total impayé : ' . $amount_str . ' · à relancer',
                // Factures clients de toutes les années ; filtre « En retard » quand elles le sont toutes
                'link' => '/mon-asso-factures-client?' . (empty($ctx['invoices']['pending_late']) ? 'status=overdue&' : '') . 'year=0',
                'link_label' => 'Voir les factures',
                'priority' => 'urgent',
            ];
        }

        // Cotisations expirées → page Relances (liste « Cotisations à renouveler »)
        $expired = (int)($ctx['members']['expired_cotisations'] ?? 0);
        if ($expired > 0) {
            $out[] = [
                'icon' => '⏳',
                'title' => $expired . ' cotisation' . ($expired > 1 ? 's' : '') . ' à renouveler',
                'description' => 'Adhérent' . ($expired > 1 ? 's' : '') . ' à relancer pour renouveler leur adhésion',
                'link' => '/relances',
                'link_label' => 'Relancer',
                'priority' => 'important',
            ];
        }

        // Support Assokit : les tickets sont les demandes de l'association à Assokit
        $support = (int)($ctx['messages']['support_unread'] ?? 0);
        if ($support > 0) {
            $out[] = [
                'icon' => '🛟',
                'title' => $support . ' réponse' . ($support > 1 ? 's' : '') . ' du support Assokit à lire',
                'description' => 'Le support Assokit vous a répondu sur ' . ($support > 1 ? 'vos demandes' : 'votre demande') . '.',
                'link' => '/support',
                'link_label' => 'Lire la réponse',
                'priority' => 'important',
            ];
        }
    }

    // ====================================================
    // CAS 2.5 : COORDINATEUR — TÂCHES OPÉRATIONNELLES (PIVOT)
    // ====================================================
    // Le coordinateur est le pivot opérationnel : il anime, organise,
    // assigne, modère. Ses tâches sont concrètes et tournées vers l'action.
    if ($profile === 'coordinator') {
        $coord = $ctx['coord_stats'] ?? [];

        // 🔴 PRIORITÉ MAX : Projets sans référent (à assigner d'urgence)
        $no_ref = (int)($coord['projects_no_referent'] ?? 0);
        if ($no_ref > 0) {
            $out[] = [
                'icon' => '🎯',
                'title' => $no_ref . ' projet' . ($no_ref > 1 ? 's' : '') . ' sans référent',
                'description' => 'À assigner pour démarrer · ton rôle de coordinateur',
                'link' => '/projets',
                'link_label' => 'Assigner des référents',
                'priority' => 'urgent',
            ];
        }

        // 🟡 Projets en warning à débloquer
        $warning = (int)($coord['projects_warning'] ?? 0);
        if ($warning > 0) {
            $out[] = [
                'icon' => '⚠️',
                'title' => $warning . ' projet' . ($warning > 1 ? 's à surveiller' : ' à surveiller'),
                'description' => 'Vérifie avec les référents si tout va bien',
                // Clé du filtre « À surveiller » de la page Projets
                'link' => '/projets?filter=warn',
                'link_label' => 'Voir les projets',
                'priority' => 'important',
            ];
        }

        // 🟡 Projets sans MAJ depuis 7+ jours (relance équipe)
        $stale = (int)($coord['projects_stale'] ?? 0);
        if ($stale > 0) {
            $out[] = [
                'icon' => '📞',
                'title' => 'Relance ' . $stale . ' référent' . ($stale > 1 ? 's' : ''),
                'description' => $stale . ' projet' . ($stale > 1 ? 's' : '') . ' sans MAJ depuis 7+ jours · besoin de nouvelles',
                'link' => '/projets',
                'link_label' => 'Voir les projets',
                'priority' => 'important',
            ];
        }

        // 🟡 Projets démarrés depuis 30+ jours mais < 25% d'avancement
        $low = (int)($coord['projects_low_progress'] ?? 0);
        if ($low > 0) {
            $out[] = [
                'icon' => '🐌',
                'title' => $low . ' projet' . ($low > 1 ? 's démarrent lentement' : ' démarre lentement'),
                'description' => 'Démarré' . ($low > 1 ? 's' : '') . ' depuis 30+ jours · moins de 25% d\'avancement',
                'link' => '/projets',
                'link_label' => 'Voir les projets',
                'priority' => 'info',
            ];
        }

        // 🟢 Événements à venir sans aucun RSVP (besoin de communication)
        $no_rsvp = (int)($coord['events_no_rsvp'] ?? 0);
        if ($no_rsvp > 0) {
            $out[] = [
                'icon' => '📣',
                'title' => $no_rsvp . ' événement' . ($no_rsvp > 1 ? 's sans RSVP' : ' sans RSVP'),
                'description' => 'Relance la communauté pour booster la participation',
                // Sans le droit Communication (403), relance par la messagerie
                'link' => !empty($ctx['can_communication']) ? '/communication?tab=evenements' : '/messages',
                'link_label' => !empty($ctx['can_communication']) ? 'Communiquer' : 'Relancer',
                'priority' => 'info',
            ];
        }

        // 🟢 Cotisations expirées (modération adhésions)
        $expired = (int)($ctx['members']['expired_cotisations'] ?? 0);
        if ($expired > 0) {
            $out[] = [
                'icon' => '⏳',
                'title' => $expired . ' cotisation' . ($expired > 1 ? 's à renouveler' : ' à renouveler'),
                'description' => 'Relance les adhérents pour maintenir leur adhésion',
                'link' => '/relances',
                'link_label' => 'Relancer',
                'priority' => 'important',
            ];
        }

        // 🟢 Activité messagerie (modération éventuelle)
        $msg_24h = (int)($coord['recent_messages_24h'] ?? 0);
        if ($msg_24h >= 10) {
            $out[] = [
                'icon' => '🌊',
                'title' => 'Activité forte sur les canaux',
                'description' => $msg_24h . ' messages dernières 24h · jette un œil à l\'animation',
                'link' => '/messages',
                'link_label' => 'Voir les canaux',
                'priority' => 'info',
            ];
        }

        // 🟢 Nouveaux adhérents à accueillir
        if (!empty($ctx['members']['new_7d']) && $ctx['members']['new_7d'] > 0) {
            $nb = (int)$ctx['members']['new_7d'];
            $names = $ctx['members']['recent_names'] ?? [];
            $desc = 'Inscrit(s) cette semaine · à accueillir personnellement';
            if (!empty($names) && count($names) <= 3) {
                $name_list = array_map(fn($n) => $n['first_name'] . ' ' . mb_substr((string)$n['last_name'], 0, 1) . '.', $names);
                $desc = implode(', ', $name_list) . ' · ton rôle de coordinateur';
            }
            $out[] = [
                'icon' => '👋',
                'title' => $nb . ' nouveau' . ($nb > 1 ? 'x' : '') . ' adhérent' . ($nb > 1 ? 's à accueillir' : ' à accueillir'),
                'description' => $desc,
                'link' => '/adherents',
                'link_label' => 'Accueillir',
                'priority' => 'info',
            ];
        }

        // 🟢 Onboarding coordinator si rien à faire (asso très récente)
        if ($no_ref === 0 && $warning === 0 && $stale === 0 && empty($ctx['projects'])) {
            $out[] = [
                'icon' => '🌱',
                'title' => 'Anime la dynamique de l\'équipe',
                'description' => 'Tout est calme · profite-en pour planifier le prochain événement ou former les référents',
                'link' => !empty($ctx['can_communication']) ? '/communication' : '/agenda',
                'link_label' => !empty($ctx['can_communication']) ? 'Communiquer' : 'Voir l\'agenda',
                'priority' => 'info',
            ];
        }
    }

    // ====================================================
    // CAS 3 : PROJETS PRIORITAIRES (admin, référent, member)
    // → Coordinator déjà couvert par CAS 2.5
    // ====================================================
    if (!empty($ctx['projects']) && $profile !== 'coordinator') {
        // Projets en warning
        $warnings = array_filter($ctx['projects'], fn($p) => $p['status'] === 'warning');
        if (!empty($warnings)) {
            $first = reset($warnings);
            $count = count($warnings);
            if ($count === 1) {
                $out[] = [
                    'icon' => '⚠️',
                    'title' => $first['name'] . ' — à suivre de près',
                    'description' => ($first['folder_name'] ?? '') . ' · avancement ' . (int)$first['progress_percent'] . '%',
                    'link' => '/projet/' . (int)$first['id'],
                    'link_label' => 'Voir le projet',
                    'priority' => 'important',
                ];
            } else {
                $out[] = [
                    'icon' => '⚠️',
                    'title' => $count . ' projets à surveiller',
                    'description' => 'Plusieurs projets nécessitent ton attention',
                    'link' => '/projets?filter=warn',
                    'link_label' => 'Voir mes projets',
                    'priority' => 'important',
                ];
            }
        }

        // Projets sans MAJ depuis 7+ jours (référent / admin)
        if (in_array($profile, ['admin', 'referent'], true)) {
            $stale = array_filter($ctx['projects'], fn($p) => ($p['days_since_update'] ?? 0) >= 7 && $p['status'] === 'active');
            if (!empty($stale)) {
                $first = reset($stale);
                $days = (int)$first['days_since_update'];
                if ($profile === 'referent') {
                    $out[] = [
                        'icon' => '📝',
                        'title' => 'Donne des nouvelles de "' . $first['name'] . '"',
                        'description' => 'Pas de mise à jour depuis ' . $days . ' jours · ton équipe attend',
                        'link' => '/projet/' . (int)$first['id'],
                        'link_label' => 'Mettre à jour',
                        'priority' => 'important',
                    ];
                } else {
                    $stale_count = count($stale);
                    $out[] = [
                        'icon' => '📝',
                        'title' => $stale_count . ' projet' . ($stale_count > 1 ? 's' : '') . ' sans MAJ récente',
                        'description' => 'Relance les référents pour avoir des nouvelles',
                        'link' => '/projets',
                        'link_label' => 'Voir les projets',
                        'priority' => 'info',
                    ];
                }
            }
        }
    }

    // ====================================================
    // CAS 4 : ÉVÉNEMENT PROCHE (tous profils)
    // ====================================================
    if (!empty($ctx['events'])) {
        $e = $ctx['events'][0];
        $days = (int)($e['days_until'] ?? 0);
        // Événement de l'Agenda ou événement publié depuis Communication (seul à avoir des RSVP).
        // Lien calculé par today_collect_context() selon les droits de l'utilisateur.
        $from_agenda = ($e['source'] ?? '') === 'agenda';
        $ev_link = (string)($e['link'] ?? '/agenda');
        $ev_title = $e['title'] . ($days === 0 ? " · aujourd'hui !" : ' · J-' . $days);
        if ($days >= 0 && $days <= 7) {
            if ($profile === 'member') {
                // Membre : répondre (page publique de l'événement) si le RSVP est ouvert et
                // qu'il n'a pas répondu, sinon simple rappel
                if (!empty($e['rsvp_enabled']) && empty($e['my_response']) && !empty($e['rsvp_link'])) {
                    $out[] = [
                        'icon' => '📅',
                        'title' => $ev_title,
                        'description' => 'Donne ta réponse pour ce prochain événement',
                        'link' => $e['rsvp_link'],
                        'link_label' => 'Répondre',
                        'priority' => 'important',
                    ];
                } elseif ($from_agenda) {
                    $out[] = [
                        'icon' => '📅',
                        'title' => $ev_title,
                        'description' => !empty($e['location']) ? 'À ' . $e['location'] . ' · prochain rendez-vous de ton association' : 'Prochain rendez-vous de ton association',
                        'link' => $ev_link,
                        'link_label' => 'Voir l\'agenda',
                        'priority' => 'info',
                    ];
                }
            } elseif ($profile === 'admin' || $profile === 'referent' || $profile === 'coordinator') {
                $rsvp_yes = (int)($e['nb_yes'] ?? 0);
                $out[] = [
                    'icon' => '📅',
                    'title' => $ev_title,
                    'description' => $rsvp_yes > 0 ? $rsvp_yes . ' personne' . ($rsvp_yes > 1 ? 's confirmées' : ' confirmée') : 'Prochain événement à préparer',
                    'link' => $ev_link,
                    'link_label' => 'Voir l\'événement',
                    'priority' => $days <= 1 ? 'urgent' : 'info',
                ];
            }
        }
    }

    // ====================================================
    // CAS 5 : NOUVEAUX ADHÉRENTS À ACCUEILLIR (Admin / Coord)
    // ====================================================
    if ($profile === 'admin' && !empty($ctx['members']['new_7d']) && $ctx['members']['new_7d'] > 0) {
        $nb = (int)$ctx['members']['new_7d'];
        $names = $ctx['members']['recent_names'] ?? [];
        $desc = 'Inscrit(s) cette semaine · à accueillir';
        if (!empty($names) && count($names) <= 3) {
            $name_list = array_map(fn($n) => $n['first_name'] . ' ' . mb_substr((string)$n['last_name'], 0, 1) . '.', $names);
            $desc = implode(', ', $name_list) . ' · à accueillir';
        }
        $out[] = [
            'icon' => '👋',
            'title' => $nb . ' nouveau' . ($nb > 1 ? 'x' : '') . ' adhérent' . ($nb > 1 ? 's' : ''),
            'description' => $desc,
            'link' => '/adherents',
            'link_label' => 'Voir les adhérents',
            'priority' => 'info',
        ];
    }

    // ====================================================
    // CAS 6 : MESSAGES NON LUS (tous profils)
    // ====================================================
    $unread = (int)($ctx['messages']['unread'] ?? 0);
    if ($unread > 0) {
        $out[] = [
            'icon' => '💬',
            'title' => $unread . ' message' . ($unread > 1 ? 's' : '') . ' non lu' . ($unread > 1 ? 's' : ''),
            'description' => 'Ton équipe a posté du nouveau · va y jeter un œil',
            'link' => '/messages',
            'link_label' => 'Voir les messages',
            'priority' => 'info',
        ];
    }

    // ====================================================
    // CAS 7 : ABONNEMENT (Admin) — alerte fin de période
    // ====================================================
    if ($profile === 'admin' && !empty($ctx['subscription'])) {
        $sub = $ctx['subscription'];
        $days_until = (int)($sub['days_until'] ?? 0);
        $sub_status = (string)($sub['status'] ?? '');
        // Abonnement déjà souscrit pendant l'essai : rien à choisir, le premier prélèvement est programmé
        if ($sub_status === 'trial' && isset($sub['days_until']) && $days_until <= 7 && empty($sub['trial_subscribed'])) {
            $out[] = [
                'icon' => '⚡',
                'title' => ($days_until <= 0 || !empty($sub['ends_today'])) ? 'Ton essai PRO se termine aujourd’hui'
                         : 'Ton essai PRO se termine dans ' . $days_until . ' jour' . ($days_until > 1 ? 's' : ''),
                'description' => (!empty($sub['trial_ends_fr']) ? 'Fin le ' . $sub['trial_ends_fr'] . ' · ' : '')
                               . 'choisis ta formule pour continuer à profiter d’Assokit',
                'link' => '/mon-asso-plan',
                'link_label' => 'Voir les formules',
                'priority' => 'urgent',
            ];
        } elseif ($sub_status === 'trial_expired') {
            $out[] = [
                'icon' => '⏳',
                'title' => (string)($sub['label'] ?? 'Essai terminé'),
                'description' => 'Tes données sont conservées · choisis une formule pour retrouver toutes les fonctionnalités',
                'link' => '/mon-asso-plan',
                'link_label' => 'Choisir une formule',
                'priority' => 'urgent',
            ];
        }
    }

    // ====================================================
    // CAS 8 : SUGGESTIONS POUR RÉFÉRENT (vue projets)
    // ====================================================
    if ($profile === 'referent' && !empty($ctx['projects'])) {
        $first = $ctx['projects'][0];
        // S'il y a un projet actif sans warning et déjà ajouté plus haut, on n'ajoute pas en double
        $already_added = false;
        foreach ($out as $existing) {
            if (strpos($existing['title'] ?? '', $first['name']) !== false) {
                $already_added = true;
                break;
            }
        }
        if (!$already_added && (int)($first['progress_percent'] ?? 0) < 100) {
            $out[] = [
                'icon' => '🎯',
                'title' => 'Ton projet "' . $first['name'] . '"',
                'description' => 'Avancement : ' . (int)$first['progress_percent'] . '% · ' . ($first['folder_name'] ?? ''),
                'link' => '/projet/' . (int)$first['id'],
                'link_label' => 'Voir le projet',
                'priority' => 'info',
            ];
        }
    }

    // ====================================================
    // CAS 9 : MEMBRE — ENGAGEMENT
    // ====================================================
    if ($profile === 'member') {
        // Pas de projets visibles
        if (empty($ctx['projects'])) {
            $out[] = [
                'icon' => '🌱',
                'title' => 'Bienvenue dans ton espace, ' . $first_name . ' !',
                'description' => 'Découvre les projets et événements de ton association',
                'link' => '/projets',
                'link_label' => 'Explorer les projets',
                'priority' => 'info',
            ];
        }
    }

    // ====================================================
    // FALLBACK ULTIME : message positif si vraiment rien
    // ====================================================
    if (empty($out)) {
        $hello = $first_name ? 'Bonne journée, ' . $first_name . ' !' : 'Bonne journée !';
        if ($profile === 'admin') {
            $out[] = [
                'icon' => '✨',
                'title' => $hello,
                'description' => 'Tout est sous contrôle aujourd\'hui · explore tes projets ou consulte les statistiques',
                'link' => '/mon-asso-stats',
                'link_label' => 'Voir les stats',
                'priority' => 'info',
            ];
        } elseif ($profile === 'coordinator') {
            $out[] = [
                'icon' => '🎼',
                'title' => $hello,
                'description' => 'Bel équilibre dans l\'équipe aujourd\'hui · profite-en pour anticiper la suite',
                'link' => '/projets',
                'link_label' => 'Voir les projets',
                'priority' => 'info',
            ];
        } elseif ($profile === 'referent') {
            $out[] = [
                'icon' => '🎯',
                'title' => $hello,
                'description' => 'Aucune action urgente · prends le temps de planifier la suite',
                'link' => '/projets',
                'link_label' => 'Mes projets',
                'priority' => 'info',
            ];
        } else {
            $out[] = [
                'icon' => '🌿',
                'title' => $hello,
                'description' => 'Aucune action urgente · profite de la journée',
                'link' => '/agenda',
                'link_label' => 'Voir l\'agenda',
                'priority' => 'info',
            ];
        }
    }

    // ====================================================
    // TRI PAR PRIORITÉ : urgent > important > info
    // ====================================================
    $priority_order = ['urgent' => 0, 'important' => 1, 'info' => 2];
    usort($out, fn($a, $b) => ($priority_order[$a['priority']] ?? 3) <=> ($priority_order[$b['priority']] ?? 3));

    // Limite à 5 suggestions max (sinon le dashboard devient lourd)
    return array_slice($out, 0, 5);
}
