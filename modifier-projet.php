<?php
/**
 * ============================================================
 * ASSOKIT — Modification d'un projet
 * ============================================================
 * Accessible à :
 *   - L'administrateur
 *   - Le référent du projet uniquement
 * ============================================================
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes-layout.php';

require_login();
$current = current_user();
$org_id = (int)$current['org_id'];

$project_id = (int)($_GET['id'] ?? 0);
if ($project_id <= 0) {
    header('Location: /projets');
    exit;
}

// Charger le projet
$stmt = $pdo->prepare("
    SELECT p.*, f.org_id, f.name AS folder_name, f.color_theme AS folder_color
    FROM projects p
    JOIN folders f ON p.folder_id = f.id
    WHERE p.id = ?
");
$stmt->execute([$project_id]);
$project = $stmt->fetch();

if (!$project || $project['org_id'] != $org_id) {
    header('Location: /projets?error=notfound');
    exit;
}

// Permissions : admin OU référent
$is_admin = ($current['role'] === 'admin');
$is_referent = ((int)$project['referent_id'] === (int)$current['id']);

if (!$is_admin && !$is_referent) {
    header('Location: /projet/' . $project_id . '?error=permission');
    exit;
}

// [FIX BUG 2] Charger les dossiers de l'org (pour le sélecteur) - exclure archivés
$folders_stmt = $pdo->prepare("SELECT id, name, color_theme FROM folders WHERE org_id = ? AND archived_at IS NULL ORDER BY name");
$folders_stmt->execute([$org_id]);
$folders = $folders_stmt->fetchAll();

// [FIX BUG 3] Charger les utilisateurs qui peuvent être référent - exclure supprimés
$refs_stmt = $pdo->prepare("
    SELECT id, first_name, last_name, role, avatar_color
    FROM users
    WHERE org_id = ?
      AND is_active = 1
      AND (deleted_at IS NULL OR deleted_at = '')
      AND role IN ('admin', 'coordinator', 'referent')
    ORDER BY first_name
");
$refs_stmt->execute([$org_id]);
$potential_referents = $refs_stmt->fetchAll();

// [NEW] Charger TOUS les membres actifs de l'asso (pour l'équipe projet)
$mems_stmt = $pdo->prepare("
    SELECT id, first_name, last_name, role
    FROM users
    WHERE org_id = ?
      AND is_active = 1
      AND (deleted_at IS NULL OR deleted_at = '')
    ORDER BY first_name ASC, last_name ASC
");
$mems_stmt->execute([$org_id]);
$potential_members = $mems_stmt->fetchAll();

// [NEW] Charger l'équipe actuelle du projet
$team_stmt = $pdo->prepare("
    SELECT user_id FROM project_members WHERE project_id = ?
");
$team_stmt->execute([$project_id]);
$current_team_ids = array_map('intval', array_column($team_stmt->fetchAll(), 'user_id'));

// Récupération erreurs/flash
$errors = $_SESSION['form_errors'] ?? [];
unset($_SESSION['form_errors']);

render_head('Modifier : ' . $project['name']);
render_sidebar('projets');

$avatar_colors = [
    'blue' => '#4F80BD', 'purple' => '#7F77DD', 'amber' => '#EF9F27',
    'pink' => '#D77CA0', 'teal' => '#2AAE89', 'green' => '#059669',
    'red' => '#B91C1C', 'gray' => '#78716C'
];
?>

<main class="main">

  <nav class="crumbs">
    <a href="/dashboard">Dashboard</a>
    <span class="sep">›</span>
    <a href="/projets">Projets</a>
    <span class="sep">›</span>
    <a href="/projet/<?= $project_id ?>"><?= h($project['name']) ?></a>
    <span class="sep">›</span>
    <span class="current">Modifier</span>
  </nav>

  <div class="main-head">
    <div>
      <h1 class="page-title">Modifier le projet</h1>
      <div class="page-sub">
        <?= h($project['folder_name']) ?> · <?= h($project['name']) ?>
        <?php if ($is_referent && !$is_admin): ?>
          · <span style="color: var(--acc); font-weight: 500;">Vous êtes le référent de ce projet</span>
        <?php endif; ?>
      </div>
    </div>
    <div class="head-actions">
      <a href="/projet/<?= $project_id ?>" class="btn btn-ghost">Annuler</a>
    </div>
  </div>

  <?php if ($errors): ?>
    <div class="alert alert-error">
      <strong>Corrigez les erreurs suivantes :</strong>
      <?php foreach ($errors as $err): ?>
        <div>• <?= h($err) ?></div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <form method="POST" action="/action-projet" novalidate>
    <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
    <input type="hidden" name="action" value="update">
    <input type="hidden" name="project_id" value="<?= (int)$project_id ?>">

    <!-- L'essentiel -->
    <div class="form-section">
      <h2 class="form-section-title">📋 L'essentiel</h2>

      <div class="form-row">
        <label class="form-label">Nom du projet <span class="required">*</span></label>
        <input type="text" name="name" class="form-input-lg" required maxlength="200" value="<?= h($project['name']) ?>">
      </div>

      <div class="form-row">
        <label class="form-label">Description</label>
        <textarea name="description" class="form-textarea-lg" rows="4" maxlength="5000"><?= h($project['description']) ?></textarea>
        <div class="form-hint">Décrivez les objectifs, le public visé, les résultats attendus…</div>
      </div>

      <div class="form-cols">
        <div class="form-row">
          <label class="form-label">Dossier</label>
          <select name="folder_id" class="form-select-lg">
            <?php foreach ($folders as $f): ?>
              <option value="<?= (int)$f['id'] ?>" <?= $f['id'] == $project['folder_id'] ? 'selected' : '' ?>>
                <?= h($f['name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-row">
          <label class="form-label">Lieu / Localisation</label>
          <input type="text" name="location" class="form-input-lg" maxlength="200" value="<?= h($project['location']) ?>" placeholder="Ex: Lycée Mendès France, Ris-Orangis">
        </div>
      </div>
    </div>

    <!-- Statut & Progression -->
    <div class="form-section">
      <h2 class="form-section-title">📊 Statut & progression</h2>

      <div class="form-cols">
        <div class="form-row">
          <label class="form-label">Statut</label>
          <select name="status" class="form-select-lg">
            <option value="draft" <?= $project['status'] === 'draft' ? 'selected' : '' ?>>📝 Brouillon</option>
            <option value="active" <?= $project['status'] === 'active' ? 'selected' : '' ?>>✅ En cours</option>
            <option value="warning" <?= $project['status'] === 'warning' ? 'selected' : '' ?>>⚠️ À surveiller</option>
            <option value="done" <?= $project['status'] === 'done' ? 'selected' : '' ?>>🎉 Terminé</option>
            <option value="archived" <?= $project['status'] === 'archived' ? 'selected' : '' ?>>📦 Archivé</option>
          </select>
          <div class="form-hint">⚠️ "À surveiller" marque le projet comme prioritaire dans les listes.</div>
        </div>

        <div class="form-row">
          <label class="form-label">Progression (%)</label>
          <div style="display: flex; gap: 10px; align-items: center;">
            <input type="range" name="progress_percent" id="progress_slider" min="0" max="100" value="<?= (int)$project['progress_percent'] ?>" style="flex: 1;" oninput="document.getElementById('progress_val').textContent = this.value + '%';">
            <span id="progress_val" style="min-width: 50px; font-weight: 500; font-variant-numeric: tabular-nums;"><?= (int)$project['progress_percent'] ?>%</span>
          </div>
        </div>
      </div>

      <div class="form-cols">
        <div class="form-row">
          <label class="form-label">Date de début</label>
          <input type="date" name="start_date" class="form-input-lg" value="<?= h($project['start_date']) ?>">
        </div>
        <div class="form-row">
          <label class="form-label">Date de fin prévue</label>
          <input type="date" name="end_date" class="form-input-lg" value="<?= h($project['end_date']) ?>">
        </div>
      </div>
    </div>

    <!-- Équipe & référent -->
    <div class="form-section">
      <h2 class="form-section-title">👥 Équipe & référent</h2>

      <div class="form-row">
        <label class="form-label">Référent du projet</label>
        <select name="referent_id" class="form-select-lg">
          <option value="">— Aucun référent —</option>
          <?php foreach ($potential_referents as $r): ?>
            <option value="<?= (int)$r['id'] ?>" <?= $r['id'] == $project['referent_id'] ? 'selected' : '' ?>>
              <?= h($r['first_name'] . ' ' . $r['last_name']) ?> · <?= h(role_label($r['role'])) ?>
            </option>
          <?php endforeach; ?>
        </select>
        <div class="form-hint">⚠️ Si tu te retires comme référent et que tu n'es pas admin, tu perdras l'accès à la modification de ce projet.</div>
      </div>

      <div class="form-row">
        <label class="form-label">Nombre de participants</label>
        <input type="number" name="participants_count" class="form-input-lg" min="0" value="<?= (int)$project['participants_count'] ?>">
        <div class="form-hint">Public touché par le projet (estimation).</div>
      </div>
    </div>

    <!-- Budget -->
    <div class="form-section">
      <h2 class="form-section-title">💰 Budget</h2>

      <div class="form-cols">
        <div class="form-row">
          <label class="form-label">Budget prévu (€)</label>
          <input type="text" name="budget_planned" class="form-input-lg" inputmode="decimal" value="<?= h(number_format((float)$project['budget_planned'], 2, ',', ' ')) ?>">
          <div class="form-hint">Total demandé aux financeurs + autofinancement.</div>
        </div>
        <div class="form-row">
          <label class="form-label">Budget déjà utilisé (€)</label>
          <input type="text" name="budget_used" class="form-input-lg" inputmode="decimal" value="<?= h(number_format((float)$project['budget_used'], 2, ',', ' ')) ?>">
          <div class="form-hint">Mis à jour automatiquement par les factures validées (en théorie).</div>
        </div>
      </div>
    </div>

    <!-- Actions -->
    <div class="form-actions">
      <div class="form-actions-right">
        <a href="/projet/<?= $project_id ?>" class="btn btn-ghost">Annuler</a>
        <button type="submit" class="btn btn-primary">Enregistrer les modifications</button>
      </div>
    </div>
  </form>

  <!-- ============================================================
       BLOC ÉQUIPE (formulaire séparé pour gérer les membres)
       ============================================================ -->
  <div class="form-section" style="margin-top: 24px;">
    <h2 class="form-section-title">👥 Membres de l'équipe</h2>
    <p class="form-section-desc">Ajoutez ou retirez les membres de l'équipe du projet (en plus du référent).</p>

    <form method="POST" action="/action-equipe">
      <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
      <input type="hidden" name="project_id" value="<?= (int)$project_id ?>">

      <div style="display:grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap:8px; max-height:320px; overflow-y:auto; padding:12px; background:var(--bg-2); border:1px solid var(--border); border-radius:10px; margin-bottom:14px;">
        <?php foreach ($potential_members as $m):
            $is_in_team = in_array((int)$m['id'], $current_team_ids, true);
            $is_referent = ((int)$m['id'] === (int)$project['referent_id']);
            $role_label_str = [
                'admin' => '🛡️ Admin',
                'coordinator' => '🧭 Coord',
                'referent' => '🎯 Référent',
                'member' => '👤 Membre',
                'follower' => '👀 Suiveur',
            ][$m['role']] ?? $m['role'];
        ?>
          <label style="cursor:pointer; display:flex; align-items:center; gap:10px; padding:8px 10px; background:var(--bg); border:1px solid <?= $is_in_team ? 'var(--acc, #10B981)' : 'var(--border)' ?>; border-radius:8px; transition:border-color 0.15s;">
            <input type="checkbox" name="team_members[]" value="<?= (int)$m['id'] ?>"
                   <?= $is_in_team ? 'checked' : '' ?>
                   <?= $is_referent ? 'disabled checked title="Le référent fait toujours partie de l\'équipe"' : '' ?>
                   style="margin:0; cursor:pointer;">
            <div style="flex:1; min-width:0;">
              <div style="font-size:13px; font-weight:500; color:var(--ink); white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">
                <?= h($m['first_name'] . ' ' . $m['last_name']) ?>
                <?= $is_referent ? ' 🎯' : '' ?>
              </div>
              <div style="font-size:11px; color:var(--ink-3); margin-top:1px;"><?= h($role_label_str) ?></div>
            </div>
          </label>
        <?php endforeach; ?>
      </div>

      <div style="display:flex; gap:10px; justify-content:flex-end;">
        <button type="submit" class="btn btn-primary">💾 Enregistrer l'équipe</button>
      </div>
    </form>
  </div>

  <!-- Actions avancées (admin uniquement) -->
  <?php if ($is_admin): ?>
  <div class="form-section" style="margin-top: 24px;">
    <h2 class="form-section-title">🛠️ Actions avancées</h2>
    <p class="form-section-desc">Fonctions réservées aux administrateurs.</p>

    <div style="display: flex; gap: 12px; flex-wrap: wrap;">
      <form method="POST" action="/action-projet" style="margin: 0;">
        <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
        <input type="hidden" name="action" value="duplicate">
        <input type="hidden" name="project_id" value="<?= (int)$project_id ?>">
        <button type="submit" class="btn btn-ghost">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
          Dupliquer ce projet
        </button>
      </form>

      <?php if ($project['status'] !== 'archived'): ?>
      <form method="POST" action="/action-projet" style="margin: 0;"
            onsubmit="return confirm('Archiver ce projet ? Il sera masqué des listes actives mais les données sont conservées.');">
        <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
        <input type="hidden" name="action" value="archive">
        <input type="hidden" name="project_id" value="<?= (int)$project_id ?>">
        <button type="submit" class="btn btn-ghost" style="color: #92400E;">
          📦 Archiver le projet
        </button>
      </form>
      <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>

</main>

<?php render_foot(); ?>
