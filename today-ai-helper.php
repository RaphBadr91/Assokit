<?php
/**
 * ============================================================
 * ASSOKIT — Helper Today AI Copilot
 * ============================================================
 * Fonctions :
 *   - today_collect_context() : rassemble la photo du jour de l'asso
 *   - today_build_prompt()    : construit le prompt selon le profil
 *   - today_call_claude()     : appelle l'API Claude
 *   - today_get_or_generate() : lit cache ou génère si besoin
 *   - today_fallback_suggestions() : suggestions statiques si API KO
 * ============================================================
 */

if (!defined('TODAY_AI_MAX_REFRESH_PER_DAY')) {
    define('TODAY_AI_MAX_REFRESH_PER_DAY', 3);
}
if (!defined('TODAY_AI_TIMEOUT_SECONDS')) {
    define('TODAY_AI_TIMEOUT_SECONDS', 15);
}
if (!defined('TODAY_AI_MODEL')) {
    define('TODAY_AI_MODEL', 'claude-3-5-haiku-latest');
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
    ];

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
    } catch (Throwable $e) {
        error_log('Today AI context projects: ' . $e->getMessage());
    }

    // === EVENEMENTS (agenda) ===
    try {
        $stmt = $pdo->prepare("
            SELECT e.id, e.title, e.start_date, e.location, e.rsvp_enabled,
                   (SELECT COUNT(*) FROM communication_event_rsvps WHERE event_id = e.id AND response = 'yes') AS nb_yes,
                   (SELECT COUNT(*) FROM communication_event_rsvps WHERE event_id = e.id) AS nb_total_rsvp,
                   DATEDIFF(e.start_date, NOW()) AS days_until
            FROM communication_events e
            WHERE e.org_id = ? AND e.status = 'published'
              AND e.start_date >= NOW() AND e.start_date <= DATE_ADD(NOW(), INTERVAL 30 DAY)
            ORDER BY e.start_date ASC
            LIMIT 5
        ");
        $stmt->execute([$org_id]);
        $ctx['events'] = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // Pour member : a-t-il répondu ?
        if ($profile === 'member') {
            foreach ($ctx['events'] as $k => $ev) {
                $s2 = $pdo->prepare("SELECT response FROM communication_event_rsvps WHERE event_id = ? AND user_id = ? LIMIT 1");
                $s2->execute([(int)$ev['id'], $user_id]);
                $ctx['events'][$k]['my_response'] = $s2->fetchColumn() ?: null;
            }
        }
    } catch (Throwable $e) {
        error_log('Today AI context events: ' . $e->getMessage());
    }

    // === ADHERENTS (admin uniquement) ===
    if ($profile === 'admin' || $profile === 'coordinator') {
        try {
            $stmt = $pdo->prepare("
                SELECT COUNT(*) AS total,
                       SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) AS new_7d,
                       SUM(CASE WHEN adhesion_valid_until IS NOT NULL AND adhesion_valid_until < NOW() THEN 1 ELSE 0 END) AS expired_cotisations
                FROM users
                WHERE org_id = ? AND (deleted_at IS NULL) AND is_active = 1
            ");
            $stmt->execute([$org_id]);
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
                  AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                ORDER BY created_at DESC LIMIT 3
            ");
            $stmt->execute([$org_id]);
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
            // Événements à venir sans aucun RSVP (besoin de relance)
            $stmt = $pdo->prepare("
                SELECT COUNT(*) FROM communication_events e
                WHERE e.org_id = ? AND e.status = 'published'
                  AND e.start_date >= NOW() AND e.start_date <= DATE_ADD(NOW(), INTERVAL 14 DAY)
                  AND (SELECT COUNT(*) FROM communication_event_rsvps WHERE event_id = e.id) = 0
            ");
            $stmt->execute([$org_id]);
            $ctx['coord_stats']['events_no_rsvp'] = (int)$stmt->fetchColumn();
        } catch (Throwable $e) {}

        try {
            // Messages dernières 24h dans les canaux (besoin de modération éventuelle)
            $stmt = $pdo->prepare("
                SELECT COUNT(*) FROM messages
                WHERE org_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
            ");
            @$stmt->execute([$org_id]);
            $ctx['coord_stats']['recent_messages_24h'] = (int)($stmt->fetchColumn() ?: 0);
        } catch (Throwable $e) {}
    }

    // === MESSAGES non lus (tous profils) ===
    try {
        // On essaie d'abord la table messages si elle existe
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM messages m
            WHERE m.org_id = ? AND (m.recipient_user_id = ? OR m.recipient_user_id IS NULL)
              AND m.created_at > COALESCE((SELECT last_read_at FROM message_read_status WHERE user_id = ? AND channel_id = m.channel_id), '1970-01-01')
              AND m.sender_user_id != ?
        ");
        @$stmt->execute([$org_id, $user_id, $user_id, $user_id]);
        $ctx['messages']['unread'] = (int)($stmt->fetchColumn() ?: 0);
    } catch (Throwable $e) {
        $ctx['messages']['unread'] = 0;
    }

    // Tickets support (admin only)
    if ($profile === 'admin') {
        try {
            $stmt = $pdo->prepare("
                SELECT COUNT(*) FROM support_tickets
                WHERE org_id = ? AND status IN ('open','in_progress')
            ");
            $stmt->execute([$org_id]);
            $ctx['messages']['open_tickets'] = (int)($stmt->fetchColumn() ?: 0);
        } catch (Throwable $e) {
            $ctx['messages']['open_tickets'] = 0;
        }
    }

    // === FACTURES (admin only) ===
    if ($profile === 'admin') {
        try {
            // Factures clients de l'asso impayées
            $stmt = $pdo->prepare("
                SELECT COUNT(*) AS nb, COALESCE(SUM(total_ttc), 0) AS amount
                FROM invoices
                WHERE org_id = ? AND status IN ('sent','overdue')
                  AND due_date < NOW()
            ");
            @$stmt->execute([$org_id]);
            $r = $stmt->fetch(PDO::FETCH_ASSOC);
            $ctx['invoices'] = [
                'overdue_count' => (int)($r['nb'] ?? 0),
                'overdue_amount' => (float)($r['amount'] ?? 0),
            ];
        } catch (Throwable $e) {
            $ctx['invoices'] = ['overdue_count' => 0, 'overdue_amount' => 0];
        }

        // Abonnement Assokit
        try {
            $stmt = $pdo->prepare("
                SELECT next_billing_date, plan_type, status,
                       DATEDIFF(next_billing_date, NOW()) AS days_until
                FROM organization_subscriptions
                WHERE org_id = ? AND status IN ('active','trial')
                ORDER BY id DESC LIMIT 1
            ");
            @$stmt->execute([$org_id]);
            $ctx['subscription'] = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
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
            $line = "- \"{$p['name']}\" ({$p['folder_name']}) : {$p['progress_percent']}%";
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
            if (!empty($p['budget_planned']) && $p['budget_planned'] > 0) {
                $pct = round(($p['budget_used'] / $p['budget_planned']) * 100);
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
            if ($e['rsvp_enabled']) {
                $line .= ", {$e['nb_yes']} RSVP positifs";
                if (!empty($e['nb_total_rsvp'])) $line .= " / {$e['nb_total_rsvp']} réponses";
            }
            if ($profile === 'member' && !empty($e['my_response'])) {
                $line .= ", MA RÉPONSE : {$e['my_response']}";
            } elseif ($profile === 'member') {
                $line .= ", JE N'AI PAS ENCORE RÉPONDU";
            }
            $prompt .= $line . "\n";
        }
        $prompt .= "\n";
    }

    // === ADHERENTS (admin) ===
    if ($profile === 'admin' && !empty($ctx['members'])) {
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
    if (!empty($ctx['messages']['unread']) || !empty($ctx['messages']['open_tickets'])) {
        $prompt .= "💬 COMMUNICATION :\n";
        if (!empty($ctx['messages']['unread'])) {
            $prompt .= "- {$ctx['messages']['unread']} messages non lus\n";
        }
        if (!empty($ctx['messages']['open_tickets'])) {
            $prompt .= "- {$ctx['messages']['open_tickets']} tickets support ouverts\n";
        }
        $prompt .= "\n";
    }

    // === FACTURES (admin) ===
    if ($profile === 'admin' && !empty($ctx['invoices']) && $ctx['invoices']['overdue_count'] > 0) {
        $prompt .= "💰 FACTURES :\n";
        $prompt .= "- {$ctx['invoices']['overdue_count']} facture(s) impayée(s) en retard — " . number_format($ctx['invoices']['overdue_amount'], 2, ',', ' ') . "€\n\n";
    }

    // === ABONNEMENT ASSOKIT (admin) ===
    if ($profile === 'admin' && !empty($ctx['subscription']) && isset($ctx['subscription']['days_until'])) {
        $days = (int)$ctx['subscription']['days_until'];
        if ($days >= 0 && $days <= 14) {
            $prompt .= "🔔 ABONNEMENT ASSOKIT :\n";
            $prompt .= "- Prochaine échéance dans {$days} jours\n";
            $prompt .= "- Plan actuel : " . ($ctx['subscription']['plan_type'] ?? 'standard') . "\n\n";
        }
    }

    // === INSTRUCTIONS ===
    $prompt .= "CONSIGNES :\n";

    if ($profile === 'admin') {
        $prompt .= "Propose 3 à 5 actions prioritaires pour cet ADMINISTRATEUR D'ASSOCIATION. Priorise :\n";
        $prompt .= "- Factures impayées et abonnement Assokit à renouveler (urgent)\n";
        $prompt .= "- Relances adhérents (RSVP, cotisations expirées)\n";
        $prompt .= "- Projets en retard ou bloqués (coordination équipe)\n";
        $prompt .= "- Communication (tickets support, messages, accueil nouveaux)\n";
    } elseif ($profile === 'referent') {
        $prompt .= "Propose 3 à 5 actions prioritaires pour ce RÉFÉRENT DE PROJET. Priorise :\n";
        $prompt .= "- Mise à jour de l'avancement des projets inactifs\n";
        $prompt .= "- Clôture des projets à 100%\n";
        $prompt .= "- Suivi budget et deadlines proches\n";
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
    $prompt .= "- un \"link\" URL relative (/projet/X, /adherents, /communication, /factures, etc.)\n";
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

    $payload = [
        'model' => TODAY_AI_MODEL,
        'max_tokens' => 1000,
        'messages' => [
            ['role' => 'user', 'content' => $prompt],
        ],
    ];

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_TIMEOUT => TODAY_AI_TIMEOUT_SECONDS,
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
 * Lit le cache ou génère les suggestions du jour.
 * Si force = true, régénère même si cache présent (pour refresh manuel).
 */
function today_get_or_generate(PDO $pdo, array $user, bool $force = false): array
{
    $user_id = (int)$user['id'];
    $org_id = (int)$user['org_id'];
    $today = date('Y-m-d');

    // Lire cache du jour
    $stmt = $pdo->prepare("
        SELECT suggestions_json, refresh_count, has_error
        FROM today_suggestions
        WHERE user_id = ? AND generation_date = ?
    ");
    $stmt->execute([$user_id, $today]);
    $cached = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($cached && !$force && !$cached['has_error']) {
        $suggestions = json_decode($cached['suggestions_json'], true) ?: [];
        return [
            'suggestions' => $suggestions,
            'from_cache' => true,
            'refresh_count' => (int)$cached['refresh_count'],
            'can_refresh' => (int)$cached['refresh_count'] < TODAY_AI_MAX_REFRESH_PER_DAY,
        ];
    }

    // Rate-limit refresh
    if ($cached && $force && (int)$cached['refresh_count'] >= TODAY_AI_MAX_REFRESH_PER_DAY) {
        $suggestions = json_decode($cached['suggestions_json'], true) ?: [];
        return [
            'suggestions' => $suggestions,
            'from_cache' => true,
            'refresh_count' => (int)$cached['refresh_count'],
            'can_refresh' => false,
            'rate_limited' => true,
        ];
    }

    // Générer
    $profile = today_get_user_profile($pdo, $user);
    $ctx = today_collect_context($pdo, $user, $profile);
    $prompt = today_build_prompt($ctx);
    $result = today_call_claude($prompt);

    if (!$result['ok']) {
        // Fallback : suggestions statiques
        $fallback = today_fallback_suggestions($ctx);

        // Stocker l'erreur (mais utiliser le fallback)
        try {
            if ($cached) {
                $stmt = $pdo->prepare("
                    UPDATE today_suggestions
                    SET suggestions_json = ?, has_error = 1, error_message = ?,
                        last_generated_at = NOW(), profile = ?
                    WHERE id = (SELECT id FROM (SELECT id FROM today_suggestions WHERE user_id = ? AND generation_date = ?) AS t)
                ");
                @$stmt->execute([json_encode($fallback), mb_substr($result['error'], 0, 500), $profile, $user_id, $today]);
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO today_suggestions
                        (user_id, org_id, profile, generation_date, suggestions_json, has_error, error_message)
                    VALUES (?, ?, ?, ?, ?, 1, ?)
                ");
                $stmt->execute([$user_id, $org_id, $profile, $today, json_encode($fallback), mb_substr($result['error'], 0, 500)]);
            }
        } catch (Throwable $e) {}

        return [
            'suggestions' => $fallback,
            'from_cache' => false,
            'refresh_count' => $cached ? (int)$cached['refresh_count'] : 0,
            'can_refresh' => true,
            'has_error' => true,
        ];
    }

    // Succès : sauvegarder
    $json = json_encode($result['suggestions']);
    try {
        if ($cached) {
            $stmt = $pdo->prepare("
                UPDATE today_suggestions
                SET suggestions_json = ?, tokens_input = ?, tokens_output = ?,
                    refresh_count = refresh_count + ?, last_refreshed_at = ?,
                    has_error = 0, error_message = NULL, profile = ?
                WHERE user_id = ? AND generation_date = ?
            ");
            $stmt->execute([
                $json, $result['tokens_in'], $result['tokens_out'],
                $force ? 1 : 0, $force ? date('Y-m-d H:i:s') : null,
                $profile, $user_id, $today,
            ]);
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO today_suggestions
                    (user_id, org_id, profile, generation_date, suggestions_json,
                     tokens_input, tokens_output, refresh_count)
                VALUES (?, ?, ?, ?, ?, ?, ?, 0)
            ");
            $stmt->execute([$user_id, $org_id, $profile, $today, $json, $result['tokens_in'], $result['tokens_out']]);
        }
    } catch (Throwable $e) {
        error_log('Today AI save: ' . $e->getMessage());
    }

    return [
        'suggestions' => $result['suggestions'],
        'from_cache' => false,
        'refresh_count' => ($cached ? (int)$cached['refresh_count'] : 0) + ($force ? 1 : 0),
        'can_refresh' => (($cached ? (int)$cached['refresh_count'] : 0) + ($force ? 1 : 0)) < TODAY_AI_MAX_REFRESH_PER_DAY,
    ];
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
                'link' => '/nouveau-utilisateur',
                'link_label' => '+ Inviter un membre',
                'priority' => 'important',
            ];
        }

        // 1.E — Aucun client / 0 facturation → suggérer onboarding facturation
        if ($clients_total === 0) {
            $out[] = [
                'icon' => '🧾',
                'title' => 'Configure ta facturation',
                'description' => 'Crée ton premier client pour émettre des factures conformes (loi 1901 / TPE).',
                'link' => '/mon-asso-facture-new',
                'link_label' => 'Créer un client',
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
                'title' => $overdue . ' facture' . ($overdue > 1 ? 's' : '') . ' en retard',
                'description' => 'Total impayé : ' . $amount_str . ' · à relancer',
                'link' => '/mon-asso-factures?status=overdue',
                'link_label' => 'Voir les factures',
                'priority' => 'urgent',
            ];
        }

        // Cotisations expirées
        $expired = (int)($ctx['members']['expired_cotisations'] ?? 0);
        if ($expired > 0) {
            $out[] = [
                'icon' => '⏳',
                'title' => $expired . ' cotisation' . ($expired > 1 ? 's' : '') . ' à renouveler',
                'description' => 'Adhérent' . ($expired > 1 ? 's' : '') . ' à relancer pour renouveler leur adhésion',
                'link' => '/adherents?filter=expired',
                'link_label' => 'Voir les adhérents',
                'priority' => 'important',
            ];
        }

        // Tickets support ouverts
        $tickets = (int)($ctx['messages']['open_tickets'] ?? 0);
        if ($tickets > 0) {
            $out[] = [
                'icon' => '💬',
                'title' => $tickets . ' ticket' . ($tickets > 1 ? 's' : '') . ' support ouvert' . ($tickets > 1 ? 's' : ''),
                'description' => 'Des adhérents ont besoin d\'aide. Réponds rapidement.',
                'link' => '/admin-tickets',
                'link_label' => 'Voir les tickets',
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
                'link' => '/projets?filter=no_referent',
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
                'link' => '/projets?filter=warning',
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
                'link' => '/projets?filter=stale',
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
                'link' => '/communication?tab=evenements',
                'link_label' => 'Communiquer',
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
                'link' => '/adherents?filter=expired',
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
                'link' => '/communication',
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
                $name_list = array_map(fn($n) => $n['first_name'] . ' ' . substr($n['last_name'], 0, 1) . '.', $names);
                $desc = implode(', ', $name_list) . ' · ton rôle de coordinateur';
            }
            $out[] = [
                'icon' => '👋',
                'title' => $nb . ' nouveau' . ($nb > 1 ? 'x' : '') . ' adhérent' . ($nb > 1 ? 's à accueillir' : ' à accueillir'),
                'description' => $desc,
                'link' => '/adherents?filter=new',
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
                'link' => '/communication',
                'link_label' => 'Communiquer',
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
                    'link' => '/projets',
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
        if ($days >= 0 && $days <= 7) {
            // Pour membre : check si pas répondu
            if ($profile === 'member' && empty($e['my_response'])) {
                $out[] = [
                    'icon' => '📅',
                    'title' => $e['title'] . ($days === 0 ? " · aujourd'hui !" : ' · J-' . $days),
                    'description' => 'Donne ta réponse pour ce prochain événement',
                    'link' => '/communication?tab=evenements',
                    'link_label' => 'Répondre',
                    'priority' => 'important',
                ];
            } elseif ($profile === 'admin' || $profile === 'referent' || $profile === 'coordinator') {
                $rsvp_yes = (int)($e['nb_yes'] ?? 0);
                $out[] = [
                    'icon' => '📅',
                    'title' => $e['title'] . ($days === 0 ? " · aujourd'hui !" : ' · J-' . $days),
                    'description' => $rsvp_yes > 0 ? $rsvp_yes . ' personne' . ($rsvp_yes > 1 ? 's confirmées' : ' confirmée') : 'Prochain événement à préparer',
                    'link' => '/communication?tab=evenements',
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
            $name_list = array_map(fn($n) => $n['first_name'] . ' ' . substr($n['last_name'], 0, 1) . '.', $names);
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
            'link' => '/communication',
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
        if ($sub['status'] === 'trial' && $days_until >= 0 && $days_until <= 7) {
            $out[] = [
                'icon' => '⚡',
                'title' => 'Ton essai se termine dans ' . $days_until . ' jour' . ($days_until > 1 ? 's' : ''),
                'description' => 'Choisis ton plan pour continuer à profiter d\'Assokit',
                'link' => '/mon-asso-plan',
                'link_label' => 'Voir les plans',
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
                'link' => '/communication',
                'link_label' => 'Espace communauté',
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
