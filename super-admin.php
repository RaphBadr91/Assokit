<?php
/**
 * super-admin.php — Dashboard v3 (Fondateur ULTIME)
 * ===================================================
 * Affiche :
 *   - Header + notifications si Fondateur
 *   - KPIs standards (MRR, assos, users, IA)
 *   - Alertes (impayes, essais expirants, assos en attente)
 *   - Si Fondateur : bloc "🏗️ Fondateur" + liste fondateurs
 *   - Dernieres assos creees
 *   - Si Fondateur : stats avancees (emails/SMS semaine/mois)
 *   - Quick actions adaptees au role
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes-layout.php';
require_once __DIR__ . '/superadmin-layout.php';
require_once __DIR__ . '/sa-permissions.php';
require_login();
$user = sa_require_super_admin();
$ctx = sa_get_permissions_context();

// =====================================================================
// KPIs communs
// =====================================================================
$nb_orgs_total = (int) $pdo->query("SELECT COUNT(*) FROM organizations")->fetchColumn();
$nb_orgs_active = (int) $pdo->query("SELECT COUNT(*) FROM organizations WHERE status = 'active'")->fetchColumn();
$nb_orgs_trial = (int) $pdo->query("SELECT COUNT(*) FROM organizations WHERE status = 'trial'")->fetchColumn();
$nb_orgs_suspended = (int) $pdo->query("SELECT COUNT(*) FROM organizations WHERE status = 'suspended'")->fetchColumn();

$nb_orgs_pending = 0;
try {
    $nb_orgs_pending = (int) $pdo->query("SELECT COUNT(*) FROM organizations WHERE validation_status = 'pending_founder'")->fetchColumn();
} catch (Throwable $e) {}

$nb_users = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role NOT IN ('super_admin') AND is_active = 1 AND deleted_at IS NULL")->fetchColumn();
$nb_super_admins = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE (role = 'super_admin' OR is_super_admin = 1) AND is_active = 1 AND deleted_at IS NULL")->fetchColumn();
$nb_founders = 0;
try { $nb_founders = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE is_founder = 1 AND is_active = 1 AND deleted_at IS NULL")->fetchColumn(); } catch (Throwable $e) {}

// MRR
$mrr = 0.00;
try {
    $stmt = $pdo->query("
        SELECT COALESCE(SUM(
            CASE
                WHEN billing_cycle = 'monthly' THEN price_ht * (1 + tva_rate/100)
                WHEN billing_cycle = 'yearly' THEN (price_ht * (1 + tva_rate/100)) / 12
                ELSE 0
            END
        ), 0) AS mrr
        FROM subscriptions WHERE status = 'active'
    ");
    $mrr = (float) $stmt->fetchColumn();
} catch (Throwable $e) {}

// Impayes
$nb_unpaid = 0;
$total_unpaid = 0.00;
try {
    $row = $pdo->query("SELECT COUNT(*) AS nb, COALESCE(SUM(amount_ttc), 0) AS total FROM subscription_invoices WHERE status IN ('sent','overdue')")->fetch(PDO::FETCH_ASSOC);
    $nb_unpaid = (int) $row['nb'];
    $total_unpaid = (float) $row['total'];
} catch (Throwable $e) {}

// IA
$ia_stats = ['nb' => 0, 'cost' => 0.00];
try {
    $stmt = $pdo->query("SELECT COUNT(*) AS nb, COALESCE(SUM(ai_cost_euros), 0) AS cost FROM communication_campaigns WHERE ai_generated = 1");
    $ia_stats = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

// Dernieres assos
$stmt = $pdo->query("
    SELECT o.id, o.name, o.status, o.plan, o.created_at,
           COALESCE(o.validation_status, 'validated') AS validation_status,
           (SELECT COUNT(*) FROM users WHERE org_id = o.id AND deleted_at IS NULL AND is_active = 1) AS nb_users
    FROM organizations o
    ORDER BY o.created_at DESC
    LIMIT 5
");
$latest_orgs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Essais expirants (7 jours)
$expiring = [];
try {
    $stmt = $pdo->query("
        SELECT id, name, trial_ends_at,
               DATEDIFF(trial_ends_at, CURDATE()) AS days_left
        FROM organizations
        WHERE status = 'trial' AND trial_ends_at IS NOT NULL
          AND trial_ends_at BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
        ORDER BY trial_ends_at ASC
    ");
    $expiring = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

// Notifs non lues (Fondateur)
$nb_unread_notifs = 0;
if ($ctx['is_founder']) {
    $nb_unread_notifs = sa_count_unread_notifications((int) $user['id']);
}

// =====================================================================
// STATS AVANCEES (Fondateur uniquement)
// =====================================================================
$advanced_stats = null;
if ($ctx['is_founder']) {
    $advanced_stats = [
        'emails' => ['week' => 0, 'month' => 0, 'quarter' => 0, 'year' => 0],
        'sms' => ['week' => 0, 'month' => 0, 'quarter' => 0, 'year' => 0],
    ];
    try {
        // Emails
        $row = $pdo->query("
            SELECT
                SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) AS week,
                SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 1 MONTH) THEN 1 ELSE 0 END) AS month,
                SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 3 MONTH) THEN 1 ELSE 0 END) AS quarter,
                SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 1 YEAR) THEN 1 ELSE 0 END) AS year
            FROM email_log
            WHERE status = 'sent'
        ")->fetch(PDO::FETCH_ASSOC);
        $advanced_stats['emails'] = [
            'week' => (int) $row['week'],
            'month' => (int) $row['month'],
            'quarter' => (int) $row['quarter'],
            'year' => (int) $row['year'],
        ];
    } catch (Throwable $e) {}
    try {
        $row = $pdo->query("
            SELECT
                SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) AS week,
                SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 1 MONTH) THEN 1 ELSE 0 END) AS month,
                SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 3 MONTH) THEN 1 ELSE 0 END) AS quarter,
                SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 1 YEAR) THEN 1 ELSE 0 END) AS year
            FROM sms_log
            WHERE status IN ('sent','delivered')
        ")->fetch(PDO::FETCH_ASSOC);
        $advanced_stats['sms'] = [
            'week' => (int) $row['week'],
            'month' => (int) $row['month'],
            'quarter' => (int) $row['quarter'],
            'year' => (int) $row['year'],
        ];
    } catch (Throwable $e) {}
}

// Fondateurs (carte dashboard)
$founders = [];
try {
    $stmt = $pdo->query("
        SELECT id, first_name, last_name, email, last_login_at, created_at
        FROM users
        WHERE is_founder = 1 AND is_active = 1
        ORDER BY created_at ASC
    ");
    $founders = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

sa_render_head('Dashboard');
sa_render_sidebar('dashboard');
?>

<div class="sa-page-head">
    <div>
        <h1 class="sa-page-title">
            Bienvenue <?= h($user['first_name']) ?>
            <?php if ($ctx['is_founder']): ?>
                <span style="font-size:14px;vertical-align:middle;margin-left:8px;background:linear-gradient(135deg, #FCD34D 0%, #F59E0B 100%);color:#78350F;padding:4px 12px;border-radius:999px;font-weight:600;letter-spacing:0.03em;">
                    🏗️ FONDATEUR
                </span>
            <?php else: ?>
                <span style="font-size:14px;vertical-align:middle;margin-left:8px;" class="sa-badge sa-badge-violet">
                    👑 Super Admin
                </span>
            <?php endif; ?>
        </h1>
        <div class="sa-page-sub">
            <?= $ctx['is_founder']
                ? 'Vous avez <strong>pouvoir absolu</strong> sur la plateforme Assokit'
                : 'Vue d\'ensemble de la plateforme au ' . date('d/m/Y') ?>
        </div>
    </div>
    <div class="sa-page-actions">
        <?php if ($ctx['is_founder'] && $nb_unread_notifs > 0): ?>
            <a href="/super-admin/notifications" class="sa-btn sa-btn-ghost" style="position:relative">
                🔔 Notifications
                <span style="position:absolute;top:-4px;right:-4px;background:#EF4444;color:white;font-size:10px;padding:2px 6px;border-radius:999px;font-weight:600;">
                    <?= $nb_unread_notifs ?>
                </span>
            </a>
        <?php endif; ?>
        <a href="/super-admin/nouvelle-asso" class="sa-btn sa-btn-violet">+ Créer une association</a>
    </div>
</div>

<!-- ============ Alertes ============ -->
<?php if ($ctx['is_founder'] && $nb_orgs_pending > 0): ?>
    <div class="sa-alert sa-alert-info" style="border-color:rgba(251, 191, 36, 0.3);background:rgba(251, 191, 36, 0.08);color:#FCD34D;">
        <span style="font-size:18px">🏗️</span>
        <div>
            <strong>Validation requise</strong> — <?= $nb_orgs_pending ?> association<?= $nb_orgs_pending > 1 ? 's en attente' : ' en attente' ?>
            créée<?= $nb_orgs_pending > 1 ? 's' : '' ?> par un Super Admin.
            <a href="/super-admin/associations?filter=pending" style="color:#FCD34D;text-decoration:underline;margin-left:8px">Voir →</a>
        </div>
    </div>
<?php endif; ?>

<?php if ($nb_unpaid > 0): ?>
    <div class="sa-alert sa-alert-error">
        <span style="font-size:18px">💰</span>
        <div>
            <strong>Attention</strong> — <?= $nb_unpaid ?> facture<?= $nb_unpaid > 1 ? 's' : '' ?> impayée<?= $nb_unpaid > 1 ? 's' : '' ?>
            pour un total de <strong><?= number_format($total_unpaid, 2, ',', ' ') ?> €</strong>.
            <a href="/super-admin/abonnements?filter=unpaid" style="color:#FCA5A5;text-decoration:underline;margin-left:8px">Voir →</a>
        </div>
    </div>
<?php endif; ?>

<?php if (!empty($expiring)): ?>
    <div class="sa-alert sa-alert-info">
        <span style="font-size:18px">⏱</span>
        <div>
            <strong><?= count($expiring) ?> essai<?= count($expiring) > 1 ? 's' : '' ?></strong> expire<?= count($expiring) > 1 ? 'nt' : '' ?> dans les 7 prochains jours :
            <?php foreach ($expiring as $i => $e): ?>
                <a href="/super-admin/associations?id=<?= (int) $e['id'] ?>" style="color:#C4B5FD;margin-left:4px">
                    <?= h($e['name']) ?> (<?= (int) $e['days_left'] ?>j)
                </a><?= $i < count($expiring) - 1 ? ',' : '' ?>
            <?php endforeach; ?>
        </div>
    </div>
<?php endif; ?>

<!-- ============ KPIs principaux ============ -->
<div class="sa-kpi-grid">
    <div class="sa-kpi">
        <div class="sa-kpi-label">MRR (TTC)</div>
        <div class="sa-kpi-value"><?= number_format($mrr, 2, ',', ' ') ?> €</div>
        <div class="sa-kpi-trend">Revenu mensuel récurrent</div>
    </div>
    <div class="sa-kpi">
        <div class="sa-kpi-label">Associations</div>
        <div class="sa-kpi-value"><?= $nb_orgs_total ?></div>
        <div class="sa-kpi-trend">
            <?= $nb_orgs_active ?> actives · <?= $nb_orgs_trial ?> essai
            <?php if ($nb_orgs_suspended > 0): ?> · <span style="color:#FCA5A5"><?= $nb_orgs_suspended ?> suspendues</span><?php endif; ?>
        </div>
    </div>
    <div class="sa-kpi">
        <div class="sa-kpi-label">Utilisateurs</div>
        <div class="sa-kpi-value"><?= number_format($nb_users, 0, ',', ' ') ?></div>
        <div class="sa-kpi-trend">tous rôles confondus</div>
    </div>
    <div class="sa-kpi">
        <div class="sa-kpi-label">Générations IA</div>
        <div class="sa-kpi-value"><?= number_format((int) $ia_stats['nb'], 0, ',', ' ') ?></div>
        <div class="sa-kpi-trend"><?= number_format((float) $ia_stats['cost'], 2, ',', ' ') ?> € dépensés</div>
    </div>
</div>

<!-- ============ Stats avancées FONDATEUR ============ -->
<?php if ($ctx['is_founder'] && $advanced_stats): ?>
<div class="sa-page-head" style="margin-top: 24px;">
    <h2 class="sa-page-title" style="font-size: 17px;">📊 Communications sortantes (Fondateur)</h2>
    <div class="sa-page-actions">
        <a href="/super-admin/stats" class="sa-btn sa-btn-ghost sa-btn-sm">Stats approfondies →</a>
    </div>
</div>

<div class="sa-comm-grid" style="margin-bottom:24px;">
    <div class="sa-card" style="border-color:rgba(127, 119, 221, 0.25);">
        <div class="sa-card-title">📧 Emails envoyés</div>
        <div class="sa-period-grid">
            <div style="padding:12px;background:rgba(127, 119, 221, 0.08);border-radius:10px;">
                <div style="font-size:10.5px;color:var(--sa-ink-3);text-transform:uppercase;letter-spacing:0.05em;">Semaine</div>
                <div style="font-size:22px;font-weight:600;color:#C4B5FD;margin-top:4px;"><?= number_format($advanced_stats['emails']['week'], 0, ',', ' ') ?></div>
            </div>
            <div style="padding:12px;background:rgba(127, 119, 221, 0.08);border-radius:10px;">
                <div style="font-size:10.5px;color:var(--sa-ink-3);text-transform:uppercase;letter-spacing:0.05em;">Mois</div>
                <div style="font-size:22px;font-weight:600;color:#C4B5FD;margin-top:4px;"><?= number_format($advanced_stats['emails']['month'], 0, ',', ' ') ?></div>
            </div>
            <div style="padding:12px;background:rgba(127, 119, 221, 0.08);border-radius:10px;">
                <div style="font-size:10.5px;color:var(--sa-ink-3);text-transform:uppercase;letter-spacing:0.05em;">Trimestre</div>
                <div style="font-size:22px;font-weight:600;color:#C4B5FD;margin-top:4px;"><?= number_format($advanced_stats['emails']['quarter'], 0, ',', ' ') ?></div>
            </div>
            <div style="padding:12px;background:rgba(127, 119, 221, 0.08);border-radius:10px;">
                <div style="font-size:10.5px;color:var(--sa-ink-3);text-transform:uppercase;letter-spacing:0.05em;">Année</div>
                <div style="font-size:22px;font-weight:600;color:#C4B5FD;margin-top:4px;"><?= number_format($advanced_stats['emails']['year'], 0, ',', ' ') ?></div>
            </div>
        </div>
    </div>

    <div class="sa-card" style="border-color:rgba(16, 185, 129, 0.25);">
        <div class="sa-card-title">💬 SMS envoyés</div>
        <div class="sa-period-grid">
            <div style="padding:12px;background:rgba(16, 185, 129, 0.08);border-radius:10px;">
                <div style="font-size:10.5px;color:var(--sa-ink-3);text-transform:uppercase;letter-spacing:0.05em;">Semaine</div>
                <div style="font-size:22px;font-weight:600;color:#6EE7B7;margin-top:4px;"><?= number_format($advanced_stats['sms']['week'], 0, ',', ' ') ?></div>
            </div>
            <div style="padding:12px;background:rgba(16, 185, 129, 0.08);border-radius:10px;">
                <div style="font-size:10.5px;color:var(--sa-ink-3);text-transform:uppercase;letter-spacing:0.05em;">Mois</div>
                <div style="font-size:22px;font-weight:600;color:#6EE7B7;margin-top:4px;"><?= number_format($advanced_stats['sms']['month'], 0, ',', ' ') ?></div>
            </div>
            <div style="padding:12px;background:rgba(16, 185, 129, 0.08);border-radius:10px;">
                <div style="font-size:10.5px;color:var(--sa-ink-3);text-transform:uppercase;letter-spacing:0.05em;">Trimestre</div>
                <div style="font-size:22px;font-weight:600;color:#6EE7B7;margin-top:4px;"><?= number_format($advanced_stats['sms']['quarter'], 0, ',', ' ') ?></div>
            </div>
            <div style="padding:12px;background:rgba(16, 185, 129, 0.08);border-radius:10px;">
                <div style="font-size:10.5px;color:var(--sa-ink-3);text-transform:uppercase;letter-spacing:0.05em;">Année</div>
                <div style="font-size:22px;font-weight:600;color:#6EE7B7;margin-top:4px;"><?= number_format($advanced_stats['sms']['year'], 0, ',', ' ') ?></div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ============ Fondateurs ============ -->
<?php if (!empty($founders)): ?>
<div class="sa-page-head" style="margin-top: 8px;">
    <h2 class="sa-page-title" style="font-size: 18px;">🏗️ Fondateur<?= count($founders) > 1 ? 's' : '' ?> de la plateforme</h2>
</div>

<div class="sa-cards-grid" style="margin-bottom: 20px;">
    <?php foreach ($founders as $f): ?>
        <div class="sa-card" style="background: linear-gradient(135deg, rgba(251, 191, 36, 0.04) 0%, rgba(245, 158, 11, 0.06) 100%); border-color: rgba(251, 191, 36, 0.25); position:relative; overflow:hidden;">
            <div style="position:absolute; top:-20px; right:-20px; font-size:80px; opacity:0.08; transform:rotate(15deg);">🏗️</div>
            <div style="display:flex; align-items:center; gap:14px; position:relative;">
                <div style="width:48px; height:48px; border-radius:14px; background:linear-gradient(135deg, #FCD34D 0%, #F59E0B 100%); display:flex; align-items:center; justify-content:center; font-size:20px; font-weight:600; color:#78350F; flex-shrink:0;">
                    <?= h(strtoupper(mb_substr($f['first_name'] ?? 'F', 0, 1) . mb_substr($f['last_name'] ?? '', 0, 1))) ?>
                </div>
                <div style="flex:1; min-width:0;">
                    <div style="font-weight:600; font-size:15px; letter-spacing:-0.01em;">
                        <?= h($f['first_name'] . ' ' . $f['last_name']) ?>
                    </div>
                    <div style="font-size:12px; color:var(--sa-ink-3); margin-top:2px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">
                        <?= h($f['email']) ?>
                    </div>
                    <div style="margin-top:6px; display:flex; gap:6px; flex-wrap:wrap;">
                        <span class="sa-badge sa-badge-gold" style="font-size:10px;">🏗️ FONDATEUR</span>
                        <span class="sa-badge sa-badge-violet" style="font-size:10px;">👑 Super Admin</span>
                    </div>
                </div>
            </div>
            <?php if ($f['last_login_at']): ?>
                <div style="font-size:11px; color:var(--sa-ink-4); margin-top:12px; padding-top:10px; border-top:1px solid rgba(251, 191, 36, 0.15);">
                    Dernière connexion : <?= date('d/m/Y H:i', strtotime($f['last_login_at'])) ?>
                </div>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- ============ Dernières assos ============ -->
<div class="sa-page-head" style="margin-top: 8px;">
    <h2 class="sa-page-title" style="font-size: 18px;">🏛️ Dernières associations créées</h2>
    <div class="sa-page-actions">
        <a href="/super-admin/associations" class="sa-btn sa-btn-ghost sa-btn-sm">Voir tout →</a>
    </div>
</div>

<?php if (empty($latest_orgs)): ?>
    <div class="sa-card">
        <div class="sa-empty">
            <div class="sa-empty-icon">🏛️</div>
            <div class="sa-empty-title">Aucune association</div>
            <div>Créez la première en cliquant sur le bouton violet en haut.</div>
        </div>
    </div>
<?php else: ?>
    <div class="sa-table-wrap">
        <table class="sa-table">
            <thead>
                <tr>
                    <th>Association</th>
                    <th>Validation</th>
                    <th>Statut</th>
                    <th>Plan</th>
                    <th>Utilisateurs</th>
                    <th>Créée</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($latest_orgs as $o): ?>
                    <tr>
                        <td>
                            <div class="sa-main-col"><?= h($o['name']) ?></div>
                            <div class="sa-sub-col">ID #<?= (int) $o['id'] ?></div>
                        </td>
                        <td>
                            <?php if ($o['validation_status'] === 'pending_founder'): ?>
                                <span class="sa-badge sa-badge-gold">🏗️ En attente</span>
                            <?php elseif ($o['validation_status'] === 'rejected'): ?>
                                <span class="sa-badge sa-badge-red">✕ Refusée</span>
                            <?php else: ?>
                                <span class="sa-badge sa-badge-green">✓ Validée</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="sa-badge <?=
                                match($o['status']) {
                                    'active' => 'sa-badge-green', 'trial' => 'sa-badge-violet',
                                    'suspended' => 'sa-badge-red', 'cancelled' => 'sa-badge-gray',
                                    default => 'sa-badge-gray',
                                } ?>">
                                <?= match($o['status']) {
                                    'active' => '● Active', 'trial' => '⏱ Essai',
                                    'suspended' => '⏸ Suspendue', 'cancelled' => '✕ Résiliée',
                                    default => h($o['status']),
                                } ?>
                            </span>
                        </td>
                        <td><span class="sa-badge sa-badge-gray"><?= h(ucfirst($o['plan'])) ?></span></td>
                        <td><?= (int) $o['nb_users'] ?></td>
                        <td style="color:var(--sa-ink-3); font-size:12.5px;"><?= date('d/m/Y', strtotime($o['created_at'])) ?></td>
                        <td>
                            <a href="/super-admin/associations?id=<?= (int) $o['id'] ?>" class="sa-btn sa-btn-ghost sa-btn-sm">Gérer →</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<!-- ============ Quick actions ============ -->
<div class="sa-quick-actions-grid" style="margin-top: 32px;">

    <a href="/super-admin/nouvelle-asso" class="sa-card" style="display:block; text-decoration:none; transition: border-color 0.15s;" onmouseover="this.style.borderColor='var(--sa-violet)'" onmouseout="this.style.borderColor=''">
        <div style="font-size:24px;margin-bottom:6px">➕</div>
        <div class="sa-card-title">Nouvelle association</div>
        <div style="font-size:12.5px;color:var(--sa-ink-3)">
            <?= $ctx['is_founder'] ? 'Création directe' : 'Soumettre à validation' ?>
        </div>
    </a>

    <a href="/super-admin/abonnements" class="sa-card" style="display:block; text-decoration:none;" onmouseover="this.style.borderColor='var(--sa-violet)'" onmouseout="this.style.borderColor=''">
        <div style="font-size:24px;margin-bottom:6px">💳</div>
        <div class="sa-card-title">Gérer les paiements</div>
        <div style="font-size:12.5px;color:var(--sa-ink-3)">Factures, paiements, impayés</div>
    </a>

    <a href="/super-admin-mairies" class="sa-card" style="display:block; text-decoration:none; border-color:rgba(245, 158, 11, 0.35)!important; background: linear-gradient(135deg, rgba(245, 158, 11, 0.06) 0%, rgba(252, 211, 77, 0.08) 100%); position:relative;" onmouseover="this.style.borderColor='#F59E0B'" onmouseout="this.style.borderColor='rgba(245, 158, 11, 0.35)'">
        <div style="position:absolute; top:14px; right:14px; font-size:10px; background:linear-gradient(135deg, #FCD34D 0%, #F59E0B 100%); color:#78350F; padding:3px 8px; border-radius:5px; font-weight:700; letter-spacing:0.04em;">
            🏛️ MULTI-ASSO
        </div>
        <div style="font-size:24px;margin-bottom:6px">🏛️</div>
        <div class="sa-card-title">Collectivités &amp; Mairies</div>
        <div style="font-size:12.5px;color:var(--sa-ink-3)">Créer / gérer mairies, CAF, départements…</div>
    </a>

    <a href="/admin-cron-login" class="sa-card" style="display:block; text-decoration:none; border-color:rgba(127, 119, 221, 0.35)!important; background: linear-gradient(135deg, rgba(127, 119, 221, 0.06) 0%, rgba(167, 139, 250, 0.08) 100%); position:relative;" onmouseover="this.style.borderColor='var(--sa-violet)'" onmouseout="this.style.borderColor='rgba(127, 119, 221, 0.35)'">
        <div style="position:absolute; top:14px; right:14px; font-size:10px; background:rgba(127, 119, 221, 0.2); color:#A78BFA; padding:3px 8px; border-radius:5px; font-weight:700; letter-spacing:0.04em;">
            🔒 RÉAUTH 15 MIN
        </div>
        <div style="font-size:24px;margin-bottom:6px">🛡</div>
        <div class="sa-card-title">Cockpit CRON</div>
        <div style="font-size:12.5px;color:var(--sa-ink-3)">Relances, essais, renouvellements</div>
    </a>

    <?php if ($ctx['is_founder']): ?>
    <a href="/fondateur-cockpit/societe" class="sa-card" style="display:block; text-decoration:none; border-color:rgba(251, 191, 36, 0.35)!important; background: linear-gradient(135deg, rgba(251, 191, 36, 0.06) 0%, rgba(252, 211, 77, 0.08) 100%); position:relative;" onmouseover="this.style.borderColor='#FCD34D'" onmouseout="this.style.borderColor='rgba(251, 191, 36, 0.35)'">
        <div style="position:absolute; top:14px; right:14px; font-size:10px; background:linear-gradient(135deg, #FCD34D 0%, #F59E0B 100%); color:#78350F; padding:3px 8px; border-radius:5px; font-weight:700; letter-spacing:0.04em;">
            🏗️ FONDATEUR
        </div>
        <div style="font-size:24px;margin-bottom:6px">⚙️</div>
        <div class="sa-card-title">Paramètres société</div>
        <div style="font-size:12.5px;color:var(--sa-ink-3)">Infos légales, TVA, IBAN, logo</div>
    </a>
    <?php endif; ?>

    <?php if ($ctx['is_founder']): ?>
        <a href="/super-admin/super-admins" class="sa-card" style="display:block; text-decoration:none;" onmouseover="this.style.borderColor='var(--sa-violet)'" onmouseout="this.style.borderColor=''">
            <div style="font-size:24px;margin-bottom:6px">👑</div>
            <div class="sa-card-title">Super admins (<?= $nb_super_admins ?>)</div>
            <div style="font-size:12.5px;color:var(--sa-ink-3)">Créer / gérer les SA</div>
        </a>

        <a href="/super-admin/stats" class="sa-card" style="display:block; text-decoration:none; border-color:rgba(251, 191, 36, 0.25)!important; background: linear-gradient(135deg, rgba(251, 191, 36, 0.03) 0%, rgba(245, 158, 11, 0.05) 100%);" onmouseover="this.style.borderColor='#FCD34D'" onmouseout="this.style.borderColor='rgba(251, 191, 36, 0.25)'">
            <div style="font-size:24px;margin-bottom:6px">📊</div>
            <div class="sa-card-title">Statistiques approfondies</div>
            <div style="font-size:12.5px;color:var(--sa-ink-3)">Emails, SMS, usage plateforme</div>
        </a>
    <?php else: ?>
        <a href="/super-admin/associations" class="sa-card" style="display:block; text-decoration:none;" onmouseover="this.style.borderColor='var(--sa-violet)'" onmouseout="this.style.borderColor=''">
            <div style="font-size:24px;margin-bottom:6px">🏛️</div>
            <div class="sa-card-title">Gérer les assos</div>
            <div style="font-size:12.5px;color:var(--sa-ink-3)">Support, modifications</div>
        </a>
    <?php endif; ?>

</div>

<?php sa_render_foot(); ?>
