<?php
/**
 * ============================================================
 * ASSOKIT — Nouvel événement
 * ============================================================
 * Formulaire de création d'un événement.
 * Accessible à tous les membres de l'organisation.
 * ============================================================
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes-layout.php';

require_login();

$user = current_user();
$org_id = (int)$user['org_id'];

// Pré-sélection éventuelle : ?project=X pour créer un événement directement lié à un projet
$preselected_project = (int)($_GET['project'] ?? 0);
$preselected_date = $_GET['date'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $preselected_date)) {
    $preselected_date = date('Y-m-d');
}

// Charger les projets pour le sélecteur
$stmt = $pdo->prepare("
    SELECT p.id, p.name, f.name AS folder_name
    FROM projects p
    JOIN folders f ON p.folder_id = f.id
    WHERE f.org_id = ? AND p.status IN ('active', 'warning', 'draft')
    ORDER BY f.name, p.name
");
$stmt->execute([$org_id]);
$projects = $stmt->fetchAll();

// Valeurs par défaut
$errors = [];
$data = [
    'title' => '',
    'description' => '',
    'location' => '',
    'event_type' => 'meeting',
    'color_theme' => '',
    'project_id' => $preselected_project,
    'start_date' => $preselected_date,
    'start_time' => '14:00',
    'end_date' => $preselected_date,
    'end_time' => '16:00',
    'is_all_day' => 0,
    'visibility' => 'organization',
];

// Traitement du formulaire
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!check_csrf($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Session expirée, rechargez la page.';
    } else {
        foreach ($data as $k => $v) {
            $data[$k] = $_POST[$k] ?? $v;
        }
        $data['is_all_day'] = isset($_POST['is_all_day']) ? 1 : 0;

        // Validations
        if (trim($data['title']) === '') $errors[] = 'Le titre de l\'événement est obligatoire.';
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data['start_date'])) $errors[] = 'Date de début invalide.';
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data['end_date'])) $errors[] = 'Date de fin invalide.';

        // Construction des datetimes
        if ($data['is_all_day']) {
            $starts_at = $data['start_date'] . ' 00:00:00';
            $ends_at = $data['end_date'] . ' 23:59:59';
        } else {
            $starts_at = $data['start_date'] . ' ' . $data['start_time'] . ':00';
            $ends_at = $data['end_date'] . ' ' . $data['end_time'] . ':00';
        }

        if (strtotime($ends_at) < strtotime($starts_at)) {
            $errors[] = 'La date/heure de fin est avant celle de début.';
        }

        // Validation projet
        $project_id = (int)$data['project_id'];
        if ($project_id > 0) {
            $check = $pdo->prepare("
                SELECT p.id FROM projects p
                JOIN folders f ON p.folder_id = f.id
                WHERE p.id = ? AND f.org_id = ?
            ");
            $check->execute([$project_id, $org_id]);
            if (!$check->fetch()) $errors[] = 'Projet invalide.';
        }

        if (!$errors) {
            $valid_types = ['meeting', 'workshop', 'public', 'internal', 'deadline', 'other'];
            $valid_colors = ['blue', 'purple', 'amber', 'pink', 'teal', 'green', 'red'];
            $valid_visibility = ['public', 'organization', 'project_only'];

            $event_type = in_array($data['event_type'], $valid_types, true) ? $data['event_type'] : 'other';
            $color_theme = in_array($data['color_theme'], $valid_colors, true) ? $data['color_theme'] : null;
            $visibility = in_array($data['visibility'], $valid_visibility, true) ? $data['visibility'] : 'organization';

            $stmt = $pdo->prepare("
                INSERT INTO events
                    (org_id, project_id, created_by, title, description, location,
                     event_type, color_theme, starts_at, ends_at, is_all_day, visibility)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $org_id,
                $project_id ?: null,
                $user['id'],
                trim($data['title']),
                trim($data['description']) ?: null,
                trim($data['location']) ?: null,
                $event_type,
                $color_theme,
                $starts_at,
                $ends_at,
                $data['is_all_day'],
                $visibility,
            ]);

            $new_id = (int)$pdo->lastInsertId();

            // Sync automatique vers Google si connecté
            if (file_exists(__DIR__ . '/google-helper.php')) {
                require_once __DIR__ . '/google-helper.php';
                if (is_google_enabled()) {
                    sync_event_to_google($new_id);
                }
            }

            header('Location: /agenda?ym=' . substr($data['start_date'], 0, 7));
            exit;
        }
    }
}

render_head('Nouvel événement');
render_sidebar('agenda');
?>

<main class="main">

  <nav class="crumbs">
    <a href="/dashboard">Dashboard</a>
    <span class="sep">›</span>
    <a href="/agenda">Agenda</a>
    <span class="sep">›</span>
    <span class="current">Nouvel événement</span>
  </nav>

  <div class="main-head">
    <div>
      <h1 class="page-title">Créer un événement</h1>
      <div class="page-sub">Atelier, réunion, AG, deadline…</div>
    </div>
  </div>

  <?php if ($errors): ?>
    <div class="alert alert-error">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/></svg>
      <div>
        <?php foreach ($errors as $err): ?>
          <div><?= h($err) ?></div>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endif; ?>

  <form method="POST" action="/nouveau-evenement" novalidate>
    <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">

    <!-- Essentiel -->
    <div class="form-section">
      <h2 class="form-section-title">L'essentiel</h2>
      <p class="form-section-desc">Titre, date et lieu de votre événement.</p>

      <div class="form-row">
        <label class="form-label" for="title">Titre <span class="required">*</span></label>
        <input type="text" name="title" id="title" class="form-input-lg" required maxlength="200"
               value="<?= h($data['title']) ?>" placeholder="Ex : Réunion mensuelle équipe">
      </div>

      <div class="form-cols">
        <div class="form-row">
          <label class="form-label" for="event_type">Type d'événement</label>
          <select name="event_type" id="event_type" class="form-select-lg">
            <option value="meeting" <?= $data['event_type'] === 'meeting' ? 'selected' : '' ?>>🤝 Réunion</option>
            <option value="workshop" <?= $data['event_type'] === 'workshop' ? 'selected' : '' ?>>🎨 Atelier</option>
            <option value="public" <?= $data['event_type'] === 'public' ? 'selected' : '' ?>>🎉 Événement public</option>
            <option value="internal" <?= $data['event_type'] === 'internal' ? 'selected' : '' ?>>🔒 Interne</option>
            <option value="deadline" <?= $data['event_type'] === 'deadline' ? 'selected' : '' ?>>⏰ Deadline</option>
            <option value="other" <?= $data['event_type'] === 'other' ? 'selected' : '' ?>>📌 Autre</option>
          </select>
        </div>
        <div class="form-row">
          <label class="form-label" for="project_id">Projet lié (optionnel)</label>
          <select name="project_id" id="project_id" class="form-select-lg">
            <option value="">— Aucun projet —</option>
            <?php foreach ($projects as $p): ?>
              <option value="<?= (int)$p['id'] ?>" <?= $data['project_id'] == $p['id'] ? 'selected' : '' ?>>
                <?= h($p['folder_name']) ?> · <?= h($p['name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <div class="form-row">
        <label class="form-label" for="location">Lieu</label>
        <input type="text" name="location" id="location" class="form-input-lg" maxlength="200"
               value="<?= h($data['location']) ?>" placeholder="Ex : Lycée Mendès France, Ris-Orangis">
      </div>
    </div>

    <!-- Dates et heures -->
    <div class="form-section">
      <h2 class="form-section-title">Quand ?</h2>
      <p class="form-section-desc">Dates et horaires. Cochez « journée entière » si les horaires précis n'ont pas d'importance.</p>

      <div class="form-row">
        <label style="display: inline-flex; align-items: center; gap: 8px; cursor: pointer; font-size: 13.5px;">
          <input type="checkbox" name="is_all_day" id="is_all_day" <?= $data['is_all_day'] ? 'checked' : '' ?> onchange="toggleAllDay()">
          Événement sur la journée entière (pas d'horaires)
        </label>
      </div>

      <div class="form-cols">
        <div class="form-row">
          <label class="form-label" for="start_date">Date de début <span class="required">*</span></label>
          <input type="date" name="start_date" id="start_date" class="form-input-lg" required
                 value="<?= h($data['start_date']) ?>">
        </div>
        <div class="form-row time-row">
          <label class="form-label" for="start_time">Heure de début</label>
          <input type="time" name="start_time" id="start_time" class="form-input-lg"
                 value="<?= h($data['start_time']) ?>">
        </div>
      </div>

      <div class="form-cols">
        <div class="form-row">
          <label class="form-label" for="end_date">Date de fin <span class="required">*</span></label>
          <input type="date" name="end_date" id="end_date" class="form-input-lg" required
                 value="<?= h($data['end_date']) ?>">
        </div>
        <div class="form-row time-row">
          <label class="form-label" for="end_time">Heure de fin</label>
          <input type="time" name="end_time" id="end_time" class="form-input-lg"
                 value="<?= h($data['end_time']) ?>">
        </div>
      </div>
    </div>

    <!-- Détails -->
    <div class="form-section">
      <h2 class="form-section-title">Description</h2>
      <p class="form-section-desc">Qu'est-ce qui va se passer ? Utile pour que les adhérents sachent à quoi s'attendre.</p>

      <div class="form-row">
        <label class="form-label" for="description">Détails de l'événement</label>
        <textarea name="description" id="description" class="form-textarea-lg" rows="4"
                  placeholder="Objectifs, déroulé, personnes attendues…"><?= h($data['description']) ?></textarea>
      </div>

      <div class="form-cols">
        <div class="form-row">
          <label class="form-label" for="color_theme">Couleur (optionnel)</label>
          <select name="color_theme" id="color_theme" class="form-select-lg">
            <option value="">— Auto (selon projet) —</option>
            <option value="blue" <?= $data['color_theme'] === 'blue' ? 'selected' : '' ?>>🔵 Bleu</option>
            <option value="purple" <?= $data['color_theme'] === 'purple' ? 'selected' : '' ?>>🟣 Violet</option>
            <option value="amber" <?= $data['color_theme'] === 'amber' ? 'selected' : '' ?>>🟡 Ambre</option>
            <option value="pink" <?= $data['color_theme'] === 'pink' ? 'selected' : '' ?>>🩷 Rose</option>
            <option value="teal" <?= $data['color_theme'] === 'teal' ? 'selected' : '' ?>>🌊 Turquoise</option>
            <option value="green" <?= $data['color_theme'] === 'green' ? 'selected' : '' ?>>🟢 Vert</option>
            <option value="red" <?= $data['color_theme'] === 'red' ? 'selected' : '' ?>>🔴 Rouge</option>
          </select>
        </div>
        <div class="form-row">
          <label class="form-label" for="visibility">Qui peut voir cet événement ?</label>
          <select name="visibility" id="visibility" class="form-select-lg">
            <option value="organization" <?= $data['visibility'] === 'organization' ? 'selected' : '' ?>>👥 Toute l'organisation</option>
            <option value="project_only" <?= $data['visibility'] === 'project_only' ? 'selected' : '' ?>>🔒 Équipe du projet uniquement</option>
            <option value="public" <?= $data['visibility'] === 'public' ? 'selected' : '' ?>>🌍 Public (partageable)</option>
          </select>
        </div>
      </div>
    </div>

    <!-- Actions -->
    <div class="form-actions">
      <div class="form-actions-left"><span class="required">*</span> Champs obligatoires</div>
      <div class="form-actions-right">
        <a href="/agenda" class="btn btn-ghost">Annuler</a>
        <button type="submit" class="btn btn-primary">Créer l'événement</button>
      </div>
    </div>
  </form>

</main>

<script>
function toggleAllDay() {
  var isAllDay = document.getElementById('is_all_day').checked;
  document.querySelectorAll('.time-row').forEach(function (el) {
    el.style.display = isAllDay ? 'none' : '';
  });
}
// Initialiser au chargement
toggleAllDay();

// Auto-remplir la date de fin quand on change la date de début
document.getElementById('start_date').addEventListener('change', function () {
  var endEl = document.getElementById('end_date');
  if (!endEl.value || endEl.value < this.value) {
    endEl.value = this.value;
  }
});

// Auto-remplir l'heure de fin (+2h)
document.getElementById('start_time').addEventListener('change', function () {
  var endEl = document.getElementById('end_time');
  if (!endEl.value || endEl.value <= this.value) {
    var parts = this.value.split(':');
    var h = (parseInt(parts[0], 10) + 2) % 24;
    endEl.value = String(h).padStart(2, '0') + ':' + parts[1];
  }
});
</script>

<?php render_foot(); ?>
