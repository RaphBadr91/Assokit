<?php
/**
 * ============================================================
 * ASSOKIT — Archiver un projet individuel
 * ============================================================
 * URL : /supprimer-projet/{id}
 * Admin uniquement.
 * Confirmation GitHub-style : saisir le nom exact du projet.
 * ============================================================
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes-layout.php';
require_once __DIR__ . '/archive-helper.php';

require_login();

$user = current_user();
$org_id = (int)$user['org_id'];
$user_id = (int)$user['id'];

// Admin uniquement
if ($user['role'] !== 'admin') {
    http_response_code(403);
    die('Accès refusé — seul l\'administrateur peut archiver des projets.');
}

$project_id = (int)($_GET['id'] ?? 0);
if ($project_id <= 0) {
    header('Location: /projets');
    exit;
}

// Charger le projet + dossier
$stmt = $pdo->prepare("
    SELECT p.id, p.name, p.status, p.progress_percent, p.archived_at,
           f.name AS folder_name, f.org_id, f.archived_at AS folder_archived
    FROM projects p
    JOIN folders f ON p.folder_id = f.id
    WHERE p.id = ? LIMIT 1
");
$stmt->execute([$project_id]);
$project = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$project || (int)$project['org_id'] !== $org_id) {
    http_response_code(404);
    die('Projet introuvable.');
}

if (!empty($project['archived_at'])) {
    $_SESSION['flash_projets'] = ['type' => 'error', 'message' => 'Ce projet est déjà archivé.'];
    header('Location: /projets');
    exit;
}

if (!empty($project['folder_archived'])) {
    $_SESSION['flash_projets'] = ['type' => 'error', 'message' => 'Le dossier parent est archivé. Impossible d\'archiver ce projet séparément.'];
    header('Location: /projets');
    exit;
}

$error = null;

// Traitement POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && check_csrf($_POST['csrf_token'] ?? '')) {
    $typed = trim($_POST['confirm_name'] ?? '');

    if ($typed === '') {
        $error = 'Veuillez saisir le nom exact du projet pour confirmer.';
    } elseif ($typed !== $project['name']) {
        $error = 'Le nom saisi ne correspond pas. Vérifiez majuscules/espaces.';
    } else {
        try {
            $pdo->beginTransaction();

            // Archiver le projet (via_folder = 0 car archivage individuel)
            $stmt = $pdo->prepare("
                UPDATE projects
                SET archived_at = NOW(), archived_by_user_id = ?, archived_via_folder = 0
                WHERE id = ?
            ");
            $stmt->execute([$user_id, $project_id]);

            // Log
            $details = [
                'status_at_archive' => $project['status'],
                'progress_at_archive' => (int)$project['progress_percent'],
                'folder_name' => $project['folder_name'],
            ];
            archive_log($pdo, $org_id, 'archive_folder', 'project', $project_id, $project['name'], $user_id, $details);

            $pdo->commit();

            $_SESSION['flash_projets'] = [
                'type' => 'success',
                'message' => 'Projet "' . $project['name'] . '" archivé avec succès. Restauration possible pendant ' . ARCHIVE_RETENTION_DAYS . ' jours dans /archives.',
            ];
            header('Location: /projets');
            exit;
        } catch (Throwable $e) {
            $pdo->rollBack();
            $error = 'Erreur technique : ' . $e->getMessage();
        }
    }
}

$status_label = match($project['status']) {
    'active'   => ['label' => 'Actif', 'color' => '#059669', 'bg' => '#ECFDF5'],
    'warning'  => ['label' => 'À surveiller', 'color' => '#B45309', 'bg' => '#FFFBEB'],
    'done'     => ['label' => 'Terminé', 'color' => '#6B7280', 'bg' => '#F3F4F6'],
    'draft'    => ['label' => 'Brouillon', 'color' => '#6B7280', 'bg' => '#F3F4F6'],
    default    => ['label' => $project['status'], 'color' => '#6B7280', 'bg' => '#F3F4F6'],
};

render_head('Archiver projet');
render_sidebar('projets');
?>

<div class="main">

  <nav class="crumbs">
    <a href="/dashboard">Dashboard</a>
    <span class="sep">›</span>
    <a href="/projets">Projets</a>
    <span class="sep">›</span>
    <a href="/projet/<?= (int)$project['id'] ?>"><?= h($project['name']) ?></a>
    <span class="sep">›</span>
    <span class="current">Archiver</span>
  </nav>

  <div class="main-head">
    <div>
      <h1 class="page-title" style="color:#991B1B;">🗑️ Archiver le projet</h1>
      <div class="page-sub">Cette action peut être annulée pendant <?= ARCHIVE_RETENTION_DAYS ?> jours.</div>
    </div>
  </div>

  <?php if ($error): ?>
    <div class="alert alert-error">
      <span>⚠️</span>
      <div><?= h($error) ?></div>
    </div>
  <?php endif; ?>

  <!-- Panneau info projet -->
  <div style="background:var(--bg); border:1px solid var(--border); border-radius:12px; padding:20px; margin-bottom:16px; max-width:620px;">
    <div style="display:flex; align-items:center; gap:14px; margin-bottom:14px;">
      <div style="width:44px; height:44px; background:var(--bg-2); border-radius:10px; display:flex; align-items:center; justify-content:center; font-size:20px;">📊</div>
      <div style="flex:1;">
        <div style="font-size:17px; font-weight:500; margin-bottom:3px;"><?= h($project['name']) ?></div>
        <div style="font-size:12.5px; color:var(--ink-3);">📁 Dans le dossier « <?= h($project['folder_name']) ?> »</div>
      </div>
      <span style="padding:4px 10px; border-radius:999px; font-size:11px; font-weight:500; background:<?= $status_label['bg'] ?>; color:<?= $status_label['color'] ?>;">
        <?= h($status_label['label']) ?>
      </span>
    </div>

    <div style="background:var(--bg-2); padding:12px; border-radius:8px; display:flex; justify-content:space-between; align-items:center;">
      <div>
        <div style="font-size:10.5px; color:var(--ink-3); text-transform:uppercase; letter-spacing:0.04em;">Avancement</div>
        <div style="font-size:18px; font-weight:600; margin-top:3px;"><?= (int)$project['progress_percent'] ?> %</div>
      </div>
      <div style="flex:1; margin-left:20px;">
        <div style="background:var(--border); height:6px; border-radius:999px; overflow:hidden;">
          <div style="background:var(--acc); height:100%; width:<?= (int)$project['progress_percent'] ?>%;"></div>
        </div>
      </div>
    </div>
  </div>

  <!-- Panneau danger -->
  <div style="background:#FEF2F2; border:1px solid #FCA5A5; border-radius:12px; padding:18px 20px; margin-bottom:16px; max-width:620px;">
    <div style="display:flex; align-items:flex-start; gap:10px;">
      <span style="font-size:18px;">⚠️</span>
      <div>
        <div style="font-size:14px; font-weight:500; color:#991B1B; margin-bottom:6px;">Ce que cette action va faire</div>
        <ul style="margin:0; padding-left:18px; font-size:13px; color:#7F1D1D; line-height:1.7;">
          <li>Le projet <strong><?= h($project['name']) ?></strong> sera archivé</li>
          <?php if ($project['status'] === 'active' || $project['status'] === 'warning'): ?>
            <li>⚠️ Ce projet est <strong>actif</strong> — une trace sera conservée dans l'historique</li>
          <?php endif; ?>
          <li>Le dossier <strong><?= h($project['folder_name']) ?></strong> reste inchangé</li>
          <li>Restauration possible pendant <strong><?= ARCHIVE_RETENTION_DAYS ?> jours</strong> dans /archives</li>
          <li>Après <?= ARCHIVE_RETENTION_DAYS ?> jours, purge <strong>définitive</strong> (irréversible)</li>
        </ul>
      </div>
    </div>
  </div>

  <!-- Formulaire de confirmation -->
  <form method="POST" style="background:var(--bg); border:1px solid var(--border); border-radius:12px; padding:20px; max-width:620px;">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">

    <div style="margin-bottom:14px;">
      <label for="confirm_name" style="display:block; font-size:13px; font-weight:500; margin-bottom:8px;">
        Pour confirmer, tapez le nom exact du projet :
      </label>
      <div style="font-size:12.5px; color:var(--ink-3); margin-bottom:8px; font-family:monospace; background:var(--bg-2); padding:7px 10px; border-radius:6px; display:inline-block;">
        <?= h($project['name']) ?>
      </div>
      <input type="text" id="confirm_name" name="confirm_name" autocomplete="off" required autofocus
             placeholder="Tapez le nom exact ci-dessus"
             style="width:100%; padding:11px 13px; background:var(--bg); border:1px solid var(--border-strong); border-radius:9px; font-family:inherit; font-size:14px; color:var(--ink);">
    </div>

    <div style="display:flex; gap:10px; justify-content:flex-end; margin-top:14px;">
      <a href="/projet/<?= (int)$project['id'] ?>" class="btn btn-ghost">Annuler</a>
      <button type="submit"
              style="background:#DC2626; color:white; border:none; padding:10px 20px; border-radius:8px; font-weight:500; cursor:pointer; font-family:inherit; font-size:13.5px;"
              onmouseover="this.style.background='#B91C1C'" onmouseout="this.style.background='#DC2626'">
        🗑️ Archiver définitivement ce projet
      </button>
    </div>
  </form>

</div>

<?php render_foot(); ?>
