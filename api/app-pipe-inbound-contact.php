#!/usr/bin/php
<?php
/**
 * api/pipe-inbound-contact.php — Réception des réponses prospects via un filtre cPanel "pipe".
 * Reçoit l'email brut sur STDIN (Exim), en extrait le destinataire à jeton + le corps,
 * et l'ajoute au fil de discussion. NE MODIFIE PAS le site. Exécuté en CLI par le serveur mail.
 *
 * Filtre cPanel (Email → Filtres) sur la boîte "reponses@assokit.fr" :
 *   Action : "Rediriger vers un programme (pipe)"
 *   Programme : /home/pura7044/public_html/api/pipe-inbound-contact.php
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }

ob_start();
require_once __DIR__ . '/../config.php';
@require_once __DIR__ . '/../includes-layout.php';
ob_end_clean();
require_once __DIR__ . '/_app-contact-token.php';

// --- Lit l'email brut depuis STDIN ---
$raw = @stream_get_contents(STDIN);
if ($raw === false || $raw === '') exit(0);
$raw = str_replace("\r\n", "\n", $raw);

// --- Sépare en-têtes / corps ---
$sep = strpos($raw, "\n\n");
$head = $sep !== false ? substr($raw, 0, $sep) : $raw;
$bodyRaw = $sep !== false ? substr($raw, $sep + 2) : '';

// Déplie les en-têtes (lignes de continuation)
$head = preg_replace("/\n[ \t]+/", ' ', $head);
$headers = [];
foreach (explode("\n", $head) as $line) {
    if (preg_match('/^([A-Za-z0-9\-]+):\s*(.*)$/', $line, $m)) {
        $headers[strtolower($m[1])][] = $m[2];
    }
}
$h = function (string $k) use ($headers): string {
    return isset($headers[$k]) ? implode(' ', $headers[$k]) : '';
};

// --- Destinataire à jeton (cherche dans les en-têtes d'adressage) ---
$recipientBlob = implode(' ', array_filter([
    $h('x-original-to'), $h('delivered-to'), $h('envelope-to'), $h('to'), $h('cc'),
]));
$from = $h('from');

// --- Extrait le corps texte ---
$ctype = strtolower($h('content-type'));
$cte   = strtolower(trim($h('content-transfer-encoding')));

$decode = function (string $data, string $enc): string {
    if (strpos($enc, 'base64') !== false) return (string) base64_decode($data);
    if (strpos($enc, 'quoted-printable') !== false) return quoted_printable_decode($data);
    return $data;
};

$body = '';
if (strpos($ctype, 'multipart/') !== false && preg_match('/boundary="?([^";]+)"?/i', $ctype, $bm)) {
    $boundary = $bm[1];
    $parts = preg_split('/--' . preg_quote($boundary, '/') . '(--)?\n/', $bodyRaw);
    foreach ($parts as $part) {
        $psep = strpos($part, "\n\n");
        if ($psep === false) continue;
        $phead = substr($part, 0, $psep);
        $pbody = substr($part, $psep + 2);
        if (stripos($phead, 'text/plain') !== false) {
            preg_match('/content-transfer-encoding:\s*([^\s;]+)/i', $phead, $em);
            $body = $decode($pbody, strtolower($em[1] ?? ''));
            break;
        }
        if ($body === '' && stripos($phead, 'text/html') !== false) {
            preg_match('/content-transfer-encoding:\s*([^\s;]+)/i', $phead, $em);
            $body = trim(strip_tags($decode($pbody, strtolower($em[1] ?? ''))));
        }
    }
} else {
    $body = $decode($bodyRaw, $cte);
    if (strpos($ctype, 'text/html') !== false) $body = trim(strip_tags($body));
}

// --- Enregistre ---
try {
    ak_contact_store_inbound($pdo, $recipientBlob, $from, (string) $body);
} catch (Throwable $e) {
    error_log('[pipe-inbound-contact] ' . $e->getMessage());
}
exit(0);
