<?php
/**
 * fondateur-activity.php — Dashboard d'audit activité utilisateurs
 * URL : /fondateur-activity
 * Visible : Fondateur uniquement
 */

require_once __DIR__ . '/config.php'; // expose $pdo + démarre session
require_once __DIR__ . '/activity-tracker.php';

// =============================================================
// VÉRIFICATION ACCÈS FONDATEUR
// =============================================================
$is_founder = false;
if (!empty($_SESSION['user_id'])) {
    try {
        $stmt = $pdo->prepare("SELECT is_founder, is_super_admin, role FROM users WHERE id = ? AND is_active = 1 LIMIT 1");
        $stmt->execute([(int)$_SESSION['user_id']]);
        $u = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($u && (
            (int)($u['is_founder'] ?? 0) === 1 ||
            (int)($u['is_super_admin'] ?? 0) === 1 ||
            ($u['role'] ?? '') === 'super_admin' ||
            ($u['role'] ?? '') === 'founder'
        )) {
            $is_founder = true;
        }
    } catch (Throwable $e) {
        error_log('fondateur-activity access check: ' . $e->getMessage());
    }
}

if (!$is_founder) {
    if (empty($_SESSION['user_id'])) {
        header('Location: /connexion');
    } else {
        http_response_code(403);
        echo '<h1>403 — Accès réservé au Fondateur</h1>';
        echo '<p><a href="/dashboard">← Retour au tableau de bord</a></p>';
    }
    exit;
}

// =============================================================
// Garantir l'existence des tables (auto-création au 1er chargement)
// =============================================================
activity_ensure_table($pdo);
$cleaned = activity_cleanup_inactive_sessions(30);

// =============================================================
// Filtres
// =============================================================
$filter_event_type = $_GET['type']   ?? '';
$filter_user_email = trim((string)($_GET['email']  ?? ''));
$filter_org        = (int)($_GET['org']    ?? 0);
$filter_period     = $_GET['period'] ?? '24h';
$filter_action     = trim((string)($_GET['action'] ?? ''));
$page              = max(1, (int)($_GET['page'] ?? 1));
$per_page          = 50;

$periods = [
    '1h'  => '1 HOUR',
    '24h' => '24 HOUR',
    '7d'  => '7 DAY',
    '30d' => '30 DAY',
    '90d' => '90 DAY',
];
$interval = $periods[$filter_period] ?? '24 HOUR';

// =============================================================
// WHERE clauses
// =============================================================
$where = ["created_at >= DATE_SUB(NOW(), INTERVAL {$interval})"];
$params = [];

if ($filter_event_type !== '' && in_array($filter_event_type, ['pageview','action','login','logout','login_failed'], true)) {
    $where[] = 'event_type = ?';
    $params[] = $filter_event_type;
}
if ($filter_user_email !== '') {
    $where[] = 'user_email LIKE ?';
    $params[] = '%' . $filter_user_email . '%';
}
if ($filter_org > 0) {
    $where[] = 'organization_id = ?';
    $params[] = $filter_org;
}
if ($filter_action !== '') {
    $where[] = 'event_action LIKE ?';
    $params[] = '%' . $filter_action . '%';
}

$where_sql = implode(' AND ', $where);

// =============================================================
// Stats globales
// =============================================================
$stats = ['total_events' => 0, 'active_sessions' => 0, 'logins_ok' => 0, 'logins_ko' => 0, 'inactive_cleaned' => $cleaned];

try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM assokit_activity_log WHERE $where_sql");
    $stmt->execute($params);
    $stats['total_events'] = (int)$stmt->fetchColumn();

    $stats['active_sessions'] = (int)$pdo->query(
        "SELECT COUNT(*) FROM assokit_active_sessions WHERE last_activity_at >= DATE_SUB(NOW(), INTERVAL 30 MINUTE)"
    )->fetchColumn();

    $stmt = $pdo->prepare("SELECT 
        SUM(event_type = 'login') AS ok,
        SUM(event_type = 'login_failed') AS ko
        FROM assokit_activity_log WHERE $where_sql");
    $stmt->execute($params);
    $logins = $stmt->fetch(PDO::FETCH_ASSOC);
    $stats['logins_ok'] = (int)($logins['ok'] ?? 0);
    $stats['logins_ko'] = (int)($logins['ko'] ?? 0);
} catch (Throwable $e) {
    error_log('fondateur-activity stats: ' . $e->getMessage());
}

