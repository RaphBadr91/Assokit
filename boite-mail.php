<?php
/**
 * ============================================================
 * ASSOKIT — Boîte mail (Gmail)
 * ============================================================
 * /boite-mail                 boîte principale (sans newsletters)
 * /boite-mail?c=<slug>        une catégorie
 * /boite-mail?f=prio          à traiter en priorité (urgents + importants)
 * /boite-mail?f=unread|archived ; q=recherche ; t=<id> conversation
 *
 * Navigation fluide : la liste et la conversation se chargent sans
 * recharger la page (?partial=list|thread renvoie du JSON avec le HTML),
 * l'adresse suit (history.pushState), chargement progressif au défilement.
 * ============================================================
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes-layout.php';
require_once __DIR__ . '/mail-helpers.php';
@require_once __DIR__ . '/ai-helper.php';
require_once __DIR__ . '/demo-guard.php';
require_once __DIR__ . '/finance-permissions.php';
require_login();

$user = current_user();
$org = (int)$user['org_id'];
$partial = (string)($_GET['partial'] ?? '');

if (!mail_can_access($user)) {
    http_response_code(403);
    if ($partial !== '') { header('Content-Type: application/json'); echo json_encode(['ok' => false, 'error' => 'Accès réservé.']); exit; }
    render_head('Boîte mail');
    render_sidebar('boite-mail');
    echo '<main class="main"><div class="bm-deny">' . ak_icon('lock', 40, '1.5') . '<h1>Accès réservé</h1><p>La boîte mail de l’association est réservée aux administrateurs et coordinateurs.</p><a href="/dashboard">← Retour</a></div></main>';
    echo '<style>.bm-deny{max-width:520px;margin:70px auto;text-align:center;background:#fff;border:1px solid #E2E8F0;border-radius:16px;padding:32px;color:#475569}.bm-deny h1{font-size:20px;color:#0F172A;margin:10px 0}.bm-deny a{color:#059669;font-weight:600}</style>';
    render_foot();
    exit;
}

$ready = mail_schema_ready($pdo);
$acc = $ready ? mail_get_account($pdo, $org) : null;
$manage = mail_can_manage($user);
$can_connect = mail_can_connect($user);
$demo = ak_demo_session();
$csrf = $_SESSION['csrf_token'] ?? '';

$cats = $ready ? mail_visible_categories($pdo, $org, $user) : [];
$cat_by_id = []; $cat_by_slug = [];
foreach ($cats as $c) { $cat_by_id[(int)$c['id']] = $c; $cat_by_slug[$c['slug']] = $c; }
$in_cats = $cat_by_id ? implode(',', array_keys($cat_by_id)) : '0';
// Les e-mails pas encore triés restent visibles de toute l'équipe mail
$scope = "(t.category_id IN ($in_cats) OR t.category_id IS NULL)";
$promos_id = isset($cat_by_slug['promos']) ? (int)$cat_by_slug['promos']['id'] : 0;
$has_prio = $ready && (function () use ($pdo) { try { $pdo->query("SELECT priority FROM mail_threads LIMIT 0"); return true; } catch (Throwable $e) { return false; } })();

// ============================================================
// Petits utilitaires d'affichage
// ============================================================
function bm_when(?string $d): string {
    if (!$d) return '';
    $t = strtotime($d);
    if (date('Y-m-d', $t) === date('Y-m-d')) return date('H:i', $t);
    $m = ['', 'janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'];
    if (date('Y', $t) === date('Y')) return (int)date('j', $t) . ' ' . $m[(int)date('n', $t)];
    return date('d/m/Y', $t);
}
function bm_group(?string $d): string {
    if (!$d) return '';
    $t = strtotime(date('Y-m-d', strtotime($d)));
    $today = strtotime('today');
    if ($t >= $today) return 'Aujourd’hui';
    if ($t >= $today - 86400) return 'Hier';
    if ($t >= $today - 6 * 86400) return 'Cette semaine';
    $m = ['', 'Janvier', 'Février', 'Mars', 'Avril', 'Mai', 'Juin', 'Juillet', 'Août', 'Septembre', 'Octobre', 'Novembre', 'Décembre'];
    return $m[(int)date('n', $t)] . (date('Y', $t) !== date('Y') ? ' ' . date('Y', $t) : '');
}
function bm_initials(string $name, string $email): string {
    $src = trim($name) !== '' ? $name : $email;
    $p = preg_split('/[^\p{L}\p{N}]+/u', $src, -1, PREG_SPLIT_NO_EMPTY);
    return mb_strtoupper(mb_substr($p[0] ?? '?', 0, 1) . mb_substr($p[1] ?? '', 0, 1));
}
function bm_color(?string $s): string {
    return ['#0EA5E9', '#10B981', '#8B5CF6', '#F59E0B', '#EC4899', '#14B8A6', '#6366F1', '#64748B'][abs(crc32((string)$s)) % 8];
}
function bm_body(string $text): string {
    $h = htmlspecialchars(mail_fix_mojibake($text), ENT_QUOTES, 'UTF-8');
    $h = preg_replace('~(https?://[^\s<>"\']{4,})~', '<a href="$1" target="_blank" rel="noopener noreferrer nofollow">$1</a>', $h);
    return nl2br($h);
}
function bm_prio_pill(array $t): string {
    $p = $t['priority'] ?? 'normal';
    if ($p === 'urgent') return '<i class="bm-pri urgent">Urgent</i>';
    if ($p === 'important') return '<i class="bm-pri important">Important</i>';
    return '';
}

// ============================================================
// Données
// ============================================================

/** Compteurs du panneau de gauche. */
function bm_counts(PDO $pdo, int $org, string $scope, int $promos_id, bool $has_prio): array {
    $out = ['by' => [], 'main_u' => 0, 'prio' => 0];
    $s = $pdo->prepare("SELECT category_id, COUNT(*) n, SUM(unread) u FROM mail_threads t WHERE t.org_id = ? AND t.is_archived = 0 AND $scope GROUP BY category_id");
    $s->execute([$org]);
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out['by'][(int)$r['category_id']] = ['n' => (int)$r['n'], 'u' => (int)$r['u']];
        if ((int)$r['category_id'] !== $promos_id) $out['main_u'] += (int)$r['u'];
    }
    if ($has_prio) {
        $s = $pdo->prepare("SELECT COUNT(*) FROM mail_threads t WHERE t.org_id = ? AND t.is_archived = 0 AND $scope AND t.priority IN ('urgent','important') AND t.last_direction = 'in'");
        $s->execute([$org]);
        $out['prio'] = (int)$s->fetchColumn();
    }
    return $out;
}

/** Liste des conversations selon les filtres. @return [lignes, il y en a d'autres] */
function bm_threads(PDO $pdo, int $org, string $scope, array $o): array {
    $w = ['t.org_id = ?', $scope, $o['f'] === 'archived' ? 't.is_archived = 1' : 't.is_archived = 0'];
    $args = [$org];
    $order = 't.last_message_at DESC';
    if ($o['cat']) { $w[] = 't.category_id = ?'; $args[] = (int)$o['cat']['id']; }
    elseif ($o['f'] === '' || $o['f'] === 'unread') { if ($o['promos_id']) { $w[] = '(t.category_id IS NULL OR t.category_id <> ?)'; $args[] = $o['promos_id']; } }
    if ($o['f'] === 'unread') $w[] = 't.unread = 1';
    if ($o['f'] === 'prio' && $o['has_prio']) {
        $w[] = "t.priority IN ('urgent','important') AND t.last_direction = 'in'";
        $order = "FIELD(t.priority, 'urgent', 'important'), t.last_message_at DESC";
    }
    if ($o['q'] !== '') {
        $w[] = '(t.subject LIKE ? OR t.counterpart_email LIKE ? OR t.counterpart_name LIKE ? OR t.snippet LIKE ?)';
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $o['q']) . '%';
        array_push($args, $like, $like, $like, $like);
    }
    $per = 40;
    $s = $pdo->prepare("SELECT t.* FROM mail_threads t WHERE " . implode(' AND ', $w) . " ORDER BY $order LIMIT " . ($per + 1) . " OFFSET " . ((max(1, $o['page']) - 1) * $per));
    $s->execute($args);
    $rows = $s->fetchAll(PDO::FETCH_ASSOC);
    return [array_slice($rows, 0, $per), count($rows) > $per];
}

