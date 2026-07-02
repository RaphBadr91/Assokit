<?php
/**
 * ============================================================
 * ASSOKIT — Édition d'un utilisateur (admin)
 * ============================================================
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes-layout.php';

require_login();
$current = current_user();
$org_id = (int)$current['org_id'];

if ($current['role'] !== 'admin') {
    header('Location: /dashboard?error=not_admin');
    exit;
}

$user_id = (int)($_GET['id'] ?? 0);
if ($user_id <= 0) {
    header('Location: /admin');
    exit;
}

$stmt = $pdo->prepare("
    SELECT u.*,
           creator.first_name AS creator_first, creator.last_name AS creator_last
    FROM users u
    LEFT JOIN users creator ON u.created_by_user_id = creator.id
    WHERE u.id = ? AND u.org_id = ?
");
$stmt->execute([$user_id, $org_id]);
$user = $stmt->fetch();

if (!$user) {
    header('Location: /admin?error=notfound');
    exit;
}

$is_self = ($user_id === (int)$current['id']);

$errors = $_SESSION['form_errors'] ?? [];
unset($_SESSION['form_errors']);

render_head('Modifier ' . $user['first_name'] . ' ' . $user['last_name']);
render_sidebar('admin');

$colors = [
    'blue' => '#4F80BD', 'purple' => '#7F77DD', 'amber' => '#EF9F27',
    'pink' => '#D77CA0', 'teal' => '#2AAE89', 'green' => '#059669',
    'red' => '#B91C1C', 'gray' => '#78716C'
];
?>

<main class="main">

  <nav class="crumbs">
    <a href="/dashboard">Dashboard</a>
    <span class="sep">›</span>
    <a href="/admin">Administration</a>
    <span class="sep">›</span>
    <span class="current"><?= h($user['first_name'] . ' ' . $user['last_name']) ?></span>
  </nav>

  <div class="main-head">
    <div style="display: flex; gap: 16px; align-items: center;">
      <div style="width: 52px; height: 52px; border-radius: 50%; background: <?= $colors[$user['avatar_color'] ?? 'gray'] ?? '#78716C' ?>; color: #fff; display: flex; align-items: center; justify-content: center; font-size: 18px; font-weight: 600;">
        <?= h(strtoupper(mb_substr($user['first_name'], 0, 1) . mb_substr($user['last_name'], 0, 1))) ?>
      </div>
      <div>
        <h1 class="page-title"><?= h($user['first_name'] . ' ' . $user['last_name']) ?></h1>
        <div class="page-sub">
          <?= h($user['email']) ?>
          <?php if (!$user['is_active']): ?>
            · <span style="color: #B91C1C; font-weight: 500;">⚠️ Compte désactivé</span>
          <?php endif; ?>
          <?php if ($user['must_change_password']): ?>
            · <span style="color: #92400E; font-weight: 500;">🔑 Mot de passe temporaire</span>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>

  <?php if ($errors): ?>
    <div class="alert alert-error">
      <strong>Corrigez les erreurs suivantes :</strong>
      <?php foreach ($errors as $err): ?>
        <div>• <?= h($err) ?></div>
      <?php endforeach; ?>
    </div>
  <?php elseif (isset($_GET['updated'])): ?>
    <div class="alert alert-success">✅ Profil mis à jour avec succès.</div>
  <?php elseif (isset($_GET['error'])):
    $err_labels = [
      'cannot_deactivate_self' => 'Vous ne pouvez pas désactiver votre propre compte.',
    ];
    $err_msg = $err_labels[$_GET['error']] ?? $_GET['error'];
  ?>
    <div class="alert alert-error">⚠️ <?= h($err_msg) ?></div>
  <?php endif; ?>

  <form method="POST" action="/action-admin-utilisateur" novalidate>
    <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
    <input type="hidden" name="action" value="update">
    <input type="hidden" name="user_id" value="<?= (int)$user['id'] ?>">

    <!-- Identité -->
    <div class="form-section">
      <h2 class="form-section-title">👤 Identité</h2>

      <div class="form-cols">
        <div class="form-row">
          <label class="form-label">Prénom <span class="required">*</span></label>
          <input type="text" name="first_name" class="form-input-lg" required maxlength="80" value="<?= h($user['first_name']) ?>">
        </div>
        <div class="form-row">
          <label class="form-label">Nom <span class="required">*</span></label>
          <input type="text" name="last_name" class="form-input-lg" required maxlength="80" value="<?= h($user['last_name']) ?>">
        </div>
      </div>

      <div class="form-cols">
        <div class="form-row">
          <label class="form-label">Email <span class="required">*</span></label>
          <input type="email" name="email" class="form-input-lg" required maxlength="150" value="<?= h($user['email']) ?>">
        </div>
        <div class="form-row">
          <label class="form-label">Téléphone</label>
          <input type="tel" name="phone" class="form-input-lg" maxlength="30" value="<?= h($user['phone']) ?>">
        </div>
      </div>

      <div class="form-row">
        <label class="form-label">Ville</label>
        <input type="text" name="city" class="form-input-lg" maxlength="100" value="<?= h($user['city']) ?>">
      </div>

      <div class="form-row">
        <label class="form-label">Couleur d'avatar</label>
        <div style="display: flex; gap: 8px; flex-wrap: wrap;">
          <?php foreach ($colors as $key => $hex):
            $is_selected = ($user['avatar_color'] === $key);
          ?>
            <label style="cursor: pointer;">
              <input type="radio" name="avatar_color" value="<?= h($key) ?>" <?= $is_selected ? 'checked' : '' ?> style="display: none;" onchange="updateAvatarSelect(this)">
              <span class="avatar-swatch" style="display: inline-block; width: 32px; height: 32px; border-radius: 50%; background: <?= $hex ?>; border: 2px solid <?= $is_selected ? 'var(--ink)' : 'transparent' ?>; cursor: pointer;"></span>
            </label>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

    <!-- Rôle & Contrat -->
    <div class="form-section">
      <h2 class="form-section-title">🎯 Rôle & Type de contrat</h2>

      <?php if ($is_self): ?>
        <div class="alert alert-info" style="margin-bottom: 18px;">
          ℹ️ Vous ne pouvez pas changer votre propre rôle administrateur (sécurité).
        </div>
      <?php endif; ?>

      <div class="form-row">
        <label class="form-label">Rôle <span class="required">*</span></label>
        <div class="role-picker">
          <?php
          $roles = [
            'admin' => ['👑 Administrateur', 'Tous les droits.'],
            'coordinator' => ['🎯 Coordinateur', 'Gère projets, événements, messages.'],
            'referent' => ['📋 Référent', 'Référent d\'un ou plusieurs projets.'],
            'member' => ['👤 Membre', 'Participation standard.'],
            'follower' => ['👁️ Suivi projet', 'Financeur externe.'],
          ];
          foreach ($roles as $key => $info):
            $is_selected = ($user['role'] === $key);
          ?>
            <label class="role-option <?= $is_selected ? 'active' : '' ?>" <?= $is_self && !$is_selected ? 'style="opacity:0.5;pointer-events:none;"' : '' ?>>
              <input type="radio" name="role" value="<?= h($key) ?>" <?= $is_selected ? 'checked' : '' ?> <?= $is_self ? 'disabled' : '' ?> onchange="updateRoleSelection(this)">
              <div class="role-option-label"><?= $info[0] ?></div>
              <div class="role-option-desc"><?= $info[1] ?></div>
            </label>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="form-cols" style="margin-top: 20px;">
        <div class="form-row">
          <label class="form-label">Type de contrat</label>
          <select name="contract_type" class="form-select-lg" onchange="updateContractFields(this)">
            <?php
            $contracts = [
              'volunteer' => '🤝 Bénévole',
              'employee' => '💼 Salarié',
              'intern' => '🎓 Stagiaire',
              'civic_service' => '🧭 Service civique',
              'contractor' => '🔧 Prestataire',
              'external' => '🌐 Externe',
            ];
            foreach ($contracts as $key => $label): ?>
              <option value="<?= h($key) ?>" <?= $user['contract_type'] === $key ? 'selected' : '' ?>><?= $label ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-row">
          <label class="form-label">Date d'adhésion</label>
          <input type="date" name="adhesion_date" class="form-input-lg" value="<?= h($user['adhesion_date']) ?>">
        </div>
      </div>

      <div class="form-cols" id="contract-dates-row" style="display: <?= in_array($user['contract_type'], ['intern', 'civic_service', 'employee', 'contractor'], true) ? '' : 'none' ?>;">
        <div class="form-row">
          <label class="form-label">Début du contrat</label>
          <input type="date" name="contract_start_date" class="form-input-lg" value="<?= h($user['contract_start_date']) ?>">
        </div>
        <div class="form-row">
          <label class="form-label">Fin du contrat</label>
          <input type="date" name="contract_end_date" class="form-input-lg" value="<?= h($user['contract_end_date']) ?>">
        </div>
      </div>

      <div class="form-row" id="org-name-row" style="display: <?= ($user['role'] === 'follower' || $user['contract_type'] === 'external') ? '' : 'none' ?>;">
        <label class="form-label">Organisation d'origine</label>
        <input type="text" name="organization_name" class="form-input-lg" maxlength="200" value="<?= h($user['organization_name']) ?>"
               placeholder="Ex : Mairie de Ris-Orangis, DRAC Île-de-France…">
      </div>
    </div>

    <!-- Capacités -->
    <div class="form-section" id="capabilities-section" style="<?= $user['role'] === 'follower' ? 'display:none;' : '' ?>">
      <h2 class="form-section-title">⚡ Capacités additionnelles</h2>

      <div class="capabilities-grid">
        <label class="cap-option">
          <input type="checkbox" name="can_create_projects" value="1" <?= $user['can_create_projects'] ? 'checked' : '' ?>>
          <div>
            <div class="cap-name">📁 Créer des projets</div>
            <div class="cap-desc">Peut créer de nouveaux projets</div>
          </div>
        </label>
        <label class="cap-option">
          <input type="checkbox" name="can_manage_members" value="1" <?= $user['can_manage_members'] ? 'checked' : '' ?>>
          <div>
            <div class="cap-name">👥 Gérer les adhérents</div>
            <div class="cap-desc">Créer/modifier les fiches</div>
          </div>
        </label>
        <label class="cap-option">
          <input type="checkbox" name="can_manage_finances" value="1" <?= $user['can_manage_finances'] ? 'checked' : '' ?>>
          <div>
            <div class="cap-name">💰 Trésorerie</div>
            <div class="cap-desc">Voir toutes les factures</div>
          </div>
        </label>
        <label class="cap-option">
          <input type="checkbox" name="can_access_marketing" value="1" <?= $user['can_access_marketing'] ? 'checked' : '' ?>>
          <div>
            <div class="cap-name">📢 Communication</div>
            <div class="cap-desc">Page Communication (bientôt)</div>
          </div>
        </label>
        <label class="cap-option">
          <input type="checkbox" name="can_manage_events" value="1" <?= $user['can_manage_events'] ? 'checked' : '' ?>>
          <div>
            <div class="cap-name">📅 Gérer les événements</div>
            <div class="cap-desc">Créer, modifier, supprimer</div>
          </div>
        </label>
        <label class="cap-option">
          <input type="checkbox" name="can_moderate_messages" value="1" <?= $user['can_moderate_messages'] ? 'checked' : '' ?>>
          <div>
            <div class="cap-name">💬 Modérer les messages</div>
            <div class="cap-desc">Supprimer, gérer les canaux</div>
          </div>
        </label>
      </div>
    </div>

    <!-- Notes admin -->
    <div class="form-section">
      <h2 class="form-section-title">📝 Notes internes (admin uniquement)</h2>
      <div class="form-row">
        <textarea name="notes_admin" class="form-textarea-lg" rows="3" maxlength="2000"><?= h($user['notes_admin']) ?></textarea>
      </div>
    </div>

    <!-- Métadonnées -->
    <div class="form-section" style="background: var(--bg-2);">
      <h2 class="form-section-title" style="font-size: 13px; color: var(--ink-3);">ℹ️ Informations du compte</h2>
      <div style="display: grid; grid-template-columns: auto 1fr; gap: 8px 16px; font-size: 12.5px; color: var(--ink-2);">
        <div style="color: var(--ink-3);">ID interne</div>
        <div style="font-family: monospace;">#<?= (int)$user['id'] ?></div>
        <div style="color: var(--ink-3);">Créé le</div>
        <div><?= h(date('d/m/Y à H:i', strtotime($user['created_at']))) ?></div>
        <?php if ($user['creator_first']): ?>
          <div style="color: var(--ink-3);">Créé par</div>
          <div><?= h($user['creator_first'] . ' ' . $user['creator_last']) ?></div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Actions -->
    <div class="form-actions">
      <div class="form-actions-right">
        <a href="/admin" class="btn btn-ghost">Annuler</a>
        <button type="submit" class="btn btn-primary">Enregistrer les modifications</button>
      </div>
    </div>
  </form>

  <!-- Actions spéciales -->
  <div class="form-section" style="border: 1px solid rgba(185,28,28,0.2); background: rgba(185,28,28,0.03); margin-top: 24px;">
    <h2 class="form-section-title" style="color: #B91C1C;">⚠️ Actions sensibles</h2>

    <div style="display: flex; gap: 12px; flex-wrap: wrap;">

      <!-- Reset mot de passe -->
      <form method="POST" action="/action-admin-utilisateur" style="margin: 0;"
            onsubmit="return confirm('Générer un nouveau mot de passe pour <?= h(addslashes($user['first_name'])) ?> ?\n\nL\'ancien mot de passe sera immédiatement invalidé.');">
        <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
        <input type="hidden" name="action" value="reset_password">
        <input type="hidden" name="user_id" value="<?= (int)$user['id'] ?>">
        <button type="submit" class="btn btn-ghost" style="color: #92400E;">
          🔑 Réinitialiser le mot de passe
        </button>
      </form>

      <?php if (!$is_self): ?>
        <?php if ($user['is_active']): ?>
          <!-- Désactiver -->
          <form method="POST" action="/action-admin-utilisateur" style="margin: 0;"
                onsubmit="return confirm('Désactiver le compte de <?= h(addslashes($user['first_name'] . ' ' . $user['last_name'])) ?> ?\n\nCette personne ne pourra plus se connecter mais ses données sont conservées.');">
            <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="action" value="deactivate">
            <input type="hidden" name="user_id" value="<?= (int)$user['id'] ?>">
            <button type="submit" class="btn btn-ghost" style="color: #B91C1C;">
              🚫 Désactiver le compte
            </button>
          </form>
        <?php else: ?>
          <!-- Réactiver -->
          <form method="POST" action="/action-admin-utilisateur" style="margin: 0;">
            <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="action" value="reactivate">
            <input type="hidden" name="user_id" value="<?= (int)$user['id'] ?>">
            <button type="submit" class="btn btn-primary">
              ✅ Réactiver le compte
            </button>
          </form>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  </div>

</main>

<style>
.role-picker { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 10px; }
.role-option { display: block; padding: 14px 16px; background: var(--bg); border: 2px solid var(--border); border-radius: 12px; cursor: pointer; transition: all 0.15s ease; }
.role-option input { display: none; }
.role-option:hover { border-color: var(--border-strong); }
.role-option.active { border-color: var(--acc); background: var(--acc-light); }
.role-option-label { font-size: 13.5px; font-weight: 500; margin-bottom: 3px; }
.role-option-desc { font-size: 11.5px; color: var(--ink-3); line-height: 1.4; }
.capabilities-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 10px; }
.cap-option { display: flex; gap: 10px; align-items: flex-start; padding: 12px 14px; background: var(--bg); border: 1px solid var(--border); border-radius: 10px; cursor: pointer; }
.cap-option:hover { background: var(--bg-2); }
.cap-option input { margin-top: 3px; flex-shrink: 0; }
.cap-name { font-size: 13px; font-weight: 500; margin-bottom: 2px; }
.cap-desc { font-size: 11.5px; color: var(--ink-3); line-height: 1.4; }
</style>

<script>
function updateRoleSelection(radio) {
  document.querySelectorAll('.role-option').forEach(function(opt) { opt.classList.remove('active'); });
  radio.closest('.role-option').classList.add('active');
  var capsSection = document.getElementById('capabilities-section');
  var orgRow = document.getElementById('org-name-row');
  if (radio.value === 'follower') {
    capsSection.style.display = 'none';
    orgRow.style.display = '';
  } else {
    capsSection.style.display = '';
  }
}
function updateContractFields(select) {
  var type = select.value;
  var datesRow = document.getElementById('contract-dates-row');
  var orgRow = document.getElementById('org-name-row');
  if (['intern', 'civic_service', 'employee', 'contractor'].indexOf(type) >= 0) {
    datesRow.style.display = '';
  } else {
    datesRow.style.display = 'none';
  }
  if (type === 'external') orgRow.style.display = '';
}
function updateAvatarSelect(radio) {
  document.querySelectorAll('.avatar-swatch').forEach(function(s) { s.style.borderColor = 'transparent'; });
  radio.nextElementSibling.style.borderColor = 'var(--ink)';
}
</script>

<?php render_foot(); ?>
