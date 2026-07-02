<?php
/**
 * ============================================================
 * ASSOKIT — Création d'un utilisateur (admin)
 * ============================================================
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes-layout.php';

// === [PACK 6.2] Helpers du système de plans ===
@require_once __DIR__ . '/plan-helpers.php';

require_login();
$current = current_user();
$org_id = (int)$current['org_id'];

if ($current['role'] !== 'admin') {
    header('Location: /dashboard?error=not_admin');
    exit;
}

// === [PACK 6.2] BLOCAGE STRICT : limite d'utilisateurs selon le plan ===
$plan_limit_reached = false;
$plan_limit_info = null;
if (function_exists('ak_can_add_user')) {
    $check = ak_can_add_user($pdo, $org_id);
    if (!$check['ok']) {
        $plan_limit_reached = true;
        $plan_limit_info = $check;
    }
}

// Récupérer les erreurs/données pré-remplies depuis session
$errors = $_SESSION['form_errors'] ?? [];
$form_data = $_SESSION['form_data'] ?? [];
unset($_SESSION['form_errors'], $_SESSION['form_data']);

function old_val($key, $default = '') {
    global $form_data;
    return $form_data[$key] ?? $default;
}

render_head('Créer un compte');
render_sidebar('admin');
?>

<main class="main">

  <nav class="crumbs">
    <a href="/dashboard">Dashboard</a>
    <span class="sep">›</span>
    <a href="/admin">Administration</a>
    <span class="sep">›</span>
    <span class="current">Créer un compte</span>
  </nav>

  <div class="main-head">
    <div>
      <h1 class="page-title">Créer un nouveau compte</h1>
      <div class="page-sub">Tous les champs avec <span class="required">*</span> sont obligatoires</div>
    </div>
  </div>

  <?php if ($errors): ?>
    <div class="alert alert-error">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/></svg>
      <div>
        <strong>Impossible de créer le compte :</strong>
        <?php foreach ($errors as $err): ?>
          <div>• <?= h($err) ?></div>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endif; ?>

  <?php if ($plan_limit_reached): ?>
    <!-- === [PACK 6.2] Bandeau de blocage strict utilisateurs === -->
    <div style="background:linear-gradient(135deg, #FEE2E2 0%, #FED7AA 100%); border:2px solid #DC2626; border-radius:14px; padding:24px 28px; margin-bottom:20px; display:flex; gap:18px; align-items:flex-start; flex-wrap:wrap;">
      <div style="font-size:42px; line-height:1; flex-shrink:0;">🔒</div>
      <div style="flex:1; min-width:240px;">
        <h3 style="margin:0 0 8px; font-size:18px; color:#991B1B;">Limite d'utilisateurs atteinte</h3>
        <p style="margin:0 0 14px; color:#7F1D1D; line-height:1.6; font-size:14px;">
          Vous avez atteint la limite de <strong><?= (int)($plan_limit_info['limit'] ?? 0) ?> utilisateurs</strong> de votre plan actuel
          (<?= (int)($plan_limit_info['current'] ?? 0) ?> utilisateurs actifs).
          <br>Pour ajouter de nouveaux utilisateurs, passez au plan supérieur.
        </p>
        <div style="display:flex; gap:10px; flex-wrap:wrap;">
          <a href="/mon-asso-plan" class="btn btn-primary" style="background:#DC2626; color:white;">↗ Voir les plans</a>
          <a href="/contact?subject=demo&plan=assokit" class="btn btn-ghost">📞 Demander une démo</a>
        </div>
      </div>
    </div>
  <?php else: ?>

  <form method="POST" action="/action-admin-utilisateur" novalidate>
    <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
    <input type="hidden" name="action" value="create">

    <!-- Identité -->
    <div class="form-section">
      <h2 class="form-section-title">👤 Identité</h2>

      <div class="form-cols">
        <div class="form-row">
          <label class="form-label">Prénom <span class="required">*</span></label>
          <input type="text" name="first_name" class="form-input-lg" required maxlength="80"
                 value="<?= h(old_val('first_name')) ?>" placeholder="Claire">
        </div>
        <div class="form-row">
          <label class="form-label">Nom <span class="required">*</span></label>
          <input type="text" name="last_name" class="form-input-lg" required maxlength="80"
                 value="<?= h(old_val('last_name')) ?>" placeholder="Dupont">
        </div>
      </div>

      <div class="form-cols">
        <div class="form-row">
          <label class="form-label">Email <span class="required">*</span></label>
          <input type="email" name="email" class="form-input-lg" required maxlength="150"
                 value="<?= h(old_val('email')) ?>" placeholder="claire@organisation.fr">
          <div class="form-hint">Cet email servira pour la connexion.</div>
        </div>
        <div class="form-row">
          <label class="form-label">Téléphone</label>
          <input type="tel" name="phone" class="form-input-lg" maxlength="30"
                 value="<?= h(old_val('phone')) ?>" placeholder="06 12 34 56 78">
        </div>
      </div>

      <div class="form-row">
        <label class="form-label">Ville</label>
        <input type="text" name="city" class="form-input-lg" maxlength="100"
               value="<?= h(old_val('city')) ?>" placeholder="Ris-Orangis">
      </div>

      <div class="form-row">
        <label class="form-label">Couleur d'avatar</label>
        <div style="display: flex; gap: 8px; flex-wrap: wrap;">
          <?php
          $colors = [
            'blue' => '#4F80BD', 'purple' => '#7F77DD', 'amber' => '#EF9F27',
            'pink' => '#D77CA0', 'teal' => '#2AAE89', 'green' => '#059669',
            'red' => '#B91C1C', 'gray' => '#78716C'
          ];
          $selected_color = old_val('avatar_color', 'gray');
          foreach ($colors as $key => $hex):
            $is_selected = ($selected_color === $key);
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
      <p class="form-section-desc">Le rôle détermine les droits dans l'organisation. Le type de contrat est informationnel.</p>

      <div class="form-row">
        <label class="form-label">Rôle dans l'organisation <span class="required">*</span></label>
        <div class="role-picker">
          <?php
          $roles = [
            'admin' => ['👑 Administrateur', 'Tous les droits. Peut gérer tout Assokit.'],
            'coordinator' => ['🎯 Coordinateur', 'Gère projets, événements, messages. Peut créer des projets.'],
            'referent' => ['📋 Référent', 'Référent d\'un ou plusieurs projets. Peut créer des projets.'],
            'member' => ['👤 Membre', 'Participe aux projets, voit l\'organisation. Standard.'],
            'follower' => ['👁️ Suivi projet', 'Financeur externe. Voit uniquement les projets qu\'il finance.'],
          ];
          $selected_role = old_val('role', 'member');
          foreach ($roles as $key => $info):
            $is_selected = ($selected_role === $key);
          ?>
            <label class="role-option <?= $is_selected ? 'active' : '' ?>">
              <input type="radio" name="role" value="<?= h($key) ?>" <?= $is_selected ? 'checked' : '' ?> onchange="updateRoleSelection(this)">
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
            <option value="volunteer" <?= old_val('contract_type', 'volunteer') === 'volunteer' ? 'selected' : '' ?>>🤝 Bénévole</option>
            <option value="employee" <?= old_val('contract_type') === 'employee' ? 'selected' : '' ?>>💼 Salarié</option>
            <option value="intern" <?= old_val('contract_type') === 'intern' ? 'selected' : '' ?>>🎓 Stagiaire</option>
            <option value="civic_service" <?= old_val('contract_type') === 'civic_service' ? 'selected' : '' ?>>🧭 Service civique</option>
            <option value="contractor" <?= old_val('contract_type') === 'contractor' ? 'selected' : '' ?>>🔧 Prestataire</option>
            <option value="external" <?= old_val('contract_type') === 'external' ? 'selected' : '' ?>>🌐 Externe (financeur, partenaire…)</option>
          </select>
        </div>
        <div class="form-row" id="adhesion-row">
          <label class="form-label">Date d'adhésion</label>
          <input type="date" name="adhesion_date" class="form-input-lg"
                 value="<?= h(old_val('adhesion_date', date('Y-m-d'))) ?>">
          <div class="form-hint">Date à laquelle la personne rejoint l'asso.</div>
        </div>
      </div>

      <div class="form-cols" id="contract-dates-row" style="display: none;">
        <div class="form-row">
          <label class="form-label">Début du contrat</label>
          <input type="date" name="contract_start_date" class="form-input-lg"
                 value="<?= h(old_val('contract_start_date')) ?>">
        </div>
        <div class="form-row">
          <label class="form-label">Fin du contrat</label>
          <input type="date" name="contract_end_date" class="form-input-lg"
                 value="<?= h(old_val('contract_end_date')) ?>">
        </div>
      </div>

      <div class="form-row" id="org-name-row" style="display: none;">
        <label class="form-label">Organisation d'origine</label>
        <input type="text" name="organization_name" class="form-input-lg" maxlength="200"
               value="<?= h(old_val('organization_name')) ?>"
               placeholder="Ex : Mairie de Ris-Orangis, DRAC Île-de-France…">
        <div class="form-hint">Visible dans les listes pour identifier l'institution.</div>
      </div>
    </div>

    <!-- Capacités additionnelles -->
    <div class="form-section" id="capabilities-section">
      <h2 class="form-section-title">⚡ Capacités additionnelles</h2>
      <p class="form-section-desc">
        Les capacités débloquent des fonctionnalités supplémentaires dans la sidebar. Elles s'ajoutent au rôle.
        <br>💡 <em>Les capacités standard du rôle sont pré-cochées. Tu peux ajouter des capacités à un membre lambda par exemple.</em>
      </p>

      <div class="capabilities-grid">
        <label class="cap-option">
          <input type="checkbox" name="can_create_projects" value="1" <?= old_val('can_create_projects') ? 'checked' : '' ?>>
          <div>
            <div class="cap-name">📁 Créer des projets</div>
            <div class="cap-desc">Peut créer de nouveaux projets et dossiers</div>
          </div>
        </label>

        <label class="cap-option">
          <input type="checkbox" name="can_manage_members" value="1" <?= old_val('can_manage_members') ? 'checked' : '' ?>>
          <div>
            <div class="cap-name">👥 Gérer les adhérents</div>
            <div class="cap-desc">Créer, modifier les fiches adhérents</div>
          </div>
        </label>

        <label class="cap-option">
          <input type="checkbox" name="can_manage_finances" value="1" <?= old_val('can_manage_finances') ? 'checked' : '' ?>>
          <div>
            <div class="cap-name">💰 Trésorerie</div>
            <div class="cap-desc">Voir toutes les factures, valider les dépenses</div>
          </div>
        </label>

        <label class="cap-option">
          <input type="checkbox" name="can_access_marketing" value="1" <?= old_val('can_access_marketing') ? 'checked' : '' ?>>
          <div>
            <div class="cap-name">📢 Communication</div>
            <div class="cap-desc">Accès à la page Communication (bientôt)</div>
          </div>
        </label>

        <label class="cap-option">
          <input type="checkbox" name="can_manage_events" value="1" <?= old_val('can_manage_events') ? 'checked' : '' ?>>
          <div>
            <div class="cap-name">📅 Gérer les événements</div>
            <div class="cap-desc">Créer, modifier, supprimer des événements</div>
          </div>
        </label>

        <label class="cap-option">
          <input type="checkbox" name="can_moderate_messages" value="1" <?= old_val('can_moderate_messages') ? 'checked' : '' ?>>
          <div>
            <div class="cap-name">💬 Modérer les messages</div>
            <div class="cap-desc">Supprimer messages, gérer les canaux</div>
          </div>
        </label>
      </div>
    </div>

    <!-- Mot de passe initial -->
    <div class="form-section">
      <h2 class="form-section-title">🔑 Mot de passe initial</h2>
      <p class="form-section-desc">L'utilisateur pourra le changer après sa première connexion.</p>

      <div class="form-row">
        <label class="form-label">Mot de passe temporaire</label>
        <div style="display: flex; gap: 10px; align-items: center;">
          <input type="text" name="initial_password" id="initial_password" class="form-input-lg"
                 maxlength="60" placeholder="Laisse vide pour en générer un automatiquement"
                 style="flex: 1;">
          <button type="button" class="btn btn-ghost" onclick="generatePassword()">🎲 Générer</button>
        </div>
        <div class="form-hint">Si vide, Assokit génère automatiquement un mot de passe type "cafe-4523". Il te sera affiché APRÈS la création pour que tu puisses le communiquer à la personne.</div>
      </div>

      <div class="form-row">
        <label style="display: inline-flex; align-items: center; gap: 8px; cursor: pointer; font-size: 13.5px;">
          <input type="checkbox" name="must_change_password" value="1" checked>
          <span>Forcer le changement de mot de passe à la première connexion</span>
        </label>
        <div class="form-hint">💡 Recommandé. La personne devra choisir son propre mot de passe.</div>
      </div>
    </div>

    <!-- Notes admin -->
    <div class="form-section">
      <h2 class="form-section-title">📝 Notes internes (optionnel)</h2>
      <p class="form-section-desc">Visibles uniquement par les admins. L'utilisateur ne les voit jamais.</p>

      <div class="form-row">
        <textarea name="notes_admin" class="form-textarea-lg" rows="3" maxlength="2000"
                  placeholder="Ex : Stagiaire master 2 Info-Com. Rattachée à Sophie. À former sur le scan de factures."><?= h(old_val('notes_admin')) ?></textarea>
      </div>
    </div>

    <!-- Actions -->
    <div class="form-actions">
      <div class="form-actions-left"><span class="required">*</span> Champs obligatoires</div>
      <div class="form-actions-right">
        <a href="/admin" class="btn btn-ghost">Annuler</a>
        <button type="submit" class="btn btn-primary">Créer le compte</button>
      </div>
    </div>
  </form>

  <?php endif; // === [PACK 6.2] fin else !$plan_limit_reached === ?>

</main>

<style>
/* Role picker */
.role-picker { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 10px; }
.role-option { display: block; padding: 14px 16px; background: var(--bg); border: 2px solid var(--border); border-radius: 12px; cursor: pointer; transition: all 0.15s ease; }
.role-option input { display: none; }
.role-option:hover { border-color: var(--border-strong); }
.role-option.active { border-color: var(--acc); background: var(--acc-light); }
.role-option-label { font-size: 13.5px; font-weight: 500; margin-bottom: 3px; }
.role-option-desc { font-size: 11.5px; color: var(--ink-3); line-height: 1.4; }

