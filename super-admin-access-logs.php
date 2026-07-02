<?php
/**
 * ============================================================
 * ASSOKIT — super-admin-access-logs.php
 * Dashboard d'audit des accès au cockpit SA
 * ============================================================
 * URL : /fondateur-cockpit/access-logs
 * Accès : is_founder=1 UNIQUEMENT
 * ============================================================
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes-layout.php';
require_once __DIR__ . '/superadmin-layout.php';
require_once __DIR__ . '/sa-auth-helpers.php';

// Double auth SA + vérif Fondateur
$me = sa_auth_require();

if ((int)($me['is_founder'] ?? 0) !== 1) {
    http_response_code(403);
    exit('Accès réservé aux Fondateurs.');
}

// ----- Export CSV
if (($_GET['export'] ?? '') === 'csv') {
    $type = $_GET['type'] ?? 'auth';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="sa-' . $type . '-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");

    if ($type === 'access') {
        fputcsv($out, ['ID', 'User', 'Email', 'Method', 'URL', 'IP', 'Date']);
        $rows = $pdo->query("
            SELECT a.id, u.first_name, u.last_name, u.email, a.method, a.url, a.ip_address, a.accessed_at
            FROM sa_access_log a
            LEFT JOIN users u ON u.id = a.user_id
            ORDER BY a.accessed_at DESC LIMIT 5000
        ")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            fputcsv($out, [$r['id'], trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? '')),
                           $r['email'] ?? '', $r['method'], $r['url'], $r['ip_address'], $r['accessed_at']]);
        }
    } else {
        fputcsv($out, ['ID', 'Email', 'Status', 'IP', 'User-Agent', 'Error', 'Date']);
        $rows = $pdo->query("
            SELECT id, email_attempted, status, ip_address, user_agent, error_detail, attempted_at
            FROM sa_auth_logs
            ORDER BY attempted_at DESC LIMIT 5000
        ")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            fputcsv($out, [$r['id'], $r['email_attempted'], $r['status'], $r['ip_address'],
                           $r['user_agent'], $r['error_detail'], $r['attempted_at']]);
        }
    }
    fclose($out);
    exit;
}

// ----- Stats
$stats = [];
try {
    $stats = $pdo->query("SELECT * FROM v_sa_auth_dashboard")->fetch(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) { $stats = []; }

// ----- Tentatives login récentes (50)
$authLogs = $pdo->query("
    SELECT a.*, u.first_name, u.last_name
    FROM sa_auth_logs a
    LEFT JOIN users u ON u.id = a.user_id
    ORDER BY a.attempted_at DESC
    LIMIT 50
")->fetchAll(PDO::FETCH_ASSOC);

// ----- Pages récentes (50)
$accessLogs = $pdo->query("
    SELECT a.*, u.first_name, u.last_name, u.email
    FROM sa_access_log a
    LEFT JOIN users u ON u.id = a.user_id
    ORDER BY a.accessed_at DESC
    LIMIT 50
")->fetchAll(PDO::FETCH_ASSOC);

sa_render_head('Logs accès SA');
sa_render_sidebar('dashboard');
?>

<div class="sa-page-head">
    <div>
        <h1 class="sa-page-title">
            🔐 Logs d'accès SA
            <span style="font-size:14px;vertical-align:middle;margin-left:8px;background:linear-gradient(135deg, #FCD34D 0%, #F59E0B 100%);color:#78350F;padding:4px 12px;border-radius:999px;font-weight:600;">
                🏗️ FONDATEUR
            </span>
        </h1>
        <div class="sa-page-sub">Audit trail des connexions et accès au cockpit Fondateur.</div>
    </div>
    <div class="sa-page-actions">
        <a href="?export=csv&type=auth" class="sa-btn sa-btn-ghost">📥 Export auth CSV</a>
        <a href="?export=csv&type=access" class="sa-btn sa-btn-ghost">📥 Export accès CSV</a>
    </div>
</div>

<!-- ============ KPIs ============ -->
<div class="sa-kpi-grid">
    <div class="sa-kpi">
        <div class="sa-kpi-label">Connexions 30j</div>
        <div class="sa-kpi-value"><?= (int)($stats['logins_30d'] ?? 0) ?></div>
        <div class="sa-kpi-trend"><?= (int)($stats['logins_7d'] ?? 0) ?> sur 7 jours</div>
    </div>
    <div class="sa-kpi">
        <div class="sa-kpi-label">Échecs 30j</div>
        <div class="sa-kpi-value" style="color:<?= (int)($stats['failed_30d'] ?? 0) > 0 ? '#F59E0B' : 'var(--sa-ink)' ?>;">
            <?= (int)($stats['failed_30d'] ?? 0) ?>
        </div>
        <div class="sa-kpi-trend"><?= (int)($stats['rate_limited_30d'] ?? 0) ?> rate-limited</div>
    </div>
    <div class="sa-kpi">
        <div class="sa-kpi-label">IPs uniques</div>
        <div class="sa-kpi-value"><?= (int)($stats['unique_ips_30d'] ?? 0) ?></div>
        <div class="sa-kpi-trend">qui se sont connectées</div>
    </div>
    <div class="sa-kpi">
        <div class="sa-kpi-label">Pages visitées 30j</div>
        <div class="sa-kpi-value"><?= (int)($stats['pages_viewed_30d'] ?? 0) ?></div>
        <div class="sa-kpi-trend">dans /super-admin/*</div>
    </div>
</div>

<!-- ============ TENTATIVES LOGIN ============ -->
<div class="sa-page-head" style="margin-top:16px;">
    <h2 class="sa-page-title" style="font-size:17px;">🔑 50 dernières tentatives de connexion</h2>
</div>

<?php if (empty($authLogs)): ?>
    <div class="sa-card">
        <div class="sa-empty">
            <div class="sa-empty-icon">🔐</div>
            <div class="sa-empty-title">Aucune tentative enregistrée</div>
        </div>
    </div>
<?php else: ?>
    <div class="sa-table-wrap">
        <table class="sa-table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Email</th>
                    <th>Statut</th>
                    <th>IP</th>
                    <th>Erreur</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($authLogs as $l): ?>
                    <tr>
                        <td style="font-family:monospace; font-size:12px; color:var(--sa-ink-3);">
                            <?= date('d/m H:i:s', strtotime($l['attempted_at'])) ?>
                        </td>
                        <td style="font-size:13px;">
                            <?= h($l['email_attempted']) ?>
                            <?php if ($l['first_name']): ?>
                                <div style="font-size:11px; color:var(--sa-ink-3);"><?= h($l['first_name'] . ' ' . $l['last_name']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php
                            $badge = match($l['status']) {
                                'success' => ['sa-badge-green', '✓ Succès'],
                                'logout' => ['sa-badge-gray', '🚪 Logout'],
                                'timeout' => ['sa-badge-gray', '⏱ Timeout'],
                                'rate_limited' => ['sa-badge-red', '🚫 Rate limit'],
                                default => ['sa-badge-red', '✕ ' . str_replace('fail_', '', $l['status'])],
                            };
                            ?>
                            <span class="sa-badge <?= $badge[0] ?>"><?= $badge[1] ?></span>
                        </td>
                        <td style="font-family:monospace; font-size:12px;"><?= h($l['ip_address']) ?></td>
                        <td style="font-size:12px; color:var(--sa-ink-3); max-width:300px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;" title="<?= h($l['error_detail'] ?? '') ?>">
                            <?= h($l['error_detail'] ?? '—') ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<!-- ============ PAGES VISITÉES ============ -->
<div class="sa-page-head" style="margin-top:24px;">
    <h2 class="sa-page-title" style="font-size:17px;">👁 50 dernières pages consultées</h2>
</div>

<?php if (empty($accessLogs)): ?>
    <div class="sa-card">
        <div class="sa-empty">
            <div class="sa-empty-icon">👁</div>
            <div class="sa-empty-title">Aucun accès enregistré</div>
        </div>
    </div>
<?php else: ?>
    <div class="sa-table-wrap">
        <table class="sa-table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>User</th>
                    <th>Méthode</th>
                    <th>URL</th>
                    <th>IP</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($accessLogs as $a): ?>
                    <tr>
                        <td style="font-family:monospace; font-size:12px; color:var(--sa-ink-3);">
                            <?= date('d/m H:i:s', strtotime($a['accessed_at'])) ?>
                        </td>
                        <td style="font-size:13px;">
                            <?= h(trim(($a['first_name'] ?? '') . ' ' . ($a['last_name'] ?? ''))) ?>
                            <div style="font-size:11px; color:var(--sa-ink-3);"><?= h($a['email'] ?? '') ?></div>
                        </td>
                        <td><span class="sa-badge sa-badge-gray"><?= h($a['method']) ?></span></td>
                        <td style="font-family:monospace; font-size:12px; max-width:400px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;" title="<?= h($a['url']) ?>">
                            <?= h($a['url']) ?>
                        </td>
                        <td style="font-family:monospace; font-size:12px;"><?= h($a['ip_address']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<?php sa_render_foot(); ?>
