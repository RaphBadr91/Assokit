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
if (!in_array($action, ['validate', 'reject', 'suspend', 'activate', 'resiliate', 'edit'], true)) app_fail(400, 'action');

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
        $pdo->prepare("UPDATE organizations SET status='active', suspended_reason=NULL WHERE id=?")->execute([$target]);
    } elseif ($action === 'resiliate') {
        $pdo->prepare("UPDATE organizations SET status='cancelled' WHERE id=?")->execute([$target]);
    } elseif ($action === 'edit') {
        // Édition : nom, email de facturation, plan (slug), note interne — comme le web
        $name  = trim((string) ($input['name'] ?? ''));
        $mail  = trim((string) ($input['billing_email'] ?? ''));
        $plan  = trim((string) ($input['plan'] ?? ''));
        $note  = (string) ($input['note'] ?? '');
        if ($name === '') app_fail(400, 'name', 'Nom obligatoire.');
        if ($mail !== '' && !filter_var($mail, FILTER_VALIDATE_EMAIL)) app_fail(400, 'billing_email', 'Email invalide.');
        // Construit dynamiquement selon les colonnes présentes
        $sets = ['name = ?']; $params = [$name];
        $colExists = function ($col) use ($pdo) {
            try { $s = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='organizations' AND COLUMN_NAME=?"); $s->execute([$col]); return (int) $s->fetchColumn() > 0; }
            catch (Throwable $e) { return false; }
        };
        if ($plan !== '') { $sets[] = 'plan = ?'; $params[] = $plan; }
        if ($mail !== '' && $colExists('billing_email')) { $sets[] = 'billing_email = ?'; $params[] = $mail; }
        if ($colExists('notes_superadmin')) { $sets[] = 'notes_superadmin = ?'; $params[] = $note; }
        $params[] = $target;
        $pdo->prepare("UPDATE organizations SET " . implode(', ', $sets) . " WHERE id = ?")->execute($params);
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
