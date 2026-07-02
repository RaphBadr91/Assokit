<?php
/**
 * mon-asso-clients.php — Liste clients (Pack UX)
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes-layout.php';
require_once __DIR__ . '/asso-invoice-helpers.php';
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
    render_sidebar('clients');
    echo '<main class="main"><div style="max-width:600px;margin:60px auto;padding:32px;background:white;border:1px solid #FECACA;border-radius:14px;text-align:center;">';
    echo '<div style="font-size:54px;margin-bottom:14px;">🔒</div>';
    echo '<h1 style="font-size:22px;color:#0F172A;margin:0 0 12px;">Accès réservé</h1>';
    echo '<p style="color:#64748B;font-size:14px;line-height:1.6;margin:0 0 22px;">La liste des clients (CA encaissé, factures, devis) est strictement réservée aux <strong>Administrateurs</strong> de l\'association.</p>';
    echo '<a href="/dashboard" style="display:inline-block;background:#0F172A;color:white;padding:11px 22px;border-radius:10px;text-decoration:none;font-weight:600;font-size:14px;">← Retour au dashboard</a>';
    echo '</div></main>';
    render_foot();
    exit;
}

$flash = $_SESSION['flash_clients'] ?? null;
unset($_SESSION['flash_clients']);

$tag_id = (int)($_GET['tag'] ?? 0);
$type_filter = $_GET['type'] ?? 'all';

$sql = "
    SELECT c.*,
           (SELECT COUNT(*) FROM asso_invoices WHERE client_id = c.id) AS nb_invoices,
           (SELECT COUNT(*) FROM asso_quotes WHERE client_id = c.id) AS nb_quotes,
           (SELECT COALESCE(SUM(amount_ttc_cents),0) FROM asso_invoices WHERE client_id = c.id AND status='paid') AS total_paid
    FROM asso_clients c
    WHERE c.org_id = :org AND c.deleted_at IS NULL
";
$params = [':org' => $org_id];

if ($type_filter !== 'all' && in_array($type_filter, ['individual','company'])) {
    $sql .= " AND c.client_type = :t"; $params[':t'] = $type_filter;
}
if ($tag_id > 0) {
    $sql .= " AND c.id IN (SELECT entity_id FROM asso_tag_links WHERE entity_type='client' AND tag_id = :tid)";
    $params[':tid'] = $tag_id;
}
$sql .= " ORDER BY c.display_name ASC LIMIT 500";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$clients = $stmt->fetchAll(PDO::FETCH_ASSOC);

$tags_by_client = ak_tag_get_for_entities($pdo, 'client', array_map(fn($c)=>(int)$c['id'], $clients));
$all_tags = ak_tag_list($pdo, $org_id);

$current_query = http_build_query($_GET);

render_head('Mes clients');
render_sidebar('clients');
?>

<div class="main">
    <div class="main-head">
        <div>
            <h1 class="page-title">👥 Mes clients</h1>
            <div class="page-sub"><?= count($clients) ?> client<?= count($clients)>1?'s':'' ?></div>
        </div>
        <div style="display:flex; gap:8px;">
            <a href="/mon-asso-tags" class="btn btn-ghost">🔖 Tags</a>
            <a href="/mon-asso-export?type=client&<?= h($current_query) ?>" class="btn btn-ghost">📥 Export Excel</a>
            <a href="/mon-asso-facture-new" class="btn btn-primary">+ Nouveau (via facture)</a>
        </div>
    </div>

    <?php if ($flash): ?>
    <div class="alert <?= $flash['type'] === 'success' ? 'alert-success' : 'alert-error' ?>" style="margin-bottom:16px;">
        <?= h($flash['message']) ?>
    </div>
    <?php endif; ?>

    <div class="card" style="padding:16px; margin-bottom:14px;">
        <div style="margin-bottom:14px;">
            <input type="text" id="live-search" placeholder="🔍 Recherche live (nom, email, ville...)" style="width:100%; padding:11px 14px; border:2px solid #E5E7EB; border-radius:10px; font-size:14px;">
        </div>

        <div style="margin-bottom:10px;">
            <div style="font-size:11px; color:#6B7280; text-transform:uppercase; margin-bottom:6px;">Type</div>
            <div style="display:flex; gap:6px; flex-wrap:wrap;">
                <?php foreach (['all'=>'Tous','company'=>'Entreprises / Assos','individual'=>'Particuliers'] as $val=>$lbl):
                    $active = ($type_filter === $val);
                    $params2 = $_GET; $params2['type'] = $val;
                ?>
                    <a href="?<?= h(http_build_query($params2)) ?>" style="padding:6px 12px; border-radius:6px; font-size:12px; text-decoration:none; <?= $active ? 'background:#059669; color:#fff;' : 'background:#F3F4F6; color:#374151;' ?>"><?= h($lbl) ?></a>
                <?php endforeach; ?>
            </div>
        </div>

        <?php if (!empty($all_tags)): ?>
        <div>
            <div style="font-size:11px; color:#6B7280; text-transform:uppercase; margin-bottom:6px;">Tags</div>
            <div style="display:flex; gap:6px; flex-wrap:wrap;">
                <?php $params2 = $_GET; unset($params2['tag']); ?>
                <a href="?<?= h(http_build_query($params2)) ?>" style="padding:4px 10px; border-radius:10px; font-size:11px; text-decoration:none; <?= $tag_id===0 ? 'background:#1F2937; color:#fff;' : 'background:#F3F4F6; color:#374151;' ?>">Tous</a>
                <?php foreach ($all_tags as $t):
                    $active = ((int)$t['id'] === $tag_id);
                    $params2 = $_GET; $params2['tag'] = (int)$t['id'];
                ?>
                    <a href="?<?= h(http_build_query($params2)) ?>" style="padding:4px 10px; border-radius:10px; font-size:11px; font-weight:600; text-decoration:none; background:<?= h($t['color']) ?><?= $active?'':'22' ?>; color:<?= $active?'#fff':h($t['color']) ?>; border:1px solid <?= h($t['color']) ?>;"><?= h($t['name']) ?></a>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <?php if (empty($clients)): ?>
        <div class="card" style="padding:40px; text-align:center; color:#6B7280;">
            <div style="font-size:36px; margin-bottom:10px;">👥</div>Aucun client. Crée ta première facture pour ajouter un client !
        </div>
    <?php else: ?>
        <div class="card" style="padding:0; overflow:hidden;">
            <table style="width:100%; border-collapse:collapse;">
                <thead style="background:#F9FAFB;">
                    <tr>
                        <th style="text-align:left; padding:10px 14px; font-size:11px; color:#6B7280; text-transform:uppercase;">Client</th>
                        <th style="text-align:left; padding:10px 14px; font-size:11px; color:#6B7280; text-transform:uppercase;">Contact</th>
                        <th style="text-align:left; padding:10px 14px; font-size:11px; color:#6B7280; text-transform:uppercase;">Adresse</th>
                        <th style="text-align:center; padding:10px 14px; font-size:11px; color:#6B7280; text-transform:uppercase;">Activité</th>
                        <th style="text-align:right; padding:10px 14px; font-size:11px; color:#6B7280; text-transform:uppercase;">CA encaissé</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($clients as $c):
                        $ctags = $tags_by_client[(int)$c['id']] ?? [];
                    ?>
                    <tr style="border-top:1px solid #E5E7EB;">
                        <td style="padding:10px 14px; font-size:13px;">
                            <strong><?= h($c['display_name']) ?></strong>
                            <span style="font-size:10px; color:#6B7280;"><?= $c['client_type'] === 'individual' ? '👤 Particulier' : '🏢 Entreprise/Asso' ?></span>
                            <?= ak_tag_render_chips($ctags) ?>
                        </td>
                        <td style="padding:10px 14px; font-size:13px;">
                            <?= h($c['email']) ?><br>
                            <span style="color:#6B7280; font-size:11px;"><?= h($c['phone'] ?? '') ?></span>
                        </td>
                        <td style="padding:10px 14px; font-size:12px; color:#6B7280;">
                            <?= h(trim(($c['address_zip'] ?? '') . ' ' . ($c['address_city'] ?? ''))) ?: '—' ?>
                        </td>
                        <td style="padding:10px 14px; font-size:12px; text-align:center;">
                            <span style="color:#10B981;"><?= (int)$c['nb_invoices'] ?> fact.</span> ·
                            <span style="color:#7E22CE;"><?= (int)$c['nb_quotes'] ?> devis</span>
                        </td>
                        <td style="padding:10px 14px; font-size:13px; text-align:right; font-variant-numeric:tabular-nums; font-weight:600; color:#10B981;">
                            <?= h(ak_asso_fmt_cents((int)$c['total_paid'])) ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?= ak_search_render_livesearch('live-search', 'table tbody tr') ?>

<?php render_foot(); ?>
