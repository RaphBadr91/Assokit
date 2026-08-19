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
            $color = preg_match('/^#[0-9a-f]{6}$/i', $_POST['color'] ?? '') ? $_POST['color'] : '#6B7280';
            $pdo->prepare("UPDATE asso_tags SET name=:n, slug=:s, color=:c WHERE id=:id AND org_id=:o")
                ->execute([':n'=>$name, ':s'=>ak_tag_slug($name), ':c'=>$color, ':id'=>$id, ':o'=>$org_id]);
            $_SESSION['flash_tags'] = ['type'=>'success', 'message'=>'Tag modifié.'];
        } elseif ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            $pdo->prepare("DELETE FROM asso_tags WHERE id=:id AND org_id=:o")->execute([':id'=>$id, ':o'=>$org_id]);
            $_SESSION['flash_tags'] = ['type'=>'success', 'message'=>'Tag supprimé.'];
        }
    } catch (Throwable $e) {
        $_SESSION['flash_tags'] = ['type'=>'error', 'message'=>'Erreur : ' . $e->getMessage()];
    }
    header('Location: /mon-asso-tags'); exit;
}

$tags = ak_tag_list($pdo, $org_id);

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
                <div style="color:#94A3B8; margin-bottom:10px;"><?= ak_icon('tag',44,'1.5') ?></div>
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
                                <?php $parts = []; foreach (['invoice'=>'facture','quote'=>'devis','client'=>'client'] as $k=>$lbl): $n = $usage[$k] ?? 0; if ($n > 0) $parts[] = "$n " . $lbl . ($n > 1 ? 's' : ''); endforeach; echo implode(' · ', $parts); ?>
                            <?php else: ?>
                                <span style="color:#9CA3AF;">Pas encore utilisé</span>
                            <?php endif; ?>
                        </td>
                        <td style="padding:12px 14px; text-align:right;">
                            <button type="button" onclick="editTag(<?= (int)$t['id'] ?>, '<?= h($t['name']) ?>', '<?= h($t['color']) ?>')" class="btn btn-ghost" style="padding:5px 10px; font-size:12px; display:inline-flex; align-items:center;"><?= ak_icon('edit',15) ?></button>
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
