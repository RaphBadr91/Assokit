<?php
/**
 * asso-quote-helpers.php
 * Helpers du module Devis
 */

if (!function_exists('ak_asso_quote_next_number')) {

function ak_asso_quote_next_number(PDO $pdo, int $org_id): string
{
    $year = (int) date('Y');
    $slug = ak_asso_ensure_slug($pdo, $org_id);

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("SELECT quote_next_sequence, quote_current_year FROM org_invoice_settings WHERE org_id = :id FOR UPDATE");
        $stmt->execute([':id' => $org_id]);
        $row = $stmt->fetch();

        if (!$row) {
            $pdo->prepare("INSERT INTO org_invoice_settings (org_id, quote_next_sequence, quote_current_year) VALUES (:id, 2, :y)")
                ->execute([':id' => $org_id, ':y' => $year]);
            $current = 1;
        } elseif ((int)$row['quote_current_year'] !== $year) {
            $pdo->prepare("UPDATE org_invoice_settings SET quote_next_sequence = 2, quote_current_year = :y WHERE org_id = :id")
                ->execute([':y' => $year, ':id' => $org_id]);
            $current = 1;
        } else {
            $current = (int)$row['quote_next_sequence'];
            $pdo->prepare("UPDATE org_invoice_settings SET quote_next_sequence = quote_next_sequence + 1 WHERE org_id = :id")
                ->execute([':id' => $org_id]);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    return sprintf('DEVIS-%s-%d-%06d', $slug, $year, $current);
}

function ak_asso_quote_status_label(string $status): string
{
    return [
        'draft' => 'Brouillon',
        'sent' => 'Envoyé',
        'signed' => 'Signé ✓',
        'refused' => 'Refusé',
        'expired' => 'Expiré',
        'converted' => 'Converti en facture',
        'cancelled' => 'Annulé',
    ][$status] ?? $status;
}

function ak_asso_quote_create(PDO $pdo, int $org_id, int $created_by_user_id, array $data): array
{
    if (empty($data['client'])) throw new RuntimeException('Données client manquantes');
    if (empty($data['lines'])) throw new RuntimeException('Au moins une ligne est obligatoire');

    $client_data = $data['client'];
    $client_data['created_by_user_id'] = $created_by_user_id;
    $client_id = $client_data['id'] ?? null;
    if (empty($client_id)) {
        $client_id = ak_asso_find_or_create_client($pdo, $org_id, $client_data);
    } else {
        // Sécurité (BOLA cross-tenant) : un client fourni par id DOIT appartenir à l'organisation.
        $chk = $pdo->prepare("SELECT id FROM asso_clients WHERE id = ? AND org_id = ? AND deleted_at IS NULL");
        $chk->execute([(int)$client_id, $org_id]);
        if (!$chk->fetchColumn()) throw new RuntimeException('Client introuvable pour cette organisation.');
    }

    $total_ht = 0; $total_vat = 0; $total_ttc = 0;
    foreach ($data['lines'] as $line) {
        $c = ak_asso_line_compute(
            (float)($line['quantity'] ?? 1),
            (float)($line['unit_price_ht'] ?? 0),
            isset($line['vat_rate']) && $line['vat_rate'] !== '' ? (float)$line['vat_rate'] : null
        );
        $total_ht += $c['total_ht_cents'];
        $total_vat += $c['total_vat_cents'];
        $total_ttc += $c['total_ttc_cents'];
    }

    $quote_number = ak_asso_quote_next_number($pdo, $org_id);
    $year = (int) date('Y');
    preg_match('/-(\d+)$/', $quote_number, $m);
    $sequence = (int)($m[1] ?? 0);

    // UUID public v4 via CSPRNG (mt_rand est prédictible -> lien public devinable).
    $ub = random_bytes(16);
    $ub[6] = chr((ord($ub[6]) & 0x0f) | 0x40);
    $ub[8] = chr((ord($ub[8]) & 0x3f) | 0x80);
    $public_uuid = vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($ub), 4));

    $stmt = $pdo->prepare("SELECT * FROM organizations WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $org_id]);
    $org_data = $stmt->fetch(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("SELECT * FROM asso_clients WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $client_id]);
    $client_full = $stmt->fetch(PDO::FETCH_ASSOC);

    $issued_at = $data['issued_at'] ?? date('Y-m-d H:i:s');
    $validity_days = (int)($data['validity_days'] ?? 30);
    $expires_at = $data['expires_at'] ?? date('Y-m-d 23:59:59', strtotime($issued_at . ' +' . $validity_days . ' days'));

    $stmt = $pdo->prepare("
        INSERT INTO asso_quotes (
            org_id, client_id, public_uuid,
            quote_number, quote_year, quote_sequence,
            issued_at, expires_at,
            amount_ht_cents, amount_vat_cents, amount_ttc_cents, currency,
            status,
            emitter_snapshot, client_snapshot,
            description, terms, internal_notes,
            created_by_user_id
        ) VALUES (
            :org, :cli, :uuid, :num, :year, :seq, :issued, :exp,
            :ht, :vat, :ttc, 'EUR', :status,
            :em_snap, :cl_snap,
            :desc, :terms, :notes, :uid
        )
    ");
    $stmt->execute([
        ':org' => $org_id, ':cli' => $client_id, ':uuid' => $public_uuid,
        ':num' => $quote_number, ':year' => $year, ':seq' => $sequence,
        ':issued' => $issued_at, ':exp' => $expires_at,
        ':ht' => $total_ht, ':vat' => $total_vat, ':ttc' => $total_ttc,
        ':status' => $data['status'] ?? 'draft',
        ':em_snap' => json_encode($org_data, JSON_UNESCAPED_UNICODE),
        ':cl_snap' => json_encode($client_full, JSON_UNESCAPED_UNICODE),
        ':desc' => $data['description'] ?? null,
        ':terms' => $data['terms'] ?? null,
        ':notes' => $data['internal_notes'] ?? null,
        ':uid' => $created_by_user_id,
    ]);

    $quote_id = (int) $pdo->lastInsertId();

    $stmt = $pdo->prepare("
        INSERT INTO asso_quote_lines (
            quote_id, line_order, designation, quantity, unit_price_ht_cents, vat_rate,
            total_ht_cents, total_vat_cents, total_ttc_cents
        ) VALUES (:q, :ord, :des, :qty, :pu, :vat, :tht, :tvat, :tttc)
    ");
    foreach ($data['lines'] as $i => $line) {
        $c = ak_asso_line_compute(
            (float)($line['quantity'] ?? 1),
            (float)($line['unit_price_ht'] ?? 0),
            isset($line['vat_rate']) && $line['vat_rate'] !== '' ? (float)$line['vat_rate'] : null
        );
        $stmt->execute([
            ':q' => $quote_id, ':ord' => $i,
            ':des' => mb_substr((string)($line['designation'] ?? ''), 0, 500),
            ':qty' => (float)($line['quantity'] ?? 1),
            ':pu' => (int) round((float)($line['unit_price_ht'] ?? 0) * 100),
            ':vat' => isset($line['vat_rate']) && $line['vat_rate'] !== '' ? (float)$line['vat_rate'] : null,
            ':tht' => $c['total_ht_cents'], ':tvat' => $c['total_vat_cents'], ':tttc' => $c['total_ttc_cents'],
        ]);
    }

    $pdf_path = ak_asso_quote_render_pdf($pdo, $quote_id);

    return ['id' => $quote_id, 'quote_number' => $quote_number, 'pdf_path' => $pdf_path, 'public_uuid' => $public_uuid];
}

function ak_asso_quote_render_pdf(PDO $pdo, int $quote_id): string
{
    $stmt = $pdo->prepare("SELECT * FROM asso_quotes WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $quote_id]);
    $quote = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$quote) throw new RuntimeException('Devis introuvable');

    $stmt = $pdo->prepare("SELECT * FROM asso_quote_lines WHERE quote_id = :id ORDER BY line_order");
    $stmt->execute([':id' => $quote_id]);
    $lines = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $emitter = !empty($quote['emitter_snapshot']) ? json_decode($quote['emitter_snapshot'], true) : [];
    $client = !empty($quote['client_snapshot']) ? json_decode($quote['client_snapshot'], true) : [];

    $stmt = $pdo->prepare("SELECT * FROM org_invoice_settings WHERE org_id = :id");
    $stmt->execute([':id' => $quote['org_id']]);
    $settings = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    ob_start();
    $pdf_quote = $quote;
    $pdf_lines = $lines;
    $pdf_emitter = $emitter;
    $pdf_client = $client;
    $pdf_settings = $settings;
    include __DIR__ . '/asso-quote-pdf-template.php';
    $html = ob_get_clean();

    $dir = __DIR__ . '/uploads/asso-quotes';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    // Nom NON DEVINABLE (suffixe UUID) : empêche l'énumération inter-organisations.
    $num_safe = preg_replace('/[^A-Za-z0-9\-]/', '-', (string) $quote['quote_number']);
    $uuid_safe = substr(preg_replace('/[^a-f0-9]/', '', (string) ($quote['public_uuid'] ?? '')), 0, 20);
    $base_name = $num_safe . ($uuid_safe !== '' ? '-' . $uuid_safe : '');
    $filename = $base_name . '.pdf';
    $full_path = $dir . '/' . $filename;

    $pdf_generated = false;
    $autoload = __DIR__ . '/vendor/autoload.php';
    if (file_exists($autoload)) {
        require_once $autoload;
        if (class_exists('\Mpdf\Mpdf')) {
            try {
                $mpdf = new \Mpdf\Mpdf([
                    'mode' => 'utf-8', 'format' => 'A4',
                    'margin_left' => 15, 'margin_right' => 15,
                    'margin_top' => 15, 'margin_bottom' => 15,
                    'tempDir' => sys_get_temp_dir(),
                ]);
                $mpdf->SetTitle('Devis ' . $quote['quote_number']);
                $mpdf->WriteHTML($html);
                $mpdf->Output($full_path, \Mpdf\Output\Destination::FILE);
                $pdf_generated = true;
            } catch (Throwable $e) {
                error_log('[ASSO QUOTE PDF] ' . $e->getMessage());
            }
        }
    }

    if (!$pdf_generated) {
        $filename = $base_name . '.html';
        $html_path = $dir . '/' . $filename;
        file_put_contents($html_path, $html);
        $full_path = $html_path;
    }

    $relative = '/uploads/asso-quotes/' . $filename;
    $pdo->prepare("UPDATE asso_quotes SET pdf_path = :p, pdf_generated_at = NOW() WHERE id = :id")
        ->execute([':p' => $relative, ':id' => $quote_id]);

    return $relative;
}

/**
 * Convertit un devis SIGNÉ en facture
 */
function ak_asso_quote_convert_to_invoice(PDO $pdo, int $quote_id, int $user_id): array
{
    $stmt = $pdo->prepare("SELECT * FROM asso_quotes WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $quote_id]);
    $quote = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$quote) throw new RuntimeException('Devis introuvable');
    if ($quote['status'] !== 'signed') throw new RuntimeException('Le devis doit être signé pour être converti.');
    if (!empty($quote['converted_to_invoice_id'])) throw new RuntimeException('Devis déjà converti.');

    $stmt = $pdo->prepare("SELECT * FROM asso_quote_lines WHERE quote_id = :id ORDER BY line_order");
    $stmt->execute([':id' => $quote_id]);
    $q_lines = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Préparer lignes facture
    $invoice_lines = [];
    foreach ($q_lines as $l) {
        $invoice_lines[] = [
            'designation' => $l['designation'],
            'quantity' => (float)$l['quantity'],
            'unit_price_ht' => round($l['unit_price_ht_cents'] / 100, 2),
            'vat_rate' => $l['vat_rate'],
        ];
    }

    // Récup client
    $stmt = $pdo->prepare("SELECT * FROM asso_clients WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $quote['client_id']]);
    $client = $stmt->fetch(PDO::FETCH_ASSOC);

    // Créer facture
    $invoice = ak_asso_invoice_create($pdo, (int)$quote['org_id'], $user_id, [
        'client' => array_merge($client, ['id' => $client['id']]),
        'lines' => $invoice_lines,
        'description' => "Facture suite au devis " . $quote['quote_number'] . " (signé le " . date('d/m/Y', strtotime($quote['signed_at'])) . ")",
        'status' => 'pending',
    ]);

    // Marquer devis comme converti
    $pdo->prepare("UPDATE asso_quotes SET status='converted', converted_to_invoice_id = :iid, converted_at = NOW(), converted_by_user_id = :uid WHERE id = :id")
        ->execute([':iid' => $invoice['id'], ':uid' => $user_id, ':id' => $quote_id]);

    return $invoice;
}

}
