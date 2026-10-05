<?php
/**
 * ============================================================
 * ASSOKIT — Boîte mail Gmail (cerveau technique)
 * ============================================================
 * Une boîte Gmail par association, reliée par OAuth (bouton officiel
 * « Se connecter avec Google », scope gmail.modify).
 *
 *   - Jetons chiffrés en base (AES-256-GCM)
 *   - Synchronisation : première passe sur N jours, puis incrémentale
 *     via l'historique Gmail (history.list)
 *   - Tri : règles sur l'objet / l'expéditeur, puis IA pour le reste
 *   - Rattachement : adhérent, client, facture
 *   - Réponse dans le même fil Gmail (In-Reply-To / References)
 *
 * Tables : migrations/2026-10-06-boite-mail.sql
 * ============================================================
 */

if (!defined('MAIL_GMAIL_API'))     define('MAIL_GMAIL_API', 'https://gmail.googleapis.com/gmail/v1/users/me/');
if (!defined('MAIL_GOOGLE_AUTH'))   define('MAIL_GOOGLE_AUTH', 'https://accounts.google.com/o/oauth2/v2/auth');
if (!defined('MAIL_GOOGLE_TOKEN'))  define('MAIL_GOOGLE_TOKEN', 'https://oauth2.googleapis.com/token');
if (!defined('MAIL_GOOGLE_REVOKE')) define('MAIL_GOOGLE_REVOKE', 'https://oauth2.googleapis.com/revoke');
const MAIL_SCOPES = 'https://www.googleapis.com/auth/gmail.modify openid email';
const MAIL_INITIAL_CAP = 400;   // e-mails au plus lors de la première synchro

// ============================================================
// DISPONIBILITÉ, DROITS
// ============================================================

function mail_google_configured(): bool {
    return defined('GOOGLE_CLIENT_ID') && GOOGLE_CLIENT_ID !== '' && GOOGLE_CLIENT_ID !== 'METS_TON_CLIENT_ID_ICI'
        && defined('GOOGLE_CLIENT_SECRET') && GOOGLE_CLIENT_SECRET !== ''
        && defined('GOOGLE_REDIRECT_URI') && GOOGLE_REDIRECT_URI !== '';
}

function mail_schema_ready(PDO $pdo): bool {
    static $ok = null;
    if ($ok !== null) return $ok;
    try { $pdo->query("SELECT 1 FROM mail_threads LIMIT 1"); $ok = true; }
    catch (Throwable $e) { $ok = false; }
    return $ok;
}

/** Qui ouvre la boîte : administrateurs et coordinateurs. */
function mail_can_access(?array $user): bool {
    if (!$user) return false;
    return in_array($user['role'] ?? '', ['admin', 'coordinator', 'founder', 'super_admin'], true)
        || !empty($user['is_founder']) || !empty($user['is_super_admin']);
}

/** Qui relie / délie la boîte et règle les catégories. */
function mail_can_manage(?array $user): bool {
    if (!$user) return false;
    return in_array($user['role'] ?? '', ['admin', 'founder', 'super_admin'], true)
        || !empty($user['is_founder']) || !empty($user['is_super_admin']);
}

// ============================================================
// CHIFFREMENT DES JETONS
// ============================================================

function mail_crypt_key(): string {
    $secret = defined('MAIL_TOKEN_KEY') && MAIL_TOKEN_KEY !== '' ? MAIL_TOKEN_KEY
            : (defined('GOOGLE_CLIENT_SECRET') ? GOOGLE_CLIENT_SECRET : '') . '|' . (defined('DB_NAME') ? DB_NAME : '');
    return hash('sha256', 'assokit-mail|' . $secret, true);
}

function mail_encrypt(?string $plain): ?string {
    if ($plain === null || $plain === '') return null;
    $iv = random_bytes(12);
    $tag = '';
    $c = openssl_encrypt($plain, 'aes-256-gcm', mail_crypt_key(), OPENSSL_RAW_DATA, $iv, $tag);
    return 'v1:' . base64_encode($iv . $tag . $c);
}

function mail_decrypt(?string $enc): ?string {
    if (!$enc || strncmp($enc, 'v1:', 3) !== 0) return null;
    $raw = base64_decode(substr($enc, 3), true);
    if ($raw === false || strlen($raw) < 29) return null;
    $p = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', mail_crypt_key(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
    return $p === false ? null : $p;
}

// ============================================================
// COMPTE, CATÉGORIES
// ============================================================

function mail_get_account(PDO $pdo, int $org_id): ?array {
    if (!mail_schema_ready($pdo)) return null;
    $s = $pdo->prepare("SELECT * FROM mail_accounts WHERE org_id = ? LIMIT 1");
    $s->execute([$org_id]);
    return $s->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** Catégories proposées à la première ouverture (modifiables ensuite). */
function mail_default_categories(): array {
    return [
        ['facturation',    'Facturation',             '#0EA5E9', 'receipt',   'facture, devis, bon de commande, paiement de la facture, règlement, virement, avoir, impayé, relance, acompte', 'admin,coordinator'],
        ['adherents',      'Adhérents',               '#10B981', 'users',     'adhésion, adhérent, cotisation, inscription, réinscription, renouvellement, carte de membre, désinscription', ''],
        ['subventions',    'Subventions & financeurs','#8B5CF6', 'euro',      'subvention, appel à projets, AAP, financement, demande de financement, convention, bilan financier, CERFA, dossier de demande, OPCO, fondation', 'admin,coordinator'],
        ['formations',     'Formations & stagiaires', '#F59E0B', 'book',      'formation, stage, stagiaire, apprenant, session, convocation, attestation de formation, positionnement, Qualiopi, CPF, émargement', ''],
        ['partenaires',    'Partenaires',             '#EC4899', 'handshake', 'partenariat, partenaire, mécénat, mécène, sponsor, collaboration, proposition', ''],
        ['benevoles',      'Bénévoles',               '#14B8A6', 'heart',     'bénévole, bénévolat, mission, disponibilité, volontaire, candidature spontanée', ''],
        ['evenements',     'Événements & réunions',   '#6366F1', 'calendar',  'événement, invitation, réunion, assemblée, AG, conseil d\'administration, rendez-vous, atelier, salon, forum', ''],
        ['administratif',  'Administratif',           '#64748B', 'building',  'assurance, URSSAF, impôts, préfecture, banque, contrat, RGPD, statuts, mutuelle, déclaration sociale, DSN', 'admin'],
        ['autre',          'Autres',                  '#94A3B8', 'inbox',     '', ''],
    ];
}

function mail_ensure_categories(PDO $pdo, int $org_id): void {
    $s = $pdo->prepare("SELECT COUNT(*) FROM mail_categories WHERE org_id = ?");
    $s->execute([$org_id]);
    if ((int)$s->fetchColumn() > 0) return;
    $ins = $pdo->prepare("INSERT IGNORE INTO mail_categories (org_id, slug, label, color, icon, keywords, roles, position, is_system) VALUES (?,?,?,?,?,?,?,?,?)");
    foreach (mail_default_categories() as $i => [$slug, $label, $color, $icon, $kw, $roles]) {
        $ins->execute([$org_id, $slug, $label, $color, $icon, $kw, $roles, $i + 1, $slug === 'autre' ? 1 : 0]);
    }
}

function mail_categories(PDO $pdo, int $org_id): array {
    mail_ensure_categories($pdo, $org_id);
    $s = $pdo->prepare("SELECT * FROM mail_categories WHERE org_id = ? ORDER BY position, id");
    $s->execute([$org_id]);
    return $s->fetchAll(PDO::FETCH_ASSOC);
}

/** Catégories qu'un utilisateur voit (liste vide de rôles = tout le monde). */
function mail_visible_categories(PDO $pdo, int $org_id, array $user): array {
    $all = mail_categories($pdo, $org_id);
    if (mail_can_manage($user)) return $all;
    $role = $user['role'] ?? '';
    return array_values(array_filter($all, function ($c) use ($role) {
        $roles = array_filter(array_map('trim', explode(',', (string)$c['roles'])));
        return !$roles || in_array($role, $roles, true);
    }));
}

function mail_category_by_slug(PDO $pdo, int $org_id, string $slug): ?array {
    $s = $pdo->prepare("SELECT * FROM mail_categories WHERE org_id = ? AND slug = ? LIMIT 1");
    $s->execute([$org_id, $slug]);
    return $s->fetch(PDO::FETCH_ASSOC) ?: null;
}

// ============================================================
// OAUTH
// ============================================================

function mail_oauth_url(string $state, string $login_hint = ''): string {
    $p = [
        'client_id'              => GOOGLE_CLIENT_ID,
        'redirect_uri'           => GOOGLE_REDIRECT_URI,
        'response_type'          => 'code',
        'scope'                  => MAIL_SCOPES,
        'access_type'            => 'offline',
        'prompt'                 => 'consent',
        'include_granted_scopes' => 'true',
        'state'                  => $state,
    ];
    if ($login_hint !== '') $p['login_hint'] = $login_hint;
    return MAIL_GOOGLE_AUTH . '?' . http_build_query($p);
}

function mail_http(string $method, string $url, array $headers = [], $body = null, int $timeout = 30): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    return [$code, $resp === false ? null : $resp, $err];
}

