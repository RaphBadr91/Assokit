<?php
/**
 * support-tickets.php — Liste des tickets de support de l'organisation
 *
 * Affiche tous les tickets de l'org connectée, filtrables par statut.
 */
require_once __DIR__ . '/config.php';
require_login();

$user = current_user();
$org_id = (int)($_SESSION['org_id'] ?? 0);

if (!$org_id) {
    header('Location: /dashboard');
    exit;
}

// Filtres
$filter_status = $_GET['status'] ?? 'all';
$valid_statuses = ['all', 'open', 'in_progress', 'waiting_user', 'resolved', 'closed'];
if (!in_array($filter_status, $valid_statuses, true)) {
    $filter_status = 'all';
}

// Stats globales par statut
$stmt = $pdo->prepare("
    SELECT 
        SUM(CASE WHEN status = 'open' THEN 1 ELSE 0 END) AS nb_open,
        SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) AS nb_progress,
        SUM(CASE WHEN status = 'waiting_user' THEN 1 ELSE 0 END) AS nb_waiting,
        SUM(CASE WHEN status = 'resolved' THEN 1 ELSE 0 END) AS nb_resolved,
        SUM(CASE WHEN status = 'closed' THEN 1 ELSE 0 END) AS nb_closed,
        COUNT(*) AS nb_total
    FROM support_tickets WHERE org_id = ?
");
$stmt->execute([$org_id]);
$stats = $stmt->fetch(PDO::FETCH_ASSOC) ?: [
    'nb_open' => 0, 'nb_progress' => 0, 'nb_waiting' => 0, 'nb_resolved' => 0, 'nb_closed' => 0, 'nb_total' => 0
];

// Liste des tickets selon filtre
$where = "t.org_id = ?";
$params = [$org_id];
if ($filter_status !== 'all') {
    $where .= " AND t.status = ?";
    $params[] = $filter_status;
}

