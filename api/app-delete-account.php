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
    // Aucune suppression simulée : un « Votre compte a été supprimé » sans rien supprimer serait une
    // tromperie de l'examinateur (règles 2.3.1 et 5.6 d'Apple), qui verrait en se reconnectant que le
    // compte existe toujours. Les anciens comptes de validation (apple.review@, demo-review@)
    // reçoivent donc le même refus explicite que les comptes partagés de DEMO F.
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
    // Seul compte de son association (cas d'un compte créé dans l'app) : la suppression ferme aussi
    // l'espace de l'association, au lieu d'exiger un e-mail au support (Apple 5.1.1 v).
    $close_org = ($blocker !== '' && function_exists('ak_account_sole_org')) ? ak_account_sole_org($pdo, $uid) : 0;
    if ($blocker !== '' && $close_org === 0) app_fail(409, 'last_admin', $blocker);

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

    if ($close_org > 0) {
        // Fermeture de l'espace, comme une résiliation du Fondateur (api/app-founder-action.php) :
        // abonnement Stripe arrêté D'ABORD (un échec n'efface rien : pas de carte prélevée pour un
        // espace fermé), fin d'essai (plan Démarrage), association « cancelled ». Ses données suivent
        // la règle de fermeture (CGU § 8, /suppression-compte § 6) : 30 jours, puis suppression.
        require_once __DIR__ . '/../trial-helpers.php';
        if (is_file(__DIR__ . '/../sa-plan-state-helpers.php')) require_once __DIR__ . '/../sa-plan-state-helpers.php';
        $stripe = ['cancelled' => [], 'failed' => []];
        if (function_exists('sa_stripe_cancel_for_org')) {
            try { $stripe = sa_stripe_cancel_for_org($pdo, $close_org); }
            catch (Throwable $e) {
                error_log('[app-delete-account] Stripe org #' . $close_org . ' : ' . get_class($e) . ' ' . $e->getMessage());
                $stripe['failed']['?'] = 'erreur technique';
            }
        }
        if ($stripe['failed']) {
            error_log('[app-delete-account] org #' . $close_org . ' : abonnement Stripe non annulé, suppression refusée');
            app_fail(502, 'billing', 'L’abonnement de l’association n’a pas pu être arrêté : rien n’a été supprimé. Réessayez dans quelques minutes.');
        }
        try {
            $pdo->beginTransaction();
            $st = function_exists('ak_org_plan_state') ? ak_org_plan_state($pdo, $close_org) : [];
            if (!empty($st['is_trial']) || !empty($st['trial_expired'])) {
                $free_id = (int) $pdo->query("SELECT id FROM asso_plans WHERE slug = 'demarrage' LIMIT 1")->fetchColumn();
                if ($free_id > 0 && function_exists('ak_org_set_plan')) ak_org_set_plan($pdo, $close_org, $free_id);
            }
            $pdo->prepare("UPDATE organizations SET status = 'cancelled' WHERE id = ?")->execute([$close_org]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;   // compte intact : l'utilisateur peut réessayer (Stripe déjà arrêté n'est pas relancé)
        }
        if (function_exists('ak_org_sync_plan_label')) { try { ak_org_sync_plan_label($pdo, $close_org); } catch (Throwable $e) {} }
        try {
            $pdo->prepare("INSERT INTO assokit_activity_log (organization_id, event_type, event_action, ip, created_at) VALUES (?, 'account', 'self_delete_close_org', ?, NOW())")
                ->execute([$close_org, $_SERVER['REMOTE_ADDR'] ?? '']);
        } catch (Throwable $e) {}
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

    echo json_encode(['ok' => true, 'message' => $close_org > 0
        ? 'Votre compte a été supprimé et l’espace de l’association est fermé.'
        : 'Votre compte a été supprimé.'], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[app-delete-account] ' . $e->getMessage());
    app_fail(500, 'server', 'Une erreur est survenue.');
}
