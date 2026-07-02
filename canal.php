<?php
/**
 * ============================================================
 * ASSOKIT — Paramètres d'un canal (admin uniquement)
 * ============================================================
 * URL : /canal/{slug}/parametres
 * Permet à l'admin de :
 *   - Modifier nom / icône / description / couleur / type
 *   - Gérer les membres (si canal privé)
 *   - Archiver le canal
 *   - Supprimer définitivement le canal
 * ============================================================
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes-layout.php';

require_login();
$current = current_user();
$org_id = (int)$current['org_id'];

if ($current['role'] !== 'admin') {
    header('Location: /messages?error=not_admin');
    exit;
}

$slug = $_GET['slug'] ?? '';
if ($slug === '') {
    header('Location: /messages');
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM channels WHERE org_id = ? AND slug = ?");
$stmt->execute([$org_id, $slug]);
$channel = $stmt->fetch();

if (!$channel) {
    header('Location: /messages?error=notfound');
    exit;
}

// Membres actuels du canal privé
$members = [];
if ($channel['type'] === 'private') {
    $stmt = $pdo->prepare("
        SELECT cm.*, u.first_name, u.last_name, u.role AS user_role, u.email, u.avatar_color
        FROM channel_members cm
        JOIN users u ON cm.user_id = u.id
        WHERE cm.channel_id = ?
        ORDER BY cm.role DESC, u.first_name ASC
    ");
    $stmt->execute([$channel['id']]);
    $members = $stmt->fetchAll();
}

// Users de l'org qui ne sont pas encore membres (pour l'ajout)
$member_user_ids = array_column($members, 'user_id');
$available_users = [];
if ($channel['type'] === 'private') {
    if (empty($member_user_ids)) {
        $stmt = $pdo->prepare("SELECT id, first_name, last_name, role, avatar_color FROM users WHERE org_id = ? AND is_active = 1 ORDER BY first_name");
        $stmt->execute([$org_id]);
    } else {
        $placeholders = implode(',', array_fill(0, count($member_user_ids), '?'));
        $params = array_merge([$org_id], $member_user_ids);
        $stmt = $pdo->prepare("SELECT id, first_name, last_name, role, avatar_color FROM users WHERE org_id = ? AND is_active = 1 AND id NOT IN ($placeholders) ORDER BY first_name");
        $stmt->execute($params);
    }
    $available_users = $stmt->fetchAll();
}

// Stats du canal
$msg_count_stmt = $pdo->prepare("SELECT COUNT(*) FROM channel_messages WHERE channel_id = ? AND deleted_at IS NULL");
$msg_count_stmt->execute([$channel['id']]);
$msg_count = (int)$msg_count_stmt->fetchColumn();

$last_msg_stmt = $pdo->prepare("SELECT created_at FROM channel_messages WHERE channel_id = ? AND deleted_at IS NULL ORDER BY created_at DESC LIMIT 1");
$last_msg_stmt->execute([$channel['id']]);
$last_msg_date = $last_msg_stmt->fetchColumn();

render_head('Paramètres · ' . $channel['name']);
render_sidebar('messages');
?>

<main class="main">

  <nav class="crumbs">
    <a href="/dashboard">Dashboard</a>
    <span class="sep">›</span>
    <a href="/messages">Messages</a>
    <span class="sep">›</span>
    <a href="/messages?c=<?= h($channel['slug']) ?>"><?= h($channel['name']) ?></a>
    <span class="sep">›</span>
    <span class="current">Paramètres</span>
  </nav>

  <div class="main-head">
    <div>
      <h1 class="page-title">
        <?= $channel['icon'] ?: '💬' ?> Paramètres du canal
      </h1>
      <div class="page-sub"><?= h($channel['name']) ?> · <?= $msg_count ?> message<?= $msg_count > 1 ? 's' : '' ?><?= $last_msg_date ? ' · dernier : ' . h(date('d/m/Y', strtotime($last_msg_date))) : '' ?></div>
    </div>
  </div>

  <?php if (isset($_GET['updated'])): ?>
    <div class="alert alert-success">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
      ✅ Canal mis à jour.
    </div>
  <?php elseif (isset($_GET['member_added'])): ?>
    <div class="alert alert-success">✅ Membre ajouté.</div>
  <?php elseif (isset($_GET['member_removed'])): ?>
    <div class="alert alert-success">✅ Membre retiré.</div>
  <?php endif; ?>

  <!-- Formulaire de modification -->
  <form method="POST" action="/action-canal" novalidate>
    <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
    <input type="hidden" name="action" value="update">
    <input type="hidden" name="channel_id" value="<?= (int)$channel['id'] ?>">

    <div class="form-section">
      <h2 class="form-section-title">Modifier le canal</h2>

      <div class="form-cols">
        <div class="form-row">
          <label class="form-label">Nom <span class="required">*</span></label>
          <input type="text" name="name" class="form-input-lg" required maxlength="80" value="<?= h($channel['name']) ?>">
        </div>
        <div class="form-row">
          <label class="form-label">Icône (emoji)</label>
          <input type="text" name="icon" class="form-input-lg" maxlength="10" value="<?= h($channel['icon']) ?>">
        </div>
      </div>

      <div class="form-row">
        <label class="form-label">Description</label>
        <input type="text" name="description" class="form-input-lg" maxlength="500" value="<?= h($channel['description']) ?>">
      </div>

      <div class="form-row">
        <label class="form-label">Couleur</label>
        <div style="display: flex; gap: 8px; flex-wrap: wrap;">
          <?php
          $colors = [
            'blue' => '#4F80BD', 'purple' => '#7F77DD', 'amber' => '#EF9F27',
            'pink' => '#D77CA0', 'teal' => '#2AAE89', 'green' => '#059669',
            'red' => '#B91C1C', 'gray' => '#78716C'
          ];
          foreach ($colors as $key => $hex):
            $is_selected = ($channel['color_theme'] === $key);
          ?>
            <label style="cursor: pointer;">
              <input type="radio" name="color_theme" value="<?= h($key) ?>" <?= $is_selected ? 'checked' : '' ?> style="display: none;" onchange="updateColorSelectionEdit(this)">
              <span class="color-swatch-edit" style="display: inline-block; width: 28px; height: 28px; border-radius: 50%; background: <?= $hex ?>; border: 2px solid <?= $is_selected ? 'var(--ink)' : 'transparent' ?>; cursor: pointer;"></span>
            </label>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="form-row">
        <label class="form-label">Type</label>
        <select name="type" class="form-select-lg">
          <option value="public" <?= $channel['type'] === 'public' ? 'selected' : '' ?>>🌐 Public (tous écrivent et lisent)</option>
          <option value="private" <?= $channel['type'] === 'private' ? 'selected' : '' ?>>🔒 Privé (sur invitation)</option>
          <option value="announce" <?= $channel['type'] === 'announce' ? 'selected' : '' ?>>📣 Annonces (admins écrivent, tous lisent)</option>
        </select>
        <div class="form-hint">⚠️ Changer le type peut affecter la visibilité du canal pour certains membres.</div>
      </div>
    </div>

    <div class="form-actions">
      <div class="form-actions-right">
        <a href="/messages?c=<?= h($channel['slug']) ?>" class="btn btn-ghost">Annuler</a>
        <button type="submit" class="btn btn-primary">Enregistrer les modifications</button>
      </div>
    </div>
  </form>

  <!-- Gestion des membres (uniquement pour canaux privés) -->
  <?php if ($channel['type'] === 'private'): ?>
  <div class="form-section">
    <h2 class="form-section-title">👥 Membres du canal privé</h2>
    <p class="form-section-desc">Seuls les membres listés peuvent voir et écrire dans ce canal (plus les admins de l'org).</p>

    <!-- Liste actuelle -->
    <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 8px; margin-bottom: 20px;">
      <?php foreach ($members as $m):
        $colors = ['blue' => '#4F80BD', 'purple' => '#7F77DD', 'amber' => '#EF9F27', 'pink' => '#D77CA0', 'teal' => '#2AAE89', 'green' => '#059669', 'red' => '#B91C1C', 'gray' => '#78716C'];
        $avatar_color = $colors[$m['avatar_color'] ?? 'gray'] ?? '#78716C';
      ?>
        <div style="display: flex; gap: 10px; align-items: center; padding: 10px 12px; border: 1px solid var(--border); border-radius: 10px; background: var(--bg-2);">
          <span style="width: 34px; height: 34px; border-radius: 50%; background: <?= $avatar_color ?>; color: #fff; display: inline-flex; align-items: center; justify-content: center; font-size: 12.5px; font-weight: 600; flex-shrink: 0;">
            <?= h(strtoupper(mb_substr($m['first_name'], 0, 1) . mb_substr($m['last_name'], 0, 1))) ?>
          </span>
          <div style="flex: 1; min-width: 0;">
            <div style="font-size: 13px; font-weight: 500;"><?= h($m['first_name'] . ' ' . $m['last_name']) ?></div>
            <div style="font-size: 11px; color: var(--ink-3);">
              <?= h(role_label($m['user_role'])) ?>
              <?php if ($m['role'] === 'moderator'): ?>
                · <span style="color: var(--ai-dark);">Modérateur du canal</span>
              <?php endif; ?>
            </div>
          </div>
          <?php if ($m['user_id'] != $current['id']): ?>
            <form method="POST" action="/action-canal" style="margin: 0;" onsubmit="return confirm('Retirer <?= h(addslashes($m['first_name'])) ?> de ce canal ?');">
              <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
              <input type="hidden" name="action" value="remove_member">
              <input type="hidden" name="channel_id" value="<?= (int)$channel['id'] ?>">
              <input type="hidden" name="user_id" value="<?= (int)$m['user_id'] ?>">
              <input type="hidden" name="channel_slug" value="<?= h($channel['slug']) ?>">
              <button type="submit" style="background: transparent; border: none; color: #B91C1C; cursor: pointer; padding: 6px; border-radius: 6px;" title="Retirer">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
              </button>
            </form>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>

    <!-- Ajouter un membre -->
    <?php if (!empty($available_users)): ?>
      <h3 style="font-size: 14px; font-weight: 500; margin-bottom: 10px;">➕ Ajouter un membre</h3>
      <form method="POST" action="/action-canal" style="display: flex; gap: 10px; align-items: center;">
        <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
        <input type="hidden" name="action" value="add_member">
        <input type="hidden" name="channel_id" value="<?= (int)$channel['id'] ?>">
        <input type="hidden" name="channel_slug" value="<?= h($channel['slug']) ?>">
        <select name="user_id" class="form-select-lg" required style="flex: 1;">
          <option value="">— Choisir un utilisateur —</option>
          <?php foreach ($available_users as $u): ?>
            <option value="<?= (int)$u['id'] ?>"><?= h($u['first_name'] . ' ' . $u['last_name']) ?> · <?= h(role_label($u['role'])) ?></option>
          <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-primary">Ajouter</button>
      </form>
    <?php else: ?>
      <div class="form-hint">Tous les membres actifs de votre organisation ont déjà accès à ce canal.</div>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <!-- Zone dangereuse -->
  <div class="form-section" style="border: 1px solid rgba(185,28,28,0.2); background: rgba(185,28,28,0.03);">
    <h2 class="form-section-title" style="color: #B91C1C;">⚠️ Zone sensible</h2>
    <p class="form-section-desc">Ces actions sont irréversibles. Réfléchissez bien avant de cliquer.</p>

    <div style="display: flex; gap: 12px; flex-wrap: wrap;">
      <form method="POST" action="/action-canal" style="margin: 0;"
            onsubmit="return confirm('Archiver le canal « <?= h(addslashes($channel['name'])) ?> » ? Il sera masqué mais les messages seront conservés.');">
        <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
        <input type="hidden" name="action" value="archive">
        <input type="hidden" name="channel_id" value="<?= (int)$channel['id'] ?>">
        <button type="submit" class="btn btn-ghost" style="color: #92400E;">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="21 8 21 21 3 21 3 8"/><rect x="1" y="3" width="22" height="5"/><line x1="10" y1="12" x2="14" y2="12"/></svg>
          Archiver le canal
        </button>
      </form>

      <form method="POST" action="/action-canal" style="margin: 0;"
            onsubmit="return confirm('⚠️ SUPPRIMER DÉFINITIVEMENT le canal « <?= h(addslashes($channel['name'])) ?> » et ses <?= $msg_count ?> message<?= $msg_count > 1 ? 's' : '' ?> ?\n\nCette action est IRRÉVERSIBLE.');">
        <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="channel_id" value="<?= (int)$channel['id'] ?>">
        <button type="submit" class="btn btn-ghost" style="color: #B91C1C;">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/></svg>
          Supprimer définitivement
        </button>
      </form>
    </div>
  </div>

</main>

<script>
function updateColorSelectionEdit(radio) {
  document.querySelectorAll('.color-swatch-edit').forEach(function(s) {
    s.style.borderColor = 'transparent';
  });
  radio.nextElementSibling.style.borderColor = 'var(--ink)';
}
</script>

<?php render_foot(); ?>
