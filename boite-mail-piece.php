<?php
/**
 * ============================================================
 * ASSOKIT — Boîte mail : pièce jointe et version d'origine
 * ============================================================
 * GET ?m=<id message>&a=<n° pièce>  → téléchargement de la pièce jointe
 * GET ?m=<id message>&html=1        → e-mail HTML d'origine, affiché dans
 *                                     une iframe « sandbox » (aucun script)
 * Mêmes droits que la conversation.
 * ============================================================
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/mail-helpers.php';
require_login();
$user = current_user();

$mid = (int)($_GET['m'] ?? 0);
$s = $pdo->prepare("SELECT * FROM mail_messages WHERE id = ? AND org_id = ?");
$s->execute([$mid, (int)$user['org_id']]);
$msg = $s->fetch(PDO::FETCH_ASSOC);
if (!$msg || !mail_thread_for_user($pdo, $user, (int)$msg['thread_id'])) { http_response_code(404); exit('Introuvable.'); }

header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
header('Referrer-Policy: no-referrer');

if (!empty($_GET['html'])) {
    // Contenu étranger : isolé par la politique de sécurité (sandbox,
    // aucun script, aucun formulaire), liens ouverts dans un nouvel onglet.
    header("Content-Security-Policy: sandbox allow-popups allow-popups-to-escape-sandbox; default-src 'none'; img-src https: data: cid:; style-src 'unsafe-inline' https:; font-src https: data:");
    header('Content-Type: text/html; charset=utf-8');
    $html = (string)$msg['body_html'];
    if ($html === '') $html = '<pre style="white-space:pre-wrap;font-family:inherit">' . htmlspecialchars((string)$msg['body_text'], ENT_QUOTES, 'UTF-8') . '</pre>';
    $html = preg_replace('#<(script|iframe|object|embed|form|meta|base)\b[^>]*>(.*?</\1>)?#is', '', $html);
    echo '<!doctype html><html><head><meta charset="utf-8"><meta name="referrer" content="no-referrer"><base target="_blank">'
       . '<style>body{font-family:Arial,sans-serif;font-size:14px;color:#1f2937;margin:12px;word-wrap:break-word}img{max-width:100%;height:auto}</style></head><body>'
       . $html . '</body></html>';
    exit;
}

$atts = json_decode((string)$msg['attachments_json'], true) ?: [];
$a = $atts[(int)($_GET['a'] ?? -1)] ?? null;
if (!$a) { http_response_code(404); exit('Pièce jointe introuvable.'); }
$acc = mail_get_account($pdo, (int)$user['org_id']);
if (!$acc || $acc['provider'] !== 'gmail') { http_response_code(404); exit('Pièce jointe non disponible dans la démonstration.'); }

[$c, $d] = mail_api($pdo, $acc, 'GET', 'messages/' . rawurlencode($msg['gmail_message_id']) . '/attachments/' . rawurlencode($a['id']));
if ($c !== 200 || empty($d['data'])) { http_response_code(502); exit('Gmail n’a pas renvoyé la pièce jointe.'); }
$bin = mail_b64url_decode($d['data']);

// Jamais servi comme page web : téléchargement forcé, type neutre hors PDF / images courantes
$mime = strtolower((string)$a['mime']);
$safe = in_array($mime, ['application/pdf', 'image/png', 'image/jpeg', 'image/gif', 'image/webp'], true) ? $mime : 'application/octet-stream';
$name = preg_replace('/[\r\n"\\\\\/]+/', '_', (string)$a['name']) ?: 'piece-jointe';
header('Content-Type: ' . $safe);
header('Content-Length: ' . strlen($bin));
header('Content-Disposition: attachment; filename="' . (preg_replace('/[^\x20-\x7E]/', '_', $name)) . '"; filename*=UTF-8\'\'' . rawurlencode($name));
echo $bin;
