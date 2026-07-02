<?php
/**
 * super-admin-associations.php — Gestion des associations
 * ========================================================
 * Deux vues dans cette page :
 *   1. Vue liste (par defaut) : toutes les assos avec filtres
 *   2. Vue fiche (?id=X)       : detail complet d'une asso + actions
 *
 * Actions disponibles sur une asso :
 *   - Modifier (nom, email, plan)
 *   - Suspendre / Reactiver / Resilier
 *   - Voir ses users, ses projets, son abonnement
 *   - Notes internes super admin
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes-layout.php';
require_once __DIR__ . '/superadmin-layout.php';
require_once __DIR__ . '/sa-permissions.php';
require_login();
$user = sa_require_super_admin();
$ctx = sa_get_permissions_context();

$error = null;
$success = null;
$view_id = (int) ($_GET['id'] ?? 0);

// =====================================================================
// Traitement POST (modifs d'une asso)
// =====================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (!function_exists('check_csrf') || check_csrf($_POST['csrf_token'] ?? ''))) {

    $action = $_POST['action'] ?? '';
    $target_id = (int) ($_POST['org_id'] ?? 0);

    $stmt = $pdo->prepare("SELECT * FROM organizations WHERE id = ?");
    $stmt->execute([$target_id]);
    $org = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$org) {
        $error = 'Association introuvable.';
    } else {

        // ---- VALIDATE (Fondateur uniquement) ----
        if ($action === 'validate_org') {
            if (!$ctx['is_founder']) {
                $error = '🏗️ Seul un Fondateur peut valider une association.';
            } else {
                $stmt = $pdo->prepare("UPDATE organizations SET validation_status = 'validated', validated_by_user_id = ?, validated_at = NOW() WHERE id = ?");
                $stmt->execute([(int) $user['id'], $target_id]);
                sa_log_action((int) $user['id'], 'validate_org', $target_id);
                $success = '✓ Association validée. Elle est maintenant pleinement active.';
                $view_id = $target_id;
            }
        }
        // ---- REJECT (Fondateur uniquement) ----
        elseif ($action === 'reject_org') {
            if (!$ctx['is_founder']) {
                $error = '🏗️ Seul un Fondateur peut refuser une association.';
            } else {
                $reason = trim($_POST['rejection_reason'] ?? '');
                $stmt = $pdo->prepare("UPDATE organizations SET validation_status = 'rejected', rejection_reason = ?, validated_by_user_id = ?, validated_at = NOW(), status = 'suspended' WHERE id = ?");
                $stmt->execute([$reason ?: 'Refusé par le Fondateur', (int) $user['id'], $target_id]);
                sa_log_action((int) $user['id'], 'reject_org', $target_id, null, ['reason' => $reason]);
                $success = '✕ Association refusée.';
                $view_id = $target_id;
            }
        }
        // ---- UPDATE ----
        elseif ($action === 'update') {
            $name = trim($_POST['name'] ?? '');
            $plan = $_POST['plan'] ?? 'essentiel';
            $notes = trim($_POST['notes_superadmin'] ?? '');

            if ($name === '') {
                $error = 'Nom requis.';
            } else {
                $stmt = $pdo->prepare("UPDATE organizations SET name = ?, plan = ?, notes_superadmin = ? WHERE id = ?");
                $stmt->execute([$name, $plan, $notes, $target_id]);
                sa_log_action((int) $user['id'], 'update_org', $target_id, null, ['name' => $name, 'plan' => $plan]);
                $success = 'Association mise à jour.';
                $view_id = $target_id;
            }
        }
        // ---- SUSPEND ----
        elseif ($action === 'suspend') {
            $reason = trim($_POST['reason'] ?? '');
            $stmt = $pdo->prepare("UPDATE organizations SET status = 'suspended', suspended_reason = ? WHERE id = ?");
            $stmt->execute([$reason ?: 'Suspendu par super admin', $target_id]);
            sa_log_action((int) $user['id'], 'suspend_org', $target_id, null, ['reason' => $reason]);
            $success = 'Association suspendue.';
            $view_id = $target_id;
        }
        // ---- REACTIVATE ----
        elseif ($action === 'reactivate') {
            $stmt = $pdo->prepare("UPDATE organizations SET status = 'active', suspended_reason = NULL WHERE id = ?");
            $stmt->execute([$target_id]);
            sa_log_action((int) $user['id'], 'reactivate_org', $target_id);
            $success = 'Association réactivée.';
            $view_id = $target_id;
        }
        // ---- CANCEL ----
        elseif ($action === 'cancel') {
            $stmt = $pdo->prepare("UPDATE organizations SET status = 'cancelled' WHERE id = ?");
            $stmt->execute([$target_id]);
            sa_log_action((int) $user['id'], 'cancel_org', $target_id);
            $success = 'Association résiliée.';
            $view_id = $target_id;
        }
        // ---- DELETE (soft-delete) ----
        elseif ($action === 'delete_org') {
            if ((int) $target_id === 1) {
                $error = '🛡️ Cette organisation est protégée et ne peut pas être supprimée.';
            } else {
                $reason = trim($_POST['delete_reason'] ?? '');
                $pdo->beginTransaction();
                try {
                    // 1. Soft-delete de l'organisation
                    $stmt = $pdo->prepare("UPDATE organizations SET deleted_at = NOW(), deletion_reason = ?, deleted_by_user_id = ?, status = 'cancelled' WHERE id = ? AND deleted_at IS NULL");
                    $stmt->execute([$reason ?: 'Supprimée via super-admin', (int) $user['id'], $target_id]);
                    // 2. Cascade users : desactivation + liberation de l'email (UNIQUE respecte)
                    $stmt = $pdo->prepare("UPDATE users SET deleted_at = NOW(), is_active = 0, deleted_by_user_id = ?, email = CONCAT('deleted_', id, '_', UNIX_TIMESTAMP(), '__', email) WHERE org_id = ? AND deleted_at IS NULL");
                    $stmt->execute([(int) $user['id'], $target_id]);
                    $freed = $stmt->rowCount();
                    $pdo->commit();
                    sa_log_action((int) $user['id'], 'delete_org', $target_id, null, ['reason' => $reason, 'users_freed' => $freed]);
                    $success = '🗑️ Association supprimée (masquée). ' . $freed . ' compte(s) désactivé(s), e-mail(s) libéré(s). Récupérable en base si besoin.';
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    $error = 'Erreur lors de la suppression : ' . $e->getMessage();
                }
            }
        }
    }
}

// =====================================================================
// Routage : fiche detaillee OU liste
// =====================================================================
if ($view_id > 0) {
    // ================== FICHE DETAILLEE ==================
    $stmt = $pdo->prepare("SELECT * FROM organizations WHERE id = ?");
    $stmt->execute([$view_id]);
    $org = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$org) {
        sa_render_head('Association introuvable');
        sa_render_sidebar('associations');
        ?>
        <div class="sa-empty">
            <div class="sa-empty-icon">🔍</div>
            <div class="sa-empty-title">Association introuvable</div>
            <div style="margin-top:18px"><a href="/super-admin/associations" class="sa-btn sa-btn-violet">← Retour liste</a></div>
        </div>
        <?php
        sa_render_foot();
        exit;
    }

    // Stats de l'asso
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE org_id = ? AND is_active = 1 AND deleted_at IS NULL");
    $stmt->execute([$view_id]);
    $nb_users = (int) $stmt->fetchColumn();

    $nb_projects = 0;
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM projects p JOIN folders f ON p.folder_id = f.id WHERE f.org_id = ?");
        $stmt->execute([$view_id]);
        $nb_projects = (int) $stmt->fetchColumn();
    } catch (Throwable $e) {}

    // Liste des users
    $stmt = $pdo->prepare("
        SELECT id, email, first_name, last_name, role, is_active, last_login_at, created_at
        FROM users
        WHERE org_id = ? AND deleted_at IS NULL AND is_active = 1
        ORDER BY FIELD(role, 'admin','coordinator','referent','member','follower'), last_name
    ");
    $stmt->execute([$view_id]);
    $org_users = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Subscription
    $subscription = null;
    try {
        $stmt = $pdo->prepare("SELECT * FROM subscriptions WHERE org_id = ?");
        $stmt->execute([$view_id]);
        $subscription = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {}

    sa_render_head($org['name']);
    sa_render_sidebar('associations');
    ?>

    <div class="sa-breadcrumb">
        <a href="/super-admin">Dashboard</a>
        <span class="sep">›</span>
        <a href="/super-admin/associations">Associations</a>
        <span class="sep">›</span>
        <?= h($org['name']) ?>
    </div>

    <div class="sa-page-head">
        <div>
            <h1 class="sa-page-title"><?= h($org['name']) ?></h1>
            <div class="sa-page-sub">
                <span class="sa-badge <?=
                    match($org['status']) {
                        'active' => 'sa-badge-green', 'trial' => 'sa-badge-violet',
                        'suspended' => 'sa-badge-red', 'cancelled' => 'sa-badge-gray',
                        default => 'sa-badge-gray',
                    } ?>">
                    <?= match($org['status']) {
                        'active' => '● Active', 'trial' => '⏱ Essai',
                        'suspended' => '⏸ Suspendue', 'cancelled' => '✕ Résiliée',
                        default => h($org['status']),
                    } ?>
                </span>
                <span style="margin:0 8px">·</span>
                Créée le <?= date('d/m/Y', strtotime($org['created_at'])) ?>
                <?php if ($org['trial_ends_at']): ?>
                    <span style="margin:0 8px">·</span>
                    Fin d'essai : <?= date('d/m/Y', strtotime($org['trial_ends_at'])) ?>
                <?php endif; ?>
            </div>
        </div>
        <div class="sa-page-actions">
            <a href="/super-admin/associations" class="sa-btn sa-btn-ghost">← Retour liste</a>
        </div>
    </div>

    <!-- Navigation onglets (Pack #5A) -->
    <div style="display:flex; gap:4px; margin-bottom:20px; border-bottom:1px solid var(--sa-border);">
        <a href="/super-admin/associations?id=<?= (int)$view_id ?>"
           style="padding:12px 20px; color:var(--sa-violet); text-decoration:none; font-size:14px; font-weight:600; border-bottom:2px solid var(--sa-violet); background:rgba(127, 119, 221, 0.05);">
            📊 Vue d'ensemble
        </a>
        <a href="/super-admin-association-facturation.php?id=<?= (int)$view_id ?>"
           style="padding:12px 20px; color:var(--sa-ink-3); text-decoration:none; font-size:14px; font-weight:500; border-bottom:2px solid transparent;">
            📄 Facturation
        </a>
    </div>

    <?php if ($error): ?><div class="sa-alert sa-alert-error">⚠️ <?= h($error) ?></div><?php endif; ?>
    <?php if ($success): ?><div class="sa-alert sa-alert-success">✅ <?= h($success) ?></div><?php endif; ?>

    <?php
    // Banniere de validation si l'asso est pending
    $val_status = $org['validation_status'] ?? 'validated';
    if ($val_status === 'pending_founder'):
    ?>
    <div class="sa-card" style="background: linear-gradient(135deg, rgba(251, 191, 36, 0.06) 0%, rgba(245, 158, 11, 0.08) 100%); border-color:rgba(251, 191, 36, 0.35); margin-bottom:16px;">
        <div style="display:flex; align-items:flex-start; gap:14px;">
            <div style="font-size:32px;">🏗️</div>
            <div style="flex:1;">
                <div style="font-size:15px; font-weight:600; color:#FCD34D; margin-bottom:4px;">
                    Association en attente de validation Fondateur
                </div>
                <div style="font-size:13px; color:var(--sa-ink-2); margin-bottom:14px;">
                    Cette asso a été créée par un Super Admin. Elle est fonctionnelle mais nécessite l'approbation d'un Fondateur pour être officiellement active sur la plateforme.
                </div>

                <?php if ($ctx['is_founder']): ?>
                    <div style="display:flex; gap:8px; flex-wrap:wrap;">
                        <form method="POST" action="/super-admin/associations" style="display:inline">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                            <input type="hidden" name="action" value="validate_org">
                            <input type="hidden" name="org_id" value="<?= (int) $org['id'] ?>">
                            <button type="submit" class="sa-btn" style="background:linear-gradient(135deg, #FCD34D 0%, #F59E0B 100%); color:#78350F; font-weight:600;">
                                ✓ Valider cette association
                            </button>
                        </form>
                        <button type="button" class="sa-btn sa-btn-danger" onclick="document.getElementById('reject-form').style.display='block'">
                            ✕ Refuser
                        </button>
                    </div>
                    <form method="POST" action="/super-admin/associations" id="reject-form" style="display:none; margin-top:14px;">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                        <input type="hidden" name="action" value="reject_org">
                        <input type="hidden" name="org_id" value="<?= (int) $org['id'] ?>">
                        <div class="sa-group">
                            <label>Motif du refus (optionnel)</label>
                            <textarea name="rejection_reason" rows="2" placeholder="Ex: Doublon, infos manquantes..."></textarea>
                        </div>
                        <button type="submit" class="sa-btn sa-btn-danger">Confirmer le refus</button>
                    </form>
                <?php else: ?>
                    <div style="font-size:12.5px; color:var(--sa-ink-3); padding:10px 12px; background:rgba(0,0,0,0.2); border-radius:8px;">
                        ℹ️ Seul un Fondateur peut valider. Le Fondateur a été notifié.
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php elseif ($val_status === 'rejected'): ?>
    <div class="sa-alert sa-alert-error">
        <span style="font-size:18px">✕</span>
        <div>
            <strong>Association refusée</strong>
            <?php if (!empty($org['rejection_reason'])): ?>
                <br><span style="font-size:12.5px;">Motif : <?= h($org['rejection_reason']) ?></span>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Stats rapides -->
    <div class="sa-kpi-grid">
        <div class="sa-kpi">
            <div class="sa-kpi-label">Utilisateurs</div>
            <div class="sa-kpi-value"><?= $nb_users ?></div>
        </div>
        <div class="sa-kpi">
            <div class="sa-kpi-label">Projets</div>
            <div class="sa-kpi-value"><?= $nb_projects ?></div>
        </div>
        <div class="sa-kpi">
            <div class="sa-kpi-label">Plan</div>
            <div class="sa-kpi-value" style="font-size:22px;"><?= h(ucfirst($org['plan'])) ?></div>
        </div>
        <?php if ($subscription): ?>
        <div class="sa-kpi">
            <div class="sa-kpi-label">Abonnement</div>
            <div class="sa-kpi-value" style="font-size:22px;"><?= number_format($subscription['price_ht'] * (1 + $subscription['tva_rate']/100), 2, ',', ' ') ?> €</div>
            <div class="sa-kpi-trend"><?= $subscription['billing_cycle'] === 'yearly' ? '/an' : '/mois' ?> TTC</div>
        </div>
        <?php endif; ?>
    </div>

    <div style="display:grid; grid-template-columns: 2fr 1fr; gap: 20px;">

        <!-- ========== COLONNE GAUCHE ========== -->
        <div>

            <!-- Modification -->
            <div class="sa-card" style="margin-bottom: 16px;">
                <div class="sa-card-title">✏️ Modifier l'association</div>

                <form method="POST" action="/super-admin/associations">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                    <input type="hidden" name="action" value="update">
                    <input type="hidden" name="org_id" value="<?= (int) $org['id'] ?>">

                    <div class="sa-group">
                        <label>Nom de l'association</label>
                        <input type="text" name="name" value="<?= h($org['name']) ?>" required>
                    </div>

                    <div class="sa-group">
                        <label>Plan tarifaire</label>
                        <select name="plan">
                            <option value="essentiel" <?= $org['plan'] === 'essentiel' ? 'selected' : '' ?>>Essentiel (gratuit)</option>
                            <option value="association" <?= $org['plan'] === 'association' ? 'selected' : '' ?>>Association (49 € TTC/mois)</option>
                            <option value="organisation" <?= $org['plan'] === 'organisation' ? 'selected' : '' ?>>Organisation (sur mesure)</option>
                        </select>
                    </div>

                    <div class="sa-group">
                        <label>Notes internes (super admin)</label>
                        <textarea name="notes_superadmin" rows="3" placeholder="Commentaires privés sur cette asso (pas visible par l'admin de l'asso)..."><?= h($org['notes_superadmin'] ?? '') ?></textarea>
                    </div>

                    <button type="submit" class="sa-btn sa-btn-violet">💾 Enregistrer les modifications</button>
                </form>
            </div>

            <!-- Liste des users -->
            <div class="sa-card">
                <div class="sa-card-title">👥 Utilisateurs (<?= count($org_users) ?>)</div>

                <?php if (empty($org_users)): ?>
                    <div class="sa-empty" style="padding: 40px 20px;">
                        <div class="sa-empty-icon">👥</div>
                        <div>Aucun utilisateur dans cette asso.</div>
                    </div>
                <?php else: ?>
                    <table class="sa-table" style="margin: 0 -6px;">
                        <thead>
                            <tr><th>Nom</th><th>Rôle</th><th>Statut</th><th>Dernière connexion</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($org_users as $u): ?>
                                <tr>
                                    <td>
                                        <div class="sa-main-col"><?= h($u['first_name'] . ' ' . $u['last_name']) ?></div>
                                        <div class="sa-sub-col"><?= h($u['email']) ?></div>
                                    </td>
                                    <td><span class="sa-badge sa-badge-violet"><?= h($u['role']) ?></span></td>
                                    <td>
                                        <?php if ($u['is_active']): ?>
                                            <span class="sa-badge sa-badge-green">Actif</span>
                                        <?php else: ?>
                                            <span class="sa-badge sa-badge-gray">Inactif</span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="font-size:12px; color:var(--sa-ink-3)">
                                        <?= $u['last_login_at'] ? date('d/m/Y H:i', strtotime($u['last_login_at'])) : '—' ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>

        <!-- ========== COLONNE DROITE ========== -->
        <div>

            <!-- Actions sensibles -->
            <div class="sa-card" style="margin-bottom: 16px;">
                <div class="sa-card-title">⚠️ Actions sensibles</div>
                <div class="sa-card-sub">Ces actions affectent l'accès de l'asso à la plateforme.</div>

                <?php if ($org['status'] === 'active' || $org['status'] === 'trial'): ?>
                    <form method="POST" action="/super-admin/associations"
                          onsubmit="return confirm('Suspendre cette asso ? Ses users ne pourront plus se connecter.');" style="margin-bottom:8px">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                        <input type="hidden" name="action" value="suspend">
                        <input type="hidden" name="org_id" value="<?= (int) $org['id'] ?>">
                        <div class="sa-group">
                            <label style="font-size:11.5px">Raison de suspension (optionnel)</label>
                            <input type="text" name="reason" placeholder="Ex: Impayé, demande de l'asso...">
                        </div>
                        <button type="submit" class="sa-btn sa-btn-danger" style="width:100%">⏸ Suspendre</button>
                    </form>
                <?php elseif ($org['status'] === 'suspended'): ?>
                    <form method="POST" action="/super-admin/associations" style="margin-bottom:8px">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                        <input type="hidden" name="action" value="reactivate">
                        <input type="hidden" name="org_id" value="<?= (int) $org['id'] ?>">
                        <button type="submit" class="sa-btn sa-btn-violet" style="width:100%">▶ Réactiver</button>
                    </form>
                <?php endif; ?>

                <?php if ($org['status'] !== 'cancelled'): ?>
                    <form method="POST" action="/super-admin/associations"
                          onsubmit="return confirm('RÉSILIER définitivement cette asso ? Les données restent mais accès coupé.');">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                        <input type="hidden" name="action" value="cancel">
                        <input type="hidden" name="org_id" value="<?= (int) $org['id'] ?>">
                        <button type="submit" class="sa-btn sa-btn-ghost" style="width:100%">✕ Résilier</button>
                    </form>
                <?php endif; ?>
            </div>

            <!-- Abonnement -->
            <?php if ($subscription): ?>
                <div class="sa-card">
                    <div class="sa-card-title">💳 Abonnement</div>

                    <div style="font-size:12.5px; color:var(--sa-ink-3); display:grid; grid-template-columns: auto 1fr; gap:6px 12px;">
                        <div>Statut :</div>
                        <div style="color:var(--sa-ink)"><strong><?= h(ucfirst($subscription['status'])) ?></strong></div>

                        <div>Cycle :</div>
                        <div style="color:var(--sa-ink)"><?= $subscription['billing_cycle'] === 'yearly' ? 'Annuel' : 'Mensuel' ?></div>

                        <div>Prix HT :</div>
                        <div style="color:var(--sa-ink)"><?= number_format($subscription['price_ht'], 2, ',', ' ') ?> €</div>

                        <div>TVA :</div>
                        <div style="color:var(--sa-ink)"><?= $subscription['tva_rate'] ?>%</div>

                        <div>Prix TTC :</div>
                        <div style="color:var(--sa-ink)"><strong><?= number_format($subscription['price_ht'] * (1 + $subscription['tva_rate']/100), 2, ',', ' ') ?> €</strong></div>

                        <?php if ($subscription['current_period_end']): ?>
                            <div>Échéance :</div>
                            <div style="color:var(--sa-ink)"><?= date('d/m/Y', strtotime($subscription['current_period_end'])) ?></div>
                        <?php endif; ?>
                    </div>

                    <a href="/super-admin/abonnements?org_id=<?= (int) $org['id'] ?>" class="sa-btn sa-btn-ghost sa-btn-sm" style="margin-top:12px; width:100%">
                        Voir les factures →
                    </a>
                </div>
            <?php endif; ?>

        </div>
    </div>

    <?php
    sa_render_foot();
    exit;
}

// =====================================================================
// VUE LISTE (par defaut)
// =====================================================================
$filter_status = $_GET['status'] ?? '';
$filter_validation = $_GET['filter'] ?? '';  // pending, validated, rejected
$search = trim($_GET['q'] ?? '');

$where = [];
$params = [];
if ($filter_status !== '') {
    $where[] = 'o.status = ?';
    $params[] = $filter_status;
}
if ($filter_validation === 'pending') {
    $where[] = "o.validation_status = 'pending_founder'";
} elseif ($filter_validation === 'validated') {
    $where[] = "o.validation_status = 'validated'";
} elseif ($filter_validation === 'rejected') {
    $where[] = "o.validation_status = 'rejected'";
}
if ($search !== '') {
    $where[] = 'o.name LIKE ?';
    $params[] = '%' . $search . '%';
}
$where[] = 'o.deleted_at IS NULL';
$where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$stmt = $pdo->prepare("
    SELECT o.*,
           COALESCE(o.validation_status, 'validated') AS validation_status_safe,
           (SELECT COUNT(*) FROM users u WHERE u.org_id = o.id AND u.is_active = 1 AND u.deleted_at IS NULL) AS nb_users,
           (SELECT COUNT(*) FROM projects p JOIN folders f ON p.folder_id = f.id WHERE f.org_id = o.id) AS nb_projects,
           (SELECT MAX(last_login_at) FROM users u WHERE u.org_id = o.id AND u.is_active = 1 AND u.deleted_at IS NULL) AS last_activity
    FROM organizations o
    $where_sql
    ORDER BY
        CASE WHEN o.validation_status = 'pending_founder' THEN 0 ELSE 1 END,
        o.created_at DESC
");
$stmt->execute($params);
$orgs = $stmt->fetchAll(PDO::FETCH_ASSOC);

sa_render_head('Associations');
sa_render_sidebar('associations');
?>

<div class="sa-page-head">
    <div>
        <h1 class="sa-page-title">🏛️ Associations</h1>
        <div class="sa-page-sub"><?= count($orgs) ?> résultat<?= count($orgs) > 1 ? 's' : '' ?></div>
    </div>
    <div class="sa-page-actions">
        <a href="/super-admin/nouvelle-asso" class="sa-btn sa-btn-violet">+ Créer une association</a>
    </div>
</div>

<?php if ($success): ?><div class="sa-alert sa-alert-success">✅ <?= h($success) ?></div><?php endif; ?>

<!-- Onglets validation -->
<div style="display:flex; gap:6px; margin-bottom:14px; flex-wrap:wrap;">
    <a href="/super-admin/associations" class="sa-btn <?= !$filter_validation ? 'sa-btn-violet' : 'sa-btn-ghost' ?> sa-btn-sm">
        Toutes
    </a>
    <a href="/super-admin/associations?filter=pending" class="sa-btn <?= $filter_validation === 'pending' ? 'sa-btn-violet' : 'sa-btn-ghost' ?> sa-btn-sm"
       style="<?= $filter_validation !== 'pending' ? 'border-color:rgba(251,191,36,0.3); color:#FCD34D;' : '' ?>">
        🏗️ En attente validation
    </a>
    <a href="/super-admin/associations?filter=validated" class="sa-btn <?= $filter_validation === 'validated' ? 'sa-btn-violet' : 'sa-btn-ghost' ?> sa-btn-sm">
        ✓ Validées
    </a>
    <a href="/super-admin/associations?filter=rejected" class="sa-btn <?= $filter_validation === 'rejected' ? 'sa-btn-violet' : 'sa-btn-ghost' ?> sa-btn-sm">
        ✕ Refusées
    </a>
</div>

<!-- Filtres -->
<form method="GET" style="display:flex; gap:10px; margin-bottom:16px; flex-wrap:wrap;">
    <?php if ($filter_validation): ?>
        <input type="hidden" name="filter" value="<?= h($filter_validation) ?>">
    <?php endif; ?>
    <input type="text" name="q" value="<?= h($search) ?>" placeholder="🔍 Rechercher par nom..."
           style="flex:1; min-width:200px; padding:8px 12px; background:var(--sa-bg-2); border:1px solid var(--sa-border-strong); border-radius:8px; color:var(--sa-ink); font-family:inherit;">

    <select name="status" style="padding:8px 12px; background:var(--sa-bg-2); border:1px solid var(--sa-border-strong); border-radius:8px; color:var(--sa-ink); font-family:inherit;">
        <option value="">Tous les statuts</option>
        <option value="active" <?= $filter_status === 'active' ? 'selected' : '' ?>>Actives</option>
        <option value="trial" <?= $filter_status === 'trial' ? 'selected' : '' ?>>En essai</option>
        <option value="suspended" <?= $filter_status === 'suspended' ? 'selected' : '' ?>>Suspendues</option>
        <option value="cancelled" <?= $filter_status === 'cancelled' ? 'selected' : '' ?>>Résiliées</option>
    </select>

    <button type="submit" class="sa-btn sa-btn-ghost">Filtrer</button>
    <?php if ($filter_status || $search): ?>
        <a href="/super-admin/associations<?= $filter_validation ? '?filter=' . h($filter_validation) : '' ?>" class="sa-btn sa-btn-ghost">Effacer</a>
    <?php endif; ?>
</form>

<?php if (empty($orgs)): ?>
    <div class="sa-card">
        <div class="sa-empty">
            <div class="sa-empty-icon">🏛️</div>
            <div class="sa-empty-title">Aucune association trouvée</div>
            <div>Essayez un autre filtre ou <a href="/super-admin/nouvelle-asso" style="color:#C4B5FD">créez-en une</a>.</div>
        </div>
    </div>
<?php else: ?>
    <div class="sa-table-wrap">
        <table class="sa-table">
            <thead>
                <tr>
                    <th>Association</th>
                    <th>Validation</th>
                    <th>Statut</th>
                    <th>Plan</th>
                    <th>Users</th>
                    <th>Projets</th>
                    <th>Dernière activité</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($orgs as $o): ?>
                    <?php $val_status = $o['validation_status_safe'] ?? 'validated'; ?>
                    <tr style="cursor:pointer; <?= $val_status === 'pending_founder' ? 'background:rgba(251, 191, 36, 0.04);' : '' ?>" onclick="window.location='/super-admin/associations?id=<?= (int) $o['id'] ?>'">
                        <td>
                            <div class="sa-main-col"><?= h($o['name']) ?></div>
                            <div class="sa-sub-col">
                                Créée <?= date('d/m/Y', strtotime($o['created_at'])) ?>
                                <?php if ($o['suspended_reason']): ?>
                                    · <span style="color:#FCA5A5"><?= h($o['suspended_reason']) ?></span>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td>
                            <?php if ($val_status === 'pending_founder'): ?>
                                <span class="sa-badge sa-badge-gold">🏗️ En attente</span>
                            <?php elseif ($val_status === 'rejected'): ?>
                                <span class="sa-badge sa-badge-red">✕ Refusée</span>
                            <?php else: ?>
                                <span class="sa-badge sa-badge-green" style="opacity:0.7">✓ Validée</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="sa-badge <?=
                                match($o['status']) {
                                    'active' => 'sa-badge-green', 'trial' => 'sa-badge-violet',
                                    'suspended' => 'sa-badge-red', 'cancelled' => 'sa-badge-gray',
                                    default => 'sa-badge-gray',
                                } ?>">
                                <?= match($o['status']) {
                                    'active' => '● Active', 'trial' => '⏱ Essai',
                                    'suspended' => '⏸ Suspendue', 'cancelled' => '✕ Résiliée',
                                    default => h($o['status']),
                                } ?>
                            </span>
                        </td>
                        <td><span class="sa-badge sa-badge-gray"><?= h(ucfirst($o['plan'])) ?></span></td>
                        <td><?= (int) $o['nb_users'] ?></td>
                        <td><?= (int) $o['nb_projects'] ?></td>
                        <td style="color:var(--sa-ink-3); font-size:12.5px;">
                            <?= $o['last_activity'] ? date('d/m/Y H:i', strtotime($o['last_activity'])) : '—' ?>
                        </td>
                        <td onclick="event.stopPropagation()">
                            <a href="/super-admin/associations?id=<?= (int) $o['id'] ?>" class="sa-btn sa-btn-ghost sa-btn-sm">
                                Gérer →
                            </a>
                            <?php if ((int) $o['id'] !== 1): ?>
                            <form method="POST" action="/super-admin/associations" style="display:inline"
                                  onsubmit="return confirm('Supprimer &laquo; <?= htmlspecialchars(addslashes($o['name'])) ?> &raquo; ? Elle sera masquee du tableau (donnees conservees en base).');">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                                <input type="hidden" name="action" value="delete_org">
                                <input type="hidden" name="org_id" value="<?= (int) $o['id'] ?>">
                                <button type="submit" class="sa-btn sa-btn-ghost sa-btn-sm" style="color:#DC2626;" title="Supprimer">🗑️</button>
                            </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<?php sa_render_foot(); ?>