/** Échange du code OAuth. @return array jetons Google ou ['error' => …] */
function mail_exchange_code(string $code): array {
    [$c, $r, $e] = mail_http('POST', MAIL_GOOGLE_TOKEN, ['Content-Type: application/x-www-form-urlencoded'], http_build_query([
        'code' => $code, 'client_id' => GOOGLE_CLIENT_ID, 'client_secret' => GOOGLE_CLIENT_SECRET,
        'redirect_uri' => GOOGLE_REDIRECT_URI, 'grant_type' => 'authorization_code',
    ]));
    $d = json_decode((string)$r, true) ?: [];
    if ($c !== 200) return ['error' => $d['error_description'] ?? $d['error'] ?? ($e ?: 'HTTP ' . $c)];
    return $d;
}

/** Jeton d'accès valide (rafraîchi si besoin). null = boîte à reconnecter. */
function mail_access_token(PDO $pdo, array &$acc): ?string {
    $tok = mail_decrypt($acc['access_token_enc'] ?? null);
    if ($tok && !empty($acc['token_expires_at']) && strtotime($acc['token_expires_at']) > time() + 60) return $tok;
    $refresh = mail_decrypt($acc['refresh_token_enc'] ?? null);
    if (!$refresh) { mail_set_error($pdo, $acc, 'Connexion Google perdue : reconnectez la boîte.', 'revoked'); return null; }
    [$c, $r, $e] = mail_http('POST', MAIL_GOOGLE_TOKEN, ['Content-Type: application/x-www-form-urlencoded'], http_build_query([
        'client_id' => GOOGLE_CLIENT_ID, 'client_secret' => GOOGLE_CLIENT_SECRET,
        'refresh_token' => $refresh, 'grant_type' => 'refresh_token',
    ]));
    $d = json_decode((string)$r, true) ?: [];
    if ($c !== 200 || empty($d['access_token'])) {
        $revoked = ($d['error'] ?? '') === 'invalid_grant';
        mail_set_error($pdo, $acc, $revoked ? 'Google a retiré l\'autorisation : reconnectez la boîte.' : 'Rafraîchissement du jeton impossible (' . ($d['error'] ?? $e ?: 'HTTP ' . $c) . ').', $revoked ? 'revoked' : 'error');
        return null;
    }
    $acc['access_token_enc'] = mail_encrypt($d['access_token']);
    $acc['token_expires_at'] = date('Y-m-d H:i:s', time() + (int)($d['expires_in'] ?? 3600) - 60);
    $pdo->prepare("UPDATE mail_accounts SET access_token_enc = ?, token_expires_at = ?, status = 'active', last_error = NULL WHERE id = ?")
        ->execute([$acc['access_token_enc'], $acc['token_expires_at'], $acc['id']]);
    $acc['status'] = 'active';
    return $d['access_token'];
}

function mail_set_error(PDO $pdo, array &$acc, string $msg, string $status = 'error'): void {
    $acc['status'] = $status; $acc['last_error'] = $msg;
    $pdo->prepare("UPDATE mail_accounts SET status = ?, last_error = ? WHERE id = ?")->execute([$status, mb_substr($msg, 0, 500), $acc['id']]);
}

/**
 * Appel à l'API Gmail.
 * @param array $query  paramètres GET (les tableaux donnent des paramètres répétés)
 * @return array [code HTTP, données décodées]
 */
function mail_api(PDO $pdo, array &$acc, string $method, string $path, array $query = [], ?array $json = null): array {
    $tok = mail_access_token($pdo, $acc);
    if (!$tok) return [401, ['error' => ['message' => $acc['last_error'] ?? 'Non connecté']]];
    $qs = [];
    foreach ($query as $k => $v) {
        foreach ((array)$v as $vv) $qs[] = rawurlencode($k) . '=' . rawurlencode((string)$vv);
    }
    $url = MAIL_GMAIL_API . ltrim($path, '/') . ($qs ? '?' . implode('&', $qs) : '');
    $h = ['Authorization: Bearer ' . $tok];
    if ($json !== null) $h[] = 'Content-Type: application/json';
    [$c, $r, $e] = mail_http($method, $url, $h, $json !== null ? json_encode($json) : null, 40);
    $d = json_decode((string)$r, true);
    if ($r === null) $d = ['error' => ['message' => $e ?: 'Pas de réponse de Gmail']];
    return [$c, is_array($d) ? $d : []];
}

