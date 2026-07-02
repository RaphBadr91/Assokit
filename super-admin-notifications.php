<?php
/**
 * super-admin-notifications.php — Notifications in-app (Fondateur)
 * ==================================================================
 * Liste les notifications recues par le Fondateur :
 *   - Nouvelle asso a valider
 *   - Nouveau ticket support (Pack 2)
 *   - Paiement recu (optionnel)
 *   - Etc.
 *
 * Clic sur une notif → marquee lue + redirection vers target_url.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes-layout.php';
require_once __DIR__ . '/superadmin-layout.php';
require_once __DIR__ . '/sa-permissions.php';
require_login();
$user = sa_require_super_admin();
$ctx = sa_get_permissions_context();

// Action "tout marquer lu"
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && ($_POST['action'] ?? '') === 'mark_all_read'
    && check_csrf($_POST['csrf_token'] ?? '')) {
    sa_mark_all_notifications_read((int) $user['id']);
    header('Location: /super-admin/notifications');
    exit;
}

// Action click sur une notif
if (($_GET['open'] ?? 0) > 0) {
    $notif_id = (int) $_GET['open'];
    try {
        $stmt = $pdo->prepare("SELECT * FROM platform_notifications WHERE id = ? AND recipient_user_id = ?");
        $stmt->execute([$notif_id, (int) $user['id']]);
        $notif = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($notif) {
            $pdo->prepare("UPDATE platform_notifications SET is_read = 1, read_at = NOW() WHERE id = ?")->execute([$notif_id]);
            if ($notif['target_url']) {
                header('Location: ' . $notif['target_url']);
                exit;
            }
        }
    } catch (Throwable $e) {}
    header('Location: /super-admin/notifications');
    exit;
}

// Liste
$notifs = [];
try {
    $stmt = $pdo->prepare("
        SELECT * FROM platform_notifications
        WHERE recipient_user_id = ?
        ORDER BY is_read ASC, created_at DESC
        LIMIT 200
    ");
    $stmt->execute([(int) $user['id']]);
    $notifs = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

$nb_unread = 0;
foreach ($notifs as $n) { if (!$n['is_read']) $nb_unread++; }

function notif_icon(string $type): string {
    return match($type) {
        'new_org_pending'       => '🏗️',
        'new_ticket'            => '💬',
        'payment_received'      => '💰',
        'invoice_overdue'       => '⚠️',
        'super_admin_action'    => '👑',
        default                 => '🔔',
    };
}

sa_render_head('Notifications');
sa_render_sidebar('dashboard');
?>

<div class="sa-breadcrumb">
    <a href="/super-admin">Dashboard</a>
    <span class="sep">›</span>
    Notifications
</div>

<div class="sa-page-head">
    <div>
        <h1 class="sa-page-title">🔔 Notifications</h1>
        <div class="sa-page-sub">
            <?= count($notifs) ?> au total
            <?php if ($nb_unread > 0): ?> · <strong style="color:#FCD34D"><?= $nb_unread ?> non lues</strong><?php endif; ?>
        </div>
    </div>
    <?php if ($nb_unread > 0): ?>
        <div class="sa-page-actions">
            <form method="POST" style="display:inline">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                <input type="hidden" name="action" value="mark_all_read">
                <button type="submit" class="sa-btn sa-btn-ghost">✓ Tout marquer lu</button>
            </form>
        </div>
    <?php endif; ?>
</div>

<?php if (empty($notifs)): ?>
    <div class="sa-card">
        <div class="sa-empty">
            <div class="sa-empty-icon">🔔</div>
            <div class="sa-empty-title">Aucune notification</div>
            <div>Tout est sous contrôle — vous êtes à jour.</div>
        </div>
    </div>
<?php else: ?>
    <div class="sa-card" style="padding:0;">
        <?php foreach ($notifs as $n): ?>
            <a href="/super-admin/notifications?open=<?= (int) $n['id'] ?>"
               style="display:grid; grid-template-columns: 44px 1fr auto; gap:14px; padding:16px 20px; border-bottom:1px solid var(--sa-border); align-items:flex-start; text-decoration:none; color:inherit; transition:background .15s; <?= !$n['is_read'] ? 'background:rgba(127, 119, 221, 0.04);' : '' ?>"
               onmouseover="this.style.background='rgba(255,255,255,0.03)'"
               onmouseout="this.style.background='<?= !$n['is_read'] ? 'rgba(127, 119, 221, 0.04)' : '' ?>'">
                <div style="width:36px; height:36px; display:flex; align-items:center; justify-content:center; background:rgba(255,255,255,0.05); border-radius:50%; font-size:16px;">
                    <?= notif_icon($n['type']) ?>
                </div>
                <div style="min-width:0;">
                    <div style="font-size:14px; font-weight:<?= $n['is_read'] ? '400' : '600' ?>; margin-bottom:3px;">
                        <?= h($n['title']) ?>
                        <?php if (!$n['is_read']): ?>
                            <span style="display:inline-block; width:8px; height:8px; background:#7F77DD; border-radius:50%; margin-left:6px;"></span>
                        <?php endif; ?>
                    </div>
                    <?php if ($n['message']): ?>
                        <div style="font-size:12.5px; color:var(--sa-ink-3); line-height:1.5;"><?= h($n['message']) ?></div>
                    <?php endif; ?>
                </div>
                <div style="font-size:11px; color:var(--sa-ink-4); text-align:right; white-space:nowrap;">
                    <?= date('d/m/Y', strtotime($n['created_at'])) ?><br>
                    <?= date('H:i', strtotime($n['created_at'])) ?>
                </div>
            </a>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php sa_render_foot(); ?>
