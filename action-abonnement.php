<?php
/**
 * ============================================================
 * ASSOKIT — Handler actions abonnement (5.2.5)
 * URL : POST /action-abonnement
 * Actions : cancel_subscription, delete_account
 * ============================================================
 */
require_once __DIR__ . '/config.php';
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

    try {
        // === IMPORTANT : resilier reellement l'abonnement Stripe (sinon le client continue d'etre debite) ===
        // Source de verite Stripe = asso_subscriptions ; on annule a la fin de la periode payee.
        if (function_exists('ak_get_org_active_subscription')) {
            $active_sub = ak_get_org_active_subscription($pdo, $org_id);
            if ($active_sub) {
                $stripe_sub_id = $active_sub['stripe_subscription_id'] ?? null;
                $payment_mode  = $active_sub['payment_mode'] ?? 'manual';
                if ($payment_mode === 'stripe' && $stripe_sub_id
                    && function_exists('ak_stripe_is_configured') && ak_stripe_is_configured($pdo)) {
                    $resp = ak_stripe_api_call($pdo, 'POST', '/subscriptions/' . $stripe_sub_id, [
                        'cancel_at_period_end' => 'true',
                    ]);
                    if (empty($resp['ok'])) {
                        throw new Exception('Stripe: ' . ($resp['error'] ?? 'annulation refusee'));
                    }
                    $pdo->prepare("UPDATE asso_subscriptions SET cancel_at_period_end = 1, cancelled_at = NOW(), updated_at = NOW() WHERE id = :id")
                        ->execute([':id' => (int)$active_sub['id']]);
                } else {
                    $pdo->prepare("UPDATE asso_subscriptions SET status = 'cancelled', cancel_at_period_end = 1, cancelled_at = NOW(), updated_at = NOW() WHERE id = :id")
                        ->execute([':id' => (int)$active_sub['id']]);
                }
            }
        }

        // Table legacy conservee pour l'affichage de /abonnement
        $stmt = $pdo->prepare("
            UPDATE subscriptions
            SET status = 'cancelled',
                cancelled_at = NOW(),
                cancellation_reason = ?
            WHERE org_id = ?
              AND status IN ('active', 'trialing', 'past_due', 'paused')
        ");
        $stmt->execute([$reason !== '' ? $reason : null, $org_id]);

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

    if ($confirm !== 'SUPPRIMER') {
        header('Location: /abonnement?tab=danger&flash=' . urlencode('Confirmation invalide : tapez SUPPRIMER') . '&ft=error');
        exit;
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