// Top utilisateurs
$top_users = [];
try {
    $stmt = $pdo->prepare("SELECT user_email, COUNT(*) AS n 
        FROM assokit_activity_log 
        WHERE $where_sql AND user_email IS NOT NULL 
        GROUP BY user_email 
        ORDER BY n DESC LIMIT 8");
    $stmt->execute($params);
    $top_users = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

// Sessions actives
$active_sessions = [];
try {
    $active_sessions = $pdo->query("
        SELECT s.*, 
               TIMESTAMPDIFF(SECOND, s.started_at, NOW()) AS duration_s,
               TIMESTAMPDIFF(SECOND, s.last_activity_at, NOW()) AS idle_s
        FROM assokit_active_sessions s
        WHERE s.last_activity_at >= DATE_SUB(NOW(), INTERVAL 30 MINUTE)
        ORDER BY s.last_activity_at DESC
        LIMIT 20
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

// Logs paginés
$logs = [];
$total_pages = 1;
try {
    $offset = ($page - 1) * $per_page;
    $stmt = $pdo->prepare("SELECT * FROM assokit_activity_log WHERE $where_sql ORDER BY created_at DESC LIMIT $per_page OFFSET $offset");
    $stmt->execute($params);
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $total_pages = (int)ceil($stats['total_events'] / $per_page);
} catch (Throwable $e) {}

// Helpers
function fmt_duration_act(int $seconds): string {
    if ($seconds < 60) return $seconds . 's';
    if ($seconds < 3600) return floor($seconds / 60) . 'min ' . ($seconds % 60) . 's';
    return floor($seconds / 3600) . 'h ' . floor(($seconds % 3600) / 60) . 'min';
}
function event_color_act(string $type): string {
    return match($type) {
        'login' => '#10b981',
        'login_failed' => '#ef4444',
        'logout' => '#94a3b8',
        'action' => '#667eea',
        'pageview' => '#cbd5e0',
        default => '#94a3b8',
    };
}
function h_act($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex,nofollow">
<title>Activité utilisateurs · Fondateur</title>
<style>
* { box-sizing: border-box; }
body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #0f172a; color: #f1f5f9; margin: 0; padding: 24px; }
.container { max-width: 1400px; margin: 0 auto; }
header.page-head { display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; flex-wrap: wrap; gap: 16px; }
h1 { margin: 0 0 6px; font-size: 26px; }
.subtitle { color: #64748b; font-size: 14px; margin: 0; }
.btn-back { padding: 8px 16px; background: #1e293b; border: 1px solid #334155; color: #e2e8f0; border-radius: 8px; text-decoration: none; font-size: 13px; }
.btn-back:hover { background: #334155; }

.stats-grid { display: grid; grid-template-columns: repeat(5, 1fr); gap: 14px; margin-bottom: 24px; }
.stat-card { background: linear-gradient(135deg, #1e293b 0%, #1a2238 100%); border: 1px solid #334155; border-radius: 12px; padding: 18px; }
.stat-label { font-size: 11px; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 8px; font-weight: 600; }
.stat-value { font-size: 28px; font-weight: 700; color: #f8fafc; }
.stat-sub { font-size: 12px; color: #64748b; margin-top: 4px; }
.stat-card.active { border-color: #10b981; box-shadow: 0 0 0 1px rgba(16, 185, 129, 0.3); }
.stat-card.warning { border-color: #f59e0b; }

.filters { background: #1e293b; border: 1px solid #334155; border-radius: 12px; padding: 16px; margin-bottom: 20px; display: flex; gap: 12px; flex-wrap: wrap; align-items: flex-end; }
.filter-group { display: flex; flex-direction: column; gap: 4px; }
.filter-group label { font-size: 11px; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; font-weight: 600; }
.filter-group input, .filter-group select { padding: 8px 12px; background: #0f172a; border: 1px solid #334155; border-radius: 6px; color: #e2e8f0; font-size: 13px; min-width: 140px; }
.btn-filter { padding: 8px 16px; background: #667eea; color: white; border: 0; border-radius: 6px; font-weight: 600; cursor: pointer; font-size: 13px; height: 36px; }
.btn-filter-clear { padding: 8px 16px; background: transparent; color: #64748b; border: 1px solid #334155; border-radius: 6px; cursor: pointer; font-size: 13px; height: 36px; text-decoration: none; display: inline-flex; align-items: center; }

.section { background: #1e293b; border: 1px solid #334155; border-radius: 12px; padding: 20px; margin-bottom: 20px; }
.section h2 { margin: 0 0 16px; font-size: 16px; display: flex; align-items: center; gap: 8px; }

.sessions-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 12px; }
.session-card { background: #0f172a; border: 1px solid #1e293b; border-radius: 10px; padding: 14px; }
.session-card.idle { border-color: #f59e0b; }
.session-card.fresh { border-color: #10b981; }
.session-email { font-weight: 600; color: #f1f5f9; font-size: 13px; word-break: break-all; }
.session-meta { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 8px; font-size: 11px; color: #64748b; }
.session-meta span { padding: 2px 8px; background: #1e293b; border-radius: 999px; }
.session-meta .meta-fresh { color: #10b981; background: rgba(16, 185, 129, 0.1); }
.session-meta .meta-idle { color: #f59e0b; background: rgba(245, 158, 11, 0.1); }

.top-users-list { display: flex; flex-direction: column; gap: 8px; }
.top-user { display: flex; justify-content: space-between; align-items: center; padding: 10px 14px; background: #0f172a; border-radius: 8px; font-size: 13px; }
.top-user-bar { width: 100%; height: 6px; background: #1e293b; border-radius: 3px; overflow: hidden; margin-top: 6px; }
.top-user-fill { height: 100%; background: linear-gradient(90deg, #667eea, #764ba2); }

table.activity-table { width: 100%; border-collapse: collapse; font-size: 12px; }
.activity-table th { text-align: left; padding: 10px; background: #0f172a; color: #cbd5e0; font-size: 11px; text-transform: uppercase; letter-spacing: 0.05em; font-weight: 600; border-bottom: 1px solid #334155; }
.activity-table td { padding: 10px; border-bottom: 1px solid #1e293b; vertical-align: top; }
.activity-table tr:hover { background: #0f172a; }
.event-pill { display: inline-block; padding: 2px 8px; border-radius: 999px; font-size: 10px; text-transform: uppercase; font-weight: 700; letter-spacing: 0.05em; }
.path-cell { max-width: 280px; word-break: break-all; color: #64748b; font-family: monospace; font-size: 11px; }
.meta-cell { max-width: 320px; color: #64748b; font-family: monospace; font-size: 11px; word-break: break-all; }
.time-cell { white-space: nowrap; color: #64748b; font-size: 11px; }

.pagination { display: flex; gap: 6px; justify-content: center; margin-top: 16px; flex-wrap: wrap; }
.pagination a, .pagination span { padding: 6px 12px; background: #0f172a; color: #64748b; border: 1px solid #334155; border-radius: 6px; text-decoration: none; font-size: 13px; }
.pagination a:hover { background: #334155; color: #f1f5f9; }
.pagination .current { background: #667eea; color: white; border-color: #667eea; }

.empty-state { text-align: center; padding: 40px 20px; color: #64748b; }
.empty-state-icon { font-size: 48px; margin-bottom: 12px; }

@media (max-width: 900px) {
    body { padding: 14px; }
    .stats-grid { grid-template-columns: repeat(2, 1fr); }
    .filters { flex-direction: column; align-items: stretch; }
    .filter-group, .filter-group select, .filter-group input { width: 100%; min-width: 0; }
    .activity-table { font-size: 11px; }
    .activity-table .meta-cell, .activity-table .path-cell { max-width: 150px; }
}
</style>
</head>
<body>
<div class="container">

<header class="page-head">
    <div>
        <h1>🕵️ Activité utilisateurs</h1>
        <p class="subtitle">Dashboard d'audit Fondateur · Tracking complet : login, actions, IP, durée session, inactivité</p>
    </div>
    <a href="/super-admin" class="btn-back">← Retour cockpit Fondateur</a>
</header>

<!-- Stats principales -->
<div class="stats-grid">
    <div class="stat-card active">
        <div class="stat-label">Sessions actives</div>
        <div class="stat-value"><?= $stats['active_sessions'] ?></div>
        <div class="stat-sub">en ligne maintenant</div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Événements (<?= h_act($filter_period) ?>)</div>
        <div class="stat-value"><?= number_format($stats['total_events'], 0, ',', ' ') ?></div>
        <div class="stat-sub">total tracked</div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Connexions OK</div>
        <div class="stat-value" style="color: #10b981;"><?= $stats['logins_ok'] ?></div>
        <div class="stat-sub">authentifications réussies</div>
    </div>
    <div class="stat-card <?= $stats['logins_ko'] > 5 ? 'warning' : '' ?>">
        <div class="stat-label">Connexions KO</div>
        <div class="stat-value" style="color: #ef4444;"><?= $stats['logins_ko'] ?></div>
        <div class="stat-sub">tentatives échouées</div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Sessions expirées</div>
        <div class="stat-value"><?= $cleaned ?></div>
        <div class="stat-sub">timeout 30 min</div>
    </div>
</div>

<!-- Sessions actives -->
<?php if (!empty($active_sessions)): ?>
<div class="section">
    <h2>🟢 Sessions actives en temps réel</h2>
    <div class="sessions-grid">
        <?php foreach ($active_sessions as $s):
            $idle_min = (int)floor(($s['idle_s'] ?? 0) / 60);
            $is_fresh = $idle_min < 5;
            $is_idle  = $idle_min >= 10;
            $cls = $is_fresh ? 'fresh' : ($is_idle ? 'idle' : '');
        ?>
        <div class="session-card <?= $cls ?>">
            <div class="session-email"><?= h_act($s['user_email'] ?? '(anonyme)') ?></div>
            <div class="session-meta">
                <span class="<?= $is_fresh ? 'meta-fresh' : ($is_idle ? 'meta-idle' : '') ?>">
                    <?= $is_fresh ? '🟢 actif' : ($is_idle ? '🟡 inactif ' . $idle_min . 'min' : '⚪ ' . $idle_min . 'min') ?>
                </span>
                <span>📊 <?= (int)$s['pageviews'] ?> pages · <?= (int)$s['actions_count'] ?> actions</span>
                <span>⏱ <?= fmt_duration_act((int)$s['duration_s']) ?></span>
                <span>🌐 <?= h_act($s['ip']) ?></span>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- Filtres -->
<form method="GET" class="filters">
    <div class="filter-group">
        <label>Période</label>
        <select name="period">
            <?php foreach (['1h' => 'Dernière heure', '24h' => '24 heures', '7d' => '7 jours', '30d' => '30 jours', '90d' => '90 jours'] as $k => $v): ?>
                <option value="<?= $k ?>" <?= $filter_period === $k ? 'selected' : '' ?>><?= h_act($v) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="filter-group">
        <label>Type d'événement</label>
        <select name="type">
            <option value="">Tous</option>
            <option value="login" <?= $filter_event_type === 'login' ? 'selected' : '' ?>>🔓 Login</option>
            <option value="login_failed" <?= $filter_event_type === 'login_failed' ? 'selected' : '' ?>>❌ Login échoué</option>
            <option value="logout" <?= $filter_event_type === 'logout' ? 'selected' : '' ?>>🚪 Logout</option>
            <option value="action" <?= $filter_event_type === 'action' ? 'selected' : '' ?>>⚡ Action</option>
            <option value="pageview" <?= $filter_event_type === 'pageview' ? 'selected' : '' ?>>👁 Pageview</option>
        </select>
    </div>
    <div class="filter-group">
        <label>Email</label>
        <input type="text" name="email" value="<?= h_act($filter_user_email) ?>" placeholder="user@email.com">
    </div>
    <div class="filter-group">
        <label>Org. ID</label>
        <input type="number" name="org" value="<?= $filter_org ?: '' ?>" placeholder="42">
    </div>
    <div class="filter-group">
        <label>Action</label>
        <input type="text" name="action" value="<?= h_act($filter_action) ?>" placeholder="facture_created">
    </div>
    <button type="submit" class="btn-filter">Filtrer</button>
    <a href="?" class="btn-filter-clear">Reset</a>
</form>

<!-- Top utilisateurs -->
<?php if (!empty($top_users)):
    $max_n = max(array_column($top_users, 'n'));
?>
<div class="section">
    <h2>🏆 Top utilisateurs (<?= h_act($filter_period) ?>)</h2>
    <div class="top-users-list">
        <?php foreach ($top_users as $u): ?>
        <div>
            <div class="top-user">
                <span><?= h_act($u['user_email']) ?></span>
                <strong><?= number_format((int)$u['n'], 0, ',', ' ') ?> events</strong>
            </div>
            <div class="top-user-bar"><div class="top-user-fill" style="width: <?= ($u['n'] / max(1,$max_n) * 100) ?>%"></div></div>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- Logs détaillés -->
<div class="section">
    <h2>📜 Journal détaillé · Page <?= $page ?> / <?= max(1, $total_pages) ?></h2>
    <?php if (empty($logs)): ?>
        <div class="empty-state">
            <div class="empty-state-icon">🌱</div>
            <p>Aucun événement encore enregistré sur cette période.<br>
            <small>Le tracking commence dès maintenant. Reconnecte-toi et navigue pour voir les premières données.</small></p>
        </div>
    <?php else: ?>
    <div style="overflow-x: auto;">
    <table class="activity-table">
        <thead>
            <tr>
                <th>Date</th>
                <th>Type</th>
                <th>Email / User</th>
                <th>Action</th>
                <th>Cible</th>
                <th>Méta</th>
                <th>Path</th>
                <th>IP</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($logs as $log): ?>
            <tr>
                <td class="time-cell"><?= h_act(date('d/m H:i:s', strtotime($log['created_at']))) ?></td>
                <td><span class="event-pill" style="background: <?= event_color_act($log['event_type']) ?>; color: white;"><?= h_act($log['event_type']) ?></span></td>
                <td><?= h_act($log['user_email'] ?? '—') ?></td>
                <td><?= h_act($log['event_action'] ?? '—') ?></td>
                <td><?= h_act($log['event_target'] ?? '—') ?></td>
                <td class="meta-cell"><?= h_act(mb_substr((string)($log['event_meta'] ?? ''), 0, 120)) ?></td>
                <td class="path-cell"><?= h_act($log['method']) ?> <?= h_act($log['path']) ?></td>
                <td class="time-cell"><?= h_act($log['ip']) ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    </div>

    <?php if ($total_pages > 1): ?>
    <div class="pagination">
        <?php
        $url_fn = function($p) {
            $params = $_GET; $params['page'] = $p;
            return '?' . http_build_query($params);
        };
        if ($page > 1) {
            echo '<a href="' . h_act($url_fn(1)) . '">««</a><a href="' . h_act($url_fn($page - 1)) . '">‹</a>';
        }
        for ($p = max(1, $page - 2); $p <= min($total_pages, $page + 2); $p++) {
            if ($p == $page) echo '<span class="current">' . $p . '</span>';
            else echo '<a href="' . h_act($url_fn($p)) . '">' . $p . '</a>';
        }
        if ($page < $total_pages) {
            echo '<a href="' . h_act($url_fn($page + 1)) . '">›</a><a href="' . h_act($url_fn($total_pages)) . '">»»</a>';
        }
        ?>
    </div>
    <?php endif; ?>
    <?php endif; ?>
</div>

</div>
<script>
// Auto-refresh des sessions actives toutes les 60s
if (document.querySelector('.sessions-grid')) {
    setTimeout(() => location.reload(), 60000);
}
</script>
</body>
</html>
