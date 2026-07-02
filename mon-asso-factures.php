<?php
/**
 * mon-asso-factures.php
 * --------------------------------------------------------------
 * Liste complète des factures Stripe d'une organisation.
 * Affiche : numéro, date, montant, statut, lien PDF + Stripe.
 * --------------------------------------------------------------
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes-layout.php';
@require_once __DIR__ . '/stripe-helpers.php';

require_login();
$user = current_user();
$org_id = (int)($user['org_id'] ?? 0);
if ($org_id <= 0) { header('Location: /'); exit; }

// [PACK 6.5 - SECURITY STRICT] Accès finances : SEULS Admin / Founder / Super Admin
$can_view_finances = (
    in_array($user['role'] ?? '', ['admin', 'founder', 'super_admin'], true)
    || !empty($user['is_founder'])
    || !empty($user['is_super_admin'])
);
if (!$can_view_finances) {
    http_response_code(403);
    render_head('Accès refusé');
    render_sidebar('mon-asso-factures');
    echo '<main class="main"><div style="max-width:600px;margin:60px auto;padding:32px;background:white;border:1px solid #FECACA;border-radius:14px;text-align:center;">';
    echo '<div style="font-size:54px;margin-bottom:14px;">🔒</div>';
    echo '<h1 style="font-size:22px;color:#0F172A;margin:0 0 12px;">Accès réservé</h1>';
    echo '<p style="color:#64748B;font-size:14px;line-height:1.6;margin:0 0 22px;">La liste des factures Stripe (montants, statuts, PDF) est strictement réservée aux <strong>Administrateurs</strong> de l\'association.</p>';
    echo '<a href="/dashboard" style="display:inline-block;background:#0F172A;color:white;padding:11px 22px;border-radius:10px;text-decoration:none;font-weight:600;font-size:14px;">← Retour au dashboard</a>';
    echo '</div></main>';
    render_foot();
    exit;
}

// Filtres
$f_status = (string)($_GET['status'] ?? '');
$f_year = (int)($_GET['year'] ?? 0);

// Récupération factures
$invoices = function_exists('ak_get_org_invoices_all') ? ak_get_org_invoices_all($pdo, $org_id, 200) : (function_exists('ak_get_org_invoices_stripe') ? ak_get_org_invoices_stripe($pdo, $org_id, 200) : []);

// Application filtres
if ($f_status) {
    $invoices = array_filter($invoices, fn($i) => $i['status'] === $f_status);
}
if ($f_year > 0) {
    $invoices = array_filter($invoices, fn($i) => (int)date('Y', strtotime($i['created_at'])) === $f_year);
}

// Stats
$stats = [
    'total_count' => 0,
    'paid_count' => 0,
    'open_count' => 0,
    'total_paid_cents' => 0,
];
foreach ($invoices as $inv) {
    $stats['total_count']++;
    if ($inv['status'] === 'paid') {
        $stats['paid_count']++;
        $stats['total_paid_cents'] += (int)$inv['amount_cents'];
    } elseif ($inv['status'] === 'open') {
        $stats['open_count']++;
    }
}

// Années disponibles pour filtre
$years = [];
try {
    $rows = $pdo->prepare("
        SELECT DISTINCT YEAR(created_at) AS y FROM asso_invoices_stripe WHERE org_id = :id
        UNION
        SELECT DISTINCT YEAR(created_at) AS y FROM subscription_invoices WHERE org_id = :id
        ORDER BY y DESC
    ");
    $rows->execute([':id' => $org_id]);
    $years = array_column($rows->fetchAll(PDO::FETCH_ASSOC), 'y');
} catch (Throwable $e) {}

render_head('Mes factures');
render_sidebar('mon-asso-factures');
?>

<main class="main">

  <nav class="crumbs">
    <a href="/dashboard">Dashboard</a>
    <span class="sep">›</span>
    <a href="/mon-asso-plan">Mon abonnement</a>
    <span class="sep">›</span>
    <span class="current">📄 Mes factures</span>
  </nav>

  <div class="main-head" style="margin-bottom:24px;">
    <div>
      <h1 style="margin:0 0 4px;">📄 Mes factures</h1>
      <p style="color:#64748B;margin:0;">Toutes vos factures Stripe avec téléchargement PDF</p>
    </div>
  </div>

  <!-- Stats -->
  <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(180px, 1fr));gap:14px;margin-bottom:22px;">
    <div style="background:white;border:1px solid #E2E8F0;border-radius:12px;padding:16px 18px;">
      <div style="font-size:11px;color:#94A3B8;text-transform:uppercase;letter-spacing:0.05em;font-weight:700;">Total factures</div>
      <div style="font-size:24px;font-weight:700;margin-top:4px;"><?= (int)$stats['total_count'] ?></div>
    </div>
    <div style="background:white;border:1px solid #E2E8F0;border-radius:12px;padding:16px 18px;">
      <div style="font-size:11px;color:#94A3B8;text-transform:uppercase;letter-spacing:0.05em;font-weight:700;">Payées</div>
      <div style="font-size:24px;font-weight:700;color:#059669;margin-top:4px;"><?= (int)$stats['paid_count'] ?></div>
    </div>
    <div style="background:white;border:1px solid #E2E8F0;border-radius:12px;padding:16px 18px;">
      <div style="font-size:11px;color:#94A3B8;text-transform:uppercase;letter-spacing:0.05em;font-weight:700;">En attente</div>
      <div style="font-size:24px;font-weight:700;color:#EA580C;margin-top:4px;"><?= (int)$stats['open_count'] ?></div>
    </div>
    <div style="background:white;border:1px solid #E2E8F0;border-radius:12px;padding:16px 18px;">
      <div style="font-size:11px;color:#94A3B8;text-transform:uppercase;letter-spacing:0.05em;font-weight:700;">Total payé</div>
      <div style="font-size:24px;font-weight:700;color:#0F172A;margin-top:4px;"><?= ak_format_price_cents((int)$stats['total_paid_cents']) ?></div>
    </div>
  </div>

  <!-- Filtres -->
  <form method="get" action="/mon-asso-factures" style="background:white;border:1px solid #E2E8F0;border-radius:12px;padding:14px 18px;margin-bottom:18px;display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
    <select name="status" style="padding:9px 12px;border:1px solid #E2E8F0;border-radius:8px;font-size:13.5px;">
      <option value="">Tous les statuts</option>
      <option value="paid" <?= $f_status === 'paid' ? 'selected' : '' ?>>Payées</option>
      <option value="open" <?= $f_status === 'open' ? 'selected' : '' ?>>En attente</option>
      <option value="void" <?= $f_status === 'void' ? 'selected' : '' ?>>Annulées</option>
      <option value="uncollectible" <?= $f_status === 'uncollectible' ? 'selected' : '' ?>>Irrécouvrables</option>
    </select>
    <?php if (!empty($years)): ?>
    <select name="year" style="padding:9px 12px;border:1px solid #E2E8F0;border-radius:8px;font-size:13.5px;">
      <option value="">Toutes les années</option>
      <?php foreach ($years as $y): ?>
      <option value="<?= (int)$y ?>" <?= $f_year === (int)$y ? 'selected' : '' ?>><?= (int)$y ?></option>
      <?php endforeach; ?>
    </select>
    <?php endif; ?>
    <button type="submit" style="background:#0F172A;color:white;padding:9px 18px;border:none;border-radius:8px;font-size:13.5px;cursor:pointer;font-weight:600;">Filtrer</button>
    <?php if ($f_status || $f_year): ?>
      <a href="/mon-asso-factures" style="color:#94A3B8;font-size:13px;text-decoration:none;">Réinitialiser</a>
    <?php endif; ?>
  </form>

  <!-- Tableau factures -->
  <div style="background:white;border:1px solid #E2E8F0;border-radius:14px;overflow:hidden;">
    <?php if (empty($invoices)): ?>
      <div style="padding:60px 20px;text-align:center;color:#64748B;">
        <div style="font-size:48px;margin-bottom:12px;">📄</div>
        <div style="font-weight:600;font-size:16px;color:#0F172A;margin-bottom:6px;">Aucune facture pour le moment</div>
        <div style="font-size:13.5px;">Vos factures apparaîtront ici dès votre premier paiement.</div>
      </div>
    <?php else: ?>
      <table style="width:100%;border-collapse:collapse;font-size:14px;">
        <thead>
          <tr style="background:#F8FAFC;">
            <th style="text-align:left;padding:12px 16px;font-size:11px;text-transform:uppercase;color:#64748B;font-weight:700;letter-spacing:0.04em;">Numéro</th>
            <th style="text-align:left;padding:12px 16px;font-size:11px;text-transform:uppercase;color:#64748B;font-weight:700;letter-spacing:0.04em;">Date</th>
            <th style="text-align:left;padding:12px 16px;font-size:11px;text-transform:uppercase;color:#64748B;font-weight:700;letter-spacing:0.04em;">Période</th>
            <th style="text-align:right;padding:12px 16px;font-size:11px;text-transform:uppercase;color:#64748B;font-weight:700;letter-spacing:0.04em;">Montant</th>
            <th style="text-align:center;padding:12px 16px;font-size:11px;text-transform:uppercase;color:#64748B;font-weight:700;letter-spacing:0.04em;">Statut</th>
            <th style="text-align:right;padding:12px 16px;font-size:11px;text-transform:uppercase;color:#64748B;font-weight:700;letter-spacing:0.04em;">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($invoices as $inv):
            $st_color = '#94A3B8'; $st_bg = '#F1F5F9'; $st_label = ucfirst($inv['status']);
            if ($inv['status'] === 'paid') { $st_color = '#065F46'; $st_bg = '#D1FAE5'; $st_label = 'Payée'; }
            elseif ($inv['status'] === 'open') { $st_color = '#92400E'; $st_bg = '#FEF3C7'; $st_label = 'En attente'; }
            elseif ($inv['status'] === 'void') { $st_color = '#475569'; $st_bg = '#F1F5F9'; $st_label = 'Annulée'; }
            elseif ($inv['status'] === 'uncollectible') { $st_color = '#991B1B'; $st_bg = '#FEE2E2'; $st_label = 'Irrécouvrable'; }
          ?>
          <tr style="border-top:1px solid #F1F5F9;">
            <td style="padding:14px 16px;font-family:monospace;font-size:13px;">
              <div style="font-weight:600;"><?= htmlspecialchars($inv['invoice_number'] ?? 'INV-' . $inv['id']) ?></div>
              <?php $_src = $inv['source'] ?? 'stripe'; ?>
              <div style="font-size:10px;font-weight:500;color:<?= $_src === 'stripe' ? '#1E40AF' : '#92400E' ?>;background:<?= $_src === 'stripe' ? '#DBEAFE' : '#FEF3C7' ?>;display:inline-block;padding:1px 6px;border-radius:4px;margin-top:3px;font-family:inherit;">
                <?= $_src === 'stripe' ? '💳 Stripe' : '🔧 Manuelle' ?>
              </div>
            </td>
            <td style="padding:14px 16px;color:#475569;"><?= htmlspecialchars(date('d/m/Y', strtotime($inv['created_at']))) ?></td>
            <td style="padding:14px 16px;color:#94A3B8;font-size:12.5px;">
              <?php if (!empty($inv['period_start']) && !empty($inv['period_end'])): ?>
                <?= htmlspecialchars(date('d/m', strtotime($inv['period_start']))) ?> → <?= htmlspecialchars(date('d/m/Y', strtotime($inv['period_end']))) ?>
              <?php else: ?>—<?php endif; ?>
            </td>
            <td style="padding:14px 16px;text-align:right;font-weight:700;"><?= ak_format_price_cents((int)$inv['amount_cents']) ?></td>
            <td style="padding:14px 16px;text-align:center;">
              <span style="background:<?= $st_bg ?>;color:<?= $st_color ?>;font-size:11px;padding:3px 10px;border-radius:999px;font-weight:600;"><?= htmlspecialchars($st_label) ?></span>
            </td>
            <td style="padding:14px 16px;text-align:right;">
              <div style="display:flex;gap:6px;justify-content:flex-end;">
                <?php if (!empty($inv['invoice_pdf_url'])): ?>
                  <a href="<?= htmlspecialchars($inv['invoice_pdf_url']) ?>" target="_blank" rel="noopener" style="background:#F0FDF4;color:#047857;padding:6px 12px;border-radius:6px;font-size:12px;text-decoration:none;font-weight:600;">📥 PDF</a>
                <?php endif; ?>
                <?php if (!empty($inv['hosted_invoice_url']) && $inv['status'] === 'open'): ?>
                  <a href="<?= htmlspecialchars($inv['hosted_invoice_url']) ?>" target="_blank" rel="noopener" style="background:#EA580C;color:white;padding:6px 12px;border-radius:6px;font-size:12px;text-decoration:none;font-weight:600;">💳 Régulariser</a>
                <?php endif; ?>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>

  <p style="text-align:center;margin-top:18px;color:#94A3B8;font-size:13px;">
    💡 Factures stockées et conservées 10 ans (obligation légale). Les factures 💳 Stripe sont émises automatiquement, les 🔧 Manuelles via l'admin Assokit.
  </p>

</main>

<?php render_foot(); ?>
