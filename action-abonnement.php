<?php
/**
 * ============================================================
 * ASSOKIT — Handler actions abonnement (5.2.5)
 * URL : POST /action-abonnement
 * Actions : cancel_subscription, delete_account
 * ============================================================
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/activity-tracker.php';  // journal fondateur : qui a fait quoi, à la minute près
activity_log_request();
require_once __DIR__ . '/includes-layout.php';
@require_once __DIR__ . '/stripe-helpers.php';
require_login();

$current = current_user();
$user_id = (int)$current['id'];
$org_id  = (int)($current['org_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /abonnement'); exit;
}

// === CSRF ===
$csrf = $_POST['csrf'] ?? '';
if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf)) {
    header('Location: /abonnement?tab=danger&flash=' . urlencode('Session expiree, veuillez recharger') . '&ft=error');
    exit;
}

// === Role admin only ===
if (($current['role'] ?? '') !== 'admin') {
    header('Location: /abonnement?tab=danger&flash=' . urlencode('Acces reserve aux administrateurs') . '&ft=error');
    exit;
}

if ($org_id <= 0) {
    header('Location: /abonnement?tab=danger&flash=' . urlencode('Organisation introuvable') . '&ft=error');
    exit;
}

$action = $_POST['action'] ?? '';

// ============================================================
// ACTION 1 : Annuler abonnement
// ============================================================
if ($action === 'cancel_subscription') {
    $reason = trim($_POST['reason'] ?? '');
    if (strlen($reason) > 500) $reason = substr($reason, 0, 500);

    // Essai gratuit : rien à annuler (comme /mon-asso-annuler-abonnement). Annuler la ligne
    // d'essai la sortait de la bascule de fin d'essai : elle gardait le plan d'essai.
    // Même règle que la page d'annulation, sur la ligne qui serait annulée ci-dessous.
    @require_once __DIR__ . '/plan-helpers.php';
    if (function_exists('ak_trial_blocks_cancel')) {
        $ti = ak_plan_trial_info($pdo, $org_id);
        $cur = function_exists('ak_get_org_active_subscription') ? ak_get_org_active_subscription($pdo, $org_id) : null;
        if (!$cur) $cur = ak_get_current_plan($pdo, $org_id);
        if (ak_trial_blocks_cancel($pdo, $org_id, $cur, $ti)) {
            $_SESSION['flash_error'] = $ti['trial_expired']
                ? 'Votre essai est terminé : il n’y a pas d’abonnement payant à annuler.'
                : 'Votre association est en essai : il n’y a pas d’abonnement à annuler.';
            header('Location: /mon-asso-plan');
            exit;
        }
    }

    try {
        // === IMPORTANT : resilier reellement l'abonnement Stripe (sinon le client continue d'etre debite) ===
        // Source de verite Stripe = asso_subscriptions ; on annule a la fin de la periode payee.
        if (function_exists('ak_get_org_active_subscription')) {
            $active_sub = ak_get_org_active_subscription($pdo, $org_id);
            // Déjà résilié : rien à refaire (la date de fin déjà annoncée ne bouge pas)
            if ($active_sub && ($active_sub['status'] ?? '') !== 'cancelled' && empty($active_sub['cancel_at_period_end'])) {
                $stripe_sub_id = $active_sub['stripe_subscription_id'] ?? null;
                $payment_mode  = $active_sub['payment_mode'] ?? 'manual';
                if ($payment_mode === 'stripe' && $stripe_sub_id) {
                    // Jamais « résilié » ici un abonnement que Stripe continuerait de prélever
                    if (!function_exists('ak_stripe_is_configured') || !ak_stripe_is_configured($pdo)) {
                        throw new Exception('Stripe non configure : abonnement par carte a arreter par le Fondateur');
                    }
                    $resp = ak_stripe_api_call($pdo, 'POST', '/subscriptions/' . rawurlencode($stripe_sub_id), [
                        'cancel_at_period_end' => 'true',
                    ]);
                    if (empty($resp['ok'])) {
                        throw new Exception('Stripe: ' . ($resp['error'] ?? 'annulation refusee'));
                    }
                    $stripe_end = !empty($resp['data']['current_period_end']) ? date('Y-m-d H:i:s', (int)$resp['data']['current_period_end']) : null;
                    ak_db_update($pdo, 'asso_subscriptions', (int)$active_sub['id'],
                        ['cancel_at_period_end' => 1] + ($stripe_end ? ['current_period_end' => $stripe_end] : []),
                        ['cancelled_at' => 'NOW()', 'updated_at' => 'NOW()']);
                } else {
                    // Même règle que /mon-asso-annuler-abonnement : formule gardée jusqu'à la fin de la
                    // période (date écrite sur la ligne), puis plan gratuit Démarrage
                    ak_sub_cancel_manual($pdo, $active_sub, $org_id);
                }
            }
        }

        // Table legacy conservee pour l'affichage de /abonnement (colonnes lues si elles existent)
        $lcols = function_exists('ak_db_columns') ? ak_db_columns($pdo, 'subscriptions') : [];
        $lsets = ["status = 'cancelled'"]; $largs = [];
        if (!$lcols || isset($lcols['cancelled_at'])) $lsets[] = 'cancelled_at = NOW()';
        if (!$lcols || isset($lcols['cancellation_reason'])) { $lsets[] = 'cancellation_reason = ?'; $largs[] = $reason !== '' ? $reason : null; }
        $largs[] = $org_id;
        $stmt = $pdo->prepare("
            UPDATE subscriptions
            SET " . implode(', ', $lsets) . "
            WHERE org_id = ?
              AND status IN ('active', 'trialing', 'past_due', 'paused')
        ");
        $stmt->execute($largs);

        header('Location: /abonnement?tab=mon-abonnement&flash=' . urlencode('Abonnement annule. Vous gardez l\'acces jusqu\'a la fin de la periode payee.') . '&ft=success');
    } catch (Throwable $e) {
        error_log('[ACTION-ABONNEMENT cancel] ' . $e->getMessage());
        header('Location: /abonnement?tab=danger&flash=' . urlencode('Une erreur technique est survenue. Merci de reessayer.') . '&ft=error');
    }
    exit;
}

// ============================================================
// ACTION 2 : Supprimer compte (soft delete)
// ============================================================
if ($action === 'delete_account') {
    $confirm = $_POST['confirm'] ?? '';
    $reason  = trim($_POST['reason'] ?? '');
    if (strlen($reason) > 500) $reason = substr($reason, 0, 500);

    // Le champ s'affiche en majuscules (text-transform) : la casse tapée ne compte pas
    if (mb_strtoupper(trim((string)$confirm), 'UTF-8') !== 'SUPPRIMER') {
        header('Location: /abonnement?tab=danger&flash=' . urlencode('Confirmation invalide : tapez SUPPRIMER') . '&ft=error');
        exit;
    }

    // Abonnement Stripe arrêté D'ABORD (comme la fermeture depuis l'appli ou le cockpit) : sinon Stripe
    // continuait de prélever la carte d'un compte supprimé. Échec → rien n'est supprimé.
    if (is_file(__DIR__ . '/sa-plan-state-helpers.php')) require_once __DIR__ . '/sa-plan-state-helpers.php';
    if (function_exists('sa_stripe_cancel_for_org')) {
        try { $stripe_stop = sa_stripe_cancel_for_org($pdo, $org_id); }
        catch (Throwable $e) { $stripe_stop = ['cancelled' => [], 'failed' => ['?' => get_class($e)]]; }
        if (!empty($stripe_stop['failed'])) {
            error_log('[ACTION-ABONNEMENT delete] org #' . $org_id . ' : abonnement Stripe non arrêté, suppression refusée');
            header('Location: /abonnement?tab=danger&flash=' . urlencode('Votre abonnement par carte n’a pas pu être arrêté : rien n’a été supprimé. Réessayez ou contactez-nous.') . '&ft=error');
            exit;
        }
    }

    try {
        $pdo->beginTransaction();

        // Soft delete organization
        $stmt = $pdo->prepare("
            UPDATE organizations
            SET deleted_at = NOW(),
                deletion_reason = ?,
                deleted_by_user_id = ?
            WHERE id = ?
              AND deleted_at IS NULL
        ");
        $stmt->execute([$reason !== '' ? $reason : null, $user_id, $org_id]);

        // Cancel subscription
        $pdo->prepare("
            UPDATE subscriptions
            SET status = 'cancelled',
                cancelled_at = COALESCE(cancelled_at, NOW()),
                cancellation_reason = COALESCE(cancellation_reason, 'Compte supprime')
            WHERE org_id = ?
        ")->execute([$org_id]);

        $pdo->commit();

        // Détruire session + logout
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();

        header('Location: /login?goodbye=1');
        exit;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[ACTION-ABONNEMENT delete] ' . $e->getMessage());
        header('Location: /abonnement?tab=danger&flash=' . urlencode('Une erreur technique est survenue. Merci de reessayer.') . '&ft=error');
        exit;
    }
}

// Action inconnue
header('Location: /abonnement'); exit;
