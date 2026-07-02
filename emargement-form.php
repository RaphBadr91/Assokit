<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes-layout.php';
require_once __DIR__ . '/includes-attendance.php';
require_login();
$user = current_user(); $org_id = (int)$user['org_id'];
if (!in_array($user['role'], ['admin','coordinator'], true)) { http_response_code(403); die('Réservé.'); }

$id = (int)($_GET['id'] ?? 0);
$sess = null;
if ($id > 0) {
    $sess = att_load($pdo, $id, $org_id);
    if (!$sess) { http_response_code(404); die('Session introuvable.'); }
}
$d = $sess ?: ['title'=>'','description'=>'','location'=>'','starts_at'=>date('Y-m-d\TH:i'),'ends_at'=>'','require_signature'=>1,'event_id'=>null,'project_id'=>null];

$projects = [];
try {
    $stmt = $pdo->prepare("SELECT p.id, p.name FROM projects p JOIN folders f ON f.id = p.folder_id WHERE f.org_id = ? AND p.archived_at IS NULL AND f.archived_at IS NULL ORDER BY p.name LIMIT 200");
    $stmt->execute([$org_id]);
    $projects = $stmt->fetchAll();
} catch (Throwable $e) {}

render_head($sess ? 'Modifier session' : 'Nouvelle session');
?>
<?= render_sidebar('emargement') ?>
<main class="main">
  <div class="at-page" style="max-width:720px;">
    <a href="/emargement" class="at-back">← Émargement</a>
    <h1 class="at-pg-title"><?= $sess ? '✏️ Modifier' : '+ Nouvelle' ?> session</h1>

    <form method="POST" action="/action-emargement" class="at-form">
      <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
      <input type="hidden" name="action" value="<?= $sess ? 'update' : 'create' ?>">
      <?php if ($sess): ?><input type="hidden" name="id" value="<?= (int)$sess['id'] ?>"><?php endif; ?>

      <div class="at-fld"><label>Titre *</label><input type="text" name="title" required maxlength="200" value="<?= h($d['title']) ?>" placeholder="Ex : Cours yoga lundi 18h"></div>
      <div class="at-fld"><label>Lieu</label><input type="text" name="location" maxlength="255" value="<?= h($d['location']) ?>" placeholder="Salle, adresse, gymnase..."></div>
      <div class="at-row">
        <div class="at-fld"><label>Début *</label><input type="datetime-local" name="starts_at" required value="<?= $d['starts_at'] ? date('Y-m-d\TH:i', strtotime($d['starts_at'])) : '' ?>"></div>
        <div class="at-fld"><label>Fin (optionnel)</label><input type="datetime-local" name="ends_at" value="<?= $d['ends_at'] ? date('Y-m-d\TH:i', strtotime($d['ends_at'])) : '' ?>"></div>
      </div>
      <div class="at-fld"><label>Description</label><textarea name="description" rows="2" placeholder="Détails (intervenant, contenu, prérequis...)"><?= h($d['description']) ?></textarea></div>
      <div class="at-fld"><label>Projet associé (optionnel)</label>
        <select name="project_id">
          <option value="">— Aucun —</option>
          <?php foreach ($projects as $p): ?><option value="<?= (int)$p['id'] ?>" <?= $d['project_id']==$p['id']?'selected':'' ?>><?= h($p['name']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="at-fld">
        <label style="display:flex;align-items:center;gap:8px;">
          <input type="checkbox" name="require_signature" value="1" <?= $d['require_signature'] ? 'checked' : '' ?> style="width:auto;">
          Demander une signature manuscrite (recommandé)
        </label>
      </div>

      <div class="at-actions">
        <a href="/emargement" class="at-btn-ghost">Annuler</a>
        <button type="submit" class="at-btn-primary"><?= $sess ? 'Enregistrer' : 'Créer la session' ?></button>
      </div>
    </form>
  </div>
</main>
<style>
.at-back { color: #6b7280; text-decoration: none; font-size: 13px; display: inline-block; margin-bottom: 12px; }
.at-back:hover { color: #10B981; }
.at-form { background: #fff; border: 1px solid #e5e7eb; border-radius: 14px; padding: 22px 24px; }
.at-fld { margin-bottom: 14px; }
.at-fld label { display: block; font-size: 12px; font-weight: 600; color: #374151; margin-bottom: 5px; text-transform: uppercase; letter-spacing: 0.04em; }
.at-fld input, .at-fld select, .at-fld textarea { width: 100%; padding: 9px 12px; border: 1px solid #e5e7eb; border-radius: 8px; font-size: 14px; font-family: inherit; box-sizing: border-box; }
.at-fld input:focus, .at-fld select:focus, .at-fld textarea:focus { outline: none; border-color: #10B981; box-shadow: 0 0 0 3px rgba(16,185,129,0.12); }
.at-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 12px; }
.at-btn-primary { padding: 10px 18px; background: #10B981; color: #fff; border: 0; border-radius: 8px; font-size: 13px; font-weight: 600; cursor: pointer; font-family: inherit; }
.at-btn-primary:hover { background: #059669; }
.at-btn-ghost { display: inline-flex; padding: 8px 14px; background: #fff; border: 1px solid #e5e7eb; color: #4b5563; text-decoration: none; border-radius: 8px; font-size: 13px; cursor: pointer; }
.at-btn-ghost:hover { background: #f9fafb; }
.at-actions { display: flex; gap: 8px; justify-content: flex-end; margin-top: 24px; padding-top: 18px; border-top: 1px solid #f3f4f6; }
</style>
<?= render_foot() ?>
