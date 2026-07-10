<?php
/**
 * super-admin-super-admins.php — Gestion des super admins (v2 double casquette)
 * ================================================================================
 * NOUVEAUTES v2 :
 *   - Support double casquette : un admin d'asso peut devenir super admin
 *     sans perdre son role d'admin (via colonne is_super_admin)
 *   - Recherche d'un user existant pour le promouvoir
 *   - Envoi email via Resend a chaque action
 *
 * 3 parcours possibles :
 *   A. Creer un nouveau super admin "pur" (pas d'asso)
 *   B. Promouvoir un user existant (garde son asso, ajoute is_super_admin)
 *   C. Revoquer la super casquette (laisse son role d'asso intact)
 *
 * Garde-fous :
 *   - Impossible de se revoquer soi-meme
 *   - Impossible de revoquer le dernier super admin actif
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes-layout.php';
require_once __DIR__ . '/superadmin-layout.php';
require_once __DIR__ . '/sa-permissions.php';
// Helper emails (Resend)
@require_once __DIR__ . '/resend-helper.php';
require_login();
$current = sa_require_super_admin();

// 🏗️ Page reservee aux FONDATEURS : seul un fondateur peut creer/gerer les SA
sa_require_capability('can_manage_super_admins');

$error = null;
$success = null;
$new_credentials = null;

/**
 * Compte les super admins actifs (role='super_admin' OR is_super_admin=1)
 */
