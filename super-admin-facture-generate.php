<?php
/**
 * super-admin-facture-generate.php
 * Backend de génération de facture
 * v3 : Reçoit TTC + taux TVA, calcule HT et TVA automatiquement
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/company-helpers.php';
require_once __DIR__ . '/org-billing-helpers.php';
require_once __DIR__ . '/invoice-helpers.php';
require_once __DIR__ . '/trial-helpers.php';   // ak_db_update (colonnes selon le schéma)

require_login();
$user = current_user();

// Vérif droits
$is_sa = false;
$is_founder = false;
try {
    $stmt = $pdo->prepare("SELECT is_super_admin, is_founder FROM users WHERE id = :id");
    $stmt->execute([':id' => (int)$user['id']]);
    $row = $stmt->fetch();
    $is_sa = $row && (int)$row['is_super_admin'] === 1;
    $is_founder = $row && (int)$row['is_founder'] === 1;
} catch (Throwable $e) {}

if (!$is_sa && !$is_founder) {
    http_response_code(403);
    die('Accès refusé.');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /super-admin-factures.php');
    exit;
}

// CSRF
$csrf_post = $_POST['csrf_token'] ?? '';
$csrf_sess = $_SESSION['csrf_token'] ?? '';
if (empty($csrf_sess) || !hash_equals($csrf_sess, $csrf_post)) {
    http_response_code(419);
    exit('Session expirée.');
}

$org_id = (int)($_POST['org_id'] ?? 0);
$amount_ttc = (float) str_replace(',', '.', $_POST['amount_ttc'] ?? '0');
$vat_rate_input = (float) str_replace(',', '.', $_POST['vat_rate'] ?? '0');

if ($org_id <= 0 || $amount_ttc <= 0) {
    $_SESSION['flash_factures'] = ['type' => 'error', 'message' => 'Paramètres invalides.'];
    header('Location: /super-admin-factures.php');
    exit;
}

// TVA cohérente avec la société (company_settings.vat_subject) : la facture porte soit une TVA, soit la
// mention « TVA non applicable, art. 293 B du CGI », jamais les deux (le PDF les affichait ensemble)
$vat_subject = (int) ak_company('vat_subject') === 1;
if (!$vat_subject && $vat_rate_input > 0) {
    $_SESSION['flash_factures'] = ['type' => 'error', 'message' => 'La société n’est pas assujettie à la TVA (art. 293 B du CGI) : la facture est émise sans TVA. Si elle l’est devenue, mettez à jour les paramètres de la société.'];
    header('Location: /super-admin-factures.php');
    exit;
}
if ($vat_subject && $vat_rate_input <= 0) {
    $_SESSION['flash_factures'] = ['type' => 'error', 'message' => 'La société est assujettie à la TVA : choisissez le taux applicable.'];
    header('Location: /super-admin-factures.php');
    exit;
}

// Calcul auto TTC → HT + TVA
$amount_ttc_cents = (int) round($amount_ttc * 100);
if ($vat_rate_input > 0) {
    $amount_ht_cents = (int) round($amount_ttc_cents / (1 + $vat_rate_input / 100));
    $amount_vat_cents = $amount_ttc_cents - $amount_ht_cents;
    $vat_rate_db = $vat_rate_input;
} else {
    $amount_ht_cents = $amount_ttc_cents;
    $amount_vat_cents = 0;
    $vat_rate_db = null;
}

try {
    $result = ak_create_invoice_v2($pdo, $org_id, [
        'amount_ht_cents'  => $amount_ht_cents,
        'amount_vat_cents' => $amount_vat_cents,
        'amount_ttc_cents' => $amount_ttc_cents,
        'vat_rate'         => $vat_rate_db,
        // « (test) » seulement sur un spécimen (société non immatriculée), jamais sur une facture légale
        'description'      => 'Abonnement Assokit' . (ak_invoice_is_test_mode() ? ' (test)' : ''),
        'plan_code'        => 'association',
        'period_start'     => date('Y-m-d'),
        'period_end'       => date('Y-m-d', strtotime('+1 month')),
        'due_days'         => 30,
        'created_by_user_id' => (int)$user['id'],
    ]);

    $_SESSION['flash_factures'] = [
        'type' => 'success',
        'message' => 'Facture ' . $result['invoice_number'] . ' générée avec succès.',
        'pdf_link' => $result['pdf_path'],
    ];
} catch (Throwable $e) {
    error_log('[FACTURE GEN] ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    $_SESSION['flash_factures'] = [
        'type' => 'error',
        'message' => 'Erreur : ' . $e->getMessage(),
    ];
}

header('Location: /super-admin-factures.php');
exit;


/**
 * Helper local : version v2 de ak_create_invoice qui accepte
 * les montants pré-calculés (TTC + HT + TVA).
 */
