<?php
/**
 * api/app-create-grant.php — Création d'une demande de subvention depuis l'app (natif).
 * Reproduit fidèlement action-subvention.php (action=create).
 * Renvoie du JSON. NE MODIFIE PAS le site.
 *
 * Rôle requis : admin ou coordinateur (parité web).
 */
require __DIR__ . '/_app-write-boot.php';
@require_once __DIR__ . '/../includes-grants.php';

if (!in_array($user['role'] ?? '', ['admin', 'coordinator'], true)) {
    app_fail(403, 'role', 'Rôle insuffisant pour créer une subvention.');
}

$name   = trim((string) ($input['name'] ?? ''));
$funder = trim((string) ($input['funder'] ?? ''));
if ($name === '')   app_fail(422, 'invalid', 'Le nom de la demande est obligatoire.');
if ($funder === '') app_fail(422, 'invalid', 'Le financeur est obligatoire.');

// Normalise les décimales à virgule (clavier FR).
$num = static fn($v) => (float) str_replace([' ', ','], ['', '.'], (string) $v);

$valid_types    = ['etat', 'region', 'departement', 'commune', 'epci', 'caf', 'fondation', 'entreprise', 'europe', 'autre'];
$valid_statuses = ['draft', 'submitted', 'in_review', 'granted', 'rejected', 'reported', 'archived'];
$funder_type = in_array(($input['funder_type'] ?? ''), $valid_types, true) ? $input['funder_type'] : 'autre';
$status      = in_array(($input['status'] ?? ''), $valid_statuses, true) ? $input['status'] : 'draft';

$description  = trim((string) ($input['description'] ?? '')) ?: null;
$notes        = trim((string) ($input['notes'] ?? '')) ?: null;
$amount_req   = (isset($input['amount_requested']) && $input['amount_requested'] !== '' && $input['amount_requested'] !== null) ? $num($input['amount_requested']) : null;
$amount_gr    = (isset($input['amount_granted']) && $input['amount_granted'] !== '' && $input['amount_granted'] !== null) ? $num($input['amount_granted']) : null;
$currency     = (($input['currency'] ?? 'EUR') === 'CHF') ? 'CHF' : 'EUR';

// Dates : YYYY-MM-DD (sinon null)
$dt = static function ($v) {
    $v = trim((string) $v);
    return ($v !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) ? $v : null;
};
$deadline_apply = $dt($input['deadline_apply'] ?? '');

$project_id = (int) ($input['project_id'] ?? 0);
if ($project_id > 0) {
    $check = $pdo->prepare("SELECT p.id FROM projects p JOIN folders f ON p.folder_id = f.id WHERE p.id = ? AND f.org_id = ?");
    $check->execute([$project_id, $org_id]);
    if (!$check->fetch()) $project_id = 0;
}

$contact_name  = trim((string) ($input['contact_name'] ?? '')) ?: null;
$contact_email = trim((string) ($input['contact_email'] ?? '')) ?: null;
$contact_phone = trim((string) ($input['contact_phone'] ?? '')) ?: null;

try {
    $stmt = $pdo->prepare("INSERT INTO grants
        (org_id, project_id, name, funder, funder_type, description, amount_requested, amount_granted, currency, status,
         deadline_apply, contact_name, contact_email, contact_phone, notes, created_by)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
    $stmt->execute([
        $org_id, $project_id ?: null, $name, $funder, $funder_type, $description, $amount_req, $amount_gr, $currency, $status,
        $deadline_apply, $contact_name, $contact_email, $contact_phone, $notes, $uid,
    ]);
    $grant_id = (int) $pdo->lastInsertId();

    if (function_exists('gr_log')) {
        try { gr_log($pdo, $grant_id, $uid, 'create', '🆕 Demande créée : ' . $name); } catch (Throwable $e) {}
    }

    echo json_encode([
        'ok'      => true,
        'id'      => $grant_id,
        'message' => 'Demande de subvention « ' . $name .' » créée.',
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[app-create-grant] ' . $e->getMessage());
    app_fail(500, 'server', 'Impossible de créer la subvention.');
}
