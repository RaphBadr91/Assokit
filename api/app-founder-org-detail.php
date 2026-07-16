<?php
/**
 * api/app-founder-org-detail.php — Fiche détaillée d'une association (Fondateur, natif).
 * GET ?id=INT → infos éditables + membres + plan + note interne + plans disponibles.
 * Réservé Fondateur/Super Admin. NE MODIFIE PAS le site : dédié à l'application.
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
$is_sa = app_is_founder($pdo, $user) || !empty($user['is_super_admin']) || (($user['role'] ?? '') === 'super_admin');
if (!$is_sa) { http_response_code(403); echo json_encode(['ok' => false, 'error' => 'forbidden']); exit; }

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) { http_response_code(400); echo json_encode(['ok' => false, 'error' => 'id']); exit; }

// Colonnes optionnelles selon le schéma
function ak_col_exists(PDO $pdo, string $table, string $col): bool {
    try {
        $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
        $st->execute([$table, $col]);
        return (int) $st->fetchColumn() > 0;
    } catch (Throwable $e) { return false; }
}

try {
    $hasNote = ak_col_exists($pdo, 'organizations', 'notes_superadmin');
    $hasBill = ak_col_exists($pdo, 'organizations', 'billing_email');

    $cols = "id, name, status, plan, created_at, trial_ends_at, COALESCE(validation_status,'validated') AS validation_status";
    if ($hasBill) $cols .= ", billing_email";
    if ($hasNote) $cols .= ", notes_superadmin";

    $st = $pdo->prepare("SELECT $cols FROM organizations WHERE id = ? AND deleted_at IS NULL LIMIT 1");
    $st->execute([$id]);
    $o = $st->fetch(PDO::FETCH_ASSOC);
    if (!$o) { http_response_code(404); echo json_encode(['ok' => false, 'error' => 'not_found']); exit; }

    // Membres
    $members = [];
    try {
        $mst = $pdo->prepare("SELECT first_name, last_name, email, role FROM users WHERE org_id = ? AND deleted_at IS NULL ORDER BY (role='admin') DESC, created_at ASC LIMIT 50");
        $mst->execute([$id]);
        foreach ($mst->fetchAll(PDO::FETCH_ASSOC) as $m) {
            $members[] = [
                'name'  => trim(($m['first_name'] ?? '') . ' ' . ($m['last_name'] ?? '')) ?: ($m['email'] ?? '—'),
                'email' => (string) ($m['email'] ?? ''),
                'role'  => (string) ($m['role'] ?? 'member'),
            ];
        }
    } catch (Throwable $e) {}

    // Plans disponibles (pour changer de formule)
    $plans = [];
    try {
        foreach ($pdo->query("SELECT slug, name FROM asso_plans WHERE is_visible = 1 OR is_trial = 1 ORDER BY is_trial ASC, price_cents ASC")->fetchAll(PDO::FETCH_ASSOC) as $p) {
            $plans[] = ['slug' => (string) $p['slug'], 'name' => (string) $p['name']];
        }
    } catch (Throwable $e) {}

    echo json_encode([
        'ok' => true,
        'org' => [
            'id'            => (int) $o['id'],
            'name'          => (string) $o['name'],
            'billing_email' => $hasBill ? (string) ($o['billing_email'] ?? '') : '',
            'plan'          => (string) ($o['plan'] ?? ''),
            'status'        => (string) $o['status'],
            'validation_status' => (string) $o['validation_status'],
            'note'          => $hasNote ? (string) ($o['notes_superadmin'] ?? '') : '',
            'created'       => !empty($o['created_at']) ? date('d/m/Y', strtotime($o['created_at'])) : '',
            'trial_ends'    => !empty($o['trial_ends_at']) && $o['trial_ends_at'] !== '0000-00-00' ? date('d/m/Y', strtotime($o['trial_ends_at'])) : '',
            'nb_users'      => count($members),
        ],
        'members' => $members,
        'plans'   => $plans,
        'can_note' => $hasNote,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[api/app-founder-org-detail] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'server']);
}
