<?php
/**
 * facture-paid.php
 * Endpoint POST quand le client clique "J'ai payé"
 * Pas de login requis (URL publique)
 */

require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /'); exit;
}

if (empty($_SESSION['csrf_token_public'])) {
    http_response_code(419); exit('Session expirée.');
}

$csrf_post = $_POST['csrf_token'] ?? '';
if (!hash_equals($_SESSION['csrf_token_public'], $csrf_post)) {
    http_response_code(419); exit('CSRF invalide.');
}

$uuid = $_POST['uuid'] ?? '';
if (empty($uuid) || !preg_match('/^[a-f0-9-]{36}$/i', $uuid)) {
    http_response_code(404); die('Facture introuvable.');
}

// Vérif facture
$stmt = $pdo->prepare("SELECT * FROM asso_invoices WHERE public_uuid = :u LIMIT 1");
$stmt->execute([':u' => $uuid]);
$inv = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$inv) {
    http_response_code(404); die('Facture introuvable.');
}

if (!empty($inv['paid_at'])) {
    $_SESSION['flash_public'] = ['type' => 'success', 'message' => 'Cette facture est déjà marquée payée.'];
    header('Location: /facture/' . $uuid);
    exit;
}

$payment_method = trim($_POST['payment_method'] ?? '');
$paid_at = $_POST['paid_at'] ?? date('Y-m-d');
$reference = trim($_POST['reference'] ?? '') ?: null;
$note = trim($_POST['note'] ?? '') ?: null;

if (empty($payment_method)) {
    $_SESSION['flash_public'] = ['type' => 'error', 'message' => 'Méthode de paiement obligatoire.'];
    header('Location: /facture/' . $uuid);
    exit;
}

try {
    $pdo->beginTransaction();

    // Marquer la facture comme déclarée payée par client
    $pdo->prepare("
        UPDATE asso_invoices SET
            client_marked_paid_at = NOW(),
            client_paid_method = :method,
            client_paid_reference = :ref,
            client_paid_note = :note
        WHERE id = :id
    ")->execute([
        ':method' => mb_substr($payment_method, 0, 50),
        ':ref' => $reference ? mb_substr($reference, 0, 255) : null,
        ':note' => $note,
        ':id' => $inv['id'],
    ]);

    // Insérer dans payments (source = client)
    $pdo->prepare("
        INSERT INTO asso_invoice_payments
        (invoice_id, org_id, amount_cents, paid_at, payment_method, reference, notes, source)
        VALUES (:inv, :org, :amt, :date, :method, :ref, :note, 'client')
    ")->execute([
        ':inv' => $inv['id'],
        ':org' => $inv['org_id'],
        ':amt' => $inv['amount_ttc_cents'],
        ':date' => $paid_at . ' 00:00:00',
        ':method' => mb_substr($payment_method, 0, 50),
        ':ref' => $reference,
        ':note' => $note,
    ]);

    $pdo->commit();

    error_log('[ASSO INVOICE PAID-CLIENT] ' . $inv['invoice_number'] . ' marqué payé par client');

    $_SESSION['flash_public'] = [
        'type' => 'success',
        'message' => 'Merci ! Votre paiement a été enregistré. ' . $inv['org_name'] ?? 'L\'association' . ' va recevoir une notification pour confirmer la réception.',
    ];

} catch (Throwable $e) {
    $pdo->rollBack();
    error_log('[ASSO INVOICE PAID-CLIENT] ' . $e->getMessage());
    $_SESSION['flash_public'] = ['type' => 'error', 'message' => 'Une erreur technique est survenue. Merci de réessayer.'];
}

header('Location: /facture/' . $uuid);
exit;
