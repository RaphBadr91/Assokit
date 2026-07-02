<?php
/**
 * ============================================================
 * ASSOKIT — Page Factures
 * ============================================================
 * Liste des factures liées aux projets de l'organisation avec :
 *   - drop zone d'upload (prête pour l'IA d'extraction plus tard)
 *   - filtres par statut (toutes / en attente / validées / rejetées)
 *   - recherche par fournisseur
 *   - stats en haut (total, en attente, validé, ce mois)
 * ============================================================
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes-layout.php';
require_once __DIR__ . '/pca-mapping.php';

require_login();

$user = current_user();
$org_id = (int)$user['org_id'];
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$can_reclass = in_array($user['role'] ?? '', ['admin','coordinator'], true);
$pca_comptes = ak_pca_comptes();

// ====== Filtres ======
$search = trim($_GET['q'] ?? '');
$filter_status = $_GET['status'] ?? 'all';
$valid_statuses = ['all', 'pending', 'validated', 'rejected'];
if (!in_array($filter_status, $valid_statuses, true)) {
    $filter_status = 'all';
}

// ====== Chargement des factures ======
$sql = "
    SELECT
        i.id, i.supplier_name, i.category, i.account_override, i.amount_ttc, i.invoice_date, i.status,
        p.name AS project_name, p.id AS project_id,
        f.name AS folder_name
    FROM project_invoices i
    JOIN projects p ON i.project_id = p.id
    JOIN folders f ON p.folder_id = f.id
    WHERE f.org_id = :org_id
";
$params = [':org_id' => $org_id];

if ($search !== '') {
    $sql .= " AND (i.supplier_name LIKE :q OR i.category LIKE :q OR p.name LIKE :q)";
    $params[':q'] = '%' . $search . '%';
}
if ($filter_status !== 'all') {
    $sql .= " AND i.status = :status";
    $params[':status'] = $filter_status;
}
$sql .= " ORDER BY i.invoice_date DESC, i.id DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$invoices = $stmt->fetchAll();

// ====== Statistiques globales ======
$stats = $pdo->prepare("
    SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN i.status = 'pending' THEN 1 ELSE 0 END) AS pending_count,
        SUM(CASE WHEN i.status = 'validated' THEN i.amount_ttc ELSE 0 END) AS validated_amount,
        SUM(CASE WHEN i.status = 'pending' THEN i.amount_ttc ELSE 0 END) AS pending_amount,
        SUM(CASE WHEN DATE_FORMAT(i.invoice_date, '%Y-%m') = DATE_FORMAT(NOW(), '%Y-%m') THEN i.amount_ttc ELSE 0 END) AS month_amount
    FROM project_invoices i
    JOIN projects p ON i.project_id = p.id
    JOIN folders f ON p.folder_id = f.id
    WHERE f.org_id = ?
");
$stats->execute([$org_id]);
$s = $stats->fetch();

function format_date_short($date_str) {
    if (!$date_str) return '—';
    $months = ['janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'];
    $t = strtotime($date_str);
    return (int)date('j', $t) . ' ' . $months[(int)date('n', $t) - 1] . ' ' . date('Y', $t);
}

function format_euros($amount) {
    return number_format((float)$amount, 2, ',', ' ') . ' €';
}

function status_label_inv($status) {
    return [
        'validated' => ['label' => 'Validée', 'class' => 'status-validated'],
        'pending' => ['label' => 'En attente', 'class' => 'status-pending'],
        'rejected' => ['label' => 'Rejetée', 'class' => 'status-rejected'],
    ][$status] ?? ['label' => 'En attente', 'class' => 'status-pending'];
}

render_head('Factures');
render_sidebar('factures');
?>

<style>
.inv-item{margin-bottom:2px;}
.inv-reclass{display:flex;align-items:center;gap:8px;flex-wrap:wrap;padding:4px 14px 10px;font-size:12px;color:var(--ink-4,#94a3b8);}
.inv-reclass-lbl{white-space:nowrap;}
.inv-reclass-sel{font-size:12px;padding:4px 8px;border:1px solid var(--line,#e2e8f0);border-radius:7px;background:#fff;color:var(--ink-2,#334155);max-width:360px;cursor:pointer;}
.inv-reclass-sel:focus{outline:2px solid #1D4ED8;outline-offset:1px;}
</style>

<main class="main">

  <nav class="crumbs">
    <a href="/dashboard">Dashboard</a>
    <span class="sep">›</span>
    <span class="current">Factures</span>
  </nav>

  <div class="main-head">
    <div>
      <h1 class="page-title">Vos factures</h1>
      <div class="page-sub"><?= (int)$s['total'] ?> facture<?= $s['total'] > 1 ? 's' : '' ?> · <?= (int)$s['pending_count'] ?> en attente</div>
    </div>
    <div class="head-actions">
      <button class="btn btn-ghost">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
        Exporter
      </button>
      <button class="btn btn-primary">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
        Nouvelle facture
      </button>
    </div>
  </div>

  <!-- Stats -->
  <section class="stats-bar">
    <div class="metric">
      <div class="metric-lbl">Validées</div>
      <div class="metric-val"><?= h(format_euros($s['validated_amount'])) ?></div>
      <div class="metric-sub up">comptabilisé</div>
    </div>
    <div class="metric">
      <div class="metric-lbl">En attente</div>
      <div class="metric-val"><?= h(format_euros($s['pending_amount'])) ?></div>
      <div class="metric-sub"><?= (int)$s['pending_count'] ?> à valider</div>
    </div>
    <div class="metric">
      <div class="metric-lbl">Ce mois-ci</div>
      <div class="metric-val"><?= h(format_euros($s['month_amount'])) ?></div>
      <div class="metric-sub">engagé</div>
    </div>
    <div class="metric">
      <div class="metric-lbl">Total factures</div>
      <div class="metric-val"><?= (int)$s['total'] ?></div>
      <div class="metric-sub">documents</div>
    </div>
  </section>

  <!-- Drop zone pour upload avec promesse IA -->
  <div class="drop-zone" onclick="document.getElementById('file-input').click();">
    <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
      <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
      <polyline points="17 8 12 3 7 8"/>
      <line x1="12" y1="3" x2="12" y2="15"/>
    </svg>
    <div class="drop-zone-title">Glissez vos factures ici ou cliquez pour choisir</div>
    <div class="drop-zone-sub">PDF, JPG, PNG · jusqu'à 10 Mo par fichier</div>
    <div class="drop-zone-ai">
      <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
      <span>Le copilote IA lira le fournisseur, le montant et la date pour vous</span>
    </div>
    <input type="file" id="file-input" accept=".pdf,image/*" multiple style="display:none;">
  </div>

  <!-- Recherche + filtres -->
  <form method="GET" action="/factures" class="toolbar">
    <div class="search-wrap">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
      <input type="search" name="q" class="search-input" placeholder="Rechercher par fournisseur, catégorie, projet…" value="<?= h($search) ?>">
    </div>
    <div class="filter-chips">
      <a href="/factures<?= $search ? '?q=' . urlencode($search) : '' ?>" class="chip <?= $filter_status === 'all' ? 'active' : '' ?>">Toutes</a>
      <a href="/factures?status=pending<?= $search ? '&q=' . urlencode($search) : '' ?>" class="chip <?= $filter_status === 'pending' ? 'active' : '' ?>">En attente</a>
      <a href="/factures?status=validated<?= $search ? '&q=' . urlencode($search) : '' ?>" class="chip <?= $filter_status === 'validated' ? 'active' : '' ?>">Validées</a>
      <a href="/factures?status=rejected<?= $search ? '&q=' . urlencode($search) : '' ?>" class="chip <?= $filter_status === 'rejected' ? 'active' : '' ?>">Rejetées</a>
    </div>
  </form>

  <!-- Liste des factures -->
  <?php if (empty($invoices)): ?>
    <div class="list">
      <div class="empty-state">
        <?php if ($search || $filter_status !== 'all'): ?>
          Aucune facture ne correspond à votre recherche.
        <?php else: ?>
          Aucune facture pour l'instant. Glissez votre première facture ci-dessus.
        <?php endif; ?>
      </div>
    </div>
  <?php else: ?>
    <div class="list">
      <div class="list-row list-row-header inv-row-header">
        <span>Fournisseur / projet</span>
        <span class="inv-cat">Catégorie</span>
        <span class="inv-date">Date</span>
        <span style="text-align: right;">Montant</span>
        <span>Statut</span>
      </div>
      <?php foreach ($invoices as $inv):
        $st = status_label_inv($inv['status']);
          $map = ak_pca_map($inv['category'], $inv['account_override'] ?? null);
          $cur = trim((string)($inv['account_override'] ?? ''));
      ?>
      <div class="inv-item">
        <a href="#" class="list-row inv-row">
        <div class="inv-main">
          <div class="inv-supplier"><?= h($inv['supplier_name'] ?: 'Fournisseur inconnu') ?></div>
          <div class="inv-project"><?= h($inv['project_name']) ?> · <?= h($inv['folder_name']) ?></div>
        </div>
        <span class="inv-cat"><?= h($inv['category'] ?: '—') ?></span>
        <span class="inv-date"><?= h(format_date_short($inv['invoice_date'])) ?></span>
        <span class="inv-amount"><?= h(format_euros($inv['amount_ttc'])) ?></span>
        <span class="inv-status <?= $st['class'] ?>"><?= h($st['label']) ?></span>
      </a>
      <?php if ($can_reclass): ?>
          <form method="post" action="/action-facture" class="inv-reclass">
            <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="action" value="set_account">
            <input type="hidden" name="project_id" value="<?= (int)$inv['project_id'] ?>">
            <input type="hidden" name="invoice_id" value="<?= (int)$inv['id'] ?>">
            <span class="inv-reclass-lbl">Poste<?= $map['unmapped'] ? ' (auto-classé à vérifier)' : '' ?> :</span>
            <select name="account_override" class="inv-reclass-sel" onchange="this.form.submit()">
              <option value=""<?= $cur === '' ? ' selected' : '' ?>>Auto (catégorie) &mdash; <?= h($map['compte'].' '.$map['libelle']) ?></option>
              <?php $grp=null; foreach ($pca_comptes as $code=>$d): if ($d['poste_code']!==$grp): if($grp!==null) echo '</optgroup>'; $grp=$d['poste_code']; echo '<optgroup label="'.h(ak_pca_poste_label($grp)).'">'; endif; ?>
                <option value="<?= h($code) ?>"<?= $cur===$code ? ' selected' : '' ?>><?= h($code.' '.$d['label']) ?></option>
              <?php endforeach; if($grp!==null) echo '</optgroup>'; ?>
            </select>
          </form>
        <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>

    <?php if (count($invoices) > 5): ?>
    <div style="margin-top: 14px; font-size: 12px; color: var(--ink-4); text-align: center;">
      <?= count($invoices) ?> facture<?= count($invoices) > 1 ? 's' : '' ?> affichée<?= count($invoices) > 1 ? 's' : '' ?>
    </div>
    <?php endif; ?>
  <?php endif; ?>

</main>

<?php render_foot(); ?>
