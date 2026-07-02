<?php
/**
 * mon-asso-notification-respond.php
 * Endpoint POST quand l'admin répond à une notification de relance.
 *
 * Réponses possibles :
 * - 'paid'      → marque facture comme payée
 * - 'not_paid'  → garde pending + propose envoi relance
 * - 'no_more'   → désactive toutes relances futures
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/asso-invoice-helpers.php';
require_once __DIR__ . '/asso-invoice-email-helpers.php';

require_login();
$user = current_user();
if (empty($user['org_id'])) { http_response_code(403); die('Aucune asso.'); }
$org_id = (int)$user['org_id'];

$is_admin = false;
try {
    $stmt = $pdo->prepare("SELECT role FROM users WHERE id = :id");
    $stmt->execute([':id' => (int)$user['id']]);
    $is_admin = in_array($stmt->fetchColumn(), ['admin', 'coordinator'], true);
} catch (Throwable $e) {}
if (!$is_admin) { http_response_code(403); die('Accès refusé.'); }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /dashboard'); exit;
}

$csrf_post = $_POST['csrf_token'] ?? '';
$csrf_sess = $_SESSION['csrf_token'] ?? '';
if (empty($csrf_sess) || !hash_equals($csrf_sess, $csrf_post)) {
    http_response_code(419); exit('Session expirée.');
}

$notification_id = (int)($_POST['notification_id'] ?? 0);
$response = $_POST['response'] ?? '';

if ($notification_id <= 0 || !in_array($response, ['paid', 'not_paid', 'no_more'], true)) {
    $_SESSION['flash_asso_factures'] = ['type' => 'error', 'message' => 'Réponse invalide.'];
    header('Location: /dashboard'); exit;
}

// Charger notif
$stmt = $pdo->prepare("
    SELECT n.*, i.invoice_number, i.amount_ttc_cents, i.client_id
    FROM asso_invoice_notifications n
    JOIN asso_invoices i ON i.id = n.invoice_id
    WHERE n.id = :id AND n.org_id = :org LIMIT 1
");
$stmt->execute([':id' => $notification_id, ':org' => $org_id]);
$notif = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$notif) {
    $_SESSION['flash_asso_factures'] = ['type' => 'error', 'message' => 'Notification introuvable.'];
    header('Location: /dashboard'); exit;
}

try {
    $pdo->beginTransaction();

    // Marquer la notif comme répondue
    $pdo->prepare("
        UPDATE asso_invoice_notifications
        SET responded_at = NOW(), response = :resp, response_by_user_id = :uid
        WHERE id = :id
    ")->execute([
        ':resp' => $response,
        ':uid' => (int)$user['id'],
        ':id' => $notification_id,
    ]);

    if ($response === 'paid') {
        // Marquer facture comme payée
        $pdo->prepare("
            UPDATE asso_invoices
            SET paid_at = NOW(), status = 'paid',
                confirmed_paid_by_org_at = NOW(),
                confirmed_paid_by_user_id = :uid
            WHERE id = :id
        ")->execute([
            ':uid' => (int)$user['id'],
            ':id' => $notif['invoice_id'],
        ]);

        // Insérer paiement
        try {
            $pdo->prepare("
                INSERT INTO asso_invoice_payments
                (invoice_id, org_id, amount_cents, paid_at, payment_method, source, recorded_by_user_id)
                VALUES (:inv, :org, :amt, NOW(), 'declared', 'admin', :uid)
            ")->execute([
                ':inv' => $notif['invoice_id'],
                ':org' => $org_id,
                ':amt' => $notif['amount_ttc_cents'],
                ':uid' => (int)$user['id'],
            ]);
        } catch (Throwable $e) {} // table peut ne pas exister si Sous-pack 2 pas installé

        $pdo->commit();
        $_SESSION['flash_asso_factures'] = [
            'type' => 'success',
            'message' => '✅ Facture ' . $notif['invoice_number'] . ' marquée comme payée.'
        ];

    } elseif ($response === 'no_more') {
        // Désactiver toute relance future pour cette facture
        $pdo->prepare("UPDATE asso_invoices SET dunning_enabled = 0 WHERE id = :id")
            ->execute([':id' => $notif['invoice_id']]);

        // Marquer toutes les notifs futures comme dismissed
        $pdo->prepare("
            UPDATE asso_invoice_notifications
            SET dismissed_at = NOW()
            WHERE invoice_id = :id AND responded_at IS NULL
        ")->execute([':id' => $notif['invoice_id']]);

        $pdo->commit();
        $_SESSION['flash_asso_factures'] = [
            'type' => 'success',
            'message' => '🚫 Relances désactivées pour ' . $notif['invoice_number'] . '.'
        ];

    } else {
        // 'not_paid' → on a déjà marqué la notif comme respondue.
        // Le CRON cron_asso_invoice_dunning_send va envoyer le mail dans les 15 min.
        $pdo->commit();
        $_SESSION['flash_asso_factures'] = [
            'type' => 'success',
            'message' => '📧 La relance pour ' . $notif['invoice_number'] . ' sera envoyée dans les 15 prochaines minutes.'
        ];
    }

} catch (Throwable $e) {
    $pdo->rollBack();
    error_log('[ASSO NOTIF RESPOND] ' . $e->getMessage());
    $_SESSION['flash_asso_factures'] = ['type' => 'error', 'message' => 'Erreur : ' . $e->getMessage()];
}

header('Location: /dashboard');
exit;
