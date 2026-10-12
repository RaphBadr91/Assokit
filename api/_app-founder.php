<?php
/**
 * api/_app-founder.php — Détection ROBUSTE du statut Fondateur pour l'app mobile.
 * Ne dépend PAS de ce que renvoie current_user() : relit la base au besoin.
 * App-only, aucun impact site. Le compte fondateur est forcé par email.
 * Fournit aussi app_session_account_alive() : compte de la session toujours actif (tous les
 * endpoints de l'app, lecture comme écriture via _app-write-boot.php).
 */
if (!function_exists('app_session_account_alive')) {
    /**
     * Le compte de la session existe-t-il toujours, actif et non supprimé ? Même règle que la
     * connexion : is_active = 1 et deleted_at vide. Un compte désactivé (par un admin, ou un
     * super-admin révoqué par le Fondateur) ou supprimé depuis l'ouverture de la session :
     * la session est fermée et false est renvoyé ; l'appelant répond 401 'auth' et l'appli
     * revient à l'écran de connexion.
     * $row reçoit la ligne users relue (null si la base n'a pas pu être lue : la session n'est
     * alors pas touchée, l'endpoint échouera de lui-même).
     */
    function app_session_account_alive(PDO $pdo, ?array &$row = null): bool {
        $row = null;
        $uid = (int) ($_SESSION['user_id'] ?? 0);
        if ($uid <= 0) return false;
        try {
            $st = $pdo->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
            $st->execute([$uid]);
            $live = $st->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return true;
        }
        if (is_array($live) && (int) ($live['is_active'] ?? 1) === 1 && empty($live['deleted_at'])) {
            $row = $live;
            return true;
        }
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) @session_destroy();
        return false;
    }
}

if (!function_exists('app_founder_emails')) {
    /** Emails toujours reconnus comme Fondateur (réservés : aucun autre compte ne peut les adopter). */
    function app_founder_emails(): array { return ['psiwaneraph@gmail.com']; }
}

if (!function_exists('app_is_founder')) {
    function app_is_founder(PDO $pdo, ?array $user = null): bool {
        // Emails toujours reconnus comme Fondateur dans l'application.
        $FOUNDER_EMAILS = app_founder_emails();

        // 1) Drapeau déjà présent dans la session utilisateur
        if (!empty($user['is_founder'])) return true;

        $uid = (int) ($_SESSION['user_id'] ?? ($user['id'] ?? 0));

        // 2) Email : depuis current_user() sinon relu en base (colonne email garantie)
        $email = strtolower(trim((string) ($user['email'] ?? '')));
        if ($email === '' && $uid > 0) {
            try {
                $st = $pdo->prepare("SELECT email FROM users WHERE id = ? LIMIT 1");
                $st->execute([$uid]);
                $email = strtolower(trim((string) $st->fetchColumn()));
            } catch (Throwable $e) {}
        }
        if ($email !== '' && in_array($email, $FOUNDER_EMAILS, true)) return true;

        // 3) Colonne is_founder relue en base (peut être absente → try/catch)
        if ($uid > 0) {
            try {
                $st = $pdo->prepare("SELECT is_founder FROM users WHERE id = ? LIMIT 1");
                $st->execute([$uid]);
                if (!empty($st->fetchColumn())) return true;
            } catch (Throwable $e) {}
        }

        return false;
    }
}
