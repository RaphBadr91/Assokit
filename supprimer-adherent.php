<?php
/**
 * ============================================================
 * ASSOKIT — Supprimer un adhérent (soft delete)
 * ============================================================
 * URL : /supprimer-adherent/{id}
 *
 * Confirmation forte : saisie du nom complet pour valider.
 * Soft delete : deleted_at = NOW(), deleted_by_user_id = admin
 *
 * Règles :
 *   - Seul un ADMIN de l'asso peut supprimer
 *   - Impossible de se supprimer soi-meme
 *   - Impossible de supprimer le dernier admin de l'asso
 *   - Les projets dont il est referent sont "desassignes"
 * ============================================================
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes-layout.php';

require_login();

$current = current_user();
$org_id = (int)$current['org_id'];
$id = (int)($_GET['id'] ?? 0);

// Seul un admin peut supprimer
if ($current['role'] !== 'admin') {
    http_response_code(403);
    die('Accès refusé — seul un administrateur peut supprimer un adhérent.');
}

if ($id <= 0) {
    header('Location: /adherents');
    exit;
}

// Empecher l'auto-suppression
if ($id === (int)$current['id']) {
    $_SESSION['flash_adherent'] = [
        'type' => 'error',
        'message' => 'Vous ne pouvez pas vous supprimer vous-même.',
    ];
    header('Location: /adherent?id=' . $id);
    exit;
}

// Charger l'adherent (non deja supprime)
$stmt = $pdo->prepare("
    SELECT id, email, first_name, last_name, role
    FROM users
    WHERE id = ? AND org_id = ? AND deleted_at IS NULL
    LIMIT 1
");
$stmt->execute([$id, $org_id]);
$adherent = $stmt->fetch();

if (!$adherent) {
    header('Location: /adherents');
    exit;
}

// Verif : ne pas pouvoir supprimer le dernier admin de l'asso
if ($adherent['role'] === 'admin') {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE org_id = ? AND role = 'admin' AND deleted_at IS NULL");
    $stmt->execute([$org_id]);
    $nb_admins = (int) $stmt->fetchColumn();

    if ($nb_admins <= 1) {
        $_SESSION['flash_adherent'] = [
            'type' => 'error',
            'message' => 'Impossible de supprimer le dernier administrateur de l\'association. Nommez d\'abord un autre administrateur.',
        ];
        header('Location: /adherent?id=' . $id);
        exit;
    }
}

$full_name = $adherent['first_name'] . ' ' . $adherent['last_name'];
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && check_csrf($_POST['csrf_token'] ?? '')) {
    $confirm_name = trim($_POST['confirm_name'] ?? '');

    // Validation : le nom saisi doit etre EXACTEMENT le nom complet
    if (mb_strtolower($confirm_name) !== mb_strtolower($full_name)) {
        $error = 'Le nom saisi ne correspond pas. Merci de taper exactement « ' . $full_name . ' ».';
    } else {
        $pdo->beginTransaction();
        try {
            // 1. Soft delete
            $stmt = $pdo->prepare("
                UPDATE users
                SET deleted_at = NOW(), deleted_by_user_id = ?, is_active = 0
                WHERE id = ? AND org_id = ?
            ");
            $stmt->execute([(int)$current['id'], $id, $org_id]);

            // 2. Desassigner les projets dont il est referent
            $stmt = $pdo->prepare("
                UPDATE projects p
                JOIN folders f ON p.folder_id = f.id
                SET p.referent_id = NULL
                WHERE p.referent_id = ? AND f.org_id = ?
            ");
            $stmt->execute([$id, $org_id]);
            $nb_projects = $stmt->rowCount();

            $pdo->commit();

            $msg = $full_name . ' a été supprimé(e). Vous avez 30 jours pour restaurer ce compte si besoin.';
            if ($nb_projects > 0) {
                $msg .= " ($nb_projects projet" . ($nb_projects > 1 ? 's' : '') . " désassigné" . ($nb_projects > 1 ? 's' : '') . ")";
            }

            $_SESSION['flash_adherents'] = [
                'type' => 'success',
                'message' => $msg,
            ];
            header('Location: /adherents');
            exit;

        } catch (Throwable $e) {
            $pdo->rollBack();
            $error = 'Erreur technique : ' . $e->getMessage();
        }
    }
}

// Compter les projets dont il est referent (pour l'affichage warning)
$stmt = $pdo->prepare("
    SELECT COUNT(*) FROM projects p
    JOIN folders f ON p.folder_id = f.id
    WHERE p.referent_id = ? AND f.org_id = ? AND p.status IN ('active','warning')
");
$stmt->execute([$id, $org_id]);
$nb_active_projects = (int) $stmt->fetchColumn();

render_head('Supprimer ' . $full_name);
render_sidebar('adherents');
?>

<div class="main">

  <nav class="crumbs">
    <a href="/dashboard">Dashboard</a>
    <span class="sep">›</span>
    <a href="/adherents">Adhérents</a>
    <span class="sep">›</span>
    <a href="/adherent?id=<?= (int)$id ?>"><?= h($full_name) ?></a>
    <span class="sep">›</span>
    <span class="current">Supprimer</span>
  </nav>

  <div class="main-head">
    <div>
      <h1 class="page-title" style="color:#991B1B;">🗑️ Supprimer <?= h($full_name) ?></h1>
      <div class="page-sub">Cette action est réversible pendant 30 jours.</div>
    </div>
  </div>

  <?php if ($error): ?>
    <div class="alert alert-error">
      <span>⚠️</span>
      <div><?= h($error) ?></div>
    </div>
  <?php endif; ?>

  <div style="max-width: 640px;">

    <!-- Carte d'avertissement -->
    <div style="background:#FEF2F2; border:1px solid #FECACA; border-radius:12px; padding:20px; margin-bottom:16px;">
      <div style="display:flex; gap:14px; align-items:flex-start;">
        <div style="width:40px; height:40px; background:#FEE2E2; border-radius:50%; display:flex; align-items:center; justify-content:center; flex-shrink:0;">
          <span style="font-size:20px;">⚠️</span>
        </div>
        <div>
          <h2 style="margin:0 0 8px; font-size:16px; font-weight:600; color:#991B1B;">Êtes-vous sûr de vouloir supprimer cet adhérent ?</h2>
          <p style="margin:0 0 10px; font-size:13.5px; color:#7F1D1D; line-height:1.6;">
            <strong><?= h($full_name) ?></strong> (<?= h($adherent['email']) ?>, <?= h(role_label($adherent['role'])) ?>)
            sera <strong>supprimé de votre association</strong>.
          </p>
          <ul style="margin:0; padding-left:20px; font-size:13px; color:#7F1D1D; line-height:1.8;">
            <li>❌ Cet adhérent ne pourra plus se connecter</li>
            <li>🔒 Ses données seront <strong>conservées 30 jours</strong> pour permettre une restauration</li>
            <li>♻️ Vous pourrez restaurer ce compte à tout moment pendant ces 30 jours</li>
            <?php if ($nb_active_projects > 0): ?>
              <li style="color:#DC2626;">⚠️ <strong><?= $nb_active_projects ?> projet<?= $nb_active_projects > 1 ? 's' : '' ?> actif<?= $nb_active_projects > 1 ? 's' : '' ?></strong> dont il est référent seront désassignés (à réaffecter ensuite)</li>
            <?php endif; ?>
          </ul>
        </div>
      </div>
    </div>

    <!-- Formulaire de confirmation forte -->
    <form method="POST" action="/supprimer-adherent/<?= (int)$id ?>"
          style="background:var(--bg); border:1px solid var(--border); border-radius:12px; padding:24px;">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">

      <label for="confirm_name" style="display:block; font-size:13.5px; font-weight:500; margin-bottom:8px; color:var(--ink);">
        Pour confirmer la suppression, tapez le nom complet :
      </label>
      <div style="background:var(--bg-2); border:1px solid var(--border); border-radius:8px; padding:10px 14px; margin-bottom:10px; font-family:monospace; font-size:14px; color:var(--ink-2); user-select:none;">
        <?= h($full_name) ?>
      </div>

      <input type="text" id="confirm_name" name="confirm_name" required autocomplete="off"
             placeholder="Tapez le nom complet ici"
             value="<?= h($_POST['confirm_name'] ?? '') ?>"
             style="width:100%; padding:12px 14px; background:var(--bg); border:2px solid #FECACA; border-radius:8px; font-family:inherit; font-size:14px; color:var(--ink); margin-bottom:18px;">

      <div style="font-size:11.5px; color:var(--ink-3); margin-bottom:20px; line-height:1.5;">
        💡 Cette saisie volontaire permet d'éviter une suppression accidentelle. Le texte doit correspondre exactement (majuscules/minuscules indifférentes).
      </div>

      <div style="display:flex; gap:10px; justify-content:flex-end;">
        <a href="/adherent?id=<?= (int)$id ?>" class="btn btn-ghost">← Annuler</a>
        <button type="submit" class="btn" style="background:#DC2626; color:white; border:none;">
          🗑️ Confirmer la suppression
        </button>
      </div>
    </form>

  </div>

</div>

<?php render_foot(); ?>