/* Capacités */
.capabilities-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 10px; }
.cap-option { display: flex; gap: 10px; align-items: flex-start; padding: 12px 14px; background: var(--bg); border: 1px solid var(--border); border-radius: 10px; cursor: pointer; transition: background 0.12s ease; }
.cap-option:hover { background: var(--bg-2); }
.cap-option input { margin-top: 3px; flex-shrink: 0; }
.cap-name { font-size: 13px; font-weight: 500; margin-bottom: 2px; }
.cap-desc { font-size: 11.5px; color: var(--ink-3); line-height: 1.4; }
</style>

<script>
// Auto-sélection des capacités selon le rôle choisi
function updateRoleSelection(radio) {
  document.querySelectorAll('.role-option').forEach(function(opt) { opt.classList.remove('active'); });
  radio.closest('.role-option').classList.add('active');

  // Définir les capacités par défaut selon le rôle
  var defaults = {
    'admin':       { projects: 1, members: 1, finances: 1, marketing: 1, events: 1, moderate: 1 },
    'coordinator': { projects: 1, members: 0, finances: 0, marketing: 1, events: 1, moderate: 1 },
    'referent':    { projects: 1, members: 0, finances: 0, marketing: 0, events: 0, moderate: 0 },
    'member':      { projects: 0, members: 0, finances: 0, marketing: 0, events: 0, moderate: 0 },
    'follower':    { projects: 0, members: 0, finances: 0, marketing: 0, events: 0, moderate: 0 },
  };
  var caps = defaults[radio.value] || defaults['member'];
  document.querySelector('input[name="can_create_projects"]').checked = !!caps.projects;
  document.querySelector('input[name="can_manage_members"]').checked = !!caps.members;
  document.querySelector('input[name="can_manage_finances"]').checked = !!caps.finances;
  document.querySelector('input[name="can_access_marketing"]').checked = !!caps.marketing;
  document.querySelector('input[name="can_manage_events"]').checked = !!caps.events;
  document.querySelector('input[name="can_moderate_messages"]').checked = !!caps.moderate;

  // Montrer/cacher les champs pertinents
  updateContractFieldsFromRole(radio.value);
}

