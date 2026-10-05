<?php
/**
 * ============================================================
 * ASSOKIT — Boîte mail (Gmail)
 * ============================================================
 * /boite-mail                 boîte de réception, toutes catégories
 * /boite-mail?c=<slug>        une catégorie
 * /boite-mail?t=<id>          une conversation
 * Filtres : f=unread | f=archived ; q=recherche
 * ============================================================
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes-layout.php';
require_once __DIR__ . '/mail-helpers.php';
@require_once __DIR__ . '/ai-helper.php';
require_once __DIR__ . '/demo-guard.php';
require_login();

$user = current_user();
$org = (int)$user['org_id'];

if (!mail_can_access($user)) {
    http_response_code(403);
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
$demo = ak_demo_session();
$csrf = $_SESSION['csrf_token'] ?? '';

$cats = $ready ? mail_visible_categories($pdo, $org, $user) : [];
$cat_ids = array_map(fn($c) => (int)$c['id'], $cats);
$cat_by_id = []; foreach ($cats as $c) $cat_by_id[(int)$c['id']] = $c;
$in_cats = $cat_ids ? implode(',', $cat_ids) : '0';
$scope = $manage ? "(t.category_id IN ($in_cats) OR t.category_id IS NULL)" : "t.category_id IN ($in_cats)";

// Conversation ouverte
$cur = null; $msgs = []; $links = [];
if ($acc && !empty($_GET['t'])) {
    $cur = mail_thread_for_user($pdo, $user, (int)$_GET['t']);
    if ($cur) {
        if ((int)$cur['unread'] === 1) { mail_mark_read($pdo, $acc, $cur, true); $cur['unread'] = 0; }
        $s = $pdo->prepare("SELECT * FROM mail_messages WHERE thread_id = ? ORDER BY sent_at ASC, id ASC");
        $s->execute([$cur['id']]);
        $msgs = $s->fetchAll(PDO::FETCH_ASSOC);
        try {
            if ($cur['linked_user_id']) {
                $s = $pdo->prepare("SELECT id, first_name, last_name, role FROM users WHERE id = ? AND org_id = ?");
                $s->execute([$cur['linked_user_id'], $org]);
                if ($u = $s->fetch(PDO::FETCH_ASSOC)) $links[] = ['users', 'Fiche ' . trim($u['first_name'] . ' ' . $u['last_name']), '/adherent/' . (int)$u['id']];
            }
            if ($cur['linked_client_id'] && user_can_access_billing_safe($user)) {
                $s = $pdo->prepare("SELECT id, display_name FROM asso_clients WHERE id = ? AND org_id = ?");
                $s->execute([$cur['linked_client_id'], $org]);
                if ($c = $s->fetch(PDO::FETCH_ASSOC)) $links[] = ['building', 'Client ' . $c['display_name'], '/mon-asso-client-detail?id=' . (int)$c['id']];
            }
            if ($cur['linked_invoice_id'] && user_can_access_billing_safe($user)) {
                $s = $pdo->prepare("SELECT id, invoice_number, status, amount_ttc_cents FROM asso_invoices WHERE id = ? AND org_id = ?");
                $s->execute([$cur['linked_invoice_id'], $org]);
                if ($i = $s->fetch(PDO::FETCH_ASSOC)) $links[] = ['receipt', 'Facture ' . $i['invoice_number'] . ' · ' . number_format($i['amount_ttc_cents'] / 100, 2, ',', ' ') . ' € · ' . (['paid' => 'payée', 'pending' => 'en attente', 'overdue' => 'en retard', 'sent' => 'envoyée', 'draft' => 'brouillon', 'cancelled' => 'annulée'][$i['status']] ?? $i['status']), '/mon-asso-facture-edit?id=' . (int)$i['id']];
            }
        } catch (Throwable $e) {}
    }
}

// Compteurs par catégorie
$counts = []; $total_unread = 0;
if ($acc) {
    $s = $pdo->prepare("SELECT category_id, COUNT(*) n, SUM(unread) u FROM mail_threads t WHERE t.org_id = ? AND t.is_archived = 0 AND $scope GROUP BY category_id");
    $s->execute([$org]);
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) { $counts[(int)$r['category_id']] = $r; $total_unread += (int)$r['u']; }
}

// Filtres
$sel_slug = (string)($_GET['c'] ?? '');
$sel_cat = null; foreach ($cats as $c) if ($c['slug'] === $sel_slug) $sel_cat = $c;
$f = in_array($_GET['f'] ?? '', ['unread', 'archived'], true) ? $_GET['f'] : '';
$q = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 100);
$page = max(1, (int)($_GET['p'] ?? 1));
$per = 40;

$threads = []; $has_more = false;
if ($acc) {
    $w = ["t.org_id = ?", $scope, $f === 'archived' ? 't.is_archived = 1' : 't.is_archived = 0'];
    $args = [$org];
    if ($sel_cat) { $w[] = 't.category_id = ?'; $args[] = (int)$sel_cat['id']; }
    if ($f === 'unread') $w[] = 't.unread = 1';
    if ($q !== '') {
        $w[] = '(t.subject LIKE ? OR t.counterpart_email LIKE ? OR t.counterpart_name LIKE ? OR t.snippet LIKE ?)';
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
        array_push($args, $like, $like, $like, $like);
    }
    $s = $pdo->prepare("SELECT t.* FROM mail_threads t WHERE " . implode(' AND ', $w) . " ORDER BY t.last_message_at DESC LIMIT " . ($per + 1) . " OFFSET " . (($page - 1) * $per));
    $s->execute($args);
    $threads = $s->fetchAll(PDO::FETCH_ASSOC);
    $has_more = count($threads) > $per;
    $threads = array_slice($threads, 0, $per);
}

function user_can_access_billing_safe(array $u): bool {
    require_once __DIR__ . '/finance-permissions.php';
    return user_can_access_billing($u);
}
function bm_url(array $p): string {
    $base = array_filter(['c' => $_GET['c'] ?? null, 'f' => $_GET['f'] ?? null, 'q' => $_GET['q'] ?? null]);
    $all = array_filter(array_merge($base, $p), fn($v) => $v !== null && $v !== '');
    return '/boite-mail' . ($all ? '?' . http_build_query($all) : '');
}
function bm_when(?string $d): string {
    if (!$d) return '';
    $t = strtotime($d);
    if (date('Y-m-d', $t) === date('Y-m-d')) return date('H:i', $t);
    if (date('Y', $t) === date('Y')) {
        $m = ['', 'janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'];
        return (int)date('j', $t) . ' ' . $m[(int)date('n', $t)];
    }
    return date('d/m/Y', $t);
}
function bm_initials(string $name, string $email): string {
    $src = trim($name) !== '' ? $name : $email;
    $p = preg_split('/[\s.@_-]+/', $src, -1, PREG_SPLIT_NO_EMPTY);
    return mb_strtoupper(mb_substr($p[0] ?? '?', 0, 1) . mb_substr($p[1] ?? '', 0, 1));
}
function bm_body(string $text): string {
    $h = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    $h = preg_replace('~(https?://[^\s<>"\']{4,})~', '<a href="$1" target="_blank" rel="noopener noreferrer nofollow">$1</a>', $h);
    return nl2br($h);
}
$color_for = fn($s) => ['#0EA5E9', '#10B981', '#8B5CF6', '#F59E0B', '#EC4899', '#14B8A6', '#6366F1', '#64748B'][abs(crc32((string)$s)) % 8];

// Destinataire proposé pour la réponse : Reply-To / expéditeur du dernier message reçu
$reply_to = ''; $last_in = null;
foreach (array_reverse($msgs) as $m) if ($m['direction'] === 'in') { $last_in = $m; break; }
if ($last_in) $reply_to = $last_in['reply_to'] ?: $last_in['from_email'];
elseif ($cur) $reply_to = (string)$cur['counterpart_email'];

render_head($cur ? mail_clean_subject((string)$cur['subject']) . ' — Boîte mail' : 'Boîte mail');
render_sidebar('boite-mail');
?>
<main class="main bm-main">
<div class="bm-page<?= $cur ? ' has-thread' : '' ?>">

  <div class="bm-head">
    <div class="bm-head-l">
      <h1 class="bm-title"><?= ak_icon_badge('mail', '#059669', 36) ?><span>Boîte mail</span></h1>
      <?php if ($acc): ?>
        <div class="bm-acc">
          <span class="bm-dot <?= $acc['status'] === 'active' ? 'ok' : 'ko' ?>"></span>
          <?= h($acc['email']) ?>
          <?php if ($acc['provider'] === 'demo'): ?><span class="bm-pill">Démonstration</span><?php endif; ?>
          <?php if ($acc['last_sync_at']): ?><span class="bm-muted">· synchronisée <?= h(bm_when($acc['last_sync_at'])) ?></span><?php endif; ?>
        </div>
      <?php endif; ?>
    </div>
    <?php if ($acc): ?>
    <div class="bm-head-r">
      <button type="button" class="bm-btn ghost" id="bmSync"><?= ak_icon('refresh', 15) ?> <span>Synchroniser</span></button>
      <?php if ($manage): ?><a class="bm-btn ghost" href="/boite-mail-parametres"><?= ak_icon('gear', 15) ?> Réglages</a><?php endif; ?>
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
        <li><?= ak_icon('layers', 16) ?> Les e-mails se rangent seuls par catégorie (facturation, adhérents, subventions…) selon l’objet, l’expéditeur et, si besoin, l’IA.</li>
        <li><?= ak_icon('link', 16) ?> Chaque e-mail est relié à la bonne fiche : adhérent, client, facture.</li>
        <li><?= ak_icon('send', 16) ?> Vous répondez depuis Assokit, avec l’adresse de l’association, dans le même fil que Gmail.</li>
        <li><?= ak_icon('sparkle', 16) ?> Un brouillon de réponse rédigé par l’IA, à partir du contexte Assokit.</li>
        <li><?= ak_icon('shield', 16) ?> Accès réservé aux administrateurs et coordinateurs, catégorie par catégorie.</li>
      </ul>
    </div>
    <div class="bm-hero-cta">
      <?php if ($manage && !$demo): ?>
        <a class="gsi-btn" href="/mail-google-connect">
          <svg width="18" height="18" viewBox="0 0 48 48" aria-hidden="true"><path fill="#EA4335" d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z"/><path fill="#4285F4" d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z"/><path fill="#FBBC05" d="M10.53 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.19C.92 16.46 0 20.12 0 24c0 3.88.92 7.54 2.56 10.78l7.97-6.19z"/><path fill="#34A853" d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.15 1.45-4.92 2.3-8.16 2.3-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z"/></svg>
          <span>Se connecter avec Google</span>
        </a>
        <p class="bm-muted">Gmail ou Google Workspace. Vous choisissez le compte sur l’écran Google ; Assokit ne voit jamais votre mot de passe. Déconnexion possible à tout moment.</p>
      <?php elseif ($demo): ?>
        <p class="bm-muted">Dans l’espace de démonstration, la boîte est déjà remplie : rechargez la page.</p>
      <?php else: ?>
        <p class="bm-muted">Demandez à un administrateur de relier la boîte Gmail de l’association.</p>
      <?php endif; ?>
    </div>
  </div>

<?php else: ?>
  <?php if ($acc['status'] !== 'active'): ?>
    <div class="bm-alert err"><?= h($acc['last_error'] ?: 'La connexion à Gmail est interrompue.') ?>
      <?php if ($manage && !$demo): ?> <a href="/mail-google-connect?email=<?= urlencode($acc['email']) ?>">Reconnecter la boîte →</a><?php endif; ?></div>
  <?php endif; ?>

  <div class="bm-grid">
    <!-- Catégories -->
    <nav class="bm-cats" aria-label="Catégories">
      <a class="bm-cat<?= !$sel_cat && $f !== 'archived' ? ' on' : '' ?>" href="<?= h(bm_url(['c' => null, 'f' => $f === 'unread' ? 'unread' : null, 't' => null, 'p' => null])) ?>">
        <?= ak_icon('inbox', 15) ?><span>Toutes</span><?php if ($total_unread): ?><b><?= $total_unread ?></b><?php endif; ?>
      </a>
      <?php foreach ($cats as $c): $n = $counts[(int)$c['id']] ?? null; ?>
        <a class="bm-cat<?= $sel_cat && $sel_cat['id'] === $c['id'] ? ' on' : '' ?>" href="<?= h(bm_url(['c' => $c['slug'], 't' => null, 'p' => null])) ?>">
          <i style="background:<?= h($c['color']) ?>"></i><span><?= h($c['label']) ?></span>
          <?php if ($n && (int)$n['u']): ?><b><?= (int)$n['u'] ?></b><?php elseif ($n): ?><em><?= (int)$n['n'] ?></em><?php endif; ?>
        </a>
      <?php endforeach; ?>
      <a class="bm-cat<?= $f === 'archived' ? ' on' : '' ?>" href="<?= h(bm_url(['c' => null, 'f' => 'archived', 't' => null, 'p' => null])) ?>"><?= ak_icon('download', 15) ?><span>Archivés</span></a>
    </nav>

    <!-- Liste -->
    <section class="bm-list">
      <form class="bm-search" method="get" action="/boite-mail">
        <?php if ($sel_cat): ?><input type="hidden" name="c" value="<?= h($sel_cat['slug']) ?>"><?php endif; ?>
        <?php if ($f): ?><input type="hidden" name="f" value="<?= h($f) ?>"><?php endif; ?>
        <?= ak_icon('search', 15) ?><input type="search" name="q" value="<?= h($q) ?>" placeholder="Rechercher (objet, expéditeur…)">
      </form>
      <div class="bm-tabs">
        <a class="<?= $f !== 'unread' && $f !== 'archived' ? 'on' : '' ?>" href="<?= h(bm_url(['f' => null, 't' => null, 'p' => null])) ?>">Tous</a>
        <a class="<?= $f === 'unread' ? 'on' : '' ?>" href="<?= h(bm_url(['f' => 'unread', 't' => null, 'p' => null])) ?>">Non lus</a>
      </div>
      <?php if (!$threads): ?>
        <div class="bm-none"><?= ak_icon('inbox', 30, '1.5') ?><p><?= $q !== '' ? 'Aucun résultat.' : ($f === 'unread' ? 'Tout est lu.' : 'Aucun e-mail ici pour l’instant.') ?></p></div>
      <?php endif; ?>
      <?php foreach ($threads as $t): $c = $cat_by_id[(int)$t['category_id']] ?? null; ?>
        <a class="bm-row<?= (int)$t['unread'] ? ' unread' : '' ?><?= $cur && $cur['id'] == $t['id'] ? ' on' : '' ?>" href="<?= h(bm_url(['t' => $t['id']])) ?>">
          <span class="bm-av" style="background:<?= h($color_for($t['counterpart_email'])) ?>"><?= h(bm_initials((string)$t['counterpart_name'], (string)$t['counterpart_email'])) ?></span>
          <span class="bm-row-main">
            <span class="bm-row-top">
              <span class="bm-from"><?= h($t['counterpart_name'] ?: $t['counterpart_email'] ?: '—') ?><?php if ((int)$t['message_count'] > 1): ?> <small><?= (int)$t['message_count'] ?></small><?php endif; ?></span>
              <span class="bm-date"><?= h(bm_when($t['last_message_at'])) ?></span>
            </span>
            <span class="bm-subj"><?php if ($t['last_direction'] === 'out'): ?><span class="bm-replied" title="Répondu">↩</span><?php endif; ?><?= h($t['subject']) ?></span>
            <span class="bm-snip"><?= h($t['snippet']) ?></span>
            <?php if ($c && !$sel_cat): ?><span class="bm-tag" style="--c:<?= h($c['color']) ?>"><?= h($c['label']) ?><?= $t['category_source'] === 'ai' ? ' · IA' : '' ?></span><?php endif; ?>
          </span>
        </a>
      <?php endforeach; ?>
      <?php if ($page > 1 || $has_more): ?>
        <div class="bm-pager">
          <?php if ($page > 1): ?><a href="<?= h(bm_url(['p' => $page - 1, 't' => null])) ?>">← Plus récents</a><?php endif; ?>
          <?php if ($has_more): ?><a href="<?= h(bm_url(['p' => $page + 1, 't' => null])) ?>">Plus anciens →</a><?php endif; ?>
        </div>
      <?php endif; ?>
    </section>

    <!-- Conversation -->
    <section class="bm-read">
    <?php if (!$cur): ?>
      <div class="bm-none big"><?= ak_icon('mail', 38, '1.4') ?><p>Choisissez une conversation.</p></div>
    <?php else: $cc = $cat_by_id[(int)$cur['category_id']] ?? null; ?>
      <a class="bm-back" href="<?= h(bm_url(['t' => null])) ?>">← Retour à la liste</a>
      <div class="bm-read-head">
        <h2><?= h(mail_clean_subject((string)$cur['subject'])) ?></h2>
        <div class="bm-read-tools">
          <label class="bm-catsel" style="--c:<?= h($cc['color'] ?? '#94A3B8') ?>">
            <span>Catégorie</span>
            <select id="bmCat" data-thread="<?= (int)$cur['id'] ?>">
              <?php foreach ($cats as $c): ?><option value="<?= (int)$c['id'] ?>"<?= $cc && $cc['id'] == $c['id'] ? ' selected' : '' ?>><?= h($c['label']) ?></option><?php endforeach; ?>
            </select>
          </label>
          <?php if ($cur['category_source'] === 'ai'): ?><span class="bm-pill">Rangé par l’IA</span><?php elseif ($cur['category_source'] === 'rule'): ?><span class="bm-pill">Rangé par règle</span><?php endif; ?>
          <button type="button" class="bm-btn ghost sm" data-act="unread"><?= ak_icon('eye-off', 14) ?> Non lu</button>
          <button type="button" class="bm-btn ghost sm" data-act="<?= (int)$cur['is_archived'] ? 'unarchive' : 'archive' ?>"><?= ak_icon('download', 14) ?> <?= (int)$cur['is_archived'] ? 'Désarchiver' : 'Archiver' ?></button>
          <?php if ($acc['provider'] === 'gmail'): ?><a class="bm-btn ghost sm" target="_blank" rel="noopener" href="https://mail.google.com/mail/?authuser=<?= urlencode($acc['email']) ?>#all/<?= h(urlencode($cur['gmail_thread_id'])) ?>"><?= ak_icon('external', 14) ?> Gmail</a><?php endif; ?>
        </div>
        <?php if ($manage && $cur['counterpart_email']): ?>
          <label class="bm-learn" id="bmLearnWrap" hidden><input type="checkbox" id="bmLearn"><span>Toujours ranger les e-mails de <strong><?= h($cur['counterpart_email']) ?></strong> dans cette catégorie</span></label>
        <?php endif; ?>
        <?php if ($links): ?>
          <div class="bm-links"><?php foreach ($links as [$ic, $lbl, $url]): ?><a href="<?= h($url) ?>"><?= ak_icon($ic, 14) ?> <?= h($lbl) ?></a><?php endforeach; ?></div>
        <?php endif; ?>
      </div>

      <div class="bm-msgs">
      <?php foreach ($msgs as $i => $m):
          $out = $m['direction'] === 'out';
          $full = (string)$m['body_text'];
          $short = mail_strip_quoted($full);
          $atts = json_decode((string)$m['attachments_json'], true) ?: [];
          $to = array_map(fn($a) => $a['name'] ?: $a['email'], json_decode((string)$m['to_list'], true) ?: []);
          $collapsed = $i < count($msgs) - 2; ?>
        <article class="bm-msg<?= $out ? ' out' : '' ?><?= $collapsed ? ' folded' : '' ?>">
          <header class="bm-msg-head" data-fold>
            <span class="bm-av sm" style="background:<?= h($out ? '#059669' : $color_for($m['from_email'])) ?>"><?= h(bm_initials((string)$m['from_name'], (string)$m['from_email'])) ?></span>
            <span class="bm-msg-who"><strong><?= h($out ? ($m['from_name'] ?: 'Vous') : ($m['from_name'] ?: $m['from_email'])) ?></strong>
              <small>&lt;<?= h($m['from_email']) ?>&gt;<?= $to ? ' → ' . h(implode(', ', array_slice($to, 0, 3))) : '' ?></small></span>
            <span class="bm-date"><?= h(date('d/m/Y H:i', strtotime($m['sent_at']))) ?></span>
          </header>
          <div class="bm-msg-body">
            <div class="bm-txt"><?= bm_body($short !== '' ? $short : $full) ?></div>
            <?php if ($short !== '' && mb_strlen($short) < mb_strlen(trim($full)) - 20): ?>
              <details class="bm-quoted"><summary>Afficher le message cité</summary><div class="bm-txt"><?= bm_body($full) ?></div></details>
            <?php endif; ?>
            <?php if ($atts): ?>
              <div class="bm-atts"><?php foreach ($atts as $k => $a): ?>
                <a href="/boite-mail-piece?m=<?= (int)$m['id'] ?>&amp;a=<?= (int)$k ?>"><?= ak_icon('file', 14) ?> <?= h($a['name']) ?> <small><?= $a['size'] ? h(number_format($a['size'] / 1024, 0, ',', ' ')) . ' Ko' : '' ?></small></a>
              <?php endforeach; ?></div>
            <?php endif; ?>
            <?php if (!empty($m['body_html'])): ?>
              <button type="button" class="bm-link" data-html="/boite-mail-piece?m=<?= (int)$m['id'] ?>&amp;html=1">Afficher la mise en forme d’origine</button>
            <?php endif; ?>
          </div>
        </article>
      <?php endforeach; ?>
      </div>

      <form class="bm-reply" id="bmReply" data-thread="<?= (int)$cur['id'] ?>">
        <div class="bm-reply-row"><label>À</label><input name="to" value="<?= h($reply_to) ?>" autocomplete="off"></div>
        <div class="bm-reply-row"><label>Cc</label><input name="cc" value="" autocomplete="off" placeholder="facultatif"></div>
        <textarea name="body" rows="8" placeholder="Votre réponse…"></textarea>
        <div class="bm-reply-ai">
          <input id="bmConsigne" maxlength="500" placeholder="Consigne pour l’IA (facultatif) : ex. « accepter le rendez-vous de jeudi »">
          <button type="button" class="bm-btn ai" id="bmDraft"><?= ak_icon('sparkle', 15) ?> Brouillon IA</button>
        </div>
        <div class="bm-reply-foot">
          <span class="bm-muted"><?= $acc['provider'] === 'gmail' && !$demo ? 'Envoyé depuis ' . h($acc['email']) . ', dans le même fil Gmail.' : 'Démonstration : la réponse est rangée dans le fil, aucun e-mail ne part.' ?></span>
          <button type="submit" class="bm-btn primary" id="bmSend"><?= ak_icon('send', 15) ?> Envoyer</button>
        </div>
      </form>
    <?php endif; ?>
    </section>
  </div>
<?php endif; ?>
</div>
</main>

<div class="bm-modal" id="bmHtml" hidden><div class="bm-modal-card"><div class="bm-modal-head"><strong>Message d’origine</strong><button type="button" class="bm-btn ghost sm" data-close>Fermer</button></div>
  <iframe sandbox="allow-popups allow-popups-to-escape-sandbox" referrerpolicy="no-referrer" title="Message d’origine"></iframe></div></div>

<style>
.bm-main { padding-bottom: 24px; }
.bm-page { max-width: 1480px; margin: 0 auto; }
.bm-head { display: flex; justify-content: space-between; align-items: flex-end; gap: 16px; flex-wrap: wrap; margin-bottom: 14px; }
.bm-title { display: flex; align-items: center; gap: 12px; font-size: 24px; font-weight: 700; color: #0F172A; margin: 0; }
.bm-acc { display: flex; align-items: center; gap: 8px; margin-top: 6px; font-size: 13px; color: #334155; flex-wrap: wrap; }
.bm-dot { width: 8px; height: 8px; border-radius: 50%; } .bm-dot.ok { background: #10B981; } .bm-dot.ko { background: #EF4444; }
.bm-muted { color: #64748B; font-size: 12.5px; }
.bm-pill { font-size: 11px; font-weight: 600; background: #EEF2FF; color: #4338CA; border-radius: 999px; padding: 2px 8px; }
.bm-head-r { display: flex; gap: 8px; }
.bm-btn { display: inline-flex; align-items: center; gap: 7px; border-radius: 10px; padding: 9px 14px; font-size: 13.5px; font-weight: 600; border: 1px solid transparent; cursor: pointer; text-decoration: none; font-family: inherit; }
.bm-btn.sm { padding: 6px 10px; font-size: 12.5px; }
.bm-btn.ghost { background: #fff; border-color: #E2E8F0; color: #334155; } .bm-btn.ghost:hover { border-color: #CBD5E1; }
.bm-btn.primary { background: #059669; color: #fff; } .bm-btn.primary:hover { background: #047857; }
.bm-btn.ai { background: linear-gradient(135deg, #6366F1, #8B5CF6); color: #fff; white-space: nowrap; }
.bm-btn[disabled] { opacity: .6; cursor: wait; }
.bm-alert { border-radius: 12px; padding: 11px 14px; font-size: 13.5px; margin-bottom: 12px; }
.bm-alert.err { background: #FEF2F2; color: #991B1B; border: 1px solid #FECACA; } .bm-alert.err a { color: #991B1B; font-weight: 700; }
.bm-alert.ok { background: #ECFDF5; color: #065F46; border: 1px solid #A7F3D0; }
.bm-empty, .bm-none { text-align: center; color: #64748B; padding: 40px 16px; }
.bm-none.big { padding: 120px 16px; }
.bm-empty h2 { color: #0F172A; font-size: 18px; }

.bm-hero { display: grid; grid-template-columns: 1.4fr 1fr; gap: 28px; background: #fff; border: 1px solid #E2E8F0; border-radius: 18px; padding: 30px; align-items: center; }
.bm-hero h2 { font-size: 20px; color: #0F172A; margin: 0 0 14px; }
.bm-hero ul { list-style: none; padding: 0; margin: 0; display: grid; gap: 10px; }
.bm-hero li { display: flex; gap: 10px; font-size: 14px; color: #334155; line-height: 1.5; } .bm-hero li svg { flex-shrink: 0; color: #059669; margin-top: 2px; }
.bm-hero-cta { text-align: center; }
.gsi-btn { display: inline-flex; align-items: center; gap: 12px; background: #fff; border: 1px solid #747775; border-radius: 4px; height: 44px; padding: 0 16px; font-family: Roboto, Arial, sans-serif; font-size: 15px; font-weight: 500; color: #1F1F1F; text-decoration: none; box-shadow: 0 1px 2px rgba(0,0,0,.08); }
.gsi-btn:hover { background: #F8FAFF; box-shadow: 0 1px 3px rgba(60,64,67,.3); }
.bm-hero-cta p { margin: 14px auto 0; max-width: 320px; }

.bm-grid { display: grid; grid-template-columns: 210px minmax(300px, 400px) 1fr; gap: 14px; align-items: start; }
.bm-cats { background: #fff; border: 1px solid #E2E8F0; border-radius: 14px; padding: 8px; position: sticky; top: 12px; }
.bm-cat { display: flex; align-items: center; gap: 9px; padding: 8px 10px; border-radius: 9px; color: #334155; text-decoration: none; font-size: 13.5px; }
.bm-cat i { width: 9px; height: 9px; border-radius: 3px; flex-shrink: 0; }
.bm-cat span { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.bm-cat b { background: #059669; color: #fff; font-size: 11px; border-radius: 999px; padding: 1px 7px; }
.bm-cat em { font-style: normal; font-size: 11.5px; color: #94A3B8; }
.bm-cat:hover { background: #F1F5F9; } .bm-cat.on { background: #ECFDF5; color: #065F46; font-weight: 600; }

.bm-list { background: #fff; border: 1px solid #E2E8F0; border-radius: 14px; overflow: hidden; }
.bm-search { display: flex; align-items: center; gap: 8px; padding: 10px 12px; border-bottom: 1px solid #F1F5F9; color: #94A3B8; }
.bm-search input { border: 0; outline: 0; flex: 1; font-size: 13.5px; font-family: inherit; background: transparent; }
.bm-tabs { display: flex; gap: 4px; padding: 8px 10px; border-bottom: 1px solid #F1F5F9; }
.bm-tabs a { font-size: 12.5px; padding: 4px 10px; border-radius: 999px; color: #64748B; text-decoration: none; } .bm-tabs a.on { background: #0F172A; color: #fff; }
.bm-row { display: flex; gap: 11px; padding: 12px 14px; border-bottom: 1px solid #F1F5F9; text-decoration: none; color: inherit; }
.bm-row:hover { background: #F8FAFC; } .bm-row.on { background: #ECFDF5; }
.bm-av { width: 34px; height: 34px; border-radius: 50%; color: #fff; font-size: 12px; font-weight: 700; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.bm-av.sm { width: 30px; height: 30px; font-size: 11px; }
.bm-row-main { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 2px; }
.bm-row-top { display: flex; justify-content: space-between; gap: 8px; }
.bm-from { font-size: 13.5px; color: #334155; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; } .bm-from small { color: #94A3B8; font-weight: 400; }
.bm-date { font-size: 12px; color: #94A3B8; white-space: nowrap; }
.bm-subj { font-size: 13.5px; color: #0F172A; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.bm-replied { color: #059669; margin-right: 5px; }
.bm-snip { font-size: 12.5px; color: #64748B; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.bm-row.unread .bm-from, .bm-row.unread .bm-subj { font-weight: 700; color: #0F172A; }
.bm-row.unread .bm-av { box-shadow: 0 0 0 2px #fff, 0 0 0 4px #10B981; }
.bm-tag { align-self: flex-start; margin-top: 4px; font-size: 11px; font-weight: 600; color: var(--c); background: color-mix(in srgb, var(--c) 12%, white); border-radius: 6px; padding: 1px 7px; }
.bm-pager { display: flex; justify-content: space-between; padding: 10px 14px; font-size: 13px; } .bm-pager a { color: #059669; font-weight: 600; text-decoration: none; }

.bm-read { background: #fff; border: 1px solid #E2E8F0; border-radius: 14px; min-height: 420px; padding: 20px 22px; }
.bm-back { display: none; font-size: 13px; color: #059669; font-weight: 600; text-decoration: none; margin-bottom: 10px; }
.bm-read-head h2 { font-size: 19px; color: #0F172A; margin: 0 0 12px; line-height: 1.35; }
.bm-read-tools { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
.bm-catsel { display: inline-flex; align-items: center; gap: 6px; border: 1px solid #E2E8F0; border-left: 4px solid var(--c); border-radius: 10px; padding: 4px 8px; font-size: 12px; color: #64748B; }
.bm-catsel select { border: 0; font-size: 13px; font-weight: 600; color: #0F172A; background: transparent; font-family: inherit; }
.bm-learn[hidden] { display: none; }
.bm-learn { display: flex; gap: 8px; align-items: center; margin-top: 10px; font-size: 13px; color: #334155; background: #F8FAFC; border-radius: 8px; padding: 8px 10px; }
.bm-links { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 12px; }
.bm-links a { display: inline-flex; gap: 6px; align-items: center; font-size: 12.5px; font-weight: 600; color: #065F46; background: #ECFDF5; border: 1px solid #A7F3D0; border-radius: 999px; padding: 4px 10px; text-decoration: none; }
.bm-msgs { margin-top: 18px; display: grid; gap: 10px; }
.bm-msg { border: 1px solid #E2E8F0; border-radius: 12px; }
.bm-msg.out { background: #F8FDFB; border-color: #D1FAE5; }
.bm-msg-head { display: flex; align-items: center; gap: 10px; padding: 11px 14px; cursor: pointer; }
.bm-msg-who { flex: 1; min-width: 0; font-size: 13.5px; color: #0F172A; } .bm-msg-who small { color: #64748B; font-size: 12px; }
.bm-msg-body { padding: 0 16px 14px 54px; }
.bm-msg.folded .bm-msg-body { display: none; }
.bm-txt { font-size: 14px; line-height: 1.6; color: #1F2937; word-wrap: break-word; overflow-wrap: anywhere; } .bm-txt a { color: #2563EB; }
.bm-quoted { margin-top: 8px; } .bm-quoted summary { font-size: 12.5px; color: #64748B; cursor: pointer; } .bm-quoted .bm-txt { color: #64748B; border-left: 3px solid #E2E8F0; padding-left: 10px; margin-top: 6px; }
.bm-atts { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 12px; }
.bm-atts a { display: inline-flex; gap: 6px; align-items: center; font-size: 12.5px; color: #334155; border: 1px solid #E2E8F0; border-radius: 8px; padding: 6px 10px; text-decoration: none; } .bm-atts small { color: #94A3B8; }
.bm-link { background: none; border: 0; padding: 0; margin-top: 10px; font-size: 12.5px; color: #2563EB; cursor: pointer; font-family: inherit; }
.bm-reply { margin-top: 18px; border: 1px solid #CBD5E1; border-radius: 12px; overflow: hidden; }
.bm-reply-row { display: flex; align-items: center; border-bottom: 1px solid #F1F5F9; } .bm-reply-row label { width: 42px; padding-left: 14px; font-size: 12.5px; color: #64748B; }
.bm-reply-row input { flex: 1; border: 0; outline: 0; padding: 9px 10px; font-size: 13.5px; font-family: inherit; }
.bm-reply textarea { width: 100%; border: 0; outline: 0; padding: 12px 14px; font-size: 14px; line-height: 1.55; font-family: inherit; resize: vertical; box-sizing: border-box; }
.bm-reply-ai { display: flex; gap: 8px; padding: 8px 12px; background: #F8FAFC; border-top: 1px solid #F1F5F9; }
.bm-reply-ai input { flex: 1; min-width: 0; border: 1px solid #E2E8F0; border-radius: 9px; padding: 8px 10px; font-size: 13px; font-family: inherit; }
.bm-reply-foot { display: flex; justify-content: space-between; align-items: center; gap: 12px; padding: 10px 12px; border-top: 1px solid #F1F5F9; }
.bm-modal { position: fixed; inset: 0; background: rgba(15,23,42,.5); z-index: 3000; display: flex; align-items: center; justify-content: center; padding: 16px; }
.bm-modal[hidden] { display: none; }
.bm-modal-card { background: #fff; border-radius: 14px; width: 100%; max-width: 860px; height: 85vh; display: flex; flex-direction: column; overflow: hidden; }
.bm-modal-head { display: flex; justify-content: space-between; align-items: center; padding: 12px 16px; border-bottom: 1px solid #E2E8F0; }
.bm-modal iframe { flex: 1; border: 0; width: 100%; }
.bm-toast { position: fixed; bottom: 22px; left: 50%; transform: translateX(-50%); background: #0F172A; color: #fff; padding: 10px 16px; border-radius: 10px; font-size: 13.5px; z-index: 3100; box-shadow: 0 10px 30px rgba(0,0,0,.25); }

@media (max-width: 1180px) { .bm-grid { grid-template-columns: 190px 1fr; } .bm-read { grid-column: 1 / -1; } .bm-page:not(.has-thread) .bm-read { display: none; } }
@media (max-width: 760px) {
  .bm-grid { grid-template-columns: 1fr; }
  .bm-cats { position: static; display: flex; overflow-x: auto; gap: 4px; padding: 6px; } .bm-cat { flex-shrink: 0; } .bm-cat span { overflow: visible; }
  .bm-page.has-thread .bm-cats, .bm-page.has-thread .bm-list { display: none; }
  .bm-back { display: inline-block; }
  .bm-read { padding: 16px; } .bm-msg-body { padding: 0 14px 14px; }
  .bm-hero { grid-template-columns: 1fr; padding: 20px; }
  .bm-reply-ai { flex-direction: column; }
}
</style>

<script>
(function () {
  var csrf = <?= json_encode($csrf) ?>;
  function call(data) {
    data.csrf_token = csrf;
    return fetch('/boite-mail-action', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(data) })
      .then(function (r) { return r.json().catch(function () { return { ok: false, error: 'Réponse invalide (' + r.status + ')' }; }); });
  }
  function toast(msg) {
    var t = document.createElement('div'); t.className = 'bm-toast'; t.textContent = msg;
    document.body.appendChild(t); setTimeout(function () { t.remove(); }, 3800);
  }

  // Synchronisation (boucle tant que Gmail a encore des e-mails à donner)
  var sync = document.getElementById('bmSync');
  function runSync(total) {
    sync.disabled = true; sync.querySelector('span').textContent = 'Synchronisation…' + (total ? ' (' + total + ')' : '');
    return call({ action: 'sync' }).then(function (r) {
      total = (total || 0) + (r.added || 0);
      if (r.ok && r.more) return runSync(total);
      sync.disabled = false; sync.querySelector('span').textContent = 'Synchroniser';
      if (!r.ok) { toast(r.error || 'Synchronisation impossible'); return; }
      if (total > 0) { toast(total + ' nouvel(s) e-mail(s)'); setTimeout(function () { location.reload(); }, 700); }
      else toast('Boîte à jour');
    }).catch(function () { sync.disabled = false; sync.querySelector('span').textContent = 'Synchroniser'; toast('Connexion interrompue'); });
  }
  if (sync) sync.addEventListener('click', function () { runSync(0); });
  if (sync && document.getElementById('bmConnected')) runSync(0);

  // Plier / déplier un message
  document.querySelectorAll('[data-fold]').forEach(function (h) {
    h.addEventListener('click', function () { h.parentNode.classList.toggle('folded'); });
  });

  // Mise en forme d'origine (iframe isolée)
  var modal = document.getElementById('bmHtml');
  document.querySelectorAll('[data-html]').forEach(function (b) {
    b.addEventListener('click', function () { modal.querySelector('iframe').src = b.getAttribute('data-html'); modal.hidden = false; });
  });
  modal.addEventListener('click', function (e) { if (e.target === modal || e.target.hasAttribute('data-close')) { modal.hidden = true; modal.querySelector('iframe').src = 'about:blank'; } });

  var form = document.getElementById('bmReply');
  var tid = form ? +form.getAttribute('data-thread') : 0;

  // Changer de catégorie (+ apprendre l'expéditeur)
  var cat = document.getElementById('bmCat'), learnWrap = document.getElementById('bmLearnWrap'), learn = document.getElementById('bmLearn');
  if (cat) cat.addEventListener('change', function () {
    if (learnWrap) learnWrap.hidden = false;
    call({ action: 'category', thread_id: tid, category_id: +cat.value, learn: learn && learn.checked ? 1 : 0 })
      .then(function (r) { toast(r.ok ? 'Conversation rangée' : (r.error || 'Erreur')); });
  });
  if (learn) learn.addEventListener('change', function () {
    if (!learn.checked) return;
    call({ action: 'category', thread_id: tid, category_id: +cat.value, learn: 1 })
      .then(function (r) { toast(r.ok ? 'Règle enregistrée : les prochains e-mails de cet expéditeur iront ici' : (r.error || 'Erreur')); });
  });

  // Non lu / archiver
  document.querySelectorAll('[data-act]').forEach(function (b) {
    b.addEventListener('click', function () {
      var act = b.getAttribute('data-act');
      call({ action: act, thread_id: tid }).then(function (r) {
        if (!r.ok) return toast(r.error || 'Erreur');
        var u = new URL(location.href); u.searchParams.delete('t'); location.href = u.toString();
      });
    });
  });

  if (!form) return;
  var body = form.querySelector('textarea'), send = document.getElementById('bmSend'), draft = document.getElementById('bmDraft');

  draft.addEventListener('click', function () {
    if (body.value.trim() && !confirm('Remplacer le texte en cours par un brouillon IA ?')) return;
    draft.disabled = true; var lbl = draft.innerHTML; draft.innerHTML = 'Rédaction…';
    call({ action: 'draft', thread_id: tid, consigne: document.getElementById('bmConsigne').value })
      .then(function (r) {
        draft.disabled = false; draft.innerHTML = lbl;
        if (!r.ok) return toast(r.error || 'IA indisponible');
        body.value = r.text; body.focus(); body.style.height = Math.min(520, body.scrollHeight + 8) + 'px';
      }).catch(function () { draft.disabled = false; draft.innerHTML = lbl; toast('Connexion interrompue'); });
  });

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    if (!body.value.trim()) return body.focus();
    send.disabled = true;
    call({ action: 'reply', thread_id: tid, to: form.to.value, cc: form.cc.value, body: body.value })
      .then(function (r) {
        send.disabled = false;
        if (!r.ok) return toast(r.error || 'Envoi impossible');
        toast(r.simulated ? 'Réponse rangée dans le fil (démonstration : rien n’est parti)' : 'Réponse envoyée');
        setTimeout(function () { location.reload(); }, 800);
      }).catch(function () { send.disabled = false; toast('Connexion interrompue'); });
  });
})();
</script>
<?php render_foot(); ?>