/** Retire l'accès chez Google et efface la boîte locale. */
function mail_disconnect(PDO $pdo, int $org_id, bool $purge = true): void {
    $acc = mail_get_account($pdo, $org_id);
    if (!$acc) return;
    if ($acc['provider'] === 'gmail') {
        $t = mail_decrypt($acc['refresh_token_enc']) ?: mail_decrypt($acc['access_token_enc']);
        if ($t) mail_http('POST', MAIL_GOOGLE_REVOKE, ['Content-Type: application/x-www-form-urlencoded'], http_build_query(['token' => $t]), 10);
    }
    if ($purge) {
        $pdo->prepare("DELETE FROM mail_messages WHERE org_id = ?")->execute([$org_id]);
        $pdo->prepare("DELETE FROM mail_threads WHERE org_id = ?")->execute([$org_id]);
    }
    $pdo->prepare("DELETE FROM mail_accounts WHERE org_id = ?")->execute([$org_id]);
}

// ============================================================
// LECTURE D'UN MESSAGE GMAIL
// ============================================================

function mail_b64url_decode(string $s): string {
    return (string)base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4));
}

function mail_b64url_encode(string $s): string {
    return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
}

function mail_decode_header(string $v): string {
    if (strpos($v, '=?') !== false && function_exists('iconv_mime_decode')) {
        $d = @iconv_mime_decode($v, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');
        if ($d !== false) $v = $d;
    }
    return trim($v);
}

/** "Jean <a@b.fr>, c@d.fr" → [['email' => 'a@b.fr', 'name' => 'Jean'], …] */
function mail_parse_addresses(string $s): array {
    $out = [];
    $parts = preg_split('/,(?=(?:[^"]*"[^"]*")*[^"]*$)/', $s);
    foreach ($parts as $p) {
        $p = trim($p);
        if ($p === '') continue;
        if (preg_match('/^(.*)<([^>]+)>$/', $p, $m)) {
            $email = strtolower(trim($m[2]));
            $name = trim(trim($m[1]), "\"' ");
        } else {
            $email = strtolower(trim($p, "<> "));
            $name = '';
        }
        if (filter_var($email, FILTER_VALIDATE_EMAIL)) $out[] = ['email' => $email, 'name' => mail_decode_header($name)];
    }
    return $out;
}

function mail_html_to_text(string $html): string {
    $html = preg_replace('#<(script|style|head)[^>]*>.*?</\1>#si', '', $html);
    $html = preg_replace('#<br\s*/?>#i', "\n", $html);
    $html = preg_replace('#</(p|div|tr|li|h[1-6]|blockquote|table)>#i', "\n", $html);
    $html = preg_replace('#<li[^>]*>#i', '• ', $html);
    $t = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $t = preg_replace("/[ \t\x{00A0}]+/u", ' ', $t);
    $t = preg_replace("/ *\n */", "\n", $t);
    return trim(preg_replace("/\n{3,}/", "\n\n", $t));
}

/** Retire la citation du message précédent (« Le … a écrit : » et lignes « > »). */
function mail_strip_quoted(string $t): string {
    $t = preg_split('/\n(?:Le .{5,160}a écrit\s*:|On .{5,160}wrote:|-{2,} ?(?:Message d\'origine|Original Message|Forwarded message) ?-{2,}|De ?: .+\nEnvoyé ?: )/u', $t)[0];
    $lines = array_filter(explode("\n", $t), fn($l) => !preg_match('/^\s*>/', $l));
    return trim(implode("\n", $lines));
}

function mail_part_text(array $part): string {
    $data = $part['body']['data'] ?? '';
    if ($data === '') return '';
    $raw = mail_b64url_decode($data);
    $charset = 'UTF-8';
    foreach ($part['headers'] ?? [] as $h) {
        if (strcasecmp($h['name'], 'Content-Type') === 0 && preg_match('/charset="?([\w\-]+)/i', $h['value'], $m)) $charset = strtoupper($m[1]);
    }
    if ($charset !== 'UTF-8' && $charset !== 'US-ASCII') {
        $conv = @mb_convert_encoding($raw, 'UTF-8', $charset);
        if ($conv !== false) $raw = $conv;
    }
    if (!mb_check_encoding($raw, 'UTF-8')) $raw = mb_convert_encoding($raw, 'UTF-8', 'ISO-8859-1');
    return $raw;
}

function mail_walk_parts(array $part, array &$acc): void {
    $mime = strtolower($part['mimeType'] ?? '');
    $fname = $part['filename'] ?? '';
    if ($fname !== '' && !empty($part['body']['attachmentId'])) {
        $acc['attachments'][] = ['id' => $part['body']['attachmentId'], 'name' => $fname, 'mime' => $mime, 'size' => (int)($part['body']['size'] ?? 0)];
        return;
    }
    if ($mime === 'text/plain' && $acc['text'] === '') $acc['text'] = mail_part_text($part);
    elseif ($mime === 'text/html' && $acc['html'] === '') $acc['html'] = mail_part_text($part);
    foreach ($part['parts'] ?? [] as $p) mail_walk_parts($p, $acc);
}

/** Message Gmail (format=full) → champs Assokit. */
function mail_parse_gmail(array $m): array {
    $h = [];
    foreach ($m['payload']['headers'] ?? [] as $x) $h[strtolower($x['name'])] = $x['value'];
    $parts = ['text' => '', 'html' => '', 'attachments' => []];
    mail_walk_parts($m['payload'] ?? [], $parts);
    $text = $parts['text'] !== '' ? $parts['text'] : ($parts['html'] !== '' ? mail_html_to_text($parts['html']) : html_entity_decode($m['snippet'] ?? '', ENT_QUOTES, 'UTF-8'));
    $from = mail_parse_addresses(mail_decode_header($h['from'] ?? ''))[0] ?? ['email' => '', 'name' => ''];
    $reply = mail_parse_addresses(mail_decode_header($h['reply-to'] ?? ''))[0]['email'] ?? null;
    $ts = !empty($m['internalDate']) ? (int)floor($m['internalDate'] / 1000) : (strtotime($h['date'] ?? '') ?: time());
    return [
        'gmail_id'    => $m['id'],
        'thread_id'   => $m['threadId'],
        'labels'      => $m['labelIds'] ?? [],
        'rfc_id'      => trim($h['message-id'] ?? ''),
        'references'  => trim(($h['references'] ?? '') . ' ' . ($h['in-reply-to'] ?? '')),
        'from_email'  => $from['email'],
        'from_name'   => $from['name'],
        'reply_to'    => $reply,
        'to'          => mail_parse_addresses(mail_decode_header($h['to'] ?? '')),
        'cc'          => mail_parse_addresses(mail_decode_header($h['cc'] ?? '')),
        'subject'     => mail_decode_header($h['subject'] ?? ''),
        'text'        => mb_substr(str_replace("\r\n", "\n", $text), 0, 200000),
        'html'        => mb_substr($parts['html'], 0, 500000),
        'attachments' => $parts['attachments'],
        'sent_at'     => date('Y-m-d H:i:s', $ts),
        'snippet'     => html_entity_decode($m['snippet'] ?? '', ENT_QUOTES, 'UTF-8'),
    ];
}

function mail_clean_subject(string $s): string {
    $s = trim($s);
    while (preg_match('/^(re|tr|fw|fwd|réf)\s*:\s*/iu', $s)) $s = preg_replace('/^(re|tr|fw|fwd|réf)\s*:\s*/iu', '', $s);
    return $s !== '' ? $s : '(sans objet)';
}

// ============================================================
// ENREGISTREMENT LOCAL
// ============================================================

/**
 * Enregistre (ou met à jour) un message parsé, crée le fil au besoin.
 * @return int|null id du fil, null si message ignoré
 */
function mail_store(PDO $pdo, array $acc, array $p, ?int $sent_by = null): ?int {
    $skip = array_intersect($p['labels'], ['SPAM', 'TRASH', 'DRAFT', 'CHAT']);
    if ($skip) return null;
    $org = (int)$acc['org_id'];
    $me = strtolower($acc['email']);
    $dir = (in_array('SENT', $p['labels'], true) || $p['from_email'] === $me) ? 'out' : 'in';

    $s = $pdo->prepare("SELECT id, counterpart_email FROM mail_threads WHERE account_id = ? AND gmail_thread_id = ?");
    $s->execute([$acc['id'], $p['thread_id']]);
    $th = $s->fetch(PDO::FETCH_ASSOC);
    if (!$th) {
        $cp = $dir === 'in' ? ['email' => $p['from_email'], 'name' => $p['from_name']] : ($p['to'][0] ?? ['email' => null, 'name' => null]);
        $pdo->prepare("INSERT INTO mail_threads (org_id, account_id, gmail_thread_id, subject, counterpart_email, counterpart_name, category_source) VALUES (?,?,?,?,?,?, 'none')")
            ->execute([$org, $acc['id'], $p['thread_id'], mb_substr(mail_clean_subject($p['subject']), 0, 500), $cp['email'], $cp['name'] ?: null]);
        $thread_id = (int)$pdo->lastInsertId();
    } else {
        $thread_id = (int)$th['id'];
        if (empty($th['counterpart_email']) && $dir === 'in') {
            $pdo->prepare("UPDATE mail_threads SET counterpart_email = ?, counterpart_name = ? WHERE id = ?")->execute([$p['from_email'], $p['from_name'] ?: null, $thread_id]);
        }
    }

    $pdo->prepare("
        INSERT INTO mail_messages (org_id, thread_id, gmail_message_id, rfc_message_id, references_hdr, direction, from_email, from_name, reply_to,
                                   to_list, cc_list, subject, body_text, body_html, sent_at, label_ids, attachments_json, sent_by_user_id)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE label_ids = VALUES(label_ids), thread_id = VALUES(thread_id)
    ")->execute([
        $org, $thread_id, $p['gmail_id'], mb_substr($p['rfc_id'], 0, 500), $p['references'] ?: null, $dir,
        $p['from_email'], $p['from_name'] ?: null, $p['reply_to'],
        json_encode($p['to'], JSON_UNESCAPED_UNICODE), json_encode($p['cc'], JSON_UNESCAPED_UNICODE),
        mb_substr($p['subject'], 0, 500), $p['text'], $p['html'] ?: null, $p['sent_at'],
        implode(',', $p['labels']), $p['attachments'] ? json_encode($p['attachments'], JSON_UNESCAPED_UNICODE) : null, $sent_by,
    ]);
    mail_refresh_thread($pdo, $thread_id);
    return $thread_id;
}