function count_active_super_admins(PDO $pdo): int {
    return (int) $pdo->query("
        SELECT COUNT(*) FROM users
        WHERE (role = 'super_admin' OR is_super_admin = 1)
          AND is_active = 1
    ")->fetchColumn();
}

/**
 * Retourne true si un user est fondateur (requete BDD)
 */
function sa_user_is_founder(PDO $pdo, int $user_id): bool {
    try {
        $stmt = $pdo->prepare("SELECT is_founder FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        return (int) $stmt->fetchColumn() === 1;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Regle d'or : peut-on agir sur un user cible ?
 * - Un fondateur ne peut etre touche QUE par un autre fondateur
 * - Les autres actions passent
 */
function sa_can_act_on(PDO $pdo, array $current, int $target_id): bool {
    // Toujours pouvoir agir sur soi-meme (meme fondateur)
    // sauf si c'est une revocation → geree ailleurs
    if ((int) $current['id'] === $target_id) return true;

    $target_is_founder = sa_user_is_founder($pdo, $target_id);
    if (!$target_is_founder) return true; // cible non-fondateur → OK

    // La cible est fondateur : il faut etre fondateur soi-meme
    $current_is_founder = !empty($current['is_founder'])
        || sa_user_is_founder($pdo, (int) $current['id']);

    return $current_is_founder;
}

/**
 * Envoi email silencieux (ne casse pas le flow si Resend indispo)
 */
function send_email_safe(callable $callback): void {
    if (!function_exists('send_transactional_email')) return;
    try { $callback(); } catch (Throwable $e) { error_log('Email send fail: ' . $e->getMessage()); }
}

// ==================================================================
// Traitement POST
// ==================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && function_exists('check_csrf') && check_csrf($_POST['csrf_token'] ?? '')) {

    $action = $_POST['action'] ?? '';

    // =======================================================
    // A. CREER un nouveau super admin "pur" (pas d'asso)
    // =======================================================
    if ($action === 'create_new') {
        $first = trim($_POST['first_name'] ?? '');
        $last  = trim($_POST['last_name'] ?? '');
        $email = trim($_POST['email'] ?? '');

        if ($first === '' || $last === '' || $email === '') {
            $error = 'Tous les champs sont obligatoires.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Email invalide.';
        } else {
            $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
            $stmt->execute([$email]);
            if ($stmt->fetch()) {
                $error = "L'email <strong>" . h($email) . "</strong> existe déjà. Utilisez plutôt la section <strong>Promouvoir un utilisateur existant</strong> ci-dessous.";
            }
        }

        if (!$error) {
            $temp_pwd = sa_generate_temp_password();
            $hash = password_hash($temp_pwd, PASSWORD_BCRYPT);

            $stmt = $pdo->prepare("
                INSERT INTO users
                    (org_id, role, is_super_admin, contract_type, email, password_hash,
                     first_name, last_name, must_change_password, is_active, created_at)
                VALUES (NULL, 'super_admin', 1, 'external', ?, ?, ?, ?, 1, 1, NOW())
            ");
            $stmt->execute([$email, $hash, $first, $last]);
            $new_id = (int) $pdo->lastInsertId();

            sa_log_action((int) $current['id'], 'create_super_admin', null, $new_id, [
                'email' => $email, 'name' => "$first $last", 'mode' => 'new',
            ]);

            // Envoi email de bienvenue via Resend
            send_email_safe(function () use ($email, $first, $last, $temp_pwd) {
                send_transactional_email(
                    $email,
                    'Bienvenue sur Assokit — Votre compte Super Admin',
                    render_email_welcome([
                        'first_name' => $first, 'last_name' => $last,
                        'email' => $email, 'temp_password' => $temp_pwd,
                        'is_super_admin' => true,
                    ]),
                    ['tag' => 'welcome_super_admin']
                );
            });

            $success = "Super admin <strong>" . h("$first $last") . "</strong> créé et email envoyé.";
            $new_credentials = [
                'mode' => 'create_new', 'name' => "$first $last",
                'email' => $email, 'password' => $temp_pwd,
            ];
        }
    }

    // =======================================================
    // B. PROMOUVOIR un user existant en super admin
    // =======================================================
    elseif ($action === 'promote_existing') {
        $target_id = (int) ($_POST['target_id'] ?? 0);

        $stmt = $pdo->prepare("SELECT u.*, o.name AS org_name FROM users u LEFT JOIN organizations o ON u.org_id = o.id WHERE u.id = ?");
        $stmt->execute([$target_id]);
        $target = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$target) {
            $error = 'Utilisateur introuvable.';
        } elseif (!empty($target['is_super_admin']) || $target['role'] === 'super_admin') {
            $error = "<strong>" . h($target['first_name'] . ' ' . $target['last_name']) . "</strong> est déjà super admin.";
        } elseif (!$target['is_active']) {
            $error = "Ce compte est désactivé. Réactivez-le d'abord depuis sa fiche.";
        } else {
            // Promotion : on ajoute juste is_super_admin=1, on garde son role
            $stmt = $pdo->prepare("UPDATE users SET is_super_admin = 1 WHERE id = ?");
            $stmt->execute([$target_id]);

            sa_log_action((int) $current['id'], 'promote_to_super_admin', $target['org_id'], $target_id, [
                'email' => $target['email'],
                'existing_role' => $target['role'],
                'org_name' => $target['org_name'],
            ]);

            // Email de notification
            send_email_safe(function () use ($target, $current) {
                send_transactional_email(
                    $target['email'],
                    'Nouveau rôle : Super Admin Assokit',
                    render_email_super_admin_promotion([
                        'first_name' => $target['first_name'],
                        'email' => $target['email'],
                        'promoted_by_name' => trim($current['first_name'] . ' ' . $current['last_name']),
                    ]),
                    ['tag' => 'super_admin_promotion']
                );
            });

            $success = "<strong>" . h($target['first_name'] . ' ' . $target['last_name']) . "</strong> a été promu super admin (garde son rôle d'admin). Email envoyé.";
        }
    }

    // =======================================================
    // C. REVOQUER la casquette super admin
    // =======================================================
    elseif ($action === 'revoke') {
        $target_id = (int) ($_POST['target_id'] ?? 0);

        if ($target_id === (int) $current['id']) {
            $error = 'Vous ne pouvez pas vous révoquer vous-même.';
        }
        // PROTECTION FONDATEUR : interdit de revoquer un fondateur (meme par un autre fondateur)
        elseif (sa_user_is_founder($pdo, $target_id)) {
            $error = '🏗️ Un <strong>Fondateur</strong> ne peut pas être révoqué. Cette protection est absolue.';
        } else {
            $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
            $stmt->execute([$target_id]);
            $target = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$target) {
                $error = 'Utilisateur introuvable.';
            } elseif (count_active_super_admins($pdo) <= 1) {
                $error = 'Il faut au moins 1 super admin actif dans le système.';
            } else {
                // Si role='super_admin' pur → on ne peut pas juste revoquer, il faut choisir
                if ($target['role'] === 'super_admin' && empty($target['org_id'])) {
                    // Super admin pur : desactivation complete (pas de double casquette a revoquer)
                    $stmt = $pdo->prepare("UPDATE users SET is_active = 0, is_super_admin = 0 WHERE id = ?");
                    $stmt->execute([$target_id]);
                    sa_log_action((int) $current['id'], 'deactivate_super_admin', null, $target_id);
                    $success = "Super admin <strong>" . h($target['first_name'] . ' ' . $target['last_name']) . "</strong> désactivé.";
                } else {
                    // Double casquette : on enleve juste la casquette SA
                    $stmt = $pdo->prepare("UPDATE users SET is_super_admin = 0 WHERE id = ?");
                    $stmt->execute([$target_id]);

                    // Si son role etait 'super_admin' pur, on le remet a 'admin' (pour qu'il garde acces a son asso)
                    if ($target['role'] === 'super_admin') {
                        $new_role = $target['org_id'] ? 'admin' : 'member';
                        $stmt = $pdo->prepare("UPDATE users SET role = ? WHERE id = ?");
                        $stmt->execute([$new_role, $target_id]);
                    }

                    sa_log_action((int) $current['id'], 'revoke_super_admin', $target['org_id'], $target_id, [
                        'kept_role' => $target['role'],
                    ]);
                    $success = "Casquette super admin retirée pour <strong>" . h($target['first_name'] . ' ' . $target['last_name']) . "</strong>. Son compte asso reste intact.";
                }
            }
        }
    }

    // =======================================================
    // RESET PASSWORD
    // =======================================================
    elseif ($action === 'reset_pwd') {
        $target_id = (int) ($_POST['target_id'] ?? 0);

        // PROTECTION FONDATEUR
        if (!sa_can_act_on($pdo, $current, $target_id)) {
            $error = "🏗️ Cet utilisateur est <strong>Fondateur</strong>. Seul un autre Fondateur peut réinitialiser son mot de passe.";
        } else {
            $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
            $stmt->execute([$target_id]);
            $target = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$target) {
                $error = 'Utilisateur introuvable.';
            } else {
            $temp_pwd = sa_generate_temp_password();
            $hash = password_hash($temp_pwd, PASSWORD_BCRYPT);

            $stmt = $pdo->prepare("UPDATE users SET password_hash = ?, must_change_password = 1 WHERE id = ?");
            $stmt->execute([$hash, $target_id]);

            sa_log_action((int) $current['id'], 'reset_super_admin_password', null, $target_id);

            // Email avec nouveau mdp
            send_email_safe(function () use ($target, $temp_pwd) {
                send_transactional_email(
                    $target['email'],
                    'Assokit — Nouveau mot de passe temporaire',
                    render_email_password_reset([
                        'first_name' => $target['first_name'],
                        'email' => $target['email'],
                        'temp_password' => $temp_pwd,
                    ]),
                    ['tag' => 'password_reset']
                );
            });

            $success = "Mot de passe réinitialisé et envoyé par email.";
            $new_credentials = [
                'mode' => 'reset',
                'name' => $target['first_name'] . ' ' . $target['last_name'],
                'email' => $target['email'], 'password' => $temp_pwd,
            ];
            }
        }
    }

    // =======================================================
    // SUPPRESSION DÉFINITIVE D'UN SUPER ADMIN (jamais le Fondateur)
    // =======================================================
    elseif ($action === 'delete_sa') {
        $target_id = (int) ($_POST['target_id'] ?? 0);

        if ($target_id === (int) $current['id']) {
            $error = 'Vous ne pouvez pas supprimer votre propre compte.';
        }
        // PROTECTION FONDATEUR : absolue, en toutes circonstances
        elseif (sa_user_is_founder($pdo, $target_id)) {
            $error = '🏗️ Le <strong>Fondateur</strong> ne peut jamais être supprimé. Protection absolue.';
        } else {
            $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? AND deleted_at IS NULL");
            $stmt->execute([$target_id]);
            $target = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$target) {
                $error = 'Super admin introuvable ou déjà supprimé.';
            } elseif (!empty($target['is_active']) && count_active_super_admins($pdo) <= 1) {
                $error = 'Il faut conserver au moins 1 super admin actif dans le système.';
            } else {
                // Soft-delete : le compte disparaît du système et ne peut plus se connecter
                $stmt = $pdo->prepare("UPDATE users SET deleted_at = NOW(), is_active = 0, is_super_admin = 0 WHERE id = ?");
                $stmt->execute([$target_id]);

                sa_log_action((int) $current['id'], 'delete_super_admin', $target['org_id'] ?? null, $target_id, [
                    'email' => $target['email'],
                    'role'  => $target['role'],
                ]);

                $success = "Super admin <strong>" . h($target['first_name'] . ' ' . $target['last_name']) . "</strong> supprimé définitivement.";
            }
        }
    }
}

