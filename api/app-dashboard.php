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
