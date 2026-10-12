<?php
/**
 * communication-evenement-nouveau.php — Creer un evenement
 * ==========================================================
 * Cree un evenement dans l'asso avec systeme RSVP et lien public.
 * Option "Diffuser" apres creation (bouton dedié sur la fiche).
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes-layout.php';
require_login();
require_capability('access_marketing');

$user = current_user();
$org_id = (int) $user['org_id'];

// Charger projets pour le select
$stmt = $pdo->prepare("
    SELECT p.id, p.name, f.name AS folder_name
    FROM projects p
    JOIN folders f ON p.folder_id = f.id
    WHERE f.org_id = ? AND p.status IN ('active','warning')
    ORDER BY f.name, p.name
");
$stmt->execute([$org_id]);
$projects = $stmt->fetchAll(PDO::FETCH_ASSOC);

$error = null;
$form = [
    'title'         => '',
    'description'   => '',
    'location'      => '',
    'location_address' => '',
    'start_date'    => '',
    'start_time'    => '19:00',
    'end_time'      => '',
    'max_attendees' => '',
    'project_id'    => '',
    'rsvp_enabled'  => '1',
    'is_public'     => '1',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && check_csrf($_POST['csrf_token'] ?? '')) {
    foreach ($form as $k => $v) {
        if (isset($_POST[$k])) $form[$k] = trim((string)$_POST[$k]);
    }
    $form['rsvp_enabled'] = isset($_POST['rsvp_enabled']) ? '1' : '0';
    $form['is_public']    = isset($_POST['is_public']) ? '1' : '0';

    // Projet lié : uniquement un projet de l'association (ceux proposés dans la liste)
    $project_val = null;
    $pid = (int) $form['project_id'];
    if ($pid > 0 && in_array($pid, array_map('intval', array_column($projects, 'id')), true)) {
        $project_val = $pid;
    }
    $time_ok = fn(string $t): bool => (bool) preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', $t);

    if ($form['title'] === '' || strlen($form['title']) < 3) {
        $error = 'Le titre est obligatoire (min 3 caractères).';
    } elseif ($form['start_date'] === '') {
        $error = 'La date de l\'événement est obligatoire.';
    } elseif ($form['project_id'] !== '' && $project_val === null) {
        $error = 'Le projet choisi n’existe pas dans votre association.';
    } elseif (($form['start_time'] !== '' && !$time_ok($form['start_time'])) || ($form['end_time'] !== '' && !$time_ok($form['end_time']))) {
        $error = 'Format d’heure invalide (HH:MM).';
    } else {
        // Construire datetime
        $form['start_time'] = substr($form['start_time'], 0, 5);
        $form['end_time'] = substr($form['end_time'], 0, 5);
        $start_datetime = $form['start_date'] . ' ' . ($form['start_time'] ?: '00:00') . ':00';
        $end_datetime = $form['end_time'] !== '' ? $form['start_date'] . ' ' . $form['end_time'] . ':00' : null;
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $form['start_date']) || !strtotime($start_datetime)) {
            $error = 'Format de date invalide.';
        } elseif ($end_datetime !== null && strtotime($end_datetime) === strtotime($start_datetime)) {
            $error = 'L’heure de fin doit être différente de l’heure de début.';
        } else {
            // Fin avant le début (soirée qui se termine après minuit) : l'événement finit le lendemain
            if ($end_datetime !== null && strtotime($end_datetime) < strtotime($start_datetime)) {
                $end_datetime = date('Y-m-d', strtotime($form['start_date'] . ' +1 day')) . ' ' . $form['end_time'] . ':00';
            }
            try {
                // Generer un slug unique pour l'URL publique
                $slug_base = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $form['title']));
                $slug_base = trim($slug_base, '-');
                $slug_base = mb_substr($slug_base, 0, 50);
                $slug = $slug_base . '-' . bin2hex(random_bytes(3));

                $stmt = $pdo->prepare("
                    INSERT INTO communication_events
                        (org_id, created_by_user_id, project_id, title, description,
                         location, location_address, start_date, end_date,
                         max_attendees, public_slug, is_public, rsvp_enabled, status, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'published', NOW())
                ");
                $stmt->execute([
                    $org_id, (int) $user['id'],
                    $project_val,
                    $form['title'],
                    $form['description'] ?: null,
                    $form['location'] ?: null,
                    $form['location_address'] ?: null,
                    $start_datetime,
                    $end_datetime,
                    $form['max_attendees'] ? (int) $form['max_attendees'] : null,
                    $slug,
                    $form['is_public'],
                    $form['rsvp_enabled'],
                ]);
                $new_id = (int) $pdo->lastInsertId();

                $_SESSION['flash_communication'] = [
                    'type' => 'success',
                    'message' => 'Événement « ' . $form['title'] . ' » créé. Vous pouvez maintenant le diffuser par email.',
                ];
                header('Location: /communication-evenement?id=' . $new_id);
                exit;

            } catch (Throwable $e) {
                error_log('[communication-evenement-nouveau] org ' . $org_id . ' : ' . $e->getMessage());
                $error = 'L’événement n’a pas pu être créé (erreur technique). Réessayez dans quelques minutes.';
            }
        }
    }
}

render_head('Nouvel événement');
render_sidebar('communication');
?>

<div class="main">

  <nav class="crumbs">
    <a href="/dashboard">Dashboard</a>
    <span class="sep">›</span>
    <a href="/communication?tab=evenements">Communication</a>
    <span class="sep">›</span>
    <span class="current">Nouvel événement</span>
  </nav>

  <div class="main-head">
    <div>
      <h1 class="page-title">🎪 Nouvel événement</h1>
      <div class="page-sub">Créez un événement et obtenez un lien public partageable avec RSVP.</div>
    </div>
  </div>

  <?php if ($error): ?>
    <div class="alert alert-error">
      <span>⚠️</span>
      <div><?= h($error) ?></div>
    </div>
  <?php endif; ?>

  <form method="POST" action="/communication-evenement-nouveau"
        style="background:var(--bg); border:1px solid var(--border); border-radius:12px; padding:24px; max-width:760px;">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">

    <div style="margin-bottom:16px;">
      <label for="title" style="display:block; font-size:13px; font-weight:500; margin-bottom:6px;">Titre de l'événement *</label>
      <input type="text" id="title" name="title" required minlength="3" maxlength="200" autofocus
             placeholder="Ex : Assemblée Générale 2026, Concert solidaire, Conférence..."
             value="<?= h($form['title']) ?>"
             style="width:100%; padding:10px 12px; background:var(--bg); border:1px solid var(--border-strong); border-radius:8px; font-family:inherit; font-size:14px; color:var(--ink);">
    </div>

    <div style="margin-bottom:16px;">
      <label for="description" style="display:block; font-size:13px; font-weight:500; margin-bottom:6px;">Description</label>
      <textarea id="description" name="description" rows="5"
                placeholder="Décrivez votre événement, son programme, ce qu'il faut apporter..."
                style="width:100%; padding:10px 12px; background:var(--bg); border:1px solid var(--border-strong); border-radius:8px; font-family:inherit; font-size:14px; color:var(--ink); resize:vertical; line-height:1.5;"><?= h($form['description']) ?></textarea>
    </div>

    <div style="display:grid; grid-template-columns: 2fr 1fr 1fr; gap:14px; margin-bottom:16px;">
      <div>
        <label for="start_date" style="display:block; font-size:13px; font-weight:500; margin-bottom:6px;">Date *</label>
        <input type="date" id="start_date" name="start_date" required
               min="<?= date('Y-m-d') ?>"
               value="<?= h($form['start_date']) ?>"
               style="width:100%; padding:10px 12px; background:var(--bg); border:1px solid var(--border-strong); border-radius:8px; font-family:inherit; font-size:14px; color:var(--ink);">
      </div>
      <div>
        <label for="start_time" style="display:block; font-size:13px; font-weight:500; margin-bottom:6px;">Heure début *</label>
        <input type="time" id="start_time" name="start_time" required
               value="<?= h($form['start_time']) ?>"
               style="width:100%; padding:10px 12px; background:var(--bg); border:1px solid var(--border-strong); border-radius:8px; font-family:inherit; font-size:14px; color:var(--ink);">
      </div>
      <div>
        <label for="end_time" style="display:block; font-size:13px; font-weight:500; margin-bottom:6px;">Heure fin</label>
        <input type="time" id="end_time" name="end_time"
               value="<?= h($form['end_time']) ?>"
               style="width:100%; padding:10px 12px; background:var(--bg); border:1px solid var(--border-strong); border-radius:8px; font-family:inherit; font-size:14px; color:var(--ink);">
        <div id="end_time_hint" style="display:none; font-size:11.5px; color:var(--ink-3); margin-top:4px;">🌙 Se termine le lendemain</div>
      </div>
    </div>

    <div style="display:grid; grid-template-columns: 1fr 1fr; gap:14px; margin-bottom:16px;">
      <div>
        <label for="location" style="display:block; font-size:13px; font-weight:500; margin-bottom:6px;">Lieu</label>
        <input type="text" id="location" name="location" maxlength="200"
               placeholder="Ex : Salle polyvalente, Zoom..."
               value="<?= h($form['location']) ?>"
               style="width:100%; padding:10px 12px; background:var(--bg); border:1px solid var(--border-strong); border-radius:8px; font-family:inherit; font-size:14px; color:var(--ink);">
      </div>
      <div>
        <label for="max_attendees" style="display:block; font-size:13px; font-weight:500; margin-bottom:6px;">Places limitées</label>
        <input type="number" id="max_attendees" name="max_attendees" min="1"
               placeholder="Laisser vide = illimité"
               value="<?= h($form['max_attendees']) ?>"
               style="width:100%; padding:10px 12px; background:var(--bg); border:1px solid var(--border-strong); border-radius:8px; font-family:inherit; font-size:14px; color:var(--ink);">
      </div>
    </div>

    <div style="margin-bottom:16px;">
      <label for="location_address" style="display:block; font-size:13px; font-weight:500; margin-bottom:6px;">Adresse complète</label>
      <input type="text" id="location_address" name="location_address" maxlength="300"
             placeholder="Ex : 12 rue des Lilas, 75020 Paris"
             value="<?= h($form['location_address']) ?>"
             style="width:100%; padding:10px 12px; background:var(--bg); border:1px solid var(--border-strong); border-radius:8px; font-family:inherit; font-size:14px; color:var(--ink);">
    </div>

    <?php if (!empty($projects)): ?>
    <div style="margin-bottom:20px;">
      <label for="project_id" style="display:block; font-size:13px; font-weight:500; margin-bottom:6px;">Projet lié (optionnel)</label>
      <select id="project_id" name="project_id"
              style="width:100%; padding:10px 12px; background:var(--bg); border:1px solid var(--border-strong); border-radius:8px; font-family:inherit; font-size:14px; color:var(--ink);">
        <option value="">— Aucun —</option>
        <?php foreach ($projects as $p): ?>
          <option value="<?= (int)$p['id'] ?>" <?= $form['project_id'] == $p['id'] ? 'selected' : '' ?>>
            <?= h($p['folder_name']) ?> / <?= h($p['name']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <?php endif; ?>

    <div style="margin-bottom:24px; padding:14px; background:var(--bg-2); border:1px solid var(--border); border-radius:10px;">
      <div style="font-size:12px; font-weight:500; margin-bottom:10px; color:var(--ink-2);">Options</div>
      <label style="display:flex; align-items:flex-start; gap:10px; cursor:pointer; margin-bottom:10px;">
        <input type="checkbox" name="rsvp_enabled" value="1" <?= $form['rsvp_enabled'] === '1' ? 'checked' : '' ?> style="margin-top:2px;">
        <div>
          <div style="font-size:13px; font-weight:500;">🎟️ Activer les réponses RSVP</div>
          <div style="font-size:11.5px; color:var(--ink-3); margin-top:2px;">
            Les invités pourront confirmer leur venue (Oui / Non / Peut-être).
          </div>
        </div>
      </label>
      <label style="display:flex; align-items:flex-start; gap:10px; cursor:pointer;">
        <input type="checkbox" name="is_public" value="1" <?= $form['is_public'] === '1' ? 'checked' : '' ?> style="margin-top:2px;">
        <div>
          <div style="font-size:13px; font-weight:500;">🔗 Lien public partageable</div>
          <div style="font-size:11.5px; color:var(--ink-3); margin-top:2px;">
            Accessible à n'importe qui avec l'URL, sans compte Assokit.
          </div>
        </div>
      </label>
    </div>

    <div style="display:flex; gap:10px; justify-content:flex-end;">
      <a href="/communication?tab=evenements" class="btn btn-ghost">Annuler</a>
      <button type="submit" class="btn btn-primary">+ Créer l'événement</button>
    </div>
  </form>

</div>

<script>
// Heure de fin : identique au début → refusée (contrôlé aussi côté serveur) ;
// avant le début → l'événement se termine le lendemain (soirée après minuit)
(function () {
  var s = document.getElementById('start_time'), e = document.getElementById('end_time'), hint = document.getElementById('end_time_hint');
  if (!s || !e) return;
  function sync() {
    var both = e.value && s.value;
    e.setCustomValidity(both && e.value === s.value ? 'L’heure de fin doit être différente de l’heure de début.' : '');
    if (hint) hint.style.display = both && e.value < s.value ? 'block' : 'none';
  }
  s.addEventListener('input', sync); e.addEventListener('input', sync); sync();
})();
</script>

<?php render_foot(); ?>
