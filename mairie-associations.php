<?php
/**
 * Liste complète des associations rattachées à la mairie
 * URL : /mairie-associations
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes-layout.php';
require_once __DIR__ . '/includes-permissions.php';
require_login();

$current = current_user();
$is_platform = is_platform_admin($current);
$parent_org = current_parent_org();
if ($is_platform && !empty($_GET['as'])) {
    $parent_org = get_parent_org((int)$_GET['as']);
}
if (!$parent_org) {
    header('Location: ' . ($is_platform ? '/super-admin-mairies' : '/dashboard'));
    exit;
}

$parent_id = (int)$parent_org['id'];
$orgs = get_orgs_for_parent_org($parent_id);

// Filtres GET
$search = trim($_GET['q'] ?? '');
$sort = $_GET['sort'] ?? 'name';

// Enrichir chaque org avec stats détaillées
$enriched = [];
foreach ($orgs as $o) {
    $oid = (int)$o['id'];
    $stats = ['adherents' => 0, 'grants_active' => 0, 'grants_total' => 0, 'projects_active' => 0, 'projects_total' => 0, 'budget' => 0, 'last_login' => null];
    try { $s = $pdo->prepare("SELECT COUNT(*) FROM users WHERE deleted_at IS NULL AND org_id=?"); $s->execute([$oid]); $stats['adherents'] = (int)$s->fetchColumn(); } catch (Exception $e) {}
    try { $s = $pdo->prepare("SELECT COUNT(*), SUM(CASE WHEN status IN ('in_progress','submitted','in_review') THEN 1 ELSE 0 END) FROM grants WHERE org_id=?"); $s->execute([$oid]); $r = $s->fetch(PDO::FETCH_NUM); $stats['grants_total'] = (int)$r[0]; $stats['grants_active'] = (int)$r[1]; } catch (Exception $e) {}
    try { $s = $pdo->prepare("SELECT COUNT(*), SUM(CASE WHEN p.status IN ('active','warning') THEN 1 ELSE 0 END), COALESCE(SUM(p.budget_planned),0) FROM projects p JOIN folders f ON p.folder_id=f.id WHERE f.org_id=? AND (p.archived_at IS NULL OR p.archived_at = '0000-00-00 00:00:00')"); $s->execute([$oid]); $r = $s->fetch(PDO::FETCH_NUM); $stats['projects_total'] = (int)$r[0]; $stats['projects_active'] = (int)$r[1]; $stats['budget'] = (float)$r[2]; } catch (Exception $e) {}
    try { $s = $pdo->prepare("SELECT MAX(last_login_at) FROM users WHERE org_id=? AND last_login_at IS NOT NULL"); $s->execute([$oid]); $stats['last_login'] = $s->fetchColumn(); } catch (Exception $e) {}

    $o['stats'] = $stats;
    if ($search === '' || stripos($o['name'], $search) !== false || stripos($o['billing_address_city'] ?? '', $search) !== false) {
        $enriched[] = $o;
    }
}

// Tri
usort($enriched, function($a, $b) use ($sort) {
    return match($sort) {
        'adherents' => $b['stats']['adherents'] <=> $a['stats']['adherents'],
        'projects' => $b['stats']['projects_active'] <=> $a['stats']['projects_active'],
        'budget' => $b['stats']['budget'] <=> $a['stats']['budget'],
        'last_login' => strcmp($b['stats']['last_login'] ?? '', $a['stats']['last_login'] ?? ''),
        default => strcmp($a['name'], $b['name']),
    };
});

render_head('Associations · ' . $parent_org['name']);
render_sidebar('mairie-assos');
?>

<div style="max-width:1300px;margin:0 auto;padding:clamp(12px,3vw,24px);">

  <h1 style="margin:0 0 6px;font-size:24px;color:#0A0A0B;font-weight:700;">🏢 Mes associations</h1>
  <p style="color:#71717A;font-size:13.5px;margin:0 0 22px;"><?= count($enriched) ?> association<?= count($enriched) > 1 ? 's' : '' ?> rattachée<?= count($enriched) > 1 ? 's' : '' ?> à <strong style="color:#3F3F46;"><?= h($parent_org['name']) ?></strong></p>

  <!-- Filtres -->
  <form method="GET" style="background:#fff;border:1px solid #E5E7EB;border-radius:10px;padding:14px;margin-bottom:18px;display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
    <input type="text" name="q" value="<?= h($search) ?>" placeholder="🔍 Rechercher par nom ou ville..." style="flex:1;min-width:240px;padding:9px 14px;border:1px solid #D4D4D8;border-radius:7px;font-size:13.5px;">
    <select name="sort" style="padding:9px 12px;border:1px solid #D4D4D8;border-radius:7px;font-size:13px;background:#fff;">
      <option value="name" <?= $sort === 'name' ? 'selected' : '' ?>>Trier par nom</option>
      <option value="adherents" <?= $sort === 'adherents' ? 'selected' : '' ?>>Trier par adhérents</option>
      <option value="projects" <?= $sort === 'projects' ? 'selected' : '' ?>>Trier par projets actifs</option>
      <option value="budget" <?= $sort === 'budget' ? 'selected' : '' ?>>Trier par budget</option>
      <option value="last_login" <?= $sort === 'last_login' ? 'selected' : '' ?>>Trier par dernière activité</option>
    </select>
    <button type="submit" style="background:#0A0A0B;color:#fff;padding:9px 18px;border:none;border-radius:7px;font-size:13px;font-weight:600;cursor:pointer;">Appliquer</button>
  </form>

  <?php if (empty($enriched)): ?>
    <div style="background:#fff;border:1px solid #E5E7EB;border-radius:10px;padding:48px;text-align:center;color:#71717A;">
      <div style="font-size:42px;margin-bottom:10px;">🏢</div>
      <strong style="color:#0A0A0B;display:block;margin-bottom:4px;">Aucune association</strong>
      <?= $search ? 'Aucun résultat pour "' . h($search) . '"' : 'Aucune association rattachée.' ?>
    </div>
  <?php else: ?>
    <div style="display:grid;gap:12px;">
      <?php foreach ($enriched as $o): $s = $o['stats']; ?>
        <div style="background:#fff;border:1px solid #E5E7EB;border-radius:11px;padding:18px 22px;display:flex;justify-content:space-between;align-items:center;gap:18px;flex-wrap:wrap;transition:all 0.15s;">
          <div style="flex:1;">
            <div style="display:flex;align-items:center;gap:10px;margin-bottom:6px;">
              <h3 style="margin:0;font-size:16px;color:#0A0A0B;font-weight:700;"><?= h($o['name']) ?></h3>
              <?php if (!empty($o['siret'])): ?><span style="font-size:11px;color:#A1A1AA;">SIRET <?= h(substr($o['siret'], 0, 9)) ?>…</span><?php endif; ?>
            </div>
            <div style="font-size:12.5px;color:#71717A;margin-bottom:10px;">
              📍 <?= h($o['billing_address_city'] ?? '—') ?>
              <?php if ($s['last_login']): ?>
                · ⏱ Dernière activité : <strong style="color:#3F3F46;"><?= date('d/m/Y H:i', strtotime($s['last_login'])) ?></strong>
              <?php else: ?>
                · <span style="color:#DC2626;font-weight:600;">⚠ Jamais connecté</span>
              <?php endif; ?>
            </div>
            <div style="display:flex;gap:18px;font-size:12.5px;flex-wrap:wrap;">
              <span>👥 <strong style="color:#0A0A0B;"><?= $s['adherents'] ?></strong> adhérents</span>
              <span title="Subventions en cours (en préparation, soumises, en examen) sur le total"><strong style="color:#059669;">💰 <?= $s['grants_active'] ?></strong>/<?= $s['grants_total'] ?> subventions en cours</span>
              <span title="Projets actifs ou en alerte sur le total"><strong style="color:#0369A1;">📁 <?= $s['projects_active'] ?></strong>/<?= $s['projects_total'] ?> projets actifs</span>
              <span title="Somme des budgets prévus de tous les projets de l'association">💵 Budget projets : <strong style="color:#0A0A0B;"><?= number_format($s['budget'], 0, ',', ' ') ?> €</strong></span>
            </div>
          </div>
          <a href="/mairie-asso?id=<?= (int)$o['id'] ?>" style="background:#0A0A0B;color:#fff;padding:9px 18px;border-radius:7px;text-decoration:none;font-size:13px;font-weight:600;white-space:nowrap;">👁 Détails</a>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

</div>
