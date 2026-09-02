<?php
/**
 * Vue mairie sur 1 association rattachée (lecture seule)
 * URL : /mairie-asso?id={org_id}
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes-layout.php';
require_once __DIR__ . '/includes-permissions.php';
require_login();

$current = current_user();
$org_id = (int)($_GET['id'] ?? 0);
if ($org_id <= 0) {
    header('Location: /mairie-dashboard');
    exit;
}

// Sécurité : l'utilisateur doit pouvoir voir cette asso
require_can_view_org($org_id);

// Charge l'asso
$stmt = $pdo->prepare("SELECT * FROM organizations WHERE id = ? LIMIT 1");
$stmt->execute([$org_id]);
$org = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$org) { http_response_code(404); die('Association introuvable.'); }

// Détermine le contexte (mairie ou super admin) pour les liens retour
$parent_org = $org['parent_org_id'] ? get_parent_org((int)$org['parent_org_id']) : null;
$is_platform = is_platform_admin($current);
$back_url = is_parent_org_user() ? '/mairie-dashboard' : ($is_platform ? '/super-admin-mairie-detail?id=' . (int)$org['parent_org_id'] : '/dashboard');

// === DONNÉES ===

// Projets (via folders)
$projects = $pdo->prepare("
    SELECT p.*, f.name AS folder_name, f.color_theme AS folder_color, f.icon AS folder_icon,
           u.first_name AS ref_first, u.last_name AS ref_last
    FROM projects p
    INNER JOIN folders f ON p.folder_id = f.id
    LEFT JOIN users u ON p.referent_id = u.id
    WHERE f.org_id = ? AND (p.archived_at IS NULL OR p.archived_at = '0000-00-00 00:00:00')
    ORDER BY
      CASE p.status
        WHEN 'active' THEN 1
        WHEN 'warning' THEN 2
        WHEN 'draft' THEN 3
        WHEN 'done' THEN 4
        ELSE 5
      END,
      p.start_date DESC
    LIMIT 100
");
$projects->execute([$org_id]);
$projects = $projects->fetchAll(PDO::FETCH_ASSOC);

// Subventions
$grants = [];
$grants_stats = ['total'=>0,'accepted'=>0,'rejected'=>0,'in_review'=>0,'submitted'=>0,'pending'=>0,'sum_requested'=>0,'sum_granted'=>0];
try {
    $g = $pdo->prepare("SELECT * FROM grants WHERE org_id = ? AND (archived_at IS NULL OR archived_at = '0000-00-00 00:00:00') ORDER BY created_at DESC LIMIT 100");
    $g->execute([$org_id]);
    $grants = $g->fetchAll(PDO::FETCH_ASSOC);
    foreach ($grants as $gr) {
        $grants_stats['total']++;
        $grants_stats['sum_requested'] += (float)$gr['amount_requested'];
        if ($gr['status'] === 'granted' || $gr['status'] === 'accepted' || $gr['status'] === 'paid') {
            $grants_stats['accepted']++;
            $grants_stats['sum_granted'] += (float)$gr['amount_granted'];
        } elseif ($gr['status'] === 'rejected') {
            $grants_stats['rejected']++;
        } elseif ($gr['status'] === 'in_review') {
            $grants_stats['in_review']++;
        } elseif ($gr['status'] === 'submitted') {
            $grants_stats['submitted']++;
        } else {
            $grants_stats['pending']++;
        }
    }
} catch (Exception $e) {}

// Adhérents
$members_count = 0;
$members_recent = [];
try {
    $m = $pdo->prepare("SELECT COUNT(*) FROM users WHERE deleted_at IS NULL AND org_id = ?");
    $m->execute([$org_id]); $members_count = (int)$m->fetchColumn();
    $m = $pdo->prepare("SELECT * FROM users WHERE deleted_at IS NULL AND org_id = ? ORDER BY created_at DESC LIMIT 5");
    $m->execute([$org_id]);
    $members_recent = $m->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// Stats projets
$stats_p = ['active'=>0, 'warning'=>0, 'done'=>0, 'draft'=>0, 'archived'=>0, 'total_budget'=>0, 'total_spent'=>0];
foreach ($projects as $p) {
    if (isset($stats_p[$p['status']])) $stats_p[$p['status']]++;
    $stats_p['total_budget'] += (float)$p['budget_planned'];
    $stats_p['total_spent'] += (float)$p['budget_used'];
}

$tab = $_GET['tab'] ?? 'projets';

render_head('Mairie · ' . $org['name']);
render_sidebar('mairie-assos');
?>

<style>
.mairie-table-wrap { overflow-x:auto; -webkit-overflow-scrolling:touch; margin:0 -8px; padding:0 8px; }
.mairie-table-wrap table { width:100%; min-width:560px; border-collapse:collapse; font-size:13px; }
.mairie-tabs { overflow-x:auto; -webkit-overflow-scrolling:touch; scrollbar-width:thin; }
.mairie-tabs::-webkit-scrollbar { height:3px; }
.mairie-tabs a { white-space:nowrap; flex-shrink:0; }
@media (max-width:1024px) {
  .mairie-grants-stats { grid-template-columns: repeat(3, 1fr) !important; }
}
@media (max-width:768px) {
  .mairie-grants-stats { grid-template-columns: repeat(2, 1fr) !important; }
  .mairie-grants-totals { grid-template-columns: 1fr !important; }
}
@media (max-width:640px) {
  .mairie-table-wrap table { font-size:12px; }
  .mairie-table-wrap th, .mairie-table-wrap td { padding:8px 6px !important; }
  .mairie-tabs a { padding:9px 12px !important; font-size:12.5px !important; }
}
</style>
<div style="max-width:1300px;margin:0 auto;padding:clamp(12px,3vw,24px);">

  <div style="margin-bottom:16px;">
    <a href="<?= h($back_url) ?>" style="color:#3F3F46;text-decoration:none;font-size:13px;">← Retour <?= is_parent_org_user() ? 'dashboard' : 'mairie' ?></a>
  </div>

  <!-- HEADER ASSO -->
  <div style="background:#fff;border:1px solid #E5E7EB;border-left:5px solid #059669;border-radius:14px;padding:22px 28px;margin-bottom:20px;">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:24px;">
      <div style="flex:1;">
        <div style="display:flex;align-items:center;gap:12px;margin-bottom:6px;flex-wrap:wrap;">
          <h1 style="margin:0;font-size:24px;color:#0A0A0B;font-weight:700;overflow-wrap:anywhere;"><?= h($org['name']) ?></h1>
          <span style="background:#F0FDF4;color:#059669;padding:3px 10px;border-radius:6px;font-size:11px;font-weight:600;border:1px solid #D1FAE5;">👁 LECTURE</span>
          <?php if ($parent_org): ?>
            <span style="font-size:12px;color:#71717A;">Suivie par <strong style="color:#3F3F46;"><?= h($parent_org['name']) ?></strong></span>
          <?php endif; ?>
        </div>
        <div style="font-size:13px;color:#71717A;line-height:1.65;">
          <?php if (!empty($org['legal_name']) && $org['legal_name'] !== $org['name']): ?>Nom légal : <?= h($org['legal_name']) ?><br><?php endif; ?>
          <?php if ($org['billing_address_city']): ?>📍 <?= h(($org['billing_address_zip'] ?? '') . ' ' . $org['billing_address_city']) ?> · <?php endif; ?>
          <?php if ($org['siret']): ?>SIRET <?= h($org['siret']) ?><?php endif; ?>
          <?php if (!empty($org['rna_number'])): ?> · RNA <?= h($org['rna_number']) ?><?php endif; ?>
        </div>
      </div>
    </div>
  </div>

  <!-- ONGLETS -->
  <div class="mairie-tabs" style="display:flex;gap:4px;border-bottom:2px solid #E5E7EB;margin-bottom:24px;flex-wrap:wrap;">
    <?php
    $tabs = [
        'profil'    => ['📋 Profil', null],
        'projets'   => ['📁 Projets', count($projects)],
        'subventions' => ['💰 Subventions', count($grants)],
        'adherents' => ['👥 Adhérents', $members_count],
    ];
    foreach ($tabs as $key => [$label, $count]):
        $active = $tab === $key;
    ?>
        <a href="?id=<?= $org_id ?>&tab=<?= $key ?>" style="padding:11px 18px;text-decoration:none;font-size:13.5px;font-weight:<?= $active ? '700' : '500' ?>;color:<?= $active ? '#059669' : '#71717A' ?>;border-bottom:2px solid <?= $active ? '#059669' : 'transparent' ?>;margin-bottom:-2px;">
            <?= $label ?>
            <?php if ($count !== null): ?><span style="background:<?= $active ? '#D1FAE5' : '#F4F4F5' ?>;color:<?= $active ? '#059669' : '#71717A' ?>;padding:1px 7px;border-radius:10px;font-size:11px;margin-left:4px;"><?= $count ?></span><?php endif; ?>
        </a>
    <?php endforeach; ?>
  </div>

  <!-- ============================================================ -->
  <!-- ONGLET PROFIL                                                  -->
  <!-- ============================================================ -->
  <?php if ($tab === 'profil'): ?>
    <div style="background:#fff;border:1px solid #E5E7EB;border-radius:12px;padding:24px;">
      <h2 style="margin:0 0 18px;font-size:17px;color:#0A0A0B;font-weight:700;">📋 Informations de l'association</h2>
      <table style="width:100%;font-size:13.5px;">
        <?php foreach ([
          'name' => 'Nom commercial',
          'legal_name' => 'Nom légal',
          'legal_form' => 'Forme juridique',
          'siret' => 'SIRET',
          'rna_number' => 'N° RNA',
          'billing_email' => 'Email',
          'billing_phone' => 'Téléphone',
          'billing_address_street' => 'Adresse',
          'billing_address_zip' => 'Code postal',
          'billing_address_city' => 'Ville',
          'website' => 'Site web',
        ] as $k => $label):
          $v = $org[$k] ?? '';
          if (!$v) continue;
        ?>
        <tr style="border-bottom:1px solid #F4F4F5;">
          <td style="padding:10px 0;color:#71717A;font-weight:500;width:170px;"><?= $label ?></td>
          <td style="padding:10px 0;color:#0A0A0B;"><?= h($v) ?></td>
        </tr>
        <?php endforeach; ?>
      </table>
    </div>

  <!-- ============================================================ -->
  <!-- ONGLET PROJETS (le plus important pour la mairie)              -->
  <!-- ============================================================ -->
  <?php elseif ($tab === 'projets'): ?>

    <!-- Stats projets -->
    <div class="mairie-grants-stats" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(135px,1fr));gap:12px;margin-bottom:18px;">
      <div style="background:#fff;border:1px solid #E5E7EB;border-radius:10px;padding:14px;text-align:center;">
        <div style="font-size:24px;font-weight:700;color:#059669;"><?= $stats_p['active'] ?></div>
        <div style="font-size:10.5px;color:#71717A;text-transform:uppercase;letter-spacing:0.05em;font-weight:600;margin-top:2px;">🟢 Actifs</div>
      </div>
      <div style="background:#fff;border:1px solid #E5E7EB;border-radius:10px;padding:14px;text-align:center;">
        <div style="font-size:24px;font-weight:700;color:#F59E0B;"><?= $stats_p['warning'] ?></div>
        <div style="font-size:10.5px;color:#71717A;text-transform:uppercase;letter-spacing:0.05em;font-weight:600;margin-top:2px;">🟠 Alerte</div>
      </div>
      <div style="background:#fff;border:1px solid #E5E7EB;border-radius:10px;padding:14px;text-align:center;">
        <div style="font-size:24px;font-weight:700;color:#3F3F46;"><?= $stats_p['done'] ?></div>
        <div style="font-size:10.5px;color:#71717A;text-transform:uppercase;letter-spacing:0.05em;font-weight:600;margin-top:2px;">✅ Terminés</div>
      </div>
      <div style="background:#fff;border:1px solid #E5E7EB;border-radius:10px;padding:14px;text-align:center;">
        <div style="font-size:18px;font-weight:700;color:#0A0A0B;"><?= number_format($stats_p['total_budget'], 0, ',', ' ') ?> €</div>
        <div style="font-size:10.5px;color:#71717A;text-transform:uppercase;letter-spacing:0.05em;font-weight:600;margin-top:2px;">💵 Budget</div>
      </div>
      <div style="background:#fff;border:1px solid #E5E7EB;border-radius:10px;padding:14px;text-align:center;">
        <div style="font-size:18px;font-weight:700;color:#059669;"><?= number_format($stats_p['total_spent'], 0, ',', ' ') ?> €</div>
        <div style="font-size:10.5px;color:#71717A;text-transform:uppercase;letter-spacing:0.05em;font-weight:600;margin-top:2px;">💸 Dépensé</div>
      </div>
    </div>

    <div style="background:#fff;border:1px solid #E5E7EB;border-radius:12px;padding:22px;">
      <h2 style="margin:0 0 14px;font-size:17px;color:#0A0A0B;font-weight:700;">📁 Tous les projets (<?= count($projects) ?>)</h2>

      <?php if (empty($projects)): ?>
        <div style="text-align:center;padding:36px;color:#71717A;background:#FAFAF9;border-radius:10px;">
          Aucun projet. L'association n'a pas encore créé de projets.
        </div>
      <?php else: ?>
        <div style="display:grid;gap:10px;">
          <?php foreach ($projects as $p):
            $status_meta = match($p['status']) {
                'active' => ['icon'=>'🟢', 'bg'=>'#F0FDF4', 'border'=>'#10B981'],
                'warning' => ['icon'=>'🟠', 'bg'=>'#FFFBEB', 'border'=>'#F59E0B'],
                'draft' => ['icon'=>'⚪', 'bg'=>'#F9FAFB', 'border'=>'#9CA3AF'],
                'done' => ['icon'=>'✅', 'bg'=>'#EEF2FF', 'border'=>'#6366F1'],
                'archived' => ['icon'=>'📦', 'bg'=>'#F4F4F5', 'border'=>'#A1A1AA'],
                default => ['icon'=>'•', 'bg'=>'#FAFAF9', 'border'=>'#E5E7EB']
            };
          ?>
          <div style="background:<?= $status_meta['bg'] ?>;border:1px solid <?= $status_meta['border'] ?>22;border-left:3px solid <?= $status_meta['border'] ?>;border-radius:8px;padding:14px 18px;">
            <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:16px;flex-wrap:wrap;">
              <div style="flex:1 1 220px;min-width:0;">
                <div style="font-size:15px;font-weight:700;color:#0A0A0B;margin-bottom:4px;">
                  <?= $status_meta['icon'] ?> <?= h($p['name']) ?>
                </div>
                <div style="font-size:12px;color:#71717A;margin-bottom:6px;">
                  <strong style="color:#3F3F46;">📂 <?= h($p['folder_name']) ?></strong>
                  <?php if ($p['ref_first']): ?> · 👤 <?= h(trim($p['ref_first'] . ' ' . $p['ref_last'])) ?><?php endif; ?>
                  <?php if ($p['location']): ?> · 📍 <?= h($p['location']) ?><?php endif; ?>
                </div>
                <?php if ($p['description']): ?>
                  <div style="font-size:12px;color:#52525B;margin-bottom:8px;"><?= h(mb_substr($p['description'], 0, 140)) ?><?= mb_strlen($p['description']) > 140 ? '...' : '' ?></div>
                <?php endif; ?>
                <div style="display:flex;gap:14px;font-size:11.5px;color:#3F3F46;flex-wrap:wrap;">
                  <?php if ($p['start_date'] && $p['start_date'] !== '0000-00-00'): ?>
                    <span>📅 <?= date('d/m/Y', strtotime($p['start_date'])) ?><?php if ($p['end_date'] && $p['end_date'] !== '0000-00-00'): ?> → <?= date('d/m/Y', strtotime($p['end_date'])) ?><?php endif; ?></span>
                  <?php endif; ?>
                  <?php if ((float)$p['budget_planned'] > 0): ?>
                    <span>💵 <strong style="color:#0A0A0B;"><?= number_format((float)$p['budget_used'], 0, ',', ' ') ?> €</strong> / <?= number_format((float)$p['budget_planned'], 0, ',', ' ') ?> €</span>
                  <?php endif; ?>
                  <?php if ((int)$p['participants_count'] > 0): ?>
                    <span>👥 <?= (int)$p['participants_count'] ?> bénéficiaires</span>
                  <?php endif; ?>
                </div>
                <?php if ((int)$p['progress_percent'] > 0): ?>
                  <div style="margin-top:8px;height:4px;background:#FFF;border-radius:2px;overflow:hidden;border:1px solid #E5E7EB;">
                    <div style="width:<?= (int)$p['progress_percent'] ?>%;height:100%;background:<?= $status_meta['border'] ?>;"></div>
                  </div>
                <?php endif; ?>
              </div>
              <div style="display:flex;flex-direction:column;gap:6px;min-width:152px;">
              <a href="/projet/<?= (int)$p['id'] ?>" style="background:#0A0A0B;color:#fff;padding:7px 14px;border-radius:6px;text-decoration:none;font-size:12.5px;font-weight:600;white-space:nowrap;text-align:center;">Détails</a>
              <a href="/download-bilan-analytique?project=<?= (int)$p['id'] ?>" target="_blank" rel="noopener" style="border:1px solid #1D4ED8;color:#1D4ED8;background:#fff;padding:5px 12px;border-radius:6px;text-decoration:none;font-size:11.5px;font-weight:600;white-space:nowrap;text-align:center;">📊 Bilan</a>
              <a href="/download-justificatifs?project=<?= (int)$p['id'] ?>" target="_blank" rel="noopener" style="border:1px solid #1D4ED8;color:#1D4ED8;background:#fff;padding:5px 12px;border-radius:6px;text-decoration:none;font-size:11.5px;font-weight:600;white-space:nowrap;text-align:center;">📎 Justificatifs</a>
              <a href="/download-bilan-analytique-xlsx?project=<?= (int)$p['id'] ?>" target="_blank" rel="noopener" style="border:1px solid #1D4ED8;color:#1D4ED8;background:#fff;padding:5px 12px;border-radius:6px;text-decoration:none;font-size:11.5px;font-weight:600;white-space:nowrap;text-align:center;">📗 Excel</a>
            </div>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

  <!-- ============================================================ -->
  <!-- ONGLET SUBVENTIONS                                              -->
  <!-- ============================================================ -->
  <?php elseif ($tab === 'subventions'): ?>

    <!-- Stats agrégées subventions -->
    <div class="mairie-grants-stats" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(120px,1fr));gap:10px;margin-bottom:16px;">
      <div style="background:#fff;border:1px solid #E5E7EB;border-radius:10px;padding:13px;text-align:center;">
        <div style="font-size:22px;font-weight:700;color:#0A0A0B;line-height:1;"><?= $grants_stats['total'] ?></div>
        <div style="font-size:10px;color:#71717A;text-transform:uppercase;letter-spacing:0.05em;font-weight:600;margin-top:3px;">📊 Demandes</div>
      </div>
      <div style="background:#F0FDF4;border:1px solid #D1FAE5;border-radius:10px;padding:13px;text-align:center;">
        <div style="font-size:22px;font-weight:700;color:#047857;line-height:1;"><?= $grants_stats['accepted'] ?></div>
        <div style="font-size:10px;color:#065F46;text-transform:uppercase;letter-spacing:0.05em;font-weight:600;margin-top:3px;">✅ Accordées</div>
      </div>
      <div style="background:#FEE2E2;border:1px solid #FECACA;border-radius:10px;padding:13px;text-align:center;">
        <div style="font-size:22px;font-weight:700;color:#B91C1C;line-height:1;"><?= $grants_stats['rejected'] ?></div>
        <div style="font-size:10px;color:#7F1D1D;text-transform:uppercase;letter-spacing:0.05em;font-weight:600;margin-top:3px;">❌ Refusées</div>
      </div>
      <div style="background:#FEF3C7;border:1px solid #FDE68A;border-radius:10px;padding:13px;text-align:center;">
        <div style="font-size:22px;font-weight:700;color:#B45309;line-height:1;"><?= $grants_stats['in_review'] ?></div>
        <div style="font-size:10px;color:#78350F;text-transform:uppercase;letter-spacing:0.05em;font-weight:600;margin-top:3px;">👀 En examen</div>
      </div>
      <div style="background:#E0E7FF;border:1px solid #C7D2FE;border-radius:10px;padding:13px;text-align:center;">
        <div style="font-size:22px;font-weight:700;color:#4338CA;line-height:1;"><?= $grants_stats['submitted'] ?></div>
        <div style="font-size:10px;color:#3730A3;text-transform:uppercase;letter-spacing:0.05em;font-weight:600;margin-top:3px;">📤 Soumises</div>
      </div>
      <div style="background:#fff;border:1px solid #E5E7EB;border-radius:10px;padding:13px;text-align:center;">
        <div style="font-size:22px;font-weight:700;color:#71717A;line-height:1;"><?= $grants_stats['pending'] ?></div>
        <div style="font-size:10px;color:#71717A;text-transform:uppercase;letter-spacing:0.05em;font-weight:600;margin-top:3px;">📝 En cours</div>
      </div>
    </div>

    <div class="mairie-grants-totals" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:12px;margin-bottom:18px;">
      <div style="background:linear-gradient(135deg,#0A0A0B,#1F2937);color:#fff;border-radius:10px;padding:16px 20px;">
        <div style="font-size:10.5px;text-transform:uppercase;letter-spacing:0.07em;opacity:0.7;font-weight:600;">💰 Total demandé</div>
        <div style="font-size:24px;font-weight:700;margin-top:4px;line-height:1;"><?= number_format($grants_stats['sum_requested'], 0, ',', ' ') ?> €</div>
        <div style="font-size:11px;opacity:0.6;margin-top:3px;">sur <?= $grants_stats['total'] ?> demandes</div>
      </div>
      <div style="background:linear-gradient(135deg,#059669,#047857);color:#fff;border-radius:10px;padding:16px 20px;">
        <div style="font-size:10.5px;text-transform:uppercase;letter-spacing:0.07em;opacity:0.8;font-weight:600;">✅ Total accordé</div>
        <div style="font-size:24px;font-weight:700;margin-top:4px;line-height:1;"><?= number_format($grants_stats['sum_granted'], 0, ',', ' ') ?> €</div>
        <div style="font-size:11px;opacity:0.8;margin-top:3px;">
          <?php
            $rate = $grants_stats['sum_requested'] > 0 ? round($grants_stats['sum_granted'] / $grants_stats['sum_requested'] * 100) : 0;
            echo $rate . '% de taux d\'obtention';
          ?>
        </div>
      </div>
    </div>

    <div style="background:#fff;border:1px solid #E5E7EB;border-radius:12px;padding:22px;">
      <h2 style="margin:0 0 14px;font-size:17px;color:#0A0A0B;font-weight:700;">📋 Détail des subventions (<?= count($grants) ?>)</h2>
      <?php if (empty($grants)): ?>
        <div style="text-align:center;padding:36px;color:#71717A;background:#FAFAF9;border-radius:10px;">Aucune subvention enregistrée.</div>
      <?php else: ?>
        <div style="overflow-x:auto;-webkit-overflow-scrolling:touch;"><table style="width:100%;min-width:560px;border-collapse:collapse;font-size:13px;">
          <thead><tr style="border-bottom:1.5pt solid #E5E7EB;font-size:11px;text-transform:uppercase;letter-spacing:0.05em;color:#71717A;">
            <th style="text-align:left;padding:10px 6px;">Subvention</th>
            <th style="text-align:left;padding:10px 6px;">Financeur</th>
            <th style="text-align:right;padding:10px 6px;">Montant</th>
            <th style="text-align:left;padding:10px 6px;">Statut</th>
            <th style="text-align:left;padding:10px 6px;">Échéance</th>
            <th style="text-align:left;padding:10px 6px;">Créée le</th>
          </tr></thead>
          <tbody>
          <?php
          $statuses = [
              'draft' => ['📝 Brouillon', '#71717A', '#F4F4F5'],
              'to_prepare' => ['📋 À préparer', '#3F3F46', '#E5E7EB'],
              'in_progress' => ['🔄 En cours', '#0369A1', '#DBEAFE'],
              'submitted' => ['📤 Soumise', '#4F46E5', '#E0E7FF'],
              'in_review' => ['👀 En examen', '#B45309', '#FEF3C7'],
              'accepted' => ['✅ Acceptée', '#047857', '#D1FAE5'],
              'granted' => ['✅ Accordée', '#047857', '#D1FAE5'],
              'rejected' => ['❌ Refusée', '#B91C1C', '#FEE2E2'],
              'paid' => ['💰 Versée', '#047857', '#D1FAE5'],
              'archived' => ['📦 Archivée', '#A1A1AA', '#F4F4F5'],
          ];
          foreach ($grants as $g):
              $st = $g['status'] ?? '';
              $meta = $statuses[$st] ?? [ucfirst($st ?: '—'), '#71717A', '#F4F4F5'];
          ?>
            <tr style="border-bottom:1px solid #F4F4F5;">
              <td style="padding:11px 6px;font-weight:600;color:#0A0A0B;"><?= h($g['name'] ?? '—') ?></td>
              <td style="padding:11px 6px;color:#3F3F46;"><?= h($g['funder'] ?? '—') ?><?php if (!empty($g['funder_type'])): ?><br><span style="font-size:10.5px;color:#A1A1AA;"><?= h($g['funder_type']) ?></span><?php endif; ?></td>
              <td style="padding:11px 6px;text-align:right;font-weight:700;white-space:nowrap;">
                <?php if ((float)$g['amount_requested'] > 0): ?>
                  <span style="color:#0A0A0B;"><?= number_format((float)$g['amount_requested'], 0, ',', ' ') ?> €</span>
                  <?php if ((float)$g['amount_granted'] > 0): ?><br><span style="font-size:10.5px;color:#059669;">✅ <?= number_format((float)$g['amount_granted'], 0, ',', ' ') ?> €</span><?php endif; ?>
                <?php else: ?><span style="color:#A1A1AA;">—</span><?php endif; ?>
              </td>
              <td style="padding:11px 6px;"><span style="background:<?= $meta[2] ?>;color:<?= $meta[1] ?>;padding:4px 10px;border-radius:12px;font-size:11.5px;font-weight:600;display:inline-block;white-space:nowrap;"><?= h($meta[0]) ?></span></td>
              <td style="padding:11px 6px;color:#3F3F46;font-size:12.5px;white-space:nowrap;">
                <?php if (!empty($g['deadline_apply']) && $g['deadline_apply'] !== '0000-00-00'): ?>📅 <?= date('d/m/Y', strtotime($g['deadline_apply'])) ?><?php else: ?><span style="color:#A1A1AA;">—</span><?php endif; ?>
              </td>
              <td style="padding:11px 6px;color:#71717A;font-size:11.5px;white-space:nowrap;">
                <?php if (!empty($g['created_at'])): ?><?= date('d/m/Y', strtotime($g['created_at'])) ?><?php else: ?><span style="color:#A1A1AA;">—</span><?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table></div>
      <?php endif; ?>
    </div>

  <!-- ============================================================ -->
  <!-- ONGLET ADHÉRENTS                                                -->
  <!-- ============================================================ -->
  <?php elseif ($tab === 'adherents'): ?>
    <div style="background:#fff;border:1px solid #E5E7EB;border-radius:12px;padding:22px;">
      <h2 style="margin:0 0 14px;font-size:17px;color:#0A0A0B;font-weight:700;">👥 Adhérents (<?= $members_count ?>)</h2>
      <p style="color:#71717A;font-size:13px;margin:0 0 14px;">Aperçu des 5 derniers adhérents. Liste complète à venir.</p>
      <?php if (empty($members_recent)): ?>
        <div style="text-align:center;padding:36px;color:#71717A;background:#FAFAF9;border-radius:10px;">Aucun adhérent.</div>
      <?php else: ?>
        <div style="overflow-x:auto;-webkit-overflow-scrolling:touch;"><table style="width:100%;min-width:560px;border-collapse:collapse;font-size:13px;">
          <thead><tr style="border-bottom:1.5pt solid #E5E7EB;font-size:11px;text-transform:uppercase;letter-spacing:0.05em;color:#71717A;">
            <th style="text-align:left;padding:10px 6px;">Nom</th>
            <th style="text-align:left;padding:10px 6px;">Email</th>
            <th style="text-align:left;padding:10px 6px;">Inscrit le</th>
          </tr></thead>
          <tbody>
          <?php foreach ($members_recent as $m): ?>
            <tr style="border-bottom:1px solid #F4F4F5;">
              <td style="padding:10px 6px;color:#0A0A0B;font-weight:600;"><?= h(trim(($m['first_name'] ?? '') . ' ' . ($m['last_name'] ?? '')) ?: '—') ?></td>
              <td style="padding:10px 6px;color:#3F3F46;"><?= h($m['email'] ?? '—') ?></td>
              <td style="padding:10px 6px;color:#71717A;"><?= !empty($m['created_at']) ? date('d/m/Y', strtotime($m['created_at'])) : '—' ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table></div>
      <?php endif; ?>
    </div>

  <?php endif; ?>

</div>

<?php render_foot(); ?>