/** Recalcule les champs agrégés d'un fil (dernier message, non-lu, extrait…). */
function mail_refresh_thread(PDO $pdo, int $thread_id): void {
    $s = $pdo->prepare("SELECT direction, body_text, sent_at, label_ids FROM mail_messages WHERE thread_id = ? ORDER BY sent_at ASC, id ASC");
    $s->execute([$thread_id]);
    $msgs = $s->fetchAll(PDO::FETCH_ASSOC);
    if (!$msgs) { $pdo->prepare("DELETE FROM mail_threads WHERE id = ?")->execute([$thread_id]); return; }
    $last = end($msgs);
    $unread = 0;
    foreach ($msgs as $m) if ($m['direction'] === 'in' && in_array('UNREAD', explode(',', (string)$m['label_ids']), true)) $unread = 1;
    $snippet = mb_substr(preg_replace('/\s+/u', ' ', mail_strip_quoted((string)$last['body_text'])), 0, 300);
    $pdo->prepare("UPDATE mail_threads SET last_message_at = ?, last_direction = ?, message_count = ?, unread = ?, snippet = ? WHERE id = ?")
        ->execute([$last['sent_at'], $last['direction'], count($msgs), $unread, $snippet, $thread_id]);
}

// ============================================================
// SYNCHRONISATION
// ============================================================

/**
 * Synchronise la boîte, dans la limite d'un budget de temps.
 * @return array ['ajoutes' => int, 'encore' => bool, 'erreur' => ?string]
 */
