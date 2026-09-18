<?php
/**
 * fondateur-listes.php — Les groupes d'adresses des campagnes.
 * ------------------------------------------------------------------
 * Créer une liste, y verser un fichier d'adresses, voir ce qu'elle
 * contient. Réservé au Fondateur.
 *
 * Les contacts ne sont pas propres à cette page : ils vivent dans
 * asso_prospects, partagée avec la séquence de prospection, pour qu'un
 * désabonnement vaille partout. Une liste n'est qu'un regroupement.
 * ------------------------------------------------------------------
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes-layout.php';
require_once __DIR__ . '/superadmin-layout.php';
require_once __DIR__ . '/sa-permissions.php';
require_once __DIR__ . '/fondateur-emailing-helpers.php';

require_login();
$user = sa_require_super_admin();
$ctx  = sa_get_permissions_context();
if (empty($ctx['is_founder'])) {
    http_response_code(403);
    sa_render_head('Accès refusé'); sa_render_sidebar('fondateur-pilotage');
    echo '<div style="max-width:560px;margin:50px auto;text-align:center;padding:34px;'
       . 'background:var(--sa-bg-2);border:1px solid var(--sa-border);border-radius:16px;">'
       . '<div style="font-size:44px;">🏗️</div><h1>Réservé au Fondateur</h1>'
       . '<a href="/super-admin" class="sa-btn sa-btn-ghost" style="margin-top:16px;">← Retour</a></div>';
    sa_render_foot(); exit;
}

ak_prospect_tables_ensure($pdo);
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
$CSRF = $_SESSION['csrf_token'];

$flash = $_SESSION['flash_listes'] ?? null;
unset($_SESSION['flash_listes']);

// Les tables manquent tant que la migration n'est pas passée : on le dit
// clairement plutôt que de laisser la page tomber sur une erreur SQL.
$migration_ok = true;
try { $pdo->query("SELECT 1 FROM fond_listes LIMIT 1")->closeCursor(); }
catch (Throwable $e) { $migration_ok = false; }

// ── Écritures ───────────────────────────────────────────────────────
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && $migration_ok) {
    if (!hash_equals($CSRF, (string) ($_POST['csrf_token'] ?? ''))) {
        http_response_code(419); exit('Jeton CSRF invalide.');
    }
    $action = (string) ($_POST['action'] ?? '');
    $lid    = (int) ($_POST['liste_id'] ?? 0);
    $msg    = null;

    try {
        if ($action === 'creer') {
            $nom = trim((string) ($_POST['nom'] ?? ''));
            if ($nom === '') {
                $msg = "Donnez un nom à la liste.";
            } else {
                $pdo->prepare("INSERT INTO fond_listes (nom, description, cree_par) VALUES (?, ?, ?)")
                    ->execute([mb_substr($nom, 0, 160),
                               mb_substr(trim((string) ($_POST['description'] ?? '')), 0, 500) ?: null,
                               (int) $user['id']]);
                $msg = "Liste « " . $nom . " » créée. Vous pouvez y verser un fichier d'adresses.";
            }

        } elseif ($action === 'importer' && $lid > 0) {
            $f = $_FILES['fichier'] ?? null;
            if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                $msg = "Choisissez un fichier .xlsx ou .csv.";
            } elseif (!is_uploaded_file($f['tmp_name'])) {
                $msg = "Fichier inattendu.";
            } else {
                $r = ak_camp_importer($pdo, $lid, $f['tmp_name'], (string) $f['name']);
                $msg = ($r['ok'] ? '' : 'Échec : ') . $r['message'];
            }

        } elseif ($action === 'retirer' && $lid > 0) {
            // On retire de la liste, on ne supprime pas le contact : il
            // peut être dans d'autres listes, et surtout son éventuel
            // désabonnement doit rester enregistré.
            $st = $pdo->prepare("DELETE FROM fond_liste_membres WHERE liste_id = ? AND prospect_id = ?");
            $st->execute([$lid, (int) ($_POST['prospect_id'] ?? 0)]);
            $msg = $st->rowCount() ? "Contact retiré de la liste." : "Contact déjà absent.";

        } elseif ($action === 'supprimer_liste' && $lid > 0) {
            $st = $pdo->prepare("SELECT COUNT(*) FROM fond_campagnes WHERE liste_id = ?");
            $st->execute([$lid]);
            if ((int) $st->fetchColumn() > 0) {
                // Refuser plutôt que casser l'historique : une campagne
                // envoyée doit pouvoir dire à qui elle l'a été.
                $msg = "Impossible : des campagnes utilisent cette liste. Supprimez-les d'abord.";
            } else {
                $pdo->prepare("DELETE FROM fond_liste_membres WHERE liste_id = ?")->execute([$lid]);
                $pdo->prepare("DELETE FROM fond_listes WHERE id = ?")->execute([$lid]);
                $msg = "Liste supprimée. Les contacts, eux, sont conservés.";
            }
        }
    } catch (Throwable $e) {
        $msg = "Échec : " . $e->getMessage();
    }

    $_SESSION['flash_listes'] = $msg;
    header('Location: /fondateur-listes' . ($lid > 0 && $action !== 'supprimer_liste' ? '?l=' . $lid : ''));
    exit;
}

// ── Lecture ─────────────────────────────────────────────────────────
$listes = [];
$vue = (int) ($_GET['l'] ?? 0);
$membres = [];
$detail = null;

if ($migration_ok) {
    $listes = $pdo->query("SELECT l.*,
                (SELECT COUNT(*) FROM fond_liste_membres m WHERE m.liste_id = l.id) AS effectif,
                (SELECT COUNT(*) FROM fond_liste_membres m
                   JOIN asso_prospects p ON p.id = m.prospect_id
                  WHERE m.liste_id = l.id AND p.status = 'unsubscribed') AS desinscrits
              FROM fond_listes l ORDER BY l.id DESC")->fetchAll(PDO::FETCH_ASSOC);

    if ($vue > 0) {
        $st = $pdo->prepare("SELECT * FROM fond_listes WHERE id = ?");
        $st->execute([$vue]);
        $detail = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($detail) {
            // 300 lignes : de quoi vérifier un import, pas de quoi
            // parcourir 20 000 adresses dans un navigateur.
            $st = $pdo->prepare("SELECT p.* FROM fond_liste_membres m
                                 JOIN asso_prospects p ON p.id = m.prospect_id
                                 WHERE m.liste_id = ? ORDER BY m.ajoute_at DESC, p.id DESC LIMIT 300");
            $st->execute([$vue]);
            $membres = $st->fetchAll(PDO::FETCH_ASSOC);
        }
    }
}

sa_render_head('Listes d’adresses');
sa_render_sidebar('fondateur-listes');
?>
<style>
.fl-wrap{max-width:1100px;margin:0 auto}
.fl-head{display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;margin-bottom:6px}
.fl-sub{color:var(--sa-text-2,#94a3b8);font-size:13.5px;margin:0 0 22px;line-height:1.6;max-width:70ch}
.fl-flash{background:var(--sa-bg-2);border:1px solid var(--sa-border);border-left:3px solid #10B981;
  border-radius:10px;padding:12px 16px;margin-bottom:18px;font-size:13.5px}
.fl-card{background:var(--sa-bg-2);border:1px solid var(--sa-border);border-radius:14px;padding:20px;margin-bottom:18px}
.fl-card h2{font-size:15px;margin:0 0 4px}
.fl-card .aide{font-size:12.5px;color:var(--sa-text-2,#94a3b8);margin:0 0 14px;line-height:1.6}
.fl-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:12px}
.fl-in{width:100%;padding:9px 12px;border-radius:9px;border:1px solid var(--sa-border);
  background:var(--sa-bg);color:inherit;font:inherit}
label.fl-lab{display:block;font-size:12px;font-weight:600;margin-bottom:5px}
.fl-table{width:100%;border-collapse:collapse;font-size:13.5px}
.fl-table th{text-align:left;font-size:11.5px;text-transform:uppercase;letter-spacing:.04em;
  color:var(--sa-text-2,#94a3b8);padding:8px 10px;border-bottom:1px solid var(--sa-border)}
.fl-table td{padding:10px;border-bottom:1px solid var(--sa-border)}
.fl-table tr:last-child td{border-bottom:none}
.fl-num{text-align:right;font-variant-numeric:tabular-nums}
.fl-bg{font-size:11px;font-weight:700;padding:2px 8px;border-radius:999px}
.fl-bg.off{background:#7f1d1d;color:#fecaca}
.fl-vide{color:var(--sa-text-2,#94a3b8);font-size:13.5px;padding:18px 0}
.fl-actions{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-top:14px}
.fl-scroll{overflow-x:auto}
</style>

<div class="fl-wrap">
  <div class="fl-head">
    <h1 style="margin:0">Listes d’adresses</h1>
    <a class="sa-btn sa-btn-ghost" href="/fondateur-campagnes">Campagnes →</a>
  </div>
  <p class="fl-sub">
    Une liste regroupe des adresses à qui envoyer une campagne. Les contacts sont
    partagés avec la prospection : une même adresse reste un seul contact, et un
    désabonnement vaut pour tous les envois.
  </p>

  <?php if ($flash): ?><div class="fl-flash"><?= h($flash) ?></div><?php endif; ?>

  <?php if (!$migration_ok): ?>
    <div class="fl-card">
      <h2>Migration à passer</h2>
      <p class="aide">Les tables des campagnes n’existent pas encore. En SSH :</p>
      <pre style="background:var(--sa-bg);padding:12px;border-radius:8px;overflow:auto;font-size:12.5px"><code>cd ~/public_html
php migrations/run.php 2026-09-18-fondateur-campagnes.sql</code></pre>
    </div>
  <?php else: ?>

  <div class="fl-card">
    <h2>Nouvelle liste</h2>
    <p class="aide">Par exemple « Associations sportives 91 » ou « Mairies Île-de-France ».</p>
    <form method="post" action="/fondateur-listes">
      <input type="hidden" name="action" value="creer">
      <input type="hidden" name="csrf_token" value="<?= h($CSRF) ?>">
      <div class="fl-grid">
        <div><label class="fl-lab">Nom</label><input class="fl-in" name="nom" required maxlength="160"></div>
        <div><label class="fl-lab">Description <span style="font-weight:400">(facultatif)</span></label>
          <input class="fl-in" name="description" maxlength="500"></div>
      </div>
      <div class="fl-actions"><button class="sa-btn" type="submit">Créer la liste</button></div>
    </form>
  </div>

  <div class="fl-card">
    <h2><?= count($listes) ?> liste<?= count($listes) > 1 ? 's' : '' ?></h2>
    <?php if (!$listes): ?>
      <p class="fl-vide">Aucune liste pour l’instant.</p>
    <?php else: ?>
      <div class="fl-scroll">
      <table class="fl-table">
        <tr><th>Liste</th><th class="fl-num">Adresses</th><th class="fl-num">Désinscrits</th><th></th></tr>
        <?php foreach ($listes as $l): ?>
          <tr>
            <td>
              <a href="/fondateur-listes?l=<?= (int) $l['id'] ?>" style="font-weight:600"><?= h($l['nom']) ?></a>
              <?php if (!empty($l['description'])): ?>
                <div style="font-size:12.5px;color:var(--sa-text-2,#94a3b8)"><?= h($l['description']) ?></div>
              <?php endif; ?>
            </td>
            <td class="fl-num"><?= number_format((int) $l['effectif'], 0, ',', ' ') ?></td>
            <td class="fl-num"><?= (int) $l['desinscrits'] ?: '—' ?></td>
            <td style="text-align:right">
              <a class="sa-btn sa-btn-ghost" href="/fondateur-listes?l=<?= (int) $l['id'] ?>">Ouvrir</a>
            </td>
          </tr>
        <?php endforeach; ?>
      </table>
      </div>
    <?php endif; ?>
  </div>

  <?php if ($detail): ?>
    <div class="fl-card">
      <h2><?= h($detail['nom']) ?> — verser un fichier d’adresses</h2>
      <p class="aide">
        Fichier <strong>.xlsx</strong> ou <strong>.csv</strong>. La première ligne doit porter les
        intitulés de colonnes — leur ordre n’a pas d’importance.<br>
        Reconnus : <code>Email</code> (obligatoire), <code>Nom</code>, <code>Association</code>,
        <code>Ville</code>, <code>Département</code>.<br>
        Une adresse déjà connue n’est pas dupliquée : elle est simplement rattachée à cette liste.
      </p>
      <form method="post" action="/fondateur-listes" enctype="multipart/form-data">
        <input type="hidden" name="action" value="importer">
        <input type="hidden" name="liste_id" value="<?= (int) $detail['id'] ?>">
        <input type="hidden" name="csrf_token" value="<?= h($CSRF) ?>">
        <input class="fl-in" type="file" name="fichier" accept=".xlsx,.xlsm,.csv,.txt" required>
        <div class="fl-actions">
          <button class="sa-btn" type="submit">Importer dans cette liste</button>
        </div>
      </form>
      <?php // Formulaire séparé, et non imbriqué dans celui du dessus :
            // HTML n'admet pas les formulaires emboîtés, et le navigateur
            // aurait ignoré l'un des deux — en silence. ?>
      <form method="post" action="/fondateur-listes" style="margin-top:10px"
            onsubmit="return confirm('Supprimer la liste « <?= h($detail['nom']) ?> » ? Les contacts, eux, sont conservés.')">
        <input type="hidden" name="action" value="supprimer_liste">
        <input type="hidden" name="liste_id" value="<?= (int) $detail['id'] ?>">
        <input type="hidden" name="csrf_token" value="<?= h($CSRF) ?>">
        <button class="sa-btn sa-btn-ghost" type="submit">Supprimer la liste</button>
      </form>
    </div>

    <div class="fl-card">
      <h2><?= count($membres) ?> adresse<?= count($membres) > 1 ? 's' : '' ?> affichée<?= count($membres) > 1 ? 's' : '' ?></h2>
      <?php if (!$membres): ?>
        <p class="fl-vide">Liste vide. Versez un fichier ci-dessus.</p>
      <?php else: ?>
        <div class="fl-scroll">
        <table class="fl-table">
          <tr><th>E-mail</th><th>Nom</th><th>Structure</th><th>Ville</th><th></th></tr>
          <?php foreach ($membres as $m): ?>
            <tr>
              <td><?= h($m['email']) ?>
                <?php if (($m['status'] ?? '') === 'unsubscribed'): ?>
                  <span class="fl-bg off">DÉSINSCRIT</span>
                <?php endif; ?>
              </td>
              <td><?= h($m['name'] ?? '') ?></td>
              <td><?= h($m['org_name'] ?? '') ?></td>
              <td><?= h($m['city'] ?? '') ?></td>
              <td style="text-align:right">
                <form method="post" action="/fondateur-listes" style="display:inline">
                  <input type="hidden" name="action" value="retirer">
                  <input type="hidden" name="liste_id" value="<?= (int) $detail['id'] ?>">
                  <input type="hidden" name="prospect_id" value="<?= (int) $m['id'] ?>">
                  <input type="hidden" name="csrf_token" value="<?= h($CSRF) ?>">
                  <button class="sa-btn sa-btn-ghost" type="submit">Retirer</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </table>
        </div>
        <?php if (count($membres) >= 300): ?>
          <p class="fl-vide">Affichage limité aux 300 dernières adresses ajoutées.</p>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <?php endif; ?>
</div>
<?php sa_render_foot(); ?>
