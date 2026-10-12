#!/usr/local/bin/php
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

/** Sépare en-têtes / corps d'une entité MIME ; en-têtes dépliés, clés en minuscules (valeurs intactes). */
function pipe_split_entity(string $entity): array {
    if (strncmp($entity, "\n", 1) === 0) return [[], substr($entity, 1)];   // partie sans en-tête
    $sep = strpos($entity, "\n\n");
    $head = $sep !== false ? substr($entity, 0, $sep) : $entity;
    $body = $sep !== false ? substr($entity, $sep + 2) : '';
    $head = preg_replace("/\n[ \t]+/", ' ', $head);   // lignes de continuation
    $headers = [];
    foreach (explode("\n", $head) as $line) {
        if (preg_match('/^([A-Za-z0-9\-]+):\s*(.*)$/', $line, $m)) $headers[strtolower($m[1])][] = trim($m[2]);
    }
    return [$headers, $body];
}

/** Paramètre d'un en-tête structuré (boundary, charset…), casse d'origine conservée. */
function pipe_header_param(string $value, string $param): string {
    if (preg_match('/(?:^|;)\s*' . preg_quote($param, '/') . '\s*=\s*(?:"([^"]*)"|([^;\s]+))/i', $value, $m)) {
        return ($m[1] ?? '') !== '' ? $m[1] : ($m[2] ?? '');
    }
    return '';
}

/** Décode le transfert (base64, quoted-printable) puis convertit le jeu de caractères en UTF-8. */
function pipe_decode_text(string $data, string $cte, string $charset): string {
    $cte = strtolower($cte);
    if (strpos($cte, 'base64') !== false) $data = (string) base64_decode($data);
    elseif (strpos($cte, 'quoted-printable') !== false) $data = quoted_printable_decode($data);

    $cs = strtolower(trim($charset));
    if (in_array($cs, ['', 'us-ascii', 'ascii', 'utf-8', 'utf8'], true)) {
        // Charset absent ou annoncé UTF-8 : on le garde s'il est valide, sinon windows-1252 (cas courant)
        return mb_check_encoding($data, 'UTF-8') ? $data : mb_convert_encoding($data, 'UTF-8', 'Windows-1252');
    }
    // iso-8859-1 annoncé = presque toujours du windows-1252 (guillemets, €, œ…) : sur-ensemble sans perte
    if (in_array($cs, ['iso-8859-1', 'iso8859-1', 'latin1', 'latin-1'], true)) $cs = 'Windows-1252';
    try {
        $out = mb_convert_encoding($data, 'UTF-8', $cs);
        if (is_string($out)) return $out;
    } catch (Throwable $e) {}
    $out = function_exists('iconv') ? @iconv($cs, 'UTF-8//IGNORE', $data) : false;
    if (is_string($out) && $out !== '') return $out;
    return mb_convert_encoding($data, 'UTF-8', 'Windows-1252');
}

/** Texte lisible d'une partie HTML (sans styles ni scripts, entités décodées). */
function pipe_html_to_text(string $html): string {
    $html = preg_replace('#<(style|script|head)\b[^>]*>.*?</\1>#is', '', $html);
    $html = preg_replace('#<br\s*/?>|</(p|div|li|tr|h[1-6])>#i', "\n", (string) $html);
    $txt = html_entity_decode(strip_tags((string) $html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $txt = preg_replace("/[ \t\x{00A0}]+/u", ' ', $txt);
    $txt = preg_replace("/\n\s*\n\s*\n+/", "\n\n", (string) $txt);
    return trim((string) $txt);
}

/**
 * Parcourt une entité MIME (récursivement : multipart/alternative dans multipart/mixed ou
 * multipart/related, comme Outlook et Apple Mail) et retient le premier texte brut et le
 * premier HTML. Les pièces jointes sont ignorées.
 */
function pipe_collect_text(array $headers, string $body, array &$found, int $depth = 0): void {
    if ($depth > 8) return;
    $ctRaw = isset($headers['content-type']) ? implode(' ', $headers['content-type']) : 'text/plain';
    $type  = strtolower(trim(explode(';', $ctRaw)[0]));
    if ($type === '') $type = 'text/plain';
    $disp  = strtolower(isset($headers['content-disposition']) ? implode(' ', $headers['content-disposition']) : '');
    if (strpos($disp, 'attachment') === 0) return;

    if (strpos($type, 'multipart/') === 0) {
        $boundary = pipe_header_param($ctRaw, 'boundary');   // casse d'origine : « _000_DB7PR… » chez Outlook
        if ($boundary === '') return;
        $parts = []; $cur = null;
        foreach (explode("\n", $body) as $line) {
            $l = rtrim($line);
            if ($l === '--' . $boundary . '--') { if ($cur !== null) $parts[] = $cur; $cur = null; break; }
            if ($l === '--' . $boundary) { if ($cur !== null) $parts[] = $cur; $cur = ''; continue; }
            if ($cur !== null) $cur .= $line . "\n";
        }
        if ($cur !== null) $parts[] = $cur;   // délimiteur final absent
        foreach ($parts as $part) {
            [$ph, $pb] = pipe_split_entity($part);
            pipe_collect_text($ph, $pb, $found, $depth + 1);
            if ($found['plain'] !== null) return;
        }
        return;
    }

    if ($type !== 'text/plain' && $type !== 'text/html') return;
    $cte = isset($headers['content-transfer-encoding']) ? implode(' ', $headers['content-transfer-encoding']) : '';
    $txt = pipe_decode_text($body, $cte, pipe_header_param($ctRaw, 'charset'));
    if ($type === 'text/plain' && $found['plain'] === null && trim($txt) !== '') $found['plain'] = $txt;
    if ($type === 'text/html' && $found['html'] === null) {
        $t = pipe_html_to_text($txt);
        if ($t !== '') $found['html'] = $t;
    }
}

[$headers, $bodyRaw] = pipe_split_entity($raw);
$h = function (string $k) use ($headers): string {
    return isset($headers[$k]) ? implode(' ', $headers[$k]) : '';
};

// --- Destinataire à jeton (cherche dans les en-têtes d'adressage) ---
$recipientBlob = implode(' ', array_filter([
    $h('x-original-to'), $h('delivered-to'), $h('envelope-to'), $h('to'), $h('cc'),
]));
// « Hélène D'Aubigné <h@x.fr> » : l'adresse seule est extraite par ak_contact_store_inbound
$from = $h('from');

// --- Extrait le corps texte (texte brut de préférence, sinon le HTML) ---
$found = ['plain' => null, 'html' => null];
pipe_collect_text($headers, $bodyRaw, $found);
$body = $found['plain'] ?? $found['html'] ?? '';

// --- Enregistre ---
try {
    ak_contact_store_inbound($pdo, $recipientBlob, $from, (string) $body);
} catch (Throwable $e) {
    error_log('[pipe-inbound-contact] ' . $e->getMessage());
}
exit(0);
