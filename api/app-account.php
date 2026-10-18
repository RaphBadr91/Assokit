<?php
/**
 * api/app-account.php — Infos du compte + org pour l'ecran Parametres natif. JSON.
 * NE MODIFIE PAS le site.
 */
// Compte relu en base : désactivé, supprimé ou introuvable → session fermée, 401 'auth'
// (plus de repli sur $_SESSION['user_id'] / $_SESSION['org_id']). Expose $user, $uid, $org_id.
require_once __DIR__ . '/_app-read-boot.php';

try {
    $st = $pdo->prepare("SELECT first_name, last_name, email, phone, city, role, is_founder, is_platform_admin FROM users WHERE id = ? LIMIT 1");
    $st->execute([$uid]);
    $u = $st->fetch(PDO::FETCH_ASSOC) ?: [];

    $org_name = ''; $org_logo = null;
    try {
        $st = $pdo->prepare("SELECT name, logo_path FROM organizations WHERE id = ? LIMIT 1");
        $st->execute([$org_id]);
        $o = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $org_name = (string) ($o['name'] ?? '');
        $lp = trim((string) ($o['logo_path'] ?? ''));
        if ($lp !== '') $org_logo = (strpos($lp, 'http') === 0) ? $lp : 'https://assokit.fr/' . ltrim($lp, '/');
    } catch (Throwable $e) {}

    // Mêmes règles que le site (ak_account_delete_blocker) : Fondateur, seul administrateur de l'association…
    $delete_blocked = function_exists('ak_account_delete_blocker') ? ak_account_delete_blocker($pdo, $uid)
        : ((!empty($u['is_founder']) || !empty($u['is_platform_admin'])) ? 'Contactez le support.' : '');
    // Seul compte de l'association : dans l'app, la suppression reste possible et ferme l'espace
    // de l'association (api/app-delete-account.php, Apple 5.1.1 v). La fiche de confirmation le dit.
    $delete_note = null;
    if ($delete_blocked !== '' && function_exists('ak_account_sole_org') && ak_account_sole_org($pdo, $uid) > 0) {
        $delete_blocked = '';
        $delete_note = 'Vous êtes le seul compte de « ' . ($org_name !== '' ? $org_name : 'votre association') . ' » : '
            . 'supprimer votre compte ferme aussi son espace Assokit. Ses données sont conservées 30 jours '
            . '(export sur demande à ' . (defined('AK_SUPPORT_EMAIL') ? AK_SUPPORT_EMAIL : 'contact@assokit.fr') . '), '
            . 'puis supprimées, sauf les pièces comptables (10 ans).';
    }
    if ($delete_blocked === '' && !empty($_SESSION['impersonation_target_user_id'])) {
        $delete_blocked = 'Suppression impossible pendant une incarnation.';
        $delete_note = null;
    }
    $can_delete = ($delete_blocked === '');

    // Association suspendue (motif renseigné : ak_org_access, app-context.php) : ses réglages (logo,
    // infos de l'organisation) répondent 403 'org_suspended'. Les entrées « Logo » et « Infos de
    // l'organisation » de l'appli dépendent de is_admin : elles disparaissent au lieu de renvoyer à
    // l'écran de suspension. Fondateur / super-admin : jamais coupés par l'état d'une association.
    $org_suspended = $org_id > 0 && function_exists('ak_org_access') && ak_org_access($pdo, $org_id) === 'suspended'
        && !(function_exists('ak_user_is_platform') && ak_user_is_platform($user));

    echo json_encode([
        'ok' => true,
        'account' => [
            'first_name' => (string) ($u['first_name'] ?? ''),
            'last_name'  => (string) ($u['last_name'] ?? ''),
            'email'      => (string) ($u['email'] ?? ''),
            'phone'      => (string) ($u['phone'] ?? ''),
            'city'       => (string) ($u['city'] ?? ''),
            'role'       => (string) ($u['role'] ?? 'member'),
            'can_delete' => $can_delete,
            // Raison du refus, à afficher sous le bouton grisé (null si la suppression est possible)
            'delete_blocked_reason' => $can_delete ? null : $delete_blocked,
            // Seul compte de l'association : ce que la suppression ferme en plus (null sinon)
            'delete_note' => $can_delete ? $delete_note : null,
        ],
        // Agent de collectivité (aucune association) : jamais administrateur d'une association,
        // même si son users.role vaut « admin ».
        'org' => ['name' => $org_name, 'logo' => $org_logo,
                  'is_admin' => $org_id > 0 && (($u['role'] ?? '') === 'admin') && !$org_suspended,
                  'suspended' => $org_suspended],
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[api/app-account] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'server']);
}
