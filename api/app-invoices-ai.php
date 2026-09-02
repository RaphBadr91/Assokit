<?php
/**
 * api/app-invoices-ai.php — Analyse IA de la facturation pour l'app. JSON, scope org.
 * Reutilise ask_claude(). NE MODIFIE PAS le site.
 */
ob_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes-layout.php';
@require_once __DIR__ . '/../ai-helper.php';
ob_end_clean();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'auth']);
    exit;
}

function eur($cents) { return number_format($cents / 100, 0, ',', ' ') . ' €'; }

try {
    $user = function_exists('current_user') ? current_user() : null;
    $org_id = (int) ($user['org_id'] ?? ($_SESSION['org_id'] ?? 0));

    // Même gate admin que app-stats-ai.php (parité mon-asso-stats.php) + limite d'appels IA.
    $role = (string) ($user['role'] ?? '');
    if (!in_array($role, ['admin', 'founder', 'super_admin'], true) && empty($user['is_founder'])) {
        echo json_encode(['ok' => true, 'allowed' => false, 'message' => 'Réservé aux administrateurs.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    @require_once __DIR__ . '/../rate-limit-helper.php';
    if (function_exists('ak_rate_limit_or_die')) ak_rate_limit_or_die('app_ai', 10, 60, (string) ($_SESSION['user_id'] ?? ''));

    // Stats de facturation
    $stmt = $pdo->prepare("
        SELECT
          COUNT(*) AS nb,
          COALESCE(SUM(amount_ttc_cents),0) AS total,
          COALESCE(SUM(CASE WHEN status='paid' THEN amount_ttc_cents ELSE 0 END),0) AS paid,
          COALESCE(SUM(CASE WHEN status='pending' AND (due_at IS NULL OR due_at >= NOW()) THEN amount_ttc_cents ELSE 0 END),0) AS pending,
          COALESCE(SUM(CASE WHEN status='overdue' OR (status='pending' AND due_at < NOW()) THEN amount_ttc_cents ELSE 0 END),0) AS overdue,
          SUM(CASE WHEN status='paid' THEN 1 ELSE 0 END) AS nb_paid,
          SUM(CASE WHEN status='overdue' OR (status='pending' AND due_at < NOW()) THEN 1 ELSE 0 END) AS nb_overdue
        FROM asso_invoices WHERE org_id = ?
    ");
    $stmt->execute([$org_id]);
    $s = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $totals = [
        'total'   => (int) ($s['total'] ?? 0) / 100,
        'paid'    => (int) ($s['paid'] ?? 0) / 100,
        'pending' => (int) ($s['pending'] ?? 0) / 100,
        'overdue' => (int) ($s['overdue'] ?? 0) / 100,
        'nb'      => (int) ($s['nb'] ?? 0),
    ];

    if (!function_exists('ask_claude')) {
        echo json_encode(['ok' => true, 'analysis' => 'Analyse IA indisponible pour le moment.', 'totals' => $totals], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $prompt = "Voici les chiffres de facturation de la structure :\n"
        . "- Nombre de factures : " . (int) ($s['nb'] ?? 0) . "\n"
        . "- Chiffre d'affaires total facturé : " . eur((int) ($s['total'] ?? 0)) . "\n"
        . "- Encaissé (payé) : " . eur((int) ($s['paid'] ?? 0)) . " (" . (int) ($s['nb_paid'] ?? 0) . " factures)\n"
        . "- En attente : " . eur((int) ($s['pending'] ?? 0)) . "\n"
        . "- Impayés / en retard : " . eur((int) ($s['overdue'] ?? 0)) . " (" . (int) ($s['nb_overdue'] ?? 0) . " factures)\n\n"
        . "Fais une analyse courte et concrète (3 phrases maximum) de la situation de trésorerie, "
        . "puis donne 1 ou 2 conseils actionnables (relances, délais, etc.). "
        . "Réponds en français, en texte simple, sans markdown, ton bienveillant et professionnel.";

    $resp = ask_claude(
        "Tu es un assistant de gestion financière pour des associations et TPE. Tu es concis, concret et bienveillant.",
        [['role' => 'user', 'content' => $prompt]],
        500
    );
    $analysis = ($resp && !empty($resp['success'])) ? trim((string) ($resp['content'] ?? '')) : '';
    if ($analysis === '') $analysis = 'Analyse IA momentanément indisponible.';

    echo json_encode(['ok' => true, 'analysis' => $analysis, 'totals' => $totals], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[api/app-invoices-ai] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'server']);
}
