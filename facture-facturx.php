<?php
/**
 * facture-facturx.php — Téléchargement du XML Factur-X (CII EN 16931) d'une facture.
 * Garde : login + can('manage_finances') + org + jeton CSRF.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/facturx-engine.php';
if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }
require_once __DIR__ . '/includes-layout.php';
require_login();
$user = current_user();
$org_id = (int)($user['org_id'] ?? 0);
if ($org_id <= 0 || !can('manage_finances')) { http_response_code(403); exit('Accès refusé.'); }

if (!isset($_GET['t']) || !hash_equals($_SESSION['csrf_token'] ?? '', (string)$_GET['t'])) {
    http_response_code(403); exit('Jeton invalide.');
}
$id = (int)($_GET['id'] ?? 0);
$data = facturx_build_data($pdo, $org_id, $id);
if (!$data) { http_response_code(404); exit('Facture introuvable.'); }

$xml = facturx_cii_xml($data);
$num = preg_replace('/[^A-Za-z0-9_-]+/', '-', (string)$data['inv']['invoice_number']);
header('Content-Type: application/xml; charset=UTF-8');
header('Content-Disposition: attachment; filename="facturx-'.$num.'.xml"');
header('Content-Length: '.strlen($xml));
header('X-Content-Type-Options: nosniff');
echo $xml;
