<?php
/**
 * ============================================================
 * ASSOKIT — Déconnexion
 * ============================================================
 * Détruit la session et redirige vers la page de connexion.
 * v2 : track le logout avant destruction (Fondateur audit)
 * ============================================================
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/app-context.php'; // ak_is_mobile_app()

// === Incarnation en cours : clôturée (journal d'audit), la déconnexion est celle du Fondateur ===
if (!empty($_SESSION['impersonation_target_user_id'])) {
    require_once __DIR__ . '/impersonation-helpers.php';
    stop_impersonation(false);
}

// === Tracking activité (Fondateur audit) ===
// IMPORTANT : à appeler AVANT de détruire la session
if (file_exists(__DIR__ . '/activity-tracker.php')) {
    require_once __DIR__ . '/activity-tracker.php';
    if (function_exists('activity_log_logout')) {
        activity_log_logout();
    }
}

// === Notifications push de l'app ===
// Déconnexion depuis l'app : le téléphone ne doit plus recevoir les notifications du compte.
// L'app peut préciser son jeton (?push_token=…) ; sans lui (anciennes versions de l'app),
// on retire les jetons du compte, que chaque appareil encore connecté réenregistre à son
// prochain lancement. Une déconnexion depuis un navigateur ne touche pas aux téléphones.
$__uid = (int)($_SESSION['user_id'] ?? 0);
$__push_token = trim((string)($_POST['push_token'] ?? ($_GET['push_token'] ?? '')));
if ($__uid > 0 && empty($_SESSION['impersonation_target_user_id']) && ($__push_token !== '' || ak_is_mobile_app())) {
    try {
        if ($__push_token !== '') {
            $pdo->prepare("DELETE FROM asso_push_tokens WHERE user_id = ? AND token = ?")->execute([$__uid, $__push_token]);
        } else {
            $pdo->prepare("DELETE FROM asso_push_tokens WHERE user_id = ?")->execute([$__uid]);
        }
    } catch (Throwable $e) {
        // Table absente (aucun appareil enregistré) : rien à retirer
    }
}

// Vide les données de session
$_SESSION = [];

// Supprime le cookie de session
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params['path'],
        $params['domain'],
        $params['secure'],
        $params['httponly']
    );
}

// Détruit la session côté serveur
session_destroy();

// Redirige vers la page de connexion
header('Location: /connexion.php');
exit;
