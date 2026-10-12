<?php
/**
 * mon-asso-devis-save.php
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/app-context.php';   // ak_session_guard() : compte désactivé, association résiliée ou suspendue, mot de passe provisoire
ak_session_guard();
require_once __DIR__ . '/asso-invoice-helpers.php';
require_once __DIR__ . '/asso-quote-helpers.php';
require_once __DIR__ . '/asso-tags-helpers.php';

require_login();
$user = current_user();
if (empty($user['org_id'])) { http_response_code(403); die('Aucune asso.'); }
$org_id = (int)$user['org_id'];

// Règle unique de la Facturation : admin, coordinateur, fondateur, super admin, ou membre
// ayant le droit « Gérer les finances » (trésorier), comme Factures et Récurrences.
require_once __DIR__ . '/finance-permissions.php';
require_once __DIR__ . '/platform-flags.php';
if (!user_can_access_billing(ak_platform_flags($user))) { http_response_code(403); die('Accès refusé.'); }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /mon-asso-devis'); exit;
}

if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
    http_response_code(419); exit('Session expirée.');
}

$action = $_POST['action'] ?? 'create';

// Tags seuls (devis signé, converti ou annulé : classement interne, absent du PDF)
if ($action === 'tags') {
    $quote_id = (int)($_POST['quote_id'] ?? 0);
    try {
        ak_tag_save_from_post($pdo, $org_id, 'quote', $quote_id, $_POST);   // devis et tags de l'association uniquement
        $_SESSION['flash_asso_devis'] = ['type' => 'success', 'message' => 'Tags enregistrés.'];
    } catch (Throwable $e) {
        error_log('[ASSO QUOTE TAGS] ' . $e->getMessage());
        $_SESSION['flash_asso_devis'] = ['type' => 'error', 'message' => 'Les tags n’ont pas pu être enregistrés. Réessayez.'];
    }
    header('Location: /mon-asso-devis-edit?id=' . $quote_id); exit;
}

$form_page = ($action === 'edit' && !empty($_POST['quote_id'])) ? '/mon-asso-devis-edit?id=' . (int)$_POST['quote_id'] : '/mon-asso-devis-new';

$lines_post = $_POST['lines'] ?? [];
$lines = [];
foreach ($lines_post as $line) {
    $designation = trim((string)($line['designation'] ?? ''));
    if ($designation === '') continue;
    $lines[] = [
        'designation' => mb_substr($designation, 0, 500),
        'quantity' => round((float) str_replace(',', '.', $line['quantity'] ?? '1'), 2),        // 2 décimales, comme en base
        'unit_price_ht' => round((float) str_replace(',', '.', $line['unit_price_ht'] ?? '0'), 2),   // au centime
        'vat_rate' => isset($line['vat_rate']) && $line['vat_rate'] !== '' ? (float)$line['vat_rate'] : null,
    ];
}

if (empty($lines)) {
    $_SESSION['flash_asso_devis'] = ['type' => 'error', 'message' => 'Au moins une ligne est requise.'];
    header('Location: ' . $form_page); exit;
}

$client_data = [
    'id' => (int)($_POST['client_id'] ?? 0) ?: null,
    'client_type' => in_array($_POST['client_type'] ?? '', ['individual', 'company']) ? $_POST['client_type'] : 'company',
    'display_name' => trim((string)($_POST['display_name'] ?? '')),
    'legal_name' => trim((string)($_POST['legal_name'] ?? '')) ?: null,
    'contact_first_name' => trim((string)($_POST['contact_first_name'] ?? '')) ?: null,
    'contact_last_name' => trim((string)($_POST['contact_last_name'] ?? '')) ?: null,
    'email' => trim((string)($_POST['email'] ?? '')),
    'phone' => trim((string)($_POST['phone'] ?? '')) ?: null,
    'address_street' => trim((string)($_POST['address_street'] ?? '')) ?: null,
    'address_complement' => trim((string)($_POST['address_complement'] ?? '')) ?: null,
    'address_zip' => trim((string)($_POST['address_zip'] ?? '')) ?: null,
    'address_city' => trim((string)($_POST['address_city'] ?? '')) ?: null,
    'siren' => trim((string)($_POST['siren'] ?? '')) ?: null,
];

if (empty($client_data['display_name']) || empty($client_data['email'])) {
    $_SESSION['flash_asso_devis'] = ['type' => 'error', 'message' => 'Nom et email obligatoires.'];
    header('Location: ' . $form_page); exit;
}

$issued_at = !empty($_POST['issued_at']) ? $_POST['issued_at'] . ' ' . date('H:i:s') : date('Y-m-d H:i:s');
$validity_days = (int)($_POST['validity_days'] ?? 30);

try {
    if ($action === 'edit' && !empty($_POST['quote_id'])) {
        $quote_id = (int)$_POST['quote_id'];
        $stmt = $pdo->prepare("SELECT * FROM asso_quotes WHERE id = :id AND org_id = :org LIMIT 1");
        $stmt->execute([':id' => $quote_id, ':org' => $org_id]);
        $quote = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$quote) throw new RuntimeException('Devis introuvable.');

        // On ne peut pas éditer un devis signé
        if ($quote['status'] === 'signed' || $quote['status'] === 'converted') {
            throw new RuntimeException('Ce devis est ' . ($quote['status'] === 'signed' ? 'signé' : 'converti') . ' et ne peut plus être modifié.');
        }

        // Coordonnées du client saisies sur le devis : enregistrées sur sa fiche (client de
        // cette association uniquement), puis reprises dans le snapshot et le PDF ci-dessous.
        $client_id = ak_asso_client_from_form($pdo, $org_id, $client_data, (int)$user['id']);

        $total_ht = 0; $total_vat = 0; $total_ttc = 0;
        foreach ($lines as $line) {
            $c = ak_asso_line_compute($line['quantity'], $line['unit_price_ht'], $line['vat_rate']);
            $total_ht += $c['total_ht_cents'];
            $total_vat += $c['total_vat_cents'];
            $total_ttc += $c['total_ttc_cents'];
        }

        $expires_at = date('Y-m-d 23:59:59', strtotime($issued_at . ' +' . $validity_days . ' days'));

        // Le statut ne change pas à l'enregistrement : un brouillon reste brouillon (c'est l'envoi,
        // mon-asso-devis-send.php, qui le passe en « Envoyé »). Un devis expiré dont la validité
        // est prolongée redevient « Envoyé » (ou brouillon s'il n'est jamais parti).
        $status = (string)$quote['status'];
        if ($status === 'expired' && strtotime($expires_at) > time()) {
            $status = !empty($quote['sent_at']) ? 'sent' : 'draft';
        }

        // Le PDF lit client_snapshot : on le rafraîchit à chaque édition (cf. facture-save).
        $client_snap = null;
        try {
            $cs = $pdo->prepare("SELECT * FROM asso_clients WHERE id = ? AND org_id = ? LIMIT 1");
            $cs->execute([$client_id, $org_id]);
            $cfull = $cs->fetch(PDO::FETCH_ASSOC);
            if ($cfull) $client_snap = json_encode($cfull, JSON_UNESCAPED_UNICODE);
        } catch (Throwable $e) {}

        $pdo->prepare("UPDATE asso_quotes SET client_id=:cli, client_snapshot=COALESCE(:snap, client_snapshot), issued_at=:iss, expires_at=:exp, amount_ht_cents=:ht, amount_vat_cents=:vat, amount_ttc_cents=:ttc, status=:status, description=:desc, terms=:terms, internal_notes=:notes, updated_at=NOW() WHERE id=:id")
            ->execute([
                ':cli' => $client_id, ':snap' => $client_snap, ':iss' => $issued_at, ':exp' => $expires_at,
                ':ht' => $total_ht, ':vat' => $total_vat, ':ttc' => $total_ttc,
                ':status' => $status,
                ':desc' => trim((string)($_POST['description'] ?? '')) ?: null,
                ':terms' => trim((string)($_POST['terms'] ?? '')) ?: null,
                ':notes' => trim((string)($_POST['internal_notes'] ?? '')) ?: null,
                ':id' => $quote_id,
            ]);

        $pdo->prepare("DELETE FROM asso_quote_lines WHERE quote_id = :id")->execute([':id' => $quote_id]);
        $stmt = $pdo->prepare("INSERT INTO asso_quote_lines (quote_id, line_order, designation, quantity, unit_price_ht_cents, vat_rate, total_ht_cents, total_vat_cents, total_ttc_cents) VALUES (:q, :ord, :des, :qty, :pu, :vat, :tht, :tvat, :tttc)");
        foreach ($lines as $i => $line) {
            $c = ak_asso_line_compute($line['quantity'], $line['unit_price_ht'], $line['vat_rate']);
            $stmt->execute([
                ':q' => $quote_id, ':ord' => $i,
                ':des' => $line['designation'], ':qty' => $line['quantity'],
                ':pu' => (int) round($line['unit_price_ht'] * 100),
                ':vat' => $line['vat_rate'],
                ':tht' => $c['total_ht_cents'], ':tvat' => $c['total_vat_cents'], ':tttc' => $c['total_ttc_cents'],
            ]);
        }

        $pdf_path = ak_asso_quote_render_pdf($pdo, $quote_id);
        try { ak_tag_save_from_post($pdo, $org_id, 'quote', $quote_id, $_POST); }
        catch (Throwable $e) { error_log('[ASSO QUOTE TAGS] ' . $e->getMessage()); }

        $_SESSION['flash_asso_devis'] = ['type' => 'success', 'message' => $status === 'draft'
            ? 'Brouillon ' . $quote['quote_number'] . ' enregistré. Envoyez-le au client quand il est prêt.'
            : 'Devis ' . $quote['quote_number'] . ' modifié.', 'pdf_link' => $pdf_path];
        header('Location: /mon-asso-devis-edit?id=' . $quote_id); exit;

    } else {
        // Double clic / formulaire renvoyé : le devis existe déjà, on n'en crée pas un second
        $form_nonce = (string)($_POST['form_nonce'] ?? '');
        if ($done_id = ak_asso_form_nonce_used('quote_new', $form_nonce)) {
            $_SESSION['flash_asso_devis'] = ['type' => 'success', 'message' => 'Ce devis a déjà été créé : le second envoi du formulaire a été ignoré.'];
            header('Location: /mon-asso-devis-edit?id=' . $done_id); exit;
        }

        // Un devis créé n'est pas encore envoyé : il reste en brouillon jusqu'à l'envoi réel
        // (mon-asso-devis-send.php le passe alors en « Envoyé », avec date et destinataire).
        // Client choisi ou de même e-mail : sa fiche prend les coordonnées saisies.
        $client_data['id'] = ak_asso_client_from_form($pdo, $org_id, $client_data, (int)$user['id']);
        $result = ak_asso_quote_create($pdo, $org_id, (int)$user['id'], [
            'client' => $client_data, 'lines' => $lines,
            'issued_at' => $issued_at, 'validity_days' => $validity_days,
            'status' => 'draft',
            'description' => trim((string)($_POST['description'] ?? '')) ?: null,
            'terms' => trim((string)($_POST['terms'] ?? '')) ?: null,
            'internal_notes' => trim((string)($_POST['internal_notes'] ?? '')) ?: null,
        ]);
        ak_asso_form_nonce_consume('quote_new', $form_nonce, (int)$result['id']);
        try { ak_tag_save_from_post($pdo, $org_id, 'quote', (int)$result['id'], $_POST); }
        catch (Throwable $e) { error_log('[ASSO QUOTE TAGS] ' . $e->getMessage()); }

        if (!empty($_POST['save_draft'])) {
            $_SESSION['flash_asso_devis'] = ['type' => 'success', 'message' => 'Devis ' . $result['quote_number'] . ' enregistré en brouillon.', 'pdf_link' => $result['pdf_path']];
            header('Location: /mon-asso-devis'); exit;
        }
        // « Créer le devis » : on ouvre le devis, prêt à être envoyé au client
        $_SESSION['flash_asso_devis'] = [
            'type' => 'success',
            'message' => 'Devis ' . $result['quote_number'] . ' créé. Vérifiez-le, puis cliquez sur « Envoyer le devis par email » pour l’adresser au client.',
            'pdf_link' => $result['pdf_path'],
        ];
        header('Location: /mon-asso-devis-edit?id=' . (int)$result['id']); exit;
    }
} catch (Throwable $e) {
    error_log('[ASSO QUOTE SAVE] ' . $e->getMessage());
    $_SESSION['flash_asso_devis'] = ['type' => 'error', 'message' => 'Erreur : ' . $e->getMessage()];
    header('Location: ' . $form_page); exit;
}
