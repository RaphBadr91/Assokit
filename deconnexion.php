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

// === Tracking activité (Fondateur audit) ===
// IMPORTANT : à appeler AVANT de détruire la session
if (file_exists(__DIR__ . '/activity-tracker.php')) {
    require_once __DIR__ . '/activity-tracker.php';
    if (function_exists('activity_log_logout')) {
        activity_log_logout();
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