function mail_sync(PDO $pdo, array $acc, float $budget = 20.0): array {
    $t0 = microtime(true);
    $res = ['ajoutes' => 0, 'encore' => false, 'erreur' => null];
    if ($acc['provider'] !== 'gmail') return $res;   // boîte de démonstration
    $touched = [];

    if (empty($acc['history_id'])) {
        [$c, $d] = mail_api($pdo, $acc, 'GET', 'profile');
        if ($c !== 200) return ['ajoutes' => 0, 'encore' => false, 'erreur' => mail_api_error($c, $d)];
        $acc['history_id'] = (string)$d['historyId'];
        $pdo->prepare("UPDATE mail_accounts SET history_id = ? WHERE id = ?")->execute([$acc['history_id'], $acc['id']]);
    }

    if (empty($acc['initial_done'])) {
        // Première passe : les N derniers jours, page par page
        $done = (int)$pdo->query("SELECT COUNT(*) FROM mail_messages WHERE org_id = " . (int)$acc['org_id'])->fetchColumn();
        do {
            $q = ['q' => 'newer_than:' . max(1, (int)$acc['sync_days']) . 'd -in:chats -in:spam -in:trash -in:drafts', 'maxResults' => 50];
            if (!empty($acc['sync_cursor'])) $q['pageToken'] = $acc['sync_cursor'];
            [$c, $d] = mail_api($pdo, $acc, 'GET', 'messages', $q);
            if ($c !== 200) { $res['erreur'] = mail_api_error($c, $d); break; }
            foreach ($d['messages'] ?? [] as $m) {
                if (microtime(true) - $t0 > $budget) { $res['encore'] = true; break 2; }
                if (mail_known($pdo, (int)$acc['org_id'], $m['id'])) continue;
                $tid = mail_fetch_store($pdo, $acc, $m['id']);
                if ($tid) { $touched[$tid] = true; $res['ajoutes']++; $done++; }
            }
            $acc['sync_cursor'] = $d['nextPageToken'] ?? null;
            $finished = empty($acc['sync_cursor']) || $done >= MAIL_INITIAL_CAP;
            $pdo->prepare("UPDATE mail_accounts SET sync_cursor = ?, initial_done = ? WHERE id = ?")
                ->execute([$finished ? null : $acc['sync_cursor'], $finished ? 1 : 0, $acc['id']]);
            if ($finished) { $acc['initial_done'] = 1; break; }
            if (microtime(true) - $t0 > $budget) { $res['encore'] = true; break; }
        } while (true);
    }

    if (!empty($acc['initial_done']) && !$res['erreur'] && !$res['encore']) {
        // Incrémental : tout ce qui a changé depuis le dernier historyId
        $page = null; $new_hist = $acc['history_id'];
        do {
            $q = ['startHistoryId' => $acc['history_id'], 'historyTypes' => ['messageAdded', 'labelAdded', 'labelRemoved'], 'maxResults' => 200];
            if ($page) $q['pageToken'] = $page;
            [$c, $d] = mail_api($pdo, $acc, 'GET', 'history', $q);
            if ($c === 404) {   // historique trop ancien : on repart d'une première passe
                $pdo->prepare("UPDATE mail_accounts SET history_id = NULL, initial_done = 0, sync_cursor = NULL WHERE id = ?")->execute([$acc['id']]);
                $res['encore'] = true;
                break;
            }
            if ($c !== 200) { $res['erreur'] = mail_api_error($c, $d); break; }
            foreach ($d['history'] ?? [] as $h) {
                foreach ($h['messagesAdded'] ?? [] as $x) {
                    $m = $x['message'];
                    if (array_intersect($m['labelIds'] ?? [], ['SPAM', 'TRASH', 'DRAFT', 'CHAT'])) continue;
                    if (mail_known($pdo, (int)$acc['org_id'], $m['id'])) continue;
                    $tid = mail_fetch_store($pdo, $acc, $m['id']);
                    if ($tid) { $touched[$tid] = true; $res['ajoutes']++; }
                }
                foreach (array_merge($h['labelsAdded'] ?? [], $h['labelsRemoved'] ?? []) as $x) {
                    $tid = mail_update_labels($pdo, (int)$acc['org_id'], $x['message']['id'], $x['message']['labelIds'] ?? []);
                    if ($tid) $touched[$tid] = true;
                }
            }
            $new_hist = $d['historyId'] ?? $new_hist;
            $page = $d['nextPageToken'] ?? null;
            if (microtime(true) - $t0 > $budget && $page) { $res['encore'] = true; break; }
        } while ($page);
        if (!$res['erreur'] && !$res['encore']) {
            $pdo->prepare("UPDATE mail_accounts SET history_id = ? WHERE id = ?")->execute([$new_hist, $acc['id']]);
        }
    }

    if ($touched) {
        foreach (array_keys($touched) as $tid) mail_link_thread($pdo, (int)$acc['org_id'], $tid);
        mail_classify_pending($pdo, $acc);
    }
    $pdo->prepare("UPDATE mail_accounts SET last_sync_at = NOW()" . ($res['erreur'] ? ", last_error = ?" : ", last_error = NULL, status = 'active'") . " WHERE id = ?")
        ->execute($res['erreur'] ? [mb_substr($res['erreur'], 0, 500), $acc['id']] : [$acc['id']]);
    return $res;
}

function mail_api_error(int $c, array $d): string {
    $m = $d['error']['message'] ?? ($d['error_description'] ?? 'erreur ' . $c);
    if ($c === 403 && stripos($m, 'has not been used') !== false) return 'L\'API Gmail n\'est pas activée dans le projet Google Cloud.';
    return 'Gmail : ' . $m;
}

function mail_known(PDO $pdo, int $org, string $gmail_id): bool {
    $s = $pdo->prepare("SELECT 1 FROM mail_messages WHERE org_id = ? AND gmail_message_id = ?");
    $s->execute([$org, $gmail_id]);
    return (bool)$s->fetchColumn();
}

function mail_fetch_store(PDO $pdo, array &$acc, string $gmail_id, ?int $sent_by = null): ?int {
    [$c, $d] = mail_api($pdo, $acc, 'GET', 'messages/' . rawurlencode($gmail_id), ['format' => 'full']);
    if ($c !== 200) return null;
    return mail_store($pdo, $acc, mail_parse_gmail($d), $sent_by);
}

function mail_update_labels(PDO $pdo, int $org, string $gmail_id, array $labels): ?int {
    $s = $pdo->prepare("SELECT id, thread_id FROM mail_messages WHERE org_id = ? AND gmail_message_id = ?");
    $s->execute([$org, $gmail_id]);
    $m = $s->fetch(PDO::FETCH_ASSOC);
    if (!$m) return null;
    if (array_intersect($labels, ['SPAM', 'TRASH'])) {
        $pdo->prepare("DELETE FROM mail_messages WHERE id = ?")->execute([$m['id']]);
    } else {
        $pdo->prepare("UPDATE mail_messages SET label_ids = ? WHERE id = ?")->execute([implode(',', $labels), $m['id']]);
    }
    mail_refresh_thread($pdo, (int)$m['thread_id']);
    return (int)$m['thread_id'];
}

// ============================================================
// RATTACHEMENT ET TRI
// ============================================================

/** Relie le fil à un adhérent, un client, une facture. */
function mail_link_thread(PDO $pdo, int $org, int $thread_id): void {
    $s = $pdo->prepare("SELECT counterpart_email, subject FROM mail_threads WHERE id = ? AND org_id = ?");
    $s->execute([$thread_id, $org]);
    $t = $s->fetch(PDO::FETCH_ASSOC);
    if (!$t) return;
    $email = strtolower((string)$t['counterpart_email']);
    $user_id = $client_id = $invoice_id = null;
    if ($email !== '') {
        try {
            $q = $pdo->prepare("SELECT id FROM users WHERE org_id = ? AND LOWER(email) = ? AND deleted_at IS NULL LIMIT 1");
            $q->execute([$org, $email]); $user_id = $q->fetchColumn() ?: null;
        } catch (Throwable $e) {}
        try {
            $q = $pdo->prepare("SELECT id FROM asso_clients WHERE org_id = ? AND LOWER(email) = ? AND deleted_at IS NULL LIMIT 1");
            $q->execute([$org, $email]); $client_id = $q->fetchColumn() ?: null;
        } catch (Throwable $e) {}
    }
    // Numéro de facture cité dans l'objet ou le premier message
    try {
        $m = $pdo->prepare("SELECT body_text FROM mail_messages WHERE thread_id = ? ORDER BY sent_at ASC LIMIT 1");
        $m->execute([$thread_id]);
        $hay = ' ' . $t['subject'] . ' ' . mb_substr((string)$m->fetchColumn(), 0, 3000) . ' ';
        // Jetons contenant au moins 4 chiffres : « F-2026-0042 », « demo-formation-2026-000085 »…
        if (preg_match_all('/[A-Za-z0-9][A-Za-z0-9_\/.-]{2,60}/u', $hay, $mm)) {
            $cands = array_slice(array_values(array_unique(array_filter(
                array_map(fn($x) => rtrim($x, '.-_/'), $mm[0]),
                fn($x) => preg_match_all('/\d/', $x) >= 4
            ))), 0, 20);
            $q = $pdo->prepare("SELECT id, client_id FROM asso_invoices WHERE org_id = ? AND invoice_number = ? LIMIT 1");
            foreach ($cands as $num) {
                $q->execute([$org, $num]);
                if ($r = $q->fetch(PDO::FETCH_ASSOC)) { $invoice_id = (int)$r['id']; if (!$client_id && $r['client_id']) $client_id = (int)$r['client_id']; break; }
            }
        }
    } catch (Throwable $e) {}
    $pdo->prepare("UPDATE mail_threads SET linked_user_id = ?, linked_client_id = ?, linked_invoice_id = ? WHERE id = ?")
        ->execute([$user_id, $client_id, $invoice_id, $thread_id]);
}

