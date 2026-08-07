<?php
/**
 * ============================================================
 * ASSOKIT — Modifier un adhérent
 * ============================================================
 * URL : /modifier-adherent/{id}
 * Permet à admin/coordinator de modifier un adhérent existant.
 * ============================================================
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes-layout.php';

require_login();

$current = current_user();
$org_id = (int)$current['org_id'];
$id = (int)($_GET['id'] ?? 0);

if ($id <= 0) {
    header('Location: /adherents');
    exit;
}

// Permissions
if (!in_array($current['role'], ['admin', 'coordinator'], true)) {
    http_response_code(403);
    die('Accès refusé — rôle insuffisant.');
}

// Charger l'adherent
$stmt = $pdo->prepare("
    SELECT id, email, first_name, last_name, role, phone, city, avatar_color,
           adhesion_date, adhesion_valid_until, is_active, can_create_projects, can_create_folders
    FROM users
    WHERE id = ? AND org_id = ?
    LIMIT 1
");
$stmt->execute([$id, $org_id]);
$adherent = $stmt->fetch();

if (!$adherent) {
    header('Location: /adherents');
    exit;
}

// Empecher de modifier soi-meme via cette page (rediriger vers profil perso)
if ((int)$adherent['id'] === (int)$current['id']) {
    header('Location: /profil');
    exit;
}

// Empecher un coordinator de modifier un admin
if ($current['role'] === 'coordinator' && $adherent['role'] === 'admin') {
    http_response_code(403);
    die('Seul un administrateur peut modifier un autre administrateur.');
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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && check_csrf($_POST['csrf_token'] ?? '')) {
    $form['first_name'] = trim($_POST['first_name'] ?? '');
    $form['last_name']  = trim($_POST['last_name'] ?? '');
    $form['email']      = trim($_POST['email'] ?? '');
    $form['phone']      = trim($_POST['phone'] ?? '');
    $form['city']       = trim($_POST['city'] ?? '');
    $form['role']       = $_POST['role'] ?? 'member';
    $form['is_active']  = isset($_POST['is_active']) ? '1' : '0';
    $form['can_create_projects'] = isset($_POST['can_create_projects']) ? '1' : '0';
    $form['can_create_folders'] = isset($_POST['can_create_folders']) ? '1' : '0';
    $form['adhesion_date'] = trim($_POST['adhesion_date'] ?? '');
    $form['adhesion_valid_until'] = trim($_POST['adhesion_valid_until'] ?? '');

    $valid_roles = ['admin', 'coordinator', 'referent', 'member', 'follower'];
    if (!in_array($form['role'], $valid_roles, true)) $form['role'] = 'member';

    // Un coordinator ne peut pas promouvoir en admin
    if ($current['role'] === 'coordinator' && $form['role'] === 'admin') {
        $form['role'] = $adherent['role'];
    }

    // Validation
    if ($form['first_name'] === '' || $form['last_name'] === '') {
        $error = 'Le prénom et le nom sont obligatoires.';
    } elseif (!filter_var($form['email'], FILTER_VALIDATE_EMAIL)) {
        $error = 'Email invalide.';
    } else {
        // Verifier email doublon (sauf si c'est le sien)
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ? LIMIT 1");
        $stmt->execute([$form['email'], $id]);
        if ($stmt->fetch()) {
            $error = 'Un autre utilisateur utilise déjà cet email.';
        } else {
            try {
                $stmt = $pdo->prepare("
                    UPDATE users SET
                        first_name = ?, last_name = ?, email = ?,
                        phone = ?, city = ?, role = ?,
                        is_active = ?, can_create_projects = ?, can_create_folders = ?,
                        adhesion_date = ?, adhesion_valid_until = ?
                    WHERE id = ? AND org_id = ?
                ");
                $stmt->execute([
                    $form['first_name'], $form['last_name'], $form['email'],
                    $form['phone'] ?: null, $form['city'] ?: null, $form['role'],
                    (int)$form['is_active'], (int)$form['can_create_projects'], (int)$form['can_create_folders'],
                    $form['adhesion_date'] ?: null, $form['adhesion_valid_until'] ?: null,
                    $id, $org_id,
                ]);

                $_SESSION['flash_adherent'] = [
                    'type' => 'success',
                    'message' => 'Les modifications ont été enregistrées avec succès.',
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
      <h1 class="page-title">✏️ Modifier <?= h($adherent['first_name'] . ' ' . $adherent['last_name']) ?></h1>
      <div class="page-sub">Modifiez les informations de cet adhérent.</div>
    </div>
  </div>

  <?php if ($error): ?>
    <div class="alert alert-error">
      <span>⚠️</span>
      <div><?= h($error) ?></div>
    </div>
  <?php endif; ?>

  <form method="POST" action="/modifier-adherent/<?= (int)$id ?>"
        style="background:var(--bg); border:1px solid var(--border); border-radius:12px; padding:24px; max-width:760px;">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">

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
      <input type="email" id="email" name="email" required maxlength="200"
             value="<?= h($form['email']) ?>"
             style="width:100%; padding:10px 12px; background:var(--bg); border:1px solid var(--border-strong); border-radius:8px; font-family:inherit; font-size:14px; color:var(--ink);">
      <div style="font-size:11.5px; color:var(--ink-4); margin-top:4px;">
        💡 Modifier l'email changera aussi l'identifiant de connexion de cet adhérent.
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
        <option value="coordinator" <?= $form['role'] === 'coordinator' ? 'selected' : '' ?>>🧭 Coordinateur — Encadrement</option>
        <?php if ($current['role'] === 'admin'): ?>
          <option value="admin" <?= $form['role'] === 'admin' ? 'selected' : '' ?>>🛡️ Administrateur — Accès total</option>
        <?php endif; ?>
        <option value="follower" <?= $form['role'] === 'follower' ? 'selected' : '' ?>>👀 Suiveur — Accès lecture limitée</option>
      </select>
    </div>
    <div style="margin-bottom:16px; padding:12px; background:var(--bg-2); border:1px solid var(--border); border-radius:10px;">
      <label style="display:flex; align-items:flex-start; gap:10px; cursor:pointer;">
        <input type="checkbox" name="can_create_projects" value="1" <?= $form['can_create_projects'] === '1' ? 'checked' : '' ?> style="margin-top:2px;">
        <div>
          <div style="font-size:13px; font-weight:500;">📁 Peut créer des projets</div>
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
          <div style="font-size:13px; font-weight:500;">🗂️ Peut créer des dossiers dans projets</div>
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
          <div style="font-size:13px; font-weight:500;">✅ Compte actif</div>
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
