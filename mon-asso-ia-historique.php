<?php
/**
 * mon-asso-ia-historique.php
 * --------------------------------------------------------------
 * Historique des générations IA — Pack PHASE 4.5
 * Avec filtre par DOSSIER + affichage du dossier
 * --------------------------------------------------------------
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes-layout.php';
require_once __DIR__ . '/asso-ai-helpers.php';

require_login();
$user = current_user();
$org_id = (int)($user['org_id'] ?? 0);
if ($org_id <= 0) { header('Location: /'); exit; }

$f_folder = $_GET['folder'] ?? '';
$f_tool   = $_GET['tool']   ?? '';
$f_q      = trim($_GET['q'] ?? '');
$f_fav    = !empty($_GET['fav']);

$where = ['org_id = :o', "status = 'success'"];
$params = [':o' => $org_id];

$folders_cat = ak_ai_folders_catalog();
$tools_cat   = ak_ai_tools_catalog();

if ($f_folder !== '' && isset($folders_cat[$f_folder])) {
    $where[] = 'folder = :f';
    $params[':f'] = $f_folder;
}
if ($f_tool !== '' && isset($tools_cat[$f_tool])) {
    $where[] = 'tool_type = :t';
    $params[':t'] = $f_tool;
}
if ($f_q !== '') {
    $where[] = '(title LIKE :q OR output_text LIKE :q)';
    $params[':q'] = '%' . $f_q . '%';
}
if ($f_fav) $where[] = 'is_favorite = 1';

$rows = [];
$err = null;
try {
    $sql = "SELECT id, tool_type, folder, title, output_text, is_favorite, tokens_input, tokens_output, created_at
            FROM asso_ai_generations
            WHERE " . implode(' AND ', $where) . "
            ORDER BY created_at DESC LIMIT 200";
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $err = $e->getMessage();
}

render_head('Historique Communication IA');
render_sidebar('ia');
?>

<main class="main">
  <style>
    .iah-page { font-family: 'Geist', system-ui, sans-serif; color: #0F172A; }
    .iah-head { display:flex; justify-content:space-between; align-items:center; margin-bottom:20px; flex-wrap:wrap; gap:12px; }
    .iah-head h1 { margin:0; font-size:24px; font-weight:700; }
    .iah-head .sub { color:#64748B; font-size:13px; margin-top:2px; }

    .iah-filters { background:white; border:1px solid #E2E8F0; border-radius:12px; padding:14px; margin-bottom:18px; display:flex; gap:10px; flex-wrap:wrap; align-items:center; }
    .iah-filters input, .iah-filters select { padding:9px 12px; border:1px solid #E2E8F0; border-radius:8px; font-size:14px; font-family:inherit; }
    .iah-filters input[type=text] { flex:1; min-width:180px; }
    .iah-filters .iah-btn { padding:9px 14px; border-radius:8px; border:1px solid #E2E8F0; background:white; cursor:pointer; font-size:13px; font-weight:600; color:#475569; text-decoration:none; }
    .iah-filters .iah-btn.active { background:#FEF3C7; border-color:#F59E0B; color:#92400E; }

    .iah-list { display: grid; gap: 12px; }
    .iah-row { background:white; border:1px solid #E2E8F0; border-radius:12px; padding:16px; display:flex; gap:14px; align-items:flex-start; }
    .iah-ico { width:40px; height:40px; border-radius:10px; display:flex; align-items:center; justify-content:center; font-size:20px; flex-shrink:0; }
    .iah-info { flex:1; min-width:0; }
    .iah-info .top { display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
    .iah-info .title { font-weight:700; font-size:15px; }
    .iah-info .pill { font-size:11px; padding:2px 8px; border-radius:999px; font-weight:600; }
    .iah-info .preview { font-size:13px; color:#64748B; margin-top:6px; line-height:1.5; max-height:42px; overflow:hidden; }
    .iah-info .meta { font-size:12px; color:#94A3B8; margin-top:6px; }
    .iah-actions { display:flex; gap:6px; flex-wrap:wrap; align-items:center; }
    .iah-btn-sm { padding:6px 10px; font-size:12px; border-radius:6px; border:1px solid #E2E8F0; background:white; cursor:pointer; text-decoration:none; color:#475569; font-weight:600; display:inline-flex; align-items:center; gap:4px; }
    .iah-btn-sm.fav-on { background:#FEF3C7; color:#92400E; border-color:#F59E0B; }
    .iah-btn-sm.del { background:#FEE2E2; color:#991B1B; border-color:#FECACA; }
    .iah-empty { padding:60px 20px; text-align:center; color:#64748B; background:white; border:1px solid #E2E8F0; border-radius:14px; }
    .iah-empty .big { font-size:48px; opacity:0.4; margin-bottom:12px; }
  </style>

  <div class="iah-page">

    <div class="iah-head">
      <div>
        <h1>Historique des générations</h1>
        <div class="sub"><?= count($rows) ?> résultat(s)</div>
      </div>
      <a href="/mon-asso-ia" class="iah-btn-sm">← Communication IA</a>
    </div>

    <?php if ($err): ?>
      <div style="background:#FEE2E2;border:1px solid #FECACA;color:#991B1B;padding:12px;border-radius:10px;margin-bottom:16px;">⚠️ <?= h($err) ?></div>
    <?php endif; ?>

    <form class="iah-filters" method="get" action="/mon-asso-ia-historique">
      <input type="text" name="q" placeholder="🔍 Rechercher dans le titre ou le contenu…" value="<?= h($f_q) ?>">
      <select name="folder">
        <option value="">Tous les dossiers</option>
        <?php foreach ($folders_cat as $k => $f): ?>
          <option value="<?= h($k) ?>" <?= $f_folder===$k?'selected':'' ?>><?= $f['icon'] ?> <?= h($f['label']) ?></option>
        <?php endforeach; ?>
      </select>
      <select name="tool">
        <option value="">Tous les outils</option>
        <?php foreach ($tools_cat as $k => $t): ?>
          <option value="<?= h($k) ?>" <?= $f_tool===$k?'selected':'' ?>><?= $t['icon'] ?> <?= h($t['label']) ?></option>
        <?php endforeach; ?>
      </select>
      <button type="submit" class="iah-btn">Filtrer</button>
      <a class="iah-btn <?= $f_fav?'active':'' ?>" href="?<?= http_build_query(array_merge($_GET, ['fav' => $f_fav?0:1])) ?>">⭐ Favoris</a>
      <?php if ($f_q || $f_tool || $f_folder || $f_fav): ?>
        <a class="iah-btn" href="/mon-asso-ia-historique">Réinitialiser</a>
      <?php endif; ?>
    </form>

    <?php if (empty($rows)): ?>
      <div class="iah-empty">
        <div class="big">📭</div>
        <div style="font-weight:600;color:#0F172A;margin-bottom:6px;">Aucune génération trouvée</div>
        <div style="margin-bottom:14px;">Lancez votre première création depuis le hub.</div>
        <a class="iah-btn-sm" href="/mon-asso-ia">→ Communication IA</a>
      </div>
    <?php else: ?>
      <div class="iah-list">
        <?php foreach ($rows as $r):
          $tool   = ak_ai_tool($r['tool_type']);
          $folder = $r['folder'] ? ak_ai_folder($r['folder']) : null;
          $color  = $folder ? $folder['color'] : '#475569';
          $preview = mb_substr(strip_tags($r['output_text'] ?? ''), 0, 200);
        ?>
          <div class="iah-row">
            <div class="iah-ico" style="background:<?= h($color) ?>22;color:<?= h($color) ?>;"><?= $tool['icon'] ?></div>
            <div class="iah-info">
              <div class="top">
                <span class="title"><?= h($r['title']) ?: h($tool['label']) ?></span>
                <?php if ($folder): ?>
                  <span class="pill" style="background:<?= h($folder['color']) ?>22;color:<?= h($folder['color']) ?>;"><?= $folder['icon'] ?> <?= h($folder['label']) ?></span>
                <?php endif; ?>
                <span class="pill" style="background:#F1F5F9;color:#475569;"><?= h($tool['label']) ?></span>
                <?php if ((int)$r['is_favorite'] === 1): ?>
                  <span class="pill" style="background:#FEF3C7;color:#92400E;">⭐ Favori</span>
                <?php endif; ?>
              </div>
              <div class="preview"><?= h($preview) ?>…</div>
              <div class="meta">
                <?= h(date('d/m/Y H:i', strtotime($r['created_at']))) ?>
                · <?= (int)$r['tokens_input'] + (int)$r['tokens_output'] ?> tokens
              </div>
            </div>
            <div class="iah-actions">
              <a class="iah-btn-sm" href="/mon-asso-ia-tool?type=<?= h($r['tool_type']) ?>&gen=<?= (int)$r['id'] ?>">Voir</a>
              <a class="iah-btn-sm" href="/mon-asso-ia-diffusion?gen=<?= (int)$r['id'] ?>" title="Diffuser par email">📨</a>
              <a class="iah-btn-sm" href="/mon-asso-ia-download?id=<?= (int)$r['id'] ?>&fmt=md" target="_blank">⬇</a>
              <form method="post" action="/mon-asso-ia-action" style="display:inline;">
                <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token'] ?? '') ?>">
                <input type="hidden" name="action" value="toggle_fav">
                <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                <button type="submit" class="iah-btn-sm <?= (int)$r['is_favorite']===1?'fav-on':'' ?>">⭐</button>
              </form>
              <form method="post" action="/mon-asso-ia-action" style="display:inline;" onsubmit="return confirm('Supprimer cette génération ?');">
                <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token'] ?? '') ?>">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                <button type="submit" class="iah-btn-sm del">🗑</button>
              </form>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

  </div>
</main>

<?php render_foot(); ?>
