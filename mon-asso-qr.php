<?php
/**
 * mon-asso-qr.php — Codes QR de collecte de contacts (association).
 * --------------------------------------------------------------
 * L'association crée un QR par usage : accueil d'un événement, stand,
 * affiche, flyer. Scanné, il ouvre /qr/<token> : la personne enregistre
 * l'association dans son téléphone et laisse ses coordonnées.
 *
 * Les contacts collectés arrivent dans l'onglet Prospection, prêts à être
 * rappelés. Aucune liste séparée à recopier.
 *
 * Le QR est dessiné dans le navigateur, à partir de l'URL. Il n'est ni
 * stocké ni généré côté serveur : c'est une fonction pure de l'adresse,
 * la regénérer donne toujours la même image.
 * --------------------------------------------------------------
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes-layout.php';

require_login();
$user = current_user();
if (empty($user['org_id'])) { http_response_code(403); die('Aucune association rattachée à ce compte.'); }
$org_id = (int) $user['org_id'];
$uid    = (int) $user['id'];

$role = (string) ($user['role'] ?? '');
$allowed = in_array($role, ['admin', 'coordinator'], true);
if (!$allowed && function_exists('can')) $allowed = can('access_marketing');
if (!$allowed) { http_response_code(403); die('Accès refusé.'); }

if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$csrf = $_SESSION['csrf_token'];

$flash = $_SESSION['flash_qr'] ?? null;
unset($_SESSION['flash_qr']);
$migration_missing = false;

// ── Écritures ───────────────────────────────────────────────────────────────
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!hash_equals($csrf, (string) ($_POST['csrf_token'] ?? ''))) {
        http_response_code(419); exit('Jeton CSRF invalide.');
    }
    $action = (string) ($_POST['action'] ?? '');
    $id     = (int) ($_POST['id'] ?? 0);
    $msg    = null;

    try {
        if ($action === 'create') {
            $label = trim((string) ($_POST['label'] ?? ''));
            if ($label === '') {
                $msg = "Donnez un nom à ce code — « Forum des associations », « Stand du samedi »… C'est ce qui vous permettra de savoir d'où viennent les contacts.";
            } else {
                // 32 hexadécimaux : l'adresse d'une page publique ne doit pas se
                // déduire de l'identifiant de l'organisation.
                $token = bin2hex(random_bytes(16));
                $pdo->prepare("INSERT INTO asso_qr_codes
                        (org_id, token, label, intro, show_vcard, ask_phone, ask_message, created_by, updated_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())")
                    ->execute([$org_id, $token, mb_substr($label, 0, 120),
                               mb_substr(trim((string) ($_POST['intro'] ?? '')), 0, 300),
                               empty($_POST['show_vcard']) ? 0 : 1,
                               empty($_POST['ask_phone']) ? 0 : 1,
                               empty($_POST['ask_message']) ? 0 : 1,
                               $uid]);
                $msg = "Code QR « $label » créé. Téléchargez-le et imprimez-le.";
            }

        } elseif ($action === 'update' && $id > 0) {
            $st = $pdo->prepare("UPDATE asso_qr_codes
                                 SET label = ?, intro = ?, show_vcard = ?, ask_phone = ?, ask_message = ?, updated_at = NOW()
                                 WHERE id = ? AND org_id = ? AND deleted_at IS NULL");
            $st->execute([mb_substr(trim((string) ($_POST['label'] ?? '')), 0, 120),
                          mb_substr(trim((string) ($_POST['intro'] ?? '')), 0, 300),
                          empty($_POST['show_vcard']) ? 0 : 1,
                          empty($_POST['ask_phone']) ? 0 : 1,
                          empty($_POST['ask_message']) ? 0 : 1,
                          $id, $org_id]);
            if ($st->rowCount() > 0) $msg = "Code QR mis à jour. Les codes déjà imprimés suivent — l'adresse n'a pas changé.";

        } elseif ($action === 'toggle' && $id > 0) {
            $st = $pdo->prepare("UPDATE asso_qr_codes SET is_active = 1 - is_active, updated_at = NOW()
                                 WHERE id = ? AND org_id = ? AND deleted_at IS NULL");
            $st->execute([$id, $org_id]);
            if ($st->rowCount() > 0) $msg = "État du code QR modifié.";

        } elseif ($action === 'delete' && $id > 0) {
            $st = $pdo->prepare("UPDATE asso_qr_codes SET deleted_at = NOW(), is_active = 0
                                 WHERE id = ? AND org_id = ? AND deleted_at IS NULL");
            $st->execute([$id, $org_id]);
            if ($st->rowCount() > 0) $msg = "Code QR supprimé. Les contacts déjà collectés restent dans la prospection.";
        }
    } catch (Throwable $e) {
        $msg = "Échec : " . $e->getMessage();
    }

    $_SESSION['flash_qr'] = $msg;
    header('Location: /mon-asso-qr');
    exit;
}

// ── Lecture ─────────────────────────────────────────────────────────────────
$codes = [];
try {
    $st = $pdo->prepare("SELECT * FROM asso_qr_codes
                         WHERE org_id = ? AND deleted_at IS NULL
                         ORDER BY is_active DESC, id DESC");
    $st->execute([$org_id]);
    $codes = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $migration_missing = true;
}

$total_scans = array_sum(array_column($codes, 'scan_count'));
$total_leads = array_sum(array_column($codes, 'submit_count'));

$BASE = 'https://assokit.fr';

render_head('Codes QR');
render_sidebar('mon-asso-qr');
?>

<main class="main">
<style>
  .qz-top{display:flex;align-items:flex-start;justify-content:space-between;gap:18px;flex-wrap:wrap;margin-bottom:20px}
  .qz-kpis{display:flex;gap:9px;flex-wrap:wrap}
  .qz-kpi{background:#fff;border:1px solid var(--line,#E7EEEA);border-radius:12px;padding:9px 15px;min-width:96px}
  .qz-kpi b{display:block;font-size:20px;line-height:1.2}
  .qz-kpi span{font-size:11px;color:var(--ink-3,#5F6D66);text-transform:uppercase;letter-spacing:.04em}
  .qz-flash{border:1px solid #A7F3D0;background:#ECFDF5;color:#065F46;border-radius:11px;padding:12px 15px;margin-bottom:16px;font-size:14px}
  .qz-panel{background:#fff;border:1px solid var(--line,#E7EEEA);border-radius:16px;padding:18px;margin-bottom:16px}
  .qz-panel h2{font-size:16px;margin:0 0 4px}
  .qz-panel p.sub{color:var(--ink-3,#5F6D66);font-size:13.5px;margin:0 0 16px}
  label.f{display:block;font-size:12.5px;font-weight:600;color:var(--ink-2,#45544D);margin-bottom:5px}
  .qz-in{width:100%;padding:10px 13px;border:1px solid var(--line,#E7EEEA);border-radius:10px;font:inherit;font-size:14px;margin-bottom:13px}
  .qz-in:focus{outline:2px solid #05966933;border-color:#059669}
  .qz-opts{display:flex;gap:18px;flex-wrap:wrap;margin-bottom:15px}
  .qz-opts label{display:flex;align-items:center;gap:8px;font-size:13.5px;color:var(--ink-2,#45544D)}
  .qz-btn{padding:11px 19px;border-radius:10px;border:none;background:#059669;color:#fff;font:inherit;font-weight:600;font-size:14px;cursor:pointer}
  .qz-btn.sec{background:#fff;border:1px solid var(--line,#E7EEEA);color:var(--ink-2,#45544D)}
  .qz-btn.dgr{background:#fff;border:1px solid #FECACA;color:#B91C1C}
  .qz-card{background:#fff;border:1px solid var(--line,#E7EEEA);border-radius:16px;margin-bottom:14px;overflow:hidden}
  .qz-card.off{opacity:.62}
  .qz-grid{display:grid;grid-template-columns:210px 1fr;gap:22px;padding:18px;align-items:start}
  .qz-qr{text-align:center}
  .qz-qr .box{width:180px;height:180px;margin:0 auto 10px;border:1px solid var(--line,#E7EEEA);border-radius:12px;padding:8px;background:#fff}
  .qz-qr .box svg{width:100%;height:100%;display:block}
  .qz-dl{display:flex;gap:8px;justify-content:center;flex-wrap:wrap;font-size:12.5px}
  .qz-dl button{background:none;border:none;color:#059669;font:inherit;font-weight:600;cursor:pointer;padding:2px 4px}
  .qz-head{display:flex;align-items:center;gap:11px;flex-wrap:wrap;margin-bottom:6px}
  .qz-head b{font-size:16px}
  .qz-tag{font-size:11px;font-weight:700;padding:3px 9px;border-radius:999px}
  .qz-on{background:#D1FAE5;color:#065F46}
  .qz-offb{background:#F1F5F9;color:#64748B}
  .qz-url{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:12.5px;color:var(--ink-3,#5F6D66);
          background:var(--bg-2,#EDF2EF);padding:7px 11px;border-radius:9px;display:inline-flex;align-items:center;
          gap:9px;margin-bottom:14px;overflow-wrap:anywhere}
  .qz-url button{background:none;border:none;color:#059669;font:inherit;font-weight:600;cursor:pointer;flex:none}
  .qz-stats{display:flex;gap:20px;flex-wrap:wrap;margin-bottom:15px;font-size:13px;color:var(--ink-3,#5F6D66)}
  .qz-stats b{color:var(--ink,#0B1A13);font-size:16px;display:block}
  .qz-acts{display:flex;gap:8px;flex-wrap:wrap;align-items:center}
  .qz-empty{text-align:center;padding:40px 20px;color:var(--ink-3,#5F6D66)}
  @media (max-width:760px){ .qz-grid{grid-template-columns:1fr} }
</style>

<div class="qz-top">
  <div>
    <h1 style="font-size:23px;margin:0 0 4px">Codes QR de collecte</h1>
    <p style="color:var(--ink-3,#5F6D66);font-size:14px;margin:0;max-width:660px">
      Imprimez un code sur votre stand ou vos affiches. En le scannant, la personne
      vous enregistre dans son téléphone et vous laisse ses coordonnées — qui arrivent
      directement dans <a href="/prospection" style="color:#059669;font-weight:600">Prospection</a>.
    </p>
  </div>
  <div class="qz-kpis">
    <div class="qz-kpi"><b><?= count($codes) ?></b><span>codes</span></div>
    <div class="qz-kpi"><b><?= (int) $total_scans ?></b><span>scans</span></div>
    <div class="qz-kpi"><b><?= (int) $total_leads ?></b><span>contacts</span></div>
  </div>
</div>

<?php if ($flash): ?><div class="qz-flash"><?= h($flash) ?></div><?php endif; ?>

<?php if ($migration_missing): ?>
  <div class="qz-flash" style="background:#FEF2F2;border-color:#FECACA;color:#991B1B">
    La table <code>asso_qr_codes</code> n'existe pas encore. Sur le serveur :
    <code style="display:inline-block;margin-top:6px">php migrations/run.php 2026-09-09-qr-collecte-asso.sql</code>
  </div>
<?php else: ?>

<div class="qz-panel">
  <h2>Nouveau code QR</h2>
  <p class="sub">Un code par occasion : vous saurez ainsi d'où vient chaque contact.</p>
  <form method="post" action="/mon-asso-qr">
    <input type="hidden" name="action" value="create">
    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
    <label class="f" for="nlabel">Nom du code</label>
    <input class="qz-in" id="nlabel" name="label" placeholder="Forum des associations 2026" maxlength="120">
    <label class="f" for="nintro">Message d'accueil <span style="font-weight:400;color:#5F6D66">— facultatif</span></label>
    <input class="qz-in" id="nintro" name="intro" placeholder="Merci de votre visite ! Laissez-nous vos coordonnées, nous revenons vers vous." maxlength="300">
    <div class="qz-opts">
      <label><input type="checkbox" name="show_vcard" value="1" checked> Proposer d'enregistrer l'association dans les contacts</label>
      <label><input type="checkbox" name="ask_phone" value="1" checked> Demander le téléphone</label>
      <label><input type="checkbox" name="ask_message" value="1"> Champ message libre</label>
    </div>
    <button class="qz-btn" type="submit">Créer le code QR</button>
  </form>
</div>

<?php if (!$codes): ?>
  <div class="qz-panel qz-empty">Aucun code pour l'instant. Créez le premier ci-dessus.</div>
<?php endif; ?>

<?php foreach ($codes as $c):
  $url = $BASE . '/qr/' . $c['token'];
  $on  = !empty($c['is_active']);
?>
<div class="qz-card <?= $on ? '' : 'off' ?>">
  <div class="qz-grid">
    <div class="qz-qr">
      <div class="box" data-qr="<?= h($url) ?>" data-name="qr-<?= h(preg_replace('/[^a-z0-9]+/i', '-', (string) $c['label']) ?: $c['id']) ?>"></div>
      <div class="qz-dl">
        <button type="button" data-dl="svg">SVG</button>
        <button type="button" data-dl="png">PNG</button>
        <a href="<?= h($url) ?>" target="_blank" rel="noopener" style="color:#059669;text-decoration:none;font-weight:600;padding:2px 4px">Aperçu</a>
      </div>
    </div>

    <div>
      <div class="qz-head">
        <b><?= h($c['label']) ?></b>
        <span class="qz-tag <?= $on ? 'qz-on' : 'qz-offb' ?>"><?= $on ? 'ACTIF' : 'DÉSACTIVÉ' ?></span>
      </div>
      <div class="qz-url"><span><?= h($url) ?></span><button type="button" data-copy="<?= h($url) ?>">Copier</button></div>

      <div class="qz-stats">
        <div><b><?= (int) $c['scan_count'] ?></b>scan<?= (int) $c['scan_count'] > 1 ? 's' : '' ?></div>
        <div><b><?= (int) $c['submit_count'] ?></b>contact<?= (int) $c['submit_count'] > 1 ? 's' : '' ?> collecté<?= (int) $c['submit_count'] > 1 ? 's' : '' ?></div>
        <?php if (!empty($c['last_scan_at'])): ?>
          <div style="align-self:center">dernier scan le <?= h(date('d/m/Y à H:i', strtotime((string) $c['last_scan_at']))) ?></div>
        <?php endif; ?>
      </div>

      <form method="post" action="/mon-asso-qr">
        <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
        <label class="f">Nom du code</label>
        <input class="qz-in" name="label" value="<?= h($c['label']) ?>" maxlength="120">
        <label class="f">Message d'accueil</label>
        <input class="qz-in" name="intro" value="<?= h($c['intro']) ?>" maxlength="300">
        <div class="qz-opts">
          <label><input type="checkbox" name="show_vcard" value="1" <?= !empty($c['show_vcard']) ? 'checked' : '' ?>> Fiche contact de l'association</label>
          <label><input type="checkbox" name="ask_phone" value="1" <?= !empty($c['ask_phone']) ? 'checked' : '' ?>> Téléphone</label>
          <label><input type="checkbox" name="ask_message" value="1" <?= !empty($c['ask_message']) ? 'checked' : '' ?>> Message libre</label>
        </div>
        <div class="qz-acts">
          <button class="qz-btn" type="submit" name="action" value="update">Enregistrer</button>
          <button class="qz-btn sec" type="submit" name="action" value="toggle"
                  onclick="return confirm('<?= $on ? 'Désactiver ce code ? Les personnes qui le scanneront verront « ce code QR n\'est plus actif ».' : 'Réactiver ce code ?' ?>')">
            <?= $on ? 'Désactiver' : 'Réactiver' ?>
          </button>
          <button class="qz-btn dgr" type="submit" name="action" value="delete"
                  onclick="return confirm('Supprimer définitivement ce code ? Les codes déjà imprimés cesseront de fonctionner. Les contacts déjà collectés restent dans la prospection.')">
            Supprimer
          </button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endforeach; ?>

<script src="/assets/js/qrcode.js"></script>
<script>
(function () {
  // Le QR est une fonction pure de l'URL : on le dessine à l'affichage plutôt
  // que de stocker une image, qui pourrait diverger de l'adresse réelle.
  // Correction d'erreur en niveau H (30 %) : tient l'impression sur papier mat.
  document.querySelectorAll('[data-qr]').forEach(function (box) {
    var q = qrcode(0, 'H');
    q.addData(box.dataset.qr);
    q.make();
    box.innerHTML = q.createSvgTag({ cellSize: 6, margin: 2, scalable: true });

    var wrap = box.closest('.qz-qr');
    var name = box.dataset.name || 'qr-assokit';

    function save(blob, ext) {
      var a = document.createElement('a');
      a.href = URL.createObjectURL(blob);
      a.download = name + '.' + ext;
      document.body.appendChild(a); a.click();
      setTimeout(function () { URL.revokeObjectURL(a.href); a.remove(); }, 0);
    }

    wrap.querySelector('[data-dl="svg"]').addEventListener('click', function () {
      save(new Blob([box.innerHTML], { type: 'image/svg+xml' }), 'svg');
    });

    wrap.querySelector('[data-dl="png"]').addEventListener('click', function () {
      // Dessin module par module : createDataURL ne sort que du GIF, et un
      // imprimeur attend un PNG à haute résolution.
      var n = q.getModuleCount(), m = 4, px = 16;
      var side = (n + m * 2) * px;
      var cv = document.createElement('canvas');
      cv.width = cv.height = side;
      var g = cv.getContext('2d');
      g.fillStyle = '#FFFFFF'; g.fillRect(0, 0, side, side);
      g.fillStyle = '#0B1A13';
      for (var r = 0; r < n; r++) for (var c = 0; c < n; c++) {
        if (q.isDark(r, c)) g.fillRect((c + m) * px, (r + m) * px, px, px);
      }
      cv.toBlob(function (b) { save(b, 'png'); }, 'image/png');
    });
  });

  document.querySelectorAll('[data-copy]').forEach(function (b) {
    b.addEventListener('click', function () {
      var t = b.dataset.copy, old = b.textContent;
      var done = function () { b.textContent = 'Copié'; setTimeout(function () { b.textContent = old; }, 1600); };
      if (navigator.clipboard) { navigator.clipboard.writeText(t).then(done, done); return; }
      var i = document.createElement('textarea');
      i.value = t; document.body.appendChild(i); i.select();
      try { document.execCommand('copy'); } catch (e) {}
      i.remove(); done();
    });
  });
})();
</script>

<p style="color:var(--ink-3,#5F6D66);font-size:13px;margin:20px 0 40px;max-width:700px">
  À l'impression : 2 cm de côté au minimum, marge blanche conservée, jamais inversé.
  Modifier le nom ou le message d'un code ne change pas son adresse : les exemplaires
  déjà imprimés continuent de fonctionner.
</p>

<?php endif; ?>
</main>
<?php render_foot(); ?>
