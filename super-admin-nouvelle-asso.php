<?php
/**
 * super-admin-nouvelle-asso.php — Créer une nouvelle association (vPro)
 * ======================================================================
 * Version avec le nouveau layout Super Admin (sidebar, dark mode).
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes-layout.php';
require_once __DIR__ . '/superadmin-layout.php';
require_once __DIR__ . '/sa-permissions.php';
@require_once __DIR__ . '/resend-helper.php';
require_login();
$user = sa_require_super_admin();

// Legacy desactive : creation via la page Fondateur moderne (asso_subscriptions/plan_id)
header('Location: /fondateur-create-organization'); exit;
$ctx = sa_get_permissions_context();

$error = null;
$success_data = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (!function_exists('check_csrf') || check_csrf($_POST['csrf_token'] ?? ''))) {

    $org_name   = trim($_POST['org_name'] ?? '');
    $org_email  = trim($_POST['org_email'] ?? '');
    $org_plan   = $_POST['org_plan'] ?? 'essentiel';
    $org_status = $_POST['org_status'] ?? 'trial';
    $trial_days = (int) ($_POST['trial_days'] ?? 30);
    $admin_first= trim($_POST['admin_first'] ?? '');
    $admin_last = trim($_POST['admin_last'] ?? '');
    $admin_email= trim($_POST['admin_email'] ?? '');

    if ($org_name === '' || $admin_first === '' || $admin_last === '' || $admin_email === '') {
        $error = 'Tous les champs obligatoires doivent être remplis.';
    } elseif (!filter_var($admin_email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Email admin invalide.';
    } elseif ($org_email !== '' && !filter_var($org_email, FILTER_VALIDATE_EMAIL)) {
        $error = "Email de l'asso invalide.";
    } else {
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$admin_email]);
        if ($stmt->fetch()) {
            $error = "L'email $admin_email est déjà utilisé.";
        }
    }

    if (!$error) {
        try {
            $pdo->beginTransaction();

            // 1. Créer l'organisation
            $trial_ends = ($org_status === 'trial')
                ? date('Y-m-d H:i:s', strtotime("+$trial_days days"))
                : null;

            // 🔧 FIX BUG : la colonne s'appelle 'billing_email' (pas 'email') dans organizations
            // Génération automatique du slug à partir du nom
            $org_slug = strtolower(trim(preg_replace('/[^a-z0-9-]+/i', '-', $org_name), '-'));
            // S'assurer de l'unicité du slug
            $base_slug = $org_slug ?: 'asso';
            $i = 1;
            $check_slug = $pdo->prepare("SELECT id FROM organizations WHERE slug = ? LIMIT 1");
            $check_slug->execute([$base_slug]);
            while ($check_slug->fetch()) {
                $i++;
                $base_slug = ($org_slug ?: 'asso') . '-' . $i;
                $check_slug->execute([$base_slug]);
            }
            $org_slug = $base_slug;

            $stmt = $pdo->prepare("
                INSERT INTO organizations
                    (name, slug, billing_email, status, plan, created_by_user_id, trial_ends_at,
                     validation_status, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            // Fondateur : creation directement validee
            // Super Admin non-fondateur : en attente validation Fondateur
            $validation_status = $ctx['is_founder'] ? 'validated' : 'pending_founder';
            $stmt->execute([
                $org_name, $org_slug, $org_email ?: null, $org_status, $org_plan,
                (int) $user['id'], $trial_ends, $validation_status,
            ]);
            $new_org_id = (int) $pdo->lastInsertId();

            // Si SA non-fondateur → notifier tous les Fondateurs in-app + email
            if (!$ctx['is_founder']) {
                sa_notify_all_founders(
                    'new_org_pending',
                    'Nouvelle association à valider',
                    $user['first_name'] . ' ' . $user['last_name'] . ' a créé "' . $org_name . '" (en attente de validation).',
                    '/super-admin/associations?id=' . $new_org_id,
                    $new_org_id
                );
                // Email fondateur
                if (function_exists('send_transactional_email')) {
                    try {
                        $stmt_f = $pdo->query("SELECT email, first_name FROM users WHERE is_founder = 1 AND is_active = 1");
                        while ($f = $stmt_f->fetch(PDO::FETCH_ASSOC)) {
                            send_transactional_email(
                                $f['email'],
                                '🏗️ Nouvelle association à valider — ' . $org_name,
                                email_wrap('Validation requise',
                                    '<h1 style="font-size:20px;margin:0 0 10px;font-weight:500;letter-spacing:-0.01em;color:#1C1917">Nouvelle association à valider</h1>
                                    <p>Bonjour ' . h($f['first_name']) . ',</p>
                                    <p><strong>' . h($user['first_name'] . ' ' . $user['last_name']) . '</strong> (Super Admin) vient de créer l\'association <strong>' . h($org_name) . '</strong>.</p>
                                    <p>Elle est en attente de votre validation avant d\'être pleinement active.</p>',
                                    'Examiner cette association',
                                    'https://assokit.fr/super-admin/associations?id=' . $new_org_id
                                ),
                                ['tag' => 'org_pending_validation']
                            );
                        }
                    } catch (Throwable $e) {
                        error_log('Pending org email: ' . $e->getMessage());
                    }
                }
            }

            // 2. Mot de passe temporaire
            $temp_password = sa_generate_temp_password();
            $password_hash = password_hash($temp_password, PASSWORD_BCRYPT);

            // 3. Créer l'admin
            $stmt = $pdo->prepare("
                INSERT INTO users
                    (org_id, role, contract_type, email, password_hash,
                     first_name, last_name, must_change_password, is_active,
                     can_create_projects, can_manage_members, can_manage_finances,
                     can_access_marketing, can_manage_events, can_moderate_messages,
                     created_at)
                VALUES (?, 'admin', 'volunteer', ?, ?, ?, ?, 1, 1,
                        1, 1, 1, 1, 1, 1, NOW())
            ");
            $stmt->execute([$new_org_id, $admin_email, $password_hash, $admin_first, $admin_last]);
            $new_admin_id = (int) $pdo->lastInsertId();

            // 4. Créer une subscription par défaut
            $price_ht = match($org_plan) {
                'association' => 40.83,  // 49 TTC / 1.20
                'organisation' => 0,     // Sur mesure
                default => 0,
            };
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO subscriptions
                        (org_id, plan, price_ht, tva_rate, billing_cycle, status, started_at, current_period_start, current_period_end)
                    VALUES (?, ?, ?, 20.00, 'monthly', ?, CURDATE(), DATE_FORMAT(CURDATE(), '%Y-%m-01'), LAST_DAY(CURDATE()))
                ");
                $stmt->execute([$new_org_id, $org_plan, $price_ht, $org_status]);
            } catch (Throwable $e) {
                // Table subscription pas encore créée, on ignore
            }

            // 5. Log
            sa_log_action((int) $user['id'], 'create_org', $new_org_id, $new_admin_id, [
                'org_name' => $org_name,
                'plan' => $org_plan,
                'status' => $org_status,
                'admin_email' => $admin_email,
            ]);

            $pdo->commit();

            // Email de bienvenue via Resend (silencieux en cas d'erreur)
            if (function_exists('send_transactional_email')) {
                try {
                    send_transactional_email(
                        $admin_email,
                        'Bienvenue sur Assokit — ' . $org_name,
                        render_email_welcome([
                            'first_name' => $admin_first,
                            'last_name' => $admin_last,
                            'email' => $admin_email,
                            'temp_password' => $temp_password,
                            'org_name' => $org_name,
                            'is_super_admin' => false,
                        ]),
                        ['tag' => 'welcome_admin']
                    );
                } catch (Throwable $e) {
                    error_log('Welcome email fail: ' . $e->getMessage());
                }
            }

            $success_data = [
                'org_name'      => $org_name,
                'org_id'        => $new_org_id,
                'admin_name'    => $admin_first . ' ' . $admin_last,
                'admin_email'   => $admin_email,
                'temp_password' => $temp_password,
                'is_pending'    => !$ctx['is_founder'],
            ];

        } catch (Throwable $e) {
            $pdo->rollBack();
            $error = 'Erreur création : ' . $e->getMessage();
        }
    }
}

sa_render_head('Nouvelle association');
sa_render_sidebar('associations');
?>

<div class="sa-breadcrumb">
    <a href="/super-admin">Dashboard</a>
    <span class="sep">›</span>
    <a href="/super-admin/associations">Associations</a>
    <span class="sep">›</span>
    Nouvelle
</div>

<div class="sa-page-head">
    <div>
        <h1 class="sa-page-title">➕ Créer une nouvelle association</h1>
        <div class="sa-page-sub">Création de l'asso + premier admin avec mot de passe temporaire</div>
    </div>
</div>

<?php if ($error): ?>
    <div class="sa-alert sa-alert-error">⚠️ <?= h($error) ?></div>
<?php endif; ?>

<?php if ($success_data): ?>

    <div class="sa-card" style="background:rgba(16, 185, 129, 0.08); border-color:rgba(16, 185, 129, 0.3); margin-bottom:16px;">
        <div style="font-size:18px; font-weight:600; color:#6EE7B7; margin-bottom:8px;">
            ✅ Association créée avec succès
        </div>
        <div style="color:var(--sa-ink-2); margin-bottom:12px;">
            <strong style="color:var(--sa-ink)"><?= h($success_data['org_name']) ?></strong>
            a été créée. Voici les identifiants du premier admin :
        </div>

        <?php if (!empty($success_data['is_pending'])): ?>
            <div class="sa-alert" style="background:rgba(251, 191, 36, 0.08); border:1px solid rgba(251, 191, 36, 0.3); color:#FCD34D; margin-top:14px; padding:12px 16px; border-radius:10px;">
                <span style="font-size:18px">🏗️</span>
                <div style="margin-left:8px;">
                    <strong>En attente de validation par le Fondateur.</strong><br>
                    <span style="font-size:12.5px; color:var(--sa-ink-3);">Une notification lui a été envoyée. L'association est créée mais apparaîtra comme "en attente" jusqu'à sa validation.</span>
                </div>
            </div>
        <?php endif; ?>

        <div style="background:rgba(0,0,0,0.3); border:1px solid var(--sa-border); border-radius:10px; padding:16px; margin-top:14px; font-family:'Courier New', monospace; font-size:13px;">
            <div style="margin-bottom:8px;"><strong style="color:var(--sa-ink)">Admin :</strong> <?= h($success_data['admin_name']) ?></div>
            <div style="margin-bottom:8px;"><strong style="color:var(--sa-ink)">Email :</strong> <?= h($success_data['admin_email']) ?></div>
            <div style="margin-bottom:8px;"><strong style="color:var(--sa-ink)">Mot de passe :</strong>
                <span style="background:rgba(245, 158, 11, 0.15); color:#FCD34D; padding:3px 10px; border-radius:5px; letter-spacing:0.08em; font-weight:bold;"><?= h($success_data['temp_password']) ?></span>
            </div>
            <div><strong style="color:var(--sa-ink)">URL :</strong> <a href="/connexion" style="color:#C4B5FD">https://assokit.fr/connexion</a></div>
        </div>

        <div style="font-size:12.5px; color:#FCD34D; margin-top:14px; padding:10px 12px; background:rgba(245, 158, 11, 0.08); border-radius:8px;">
            ⚠️ <strong>Important :</strong> Notez ce mot de passe et transmettez-le à l'admin de façon sécurisée. Il sera forcé à le changer à la première connexion. Ce mot de passe ne sera plus jamais affiché.
        </div>

        <div style="display:flex; gap:8px; margin-top:18px; flex-wrap:wrap;">
            <a href="/super-admin/associations?id=<?= (int) $success_data['org_id'] ?>" class="sa-btn sa-btn-violet">
                Gérer <?= h($success_data['org_name']) ?> →
            </a>
            <a href="/super-admin/nouvelle-asso" class="sa-btn sa-btn-ghost">+ Créer une autre asso</a>
            <a href="/super-admin" class="sa-btn sa-btn-ghost">← Dashboard</a>
        </div>
    </div>

<?php else: ?>

    <form method="POST" action="/super-admin/nouvelle-asso">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">

        <div class="sa-card" style="margin-bottom:16px;">
            <div class="sa-card-title">🏛️ Informations de l'association</div>
            <div class="sa-card-sub">Les données qui identifient l'asso sur la plateforme.</div>

            <div class="sa-group">
                <label>Nom de l'association <span class="req">*</span></label>
                <input type="text" name="org_name" required placeholder="Ex: Les Amis du Quartier" value="<?= h($_POST['org_name'] ?? '') ?>">
            </div>

            <div class="sa-group">
                <label>Email de contact (optionnel)</label>
                <input type="email" name="org_email" placeholder="contact@mon-asso.fr" value="<?= h($_POST['org_email'] ?? '') ?>">
            </div>

            <div class="sa-row-3">
                <div class="sa-group">
                    <label>Plan tarifaire</label>
                    <select name="org_plan">
                        <option value="essentiel">Essentiel (gratuit)</option>
                        <option value="association" selected>Association (49 € TTC/mois)</option>
                        <option value="organisation">Organisation (sur mesure)</option>
                    </select>
                </div>
                <div class="sa-group">
                    <label>Statut initial</label>
                    <select name="org_status">
                        <option value="trial" selected>Essai gratuit</option>
                        <option value="active">Active (payante)</option>
                    </select>
                </div>
                <div class="sa-group">
                    <label>Durée d'essai</label>
                    <select name="trial_days">
                        <option value="14">14 jours</option>
                        <option value="30" selected>30 jours</option>
                        <option value="60">60 jours</option>
                        <option value="90">90 jours</option>
                    </select>
                </div>
            </div>
        </div>

        <div class="sa-card" style="margin-bottom:16px;">
            <div class="sa-card-title">👤 Premier administrateur</div>
            <div class="sa-card-sub">Cette personne aura tous les droits sur l'asso et pourra créer d'autres comptes.</div>

            <div class="sa-row-2">
                <div class="sa-group">
                    <label>Prénom <span class="req">*</span></label>
                    <input type="text" name="admin_first" required value="<?= h($_POST['admin_first'] ?? '') ?>">
                </div>
                <div class="sa-group">
                    <label>Nom <span class="req">*</span></label>
                    <input type="text" name="admin_last" required value="<?= h($_POST['admin_last'] ?? '') ?>">
                </div>
            </div>

            <div class="sa-group">
                <label>Email de connexion <span class="req">*</span></label>
                <input type="email" name="admin_email" required placeholder="president@mon-asso.fr" value="<?= h($_POST['admin_email'] ?? '') ?>">
            </div>

            <div class="sa-alert sa-alert-info" style="margin-bottom:0; font-size:12.5px;">
                🔐 Un mot de passe temporaire sera généré automatiquement et affiché après création. À transmettre à l'admin de façon sécurisée.
            </div>
        </div>

        <div style="display:flex; gap:8px; flex-wrap:wrap;">
            <button type="submit" class="sa-btn sa-btn-violet">✓ Créer l'association</button>
            <a href="/super-admin" class="sa-btn sa-btn-ghost">Annuler</a>
        </div>
    </form>

<?php endif; ?>

<?php sa_render_foot(); ?>
