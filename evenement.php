<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes-layout.php';

require_login();
$current = current_user();
$org_id = (int)$current['org_id'];

$event_id = (int)($_GET['id'] ?? 0);
if ($event_id <= 0) { header('Location: /agenda'); exit; }

$stmt = $pdo->prepare("
    SELECT e.*,
           p.name AS project_name, p.id AS project_id_ref, f.name AS folder_name, f.color_theme AS folder_color,
           u.first_name AS author_first, u.last_name AS author_last
    FROM events e
    LEFT JOIN projects p ON e.project_id = p.id
    LEFT JOIN folders f ON p.folder_id = f.id
    LEFT JOIN users u ON e.created_by = u.id
    WHERE e.id = ? AND e.org_id = ? AND e.deleted_at IS NULL
    LIMIT 1
");
$stmt->execute([$event_id, $org_id]);
$event = $stmt->fetch();
if (!$event) { header('Location: /agenda?error=notfound'); exit; }

$can_edit = $current['role'] === 'admin' || $current['role'] === 'coordinator' || $event['created_by'] == $current['id'];
$edit_mode = isset($_GET['edit']) && $can_edit;

$projects = [];
if ($edit_mode) {
    $stmt = $pdo->prepare("
        SELECT p.id, p.name, f.name AS folder_name
        FROM projects p
        JOIN folders f ON p.folder_id = f.id
        WHERE f.org_id = ? AND p.status IN ('active', 'warning', 'draft')
        ORDER BY f.name, p.name
    ");
    $stmt->execute([$org_id]);
    $projects = $stmt->fetchAll();
}

function month_name_fr_full($m) {
    $names = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
    return $names[$m - 1] ?? '';
}
function day_name_fr_full($ts) {
    $days = ['Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi', 'Dimanche'];
    return $days[(int)date('N', $ts) - 1];
}

$start_ts = strtotime($event['starts_at']);
$end_ts = strtotime($event['ends_at']);
$same_day = date('Y-m-d', $start_ts) === date('Y-m-d', $end_ts);

$type_labels = [
    'meeting' => ['label' => 'Réunion', 'icon' => '🤝'],
    'workshop' => ['label' => 'Atelier', 'icon' => '🎨'],
    'public' => ['label' => 'Événement public', 'icon' => '🎉'],
    'internal' => ['label' => 'Interne', 'icon' => '🔒'],
    'deadline' => ['label' => 'Deadline', 'icon' => '⏰'],
    'other' => ['label' => 'Autre', 'icon' => '📌'],
];
$type_info = $type_labels[$event['event_type']] ?? $type_labels['other'];

render_head($event['title']);
render_sidebar('agenda');
?>

<main class="main">

  <nav class="crumbs">
    <a href="/dashboard">Dashboard</a>
    <span class="sep">›</span>
    <a href="/agenda">Agenda</a>
    <span class="sep">›</span>
    <span class="current"><?= h($event['title']) ?></span>
  </nav>

  <?php if (isset($_GET['updated'])): ?>
    <div class="alert alert-success" style="display:inline-flex;align-items:center;gap:8px;"><?= ak_icon('check-circle',16) ?><span>Événement mis à jour<?= !empty($event['google_event_id']) ? ' et synchronisé avec Google Calendar' : '' ?>.</span></div>
  <?php elseif (isset($_GET['error'])):
    $err_labels = ['permission' => 'Droits insuffisants.', 'dates' => 'Date de fin avant date de début.', 'invalid' => 'Données invalides.', 'csrf' => 'Session expirée.'];
    $err_msg = $err_labels[$_GET['error']] ?? $_GET['error'];
  ?>
    <div class="alert alert-error" style="display:inline-flex;align-items:center;gap:8px;"><?= ak_icon('alert-tri',16) ?><span><?= h($err_msg) ?></span></div>
  <?php endif; ?>

  <?php if ($edit_mode): ?>

    <div class="main-head">
      <div>
        <h1 class="page-title">Modifier l'événement</h1>
        <div class="page-sub"><?= h($event['title']) ?></div>
      </div>
      <div class="head-actions">
        <a href="/evenement/<?= $event_id ?>" class="btn btn-ghost">Annuler</a>
      </div>
    </div>

    <form method="POST" action="/action-evenement" novalidate>
      <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
      <input type="hidden" name="action" value="update">
      <input type="hidden" name="event_id" value="<?= $event_id ?>">

      <div class="form-section">
        <h2 class="form-section-title">L'essentiel</h2>
        <div class="form-row">
          <label class="form-label">Titre <span class="required">*</span></label>
          <input type="text" name="title" class="form-input-lg" required maxlength="200" value="<?= h($event['title']) ?>">
        </div>

        <div class="form-cols">
          <div class="form-row">
            <label class="form-label">Type</label>
            <select name="event_type" class="form-select-lg">
              <option value="meeting" <?= $event['event_type'] === 'meeting' ? 'selected' : '' ?>>🤝 Réunion</option>
              <option value="workshop" <?= $event['event_type'] === 'workshop' ? 'selected' : '' ?>>🎨 Atelier</option>
              <option value="public" <?= $event['event_type'] === 'public' ? 'selected' : '' ?>>🎉 Événement public</option>
              <option value="internal" <?= $event['event_type'] === 'internal' ? 'selected' : '' ?>>🔒 Interne</option>
              <option value="deadline" <?= $event['event_type'] === 'deadline' ? 'selected' : '' ?>>⏰ Deadline</option>
              <option value="other" <?= $event['event_type'] === 'other' ? 'selected' : '' ?>>📌 Autre</option>
            </select>
          </div>
          <div class="form-row">
            <label class="form-label">Projet lié</label>
            <select name="project_id" class="form-select-lg">
              <option value="">— Aucun —</option>
              <?php foreach ($projects as $p): ?>
                <option value="<?= (int)$p['id'] ?>" <?= $event['project_id'] == $p['id'] ? 'selected' : '' ?>>
                  <?= h($p['folder_name']) ?> · <?= h($p['name']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <div class="form-row">
          <label class="form-label">Lieu</label>
          <input type="text" name="location" class="form-input-lg" maxlength="200" value="<?= h($event['location']) ?>">
        </div>
      </div>

      <div class="form-section">
        <h2 class="form-section-title">Quand ?</h2>
        <div class="form-row">
          <label style="display: inline-flex; align-items: center; gap: 8px; cursor: pointer; font-size: 13.5px;">
            <input type="checkbox" name="is_all_day" id="is_all_day" <?= $event['is_all_day'] ? 'checked' : '' ?> onchange="toggleAllDay()">
            Journée entière
          </label>
        </div>
        <div class="form-cols">
          <div class="form-row">
            <label class="form-label">Date début <span class="required">*</span></label>
            <input type="date" name="start_date" class="form-input-lg" required value="<?= h(date('Y-m-d', $start_ts)) ?>">
          </div>
          <div class="form-row time-row">
            <label class="form-label">Heure début</label>
            <input type="time" name="start_time" class="form-input-lg" value="<?= h(date('H:i', $start_ts)) ?>">
          </div>
        </div>
        <div class="form-cols">
          <div class="form-row">
            <label class="form-label">Date fin <span class="required">*</span></label>
            <input type="date" name="end_date" class="form-input-lg" required value="<?= h(date('Y-m-d', $end_ts)) ?>">
          </div>
          <div class="form-row time-row">
            <label class="form-label">Heure fin</label>
            <input type="time" name="end_time" class="form-input-lg" value="<?= h(date('H:i', $end_ts)) ?>">
          </div>
        </div>
      </div>

      <div class="form-section">
        <h2 class="form-section-title">Description</h2>
        <div class="form-row">
          <label class="form-label">Détails</label>
          <textarea name="description" class="form-textarea-lg" rows="4"><?= h($event['description']) ?></textarea>
        </div>
        <div class="form-cols">
          <div class="form-row">
            <label class="form-label">Couleur</label>
            <select name="color_theme" class="form-select-lg">
              <option value="">— Auto —</option>
              <option value="blue" <?= $event['color_theme'] === 'blue' ? 'selected' : '' ?>>🔵 Bleu</option>
              <option value="purple" <?= $event['color_theme'] === 'purple' ? 'selected' : '' ?>>🟣 Violet</option>
              <option value="amber" <?= $event['color_theme'] === 'amber' ? 'selected' : '' ?>>🟡 Ambre</option>
              <option value="pink" <?= $event['color_theme'] === 'pink' ? 'selected' : '' ?>>🩷 Rose</option>
              <option value="teal" <?= $event['color_theme'] === 'teal' ? 'selected' : '' ?>>🌊 Turquoise</option>
              <option value="green" <?= $event['color_theme'] === 'green' ? 'selected' : '' ?>>🟢 Vert</option>
              <option value="red" <?= $event['color_theme'] === 'red' ? 'selected' : '' ?>>🔴 Rouge</option>
            </select>
          </div>
          <div class="form-row">
            <label class="form-label">Visibilité</label>
            <select name="visibility" class="form-select-lg">
              <option value="organization" <?= $event['visibility'] === 'organization' ? 'selected' : '' ?>>👥 Organisation</option>
              <option value="project_only" <?= $event['visibility'] === 'project_only' ? 'selected' : '' ?>>🔒 Projet uniquement</option>
              <option value="public" <?= $event['visibility'] === 'public' ? 'selected' : '' ?>>🌍 Public</option>
            </select>
          </div>
        </div>
      </div>

      <div class="form-actions">
        <div class="form-actions-right">
          <a href="/evenement/<?= $event_id ?>" class="btn btn-ghost">Annuler</a>
          <button type="submit" class="btn btn-primary">Enregistrer</button>
        </div>
      </div>
    </form>

    <script>
    function toggleAllDay() {
      var isAllDay = document.getElementById('is_all_day').checked;
      document.querySelectorAll('.time-row').forEach(function(el) { el.style.display = isAllDay ? 'none' : ''; });
    }
    toggleAllDay();
    </script>

  <?php else: ?>

    <div class="main-head">
      <div style="flex: 1;">
        <div style="display: inline-flex; align-items: center; gap: 8px; padding: 4px 10px; background: var(--bg-2); border-radius: 20px; font-size: 11.5px; color: var(--ink-2); margin-bottom: 10px;">
          <span><?= $type_info['icon'] ?></span>
          <span><?= h($type_info['label']) ?></span>
          <?php if (!empty($event['google_event_id'])): ?>
            <span style="color: var(--ink-4);">·</span>
            <span style="color: #4285F4; font-weight: 500;">Sync Google</span>
          <?php endif; ?>
        </div>
        <h1 class="page-title"><?= h($event['title']) ?></h1>
      </div>
      <div class="head-actions">
        <a href="/agenda-ics?event=<?= $event_id ?>" class="btn btn-ghost">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
          Télécharger .ics
        </a>
        <?php if ($can_edit): ?>
          <a href="/evenement/<?= $event_id ?>?edit=1" class="btn btn-primary">Modifier</a>
        <?php endif; ?>
      </div>
    </div>

    <div class="form-section">
      <div style="display: grid; grid-template-columns: auto 1fr; gap: 14px 20px; align-items: start;">

        <div style="font-size: 12.5px; color: var(--ink-3); font-weight: 500; display:inline-flex; align-items:center; gap:6px;"><?= ak_icon('calendar',14) ?> Quand</div>
        <div>
          <?php if ($event['is_all_day']): ?>
            <?php if ($same_day): ?>
              <div style="font-size: 15px;"><?= h(day_name_fr_full($start_ts)) ?> <?= (int)date('j', $start_ts) ?> <?= h(month_name_fr_full((int)date('n', $start_ts))) ?> <?= date('Y', $start_ts) ?></div>
              <div style="font-size: 12.5px; color: var(--ink-3); margin-top: 2px;">Journée entière</div>
            <?php else: ?>
              <div style="font-size: 15px;">Du <?= (int)date('j', $start_ts) ?> <?= h(month_name_fr_full((int)date('n', $start_ts))) ?> au <?= (int)date('j', $end_ts) ?> <?= h(month_name_fr_full((int)date('n', $end_ts))) ?> <?= date('Y', $end_ts) ?></div>
            <?php endif; ?>
          <?php else: ?>
            <?php if ($same_day): ?>
              <div style="font-size: 15px;"><?= h(day_name_fr_full($start_ts)) ?> <?= (int)date('j', $start_ts) ?> <?= h(month_name_fr_full((int)date('n', $start_ts))) ?> <?= date('Y', $start_ts) ?></div>
              <div style="font-size: 13px; color: var(--ink-2); margin-top: 2px;">De <?= h(date('H:i', $start_ts)) ?> à <?= h(date('H:i', $end_ts)) ?></div>
            <?php else: ?>
              <div style="font-size: 15px;">Du <?= h(date('d/m/Y H:i', $start_ts)) ?> au <?= h(date('d/m/Y H:i', $end_ts)) ?></div>
            <?php endif; ?>
          <?php endif; ?>
        </div>

        <?php if (!empty($event['location'])): ?>
        <div style="font-size: 12.5px; color: var(--ink-3); font-weight: 500; display:inline-flex; align-items:center; gap:6px;"><?= ak_icon('pin',14) ?> Où</div>
        <div style="font-size: 14px;"><?= h($event['location']) ?></div>
        <?php endif; ?>

        <?php if (!empty($event['project_name'])): ?>
        <div style="font-size: 12.5px; color: var(--ink-3); font-weight: 500; display:inline-flex; align-items:center; gap:6px;"><?= ak_icon('folder',14) ?> Projet</div>
        <div>
          <a href="/projet/<?= (int)$event['project_id_ref'] ?>" style="color: var(--acc); font-weight: 500; text-decoration: none; font-size: 14px;">
            <?= h($event['folder_name']) ?> · <?= h($event['project_name']) ?>
          </a>
        </div>
        <?php endif; ?>

        <div style="font-size: 12.5px; color: var(--ink-3); font-weight: 500; display:inline-flex; align-items:center; gap:6px;"><?= ak_icon('eye',14) ?> Visibilité</div>
        <div style="font-size: 13px; color: var(--ink-2);">
          <?php
          $vis_labels = ['organization' => '👥 Toute l\'organisation', 'project_only' => '🔒 Équipe du projet uniquement', 'public' => '🌍 Public'];
          echo h($vis_labels[$event['visibility']] ?? $event['visibility']);
          ?>
        </div>

        <div style="font-size: 12.5px; color: var(--ink-3); font-weight: 500;">✍️ Créé par</div>
        <div style="font-size: 13px; color: var(--ink-2);">
          <?= h(trim(($event['author_first'] ?? '') . ' ' . ($event['author_last'] ?? ''))) ?>
          <span style="color: var(--ink-4);">· le <?= h(date('d/m/Y', strtotime($event['created_at']))) ?></span>
        </div>

      </div>
    </div>

    <?php if (!empty($event['description'])): ?>
    <div class="form-section">
      <h2 class="form-section-title" style="display:inline-flex; align-items:center; gap:8px;"><?= ak_icon('edit',18) ?> Description</h2>
      <div style="font-size: 14px; color: var(--ink-2); line-height: 1.7; word-break: break-word;"><?= ak_render_rich_text($event['description']) ?></div>
    </div>
    <?php endif; ?>

    <?php if (!empty($event['google_event_id'])): ?>
    <div style="margin-top: 20px; padding: 14px 18px; background: rgba(66,133,244,0.06); border: 1px solid rgba(66,133,244,0.2); border-radius: 10px;">
      <div style="display: flex; gap: 12px; align-items: center;">
        <span style="display:inline-flex; color: var(--ink-2);"><?= ak_icon('calendar',18) ?></span>
        <div style="font-size: 12.5px; color: var(--ink-2); line-height: 1.5;">
          <strong>Synchronisé avec Google Calendar</strong><br>
          <?php if (!empty($event['synced_at'])): ?>Dernière sync : <?= h(date('d/m/Y H:i', strtotime($event['synced_at']))) ?><?php endif; ?>
          <?php if ($event['sync_origin'] === 'google'): ?><span style="color: var(--ink-4);"> · Créé depuis Google</span><?php endif; ?>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <?php if ($can_edit): ?>
    <div style="margin-top: 30px; padding-top: 20px; border-top: 1px solid var(--border);">
      <form method="POST" action="/action-evenement" style="display: inline-block; margin: 0;"
            onsubmit="return confirm('Supprimer définitivement cet événement ? Cette action est irréversible<?= !empty($event['google_event_id']) ? ' et sera propagée à Google Calendar' : '' ?>.');">
        <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="event_id" value="<?= $event_id ?>">
        <button type="submit" class="btn btn-ghost" style="color: #B91C1C;">Supprimer l'événement</button>
      </form>
    </div>
    <?php endif; ?>

  <?php endif; ?>

</main>

<?php render_foot(); ?>
