<?php
/**
 * mon-asso-devis-duplicate.php
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/asso-invoice-helpers.php';
require_once __DIR__ . '/asso-quote-helpers.php';

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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: /mon-asso-devis'); exit; }
if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
    http_response_code(419); exit('CSRF.');
}

$quote_id = (int)($_POST['quote_id'] ?? 0);
if ($quote_id <= 0) { header('Location: /mon-asso-devis'); exit; }

try {
    $stmt = $pdo->prepare("SELECT * FROM asso_quotes WHERE id = :id AND org_id = :org LIMIT 1");
    $stmt->execute([':id' => $quote_id, ':org' => $org_id]);
    $src = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$src) throw new RuntimeException('Devis introuvable.');

    $stmt = $pdo->prepare("SELECT * FROM asso_quote_lines WHERE quote_id = :id ORDER BY line_order");
    $stmt->execute([':id' => $quote_id]);
    $src_lines = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("SELECT * FROM asso_clients WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $src['client_id']]);
    $client = $stmt->fetch(PDO::FETCH_ASSOC);

    $lines = [];
    foreach ($src_lines as $l) {
        $lines[] = [
            'designation' => $l['designation'],
            'quantity' => (float)$l['quantity'],
            'unit_price_ht' => round($l['unit_price_ht_cents'] / 100, 2),
            'vat_rate' => $l['vat_rate'],
        ];
    }

    $new_quote = ak_asso_quote_create($pdo, $org_id, (int)$user['id'], [
        'client' => array_merge($client, ['id' => $client['id']]),
        'lines' => $lines,
        'description' => $src['description'] ? '[Copie] ' . $src['description'] : '[Copie de ' . $src['quote_number'] . ']',
        'terms' => $src['terms'],
        'validity_days' => max(1, (int)floor((strtotime($src['expires_at']) - strtotime($src['issued_at'])) / 86400)),
        'status' => 'draft',
    ]);

    $_SESSION['flash_asso_devis'] = [
        'type' => 'success',
        'message' => '📋 Devis dupliqué : ' . $new_quote['quote_number'] . ' (brouillon).',
        'pdf_link' => $new_quote['pdf_path'],
    ];

    header('Location: /mon-asso-devis-edit?id=' . $new_quote['id']);
    exit;

} catch (Throwable $e) {
    error_log('[DEVIS DUP] ' . $e->getMessage());
    $_SESSION['flash_asso_devis'] = ['type' => 'error', 'message' => 'Erreur duplication : ' . $e->getMessage()];
    header('Location: /mon-asso-devis');
    exit;
}
