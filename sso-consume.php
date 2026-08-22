<?php
/**
 * sso-consume.php — Étape 2 du SSO WordPress.
 * Consomme un jeton à usage unique (?t=), ouvre la session Assokit de
 * l'utilisateur associé, puis redirige vers le tableau de bord.
 */
require_once __DIR__ . '/config.php';
if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }

$fail = function() { header('Location: /connexion?sso=err'); exit; };

$token = (string)($_GET['t'] ?? '');
if (!preg_match('/^[a-f0-9]{64}$/i', $token)) $fail();

$token_hash = hash('sha256', strtolower($token));
try {
    $pdo->beginTransaction();
    // Verrou + lecture du jeton non utilisé et non expiré.
    $st = $pdo->prepare("SELECT id, user_id FROM sso_tokens WHERE token_hash = ? AND used = 0 AND expires_at >= NOW() LIMIT 1 FOR UPDATE");
    $st->execute([$token_hash]);
    $tok = $st->fetch(PDO::FETCH_ASSOC);
    if (!$tok) { $pdo->rollBack(); $fail(); }
    // Usage unique.
    $pdo->prepare("UPDATE sso_tokens SET used = 1 WHERE id = ?")->execute([(int)$tok['id']]);
    $pdo->commit();

    // Charge l'utilisateur cible.
    $us = $pdo->prepare("SELECT id, org_id, email, first_name, last_name, role, parent_org_id, parent_org_role, is_super_admin
                         FROM users WHERE id = ? AND (is_active = 1 OR is_active IS NULL) AND deleted_at IS NULL LIMIT 1");
    $us->execute([(int)$tok['user_id']]);
    $u = $us->fetch(PDO::FETCH_ASSOC);
    if (!$u) $fail();

    // Ouvre la session (même contrat que connexion.php).
    session_regenerate_id(true);
    $_SESSION['user_id']        = (int)$u['id'];
    $_SESSION['org_id']         = (int)$u['org_id'];
    $_SESSION['user_email']     = $u['email'];
    $_SESSION['user_name']      = trim(($u['first_name'] ?? '').' '.($u['last_name'] ?? ''));
    $_SESSION['user_role']      = $u['role'];
    $_SESSION['parent_org_id']  = $u['parent_org_id'] ?? null;
    $_SESSION['parent_org_role']= $u['parent_org_role'] ?? null;
    $_SESSION['is_super_admin'] = (!empty($u['is_super_admin']) || $u['role'] === 'super_admin') ? 1 : 0;
    $_SESSION['logged_at']      = time();
    $_SESSION['sso_login']      = 1;
    $_SESSION['csrf_token']     = bin2hex(random_bytes(32));

    header('Location: /dashboard'); exit;
} catch (Throwable $e) {
    if ($pdo->inTransaction()) { try { $pdo->rollBack(); } catch (Throwable $e2) {} }
    error_log('[sso-consume] '.$e->getMessage());
    $fail();
}
