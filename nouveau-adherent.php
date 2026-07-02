<?php
/**
 * ============================================================
 * ASSOKIT — Ajouter un adhérent (v3 - token magic link)
 * ============================================================
 * Au lieu de generer un mdp en clair, on :
 *   1. Cree l'utilisateur avec password_hash = ''
 *   2. Genere un token (valide 7 jours)
 *   3. Envoie un email avec lien "Créer mon mot de passe"
 *   4. L'adherent definit lui-meme son mdp
 * ============================================================
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes-layout.php';
@require_once __DIR__ . '/password-token-helper.php';

// === [PACK 6.2] Helpers du système de plans ===
@require_once __DIR__ . '/plan-helpers.php';

require_login();

$user = current_user();
$org_id = (int)$user['org_id'];

if (!in_array($user['role'], ['admin', 'coordinator'], true)) {
    http_response_code(403);
    die('Accès refusé — rôle insuffisant.');
}

// === [PACK 6.2] BLOCAGE STRICT : limite d'adhérents selon le plan ===
$plan_limit_reached = false;
$plan_limit_info = null;
if (function_exists('ak_can_add_adherent')) {
    $check = ak_can_add_adherent($pdo, $org_id);
    if (!$check['ok']) {
        $plan_limit_reached = true;
        $plan_limit_info = $check;
    }
}

$error = null;
$form = [
    'first_name' => '',
    'last_name'  => '',
    'email'      => '',
    'phone'      => '',
    'city'       => '',
    'role'       => 'member',
    'send_email' => '1',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && check_csrf($_POST['csrf_token'] ?? '')) {

    // === [PACK 6.2] Re-check plan au POST (sécurité) ===
    if ($plan_limit_reached) {
        $error = '🚫 ' . ($plan_limit_info['reason'] ?? 'Limite d\'adhérents atteinte') . '. Passez au plan supérieur pour continuer.';
    } else {

    $form['first_name'] = trim($_POST['first_name'] ?? '');
    $form['last_name']  = trim($_POST['last_name'] ?? '');
    $form['email']      = trim($_POST['email'] ?? '');
    $form['phone']      = trim($_POST['phone'] ?? '');
    $form['city']       = trim($_POST['city'] ?? '');
    $form['role']       = $_POST['role'] ?? 'member';
    $form['send_email'] = isset($_POST['send_email']) ? '1' : '0';

    $valid_roles = ['admin', 'coordinator', 'referent', 'member', 'follower'];
    if (!in_array($form['role'], $valid_roles, true)) $form['role'] = 'member';

    if ($form['first_name'] === '' || $form['last_name'] === '') {
        $error = 'Le prénom et le nom sont obligatoires.';
    } elseif (!filter_var($form['email'], FILTER_VALIDATE_EMAIL)) {
        $error = 'Email invalide.';
    } else {
        // Verifier doublon (y compris soft-deleted pour eviter collision)
        $stmt = $pdo->prepare("SELECT id, deleted_at FROM users WHERE email = ? LIMIT 1");
        $stmt->execute([$form['email']]);
        $existing = $stmt->fetch();

        if ($existing && $existing['deleted_at'] === null) {
            $error = 'Un utilisateur avec cet email existe déjà sur la plateforme.';
        } else {
            try {
                // Mot de passe temporaire "placeholder" qui ne marchera jamais pour se connecter
                // L'adherent devra IMPERATIVEMENT utiliser le lien magique
                $placeholder_hash = password_hash(bin2hex(random_bytes(32)), PASSWORD_BCRYPT);

                $colors = ['blue','purple','amber','pink','teal'];
                $avatar_color = $colors[array_rand($colors)];

                $perms = match($form['role']) {
                    'admin'       => [1, 1, 1, 1, 1, 1],
                    'coordinator' => [1, 1, 0, 1, 1, 1],
                    'referent'    => [1, 0, 0, 0, 1, 0],
                    default       => [0, 0, 0, 0, 0, 0],
                };

                $stmt = $pdo->prepare("
                    INSERT INTO users
                        (org_id, role, email, password_hash, first_name, last_name,
                         phone, city, avatar_color, adhesion_date,
                         must_change_password, is_active,
                         can_create_projects, can_manage_members, can_manage_finances,
                         can_access_marketing, can_manage_events, can_moderate_messages,
                         created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, CURDATE(), 1, 1, ?, ?, ?, ?, ?, ?, NOW())
                ");
                $stmt->execute([
                    $org_id, $form['role'], $form['email'], $placeholder_hash,
                    $form['first_name'], $form['last_name'],
                    $form['phone'] ?: null, $form['city'] ?: null, $avatar_color,
                    ...$perms,
                ]);
                $new_id = (int) $pdo->lastInsertId();

                // Generer le token + envoyer l'email
                $email_ok = false;
                if ($form['send_email'] === '1') {
                    try {
                        $token = create_password_token($pdo, $new_id, 'set_password');

                        // Charger nom org
                        $org = $pdo->prepare("SELECT name FROM organizations WHERE id = ?");
                        $org->execute([$org_id]);
                        $org_row = $org->fetch();
                        $org_name = $org_row['name'] ?? 'votre association';

                        $email_ok = send_welcome_email(
                            $form['email'],
                            $form['first_name'],
                            $org_name,
                            $token
                        );
                    } catch (Throwable $e) {
                        error_log('Welcome email error: ' . $e->getMessage());
                    }
                }

                $msg = $form['first_name'] . ' ' . $form['last_name'] . ' a été ajouté(e) avec succès.';
                if ($form['send_email'] === '1') {
                    $msg .= $email_ok
                        ? ' Un email a été envoyé avec un lien de création de mot de passe (valide 7 jours).'
                        : ' ⚠️ L\'email n\'a pas pu être envoyé (vérifier la configuration Resend).';
                } else {
                    $msg .= ' Pensez à lui envoyer le lien de création de mot de passe depuis sa fiche.';
                }

                $_SESSION['flash_adherents'] = [
                    'type' => $email_ok || $form['send_email'] === '0' ? 'success' : 'error',
                    'message' => $msg,
                ];
                header('Location: /adherents');
                exit;

            } catch (Throwable $e) {
                $error = 'Erreur technique : ' . $e->getMessage();
            }
        }
    }
    } // === [PACK 6.2] fin du else "plan_limit_reached" ===
}

render_head('Ajouter un adhérent');
render_sidebar('adherents');
?>

<div class="main">

  <nav class="crumbs">
    <a href="/dashboard">Dashboard</a>
    <span class="sep">›</span>
    <a href="/adherents">Adhérents</a>
    <span class="sep">›</span>
    <span class="current">Ajouter</span>
  </nav>

  <div class="main-head">
    <div>
      <h1 class="page-title">Ajouter un adhérent</h1>
      <div class="page-sub">L'adhérent recevra un email pour créer lui-même son mot de passe (sécurisé ✓).</div>
    </div>
  </div>

  <?php if ($error): ?>
    <div class="alert alert-error">
      <span>⚠️</span>
      <div><?= h($error) ?></div>
    </div>
  <?php endif; ?>

  <?php if ($plan_limit_reached): ?>
    <!-- === [PACK 6.2] Bandeau de blocage strict adhérents === -->
    <div style="background:linear-gradient(135deg, #FEE2E2 0%, #FED7AA 100%); border:2px solid #DC2626; border-radius:14px; padding:24px 28px; margin-bottom:20px; display:flex; gap:18px; align-items:flex-start; flex-wrap:wrap;">
      <div style="font-size:42px; line-height:1; flex-shrink:0;">🔒</div>
      <div style="flex:1; min-width:240px;">
        <h3 style="margin:0 0 8px; font-size:18px; color:#991B1B;">Limite d'adhérents atteinte</h3>
        <p style="margin:0 0 14px; color:#7F1D1D; line-height:1.6; font-size:14px;">
          Vous avez atteint la limite de <strong><?= (int)($plan_limit_info['limit'] ?? 0) ?> adhérents</strong> de votre plan actuel
          (<?= (int)($plan_limit_info['current'] ?? 0) ?> adhérents inscrits).
          <br>Pour continuer à ajouter des adhérents, passez au plan supérieur.
        </p>
        <div style="display:flex; gap:10px; flex-wrap:wrap;">
          <a href="/mon-asso-plan" class="btn btn-primary" style="background:#DC2626; color:white;">↗ Voir les plans</a>
          <a href="/contact?subject=demo&plan=assokit" class="btn btn-ghost">📞 Demander une démo</a>
        </div>
      </div>
    </div>
  <?php else: ?>

  <form method="POST" action="/nouveau-adherent"
        style="background:var(--bg); border:1px solid var(--border); border-radius:12px; padding:24px; max-width:720px;">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">

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

    <div style="margin-bottom:16px;">
      <label for="email" style="display:block; font-size:13px; font-weight:500; margin-bottom:6px;">Email *</label>
      <input type="email" id="email" name="email" required maxlength="200"
             placeholder="prenom.nom@example.com"
             value="<?= h($form['email']) ?>"
             style="width:100%; padding:10px 12px; background:var(--bg); border:1px solid var(--border-strong); border-radius:8px; font-family:inherit; font-size:14px; color:var(--ink);">
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
               placeholder="Paris"
               value="<?= h($form['city']) ?>"
               style="width:100%; padding:10px 12px; background:var(--bg); border:1px solid var(--border-strong); border-radius:8px; font-family:inherit; font-size:14px; color:var(--ink);">
      </div>
    </div>

    <div style="margin-bottom:20px;">
      <label for="role" style="display:block; font-size:13px; font-weight:500; margin-bottom:6px;">Rôle *</label>
      <select id="role" name="role" required
              style="width:100%; padding:10px 12px; background:var(--bg); border:1px solid var(--border-strong); border-radius:8px; font-family:inherit; font-size:14px; color:var(--ink);">
        <option value="member" <?= $form['role'] === 'member' ? 'selected' : '' ?>>👤 Membre — Adhérent standard</option>
        <option value="referent" <?= $form['role'] === 'referent' ? 'selected' : '' ?>>🎯 Référent — Responsable de projet</option>
        <option value="coordinator" <?= $form['role'] === 'coordinator' ? 'selected' : '' ?>>🧭 Coordinateur — Encadrement</option>
        <?php if ($user['role'] === 'admin'): ?>
          <option value="admin" <?= $form['role'] === 'admin' ? 'selected' : '' ?>>🛡️ Administrateur — Accès total</option>
        <?php endif; ?>
        <option value="follower" <?= $form['role'] === 'follower' ? 'selected' : '' ?>>👀 Suiveur — Accès lecture limitée</option>
      </select>
    </div>

    <div style="margin-bottom:24px; padding:14px; background:rgba(5, 150, 105, 0.06); border:1px solid rgba(5, 150, 105, 0.2); border-radius:10px;">
      <label style="display:flex; align-items:flex-start; gap:10px; cursor:pointer;">
        <input type="checkbox" name="send_email" value="1" <?= $form['send_email'] === '1' ? 'checked' : '' ?>
               style="margin-top:2px;">
        <div>
          <div style="font-size:13px; font-weight:500;">📧 Envoyer un email avec lien de création de mot de passe</div>
          <div style="font-size:11.5px; color:var(--ink-3); margin-top:3px; line-height:1.5;">
            🔐 <strong>Plus sécurisé :</strong> l'adhérent définit lui-même son mot de passe via un lien unique valide 7 jours.<br>
            Aucun mot de passe n'est transmis par email.
          </div>
        </div>
      </label>
    </div>

    <div style="display:flex; gap:10px; justify-content:flex-end;">
      <a href="/adherents" class="btn btn-ghost">Annuler</a>
      <button type="submit" class="btn btn-primary">+ Créer l'adhérent</button>
    </div>
  </form>

  <?php endif; // === [PACK 6.2] fin else !$plan_limit_reached === ?>

</div>

<?php render_foot(); ?>
