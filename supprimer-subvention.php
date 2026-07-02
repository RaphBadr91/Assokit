<?php
/**
 * ============================================================
 * ASSOKIT — Supprimer un dossier de subvention (HARD DELETE)
 * ============================================================
 * URL : /supprimer-subvention/{id}
 * Admin uniquement.
 * Confirmation GitHub-style : saisir le nom exact du dossier.
 * Garde-fou : bloque si status ∈ granted/reported OU amount_granted > 0
 *   (override via checkbox).
 * Cascade : grant_documents (+ fichiers physiques), grant_steps,
 *   grant_activity_log, grant_reminders_sent.
 * ============================================================
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes-layout.php';

require_login();

$user = current_user();
$org_id = (int)$user['org_id'];
$user_id = (int)$user['id'];

// Admin uniquement
if ($user['role'] !== 'admin') {
    http_response_code(403);
    die('Accès refusé — seul l\'administrateur peut supprimer des dossiers de subvention.');
}

$grant_id = (int)($_GET['id'] ?? 0);
if ($grant_id <= 0) {
    header('Location: /subventions');
    exit;
}

// Charger le dossier (et vérifier appartenance à l'org)
$stmt = $pdo->prepare("SELECT id, name, funder, status, amount_requested, amount_granted, currency, org_id FROM grants WHERE id = ? LIMIT 1");
$stmt->execute([$grant_id]);
$grant = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$grant || (int)$grant['org_id'] !== $org_id) {
    http_response_code(404);
    die('Dossier de subvention introuvable.');
}

// Stats liées
$stmt = $pdo->prepare("SELECT COUNT(*) FROM grant_documents WHERE grant_id = ?");
$stmt->execute([$grant_id]);
$nb_docs = (int)$stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM grant_steps WHERE grant_id = ?");
$stmt->execute([$grant_id]);
$nb_steps = (int)$stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM grant_activity_log WHERE grant_id = ?");
$stmt->execute([$grant_id]);
$nb_logs = (int)$stmt->fetchColumn();

// Garde-fou : status sensible OU montant accordé
$is_sensitive = in_array($grant['status'], ['granted', 'reported'], true)
              || (float)($grant['amount_granted'] ?? 0) > 0;

$error = null;

// Traitement POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && check_csrf($_POST['csrf_token'] ?? '')) {
    $typed = trim($_POST['confirm_name'] ?? '');
    $force = !empty($_POST['force_delete']);

    if ($typed === '') {
        $error = 'Veuillez saisir le nom exact du dossier pour confirmer.';
    } elseif ($typed !== $grant['name']) {
        $error = 'Le nom saisi ne correspond pas. Vérifiez majuscules/espaces/accents.';
    } elseif ($is_sensitive && !$force) {
        $error = 'Ce dossier est sensible (subvention accordée ou rendue). Cochez la case d\'override pour continuer.';
    } else {
        // Récup chemins fichiers AVANT delete BDD
        $stmt = $pdo->prepare("SELECT file_path FROM grant_documents WHERE grant_id = ?");
        $stmt->execute([$grant_id]);
        $file_paths = $stmt->fetchAll(PDO::FETCH_COLUMN);

        // Transaction SQL
        try {
            $pdo->beginTransaction();
            $pdo->prepare("DELETE FROM grant_reminders_sent WHERE grant_id = ?")->execute([$grant_id]);
            $pdo->prepare("DELETE FROM grant_activity_log WHERE grant_id = ?")->execute([$grant_id]);
            $pdo->prepare("DELETE FROM grant_steps WHERE grant_id = ?")->execute([$grant_id]);
            $pdo->prepare("DELETE FROM grant_documents WHERE grant_id = ?")->execute([$grant_id]);
            $pdo->prepare("DELETE FROM grants WHERE id = ? AND org_id = ?")->execute([$grant_id, $org_id]);
            $pdo->commit();

            // Suppression fichiers physiques (best-effort, après commit BDD réussi)
            $base = __DIR__;
            foreach ($file_paths as $fp) {
                $fp_clean = ltrim($fp, '/');
                $abs = $base . '/' . $fp_clean;
                if (is_file($abs) && strpos(realpath($abs), $base) === 0) {
                    @unlink($abs);
                }
            }

            $_SESSION['flash_subventions'] = [
                'type' => 'success',
                'message' => 'Dossier "' . $grant['name'] . '" supprimé définitivement.'
            ];
            header('Location: /subventions');
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error = 'Erreur technique : ' . $e->getMessage();
        }
    }
}

// Map status → label FR
$status_labels = [
    'draft' => 'Brouillon',
    'submitted' => 'Soumis',
    'in_review' => 'En instruction',
    'granted' => '✅ Accordé',
    'rejected' => 'Refusé',
    'reported' => '📄 Rendu',
    'archived' => 'Archivé',
];

render_head('Supprimer dossier de subvention');
render_sidebar('subventions');
?>

<div class="main">

  <nav class="crumbs">
    <a href="/dashboard">Dashboard</a>
    <span class="sep">›</span>
    <a href="/subventions">Subventions</a>
    <span class="sep">›</span>
    <a href="/subvention-detail?id=<?= $grant_id ?>"><?= h($grant['name']) ?></a>
    <span class="sep">›</span>
    <span class="current">Supprimer</span>
  </nav>

  <div class="main-head">
    <div>
      <h1 class="page-title" style="color:#991B1B;">🗑️ Supprimer ce dossier de subvention</h1>
      <div class="page-sub">⚠️ Cette action est <strong>irréversible</strong>.</div>
    </div>
  </div>

  <?php if ($error): ?>
    <div class="alert alert-error" style="background:#FEF2F2; border:1px solid #FCA5A5; border-radius:10px; padding:12px 16px; margin-bottom:16px; max-width:620px; color:#991B1B;">
      <strong>⚠️</strong> <?= h($error) ?>
    </div>
  <?php endif; ?>

  <!-- Panneau info du dossier -->
  <div style="background:var(--bg); border:1px solid var(--border); border-radius:12px; padding:20px; margin-bottom:16px; max-width:620px;">
    <div style="display:flex; align-items:center; gap:14px; margin-bottom:14px;">
      <div style="width:44px; height:44px; background:var(--bg-2); border-radius:10px; display:flex; align-items:center; justify-content:center; font-size:22px;">💰</div>
      <div>
        <div style="font-size:17px; font-weight:500; margin-bottom:2px;"><?= h($grant['name']) ?></div>
        <div style="font-size:12.5px; color:var(--ink-3);">Financeur : <?= h($grant['funder']) ?> · Statut : <?= h($status_labels[$grant['status']] ?? $grant['status']) ?></div>
      </div>
    </div>

    <div style="display:grid; grid-template-columns: repeat(3, 1fr); gap:10px; margin-bottom:4px;">
      <div style="background:var(--bg-2); padding:12px; border-radius:8px;">
        <div style="font-size:10.5px; color:var(--ink-3); text-transform:uppercase; letter-spacing:0.04em;">Documents</div>
        <div style="font-size:20px; font-weight:600; margin-top:3px;"><?= $nb_docs ?></div>
      </div>
      <div style="background:var(--bg-2); padding:12px; border-radius:8px;">
        <div style="font-size:10.5px; color:var(--ink-3); text-transform:uppercase; letter-spacing:0.04em;">Étapes</div>
        <div style="font-size:20px; font-weight:600; margin-top:3px;"><?= $nb_steps ?></div>
      </div>
      <div style="background:var(--bg-2); padding:12px; border-radius:8px;">
        <div style="font-size:10.5px; color:var(--ink-3); text-transform:uppercase; letter-spacing:0.04em;">Activité</div>
        <div style="font-size:20px; font-weight:600; margin-top:3px;"><?= $nb_logs ?></div>
      </div>
    </div>

    <?php if ((float)($grant['amount_granted'] ?? 0) > 0): ?>
      <div style="margin-top:12px; background:#FFFBEB; padding:10px 12px; border-radius:8px; font-size:13px; color:#92400E;">
        💶 Montant accordé : <strong><?= number_format((float)$grant['amount_granted'], 2, ',', ' ') ?> <?= h($grant['currency']) ?></strong>
      </div>
    <?php endif; ?>
  </div>

  <!-- Panneau danger -->
  <div style="background:#FEF2F2; border:1px solid #FCA5A5; border-radius:12px; padding:18px 20px; margin-bottom:16px; max-width:620px;">
    <div style="display:flex; align-items:flex-start; gap:10px;">
      <span style="font-size:18px;">⚠️</span>
      <div>
        <div style="font-size:14px; font-weight:500; color:#991B1B; margin-bottom:6px;">Ce qui sera supprimé DÉFINITIVEMENT</div>
        <ul style="margin:0; padding-left:18px; font-size:13px; color:#7F1D1D; line-height:1.7;">
          <li>Le dossier <strong><?= h($grant['name']) ?></strong></li>
          <?php if ($nb_docs > 0): ?><li><strong><?= $nb_docs ?></strong> document(s) joint(s) <em>(fichiers physiques inclus)</em></li><?php endif; ?>
          <?php if ($nb_steps > 0): ?><li><strong><?= $nb_steps ?></strong> étape(s) du suivi</li><?php endif; ?>
          <?php if ($nb_logs > 0): ?><li><strong><?= $nb_logs ?></strong> ligne(s) d'historique d'activité</li><?php endif; ?>
          <li>Tous les rappels envoyés liés à ce dossier</li>
          <li><strong>Aucune restauration possible</strong> — opération irréversible</li>
        </ul>
      </div>
    </div>
  </div>

  <?php if ($is_sensitive): ?>
    <div style="background:#FFFBEB; border:1px solid #FCD34D; border-radius:12px; padding:14px 18px; margin-bottom:16px; max-width:620px;">
      <div style="font-size:13.5px; color:#78350F;">
        <strong>⚠ Dossier sensible détecté.</strong>
        <?php if (in_array($grant['status'], ['granted', 'reported'], true)): ?>
          Le statut est <strong><?= h($status_labels[$grant['status']]) ?></strong>.
        <?php endif; ?>
        <?php if ((float)($grant['amount_granted'] ?? 0) > 0): ?>
          Un montant a été accordé.
        <?php endif; ?>
        Coche la case ci-dessous pour quand même supprimer.
      </div>
    </div>
  <?php endif; ?>

  <!-- Formulaire de confirmation -->
  <form method="POST" style="background:var(--bg); border:1px solid var(--border); border-radius:12px; padding:20px; max-width:620px;">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">

    <div style="margin-bottom:14px;">
      <label for="confirm_name" style="display:block; font-size:13px; font-weight:500; margin-bottom:8px;">
        Pour confirmer, tape le nom exact du dossier :
      </label>
      <div style="font-size:12.5px; color:var(--ink-3); margin-bottom:8px; font-family:monospace; background:var(--bg-2); padding:7px 10px; border-radius:6px; display:inline-block;">
        <?= h($grant['name']) ?>
      </div>
      <input type="text" id="confirm_name" name="confirm_name" autocomplete="off" required autofocus
             placeholder="Tape le nom exact ci-dessus"
             style="width:100%; padding:11px 13px; background:var(--bg); border:1px solid var(--border-strong); border-radius:9px; font-family:inherit; font-size:14px; color:var(--ink); box-sizing:border-box;">
    </div>

    <?php if ($is_sensitive): ?>
      <label style="display:flex; align-items:flex-start; gap:8px; margin-bottom:14px; padding:10px; background:#FFFBEB; border:1px solid #FCD34D; border-radius:8px; cursor:pointer;">
        <input type="checkbox" name="force_delete" value="1" style="margin-top:2px;">
        <span style="font-size:13px; color:#78350F;">Je comprends que ce dossier est sensible (subvention accordée/rendue) et je veux quand même le supprimer.</span>
      </label>
    <?php endif; ?>

    <div style="display:flex; gap:10px; justify-content:flex-end; margin-top:14px;">
      <a href="/subvention-detail?id=<?= $grant_id ?>" class="btn btn-ghost">Annuler</a>
      <button type="submit" class="btn"
              style="background:#DC2626; color:white; border:none; padding:10px 20px; border-radius:8px; font-weight:500; cursor:pointer;"
              onmouseover="this.style.background='#B91C1C'" onmouseout="this.style.background='#DC2626'">
        🗑️ Supprimer définitivement
      </button>
    </div>
  </form>

</div>

<?php render_foot(); ?>
