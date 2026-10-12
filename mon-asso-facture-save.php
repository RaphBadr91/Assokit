<?php
/**
 * mon-asso-facture-save.php
 * Endpoint POST création/édition de facture
 * v2 : URLs propres (sans .php) pour éviter le bug 301 du htaccess
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/app-context.php';   // ak_session_guard() : compte désactivé, association résiliée ou suspendue, mot de passe provisoire
ak_session_guard();
require_once __DIR__ . '/asso-invoice-helpers.php';
require_once __DIR__ . '/finance-permissions.php';
require_once __DIR__ . '/asso-tags-helpers.php';

require_login();
$user = current_user();

if (empty($user['org_id'])) {
    http_response_code(403);
    die('Aucune association associée.');
}
$org_id = (int)$user['org_id'];

// Même politique que l'affichage/formulaire : Admin / Founder / Super Admin / Coordinateur.
if (!user_can_access_billing($user)) {
    http_response_code(403);
    die('Accès réservé aux administrateurs.');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /mon-asso-factures-client');
    exit;
}

// CSRF
$csrf_post = $_POST['csrf_token'] ?? '';
$csrf_sess = $_SESSION['csrf_token'] ?? '';
if (empty($csrf_sess) || !hash_equals($csrf_sess, $csrf_post)) {
    http_response_code(419);
    exit('Session expirée.');
}

$action = $_POST['action'] ?? 'create';

// ────── ENCAISSEMENT CONSTATÉ (« Marquer comme payée », fiche ou liste) ──────
if ($action === 'mark_paid') {
    $invoice_id = (int)($_POST['invoice_id'] ?? 0);
    $back = ($_POST['return'] ?? '') === 'list' ? '/mon-asso-factures-client' : '/mon-asso-facture-edit?id=' . $invoice_id;
    try {
        $res = ak_asso_invoice_mark_paid($pdo, $org_id, $invoice_id, (int)$user['id'], [
            'paid_at'   => (string)($_POST['paid_at'] ?? ''),
            'method'    => (string)($_POST['payment_method'] ?? ''),
            'reference' => (string)($_POST['payment_reference'] ?? ''),
        ]);
        if ($res['code'] === 'not_found') $back = '/mon-asso-factures-client';
        $_SESSION['flash_asso_factures'] = ['type' => $res['ok'] ? 'success' : 'error', 'message' => $res['message']];
    } catch (Throwable $e) {
        error_log('[ASSO INVOICE MARK PAID] ' . $e->getMessage());
        $_SESSION['flash_asso_factures'] = ['type' => 'error', 'message' => 'Impossible d’enregistrer l’encaissement. Réessayez.'];
    }
    header('Location: ' . $back);
    exit;
}

// ────── AVOIR (« Créer un avoir » sur une facture émise) ──────
if ($action === 'credit_note') {
    $invoice_id = (int)($_POST['invoice_id'] ?? 0);
    try {
        $res = ak_asso_credit_note_create($pdo, $org_id, $invoice_id, (int)$user['id'], (string)($_POST['reason'] ?? ''));
        $_SESSION['flash_asso_factures'] = ['type' => $res['ok'] ? 'success' : 'error', 'message' => $res['message'],
                                            'pdf_link' => $res['pdf_path'] ?? null];
        if ($res['ok']) { header('Location: /mon-asso-facture-edit?id=' . (int)$res['id']); exit; }
        if ($res['code'] === 'not_found') { header('Location: /mon-asso-factures-client'); exit; }
    } catch (Throwable $e) {
        error_log('[ASSO CREDIT NOTE] ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
        $_SESSION['flash_asso_factures'] = ['type' => 'error', 'message' => 'L’avoir n’a pas pu être créé. Réessayez dans un instant.'];
    }
    header('Location: /mon-asso-facture-edit?id=' . $invoice_id);
    exit;
}

// ────── TAGS (fiche d'une facture finalisée : classement interne, absent du PDF) ──────
if ($action === 'tags') {
    $invoice_id = (int)($_POST['invoice_id'] ?? 0);
    try {
        ak_tag_save_from_post($pdo, $org_id, 'invoice', $invoice_id, $_POST);   // facture et tags de l'association uniquement
        $_SESSION['flash_asso_factures'] = ['type' => 'success', 'message' => 'Tags enregistrés.'];
    } catch (Throwable $e) {
        error_log('[ASSO INVOICE TAGS] ' . $e->getMessage());
        $_SESSION['flash_asso_factures'] = ['type' => 'error', 'message' => 'Les tags n’ont pas pu être enregistrés. Réessayez.'];
    }
    header('Location: /mon-asso-facture-edit?id=' . $invoice_id);
    exit;
}

$is_edit = $action === 'edit' && !empty($_POST['invoice_id']);
$form_page = $is_edit ? '/mon-asso-facture-edit?id=' . (int)$_POST['invoice_id'] : '/mon-asso-facture-new';

// Lignes
$lines_post = $_POST['lines'] ?? [];
if (!is_array($lines_post) || count($lines_post) === 0) {
    $_SESSION['flash_asso_factures'] = ['type' => 'error', 'message' => 'Au moins une ligne est requise.'];
    header('Location: ' . $form_page);
    exit;
}

$lines = [];
foreach ($lines_post as $line) {
    $designation = trim((string)($line['designation'] ?? ''));
    if ($designation === '') continue;
    $lines[] = [
        'designation'   => mb_substr($designation, 0, 500),
        'quantity'      => round((float) str_replace(',', '.', $line['quantity'] ?? '1'), 2),        // 2 décimales, comme en base
        'unit_price_ht' => round((float) str_replace(',', '.', $line['unit_price_ht'] ?? '0'), 2),   // au centime
        'vat_rate'      => isset($line['vat_rate']) && $line['vat_rate'] !== '' ? (float)$line['vat_rate'] : null,
    ];
}

if (empty($lines)) {
    $_SESSION['flash_asso_factures'] = ['type' => 'error', 'message' => 'Renseignez au moins une désignation.'];
    header('Location: ' . $form_page);
    exit;
}

// Client
$client_data = [
    'id'                 => (int)($_POST['client_id'] ?? 0) ?: null,
    'client_type'        => in_array($_POST['client_type'] ?? '', ['individual', 'company']) ? $_POST['client_type'] : 'company',
    'display_name'       => trim((string)($_POST['display_name'] ?? '')),
    'legal_name'         => trim((string)($_POST['legal_name'] ?? '')) ?: null,
    'contact_first_name' => trim((string)($_POST['contact_first_name'] ?? '')) ?: null,
    'contact_last_name'  => trim((string)($_POST['contact_last_name'] ?? '')) ?: null,
    'email'              => trim((string)($_POST['email'] ?? '')),
    'phone'              => trim((string)($_POST['phone'] ?? '')) ?: null,
    'address_street'     => trim((string)($_POST['address_street'] ?? '')) ?: null,
    'address_complement' => trim((string)($_POST['address_complement'] ?? '')) ?: null,
    'address_zip'        => trim((string)($_POST['address_zip'] ?? '')) ?: null,
    'address_city'       => trim((string)($_POST['address_city'] ?? '')) ?: null,
    'siren'              => trim((string)($_POST['siren'] ?? '')) ?: null,
];

if (empty($client_data['display_name']) || empty($client_data['email'])) {
    $_SESSION['flash_asso_factures'] = ['type' => 'error', 'message' => 'Nom et email du client obligatoires.'];
    header('Location: ' . $form_page);
    exit;
}

$issued_in = trim((string)($_POST['issued_at'] ?? ''));
if ($issued_in !== '') {
    $d = DateTime::createFromFormat('!Y-m-d', $issued_in);
    if (!$d || $d->format('Y-m-d') !== $issued_in) {
        $_SESSION['flash_asso_factures'] = ['type' => 'error', 'message' => 'Date d’émission invalide.'];
        header('Location: ' . $form_page);
        exit;
    }
}
$issued_at = $issued_in !== '' ? $issued_in . ' ' . date('H:i:s') : date('Y-m-d H:i:s');
// Sans valeur saisie : délai par défaut des paramètres de facturation
$due_days  = (isset($_POST['due_days']) && trim((string)$_POST['due_days']) !== '')
    ? max(0, min(365, (int)$_POST['due_days']))
    : ak_asso_default_due_days($pdo, $org_id);
// Création : « Enregistrer en brouillon » (save_draft) ou « Créer la facture ».
// Modification d'un brouillon : il reste brouillon, sauf clic sur « Finaliser la facture ».
$status = $is_edit
    ? (!empty($_POST['finalize']) ? 'pending' : 'draft')
    : (!empty($_POST['save_draft']) ? 'draft' : 'pending');

try {
    if ($is_edit) {
        $invoice_id = (int)$_POST['invoice_id'];
        $stmt = $pdo->prepare("SELECT * FROM asso_invoices WHERE id = :id AND org_id = :org LIMIT 1");
        $stmt->execute([':id' => $invoice_id, ':org' => $org_id]);
        $invoice = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$invoice) throw new RuntimeException('Facture introuvable.');

        // [PISTE D'AUDIT FIABLE] Une facture finalisée est inaltérable (art. 289 CGI /
        // facture électronique). Seul un brouillon peut être modifié. Toute correction
        // d'une facture émise passe par un avoir (note de crédit).
        if ((string)($invoice['status'] ?? '') !== 'draft') {
            $_SESSION['flash_asso_factures'] = [
                'type' => 'error',
                'message' => 'La facture ' . ($invoice['invoice_number'] ?? '') . ' est finalisée : elle ne peut plus être modifiée. Pour la corriger, émettez un avoir.',
            ];
            header('Location: /mon-asso-facture-edit?id=' . $invoice_id);
            exit;
        }

        // Coordonnées du client saisies sur la facture : enregistrées sur sa fiche (client de
        // cette association uniquement), puis reprises dans le snapshot et le PDF ci-dessous.
        $client_id = ak_asso_client_from_form($pdo, $org_id, $client_data, (int)$user['id']);

        $total_ht = 0; $total_vat = 0; $total_ttc = 0;
        foreach ($lines as $line) {
            $c = ak_asso_line_compute($line['quantity'], $line['unit_price_ht'], $line['vat_rate']);
            $total_ht += $c['total_ht_cents'];
            $total_vat += $c['total_vat_cents'];
            $total_ttc += $c['total_ttc_cents'];
        }

        $due_at = date('Y-m-d 23:59:59', strtotime($issued_at . ' +' . $due_days . ' days'));

        // Le PDF lit client_snapshot (pas le client live) : on le rafraîchit à chaque édition,
        // sinon un changement de client sur un brouillon produit un PDF au nom de l'ancien client.
        $client_snap = null;
        try {
            $cs = $pdo->prepare("SELECT * FROM asso_clients WHERE id = ? AND org_id = ? LIMIT 1");
            $cs->execute([$client_id, $org_id]);
            $cfull = $cs->fetch(PDO::FETCH_ASSOC);
            if ($cfull) $client_snap = json_encode($cfull, JSON_UNESCAPED_UNICODE);
        } catch (Throwable $e) {}

        $pdo->prepare("
            UPDATE asso_invoices SET
                client_id = :cli,
                client_snapshot = COALESCE(:snap, client_snapshot),
                issued_at = :issued, due_at = :due,
                amount_ht_cents = :ht, amount_vat_cents = :vat, amount_ttc_cents = :ttc,
                status = :status,
                description = :desc, internal_notes = :notes,
                updated_at = NOW()
            WHERE id = :id
        ")->execute([
            ':cli' => $client_id,
            ':snap' => $client_snap,
            ':issued' => $issued_at,
            ':due' => $due_at,
            ':ht' => $total_ht,
            ':vat' => $total_vat,
            ':ttc' => $total_ttc,
            ':status' => $status,
            ':desc' => trim((string)($_POST['description'] ?? '')) ?: null,
            ':notes' => trim((string)($_POST['internal_notes'] ?? '')) ?: null,
            ':id' => $invoice_id,
        ]);
        if ($status === 'pending') {
            // Finalisation : la facture devient inaltérable (date de finalisation + journal d'audit)
            require_once __DIR__ . '/trial-helpers.php';
            try { ak_db_update($pdo, 'asso_invoices', $invoice_id, ['finalized_at' => date('Y-m-d H:i:s')]); } catch (Throwable $e) {}
            ak_asso_invoice_audit($pdo, $org_id, $invoice_id, 'finalized', (int)$user['id'], 'draft', 'pending');
        }

        $pdo->prepare("DELETE FROM asso_invoice_lines WHERE invoice_id = :id")->execute([':id' => $invoice_id]);
        $stmt = $pdo->prepare("
            INSERT INTO asso_invoice_lines (
                invoice_id, line_order, designation, quantity, unit_price_ht_cents, vat_rate,
                total_ht_cents, total_vat_cents, total_ttc_cents
            ) VALUES (
                :inv, :ord, :des, :qty, :pu, :vat, :tht, :tvat, :tttc
            )
        ");
        foreach ($lines as $i => $line) {
            $c = ak_asso_line_compute($line['quantity'], $line['unit_price_ht'], $line['vat_rate']);
            $stmt->execute([
                ':inv'  => $invoice_id,
                ':ord'  => $i,
                ':des'  => $line['designation'],
                ':qty'  => $line['quantity'],
                ':pu'   => (int) round($line['unit_price_ht'] * 100),
                ':vat'  => $line['vat_rate'],
                ':tht'  => $c['total_ht_cents'],
                ':tvat' => $c['total_vat_cents'],
                ':tttc' => $c['total_ttc_cents'],
            ]);
        }

        $pdf_path = ak_asso_invoice_render_pdf($pdo, $invoice_id);
        try { ak_tag_save_from_post($pdo, $org_id, 'invoice', $invoice_id, $_POST); }
        catch (Throwable $e) { error_log('[ASSO INVOICE TAGS] ' . $e->getMessage()); }

        $_SESSION['flash_asso_factures'] = [
            'type' => 'success',
            'message' => $status === 'pending'
                ? 'Facture ' . $invoice['invoice_number'] . ' finalisée : elle ne peut plus être modifiée. Vous pouvez maintenant l’envoyer au client.'
                : 'Brouillon ' . $invoice['invoice_number'] . ' enregistré. Il reste modifiable jusqu’à sa finalisation.',
            'pdf_link' => $pdf_path,
        ];

        header('Location: /mon-asso-facture-edit?id=' . $invoice_id);
        exit;

    } else {
        // ────── CRÉATION ──────
        // Double clic / formulaire renvoyé : la facture existe déjà, on n'en crée pas une seconde
        $form_nonce = (string)($_POST['form_nonce'] ?? '');
        if ($done_id = ak_asso_form_nonce_used('invoice_new', $form_nonce)) {
            $_SESSION['flash_asso_factures'] = [
                'type' => 'success',
                'message' => 'Cette facture a déjà été créée : le second envoi du formulaire a été ignoré.',
            ];
            header('Location: /mon-asso-facture-edit?id=' . $done_id);
            exit;
        }

        // Client choisi ou de même e-mail : sa fiche prend les coordonnées saisies
        $client_data['id'] = ak_asso_client_from_form($pdo, $org_id, $client_data, (int)$user['id']);
        $result = ak_asso_invoice_create($pdo, $org_id, (int)$user['id'], [
            'client'       => $client_data,
            'lines'        => $lines,
            'issued_at'    => $issued_at,
            'due_days'     => $due_days,
            'status'       => $status,
            'description'  => trim((string)($_POST['description'] ?? '')) ?: null,
            'internal_notes' => trim((string)($_POST['internal_notes'] ?? '')) ?: null,
        ]);
        ak_asso_form_nonce_consume('invoice_new', $form_nonce, (int)$result['id']);
        try { ak_tag_save_from_post($pdo, $org_id, 'invoice', (int)$result['id'], $_POST); }
        catch (Throwable $e) { error_log('[ASSO INVOICE TAGS] ' . $e->getMessage()); }

        $_SESSION['flash_asso_factures'] = [
            'type' => 'success',
            'message' => 'Facture ' . $result['invoice_number'] . ' créée avec succès.',
            'pdf_link' => $result['pdf_path'],
        ];

        header('Location: /mon-asso-factures-client');
        exit;
    }
} catch (Throwable $e) {
    error_log('[ASSO INVOICE SAVE] ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    $_SESSION['flash_asso_factures'] = [
        'type' => 'error',
        'message' => 'Erreur : ' . $e->getMessage(),
    ];
    header('Location: ' . $form_page);
    exit;
}
