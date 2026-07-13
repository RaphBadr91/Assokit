<?php
/**
 * api/inbound-contact.php — Webhook de RÉCEPTION des réponses des prospects.
 * Appelé par le fournisseur email entrant (Resend Inbound / Cloudflare Email Routing…)
 * quand un prospect répond à une demande de contact. Ajoute le message au fil dans l'app.
 * PUBLIC (pas de session) — sécurisé par le jeton contenu dans l'adresse destinataire.
 * NE MODIFIE PAS le site : dédié à l'application.
 */
ob_start();
require_once __DIR__ . '/../config.php';
@require_once __DIR__ . '/../includes-layout.php';
ob_end_clean();

require_once __DIR__ . '/_app-contact-token.php';
header('Content-Type: application/json; charset=utf-8');

function inb_ok($m = 'ok') { echo json_encode(['ok' => true, 'msg' => $m]); exit; }

// ---- Lecture du payload (JSON ou form-encoded, multi-fournisseurs) ----
$raw = file_get_contents('php://input');
$data = json_decode((string) $raw, true);
if (!is_array($data)) $data = [];
$src = $data;
if (isset($data['data']) && is_array($data['data'])) $src = $data['data']; // Resend enveloppe { type, data }
$form = $_POST ?: [];

// Récupère toutes les adresses destinataires possibles
$recips = [];
$collect = function ($v) use (&$recips) {
    if (is_string($v)) $recips[] = $v;
    elseif (is_array($v)) foreach ($v as $x) {
        if (is_string($x)) $recips[] = $x;
        elseif (is_array($x) && !empty($x['address'])) $recips[] = $x['address'];
        elseif (is_array($x) && !empty($x['email'])) $recips[] = $x['email'];
    }
};
$collect($src['to'] ?? null);
$collect($src['recipient'] ?? null);
$collect($src['envelope']['to'] ?? null);
$collect($form['recipient'] ?? null);
$collect($form['to'] ?? null);

// Corps texte (privilégie le texte "nettoyé" si fourni)
$body = (string) ($src['stripped-text'] ?? $form['stripped-text'] ?? $src['text'] ?? $form['body-plain'] ?? $src['body'] ?? '');
if ($body === '' && !empty($src['html'])) $body = trim(strip_tags((string) $src['html']));

// Expéditeur
$from = (string) ($src['from'] ?? $form['sender'] ?? $form['from'] ?? '');
if (is_array($src['from'] ?? null)) $from = (string) ($src['from']['address'] ?? $src['from']['email'] ?? '');

// Trouve un destinataire qui matche notre format à jeton
$match = null;
foreach ($recips as $r) { $p = ak_contact_parse_recipient((string) $r); if ($p) { $match = $p; break; } }
if (!$match) { http_response_code(202); inb_ok('no-token'); }

try {
    $st = $pdo->prepare("SELECT id, email FROM asso_contact_messages WHERE id = ? LIMIT 1");
    $st->execute([$match['id']]);
    $c = $st->fetch(PDO::FETCH_ASSOC);
    if (!$c) { http_response_code(202); inb_ok('unknown-contact'); }

    // Vérifie le jeton (anti-spoof)
    $expected = ak_contact_token((int) $c['id'], (string) $c['email']);
    if (!hash_equals($expected, $match['token'])) { http_response_code(403); echo json_encode(['ok' => false, 'error' => 'bad-token']); exit; }

    // Nettoie l'historique cité (best-effort)
    $clean = $body;
    if ($clean !== '') {
        $parts = preg_split('/^\s*(Le .+ a écrit\s*:|On .+ wrote:|-{3,} ?Original Message|De\s*:\s|From:\s|>\s)/mu', $clean, 2);
        if (is_array($parts) && $parts[0] !== '') $clean = $parts[0];
        $clean = trim($clean);
    }
    if ($clean === '') $clean = '(message vide)';
    $clean = mb_substr($clean, 0, 8000);

    ak_contact_thread_ensure($pdo);
    $pdo->prepare("INSERT INTO asso_contact_thread (contact_id, direction, body, from_email, read_by_founder, created_at) VALUES (?, 'in', ?, ?, 0, NOW())")
        ->execute([(int) $c['id'], $clean, mb_substr($from, 0, 255)]);

    // Re-signale la demande comme non lue dans l'app
    try { $pdo->prepare("UPDATE asso_contact_messages SET status = 'new' WHERE id = ?")->execute([(int) $c['id']]); } catch (Throwable $e) {}

    inb_ok('stored');
} catch (Throwable $e) {
    error_log('[inbound-contact] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'server']);
}
