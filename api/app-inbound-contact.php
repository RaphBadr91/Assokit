<?php
/**
 * api/app-inbound-contact.php — Webhook HTTP de réception (option Cloudflare/Resend inbound).
 * Reçoit une réponse de prospect en JSON/form et l'ajoute au fil via ak_contact_store_inbound.
 * PUBLIC — sécurisé par le jeton dans l'adresse destinataire. NE MODIFIE PAS le site.
 * (Sur O2switch, le plus simple est le pipe cPanel : voir pipe-inbound-contact.php.)
 */
ob_start();
require_once __DIR__ . '/../config.php';
@require_once __DIR__ . '/../includes-layout.php';
ob_end_clean();

require_once __DIR__ . '/_app-contact-token.php';
header('Content-Type: application/json; charset=utf-8');

$raw = file_get_contents('php://input');
$data = json_decode((string) $raw, true);
if (!is_array($data)) $data = [];
$src = (isset($data['data']) && is_array($data['data'])) ? $data['data'] : $data;
$form = $_POST ?: [];

$recips = [];
$collect = function ($v) use (&$recips) {
    if (is_string($v)) $recips[] = $v;
    elseif (is_array($v)) foreach ($v as $x) {
        if (is_string($x)) $recips[] = $x;
        elseif (is_array($x)) $recips[] = ($x['address'] ?? $x['email'] ?? '');
    }
};
$collect($src['to'] ?? null); $collect($src['recipient'] ?? null); $collect($src['envelope']['to'] ?? null);
$collect($form['recipient'] ?? null); $collect($form['to'] ?? null);

$body = (string) ($src['stripped-text'] ?? $form['stripped-text'] ?? $src['text'] ?? $form['body-plain'] ?? $src['body'] ?? '');
if ($body === '' && !empty($src['html'])) $body = trim(strip_tags((string) $src['html']));
$from = $src['from'] ?? $form['sender'] ?? $form['from'] ?? '';
if (is_array($from)) $from = (string) ($from['address'] ?? $from['email'] ?? '');

$recipient = '';
foreach ($recips as $r) { if (ak_contact_parse_recipient((string) $r)) { $recipient = (string) $r; break; } }

try {
    $res = ak_contact_store_inbound($pdo, $recipient, (string) $from, $body);
} catch (Throwable $e) {
    error_log('[app-inbound-contact] ' . $e->getMessage());
    $res = ['ok' => false, 'msg' => 'server'];
}
if (!$res['ok'] && in_array($res['msg'], ['no-token', 'unknown-contact'], true)) http_response_code(202);
elseif (!$res['ok'] && $res['msg'] === 'bad-token') http_response_code(403);
elseif (!$res['ok']) http_response_code(500);
echo json_encode($res, JSON_UNESCAPED_UNICODE);
