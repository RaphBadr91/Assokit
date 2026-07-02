<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes-layout.php';
require_once __DIR__ . '/includes-grants.php';
require_login();
$user = current_user(); $org_id = (int)$user['org_id'];
$is_admin = ($user['role'] === 'admin'); $is_coord = false;
if (!$is_admin && !$is_coord) { http_response_code(403); die('Accès refusé.'); }

$grant_id = (int)($_GET['id'] ?? 0);
$grant = null; $steps = [];
if ($grant_id > 0) {
    $grant = gr_load($pdo, $grant_id, $org_id);
    if (!$grant) { http_response_code(404); die('Subvention introuvable.'); }
    $steps = gr_load_steps($pdo, $grant_id);
}

$d = $grant ?: ['name'=>'', 'funder'=>'', 'funder_type'=>'autre', 'description'=>'', 'amount_requested'=>'', 'amount_granted'=>'', 'currency'=>'EUR', 'status'=>'draft',
    'deadline_apply'=>'', 'submitted_at'=>'', 'decision_at'=>'', 'deadline_report'=>'', 'reported_at'=>'', 'cerfa_number'=>'', 'reference'=>'', 'platform'=>'', 'platform_url'=>'',
    'contact_name'=>'', 'contact_email'=>'', 'contact_phone'=>'', 'notes'=>'', 'project_id'=>0];
if (empty($steps)) {
    // Étapes par défaut pour une demande standard
    $steps = [
        ['id'=>0, 'title'=>'Identifier le bon dispositif et le calendrier', 'is_completed'=>0],
        ['id'=>0, 'title'=>'Rédiger la note de présentation du projet', 'is_completed'=>0],
        ['id'=>0, 'title'=>'Construire le budget prévisionnel', 'is_completed'=>0],
        ['id'=>0, 'title'=>'Réunir les pièces (CERFA, statuts, RIB, bilan)', 'is_completed'=>0],
        ['id'=>0, 'title'=>'Déposer le dossier', 'is_completed'=>0],
    ];
}

// Liste projets pour rattacher
$projects = [];
try {
    $stmt = $pdo->prepare("SELECT p.id, p.name FROM projects p JOIN folders f ON f.id = p.folder_id WHERE f.org_id = ? AND p.archived_at IS NULL AND f.archived_at IS NULL ORDER BY p.name LIMIT 200");
    $stmt->execute([$org_id]);
    $projects = $stmt->fetchAll();
} catch (Throwable $e) {}

