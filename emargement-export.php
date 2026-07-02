<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes-layout.php';
require_once __DIR__ . '/includes-attendance.php';
require_login();
$user = current_user(); $org_id = (int)$user['org_id'];
if (!in_array($user['role'], ['admin','coordinator'], true)) { http_response_code(403); die('Réservé.'); }

$id = (int)($_GET['id'] ?? 0);
$sess = att_load($pdo, $id, $org_id);
if (!$sess) { http_response_code(404); die('Introuvable.'); }
$records = att_load_records($pdo, $id);

$filename = 'emargement-' . preg_replace('/[^a-z0-9]+/i', '-', $sess['title']) . '-' . date('Ymd', strtotime($sess['starts_at'])) . '.csv';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

$out = fopen('php://output', 'w');
fputs($out, "\xEF\xBB\xBF"); // BOM UTF-8 pour Excel
fputcsv($out, ['Nom complet', 'Email', 'Téléphone', 'Date émargement', 'IP'], ';');
foreach ($records as $r) {
    fputcsv($out, [$r['full_name'], $r['email'] ?? '', $r['phone'] ?? '', date('d/m/Y H:i:s', strtotime($r['signed_at'])), $r['ip'] ?? ''], ';');
}
fclose($out);
exit;
