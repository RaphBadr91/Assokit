<?php
/**
 * ASSOKIT — Suppression de compte RGPD (art. 17 droit à l'effacement)
 * POST /rgpd-supprimer-compte — anonymise + soft-delete le compte du user connecté
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes-layout.php';
require_login();

$current = current_user();
$uid = (int)$current['id'];

function redir($msg, $type='error'){
    header('Location: /parametres?tab=compte&flash=' . urlencode($msg) . '&ft=' . $type);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST')        redir('Requête invalide.');
if (!check_csrf($_POST['csrf_token'] ?? ''))      redir('Session expirée, merci de réessayer.');
if (($_POST['confirm'] ?? '') !== 'SUPPRIMER')    redir('Confirmation manquante.');

// Recharger le user pour vérifier les statuts critiques
$st = $pdo->prepare("SELECT is_founder, is_platform_admin FROM users WHERE id = ? LIMIT 1");
$st->execute([$uid]);
$u = $st->fetch(PDO::FETCH_ASSOC) ?: [];

// Garde-fou : comptes critiques non auto-supprimables
if (!empty($u['is_founder']) || !empty($u['is_platform_admin'])) {
    redir("Votre compte gère la structure : contactez le support pour transférer la propriété avant suppression.");
}

// Anonymisation + soft-delete (préserve l'intégrité référentielle)
try {
    $st = $pdo->prepare("
        UPDATE users SET
            email              = CONCAT('deleted_', id, '@anonyme.assokit.local'),
            first_name         = 'Compte',
            last_name          = 'supprimé',
            phone              = NULL,
            city               = NULL,
            is_active          = 0,
            deleted_at         = NOW(),
            deleted_by_user_id = id
        WHERE id = ? AND deleted_at IS NULL
    ");
    $st->execute([$uid]);

    // Journaliser (sans donnée personnelle)
    try {
        $pdo->prepare("INSERT INTO assokit_activity_log (event_type, event_action, ip, created_at) VALUES ('account','self_delete', ?, NOW())")
            ->execute([$_SERVER['REMOTE_ADDR'] ?? '']);
    } catch (Throwable $e) {}
} catch (Throwable $e) {
    error_log('[rgpd-delete] ' . $e->getMessage());
    redir("Une erreur est survenue. Merci de réessayer ou de contacter le support.");
}

// Déconnexion complète
$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time()-42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
}
session_destroy();
header('Location: /connexion.php?deleted=1');
exit;