// ==================================================================
// Liste des super admins (tous ceux qui ont role='super_admin' OR is_super_admin=1)
// Tri : fondateurs d'abord, puis actifs, puis anciennete
// ==================================================================
$stmt = $pdo->query("
    SELECT u.*, o.name AS org_name,
           (SELECT COUNT(*) FROM platform_activity_log l WHERE l.super_admin_id = u.id) AS nb_actions
    FROM users u
    LEFT JOIN organizations o ON u.org_id = o.id
    WHERE (u.role = 'super_admin' OR u.is_super_admin = 1)
      AND u.deleted_at IS NULL
    ORDER BY u.is_founder DESC, u.is_active DESC, u.created_at ASC
");
$super_admins = $stmt->fetchAll(PDO::FETCH_ASSOC);
$nb_active = count_active_super_admins($pdo);

// Recherche d'un user existant (pour promotion)
$search_results = [];
$search_email = trim($_GET['search_email'] ?? '');
if ($search_email !== '') {
    $stmt = $pdo->prepare("
        SELECT u.*, o.name AS org_name
        FROM users u
        LEFT JOIN organizations o ON u.org_id = o.id
        WHERE u.email LIKE ?
          AND u.is_active = 1
        LIMIT 10
    ");
    $stmt->execute(['%' . $search_email . '%']);
    $search_results = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

sa_render_head('Super admins');
sa_render_sidebar('superadmins');
?>

<div class="sa-page-head">
    <div>
        <h1 class="sa-page-title">👑 Super admins</h1>
        <div class="sa-page-sub">
            <?= count($super_admins) ?> super admin<?= count($super_admins) > 1 ? 's' : '' ?>
            · <?= $nb_active ?> actif<?= $nb_active > 1 ? 's' : '' ?>
        </div>
    </div>
</div>

<?php if ($error): ?><div class="sa-alert sa-alert-error">⚠️ <?= $error ?></div><?php endif; ?>
<?php if ($success): ?><div class="sa-alert sa-alert-success">✅ <?= $success ?></div><?php endif; ?>

<?php if ($new_credentials): ?>
    <div class="sa-card" style="background:rgba(245, 158, 11, 0.08); border-color:rgba(245, 158, 11, 0.3); margin-bottom:16px;">
        <div style="font-size:15px; font-weight:600; color:#FCD34D; margin-bottom:6px;">
            🔐 <?= $new_credentials['mode'] === 'create_new' ? 'Identifiants du nouveau super admin' : 'Nouveau mot de passe temporaire' ?>
        </div>
        <div style="color:var(--sa-ink-2); font-size:13px;">
            Email automatiquement envoyé via Resend. Voici les infos en backup si besoin :
        </div>
        <div style="background:rgba(0,0,0,0.3); border:1px solid var(--sa-border); border-radius:8px; padding:14px; margin-top:14px; font-family:'Courier New', monospace; font-size:13px;">
            <div style="margin-bottom:6px;"><strong style="color:var(--sa-ink)">Nom :</strong> <?= h($new_credentials['name']) ?></div>
            <div style="margin-bottom:6px;"><strong style="color:var(--sa-ink)">Email :</strong> <?= h($new_credentials['email']) ?></div>
            <div><strong style="color:var(--sa-ink)">Mot de passe :</strong>
                <span style="background:rgba(245, 158, 11, 0.15); color:#FCD34D; padding:3px 10px; border-radius:5px; letter-spacing:0.08em; font-weight:bold;"><?= h($new_credentials['password']) ?></span>
            </div>
        </div>
    </div>
<?php endif; ?>

<!-- ============================================================ -->
<!-- SECTION A : Promouvoir un user existant                       -->
<!-- ============================================================ -->
<div class="sa-card" style="margin-bottom:16px; border-color:rgba(127, 119, 221, 0.3);">
    <div class="sa-card-title">🎭 Promouvoir un utilisateur existant (double casquette)</div>
    <div class="sa-card-sub">
        Si un admin d'asso existe déjà, tu peux lui <strong>ajouter la casquette super admin</strong>
        sans toucher à son compte. Il gardera son rôle d'admin ET aura accès au cockpit.
    </div>

    <form method="GET" action="/super-admin/super-admins" style="display:flex; gap:8px; margin-bottom:14px;">
        <input type="text" name="search_email" value="<?= h($search_email) ?>"
               placeholder="Rechercher par email (ex: hakim@...)"
               style="flex:1; padding:10px 14px; background:var(--sa-bg); border:1px solid var(--sa-border-strong); border-radius:8px; color:var(--sa-ink); font-family:inherit;">
        <button type="submit" class="sa-btn sa-btn-violet">🔍 Chercher</button>
    </form>

    <?php if ($search_email !== '' && empty($search_results)): ?>
        <div style="color:var(--sa-ink-3); font-size:13px; padding:10px;">
            Aucun utilisateur actif trouvé pour <strong><?= h($search_email) ?></strong>.
        </div>
    <?php elseif (!empty($search_results)): ?>
        <div style="background:var(--sa-bg); border:1px solid var(--sa-border); border-radius:10px; overflow:hidden;">
            <?php foreach ($search_results as $u): ?>
                <?php $already_sa = !empty($u['is_super_admin']) || $u['role'] === 'super_admin'; ?>
                <div style="display:flex; justify-content:space-between; align-items:center; padding:12px 16px; border-bottom:1px solid var(--sa-border);">
                    <div>
                        <div style="font-size:14px; font-weight:500;">
                            <?= h($u['first_name'] . ' ' . $u['last_name']) ?>
                            <span class="sa-badge sa-badge-violet" style="margin-left:6px; font-size:10px; text-transform:uppercase;"><?= h($u['role']) ?></span>
                            <?php if ($already_sa): ?>
                                <span class="sa-badge sa-badge-amber" style="margin-left:4px; font-size:10px;">👑 DÉJÀ SA</span>
                            <?php endif; ?>
                        </div>
                        <div style="font-size:12px; color:var(--sa-ink-3); margin-top:2px;">
                            📧 <?= h($u['email']) ?>
                            <?php if ($u['org_name']): ?>· 🏛️ <?= h($u['org_name']) ?><?php endif; ?>
                        </div>
                    </div>
                    <?php if (!$already_sa): ?>
                        <form method="POST" action="/super-admin/super-admins"
                              onsubmit="return confirm('Promouvoir <?= h(addslashes($u['first_name'] . ' ' . $u['last_name'])) ?> en super admin ?\n\nIl/elle conservera son rôle actuel et recevra un email.');">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                            <input type="hidden" name="action" value="promote_existing">
                            <input type="hidden" name="target_id" value="<?= (int) $u['id'] ?>">
                            <button type="submit" class="sa-btn sa-btn-violet sa-btn-sm">👑 Promouvoir</button>
                        </form>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<!-- ============================================================ -->
<!-- SECTION B : Créer un nouveau super admin "pur"                 -->
<!-- ============================================================ -->
<?php if (!$new_credentials): ?>
<div class="sa-card" style="margin-bottom:16px;">
    <div class="sa-card-title">➕ Créer un nouveau super admin (compte dédié)</div>
    <div class="sa-card-sub">
        Pour créer un compte super admin <strong>sans lien avec une asso</strong>.
        Si l'email existe déjà, utilise plutôt la section Promouvoir ci-dessus.
    </div>

    <form method="POST" action="/super-admin/super-admins">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
        <input type="hidden" name="action" value="create_new">

        <div class="sa-row-2">
            <div class="sa-group">
                <label>Prénom <span class="req">*</span></label>
                <input type="text" name="first_name" required placeholder="Ex: Sarah">
            </div>
            <div class="sa-group">
                <label>Nom <span class="req">*</span></label>
                <input type="text" name="last_name" required placeholder="Ex: Benali">
            </div>
        </div>
        <div class="sa-group">
            <label>Email <span class="req">*</span></label>
            <input type="email" name="email" required placeholder="sarah@assokit.fr">
        </div>

        <button type="submit" class="sa-btn sa-btn-violet">+ Créer ce super admin</button>
    </form>
</div>
<?php endif; ?>

<!-- ============================================================ -->
<!-- LISTE des super admins existants                               -->
<!-- ============================================================ -->
<div class="sa-card-title" style="padding-left:4px; margin: 28px 0 14px;">📋 Super admins existants</div>

<div class="sa-table-wrap">
    <table class="sa-table">
        <thead>
            <tr>
                <th>Super admin</th>
                <th>Rôle principal</th>
                <th>Statut</th>
                <th>Actions tracées</th>
                <th>Dernière connexion</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($super_admins as $sa): ?>
                <?php
                $is_me = ((int) $sa['id'] === (int) $current['id']);
                $is_active = ((int) $sa['is_active'] === 1);
                $is_last_active = ($is_active && $nb_active <= 1);
                $is_pure_sa = ($sa['role'] === 'super_admin');
                $is_double = !empty($sa['is_super_admin']) && $sa['role'] !== 'super_admin';
                $is_founder = !empty($sa['is_founder']);
                // Peut-on agir sur cette cible ?
                $can_act = sa_can_act_on($pdo, $current, (int) $sa['id']);
                ?>
                <tr <?= $is_founder ? 'style="background:rgba(251, 191, 36, 0.04);"' : '' ?>>
                    <td>
                        <div class="sa-main-col">
                            <?= h($sa['first_name'] . ' ' . $sa['last_name']) ?>
                            <?php if ($is_founder): ?>
                                <span class="sa-badge sa-badge-gold" style="margin-left:6px; font-size:10px;">🏗️ FONDATEUR</span>
                            <?php endif; ?>
                            <?php if ($is_me): ?>
                                <span class="sa-badge sa-badge-violet" style="margin-left:6px; font-size:10px;">VOUS</span>
                            <?php endif; ?>
                            <?php if ($is_double): ?>
                                <span class="sa-badge sa-badge-amber" style="margin-left:6px; font-size:10px;">🎭 DOUBLE</span>
                            <?php endif; ?>
                        </div>
                        <div class="sa-sub-col"><?= h($sa['email']) ?></div>
                    </td>
                    <td>
                        <?php if ($is_pure_sa): ?>
                            <span class="sa-badge sa-badge-violet">👑 Super admin pur</span>
                        <?php else: ?>
                            <span class="sa-badge sa-badge-gray"><?= h(ucfirst($sa['role'])) ?></span>
                            <?php if ($sa['org_name']): ?>
                                <div class="sa-sub-col">🏛️ <?= h($sa['org_name']) ?></div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="sa-badge <?= $is_active ? 'sa-badge-green' : 'sa-badge-gray' ?>">
                            <?= $is_active ? '● Actif' : '⏸ Désactivé' ?>
                        </span>
                    </td>
                    <td><?= (int) $sa['nb_actions'] ?></td>
                    <td style="color:var(--sa-ink-3);font-size:12.5px">
                        <?= $sa['last_login_at'] ? date('d/m/Y H:i', strtotime($sa['last_login_at'])) : '—' ?>
                    </td>
                    <td>
                        <?php if ($is_founder && !$is_me && !$can_act): ?>
                            <!-- Protection : aucune action disponible sur un fondateur si on n'en est pas un -->
                            <div style="font-size:11px; color:#FCD34D; padding:4px 8px; background:rgba(251, 191, 36, 0.08); border-radius:6px; display:inline-block;">
                                🏗️ Protégé (Fondateur)
                            </div>
                        <?php else: ?>
                            <div style="display:flex; gap:4px; flex-wrap:wrap;">
                                <?php if (!$is_me && $is_active && $can_act): ?>
                                    <form method="POST" style="display:inline"
                                          onsubmit="return confirm('Générer un nouveau mot de passe temporaire et envoyer par email ?');">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                                        <input type="hidden" name="action" value="reset_pwd">
                                        <input type="hidden" name="target_id" value="<?= (int) $sa['id'] ?>">
                                        <button type="submit" class="sa-btn sa-btn-ghost sa-btn-sm">🔑 Reset mdp</button>
                                    </form>
                                <?php endif; ?>

                                <?php if (!$is_me && $is_active && !$is_last_active && !$is_founder): ?>
                                    <form method="POST" style="display:inline"
                                          onsubmit="return confirm('<?= $is_double ? "Retirer la casquette super admin ? Son compte d'asso restera intact." : "Désactiver complètement ce super admin ?" ?>');">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                                        <input type="hidden" name="action" value="revoke">
                                        <input type="hidden" name="target_id" value="<?= (int) $sa['id'] ?>">
                                        <button type="submit" class="sa-btn sa-btn-danger sa-btn-sm">
                                            <?= $is_double ? '👑 Retirer SA' : '⏸ Désactiver' ?>
                                        </button>
                                    </form>
                                <?php endif; ?>

                                <?php if (!$is_me && !$is_founder): ?>
                                    <form method="POST" style="display:inline"
                                          onsubmit="return confirm('⚠️ SUPPRESSION DÉFINITIVE\n\nSupprimer définitivement ce super admin ?<?= $is_double ? '\n\nCe compte possède aussi un accès à une association : il perdra TOUT accès.' : '' ?>\n\nCette action est irréversible.');">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                                        <input type="hidden" name="action" value="delete_sa">
                                        <input type="hidden" name="target_id" value="<?= (int) $sa['id'] ?>">
                                        <button type="submit" class="sa-btn sa-btn-sm" style="background:#DC2626;color:#fff;border-color:#DC2626;">🗑 Supprimer</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>

                        <?php if ($is_me && $is_founder): ?>
                            <div style="font-size:11px; color:#FCD34D; margin-top:4px;">🏗️ Fondateur — Protection absolue</div>
                        <?php elseif ($is_me): ?>
                            <div style="font-size:11px; color:var(--sa-ink-4); margin-top:4px;">Pas d'auto-révocation</div>
                        <?php elseif ($is_last_active && $is_active): ?>
                            <div style="font-size:11px; color:#FCD34D; margin-top:4px;">Dernier actif du système</div>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<div class="sa-alert sa-alert-info" style="margin-top:24px;">
    <span style="font-size:18px">🎭</span>
    <div>
        <strong>Double casquette</strong> : un admin d'asso peut devenir super admin tout en gardant son rôle.
        Il verra le cockpit super admin à la connexion, et pourra revenir sur son asso via le lien "↪ Mode asso" dans la sidebar.
        <br>📧 <strong>Emails automatiques</strong> via Resend à chaque action (promotion, reset mdp, etc.)
    </div>
</div>

<?php sa_render_foot(); ?>
