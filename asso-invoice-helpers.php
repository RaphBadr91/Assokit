<?php
/**
 * ============================================================
 * ASSOKIT — asso-invoice-helpers.php
 * Helpers du module Factures Asso (Sous-pack 1)
 * ============================================================
 */

if (!function_exists('ak_asso_slug_from_name')) {

/**
 * Génère un slug ASCII pur depuis un nom d'asso.
 * "Latitude91" → "LATITUDE91"
 * "Mon Asso & Cie" → "MON-ASSO-CIE"
 */
function ak_asso_slug_from_name(string $name): string
{
    // Translittération (é → e, à → a, etc.)
    $slug = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name) ?: $name;
    $slug = strtoupper($slug);
    $slug = preg_replace('/[^A-Z0-9]+/', '-', $slug);
    $slug = trim($slug, '-');
    if ($slug === '') $slug = 'ASSO';
    return mb_substr($slug, 0, 30);
}

/**
 * Garantit qu'une asso a un slug. Si vide, génère depuis le nom.
 * Garantit unicité (suffixe -2, -3 si conflit).
 */
function ak_asso_ensure_slug(PDO $pdo, int $org_id): string
{
    $stmt = $pdo->prepare("SELECT slug, name FROM organizations WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $org_id]);
    $row = $stmt->fetch();
    if (!$row) throw new RuntimeException('Asso introuvable id=' . $org_id);

    if (!empty($row['slug'])) return $row['slug'];

    $base = ak_asso_slug_from_name($row['name']);
    $slug = $base;
    $i = 2;
    while (true) {
        $check = $pdo->prepare("SELECT COUNT(*) FROM organizations WHERE slug = :s AND id != :id");
        $check->execute([':s' => $slug, ':id' => $org_id]);
        if ((int)$check->fetchColumn() === 0) break;
        $slug = $base . '-' . $i;
        $i++;
    }

    $upd = $pdo->prepare("UPDATE organizations SET slug = :s WHERE id = :id");
    $upd->execute([':s' => $slug, ':id' => $org_id]);
    return $slug;
}

/**
 * Génère le prochain numéro de facture pour une asso.
 * Format : SLUG-2026-000001
 */
function ak_asso_invoice_next_number(PDO $pdo, int $org_id): string
{
    $year = (int) date('Y');
    $slug = ak_asso_ensure_slug($pdo, $org_id);

    // Initialise / récupère la séquence
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("SELECT next_sequence, current_year FROM org_invoice_settings WHERE org_id = :id FOR UPDATE");
        $stmt->execute([':id' => $org_id]);
        $row = $stmt->fetch();

        if (!$row) {
            // Création des paramètres pour cette asso
            $pdo->prepare("INSERT INTO org_invoice_settings (org_id, next_sequence, current_year) VALUES (:id, 2, :y)")
                ->execute([':id' => $org_id, ':y' => $year]);
            $current = 1;
        } elseif ((int)$row['current_year'] !== $year) {
            // Nouvelle année → reset séquence à 1
            $pdo->prepare("UPDATE org_invoice_settings SET next_sequence = 2, current_year = :y WHERE org_id = :id")
                ->execute([':y' => $year, ':id' => $org_id]);
            $current = 1;
        } else {
            $current = (int)$row['next_sequence'];
            $pdo->prepare("UPDATE org_invoice_settings SET next_sequence = next_sequence + 1 WHERE org_id = :id")
                ->execute([':id' => $org_id]);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    return sprintf('%s-%d-%06d', $slug, $year, $current);
}

/**
 * Garantit qu'une asso a une ligne dans org_invoice_settings.
 */
function ak_asso_invoice_settings(PDO $pdo, int $org_id): array
{
    $stmt = $pdo->prepare("SELECT * FROM org_invoice_settings WHERE org_id = :id LIMIT 1");
    $stmt->execute([':id' => $org_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) return $row;

    $year = (int) date('Y');
    $pdo->prepare("INSERT INTO org_invoice_settings (org_id, next_sequence, current_year) VALUES (:id, 1, :y)")
        ->execute([':id' => $org_id, ':y' => $year]);

    $stmt->execute([':id' => $org_id]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

/**
 * Format cents → "49,99 €"
 */
function ak_asso_fmt_cents(int $cents): string
{
    return number_format($cents / 100, 2, ',', ' ') . ' €';
}

/**
 * Statut → label lisible
 */
function ak_asso_invoice_status_label(string $status): string
{
    return [
        'draft' => 'Brouillon',
        'pending' => 'En attente',
        'paid' => 'Payée',
        'overdue' => 'En retard',
        'cancelled' => 'Annulée',
        'refunded' => 'Remboursée',
    ][$status] ?? $status;
}

/**
 * Cherche un client existant par email. Sinon le crée et le retourne.
 * Utilisé pour la sauvegarde auto à la création d'une facture.
 */
function ak_asso_find_or_create_client(PDO $pdo, int $org_id, array $data): int
{
    $email = trim((string)($data['email'] ?? ''));
    if ($email === '') throw new RuntimeException('Email client obligatoire');

    // Chercher client existant
    $stmt = $pdo->prepare("SELECT id FROM asso_clients WHERE org_id = :org AND email = :em AND deleted_at IS NULL LIMIT 1");
    $stmt->execute([':org' => $org_id, ':em' => $email]);
    $existing = $stmt->fetchColumn();
    if ($existing) return (int)$existing;

    // Créer
    $stmt = $pdo->prepare("
        INSERT INTO asso_clients (
            org_id, client_type, display_name, legal_name,
            contact_first_name, contact_last_name,
            email, phone,
            address_street, address_complement, address_zip, address_city, address_country,
            siren, siret, vat_number,
            internal_notes, created_by_user_id
        ) VALUES (
            :org, :ctype, :dname, :lname,
            :fn, :ln,
            :em, :phone,
            :str, :compl, :zip, :city, :country,
            :siren, :siret, :vat,
            :notes, :uid
        )
    ");
    $stmt->execute([
        ':org'   => $org_id,
        ':ctype' => $data['client_type'] ?? 'company',
        ':dname' => mb_substr((string)($data['display_name'] ?? ''), 0, 200),
        ':lname' => $data['legal_name'] ?? null,
        ':fn'    => $data['contact_first_name'] ?? null,
        ':ln'    => $data['contact_last_name'] ?? null,
        ':em'    => $email,
        ':phone' => $data['phone'] ?? null,
        ':str'   => $data['address_street'] ?? null,
        ':compl' => $data['address_complement'] ?? null,
        ':zip'   => $data['address_zip'] ?? null,
        ':city'  => $data['address_city'] ?? null,
        ':country' => $data['address_country'] ?? 'France',
        ':siren' => $data['siren'] ?? null,
        ':siret' => $data['siret'] ?? null,
        ':vat'   => $data['vat_number'] ?? null,
        ':notes' => $data['internal_notes'] ?? null,
        ':uid'   => $data['created_by_user_id'] ?? null,
    ]);

    return (int) $pdo->lastInsertId();
}

/**
 * Calcul des montants d'une ligne.
 * Reçoit : quantity, unit_price_ht (€), vat_rate (%)
 * Retourne : total_ht_cents, total_vat_cents, total_ttc_cents
 */
function ak_asso_line_compute(float $quantity, float $unit_price_ht, ?float $vat_rate): array
{
    $total_ht_cents = (int) round($quantity * $unit_price_ht * 100);
    if ($vat_rate !== null && $vat_rate > 0) {
        $total_vat_cents = (int) round($total_ht_cents * $vat_rate / 100);
    } else {
        $total_vat_cents = 0;
    }
    $total_ttc_cents = $total_ht_cents + $total_vat_cents;
    return [
        'total_ht_cents'  => $total_ht_cents,
        'total_vat_cents' => $total_vat_cents,
        'total_ttc_cents' => $total_ttc_cents,
    ];
}

/**
 * Crée une facture asso complète (avec lignes + sauvegarde auto client).
 * Retourne ['id' => int, 'invoice_number' => string, 'pdf_path' => string]
 */
function ak_asso_invoice_create(PDO $pdo, int $org_id, int $created_by_user_id, array $data): array
{
    if (empty($data['client'])) throw new RuntimeException('Données client manquantes');
    if (empty($data['lines'])) throw new RuntimeException('Au moins une ligne est obligatoire');

    // 1) Trouve/crée client
    $client_data = $data['client'];
    $client_data['created_by_user_id'] = $created_by_user_id;
    $client_id = $client_data['id'] ?? null;
    if (empty($client_id)) {
        $client_id = ak_asso_find_or_create_client($pdo, $org_id, $client_data);
    }

    // 2) Calculer les totaux
    $total_ht = 0; $total_vat = 0; $total_ttc = 0;
    foreach ($data['lines'] as $line) {
        $computed = ak_asso_line_compute(
            (float)($line['quantity'] ?? 1),
            (float)($line['unit_price_ht'] ?? 0),
            isset($line['vat_rate']) && $line['vat_rate'] !== '' ? (float)$line['vat_rate'] : null
        );
        $total_ht += $computed['total_ht_cents'];
        $total_vat += $computed['total_vat_cents'];
        $total_ttc += $computed['total_ttc_cents'];
    }

    // 3) Numérotation
    $invoice_number = ak_asso_invoice_next_number($pdo, $org_id);
    $year = (int) date('Y');
    preg_match('/-(\d+)$/', $invoice_number, $m);
    $sequence = (int)($m[1] ?? 0);

    // 4) UUID public
    $public_uuid = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000,
        mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
    );

    // 5) Snapshots
    $stmt = $pdo->prepare("SELECT * FROM organizations WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $org_id]);
    $org_data = $stmt->fetch(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("SELECT * FROM asso_clients WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $client_id]);
    $client_full = $stmt->fetch(PDO::FETCH_ASSOC);

    $emitter_snap = json_encode($org_data, JSON_UNESCAPED_UNICODE);
    $client_snap  = json_encode($client_full, JSON_UNESCAPED_UNICODE);

    // 6) Dates
    $issued_at = $data['issued_at'] ?? date('Y-m-d H:i:s');
    $due_days = (int)($data['due_days'] ?? 30);
    $due_at = $data['due_at'] ?? date('Y-m-d 23:59:59', strtotime($issued_at . ' +' . $due_days . ' days'));

    // 7) INSERT facture
    $stmt = $pdo->prepare("
        INSERT INTO asso_invoices (
            org_id, client_id, public_uuid,
            invoice_number, invoice_year, invoice_sequence,
            issued_at, due_at,
            amount_ht_cents, amount_vat_cents, amount_ttc_cents, currency,
            status,
            emitter_snapshot, client_snapshot,
            description, internal_notes,
            created_by_user_id
        ) VALUES (
            :org, :cli, :uuid,
            :num, :year, :seq,
            :issued, :due,
            :ht, :vat, :ttc, 'EUR',
            :status,
            :em_snap, :cl_snap,
            :desc, :notes,
            :uid
        )
    ");
    $stmt->execute([
        ':org'    => $org_id,
        ':cli'    => $client_id,
        ':uuid'   => $public_uuid,
        ':num'    => $invoice_number,
        ':year'   => $year,
        ':seq'    => $sequence,
        ':issued' => $issued_at,
        ':due'    => $due_at,
        ':ht'     => $total_ht,
        ':vat'    => $total_vat,
        ':ttc'    => $total_ttc,
        ':status' => $data['status'] ?? 'pending',
        ':em_snap'=> $emitter_snap,
        ':cl_snap'=> $client_snap,
        ':desc'   => $data['description'] ?? null,
        ':notes'  => $data['internal_notes'] ?? null,
        ':uid'    => $created_by_user_id,
    ]);

    $invoice_id = (int) $pdo->lastInsertId();

    // 8) INSERT lignes
    $stmt = $pdo->prepare("
        INSERT INTO asso_invoice_lines (
            invoice_id, line_order, designation, quantity, unit_price_ht_cents, vat_rate,
            total_ht_cents, total_vat_cents, total_ttc_cents
        ) VALUES (
            :inv, :ord, :des, :qty, :pu, :vat,
            :tht, :tvat, :tttc
        )
    ");
    foreach ($data['lines'] as $i => $line) {
        $computed = ak_asso_line_compute(
            (float)($line['quantity'] ?? 1),
            (float)($line['unit_price_ht'] ?? 0),
            isset($line['vat_rate']) && $line['vat_rate'] !== '' ? (float)$line['vat_rate'] : null
        );
        $stmt->execute([
            ':inv'  => $invoice_id,
            ':ord'  => $i,
            ':des'  => mb_substr((string)($line['designation'] ?? ''), 0, 500),
            ':qty'  => (float)($line['quantity'] ?? 1),
            ':pu'   => (int) round((float)($line['unit_price_ht'] ?? 0) * 100),
            ':vat'  => isset($line['vat_rate']) && $line['vat_rate'] !== '' ? (float)$line['vat_rate'] : null,
            ':tht'  => $computed['total_ht_cents'],
            ':tvat' => $computed['total_vat_cents'],
            ':tttc' => $computed['total_ttc_cents'],
        ]);
    }

    // 9) Génération PDF
    $pdf_path = ak_asso_invoice_render_pdf($pdo, $invoice_id);

    return [
        'id' => $invoice_id,
        'invoice_number' => $invoice_number,
        'pdf_path' => $pdf_path,
    ];
}

/**
 * Régénère le PDF d'une facture asso.
 */
function ak_asso_invoice_render_pdf(PDO $pdo, int $invoice_id): string
{
    $stmt = $pdo->prepare("SELECT * FROM asso_invoices WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $invoice_id]);
    $invoice = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$invoice) throw new RuntimeException('Facture introuvable');

    $stmt = $pdo->prepare("SELECT * FROM asso_invoice_lines WHERE invoice_id = :id ORDER BY line_order");
    $stmt->execute([':id' => $invoice_id]);
    $lines = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $emitter = !empty($invoice['emitter_snapshot']) ? json_decode($invoice['emitter_snapshot'], true) : [];
    $client  = !empty($invoice['client_snapshot'])  ? json_decode($invoice['client_snapshot'], true)  : [];

    // Récup paramètres asso pour IBAN/BIC
    $stmt = $pdo->prepare("SELECT * FROM org_invoice_settings WHERE org_id = :id");
    $stmt->execute([':id' => $invoice['org_id']]);
    $settings = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    // Render HTML
    ob_start();
    $pdf_invoice = $invoice;
    $pdf_lines   = $lines;
    $pdf_emitter = $emitter;
    $pdf_client  = $client;
    $pdf_settings = $settings;
    include __DIR__ . '/asso-invoice-pdf-template.php';
    $html = ob_get_clean();

    // Dossier upload
    $dir = __DIR__ . '/uploads/asso-invoices';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    // Nom de fichier NON DEVINABLE : on ajoute un suffixe issu de l'UUID de la facture,
    // pour empêcher l'énumération inter-organisations des PDF (confidentialité / RGPD / PDP).
    $num_safe = preg_replace('/[^A-Za-z0-9\-]/', '-', (string) $invoice['invoice_number']);
    $uuid_safe = substr(preg_replace('/[^a-f0-9]/', '', (string) ($invoice['public_uuid'] ?? '')), 0, 20);
    $base_name = $num_safe . ($uuid_safe !== '' ? '-' . $uuid_safe : '');
    $filename = $base_name . '.pdf';
    $full_path = $dir . '/' . $filename;

    // Génération du XML Factur-X (norme DGFiP) si possible.
    require_once __DIR__ . '/facturx-helpers.php';
    $facturx_xml = null;
    if (AK_FACTURX_ENABLED) {
        try { $facturx_xml = ak_facturx_build_xml($invoice, $emitter, $client); }
        catch (Throwable $e) { error_log('[FACTURX] build error : ' . $e->getMessage()); }
    }

    // Fabrique le PDF (Factur-X/PDF-A3 si XML dispo, sinon PDF classique) avec repli.
    $render_pdf = function (bool $with_facturx) use ($html, $invoice, $full_path, $facturx_xml): bool {
        if (!class_exists('\Mpdf\Mpdf')) return false;
        $mpdf = new \Mpdf\Mpdf([
            'mode' => 'utf-8', 'format' => 'A4',
            'margin_left' => 15, 'margin_right' => 15, 'margin_top' => 15, 'margin_bottom' => 15,
            'tempDir' => sys_get_temp_dir(),
        ]);
        if ($with_facturx && $facturx_xml) {
            // PDF/A-3 requis pour embarquer le XML Factur-X
            $mpdf->PDFA = true;
            $mpdf->PDFAauto = true;
            if (property_exists($mpdf, 'PDFAversion')) $mpdf->PDFAversion = '3-B';
        }
        $mpdf->SetTitle('Facture ' . $invoice['invoice_number']);
        $mpdf->WriteHTML($html);
        if ($with_facturx && $facturx_xml) {
            $mpdf->SetAssociatedFiles([[
                'name' => 'factur-x.xml',
                'mime' => 'text/xml',
                'description' => 'Facture électronique Factur-X',
                'AFRelationship' => 'Data',
                'content' => $facturx_xml,
            ]]);
            if (method_exists($mpdf, 'SetAdditionalXmpRdf')) $mpdf->SetAdditionalXmpRdf(ak_facturx_xmp());
        }
        $mpdf->Output($full_path, \Mpdf\Output\Destination::FILE);
        return true;
    };

    $pdf_generated = false;
    $facturx_embedded = false;
    $autoload = __DIR__ . '/vendor/autoload.php';
    if (file_exists($autoload)) {
        require_once $autoload;
        if (class_exists('\Mpdf\Mpdf')) {
            // 1) Tentative Factur-X (PDF/A-3 + XML embarqué)
            if ($facturx_xml) {
                try { $pdf_generated = $render_pdf(true); $facturx_embedded = $pdf_generated; }
                catch (Throwable $e) { error_log('[FACTURX] embed error, repli PDF classique : ' . $e->getMessage()); }
            }
            // 2) Repli : PDF classique si Factur-X indisponible ou en échec
            if (!$pdf_generated) {
                try { $pdf_generated = $render_pdf(false); }
                catch (Throwable $e) { error_log('[ASSO INVOICE PDF] mPDF error : ' . $e->getMessage()); }
            }
        }
    }

    if (!$pdf_generated) {
        // Fallback HTML (même nom non devinable)
        $filename = $base_name . '.html';
        $html_path = $dir . '/' . $filename;
        file_put_contents($html_path, $html);
        $full_path = $html_path;
    }

    $relative_path = '/uploads/asso-invoices/' . $filename;
    $pdo->prepare("UPDATE asso_invoices SET pdf_path = :p, pdf_generated_at = NOW() WHERE id = :id")
        ->execute([':p' => $relative_path, ':id' => $invoice_id]);

    return $relative_path;
}

/**
 * Stats d'un client : CA total, nb factures, retards, etc.
 */
function ak_asso_client_stats(PDO $pdo, int $client_id): array
{
    $stmt = $pdo->prepare("
        SELECT
            COUNT(*) AS nb_invoices,
            SUM(amount_ttc_cents) AS total_ttc_cents,
            SUM(CASE WHEN status = 'paid' THEN amount_ttc_cents ELSE 0 END) AS paid_ttc_cents,
            SUM(CASE WHEN status = 'pending' THEN amount_ttc_cents ELSE 0 END) AS pending_ttc_cents,
            SUM(CASE WHEN status = 'overdue' OR (status='pending' AND due_at < NOW()) THEN amount_ttc_cents ELSE 0 END) AS overdue_ttc_cents,
            SUM(CASE WHEN status = 'paid' THEN 1 ELSE 0 END) AS nb_paid,
            SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS nb_pending,
            SUM(CASE WHEN status = 'overdue' OR (status='pending' AND due_at < NOW()) THEN 1 ELSE 0 END) AS nb_overdue,
            MAX(issued_at) AS last_invoice_at
        FROM asso_invoices
        WHERE client_id = :cid
    ");
    $stmt->execute([':cid' => $client_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return [
        'nb_invoices'        => (int)($row['nb_invoices'] ?? 0),
        'total_ttc_cents'    => (int)($row['total_ttc_cents'] ?? 0),
        'paid_ttc_cents'     => (int)($row['paid_ttc_cents'] ?? 0),
        'pending_ttc_cents'  => (int)($row['pending_ttc_cents'] ?? 0),
        'overdue_ttc_cents'  => (int)($row['overdue_ttc_cents'] ?? 0),
        'nb_paid'            => (int)($row['nb_paid'] ?? 0),
        'nb_pending'         => (int)($row['nb_pending'] ?? 0),
        'nb_overdue'         => (int)($row['nb_overdue'] ?? 0),
        'last_invoice_at'    => $row['last_invoice_at'] ?? null,
    ];
}

} // fin if (!function_exists)