function mail_norm(string $s): string {
    $s = mb_strtolower($s, 'UTF-8');
    $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
    if ($t !== false && $t !== '') $s = $t;
    return ' ' . trim(preg_replace('/[^a-z0-9@.\-]+/', ' ', $s)) . ' ';
}

/**
 * Règles : un mot-clé de la catégorie présent dans l'objet ; un mot-clé
 * contenant @ vise l'expéditeur (« @domaine.fr » ou « nom@domaine.fr »). Première catégorie
 * gagnante dans l'ordre défini par l'admin.
 */
function mail_rule_category(array $cats, string $subject, string $from): ?array {
    $subj = mail_norm($subject);
    $from = strtolower($from);
    foreach ($cats as $c) {
        foreach (array_filter(array_map('trim', explode(',', (string)$c['keywords']))) as $kw) {
            if (strpos($kw, '@') !== false) {
                // « @domaine.fr » = tout le domaine ; « nom@domaine.fr » = cet expéditeur
                $kwl = strtolower($kw);
                if ($from !== '' && ($kwl[0] === '@' ? substr($from, -strlen($kwl)) === $kwl : $from === $kwl)) return $c;
                continue;
            }
            $k = trim(mail_norm($kw));
            if ($k !== '' && strpos($subj, ' ' . $k . ' ') !== false) return $c;
        }
    }
    return null;
}

