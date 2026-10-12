<?php
/**
 * ============================================================
 * ASSOKIT — Modifier un adhérent
 * ============================================================
 * URL : /modifier-adherent/{id}
 * Permet à admin/coordinator (ou à qui a le droit « Gérer les adhérents »)
 * de modifier un adhérent existant.
 * ============================================================
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/demo-guard.php';
require_once __DIR__ . '/includes-layout.php';
@require_once __DIR__ . '/plan-helpers.php';

require_login();

$current = current_user();
$org_id = (int)$current['org_id'];
if ($org_id <= 0) { header('Location: /dashboard'); exit; }  // compte sans association (agent de collectivité) : pas d'« org 0 » partagée
$id = (int)($_GET['id'] ?? 0);

if ($id <= 0) {
    header('Location: /adherents');
    exit;
}

// Permissions : admin, coordinateur, ou droit « Gérer les adhérents » donné par l'admin
// (jamais un suiveur, financeur externe). Rôles qu'il ne peut ni modifier ni attribuer :
// admin pour un coordinateur ; admin et coordinateur pour un titulaire du seul droit.
$role_me = (string)($current['role'] ?? '');
$is_manager_role = in_array($role_me, ['admin', 'coordinator'], true);
if (!$is_manager_role && ($role_me === 'follower' || !function_exists('can') || !can('manage_members'))) {
    http_response_code(403);
    die('Accès refusé — rôle insuffisant.');
}
$roles_reserved = $role_me === 'admin' ? [] : ($role_me === 'coordinator' ? ['admin'] : ['admin', 'coordinator']);

// Charger l'adherent (hors corbeille : un compte supprimé se restaure d'abord depuis la corbeille)
$stmt = $pdo->prepare("
    SELECT id, email, first_name, last_name, role, phone, city, avatar_color,
           adhesion_date, adhesion_valid_until, is_active, can_create_projects, can_create_folders
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

// Empecher de modifier soi-meme via cette page : son propre compte se gère dans Paramètres
if ((int)$adherent['id'] === (int)$current['id']) {
    header('Location: /parametres');
    exit;
}

// Compte plateforme (fondateur / super admin) : jamais modifiable depuis une asso
$plat = ak_platform_flags(['id' => (int)$adherent['id']]);
if (!empty($plat['is_founder']) || !empty($plat['is_super_admin']) || in_array($adherent['role'], ['super_admin', 'founder'], true)) {
    http_response_code(403);
    die('Ce compte ne peut pas être modifié depuis cette page.');
}

// Empecher un coordinator de modifier un admin (et un simple gestionnaire, un admin ou un coordinateur)
if (in_array($adherent['role'], $roles_reserved, true)) {
    http_response_code(403);
    die($adherent['role'] === 'admin'
        ? 'Seul un administrateur peut modifier un autre administrateur.'
        : 'Seul un administrateur ou un coordinateur peut modifier un coordinateur.');
}

$error = null;

// Pre-remplir le form
$form = [
    'first_name' => $adherent['first_name'] ?? '',
    'last_name'  => $adherent['last_name'] ?? '',
    'email'      => $adherent['email'] ?? '',
    'phone'      => $adherent['phone'] ?? '',
    'city'       => $adherent['city'] ?? '',
    'role'       => $adherent['role'] ?? 'member',
    'is_active'  => $adherent['is_active'] ? '1' : '0',
    'can_create_projects' => $adherent['can_create_projects'] ? '1' : '0',
    'can_create_folders' => $adherent['can_create_folders'] ? '1' : '0',
    'adhesion_date' => $adherent['adhesion_date'] ?? '',
    'adhesion_valid_until' => $adherent['adhesion_valid_until'] ?? '',
];

$is_post = $_SERVER['REQUEST_METHOD'] === 'POST';
$csrf_ok = $is_post && check_csrf($_POST['csrf_token'] ?? '');

if ($is_post) {
    // Saisie relue même si le jeton a expiré : elle est réaffichée au lieu d'être perdue
    $form['first_name'] = trim($_POST['first_name'] ?? '');
    $form['last_name']  = trim($_POST['last_name'] ?? '');
    $form['email']      = trim($_POST['email'] ?? '');
    $form['phone']      = trim($_POST['phone'] ?? '');
    $form['city']       = trim($_POST['city'] ?? '');
    $form['role']       = (string)($_POST['role'] ?? 'member');
    $form['is_active']  = isset($_POST['is_active']) ? '1' : '0';
    $form['can_create_projects'] = isset($_POST['can_create_projects']) ? '1' : '0';
    $form['can_create_folders'] = isset($_POST['can_create_folders']) ? '1' : '0';
    $form['adhesion_date'] = trim($_POST['adhesion_date'] ?? '');
    $form['adhesion_valid_until'] = trim($_POST['adhesion_valid_until'] ?? '');

    // Seul un admin change l'adresse email d'un compte (sinon : changement d'email puis
    // « mot de passe oublié » = prise de contrôle du compte)
    if (($current['role'] ?? '') !== 'admin') $form['email'] = $adherent['email'];

    $valid_roles = ['admin', 'coordinator', 'referent', 'member', 'follower'];
    if (!in_array($form['role'], $valid_roles, true)) $form['role'] = 'member';

    // Un coordinator ne peut pas promouvoir en admin (ni un simple gestionnaire en coordinateur)
    if (in_array($form['role'], $roles_reserved, true)) {
        $form['role'] = $adherent['role'];
    }
}

if ($is_post && !$csrf_ok) {
    $error = 'Votre session a expiré : vos modifications n’ont pas été enregistrées. Vérifiez-les puis cliquez à nouveau sur « Enregistrer ».';
} elseif ($csrf_ok) {
    // Validation
    if (ak_demo_protected_account($id)) {
        $error = ak_demo_message();
    } elseif ($form['first_name'] === '' || $form['last_name'] === '') {
        $error = 'Le prénom et le nom sont obligatoires.';
    } elseif (!filter_var($form['email'], FILTER_VALIDATE_EMAIL)) {
        $error = 'Email invalide.';
    } elseif (!$adherent['is_active'] && $form['is_active'] === '1' && function_exists('ak_can_add_adherent')
              && empty(($plan_check = ak_can_add_adherent($pdo, $org_id))['ok'])) {
        // Réactiver un compte le remet dans le décompte du plan, comme un ajout ou une restauration.
        $error = 'Impossible de réactiver ce compte : ' . ($plan_check['reason'] ?? 'limite d’adhérents atteinte')
               . '. Passez au plan supérieur ou désactivez un autre adhérent.';
    } else {
        // Verifier email doublon (sauf si c'est le sien)
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ? LIMIT 1");
        $stmt->execute([$form['email'], $id]);
        if ($stmt->fetch()) {
            $error = 'Un autre utilisateur utilise déjà cet email.';
        } else {
            try {
                // Changement de rôle : droits par défaut du nouveau rôle, même matrice qu'à la
                // création (nouveau-adherent.php). Un coordinateur rétrogradé « Membre » perd ainsi
                // Diffusion, Prospection, Événements… ; un membre promu coordinateur les obtient.
                // Rôle inchangé : les réglages fins faits dans Paramètres › Utilisateurs sont gardés.
                // « Peut créer des projets » : la case l'emporte si elle a été changée dans ce même envoi.
                $perm_sql = '';
                $perm_vals = [];
                $can_projects = (int)$form['can_create_projects'];
                if ($form['role'] !== $adherent['role']) {
                    $perms = match($form['role']) {
                        'admin'       => [1, 1, 1, 1, 1, 1],
                        'coordinator' => [1, 1, 0, 1, 1, 1],
                        'referent'    => [1, 0, 0, 0, 1, 0],
                        default       => [0, 0, 0, 0, 0, 0],
                    };
                    if ($form['can_create_projects'] === ($adherent['can_create_projects'] ? '1' : '0')) {
                        $can_projects = $perms[0];
                    }
                    $perm_sql = ', can_manage_members = ?, can_manage_finances = ?, can_access_marketing = ?,
                                   can_manage_events = ?, can_moderate_messages = ?';
                    $perm_vals = array_slice($perms, 1);
                }

                $stmt = $pdo->prepare("
                    UPDATE users SET
                        first_name = ?, last_name = ?, email = ?,
                        phone = ?, city = ?, role = ?,
                        is_active = ?, can_create_projects = ?, can_create_folders = ?,
                        adhesion_date = ?, adhesion_valid_until = ?
                        $perm_sql
                    WHERE id = ? AND org_id = ? AND deleted_at IS NULL
                ");
                $stmt->execute([
                    $form['first_name'], $form['last_name'], $form['email'],
                    $form['phone'] ?: null, $form['city'] ?: null, $form['role'],
                    (int)$form['is_active'], $can_projects, (int)$form['can_create_folders'],
                    $form['adhesion_date'] ?: null, $form['adhesion_valid_until'] ?: null,
                    ...$perm_vals,
                    $id, $org_id,
                ]);

                // Adresse de connexion changée ou compte désactivé : les liens d'invitation et de
                // « mot de passe oublié » déjà envoyés ne servent plus (ils donneraient la main sur le
                // compte à qui lit l'ancienne boîte), comme dans Paramètres › Utilisateurs.
                $email_changed = strcasecmp($form['email'], (string)$adherent['email']) !== 0;
                if ($email_changed || ((int)$adherent['is_active'] === 1 && $form['is_active'] === '0')) {
                    try {
                        $pdo->prepare("UPDATE user_password_tokens SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL")
                            ->execute([$id]);
                    } catch (Throwable $e) {
                        error_log('[MODIFIER-ADHERENT] jetons : ' . $e->getMessage());
                    }
                }
                // L'ancienne adresse est prévenue et la nouvelle doit être confirmée
                if ($email_changed) {
                    require_once __DIR__ . '/password-token-helper.php';
                    ak_account_email_changed($pdo, $id, (string)$adherent['email'], $form['email'], $form['first_name'], true);
                }

                $_SESSION['flash_adherent'] = [
                    'type' => 'success',
                    'message' => 'Les modifications ont été enregistrées avec succès.'
                        . ($perm_sql !== '' ? ' Les droits ont été remis à ceux du rôle « ' . role_label($form['role']) . ' ».' : ''),
                ];
                header('Location: /adherent?id=' . $id);
                exit;

            } catch (Throwable $e) {
                error_log('[MODIFIER-ADHERENT] ' . $e->getMessage());
                $error = 'Une erreur technique est survenue. Merci de réessayer.';
            }
        }
    }
}

render_head('Modifier ' . $adherent['first_name']);
render_sidebar('adherents');
?>

<div class="main">

  <nav class="crumbs">
    <a href="/dashboard">Dashboard</a>
    <span class="sep">›</span>
    <a href="/adherents">Adhérents</a>
    <span class="sep">›</span>
    <a href="/adherent?id=<?= (int)$id ?>"><?= h($adherent['first_name'] . ' ' . $adherent['last_name']) ?></a>
    <span class="sep">›</span>
    <span class="current">Modifier</span>
  </nav>

  <div class="main-head">
    <div>
      <h1 class="page-title" style="display:flex; align-items:center; gap:11px;"><?= ak_icon_badge('edit','#059669',36) ?><span>Modifier <?= h($adherent['first_name'] . ' ' . $adherent['last_name']) ?></span></h1>
      <div class="page-sub">Modifiez les informations de cet adhérent.</div>
    </div>
  </div>

  <?php if ($error): ?>
    <div class="alert alert-error">
      <span><?= ak_icon('alert-tri',16) ?></span>
      <div><?= h($error) ?></div>
    </div>
  <?php endif; ?>

  <form method="POST" action="/modifier-adherent/<?= (int)$id ?>"
        style="background:var(--bg); border:1px solid var(--border); border-radius:12px; padding:24px; max-width:760px;">
    <input type="hidden" name="csrf_token" value="<?= h(function_exists('csrf_token') ? csrf_token() : (empty($_SESSION['csrf_token']) ? ($_SESSION['csrf_token'] = bin2hex(random_bytes(32))) : $_SESSION['csrf_token'])) ?>">

    <!-- Identité -->
    <div style="font-size:11.5px; color:var(--ink-3); font-weight:500; text-transform:uppercase; letter-spacing:0.05em; margin-bottom:10px;">Identité</div>
    <div style="display:grid; grid-template-columns: 1fr 1fr; gap:14px; margin-bottom:16px;">
      <div>
        <label for="first_name" style="display:block; font-size:13px; font-weight:500; margin-bottom:6px;">Prénom *</label>
        <input type="text" id="first_name" name="first_name" required maxlength="100"
               value="<?= h($form['first_name']) ?>"
               style="width:100%; padding:10px 12px; background:var(--bg); border:1px solid var(--border-strong); border-radius:8px; font-family:inherit; font-size:14px; color:var(--ink);">
      </div>
      <div>
        <label for="last_name" style="display:block; font-size:13px; font-weight:500; margin-bottom:6px;">Nom *</label>
        <input type="text" id="last_name" name="last_name" required maxlength="100"
               value="<?= h($form['last_name']) ?>"
               style="width:100%; padding:10px 12px; background:var(--bg); border:1px solid var(--border-strong); border-radius:8px; font-family:inherit; font-size:14px; color:var(--ink);">
      </div>
    </div>

    <!-- Contact -->
    <div style="font-size:11.5px; color:var(--ink-3); font-weight:500; text-transform:uppercase; letter-spacing:0.05em; margin:20px 0 10px;">Contact</div>
    <div style="margin-bottom:14px;">
      <label for="email" style="display:block; font-size:13px; font-weight:500; margin-bottom:6px;">Email *</label>
      <input type="email" id="email" name="email" required maxlength="200"<?= ($current['role'] ?? '') !== 'admin' ? ' readonly title="Seul un administrateur peut changer l’adresse email"' : '' ?>
             value="<?= h($form['email']) ?>"
             style="width:100%; padding:10px 12px; background:var(--bg); border:1px solid var(--border-strong); border-radius:8px; font-family:inherit; font-size:14px; color:var(--ink);">
      <div style="font-size:11.5px; color:var(--ink-4); margin-top:4px; display:flex; align-items:flex-start; gap:5px;">
        <span style="flex:none; margin-top:1px;"><?= ak_icon('lightbulb',13) ?></span>
        <span>Modifier l'email changera aussi l'identifiant de connexion de cet adhérent.</span>
      </div>
    </div>
    <div style="display:grid; grid-template-columns: 1fr 1fr; gap:14px; margin-bottom:16px;">
      <div>
        <label for="phone" style="display:block; font-size:13px; font-weight:500; margin-bottom:6px;">Téléphone</label>
        <input type="tel" id="phone" name="phone" maxlength="30"
               placeholder="06 12 34 56 78"
               value="<?= h($form['phone']) ?>"
               style="width:100%; padding:10px 12px; background:var(--bg); border:1px solid var(--border-strong); border-radius:8px; font-family:inherit; font-size:14px; color:var(--ink);">
      </div>
      <div>
        <label for="city" style="display:block; font-size:13px; font-weight:500; margin-bottom:6px;">Ville</label>
        <input type="text" id="city" name="city" maxlength="100"
               value="<?= h($form['city']) ?>"
               style="width:100%; padding:10px 12px; background:var(--bg); border:1px solid var(--border-strong); border-radius:8px; font-family:inherit; font-size:14px; color:var(--ink);">
      </div>
    </div>

    <!-- Rôle & permissions -->
    <div style="font-size:11.5px; color:var(--ink-3); font-weight:500; text-transform:uppercase; letter-spacing:0.05em; margin:20px 0 10px;">Rôle & permissions</div>
    <div style="margin-bottom:14px;">
      <label for="role" style="display:block; font-size:13px; font-weight:500; margin-bottom:6px;">Rôle *</label>
      <select id="role" name="role" required
              style="width:100%; padding:10px 12px; background:var(--bg); border:1px solid var(--border-strong); border-radius:8px; font-family:inherit; font-size:14px; color:var(--ink);">
        <option value="member" <?= $form['role'] === 'member' ? 'selected' : '' ?>>👤 Membre — Adhérent standard</option>
        <option value="referent" <?= $form['role'] === 'referent' ? 'selected' : '' ?>>🎯 Référent — Responsable de projet</option>
        <?php if (!in_array('coordinator', $roles_reserved, true)): ?>
          <option value="coordinator" <?= $form['role'] === 'coordinator' ? 'selected' : '' ?>>🧭 Coordinateur — Encadrement</option>
        <?php endif; ?>
        <?php if ($current['role'] === 'admin'): ?>
          <option value="admin" <?= $form['role'] === 'admin' ? 'selected' : '' ?>>🛡️ Administrateur — Accès total</option>
        <?php endif; ?>
        <option value="follower" <?= $form['role'] === 'follower' ? 'selected' : '' ?>>👀 Suiveur — Accès lecture limitée</option>
      </select>
      <div style="font-size:11.5px; color:var(--ink-4); margin-top:4px; display:flex; align-items:flex-start; gap:5px;">
        <span style="flex:none; margin-top:1px;"><?= ak_icon('lightbulb',13) ?></span>
        <span>Changer le rôle redonne les droits par défaut de ce rôle (diffusion, événements, finances…).<?php if ($current['role'] === 'admin'): ?>
          Réglage fin des droits : <a href="/admin-modifier-utilisateur/<?= (int)$id ?>" style="color:var(--acc);">gestion des utilisateurs</a>.<?php endif; ?></span>
      </div>
    </div>
    <div style="margin-bottom:16px; padding:12px; background:var(--bg-2); border:1px solid var(--border); border-radius:10px;">
      <label style="display:flex; align-items:flex-start; gap:10px; cursor:pointer;">
        <input type="checkbox" name="can_create_projects" value="1" <?= $form['can_create_projects'] === '1' ? 'checked' : '' ?> style="margin-top:2px;">
        <div>
          <div style="font-size:13px; font-weight:500; display:flex; align-items:center; gap:6px;"><?= ak_icon('folder',14) ?> Peut créer des projets</div>
          <div style="font-size:11.5px; color:var(--ink-3); margin-top:2px;">
            Autorise cet adhérent à créer de nouveaux projets dans les dossiers existants.
          </div>
        </div>
      </label>
    </div>
    <div style="margin-bottom:16px; padding:12px; background:var(--bg-2); border:1px solid var(--border); border-radius:10px;">
      <label style="display:flex; align-items:flex-start; gap:10px; cursor:pointer;">
        <input type="checkbox" name="can_create_folders" value="1" <?= $form['can_create_folders'] === '1' ? 'checked' : '' ?> style="margin-top:2px;">
        <div>
          <div style="font-size:13px; font-weight:500; display:flex; align-items:center; gap:6px;"><?= ak_icon('folder',14) ?> Peut créer des dossiers dans projets</div>
          <div style="font-size:11.5px; color:var(--ink-3); margin-top:2px;">
            Autorise cet adhérent à créer de nouveaux dossiers thématiques pour organiser les projets.
          </div>
        </div>
      </label>
    </div>

    <!-- Adhésion -->
    <div style="font-size:11.5px; color:var(--ink-3); font-weight:500; text-transform:uppercase; letter-spacing:0.05em; margin:20px 0 10px;">Adhésion</div>
    <div style="display:grid; grid-template-columns: 1fr 1fr; gap:14px; margin-bottom:16px;">
      <div>
        <label for="adhesion_date" style="display:block; font-size:13px; font-weight:500; margin-bottom:6px;">Date d'adhésion</label>
        <input type="date" id="adhesion_date" name="adhesion_date"
               value="<?= h($form['adhesion_date']) ?>"
               style="width:100%; padding:10px 12px; background:var(--bg); border:1px solid var(--border-strong); border-radius:8px; font-family:inherit; font-size:14px; color:var(--ink);">
      </div>
      <div>
        <label for="adhesion_valid_until" style="display:block; font-size:13px; font-weight:500; margin-bottom:6px;">Valide jusqu'au</label>
        <input type="date" id="adhesion_valid_until" name="adhesion_valid_until"
               value="<?= h($form['adhesion_valid_until']) ?>"
               style="width:100%; padding:10px 12px; background:var(--bg); border:1px solid var(--border-strong); border-radius:8px; font-family:inherit; font-size:14px; color:var(--ink);">
      </div>
    </div>

    <!-- Statut -->
    <div style="margin-bottom:24px; padding:12px; background:var(--bg-2); border:1px solid var(--border); border-radius:10px;">
      <label style="display:flex; align-items:flex-start; gap:10px; cursor:pointer;">
        <input type="checkbox" name="is_active" value="1" <?= $form['is_active'] === '1' ? 'checked' : '' ?> style="margin-top:2px;">
        <div>
          <div style="font-size:13px; font-weight:500; display:flex; align-items:center; gap:6px;"><?= ak_icon('check-circle',14) ?> Compte actif</div>
          <div style="font-size:11.5px; color:var(--ink-3); margin-top:2px;">
            Décocher pour désactiver le compte (l'adhérent ne pourra plus se connecter).
          </div>
        </div>
      </label>
    </div>

    <div style="display:flex; gap:10px; justify-content:flex-end;">
      <a href="/adherent?id=<?= (int)$id ?>" class="btn btn-ghost">Annuler</a>
      <button type="submit" class="btn btn-primary">💾 Enregistrer les modifications</button>
    </div>
  </form>

</div>

<?php render_foot(); ?>
