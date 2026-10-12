<?php
/**
 * api/app-founder-activity.php — Journal d'activité de la plateforme (Fondateur, lecture seule).
 * GET → dernières actions (platform_activity_log) enrichies (org + libellé). NE MODIFIE PAS le site.
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
require_once __DIR__ . '/../trial-helpers.php';
$is_sa = app_is_founder($pdo, $user) || !empty($user['is_super_admin']) || (($user['role'] ?? '') === 'super_admin');
if (!$is_sa) { http_response_code(403); echo json_encode(['ok' => false, 'error' => 'forbidden']); exit; }

function act_info(string $a): array {
    $map = [
        'founder_validate' => ['✅', 'Association validée', '#059669'],
        'founder_activate' => ['▶', 'Réactivation', '#059669'],
        'founder_suspend'  => ['⏸', 'Suspension', '#B45309'],
        'founder_reject'   => ['✕', 'Refus', '#DC2626'],
        'founder_resiliate'=> ['✕', 'Résiliation', '#DC2626'],
        'founder_edit'     => ['✏️', 'Édition', '#2563EB'],
        'founder_extend_trial' => ['⏳', 'Essai prolongé', '#6D28D9'],
        'validate_org'     => ['✅', 'Association validée', '#059669'],
        'reject_org'       => ['✕', 'Refus', '#DC2626'],
        'update_org'       => ['✏️', 'Édition', '#2563EB'],
        'suspend_org'      => ['⏸', 'Suspension', '#B45309'],
        'reactivate_org'   => ['▶', 'Réactivation', '#059669'],
        'cancel_org'       => ['✕', 'Résiliation', '#DC2626'],
        'set_plan'         => ['✏️', 'Formule changée', '#2563EB'],
        'trial_extend'     => ['⏳', 'Essai prolongé', '#6D28D9'],
        'delete_org'       => ['🗑', 'Suppression', '#DC2626'],
        'create_invoice'   => ['🧾', 'Facture créée', '#2563EB'],
        'cancel_invoice'   => ['✕', 'Facture annulée', '#DC2626'],
        'mark_invoice_paid'=> ['💰', 'Facture payée', '#059669'],
        'create_org'       => ['🏢', 'Organisation créée', '#059669'],
        'org_created'      => ['🏢', 'Organisation créée', '#059669'],
        'user_login'       => ['🔑', 'Connexion', '#64748B'],
        'invoice_paid'     => ['💰', 'Facture payée', '#059669'],
    ];
    return $map[$a] ?? ['•', $a, '#94A3B8'];
}

try {
    $rows = [];
    try {
        // sa_log_action() écrit target_org_id / super_admin_id / details_json (anciens noms : org_id / user_id)
        $lc = ak_db_columns($pdo, 'platform_activity_log');
        $org_col   = isset($lc['target_org_id']) ? 'target_org_id' : 'org_id';
        $actor_col = isset($lc['super_admin_id']) ? 'super_admin_id' : 'user_id';
        $st = $pdo->query("
            SELECT l.*, o.name AS org_name,
                   CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,'')) AS actor
            FROM platform_activity_log l
            LEFT JOIN organizations o ON l.$org_col = o.id
            LEFT JOIN users u ON l.$actor_col = u.id
            ORDER BY l.created_at DESC LIMIT 120");
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $l) {
            [$icon, $label, $color] = act_info((string) ($l['action'] ?? ''));
            $when = !empty($l['created_at']) ? date('d/m/Y H:i', strtotime($l['created_at'])) : '';
            $detail = (string) ($l['detail'] ?? ($l['description'] ?? ''));
            $dj = !empty($l['details_json']) ? json_decode((string) $l['details_json'], true) : null;
            if ($detail === '' && is_array($dj)) {
                // Formats : appli { plan: [de, vers] } ; web update_org { plan: {from, to} } ;
                // web set_plan { from, to } ; prolongation appli { days, until } / web { days, from, to }
                $pl = isset($dj['plan']) && is_array($dj['plan']) ? $dj['plan'] : null;
                $from = $pl ? ($pl['from'] ?? ($pl[0] ?? '')) : '';
                $to   = $pl ? ($pl['to'] ?? ($pl[1] ?? '')) : '';
                $until = (string) ($dj['until'] ?? '');
                if (!$pl && !empty($dj['days']) && !empty($dj['to'])) $until = (string) $dj['to'];
                elseif (!$pl && !empty($dj['to']) && is_string($dj['to'])) { $from = (string) ($dj['from'] ?? ''); $to = $dj['to']; }
                if ($to !== '' && is_string($to)) $detail = 'Formule : ' . ak_plan_label(is_string($from) ? $from : '') . ' → ' . ak_plan_label($to);
                elseif ($until !== '') $detail = '+' . (int) ($dj['days'] ?? 0) . ' j · essai jusqu’au ' . ak_trial_end_label($until);
                elseif (!empty($dj['trial_ended'])) $detail = 'Essai terminé (formule Démarrage)';
                elseif (!empty($dj['reason']) && is_string($dj['reason'])) $detail = $dj['reason'];
            }
            $rows[] = [
                'icon'  => $icon,
                'label' => $label,
                'color' => $color,
                'org'   => (string) ($l['org_name'] ?? ($l['org_name_snapshot'] ?? '')),
                'actor' => trim((string) ($l['actor'] ?? '')),
                'detail'=> $detail,
                'when'  => $when,
            ];
        }
    } catch (Throwable $e) {}

    echo json_encode(['ok' => true, 'activity' => $rows, 'count' => count($rows)], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[app-founder-activity] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'server']);
}
