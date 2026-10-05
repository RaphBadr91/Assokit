<?php
/**
 * api/app-mail.php — Boîte mail pour l'écran natif de l'application.
 * JSON, authentifié par session, mêmes droits que /boite-mail.
 *
 *   GET ?view=main|prio|unread|archived|cat&cat=<slug>&q=&p=   → liste
 *   GET ?thread=<id>                                          → conversation (marquée lue)
 *
 * Les actions (répondre, brouillon IA, archiver, catégorie, synchroniser)
 * passent par /boite-mail-action (POST JSON + jeton CSRF), comme sur le site.
 */
ob_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes-layout.php';
require_once __DIR__ . '/../mail-helpers.php';
require_once __DIR__ . '/../demo-guard.php';
ob_end_clean();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$out = function (array $d, int $code = 200) { http_response_code($code); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; };
if (empty($_SESSION['user_id'])) $out(['ok' => false, 'error' => 'auth'], 401);

$user = current_user();
if (!mail_can_access($user)) $out(['ok' => false, 'error' => 'Boîte mail réservée aux administrateurs et coordinateurs.'], 403);
if (!mail_schema_ready($pdo)) $out(['ok' => false, 'error' => 'Boîte mail non installée sur ce serveur.'], 503);
$org = (int)$user['org_id'];
$acc = mail_get_account($pdo, $org);

$fr_when = function (?string $d): string {
    if (!$d) return '';
    $t = strtotime($d);
    if (date('Y-m-d', $t) === date('Y-m-d')) return date('H:i', $t);
    if (date('Y-m-d', $t) === date('Y-m-d', strtotime('-1 day'))) return 'Hier';
    $m = ['', 'janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'];
    return (int)date('j', $t) . ' ' . $m[(int)date('n', $t)] . (date('Y', $t) !== date('Y') ? ' ' . date('Y', $t) : '');
};
$initials = function (string $name, string $email): string {
    $p = preg_split('/[^\p{L}\p{N}]+/u', trim($name) !== '' ? $name : $email, -1, PREG_SPLIT_NO_EMPTY);
    return mb_strtoupper(mb_substr($p[0] ?? '?', 0, 1) . mb_substr($p[1] ?? '', 0, 1));
};
$color = fn($s) => ['#0EA5E9', '#10B981', '#8B5CF6', '#F59E0B', '#EC4899', '#14B8A6', '#6366F1', '#64748B'][abs(crc32((string)$s)) % 8];

$base = [
    'ok' => true,
    'account' => $acc ? ['email' => $acc['email'], 'status' => $acc['status'], 'error' => $acc['status'] !== 'active' ? $acc['last_error'] : null,
                         'demo' => $acc['provider'] !== 'gmail' || ak_demo_session(), 'last_sync' => $acc['last_sync_at'] ? $fr_when($acc['last_sync_at']) : null] : null,
    'can_connect' => mail_can_connect($user),
    'connect_url' => 'https://assokit.fr/boite-mail',
];
if (!$acc) $out($base + ['threads' => [], 'categories' => [], 'counts' => ['main' => 0, 'prio' => 0]]);

$cats = mail_visible_categories($pdo, $org, $user);
$by_id = []; $by_slug = [];
foreach ($cats as $c) { $by_id[(int)$c['id']] = $c; $by_slug[$c['slug']] = $c; }
$in = $by_id ? implode(',', array_keys($by_id)) : '0';
$scope = mail_can_manage($user) ? "(t.category_id IN ($in) OR t.category_id IS NULL)" : "t.category_id IN ($in)";
$promos = (int)($by_slug['promos']['id'] ?? 0);

// ---------- Conversation ----------
if (isset($_GET['thread'])) {
    $t = mail_thread_for_user($pdo, $user, (int)$_GET['thread']);
    if (!$t) $out(['ok' => false, 'error' => 'Conversation introuvable.'], 404);
    if ((int)$t['unread'] === 1) mail_mark_read($pdo, $acc, $t, true);
    $s = $pdo->prepare("SELECT * FROM mail_messages WHERE thread_id = ? ORDER BY sent_at ASC, id ASC");
    $s->execute([$t['id']]);
    $msgs = []; $reply_to = '';
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $m) {
        $text = mail_fix_mojibake(mail_strip_quoted((string)$m['body_text']) ?: (string)$m['body_text']);
        $msgs[] = [
            'id' => (int)$m['id'], 'out' => $m['direction'] === 'out',
            'from' => $m['direction'] === 'out' ? ($m['from_name'] ?: 'Vous') : ($m['from_name'] ?: $m['from_email']),
            'email' => $m['from_email'], 'initials' => $initials((string)$m['from_name'], (string)$m['from_email']),
            'color' => $m['direction'] === 'out' ? '#059669' : $color($m['from_email']),
            'date' => date('d/m/Y H:i', strtotime($m['sent_at'])),
            'text' => mb_substr($text, 0, 20000),
            'attachments' => array_map(fn($a) => $a['name'], json_decode((string)$m['attachments_json'], true) ?: []),
        ];
        if ($m['direction'] === 'in') $reply_to = $m['reply_to'] ?: $m['from_email'];
    }
    $c = $by_id[(int)$t['category_id']] ?? null;
    $out($base + [
        'thread' => [
            'id' => (int)$t['id'], 'subject' => mail_fix_mojibake(mail_clean_subject((string)$t['subject'])),
            'priority' => $t['priority'] ?? 'normal', 'reason' => $t['priority_reason'] ?? null,
            'category_id' => $c ? (int)$c['id'] : null, 'category' => $c ? $c['label'] : null, 'color' => $c ? $c['color'] : null,
            'archived' => (int)$t['is_archived'] === 1,
        ],
        'messages' => $msgs,
        'reply_to' => $reply_to ?: (string)$t['counterpart_email'],
        'categories' => array_map(fn($c) => ['id' => (int)$c['id'], 'label' => $c['label'], 'color' => $c['color']], $cats),
    ]);
}

