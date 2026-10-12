<?php
/**
 * mon-asso-tags.php — Gestion tags
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes-layout.php';
require_once __DIR__ . '/asso-tags-helpers.php';

require_login();
$user = current_user();
if (empty($user['org_id'])) { http_response_code(403); die('Aucune asso.'); }
$org_id = (int)$user['org_id'];

// Admin ou coordinateur : les tags classent factures, devis et clients, dont l'accès est réservé
// à ces rôles (user_can_access_billing) ; l'entrée « Tags » du menu suit la même règle.
$is_admin = false;
try {
    $stmt = $pdo->prepare("SELECT role FROM users WHERE id = :id");
    $stmt->execute([':id' => (int)$user['id']]);
    $is_admin = in_array($stmt->fetchColumn(), ['admin', 'coordinator'], true);
} catch (Throwable $e) {}
if (!$is_admin) { http_response_code(403); die('Accès refusé.'); }

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

$flash = $_SESSION['flash_tags'] ?? null;
unset($_SESSION['flash_tags']);

// Écran d'attribution : nombre maximal d'éléments listés par type (les plus récents)
const AK_TAG_ASSIGN_MAX = 500;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        http_response_code(419); exit('CSRF.');
    }

    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'create') {
            $name = trim((string)($_POST['name'] ?? ''));
            $color = preg_match('/^#[0-9a-f]{6}$/i', $_POST['color'] ?? '') ? $_POST['color'] : '#6B7280';
            if ($name === '') throw new RuntimeException('Nom requis.');
            ak_tag_create($pdo, $org_id, $name, $color, (int)$user['id']);
            $_SESSION['flash_tags'] = ['type'=>'success', 'message'=>'Tag « ' . $name . ' » créé.'];
        } elseif ($action === 'update') {
            $id = (int)($_POST['id'] ?? 0);
            $name = trim((string)($_POST['name'] ?? ''));
            if ($name === '') throw new RuntimeException('Nom requis.');
            $color = preg_match('/^#[0-9a-f]{6}$/i', $_POST['color'] ?? '') ? $_POST['color'] : '#6B7280';
            $pdo->prepare("UPDATE asso_tags SET name=:n, slug=:s, color=:c WHERE id=:id AND org_id=:o")
                ->execute([':n'=>$name, ':s'=>ak_tag_slug($name), ':c'=>$color, ':id'=>$id, ':o'=>$org_id]);
            $_SESSION['flash_tags'] = ['type'=>'success', 'message'=>'Tag modifié.'];
        } elseif ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            if (!ak_tag_filter_org($pdo, $org_id, [$id])) throw new RuntimeException('Tag introuvable.');
            $pdo->prepare("DELETE FROM asso_tag_links WHERE tag_id=:id")->execute([':id'=>$id]);
            $pdo->prepare("DELETE FROM asso_tags WHERE id=:id AND org_id=:o")->execute([':id'=>$id, ':o'=>$org_id]);
            $_SESSION['flash_tags'] = ['type'=>'success', 'message'=>'Tag supprimé.'];
        } elseif ($action === 'assign') {
            // Attribution du tag aux clients, factures et devis cochés (liste recalculée ici,
            // limitée à l'association : un id posté d'une autre association est ignoré).
            $id = (int)($_POST['id'] ?? 0);
            if (!ak_tag_filter_org($pdo, $org_id, [$id])) throw new RuntimeException('Tag introuvable.');
            $n = 0;
            foreach (['client', 'invoice', 'quote'] as $type) {
                $shown = array_map(fn($r) => (int)$r['id'], ak_tag_assignable($pdo, $org_id, $type, AK_TAG_ASSIGN_MAX));
                $checked = (array)($_POST['sel'][$type] ?? []);
                ak_tag_set_entities_for_tag($pdo, $org_id, $id, $type, $checked, $shown);
                $n += count(array_intersect(array_map('intval', $checked), $shown));
            }
            $_SESSION['flash_tags'] = ['type'=>'success', 'message'=>'Tag attribué à ' . $n . ' élément' . ($n > 1 ? 's' : '') . '.'];
            header('Location: /mon-asso-tags?tag=' . $id); exit;
        }
    } catch (Throwable $e) {
        $_SESSION['flash_tags'] = ['type'=>'error', 'message'=>'Erreur : ' . $e->getMessage()];
    }
    header('Location: /mon-asso-tags'); exit;
}

$tags = ak_tag_list($pdo, $org_id);

// Attribution d'un tag (?tag=<id>) : clients, factures et devis de l'association, cochés s'ils l'ont déjà.
$assign_tag = null;
$assign_lists = [];
$assign_linked = [];
if (!empty($_GET['tag'])) {
    foreach ($tags as $t) if ((int)$t['id'] === (int)$_GET['tag']) $assign_tag = $t;
    if ($assign_tag) {
        foreach (['client', 'invoice', 'quote'] as $type) {
            $assign_lists[$type] = ak_tag_assignable($pdo, $org_id, $type, AK_TAG_ASSIGN_MAX);
            $st = $pdo->prepare("SELECT entity_id FROM asso_tag_links WHERE tag_id = ? AND entity_type = ?");
            $st->execute([(int)$assign_tag['id'], $type]);
            $assign_linked[$type] = array_flip(array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN)));
        }
    }
}

// Compter usages
$tag_usage = [];
if (!empty($tags)) {
    $ids = array_map(fn($t) => (int)$t['id'], $tags);
    $in = implode(',', $ids);
    $stmt = $pdo->query("SELECT tag_id, entity_type, COUNT(*) AS n FROM asso_tag_links WHERE tag_id IN ($in) GROUP BY tag_id, entity_type");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $tag_usage[(int)$r['tag_id']][$r['entity_type']] = (int)$r['n'];
    }
}

render_head('Mes tags');
render_sidebar('tags');

$colors = ['#6B7280','#EF4444','#F59E0B','#10B981','#3B82F6','#8B5CF6','#EC4899','#7E22CE','#0891B2','#059669'];
?>

<div class="main">
    <div class="main-head">
        <div>
            <h1 class="page-title" style="display:flex; align-items:center; gap:11px;"><?= ak_icon_badge('tag','#059669',36) ?><span>Mes tags</span></h1>
            <div class="page-sub">Catégorise tes factures, devis et clients</div>
        </div>
        <a href="/mon-asso-factures" class="btn btn-ghost">← Retour</a>
    </div>

    <?php if ($flash): ?>
    <div class="alert <?= $flash['type'] === 'success' ? 'alert-success' : 'alert-error' ?>" style="margin-bottom:16px;">
        <?= h($flash['message']) ?>
    </div>
    <?php endif; ?>

    <?php if ($assign_tag):
        $tc = h($assign_tag['color']);
        $type_labels = ['client' => ['Clients', 'Aucun client.', '/mon-asso-clients'], 'invoice' => ['Factures', 'Aucune facture.', ''], 'quote' => ['Devis', 'Aucun devis.', '/mon-asso-devis']];
    ?>
    <!-- ATTRIBUTION -->
    <div class="card" style="padding:22px; margin-bottom:16px;">
        <div style="display:flex; justify-content:space-between; align-items:center; gap:10px; flex-wrap:wrap; margin-bottom:12px;">
            <h3 style="margin:0; font-size:15px;">Attribuer le tag
                <span style="display:inline-block; padding:3px 10px; border-radius:12px; font-size:13px; font-weight:600; background:<?= $tc ?>22; color:<?= $tc ?>; border:1px solid <?= $tc ?>44;"><?= h($assign_tag['name']) ?></span>
            </h3>
            <a href="/mon-asso-tags" class="btn btn-ghost" style="padding:6px 12px; font-size:12.5px;">Fermer</a>
        </div>
        <p style="margin:0 0 14px; font-size:12.5px; color:#6B7280;">Coche les clients, factures et devis à classer sous ce tag, puis enregistre. Les listes Clients et Devis peuvent ensuite être filtrées par tag.</p>
        <form method="POST" action="/mon-asso-tags">
            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
            <input type="hidden" name="action" value="assign">
            <input type="hidden" name="id" value="<?= (int)$assign_tag['id'] ?>">
            <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(260px, 1fr)); gap:14px;">
                <?php foreach ($assign_lists as $type => $items): [$lbl, $empty, $list_url] = $type_labels[$type]; ?>
                <div style="border:1px solid #E5E7EB; border-radius:10px; padding:12px;">
                    <div style="display:flex; justify-content:space-between; align-items:baseline; margin-bottom:8px;">
                        <strong style="font-size:13px;"><?= $lbl ?></strong>
                        <span style="font-size:11.5px; color:#6B7280;"><?= count($assign_linked[$type] ?? []) ?> avec ce tag</span>
                    </div>
                    <?php if (!$items): ?>
                        <div style="font-size:12.5px; color:#6B7280;"><?= $empty ?></div>
                    <?php else: ?>
                        <input type="search" placeholder="Filtrer…" oninput="akTagFilter(this)" style="width:100%; padding:7px 9px; border:1px solid #E5E7EB; border-radius:7px; font-size:12.5px; margin-bottom:8px; box-sizing:border-box;">
                        <div class="ak-tag-assign-list" style="max-height:320px; overflow:auto;">
                            <?php foreach ($items as $it): $eid = (int)$it['id']; ?>
                            <label style="display:flex; gap:8px; align-items:flex-start; padding:5px 2px; font-size:12.5px; cursor:pointer; border-top:1px solid #F3F4F6;" data-q="<?= h(mb_strtolower($it['label'] . ' ' . ($it['sub'] ?? ''))) ?>">
                                <input type="checkbox" name="sel[<?= $type ?>][]" value="<?= $eid ?>" <?= isset($assign_linked[$type][$eid]) ? 'checked' : '' ?> style="margin-top:2px;">
                                <span><span style="font-weight:600; color:#111827;"><?= h($it['label']) ?></span><?php if (!empty($it['sub'])): ?><br><span style="color:#6B7280; font-size:11.5px;"><?= h($it['sub']) ?></span><?php endif; ?></span>
                            </label>
                            <?php endforeach; ?>
                        </div>
                        <?php if (count($items) >= AK_TAG_ASSIGN_MAX): ?>
                        <div style="font-size:11.5px; color:#6B7280; margin-top:6px;">Les <?= AK_TAG_ASSIGN_MAX ?> plus récents sont affichés.</div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
            <div style="display:flex; justify-content:flex-end; margin-top:14px;">
                <button type="submit" class="btn btn-primary">Enregistrer l’attribution</button>
            </div>
        </form>
    </div>
    <?php endif; ?>

    <!-- CRÉATION -->
    <div class="card" style="padding:22px; margin-bottom:16px;">
        <h3 style="margin:0 0 14px 0; font-size:15px;">+ Nouveau tag</h3>
        <form method="POST" action="/mon-asso-tags" style="display:grid; grid-template-columns:2fr auto auto; gap:10px; align-items:end;">
            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
            <input type="hidden" name="action" value="create">
            <div>
                <label style="display:block; font-size:12px; color:#6B7280; margin-bottom:6px;">Nom du tag</label>
                <input type="text" name="name" maxlength="80" required placeholder="ex: Récurrent, Subvention, Projet X..." style="width:100%; padding:10px; border:1px solid #E5E7EB; border-radius:8px;">
            </div>
            <div>
                <label style="display:block; font-size:12px; color:#6B7280; margin-bottom:6px;">Couleur</label>
                <div style="display:flex; gap:5px; align-items:center;">
                    <?php foreach ($colors as $c): ?>
                        <label style="cursor:pointer;">
                            <input type="radio" name="color" value="<?= $c ?>" <?= $c === '#6B7280' ? 'checked' : '' ?> style="display:none;" onchange="document.querySelectorAll('.color-swatch').forEach(s => s.style.outline='none'); this.parentElement.querySelector('.color-swatch').style.outline='3px solid #1F2937'">
                            <span class="color-swatch" style="display:inline-block; width:24px; height:24px; border-radius:50%; background:<?= $c ?>; <?= $c === '#6B7280' ? 'outline:3px solid #1F2937;' : '' ?>"></span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>
            <button type="submit" class="btn btn-primary" style="padding:10px 20px;">+ Créer</button>
        </form>
    </div>

    <!-- LISTE -->
    <div class="card" style="padding:0; overflow:hidden;">
        <?php if (empty($tags)): ?>
            <div style="padding:40px; text-align:center; color:#6B7280;">
                <div style="color:#64748B; margin-bottom:10px;"><?= ak_icon('tag',44,'1.5') ?></div>
                Aucun tag créé. Crée ton premier tag ci-dessus !
            </div>
        <?php else: ?>
            <table style="width:100%; border-collapse:collapse;">
                <thead style="background:#F9FAFB;">
                    <tr>
                        <th style="text-align:left; padding:12px 14px; font-size:11px; color:#6B7280; text-transform:uppercase;">Tag</th>
                        <th style="text-align:left; padding:12px 14px; font-size:11px; color:#6B7280; text-transform:uppercase;">Utilisé sur</th>
                        <th style="text-align:right; padding:12px 14px; font-size:11px; color:#6B7280; text-transform:uppercase;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($tags as $t):
                        $usage = $tag_usage[(int)$t['id']] ?? [];
                        $total = array_sum($usage);
                    ?>
                    <tr style="border-top:1px solid #E5E7EB;">
                        <td style="padding:12px 14px;">
                            <span style="display:inline-block; padding:4px 12px; border-radius:12px; font-size:13px; font-weight:600; background:<?= h($t['color']) ?>22; color:<?= h($t['color']) ?>; border:1px solid <?= h($t['color']) ?>44;"><?= h($t['name']) ?></span>
                        </td>
                        <td style="padding:12px 14px; font-size:13px; color:#6B7280;">
                            <?php if ($total > 0): ?>
                                <?php $parts = []; foreach (['invoice'=>['facture',''],'quote'=>['devis','/mon-asso-devis'],'client'=>['client','/mon-asso-clients']] as $k=>[$lbl, $url]): $n = $usage[$k] ?? 0; if ($n > 0) { $txt = "$n " . $lbl . ($n > 1 && $lbl !== 'devis' ? 's' : ''); $parts[] = $url ? '<a href="' . $url . '?tag=' . (int)$t['id'] . '" style="color:inherit;">' . h($txt) . '</a>' : h($txt); } endforeach; echo implode(' · ', $parts); ?>
                            <?php else: ?>
                                <span style="color:#6B7280;">Pas encore utilisé</span>
                            <?php endif; ?>
                        </td>
                        <td style="padding:12px 14px; text-align:right; white-space:nowrap;">
                            <a href="/mon-asso-tags?tag=<?= (int)$t['id'] ?>" class="btn btn-ghost" style="padding:5px 10px; font-size:12px;">Attribuer</a>
                            <button type="button" onclick="editTag(<?= (int)$t['id'] ?>, <?= h(json_encode((string)$t['name'], JSON_UNESCAPED_UNICODE)) ?>, <?= h(json_encode((string)$t['color'])) ?>)" class="btn btn-ghost" style="padding:5px 10px; font-size:12px; display:inline-flex; align-items:center;"><?= ak_icon('edit',15) ?></button>
                            <form method="POST" action="/mon-asso-tags" style="display:inline;" onsubmit="return confirm('Supprimer ce tag ? Toutes les associations seront perdues.');">
                                <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
                                <button type="submit" class="btn btn-ghost" style="padding:5px 10px; font-size:12px; color:#DC2626; display:inline-flex; align-items:center;"><?= ak_icon('trash',15) ?></button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<!-- MODAL EDIT -->
<div id="modal-edit" style="display:none; position:fixed; top:0; left:0; right:0; bottom:0; background:rgba(0,0,0,0.5); z-index:1000; align-items:center; justify-content:center; padding:20px;">
    <div style="background:white; border-radius:12px; padding:24px; max-width:500px; width:100%;">
        <h3 style="margin:0 0 14px 0; font-size:16px; display:flex; align-items:center; gap:8px;"><?= ak_icon('edit',18) ?> Modifier le tag</h3>
        <form method="POST" action="/mon-asso-tags">
            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="id" id="edit-id">

            <label style="display:block; font-size:12px; color:#6B7280; margin-bottom:6px;">Nom</label>
            <input type="text" name="name" id="edit-name" required style="width:100%; padding:10px; border:1px solid #E5E7EB; border-radius:8px; margin-bottom:12px;">

            <label style="display:block; font-size:12px; color:#6B7280; margin-bottom:6px;">Couleur</label>
            <div id="edit-colors" style="display:flex; gap:6px; flex-wrap:wrap; margin-bottom:14px;">
                <?php foreach ($colors as $c): ?>
                    <label style="cursor:pointer;">
                        <input type="radio" name="color" value="<?= $c ?>" style="display:none;" onchange="document.querySelectorAll('#edit-colors .swatch').forEach(s => s.style.outline='none'); this.parentElement.querySelector('.swatch').style.outline='3px solid #1F2937'">
                        <span class="swatch" data-color="<?= $c ?>" style="display:inline-block; width:28px; height:28px; border-radius:50%; background:<?= $c ?>;"></span>
                    </label>
                <?php endforeach; ?>
            </div>

            <div style="display:flex; gap:10px; justify-content:flex-end;">
                <button type="button" onclick="document.getElementById('modal-edit').style.display='none'" class="btn btn-ghost">Annuler</button>
                <button type="submit" class="btn btn-primary">💾 Sauvegarder</button>
            </div>
        </form>
    </div>
</div>

<script>
function akTagFilter(input) {
    const q = input.value.trim().toLowerCase();
    input.parentElement.querySelectorAll('.ak-tag-assign-list label').forEach(l => {
        l.style.display = (!q || (l.dataset.q || '').indexOf(q) !== -1) ? 'flex' : 'none';
    });
}
function editTag(id, name, color) {
    document.getElementById('edit-id').value = id;
    document.getElementById('edit-name').value = name;
    document.querySelectorAll('#edit-colors input[type=radio]').forEach(r => {
        r.checked = (r.value === color);
        const sw = r.parentElement.querySelector('.swatch');
        sw.style.outline = (r.value === color) ? '3px solid #1F2937' : 'none';
    });
    document.getElementById('modal-edit').style.display = 'flex';
}
</script>

<?php render_foot(); ?>
