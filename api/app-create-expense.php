<?php
/**
 * api/app-create-expense.php — Ajout d'une facture/depense a un projet (natif).
 * Reproduit action-facture.php (action=add) : calcul HT/TVA/TTC, piece jointe
 * issue du scan IA (session), statut selon role, recalcul du budget. Retour JSON.
 * NE MODIFIE PAS le site.
 */
require __DIR__ . '/_app-write-boot.php';

$project_id = (int) ($input['project_id'] ?? 0);
if ($project_id <= 0) app_fail(422, 'invalid', 'Projet manquant.');

// Le projet appartient a l'org
$st = $pdo->prepare("SELECT p.id, p.referent_id FROM projects p JOIN folders f ON p.folder_id = f.id WHERE p.id = ? AND f.org_id = ? LIMIT 1");
$st->execute([$project_id, $org_id]);
$project = $st->fetch(PDO::FETCH_ASSOC);
if (!$project) app_fail(403, 'forbidden', 'Accès refusé à ce projet.');

$role = (string) ($user['role'] ?? '');
// Parité site (action-facture.php) : seuls admin/coordinateur auto-valident ; un référent crée en « pending »
// (sinon le budget engagé diverge entre l'app et le site).
$can_validate = in_array($role, ['admin', 'coordinator'], true);

$supplier = trim((string) ($input['supplier_name'] ?? ''));
$category = trim((string) ($input['category'] ?? ''));
$description = trim((string) ($input['description'] ?? ''));
$invoice_date = trim((string) ($input['invoice_date'] ?? ''));

$mode = in_array(($input['amount_mode'] ?? 'ttc'), ['ttc', 'ht', 'no_vat'], true) ? $input['amount_mode'] : 'ttc';
$vat_rate = (float) str_replace(',', '.', (string) ($input['vat_rate'] ?? '20'));
$norm = fn($v) => (float) str_replace([',', ' '], ['.', ''], (string) $v);

$amount_ttc = 0.0; $amount_ht = 0.0;
if ($mode === 'ht') {
    $amount_ht = $norm($input['amount_ht'] ?? '0');
    $amount_ttc = round($amount_ht * (1 + $vat_rate / 100), 2);
} elseif ($mode === 'no_vat') {
    $amount_ttc = $norm($input['amount_ttc'] ?? '0');
    $amount_ht = $amount_ttc;
    $vat_rate = 0;
} else {
    $amount_ttc = $norm($input['amount_ttc'] ?? '0');
    $amount_ht = round($amount_ttc / (1 + $vat_rate / 100), 2);
}

if ($supplier === '' || $amount_ttc <= 0 || $invoice_date === '') {
    app_fail(422, 'invalid', 'Fournisseur, montant et date sont obligatoires.');
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $invoice_date)) {
    app_fail(422, 'invalid', 'Date invalide (format AAAA-MM-JJ).');
}

// Piece jointe : fichier temporaire du scan IA (meme session que le scan)
$file_path = null;
$temp_file = (string) ($input['temp_file'] ?? '');
if ($temp_file !== '' && !empty($_SESSION['invoice_scan_result'])
    && ($_SESSION['invoice_scan_result']['temp_file'] ?? '') === $temp_file) {
    $temp_full = __DIR__ . '/../' . $temp_file;
    if (is_file($temp_full)) {
        $final_dir = __DIR__ . '/../uploads/projet_' . $project_id . '/factures';
        if (!is_dir($final_dir)) @mkdir($final_dir, 0755, true);
        $ext = pathinfo($temp_full, PATHINFO_EXTENSION) ?: 'jpg';
        $orig = $_SESSION['invoice_scan_result']['original_name'] ?? ('facture.' . $ext);
        $safe = preg_replace('/[^a-zA-Z0-9._\-]/', '_', pathinfo($orig, PATHINFO_FILENAME));
        $final_name = 'facture_' . substr($safe, 0, 40) . '_' . time() . '.' . $ext;
        if (@rename($temp_full, $final_dir . '/' . $final_name)) {
            $file_path = 'uploads/projet_' . $project_id . '/factures/' . $final_name;
        }
    }
    unset($_SESSION['invoice_scan_result']);
}

if ($can_validate) { $status = 'validated'; $validated_by = $uid; $validated_at = date('Y-m-d H:i:s'); }
else { $status = 'pending'; $validated_by = null; $validated_at = null; }

try {
    $stmt = $pdo->prepare("
        INSERT INTO project_invoices
            (project_id, uploaded_by, supplier_name, category, description,
             amount_ht, vat_rate, amount_ttc, invoice_date, file_path, status, validated_by, validated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $project_id, $uid, $supplier, $category ?: null, $description ?: null,
        $amount_ht, $vat_rate, $amount_ttc, $invoice_date, $file_path, $status, $validated_by, $validated_at,
    ]);

    // Recalcul du budget engage (somme des factures validees) — comme le site
    $sum = $pdo->prepare("SELECT COALESCE(SUM(amount_ttc),0) FROM project_invoices WHERE project_id = ? AND status = 'validated'");
    $sum->execute([$project_id]);
    $pdo->prepare("UPDATE projects SET budget_used = ? WHERE id = ?")->execute([(float) $sum->fetchColumn(), $project_id]);

    // Journal projet (best effort)
    try {
        $pdo->prepare("INSERT INTO project_activity_log (project_id, user_id, action_type, action_label, ip_address) VALUES (?, ?, 'invoice_added', ?, ?)")
            ->execute([$project_id, $uid, 'a ajouté une facture de ' . $supplier . ' (' . number_format($amount_ttc, 2, ',', ' ') . ' € TTC)', $_SERVER['REMOTE_ADDR'] ?? null]);
    } catch (Throwable $e) {}

    echo json_encode([
        'ok'      => true,
        'status'  => $status,
        'message' => $status === 'validated'
            ? 'Dépense ajoutée au projet et budget mis à jour.'
            : 'Dépense enregistrée — en attente de validation.',
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[app-create-expense] ' . $e->getMessage());
    app_fail(500, 'server', 'Impossible d\'enregistrer la dépense.');
}
