<?php
/**
 * api/app-clients.php — Liste des clients (profil TPE) pour l'ecran natif de l'app.
 * JSON, authentifie par session. NE MODIFIE PAS le site.
 */
ob_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes-layout.php';
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

    $stmt = $pdo->prepare("
        SELECT c.id, c.display_name, c.email, c.city, c.client_type,
               (SELECT COALESCE(SUM(amount_ttc_cents),0) FROM asso_invoices
                  WHERE client_id = c.id AND status = 'paid') AS total_paid_cents
        FROM asso_clients c
        WHERE c.org_id = ? AND (c.deleted_at IS NULL OR c.deleted_at = '')
        ORDER BY c.display_name ASC
        LIMIT 500
    ");
    $stmt->execute([$org_id]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $clients = [];
    foreach ($rows as $r) {
        $name = trim((string) ($r['display_name'] ?? ''));
        if ($name === '') $name = (string) ($r['email'] ?? 'Client');
        $ini = strtoupper(function_exists('mb_substr') ? mb_substr($name, 0, 2) : substr($name, 0, 2));
        $clients[] = [
            'id'         => (int) $r['id'],
            'name'       => $name,
            'initials'   => $ini !== '' ? $ini : '?',
            'email'      => (string) ($r['email'] ?? ''),
            'city'       => (string) ($r['city'] ?? ''),
            'type'       => (string) ($r['client_type'] ?? 'company'),
            'total_paid' => ((int) ($r['total_paid_cents'] ?? 0)) / 100,
        ];
    }

    echo json_encode(['ok' => true, 'clients' => $clients], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[api/app-clients] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'server']);
}
