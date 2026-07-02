<?php
/**
 * Dashboard Mairie — Vue d'ensemble pour les agents mairie/collectivité
 * URL : /mairie-dashboard
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes-layout.php';
require_once __DIR__ . '/includes-permissions.php';
require_login();

$current = current_user();
$is_platform = is_platform_admin($current);
$parent_org = current_parent_org();

// Si Raphaël (super admin) accède : sélecteur de mairie via ?as=ID
if ($is_platform && !empty($_GET['as'])) {
    $parent_org = get_parent_org((int)$_GET['as']);
}

if (!$parent_org) {
    if ($is_platform) {
        header('Location: /super-admin-mairies');
        exit;
    }
    http_response_code(403);
    die('<h1>⛔ Accès refusé</h1><p>Ce dashboard est réservé aux agents mairie/collectivité.</p>');
}

$parent_id = (int)$parent_org['id'];
$orgs = get_orgs_for_parent_org($parent_id);
$nb_orgs = count($orgs);
$org_ids = array_column($orgs, 'id');

// Stats agrégées (uniquement si on a des orgs)
$stats = ['adherents' => 0, 'grants_active' => 0, 'budget_planned' => 0, 'projects_active' => 0];
if (!empty($org_ids)) {
    $in = implode(',', array_fill(0, count($org_ids), '?'));

    // Adhérents (table members)
    try {
        $s = $pdo->prepare("SELECT COUNT(*) FROM users WHERE deleted_at IS NULL AND org_id IN ($in)");
        $s->execute($org_ids); $stats['adherents'] = (int)$s->fetchColumn();
    } catch (Exception $e) {}

    // Subventions actives
    try {
        $s = $pdo->prepare("SELECT COUNT(*) FROM grants WHERE org_id IN ($in) AND status IN ('in_progress','submitted','to_prepare')");
        $s->execute($org_ids); $stats['grants_active'] = (int)$s->fetchColumn();
    } catch (Exception $e) {}

    // Projets actifs (via folders → projects)
    try {
        $s = $pdo->prepare("SELECT COUNT(*) FROM projects p INNER JOIN folders f ON p.folder_id = f.id WHERE f.org_id IN ($in) AND p.status IN ('active','warning') AND (p.archived_at IS NULL OR p.archived_at = '0000-00-00 00:00:00')");
        $s->execute($org_ids); $stats['projects_active'] = (int)$s->fetchColumn();

        $s = $pdo->prepare("SELECT COALESCE(SUM(p.budget_planned),0) FROM projects p INNER JOIN folders f ON p.folder_id = f.id WHERE f.org_id IN ($in) AND p.status IN ('active','warning','draft') AND (p.archived_at IS NULL OR p.archived_at = '0000-00-00 00:00:00')");
        $s->execute($org_ids); $stats['budget_planned'] = (float)$s->fetchColumn();
    } catch (Exception $e) {}
}

// === Données pour graphiques (orientées suivi mairie) ===
$top_assos = [];
$top_projects = [];
$events_upcoming = 0;
$assos_inactive = 0;

if (!empty($org_ids)) {
    $in_p = implode(',', array_fill(0, count($org_ids), '?'));

    // Top 5 associations actives
    try {
        $s = $pdo->prepare("
            SELECT o.id, o.name, o.billing_address_city,
                (SELECT COUNT(*) FROM projects p JOIN folders f ON p.folder_id=f.id WHERE f.org_id=o.id AND p.status IN ('active','warning') AND (p.archived_at IS NULL OR p.archived_at='0000-00-00 00:00:00')) AS nb_projects,
                (SELECT COUNT(*) FROM users u WHERE u.org_id=o.id AND u.deleted_at IS NULL) AS nb_members,
                (SELECT MAX(u.last_login_at) FROM users u WHERE u.org_id=o.id AND u.last_login_at IS NOT NULL) AS last_login
            FROM organizations o
            WHERE o.id IN ($in_p)
            ORDER BY nb_projects DESC, nb_members DESC, last_login DESC
            LIMIT 5
        ");
        $s->execute($org_ids);
        $top_assos = $s->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}

    // Top 5 projets actifs avec nom asso
    try {
        $s = $pdo->prepare("
            SELECT p.id, p.name, p.status, p.start_date, p.end_date, p.progress_percent, p.location, p.participants_count,
                   f.name AS folder_name, o.name AS org_name,
                   u.first_name AS ref_first, u.last_name AS ref_last
            FROM projects p
            JOIN folders f ON p.folder_id=f.id
            JOIN organizations o ON f.org_id=o.id
            LEFT JOIN users u ON p.referent_id=u.id
            WHERE f.org_id IN ($in_p)
              AND p.status IN ('active','warning')
              AND (p.archived_at IS NULL OR p.archived_at='0000-00-00 00:00:00')
            ORDER BY p.participants_count DESC, p.progress_percent DESC, p.start_date DESC
            LIMIT 5
        ");
        $s->execute($org_ids);
        $top_projects = $s->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}

    // Événements à venir
    try {
        $s = $pdo->prepare("SELECT COUNT(*) FROM events WHERE org_id IN ($in_p) AND start_at >= NOW()");
        $s->execute($org_ids);
        $events_upcoming = (int)$s->fetchColumn();
    } catch (Exception $e) {}

    // Asso inactives 30 jours
    foreach ($org_ids as $oid) {
        try {
            $s = $pdo->prepare("SELECT MAX(last_login_at) FROM users WHERE org_id=? AND last_login_at IS NOT NULL");
            $s->execute([$oid]);
            $last = $s->fetchColumn();
            if (!$last || strtotime($last) < strtotime('-30 days')) $assos_inactive++;
        } catch (Exception $e) {}
    }
}

$type_emoji = match($parent_org['type']) {
    'mairie' => '🏛', 'departement' => '🏢', 'region' => '🌍',
    'drac' => '🎭', 'caf' => '👨‍👩‍👧', 'federation' => '🤝', default => '🏢'
};
$quota_pct = $parent_org['validated_quota'] > 0 ? min(100, round($nb_orgs / $parent_org['validated_quota'] * 100)) : 0;

render_head('Dashboard ' . $parent_org['name']);
render_sidebar('mairie-dashboard');
?>

<div style="max-width:1300px;margin:0 auto;padding:clamp(12px,3vw,24px);">

  <?php if (!empty($_SESSION['flash_success'])): ?>
    <div style="background:#D1FAE5;border:1px solid #10B981;color:#065F46;padding:12px 16px;border-radius:8px;margin-bottom:16px;font-weight:600;">✅ <?= h($_SESSION['flash_success']) ?></div>
    <?php unset($_SESSION['flash_success']); ?>
  <?php endif; ?>

  <!-- HEADER MAIRIE -->
  <div style="background:linear-gradient(135deg,#0A0A0B 0%,#1F2937 100%);color:#fff;border-radius:16px;padding:clamp(18px,3vw,28px) clamp(20px,3vw,32px);margin-bottom:24px;">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:24px;flex-wrap:wrap;">
      <div>
        <div style="display:flex;align-items:center;gap:14px;">
          <span style="font-size:42px;"><?= $type_emoji ?></span>
          <div>
            <div style="font-size:11px;text-transform:uppercase;letter-spacing:0.15em;opacity:0.7;">Dashboard <?= h($parent_org['type']) ?></div>
            <h1 style="margin:2px 0 0;font-size:28px;font-weight:700;letter-spacing:-0.01em;"><?= h($parent_org['name']) ?></h1>
          </div>
        </div>
        <p style="margin:10px 0 0;opacity:0.75;font-size:13.5px;">
          <?php if ($parent_org['address_city']): ?>📍 <?= h($parent_org['address_city']) ?><?php endif; ?>
          <?php if ($parent_org['contact_email']): ?> · ✉ <?= h($parent_org['contact_email']) ?><?php endif; ?>
          · Bienvenue <strong><?= h($current['first_name']) ?></strong>
        </p>
      </div>
      <div style="text-align:right;">
        <div style="font-size:11px;text-transform:uppercase;letter-spacing:0.1em;opacity:0.6;">Quota</div>
        <div style="font-size:32px;font-weight:700;line-height:1;"><?= $nb_orgs ?> <span style="opacity:0.5;font-size:18px;font-weight:500;">/ <?= (int)$parent_org['validated_quota'] ?></span></div>
        <div style="font-size:11px;opacity:0.6;margin-top:2px;">associations</div>
        <?php if ($parent_org['validated_quota'] > 0): ?>
          <div style="margin-top:8px;width:140px;height:5px;background:rgba(255,255,255,0.15);border-radius:3px;overflow:hidden;">
            <div style="width:<?= $quota_pct ?>%;height:100%;background:<?= $quota_pct > 80 ? '#F87171' : ($quota_pct > 50 ? '#FBBF24' : '#34D399') ?>;"></div>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- ASSOKIT IA · Suivi des associations -->
  <div style="background:linear-gradient(135deg,#0A0A0B,#1F2937);color:#fff;border-radius:14px;padding:20px 24px;margin-bottom:18px;">
    <div style="display:flex;align-items:center;gap:8px;margin-bottom:14px;">
      <span style="font-size:18px;">🤖</span>
      <h2 style="margin:0;font-size:15px;font-weight:700;letter-spacing:0.02em;">Assokit IA · Suivi de vos associations</h2>
    </div>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:14px;">
      <div>
        <div style="font-size:11px;opacity:0.6;text-transform:uppercase;letter-spacing:0.07em;font-weight:600;">🏢 Asso suivies</div>
        <div style="font-size:24px;font-weight:700;margin-top:3px;line-height:1;"><?= $nb_orgs ?></div>
        <div style="font-size:11px;opacity:0.6;margin-top:2px;">sur quota de <?= (int)$parent_org['validated_quota'] ?></div>
      </div>
      <div>
        <div style="font-size:11px;opacity:0.6;text-transform:uppercase;letter-spacing:0.07em;font-weight:600;">👥 Adhérents</div>
        <div style="font-size:24px;font-weight:700;margin-top:3px;line-height:1;color:#60A5FA;"><?= number_format($stats['adherents'], 0, ',', ' ') ?></div>
        <div style="font-size:11px;opacity:0.6;margin-top:2px;">cumulés</div>
      </div>
      <div>
        <div style="font-size:11px;opacity:0.6;text-transform:uppercase;letter-spacing:0.07em;font-weight:600;">📁 Projets actifs</div>
        <div style="font-size:24px;font-weight:700;margin-top:3px;line-height:1;color:#34D399;"><?= $stats['projects_active'] ?></div>
        <div style="font-size:11px;opacity:0.6;margin-top:2px;">en cours</div>
      </div>
      <div>
        <div style="font-size:11px;opacity:0.6;text-transform:uppercase;letter-spacing:0.07em;font-weight:600;">📅 Événements</div>
        <div style="font-size:24px;font-weight:700;margin-top:3px;line-height:1;color:#FBBF24;"><?= $events_upcoming ?></div>
        <div style="font-size:11px;opacity:0.6;margin-top:2px;">à venir</div>
      </div>
    </div>
    <?php if ($assos_inactive > 0): ?>
    <div style="margin-top:14px;padding:10px 14px;background:rgba(248,113,113,0.15);border-left:3px solid #F87171;border-radius:6px;font-size:12.5px;">
      ⚠ <strong><?= $assos_inactive ?> association<?= $assos_inactive > 1 ? 's' : '' ?></strong> sans connexion depuis 30 jours
    </div>
    <?php endif; ?>
  </div>

  <!-- STATS GRID -->
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:14px;margin-bottom:24px;">
    <div style="background:#fff;border:1px solid #E5E7EB;border-radius:12px;padding:18px;">
      <div style="font-size:10.5px;color:#71717A;text-transform:uppercase;letter-spacing:0.07em;font-weight:600;">🏢 Associations</div>
      <div style="font-size:30px;font-weight:700;color:#0A0A0B;margin-top:6px;line-height:1;"><?= $nb_orgs ?></div>
      <div style="font-size:11.5px;color:#71717A;margin-top:4px;">rattachées à votre <?= h($parent_org['type']) ?></div>
    </div>
    <div style="background:#fff;border:1px solid #E5E7EB;border-radius:12px;padding:18px;">
      <div style="font-size:10.5px;color:#71717A;text-transform:uppercase;letter-spacing:0.07em;font-weight:600;">👥 Adhérents</div>
      <div style="font-size:30px;font-weight:700;color:#0A0A0B;margin-top:6px;line-height:1;"><?= number_format($stats['adherents'], 0, ',', ' ') ?></div>
      <div style="font-size:11.5px;color:#71717A;margin-top:4px;">cumulés sur toutes les asso</div>
    </div>
    <div style="background:#fff;border:1px solid #E5E7EB;border-radius:12px;padding:18px;">
      <div style="font-size:10.5px;color:#71717A;text-transform:uppercase;letter-spacing:0.07em;font-weight:600;">💰 Subventions actives</div>
      <div style="font-size:30px;font-weight:700;color:#059669;margin-top:6px;line-height:1;"><?= $stats['grants_active'] ?></div>
      <div style="font-size:11.5px;color:#71717A;margin-top:4px;">en cours / soumises</div>
    </div>
    <div style="background:#fff;border:1px solid #E5E7EB;border-radius:12px;padding:18px;">
      <div style="font-size:10.5px;color:#71717A;text-transform:uppercase;letter-spacing:0.07em;font-weight:600;">📁 Projets actifs</div>
      <div style="font-size:30px;font-weight:700;color:#0A0A0B;margin-top:6px;line-height:1;"><?= $stats['projects_active'] ?></div>
      <div style="font-size:11.5px;color:#71717A;margin-top:4px;" title="Budget cumulé prévu sur tous les projets actifs/alerte/draft"><?= number_format($stats['budget_planned'], 0, ',', ' ') ?> € de budget projets</div>
    </div>
  </div>


  <!-- 2 COLONNES : TOP 5 ASSO + TOP 5 PROJETS -->
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(340px,1fr));gap:14px;margin-bottom:18px;">

    <!-- TOP 5 ASSOCIATIONS ACTIVES -->
    <?php if (!empty($top_assos)): ?>
    <div style="background:#fff;border:1px solid #E5E7EB;border-radius:14px;padding:22px;">
      <h2 style="margin:0 0 14px;font-size:16px;color:#0A0A0B;font-weight:700;">🏆 Top 5 associations actives</h2>
      <?php foreach ($top_assos as $i => $a): ?>
        <div style="display:flex;align-items:center;gap:12px;padding:12px 0;<?= $i < count($top_assos) - 1 ? 'border-bottom:1px solid #F4F4F5;' : '' ?>">
          <div style="background:#F4F4F5;color:#3F3F46;width:28px;height:28px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700;flex-shrink:0;"><?= $i + 1 ?></div>
          <div style="flex:1;min-width:0;">
            <div style="font-size:14px;font-weight:700;color:#0A0A0B;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= h($a['name']) ?></div>
            <div style="font-size:11.5px;color:#71717A;margin-top:2px;">
              📍 <?= h($a['billing_address_city'] ?? '—') ?>
              <?php if ($a['last_login']): ?> · ⏱ <?= date('d/m H:i', strtotime($a['last_login'])) ?><?php endif; ?>
            </div>
            <div style="display:flex;gap:10px;margin-top:5px;font-size:11.5px;">
              <span><strong style="color:#10B981;">📁 <?= $a['nb_projects'] ?></strong> projets actifs</span>
              <span><strong style="color:#0369A1;">👥 <?= $a['nb_members'] ?></strong> adhérents</span>
            </div>
          </div>
          <a href="/mairie-asso?id=<?= (int)$a['id'] ?>" style="background:#0A0A0B;color:#fff;padding:6px 12px;border-radius:6px;text-decoration:none;font-size:11.5px;font-weight:600;flex-shrink:0;">Voir</a>
        </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- TOP 5 PROJETS -->
    <?php if (!empty($top_projects)): ?>
    <div style="background:#fff;border:1px solid #E5E7EB;border-radius:14px;padding:22px;">
      <h2 style="margin:0 0 14px;font-size:16px;color:#0A0A0B;font-weight:700;">📁 Top 5 projets en cours</h2>
      <?php foreach ($top_projects as $i => $p):
        $st = match($p['status']) {
            'active' => ['🟢', '#10B981'],
            'warning' => ['🟠', '#F59E0B'],
            default => ['•', '#9CA3AF']
        };
      ?>
        <div style="padding:12px 0;<?= $i < count($top_projects) - 1 ? 'border-bottom:1px solid #F4F4F5;' : '' ?>">
          <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:10px;margin-bottom:4px;">
            <div style="font-size:14px;font-weight:700;color:#0A0A0B;flex:1;">
              <?= $st[0] ?> <?= h($p['name']) ?>
            </div>
            <a href="/projet/<?= (int)$p['id'] ?>" style="background:#0A0A0B;color:#fff;padding:5px 11px;border-radius:6px;text-decoration:none;font-size:11px;font-weight:600;flex-shrink:0;">Détails</a>
          </div>
          <div style="font-size:11.5px;color:#71717A;margin-bottom:5px;">
            <strong style="color:#059669;">🏢 <?= h($p['org_name']) ?></strong>
            · 📂 <?= h($p['folder_name']) ?>
            <?php if ($p['ref_first']): ?> · 👤 <?= h(trim($p['ref_first'] . ' ' . $p['ref_last'])) ?><?php endif; ?>
          </div>
          <?php if ($p['start_date'] && $p['start_date'] !== '0000-00-00'): ?>
            <div style="font-size:11px;color:#A1A1AA;">
              📅 <?= date('d/m/Y', strtotime($p['start_date'])) ?>
              <?php if ($p['end_date'] && $p['end_date'] !== '0000-00-00'): ?> → <?= date('d/m/Y', strtotime($p['end_date'])) ?><?php endif; ?>
              <?php if ((int)$p['participants_count'] > 0): ?> · 👥 <?= (int)$p['participants_count'] ?> bénéficiaires<?php endif; ?>
            </div>
          <?php endif; ?>
          <?php if ((int)$p['progress_percent'] > 0): ?>
          <div style="margin-top:6px;height:4px;background:#F4F4F5;border-radius:2px;overflow:hidden;">
            <div style="width:<?= (int)$p['progress_percent'] ?>%;height:100%;background:<?= $st[1] ?>;"></div>
          </div>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

  </div>

  <!-- LISTE DES ASSO -->
  <div style="background:#fff;border:1px solid #E5E7EB;border-radius:14px;padding:24px;">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:18px;">
      <h2 style="margin:0;font-size:18px;color:#0A0A0B;font-weight:700;">🏢 Mes associations (<?= $nb_orgs ?>)</h2>
      <input type="search" placeholder="🔍 Rechercher..." oninput="filterOrgs(this.value)" style="padding:8px 14px;border:1px solid #D4D4D8;border-radius:7px;font-size:13px;width:100%;max-width:240px;">
    </div>

    <?php if (empty($orgs)): ?>
      <div style="text-align:center;padding:48px 24px;color:#71717A;font-size:14px;background:#FAFAF9;border-radius:10px;">
        <div style="font-size:42px;margin-bottom:10px;">🏢</div>
        <strong style="color:#0A0A0B;display:block;margin-bottom:4px;">Aucune association rattachée</strong>
        Le Super Admin Fondateur doit lier des associations à votre <?= h($parent_org['type']) ?>.<br>
        <span style="font-size:12px;color:#A1A1AA;">Contact : <a href="mailto:contact@assokit.fr" style="color:#059669;">contact@assokit.fr</a></span>
      </div>
    <?php else: ?>
      <div id="orgs-list" style="display:grid;gap:10px;">
        <?php foreach ($orgs as $o):
          // Mini stats par asso
          $nb_members = 0; $nb_grants = 0; $last_login = null;
          try { $s = $pdo->prepare("SELECT COUNT(*) FROM users WHERE deleted_at IS NULL AND org_id=?"); $s->execute([$o['id']]); $nb_members = (int)$s->fetchColumn(); } catch (Exception $e) {}
          try { $s = $pdo->prepare("SELECT COUNT(*) FROM grants WHERE org_id=? AND status IN ('in_progress','submitted','in_review')"); $s->execute([$o['id']]); $nb_grants = (int)$s->fetchColumn(); } catch (Exception $e) {}
          try { $s = $pdo->prepare("SELECT MAX(last_login_at) FROM users WHERE org_id=? AND last_login_at IS NOT NULL"); $s->execute([$o['id']]); $last_login = $s->fetchColumn(); } catch (Exception $e) {}
        ?>
        <div class="org-card" data-name="<?= strtolower(h($o['name'])) ?>" style="background:#FAFAF9;border:1px solid #E5E7EB;border-radius:10px;padding:14px 18px;transition:all 0.15s;">
          <div style="display:flex;justify-content:space-between;align-items:center;gap:14px;">
            <div style="flex:1;">
              <div style="font-size:15px;font-weight:700;color:#0A0A0B;margin-bottom:4px;"><?= h($o['name']) ?></div>
              <div style="font-size:12.5px;color:#71717A;">
                <?php if ($o['billing_address_city']): ?>📍 <?= h($o['billing_address_city']) ?><?php endif; ?>
                <?php if ($o['siret']): ?> · SIRET <?= h($o['siret']) ?><?php endif; ?>
              </div>
              <div style="margin-top:8px;display:flex;gap:14px;font-size:12px;color:#3F3F46;">
                <span><strong style="color:#0A0A0B;"><?= $nb_members ?></strong> adhérents</span>
                <span><strong style="color:#059669;"><?= $nb_grants ?></strong> subv. en cours</span>
                <?php if ($last_login): ?>
                  <span style="color:#3F3F46;">⏱ <strong><?= date('d/m/Y H:i', strtotime($last_login)) ?></strong></span>
                <?php else: ?>
                  <span style="color:#DC2626;font-weight:600;">⚠ Jamais connecté</span>
                <?php endif; ?>
                <?php if ($o['parent_org_linked_at']): ?><span style="color:#A1A1AA;">· Lié le <?= date('d/m/Y', strtotime($o['parent_org_linked_at'])) ?></span><?php endif; ?>
              </div>
            </div>
            <div style="display:flex;gap:8px;">
              <a href="/mairie-asso?id=<?= (int)$o['id'] ?>" style="background:#0A0A0B;color:#fff;padding:9px 16px;border-radius:7px;text-decoration:none;font-size:13px;font-weight:600;">👁 Voir</a>
            </div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

  <div style="margin-top:24px;text-align:center;font-size:11.5px;color:#A1A1AA;">
    Dashboard mairie · powered by <strong style="color:#059669;">Assokit</strong>
  </div>
</div>

<script>
function filterOrgs(query) {
  query = query.toLowerCase();
  document.querySelectorAll('.org-card').forEach(card => {
    card.style.display = card.dataset.name.includes(query) ? '' : 'none';
  });
}
</script>
