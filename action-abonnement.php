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
        $stmt = $pdo->prepare("
            UPDATE subscriptions
            SET status = 'cancelled',
                cancelled_at = NOW(),
                cancellation_reason = ?
            WHERE org_id = ?
              AND status IN ('active', 'trialing', 'past_due', 'paused')
        ");
        $stmt->execute([$reason !== '' ? $reason : null, $org_id]);
        $n = $stmt->rowCount();

        if ($n > 0) {
            header('Location: /abonnement?tab=mon-abonnement&flash=' . urlencode('Abonnement annule') . '&ft=success');
        } else {
            header('Location: /abonnement?tab=danger&flash=' . urlencode('Aucun abonnement actif a annuler') . '&ft=error');
        }
    } catch (Throwable $e) {
        header('Location: /abonnement?tab=danger&flash=' . urlencode('Erreur : ' . $e->getMessage()) . '&ft=error');
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
        header('Location: /abonnement?tab=danger&flash=' . urlencode('Erreur suppression : ' . $e->getMessage()) . '&ft=error');
        exit;
    }
}

// Action inconnue
header('Location: /abonnement'); exit;