function ak_create_invoice_v2(PDO $pdo, int $org_id, array $opts): array
{
    $client = get_org_billing_info($pdo, $org_id);
    if (!$client) {
        throw new RuntimeException('Association introuvable (id=' . $org_id . ')');
    }

    $emitter = ak_company();

    $amount_ht_cents  = (int)($opts['amount_ht_cents'] ?? 0);
    $amount_vat_cents = (int)($opts['amount_vat_cents'] ?? 0);
    $amount_ttc_cents = (int)($opts['amount_ttc_cents'] ?? ($amount_ht_cents + $amount_vat_cents));
    $vat_rate         = $opts['vat_rate'] ?? null;

    // Une seule numérotation AK-AAAA-NNNNNN : la séquence est d'abord recalée sur le plus grand numéro
    // déjà émis (anciennes factures du cron de renouvellement, numérotées sans elle), jamais un doublon
    ak_invoice_sequence_catch_up($pdo);
    $invoice_number = ak_generate_invoice_number($pdo);
    $year = (int) date('Y');
    preg_match('/-(\d+)$/', $invoice_number, $m);
    $sequence = (int)($m[1] ?? 0);

    $issued_at = date('Y-m-d H:i:s');
    $due_days = (int)($opts['due_days'] ?? 30);
    $due_at = date('Y-m-d H:i:s', strtotime('+' . $due_days . ' days'));

    $is_test = ak_invoice_is_test_mode() ? 1 : 0;

    $emitter_snap = json_encode($emitter, JSON_UNESCAPED_UNICODE);
    $client_snap  = json_encode($client,  JSON_UNESCAPED_UNICODE);

    $stmt = $pdo->prepare("
        INSERT INTO invoices (
            org_id, user_id, invoice_number, invoice_year, invoice_sequence,
            issued_at, due_at,
            amount_ht_cents, vat_rate, amount_vat_cents, amount_ttc_cents, currency,
            status, period_start, period_end,
            description, plan_code,
            emitter_snapshot, client_snapshot,
            is_test_mode, created_by_user_id
        ) VALUES (
            :org_id, :user_id, :inv_num, :year, :seq,
            :issued_at, :due_at,
            :ht, :vat_rate, :vat, :ttc, 'EUR',
            'pending', :pstart, :pend,
            :desc, :plan,
            :em_snap, :cl_snap,
            :test, :uid
        )
    ");
    $stmt->execute([
        ':org_id'   => $org_id,
        ':user_id'  => $opts['created_by_user_id'] ?? null,
        ':inv_num'  => $invoice_number,
        ':year'     => $year,
        ':seq'      => $sequence,
        ':issued_at' => $issued_at,
        ':due_at'   => $due_at,
        ':ht'       => $amount_ht_cents,
        ':vat_rate' => $vat_rate,
        ':vat'      => $amount_vat_cents,
        ':ttc'      => $amount_ttc_cents,
        ':pstart'   => $opts['period_start'] ?? null,
        ':pend'     => $opts['period_end']   ?? null,
        ':desc'     => $opts['description']  ?? 'Abonnement Assokit',
        ':plan'     => $opts['plan_code']    ?? null,
        ':em_snap'  => $emitter_snap,
        ':cl_snap'  => $client_snap,
        ':test'     => $is_test,
        ':uid'      => $opts['created_by_user_id'] ?? null,
    ]);

    $invoice_id = (int) $pdo->lastInsertId();
    // Mêmes colonnes de montant que les factures du cron de renouvellement (amount_cents = TTC), pour
    // tous les lecteurs de la table (colonne absente : rien n'est écrit)
    if (function_exists('ak_db_update')) ak_db_update($pdo, 'invoices', $invoice_id, ['amount_cents' => $amount_ttc_cents]);
    $pdf_path = ak_render_invoice_pdf($pdo, $invoice_id);

    return [
        'id' => $invoice_id,
        'invoice_number' => $invoice_number,
        'pdf_path' => $pdf_path,
    ];
}


/**
 * Recale invoice_sequences sur le plus grand numéro AK-AAAA-NNNNNN déjà présent dans invoices pour
 * l'année (ne fait que remonter la séquence). Même règle que cron_invoice_sequence_sync (cron-includes.php)
 * et la migration 2026-10-11-L11-abonnement.sql : sans elle, un numéro déjà émis par l'ancien cron
 * pouvait être redonné ici.
 */
function ak_invoice_sequence_catch_up(PDO $pdo): void
{
    $year = (int) date('Y');
    $stmt = $pdo->prepare("SELECT MAX(CAST(SUBSTRING_INDEX(invoice_number, '-', -1) AS UNSIGNED)) FROM invoices WHERE invoice_number LIKE :prefix");
    $stmt->execute([':prefix' => 'AK-' . $year . '-%']);
    $max = (int) $stmt->fetchColumn();
    if ($max <= 0) return;
    $pdo->prepare("INSERT INTO invoice_sequences (year, next_sequence) VALUES (:y, :n)
                   ON DUPLICATE KEY UPDATE next_sequence = GREATEST(next_sequence, VALUES(next_sequence))")
        ->execute([':y' => $year, ':n' => $max + 1]);
}
