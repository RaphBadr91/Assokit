<?php
/**
 * api/app-founder.php — Données du cockpit Fondateur pour l'app mobile (natif).
 * Réponse JSON, réservée au Fondateur / Super Admin. NE MODIFIE PAS le site.
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
require_once __DIR__ . '/_app-contact-token.php';
$is_founder = app_is_founder($pdo, $user);
$is_sa = $is_founder || !empty($user['is_super_admin']) || (($user['role'] ?? '') === 'super_admin');
if (!$is_sa) { http_response_code(403); echo json_encode(['ok' => false, 'error' => 'forbidden']); exit; }

$q = function (string $sql) use ($pdo) { try { return $pdo->query($sql); } catch (Throwable $e) { return null; } };
$scalar = function (string $sql) use ($pdo) { try { return $pdo->query($sql)->fetchColumn(); } catch (Throwable $e) { return 0; } };

try {
    // Compteurs et MRR : mêmes règles qu'ak_org_plan_state() (un essai n'est jamais un revenu)
    $pc = fd_plan_counts($pdo);
    $nb_orgs_total = $pc['total'];
    $nb_orgs_active = $pc['active'];
    $nb_orgs_trial = $pc['trial'];
    $nb_users = (int) $scalar("SELECT COUNT(*) FROM users WHERE role NOT IN ('super_admin') AND is_active=1 AND deleted_at IS NULL");
    $nb_orgs_30 = (int) $scalar("SELECT COUNT(*) FROM organizations WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) AND deleted_at IS NULL");
    $nb_users_30 = (int) $scalar("SELECT COUNT(*) FROM users WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) AND is_active=1 AND deleted_at IS NULL");
    $ca_paid_30 = (float) $scalar("SELECT COALESCE(SUM(amount_ttc),0) FROM subscription_invoices WHERE status='paid' AND paid_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)");

    // MRR : abonnement courant (asso_subscriptions + asso_plans) actif, sur un plan payant hors essai,
    // d'une association ni en essai, ni en fin d'essai, ni suspendue, ni résiliée, ni supprimée.
    $mrr = (float) $pc['mrr'];

    $ia = ['nb' => 0, 'cost' => 0.0];
    try { $ia = $pdo->query("SELECT COUNT(*) AS nb, COALESCE(SUM(ai_cost_euros),0) AS cost FROM communication_campaigns WHERE ai_generated=1")->fetch(PDO::FETCH_ASSOC) ?: $ia; } catch (Throwable $e) {}

    // Alertes / signaux
    $nb_pending = (int) $scalar("SELECT COUNT(*) FROM organizations WHERE validation_status='pending_founder' AND deleted_at IS NULL");
    // Impayés : le signal ouvre le filtre « Impayés » des Associations, qui ignore les associations
    // supprimées — même périmètre ici (unpaid_nb = factures, unpaid_orgs = associations listées)
    $unpaid = fd_unpaid_summary($pdo);
    $nb_expiring = $pc['expiring'];   // essais en cours qui se terminent sous 7 jours

    // Demandes de contact non lues (nouvelles demandes + réponses entrantes du double-sens) :
    // même calcul que l'écran Contacts (ak_contact_unread_count)
    $contacts_unread = ak_contact_unread_count($pdo);

    // Support : tickets ayant des messages de l'association non lus — même compteur que le badge
    // « Support » du menu web (superadmin-layout.php) ; retombe à 0 à l'ouverture du fil
    $support_unread = (int) $scalar("SELECT COUNT(DISTINCT t.id) FROM support_tickets t JOIN support_messages m ON m.ticket_id = t.id WHERE m.author_side = 'org' AND m.read_by_support = 0 AND m.is_internal_note = 0");

    // Dernières associations
    $orgs = [];
    try {
        $st = $pdo->query("
            SELECT o.id, o.name, o.status, o.plan, o.created_at,
                   COALESCE(o.validation_status,'validated') AS validation_status,
                   (SELECT COUNT(*) FROM users WHERE org_id=o.id AND deleted_at IS NULL AND is_active=1) AS nb_users
            FROM organizations o WHERE o.deleted_at IS NULL ORDER BY o.created_at DESC LIMIT 6");
        $latest = $st->fetchAll(PDO::FETCH_ASSOC);
        $plan_states = fd_org_plans($pdo, array_column($latest, 'id'));
        foreach ($latest as $o) {
            $ps = $plan_states[(int) $o['id']] ?? fd_org_plan($pdo, (int) $o['id']);
            $orgs[] = [
                'id' => (int) $o['id'],
                'name' => (string) $o['name'],
                'status' => (string) $o['status'],
                'plan' => $ps['plan_label'],
                'validated' => ($o['validation_status'] !== 'pending_founder' && $o['validation_status'] !== 'rejected'),
                'pending' => ($o['validation_status'] === 'pending_founder'),
                'nb_users' => (int) $o['nb_users'],
                'created' => date('d/m/Y', strtotime($o['created_at'])),
            ] + $ps;
        }
    } catch (Throwable $e) {}

    echo json_encode([
        'ok' => true,
        'first_name' => trim((string) ($user['first_name'] ?? '')),
        'kpis' => [
            'mrr' => round($mrr, 2),
            'orgs_total' => $nb_orgs_total,
            'orgs_active' => $nb_orgs_active,
            'orgs_trial' => $nb_orgs_trial,
            'orgs_paying' => $pc['paying'],
            'orgs_free' => $pc['free'],
            'orgs_pending_payment' => $pc['pending_payment'],
            'orgs_trial_expired' => $pc['trial_expired'],
            'orgs_suspended' => $pc['suspended'],
            'orgs_cancelled' => $pc['cancelled'],
            'users' => $nb_users,
            'ia_nb' => (int) $ia['nb'],
            'ia_cost' => round((float) $ia['cost'], 2),
        ],
        'month' => [
            'new_orgs' => $nb_orgs_30,
            'new_users' => $nb_users_30,
            'ca_paid' => round($ca_paid_30, 2),
        ],
        'signals' => [
            'pending' => $nb_pending,
            'unpaid_nb' => (int) $unpaid['nb'],
            'unpaid_orgs' => (int) $unpaid['orgs'],
            'unpaid_total' => round((float) $unpaid['total'], 2),
            'expiring' => $nb_expiring,
            'trial_expired' => $pc['trial_expired'],
            'contacts_unread' => $contacts_unread,
            'support_unread' => $support_unread,
        ],
        'orgs' => $orgs,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[api/app-founder] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'server']);
}
