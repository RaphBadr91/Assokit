<?php
/**
 * ============================================================
 * ASSOKIT — Administration (admin uniquement)
 * ============================================================
 * Page centrale pour la gestion de l'organisation :
 *   - Onglet Utilisateurs : liste + filtres + création/édition
 *   - Onglet Organisation : (Phase future) paramètres généraux
 *   - Onglet Logs : (Phase future) journal d'activité
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

// Filtres
$filter_role = $_GET['role'] ?? 'all';
$filter_contract = $_GET['contract'] ?? 'all';
$filter_active = $_GET['active'] ?? 'active';
$search = trim($_GET['q'] ?? '');

// Construction de la requête
$where_parts = ['u.org_id = ?'];
$params = [$org_id];

if ($filter_role !== 'all') {
    $where_parts[] = 'u.role = ?';
    $params[] = $filter_role;
}
if ($filter_contract !== 'all') {
    $where_parts[] = 'u.contract_type = ?';
    $params[] = $filter_contract;
}
if ($filter_active === 'active') {
    $where_parts[] = 'u.is_active = 1';
} elseif ($filter_active === 'inactive') {
    $where_parts[] = 'u.is_active = 0';
}
if ($search !== '') {
    $where_parts[] = '(u.first_name LIKE ? OR u.last_name LIKE ? OR u.email LIKE ? OR u.organization_name LIKE ?)';
    $like = '%' . $search . '%';
    $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like;
}

$where_clause = implode(' AND ', $where_parts);

$stmt = $pdo->prepare("
    SELECT u.*,
           creator.first_name AS creator_first, creator.last_name AS creator_last
    FROM users u
    LEFT JOIN users creator ON u.created_by_user_id = creator.id
    WHERE $where_clause
    ORDER BY u.is_active DESC,
             FIELD(u.role, 'admin', 'coordinator', 'referent', 'member', 'follower'),
             u.first_name ASC
");
$stmt->execute($params);
$users = $stmt->fetchAll();

// Statistiques globales de l'org
$stats_stmt = $pdo->prepare("
    SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) AS active,
        SUM(CASE WHEN role = 'admin' THEN 1 ELSE 0 END) AS admins,
        SUM(CASE WHEN role = 'coordinator' THEN 1 ELSE 0 END) AS coords,
        SUM(CASE WHEN role = 'referent' THEN 1 ELSE 0 END) AS referents,
        SUM(CASE WHEN role = 'member' THEN 1 ELSE 0 END) AS members,
        SUM(CASE WHEN role = 'follower' THEN 1 ELSE 0 END) AS followers,
        SUM(CASE WHEN contract_type = 'intern' THEN 1 ELSE 0 END) AS interns,
        SUM(CASE WHEN contract_type = 'civic_service' THEN 1 ELSE 0 END) AS civic_services
    FROM users WHERE org_id = ?
");
$stats_stmt->execute([$org_id]);
$stats = $stats_stmt->fetch();

// Helpers d'affichage
function role_badge_class($role) {
    return [
        'admin' => 'role-admin',
        'coordinator' => 'role-coord',
        'referent' => 'role-ref',
        'member' => 'role-member',
        'follower' => 'role-follower',
    ][$role] ?? 'role-member';
}
function role_badge_label($role) {
    return [
        'admin' => 'Administrateur',
        'coordinator' => 'Coordinateur',
        'referent' => 'Référent',
        'member' => 'Membre',
        'follower' => 'Suivi projet',
    ][$role] ?? 'Membre';
}
function contract_label($contract) {
    return [
        'volunteer' => ['Bénévole', '🤝'],
        'employee' => ['Salarié', '💼'],
        'intern' => ['Stagiaire', '🎓'],
        'civic_service' => ['Service civique', '🧭'],
        'contractor' => ['Prestataire', '🔧'],
        'external' => ['Externe', '🌐'],
    ][$contract] ?? ['Bénévole', '🤝'];
}
function avatar_color_hex($key) {
    return [
        'blue' => '#4F80BD', 'purple' => '#7F77DD', 'amber' => '#EF9F27',
        'pink' => '#D77CA0', 'teal' => '#2AAE89', 'green' => '#059669',
        'red' => '#B91C1C', 'gray' => '#78716C'
    ][$key] ?? '#78716C';
}

render_head('Administration');
render_sidebar('admin');
?>

<main class="main">

  <nav class="crumbs">
    <a href="/dashboard">Dashboard</a>
    <span class="sep">›</span>
    <span class="current">Administration</span>
  </nav>

  <div class="main-head">
    <div>
      <h1 class="page-title">Administration</h1>
      <div class="page-sub">Gérez les comptes de votre organisation</div>
    </div>
    <div class="head-actions">
      <a href="/admin-nouveau-utilisateur" class="btn btn-primary">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
        Créer un compte
      </a>
    </div>
  </div>

  <?php if (isset($_GET['created'])): ?>
    <div class="alert alert-success">✅ Compte créé avec succès. L'utilisateur peut maintenant se connecter.</div>
  <?php elseif (isset($_GET['updated'])): ?>
    <div class="alert alert-success">✅ Profil mis à jour.</div>
  <?php elseif (isset($_GET['deactivated'])): ?>
    <div class="alert alert-success">✅ Compte désactivé. L'utilisateur ne peut plus se connecter.</div>
  <?php elseif (isset($_GET['reactivated'])): ?>
    <div class="alert alert-success">✅ Compte réactivé.</div>
  <?php elseif (isset($_GET['password_reset'])): ?>
    <div class="alert alert-success">
      🔑 Mot de passe réinitialisé. <strong>Nouveau mot de passe temporaire :</strong>
      <code style="background: #fff; padding: 3px 8px; border-radius: 4px; margin-left: 8px;"><?= h($_GET['password_reset']) ?></code>
      (l'utilisateur devra le changer à sa prochaine connexion)
    </div>
  <?php endif; ?>

  <!-- Stats de l'org -->
  <div class="admin-stats">
    <div class="stat-card">
      <div class="stat-value"><?= (int)$stats['active'] ?><span class="stat-total">/<?= (int)$stats['total'] ?></span></div>
      <div class="stat-label">Comptes actifs</div>
    </div>
    <div class="stat-card">
      <div class="stat-value"><?= (int)$stats['admins'] ?></div>
      <div class="stat-label">Administrateurs</div>
    </div>
    <div class="stat-card">
      <div class="stat-value"><?= (int)$stats['coords'] ?></div>
      <div class="stat-label">Coordinateurs</div>
    </div>
    <div class="stat-card">
      <div class="stat-value"><?= (int)$stats['referents'] ?></div>
      <div class="stat-label">Référents</div>
    </div>
    <div class="stat-card">
      <div class="stat-value"><?= (int)$stats['members'] ?></div>
      <div class="stat-label">Membres</div>
    </div>
    <div class="stat-card stat-highlight">
      <div class="stat-value"><?= (int)$stats['followers'] ?></div>
      <div class="stat-label">Suivis projet</div>
    </div>
  </div>

  <!-- Filtres -->
  <form method="GET" action="/admin" class="admin-filters">
    <div class="filter-search">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
      <input type="text" name="q" value="<?= h($search) ?>" placeholder="Rechercher par nom, email, organisation..." class="filter-search-input">
    </div>

    <div class="filter-group">
      <select name="role" onchange="this.form.submit()" class="filter-select">
        <option value="all" <?= $filter_role === 'all' ? 'selected' : '' ?>>Tous les rôles</option>
        <option value="admin" <?= $filter_role === 'admin' ? 'selected' : '' ?>>👑 Administrateurs</option>
        <option value="coordinator" <?= $filter_role === 'coordinator' ? 'selected' : '' ?>>🎯 Coordinateurs</option>
        <option value="referent" <?= $filter_role === 'referent' ? 'selected' : '' ?>>📋 Référents</option>
        <option value="member" <?= $filter_role === 'member' ? 'selected' : '' ?>>👤 Membres</option>
        <option value="follower" <?= $filter_role === 'follower' ? 'selected' : '' ?>>👁️ Suivis projet</option>
      </select>

      <select name="contract" onchange="this.form.submit()" class="filter-select">
        <option value="all" <?= $filter_contract === 'all' ? 'selected' : '' ?>>Tous les contrats</option>
        <option value="volunteer" <?= $filter_contract === 'volunteer' ? 'selected' : '' ?>>🤝 Bénévoles</option>
        <option value="employee" <?= $filter_contract === 'employee' ? 'selected' : '' ?>>💼 Salariés</option>
        <option value="intern" <?= $filter_contract === 'intern' ? 'selected' : '' ?>>🎓 Stagiaires</option>
        <option value="civic_service" <?= $filter_contract === 'civic_service' ? 'selected' : '' ?>>🧭 Service civique</option>
        <option value="contractor" <?= $filter_contract === 'contractor' ? 'selected' : '' ?>>🔧 Prestataires</option>
        <option value="external" <?= $filter_contract === 'external' ? 'selected' : '' ?>>🌐 Externes</option>
      </select>

      <select name="active" onchange="this.form.submit()" class="filter-select">
        <option value="active" <?= $filter_active === 'active' ? 'selected' : '' ?>>Actifs uniquement</option>
        <option value="inactive" <?= $filter_active === 'inactive' ? 'selected' : '' ?>>Désactivés</option>
        <option value="all" <?= $filter_active === 'all' ? 'selected' : '' ?>>Tous</option>
      </select>

      <?php if ($filter_role !== 'all' || $filter_contract !== 'all' || $filter_active !== 'active' || $search !== ''): ?>
        <a href="/admin" class="filter-reset">Réinitialiser</a>
      <?php endif; ?>
    </div>

    <button type="submit" style="display: none;"></button>
  </form>

  <!-- Liste des utilisateurs -->
  <div class="admin-table">
    <?php if (empty($users)): ?>
      <div class="empty-state">
        <div style="font-size: 36px; margin-bottom: 10px;">🔍</div>
        <div style="font-size: 14px; color: var(--ink-2); margin-bottom: 4px;">Aucun utilisateur trouvé</div>
        <div style="font-size: 12.5px; color: var(--ink-3);">Essayez d'ajuster les filtres ou la recherche.</div>
      </div>
    <?php else: ?>
      <?php foreach ($users as $u):
        $contract = contract_label($u['contract_type']);
        $avatar_hex = avatar_color_hex($u['avatar_color'] ?? 'gray');
        $capabilities = [];
        if ($u['can_create_projects']) $capabilities[] = 'Projets';
        if ($u['can_manage_members']) $capabilities[] = 'Adhérents';
        if ($u['can_manage_finances']) $capabilities[] = 'Factures';
        if ($u['can_access_marketing']) $capabilities[] = 'Marketing';
        if ($u['can_manage_events']) $capabilities[] = 'Événements';
        if ($u['can_moderate_messages']) $capabilities[] = 'Modération';
      ?>
        <a href="/admin-modifier-utilisateur/<?= (int)$u['id'] ?>" class="admin-row <?= !$u['is_active'] ? 'inactive' : '' ?>">
          <div class="admin-row-avatar" style="background: <?= $avatar_hex ?>;">
            <?= h(strtoupper(mb_substr($u['first_name'], 0, 1) . mb_substr($u['last_name'], 0, 1))) ?>
          </div>

          <div class="admin-row-main">
            <div class="admin-row-name">
              <?= h($u['first_name'] . ' ' . $u['last_name']) ?>
              <?php if (!$u['is_active']): ?>
                <span class="admin-row-tag-inactive">Désactivé</span>
              <?php endif; ?>
              <?php if ($u['must_change_password']): ?>
                <span class="admin-row-tag-must-change">🔑 Mdp temp.</span>
              <?php endif; ?>
            </div>
            <div class="admin-row-email"><?= h($u['email']) ?></div>
            <?php if ($u['organization_name']): ?>
              <div class="admin-row-org">🏛️ <?= h($u['organization_name']) ?></div>
            <?php endif; ?>
          </div>

          <div class="admin-row-badges">
            <span class="role-badge <?= role_badge_class($u['role']) ?>"><?= h(role_badge_label($u['role'])) ?></span>
            <span class="contract-badge"><?= $contract[1] ?> <?= h($contract[0]) ?></span>
            <?php if ($u['contract_end_date']): ?>
              <?php $end_ts = strtotime($u['contract_end_date']); $now = time(); $is_near = ($end_ts - $now) < 30 * 86400; ?>
              <span class="contract-date <?= $is_near ? 'near-end' : '' ?>" title="Fin de contrat">📅 <?= h(date('d/m/Y', $end_ts)) ?></span>
            <?php endif; ?>
          </div>

          <?php if (!empty($capabilities)): ?>
            <div class="admin-row-caps">
              <?php foreach (array_slice($capabilities, 0, 3) as $cap): ?>
                <span class="cap-pill"><?= h($cap) ?></span>
              <?php endforeach; ?>
              <?php if (count($capabilities) > 3): ?>
                <span class="cap-pill">+<?= count($capabilities) - 3 ?></span>
              <?php endif; ?>
            </div>
          <?php endif; ?>

          <svg class="admin-row-arrow" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><polyline points="9 18 15 12 9 6"/></svg>
        </a>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>

</main>

<style>
/* Stats admin */
.admin-stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(130px, 1fr)); gap: 10px; margin-bottom: 24px; }
.stat-card { background: var(--bg); border: 1px solid var(--border); border-radius: 10px; padding: 14px 16px; }
.stat-card.stat-highlight { background: var(--ai-light); border-color: var(--ai); }
.stat-value { font-size: 22px; font-weight: 500; letter-spacing: -0.02em; line-height: 1; font-variant-numeric: tabular-nums; }
.stat-total { font-size: 13px; color: var(--ink-3); font-weight: 400; }
.stat-label { font-size: 11.5px; color: var(--ink-3); margin-top: 4px; text-transform: uppercase; letter-spacing: 0.04em; }

