<?php
/**
 * ============================================================
 * ASSOKIT — Page Notifications (style Facebook)
 * ============================================================
 * URL : /notifications
 * ============================================================
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes-layout.php';
require_once __DIR__ . '/notification-helpers.php';

require_login();

$current = current_user();
$user_id = (int)$current['id'];

// Filtre
$filter = $_GET['filter'] ?? 'all';
$valid_filters = ['all', 'unread', 'message', 'mention', 'team_added', 'step_assigned'];
if (!in_array($filter, $valid_filters)) $filter = 'all';

// Charger les notifs selon le filtre
try {
    $where = "WHERE n.user_id = ?";
    $params = [$user_id];
    
    if ($filter === 'unread') {
        $where .= " AND n.is_read = 0";
    } elseif ($filter !== 'all') {
        $where .= " AND n.notification_type = ?";
        $params[] = $filter;
    }
    
    $stmt = $pdo->prepare("
        SELECT n.id, n.notification_type, n.title, n.body, n.link_url,
               n.is_read, n.read_at, n.created_at,
               n.project_id, n.message_id, n.step_id, n.actor_id,
               u.first_name AS actor_first, u.last_name AS actor_last,
               u.avatar_color AS actor_color
        FROM user_notifications n
        LEFT JOIN users u ON n.actor_id = u.id
        $where
        ORDER BY n.created_at DESC
        LIMIT 100
    ");
    $stmt->execute($params);
    $notifications = $stmt->fetchAll();
} catch (Throwable $e) {
    $notifications = [];
}

$total_unread = ak_notif_count_unread($pdo, $user_id);

// Compter par type
$counts = ['all' => 0, 'unread' => 0, 'message' => 0, 'mention' => 0, 'team_added' => 0, 'step_assigned' => 0];
try {
    $stmt = $pdo->prepare("
        SELECT notification_type, is_read, COUNT(*) AS n
        FROM user_notifications WHERE user_id = ?
        GROUP BY notification_type, is_read
    ");
    $stmt->execute([$user_id]);
    foreach ($stmt->fetchAll() as $row) {
        $counts['all'] += $row['n'];
        if (!$row['is_read']) $counts['unread'] += $row['n'];
        if (isset($counts[$row['notification_type']])) {
            $counts[$row['notification_type']] += $row['n'];
        }
    }
} catch (Throwable $e) {}

render_head('Notifications');
render_sidebar('notifications');
?>

<main class="main">
    
    <div class="main-head">
        <div>
            <h1 class="page-title" style="display:flex; align-items:center; gap:11px;"><?= ak_icon_badge('bell','#059669',36) ?><span>Notifications</span></h1>
            <div class="page-sub">
                <?= $total_unread > 0 ? "<strong>$total_unread</strong> non lue" . ($total_unread > 1 ? 's' : '') : 'Tout est à jour' ?>
            </div>
        </div>
        <?php if ($total_unread > 0): ?>
        <div class="head-actions">
            <button type="button" id="markAllRead" class="btn btn-primary" style="font-size:13px;">
                ✓ Tout marquer comme lu
            </button>
        </div>
        <?php endif; ?>
    </div>
    
    <!-- Onglets filtres -->
    <div class="tabs">
        <a href="/notifications" class="tab <?= $filter === 'all' ? 'active' : '' ?>">
            Toutes <?= $counts['all'] > 0 ? '<span class="tab-badge">' . $counts['all'] . '</span>' : '' ?>
        </a>
        <a href="/notifications?filter=unread" class="tab <?= $filter === 'unread' ? 'active' : '' ?>">
            Non lues <?= $counts['unread'] > 0 ? '<span class="tab-badge" style="background:#EF4444; color:#fff;">' . $counts['unread'] . '</span>' : '' ?>
        </a>
        <a href="/notifications?filter=mention" class="tab <?= $filter === 'mention' ? 'active' : '' ?>">
            <span style="display:inline-flex; vertical-align:-3px;"><?= ak_icon('tag',15) ?></span> Mentions <?= $counts['mention'] > 0 ? '<span class="tab-badge">' . $counts['mention'] . '</span>' : '' ?>
        </a>
        <a href="/notifications?filter=message" class="tab <?= $filter === 'message' ? 'active' : '' ?>">
            <span style="display:inline-flex; vertical-align:-3px;"><?= ak_icon('message',15) ?></span> Messages <?= $counts['message'] > 0 ? '<span class="tab-badge">' . $counts['message'] . '</span>' : '' ?>
        </a>
        <a href="/notifications?filter=team_added" class="tab <?= $filter === 'team_added' ? 'active' : '' ?>">
            <span style="display:inline-flex; vertical-align:-3px;"><?= ak_icon('users',15) ?></span> Équipe <?= $counts['team_added'] > 0 ? '<span class="tab-badge">' . $counts['team_added'] . '</span>' : '' ?>
        </a>
        <a href="/notifications?filter=step_assigned" class="tab <?= $filter === 'step_assigned' ? 'active' : '' ?>">
            <span style="display:inline-flex; vertical-align:-3px;"><?= ak_icon('edit',15) ?></span> Étapes <?= $counts['step_assigned'] > 0 ? '<span class="tab-badge">' . $counts['step_assigned'] . '</span>' : '' ?>
        </a>
    </div>
    
    <?php if (empty($notifications)): ?>
        <div class="empty-state" style="margin-top:60px; text-align:center;">
            <div style="font-size:48px; margin-bottom:12px;">🎉</div>
            <div style="font-size:16px; font-weight:500; color:var(--ink);">Aucune notification</div>
            <div style="font-size:13px; color:var(--ink-3); margin-top:6px;">
                Tu es à jour ! Les notifications apparaîtront ici.
            </div>
        </div>
    <?php else: ?>
        <div style="background:var(--bg); border:1px solid var(--border); border-radius:14px; overflow:hidden;">
            <?php foreach ($notifications as $n): 
                $icon_data = ak_notif_icon($n['notification_type']);
                $is_unread = !$n['is_read'];
                $actor_name = $n['actor_first'] ? trim($n['actor_first'] . ' ' . $n['actor_last']) : '';
                $actor_initials = $n['actor_first'] ? mb_strtoupper(mb_substr($n['actor_first'], 0, 1) . mb_substr($n['actor_last'], 0, 1)) : '';
            ?>
                <a href="<?= h($n['link_url'] ?? '#') ?>" 
                   class="notif-row" 
                   data-notif-id="<?= (int)$n['id'] ?>"
                   style="display:flex; align-items:flex-start; gap:14px; padding:16px 20px; 
                          background:<?= $is_unread ? '#EFF6FF' : 'var(--bg)' ?>;
                          border-bottom:1px solid var(--border); 
                          text-decoration:none; color:inherit;
                          transition: background 0.15s;">
                    
                    <!-- Avatar de l'auteur OU icône type -->
                    <?php if ($actor_name): ?>
                        <div style="position:relative; flex-shrink:0;">
                            <div class="chat-avatar av-<?= h($n['actor_color'] ?? 'blue') ?>" style="width:44px; height:44px; font-size:14px;">
                                <?= h($actor_initials) ?>
                            </div>
                            <!-- Badge type en bas-droite -->
                            <div style="position:absolute; bottom:-4px; right:-4px; width:22px; height:22px; border-radius:50%; background:<?= $icon_data['color'] ?>; display:flex; align-items:center; justify-content:center; font-size:11px; border:2px solid var(--bg);">
                                <?= $icon_data['icon'] ?>
                            </div>
                        </div>
                    <?php else: ?>
                        <div style="width:44px; height:44px; border-radius:50%; background:<?= $icon_data['color'] ?>20; color:<?= $icon_data['color'] ?>; display:flex; align-items:center; justify-content:center; font-size:18px; flex-shrink:0;">
                            <?= $icon_data['icon'] ?>
                        </div>
                    <?php endif; ?>
                    
                    <!-- Contenu -->
                    <div style="flex:1; min-width:0;">
                        <div style="font-size:14px; <?= $is_unread ? 'font-weight:600;' : 'font-weight:400;' ?> color:var(--ink); line-height:1.4;">
                            <?= h($n['title']) ?>
                        </div>
                        <?php if (!empty($n['body'])): ?>
                            <div style="font-size:13px; color:var(--ink-2); margin-top:4px; line-height:1.4; word-break:break-word; overflow:hidden; text-overflow:ellipsis; display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical;">
                                <?= h($n['body']) ?>
                            </div>
                        <?php endif; ?>
                        <div style="font-size:11.5px; color:<?= $is_unread ? '#3B82F6' : 'var(--ink-3)' ?>; font-weight:<?= $is_unread ? '500' : '400' ?>; margin-top:6px;">
                            <?= h(ak_notif_time_ago($n['created_at'])) ?>
                            <?php if ($is_unread): ?>
                                <span style="display:inline-block; width:8px; height:8px; border-radius:50%; background:#3B82F6; margin-left:6px; vertical-align:middle;"></span>
                            <?php endif; ?>
                        </div>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
    
</main>

<script>
// Marquer comme lu au clic
document.querySelectorAll('.notif-row').forEach(function(row) {
    row.addEventListener('click', function(e) {
        var notifId = this.dataset.notifId;
        var formData = new FormData();
        formData.append('csrf_token', '<?= h($_SESSION['csrf_token'] ?? '') ?>');
        formData.append('action', 'mark_one');
        formData.append('notif_id', notifId);
        
        // Fire-and-forget : on ne bloque pas la navigation
        fetch('/action-mark-read', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: formData,
            keepalive: true
        }).catch(function(){});
    });
});

// Bouton "Tout marquer comme lu"
var markAllBtn = document.getElementById('markAllRead');
if (markAllBtn) {
    markAllBtn.addEventListener('click', function() {
        var btn = this;
        btn.disabled = true;
        btn.textContent = 'En cours...';
        
        var formData = new FormData();
        formData.append('csrf_token', '<?= h($_SESSION['csrf_token'] ?? '') ?>');
        formData.append('action', 'mark_all');
        
        fetch('/action-mark-read', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: formData
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.ok) {
                window.location.reload();
            } else {
                alert('Erreur : ' + (data.error || 'inconnue'));
                btn.disabled = false;
                btn.textContent = '✓ Tout marquer comme lu';
            }
        });
    });
}

// Hover effect
document.querySelectorAll('.notif-row').forEach(function(row) {
    row.addEventListener('mouseenter', function() {
        this.style.background = 'var(--bg-2)';
    });
    row.addEventListener('mouseleave', function() {
        var isUnread = this.querySelector('span[style*="background:#3B82F6"]');
        this.style.background = isUnread ? '#EFF6FF' : 'var(--bg)';
    });
});
</script>

<?php render_foot(); ?>
