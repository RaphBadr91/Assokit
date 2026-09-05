<?php
/**
 * super-admin-projets.php — Vue globale des projets (CORRIGE)
 * ==============================================================
 * Le super admin voit TOUS les projets de TOUTES les assos.
 *
 * Colonnes REELLES de la table projects :
 *   id, folder_id, name, location, description, objective,
 *   referent_id, budget_planned, budget_used,
 *   progress_percent, participants_count, status,
 *   start_date, end_date, created_at, updated_at, color_theme
 *
 * Statuts : draft, active, warning, done, archived
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes-layout.php';
require_once __DIR__ . '/superadmin-layout.php';
require_once __DIR__ . '/sa-permissions.php';
require_login();
$user = sa_require_super_admin();

// 🏗️ Page reservee aux FONDATEURS : le SA n'a pas acces a la vue globale projets
sa_require_capability('can_view_all_projects');

// Filtres
$filter_org = (int) ($_GET['org_id'] ?? 0);
$filter_status = $_GET['status'] ?? '';
$search = trim($_GET['q'] ?? '');

$where = [];
$params = [];
if ($filter_org > 0) {
    $where[] = 'f.org_id = ?';
    $params[] = $filter_org;
}
if ($filter_status !== '') {
    $where[] = 'p.status = ?';
    $params[] = $filter_status;
}
if ($search !== '') {
    $where[] = 'p.name LIKE ?';
    $params[] = '%' . $search . '%';
}
$where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$projects = [];
$fetch_error = null;
try {
    $stmt = $pdo->prepare("
        SELECT
            p.id, p.name, p.status, p.progress_percent,
            p.budget_planned, p.budget_used,
            p.start_date, p.end_date, p.created_at,
            f.name AS folder_name, f.org_id,
            o.name AS org_name, o.status AS org_status,
            u.first_name AS ref_first, u.last_name AS ref_last
        FROM projects p
        JOIN folders f ON p.folder_id = f.id
        JOIN organizations o ON f.org_id = o.id
        LEFT JOIN users u ON p.referent_id = u.id
        $where_sql
        ORDER BY p.created_at DESC
        LIMIT 500
    ");
    $stmt->execute($params);
    $projects = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $fetch_error = $e->getMessage();
}

// Stats
$total_budget_planned = 0;
$total_budget_used = 0;
$count_by_status = ['draft' => 0, 'active' => 0, 'warning' => 0, 'done' => 0, 'archived' => 0];
foreach ($projects as $p) {
    $total_budget_planned += (float) ($p['budget_planned'] ?? 0);
    $total_budget_used += (float) ($p['budget_used'] ?? 0);
    if (isset($count_by_status[$p['status']])) {
        $count_by_status[$p['status']]++;
    }
}

$orgs_list = $pdo->query("SELECT id, name FROM organizations ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

function proj_status_info(string $status): array {
    return match($status) {
        'draft'    => ['label' => '📝 Brouillon',    'badge' => 'sa-badge-gray',   'color' => '#A1A1AA'],
        'active'   => ['label' => '● En cours',      'badge' => 'sa-badge-green',  'color' => '#6EE7B7'],
        'warning'  => ['label' => '⚠ À surveiller',  'badge' => 'sa-badge-amber',  'color' => '#FCD34D'],
        'done'     => ['label' => '✓ Terminé',       'badge' => 'sa-badge-violet', 'color' => '#C4B5FD'],
        'archived' => ['label' => '📦 Archivé',      'badge' => 'sa-badge-gray',   'color' => '#71717A'],
        default    => ['label' => htmlspecialchars($status), 'badge' => 'sa-badge-gray', 'color' => '#A1A1AA'],
    };
}

sa_render_head('Projets');
sa_render_sidebar('projets');
?>

<div class="sa-page-head">
    <div>
        <h1 class="sa-page-title">📁 Projets (toutes assos)</h1>
        <div class="sa-page-sub">
            <?= count($projects) ?> projet<?= count($projects) > 1 ? 's' : '' ?>
            · Budget prévu : <strong><?= number_format($total_budget_planned, 2, ',', ' ') ?> €</strong>
            · Engagé : <strong><?= number_format($total_budget_used, 2, ',', ' ') ?> €</strong>
        </div>
    </div>
</div>

<?php if ($fetch_error): ?>
    <div class="sa-alert sa-alert-error">⚠️ <?= h($fetch_error) ?></div>
<?php endif; ?>

<!-- Stats rapides -->
<div class="sa-kpi-grid">
    <div class="sa-kpi">
        <div class="sa-kpi-label">Total projets</div>
        <div class="sa-kpi-value"><?= count($projects) ?></div>
    </div>
    <div class="sa-kpi">
        <div class="sa-kpi-label">En cours</div>
        <div class="sa-kpi-value" style="color:#6EE7B7"><?= $count_by_status['active'] ?></div>
    </div>
    <div class="sa-kpi">
        <div class="sa-kpi-label">À surveiller</div>
        <div class="sa-kpi-value" style="color:#FCD34D"><?= $count_by_status['warning'] ?></div>
    </div>
    <div class="sa-kpi">
        <div class="sa-kpi-label">Terminés</div>
        <div class="sa-kpi-value" style="color:#C4B5FD"><?= $count_by_status['done'] ?></div>
    </div>
    <div class="sa-kpi">
        <div class="sa-kpi-label">Brouillons/Archivés</div>
        <div class="sa-kpi-value" style="color:#6B7280"><?= $count_by_status['draft'] + $count_by_status['archived'] ?></div>
    </div>
</div>

<!-- Filtres -->
<form method="GET" style="display:flex; gap:10px; margin-bottom:16px; flex-wrap:wrap;">
    <input type="text" name="q" value="<?= h($search) ?>" placeholder="🔍 Rechercher un projet..."
           style="flex:1; min-width:200px; padding:8px 12px; background:var(--sa-bg-2); border:1px solid var(--sa-border-strong); border-radius:8px; color:var(--sa-ink); font-family:inherit;">

    <select name="org_id" style="padding:8px 12px; background:var(--sa-bg-2); border:1px solid var(--sa-border-strong); border-radius:8px; color:var(--sa-ink); font-family:inherit;">
        <option value="">Toutes les assos</option>
        <?php foreach ($orgs_list as $o): ?>
            <option value="<?= (int) $o['id'] ?>" <?= $filter_org === (int) $o['id'] ? 'selected' : '' ?>>
                <?= h($o['name']) ?>
            </option>
        <?php endforeach; ?>
    </select>

    <select name="status" style="padding:8px 12px; background:var(--sa-bg-2); border:1px solid var(--sa-border-strong); border-radius:8px; color:var(--sa-ink); font-family:inherit;">
        <option value="">Tous statuts</option>
        <option value="draft"    <?= $filter_status === 'draft'    ? 'selected' : '' ?>>📝 Brouillons</option>
        <option value="active"   <?= $filter_status === 'active'   ? 'selected' : '' ?>>● En cours</option>
        <option value="warning"  <?= $filter_status === 'warning'  ? 'selected' : '' ?>>⚠ À surveiller</option>
        <option value="done"     <?= $filter_status === 'done'     ? 'selected' : '' ?>>✓ Terminés</option>
        <option value="archived" <?= $filter_status === 'archived' ? 'selected' : '' ?>>📦 Archivés</option>
    </select>

    <button type="submit" class="sa-btn sa-btn-ghost">Filtrer</button>
    <?php if ($filter_org || $filter_status || $search): ?>
        <a href="/super-admin/projets" class="sa-btn sa-btn-ghost">Effacer</a>
    <?php endif; ?>
</form>

<?php if (empty($projects)): ?>
    <div class="sa-card">
        <div class="sa-empty">
            <div class="sa-empty-icon">📁</div>
            <div class="sa-empty-title">Aucun projet</div>
            <div>Les projets créés dans les assos apparaîtront ici.</div>
        </div>
    </div>
<?php else: ?>
    <div class="sa-table-wrap">
        <table class="sa-table">
            <thead>
                <tr>
                    <th>Projet</th>
                    <th>Association</th>
                    <th>Statut</th>
                    <th>Progression</th>
                    <th>Budget</th>
                    <th>Référent</th>
                    <th>Période</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($projects as $p): ?>
                    <?php $s = proj_status_info($p['status']); ?>
                    <tr>
                        <td>
                            <div class="sa-main-col"><?= h($p['name']) ?></div>
                            <div class="sa-sub-col">📂 <?= h($p['folder_name']) ?></div>
                        </td>
                        <td>
                            <a href="/super-admin/associations?id=<?= (int) $p['org_id'] ?>" style="color:#C4B5FD">
                                <?= h($p['org_name']) ?>
                            </a>
                        </td>
                        <td>
                            <span class="sa-badge <?= $s['badge'] ?>"><?= $s['label'] ?></span>
                        </td>
                        <td>
                            <div style="display:flex; align-items:center; gap:8px;">
                                <div style="flex:1; height:6px; background:var(--sa-bg-3); border-radius:999px; overflow:hidden; min-width:60px;">
                                    <div style="width:<?= (int) $p['progress_percent'] ?>%; height:100%; background:<?= $s['color'] ?>; transition:width .3s;"></div>
                                </div>
                                <span style="font-size:12px; color:var(--sa-ink-3); font-variant-numeric:tabular-nums; min-width:32px;">
                                    <?= (int) $p['progress_percent'] ?>%
                                </span>
                            </div>
                        </td>
                        <td>
                            <?php if ($p['budget_planned'] > 0): ?>
                                <div style="font-size:12.5px;">
                                    <strong><?= number_format((float) $p['budget_used'], 0, ',', ' ') ?></strong>
                                    <span style="color:var(--sa-ink-4)">/ <?= number_format((float) $p['budget_planned'], 0, ',', ' ') ?> €</span>
                                </div>
                            <?php else: ?>
                                <span style="color:var(--sa-ink-4)">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($p['ref_first']): ?>
                                <?= h($p['ref_first'] . ' ' . $p['ref_last']) ?>
                            <?php else: ?>
                                <span style="color:var(--sa-ink-4)">—</span>
                            <?php endif; ?>
                        </td>
                        <td style="color:var(--sa-ink-3); font-size:12px;">
                            <?php if ($p['start_date']): ?>
                                <?= date('d/m/Y', strtotime($p['start_date'])) ?>
                                <?php if ($p['end_date']): ?>
                                    <br>→ <?= date('d/m/Y', strtotime($p['end_date'])) ?>
                                <?php endif; ?>
                            <?php else: ?>
                                <?= date('d/m/Y', strtotime($p['created_at'])) ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<?php sa_render_foot(); ?>