// ---------- Liste ----------
$view = (string)($_GET['view'] ?? 'main');
$w = ['t.org_id = ?', $scope, $view === 'archived' ? 't.is_archived = 1' : 't.is_archived = 0'];
$args = [$org];
$order = 't.last_message_at DESC';
if ($view === 'cat' && isset($by_slug[(string)($_GET['cat'] ?? '')])) { $w[] = 't.category_id = ?'; $args[] = (int)$by_slug[$_GET['cat']]['id']; }
elseif (in_array($view, ['main', 'unread'], true) && $promos) { $w[] = '(t.category_id IS NULL OR t.category_id <> ?)'; $args[] = $promos; }
if ($view === 'unread') $w[] = 't.unread = 1';
if ($view === 'prio') { $w[] = "t.priority IN ('urgent','important') AND t.last_direction = 'in'"; $order = "FIELD(t.priority, 'urgent', 'important'), t.last_message_at DESC"; }
$q = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 100);
if ($q !== '') {
    $w[] = '(t.subject LIKE ? OR t.counterpart_email LIKE ? OR t.counterpart_name LIKE ? OR t.snippet LIKE ?)';
    $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
    array_push($args, $like, $like, $like, $like);
}
$page = max(1, (int)($_GET['p'] ?? 1)); $per = 30;
$s = $pdo->prepare("SELECT t.* FROM mail_threads t WHERE " . implode(' AND ', $w) . " ORDER BY $order LIMIT " . ($per + 1) . " OFFSET " . (($page - 1) * $per));
$s->execute($args);
$rows = $s->fetchAll(PDO::FETCH_ASSOC);
$threads = [];
foreach (array_slice($rows, 0, $per) as $t) {
    $c = $by_id[(int)$t['category_id']] ?? null;
    $threads[] = [
        'id' => (int)$t['id'],
        'from' => $t['counterpart_name'] ?: ($t['counterpart_email'] ?: '—'),
        'initials' => $initials((string)$t['counterpart_name'], (string)$t['counterpart_email']),
        'color' => $color($t['counterpart_email']),
        'subject' => mail_fix_mojibake((string)$t['subject']), 'snippet' => mail_fix_mojibake((string)$t['snippet']),
        'date' => $fr_when($t['last_message_at']), 'unread' => (int)$t['unread'] === 1, 'replied' => $t['last_direction'] === 'out',
        'count' => (int)$t['message_count'], 'priority' => $t['priority'] ?? 'normal', 'reason' => $t['priority_reason'] ?? null,
        'category' => $c ? $c['label'] : null, 'cat_color' => $c ? $c['color'] : null,
    ];
}

// Compteurs (onglets)
$cnt = ['main' => 0, 'prio' => 0];
$s = $pdo->prepare("SELECT category_id, SUM(unread) u, COUNT(*) n FROM mail_threads t WHERE t.org_id = ? AND t.is_archived = 0 AND $scope GROUP BY category_id");
$s->execute([$org]);
$per_cat = [];
foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $per_cat[(int)$r['category_id']] = ['u' => (int)$r['u'], 'n' => (int)$r['n']];
    if ((int)$r['category_id'] !== $promos) $cnt['main'] += (int)$r['u'];
}
$s = $pdo->prepare("SELECT COUNT(*) FROM mail_threads t WHERE t.org_id = ? AND t.is_archived = 0 AND $scope AND t.priority IN ('urgent','important') AND t.last_direction = 'in'");
$s->execute([$org]);
$cnt['prio'] = (int)$s->fetchColumn();

$out($base + [
    'threads' => $threads, 'more' => count($rows) > $per, 'page' => $page, 'counts' => $cnt,
    'categories' => array_values(array_map(fn($c) => ['slug' => $c['slug'], 'label' => $c['label'], 'color' => $c['color'],
        'unread' => $per_cat[(int)$c['id']]['u'] ?? 0, 'total' => $per_cat[(int)$c['id']]['n'] ?? 0], array_filter($cats, fn($c) => ($per_cat[(int)$c['id']]['n'] ?? 0) > 0))),
]);
