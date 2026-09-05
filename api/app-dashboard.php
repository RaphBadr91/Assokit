<?php
/**
 * api/app-dashboard.php — KPIs pour l'ecran d'accueil NATIF de l'app mobile.
 * Reponse JSON, authentifiee par la session (cookie partage avec la WebView).
 * NE MODIFIE PAS le site : fichier dedie a l'application.
 */
ob_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes-layout.php';
ob_end_clean(); // jette toute sortie parasite eventuelle

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'auth']);
    exit;
}

try {
    $user = function_exists('current_user') ? current_user() : null;
    $org_id = (int) ($user['org_id'] ?? ($_SESSION['org_id'] ?? 0));
    $first_name = trim((string) ($user['first_name'] ?? ''));

    // Fondateur : détection robuste (relit la base) — app-only, aucun impact site.
    require_once __DIR__ . '/_app-founder.php';
    $is_founder = app_is_founder($pdo, $user);

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
          AND p.archived_at IS NULL AND f.archived_at IS NULL
    ");
    $stmt->execute([$org_id]);
    $active_projects = (int) $stmt->fetchColumn();

    // Membres actifs
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

    // Budget engage (projets actifs)
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(p.budget_used),0) AS used, COALESCE(SUM(p.budget_planned),0) AS planned
        FROM projects p JOIN folders f ON p.folder_id = f.id
        WHERE f.org_id = ? AND p.status IN ('active','warning')
          AND p.archived_at IS NULL AND f.archived_at IS NULL
    ");
    $stmt->execute([$org_id]);
    $b = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['used' => 0, 'planned' => 0];

    // Evenements a venir
    $events = 0;
    try {
        // Colonne réelle : starts_at (start_date n'existe pas → le KPI restait à 0)
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM events WHERE org_id = ? AND starts_at >= CURDATE() AND deleted_at IS NULL");
        $stmt->execute([$org_id]);
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

    // KPIs facturation (utiles pour TPE, calcules pour les deux)
    $ca_paid_cents = 0; $impayes_cents = 0; $factures_count = 0; $devis_encours = 0;
    try {
        $stmt = $pdo->prepare("
            SELECT
              COALESCE(SUM(CASE WHEN status = 'paid' AND YEAR(issued_at) = YEAR(CURDATE()) THEN amount_ttc_cents ELSE 0 END), 0) AS paid,
              COALESCE(SUM(CASE WHEN status IN ('pending','overdue') AND due_at < NOW() THEN amount_ttc_cents ELSE 0 END), 0) AS due,
              COUNT(*) AS total
            FROM asso_invoices WHERE org_id = ?
        ");
        $stmt->execute([$org_id]);
        $fi = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $ca_paid_cents  = (int) ($fi['paid'] ?? 0);
        $impayes_cents  = (int) ($fi['due'] ?? 0);
        $factures_count = (int) ($fi['total'] ?? 0);
    } catch (Throwable $e) {}
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM asso_quotes WHERE org_id = ? AND status = 'sent'");
        $stmt->execute([$org_id]);
        $devis_encours = (int) $stmt->fetchColumn();
    } catch (Throwable $e) {}

    $initials = strtoupper(function_exists('mb_substr') ? mb_substr($org_name, 0, 2) : substr($org_name, 0, 2));

    /* ── Fil « Aujourd'hui » de l'accueil natif (maquette V2) ───────────────
       Trois sources, une couleur d'accent par type, comme sur l'écran de
       référence : événement (vert), facture échue (bleu), subvention (ambre).
       Chaque source est défensive : une table absente ne casse pas l'accueil. */
    $today = [];
    try {
        $stmt = $pdo->prepare("
            SELECT id, title, location, starts_at, is_all_day
            FROM events
            WHERE org_id = ? AND deleted_at IS NULL
              AND starts_at >= NOW() AND starts_at < DATE_ADD(CURDATE(), INTERVAL 1 DAY)
            ORDER BY starts_at ASC LIMIT 4
        ");
        $stmt->execute([$org_id]);
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
    try {
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
    try {
        $stmt = $pdo->prepare("
            SELECT id, name, deadline_apply FROM grants
            WHERE org_id = ? AND archived_at IS NULL
              AND status IN ('draft','submitted')
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
              AND starts_at >= NOW() AND starts_at < DATE_ADD(CURDATE(), INTERVAL 7 DAY)
        ");
        $stmt->execute([$org_id]);
        $week_due = (int) $stmt->fetchColumn();
    } catch (Throwable $e) {}
    $late_invoices = 0;
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM asso_invoices WHERE org_id = ? AND status IN ('pending','overdue') AND due_at < CURDATE()");
        $stmt->execute([$org_id]);
        $late_invoices = (int) $stmt->fetchColumn();
    } catch (Throwable $e) {}
    $head_bits = [];
    if ($week_due > 0)      $head_bits[] = $week_due . ' échéance' . ($week_due > 1 ? 's' : '') . ' cette semaine';
    if ($late_invoices > 0) $head_bits[] = $late_invoices . ' facture' . ($late_invoices > 1 ? 's' : '') . ' à relancer';
    $head_line = $head_bits ? implode(' · ', $head_bits) : 'Tout est à jour, rien ne vous attend.';

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

    echo json_encode([
        'ok'           => true,
        'profile'      => $profile,
        'role'         => (string) ($user['role'] ?? 'member'),
        'can_create_projects' => (($user['role'] ?? '') === 'admin') || !empty($user['can_create_projects']) || $is_founder || !empty($user['is_super_admin']),
        'is_founder'   => $is_founder,
        'is_super_admin' => ($is_founder || !empty($user['is_super_admin']) || ($user['role'] ?? '') === 'super_admin'),
        'notif_unread' => $notif_unread,
        'msg_unread'   => $msg_unread,
        'support_unread' => $support_unread,
        'first_name'   => $first_name,
        'org_name'     => $org_name,
        'org_initials' => $initials,
        'org_logo'     => $org_logo,
        'head_line'    => $head_line,
        'today'        => $today,
        'kpis'         => [
            'projets_actifs'   => $active_projects,
            'membres'          => $total_users,
            'membres_nouveaux' => $new_users,
            'evenements'       => $events,
            'budget_used'      => (float) $b['used'],
            'budget_planned'   => (float) $b['planned'],
            // TPE
            'clients'          => $clients_count,
            'devis_encours'    => $devis_encours,
            'factures'         => $factures_count,
            'ca_paid'          => $ca_paid_cents / 100,
            'impayes'          => $impayes_cents / 100,
        ],
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[api/app-dashboard] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'server']);
}
