<?php
/**
 * super-admin-stats.php — Statistiques approfondies (FONDATEUR uniquement)
 * =========================================================================
 * Affiche :
 *   - Emails : total, par jour sur 30j, top tags, taux d'echec
 *   - SMS : idem
 *   - Assos : croissance
 *   - MRR : evolution
 *   - Usage IA : cout + volume
 *
 * Graphiques en SVG inline (pas de lib externe, leger, rapide).
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes-layout.php';
require_once __DIR__ . '/superadmin-layout.php';
require_once __DIR__ . '/sa-permissions.php';
require_login();
sa_require_super_admin();
sa_require_capability('can_view_platform_stats');

// =====================================================================
// Donnees
// =====================================================================

// Emails par jour (30 derniers jours)
$emails_daily = array_fill(0, 30, 0);
try {
    $stmt = $pdo->query("
        SELECT DATE(created_at) AS d, COUNT(*) AS nb
        FROM email_log
        WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 29 DAY)
          AND status = 'sent'
        GROUP BY DATE(created_at)
        ORDER BY d ASC
    ");
    $raw = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    for ($i = 29; $i >= 0; $i--) {
        $date = date('Y-m-d', strtotime("-$i days"));
        $emails_daily[29 - $i] = (int) ($raw[$date] ?? 0);
    }
} catch (Throwable $e) {}

// SMS par jour
$sms_daily = array_fill(0, 30, 0);
try {
    $stmt = $pdo->query("
        SELECT DATE(created_at) AS d, COUNT(*) AS nb
        FROM sms_log
        WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 29 DAY)
          AND status IN ('sent','delivered')
        GROUP BY DATE(created_at)
        ORDER BY d ASC
    ");
    $raw = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    for ($i = 29; $i >= 0; $i--) {
        $date = date('Y-m-d', strtotime("-$i days"));
        $sms_daily[29 - $i] = (int) ($raw[$date] ?? 0);
    }
} catch (Throwable $e) {}

// Top tags emails
$top_email_tags = [];
try {
    $stmt = $pdo->query("
        SELECT tag, COUNT(*) AS nb
        FROM email_log
        WHERE tag IS NOT NULL AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        GROUP BY tag
        ORDER BY nb DESC
        LIMIT 10
    ");
    $top_email_tags = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

// Stats globales emails
$email_stats = ['total' => 0, 'sent' => 0, 'failed' => 0];
try {
    $row = $pdo->query("
        SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) AS sent,
            SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed
        FROM email_log
    ")->fetch(PDO::FETCH_ASSOC);
    $email_stats = [
        'total' => (int) $row['total'],
        'sent' => (int) $row['sent'],
        'failed' => (int) $row['failed'],
    ];
} catch (Throwable $e) {}

$email_success_rate = $email_stats['total'] > 0
    ? round(($email_stats['sent'] / $email_stats['total']) * 100, 1)
    : 0;

// Stats SMS
$sms_stats = ['total' => 0, 'sent' => 0, 'failed' => 0, 'cost' => 0];
try {
    $row = $pdo->query("
        SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN status IN ('sent','delivered') THEN 1 ELSE 0 END) AS sent,
            SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed,
            COALESCE(SUM(cost_euros), 0) AS cost
        FROM sms_log
    ")->fetch(PDO::FETCH_ASSOC);
    $sms_stats = [
        'total' => (int) $row['total'],
        'sent' => (int) $row['sent'],
        'failed' => (int) $row['failed'],
        'cost' => (float) $row['cost'],
    ];
} catch (Throwable $e) {}

// Croissance assos (par mois, 12 derniers)
$orgs_monthly = [];
try {
    $stmt = $pdo->query("
        SELECT DATE_FORMAT(created_at, '%Y-%m') AS ym, COUNT(*) AS nb
        FROM organizations
        WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
          AND deleted_at IS NULL
        GROUP BY ym
        ORDER BY ym ASC
    ");
    $orgs_monthly = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
} catch (Throwable $e) {}

// Repartition plans
$plans_split = [];
try {
    $plans_split = $pdo->query("
        SELECT plan, COUNT(*) AS nb FROM organizations WHERE deleted_at IS NULL GROUP BY plan
    ")->fetchAll(PDO::FETCH_KEY_PAIR);
} catch (Throwable $e) {}

// =====================================================================
// Helper : trace un mini graph SVG (sparkline)
// =====================================================================
function stats_sparkline(array $data, string $color = '#7F77DD', int $w = 280, int $h = 80): string {
    if (empty($data)) return '';
    $max = max($data) ?: 1;
    $min = 0;
    $n = count($data);
    $step = $n > 1 ? $w / ($n - 1) : 0;
    $points = [];
    $area = ["{$w},{$h}", "0,{$h}"];
    foreach ($data as $i => $v) {
        $x = round($i * $step, 1);
        $y = round($h - (($v - $min) / ($max - $min ?: 1)) * ($h - 4) - 2, 1);
        $points[] = "$x,$y";
        $area[] = "$x,$y";
    }
    $poly = implode(' ', $points);
    $areaStr = implode(' ', array_merge([array_pop($area)], $points, ["{$w},{$h}", "0,{$h}"]));

    $svg  = '<svg width="100%" viewBox="0 0 ' . $w . ' ' . $h . '" preserveAspectRatio="none" xmlns="http://www.w3.org/2000/svg">';
    $svg .= '<defs><linearGradient id="grad' . md5($color) . '" x1="0" x2="0" y1="0" y2="1">';
    $svg .= '<stop offset="0%" stop-color="' . $color . '" stop-opacity="0.3"/>';
    $svg .= '<stop offset="100%" stop-color="' . $color . '" stop-opacity="0"/>';
    $svg .= '</linearGradient></defs>';
    $svg .= '<polygon fill="url(#grad' . md5($color) . ')" points="' . $poly . ' ' . $w . ',' . $h . ' 0,' . $h . '"/>';
    $svg .= '<polyline fill="none" stroke="' . $color . '" stroke-width="2" points="' . $poly . '" stroke-linejoin="round" stroke-linecap="round"/>';
    $svg .= '</svg>';
    return $svg;
}

sa_render_head('Statistiques approfondies');
sa_render_sidebar('dashboard');
?>

<div class="sa-breadcrumb">
    <a href="/super-admin">Dashboard</a>
    <span class="sep">›</span>
    Statistiques
</div>

<div class="sa-page-head">
    <div>
        <h1 class="sa-page-title">📊 Statistiques approfondies</h1>
        <div class="sa-page-sub">Vue d'ensemble de l'activité plateforme — <span class="sa-badge sa-badge-gold" style="font-size:10px;">🏗️ FONDATEUR</span></div>
    </div>
</div>

<!-- ============ EMAILS ============ -->
<div class="sa-page-head" style="margin-top: 12px;">
    <h2 class="sa-page-title" style="font-size: 16px;">📧 Emails</h2>
</div>

<div style="display:grid;grid-template-columns:2fr 1fr;gap:16px;margin-bottom:24px;">
    <div class="sa-card">
        <div class="sa-card-title">Emails envoyés (30 derniers jours)</div>
        <div style="margin-top:16px;">
            <?= stats_sparkline($emails_daily, '#7F77DD', 600, 120) ?>
        </div>
        <div style="display:flex;justify-content:space-between;margin-top:8px;font-size:11px;color:var(--sa-ink-4);">
            <span><?= date('d/m', strtotime('-29 days')) ?></span>
            <span>Aujourd'hui</span>
        </div>
    </div>
    <div class="sa-card">
        <div class="sa-card-title">Résumé Resend</div>
        <div style="margin-top:12px; display:flex; flex-direction:column; gap:10px;">
            <div>
                <div style="font-size:11px; color:var(--sa-ink-3);">Total envoyés</div>
                <div style="font-size:22px; font-weight:600;"><?= number_format($email_stats['total'], 0, ',', ' ') ?></div>
            </div>
            <div>
                <div style="font-size:11px; color:var(--sa-ink-3);">Taux de succès</div>
                <div style="font-size:22px; font-weight:600; color:#6EE7B7;"><?= $email_success_rate ?>%</div>
            </div>
            <?php if ($email_stats['failed'] > 0): ?>
            <div>
                <div style="font-size:11px; color:var(--sa-ink-3);">Échecs</div>
                <div style="font-size:18px; font-weight:600; color:#FCA5A5;"><?= $email_stats['failed'] ?></div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if (!empty($top_email_tags)): ?>
<div class="sa-card" style="margin-bottom: 24px;">
    <div class="sa-card-title">Top types d'emails (30 derniers jours)</div>
    <div style="margin-top:14px;">
        <?php
        $max_tag = max(array_column($top_email_tags, 'nb')) ?: 1;
        foreach ($top_email_tags as $t):
            $pct = round(($t['nb'] / $max_tag) * 100);
        ?>
            <div style="display:flex;align-items:center;gap:12px;margin-bottom:8px;">
                <div style="width:140px;font-size:12px;color:var(--sa-ink-2);flex-shrink:0;"><?= h($t['tag']) ?></div>
                <div style="flex:1;height:8px;background:var(--sa-bg-3);border-radius:999px;overflow:hidden;">
                    <div style="width:<?= $pct ?>%;height:100%;background:linear-gradient(90deg, #7F77DD, #C4B5FD);"></div>
                </div>
                <div style="width:60px;text-align:right;font-size:13px;font-weight:500;font-variant-numeric:tabular-nums;"><?= number_format($t['nb'], 0, ',', ' ') ?></div>
            </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- ============ SMS ============ -->
<div class="sa-page-head" style="margin-top: 12px;">
    <h2 class="sa-page-title" style="font-size: 16px;">💬 SMS</h2>
</div>

<div style="display:grid;grid-template-columns:2fr 1fr;gap:16px;margin-bottom:24px;">
    <div class="sa-card">
        <div class="sa-card-title">SMS envoyés (30 derniers jours)</div>
        <div style="margin-top:16px;">
            <?= stats_sparkline($sms_daily, '#10B981', 600, 120) ?>
        </div>
        <div style="display:flex;justify-content:space-between;margin-top:8px;font-size:11px;color:var(--sa-ink-4);">
            <span><?= date('d/m', strtotime('-29 days')) ?></span>
            <span>Aujourd'hui</span>
        </div>
        <?php if ($sms_stats['total'] === 0): ?>
        <div style="margin-top:16px;padding:14px;background:rgba(127,119,221,0.06);border-radius:10px;font-size:12.5px;color:var(--sa-ink-3);">
            ℹ️ Aucun SMS encore envoyé. La structure est prête, il suffit d'activer l'envoi une fois le provider configuré.
        </div>
        <?php endif; ?>
    </div>
    <div class="sa-card">
        <div class="sa-card-title">Résumé SMS</div>
        <div style="margin-top:12px; display:flex; flex-direction:column; gap:10px;">
            <div>
                <div style="font-size:11px; color:var(--sa-ink-3);">Total</div>
                <div style="font-size:22px; font-weight:600;"><?= number_format($sms_stats['total'], 0, ',', ' ') ?></div>
            </div>
            <div>
                <div style="font-size:11px; color:var(--sa-ink-3);">Coût total</div>
                <div style="font-size:22px; font-weight:600; color:#6EE7B7;"><?= number_format($sms_stats['cost'], 2, ',', ' ') ?> €</div>
            </div>
            <?php if ($sms_stats['failed'] > 0): ?>
            <div>
                <div style="font-size:11px; color:var(--sa-ink-3);">Échecs</div>
                <div style="font-size:18px; font-weight:600; color:#FCA5A5;"><?= $sms_stats['failed'] ?></div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- ============ ASSOCIATIONS ============ -->
<div class="sa-page-head" style="margin-top: 12px;">
    <h2 class="sa-page-title" style="font-size: 16px;">🏛️ Associations</h2>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:24px;">
    <div class="sa-card">
        <div class="sa-card-title">Nouvelles associations par mois</div>
        <?php if (empty($orgs_monthly)): ?>
            <div style="color:var(--sa-ink-4);font-size:13px;margin-top:10px;">Aucune donnée disponible.</div>
        <?php else: ?>
            <div style="margin-top:14px;">
                <?php
                $max_m = max($orgs_monthly) ?: 1;
                foreach ($orgs_monthly as $ym => $nb):
                    $pct = round(($nb / $max_m) * 100);
                    $label = date('M Y', strtotime($ym . '-01'));
                ?>
                    <div style="display:flex;align-items:center;gap:12px;margin-bottom:6px;">
                        <div style="width:80px;font-size:12px;color:var(--sa-ink-2);"><?= h($label) ?></div>
                        <div style="flex:1;height:18px;background:var(--sa-bg-3);border-radius:4px;overflow:hidden;position:relative;">
                            <div style="width:<?= $pct ?>%;height:100%;background:linear-gradient(90deg, #7F77DD, #C4B5FD);"></div>
                        </div>
                        <div style="width:40px;text-align:right;font-size:13px;font-weight:500;"><?= $nb ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <div class="sa-card">
        <div class="sa-card-title">Répartition par plan</div>
        <div style="margin-top:14px;">
            <?php
            $total_plans = array_sum($plans_split) ?: 1;
            $colors = ['essentiel' => '#A1A1AA', 'association' => '#7F77DD', 'organisation' => '#FCD34D'];
            foreach ($plans_split as $plan => $nb):
                $pct = round(($nb / $total_plans) * 100, 1);
                $color = $colors[$plan] ?? '#A1A1AA';
            ?>
                <div style="margin-bottom:12px;">
                    <div style="display:flex;justify-content:space-between;font-size:12.5px;margin-bottom:4px;">
                        <span><?= h(ucfirst($plan)) ?></span>
                        <span style="color:var(--sa-ink-3);"><?= $nb ?> · <?= $pct ?>%</span>
                    </div>
                    <div style="height:6px;background:var(--sa-bg-3);border-radius:999px;overflow:hidden;">
                        <div style="width:<?= $pct ?>%;height:100%;background:<?= $color ?>;"></div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<?php sa_render_foot(); ?>
