<?php
/**
 * api/app-project-bilan.php — Bilan analytique d'un projet pour la fiche native.
 * Reutilise ak_invoice_dossier() (pca-mapping.php). Lecture seule, scope org.
 * Gate : fonctionnalite Pro "advanced_stats" (sans consommer l'export gratuit).
 * NE MODIFIE PAS le site.
 */
ob_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes-layout.php';
@require_once __DIR__ . '/../pca-mapping.php';
@require_once __DIR__ . '/../plan-helpers.php';
ob_end_clean();

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
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) { http_response_code(400); echo json_encode(['ok' => false, 'error' => 'id']); exit; }

    // Scope : le projet appartient a l'org du user
    $st = $pdo->prepare("SELECT f.org_id FROM projects p JOIN folders f ON p.folder_id = f.id WHERE p.id = ? LIMIT 1");
    $st->execute([$id]);
    $proj_org = (int) ($st->fetchColumn() ?: 0);
    if ($proj_org !== $org_id) { http_response_code(404); echo json_encode(['ok' => false, 'error' => 'not_found']); exit; }

    // Gate Pro sans effet de bord (ne consomme pas l'export gratuit)
    $allowed = true;
    if (function_exists('ak_can_use_feature')) {
        $allowed = (bool) ak_can_use_feature($pdo, $org_id, 'advanced_stats');
    }

    if (!$allowed) {
        echo json_encode([
            'ok'      => true,
            'allowed' => false,
            'upsell'  => 'Le bilan analytique (comptabilité par poste) est inclus dans le plan Pro.',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (!function_exists('ak_invoice_dossier')) {
        echo json_encode(['ok' => true, 'allowed' => true, 'postes' => [], 'total' => 0, 'count' => 0], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $dossier = ak_invoice_dossier($pdo, $id, true); // valides uniquement
    $postes = [];
    foreach (($dossier['postes'] ?? []) as $code => $p) {
        $postes[] = [
            'code'  => (string) ($p['code'] ?? $code),
            'label' => (string) ($p['label'] ?? ''),
            'total' => (float) ($p['total'] ?? 0),
            'count' => is_array($p['lines'] ?? null) ? count($p['lines']) : 0,
        ];
    }

    echo json_encode([
        'ok'      => true,
        'allowed' => true,
        'postes'  => $postes,
        'total'   => (float) ($dossier['total'] ?? 0),
        'count'   => (int) ($dossier['count'] ?? 0),
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[api/app-project-bilan] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'server']);
}