render_head($grant ? 'Modifier subvention' : 'Nouvelle subvention');
?>
<?= render_sidebar('subventions') ?>
<main class="main">
  <div class="gr-page" style="max-width:780px;">
    <a href="/subventions" class="gr-back">← Subventions</a>
    <h1 class="gr-pg-title"><?= $grant ? '✏️ Modifier' : '+ Nouvelle' ?> demande de subvention</h1>

    <form method="POST" action="/action-subvention" class="gr-form">
      <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
      <input type="hidden" name="action" value="<?= $grant ? 'update' : 'create' ?>">
      <?php if ($grant): ?><input type="hidden" name="id" value="<?= (int)$grant['id'] ?>"><?php endif; ?>

      <h2 class="gr-section">📌 Identité</h2>
      <div class="gr-fld"><label>Nom de la demande *</label><input type="text" name="name" required maxlength="150" value="<?= h($d['name']) ?>" placeholder="Ex : FDVA 2026 - Fonctionnement"></div>
      <div class="gr-row">
        <div class="gr-fld"><label>Financeur *</label><input type="text" name="funder" required maxlength="150" value="<?= h($d['funder']) ?>" placeholder="Ex : Préfecture de l'Essonne"></div>
        <div class="gr-fld"><label>Type</label>
          <select name="funder_type">
            <?php foreach (['etat','region','departement','commune','epci','caf','fondation','entreprise','europe','autre'] as $t): ?>
              <option value="<?= h($t) ?>" <?= $d['funder_type']==$t?'selected':'' ?>><?= h(gr_funder_label($t)) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="gr-fld"><label>Projet associé</label>
        <select name="project_id">
          <option value="">— Aucun —</option>
          <?php foreach ($projects as $p): ?><option value="<?= (int)$p['id'] ?>" <?= $d['project_id']==$p['id']?'selected':'' ?>><?= h($p['name']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="gr-fld"><label>Description</label><textarea name="description" rows="3" placeholder="Présentation du projet financé"><?= h($d['description']) ?></textarea></div>

      <h2 class="gr-section">💰 Montants</h2>
      <div class="gr-row">
        <div class="gr-fld"><label>Demandé (€)</label><input type="number" name="amount_requested" min="0" step="0.01" value="<?= h($d['amount_requested']) ?>"></div>
        <div class="gr-fld"><label>Accordé (€)</label><input type="number" name="amount_granted" min="0" step="0.01" value="<?= h($d['amount_granted']) ?>"></div>
        <div class="gr-fld"><label>Devise</label><select name="currency"><option value="EUR" <?= $d['currency']=='EUR'?'selected':'' ?>>EUR</option><option value="CHF" <?= $d['currency']=='CHF'?'selected':'' ?>>CHF</option></select></div>
      </div>

      <h2 class="gr-section">📅 Statut & dates</h2>
      <div class="gr-row">
        <div class="gr-fld"><label>Statut</label>
          <select name="status">
            <?php foreach (['draft','submitted','in_review','granted','rejected','reported'] as $s): $m = gr_status_meta($s); ?>
              <option value="<?= h($s) ?>" <?= $d['status']==$s?'selected':'' ?>><?= $m[3] ?> <?= h($m[0]) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="gr-fld"><label>Deadline dépôt</label><input type="date" name="deadline_apply" value="<?= h($d['deadline_apply']) ?>"></div>
      </div>
      <div class="gr-row">
        <div class="gr-fld"><label>Date de dépôt</label><input type="date" name="submitted_at" value="<?= h($d['submitted_at']) ?>"></div>
        <div class="gr-fld"><label>Date de décision</label><input type="date" name="decision_at" value="<?= h($d['decision_at']) ?>"></div>
      </div>
      <div class="gr-row">
        <div class="gr-fld"><label>Deadline bilan</label><input type="date" name="deadline_report" value="<?= h($d['deadline_report']) ?>"></div>
        <div class="gr-fld"><label>Bilan rendu le</label><input type="date" name="reported_at" value="<?= h($d['reported_at']) ?>"></div>
      </div>

      <h2 class="gr-section">📞 Contact financeur</h2>
      <div class="gr-row">
        <div class="gr-fld"><label>Nom</label><input type="text" name="contact_name" maxlength="150" value="<?= h($d['contact_name']) ?>"></div>
        <div class="gr-fld"><label>Email</label><input type="email" name="contact_email" maxlength="200" value="<?= h($d['contact_email']) ?>"></div>
        <div class="gr-fld"><label>Téléphone</label><input type="text" name="contact_phone" maxlength="40" value="<?= h($d['contact_phone']) ?>"></div>
      </div>
      <div class="gr-row">
        <div class="gr-fld"><label>N° CERFA</label><input type="text" name="cerfa_number" maxlength="40" value="<?= h($d['cerfa_number']) ?>" placeholder="12156*06"></div>
        <div class="gr-fld"><label>Référence dossier</label><input type="text" name="reference" maxlength="100" value="<?= h($d['reference']) ?>"></div>
      </div>

      <h3 class="gr-form-h3" style="margin-top:24px;">📂 Plateforme de dépôt</h3>
      <div class="gr-grid-2">
        <div class="gr-fld">
          <label>Où la demande a-t-elle été déposée ?</label>
          <select name="platform" id="grant-platform-select" onchange="updatePlatformUrl(this)">
            <option value="">— Aucune / Pas encore déposé —</option>
            <option value="dauphin"               data-url="https://agents.cnvfederation.fr"      <?= $d['platform']==='dauphin'?'selected':'' ?>>🏛️ Dauphin (Politique de la Ville · État)</option>
            <option value="subventia"             data-url="https://subventia.interieur.gouv.fr"  <?= $d['platform']==='subventia'?'selected':'' ?>>🛡️ Subventia (FIPD · Ministère Intérieur)</option>
            <option value="elan"                  data-url="https://elan.caf.fr"                  <?= $d['platform']==='elan'?'selected':'' ?>>👨‍👩‍👧 Elan (Parentalité · CAF)</option>
            <option value="lecompteasso"          data-url="https://lecompteasso.associations.gouv.fr" <?= $d['platform']==='lecompteasso'?'selected':'' ?>>🌍 Le Compte Asso (CNDS, FDVA, JEP)</option>
            <option value="demarches_simplifiees" data-url="https://demarches-simplifiees.fr"     <?= $d['platform']==='demarches_simplifiees'?'selected':'' ?>>📋 Démarches Simplifiées (État)</option>
            <option value="ddva"                  data-url="https://www.associations.gouv.fr/le-fonds-pour-le-developpement-de-la-vie-associative-fdva.html" <?= $d['platform']==='ddva'?'selected':'' ?>>🤝 FDVA (Fonds Vie Associative)</option>
            <option value="region"                data-url=""                                     <?= $d['platform']==='region'?'selected':'' ?>>🗺️ Portail Région</option>
            <option value="departement"           data-url=""                                     <?= $d['platform']==='departement'?'selected':'' ?>>🏞️ Portail Département</option>
            <option value="commune"               data-url=""                                     <?= $d['platform']==='commune'?'selected':'' ?>>🏘️ Portail Commune / EPCI</option>
            <option value="google_form"           data-url=""                                     <?= $d['platform']==='google_form'?'selected':'' ?>>📝 Google Form</option>
            <option value="email"                 data-url=""                                     <?= $d['platform']==='email'?'selected':'' ?>>📧 Par email</option>
            <option value="papier"                data-url=""                                     <?= $d['platform']==='papier'?'selected':'' ?>>📄 Dossier papier</option>
            <option value="autre"                 data-url=""                                     <?= $d['platform']==='autre'?'selected':'' ?>>🔗 Autre</option>
          </select>
        </div>
        <div class="gr-fld">
          <label>Lien direct du dossier <span style="color:#9ca3af;font-weight:400;">(optionnel)</span></label>
          <input type="url" name="platform_url" id="grant-platform-url" maxlength="500" value="<?= h($d['platform_url']) ?>" placeholder="https://...">
        </div>
      </div>

      <script>
      function updatePlatformUrl(sel) {
        var input = document.getElementById('grant-platform-url');
        if (!input) return;
        // Ne pré-remplit QUE si le champ est vide (préserve la saisie user)
        if (!input.value.trim()) {
          var url = sel.options[sel.selectedIndex].getAttribute('data-url');
          if (url) input.value = url;
        }
      }
      </script>

      <div class="gr-grid-2" style="display:none;"><div></div>
      </div>

      <h2 class="gr-section">✅ Étapes du dossier</h2>
      <p class="gr-help">Checklist personnalisable. Les étapes pré-remplies sont éditables.</p>
      <div id="gr-steps">
        <?php foreach ($steps as $i => $s): ?>
        <div class="gr-step">
          <input type="hidden" name="step_id[]" value="<?= (int)($s['id'] ?? 0) ?>">
          <input type="hidden" name="step_done[]" value="<?= (int)($s['is_completed'] ?? 0) ?>">
          <input type="text" name="step_title[]" required value="<?= h($s['title']) ?>" placeholder="Étape">
          <button type="button" class="gr-step-rm" onclick="this.closest('.gr-step').remove()">×</button>
        </div>
        <?php endforeach; ?>
      </div>
      <button type="button" id="gr-add-step" class="gr-btn-ghost">+ Ajouter une étape</button>

      <h2 class="gr-section">📝 Notes</h2>
      <div class="gr-fld"><textarea name="notes" rows="3" placeholder="Infos internes, points de vigilance, contacts utiles…"><?= h($d['notes']) ?></textarea></div>

      <div class="gr-actions">
        <a href="/subventions" class="gr-btn-ghost">Annuler</a>
        <button type="submit" class="gr-btn-primary"><?= $grant ? 'Enregistrer' : 'Créer la demande' ?></button>
      </div>
    </form>
  </div>
</main>
<style>
.gr-back { color: #6b7280; text-decoration: none; font-size: 13px; display: inline-block; margin-bottom: 12px; }
.gr-back:hover { color: #10B981; }
.gr-form { background: #fff; border: 1px solid #e5e7eb; border-radius: 14px; padding: 22px 24px; }
.gr-section { font-size: 14px; color: #065F46; margin: 22px 0 8px; padding-bottom: 6px; border-bottom: 1px solid #d1fae5; }
.gr-section:first-of-type { margin-top: 0; }
.gr-help { font-size: 12.5px; color: #6b7280; margin: 0 0 10px; }
.gr-fld { margin-bottom: 14px; }
.gr-fld label { display: block; font-size: 12px; font-weight: 600; color: #374151; margin-bottom: 5px; text-transform: uppercase; letter-spacing: 0.04em; }
.gr-fld input, .gr-fld select, .gr-fld textarea { width: 100%; padding: 9px 12px; border: 1px solid #e5e7eb; border-radius: 8px; font-size: 14px; font-family: inherit; box-sizing: border-box; }
.gr-fld input:focus, .gr-fld select:focus, .gr-fld textarea:focus { outline: none; border-color: #10B981; box-shadow: 0 0 0 3px rgba(16,185,129,0.12); }
.gr-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 12px; }
.gr-step { display: flex; gap: 8px; margin-bottom: 6px; }
.gr-step input[type="text"] { flex: 1; padding: 8px 10px; border: 1px solid #e5e7eb; border-radius: 7px; font-size: 13px; font-family: inherit; }
.gr-step-rm { background: transparent; border: 1px solid #e5e7eb; padding: 0 12px; border-radius: 7px; cursor: pointer; color: #DC2626; font-size: 18px; }
.gr-step-rm:hover { background: #FEF2F2; border-color: #DC2626; }
.gr-btn-ghost { display: inline-flex; padding: 8px 14px; background: #fff; border: 1px solid #e5e7eb; color: #4b5563; text-decoration: none; border-radius: 8px; font-size: 13px; cursor: pointer; font-family: inherit; }
.gr-btn-ghost:hover { background: #f9fafb; }
.gr-actions { display: flex; gap: 8px; justify-content: flex-end; margin-top: 24px; padding-top: 18px; border-top: 1px solid #f3f4f6; }
</style>
<script>
document.getElementById('gr-add-step').addEventListener('click', function() {
  const c = document.getElementById('gr-steps');
  const div = document.createElement('div');
  div.className = 'gr-step';
  div.innerHTML = '<input type="hidden" name="step_id[]" value="0"><input type="hidden" name="step_done[]" value="0"><input type="text" name="step_title[]" required placeholder="Étape"><button type="button" class="gr-step-rm" onclick="this.closest(\'.gr-step\').remove()">×</button>';
  c.appendChild(div);
});
</script>
<?= render_foot() ?>
