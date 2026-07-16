<?php
/**
 * api/app-founder-settings.php — Paramètres société (company_settings, singleton id=1).
 * GET  → charge les paramètres. POST → enregistre (colonnes whitelistées existantes).
 * Réservé Fondateur/Super Admin. Porte la logique de super-admin-parametres-societe. NE MODIFIE PAS le site.
 */
ob_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes-layout.php';
ob_end_clean();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function set_fail($c, $e, $m = null) { http_response_code($c); echo json_encode(['ok' => false, 'error' => $e, 'message' => $m], JSON_UNESCAPED_UNICODE); exit; }

if (empty($_SESSION['user_id'])) set_fail(401, 'auth');
$user = function_exists('current_user') ? current_user() : null;
require_once __DIR__ . '/_app-founder.php';
$is_sa = app_is_founder($pdo, $user) || !empty($user['is_super_admin']) || (($user['role'] ?? '') === 'super_admin');
if (!$is_sa) set_fail(403, 'forbidden');

// Champs éditables depuis l'app (whitelist)
$FIELDS = ['legal_name', 'legal_form', 'siren', 'siret', 'rcs_city', 'rcs_number', 'ape_code',
    'vat_subject', 'vat_number', 'vat_rate', 'address_street', 'address_complement', 'address_zip',
    'address_city', 'email_billing', 'email_support', 'email_legal', 'phone', 'website',
    'iban', 'bic', 'bank_name', 'legal_rep_first_name', 'legal_rep_last_name', 'legal_rep_role'];

function existing_cols(PDO $pdo, array $fields): array {
    $ok = [];
    try {
        $in = implode(',', array_fill(0, count($fields), '?'));
        $st = $pdo->prepare("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='company_settings' AND COLUMN_NAME IN ($in)");
        $st->execute($fields);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $c) $ok[] = $c;
    } catch (Throwable $e) {}
    return $ok;
}

try {
    $cols = existing_cols($pdo, $FIELDS);
    if (empty($cols)) set_fail(500, 'no_table', 'Table des paramètres introuvable.');

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        $c = [];
        try { $c = $pdo->query("SELECT * FROM company_settings WHERE id = 1 LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: []; } catch (Throwable $e) {}
        $out = [];
        foreach ($FIELDS as $f) {
            if ($f === 'vat_subject') $out[$f] = !empty($c[$f]);
            else $out[$f] = isset($c[$f]) ? (string) $c[$f] : '';
        }
        echo json_encode(['ok' => true, 'settings' => $out, 'editable' => $cols], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // POST
    $raw = file_get_contents('php://input');
    $input = json_decode((string) $raw, true);
    if (!is_array($input)) $input = [];
    $token = (string) ($input['csrf'] ?? '');
    if (empty($_SESSION['csrf_token']) || !hash_equals((string) $_SESSION['csrf_token'], $token)) set_fail(403, 'csrf', 'Session expirée.');

    // S'assure de l'existence de la ligne singleton
    try {
        $n = (int) $pdo->query("SELECT COUNT(*) FROM company_settings WHERE id = 1")->fetchColumn();
        if ($n === 0) $pdo->exec("INSERT INTO company_settings (id) VALUES (1)");
    } catch (Throwable $e) {}

    $sets = []; $params = [];
    foreach ($cols as $f) {
        if (!array_key_exists($f, $input)) continue;
        $v = $input[$f];
        if ($f === 'vat_subject') { $sets[] = "$f = ?"; $params[] = !empty($v) ? 1 : 0; }
        elseif ($f === 'vat_rate') { $sets[] = "$f = ?"; $params[] = ($v === '' || $v === null) ? null : (float) $v; }
        else { $sets[] = "$f = ?"; $params[] = is_string($v) ? mb_substr(trim($v), 0, 255) : $v; }
    }
    if (empty($sets)) set_fail(400, 'nothing', 'Rien à enregistrer.');
    $params[] = 1;
    $pdo->prepare("UPDATE company_settings SET " . implode(', ', $sets) . " WHERE id = ?")->execute($params);
    echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[app-founder-settings] ' . $e->getMessage());
    set_fail(500, 'server', 'Enregistrement impossible.');
}
