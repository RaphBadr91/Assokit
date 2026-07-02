<?php
/**
 * super-admin-impersonation-logs.php
 * URL : /super-admin/logs-incarnation
 * Historique RGPD de toutes les incarnations
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

$sessionView = (int) ($_GET['session'] ?? 0);

// Stats
$stats = ['sessions_30d' => 0, 'sessions_active' => 0, 'actions_30d' => 0, 'destructive_30d' => 0];
try {
    $row = $pdo->query("SELECT * FROM v_impersonation_stats")->fetch(PDO::FETCH_ASSOC);
    if ($row) $stats = array_merge($stats, $row);
} catch (Throwable $e) {}

// Export CSV
if (!empty($_GET['export']) && $_GET['export'] === 'csv') {
    $stmt = $pdo->query("
        SELECT s.id, s.started_at, s.ended_at, s.reason, s.actions_count,
               s.ip_address, s.auto_ended,
               a.email AS admin_email, a.first_name AS admin_first, a.last_name AS admin_last,
               t.email AS target_email, t.first_name AS target_first, t.last_name AS target_last
        FROM impersonation_sessions s
        LEFT JOIN users a ON a.id = s.admin_user_id
        LEFT JOIN users t ON t.id = s.target_user_id
        ORDER BY s.started_at DESC
    ");
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="incarnations-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fputs($out, "\xEF\xBB\xBF"); // BOM UTF-8 Excel
    fputcsv($out, ['ID','Début','Fin','Admin Email','Admin Nom','Cible Email','Cible Nom','Raison','Actions','IP','Timeout Auto'], ';');
    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($out, [
            $r['id'], $r['started_at'], $r['ended_at'] ?? 'en cours',
            $r['admin_email'], ($r['admin_first'].' '.$r['admin_last']),
            $r['target_email'], ($r['target_first'].' '.$r['target_last']),
            $r['reason'], $r['actions_count'], $r['ip_address'],
            $r['auto_ended'] ? 'OUI' : 'NON',
        ], ';');
    }
    fclose($out);
    exit;
}

// Liste des sessions
$stmt = $pdo->query("
    SELECT s.*,
           a.email AS admin_email, a.first_name AS admin_first, a.last_name AS admin_last,
           t.email AS target_email, t.first_name AS target_first, t.last_name AS target_last, t.role AS target_role,
           o.name AS target_org_name
    FROM impersonation_sessions s
    LEFT JOIN users a ON a.id = s.admin_user_id
    LEFT JOIN users t ON t.id = s.target_user_id
    LEFT JOIN organizations o ON o.id = t.org_id
    ORDER BY s.started_at DESC
    LIMIT 100
");
$sessions = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Si on consulte une session précise
$sessionDetail = null;
$sessionActions = [];
if ($sessionView > 0) {
    $stmt = $pdo->prepare("
        SELECT s.*,
               a.email AS admin_email, a.first_name AS admin_first, a.last_name AS admin_last,
               t.email AS target_email, t.first_name AS target_first, t.last_name AS target_last
        FROM impersonation_sessions s
        LEFT JOIN users a ON a.id = s.admin_user_id
        LEFT JOIN users t ON t.id = s.target_user_id
        WHERE s.id = :id LIMIT 1
    ");
    $stmt->execute([':id' => $sessionView]);
    $sessionDetail = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($sessionDetail) {
        $stmt = $pdo->prepare("SELECT * FROM impersonation_actions_log WHERE session_id = :id ORDER BY created_at ASC LIMIT 500");
        $stmt->execute([':id' => $sessionView]);
        $sessionActions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

sa_render_head('Historique des incarnations');
sa_render_sidebar('dashboard');
?>

<div class="sa-page-head">
    <div>
        <h1 class="sa-page-title">📋 Historique des incarnations
            <span class="sa-badge sa-badge-gold" style="font-size:12px;margin-left:8px;">🏗️ RGPD</span>
        </h1>
        <div class="sa-page-sub">Audit complet de toutes les sessions d'incarnation et des actions réalisées.</div>
    </div>
    <div class="sa-page-actions">
        <a href="/super-admin/logs-incarnation?export=csv" class="sa-btn sa-btn-ghost">📥 Export CSV</a>
        <a href="/super-admin/incarner" class="sa-btn sa-btn-violet">🎭 Nouvelle incarnation</a>
    </div>
</div>

<!-- Stats -->
<div class="sa-kpi-grid" style="margin-bottom:24px;">
    <div class="sa-kpi">
        <div class="sa-kpi-label">Sessions (30j)</div>
        <div class="sa-kpi-value"><?= (int)$stats['sessions_30d'] ?></div>
    </div>
    <div class="sa-kpi">
        <div class="sa-kpi-label">Actives maintenant</div>
        <div class="sa-kpi-value" style="<?= $stats['sessions_active'] > 0 ? 'color:#F97316;' : '' ?>"><?= (int)$stats['sessions_active'] ?></div>
    </div>
    <div class="sa-kpi">
        <div class="sa-kpi-label">Actions loggées (30j)</div>
        <div class="sa-kpi-value"><?= number_format((int)$stats['actions_30d'], 0, ',', ' ') ?></div>
    </div>
    <div class="sa-kpi">
        <div class="sa-kpi-label">Destructives (30j)</div>
        <div class="sa-kpi-value" style="<?= $stats['destructive_30d'] > 0 ? 'color:#EF4444;' : '' ?>"><?= (int)$stats['destructive_30d'] ?></div>
    </div>
</div>

<?php if ($sessionDetail): ?>
    <!-- Détail d'une session -->
    <div class="sa-page-head" style="margin-top:8px;">
        <h2 class="sa-page-title" style="font-size:18px;">
            🔎 Session #<?= (int)$sessionDetail['id'] ?>
            <?php if (!$sessionDetail['ended_at']): ?>
                <span class="sa-badge" style="background:rgba(249,115,22,0.2);color:#F97316;margin-left:8px;font-size:11px;">🎭 EN COURS</span>
            <?php endif; ?>
        </h2>
        <div class="sa-page-actions">
            <a href="/super-admin/logs-incarnation" class="sa-btn sa-btn-ghost">← Retour à la liste</a>
        </div>
    </div>

    <div class="sa-card" style="margin-bottom:16px;">
        <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:14px;">
            <div>
                <div style="font-size:11px;color:var(--sa-ink-3);text-transform:uppercase;letter-spacing:0.05em;">Fondateur</div>
                <div style="font-weight:600;margin-top:4px;"><?= h(($sessionDetail['admin_first'] ?? '').' '.($sessionDetail['admin_last'] ?? '')) ?></div>
                <div style="font-size:12px;color:var(--sa-ink-3);"><?= h($sessionDetail['admin_email']) ?></div>
            </div>
            <div>
                <div style="font-size:11px;color:var(--sa-ink-3);text-transform:uppercase;letter-spacing:0.05em;">Incarné</div>
                <div style="font-weight:600;margin-top:4px;"><?= h(($sessionDetail['target_first'] ?? '').' '.($sessionDetail['target_last'] ?? '')) ?></div>
                <div style="font-size:12px;color:var(--sa-ink-3);"><?= h($sessionDetail['target_email']) ?></div>
            </div>
            <div>
                <div style="font-size:11px;color:var(--sa-ink-3);text-transform:uppercase;letter-spacing:0.05em;">Début → Fin</div>
                <div style="font-weight:600;margin-top:4px;font-size:13px;"><?= h(date('d/m/Y H:i:s', strtotime($sessionDetail['started_at']))) ?></div>
                <div style="font-size:12px;color:var(--sa-ink-3);"><?= $sessionDetail['ended_at'] ? h(date('d/m/Y H:i:s', strtotime($sessionDetail['ended_at']))) : 'En cours' ?></div>
            </div>
            <div>
                <div style="font-size:11px;color:var(--sa-ink-3);text-transform:uppercase;letter-spacing:0.05em;">IP · Actions</div>
                <div style="font-weight:600;margin-top:4px;font-family:monospace;font-size:13px;"><?= h($sessionDetail['ip_address']) ?></div>
                <div style="font-size:12px;color:var(--sa-ink-3);"><?= (int)$sessionDetail['actions_count'] ?> actions</div>
            </div>
        </div>
        <div style="margin-top:16px; padding-top:16px; border-top:1px solid rgba(255,255,255,0.08);">
            <div style="font-size:11px;color:var(--sa-ink-3);text-transform:uppercase;letter-spacing:0.05em;margin-bottom:6px;">Raison saisie</div>
            <div style="font-style:italic;"><?= h($sessionDetail['reason']) ?></div>
        </div>
    </div>

    <h3 style="font-size:16px;margin:24px 0 12px;color:var(--sa-ink);">Actions effectuées (<?= count($sessionActions) ?>)</h3>

    <?php if (empty($sessionActions)): ?>
        <div class="sa-card"><div class="sa-empty"><div class="sa-empty-title">Aucune action enregistrée.</div></div></div>
    <?php else: ?>
        <div class="sa-table-wrap">
            <table class="sa-table">
                <thead><tr><th>Heure</th><th>Type</th><th>Méthode</th><th>URL</th><th>Description</th></tr></thead>
                <tbody>
                <?php foreach ($sessionActions as $a): ?>
                    <tr>
                        <td style="font-family:monospace;font-size:12px;color:var(--sa-ink-3);"><?= h(date('H:i:s', strtotime($a['created_at']))) ?></td>
                        <td>
                            <?php
                            $colors = ['page_view'=>'gray','form_post'=>'violet','destructive'=>'red','api_call'=>'gold','other'=>'gray'];
                            $c = $colors[$a['action_type']] ?? 'gray';
                            ?>
                            <span class="sa-badge sa-badge-<?= $c ?>"><?= h($a['action_type']) ?></span>
                        </td>
                        <td style="font-family:monospace;font-size:12px;"><?= h($a['method'] ?? '') ?></td>
                        <td style="font-family:monospace;font-size:12px;max-width:400px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= h($a['url']) ?></td>
                        <td style="font-size:12.5px;"><?= h($a['description'] ?? '—') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

<?php else: ?>
    <!-- Liste globale -->
    <?php if (empty($sessions)): ?>
        <div class="sa-card">
            <div class="sa-empty">
                <div class="sa-empty-icon">🎭</div>
                <div class="sa-empty-title">Aucune incarnation à ce jour</div>
                <div>Les sessions d'incarnation apparaîtront ici pour audit.</div>
            </div>
        </div>
    <?php else: ?>
        <div class="sa-table-wrap">
            <table class="sa-table">
                <thead><tr><th>Date</th><th>Fondateur</th><th>Incarné</th><th>Raison</th><th>Durée</th><th>Actions</th><th>Statut</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($sessions as $s): ?>
                    <tr>
                        <td style="font-size:12.5px;"><?= h(date('d/m/Y H:i', strtotime($s['started_at']))) ?></td>
                        <td>
                            <div style="font-weight:500;"><?= h(($s['admin_first'] ?? '').' '.($s['admin_last'] ?? '')) ?></div>
                            <div style="font-size:11px;color:var(--sa-ink-3);"><?= h($s['admin_email']) ?></div>
                        </td>
                        <td>
                            <div style="font-weight:500;"><?= h(($s['target_first'] ?? '').' '.($s['target_last'] ?? '')) ?></div>
                            <div style="font-size:11px;color:var(--sa-ink-3);"><?= h($s['target_email']) ?><?= $s['target_org_name'] ? ' · '.h($s['target_org_name']) : '' ?></div>
                        </td>
                        <td style="max-width:240px;font-size:12.5px;font-style:italic;color:var(--sa-ink-3);overflow:hidden;text-overflow:ellipsis;"><?= h(mb_substr($s['reason'], 0, 80)) ?><?= mb_strlen($s['reason']) > 80 ? '…' : '' ?></td>
                        <td style="font-size:12px;">
                            <?php if ($s['ended_at']): ?>
                                <?php
                                $diff = strtotime($s['ended_at']) - strtotime($s['started_at']);
                                $mm = floor($diff / 60);
                                $ss = $diff % 60;
                                echo ($mm > 0 ? $mm.'m ' : '').$ss.'s';
                                ?>
                            <?php else: ?>
                                <span style="color:#F97316;">🔴 en cours</span>
                            <?php endif; ?>
                        </td>
                        <td><?= (int)$s['actions_count'] ?></td>
                        <td>
                            <?php if (!$s['ended_at']): ?>
                                <span class="sa-badge" style="background:rgba(249,115,22,0.2);color:#F97316;">ACTIVE</span>
                            <?php elseif ($s['auto_ended']): ?>
                                <span class="sa-badge sa-badge-gray">Auto timeout</span>
                            <?php else: ?>
                                <span class="sa-badge sa-badge-green">Terminée</span>
                            <?php endif; ?>
                        </td>
                        <td><a href="/super-admin/logs-incarnation?session=<?= (int)$s['id'] ?>" class="sa-btn sa-btn-ghost sa-btn-sm">Détail →</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
<?php endif; ?>

<?php sa_render_foot(); ?>
