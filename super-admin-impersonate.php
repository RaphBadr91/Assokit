<?php
/**
 * super-admin-impersonate.php
 * URL : /super-admin/incarner
 * Réservé aux Fondateurs (is_founder = 1)
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes-layout.php';
require_once __DIR__ . '/superadmin-layout.php';
require_once __DIR__ . '/sa-permissions.php';
require_once __DIR__ . '/impersonation-helpers.php';

require_login();
$user = sa_require_super_admin();
$ctx = sa_get_permissions_context();

if (!$ctx['is_founder']) {
    http_response_code(403);
    exit('🔒 Accès réservé au Fondateur.');
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'start') {
    if (!hash_equals($csrf, $_POST['csrf_token'] ?? '')) {
        $error = 'Session expirée — rechargez la page.';
    } else {
        $targetId = (int) ($_POST['target_user_id'] ?? 0);
        $reason = trim($_POST['reason'] ?? '');
        $result = start_impersonation((int)$user['id'], $targetId, $reason);
        if ($result['success']) {
            header('Location: /tableau-de-bord?impersonation_started=1');
            exit;
        } else {
            $error = $result['error'];
        }
    }
}

$search = trim($_GET['q'] ?? '');
$roleFilter = $_GET['role'] ?? '';

$where = ["u.is_active = 1", "u.deleted_at IS NULL", "u.id != :self"];
$params = [':self' => (int)$user['id']];

if (!empty($search)) {
    $where[] = "(u.email LIKE :search OR u.first_name LIKE :search OR u.last_name LIKE :search OR o.name LIKE :search)";
    $params[':search'] = '%' . $search . '%';
}
if (!empty($roleFilter) && in_array($roleFilter, ['admin','coordinator','referent','member','follower','super_admin'], true)) {
    $where[] = "u.role = :role";
    $params[':role'] = $roleFilter;
}

$sql = "
    SELECT u.id, u.email, u.first_name, u.last_name, u.role, u.last_login_at,
           u.is_super_admin, u.is_founder,
           o.id AS org_id, o.name AS org_name
    FROM users u
    LEFT JOIN organizations o ON o.id = u.org_id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY u.last_login_at DESC, u.id DESC
    LIMIT 50
";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

sa_render_head('Incarner un utilisateur');
sa_render_sidebar('dashboard');
?>

<div class="sa-page-head">
    <div>
        <h1 class="sa-page-title">
            🎭 Incarner un utilisateur
            <span class="sa-badge sa-badge-gold" style="font-size:12px;margin-left:8px;">🏗️ FONDATEUR UNIQUEMENT</span>
        </h1>
        <div class="sa-page-sub">Voir la plateforme à travers les yeux d'un utilisateur pour diagnostiquer ou fournir du support.</div>
    </div>
    <div class="sa-page-actions">
        <a href="/super-admin/logs-incarnation" class="sa-btn sa-btn-ghost">📋 Historique</a>
    </div>
</div>

<?php if ($error): ?>
    <div class="sa-alert sa-alert-error" style="margin-bottom:16px;">
        <span style="font-size:18px">⚠️</span>
        <div><strong>Erreur :</strong> <?= h($error) ?></div>
    </div>
<?php endif; ?>

<?php if (is_impersonating()): ?>
    <div class="sa-alert sa-alert-info" style="border-color:rgba(249, 115, 22, 0.4); background:rgba(249, 115, 22, 0.1);">
        <span style="font-size:18px">🎭</span>
        <div><strong>Une incarnation est déjà active.</strong> Terminez-la d'abord via « Revenir à mon compte » en haut de page.</div>
    </div>
<?php endif; ?>

<div class="sa-card" style="background: linear-gradient(135deg, rgba(251, 191, 36, 0.04) 0%, rgba(245, 158, 11, 0.06) 100%); border-color: rgba(251, 191, 36, 0.25); margin-bottom: 20px;">
    <div style="display:flex; gap:14px; align-items:flex-start;">
        <div style="font-size:22px; flex-shrink:0;">📜</div>
        <div style="font-size:13px; line-height:1.6;">
            <strong>Traçabilité RGPD</strong> — Chaque incarnation est enregistrée : qui, quand, pourquoi, combien de temps, et toutes les actions effectuées. Session auto-terminée après 1 heure.
        </div>
    </div>
</div>

<div class="sa-card" style="margin-bottom:16px;">
    <form method="GET" action="/super-admin/incarner" style="display:flex; gap:12px; flex-wrap:wrap; align-items:flex-end;">
        <div style="flex:1; min-width:240px;">
            <label style="font-size:11px; color:var(--sa-ink-3); text-transform:uppercase; letter-spacing:0.05em; display:block; margin-bottom:4px;">Recherche</label>
            <input type="text" name="q" value="<?= h($search) ?>" placeholder="Email, nom, asso..." style="width:100%; padding:9px 12px; background:#22202F; color:#F3F4F6; border:1px solid rgba(255,255,255,0.1); border-radius:8px; font-size:14px; font-family:inherit;">
        </div>
        <div>
            <label style="font-size:11px; color:var(--sa-ink-3); text-transform:uppercase; letter-spacing:0.05em; display:block; margin-bottom:4px;">Rôle</label>
            <select name="role" style="padding:9px 12px; background:#22202F; color:#F3F4F6; border:1px solid rgba(255,255,255,0.1); border-radius:8px; font-size:14px; font-family:inherit; min-width:160px;">
                <option value="">Tous</option>
                <option value="admin" <?= $roleFilter==='admin'?'selected':'' ?>>Admin asso</option>
                <option value="coordinator" <?= $roleFilter==='coordinator'?'selected':'' ?>>Coordinateur</option>
                <option value="referent" <?= $roleFilter==='referent'?'selected':'' ?>>Référent</option>
                <option value="member" <?= $roleFilter==='member'?'selected':'' ?>>Membre</option>
                <option value="follower" <?= $roleFilter==='follower'?'selected':'' ?>>Follower</option>
                <option value="super_admin" <?= $roleFilter==='super_admin'?'selected':'' ?>>Super Admin</option>
            </select>
        </div>
        <button type="submit" class="sa-btn sa-btn-violet">Filtrer</button>
        <?php if ($search || $roleFilter): ?>
            <a href="/super-admin/incarner" class="sa-btn sa-btn-ghost">Réinitialiser</a>
        <?php endif; ?>
    </form>
</div>

<?php if (empty($users)): ?>
    <div class="sa-card">
        <div class="sa-empty">
            <div class="sa-empty-icon">🔍</div>
            <div class="sa-empty-title">Aucun utilisateur trouvé</div>
        </div>
    </div>
<?php else: ?>
    <div class="sa-table-wrap">
        <table class="sa-table">
            <thead>
                <tr><th>Utilisateur</th><th>Organisation</th><th>Rôle</th><th>Dernière connexion</th><th></th></tr>
            </thead>
            <tbody>
                <?php foreach ($users as $u): ?>
                    <tr>
                        <td>
                            <div class="sa-main-col">
                                <?= h(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? '')) ?>
                                <?php if ((int)$u['is_founder'] === 1): ?>
                                    <span class="sa-badge sa-badge-gold" style="margin-left:6px;font-size:10px;">🏗️ FONDATEUR</span>
                                <?php elseif ((int)$u['is_super_admin'] === 1): ?>
                                    <span class="sa-badge sa-badge-violet" style="margin-left:6px;font-size:10px;">👑 SA</span>
                                <?php endif; ?>
                            </div>
                            <div class="sa-sub-col"><?= h($u['email']) ?></div>
                        </td>
                        <td><?= $u['org_name'] ? h($u['org_name']) : '<span style="color:var(--sa-ink-4);">—</span>' ?></td>
                        <td><span class="sa-badge sa-badge-gray"><?= h($u['role']) ?></span></td>
                        <td style="color:var(--sa-ink-3); font-size:12.5px;">
                            <?= $u['last_login_at'] ? date('d/m/Y H:i', strtotime($u['last_login_at'])) : '<span style="color:var(--sa-ink-4);">Jamais</span>' ?>
                        </td>
                        <td>
                            <button type="button" class="sa-btn sa-btn-sm"
                                    style="background:#F97316;color:white;<?= is_impersonating() ? 'opacity:0.4;cursor:not-allowed;' : '' ?>"
                                    onclick="openImpersonateModal(<?= (int)$u['id'] ?>, '<?= h(addslashes(($u['first_name']??'').' '.($u['last_name']??''))) ?>', '<?= h(addslashes($u['email'])) ?>')"
                                    <?= is_impersonating() ? 'disabled' : '' ?>>
                                🎭 Incarner
                            </button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<div id="imp-modal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.7); z-index:9999; align-items:center; justify-content:center; padding:20px;">
    <div style="background:#1A1828; border:1px solid rgba(255,255,255,0.1); border-radius:16px; max-width:520px; width:100%; padding:32px; box-shadow:0 20px 60px rgba(0,0,0,0.5);">
        <div style="display:flex; align-items:center; gap:12px; margin-bottom:20px;">
            <div style="font-size:28px;">🎭</div>
            <h2 style="margin:0; font-size:20px; font-weight:600; color:#F3F4F6;">Confirmer l'incarnation</h2>
        </div>
        <div style="background:rgba(249, 115, 22, 0.1); border:1px solid rgba(249, 115, 22, 0.3); border-radius:10px; padding:14px 16px; margin-bottom:20px; font-size:13px; line-height:1.6; color:#FED7AA;">
            Vous allez vous connecter en tant que <strong id="imp-target-name">?</strong> (<span id="imp-target-email">?</span>).<br>
            <strong>Toutes vos actions seront enregistrées</strong> pour l'audit RGPD.
        </div>
        <form method="POST" action="/super-admin/incarner">
            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
            <input type="hidden" name="action" value="start">
            <input type="hidden" name="target_user_id" id="imp-target-id" value="">
            <div style="margin-bottom:20px;">
                <label style="display:block; margin-bottom:6px; font-size:12px; color:#9CA3AF; text-transform:uppercase; letter-spacing:0.05em; font-weight:600;">Raison de l'incarnation <span style="color:#F97316;">*</span></label>
                <textarea name="reason" required minlength="10" maxlength="500" rows="3" placeholder="Ex: Diagnostic bug création de projet — ticket #42" style="width:100%; padding:12px 14px; background:#22202F; color:#F3F4F6; border:1px solid rgba(255,255,255,0.1); border-radius:10px; font-size:14px; font-family:inherit; resize:vertical; min-height:80px;"></textarea>
                <div style="font-size:11.5px; color:#6B7280; margin-top:6px;">Minimum 10 caractères · Obligatoire pour traçabilité RGPD</div>
            </div>
            <div style="display:flex; gap:10px; justify-content:flex-end;">
                <button type="button" onclick="closeImpersonateModal()" class="sa-btn sa-btn-ghost">Annuler</button>
                <button type="submit" class="sa-btn" style="background:#F97316; color:white;">🎭 Démarrer</button>
            </div>
        </form>
    </div>
</div>

<script>
function openImpersonateModal(userId, userName, userEmail) {
    document.getElementById('imp-target-id').value = userId;
    document.getElementById('imp-target-name').textContent = userName;
    document.getElementById('imp-target-email').textContent = userEmail;
    document.getElementById('imp-modal').style.display = 'flex';
}
function closeImpersonateModal() {
    document.getElementById('imp-modal').style.display = 'none';
}
document.getElementById('imp-modal').addEventListener('click', function(e) {
    if (e.target === this) closeImpersonateModal();
});
</script>

<?php sa_render_foot(); ?>
