<?php
/**
 * ============================================================
 * ASSOKIT — Corbeille des adhérents (soft-deleted, <30 jours)
 * ============================================================
 * URL : /adherents-corbeille
 *
 * Affiche les adhérents supprimés ces 30 derniers jours.
 * Action : restaurer (remettre deleted_at = NULL).
 * Admin uniquement.
 * ============================================================
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes-layout.php';

require_login();

$user = current_user();
$org_id = (int)$user['org_id'];

// Admin uniquement
if ($user['role'] !== 'admin') {
    http_response_code(403);
    die('Accès refusé — réservé aux administrateurs.');
}

$flash = $_SESSION['flash_corbeille'] ?? null;
unset($_SESSION['flash_corbeille']);

// Traitement restauration
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'restore' && check_csrf($_POST['csrf_token'] ?? '')) {
    $restore_id = (int)($_POST['id'] ?? 0);

    // Verifier que cet adherent est bien dans la corbeille et dans notre asso
    $stmt = $pdo->prepare("
        SELECT id, first_name, last_name, email
        FROM users
        WHERE id = ? AND org_id = ? AND deleted_at IS NOT NULL
    ");
    $stmt->execute([$restore_id, $org_id]);
    $to_restore = $stmt->fetch();

    if ($to_restore) {
        // Verifier que l'email n'est pas pris par un autre user
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ? AND deleted_at IS NULL LIMIT 1");
        $stmt->execute([$to_restore['email'], $restore_id]);
        if ($stmt->fetch()) {
            $flash = ['type' => 'error', 'message' => 'Impossible de restaurer : un autre utilisateur a déjà cet email.'];
        } else {
            $stmt = $pdo->prepare("
                UPDATE users
                SET deleted_at = NULL, deleted_by_user_id = NULL, is_active = 1
                WHERE id = ? AND org_id = ?
            ");
            $stmt->execute([$restore_id, $org_id]);

            $_SESSION['flash_adherents'] = [
                'type' => 'success',
                'message' => $to_restore['first_name'] . ' ' . $to_restore['last_name'] . ' a été restauré(e).',
            ];
            header('Location: /adherents');
            exit;
        }
    }
}

// Charger les adherents supprimes < 30j
$stmt = $pdo->prepare("
    SELECT u.id, u.email, u.first_name, u.last_name, u.role, u.avatar_color,
           u.deleted_at, du.first_name AS del_first, du.last_name AS del_last
    FROM users u
    LEFT JOIN users du ON u.deleted_by_user_id = du.id
    WHERE u.org_id = ? AND u.deleted_at IS NOT NULL
      AND u.deleted_at > DATE_SUB(NOW(), INTERVAL 30 DAY)
    ORDER BY u.deleted_at DESC
");
$stmt->execute([$org_id]);
$deleted = $stmt->fetchAll();

render_head('Corbeille adhérents');
render_sidebar('adherents');
?>

<div class="main">

  <nav class="crumbs">
    <a href="/dashboard">Dashboard</a>
    <span class="sep">›</span>
    <a href="/adherents">Adhérents</a>
    <span class="sep">›</span>
    <span class="current">Corbeille</span>
  </nav>

  <?php if ($flash): ?>
    <div class="alert <?= $flash['type'] === 'success' ? 'alert-success' : 'alert-error' ?>" style="margin-bottom: 18px;">
      <span><?= $flash['type'] === 'success' ? '✅' : '⚠️' ?></span>
      <div><?= h($flash['message']) ?></div>
    </div>
  <?php endif; ?>

  <div class="main-head">
    <div>
      <h1 class="page-title">🗑️ Corbeille</h1>
      <div class="page-sub">Adhérents supprimés ces 30 derniers jours. Vous pouvez les restaurer à tout moment.</div>
    </div>
  </div>

  <?php if (empty($deleted)): ?>
    <div class="empty-state" style="padding:48px 20px;">
      <div style="font-size:48px;opacity:.35;margin-bottom:10px;">🗑️</div>
      <div style="font-size:16px; color:var(--ink); font-weight:500; margin-bottom:6px;">Corbeille vide</div>
      <div style="max-width:420px; margin:0 auto; line-height:1.5; color:var(--ink-3);">
        Aucun adhérent supprimé récemment. Les adhérents supprimés apparaîtront ici pendant 30 jours.
      </div>
    </div>
  <?php else: ?>
    <div style="background:var(--bg); border:1px solid var(--border); border-radius:12px; overflow:hidden;">
      <?php foreach ($deleted as $a):
        $color_class = in_array($a['avatar_color'], ['blue','purple','amber','pink','teal'], true)
            ? 'av-' . $a['avatar_color'] : 'av-blue';
        $deleted_ts = strtotime($a['deleted_at']);
        $days_ago = (int) ((time() - $deleted_ts) / 86400);
        $days_left = 30 - $days_ago;
      ?>
      <div style="display:grid; grid-template-columns: auto 1fr auto auto; gap:14px; padding:14px 18px; border-bottom:1px solid var(--border); align-items:center;">
        <span class="adh-avatar <?= $color_class ?>" style="opacity:0.6;"><?= h(user_initials($a['first_name'], $a['last_name'])) ?></span>
        <div style="min-width:0;">
          <div style="font-size:14px; font-weight:500; text-decoration:line-through; color:var(--ink-3); margin-bottom:3px;">
            <?= h($a['first_name'] . ' ' . $a['last_name']) ?>
          </div>
          <div style="font-size:12px; color:var(--ink-4);">
            <?= h($a['email']) ?>
            · Supprimé il y a <?= $days_ago ?> jour<?= $days_ago > 1 ? 's' : '' ?>
            <?php if ($a['del_first']): ?>
              par <?= h($a['del_first'] . ' ' . $a['del_last']) ?>
            <?php endif; ?>
          </div>
        </div>
        <div style="font-size:11.5px; color:<?= $days_left <= 7 ? '#DC2626' : 'var(--ink-3)' ?>; text-align:right;">
          <?php if ($days_left <= 0): ?>
            ⚠️ Purge imminente
          <?php elseif ($days_left <= 7): ?>
            ⏰ <?= $days_left ?> jour<?= $days_left > 1 ? 's' : '' ?> restants
          <?php else: ?>
            <?= $days_left ?> jour<?= $days_left > 1 ? 's' : '' ?> restants
          <?php endif; ?>
        </div>
        <form method="POST" style="margin:0;">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
          <input type="hidden" name="action" value="restore">
          <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
          <button type="submit" class="btn btn-ghost" style="padding:5px 12px; font-size:12px;"
                  onclick="return confirm('Restaurer <?= h($a['first_name'] . ' ' . $a['last_name']) ?> ?')">
            ♻️ Restaurer
          </button>
        </form>
      </div>
      <?php endforeach; ?>
    </div>

    <div style="font-size:12px; color:var(--ink-4); text-align:center; margin-top:16px; line-height:1.5;">
      💡 Les comptes restent dans la corbeille pendant <strong>30 jours</strong>, puis sont définitivement supprimés par un processus automatique (RGPD).
    </div>
  <?php endif; ?>

</div>

<?php render_foot(); ?>
