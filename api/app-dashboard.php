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

    // Organisation
    $stmt = $pdo->prepare("SELECT name FROM organizations WHERE id = ? LIMIT 1");
    $stmt->execute([$org_id]);
    $org_name = (string) ($stmt->fetchColumn() ?: '');

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
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM events WHERE org_id = ? AND start_date >= CURDATE()");
        $stmt->execute([$org_id]);
        $events = (int) $stmt->fetchColumn();
    } catch (Throwable $e) {}

    $initials = strtoupper(function_exists('mb_substr') ? mb_substr($org_name, 0, 2) : substr($org_name, 0, 2));

    echo json_encode([
        'ok'           => true,
        'first_name'   => $first_name,
        'org_name'     => $org_name,
        'org_initials' => $initials,
        'kpis'         => [
            'projets_actifs'   => $active_projects,
            'membres'          => $total_users,
            'membres_nouveaux' => $new_users,
            'evenements'       => $events,
            'budget_used'      => (float) $b['used'],
            'budget_planned'   => (float) $b['planned'],
        ],
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[api/app-dashboard] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'server']);
}
