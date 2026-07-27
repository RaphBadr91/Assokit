<?php
/**
 * ============================================================
 * ASSOKIT — invoice-helpers.php
 * Fonctions de création et génération de factures
 * ============================================================
 */

if (!function_exists('ak_generate_invoice_number')) {

/**
 * Génère le prochain numéro de facture au format AK-2026-000001.
 * Utilise une séquence dédiée par année (stockée en BDD).
 */
function ak_generate_invoice_number(PDO $pdo, ?int $year = null): string
{
    if ($year === null) $year = (int) date('Y');

    // Récupérer ou initialiser la séquence pour cette année
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("SELECT next_sequence FROM invoice_sequences WHERE year = :y FOR UPDATE");
        $stmt->execute([':y' => $year]);
        $seq = $stmt->fetchColumn();

        if ($seq === false) {
            // Initialisation si année inexistante
            $pdo->prepare("INSERT INTO invoice_sequences (year, next_sequence) VALUES (:y, 2)")
                ->execute([':y' => $year]);
            $current = 1;
        } else {
            $current = (int) $seq;
            $pdo->prepare("UPDATE invoice_sequences SET next_sequence = next_sequence + 1 WHERE year = :y")
                ->execute([':y' => $year]);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    return sprintf('AK-%d-%06d', $year, $current);
}

/**
 * Détermine si l'émetteur est en mode test.
 * = SIREN vide OU = "000 000 000" OU = "000000000".
 */
function ak_invoice_is_test_mode(): bool
{
    $siren = ak_company('siren') ?? '';
    $siren = preg_replace('/\s+/', '', (string)$siren);
    if ($siren === '' || $siren === '000000000') return true;
    return false;
}

/**
 * Crée une facture en BDD et génère le PDF.
 *
 * @param PDO $pdo
 * @param int $org_id
 * @param array $opts [
 *   'amount_ht_cents' => int (requis),
 *   'vat_rate' => float|null,
 *   'description' => string,
 *   'plan_code' => string,
 *   'period_start' => 'YYYY-MM-DD',
 *   'period_end' => 'YYYY-MM-DD',
 *   'due_days' => int (def 30),
 *   'created_by_user_id' => int|null,
 * ]
 * @return array ['id' => int, 'invoice_number' => string, 'pdf_path' => string]
 */
function ak_create_invoice(PDO $pdo, int $org_id, array $opts): array
{
    // Infos client (asso)
    $client = get_org_billing_info($pdo, $org_id);
    if (!$client) {
        throw new RuntimeException('Association introuvable (id=' . $org_id . ')');
    }

    // Infos émetteur (société)
    $emitter = ak_company();

    // Montants
    $amount_ht_cents = (int)($opts['amount_ht_cents'] ?? 0);
    $vat_rate = isset($opts['vat_rate']) ? (float)$opts['vat_rate'] : null;
    if ($vat_rate === null || $vat_rate <= 0) {
        $amount_vat_cents = 0;
    } else {
        $amount_vat_cents = (int) round($amount_ht_cents * $vat_rate / 100);
    }
    $amount_ttc_cents = $amount_ht_cents + $amount_vat_cents;

    // Numérotation
    $invoice_number = ak_generate_invoice_number($pdo);
    $year = (int) date('Y');
    preg_match('/-(\d+)$/', $invoice_number, $m);
    $sequence = (int)($m[1] ?? 0);

    // Dates
    $issued_at = date('Y-m-d H:i:s');
    $due_days = (int)($opts['due_days'] ?? 30);
    $due_at = date('Y-m-d H:i:s', strtotime('+' . $due_days . ' days'));

    $is_test = ak_invoice_is_test_mode() ? 1 : 0;

    // Snapshot
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
        ':org_id'  => $org_id,
        ':user_id' => $opts['created_by_user_id'] ?? null,
        ':inv_num' => $invoice_number,
        ':year'    => $year,
        ':seq'     => $sequence,
        ':issued_at' => $issued_at,
        ':due_at'  => $due_at,
        ':ht'      => $amount_ht_cents,
        ':vat_rate' => $vat_rate,
        ':vat'     => $amount_vat_cents,
        ':ttc'     => $amount_ttc_cents,
        ':pstart'  => $opts['period_start'] ?? null,
        ':pend'    => $opts['period_end']   ?? null,
        ':desc'    => $opts['description']  ?? 'Abonnement Assokit',
        ':plan'    => $opts['plan_code']    ?? null,
        ':em_snap' => $emitter_snap,
        ':cl_snap' => $client_snap,
        ':test'    => $is_test,
        ':uid'     => $opts['created_by_user_id'] ?? null,
    ]);

    $invoice_id = (int) $pdo->lastInsertId();

    // Générer le PDF
    $pdf_path = ak_render_invoice_pdf($pdo, $invoice_id);

    return [
        'id' => $invoice_id,
        'invoice_number' => $invoice_number,
        'pdf_path' => $pdf_path,
    ];
}