function updateContractFieldsFromRole(role) {
  var orgRow = document.getElementById('org-name-row');
  var capsSection = document.getElementById('capabilities-section');
  if (role === 'follower') {
    orgRow.style.display = '';
    capsSection.style.display = 'none';
    // Pré-sélectionner "external" comme type de contrat
    document.querySelector('select[name="contract_type"]').value = 'external';
    updateContractFields(document.querySelector('select[name="contract_type"]'));
  } else {
    orgRow.style.display = 'none';
    capsSection.style.display = '';
  }
}

function updateContractFields(select) {
  var type = select.value;
  var datesRow = document.getElementById('contract-dates-row');
  var orgRow = document.getElementById('org-name-row');

  // Dates de contrat : utiles pour stagiaire / service civique / salarié / prestataire
  if (['intern', 'civic_service', 'employee', 'contractor'].indexOf(type) >= 0) {
    datesRow.style.display = '';
  } else {
    datesRow.style.display = 'none';
  }

  // Organisation : utile pour external (et déjà géré par le rôle follower)
  if (type === 'external') {
    orgRow.style.display = '';
  }
}

function updateAvatarSelect(radio) {
  document.querySelectorAll('.avatar-swatch').forEach(function(s) { s.style.borderColor = 'transparent'; });
  radio.nextElementSibling.style.borderColor = 'var(--ink)';
}

function generatePassword() {
  var words = ['assos', 'projet', 'bureau', 'cafe', 'paper', 'livre', 'metro', 'radio'];
  var word = words[Math.floor(Math.random() * words.length)];
  var num = Math.floor(1000 + Math.random() * 9000);
  document.getElementById('initial_password').value = word + '-' + num;
}

// Init au chargement
(function() {
  var selectedRole = document.querySelector('input[name="role"]:checked');
  if (selectedRole) updateContractFieldsFromRole(selectedRole.value);
  updateContractFields(document.querySelector('select[name="contract_type"]'));
})();
</script>

<?php render_foot(); ?>
