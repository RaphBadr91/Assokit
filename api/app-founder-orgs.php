<?php
/**
 * api/app-founder-orgs.php — Liste native des associations pour le cockpit Fondateur.
 * GET ?filter=all|pending|unpaid|trial  · réservé Fondateur/Super Admin.
 * Chaque association porte l'état de son plan tel que le donne ak_org_plan_state()
 * (plan_label, is_trial, trial_expired, trial_ends_at, days_left, badge, badge_tone) :
 * le même que le cockpit web et l'espace de l'association.
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
// Compte désactivé ou supprimé depuis l'ouverture de la session : session fermée, 401
if (!app_session_account_alive($pdo)) { http_response_code(401); echo json_encode(['ok' => false, 'error' => 'auth']); exit; }
require_once __DIR__ . '/_app-founder-plan.php';
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
    $where_trial = '';
    if ($filter === 'pending') $where .= " AND o.validation_status = 'pending_founder'";
    elseif ($filter === 'trial') {
        // Pré-filtre large (toute trace d'essai) ; le tri fin se fait plus bas avec ak_org_plan_state()
        $has_trial = isset(ak_db_columns($pdo, 'asso_plans')['is_trial']);
        $where_trial = " AND (o.status = 'trial' OR o.trial_ends_at IS NOT NULL OR o.plan = 'pro-essai' OR EXISTS (
                        SELECT 1 FROM asso_subscriptions s JOIN asso_plans p ON p.id = s.plan_id
                        WHERE s.org_id = o.id AND (s.status = 'trial'" . ($has_trial ? " OR p.is_trial = 1" : " OR p.slug = 'pro-essai'") . ")))";
    }

    $list_sql = function (string $extra) use ($where) {
        return "
        SELECT o.id, o.name, o.status, o.plan, o.created_at, o.trial_ends_at,
               COALESCE(o.validation_status,'validated') AS validation_status,
               (SELECT COUNT(*) FROM users WHERE org_id = o.id AND deleted_at IS NULL AND is_active = 1) AS nb_users
        FROM organizations o
        WHERE $where $extra
        ORDER BY o.created_at DESC
        LIMIT 200";
    };
    try { $rows = $pdo->query($list_sql($where_trial))->fetchAll(PDO::FETCH_ASSOC); }
    catch (Throwable $e) {
        if ($where_trial === '') throw $e;
        // Tables d'abonnement illisibles : on garde les traces d'essai portées par organizations
        $rows = $pdo->query($list_sql(" AND (o.status = 'trial' OR o.trial_ends_at IS NOT NULL OR o.plan = 'pro-essai')"))->fetchAll(PDO::FETCH_ASSOC);
    }

    $orgs = [];
    // État du plan de toute la liste en une requête (pas 2 requêtes par association)
    $plan_states = fd_org_plans($pdo, array_column($rows, 'id'));
    foreach ($rows as $o) {
        $oid = (int) $o['id'];
        $up = $unpaid[$oid] ?? ['nb' => 0, 'total' => 0.0];
        if ($filter === 'unpaid' && $up['nb'] === 0) continue;
        $ps = $plan_states[$oid] ?? fd_org_plan($pdo, $oid);
        if ($filter === 'trial' && !$ps['is_trial'] && !$ps['trial_expired']) continue;
        $name = (string) $o['name'];
        $ini = function_exists('mb_substr') ? mb_strtoupper(mb_substr($name, 0, 2)) : strtoupper(substr($name, 0, 2));
        $vs = (string) $o['validation_status'];
        $orgs[] = [
            'id'         => $oid,
            'name'       => $name,
            'initials'   => $ini !== '' ? $ini : '?',
            'status'     => (string) $o['status'],
            // « plan » : libellé lisible (les anciennes versions de l'app l'affichent tel quel)
            'plan'       => $ps['plan_label'],
            'nb_users'   => (int) $o['nb_users'],
            'created'    => !empty($o['created_at']) ? date('d/m/Y', strtotime($o['created_at'])) : '',
            'pending'    => ($vs === 'pending_founder'),
            'rejected'   => ($vs === 'rejected'),
            'unpaid_nb'  => (int) $up['nb'],
            'unpaid_total' => round((float) $up['total'], 2),
        ] + $ps;
    }

    // Essais : ceux qui se terminent le plus tôt d'abord, puis les essais terminés (les plus récents d'abord)
    if ($filter === 'trial') {
        usort($orgs, function ($a, $b) {
            if ($a['trial_expired'] !== $b['trial_expired']) return $a['trial_expired'] ? 1 : -1;
            $ta = (string) ($a['trial_ends_at'] ?? ''); $tb = (string) ($b['trial_ends_at'] ?? '');
            return $a['trial_expired'] ? strcmp($tb, $ta) : strcmp($ta === '' ? '9' : $ta, $tb === '' ? '9' : $tb);
        });
    }

    echo json_encode(['ok' => true, 'filter' => $filter, 'orgs' => $orgs], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[api/app-founder-orgs] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'server']);
}
