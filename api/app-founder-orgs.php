<?php
/**
 * api/app-founder-orgs.php — Liste native des associations pour le cockpit Fondateur.
 * GET ?filter=all|pending|unpaid|trial  · réservé Fondateur/Super Admin.
 * Réponse JSON. NE MODIFIE PAS le site : dédié à l'application mobile.
 */
ob_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes-layout.php';
ob_end_clean();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (empty($_SESSION['user_id'])) { http_response_code(401); echo json_encode(['ok' => false, 'error' => 'auth']); exit; }

$user = function_exists('current_user') ? current_user() : null;
require_once __DIR__ . '/_app-founder.php';
$is_founder = app_is_founder($pdo, $user);
$is_sa = $is_founder || !empty($user['is_super_admin']) || (($user['role'] ?? '') === 'super_admin');
if (!$is_sa) { http_response_code(403); echo json_encode(['ok' => false, 'error' => 'forbidden']); exit; }

$filter = (string) ($_GET['filter'] ?? 'all');

try {
    // Impayés par org (défensif : la table peut varier)
    $unpaid = [];
    try {
        $ust = $pdo->query("
            SELECT org_id, COUNT(*) AS nb, COALESCE(SUM(amount_ttc),0) AS total
            FROM subscription_invoices WHERE status IN ('sent','overdue') GROUP BY org_id");
        foreach ($ust->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $unpaid[(int) $r['org_id']] = ['nb' => (int) $r['nb'], 'total' => (float) $r['total']];
        }
    } catch (Throwable $e) {}

    $where = "o.deleted_at IS NULL";
    if ($filter === 'pending') $where .= " AND o.validation_status = 'pending_founder'";
    elseif ($filter === 'trial') $where .= " AND o.status = 'trial'";

    $st = $pdo->query("
        SELECT o.id, o.name, o.status, o.plan, o.created_at, o.trial_ends_at,
               COALESCE(o.validation_status,'validated') AS validation_status,
               (SELECT COUNT(*) FROM users WHERE org_id = o.id AND deleted_at IS NULL AND is_active = 1) AS nb_users
        FROM organizations o
        WHERE $where
        ORDER BY o.created_at DESC
        LIMIT 200");

    $orgs = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $o) {
        $oid = (int) $o['id'];
        $up = $unpaid[$oid] ?? ['nb' => 0, 'total' => 0.0];
        if ($filter === 'unpaid' && $up['nb'] === 0) continue;
        $name = (string) $o['name'];
        $ini = strtoupper(function_exists('mb_substr') ? mb_substr($name, 0, 2) : substr($name, 0, 2));
        $vs = (string) $o['validation_status'];
        $orgs[] = [
            'id'         => $oid,
            'name'       => $name,
            'initials'   => $ini !== '' ? $ini : '?',
            'status'     => (string) $o['status'],
            'plan'       => ucfirst((string) $o['plan']),
            'nb_users'   => (int) $o['nb_users'],
            'created'    => !empty($o['created_at']) ? date('d/m/Y', strtotime($o['created_at'])) : '',
            'trial_ends' => !empty($o['trial_ends_at']) && $o['trial_ends_at'] !== '0000-00-00' ? date('d/m/Y', strtotime($o['trial_ends_at'])) : '',
            'pending'    => ($vs === 'pending_founder'),
            'rejected'   => ($vs === 'rejected'),
            'unpaid_nb'  => (int) $up['nb'],
            'unpaid_total' => round((float) $up['total'], 2),
        ];
    }

    echo json_encode(['ok' => true, 'filter' => $filter, 'orgs' => $orgs], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[api/app-founder-orgs] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'server']);
}
