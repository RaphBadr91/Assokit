<?php
/**
 * super-admin-logs.php — Historique des actions (vPro)
 * =======================================================
 * Version avec le nouveau layout (sidebar, dark mode).
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes-layout.php';
require_once __DIR__ . '/superadmin-layout.php';
require_login();
$user = sa_require_super_admin();

// Pagination
$page = max(1, (int) ($_GET['page'] ?? 1));
$per_page = 50;
$offset = ($page - 1) * $per_page;

// Filtres
$filter_action = $_GET['action'] ?? '';
$filter_org = (int) ($_GET['org_id'] ?? 0);

$where = [];
$params = [];
if ($filter_action !== '') { $where[] = 'l.action = ?'; $params[] = $filter_action; }
if ($filter_org > 0) { $where[] = 'l.target_org_id = ?'; $params[] = $filter_org; }
$where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$stmt = $pdo->prepare("SELECT COUNT(*) FROM platform_activity_log l $where_sql");
$stmt->execute($params);
$total = (int) $stmt->fetchColumn();
$total_pages = max(1, (int) ceil($total / $per_page));

$stmt = $pdo->prepare("
    SELECT l.*,
           u.first_name AS sa_first, u.last_name AS sa_last,
           o.name AS org_name,
           tu.first_name AS tu_first, tu.last_name AS tu_last, tu.email AS tu_email
    FROM platform_activity_log l
    LEFT JOIN users u ON l.super_admin_id = u.id
    LEFT JOIN organizations o ON l.target_org_id = o.id
    LEFT JOIN users tu ON l.target_user_id = tu.id
    $where_sql
    ORDER BY l.created_at DESC
    LIMIT $per_page OFFSET $offset
");
$stmt->execute($params);
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

$orgs = $pdo->query("SELECT id, name FROM organizations ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

function action_info(string $action): array {
    return match($action) {
        'create_org'                  => ['icon' => '➕', 'label' => 'Création asso', 'color' => '#6EE7B7'],
        'update_org'                  => ['icon' => '✏️', 'label' => 'Modif asso', 'color' => '#C4B5FD'],
        'suspend_org'                 => ['icon' => '⏸', 'label' => 'Suspension', 'color' => '#FCA5A5'],
        'reactivate_org'              => ['icon' => '▶',  'label' => 'Réactivation', 'color' => '#6EE7B7'],
        'cancel_org'                  => ['icon' => '✕',  'label' => 'Résiliation', 'color' => '#A1A1AA'],
        'create_super_admin'          => ['icon' => '👑', 'label' => 'Création super admin', 'color' => '#C4B5FD'],
        'update_super_admin'          => ['icon' => '✏️', 'label' => 'Modif super admin', 'color' => '#C4B5FD'],
        'deactivate_super_admin'      => ['icon' => '⏸', 'label' => 'Désactivation SA', 'color' => '#FCA5A5'],
        'reactivate_super_admin'      => ['icon' => '▶',  'label' => 'Réactivation SA', 'color' => '#6EE7B7'],
        'reset_super_admin_password'  => ['icon' => '🔑', 'label' => 'Reset mdp SA', 'color' => '#FCD34D'],
        'create_invoice'              => ['icon' => '💳', 'label' => 'Création facture', 'color' => '#C4B5FD'],
        'mark_invoice_paid'           => ['icon' => '✅', 'label' => 'Facture payée', 'color' => '#6EE7B7'],
        'cancel_invoice'              => ['icon' => '❌', 'label' => 'Facture annulée', 'color' => '#A1A1AA'],
        default                       => ['icon' => '•',  'label' => $action, 'color' => '#A1A1AA'],
    };
}

sa_render_head('Historique');
sa_render_sidebar('logs');
?>

<div class="sa-page-head">
    <div>
        <h1 class="sa-page-title">📜 Historique des actions</h1>
        <div class="sa-page-sub"><?= number_format($total, 0, ',', ' ') ?> action<?= $total > 1 ? 's' : '' ?> tracée<?= $total > 1 ? 's' : '' ?></div>
    </div>
</div>

<!-- Filtres -->
<form method="GET" style="display:flex; gap:10px; margin-bottom:16px; flex-wrap:wrap;">
    <select name="action" style="padding:8px 12px; background:var(--sa-bg-2); border:1px solid var(--sa-border-strong); border-radius:8px; color:var(--sa-ink); font-family:inherit;">
        <option value="">Toutes les actions</option>
        <option value="create_org" <?= $filter_action === 'create_org' ? 'selected' : '' ?>>Création asso</option>
        <option value="suspend_org" <?= $filter_action === 'suspend_org' ? 'selected' : '' ?>>Suspensions</option>
        <option value="create_super_admin" <?= $filter_action === 'create_super_admin' ? 'selected' : '' ?>>Créations SA</option>
        <option value="create_invoice" <?= $filter_action === 'create_invoice' ? 'selected' : '' ?>>Créations facture</option>
        <option value="mark_invoice_paid" <?= $filter_action === 'mark_invoice_paid' ? 'selected' : '' ?>>Paiements</option>
    </select>

    <select name="org_id" style="padding:8px 12px; background:var(--sa-bg-2); border:1px solid var(--sa-border-strong); border-radius:8px; color:var(--sa-ink); font-family:inherit;">
        <option value="">Toutes les assos</option>
        <?php foreach ($orgs as $o): ?>
            <option value="<?= (int) $o['id'] ?>" <?= $filter_org === (int) $o['id'] ? 'selected' : '' ?>><?= h($o['name']) ?></option>
        <?php endforeach; ?>
    </select>

    <button type="submit" class="sa-btn sa-btn-ghost">Filtrer</button>
    <?php if ($filter_action || $filter_org): ?>
        <a href="/super-admin/logs" class="sa-btn sa-btn-ghost">Effacer</a>
    <?php endif; ?>
</form>

<?php if (empty($logs)): ?>
    <div class="sa-card">
        <div class="sa-empty">
            <div class="sa-empty-icon">📭</div>
            <div class="sa-empty-title">Aucune action tracée</div>
            <div>Les actions du super admin apparaîtront ici au fur et à mesure.</div>
        </div>
    </div>
<?php else: ?>
    <div class="sa-card" style="padding: 0;">
        <?php foreach ($logs as $log):
            $a = action_info($log['action']);
            $details = $log['details_json'] ? json_decode($log['details_json'], true) : null;
        ?>
            <div style="display:grid; grid-template-columns: 40px 1fr auto; gap:14px; padding:14px 18px; border-bottom:1px solid var(--sa-border); align-items:flex-start;">
                <div style="width:32px; height:32px; display:flex; align-items:center; justify-content:center; background:rgba(255,255,255,0.05); border-radius:50%; font-size:15px; color:<?= $a['color'] ?>"><?= $a['icon'] ?></div>
                <div style="min-width:0">
                    <div style="font-size:13.5px; font-weight:500; color:<?= $a['color'] ?>; margin-bottom:3px;"><?= h($a['label']) ?></div>
                    <div style="font-size:12px; color:var(--sa-ink-3); display:flex; gap:12px; flex-wrap:wrap;">
                        <span>👤 <?= h(trim(($log['sa_first'] ?? '') . ' ' . ($log['sa_last'] ?? '')) ?: 'Supprimé') ?></span>
                        <?php if ($log['org_name']): ?><span>🏛️ <?= h($log['org_name']) ?></span><?php endif; ?>
                        <?php if ($log['tu_email']): ?><span>🎯 <?= h($log['tu_email']) ?></span><?php endif; ?>
                        <?php if ($log['ip_address']): ?><span>📍 <?= h($log['ip_address']) ?></span><?php endif; ?>
                    </div>
                    <?php if ($details && is_array($details)): ?>
                        <div style="font-size:11.5px; color:var(--sa-ink-4); margin-top:6px; font-family:'Courier New', monospace; background:rgba(0,0,0,0.3); padding:6px 10px; border-radius:6px; white-space:pre-wrap; word-break:break-all;"><?= h(json_encode($details, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) ?></div>
                    <?php endif; ?>
                </div>
                <div style="font-size:11.5px; color:var(--sa-ink-4); white-space:nowrap; text-align:right;">
                    <?= date('d/m/Y', strtotime($log['created_at'])) ?><br>
                    <?= date('H:i:s', strtotime($log['created_at'])) ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <?php if ($total_pages > 1): ?>
        <div style="display:flex; justify-content:center; gap:6px; margin-top:20px;">
            <a href="?page=<?= max(1, $page - 1) ?>&action=<?= h($filter_action) ?>&org_id=<?= $filter_org ?>"
               class="sa-btn sa-btn-ghost sa-btn-sm<?= $page === 1 ? '' : '' ?>" <?= $page === 1 ? 'style="opacity:0.4;pointer-events:none"' : '' ?>>← Précédent</a>

            <?php for ($p = max(1, $page - 2); $p <= min($total_pages, $page + 2); $p++): ?>
                <a href="?page=<?= $p ?>&action=<?= h($filter_action) ?>&org_id=<?= $filter_org ?>"
                   class="sa-btn <?= $p === $page ? 'sa-btn-violet' : 'sa-btn-ghost' ?> sa-btn-sm"><?= $p ?></a>
            <?php endfor; ?>

            <a href="?page=<?= min($total_pages, $page + 1) ?>&action=<?= h($filter_action) ?>&org_id=<?= $filter_org ?>"
               class="sa-btn sa-btn-ghost sa-btn-sm" <?= $page === $total_pages ? 'style="opacity:0.4;pointer-events:none"' : '' ?>>Suivant →</a>
        </div>
    <?php endif; ?>
<?php endif; ?>

<?php sa_render_foot(); ?>