/**
 * Génère le PDF d'une facture existante et l'enregistre sur disque.
 * Retourne le chemin relatif.
 */
function ak_render_invoice_pdf(PDO $pdo, int $invoice_id): string
{
    $stmt = $pdo->prepare("SELECT * FROM invoices WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $invoice_id]);
    $invoice = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$invoice) throw new RuntimeException('Facture introuvable');

    // Infos à jour si snapshot vide (ou forcer snapshot pour cohérence)
    $emitter = !empty($invoice['emitter_snapshot'])
        ? json_decode($invoice['emitter_snapshot'], true)
        : ak_company();
    $client  = !empty($invoice['client_snapshot'])
        ? json_decode($invoice['client_snapshot'], true)
        : get_org_billing_info($pdo, (int)$invoice['org_id']);

    // Générer le HTML
    ob_start();
    $pdf_emitter = $emitter;
    $pdf_client  = $client;
    $pdf_invoice = $invoice;
    include __DIR__ . '/invoice-pdf-template.php';
    $html = ob_get_clean();

    // Destination
    $dir = __DIR__ . '/uploads/invoices';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    // Nom NON DEVINABLE (suffixe UUID/aléatoire) : empêche l'énumération des factures.
    $num_safe = preg_replace('/[^A-Za-z0-9\-]/', '-', (string) $invoice['invoice_number']);
    $uuid_safe = substr(preg_replace('/[^a-f0-9]/', '', (string) ($invoice['public_uuid'] ?? '')), 0, 20);
    if ($uuid_safe === '') $uuid_safe = bin2hex(random_bytes(8));
    $base_name = $num_safe . '-' . $uuid_safe;
    $filename = $base_name . '.pdf';
    $full_path = $dir . '/' . $filename;

    // Utiliser mPDF si dispo, sinon fallback HTML + wkhtmltopdf, sinon juste HTML
    $pdf_generated = false;
    $mpdf_error = null;

    $autoloader_paths = [
        __DIR__ . '/vendor/autoload.php',
        __DIR__ . '/mpdf/vendor/autoload.php',
    ];
    foreach ($autoloader_paths as $autoload) {
        if (file_exists($autoload)) {
            require_once $autoload;
            if (class_exists('\Mpdf\Mpdf')) {
                try {
                    $mpdf = new \Mpdf\Mpdf([
                        'mode' => 'utf-8',
                        'format' => 'A4',
                        'margin_left' => 15,
                        'margin_right' => 15,
                        'margin_top' => 15,
                        'margin_bottom' => 15,
                        'tempDir' => sys_get_temp_dir(),
                    ]);
                    $mpdf->SetTitle('Facture ' . $invoice['invoice_number']);

                    // Watermark si mode test
                    if ((int)$invoice['is_test_mode'] === 1) {
                        $mpdf->SetWatermarkText('SPECIMEN - MODE TEST');
                        $mpdf->showWatermarkText = true;
                        $mpdf->watermarkTextAlpha = 0.12;
                    }

                    $mpdf->WriteHTML($html);
                    $mpdf->Output($full_path, \Mpdf\Output\Destination::FILE);
                    $pdf_generated = true;
                    break;
                } catch (Throwable $e) {
                    $mpdf_error = $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine();
                    error_log('[INVOICE PDF] mPDF error : ' . $mpdf_error);
                }
            }
        }
    }

    // Fallback : sauvegarder juste le HTML si mPDF indisponible
    if (!$pdf_generated) {
        $filename = $base_name . '.html';
        $html_path = $dir . '/' . $filename;
        file_put_contents($html_path, $html);
        $full_path = $html_path;
        $reason = $mpdf_error ? ('Erreur mPDF: ' . $mpdf_error) : 'mPDF non disponible';
        error_log('[INVOICE PDF] HTML fallback - ' . $reason);
    }

    // Mettre à jour le chemin en BDD
    $relative_path = '/uploads/invoices/' . $filename;
    $stmt = $pdo->prepare("UPDATE invoices SET pdf_path = :p, pdf_generated_at = NOW() WHERE id = :id");
    $stmt->execute([':p' => $relative_path, ':id' => $invoice_id]);

    return $relative_path;
}

/**
 * Formate un montant en centimes pour affichage : 4999 → "49,99 €"
 */
function ak_format_cents_eur(int $cents): string
{
    return number_format($cents / 100, 2, ',', ' ') . ' €';
}

/**
 * Label lisible d'un statut de facture.
 */
function ak_invoice_status_label(string $status): string
{
    return [
        'draft'     => 'Brouillon',
        'pending'   => 'En attente',
        'paid'      => 'Payée',
        'overdue'   => 'En retard',
        'cancelled' => 'Annulée',
        'refunded'  => 'Remboursée',
    ][$status] ?? $status;
}

} // fin if (!function_exists)
