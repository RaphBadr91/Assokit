<?php
/**
 * ============================================================
 * ASSOKIT — Boîte mail : actions (JSON)
 * ============================================================
 * POST, jeton CSRF obligatoire. Chaque action vérifie que le fil
 * appartient à l'association ET que sa catégorie est visible par
 * l'utilisateur ; les réglages sont réservés aux administrateurs.
 * ============================================================
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/mail-helpers.php';
@require_once __DIR__ . '/ai-helper.php';
@require_once __DIR__ . '/rate-limit-helper.php';
require_login();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$out = function (array $d, int $code = 200) { http_response_code($code); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; };
if ($_SERVER['REQUEST_METHOD'] !== 'POST') $out(['ok' => false, 'error' => 'POST requis'], 405);
$in = json_decode(file_get_contents('php://input'), true);
if (!is_array($in)) $in = $_POST;
if (!check_csrf((string)($in['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')))) $out(['ok' => false, 'error' => 'Session expirée, rechargez la page.'], 403);

$user = current_user();
if (!mail_can_access($user)) $out(['ok' => false, 'error' => 'Accès réservé.'], 403);
if (!mail_schema_ready($pdo)) $out(['ok' => false, 'error' => 'Migration boîte mail non appliquée.'], 503);
$org = (int)$user['org_id'];
$acc = mail_get_account($pdo, $org);
$action = (string)($in['action'] ?? '');
$manage = mail_can_manage($user);

$thread = function () use ($pdo, $user, $in, $out) {
    $t = mail_thread_for_user($pdo, $user, (int)($in['thread_id'] ?? 0));
    if (!$t) $out(['ok' => false, 'error' => 'Conversation introuvable.'], 404);
    return $t;
};
if (!$acc && !in_array($action, ['category_save', 'category_delete', 'category_order'], true)) $out(['ok' => false, 'error' => 'Aucune boîte reliée.'], 409);

switch ($action) {

case 'sync':
    if (function_exists('ak_rate_limit_or_die')) ak_rate_limit_or_die('mail_sync', 30, 300, (string)$user['id']);
    @set_time_limit(60);
    $r = mail_sync($pdo, $acc, 20.0);
    $out(['ok' => !$r['erreur'], 'added' => $r['ajoutes'], 'more' => $r['encore'], 'error' => $r['erreur'],
          'unread' => mail_unread_count($pdo, $user)]);

case 'read':
case 'unread':
    $t = $thread();
    mail_mark_read($pdo, $acc, $t, $action === 'read');
    $out(['ok' => true, 'unread' => mail_unread_count($pdo, $user)]);

case 'archive':
case 'unarchive':
    $t = $thread();
    $pdo->prepare("UPDATE mail_threads SET is_archived = ? WHERE id = ?")->execute([$action === 'archive' ? 1 : 0, $t['id']]);
    $out(['ok' => true]);

case 'category':
    $t = $thread();
    $cid = (int)($in['category_id'] ?? 0);
    $vis = mail_visible_categories($pdo, $org, $user);
    $cat = null; foreach ($vis as $c) if ((int)$c['id'] === $cid) $cat = $c;
    if (!$cat) $out(['ok' => false, 'error' => 'Catégorie inconnue.'], 400);
    $pdo->prepare("UPDATE mail_threads SET category_id = ?, category_source = 'manual' WHERE id = ?")->execute([$cid, $t['id']]);
    $learned = false;
    if (!empty($in['learn']) && $manage && filter_var($t['counterpart_email'], FILTER_VALIDATE_EMAIL)) {
        // « Toujours ranger les e-mails de cet expéditeur ici »
        $email = strtolower($t['counterpart_email']);
        foreach (mail_categories($pdo, $org) as $c) {   // retire la règle d'une autre catégorie
            $kws = array_values(array_filter(array_map('trim', explode(',', (string)$c['keywords'])), fn($k) => strtolower($k) !== $email));
            if ((int)$c['id'] === $cid) $kws[] = $email;
            $pdo->prepare("UPDATE mail_categories SET keywords = ? WHERE id = ?")->execute([implode(', ', array_unique($kws)), $c['id']]);
        }
        $pdo->prepare("UPDATE mail_threads SET category_id = ?, category_source = 'rule' WHERE org_id = ? AND LOWER(counterpart_email) = ? AND category_source <> 'manual'")
            ->execute([$cid, $org, $email]);
        $learned = true;
    }
    $out(['ok' => true, 'learned' => $learned]);

case 'reply':
    $t = $thread();
    if (function_exists('ak_rate_limit_or_die')) ak_rate_limit_or_die('mail_reply', 40, 600, (string)$user['id']);
    $split = fn($v) => array_filter(array_map('trim', preg_split('/[,;\s]+/', (string)$v)));
    $body = (string)($in['body'] ?? '');
    if (mb_strlen($body) > 50000) $out(['ok' => false, 'error' => 'Message trop long.'], 400);
    $r = mail_send_reply($pdo, $acc, $t, $user, $split($in['to'] ?? ''), $split($in['cc'] ?? ''), $body);
    if ($r['ok']) {
        mail_mark_read($pdo, $acc, mail_thread_for_user($pdo, $user, (int)$t['id']) ?: $t, true);
        if (function_exists('activity_log_action')) { try { activity_log_action('mail_reply', ['thread' => (int)$t['id']]); } catch (Throwable $e) {} }
    }
    $out(['ok' => $r['ok'], 'error' => $r['erreur'], 'simulated' => !empty($r['simule'])], $r['ok'] ? 200 : 400);

case 'draft':
    $t = $thread();
    if (function_exists('ak_rate_limit_or_die')) ak_rate_limit_or_die('mail_draft', 30, 600, (string)$user['id']);
    @set_time_limit(90);
    $r = mail_ai_draft($pdo, $acc, $t, $user, mb_substr(trim((string)($in['consigne'] ?? '')), 0, 500));
    $out(['ok' => $r['ok'], 'text' => $r['texte'], 'error' => $r['erreur']], $r['ok'] ? 200 : 400);

case 'settings':
    if (!$manage) $out(['ok' => false, 'error' => 'Réservé aux administrateurs.'], 403);
    $pdo->prepare("UPDATE mail_accounts SET display_name = ?, signature = ?, sync_days = ?, retention_months = ?, ai_sort = ? WHERE id = ?")->execute([
        mb_substr(trim((string)($in['display_name'] ?? '')), 0, 190) ?: null,
        mb_substr(trim((string)($in['signature'] ?? '')), 0, 2000) ?: null,
        max(1, min(365, (int)($in['sync_days'] ?? 30))),
        max(1, min(120, (int)($in['retention_months'] ?? 24))),
        !empty($in['ai_sort']) ? 1 : 0,
        $acc['id'],
    ]);
    $out(['ok' => true]);

case 'reclassify':
    if (!$manage) $out(['ok' => false, 'error' => 'Réservé aux administrateurs.'], 403);
    @set_time_limit(120);
    $pdo->prepare("UPDATE mail_threads SET category_source = 'none' WHERE org_id = ? AND category_source IN ('rule','ai','default','none')")->execute([$org]);
    $n = 0;
    for ($i = 0; $i < 10; $i++) { $k = mail_classify_pending($pdo, $acc, 60); $n += $k; if ($k < 60) break; }
    $out(['ok' => true, 'count' => $n]);

case 'category_save':
    if (!$manage) $out(['ok' => false, 'error' => 'Réservé aux administrateurs.'], 403);
    $label = mb_substr(trim((string)($in['label'] ?? '')), 0, 80);
    if ($label === '') $out(['ok' => false, 'error' => 'Nom obligatoire.'], 400);
    $color = preg_match('/^#[0-9A-Fa-f]{6}$/', (string)($in['color'] ?? '')) ? $in['color'] : '#64748B';
    $kw = mb_substr(trim(preg_replace('/\s*,\s*/', ', ', (string)($in['keywords'] ?? ''))), 0, 4000);
    $roles = implode(',', array_intersect((array)($in['roles'] ?? []), ['admin', 'coordinator']));
    $id = (int)($in['id'] ?? 0);
    if ($id) {
        $pdo->prepare("UPDATE mail_categories SET label = ?, color = ?, keywords = ?, roles = ? WHERE id = ? AND org_id = ?")->execute([$label, $color, $kw, $roles ?: null, $id, $org]);
    } else {
        $slug = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower(@iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $label) ?: 'cat')), '-') ?: 'cat';
        $slug = substr($slug, 0, 30) . '-' . substr(bin2hex(random_bytes(2)), 0, 4);
        $pos = (int)$pdo->query("SELECT COALESCE(MAX(position),0) FROM mail_categories WHERE org_id = $org")->fetchColumn();
        $pdo->prepare("INSERT INTO mail_categories (org_id, slug, label, color, icon, keywords, roles, position) VALUES (?,?,?,?, 'tag', ?,?,?)")
            ->execute([$org, $slug, $label, $color, $kw, $roles ?: null, $pos]);
        // « Autres » reste en dernier
        $pdo->prepare("UPDATE mail_categories SET position = ? WHERE org_id = ? AND slug = 'autre'")->execute([$pos + 1, $org]);
    }
    $out(['ok' => true]);