/* Filtres */
.admin-filters { display: flex; gap: 10px; align-items: center; margin-bottom: 18px; flex-wrap: wrap; }
.filter-search { flex: 1; min-width: 240px; position: relative; display: flex; align-items: center; }
.filter-search svg { position: absolute; left: 12px; color: var(--ink-3); pointer-events: none; }
.filter-search-input { width: 100%; padding: 9px 14px 9px 34px; border: 1px solid var(--border); border-radius: 10px; font-size: 13px; background: var(--bg); font-family: inherit; }
.filter-search-input:focus { outline: none; border-color: var(--acc); }
.filter-group { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
.filter-select { padding: 9px 14px; border: 1px solid var(--border); border-radius: 10px; background: var(--bg); font-size: 12.5px; color: var(--ink-2); cursor: pointer; font-family: inherit; }
.filter-reset { font-size: 11.5px; color: var(--ink-3); text-decoration: none; padding: 8px 12px; border-radius: 8px; }
.filter-reset:hover { background: var(--bg-2); color: var(--ink); }

/* Liste */
.admin-table { display: flex; flex-direction: column; gap: 6px; }
.admin-row { display: grid; grid-template-columns: 40px 1fr auto auto 16px; gap: 14px; align-items: center; padding: 12px 16px; background: var(--bg); border: 1px solid var(--border); border-radius: 10px; text-decoration: none; transition: border-color 0.12s ease, transform 0.12s ease; cursor: pointer; }
.admin-row:hover { border-color: var(--border-strong); transform: translateY(-1px); }
.admin-row.inactive { opacity: 0.55; }
.admin-row-avatar { width: 40px; height: 40px; border-radius: 50%; color: #fff; display: flex; align-items: center; justify-content: center; font-size: 13px; font-weight: 600; flex-shrink: 0; }
.admin-row-main { min-width: 0; }
.admin-row-name { font-size: 14px; font-weight: 500; display: flex; align-items: center; gap: 8px; flex-wrap: wrap; margin-bottom: 2px; }
.admin-row-email { font-size: 12.5px; color: var(--ink-3); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.admin-row-org { font-size: 11.5px; color: var(--ink-3); margin-top: 2px; }
.admin-row-tag-inactive { font-size: 10px; color: #B91C1C; padding: 1px 6px; background: rgba(185,28,28,0.1); border-radius: 4px; font-weight: 500; }
.admin-row-tag-must-change { font-size: 10px; color: #92400E; padding: 1px 6px; background: rgba(217,119,6,0.12); border-radius: 4px; font-weight: 500; }
.admin-row-badges { display: flex; gap: 6px; flex-wrap: wrap; align-items: center; }
.role-badge { font-size: 11px; font-weight: 500; padding: 3px 8px; border-radius: 20px; white-space: nowrap; }
.role-admin { background: rgba(127, 119, 221, 0.15); color: #3C3489; }
.role-coord { background: rgba(5, 150, 105, 0.12); color: var(--acc-dark); }
.role-ref { background: rgba(79, 128, 189, 0.15); color: #0C447C; }
.role-member { background: var(--bg-2); color: var(--ink-2); }
.role-follower { background: rgba(239, 159, 39, 0.15); color: #633806; }
.contract-badge { font-size: 11px; color: var(--ink-3); padding: 3px 8px; background: var(--bg-2); border-radius: 20px; white-space: nowrap; }
.contract-date { font-size: 10.5px; color: var(--ink-3); padding: 3px 7px; background: var(--bg-2); border-radius: 20px; white-space: nowrap; }
.contract-date.near-end { color: #92400E; background: rgba(217,119,6,0.12); }
.admin-row-caps { display: flex; gap: 4px; flex-wrap: wrap; max-width: 200px; justify-content: flex-end; }
.cap-pill { font-size: 10px; color: var(--ink-2); padding: 2px 6px; background: var(--bg-2); border: 1px solid var(--border); border-radius: 4px; white-space: nowrap; }
.admin-row-arrow { color: var(--ink-4); flex-shrink: 0; }
@media (max-width: 720px) {
  .admin-row { grid-template-columns: 36px 1fr 16px; gap: 10px; }
  .admin-row-badges, .admin-row-caps { display: none; }
}
</style>

<?php render_foot(); ?>
