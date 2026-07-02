<?php
/**
 * mon-asso-devis.php — Liste devis (Redesign Premium léger)
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes-layout.php';
require_once __DIR__ . '/asso-invoice-helpers.php';
require_once __DIR__ . '/asso-quote-helpers.php';
require_once __DIR__ . '/asso-tags-helpers.php';
require_once __DIR__ . '/asso-search-helpers.php';

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
    render_sidebar('devis');
    echo '<main class="main"><div style="max-width:600px;margin:60px auto;padding:32px;background:white;border:1px solid #FECACA;border-radius:14px;text-align:center;">';
    echo '<div style="font-size:54px;margin-bottom:14px;">🔒</div>';
    echo '<h1 style="font-size:22px;color:#0F172A;margin:0 0 12px;">Accès réservé</h1>';
    echo '<p style="color:#64748B;font-size:14px;line-height:1.6;margin:0 0 22px;">La liste des devis (montants prévus, conversions) est strictement réservée aux <strong>Administrateurs</strong> de l\'association.</p>';
    echo '<a href="/dashboard" style="display:inline-block;background:#0F172A;color:white;padding:11px 22px;border-radius:10px;text-decoration:none;font-weight:600;font-size:14px;">← Retour au dashboard</a>';
    echo '</div></main>';
    render_foot();
    exit;
}

$flash = $_SESSION['flash_asso_devis'] ?? null;
unset($_SESSION['flash_asso_devis']);

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

$status_filter = $_GET['status'] ?? 'all';
$period = $_GET['period'] ?? 'all';
$custom_from = $_GET['from'] ?? null;
$custom_to = $_GET['to'] ?? null;
$tag_id = (int)($_GET['tag'] ?? 0);
$min_amount = !empty($_GET['min']) ? (float)$_GET['min'] : null;
$max_amount = !empty($_GET['max']) ? (float)$_GET['max'] : null;

[$dateStart, $dateEnd] = ak_search_period_dates($period, $custom_from, $custom_to);

$sql = "SELECT q.*, c.display_name AS client_name, c.email AS client_email FROM asso_quotes q LEFT JOIN asso_clients c ON c.id = q.client_id WHERE q.org_id = :org";
$params = [':org' => $org_id];

if ($status_filter !== 'all' && in_array($status_filter, ['draft','sent','signed','refused','expired','converted','cancelled'])) {
    $sql .= " AND q.status = :st"; $params[':st'] = $status_filter;
}
if ($dateStart) { $sql .= " AND q.issued_at >= :ds"; $params[':ds'] = $dateStart; }
if ($dateEnd) { $sql .= " AND q.issued_at <= :de"; $params[':de'] = $dateEnd; }
if ($min_amount !== null) { $sql .= " AND q.amount_ttc_cents >= :min"; $params[':min'] = (int)round($min_amount * 100); }
if ($max_amount !== null) { $sql .= " AND q.amount_ttc_cents <= :max"; $params[':max'] = (int)round($max_amount * 100); }
if ($tag_id > 0) {
    $sql .= " AND q.id IN (SELECT entity_id FROM asso_tag_links WHERE entity_type='quote' AND tag_id = :tid)";
    $params[':tid'] = $tag_id;
}
$sql .= " ORDER BY q.id DESC LIMIT 500";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$quotes = $stmt->fetchAll(PDO::FETCH_ASSOC);

$tags_by_quote = ak_tag_get_for_entities($pdo, 'quote', array_map(fn($q)=>(int)$q['id'], $quotes));
$all_tags = ak_tag_list($pdo, $org_id);

$kpi = ['count' => 0, 'sent' => 0, 'signed' => 0, 'converted' => 0];
foreach ($quotes as $q) {
    $kpi['count']++;
    if ($q['status'] === 'sent') $kpi['sent'] += (int)$q['amount_ttc_cents'];
    elseif ($q['status'] === 'signed') $kpi['signed'] += (int)$q['amount_ttc_cents'];
    elseif ($q['status'] === 'converted') $kpi['converted'] += (int)$q['amount_ttc_cents'];
}

$has_filters = ($min_amount !== null || $max_amount !== null || $tag_id > 0 || $period !== 'all' || $status_filter !== 'all');

render_head('Mes devis');
render_sidebar('devis');

$current_query = http_build_query($_GET);
?>

<style>
/* === REDESIGN PREMIUM LIGHT - DEVIS (violet) === */
.ak-page-head { display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:24px; gap:20px; flex-wrap:wrap; }
.ak-page-title { font-size:24px; font-weight:700; color:#111827; letter-spacing:-0.3px; margin:0 0 6px 0; }
.ak-page-sub { color:#6B7280; font-size:13px; }
.ak-page-actions { display:flex; gap:8px; flex-wrap:wrap; }

.ak-kpis { display:grid; grid-template-columns:repeat(4, 1fr); gap:14px; margin-bottom:20px; }
.ak-kpi { background:#fff; border:1px solid #E5E7EB; border-radius:12px; padding:18px 20px; transition:all 0.2s; }
.ak-kpi:hover { border-color:#D1D5DB; transform:translateY(-1px); box-shadow:0 4px 12px rgba(0,0,0,0.04); }
.ak-kpi-label { font-size:11px; color:#6B7280; text-transform:uppercase; letter-spacing:0.6px; font-weight:600; margin-bottom:8px; display:flex; align-items:center; gap:6px; }
.ak-kpi-dot { width:8px; height:8px; border-radius:50%; display:inline-block; }
.ak-kpi-value { font-size:24px; font-weight:700; color:#111827; font-variant-numeric:tabular-nums; letter-spacing:-0.5px; }
.ak-kpi-meta { font-size:12px; color:#6B7280; margin-top:4px; }

.ak-filters { background:#fff; border:1px solid #E5E7EB; border-radius:12px; padding:16px 18px; margin-bottom:18px; }
.ak-search-wrap { position:relative; margin-bottom:16px; }
.ak-search-wrap input { width:100%; padding:11px 14px 11px 40px; border:1px solid #E5E7EB; border-radius:10px; font-size:14px; background:#F9FAFB; transition:all 0.15s; }
.ak-search-wrap input:focus { outline:none; background:#fff; border-color:#7E22CE; box-shadow:0 0 0 3px rgba(126,34,206,0.1); }
.ak-search-wrap::before { content:"🔍"; position:absolute; left:14px; top:50%; transform:translateY(-50%); font-size:14px; opacity:0.5; }

.ak-filter-row { display:flex; gap:18px; align-items:center; flex-wrap:wrap; padding:8px 0; border-top:1px solid #F3F4F6; }
.ak-filter-row:first-of-type { border-top:none; }
.ak-filter-label { font-size:11px; color:#6B7280; text-transform:uppercase; letter-spacing:0.5px; font-weight:600; min-width:75px; }
.ak-chips { display:flex; gap:6px; flex-wrap:wrap; flex:1; }
.ak-chip { padding:6px 12px; border-radius:8px; font-size:12px; font-weight:500; text-decoration:none; transition:all 0.15s; background:#F3F4F6; color:#374151; border:1px solid transparent; }
.ak-chip:hover { background:#E5E7EB; color:#111827; }
.ak-chip.active { background:#7E22CE; color:#fff; }
.ak-chip-tag { padding:4px 10px; border-radius:14px; font-size:11px; font-weight:600; text-decoration:none; transition:all 0.15s; }

.ak-table-wrap { background:#fff; border:1px solid #E5E7EB; border-radius:12px; overflow:hidden; }
.ak-table { width:100%; border-collapse:collapse; }
.ak-table thead { background:#F9FAFB; border-bottom:1px solid #E5E7EB; }
.ak-table thead th { padding:12px 18px; text-align:left; font-size:11px; color:#6B7280; text-transform:uppercase; letter-spacing:0.5px; font-weight:600; }
.ak-table thead th.num { text-align:right; }
.ak-table thead th.center { text-align:center; }
.ak-table tbody tr { border-bottom:1px solid #F3F4F6; transition:background 0.15s; }
.ak-table tbody tr:last-child { border-bottom:none; }
.ak-table tbody tr:hover { background:#FAF5FF; }
.ak-table tbody td { padding:14px 18px; font-size:13px; color:#111827; vertical-align:middle; }
.ak-table tbody td.num { text-align:right; font-variant-numeric:tabular-nums; }
.ak-table tbody td.center { text-align:center; }

.ak-status { display:inline-flex; align-items:center; gap:5px; padding:4px 10px; border-radius:20px; font-size:11px; font-weight:600; letter-spacing:0.2px; }
.ak-status::before { content:""; width:6px; height:6px; border-radius:50%; background:currentColor; }
.ak-status-draft { background:#F3F4F6; color:#374151; }
.ak-status-sent { background:#FEF3C7; color:#92400E; }
.ak-status-signed { background:#D1FAE5; color:#065F46; }
.ak-status-refused { background:#FEE2E2; color:#991B1B; }
.ak-status-expired { background:#F3F4F6; color:#6B7280; }
.ak-status-converted { background:#F3E8FF; color:#7E22CE; }
.ak-status-cancelled { background:#F3F4F6; color:#6B7280; }

.ak-actions-cell { display:flex; gap:4px; justify-content:flex-end; align-items:center; }
.ak-icon-btn { display:inline-flex; align-items:center; justify-content:center; width:32px; height:32px; border-radius:8px; background:transparent; border:1px solid transparent; color:#6B7280; cursor:pointer; text-decoration:none; transition:all 0.15s; font-size:14px; padding:0; }
.ak-icon-btn:hover { background:#F3F4F6; color:#111827; border-color:#E5E7EB; }
.ak-icon-btn.primary:hover { background:#F3E8FF; color:#7E22CE; border-color:#A855F7; }

.ak-empty { padding:60px 20px; text-align:center; color:#6B7280; }
.ak-empty-icon { font-size:42px; margin-bottom:14px; opacity:0.4; }
.ak-empty-text { font-size:15px; color:#374151; margin-bottom:6px; }
.ak-empty-sub { font-size:13px; color:#9CA3AF; }

.ak-btn-primary { background:#7E22CE; color:#fff; border:1px solid #7E22CE; padding:10px 18px; border-radius:10px; font-size:13px; font-weight:600; text-decoration:none; transition:all 0.15s; display:inline-flex; align-items:center; gap:6px; }
.ak-btn-primary:hover { background:#6B21A8; border-color:#6B21A8; }
.ak-btn-ghost { background:#fff; color:#374151; border:1px solid #E5E7EB; padding:10px 16px; border-radius:10px; font-size:13px; font-weight:500; text-decoration:none; transition:all 0.15s; display:inline-flex; align-items:center; gap:6px; }
.ak-btn-ghost:hover { background:#F9FAFB; border-color:#D1D5DB; }

.ak-tag-chip { display:inline-block; padding:2px 8px; border-radius:10px; font-size:10px; font-weight:600; margin-right:3px; margin-top:3px; }

.ak-money-row { font-weight:600; }

@media (max-width: 900px) {
    .ak-kpis { grid-template-columns:repeat(2, 1fr); }
    .ak-table { font-size:12px; }
    .ak-table thead th, .ak-table tbody td { padding:10px; }
}
</style>

<div class="main">

    <!-- HEADER -->
    <div class="ak-page-head">
        <div>
            <h1 class="ak-page-title">📝 Mes devis</h1>
            <div class="ak-page-sub">
                <?= $kpi['count'] ?> devis
                <?php if ($has_filters): ?> · <span style="color:#7E22CE; font-weight:500;">Filtres actifs</span><?php endif; ?>
            </div>
        </div>
        <div class="ak-page-actions">
            <a href="/mon-asso-tags" class="ak-btn-ghost">🔖 Tags</a>
            <a href="/mon-asso-export?type=quote&<?= h($current_query) ?>" class="ak-btn-ghost">📥 Export Excel</a>
            <a href="/mon-asso-devis-new" class="ak-btn-primary">+ Nouveau devis</a>
        </div>
    </div>

    <?php if ($flash): ?>
    <div class="alert <?= $flash['type'] === 'success' ? 'alert-success' : 'alert-error' ?>" style="margin-bottom:16px; border-radius:10px;">
        <?= h($flash['message']) ?>
        <?php if (!empty($flash['pdf_link'])): ?> — <a href="<?= h($flash['pdf_link']) ?>" target="_blank" style="font-weight:600;">📥 PDF</a><?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- KPIs -->
    <div class="ak-kpis">
        <div class="ak-kpi">
            <div class="ak-kpi-label">📊 Total filtré</div>
            <div class="ak-kpi-value"><?= (int)$kpi['count'] ?></div>
            <div class="ak-kpi-meta">devis</div>
        </div>
        <div class="ak-kpi">
            <div class="ak-kpi-label"><span class="ak-kpi-dot" style="background:#F59E0B;"></span>En attente</div>
            <div class="ak-kpi-value" style="color:#F59E0B;"><?= h(ak_asso_fmt_cents($kpi['sent'])) ?></div>
            <div class="ak-kpi-meta">en attente signature</div>
        </div>
        <div class="ak-kpi">
            <div class="ak-kpi-label"><span class="ak-kpi-dot" style="background:#10B981;"></span>Signés</div>
            <div class="ak-kpi-value" style="color:#10B981;"><?= h(ak_asso_fmt_cents($kpi['signed'])) ?></div>
            <div class="ak-kpi-meta">acceptés par client</div>
        </div>
        <div class="ak-kpi">
            <div class="ak-kpi-label"><span class="ak-kpi-dot" style="background:#7E22CE;"></span>Convertis</div>
            <div class="ak-kpi-value" style="color:#7E22CE;"><?= h(ak_asso_fmt_cents($kpi['converted'])) ?></div>
            <div class="ak-kpi-meta">en facture</div>
        </div>
    </div>

    <!-- FILTRES -->
    <div class="ak-filters">
        <div class="ak-search-wrap">
            <input type="text" id="live-search" placeholder="Rechercher par numéro, client, montant…">
        </div>

        <div class="ak-filter-row">
            <div class="ak-filter-label">Période</div>
            <div class="ak-chips">
                <?php foreach (['all'=>'Tout','this_month'=>'Ce mois','last_month'=>'Mois dernier','this_quarter'=>'Trimestre','this_year'=>'Année','last_30'=>'30 jours','custom'=>'📅 Personnalisé'] as $p => $lbl):
                    $active = ($period === $p);
                    $params2 = $_GET; $params2['period'] = $p;
                ?>
                    <a href="?<?= h(http_build_query($params2)) ?>" class="ak-chip <?= $active?'active':'' ?>"><?= h($lbl) ?></a>
                <?php endforeach; ?>
            </div>
        </div>

        <?php if ($period === 'custom'): ?>
        <div class="ak-filter-row">
            <div class="ak-filter-label"></div>
            <form method="GET" style="display:flex; gap:8px; align-items:end; flex-wrap:wrap;">
                <input type="hidden" name="period" value="custom">
                <?php foreach ($_GET as $k=>$v): ?>
                    <?php if (!in_array($k, ['period','from','to'])): ?>
                        <input type="hidden" name="<?= h($k) ?>" value="<?= h($v) ?>">
                    <?php endif; ?>
                <?php endforeach; ?>
                <div><label style="font-size:11px; color:#6B7280;">Du</label><input type="date" name="from" value="<?= h($custom_from) ?>" style="display:block; padding:7px; border:1px solid #E5E7EB; border-radius:8px; font-size:12px;"></div>
                <div><label style="font-size:11px; color:#6B7280;">Au</label><input type="date" name="to" value="<?= h($custom_to) ?>" style="display:block; padding:7px; border:1px solid #E5E7EB; border-radius:8px; font-size:12px;"></div>
                <button type="submit" class="ak-btn-primary" style="padding:8px 16px; font-size:12px;">Filtrer</button>
            </form>
        </div>
        <?php endif; ?>

        <div class="ak-filter-row">
            <div class="ak-filter-label">Statut</div>
            <div class="ak-chips">
                <?php foreach (['all'=>'Tous','draft'=>'Brouillons','sent'=>'Envoyés','signed'=>'Signés','refused'=>'Refusés','expired'=>'Expirés','converted'=>'Convertis'] as $val=>$lbl):
                    $active = ($status_filter === $val);
                    $params2 = $_GET; $params2['status'] = $val;
                ?>
                    <a href="?<?= h(http_build_query($params2)) ?>" class="ak-chip <?= $active?'active':'' ?>"><?= h($lbl) ?></a>
                <?php endforeach; ?>
            </div>
        </div>

        <?php if (!empty($all_tags)): ?>
        <div class="ak-filter-row">
            <div class="ak-filter-label">Tags</div>
            <div class="ak-chips">
                <?php $params2 = $_GET; unset($params2['tag']); ?>
                <a href="?<?= h(http_build_query($params2)) ?>" class="ak-chip <?= $tag_id===0?'active':'' ?>" style="<?= $tag_id===0?'background:#1F2937;':'' ?>">Tous</a>
                <?php foreach ($all_tags as $t):
                    $active = ((int)$t['id'] === $tag_id);
                    $params2 = $_GET; $params2['tag'] = (int)$t['id'];
                ?>
                    <a href="?<?= h(http_build_query($params2)) ?>" class="ak-chip-tag" style="background:<?= $active ? h($t['color']) : h($t['color']).'1A' ?>; color:<?= $active ? '#fff' : h($t['color']) ?>; border:1px solid <?= h($t['color']) ?>;"><?= h($t['name']) ?></a>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <div class="ak-filter-row">
            <div class="ak-filter-label">Montant</div>
            <form method="GET" style="display:flex; gap:8px; align-items:end; flex-wrap:wrap; flex:1;">
                <?php foreach ($_GET as $k=>$v): ?>
                    <?php if (!in_array($k, ['min','max'])): ?>
                        <input type="hidden" name="<?= h($k) ?>" value="<?= h($v) ?>">
                    <?php endif; ?>
                <?php endforeach; ?>
                <input type="number" name="min" step="0.01" placeholder="Min €" value="<?= h($min_amount) ?>" style="padding:7px 10px; border:1px solid #E5E7EB; border-radius:8px; width:110px; font-size:12px;">
                <input type="number" name="max" step="0.01" placeholder="Max €" value="<?= h($max_amount) ?>" style="padding:7px 10px; border:1px solid #E5E7EB; border-radius:8px; width:110px; font-size:12px;">
                <button type="submit" class="ak-chip" style="cursor:pointer; border:none;">Appliquer</button>
                <?php if ($has_filters): ?>
                    <a href="/mon-asso-devis" class="ak-chip" style="background:#FEE2E2; color:#991B1B;">✕ Réinitialiser</a>
                <?php endif; ?>
            </form>
        </div>
    </div>

    <!-- TABLE -->
    <?php if (empty($quotes)): ?>
        <div class="ak-table-wrap">
            <div class="ak-empty">
                <div class="ak-empty-icon">📝</div>
                <div class="ak-empty-text">Aucun devis trouvé</div>
                <div class="ak-empty-sub"><?= $has_filters ? 'Essaie de modifier ou réinitialiser les filtres.' : 'Crée ton premier devis pour commencer.' ?></div>
            </div>
        </div>
    <?php else: ?>
        <div class="ak-table-wrap">
            <table class="ak-table">
                <thead>
                    <tr>
                        <th>N°</th>
                        <th>Client</th>
                        <th>Émis</th>
                        <th>Validité</th>
                        <th class="num">Montant TTC</th>
                        <th class="center">Statut</th>
                        <th class="num">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($quotes as $q):
                        $is_expired = ($q['status'] === 'sent' && strtotime($q['expires_at']) < time());
                        $effective_status = $is_expired ? 'expired' : $q['status'];
                        $statusClass = 'ak-status-' . $effective_status;
                        $qtags = $tags_by_quote[(int)$q['id']] ?? [];
                    ?>
                    <tr>
                        <td><strong><?= h($q['quote_number']) ?></strong></td>
                        <td>
                            <div style="font-weight:600; color:#111827;"><?= h($q['client_name'] ?? 'Client inconnu') ?></div>
                            <?php if (!empty($q['client_email'])): ?>
                                <div style="font-size:11px; color:#9CA3AF;"><?= h($q['client_email']) ?></div>
                            <?php endif; ?>
                            <?php if (!empty($qtags)): ?>
                                <div style="margin-top:4px;">
                                    <?php foreach ($qtags as $t): ?>
                                        <span class="ak-tag-chip" style="background:<?= h($t['color']) ?>1A; color:<?= h($t['color']) ?>; border:1px solid <?= h($t['color']) ?>33;"><?= h($t['name']) ?></span>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td style="color:#6B7280;"><?= h(date('d/m/Y', strtotime($q['issued_at']))) ?></td>
                        <td style="color:<?= $is_expired ? '#DC2626' : '#6B7280' ?>;"><?= h(date('d/m/Y', strtotime($q['expires_at']))) ?></td>
                        <td class="num ak-money-row"><?= h(ak_asso_fmt_cents((int)$q['amount_ttc_cents'])) ?></td>
                        <td class="center">
                            <span class="ak-status <?= $statusClass ?>"><?= h(ak_asso_quote_status_label($effective_status)) ?></span>
                        </td>
                        <td>
                            <div class="ak-actions-cell">
                                <?php if (!empty($q['pdf_path'])): ?>
                                    <a href="<?= h($q['pdf_path']) ?>" target="_blank" class="ak-icon-btn" title="Télécharger PDF">📥</a>
                                <?php endif; ?>
                                <form method="POST" action="/mon-asso-devis-duplicate" style="display:inline;">
                                    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                                    <input type="hidden" name="quote_id" value="<?= (int)$q['id'] ?>">
                                    <button type="submit" class="ak-icon-btn" title="Dupliquer">📋</button>
                                </form>
                                <a href="/mon-asso-devis-edit?id=<?= (int)$q['id'] ?>" class="ak-icon-btn primary" title="Modifier">✏️</a>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?= ak_search_render_livesearch('live-search', '.ak-table tbody tr') ?>

<?php render_foot(); ?>
