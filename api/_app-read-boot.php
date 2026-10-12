<?php
/**
 * api/_app-read-boot.php — Bootstrap commun aux endpoints de LECTURE de l'app.
 * Pendant de _app-write-boot.php, sans corps JSON ni CSRF : auth session, compte relu en base,
 * en-têtes JSON.
 * Expose : $pdo, $user (current_user(), jamais vide), $uid (int), $org_id (int).
 *
 * Compte absent, désactivé (par un admin) ou supprimé (« Supprimer mon compte » sur un autre
 * appareil) depuis l'ouverture de la session : la session est fermée et l'endpoint répond
 * 401 'auth' ; l'appli revient alors à l'écran de connexion, au lieu de continuer à lire les
 * données de l'association jusqu'à l'expiration de la session. Base illisible : 500 'server'.
 * Plus aucun repli sur le seul identifiant de session quand current_user() ne renvoie rien.
 * NE MODIFIE PAS le site : dédié à l'application mobile.
 */
ob_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes-layout.php';
ob_end_clean(); // jette toute sortie parasite éventuelle

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (!function_exists('app_read_fail')) {
    function app_read_fail(int $code, string $err): void {
        http_response_code($code);
        echo json_encode(['ok' => false, 'error' => $err]);
        exit;
    }
}

if (empty($_SESSION['user_id'])) app_read_fail(401, 'auth');

// Règle unique avec _app-write-boot.php et les endpoints fondateur (app_session_account_alive :
// is_active = 1 et deleted_at vide) ; la fonction ferme elle-même la session d'un compte parti.
require_once __DIR__ . '/_app-founder.php';
$live = null;
if (!app_session_account_alive($pdo, $live)) app_read_fail(401, 'auth');
if ($live === null) {   // compte non relu : base indisponible, la session est gardée
    error_log('[api/_app-read-boot] lecture du compte ' . (int) $_SESSION['user_id'] . ' impossible');
    app_read_fail(500, 'server');
}
try {
    $user = function_exists('current_user') ? current_user() : null;
} catch (Throwable $e) {
    error_log('[api/_app-read-boot] ' . $e->getMessage());
    app_read_fail(500, 'server');
}
if (!$user || (int) ($user['id'] ?? 0) <= 0) app_read_fail(401, 'auth');

$uid    = (int) $user['id'];
$org_id = (int) ($user['org_id'] ?? ($_SESSION['org_id'] ?? 0));   // même calcul que _app-write-boot.php