/** HTML des lignes, avec intertitres de date (sauf en vue « priorités »). */
function bm_rows_html(array $rows, array $cat_by_id, bool $show_tag, string $last_group, bool $grouped, ?int $open_id): string {
    $h = '';
    foreach ($rows as $t) {
        if ($grouped) {
            $g = bm_group($t['last_message_at']);
            if ($g !== $last_group) { $h .= '<div class="bm-grp" data-g="' . h($g) . '">' . h($g) . '</div>'; $last_group = $g; }
        }
        $c = $cat_by_id[(int)$t['category_id']] ?? null;
        $name = $t['counterpart_name'] ?: $t['counterpart_email'] ?: '—';
        $reason = in_array($t['priority'] ?? '', ['urgent', 'important'], true) ? trim((string)($t['priority_reason'] ?? '')) : '';
        $h .= '<a class="bm-row' . ((int)$t['unread'] ? ' unread' : '') . ($open_id === (int)$t['id'] ? ' on' : '') . '" href="?t=' . (int)$t['id'] . '" data-id="' . (int)$t['id'] . '">'
            . '<span class="bm-av" style="background:' . bm_color($t['counterpart_email']) . '">' . h(bm_initials((string)$t['counterpart_name'], (string)$t['counterpart_email'])) . '</span>'
            . '<span class="bm-r">'
            . '<span class="bm-r1"><b class="bm-from">' . h($name) . '</b>' . ((int)$t['message_count'] > 1 ? '<small class="bm-n">' . (int)$t['message_count'] . '</small>' : '')
            . '<span class="bm-date">' . h(bm_when($t['last_message_at'])) . '</span></span>'
            . '<span class="bm-r2">' . ($t['last_direction'] === 'out' ? '<span class="bm-replied" title="Répondu">↩</span>' : '')
            . '<span class="bm-subj">' . h(mail_fix_mojibake((string)$t['subject'])) . '</span><span class="bm-snip"> — ' . h(mail_fix_mojibake((string)$t['snippet'])) . '</span></span>';
        $pill = bm_prio_pill($t);
        $tag = $c && $show_tag ? '<span class="bm-tag" style="--c:' . h($c['color']) . '" title="' . h($c['label']) . ($t['category_source'] === 'ai' ? ' · rangé par l’IA' : '') . '">' . h($c['label']) . '</span>' : '';
        if ($pill !== '' || $tag !== '' || $reason !== '') {
            $h .= '<span class="bm-r3">' . $pill . $tag . ($reason !== '' ? '<span class="bm-reason">' . h($reason) . '</span>' : '') . '</span>';
        }
        $h .= '</span></a>';
    }
    return $h;
}

/** Conversation complète (et marquée lue). */
function bm_thread_data(PDO $pdo, array $acc, array $user, int $org, int $id): ?array {
    $t = mail_thread_for_user($pdo, $user, $id);
    if (!$t) return null;
    if ((int)$t['unread'] === 1) { mail_mark_read($pdo, $acc, $t, true); $t['unread'] = 0; }
    $s = $pdo->prepare("SELECT * FROM mail_messages WHERE thread_id = ? ORDER BY sent_at ASC, id ASC");
    $s->execute([$t['id']]);
    $msgs = $s->fetchAll(PDO::FETCH_ASSOC);
    $msgs = mail_with_authors($pdo, $org, $msgs);
    $links = [];
    $statuts = ['paid' => 'payée', 'pending' => 'en attente', 'overdue' => 'en retard', 'sent' => 'envoyée', 'draft' => 'brouillon', 'cancelled' => 'annulée'];
    try {
        if ($t['linked_user_id']) {
            $s = $pdo->prepare("SELECT id, first_name, last_name FROM users WHERE id = ? AND org_id = ?");
            $s->execute([$t['linked_user_id'], $org]);
            if ($u = $s->fetch(PDO::FETCH_ASSOC)) $links[] = ['users', 'Fiche ' . trim($u['first_name'] . ' ' . $u['last_name']), '/adherent/' . (int)$u['id']];
        }
        if ($t['linked_client_id'] && user_can_access_billing($user)) {
            $s = $pdo->prepare("SELECT id, display_name FROM asso_clients WHERE id = ? AND org_id = ?");
            $s->execute([$t['linked_client_id'], $org]);
            if ($c = $s->fetch(PDO::FETCH_ASSOC)) $links[] = ['building', 'Client ' . $c['display_name'], '/mon-asso-client-detail?id=' . (int)$c['id']];
        }
        if ($t['linked_invoice_id'] && user_can_access_billing($user)) {
            $s = $pdo->prepare("SELECT id, invoice_number, status, amount_ttc_cents FROM asso_invoices WHERE id = ? AND org_id = ?");
            $s->execute([$t['linked_invoice_id'], $org]);
            if ($i = $s->fetch(PDO::FETCH_ASSOC)) $links[] = ['receipt', 'Facture ' . $i['invoice_number'] . ' · ' . number_format($i['amount_ttc_cents'] / 100, 2, ',', ' ') . ' € · ' . ($statuts[$i['status']] ?? $i['status']), '/mon-asso-facture-edit?id=' . (int)$i['id']];
        }
    } catch (Throwable $e) {}
    $reply_to = '';
    foreach (array_reverse($msgs) as $m) if ($m['direction'] === 'in') { $reply_to = $m['reply_to'] ?: $m['from_email']; break; }
    if ($reply_to === '') $reply_to = (string)$t['counterpart_email'];
    return ['t' => $t, 'msgs' => $msgs, 'links' => $links, 'reply_to' => $reply_to];
}

