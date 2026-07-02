<?php
/**
 * ============================================================
 * ASSOKIT — Création de canal (admin uniquement)
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

// Charger les utilisateurs de l'org (pour la sélection initiale de membres en cas de canal privé)
$stmt = $pdo->prepare("
    SELECT id, first_name, last_name, role, avatar_color
    FROM users
    WHERE org_id = ? AND is_active = 1 AND id != ?
    ORDER BY first_name ASC
");
$stmt->execute([$org_id, $current['id']]);
$org_users = $stmt->fetchAll();

$errors = [];
if (isset($_GET['error'])) {
    $error_labels = [
        'invalid_name' => 'Le nom du canal est invalide.',
        'not_admin' => 'Seul un administrateur peut créer un canal.',
    ];
    $errors[] = $error_labels[$_GET['error']] ?? $_GET['error'];
}

render_head('Nouveau canal');
render_sidebar('messages');
?>

<main class="main">

  <nav class="crumbs">
    <a href="/dashboard">Dashboard</a>
    <span class="sep">›</span>
    <a href="/messages">Messages</a>
    <span class="sep">›</span>
    <span class="current">Nouveau canal</span>
  </nav>

  <div class="main-head">
    <div>
      <h1 class="page-title">Créer un canal</h1>
      <div class="page-sub">Organisez les discussions par thème ou par équipe</div>
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

  <form method="POST" action="/action-canal" novalidate>
    <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
    <input type="hidden" name="action" value="create">

    <div class="form-section">
      <h2 class="form-section-title">L'essentiel</h2>
      <p class="form-section-desc">Nom du canal, icône et petite description.</p>

      <div class="form-cols">
        <div class="form-row">
          <label class="form-label" for="name">Nom du canal <span class="required">*</span></label>
          <input type="text" name="name" id="name" class="form-input-lg" required maxlength="80"
                 placeholder="Ex : Pôle Vidéo">
          <div class="form-hint">Pas besoin de # ou d'emoji dans le nom, on gère ça séparément.</div>
        </div>
        <div class="form-row">
          <label class="form-label" for="icon">Icône (emoji)</label>
          <input type="text" name="icon" id="icon" class="form-input-lg" maxlength="10"
                 placeholder="Ex : 🎥">
          <div class="form-hint">Optionnel. Affiché à côté du nom.</div>
        </div>
      </div>

      <div class="form-row">
        <label class="form-label" for="description">Description</label>
        <input type="text" name="description" id="description" class="form-input-lg" maxlength="500"
               placeholder="À quoi sert ce canal ? (visible dans l'en-tête)">
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
          ?>
            <label style="cursor: pointer;">
              <input type="radio" name="color_theme" value="<?= h($key) ?>" <?= $key === 'gray' ? 'checked' : '' ?> style="display: none;" onchange="updateColorSelection(this)">
              <span class="color-swatch" style="display: inline-block; width: 28px; height: 28px; border-radius: 50%; background: <?= $hex ?>; border: 2px solid <?= $key === 'gray' ? 'var(--ink)' : 'transparent' ?>; cursor: pointer; transition: border-color 0.15s ease;"></span>
            </label>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

    <!-- Type de canal -->
    <div class="form-section">
      <h2 class="form-section-title">Qui peut y accéder ?</h2>
      <p class="form-section-desc">Choisissez soigneusement : le type détermine qui voit le canal et qui peut y écrire.</p>

      <div style="display: grid; gap: 10px;">
        <label style="display: block; padding: 14px 16px; border: 2px solid var(--border); border-radius: 10px; cursor: pointer; transition: all 0.15s ease;" class="type-option type-option-active">
          <input type="radio" name="type" value="public" checked style="display: none;" onchange="updateTypeSelection(this)">
          <div style="display: flex; gap: 12px; align-items: flex-start;">
            <span style="font-size: 22px;">🌐</span>
            <div>
              <div style="font-size: 14px; font-weight: 500; margin-bottom: 2px;">Public</div>
              <div style="font-size: 12.5px; color: var(--ink-3); line-height: 1.5;">Tous les membres de l'organisation voient le canal et peuvent y écrire. <strong>C'est le choix standard.</strong></div>
            </div>
          </div>
        </label>

        <label style="display: block; padding: 14px 16px; border: 2px solid var(--border); border-radius: 10px; cursor: pointer; transition: all 0.15s ease;" class="type-option">
          <input type="radio" name="type" value="private" style="display: none;" onchange="updateTypeSelection(this)">
          <div style="display: flex; gap: 12px; align-items: flex-start;">
            <span style="font-size: 22px;">🔒</span>
            <div>
              <div style="font-size: 14px; font-weight: 500; margin-bottom: 2px;">Privé</div>
              <div style="font-size: 12.5px; color: var(--ink-3); line-height: 1.5;">Seuls les membres que vous invitez voient le canal. Idéal pour la coordination, les sujets sensibles, les équipes restreintes.</div>
            </div>
          </div>
        </label>

        <label style="display: block; padding: 14px 16px; border: 2px solid var(--border); border-radius: 10px; cursor: pointer; transition: all 0.15s ease;" class="type-option">
          <input type="radio" name="type" value="announce" style="display: none;" onchange="updateTypeSelection(this)">
          <div style="display: flex; gap: 12px; align-items: flex-start;">
            <span style="font-size: 22px;">📣</span>
            <div>
              <div style="font-size: 14px; font-weight: 500; margin-bottom: 2px;">Annonces</div>
              <div style="font-size: 12.5px; color: var(--ink-3); line-height: 1.5;">Tous les membres lisent, mais seuls les admins et coordinateurs peuvent écrire. Parfait pour les communications officielles.</div>
            </div>
          </div>
        </label>
      </div>
    </div>

    <!-- Sélection de membres (visible uniquement si type = private) -->
    <div class="form-section" id="membersSection" style="display: none;">
      <h2 class="form-section-title">👥 Membres invités</h2>
      <p class="form-section-desc">Vous serez automatiquement ajouté comme modérateur. Sélectionnez qui d'autre aura accès à ce canal privé.</p>

      <?php if (empty($org_users)): ?>
        <div class="form-hint">Aucun autre membre actif dans votre organisation.</div>
      <?php else: ?>
        <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 8px;">
          <?php foreach ($org_users as $u):
            $colors = ['blue' => '#4F80BD', 'purple' => '#7F77DD', 'amber' => '#EF9F27', 'pink' => '#D77CA0', 'teal' => '#2AAE89', 'green' => '#059669', 'red' => '#B91C1C', 'gray' => '#78716C'];
            $avatar_color = $colors[$u['avatar_color'] ?? 'gray'] ?? '#78716C';
          ?>
            <label style="display: flex; gap: 10px; align-items: center; padding: 10px 12px; border: 1px solid var(--border); border-radius: 10px; cursor: pointer;">
              <input type="checkbox" name="initial_members[]" value="<?= (int)$u['id'] ?>">
              <span style="width: 30px; height: 30px; border-radius: 50%; background: <?= $avatar_color ?>; color: #fff; display: inline-flex; align-items: center; justify-content: center; font-size: 11.5px; font-weight: 600; flex-shrink: 0;">
                <?= h(strtoupper(mb_substr($u['first_name'], 0, 1) . mb_substr($u['last_name'], 0, 1))) ?>
              </span>
              <div style="flex: 1; min-width: 0;">
                <div style="font-size: 13px; font-weight: 500; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                  <?= h($u['first_name'] . ' ' . $u['last_name']) ?>
                </div>
                <div style="font-size: 11px; color: var(--ink-3);"><?= h(role_label($u['role'])) ?></div>
              </div>
            </label>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

    <!-- Actions -->
    <div class="form-actions">
      <div class="form-actions-left"><span class="required">*</span> Champs obligatoires</div>
      <div class="form-actions-right">
        <a href="/messages" class="btn btn-ghost">Annuler</a>
        <button type="submit" class="btn btn-primary">Créer le canal</button>
      </div>
    </div>
  </form>

</main>

<script>
function updateColorSelection(radio) {
  document.querySelectorAll('.color-swatch').forEach(function(s) {
    s.style.borderColor = 'transparent';
  });
  radio.nextElementSibling.style.borderColor = 'var(--ink)';
}

function updateTypeSelection(radio) {
  document.querySelectorAll('.type-option').forEach(function(opt) {
    opt.style.borderColor = 'var(--border)';
    opt.style.background = 'transparent';
  });
  var selected = radio.closest('.type-option');
  selected.style.borderColor = 'var(--acc)';
  selected.style.background = 'var(--acc-light)';

  // Montrer/cacher la section membres
  var membersSection = document.getElementById('membersSection');
  if (radio.value === 'private') {
    membersSection.style.display = '';
  } else {
    membersSection.style.display = 'none';
  }
}

// Initialiser le style du type actif
document.querySelector('input[name="type"]:checked').closest('.type-option').style.borderColor = 'var(--acc)';
document.querySelector('input[name="type"]:checked').closest('.type-option').style.background = 'var(--acc-light)';
</script>

<?php render_foot(); ?>
