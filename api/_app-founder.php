<?php
/**
 * api/_app-founder.php — Détection ROBUSTE du statut Fondateur pour l'app mobile.
 * Ne dépend PAS de ce que renvoie current_user() : relit la base au besoin.
 * App-only, aucun impact site. Le compte fondateur est forcé par email.
 */
if (!function_exists('app_is_founder')) {
    function app_is_founder(PDO $pdo, ?array $user = null): bool {
        // Emails toujours reconnus comme Fondateur dans l'application.
        $FOUNDER_EMAILS = ['psiwaneraph@gmail.com'];

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
