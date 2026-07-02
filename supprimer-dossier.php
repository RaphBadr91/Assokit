<?php
/**
 * ============================================================
 * ASSOKIT — Supprimer un dossier (archivage)
 * ============================================================
 * URL : /supprimer-dossier/{id}
 * Admin uniquement.
 * Confirmation GitHub-style : saisir le nom exact du dossier.
 * Si projets actifs, alerte + log special pour le Fondateur.
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
    die('Accès refusé — seul l\'administrateur peut supprimer des dossiers.');
}

$folder_id = (int)($_GET['id'] ?? 0);
if ($folder_id <= 0) {
    header('Location: /projets');
    exit;
}

// Charger le dossier (et vérifier appartenance à l'org)
$stmt = $pdo->prepare("SELECT id, name, color_theme, org_id, archived_at FROM folders WHERE id = ? LIMIT 1");
$stmt->execute([$folder_id]);
$folder = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$folder || (int)$folder['org_id'] !== $org_id) {
    http_response_code(404);
    die('Dossier introuvable.');
}

if (!empty($folder['archived_at'])) {
    $_SESSION['flash_projets'] = ['type' => 'error', 'message' => 'Ce dossier est déjà archivé.'];
    header('Location: /projets');
    exit;
}

// Stats du dossier
$stmt = $pdo->prepare("
    SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN status IN ('active','warning') THEN 1 ELSE 0 END) AS active,
        SUM(CASE WHEN status = 'done' THEN 1 ELSE 0 END) AS done
    FROM projects
    WHERE folder_id = ? AND archived_at IS NULL
");
$stmt->execute([$folder_id]);
$stats = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['total'=>0,'active'=>0,'done'=>0];

$error = null;

// Traitement POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && check_csrf($_POST['csrf_token'] ?? '')) {
    $typed = trim($_POST['confirm_name'] ?? '');

    if ($typed === '') {
        $error = 'Veuillez saisir le nom exact du dossier pour confirmer.';
    } elseif ($typed !== $folder['name']) {
        $error = 'Le nom saisi ne correspond pas. Vérifiez majuscules/espaces.';
    } else {
        $result = archive_folder($pdo, $folder_id, $user_id);
        if ($result['ok']) {
            $msg = "Dossier \"{$folder['name']}\" archivé avec succès.";
            if ($result['nb_projects'] > 0) {
                $msg .= " {$result['nb_projects']} projet(s) archivé(s)";
                if ($result['nb_active'] > 0) {
                    $msg .= ", dont {$result['nb_active']} encore actif(s) au moment de l'archivage";
                }
                $msg .= ".";
            }
            $msg .= " Restauration possible pendant " . ARCHIVE_RETENTION_DAYS . " jours.";

            $_SESSION['flash_projets'] = ['type' => 'success', 'message' => $msg];
            header('Location: /projets');
            exit;
        } else {
            $error = 'Erreur technique : ' . ($result['error'] ?? 'inconnue');
        }
    }
}

render_head('Supprimer dossier');
render_sidebar('projets');
?>

<div class="main">

  <nav class="crumbs">
    <a href="/dashboard">Dashboard</a>
    <span class="sep">›</span>
    <a href="/projets">Projets</a>
    <span class="sep">›</span>
    <span class="current">Supprimer le dossier</span>
  </nav>

  <div class="main-head">
    <div>
      <h1 class="page-title" style="color:#991B1B;">🗑️ Supprimer le dossier</h1>
      <div class="page-sub">Cette action peut être annulée pendant <?= ARCHIVE_RETENTION_DAYS ?> jours.</div>
    </div>
  </div>

  <?php if ($error): ?>
    <div class="alert alert-error">
      <span>⚠️</span>
      <div><?= h($error) ?></div>
    </div>
  <?php endif; ?>

  <!-- Panneau info du dossier -->
  <div style="background:var(--bg); border:1px solid var(--border); border-radius:12px; padding:20px; margin-bottom:16px; max-width:620px;">
    <div style="display:flex; align-items:center; gap:14px; margin-bottom:14px;">
      <div style="width:44px; height:44px; background:var(--bg-2); border-radius:10px; display:flex; align-items:center; justify-content:center; font-size:22px;">📁</div>
      <div>
        <div style="font-size:17px; font-weight:500; margin-bottom:2px;"><?= h($folder['name']) ?></div>
        <div style="font-size:12.5px; color:var(--ink-3);">Dossier de l'association</div>
      </div>
    </div>

    <div style="display:grid; grid-template-columns: repeat(3, 1fr); gap:10px; margin-bottom:4px;">
      <div style="background:var(--bg-2); padding:12px; border-radius:8px;">
        <div style="font-size:10.5px; color:var(--ink-3); text-transform:uppercase; letter-spacing:0.04em;">Total projets</div>
        <div style="font-size:20px; font-weight:600; margin-top:3px;"><?= (int)$stats['total'] ?></div>
      </div>
      <div style="background:<?= $stats['active'] > 0 ? '#FFFBEB' : 'var(--bg-2)' ?>; padding:12px; border-radius:8px;">
        <div style="font-size:10.5px; color:<?= $stats['active'] > 0 ? '#92400E' : 'var(--ink-3)' ?>; text-transform:uppercase; letter-spacing:0.04em;">Actifs</div>
        <div style="font-size:20px; font-weight:600; margin-top:3px; color:<?= $stats['active'] > 0 ? '#92400E' : 'inherit' ?>;"><?= (int)$stats['active'] ?></div>
      </div>
      <div style="background:var(--bg-2); padding:12px; border-radius:8px;">
        <div style="font-size:10.5px; color:var(--ink-3); text-transform:uppercase; letter-spacing:0.04em;">Terminés</div>
        <div style="font-size:20px; font-weight:600; margin-top:3px;"><?= (int)$stats['done'] ?></div>
      </div>
    </div>
  </div>

  <!-- Panneau danger -->
  <div style="background:#FEF2F2; border:1px solid #FCA5A5; border-radius:12px; padding:18px 20px; margin-bottom:16px; max-width:620px;">
    <div style="display:flex; align-items:flex-start; gap:10px; margin-bottom:10px;">
      <span style="font-size:18px;">⚠️</span>
      <div>
        <div style="font-size:14px; font-weight:500; color:#991B1B; margin-bottom:4px;">Ce que cette action va faire</div>
        <ul style="margin:0; padding-left:18px; font-size:13px; color:#7F1D1D; line-height:1.7;">
          <li>Le dossier <strong><?= h($folder['name']) ?></strong> sera archivé</li>
          <?php if ((int)$stats['total'] > 0): ?>
            <li><strong><?= (int)$stats['total'] ?> projet(s)</strong> seront aussi archivés</li>
          <?php endif; ?>
          <?php if ((int)$stats['active'] > 0): ?>
            <li>⚠️ <strong><?= (int)$stats['active'] ?> projet(s) actif(s)</strong> seront également archivés (une trace sera conservée pour le Fondateur)</li>
          <?php endif; ?>
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
        Pour confirmer, tapez le nom exact du dossier :
      </label>
      <div style="font-size:12.5px; color:var(--ink-3); margin-bottom:8px; font-family:monospace; background:var(--bg-2); padding:7px 10px; border-radius:6px; display:inline-block;">
        <?= h($folder['name']) ?>
      </div>
      <input type="text" id="confirm_name" name="confirm_name" autocomplete="off" required autofocus
             placeholder="Tapez le nom exact ci-dessus"
             style="width:100%; padding:11px 13px; background:var(--bg); border:1px solid var(--border-strong); border-radius:9px; font-family:inherit; font-size:14px; color:var(--ink);">
    </div>

    <div style="display:flex; gap:10px; justify-content:flex-end; margin-top:14px;">
      <a href="/projets" class="btn btn-ghost">Annuler</a>
      <button type="submit" class="btn"
              style="background:#DC2626; color:white; border:none; padding:10px 20px; border-radius:8px; font-weight:500; cursor:pointer;"
              onmouseover="this.style.background='#B91C1C'" onmouseout="this.style.background='#DC2626'">
        🗑️ Archiver définitivement ce dossier
      </button>
    </div>
  </form>

</div>

<?php render_foot(); ?>