/** HTML du panneau de lecture. */
function bm_reader_html(array $d, array $cats, array $cat_by_id, array $acc, bool $manage, bool $demo): string {
    $t = $d['t'];
    $cc = $cat_by_id[(int)$t['category_id']] ?? null;
    ob_start(); ?>
    <div class="bm-read-head">
      <button type="button" class="bm-back" data-close>← Retour</button>
      <h2><?= h(mail_fix_mojibake(mail_clean_subject((string)$t['subject']))) ?></h2>
      <?php if (in_array($t['priority'] ?? '', ['urgent', 'important'], true)): ?>
        <div class="bm-alert-prio <?= h($t['priority']) ?>">⚡ <?= $t['priority'] === 'urgent' ? 'À traiter en urgence' : 'Réponse attendue' ?><?= !empty($t['priority_reason']) ? ' — ' . h($t['priority_reason']) : '' ?></div>
      <?php endif; ?>
      <div class="bm-read-tools">
        <label class="bm-catsel" style="--c:<?= h($cc['color'] ?? '#94A3B8') ?>">
          <select data-cat>
            <?php foreach ($cats as $c): ?><option value="<?= (int)$c['id'] ?>"<?= $cc && $cc['id'] == $c['id'] ? ' selected' : '' ?>><?= h($c['label']) ?></option><?php endforeach; ?>
          </select>
        </label>
        <?php if ($t['category_source'] === 'ai'): ?><span class="bm-pill">Rangé par l’IA</span><?php elseif ($t['category_source'] === 'rule'): ?><span class="bm-pill">Rangé par règle</span><?php endif; ?>
        <span class="bm-sp"></span>
        <button type="button" class="bm-ic" data-act="unread" title="Marquer non lu">✉︎</button>
        <button type="button" class="bm-ic" data-act="<?= (int)$t['is_archived'] ? 'unarchive' : 'archive' ?>" title="<?= (int)$t['is_archived'] ? 'Désarchiver' : 'Archiver (touche E)' ?>"><?= ak_icon('download', 15) ?></button>
        <?php if ($acc['provider'] === 'gmail'): ?><a class="bm-ic" target="_blank" rel="noopener" title="Ouvrir dans Gmail" href="https://mail.google.com/mail/?authuser=<?= urlencode($acc['email']) ?>#all/<?= h(urlencode($t['gmail_thread_id'])) ?>"><?= ak_icon('external', 15) ?></a><?php endif; ?>
      </div>
      <?php if ($manage && $t['counterpart_email']): ?>
        <label class="bm-learn" hidden><input type="checkbox" data-learn><span>Toujours ranger les e-mails de <strong><?= h($t['counterpart_email']) ?></strong> dans cette catégorie</span></label>
      <?php endif; ?>
      <?php if ($d['links']): ?><div class="bm-links"><?php foreach ($d['links'] as [$ic, $lbl, $url]): ?><a href="<?= h($url) ?>"><?= ak_icon($ic, 14) ?> <?= h($lbl) ?></a><?php endforeach; ?></div><?php endif; ?>
    </div>
    <div class="bm-msgs">
    <?php foreach ($d['msgs'] as $i => $m):
        $out = $m['direction'] === 'out';
        $full = (string)$m['body_text'];
        $short = mail_strip_quoted($full);
        $atts = json_decode((string)$m['attachments_json'], true) ?: [];
        $to = array_map(fn($a) => $a['name'] ?: $a['email'], json_decode((string)$m['to_list'], true) ?: []);
        $folded = $i < count($d['msgs']) - 2; ?>
      <article class="bm-msg<?= $out ? ' out' : '' ?><?= $folded ? ' folded' : '' ?>">
        <header class="bm-msg-head" data-fold>
          <span class="bm-av sm" style="background:<?= $out ? '#059669' : bm_color($m['from_email']) ?>"><?= h(bm_initials((string)$m['from_name'], (string)$m['from_email'])) ?></span>
          <span class="bm-msg-who"><strong><?= h($out ? ($m['from_name'] ?: 'Vous') : ($m['from_name'] ?: $m['from_email'])) ?></strong>
            <small>&lt;<?= h($m['from_email']) ?>&gt;<?= $to ? ' → ' . h(implode(', ', array_slice($to, 0, 3))) : '' ?></small>
            <?php if ($out && !empty($m['author'])): ?><span class="bm-by">Envoyé par <?= h($m['author']) ?> via Assokit</span><?php endif; ?>
            <em class="bm-msg-prev"><?= h(mb_substr(mail_snippet($full), 0, 120)) ?></em></span>
          <span class="bm-date"><?= h(date('d/m/Y H:i', strtotime($m['sent_at']))) ?></span>
        </header>
        <div class="bm-msg-body">
          <div class="bm-txt"><?= bm_body($short !== '' ? $short : $full) ?></div>
          <?php if ($short !== '' && mb_strlen($short) < mb_strlen(trim($full)) - 20): ?>
            <details class="bm-quoted"><summary>Afficher le message cité</summary><div class="bm-txt"><?= bm_body($full) ?></div></details>
          <?php endif; ?>
          <?php if ($atts): ?><div class="bm-atts"><?php foreach ($atts as $k => $a): ?>
            <a href="/boite-mail-piece?m=<?= (int)$m['id'] ?>&amp;a=<?= (int)$k ?>"><?= ak_icon('file', 14) ?> <?= h($a['name']) ?> <small><?= $a['size'] ? h(number_format($a['size'] / 1024, 0, ',', ' ')) . ' Ko' : '' ?></small></a>
          <?php endforeach; ?></div><?php endif; ?>
          <?php if (!empty($m['body_html'])): ?><button type="button" class="bm-link" data-html="/boite-mail-piece?m=<?= (int)$m['id'] ?>&amp;html=1">Afficher la mise en forme d’origine</button><?php endif; ?>
        </div>
      </article>
    <?php endforeach; ?>
    </div>
    <form class="bm-reply" data-reply>
      <div class="bm-reply-row"><label>À</label><input name="to" value="<?= h($d['reply_to']) ?>" autocomplete="off"></div>
      <div class="bm-reply-row"><label>Cc</label><input name="cc" value="" autocomplete="off" placeholder="facultatif"></div>
      <textarea name="body" rows="6" placeholder="Votre réponse…"></textarea>
      <div class="bm-reply-ai">
        <input name="consigne" maxlength="500" placeholder="Consigne pour l’IA (facultatif) : ex. « accepter le rendez-vous de jeudi »">
        <button type="button" class="bm-btn ai" data-draft><?= ak_icon('sparkle', 15) ?> Brouillon IA</button>
      </div>
      <div class="bm-reply-foot">
        <span class="bm-muted"><?= $acc['provider'] === 'gmail' && !$demo ? 'Envoyé depuis ' . h($acc['email']) . ', dans le même fil Gmail.' : 'Démonstration : la réponse est rangée dans le fil, aucun e-mail ne part.' ?></span>
        <button type="submit" class="bm-btn primary"><?= ak_icon('send', 15) ?> Envoyer</button>
      </div>
    </form>
    <?php return ob_get_clean();
}

$opts = function () use ($cat_by_slug, $promos_id, $has_prio) {
    $f = in_array($_GET['f'] ?? '', ['unread', 'archived', 'prio'], true) ? $_GET['f'] : '';
    return [
        'cat' => $cat_by_slug[(string)($_GET['c'] ?? '')] ?? null,
        'f' => $f, 'q' => mb_substr(trim((string)($_GET['q'] ?? '')), 0, 100),
        'page' => max(1, (int)($_GET['p'] ?? 1)), 'promos_id' => $promos_id, 'has_prio' => $has_prio,
    ];
};

