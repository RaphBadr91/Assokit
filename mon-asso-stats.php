<?php
/**
 * mon-asso-stats.php — Page Stats avancées
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes-layout.php';
require_once __DIR__ . '/asso-invoice-helpers.php';
require_once __DIR__ . '/asso-stats-helpers.php';
require_once __DIR__ . '/facturation-hub.php';

require_login();
$user = current_user();
if (empty($user['org_id'])) { http_response_code(403); die('Aucune asso.'); }
$org_id = (int)$user['org_id'];

$is_admin = false;
try {
    $stmt = $pdo->prepare("SELECT role FROM users WHERE id = :id");
    $stmt->execute([':id' => (int)$user['id']]);
    $is_admin = in_array($stmt->fetchColumn(), ['admin', 'founder', 'super_admin'], true)
        || !empty($user['is_founder']) || !empty($user['is_super_admin']);
} catch (Throwable $e) {}
if (!$is_admin) {
    http_response_code(403);
    render_head('Accès refusé');
    render_sidebar('stats');
    echo '<main class="main"><div style="max-width:600px;margin:60px auto;padding:32px;background:white;border:1px solid #FECACA;border-radius:14px;text-align:center;">';
    echo '<div style="color:#64748B;margin-bottom:14px;">' . ak_icon('lock',44,'1.5') . '</div>';
    echo '<h1 style="font-size:22px;color:#0F172A;margin:0 0 12px;">Accès réservé</h1>';
    echo '<p style="color:#64748B;font-size:14px;line-height:1.6;margin:0 0 22px;">Les statistiques avancées (revenus, top clients, CA) sont strictement réservées aux <strong>Administrateurs</strong> de l\'association.</p>';
    echo '<a href="/dashboard" style="display:inline-block;background:#0F172A;color:white;padding:11px 22px;border-radius:10px;text-decoration:none;font-weight:600;font-size:14px;">← Retour au dashboard</a>';
    echo '</div></main>';
    render_foot();
    exit;
}

$kpis = ak_stats_global_kpis($pdo, $org_id);
$revenue = ak_stats_revenue_monthly($pdo, $org_id, 12);
$yearly = ak_stats_yearly_compare($pdo, $org_id);
$top_clients = ak_stats_top_clients($pdo, $org_id, 10);
$status_dist = ak_stats_status_distribution($pdo, $org_id);
$by_tag = ak_stats_by_tag($pdo, $org_id);

$max_top_paid = !empty($top_clients) ? max(array_map(fn($c) => (int)$c['total_paid_cents'], $top_clients)) : 1;
if ($max_top_paid <= 0) $max_top_paid = 1;

render_head('Statistiques');
render_sidebar('stats');
?>

<div class="main">
    <?php render_facturation_hub($pdo, $org_id, 'stats'); ?>

    <h3 style="margin:0 0 14px; font-size:16px; color:var(--ink,#0B1A13);">Analyse détaillée <?= (int)$kpis['year'] ?></h3>

    <!-- KPIs performance (complète le hub qui montre les montants) -->
    <div class="fstat-kpis">
        <div class="fstat-kpi">
            <span class="g" style="background:linear-gradient(90deg,#8B5CF6,#6366F1);"></span>
            <div class="l">Taux de conversion</div>
            <div class="n" style="color:#6366F1;"><?= number_format($kpis['conversion_rate'], 1, ',', '') ?> %</div>
            <div class="c"><?= (int)$kpis['nb_signed'] + (int)$kpis['nb_converted'] ?>/<?= (int)$kpis['total_quotes'] ?> devis acceptés</div>
        </div>
        <div class="fstat-kpi">
            <span class="g" style="background:linear-gradient(90deg,#60A5FA,#2F73E8);"></span>
            <div class="l">Délai moyen de paiement</div>
            <div class="n" style="color:#2F73E8;"><?= number_format($kpis['avg_payment_days'], 1, ',', '') ?> j</div>
            <div class="c">après émission de la facture</div>
        </div>
        <div class="fstat-kpi">
            <span class="g" style="background:linear-gradient(90deg,#34D399,#059669);"></span>
            <div class="l">Factures payées</div>
            <div class="n" style="color:#059669;"><?= (int)$kpis['nb_paid'] ?></div>
            <div class="c">sur <?= (int)$kpis['total_invoices'] ?> émise<?= $kpis['total_invoices']>1?'s':'' ?> en <?= (int)$kpis['year'] ?></div>
        </div>
        <div class="fstat-kpi">
            <span class="g" style="background:linear-gradient(90deg,#F87171,#E5484D);"></span>
            <div class="l">Factures en retard</div>
            <div class="n" style="color:<?= (int)$kpis['nb_overdue'] > 0 ? '#E5484D' : 'var(--ink)' ?>;"><?= (int)$kpis['nb_overdue'] ?></div>
            <div class="c"><?= (int)$kpis['nb_overdue'] > 0 ? 'à relancer rapidement' : 'aucun retard 🎉' ?></div>
        </div>
    </div>

    <!-- Graphique CA mensuel -->
    <div class="card" style="padding:22px; margin-bottom:18px;">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
            <h3 style="margin:0; font-size:16px; display:flex; align-items:center; gap:8px;"><?= ak_icon('trending-up',18) ?> Chiffre d'affaires mensuel (12 derniers mois)</h3>
            <div style="display:flex; gap:14px; font-size:11px;">
                <span><span style="display:inline-block; width:10px; height:10px; background:#10B981; border-radius:2px; margin-right:4px;"></span>Payé</span>
                <span><span style="display:inline-block; width:10px; height:10px; background:#F59E0B; border-radius:2px; margin-right:4px;"></span>En attente</span>
                <span><span style="display:inline-block; width:10px; height:10px; background:#DC2626; border-radius:2px; margin-right:4px;"></span>Retard</span>
            </div>
        </div>
        <canvas id="chart-monthly" style="max-height:280px;"></canvas>
    </div>

    <!-- Vue annuelle N vs N-1 + Camembert statuts -->
    <div style="display:grid; grid-template-columns: 2fr 1fr; gap:14px; margin-bottom:18px;">
        <div class="card" style="padding:22px;">
            <h3 style="margin:0 0 16px 0; font-size:16px; display:flex; align-items:center; gap:8px;"><?= ak_icon('calendar',18) ?> Comparaison <?= $yearly['current_year'] ?> vs <?= $yearly['previous_year'] ?></h3>
            <canvas id="chart-yearly" style="max-height:240px;"></canvas>
        </div>
        <div class="card" style="padding:22px;">
            <h3 style="margin:0 0 16px 0; font-size:16px; display:flex; align-items:center; gap:8px;"><?= ak_icon('euro',18) ?> Répartition par statut</h3>
            <?php if (!empty($status_dist['values'])): ?>
                <canvas id="chart-status" style="max-height:240px;"></canvas>
            <?php else: ?>
                <div style="text-align:center; color:#6B7280; padding:40px 0;">Pas encore de données</div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Top 10 clients -->
    <div class="card" style="padding:22px; margin-bottom:18px;">
        <h3 style="margin:0 0 16px 0; font-size:16px; display:flex; align-items:center; gap:8px;"><?= ak_icon('trophy',18) ?> Top <?= min(10, count($top_clients)) ?> clients par CA encaissé</h3>
        <?php if (empty($top_clients)): ?>
            <div style="color:#6B7280; text-align:center; padding:30px 0;">Pas encore de clients facturés payés.</div>
        <?php else: ?>
            <table style="width:100%; border-collapse:collapse;">
                <thead style="background:#F9FAFB;">
                    <tr>
                        <th style="text-align:left; padding:8px 12px; font-size:11px; color:#6B7280; text-transform:uppercase;">#</th>
                        <th style="text-align:left; padding:8px 12px; font-size:11px; color:#6B7280; text-transform:uppercase;">Client</th>
                        <th style="text-align:center; padding:8px 12px; font-size:11px; color:#6B7280; text-transform:uppercase;">Factures</th>
                        <th style="text-align:left; padding:8px 12px; font-size:11px; color:#6B7280; text-transform:uppercase; width:30%;">Part du CA</th>
                        <th style="text-align:right; padding:8px 12px; font-size:11px; color:#6B7280; text-transform:uppercase;">CA encaissé</th>
                        <th style="text-align:right; padding:8px 12px; font-size:11px; color:#6B7280; text-transform:uppercase;">À encaisser</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($top_clients as $idx => $c):
                        $pct = ((int)$c['total_paid_cents'] / max(1, $max_top_paid)) * 100;
                    ?>
                    <tr style="border-top:1px solid #E5E7EB;">
                        <td style="padding:10px 12px; font-size:13px; font-weight:700; color:#10B981;">
                            <?= ($idx === 0) ? '🥇' : (($idx === 1) ? '🥈' : (($idx === 2) ? '🥉' : ($idx + 1))) ?>
                        </td>
                        <td style="padding:10px 12px; font-size:13px;">
                            <strong><?= h($c['display_name']) ?></strong><br>
                            <span style="font-size:11px; color:#6B7280;"><?= h($c['email']) ?></span>
                        </td>
                        <td style="padding:10px 12px; text-align:center; font-size:13px;"><?= (int)$c['nb_invoices'] ?></td>
                        <td style="padding:10px 12px;">
                            <div style="background:#F3F4F6; border-radius:4px; height:8px; overflow:hidden;">
                                <div style="background:linear-gradient(90deg, #10B981, #059669); height:100%; width:<?= number_format($pct, 1, '.', '') ?>%; transition:width 0.3s;"></div>
                            </div>
                        </td>
                        <td style="padding:10px 12px; text-align:right; font-size:13px; font-weight:700; color:#10B981; font-variant-numeric:tabular-nums;">
                            <?= h(ak_asso_fmt_cents((int)$c['total_paid_cents'])) ?>
                        </td>
                        <td style="padding:10px 12px; text-align:right; font-size:13px; color:#F59E0B; font-variant-numeric:tabular-nums;">
                            <?= h(ak_asso_fmt_cents((int)$c['total_pending_cents'])) ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <!-- Stats par tag -->
    <?php if (!empty($by_tag)): ?>
    <div class="card" style="padding:22px;">
        <h3 style="margin:0 0 16px 0; font-size:16px; display:flex; align-items:center; gap:8px;"><?= ak_icon('star-fill',18) ?> Performance par catégorie (tags)</h3>
        <table style="width:100%; border-collapse:collapse;">
            <thead style="background:#F9FAFB;">
                <tr>
                    <th style="text-align:left; padding:8px 12px; font-size:11px; color:#6B7280; text-transform:uppercase;">Catégorie</th>
                    <th style="text-align:center; padding:8px 12px; font-size:11px; color:#6B7280; text-transform:uppercase;">Nb factures</th>
                    <th style="text-align:right; padding:8px 12px; font-size:11px; color:#6B7280; text-transform:uppercase;">CA encaissé</th>
                    <th style="text-align:right; padding:8px 12px; font-size:11px; color:#6B7280; text-transform:uppercase;">CA total</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($by_tag as $t): ?>
                <tr style="border-top:1px solid #E5E7EB;">
                    <td style="padding:10px 12px;">
                        <span style="display:inline-block; padding:3px 10px; border-radius:10px; font-size:12px; font-weight:600; background:<?= h($t['color']) ?>22; color:<?= h($t['color']) ?>; border:1px solid <?= h($t['color']) ?>44;"><?= h($t['name']) ?></span>
                    </td>
                    <td style="padding:10px 12px; text-align:center; font-size:13px;"><?= (int)$t['nb_invoices'] ?></td>
                    <td style="padding:10px 12px; text-align:right; font-size:13px; font-weight:700; color:#10B981; font-variant-numeric:tabular-nums;">
                        <?= h(ak_asso_fmt_cents((int)$t['revenue_paid'])) ?>
                    </td>
                    <td style="padding:10px 12px; text-align:right; font-size:13px; color:#6B7280; font-variant-numeric:tabular-nums;">
                        <?= h(ak_asso_fmt_cents((int)$t['revenue_total'])) ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
const fmtEur = (v) => v.toLocaleString('fr-FR', {style:'currency', currency:'EUR', maximumFractionDigits:0});

// Graphique CA mensuel (stacked bars)
new Chart(document.getElementById('chart-monthly'), {
    type: 'bar',
    data: {
        labels: <?= json_encode($revenue['labels']) ?>,
        datasets: [
            {label: 'Payé', data: <?= json_encode($revenue['paid']) ?>, backgroundColor: '#10B981', stack: 's1', borderRadius:4},
            {label: 'En attente', data: <?= json_encode($revenue['pending']) ?>, backgroundColor: '#F59E0B', stack: 's1', borderRadius:4},
            {label: 'Retard', data: <?= json_encode($revenue['overdue']) ?>, backgroundColor: '#DC2626', stack: 's1', borderRadius:4},
        ]
    },
    options: {
        responsive: true, maintainAspectRatio: false,
        plugins: {
            legend: { display: false },
            tooltip: { callbacks: { label: (c) => c.dataset.label + ': ' + fmtEur(c.parsed.y) } }
        },
        scales: {
            y: { beginAtZero: true, ticks: { callback: (v) => fmtEur(v) }, grid: { color: '#F3F4F6' } },
            x: { grid: { display: false } }
        }
    }
});

// Graphique annuel ligne
new Chart(document.getElementById('chart-yearly'), {
    type: 'line',
    data: {
        labels: <?= json_encode($yearly['labels']) ?>,
        datasets: [
            {label: '<?= $yearly['current_year'] ?>', data: <?= json_encode($yearly['current']) ?>, borderColor:'#10B981', backgroundColor:'rgba(16,185,129,0.1)', fill:true, tension:0.4, borderWidth:3, pointRadius:4, pointBackgroundColor:'#10B981'},
            {label: '<?= $yearly['previous_year'] ?>', data: <?= json_encode($yearly['previous']) ?>, borderColor:'#9CA3AF', backgroundColor:'rgba(156,163,175,0.05)', fill:true, tension:0.4, borderWidth:2, borderDash:[5,5], pointRadius:3, pointBackgroundColor:'#9CA3AF'},
        ]
    },
    options: {
        responsive: true, maintainAspectRatio: false,
        plugins: {
            legend: { position: 'top', align:'end', labels: { boxWidth: 12, font: { size: 12 } } },
            tooltip: { callbacks: { label: (c) => c.dataset.label + ': ' + fmtEur(c.parsed.y) } }
        },
        scales: {
            y: { beginAtZero: true, ticks: { callback: (v) => fmtEur(v) }, grid: { color: '#F3F4F6' } },
            x: { grid: { display: false } }
        }
    }
});

<?php if (!empty($status_dist['values'])): ?>
// Doughnut statuts
new Chart(document.getElementById('chart-status'), {
    type: 'doughnut',
    data: {
        labels: <?= json_encode($status_dist['labels']) ?>,
        datasets: [{
            data: <?= json_encode($status_dist['values']) ?>,
            backgroundColor: <?= json_encode($status_dist['colors']) ?>,
            borderWidth: 2, borderColor: '#fff'
        }]
    },
    options: {
        responsive: true, maintainAspectRatio: false,
        cutout: '65%',
        plugins: {
            legend: { position: 'bottom', labels: { boxWidth: 10, font: { size: 11 } } },
            tooltip: { callbacks: { label: (c) => c.label + ': ' + fmtEur(c.parsed) } }
        }
    }
});
<?php endif; ?>
</script>

<?php render_foot(); ?>
