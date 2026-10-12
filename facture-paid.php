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

// Vérif facture (+ nom de l'association pour le message de confirmation)
$stmt = $pdo->prepare("
    SELECT i.*, o.name AS org_name, o.billing_email AS org_billing_email
    FROM asso_invoices i
    LEFT JOIN organizations o ON o.id = i.org_id
    WHERE i.public_uuid = :u LIMIT 1
");
$stmt->execute([':u' => $uuid]);
$inv = $stmt->fetch(PDO::FETCH_ASSOC);
// Un brouillon n'est pas émis : introuvable, comme sur la page publique
if (!$inv || (string)$inv['status'] === 'draft') {
    http_response_code(404); die('Facture introuvable.');
}

// Seule une facture émise et non réglée attend un paiement (même règle que la page publique) ;
// un avoir n'attend jamais de règlement du client
if (($inv['invoice_type'] ?? 'invoice') === 'credit_note') {
    $_SESSION['flash_public'] = ['type' => 'error', 'message' => 'Ce document est un avoir : aucun règlement n’est attendu de votre part.'];
    header('Location: /facture/' . $uuid);
    exit;
}
if (in_array((string)$inv['status'], ['cancelled', 'refunded'], true)) {
    $_SESSION['flash_public'] = ['type' => 'error', 'message' => 'Cette facture a été annulée : aucun règlement n’est attendu.'];
    header('Location: /facture/' . $uuid);
    exit;
}
if (!empty($inv['paid_at']) || (string)$inv['status'] === 'paid') {
    $_SESSION['flash_public'] = ['type' => 'success', 'message' => 'Cette facture est déjà marquée payée.'];
    header('Location: /facture/' . $uuid);
    exit;
}
if (!empty($inv['client_marked_paid_at'])) {
    $_SESSION['flash_public'] = ['type' => 'success', 'message' => 'Votre paiement a déjà été déclaré le '
        . date('d/m/Y', strtotime($inv['client_marked_paid_at'])) . ' : il est en attente de confirmation.'];
    header('Location: /facture/' . $uuid);
    exit;
}

$payment_method = trim($_POST['payment_method'] ?? '');
$paid_at = (string)($_POST['paid_at'] ?? '');
$d = DateTime::createFromFormat('!Y-m-d', $paid_at);
if (!$d || $d->format('Y-m-d') !== $paid_at || $paid_at > date('Y-m-d')) $paid_at = date('Y-m-d');
$reference = trim($_POST['reference'] ?? '') ?: null;
$note = trim($_POST['note'] ?? '') ?: null;

if (empty($payment_method)) {
    $_SESSION['flash_public'] = ['type' => 'error', 'message' => 'Méthode de paiement obligatoire.'];
    header('Location: /facture/' . $uuid);
    exit;
}

try {
    $pdo->beginTransaction();

    // Marquer la facture comme déclarée payée par client (une seule déclaration : un double
    // envoi du formulaire ne crée pas deux lignes de règlement)
    $upd = $pdo->prepare("
        UPDATE asso_invoices SET
            client_marked_paid_at = NOW(),
            client_paid_method = :method,
            client_paid_reference = :ref,
            client_paid_note = :note
        WHERE id = :id AND client_marked_paid_at IS NULL AND paid_at IS NULL
    ");
    $upd->execute([
        ':method' => mb_substr($payment_method, 0, 50),
        ':ref' => $reference ? mb_substr($reference, 0, 255) : null,
        ':note' => $note,
        ':id' => $inv['id'],
    ]);
    if ($upd->rowCount() === 0) {
        $pdo->rollBack();
        $_SESSION['flash_public'] = ['type' => 'success', 'message' => 'Votre paiement a déjà été déclaré : il est en attente de confirmation.'];
        header('Location: /facture/' . $uuid);
        exit;
    }

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

    // Prévenir l'association : la déclaration apparaît aussi sur son tableau de bord et
    // dans la liste des factures (« Paiement déclaré », à confirmer). E-mail en complément.
    try {
        $to = trim((string)($inv['org_billing_email'] ?? ''));
        if ($to === '') {
            $adm = $pdo->prepare("SELECT email FROM users WHERE org_id = ? AND role = 'admin' AND is_active = 1 AND deleted_at IS NULL ORDER BY id ASC LIMIT 1");
            $adm->execute([(int)$inv['org_id']]);
            $to = (string)$adm->fetchColumn();
        }
        if ($to !== '' && filter_var($to, FILTER_VALIDATE_EMAIL)) {
            require_once __DIR__ . '/asso-invoice-email-helpers.php';
            $esc = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            // Domaine fixe : la requête vient d'un visiteur non connecté (en-tête Host non fiable)
            $link = 'https://assokit.fr/mon-asso-facture-edit?id=' . (int)$inv['id'] . '#encaissement';
            $methods = ['virement' => 'virement bancaire', 'cheque' => 'chèque', 'especes' => 'espèces', 'cb' => 'carte bancaire', 'autre' => 'autre moyen'];
            $html = '<p>Bonjour,</p>'
                . '<p>Votre client indique avoir réglé la facture <strong>' . $esc($inv['invoice_number']) . '</strong> ('
                . $esc(number_format(((int)$inv['amount_ttc_cents']) / 100, 2, ',', ' ')) . ' €) le '
                . $esc(date('d/m/Y', strtotime($paid_at))) . ' par ' . $esc($methods[$payment_method] ?? $payment_method)
                . ($reference ? ' (réf. ' . $esc($reference) . ')' : '') . '.</p>'
                . ($note ? '<p>Note du client : « ' . $esc($note) . ' »</p>' : '')
                . '<p>Vérifiez la réception du règlement, puis confirmez-le sur la facture : '
                . '<a href="' . $esc($link) . '">' . $esc($link) . '</a></p>'
                . '<p>La facture reste « en attente » et ses relances continuent tant que vous n’avez pas confirmé.</p>';
            ak_asso_send_resend($to, 'Paiement déclaré — facture ' . $inv['invoice_number'], $html, null, null, 'Assokit');
        }
    } catch (Throwable $e) {
        error_log('[ASSO INVOICE PAID-CLIENT] notification : ' . $e->getMessage());
    }

    $org_name = trim((string)($inv['org_name'] ?? '')) !== '' ? (string)$inv['org_name'] : 'L’association';
    $_SESSION['flash_public'] = [
        'type' => 'success',
        'message' => 'Merci ! Votre déclaration de paiement a été enregistrée. ' . $org_name
                   . ' en est informée et confirmera la réception de votre règlement.',
    ];

} catch (Throwable $e) {
    $pdo->rollBack();
    error_log('[ASSO INVOICE PAID-CLIENT] ' . $e->getMessage());
    $_SESSION['flash_public'] = ['type' => 'error', 'message' => 'Une erreur technique est survenue. Merci de réessayer.'];
}

header('Location: /facture/' . $uuid);
exit;