/** Trie les fils en attente : règles, puis IA par lots, puis « Autres ». */
function mail_classify_pending(PDO $pdo, array $acc, int $limit = 60): int {
    $org = (int)$acc['org_id'];
    $cats = mail_categories($pdo, $org);
    $by_slug = []; foreach ($cats as $c) $by_slug[$c['slug']] = $c;
    $autre = $by_slug['autre'] ?? end($cats);
    $s = $pdo->prepare("SELECT t.id, t.subject, t.counterpart_email, t.counterpart_name,
                               (SELECT body_text FROM mail_messages m WHERE m.thread_id = t.id ORDER BY sent_at ASC LIMIT 1) AS body
                          FROM mail_threads t WHERE t.org_id = ? AND t.category_source = 'none' ORDER BY t.last_message_at DESC LIMIT " . (int)$limit);
    $s->execute([$org]);
    $todo = $s->fetchAll(PDO::FETCH_ASSOC);
    if (!$todo) return 0;
    $set = $pdo->prepare("UPDATE mail_threads SET category_id = ?, category_source = ? WHERE id = ?");
    $reste = [];
    foreach ($todo as $t) {
        $c = mail_rule_category($cats, (string)$t['subject'], (string)$t['counterpart_email']);
        if ($c) $set->execute([$c['id'], 'rule', $t['id']]);
        else $reste[] = $t;
    }
    if ($reste && !empty($acc['ai_sort']) && function_exists('ask_claude') && function_exists('is_ai_enabled') && is_ai_enabled()) {
        foreach (array_chunk($reste, 20) as $lot) {
            $ai = mail_ai_classify($cats, $lot);
            foreach ($lot as $t) {
                $slug = $ai[(string)$t['id']] ?? null;
                if ($slug && isset($by_slug[$slug])) $set->execute([$by_slug[$slug]['id'], 'ai', $t['id']]);
                else $set->execute([$autre['id'], 'default', $t['id']]);
            }
        }
    } else {
        foreach ($reste as $t) $set->execute([$autre['id'], 'default', $t['id']]);
    }
    return count($todo);
}

/** Demande à l'IA la catégorie d'un lot de fils. @return array id => slug */
function mail_ai_classify(array $cats, array $lot): array {
    $liste = '';
    foreach ($cats as $c) $liste .= "- {$c['slug']} : {$c['label']}" . ($c['keywords'] ? " (ex. : " . mb_substr($c['keywords'], 0, 160) . ")" : '') . "\n";
    $mails = '';
    foreach ($lot as $t) {
        $corps = mb_substr(preg_replace('/\s+/u', ' ', mail_strip_quoted((string)$t['body'])), 0, 400);
        $mails .= "[{$t['id']}] De : {$t['counterpart_name']} <{$t['counterpart_email']}>\nObjet : {$t['subject']}\nDébut : {$corps}\n\n";
    }
    $prompt = "Classe chaque e-mail reçu par une association dans UNE des catégories suivantes :\n{$liste}\n"
            . "Utilise « autre » si aucune ne convient vraiment.\n\nE-mails :\n{$mails}"
            . "Réponds UNIQUEMENT par un objet JSON {\"<numéro>\": \"<slug>\"}, sans texte autour.";
    $r = ask_claude('Tu tries la boîte mail d\'une association française. Tu réponds uniquement en JSON valide.', [['role' => 'user', 'content' => $prompt]], 800);
    if (empty($r['success'])) return [];
    $txt = trim((string)$r['content']);
    if (preg_match('/\{.*\}/s', $txt, $m)) $txt = $m[0];
    $d = json_decode($txt, true);
    return is_array($d) ? array_map('strval', $d) : [];
}

// ============================================================
// RÉPONSE
// ============================================================

function mail_mime_header(string $s): string {
    return preg_match('/[^\x20-\x7E]/', $s) ? '=?UTF-8?B?' . base64_encode($s) . '?=' : $s;
}

function mail_format_address(string $email, string $name = ''): string {
    $name = trim(str_replace(['"', "\r", "\n"], '', $name));
    return $name !== '' ? mail_mime_header('"' . $name . '"') . ' <' . $email . '>' : $email;
}

function mail_text_to_html(string $text): string {
    $h = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    $h = preg_replace('~(https?://[^\s<]+)~', '<a href="$1">$1</a>', $h);
    return '<div style="font-family:Arial,sans-serif;font-size:14px;line-height:1.55;color:#1f2937;">' . nl2br($h) . '</div>';
}

/**
 * Construit le message RFC 2822 d'une réponse dans un fil.
 * @param array $to / $cc  listes d'adresses e-mail
 */
function mail_build_reply(array $acc, array $thread, array $last_in, array $to, array $cc, string $body): string {
    $subject = (string)($last_in['subject'] ?: $thread['subject']);
    if (!preg_match('/^re\s*:/i', $subject)) $subject = 'Re: ' . $subject;
    $refs = trim(((string)($last_in['references_hdr'] ?? '')) . ' ' . ((string)($last_in['rfc_message_id'] ?? '')));
    $refs = implode(' ', array_slice(array_unique(preg_split('/\s+/', $refs, -1, PREG_SPLIT_NO_EMPTY)), -20));
    $b = 'ak_' . bin2hex(random_bytes(8));
    $h = [
        'From: ' . mail_format_address($acc['email'], (string)$acc['display_name']),
        'To: ' . implode(', ', $to),
    ];
    if ($cc) $h[] = 'Cc: ' . implode(', ', $cc);
    $h[] = 'Subject: ' . mail_mime_header($subject);
    if (!empty($last_in['rfc_message_id'])) $h[] = 'In-Reply-To: ' . $last_in['rfc_message_id'];
    if ($refs !== '') $h[] = 'References: ' . $refs;
    $h[] = 'MIME-Version: 1.0';
    $h[] = 'Content-Type: multipart/alternative; boundary="' . $b . '"';
    return implode("\r\n", $h) . "\r\n\r\n"
        . "--$b\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
        . chunk_split(base64_encode($body)) . "\r\n"
        . "--$b\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
        . chunk_split(base64_encode(mail_text_to_html($body))) . "\r\n"
        . "--$b--\r\n";
}

/**
 * Envoie une réponse dans le fil (ou la simule pour la démo).
 * @return array ['ok' => bool, 'erreur' => ?string]
 */
function mail_send_reply(PDO $pdo, array $acc, array $thread, array $user, array $to, array $cc, string $body): array {
    $to = array_values(array_filter(array_map('trim', $to), fn($e) => filter_var($e, FILTER_VALIDATE_EMAIL)));
    $cc = array_values(array_filter(array_map('trim', $cc), fn($e) => filter_var($e, FILTER_VALIDATE_EMAIL)));
    if (!$to) return ['ok' => false, 'erreur' => 'Destinataire invalide.'];
    if (trim($body) === '') return ['ok' => false, 'erreur' => 'Message vide.'];
    if (!empty($acc['signature']) && strpos($body, trim($acc['signature'])) === false) $body = rtrim($body) . "\n\n" . trim($acc['signature']);

    // On répond au dernier message reçu (à défaut, au dernier du fil)
    $s = $pdo->prepare("SELECT * FROM mail_messages WHERE thread_id = ? ORDER BY (direction = 'in') DESC, sent_at DESC, id DESC LIMIT 1");
    $s->execute([$thread['id']]);
    $last = $s->fetch(PDO::FETCH_ASSOC) ?: [];

    $simulate = $acc['provider'] !== 'gmail';
    if (!$simulate) {
        require_once __DIR__ . '/demo-guard.php';
        $simulate = ak_demo_session();
    }
    if ($simulate) {
        // Démonstration : le message est rangé dans le fil, rien ne part
        mail_store($pdo, $acc, [
            'gmail_id' => 'demo-' . bin2hex(random_bytes(6)), 'thread_id' => $thread['gmail_thread_id'], 'labels' => ['SENT'],
            'rfc_id' => '', 'references' => '', 'from_email' => strtolower($acc['email']), 'from_name' => (string)$acc['display_name'],
            'reply_to' => null, 'to' => array_map(fn($e) => ['email' => $e, 'name' => ''], $to), 'cc' => array_map(fn($e) => ['email' => $e, 'name' => ''], $cc),
            'subject' => 'Re: ' . $thread['subject'], 'text' => $body, 'html' => '', 'attachments' => [], 'sent_at' => date('Y-m-d H:i:s'), 'snippet' => '',
        ], (int)$user['id']);
        return ['ok' => true, 'erreur' => null, 'simule' => true];
    }

    $raw = mail_build_reply($acc, $thread, $last, $to, $cc, $body);
    [$c, $d] = mail_api($pdo, $acc, 'POST', 'messages/send', [], ['raw' => mail_b64url_encode($raw), 'threadId' => $thread['gmail_thread_id']]);
    if ($c !== 200 || empty($d['id'])) return ['ok' => false, 'erreur' => mail_api_error($c, $d)];
    if (!mail_fetch_store($pdo, $acc, $d['id'], (int)$user['id'])) {
        // Relecture impossible : on garde quand même une trace locale
        mail_store($pdo, $acc, [
            'gmail_id' => $d['id'], 'thread_id' => $thread['gmail_thread_id'], 'labels' => $d['labelIds'] ?? ['SENT'],
            'rfc_id' => '', 'references' => '', 'from_email' => strtolower($acc['email']), 'from_name' => (string)$acc['display_name'],
            'reply_to' => null, 'to' => array_map(fn($e) => ['email' => $e, 'name' => ''], $to), 'cc' => array_map(fn($e) => ['email' => $e, 'name' => ''], $cc),
            'subject' => 'Re: ' . $thread['subject'], 'text' => $body, 'html' => '', 'attachments' => [], 'sent_at' => date('Y-m-d H:i:s'), 'snippet' => '',
        ], (int)$user['id']);
    }
    return ['ok' => true, 'erreur' => null];
}

/** Marque le fil comme lu, dans Assokit et dans Gmail. */
function mail_mark_read(PDO $pdo, array $acc, array $thread, bool $read = true): void {
    $msgs = $pdo->prepare("SELECT id, label_ids FROM mail_messages WHERE thread_id = ?");
    $msgs->execute([$thread['id']]);
    $up = $pdo->prepare("UPDATE mail_messages SET label_ids = ? WHERE id = ?");
    foreach ($msgs->fetchAll(PDO::FETCH_ASSOC) as $m) {
        $l = array_filter(explode(',', (string)$m['label_ids']));
        $l = $read ? array_values(array_diff($l, ['UNREAD'])) : array_values(array_unique(array_merge($l, ['UNREAD'])));
        $up->execute([implode(',', $l), $m['id']]);
    }
    mail_refresh_thread($pdo, (int)$thread['id']);
    if ($acc['provider'] === 'gmail' && strncmp((string)$thread['gmail_thread_id'], 'demo', 4) !== 0) {
        mail_api($pdo, $acc, 'POST', 'threads/' . rawurlencode($thread['gmail_thread_id']) . '/modify', [],
            $read ? ['removeLabelIds' => ['UNREAD']] : ['addLabelIds' => ['UNREAD']]);
    }
}

// ============================================================
// BROUILLON IA
// ============================================================

/** Contexte métier du fil pour l'IA : adhérent, client, facture. */
function mail_thread_context(PDO $pdo, int $org, array $t): string {
    $ctx = [];
    try {
        if ($t['linked_user_id']) {
            $s = $pdo->prepare("SELECT first_name, last_name, role, adhesion_valid_until FROM users WHERE id = ? AND org_id = ?");
            $s->execute([$t['linked_user_id'], $org]);
            if ($u = $s->fetch(PDO::FETCH_ASSOC)) {
                $ctx[] = "Expéditeur connu : {$u['first_name']} {$u['last_name']}, rôle « {$u['role']} »"
                       . ($u['adhesion_valid_until'] ? ', adhésion valable jusqu\'au ' . date('d/m/Y', strtotime($u['adhesion_valid_until'])) : '') . '.';
            }
        }
        if ($t['linked_client_id']) {
            $s = $pdo->prepare("SELECT display_name FROM asso_clients WHERE id = ? AND org_id = ?");
            $s->execute([$t['linked_client_id'], $org]);
            if ($name = $s->fetchColumn()) {
                $ctx[] = "Client de facturation : {$name}.";
                $s = $pdo->prepare("SELECT invoice_number, amount_ttc_cents, due_at, status FROM asso_invoices WHERE org_id = ? AND client_id = ? AND status NOT IN ('paid','cancelled','draft') ORDER BY due_at LIMIT 5");
                $s->execute([$org, $t['linked_client_id']]);
                foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $i) {
                    $ctx[] = "Facture non réglée {$i['invoice_number']} : " . number_format($i['amount_ttc_cents'] / 100, 2, ',', ' ') . ' €' . ($i['due_at'] ? ', échéance ' . date('d/m/Y', strtotime($i['due_at'])) : '') . '.';
                }
            }
        }
        if ($t['linked_invoice_id']) {
            $s = $pdo->prepare("SELECT invoice_number, amount_ttc_cents, status, due_at, paid_at FROM asso_invoices WHERE id = ? AND org_id = ?");
            $s->execute([$t['linked_invoice_id'], $org]);
            if ($i = $s->fetch(PDO::FETCH_ASSOC)) {
                $ctx[] = "Facture citée {$i['invoice_number']} : " . number_format($i['amount_ttc_cents'] / 100, 2, ',', ' ') . " €, statut « {$i['status']} »"
                       . ($i['paid_at'] ? ', payée le ' . date('d/m/Y', strtotime($i['paid_at'])) : ($i['due_at'] ? ', échéance ' . date('d/m/Y', strtotime($i['due_at'])) : '')) . '.';
            }
        }
    } catch (Throwable $e) {}
    return implode("\n", $ctx);
}

