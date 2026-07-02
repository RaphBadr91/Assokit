<?php
/**
 * ============================================================
 * Super Admin Fondateur — Gestion des Mairies/Collectivités
 * URL : /super-admin-mairies
 * Accès : is_platform_admin uniquement
 * ============================================================
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes-layout.php';
require_once __DIR__ . '/includes-permissions.php';
require_login();
require_platform_admin();

$filter = $_GET['filter'] ?? 'all';
$where = '1=1';
if ($filter === 'active')    $where = "status = 'active'";
elseif ($filter === 'pending')   $where = "status = 'pending'";
elseif ($filter === 'suspended') $where = "status = 'suspended'";
elseif ($filter === 'rejected')  $where = "status = 'rejected'";

$mairies = $pdo->query("
    SELECT po.*,
           (SELECT COUNT(*) FROM organizations WHERE parent_org_id = po.id) AS nb_orgs,
           (SELECT COUNT(*) FROM users WHERE parent_org_id = po.id) AS nb_users
    FROM parent_orgs po
    WHERE $where
    ORDER BY po.created_at DESC
")->fetchAll(PDO::FETCH_ASSOC);

$stats = $pdo->query("
    SELECT
      COUNT(*) AS total,
      SUM(status='active') AS active,
      SUM(status='pending') AS pending,
      SUM(status='suspended') AS suspended,
      SUM(validated_quota) AS total_quota,
      (SELECT COUNT(*) FROM organizations WHERE parent_org_id IS NOT NULL) AS linked_orgs
    FROM parent_orgs
")->fetch(PDO::FETCH_ASSOC);

render_head('Mairies & Collectivités');
render_sidebar('super-admin');
?>

<div style="max-width:1300px;margin:0 auto;padding:24px;">

  <?php if (!empty($_SESSION['flash_success'])): ?>
    <div style="background:#D1FAE5;border:1px solid #10B981;color:#065F46;padding:12px 16px;border-radius:8px;margin-bottom:16px;font-weight:600;">
      ✅ <?= htmlspecialchars($_SESSION['flash_success']) ?>
    </div>
    <?php unset($_SESSION['flash_success']); ?>
  <?php endif; ?>

  <?php if (!empty($_SESSION['flash_error'])): ?>
    <div style="background:#FEE2E2;border:1px solid #DC2626;color:#991B1B;padding:12px 16px;border-radius:8px;margin-bottom:16px;font-weight:600;">
      ❌ <?= htmlspecialchars($_SESSION['flash_error']) ?>
    </div>
    <?php unset($_SESSION['flash_error']); ?>
  <?php endif; ?>

  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:24px;">
    <div>
      <h1 style="font-size:26px;margin:0;font-weight:700;color:#0A0A0B;">🏛 Mairies & Collectivités</h1>
      <p style="color:#71717A;margin:4px 0 0;font-size:13.5px;">Super Admin Fondateur · gestion des partenaires institutionnels</p>
    </div>
    <a href="/super-admin-mairie-nouvelle" style="background:#059669;color:#fff;padding:10px 18px;border-radius:8px;text-decoration:none;font-weight:600;font-size:14px;">+ Créer une mairie</a>
  </div>

  <!-- Stats -->
  <div style="display:grid;grid-template-columns:repeat(5,1fr);gap:14px;margin-bottom:24px;">
    <div style="background:#fff;border:1px solid #E5E7EB;border-radius:10px;padding:16px;">
      <div style="font-size:11px;color:#71717A;text-transform:uppercase;letter-spacing:0.05em;">Total</div>
      <div style="font-size:24px;font-weight:700;color:#0A0A0B;"><?= (int)$stats['total'] ?></div>
    </div>
    <div style="background:#fff;border:1px solid #D1FAE5;border-radius:10px;padding:16px;">
      <div style="font-size:11px;color:#059669;text-transform:uppercase;letter-spacing:0.05em;">Actives</div>
      <div style="font-size:24px;font-weight:700;color:#059669;"><?= (int)$stats['active'] ?></div>
    </div>
    <div style="background:#fff;border:1px solid #FEF3C7;border-radius:10px;padding:16px;">
      <div style="font-size:11px;color:#B45309;text-transform:uppercase;letter-spacing:0.05em;">En attente</div>
      <div style="font-size:24px;font-weight:700;color:#B45309;"><?= (int)$stats['pending'] ?></div>
    </div>
    <div style="background:#fff;border:1px solid #E5E7EB;border-radius:10px;padding:16px;">
      <div style="font-size:11px;color:#71717A;text-transform:uppercase;letter-spacing:0.05em;">Quota total</div>
      <div style="font-size:24px;font-weight:700;color:#0A0A0B;"><?= (int)$stats['total_quota'] ?></div>
    </div>
    <div style="background:#fff;border:1px solid #E5E7EB;border-radius:10px;padding:16px;">
      <div style="font-size:11px;color:#71717A;text-transform:uppercase;letter-spacing:0.05em;">Asso liées</div>
      <div style="font-size:24px;font-weight:700;color:#0A0A0B;"><?= (int)$stats['linked_orgs'] ?></div>
    </div>
  </div>

  <!-- Filtres -->
  <div style="display:flex;gap:8px;margin-bottom:20px;flex-wrap:wrap;">
    <?php foreach (['all'=>'Toutes','active'=>'Actives','pending'=>'En attente','suspended'=>'Suspendues','rejected'=>'Rejetées'] as $k => $label): ?>
      <a href="?filter=<?= $k ?>" style="padding:7px 14px;border-radius:8px;font-size:13px;text-decoration:none;<?= $filter===$k ? 'background:#0A0A0B;color:#fff;font-weight:600;' : 'background:#fff;color:#3F3F46;border:1px solid #E5E7EB;' ?>"><?= $label ?></a>
    <?php endforeach; ?>
  </div>

  <!-- Liste -->
  <?php if (empty($mairies)): ?>
    <div style="background:#fff;border:1px dashed #D4D4D8;border-radius:12px;padding:60px 24px;text-align:center;">
      <div style="font-size:48px;margin-bottom:12px;">🏛</div>
      <h3 style="margin:0 0 6px;color:#0A0A0B;">Aucune mairie <?= $filter !== 'all' ? '('.$filter.')' : '' ?></h3>
      <p style="color:#71717A;margin:0 0 18px;">Crée la première mairie partenaire pour démarrer.</p>
      <a href="/super-admin-mairie-nouvelle" style="background:#059669;color:#fff;padding:10px 18px;border-radius:8px;text-decoration:none;font-weight:600;">+ Créer une mairie</a>
    </div>
  <?php else: ?>
    <div style="display:grid;gap:12px;">
      <?php foreach ($mairies as $m):
        $status_color = match($m['status']) {
          'active' => ['bg'=>'#D1FAE5','txt'=>'#059669','label'=>'🟢 Active'],
          'pending' => ['bg'=>'#FEF3C7','txt'=>'#B45309','label'=>'🟠 En attente'],
          'suspended' => ['bg'=>'#FEE2E2','txt'=>'#B91C1C','label'=>'🔴 Suspendue'],
          'rejected' => ['bg'=>'#F4F4F5','txt'=>'#52525B','label'=>'⚫ Rejetée'],
          default => ['bg'=>'#F4F4F5','txt'=>'#52525B','label'=>'?']
        };
        $type_emoji = match($m['type']) {
          'mairie' => '🏛', 'departement' => '🏢', 'region' => '🌍',
          'drac' => '🎭', 'caf' => '👨‍👩‍👧', 'federation' => '🤝', default => '🏢'
        };
        $quota_used_pct = $m['validated_quota'] > 0 ? min(100, round($m['nb_orgs'] / $m['validated_quota'] * 100)) : 0;
      ?>
        <div style="background:#fff;border:1px solid #E5E7EB;border-radius:12px;padding:18px 22px;">
          <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:20px;">
            <div style="flex:1;">
              <div style="display:flex;align-items:center;gap:10px;margin-bottom:6px;">
                <span style="font-size:22px;"><?= $type_emoji ?></span>
                <h3 style="margin:0;font-size:17px;color:#0A0A0B;"><?= htmlspecialchars($m['name']) ?></h3>
                <span style="background:<?= $status_color['bg'] ?>;color:<?= $status_color['txt'] ?>;padding:3px 9px;border-radius:6px;font-size:11px;font-weight:600;"><?= $status_color['label'] ?></span>
              </div>
              <div style="font-size:13px;color:#71717A;line-height:1.65;">
                <?php if ($m['address_city']): ?>📍 <?= htmlspecialchars(($m['address_zip'] ?? '') . ' ' . $m['address_city']) ?> · <?php endif; ?>
                <?php if ($m['contact_email']): ?>✉ <?= htmlspecialchars($m['contact_email']) ?> · <?php endif; ?>
                <?php if ($m['siret']): ?>SIRET <?= htmlspecialchars($m['siret']) ?><?php endif; ?>
              </div>
              <div style="margin-top:12px;display:flex;gap:18px;font-size:12.5px;color:#3F3F46;">
                <span><strong style="color:#0A0A0B;font-size:14px;"><?= (int)$m['nb_orgs'] ?></strong> / <?= (int)$m['validated_quota'] ?> asso</span>
                <span><strong style="color:#0A0A0B;font-size:14px;"><?= (int)$m['nb_users'] ?></strong> utilisateur(s)</span>
                <span style="color:#71717A;">Créée le <?= date('d/m/Y', strtotime($m['created_at'])) ?></span>
              </div>
              <?php if ($m['validated_quota'] > 0): ?>
                <div style="margin-top:8px;height:5px;background:#F4F4F5;border-radius:3px;overflow:hidden;">
                  <div style="width:<?= $quota_used_pct ?>%;height:100%;background:<?= $quota_used_pct > 80 ? '#DC2626' : ($quota_used_pct > 50 ? '#F59E0B' : '#10B981') ?>;"></div>
                </div>
              <?php endif; ?>
            </div>
            <div style="display:flex;gap:8px;flex-direction:column;">
              <a href="/super-admin-mairie-detail?id=<?= (int)$m['id'] ?>" style="background:#0A0A0B;color:#fff;padding:8px 16px;border-radius:7px;text-decoration:none;font-size:13px;text-align:center;">Gérer</a>
              <?php if ($m['status'] === 'pending'): ?>
                <a href="/super-admin-mairie-detail?id=<?= (int)$m['id'] ?>#validate" style="background:#059669;color:#fff;padding:8px 16px;border-radius:7px;text-decoration:none;font-size:13px;text-align:center;">✓ Valider</a>
              <?php endif; ?>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<?php /* render_footer si existe */ ?>