$stmt = $pdo->prepare("
    SELECT 
        t.id, t.title, t.category, t.priority, t.status, t.created_at,
        t.last_message_at, t.last_message_by, t.resolved_at,
        u.first_name AS author_first, u.last_name AS author_last,
        (SELECT COUNT(*) FROM support_messages WHERE ticket_id = t.id) AS nb_messages
    FROM support_tickets t
    LEFT JOIN users u ON t.created_by_user_id = u.id
    WHERE $where
    ORDER BY 
        CASE t.status 
            WHEN 'open' THEN 1 
            WHEN 'in_progress' THEN 2 
            WHEN 'waiting_user' THEN 3 
            WHEN 'resolved' THEN 4 
            WHEN 'closed' THEN 5 
        END,
        t.last_message_at DESC, t.created_at DESC
");
$stmt->execute($params);
$tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Helpers
function h_st($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function status_badge_st($status) {
    $cfg = [
        'open'         => ['label' => '🔵 Ouvert',        'color' => '#3b82f6', 'bg' => 'rgba(59,130,246,0.15)'],
        'in_progress'  => ['label' => '🟡 En cours',      'color' => '#f59e0b', 'bg' => 'rgba(245,158,11,0.15)'],
        'waiting_user' => ['label' => '⏳ Attente client', 'color' => '#a855f7', 'bg' => 'rgba(168,85,247,0.15)'],
        'resolved'     => ['label' => '✅ Résolu',         'color' => '#10b981', 'bg' => 'rgba(16,185,129,0.15)'],
        'closed'       => ['label' => '⚫ Fermé',          'color' => '#94a3b8', 'bg' => 'rgba(148,163,184,0.15)'],
    ];
    return $cfg[$status] ?? ['label' => $status, 'color' => '#94a3b8', 'bg' => 'rgba(148,163,184,0.15)'];
}

function priority_badge_st($prio) {
    $cfg = [
        'low'    => ['label' => 'Faible',  'color' => '#94a3b8'],
        'normal' => ['label' => 'Normal',  'color' => '#3b82f6'],
        'high'   => ['label' => 'Haute',   'color' => '#f59e0b'],
        'urgent' => ['label' => 'URGENT',  'color' => '#ef4444'],
    ];
    return $cfg[$prio] ?? ['label' => $prio, 'color' => '#94a3b8'];
}

function category_label_st($cat) {
    return [
        'question'        => '❓ Question',
        'bug'             => '🐛 Bug',
        'feature_request' => '💡 Suggestion',
        'billing'         => '💳 Facturation',
        'account'         => '👤 Compte',
        'other'           => '📋 Autre',
    ][$cat] ?? $cat;
}

function fmt_relative_st($dt) {
    if (!$dt) return '—';
    $ts = is_string($dt) ? strtotime($dt) : $dt;
    $diff = time() - $ts;
    if ($diff < 60) return "à l'instant";
    if ($diff < 3600) return floor($diff / 60) . " min";
    if ($diff < 86400) return floor($diff / 3600) . "h";
    if ($diff < 86400 * 7) return floor($diff / 86400) . "j";
    return date('d/m/Y', $ts);
}

// Layout
if (function_exists('render_head')) {
    render_head('Support — Tickets');
}
?>
<style>
.st-container { max-width: 1280px; margin: 24px auto; padding: 0 20px; }
.st-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 24px; flex-wrap: wrap; gap: 16px; }
.st-header h1 { font-size: 28px; margin: 0 0 4px; color: #f1f5f9; }
.st-header p { color: #64748b; margin: 0; font-size: 14px; }
.st-cta { background: #3b82f6; color: white; padding: 10px 18px; border-radius: 8px; text-decoration: none; font-weight: 600; font-size: 14px; }
.st-cta:hover { filter: brightness(1.1); }

.st-stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 12px; margin-bottom: 24px; }
.st-stat { background: linear-gradient(135deg, #1e293b 0%, #1a2238 100%); border: 1px solid #334155; padding: 14px 16px; border-radius: 12px; transition: transform 0.15s; cursor: pointer; }
.st-stat:hover { transform: translateY(-2px); border-color: #475569; }
.st-stat.is-active { border-color: #3b82f6; background: linear-gradient(135deg, #1e3a8a30 0%, #1a2238 100%); }
.st-stat-label { font-size: 11px; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 4px; }
.st-stat-value { font-size: 24px; font-weight: 700; color: #f8fafc; }

.st-filters { display: flex; gap: 8px; margin-bottom: 16px; flex-wrap: wrap; }
.st-filter { padding: 6px 14px; background: rgba(255,255,255,0.05); border: 1px solid #334155; color: #64748b; border-radius: 999px; font-size: 13px; text-decoration: none; transition: all 0.15s; }
.st-filter:hover { background: rgba(255,255,255,0.1); }
.st-filter.is-active { background: #3b82f6; color: white; border-color: #3b82f6; }

.st-list { background: #1e293b; border: 1px solid #334155; border-radius: 12px; overflow: hidden; }
.st-row { display: grid; grid-template-columns: 90px 1fr 130px 110px 100px 80px; gap: 16px; padding: 16px 20px; align-items: center; border-bottom: 1px solid #334155; transition: background 0.15s; }
.st-row:last-child { border-bottom: 0; }
.st-row:hover { background: rgba(255,255,255,0.03); }
.st-row.is-header { background: rgba(0,0,0,0.25); font-size: 11px; text-transform: uppercase; color: #64748b; letter-spacing: 0.05em; font-weight: 700; padding: 12px 20px; }
.st-row a { color: inherit; text-decoration: none; }

.st-id { color: #64748b; font-size: 12px; font-family: monospace; }
.st-title { font-weight: 600; color: #f1f5f9; margin-bottom: 4px; line-height: 1.3; }
.st-meta { font-size: 11px; color: #64748b; }
.st-badge { display: inline-block; padding: 3px 10px; border-radius: 999px; font-size: 11px; font-weight: 600; white-space: nowrap; }
.st-badge-prio { padding: 2px 8px; border-radius: 4px; font-size: 10px; font-weight: 700; text-transform: uppercase; }
.st-msg-count { color: #64748b; font-size: 13px; }
.st-time { color: #64748b; font-size: 12px; text-align: right; }

.st-empty { padding: 60px 20px; text-align: center; color: #64748b; }
.st-empty-icon { font-size: 48px; margin-bottom: 12px; opacity: 0.5; }

@media (max-width: 768px) {
    .st-row { grid-template-columns: 1fr; gap: 8px; }
    .st-row.is-header { display: none; }
    .st-meta-mobile { display: flex; gap: 12px; flex-wrap: wrap; align-items: center; }
}
</style>

<div class="st-container">

<div class="st-header">
    <div>
        <h1>🎫 Support — Mes tickets</h1>
        <p>Suivez vos demandes auprès de l'équipe Assokit</p>
    </div>
    <a href="/support/nouveau" class="st-cta">+ Nouveau ticket</a>
</div>

<!-- Stats cliquables = filtres -->
<div class="st-stats">
    <a href="?status=all" style="text-decoration:none;">
        <div class="st-stat <?= $filter_status === 'all' ? 'is-active' : '' ?>">
            <div class="st-stat-label">Tous</div>
            <div class="st-stat-value"><?= (int)$stats['nb_total'] ?></div>
        </div>
    </a>
    <a href="?status=open" style="text-decoration:none;">
        <div class="st-stat <?= $filter_status === 'open' ? 'is-active' : '' ?>">
            <div class="st-stat-label">🔵 Ouverts</div>
            <div class="st-stat-value" style="color: #3b82f6;"><?= (int)$stats['nb_open'] ?></div>
        </div>
    </a>
    <a href="?status=in_progress" style="text-decoration:none;">
        <div class="st-stat <?= $filter_status === 'in_progress' ? 'is-active' : '' ?>">
            <div class="st-stat-label">🟡 En cours</div>
            <div class="st-stat-value" style="color: #f59e0b;"><?= (int)$stats['nb_progress'] ?></div>
        </div>
    </a>
    <a href="?status=waiting_user" style="text-decoration:none;">
        <div class="st-stat <?= $filter_status === 'waiting_user' ? 'is-active' : '' ?>">
            <div class="st-stat-label">⏳ Attente</div>
            <div class="st-stat-value" style="color: #a855f7;"><?= (int)$stats['nb_waiting'] ?></div>
        </div>
    </a>
    <a href="?status=resolved" style="text-decoration:none;">
        <div class="st-stat <?= $filter_status === 'resolved' ? 'is-active' : '' ?>">
            <div class="st-stat-label">✅ Résolus</div>
            <div class="st-stat-value" style="color: #10b981;"><?= (int)$stats['nb_resolved'] ?></div>
        </div>
    </a>
    <a href="?status=closed" style="text-decoration:none;">
        <div class="st-stat <?= $filter_status === 'closed' ? 'is-active' : '' ?>">
            <div class="st-stat-label">⚫ Fermés</div>
            <div class="st-stat-value" style="color: #64748b;"><?= (int)$stats['nb_closed'] ?></div>
        </div>
    </a>
</div>

<!-- Liste -->
<div class="st-list">
    <?php if (empty($tickets)): ?>
        <div class="st-empty">
            <div class="st-empty-icon">🎫</div>
            <p style="font-size: 16px; margin: 0 0 8px; color: #64748b;"><strong>Aucun ticket <?= $filter_status !== 'all' ? status_badge_st($filter_status)['label'] : '' ?></strong></p>
            <p style="margin: 0;">Tu n'as pas de ticket dans cette catégorie.</p>
        </div>
    <?php else: ?>
        <div class="st-row is-header">
            <div>ID</div>
            <div>Sujet</div>
            <div>Catégorie</div>
            <div>Statut</div>
            <div>Messages</div>
            <div style="text-align:right;">Activité</div>
        </div>
        
        <?php foreach ($tickets as $ticket):
            $status_cfg = status_badge_st($ticket['status']);
            $prio_cfg = priority_badge_st($ticket['priority']);
        ?>
        <a href="/support-ticket?id=<?= (int)$ticket['id'] ?>">
            <div class="st-row">
                <div class="st-id">#<?= str_pad((string)$ticket['id'], 5, '0', STR_PAD_LEFT) ?></div>
                <div>
                    <div class="st-title"><?= h_st($ticket['title']) ?></div>
                    <div class="st-meta">
                        Par <?= h_st($ticket['author_first'] . ' ' . substr($ticket['author_last'], 0, 1) . '.') ?> · 
                        <span class="st-badge-prio" style="color: <?= $prio_cfg['color'] ?>;"><?= h_st($prio_cfg['label']) ?></span>
                    </div>
                </div>
                <div style="font-size: 12px; color: #64748b;">
                    <?= h_st(category_label_st($ticket['category'])) ?>
                </div>
                <div>
                    <span class="st-badge" style="background: <?= $status_cfg['bg'] ?>; color: <?= $status_cfg['color'] ?>;">
                        <?= h_st($status_cfg['label']) ?>
                    </span>
                </div>
                <div class="st-msg-count">
                    💬 <?= (int)$ticket['nb_messages'] ?>
                </div>
                <div class="st-time">
                    <?= h_st(fmt_relative_st($ticket['last_message_at'] ?: $ticket['created_at'])) ?>
                </div>
            </div>
        </a>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

</div>

<?php
if (function_exists('render_foot')) {
    render_foot();
}