/** @return array ['ok' => bool, 'texte' => string, 'erreur' => ?string] */
function mail_ai_draft(PDO $pdo, array $acc, array $t, array $user, string $consigne = ''): array {
    if (!function_exists('ask_claude') || !is_ai_enabled()) return ['ok' => false, 'texte' => '', 'erreur' => 'IA non configurée.'];
    $org = (int)$acc['org_id'];
    $s = $pdo->prepare("SELECT direction, from_name, from_email, body_text, sent_at FROM mail_messages WHERE thread_id = ? ORDER BY sent_at DESC, id DESC LIMIT 6");
    $s->execute([$t['id']]);
    $fil = '';
    foreach (array_reverse($s->fetchAll(PDO::FETCH_ASSOC)) as $m) {
        $qui = $m['direction'] === 'out' ? 'NOUS (l\'association)' : trim($m['from_name'] . ' <' . $m['from_email'] . '>');
        $fil .= '— ' . date('d/m/Y H:i', strtotime($m['sent_at'])) . " — {$qui} :\n" . mb_substr(mail_strip_quoted((string)$m['body_text']), 0, 2500) . "\n\n";
    }
    $o = $pdo->prepare("SELECT name FROM organizations WHERE id = ?");
    $o->execute([$org]);
    $asso = (string)$o->fetchColumn();
    $ctx = mail_thread_context($pdo, $org, $t);
    $prompt = "Association : {$asso}\nRédacteur de la réponse : {$user['first_name']} {$user['last_name']}\n"
            . ($ctx ? "Informations Assokit utiles :\n{$ctx}\n" : '')
            . "\nFil d'e-mails (du plus ancien au plus récent) :\n{$fil}"
            . ($consigne !== '' ? "Consigne pour la réponse : {$consigne}\n\n" : '')
            . "Rédige la réponse au dernier message reçu. Français, ton professionnel et chaleureux, phrases courtes. "
            . "Commence par la formule d'appel, termine par une formule de politesse et le prénom + nom du rédacteur. "
            . "N'invente aucun fait, montant ou date absent des informations ci-dessus ; s'il manque une information, écris [à compléter]. "
            . "Pas d'objet, pas de markdown : uniquement le texte de l'e-mail.";
    $r = ask_claude('Tu aides une association à répondre à ses e-mails.', [['role' => 'user', 'content' => $prompt]], 900);
    if (empty($r['success'])) return ['ok' => false, 'texte' => '', 'erreur' => $r['error'] ?? 'IA indisponible'];
    return ['ok' => true, 'texte' => trim((string)$r['content']), 'erreur' => null];
}

// ============================================================
// RÉTENTION (RGPD)
// ============================================================

function mail_purge_old(PDO $pdo, array $acc): int {
    $mois = max(1, (int)$acc['retention_months']);
    $s = $pdo->prepare("SELECT id FROM mail_threads WHERE org_id = ? AND last_message_at < DATE_SUB(NOW(), INTERVAL $mois MONTH)");
    $s->execute([$acc['org_id']]);
    $ids = $s->fetchAll(PDO::FETCH_COLUMN);
    if (!$ids) return 0;
    $in = implode(',', array_map('intval', $ids));
    $pdo->exec("DELETE FROM mail_messages WHERE thread_id IN ($in)");
    $pdo->exec("DELETE FROM mail_threads WHERE id IN ($in)");
    return count($ids);
}

/** Non-lus visibles pour le badge du menu. */
function mail_unread_count(PDO $pdo, array $user): int {
    if (!mail_can_access($user) || !mail_schema_ready($pdo)) return 0;
    try {
        $cats = mail_visible_categories($pdo, (int)$user['org_id'], $user);
        if (!$cats) return 0;
        $ids = implode(',', array_map(fn($c) => (int)$c['id'], $cats));
        $s = $pdo->prepare("SELECT COUNT(*) FROM mail_threads WHERE org_id = ? AND unread = 1 AND is_archived = 0 AND category_id IN ($ids)");
        $s->execute([(int)$user['org_id']]);
        return (int)$s->fetchColumn();
    } catch (Throwable $e) { return 0; }
}

/** Fil accessible à cet utilisateur (même asso, catégorie visible). */
function mail_thread_for_user(PDO $pdo, array $user, int $thread_id): ?array {
    if (!mail_can_access($user)) return null;
    $s = $pdo->prepare("SELECT * FROM mail_threads WHERE id = ? AND org_id = ?");
    $s->execute([$thread_id, (int)$user['org_id']]);
    $t = $s->fetch(PDO::FETCH_ASSOC);
    if (!$t) return null;
    if (mail_can_manage($user)) return $t;
    if ($t['category_id'] === null) return null;   // pas encore trié : réservé aux admins
    foreach (mail_visible_categories($pdo, (int)$user['org_id'], $user) as $c) {
        if ((int)$c['id'] === (int)$t['category_id']) return $t;
    }
    return null;
}
