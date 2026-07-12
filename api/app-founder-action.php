<?php
/**
 * api/app-founder-action.php — Actions Fondateur sur une association (app mobile).
 * POST { org_id:int, action:'validate'|'reject'|'suspend'|'activate', csrf }
 * Réservé Fondateur/Super Admin. NE MODIFIE PAS le site : dédié à l'application.
 */
require __DIR__ . '/_app-write-boot.php';

require_once __DIR__ . '/_app-founder.php';
$is_founder = app_is_founder($pdo, $user);
$is_sa = $is_founder || !empty($user['is_super_admin']) || (($user['role'] ?? '') === 'super_admin');
if (!$is_sa) app_fail(403, 'forbidden');

$target = (int) ($input['org_id'] ?? 0);
$action = (string) ($input['action'] ?? '');
if ($target <= 0) app_fail(400, 'org_id');
if (!in_array($action, ['validate', 'reject', 'suspend', 'activate'], true)) app_fail(400, 'action');

try {
    // Vérifie que l'org existe
    $st = $pdo->prepare("SELECT id, name FROM organizations WHERE id = ? AND deleted_at IS NULL LIMIT 1");
    $st->execute([$target]);
    $org = $st->fetch(PDO::FETCH_ASSOC);
    if (!$org) app_fail(404, 'not_found');

    if ($action === 'validate') {
        try { $pdo->prepare("UPDATE organizations SET validation_status='validated', status='active' WHERE id=?")->execute([$target]); }
        catch (Throwable $e) { $pdo->prepare("UPDATE organizations SET status='active' WHERE id=?")->execute([$target]); }
    } elseif ($action === 'reject') {
        try { $pdo->prepare("UPDATE organizations SET validation_status='rejected' WHERE id=?")->execute([$target]); }
        catch (Throwable $e) {}
    } elseif ($action === 'suspend') {
        $pdo->prepare("UPDATE organizations SET status='suspended' WHERE id=?")->execute([$target]);
    } elseif ($action === 'activate') {
        $pdo->prepare("UPDATE organizations SET status='active' WHERE id=?")->execute([$target]);
    }

    // Journalise si une fonction dédiée existe (aucune erreur si absente)
    if (function_exists('sa_log_action')) {
        try { sa_log_action($uid, 'founder_' . $action, (int) $org['id'], (string) $org['name']); } catch (Throwable $e) {}
    }

    echo json_encode(['ok' => true, 'org_id' => $target, 'action' => $action], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[api/app-founder-action] ' . $e->getMessage());
    app_fail(500, 'server');
}
