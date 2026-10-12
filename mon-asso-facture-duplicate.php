<?php
/**
 * mon-asso-facture-duplicate.php
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/asso-invoice-helpers.php';

require_login();
$user = current_user();
if (empty($user['org_id'])) { http_response_code(403); die('Aucune asso.'); }
$org_id = (int)$user['org_id'];

// Même règle d'accès que la fiche facture qui porte le bouton « Dupliquer »
require_once __DIR__ . '/finance-permissions.php';
if (!user_can_access_billing($user)) { http_response_code(403); die('Accès refusé.'); }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: /mon-asso-factures-client'); exit; }
if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
    http_response_code(419); exit('CSRF.');
}

$invoice_id = (int)($_POST['invoice_id'] ?? 0);
if ($invoice_id <= 0) { header('Location: /mon-asso-factures-client'); exit; }

$src = null;
try {
    $stmt = $pdo->prepare("SELECT * FROM asso_invoices WHERE id = :id AND org_id = :org LIMIT 1");
    $stmt->execute([':id' => $invoice_id, ':org' => $org_id]);
    $src = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$src) throw new RuntimeException('Facture introuvable.');
    // Un avoir ne se duplique pas (il annule une facture précise) : dupliquez la facture d'origine
    if (ak_asso_is_credit_note($src)) throw new RuntimeException('un avoir ne peut pas être dupliqué ; dupliquez la facture d’origine.');

    $stmt = $pdo->prepare("SELECT * FROM asso_invoice_lines WHERE invoice_id = :id ORDER BY line_order");
    $stmt->execute([':id' => $invoice_id]);
    $src_lines = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("SELECT * FROM asso_clients WHERE id = :id AND org_id = :org LIMIT 1");
    $stmt->execute([':id' => $src['client_id'], ':org' => $org_id]);
    $client = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$client) throw new RuntimeException('le client de cette facture n’existe plus.');

    $lines = [];
    foreach ($src_lines as $l) {
        $lines[] = [
            'designation' => $l['designation'],
            'quantity' => (float)$l['quantity'],
            'unit_price_ht' => round($l['unit_price_ht_cents'] / 100, 2),
            'vat_rate' => $l['vat_rate'],
        ];
    }

    $new_invoice = ak_asso_invoice_create($pdo, $org_id, (int)$user['id'], [
        'client' => array_merge($client, ['id' => $client['id']]),
        'lines' => $lines,
        // La note (imprimée sur la facture) est reprise telle quelle ; l'origine de la copie
        // reste une note interne, jamais visible du client.
        'description' => trim((string)($src['description'] ?? '')) ?: null,
        'internal_notes' => 'Copie de la facture ' . $src['invoice_number'],
        'status' => 'draft',
    ]);

    $_SESSION['flash_asso_factures'] = [
        'type' => 'success',
        'message' => '📋 Facture dupliquée : ' . $new_invoice['invoice_number'] . ' (brouillon).',
        'pdf_link' => $new_invoice['pdf_path'],
    ];

    header('Location: /mon-asso-facture-edit?id=' . $new_invoice['id']);
    exit;

} catch (Throwable $e) {
    error_log('[FACT DUP] ' . $e->getMessage());
    $_SESSION['flash_asso_factures'] = ['type' => 'error', 'message' => 'Erreur duplication : ' . $e->getMessage()];
    // Retour sur la facture d'origine, qui affiche le message
    header('Location: ' . ($src ? '/mon-asso-facture-edit?id=' . $invoice_id : '/mon-asso-factures-client'));
    exit;
}
