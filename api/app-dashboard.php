<?php
/**
 * api/app-dashboard.php — KPIs pour l'ecran d'accueil NATIF de l'app mobile.
 * Reponse JSON, authentifiee par la session (cookie partage avec la WebView).
 * NE MODIFIE PAS le site : fichier dedie a l'application.
 */
require_once __DIR__ . '/_app-read-boot.php';   // compte actif garanti : $user, $uid, $org_id
require_once __DIR__ . '/../includes-attendance.php';   // ak_event_visibility_sql()

try {
    $first_name = trim((string) ($user['first_name'] ?? ''));

    // Fondateur : détection robuste (relit la base) — app-only, aucun impact site.
    require_once __DIR__ . '/_app-founder.php';
    $is_founder = app_is_founder($pdo, $user);

    // Droits : les mêmes règles que le site. Budgets = user_can_view_finances() (dashboard.php,
    // projet.php) ; factures, devis, clients, impayés = user_can_access_billing() (admin et
    // coordinateur, comme /mon-asso-factures). Les montants non autorisés partent à null.
    require_once __DIR__ . '/../finance-permissions.php';
    $perm_user = $user ?: [];
    if ($is_founder) $perm_user['is_founder'] = 1;   // current_user() ne le porte pas toujours
    $can_fin  = user_can_view_finances($perm_user);
    $can_bill = user_can_access_billing($perm_user);

    // Agent mairie / collectivité : aucun espace association (org_id vide). L'appli n'a pas
    // d'espace collectivité : on renvoie profile « mairie » et le lien du site, au lieu d'un
    // faux espace association vide où l'agent passait pour « admin ».
    $mairie = null;
    if ($org_id <= 0 && $user) {
        try {
            require_once __DIR__ . '/../includes-permissions.php';
            if (is_parent_org_user($user)) {
                // Collectivité en attente, suspendue ou refusée : son espace est fermé sur le site
                // aussi (current_parent_org) ; l'appli l'explique au lieu de renvoyer vers le site.
                $po = ak_parent_org_row_for_user($user);
                $po_open = parent_org_is_open($po) || is_platform_admin($user);
                $mairie = [
                    'name'     => (string) ($po['name'] ?? ''),
                    'type'     => (string) ($po['type'] ?? 'mairie'),
                    'web_path' => '/mairie-dashboard',
                    'message'  => $po_open
                        ? 'L’espace collectivité (associations suivies, messagerie, quota) est disponible sur le site.'
                        : ak_parent_org_closed_message($po),
                    'open'     => $po_open,
                ];
            }
        } catch (Throwable $e) {}
    }
    // Agent mairie : aucune donnée d'association. Sans association, les requêtes ci-dessous liraient
    // l'espace « org 0 », commun à toutes les collectivités (fermé aux agents : ak_mairie_space_guard)
    // Fondateur ou super-admin non rattaché (org_id vide) : de même, aucun droit d'association
    // (factures, budgets, boîte mail, créations : chaque écriture répond 400 'org'). has_org le dit à
    // l'appli, qui ne garde alors que l'Espace Fondateur, les notifications, les paramètres et le support.
    $has_org = $org_id > 0;
    if ($mairie || !$has_org) { $can_fin = false; $can_bill = false; $org_id = -1; }

    // Suiveur (financeur externe) : uniquement ses projets suivis, comme /projets et /agenda.
    $fpids = function_exists('get_follower_project_ids') ? get_follower_project_ids()
           : ((($user['role'] ?? '') === 'follower') ? [] : null);
    $is_follower = ($fpids !== null);
    $fol_sql = function (string $col) use ($fpids): string {
        if ($fpids === null) return '';
        return $fpids ? ' AND ' . $col . ' IN (' . implode(',', array_map('intval', $fpids)) . ')' : ' AND 1 = 0';
    };

    // Subventions : la règle du site (gr_can_manage : /subventions, /financements, app-grants),
    // plus stricte que la facturation — un trésorier non coordinateur n'y a pas accès.
    require_once __DIR__ . '/../includes-grants.php';
    $can_grants = $has_org && !$mairie && !$is_follower && gr_can_manage($perm_user);

    // Ajout d'une dépense de projet : admin, coordinateur, droit finances, ou équipe d'au moins un
    // projet en cours (référent ou membre) — la règle d'api/app-create-expense.php
    $can_add_expense = false;
    if ($has_org && !$mairie && !$is_follower) {
        $can_add_expense = ak_expense_any_project($user);
        if (!$can_add_expense) try {
            $st = $pdo->prepare("SELECT 1 FROM projects p JOIN folders f ON f.id = p.folder_id
                                 WHERE f.org_id = ? AND p.archived_at IS NULL AND f.archived_at IS NULL
                                   AND (p.referent_id = ? OR EXISTS (SELECT 1 FROM project_members pm WHERE pm.project_id = p.id AND pm.user_id = ?))
                                 LIMIT 1");
            $st->execute([$org_id, $uid, $uid]);
            $can_add_expense = (bool) $st->fetchColumn();
        } catch (Throwable $e) {}
    }

    // Organisation
    $stmt = $pdo->prepare("SELECT name FROM organizations WHERE id = ? LIMIT 1");
    $stmt->execute([$org_id]);
    $org_name = (string) ($stmt->fetchColumn() ?: '');

    // Logo de l'org (defensif : la colonne peut ne pas exister)
    $org_logo = null;
    try {
        $stmt = $pdo->prepare("SELECT logo_path FROM organizations WHERE id = ? LIMIT 1");
        $stmt->execute([$org_id]);
        $lp = trim((string) ($stmt->fetchColumn() ?: ''));
        if ($lp !== '') {
            $org_logo = (strpos($lp, 'http') === 0) ? $lp : 'https://assokit.fr/' . ltrim($lp, '/');
        }
    } catch (Throwable $e) {}

    // Projets actifs
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM projects p
        JOIN folders f ON p.folder_id = f.id
        WHERE f.org_id = ? AND p.status IN ('active','warning')
          AND p.archived_at IS NULL AND f.archived_at IS NULL" . $fol_sql('p.id') . "
    ");
    $stmt->execute([$org_id]);
    $active_projects = (int) $stmt->fetchColumn();

    // Membres actifs (pas pour un suiveur : il ne voit pas les adhérents sur le site)
    $total_users = null; $new_users = null;
    if (!$is_follower) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE org_id = ? AND (deleted_at IS NULL OR deleted_at = '') AND is_active = 1");
        $stmt->execute([$org_id]);
        $total_users = (int) $stmt->fetchColumn();

        // Nouveaux membres (30j)
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM users
            WHERE org_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
              AND is_active = 1 AND (deleted_at IS NULL OR deleted_at = '')
        ");
        $stmt->execute([$org_id]);
        $new_users = (int) $stmt->fetchColumn();
    }

    // Budget engage (projets actifs) : réservé aux rôles finances
    $b = ['used' => null, 'planned' => null];
    if ($can_fin) {
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(p.budget_used),0) AS used, COALESCE(SUM(p.budget_planned),0) AS planned
            FROM projects p JOIN folders f ON p.folder_id = f.id
            WHERE f.org_id = ? AND p.status IN ('active','warning')
              AND p.archived_at IS NULL AND f.archived_at IS NULL
        ");
        $stmt->execute([$org_id]);
        $b = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['used' => 0, 'planned' => 0];
    }

    // Événements : ceux que l'agenda montre à ce compte (api/app-events.php, /agenda) — suiveur
    // limité à ses projets, « Équipe du projet uniquement » réservé à l'équipe du projet.
    [$ev_vis_sql, $ev_vis_params] = ak_event_visibility_sql($pdo, $user, 'events');

    // Evenements a venir
    $events = 0;
    try {
        // Colonne réelle : starts_at (start_date n'existe pas → le KPI restait à 0)
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM events WHERE org_id = ? AND starts_at >= CURDATE() AND deleted_at IS NULL" . $ev_vis_sql);
        $stmt->execute(array_merge([$org_id], $ev_vis_params));
        $events = (int) $stmt->fetchColumn();
    } catch (Throwable $e) {}

    // --- Profil TPE vs Association ---------------------------------------
    // Heuristique : une TPE facture des clients et n'a pas de projets/dossiers.
    // (aucune colonne de type n'existe cote base -> detection par usage)
    $clients_count = 0;
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM asso_clients WHERE org_id = ? AND (deleted_at IS NULL OR deleted_at = '')");
        $stmt->execute([$org_id]);
        $clients_count = (int) $stmt->fetchColumn();
    } catch (Throwable $e) {}

    $projects_total = 0;
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM projects p JOIN folders f ON p.folder_id = f.id
            WHERE f.org_id = ?
        ");
        $stmt->execute([$org_id]);
        $projects_total = (int) $stmt->fetchColumn();
    } catch (Throwable $e) {}

    $profile = ($clients_count > 0 && $projects_total === 0) ? 'tpe' : 'asso';
    // Type choisi à l'inscription (association / TPE) : il prime sur la déduction
    try {
        $tp = $pdo->prepare("SELECT org_type FROM organizations WHERE id = ?");
        $tp->execute([$org_id]);
        $declared = (string) $tp->fetchColumn();
        if ($declared === 'tpe' || $declared === 'asso') $profile = $declared;
    } catch (Throwable $e) {}   // colonne absente tant que la migration n'est pas passée

    // KPIs facturation (utiles pour TPE, calcules pour les deux) : rôles facturation uniquement
    // impayes = factures échues non payées (carte « Impayés à recouvrer ») ; a_encaisser = toutes
    // les factures en attente ou en retard, échues ou non (totals.unpaid de app-invoices.php,
    // « En attente » du hub Facturation du site).
    $ca_paid_cents = 0; $impayes_cents = 0; $a_encaisser_cents = 0; $factures_count = 0; $devis_encours = 0;
    if ($can_bill) try {
        $stmt = $pdo->prepare("
            SELECT
              COALESCE(SUM(CASE WHEN status = 'paid' AND YEAR(issued_at) = YEAR(CURDATE()) THEN amount_ttc_cents ELSE 0 END), 0) AS paid,
              COALESCE(SUM(CASE WHEN status IN ('pending','overdue') AND due_at < NOW() THEN amount_ttc_cents ELSE 0 END), 0) AS due,
              COALESCE(SUM(CASE WHEN status IN ('pending','overdue') THEN amount_ttc_cents ELSE 0 END), 0) AS unpaid,
              COUNT(*) AS total
            FROM asso_invoices WHERE org_id = ?
        ");
        $stmt->execute([$org_id]);
        $fi = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $ca_paid_cents  = (int) ($fi['paid'] ?? 0);
        $impayes_cents  = (int) ($fi['due'] ?? 0);
        $a_encaisser_cents = (int) ($fi['unpaid'] ?? 0);
        $factures_count = (int) ($fi['total'] ?? 0);
    } catch (Throwable $e) {}
    if ($can_bill) try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM asso_quotes WHERE org_id = ? AND status = 'sent'");
        $stmt->execute([$org_id]);
        $devis_encours = (int) $stmt->fetchColumn();
    } catch (Throwable $e) {}

    $initials = strtoupper(function_exists('mb_substr') ? mb_substr($org_name, 0, 2) : substr($org_name, 0, 2));

    // Formule et essai : ak_org_plan_state() (trial-helpers.php), la même vérité que le cockpit
    // fondateur et la page « Mon abonnement ». trial = essai EN COURS ; trial_expired = essai terminé.
    $plan_info = ['in_trial' => false, 'trial_expired' => false, 'trial_ends_at' => null, 'days_left' => null, 'plan_label' => ''];
    try {
        ob_start();
        require_once __DIR__ . '/../plan-helpers.php';
        ob_end_clean(); // le JSON reste propre même si un helper inclus écrit quelque chose
        if ($org_id > 0) $plan_info = ak_plan_trial_info($pdo, $org_id);
    } catch (Throwable $e) {
        error_log('[api/app-dashboard] plan: ' . get_class($e));
    }
    // App iPhone : ni essai ni formule à l'écran (règle 3.1.1 d'Apple ; mobile/App.js,
    // IOS_SIGNUP_REQUEST). Même vide côté serveur, quelle que soit la version de l'app.
    if (function_exists('ak_is_ios_app') && ak_is_ios_app()) {
        $plan_info = ['in_trial' => false, 'trial_expired' => false, 'trial_ends_at' => null, 'days_left' => null, 'plan_label' => '—'];
    }

    // Accord pour les fonctions IA dans l'app (règle 5.1.2(i) d'Apple) : null = jamais demandé
    $ai_consent = null;
    try {
        require_once __DIR__ . '/../ai-consent-helper.php';
        $ai_consent = ak_ai_consent_get($pdo, (int) $uid);
    } catch (Throwable $e) {}

    /* ── Fil « Aujourd'hui » de l'accueil natif (maquette V2) ───────────────
       Trois sources, une couleur d'accent par type, comme sur l'écran de
       référence : événement (vert), facture échue (bleu), subvention (ambre).
       Chaque source est défensive : une table absente ne casse pas l'accueil.
       Factures et subventions : rôles facturation (admin, coordinateur). */
    $today = [];
    try {
        $stmt = $pdo->prepare("
            SELECT id, title, location, starts_at, is_all_day
            FROM events
            WHERE org_id = ? AND deleted_at IS NULL
              AND starts_at >= NOW() AND starts_at < DATE_ADD(CURDATE(), INTERVAL 1 DAY)" . $ev_vis_sql . "
            ORDER BY starts_at ASC LIMIT 4
        ");
        $stmt->execute(array_merge([$org_id], $ev_vis_params));
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $today[] = [
                'kind'  => 'event',
                'id'    => (int) $r['id'],
                'time'  => !empty($r['is_all_day']) ? 'Jour' : date('H:i', strtotime((string) $r['starts_at'])),
                'title' => (string) $r['title'],
                'sub'   => trim((string) ($r['location'] ?? '')),
                'color' => '#059669',
            ];
        }
    } catch (Throwable $e) {}
    if ($can_bill) try {
        $stmt = $pdo->prepare("
            SELECT i.id, i.invoice_number, i.amount_ttc_cents, i.due_at, c.display_name AS client_name
            FROM asso_invoices i
            LEFT JOIN asso_clients c ON i.client_id = c.id
            WHERE i.org_id = ? AND i.status IN ('pending','overdue') AND i.due_at <= CURDATE()
            ORDER BY i.due_at ASC LIMIT 3
        ");
        $stmt->execute([$org_id]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $due = strtotime((string) $r['due_at']);
            $today[] = [
                'kind'  => 'invoice',
                'id'    => (int) $r['id'],
                'time'  => '—',
                'title' => 'Facture ' . (string) $r['invoice_number'],
                'sub'   => ($due && $due >= strtotime('today') ? "Échéance aujourd'hui" : 'Échue le ' . date('d/m', (int) $due))
                           . ' · ' . number_format(((int) $r['amount_ttc_cents']) / 100, 0, ',', ' ') . ' €',
                'color' => '#2563EB',
            ];
        }
    } catch (Throwable $e) {}
    // Subventions : admin et coordinateur (Radar subventions, /subventions), pas les autres rôles.
    // Seul un dossier encore à déposer a une date limite de dépôt (même règle que gr_days_to_apply()
    // et /subventions) : un dossier déposé n'apparaît plus en « À déposer avant le … ».
    if ($can_grants) try {
        $stmt = $pdo->prepare("
            SELECT id, name, deadline_apply FROM grants
            WHERE org_id = ? AND archived_at IS NULL
              AND status = 'draft' AND submitted_at IS NULL
              AND deadline_apply IS NOT NULL AND deadline_apply >= CURDATE()
              AND deadline_apply <= DATE_ADD(CURDATE(), INTERVAL 21 DAY)
            ORDER BY deadline_apply ASC LIMIT 2
        ");
        $stmt->execute([$org_id]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $today[] = [
                'kind'  => 'grant',
                'id'    => (int) $r['id'],
                'time'  => '—',
                'title' => (string) $r['name'],
                'sub'   => 'À déposer avant le ' . date('d/m', strtotime((string) $r['deadline_apply'])),
                'color' => '#B45309',
            ];
        }
    } catch (Throwable $e) {}

    // Sous-titre de l'en-tête : « N échéances cette semaine · N factures à relancer »
    $week_due = 0;
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM events
            WHERE org_id = ? AND deleted_at IS NULL
              AND starts_at >= NOW() AND starts_at < DATE_ADD(CURDATE(), INTERVAL 7 DAY)" . $ev_vis_sql . "
        ");
        $stmt->execute(array_merge([$org_id], $ev_vis_params));
        $week_due = (int) $stmt->fetchColumn();
    } catch (Throwable $e) {}
    $late_invoices = 0;
    if ($can_bill) try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM asso_invoices WHERE org_id = ? AND status IN ('pending','overdue') AND due_at < CURDATE()");
        $stmt->execute([$org_id]);
        $late_invoices = (int) $stmt->fetchColumn();
    } catch (Throwable $e) {}
    $head_bits = [];
    if ($week_due > 0)      $head_bits[] = $week_due . ' échéance' . ($week_due > 1 ? 's' : '') . ' cette semaine';
    if ($late_invoices > 0) $head_bits[] = $late_invoices . ' facture' . ($late_invoices > 1 ? 's' : '') . ' à relancer';
    $head_line = $head_bits ? implode(' · ', $head_bits) : 'Tout est à jour, rien ne vous attend.';
    if ($mairie) $head_line = !empty($mairie['open'])
        ? 'Votre espace collectivité est disponible sur le site.'
        : 'L’espace de votre collectivité n’est pas accessible pour le moment.';

    // Compteurs de notifications non lues (endpoint dédié app — aucun impact site)
    $notif_unread = 0; $msg_unread = 0; $support_unread = 0;
    // Notifications internes (messages + mentions) depuis user_notifications
    try {
        $nst = $pdo->prepare("SELECT COUNT(*) FROM user_notifications WHERE user_id = ? AND is_read = 0 AND notification_type IN ('message','mention')");
        $nst->execute([(int) ($user['id'] ?? 0)]);
        $msg_unread = (int) $nst->fetchColumn();
    } catch (Throwable $e) {}
    // Toutes les notifs non lues (pour le badge global)
    try {
        $nst = $pdo->prepare("SELECT COUNT(*) FROM user_notifications WHERE user_id = ? AND is_read = 0");
        $nst->execute([(int) ($user['id'] ?? 0)]);
        $notif_unread = (int) $nst->fetchColumn();
    } catch (Throwable $e) {}
    // Fondateur / super admin : notifications de la plateforme (nouveau message support,
    // association à valider…), celles de la cloche du cockpit web (platform_notifications).
    // Comptées dans notif_unread comme dans la liste (api/app-notifications.php, « unread ») et après
    // lecture (api/app-notif-read.php) : sinon le badge du fondateur change de valeur d'un écran à l'autre.
    // Aussi renvoyées à part (platform_unread).
    // Même règle que la liste et la lecture : drapeaux relus par ak_platform_flags().
    $platform_unread = 0;
    require_once __DIR__ . '/../platform-flags.php';
    $pf_notif = ak_platform_flags($user ?: ['id' => $uid]) ?: [];
    if ($is_founder || !empty($pf_notif['is_super_admin'])) {
        try {
            $nst = $pdo->prepare("SELECT COUNT(*) FROM platform_notifications WHERE recipient_user_id = ? AND is_read = 0");
            $nst->execute([(int) ($user['id'] ?? 0)]);
            $platform_unread = (int) $nst->fetchColumn();
        } catch (Throwable $e) {}   // table absente : aucune notification plateforme
    }
    // Support : messages du support non lus par l'org (calcul direct, comme la sidebar web)
    try {
        $sst = $pdo->prepare("
            SELECT COUNT(DISTINCT t.id)
            FROM support_tickets t
            JOIN support_messages m ON m.ticket_id = t.id
            WHERE t.org_id = ? AND m.author_side = 'support' AND m.read_by_org = 0 AND m.is_internal_note = 0
        ");
        $sst->execute([$org_id]);
        $support_unread = (int) $sst->fetchColumn();
    } catch (Throwable $e) {}

    // Boîte mail : conversations non lues visibles (admins et coordinateurs)
    $mail_unread = 0; $mail_prio = 0; $mail_access = false; $mail_connected = false;
    try {
        require_once __DIR__ . '/../mail-helpers.php';
        $mail_user = $user + ['is_founder' => $is_founder ? 1 : 0];
        $mail_access = $has_org && !$mairie && mail_can_access($mail_user);   // pas de boîte d'association sans association (agent mairie, Fondateur non rattaché)
        if ($mail_access && mail_schema_ready($pdo)) {
            $mail_unread = mail_unread_count($pdo, $mail_user);
            $mail_connected = (bool)mail_get_account($pdo, (int)$user['org_id']);
            if ($mail_connected) {
                $cats = mail_visible_categories($pdo, (int)$user['org_id'], $mail_user);
                $ids = $cats ? implode(',', array_map(fn($c) => (int)$c['id'], $cats)) : '0';
                $st = $pdo->prepare("SELECT COUNT(*) FROM mail_threads WHERE org_id = ? AND is_archived = 0 AND last_direction = 'in'
                                     AND priority IN ('urgent','important') AND (category_id IN ($ids) OR category_id IS NULL)");
                $st->execute([(int)$user['org_id']]);
                $mail_prio = (int)$st->fetchColumn();
            }
        }
    } catch (Throwable $e) {}

    echo json_encode([
        'ok'           => true,
        'profile'      => $mairie ? 'mairie' : $profile,
        'role'         => $mairie ? 'mairie' : (string) ($user['role'] ?? 'member'),
        'mairie'       => $mairie,   // null hors agent mairie ; sinon nom, type, lien du site, message
        'can_create_projects' => $has_org && !$mairie && ((($user['role'] ?? '') === 'admin') || !empty($user['can_create_projects']) || $is_founder || !empty($user['is_super_admin'])),
        // false : compte sans association (agent mairie, Fondateur ou super-admin non rattaché)
        'has_org'      => $has_org,
        // « Scanner une facture » : au moins un projet où ce compte peut ajouter une dépense
        // (même règle qu'api/app-create-expense.php ; can_add_expense de chaque projet d'app-projects.php)
        'can_add_expense' => $can_add_expense,
        'is_founder'   => $is_founder,
        'is_super_admin' => ($is_founder || !empty($user['is_super_admin']) || ($user['role'] ?? '') === 'super_admin'),
        'notif_unread' => $notif_unread + $platform_unread,
        'platform_unread' => $platform_unread,
        'msg_unread'   => $msg_unread,
        'support_unread' => $support_unread,
        'mail_unread'  => $mail_unread,
        'mail_access'  => $mail_access,
        'mail_connected' => $mail_connected,
        'mail_prio'    => $mail_prio,
        'first_name'   => $first_name,
        'org_name'     => $org_name,
        'org_initials' => $initials,
        'org_logo'     => $org_logo,
        'head_line'    => $head_line,
        'trial'           => (bool) $plan_info['in_trial'],
        'trial_expired'   => (bool) $plan_info['trial_expired'],
        'trial_ends_at'   => $plan_info['trial_ends_at'],
        'trial_days_left' => $plan_info['days_left'] !== null ? (int) $plan_info['days_left'] : null,
        // Abonnement déjà souscrit pendant l'essai (premier prélèvement automatique à la fin de l'essai) :
        // information seulement, jamais de paiement dans l'appli
        'trial_subscribed' => !empty($plan_info['trial_subscribed']),
        'first_charge_at'  => !empty($plan_info['trial_subscribed']) ? ($plan_info['first_charge_at'] ?? null) : null,
        // Pendant l'essai : « Essai PRO » (même si un paiement Stripe pris pendant l'essai est en attente)
        'plan_label'      => (string) (!empty($plan_info['in_trial']) && !empty($plan_info['trial_name']) ? $plan_info['trial_name']
                                       : ($plan_info['plan_label'] === '—' ? '' : $plan_info['plan_label'])),
        'today'        => $today,
        'ai_consent'   => $ai_consent,
        // L'appli masque les cartes Budget / Facturation quand ces drapeaux sont faux
        'can_view_finances'  => $can_fin,
        'can_access_billing' => $can_bill,
        // null = non autorisé pour ce rôle (les clés restent présentes pour l'appli)
        'kpis'         => [
            'projets_actifs'   => $active_projects,
            'membres'          => $total_users,
            'membres_nouveaux' => $new_users,
            'evenements'       => $events,
            'budget_used'      => $can_fin ? (float) $b['used'] : null,
            'budget_planned'   => $can_fin ? (float) $b['planned'] : null,
            // TPE
            'clients'          => $can_bill ? $clients_count : null,
            'devis_encours'    => $can_bill ? $devis_encours : null,
            'factures'         => $can_bill ? $factures_count : null,
            'ca_paid'          => $can_bill ? $ca_paid_cents / 100 : null,
            'impayes'          => $can_bill ? $impayes_cents / 100 : null,
            'a_encaisser'      => $can_bill ? $a_encaisser_cents / 100 : null,
        ],
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[api/app-dashboard] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'server']);
}
