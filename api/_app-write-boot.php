<?php
/**
 * api/_app-write-boot.php — Bootstrap commun aux endpoints d'ECRITURE de l'app.
 * Auth session + lecture du corps JSON + verification CSRF.
 * Expose : $pdo, $user, $org_id, $input (array), $uid (int).
 * NE MODIFIE PAS le site : dedie a l'application mobile.
 */
if (!defined('AK_APP_WRITE')) define('AK_APP_WRITE', 1);

ob_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes-layout.php';
ob_end_clean();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function app_fail(int $code, string $err, ?string $msg = null): void {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $err, 'message' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!function_exists('app_parse_date')) {
    /**
     * Date saisie dans l'appli : AAAA-MM-JJ (heure éventuelle ignorée) ou JJ/MM/AAAA, comme
     * api/app-create-cotisation-payment.php. Vide = null ; date illisible ou inexistante (31/02)
     * = 422 avec « <$label> invalide », au lieu d'être ignorée en silence.
     * Ex. : $deadline = app_parse_date($input['deadline_apply'] ?? '', 'Date limite de dépôt');
     */
    function app_parse_date($v, string $label): ?string {
        if ($v === null) return null;
        if (!is_scalar($v)) app_fail(422, 'invalid', $label . ' invalide : utilisez JJ/MM/AAAA ou AAAA-MM-JJ.');
        $v = trim((string) $v);
        if ($v === '') return null;
        $y = $mo = $d = 0;
        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})(?:[ T]\d{1,2}:\d{2}(?::\d{2})?)?$/', $v, $m)) {
            [$y, $mo, $d] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } elseif (preg_match('#^(\d{1,2})[/.-](\d{1,2})[/.-](\d{4})$#', $v, $m)) {
            [$d, $mo, $y] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        }
        // Année hors 1900-2100 : faute de frappe (« 12/10/0026 »), refusée comme une date illisible
        if ($y < 1900 || $y > 2100 || !checkdate($mo, $d, $y)) app_fail(422, 'invalid', $label . ' invalide : utilisez JJ/MM/AAAA ou AAAA-MM-JJ.');
        return sprintf('%04d-%02d-%02d', $y, $mo, $d);
    }
}

if (empty($_SESSION['user_id'])) app_fail(401, 'auth');

// Compte désactivé (par un admin) ou supprimé (« Supprimer mon compte » sur un autre appareil)
// depuis l'ouverture de la session : la session est fermée ici et l'appli revient à l'écran de
// connexion (401 'auth'), au lieu de continuer à écrire sous ce compte jusqu'à son expiration.
// Règle unique (is_active = 1 et deleted_at vide), partagée avec les endpoints de lecture.
require_once __DIR__ . '/_app-founder.php';
$live = null;
if (!app_session_account_alive($pdo, $live)) app_fail(401, 'auth');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') app_fail(405, 'method');

$raw = file_get_contents('php://input');
$input = json_decode((string) $raw, true);
if (!is_array($input)) $input = [];

// CSRF : jeton de session (identique au site)
$token = (string) ($input['csrf'] ?? ($_POST['csrf_token'] ?? ''));
if (empty($_SESSION['csrf_token']) || !hash_equals((string) $_SESSION['csrf_token'], $token)) {
    app_fail(403, 'csrf', 'Session expirée, merci de réessayer.');
}

// Compte relu en base plus haut : plus de repli sur le seul identifiant de session
$user = function_exists('current_user') ? current_user() : null;
if (!$user && is_array($live)) {
    $user = $live;
    if (!empty($_SESSION['org_id'])) $user['org_id'] = (int) $_SESSION['org_id'];   // association choisie dans la session
}
if (!$user) app_fail(401, 'auth');
$org_id = (int) ($user['org_id'] ?? ($_SESSION['org_id'] ?? 0));
$uid    = (int) ($user['id'] ?? 0);
if ($uid <= 0) app_fail(401, 'auth');
// Compte sans association (agent de collectivité : org_id vide, parent_org_id renseigné ; Fondateur
// ou super-admin non rattaché) : seuls passent
//   - le compte personnel (mot de passe, profil, suppression) : ne touche que la ligne users de $uid ;
//   - les notifications (lues, jeton push) : celles de $uid ; app-notif-read.php remplace alors
//     $org_id par -1 (aucun canal, projet ni ticket « org 0 ») et le cron push lit par user_id ;
//   - l'espace Fondateur (app-founder-*) : chaque script vérifie app_is_founder / is_super_admin
//     et ne lit jamais $org_id.
// Un agent de collectivité est arrêté plus tôt (403 'mairie_space') sur ce qu'app-context.php ne
// lui ouvre pas (AK_GUARD_OPEN, AK_GUARD_MAIRIE_OPEN).
$ak_boot_script = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
$ak_boot_no_org = in_array($ak_boot_script, ['app-change-password.php', 'app-update-account.php', 'app-delete-account.php',
                                             'app-notif-read.php', 'app-register-push.php', 'app-ai-consent.php'], true)
                  || strpos($ak_boot_script, 'app-founder-') === 0;
if ($org_id <= 0 && !$ak_boot_no_org) {
    app_fail(400, 'org', 'Aucune association n’est rattachée à ce compte.');
}
unset($ak_boot_script, $ak_boot_no_org);