case 'category_delete':
    if (!$manage) $out(['ok' => false, 'error' => 'Réservé aux administrateurs.'], 403);
    $id = (int)($in['id'] ?? 0);
    $autre = mail_category_by_slug($pdo, $org, 'autre');
    $s = $pdo->prepare("SELECT is_system FROM mail_categories WHERE id = ? AND org_id = ?");
    $s->execute([$id, $org]);
    $sys = $s->fetchColumn();
    if ($sys === false || (int)$sys === 1) $out(['ok' => false, 'error' => 'Cette catégorie ne peut pas être supprimée.'], 400);
    $pdo->prepare("UPDATE mail_threads SET category_id = ?, category_source = 'default' WHERE org_id = ? AND category_id = ?")->execute([$autre['id'] ?? null, $org, $id]);
    $pdo->prepare("DELETE FROM mail_categories WHERE id = ? AND org_id = ?")->execute([$id, $org]);
    $out(['ok' => true]);

case 'category_order':
    if (!$manage) $out(['ok' => false, 'error' => 'Réservé aux administrateurs.'], 403);
    $up = $pdo->prepare("UPDATE mail_categories SET position = ? WHERE id = ? AND org_id = ? AND slug <> 'autre'");
    foreach (array_values((array)($in['ids'] ?? [])) as $i => $cid) $up->execute([$i + 1, (int)$cid, $org]);
    $out(['ok' => true]);

case 'disconnect':
    if (!$manage) $out(['ok' => false, 'error' => 'Réservé aux administrateurs.'], 403);
    require_once __DIR__ . '/demo-guard.php';
    if (ak_demo_session()) $out(['ok' => false, 'error' => 'Désactivé dans l’espace de démonstration.'], 403);
    mail_disconnect($pdo, $org, true);
    if (function_exists('activity_log_action')) { try { activity_log_action('mail_disconnect', []); } catch (Throwable $e) {} }
    $out(['ok' => true]);
}
$out(['ok' => false, 'error' => 'Action inconnue.'], 400);