// ============================================================
// Réponses partielles (JSON) pour la navigation fluide
// ============================================================
if ($partial !== '') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    if (!$acc) { echo json_encode(['ok' => false, 'error' => 'Aucune boîte reliée.']); exit; }
    if ($partial === 'list') {
        $o = $opts();
        [$rows, $more] = bm_threads($pdo, $org, $scope, $o);
        echo json_encode([
            'ok' => true,
            'html' => bm_rows_html($rows, $cat_by_id, !$o['cat'], (string)($_GET['g'] ?? ''), $o['f'] !== 'prio', null),
            'more' => $more, 'page' => $o['page'], 'count' => count($rows),
            'counts' => bm_counts($pdo, $org, $scope, $promos_id, $has_prio),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($partial === 'thread') {
        $d = bm_thread_data($pdo, $acc, $user, $org, (int)($_GET['t'] ?? 0));
        if (!$d) { http_response_code(404); echo json_encode(['ok' => false, 'error' => 'Conversation introuvable.']); exit; }
        echo json_encode([
            'ok' => true, 'id' => (int)$d['t']['id'],
            'title' => mail_fix_mojibake(mail_clean_subject((string)$d['t']['subject'])),
            'html' => bm_reader_html($d, $cats, $cat_by_id, $acc, $manage, $demo),
            'counts' => bm_counts($pdo, $org, $scope, $promos_id, $has_prio),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    echo json_encode(['ok' => false]); exit;
}

// ============================================================
// Page complète
// ============================================================
$o = $opts();
$counts = $acc ? bm_counts($pdo, $org, $scope, $promos_id, $has_prio) : ['by' => [], 'main_u' => 0, 'prio' => 0];
[$rows, $more] = $acc ? bm_threads($pdo, $org, $scope, $o) : [[], false];
$cur = ($acc && !empty($_GET['t'])) ? bm_thread_data($pdo, $acc, $user, $org, (int)$_GET['t']) : null;
if ($cur) $counts = bm_counts($pdo, $org, $scope, $promos_id, $has_prio);

// Bandeau « urgent » de la boîte principale
$urgents = [];
if ($acc && $has_prio && !$o['cat'] && $o['f'] === '' && $o['q'] === '') {
    $s = $pdo->prepare("SELECT id, subject, counterpart_name, counterpart_email, priority_reason FROM mail_threads t WHERE t.org_id = ? AND $scope AND t.is_archived = 0 AND t.priority = 'urgent' AND t.last_direction = 'in' ORDER BY t.last_message_at DESC LIMIT 3");
    $s->execute([$org]);
    $urgents = $s->fetchAll(PDO::FETCH_ASSOC);
}
$view_label = $o['f'] === 'prio' ? 'À traiter en priorité' : ($o['f'] === 'archived' ? 'Archivés' : ($o['cat'] ? $o['cat']['label'] : 'Boîte principale'));

render_head($cur ? mail_clean_subject((string)$cur['t']['subject']) . ' — Boîte mail' : 'Boîte mail');
render_sidebar('boite-mail');
?>
<main class="main bm-main">
<div class="bm-page<?= $cur ? ' reading' : '' ?>" id="bmPage">

  <div class="bm-head">
    <h1 class="bm-title"><?= ak_icon_badge('mail', '#059669', 32) ?><span>Boîte mail</span></h1>
    <?php if ($acc): ?>
    <div class="bm-head-r">
      <button type="button" class="bm-btn ghost" id="bmSync" title="Synchroniser maintenant"><?= ak_icon('refresh', 15) ?> <span>Synchroniser</span></button>
      <div class="bm-accmenu">
        <button type="button" class="bm-btn ghost" id="bmAccBtn" aria-haspopup="true">
          <span class="bm-dot <?= $acc['status'] === 'active' ? 'ok' : 'ko' ?>"></span><span class="bm-acc-mail"><?= h($acc['email']) ?></span> ▾
        </button>
        <div class="bm-accpop" id="bmAccPop" hidden>
          <div class="bm-accpop-h"><?= h($acc['email']) ?><?= $acc['provider'] === 'demo' ? ' · démonstration' : '' ?><br><span class="bm-muted"><?= $acc['last_sync_at'] ? 'Synchronisée ' . h(bm_when($acc['last_sync_at'])) : 'Jamais synchronisée' ?></span></div>
          <?php if ($can_connect && !$demo): ?>
            <a href="/mail-google-connect"><?= ak_icon('refresh', 14) ?> Changer de compte Gmail</a>
            <button type="button" id="bmDisconnect" class="danger"><?= ak_icon('trash', 14) ?> Déconnecter cette boîte</button>
          <?php endif; ?>
          <?php if ($manage): ?><a href="/boite-mail-parametres"><?= ak_icon('gear', 14) ?> Catégories et réglages</a><?php endif; ?>
        </div>
      </div>
    </div>
    <?php endif; ?>
  </div>

  <?php if (!empty($_GET['err'])): ?><div class="bm-alert err"><?= h($_GET['err']) ?></div><?php endif; ?>
  <?php if (!empty($_GET['connected'])): ?><div class="bm-alert ok" id="bmConnected">Boîte reliée. Première synchronisation en cours : les e-mails des <?= (int)($acc['sync_days'] ?? 30) ?> derniers jours arrivent et se rangent dans les catégories…</div><?php endif; ?>

<?php if (!$ready): ?>
  <div class="bm-empty"><?= ak_icon('wrench', 44, '1.5') ?><h2>Initialisation requise</h2><p>Lancez la migration <code>2026-10-06-boite-mail.sql</code> pour activer la boîte mail.</p></div>

<?php elseif (!$acc): ?>
  <div class="bm-hero">
    <div class="bm-hero-txt">
      <h2>Toute la boîte mail de l’association, rangée et partagée</h2>
      <ul>
        <li><?= ak_icon('layers', 16) ?> Les e-mails se rangent seuls par catégorie (subventions, institutions, facturation, adhérents…), newsletters mises de côté.</li>
        <li><?= ak_icon('bolt', 16) ?> Les urgences ressortent en haut : dossier de subvention, préfecture, cité éducative, date limite…</li>
        <li><?= ak_icon('link', 16) ?> Chaque e-mail est relié à la bonne fiche : adhérent, client, facture.</li>
        <li><?= ak_icon('send', 16) ?> Vous répondez depuis Assokit, avec l’adresse de l’association, dans le même fil que Gmail — avec un brouillon rédigé par l’IA si besoin.</li>
        <li><?= ak_icon('shield', 16) ?> Réservé aux administrateurs et coordinateurs.</li>
      </ul>
    </div>
    <div class="bm-hero-cta">
      <?php if ($can_connect && !$demo): ?>
        <a class="gsi-btn" href="/mail-google-connect">
          <svg width="18" height="18" viewBox="0 0 48 48" aria-hidden="true"><path fill="#EA4335" d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z"/><path fill="#4285F4" d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z"/><path fill="#FBBC05" d="M10.53 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.19C.92 16.46 0 20.12 0 24c0 3.88.92 7.54 2.56 10.78l7.97-6.19z"/><path fill="#34A853" d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.15 1.45-4.92 2.3-8.16 2.3-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z"/></svg>
          <span>Se connecter avec Google</span>
        </a>
        <p class="bm-muted">Gmail ou Google Workspace. Vous choisissez le compte sur l’écran Google ; Assokit ne voit jamais votre mot de passe. Déconnexion possible à tout moment.</p>
      <?php elseif ($demo): ?>
        <p class="bm-muted">Dans l’espace de démonstration, la boîte est déjà remplie : rechargez la page.</p>
      <?php else: ?>
        <p class="bm-muted">Demandez à un administrateur ou un coordinateur de relier la boîte Gmail de l’association.</p>
      <?php endif; ?>
    </div>
  </div>

<?php else: ?>
  <?php if ($acc['status'] !== 'active'): ?>
    <div class="bm-alert err"><?= h($acc['last_error'] ?: 'La connexion à Gmail est interrompue.') ?>
      <?php if ($can_connect && !$demo): ?> <a href="/mail-google-connect?email=<?= urlencode($acc['email']) ?>">Reconnecter la boîte →</a><?php endif; ?></div>
  <?php endif; ?>

  <div class="bm-grid">
    <nav class="bm-cats" id="bmCats" aria-label="Catégories">
      <?php if ($has_prio): ?>
      <a class="bm-cat prio<?= $o['f'] === 'prio' ? ' on' : '' ?>" href="?f=prio" data-nav="f=prio"><span class="bm-cat-ic">⚡</span><span>Prioritaires</span><b data-count="prio"<?= $counts['prio'] ? '' : ' hidden' ?>><?= $counts['prio'] ?></b></a>
      <?php endif; ?>
      <a class="bm-cat<?= !$o['cat'] && $o['f'] === '' ? ' on' : '' ?>" href="?" data-nav=""><?= ak_icon('inbox', 15) ?><span>Boîte principale</span><b data-count="main"<?= $counts['main_u'] ? '' : ' hidden' ?>><?= $counts['main_u'] ?></b></a>
      <div class="bm-cats-sep"></div>
      <?php foreach ($cats as $c): $n = $counts['by'][(int)$c['id']] ?? ['n' => 0, 'u' => 0]; ?>
        <a class="bm-cat<?= $o['cat'] && $o['cat']['id'] === $c['id'] ? ' on' : '' ?><?= $c['slug'] === 'promos' ? ' muted' : '' ?>" href="?c=<?= h($c['slug']) ?>" data-nav="c=<?= h($c['slug']) ?>">
          <i style="background:<?= h($c['color']) ?>"></i><span><?= h($c['label']) ?></span>
          <b data-count="c<?= (int)$c['id'] ?>"<?= $n['u'] && $c['slug'] !== 'promos' ? '' : ' hidden' ?>><?= (int)$n['u'] ?></b>
          <em data-total="c<?= (int)$c['id'] ?>"<?= (!$n['u'] || $c['slug'] === 'promos') && $n['n'] ? '' : ' hidden' ?>><?= (int)$n['n'] ?></em>
        </a>
      <?php endforeach; ?>
      <div class="bm-cats-sep"></div>
      <a class="bm-cat<?= $o['f'] === 'archived' ? ' on' : '' ?>" href="?f=archived" data-nav="f=archived"><?= ak_icon('download', 15) ?><span>Archivés</span></a>
    </nav>

    <section class="bm-list" id="bmList">
      <div class="bm-list-top">
        <form class="bm-search" id="bmSearch"><?= ak_icon('search', 15) ?><input type="search" name="q" value="<?= h($o['q']) ?>" placeholder="Rechercher (objet, expéditeur…)" autocomplete="off"></form>
        <div class="bm-tabs">
          <span class="bm-view" id="bmView"><?= h($view_label) ?></span>
          <button type="button" data-unread class="<?= $o['f'] === 'unread' ? 'on' : '' ?>">Non lus</button>
        </div>
      </div>
      <div class="bm-scroll" id="bmScroll">
        <?php if ($urgents): ?>
          <div class="bm-urgent" id="bmUrgent">
            <div class="bm-urgent-h">⚡ <?= count($urgents) ?> e-mail<?= count($urgents) > 1 ? 's' : '' ?> à traiter en urgence</div>
            <?php foreach ($urgents as $u): ?>
              <a href="?t=<?= (int)$u['id'] ?>" data-id="<?= (int)$u['id'] ?>" class="bm-urgent-i"><strong><?= h($u['counterpart_name'] ?: $u['counterpart_email']) ?></strong> · <?= h(mail_fix_mojibake((string)$u['subject'])) ?><?= $u['priority_reason'] ? '<em> — ' . h($u['priority_reason']) . '</em>' : '' ?></a>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
        <div id="bmRows"><?= bm_rows_html($rows, $cat_by_id, !$o['cat'], '', $o['f'] !== 'prio', $cur ? (int)$cur['t']['id'] : null) ?></div>
        <div class="bm-none" id="bmNone"<?= $rows ? ' hidden' : '' ?>><?= ak_icon('inbox', 28, '1.5') ?><p><?= $o['q'] !== '' ? 'Aucun résultat.' : ($o['f'] === 'prio' ? 'Rien d’urgent : tout est traité.' : 'Aucun e-mail ici.') ?></p></div>
        <div class="bm-more" id="bmMore"<?= $more ? '' : ' hidden' ?>>Chargement…</div>
      </div>
    </section>

    <section class="bm-read" id="bmRead" data-id="<?= $cur ? (int)$cur['t']['id'] : '' ?>">
      <?php if ($cur): ?>
        <?= bm_reader_html($cur, $cats, $cat_by_id, $acc, $manage, $demo) ?>
      <?php else: ?>
        <div class="bm-none big"><?= ak_icon('mail', 38, '1.4') ?><p>Choisissez une conversation.<br><span class="bm-muted">Raccourcis : J / K pour naviguer, E pour archiver.</span></p></div>
      <?php endif; ?>
    </section>
  </div>
<?php endif; ?>
</div>
</main>

<div class="bm-modal" id="bmHtml" hidden><div class="bm-modal-card"><div class="bm-modal-head"><strong>Message d’origine</strong><button type="button" class="bm-btn ghost sm" data-close-modal>Fermer</button></div>
  <iframe sandbox="allow-popups allow-popups-to-escape-sandbox" referrerpolicy="no-referrer" title="Message d’origine"></iframe></div></div>

<style>
.bm-main { padding-bottom: 12px; }
.bm-page { max-width: 1560px; margin: 0 auto; }
.bm-head { display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap; margin-bottom: 12px; }
.bm-title { display: flex; align-items: center; gap: 10px; font-size: 22px; font-weight: 700; color: #0F172A; margin: 0; }
.bm-head-r { display: flex; gap: 8px; align-items: center; }
.bm-dot { width: 8px; height: 8px; border-radius: 50%; display: inline-block; } .bm-dot.ok { background: #10B981; } .bm-dot.ko { background: #EF4444; }
.bm-muted { color: #64748B; font-size: 12.5px; }
.bm-pill { font-size: 11px; font-weight: 600; background: #EEF2FF; color: #4338CA; border-radius: 999px; padding: 2px 8px; }
.bm-btn { display: inline-flex; align-items: center; gap: 7px; border-radius: 10px; padding: 8px 13px; font-size: 13.5px; font-weight: 600; border: 1px solid transparent; cursor: pointer; text-decoration: none; font-family: inherit; }
.bm-btn.sm { padding: 6px 10px; font-size: 12.5px; }
.bm-btn.ghost { background: #fff; border-color: #E2E8F0; color: #334155; } .bm-btn.ghost:hover { border-color: #CBD5E1; }
.bm-btn.primary { background: #059669; color: #fff; } .bm-btn.primary:hover { background: #047857; }
.bm-btn.ai { background: linear-gradient(135deg, #6366F1, #8B5CF6); color: #fff; white-space: nowrap; }
.bm-btn[disabled] { opacity: .6; cursor: wait; }
.bm-accmenu { position: relative; }
.bm-acc-mail { max-width: 220px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.bm-accpop { position: absolute; right: 0; top: calc(100% + 6px); background: #fff; border: 1px solid #E2E8F0; border-radius: 12px; box-shadow: 0 12px 30px rgba(15,23,42,.14); width: 270px; z-index: 50; padding: 6px; }
.bm-accpop-h { padding: 8px 10px 10px; font-size: 13px; font-weight: 600; color: #0F172A; border-bottom: 1px solid #F1F5F9; margin-bottom: 4px; word-break: break-all; }
.bm-accpop a, .bm-accpop button { display: flex; gap: 8px; align-items: center; width: 100%; text-align: left; padding: 9px 10px; border-radius: 8px; font-size: 13px; color: #334155; text-decoration: none; background: none; border: 0; cursor: pointer; font-family: inherit; }
.bm-accpop a:hover, .bm-accpop button:hover { background: #F1F5F9; } .bm-accpop .danger { color: #B91C1C; }
.bm-alert { border-radius: 12px; padding: 10px 14px; font-size: 13.5px; margin-bottom: 10px; }
.bm-alert.err { background: #FEF2F2; color: #991B1B; border: 1px solid #FECACA; } .bm-alert.err a { color: #991B1B; font-weight: 700; }
.bm-alert.ok { background: #ECFDF5; color: #065F46; border: 1px solid #A7F3D0; }
.bm-empty, .bm-none { text-align: center; color: #64748B; padding: 34px 16px; }
.bm-none.big { padding: 110px 16px; }
.bm-hero { display: grid; grid-template-columns: 1.4fr 1fr; gap: 28px; background: #fff; border: 1px solid #E2E8F0; border-radius: 18px; padding: 30px; align-items: center; }
.bm-hero h2 { font-size: 20px; color: #0F172A; margin: 0 0 14px; }
.bm-hero ul { list-style: none; padding: 0; margin: 0; display: grid; gap: 10px; }
.bm-hero li { display: flex; gap: 10px; font-size: 14px; color: #334155; line-height: 1.5; } .bm-hero li svg { flex-shrink: 0; color: #059669; margin-top: 2px; }
.bm-hero-cta { text-align: center; }
.gsi-btn { display: inline-flex; align-items: center; gap: 12px; background: #fff; border: 1px solid #747775; border-radius: 4px; height: 44px; padding: 0 16px; font-family: Roboto, Arial, sans-serif; font-size: 15px; font-weight: 500; color: #1F1F1F; text-decoration: none; }
.gsi-btn:hover { background: #F8FAFF; box-shadow: 0 1px 3px rgba(60,64,67,.3); }
.bm-hero-cta p { margin: 14px auto 0; max-width: 320px; }

/* Trois colonnes à défilement indépendant : pas de défilement de page */
.bm-grid { display: grid; grid-template-columns: 245px minmax(330px, 440px) 1fr; gap: 12px; height: calc(100vh - 120px); min-height: 520px; }
.bm-cats, .bm-list, .bm-read { background: #fff; border: 1px solid #E2E8F0; border-radius: 14px; min-height: 0; }
.bm-cats { overflow-y: auto; padding: 8px; }
.bm-cat { display: flex; align-items: center; gap: 9px; padding: 7px 10px; border-radius: 9px; color: #334155; text-decoration: none; font-size: 13.5px; }
.bm-cat i { width: 9px; height: 9px; border-radius: 3px; flex-shrink: 0; }
.bm-cat span { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.bm-cat b { background: #059669; color: #fff; font-size: 11px; border-radius: 999px; padding: 1px 7px; }
.bm-cat em { font-style: normal; font-size: 11.5px; color: #94A3B8; }
.bm-cat:hover { background: #F1F5F9; } .bm-cat.on { background: #ECFDF5; color: #065F46; font-weight: 600; }
.bm-cat.prio b { background: #DC2626; } .bm-cat.prio.on { background: #FEF2F2; color: #991B1B; }
.bm-cat.muted { opacity: .75; }
.bm-cat-ic { flex: 0 0 auto !important; width: 15px; text-align: center; }
.bm-cats-sep { height: 1px; background: #F1F5F9; margin: 6px 4px; }

.bm-list { display: flex; flex-direction: column; overflow: hidden; }
.bm-list-top { border-bottom: 1px solid #F1F5F9; }
.bm-search { display: flex; align-items: center; gap: 8px; padding: 9px 12px; color: #94A3B8; margin: 0; }
.bm-search input { border: 0; outline: 0; flex: 1; font-size: 13.5px; font-family: inherit; background: transparent; }
.bm-tabs { display: flex; align-items: center; gap: 6px; padding: 0 12px 8px; }
.bm-view { flex: 1; font-size: 12px; font-weight: 700; color: #0F172A; text-transform: uppercase; letter-spacing: .04em; }
.bm-tabs button { font-size: 12px; padding: 3px 10px; border-radius: 999px; color: #64748B; background: #F1F5F9; border: 0; cursor: pointer; font-family: inherit; }
.bm-tabs button.on { background: #0F172A; color: #fff; }
.bm-scroll { flex: 1; overflow-y: auto; overscroll-behavior: contain; }
.bm-grp { position: sticky; top: 0; z-index: 1; background: #F8FAFC; padding: 4px 14px; font-size: 11px; font-weight: 700; color: #64748B; text-transform: uppercase; letter-spacing: .05em; border-bottom: 1px solid #F1F5F9; }
.bm-row { display: flex; gap: 10px; padding: 9px 12px; border-bottom: 1px solid #F1F5F9; text-decoration: none; color: inherit; cursor: pointer; transition: background .08s; }
.bm-row:hover { background: #F8FAFC; } .bm-row.on { background: #ECFDF5; box-shadow: inset 3px 0 0 #059669; }
.bm-av { width: 30px; height: 30px; border-radius: 50%; color: #fff; font-size: 11px; font-weight: 700; display: flex; align-items: center; justify-content: center; flex-shrink: 0; margin-top: 2px; }
.bm-av.sm { width: 28px; height: 28px; margin-top: 0; }
.bm-r { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 1px; }
.bm-r1 { display: flex; align-items: center; gap: 6px; min-width: 0; }
.bm-from { font-weight: 500; font-size: 13.5px; color: #334155; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; min-width: 0; }
.bm-n { color: #94A3B8; font-size: 11px; }
.bm-date { margin-left: auto; font-size: 11.5px; color: #94A3B8; white-space: nowrap; padding-left: 6px; }
.bm-r2 { font-size: 13px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; color: #64748B; }
.bm-subj { color: #1E293B; } .bm-replied { color: #059669; margin-right: 4px; }
.bm-row.unread .bm-from, .bm-row.unread .bm-subj { font-weight: 700; color: #0F172A; }
.bm-row.unread .bm-av { box-shadow: 0 0 0 2px #fff, 0 0 0 4px #10B981; }
.bm-tag { flex-shrink: 0; max-width: 120px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-size: 10.5px; font-weight: 600; color: var(--c); background: color-mix(in srgb, var(--c) 12%, white); border-radius: 5px; padding: 1px 6px; }
.bm-pri { flex-shrink: 0; font-style: normal; font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .03em; border-radius: 5px; padding: 1px 6px; }
.bm-pri.urgent { background: #FEE2E2; color: #B91C1C; } .bm-pri.important { background: #FEF3C7; color: #B45309; }
.bm-r3 { display: flex; align-items: center; gap: 5px; margin-top: 2px; min-width: 0; }
.bm-reason { font-size: 11.5px; color: #B91C1C; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; min-width: 0; }
.bm-urgent { margin: 8px; border: 1px solid #FECACA; background: #FEF2F2; border-radius: 10px; padding: 8px 10px; }
.bm-urgent-h { font-size: 12.5px; font-weight: 700; color: #991B1B; margin-bottom: 4px; }
.bm-urgent-i { display: block; font-size: 12.5px; color: #7F1D1D; text-decoration: none; padding: 3px 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.bm-urgent-i:hover { text-decoration: underline; } .bm-urgent-i em { color: #B91C1C; }
.bm-more { text-align: center; color: #94A3B8; font-size: 12.5px; padding: 14px; }

.bm-read { overflow-y: auto; padding: 18px 22px; position: relative; }
.bm-read.loading::after { content: ''; position: absolute; inset: 0; background: rgba(255,255,255,.6); }
.bm-read.loading::before { content: ''; position: absolute; top: 0; left: 0; height: 3px; width: 40%; background: #10B981; animation: bm-load 1s ease-in-out infinite; z-index: 2; }
@keyframes bm-load { 0% { left: -40%; } 100% { left: 100%; } }
.bm-back { display: none; background: none; border: 0; font-size: 13px; color: #059669; font-weight: 600; padding: 0; margin-bottom: 10px; cursor: pointer; font-family: inherit; }
.bm-read-head h2 { font-size: 19px; color: #0F172A; margin: 0 0 10px; line-height: 1.35; }
.bm-alert-prio { font-size: 13px; font-weight: 600; border-radius: 9px; padding: 7px 11px; margin-bottom: 10px; }
.bm-alert-prio.urgent { background: #FEF2F2; color: #991B1B; } .bm-alert-prio.important { background: #FFFBEB; color: #92400E; }
.bm-read-tools { display: flex; flex-wrap: wrap; gap: 6px; align-items: center; }
.bm-sp { flex: 1; }
.bm-catsel { display: inline-flex; align-items: center; border: 1px solid #E2E8F0; border-left: 4px solid var(--c); border-radius: 9px; padding: 3px 6px; }
.bm-catsel select { border: 0; font-size: 13px; font-weight: 600; color: #0F172A; background: transparent; font-family: inherit; }
.bm-ic { width: 32px; height: 32px; display: inline-flex; align-items: center; justify-content: center; border: 1px solid #E2E8F0; border-radius: 9px; background: #fff; color: #475569; cursor: pointer; text-decoration: none; font-size: 15px; }
.bm-ic:hover { border-color: #CBD5E1; background: #F8FAFC; }
.bm-learn { display: flex; gap: 8px; align-items: center; margin-top: 10px; font-size: 13px; color: #334155; background: #F8FAFC; border-radius: 8px; padding: 8px 10px; }
.bm-learn[hidden] { display: none; }
.bm-links { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 10px; }
.bm-links a { display: inline-flex; gap: 6px; align-items: center; font-size: 12.5px; font-weight: 600; color: #065F46; background: #ECFDF5; border: 1px solid #A7F3D0; border-radius: 999px; padding: 4px 10px; text-decoration: none; }
.bm-msgs { margin-top: 14px; display: grid; gap: 8px; }
.bm-msg { border: 1px solid #E2E8F0; border-radius: 12px; }
.bm-msg.out { background: #F8FDFB; border-color: #D1FAE5; }
.bm-msg-head { display: flex; align-items: center; gap: 10px; padding: 10px 14px; cursor: pointer; }
.bm-msg-who { flex: 1; min-width: 0; font-size: 13.5px; color: #0F172A; } .bm-msg-who small { color: #64748B; font-size: 12px; }
.bm-msg-prev { display: none; font-style: normal; color: #64748B; font-size: 12.5px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.bm-msg.folded .bm-msg-prev { display: block; } .bm-msg.folded .bm-msg-who small { display: none; }
.bm-by { display: inline-block; margin-left: 6px; padding: 1px 7px; border-radius: 999px; background: #ECFDF5; color: #047857; font-size: 11.5px; font-weight: 600; }
.bm-msg.folded .bm-by { display: none; }
.bm-msg-body { padding: 0 16px 14px 52px; }
.bm-msg.folded .bm-msg-body { display: none; }
.bm-txt { font-size: 14px; line-height: 1.6; color: #1F2937; overflow-wrap: anywhere; } .bm-txt a { color: #2563EB; }
.bm-quoted { margin-top: 8px; } .bm-quoted summary { font-size: 12.5px; color: #64748B; cursor: pointer; } .bm-quoted .bm-txt { color: #64748B; border-left: 3px solid #E2E8F0; padding-left: 10px; margin-top: 6px; }
.bm-atts { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 12px; }
.bm-atts a { display: inline-flex; gap: 6px; align-items: center; font-size: 12.5px; color: #334155; border: 1px solid #E2E8F0; border-radius: 8px; padding: 6px 10px; text-decoration: none; } .bm-atts small { color: #94A3B8; }
.bm-link { background: none; border: 0; padding: 0; margin-top: 10px; font-size: 12.5px; color: #2563EB; cursor: pointer; font-family: inherit; }
.bm-reply { margin-top: 14px; border: 1px solid #CBD5E1; border-radius: 12px; overflow: hidden; background: #fff; }
.bm-reply-row { display: flex; align-items: center; border-bottom: 1px solid #F1F5F9; } .bm-reply-row label { width: 42px; padding-left: 14px; font-size: 12.5px; color: #64748B; }
.bm-reply-row input { flex: 1; border: 0; outline: 0; padding: 8px 10px; font-size: 13.5px; font-family: inherit; }
.bm-reply textarea { width: 100%; border: 0; outline: 0; padding: 10px 14px; font-size: 14px; line-height: 1.55; font-family: inherit; resize: vertical; box-sizing: border-box; min-height: 90px; }
.bm-reply-ai { display: flex; gap: 8px; padding: 8px 12px; background: #F8FAFC; border-top: 1px solid #F1F5F9; }
.bm-reply-ai input { flex: 1; min-width: 0; border: 1px solid #E2E8F0; border-radius: 9px; padding: 7px 10px; font-size: 13px; font-family: inherit; }
.bm-reply-foot { display: flex; justify-content: space-between; align-items: center; gap: 12px; padding: 9px 12px; border-top: 1px solid #F1F5F9; }
.bm-modal { position: fixed; inset: 0; background: rgba(15,23,42,.5); z-index: 3000; display: flex; align-items: center; justify-content: center; padding: 16px; }
.bm-modal[hidden] { display: none; }
.bm-modal-card { background: #fff; border-radius: 14px; width: 100%; max-width: 860px; height: 85vh; display: flex; flex-direction: column; overflow: hidden; }
.bm-modal-head { display: flex; justify-content: space-between; align-items: center; padding: 12px 16px; border-bottom: 1px solid #E2E8F0; }
.bm-modal iframe { flex: 1; border: 0; width: 100%; }
.bm-toast { position: fixed; bottom: 22px; left: 50%; transform: translateX(-50%); background: #0F172A; color: #fff; padding: 10px 16px; border-radius: 10px; font-size: 13.5px; z-index: 3100; box-shadow: 0 10px 30px rgba(0,0,0,.25); }

@media (max-width: 1180px) { .bm-grid { grid-template-columns: 190px minmax(280px, 1fr) 1.3fr; } }
@media (max-width: 920px) {
  .bm-grid { grid-template-columns: 1fr; height: auto; min-height: 0; }
  .bm-cats { display: flex; overflow-x: auto; gap: 4px; padding: 6px; } .bm-cat { flex-shrink: 0; } .bm-cat span { overflow: visible; } .bm-cats-sep { display: none; }
  .bm-list { height: calc(100vh - 210px); }
  .bm-read { display: none; }
  .bm-page.reading .bm-read { display: block; position: fixed; inset: 0; z-index: 2000; border-radius: 0; padding: 14px 16px 90px; }
  .bm-back { display: inline-block; }
  .bm-msg-body { padding: 0 14px 14px; }
  .bm-hero { grid-template-columns: 1fr; padding: 20px; }
  .bm-reply-ai { flex-direction: column; }
  .bm-acc-mail { max-width: 130px; }
}
</style>

<script>
(function () {
  var csrf = <?= json_encode($csrf) ?>;
  var page = document.getElementById('bmPage');
  var rows = document.getElementById('bmRows');
  var scroller = document.getElementById('bmScroll');
  var reader = document.getElementById('bmRead');
  if (!page) return;

  function call(data) {
    data.csrf_token = csrf;
    return fetch('/boite-mail-action', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(data) })
      .then(function (r) { return r.json().catch(function () { return { ok: false, error: 'Réponse invalide (' + r.status + ')' }; }); });
  }
  function toast(msg) {
    var t = document.createElement('div'); t.className = 'bm-toast'; t.textContent = msg;
    document.body.appendChild(t); setTimeout(function () { t.remove(); }, 3500);
  }

  // Menu du compte
  var accBtn = document.getElementById('bmAccBtn'), accPop = document.getElementById('bmAccPop');
  if (accBtn) {
    accBtn.addEventListener('click', function (e) { e.stopPropagation(); accPop.hidden = !accPop.hidden; });
    document.addEventListener('click', function (e) { if (!accPop.contains(e.target)) accPop.hidden = true; });
  }
  var disc = document.getElementById('bmDisconnect');
  if (disc) disc.addEventListener('click', function () {
    if (!confirm('Déconnecter cette boîte ? L’accès Google est retiré et la copie des e-mails dans Assokit est effacée (rien n’est supprimé dans Gmail). Vous pourrez relier une autre boîte juste après.')) return;
    call({ action: 'disconnect' }).then(function (r) { r.ok ? (location.href = '/boite-mail') : toast(r.error || 'Erreur'); });
  });

  if (!rows) return;

  // ---------- État de la liste ----------
  var params = new URLSearchParams(location.search);
  var state = { c: params.get('c') || '', f: params.get('f') || '', q: params.get('q') || '', p: 1, more: !document.getElementById('bmMore').hidden, busy: false };
  var current = +(reader.getAttribute('data-id') || 0);

  function qs(extra) {
    var o = new URLSearchParams();
    if (state.c) o.set('c', state.c);
    if (state.f) o.set('f', state.f);
    if (state.q) o.set('q', state.q);
    for (var k in (extra || {})) if (extra[k] !== null && extra[k] !== '') o.set(k, extra[k]);
    return o.toString();
  }
  function pushUrl(replace) {
    var u = '/boite-mail' + (qs(current ? { t: current } : {}) ? '?' + qs(current ? { t: current } : {}) : '');
    history[replace ? 'replaceState' : 'pushState']({}, '', u);
  }
  function lastGroup() { var g = rows.querySelectorAll('.bm-grp'); return g.length ? g[g.length - 1].getAttribute('data-g') : ''; }

  function updateCounts(c) {
    if (!c) return;
    var set = function (key, n) {
      var b = document.querySelector('[data-count="' + key + '"]');
      if (b) { b.textContent = n; b.hidden = !n; }
    };
    set('prio', c.prio); set('main', c.main_u);
    document.querySelectorAll('[data-count^="c"]').forEach(function (b) {
      var id = b.getAttribute('data-count').slice(1), d = c.by[id] || { n: 0, u: 0 };
      var muted = b.parentNode.classList.contains('muted');
      b.textContent = d.u; b.hidden = !d.u || muted;
      var em = document.querySelector('[data-total="c' + id + '"]');
      if (em) { em.textContent = d.n; em.hidden = !(d.n && (!d.u || muted)); }
    });
  }

  function loadList(append) {
    if (state.busy) return;
    state.busy = true;
    var p = append ? state.p + 1 : 1;
    if (!append) rows.style.opacity = '.5';
    fetch('/boite-mail?' + qs({ partial: 'list', p: p, g: append ? lastGroup() : '' }), { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        state.busy = false; rows.style.opacity = '';
        if (!d.ok) return toast(d.error || 'Erreur de chargement');
        if (append) rows.insertAdjacentHTML('beforeend', d.html); else { rows.innerHTML = d.html; scroller.scrollTop = 0; }
        state.p = p; state.more = d.more;
        document.getElementById('bmMore').hidden = !d.more;
        document.getElementById('bmNone').hidden = rows.children.length > 0;
        var urg = document.getElementById('bmUrgent'); if (urg && !append) urg.hidden = !!(state.c || state.f || state.q);
        markOpen(); updateCounts(d.counts);
      })
      .catch(function () { state.busy = false; rows.style.opacity = ''; toast('Connexion interrompue'); });
  }

  // Défilement : charge la suite automatiquement
  scroller.addEventListener('scroll', function () {
    if (state.more && !state.busy && scroller.scrollTop + scroller.clientHeight > scroller.scrollHeight - 300) loadList(true);
  });

  // Catégories, onglets, recherche : sans rechargement
  document.querySelectorAll('[data-nav]').forEach(function (a) {
    a.addEventListener('click', function (e) {
      e.preventDefault();
      var n = new URLSearchParams(a.getAttribute('data-nav'));
      state.c = n.get('c') || ''; state.f = n.get('f') || ''; state.q = '';
      document.querySelector('#bmSearch input').value = '';
      document.querySelectorAll('.bm-cat').forEach(function (x) { x.classList.toggle('on', x === a); });
      document.getElementById('bmView').textContent = a.querySelector('span:not(.bm-cat-ic)').textContent;
      document.querySelector('[data-unread]').classList.remove('on');
      pushUrl(); loadList(false);
    });
  });
  document.querySelector('[data-unread]').addEventListener('click', function () {
    var on = !this.classList.contains('on');
    this.classList.toggle('on', on);
    state.f = on ? 'unread' : (state.f === 'unread' ? '' : state.f);
    pushUrl(); loadList(false);
  });
  var searchT;
  document.querySelector('#bmSearch input').addEventListener('input', function () {
    clearTimeout(searchT); var v = this.value.trim();
    searchT = setTimeout(function () { state.q = v; pushUrl(true); loadList(false); }, 280);
  });
  document.getElementById('bmSearch').addEventListener('submit', function (e) { e.preventDefault(); });

  // ---------- Conversation ----------
  function markOpen() {
    rows.querySelectorAll('.bm-row').forEach(function (r) { r.classList.toggle('on', +r.getAttribute('data-id') === current); });
  }
  var reqId = 0;
  function openThread(id, push) {
    if (!id) return;
    current = id; markOpen();
    var row = rows.querySelector('.bm-row[data-id="' + id + '"]');
    if (row) row.classList.remove('unread');
    page.classList.add('reading');
    reader.classList.add('loading');
    var mine = ++reqId;
    if (push !== false) pushUrl();
    fetch('/boite-mail?' + qs({ partial: 'thread', t: id }), { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (mine !== reqId) return;
        reader.classList.remove('loading');
        if (!d.ok) { reader.innerHTML = '<div class="bm-none big"><p>' + (d.error || 'Conversation introuvable.') + '</p></div>'; return; }
        reader.innerHTML = d.html; reader.setAttribute('data-id', d.id); reader.scrollTop = 0;
        document.title = d.title + ' — Boîte mail — Assokit';
        updateCounts(d.counts);
      })
      .catch(function () { reader.classList.remove('loading'); toast('Connexion interrompue'); });
  }
  function closeThread(push) {
    current = 0; markOpen(); page.classList.remove('reading');
    reader.innerHTML = '<div class="bm-none big"><p>Choisissez une conversation.</p></div>';
    reader.setAttribute('data-id', '');
    if (push !== false) pushUrl();
  }

  document.addEventListener('click', function (e) {
    var a = e.target.closest('.bm-row, .bm-urgent-i');
    if (!a || e.metaKey || e.ctrlKey || e.shiftKey) return;
    e.preventDefault();
    openThread(+a.getAttribute('data-id'));
  });
  window.addEventListener('popstate', function () {
    var p = new URLSearchParams(location.search);
    var c = p.get('c') || '', f = p.get('f') || '', q = p.get('q') || '';
    if (c !== state.c || f !== state.f || q !== state.q) { state.c = c; state.f = f; state.q = q; loadList(false); }
    var t = +(p.get('t') || 0);
    if (t && t !== current) openThread(t, false); else if (!t && current) closeThread(false);
  });

  // Actions dans la conversation (délégation : le contenu est remplacé à chaque ouverture)
  reader.addEventListener('click', function (e) {
    var b;
    if ((b = e.target.closest('[data-close]'))) return closeThread();
    if ((b = e.target.closest('[data-fold]'))) return b.parentNode.classList.toggle('folded');
    if ((b = e.target.closest('[data-html]'))) {
      var m = document.getElementById('bmHtml'); m.querySelector('iframe').src = b.getAttribute('data-html'); m.hidden = false; return;
    }
    if ((b = e.target.closest('[data-act]'))) {
      var act = b.getAttribute('data-act'), id = current;
      call({ action: act, thread_id: id }).then(function (r) {
        if (!r.ok) return toast(r.error || 'Erreur');
        var row = rows.querySelector('.bm-row[data-id="' + id + '"]');
        if (act === 'unread') { if (row) row.classList.add('unread'); toast('Marqué comme non lu'); closeThread(); }
        else { if (row) row.remove(); toast(act === 'archive' ? 'Conversation archivée' : 'Conversation désarchivée'); closeThread(); }
      });
      return;
    }
    if ((b = e.target.closest('[data-draft]'))) {
      var form = reader.querySelector('[data-reply]'), body = form.body;
      if (body.value.trim() && !confirm('Remplacer le texte en cours par un brouillon IA ?')) return;
      b.disabled = true; var lbl = b.innerHTML; b.innerHTML = 'Rédaction…';
      call({ action: 'draft', thread_id: current, consigne: form.consigne.value }).then(function (r) {
        b.disabled = false; b.innerHTML = lbl;
        if (!r.ok) return toast(r.error || 'IA indisponible');
        body.value = r.text; body.focus(); body.style.height = Math.min(480, body.scrollHeight + 8) + 'px';
      }).catch(function () { b.disabled = false; b.innerHTML = lbl; toast('Connexion interrompue'); });
    }
  });
  reader.addEventListener('change', function (e) {
    var sel = e.target.closest('[data-cat]'), learn = e.target.closest('[data-learn]');
    if (!sel && !learn) return;
    var catSel = reader.querySelector('[data-cat]'), lw = reader.querySelector('.bm-learn');
    if (sel && lw) lw.hidden = false;
    var opt = catSel.options[catSel.selectedIndex];
    call({ action: 'category', thread_id: current, category_id: +catSel.value, learn: learn && learn.checked ? 1 : 0 }).then(function (r) {
      if (!r.ok) return toast(r.error || 'Erreur');
      toast(learn ? 'Règle enregistrée : les prochains e-mails de cet expéditeur iront ici' : 'Rangé dans « ' + opt.text + ' »');
      var tag = rows.querySelector('.bm-row[data-id="' + current + '"] .bm-tag'); if (tag) tag.textContent = opt.text;
    });
  });
  reader.addEventListener('submit', function (e) {
    var form = e.target.closest('[data-reply]'); if (!form) return;
    e.preventDefault();
    if (!form.body.value.trim()) return form.body.focus();
    var btn = form.querySelector('[type=submit]'); btn.disabled = true;
    call({ action: 'reply', thread_id: current, to: form.to.value, cc: form.cc.value, body: form.body.value }).then(function (r) {
      btn.disabled = false;
      if (!r.ok) return toast(r.error || 'Envoi impossible');
      toast(r.simulated ? 'Réponse rangée dans le fil (démonstration : rien n’est parti)' : 'Réponse envoyée');
      var row = rows.querySelector('.bm-row[data-id="' + current + '"]');
      if (row && !row.querySelector('.bm-replied')) row.querySelector('.bm-r2').insertAdjacentHTML('afterbegin', '<span class="bm-replied">↩</span>');
      openThread(current, false);
    }).catch(function () { btn.disabled = false; toast('Connexion interrompue'); });
  });

  var modal = document.getElementById('bmHtml');
  modal.addEventListener('click', function (e) { if (e.target === modal || e.target.hasAttribute('data-close-modal')) { modal.hidden = true; modal.querySelector('iframe').src = 'about:blank'; } });

  // Raccourcis clavier : J/K suivant/précédent, E archiver, Échap fermer
  document.addEventListener('keydown', function (e) {
    if (e.target.closest('input, textarea, select') || e.metaKey || e.ctrlKey || e.altKey) return;
    var list = [].slice.call(rows.querySelectorAll('.bm-row')), i = list.findIndex(function (r) { return +r.getAttribute('data-id') === current; });
    if (e.key === 'j' || e.key === 'k') {
      var n = list[e.key === 'j' ? i + 1 : Math.max(0, i - 1)] || list[0];
      if (n) { openThread(+n.getAttribute('data-id')); n.scrollIntoView({ block: 'nearest' }); }
    } else if (e.key === 'e' && current) { var a = reader.querySelector('[data-act="archive"]'); if (a) a.click(); }
    else if (e.key === 'Escape' && current) closeThread();
  });

  // Synchronisation : met la liste à jour sans recharger
  var sync = document.getElementById('bmSync');
  function runSync(total) {
    sync.disabled = true; sync.querySelector('span').textContent = 'Synchronisation…' + (total ? ' (' + total + ')' : '');
    return call({ action: 'sync' }).then(function (r) {
      total = (total || 0) + (r.added || 0);
      if (r.ok && r.more) return runSync(total);
      sync.disabled = false; sync.querySelector('span').textContent = 'Synchroniser';
      if (!r.ok) { toast(r.error || 'Synchronisation impossible'); return; }
      toast(total > 0 ? total + ' nouvel(s) e-mail(s), triés' : 'Boîte à jour');
      loadList(false);
      var ok = document.getElementById('bmConnected'); if (ok) ok.remove();
    }).catch(function () { sync.disabled = false; sync.querySelector('span').textContent = 'Synchroniser'; toast('Connexion interrompue'); });
  }
  if (sync) sync.addEventListener('click', function () { runSync(0); });
  if (sync && document.getElementById('bmConnected')) runSync(0);
})();
</script>
<?php render_foot(); ?>
