<?php
/**
 * api/app-delete-account.php — Suppression RGPD du compte depuis l'app (natif).
 * Reproduit rgpd-supprimer-compte.php (anonymisation + soft-delete + deconnexion). JSON.
 * L'anonymisation est commune aux deux : ak_account_self_anonymise() (app-context.php).
 * NE MODIFIE PAS le site.
 */
require __DIR__ . '/_app-write-boot.php';
require_once __DIR__ . '/../demo-guard.php';
if (ak_demo_protected_account($uid)) {
    // Comptes de validation Apple / Google : le parcours de suppression doit « fonctionner »
    // pour le relecteur, mais le compte reste en place pour la relecture suivante.
    $em = strtolower((string)($pdo->query("SELECT email FROM users WHERE id = " . (int)$uid)->fetchColumn() ?: ''));
    if (in_array($em, ['apple.review@assokit.fr', 'demo-review@assokit.fr'], true) && ($input['confirm'] ?? '') === 'SUPPRIMER') {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) session_destroy();
        echo json_encode(['ok' => true, 'message' => 'Votre compte a été supprimé.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    // Comptes partagés de la démo DEMO F : la suppression ne s'applique pas (sinon le premier
    // examinateur supprimerait la démo pour tous les suivants). Le message dit ce qu'elle fait
    // sur un vrai compte : un examinateur Google ou Apple le lit à la place d'une erreur sèche.
    app_fail(403, 'demo', 'Compte de démonstration partagé : sa suppression est désactivée pour que la démo reste utilisable (elle est remise à zéro chaque nuit). Sur un vrai compte, cette étape anonymise immédiatement les données personnelles et ferme la session.');
}


if (($input['confirm'] ?? '') !== 'SUPPRIMER') {
    app_fail(422, 'confirm', 'Confirmation manquante.');
}

// Incarnation (support Fondateur) : seule la personne elle-même peut supprimer son compte
if (!empty($_SESSION['impersonation_target_user_id'])) {
    app_fail(403, 'impersonation', 'Suppression impossible pendant une incarnation.');
}

try {
    // Mêmes garde-fous que le site (ak_account_delete_blocker, app-context.php) : Fondateur ou
    // administrateur de la plateforme, seul administrateur de l'association
    $st = $pdo->prepare("SELECT is_founder, is_platform_admin, password_hash FROM users WHERE id = ? LIMIT 1");
    $st->execute([$uid]);
    $u = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    if (!empty($u['is_founder']) || !empty($u['is_platform_admin'])) {
        app_fail(403, 'critical', 'Votre compte gère la structure : contactez le support pour transférer la propriété avant suppression.');
    }
    $blocker = ak_account_delete_blocker($pdo, $uid);
    if ($blocker !== '') app_fail(409, 'last_admin', $blocker);

    // Mot de passe : vérifié quand l'app l'envoie (« current_password », comme sur le site). Les
    // versions de l'app qui ne le demandent pas encore restent acceptées (suppression de compte
    // exigée dans l'app par Apple et Google).
    if (array_key_exists('current_password', $input)) {
        @require_once __DIR__ . '/../rate-limit-helper.php';
        if (function_exists('ak_rate_limit') && !ak_rate_limit('account_delete_pw', 8, 600, (string) $uid)) {
            app_fail(429, 'too_many_requests', 'Trop de tentatives. Merci de patienter quelques minutes.');
        }
        if (empty($u['password_hash']) || !password_verify((string) $input['current_password'], (string) $u['password_hash'])) {
            app_fail(422, 'invalid_password', 'Mot de passe incorrect : votre compte n’a pas été supprimé.');
        }
    }

    // Anonymisation immédiate, la même que sur le site (ak_account_self_anonymise, app-context.php) :
    // identité, coordonnées, mot de passe, double authentification, notes d'administrateur, contrat,
    // dates d'adhésion, jetons de notification, liens d'agenda et de mot de passe
    ak_account_self_anonymise($pdo, $uid);

    try {
        $pdo->prepare("INSERT INTO assokit_activity_log (event_type, event_action, ip, created_at) VALUES ('account','self_delete', ?, NOW())")
            ->execute([$_SERVER['REMOTE_ADDR'] ?? '']);
    } catch (Throwable $e) {}

    // Deconnexion complete
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    @session_destroy();

    echo json_encode(['ok' => true, 'message' => 'Votre compte a été supprimé.'], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[app-delete-account] ' . $e->getMessage());
    app_fail(500, 'server', 'Une erreur est survenue.');
}
