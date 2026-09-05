<?php
/**
 * mon-asso-factures-client.php
 * --------------------------------------------------------------
 * Liste des factures CLIENT de l'association (table asso_invoices) —
 * celles créées via « Nouvelle facture ». À ne pas confondre avec
 * mon-asso-factures.php qui liste les factures d'ABONNEMENT (Stripe,
 * Assokit → asso, section /abonnement).
 * Onglet « Factures » du hub Facturation.
 * --------------------------------------------------------------
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes-layout.php';
require_once __DIR__ . '/facturation-hub.php';
require_once __DIR__ . '/asso-invoice-helpers.php';

require_login();
$user = current_user();
$org_id = (int)($user['org_id'] ?? 0);
if ($org_id <= 0) { header('Location: /'); exit; }

// Accès finances : Admin / Founder / Super Admin uniquement (comme la page abonnement)
$can_view_finances = (
    in_array($user['role'] ?? '', ['admin', 'founder', 'super_admin'], true)
    || !empty($user['is_founder']) || !empty($user['is_super_admin'])
);
if (!$can_view_finances) {
    http_response_code(403);
    render_head('Accès refusé');
    render_sidebar('factures');
    echo '<main class="main"><div style="max-width:600px;margin:60px auto;padding:32px;background:white;border:1px solid #FECACA;border-radius:14px;text-align:center;">';
    echo '<div style="color:#64748B;margin-bottom:14px;">'.ak_icon('lock',44,'1.5').'</div>';
    echo '<h1 style="font-size:22px;color:#0F172A;margin:0 0 12px;">Accès réservé</h1>';
    echo '<p style="color:#64748B;font-size:14px;line-height:1.6;margin:0 0 22px;">La liste des factures est réservée aux <strong>Administrateurs</strong> de l\'association.</p>';
    echo '<a href="/dashboard" style="display:inline-block;background:#0F172A;color:white;padding:11px 22px;border-radius:10px;text-decoration:none;font-weight:600;font-size:14px;">← Retour au dashboard</a>';
    echo '</div></main>';
    render_foot();
    exit;
}

// Filtres
$f_status = (string)($_GET['status'] ?? '');
// Vue par défaut = année en cours (cohérent avec le badge de l'onglet).
// L'utilisateur peut sélectionner « Toutes les années » (year=«») dans le filtre.
$f_year   = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
$allowed_status = ['paid', 'pending', 'overdue', 'draft', 'cancelled'];
if ($f_status !== '' && !in_array($f_status, $allowed_status, true)) $f_status = '';

// Années disponibles
$years = [];
try {
    $ry = $pdo->prepare("SELECT DISTINCT YEAR(issued_at) AS y FROM asso_invoices WHERE org_id = :o AND issued_at IS NOT NULL ORDER BY y DESC");
    $ry->execute([':o' => $org_id]);
    $years = array_map('intval', array_column($ry->fetchAll(PDO::FETCH_ASSOC), 'y'));
} catch (Throwable $e) { $years = []; }

// Requête liste factures client
$where = 'i.org_id = :o';
$params = [':o' => $org_id];
if ($f_status !== '') { $where .= ' AND i.status = :st'; $params[':st'] = $f_status; }
if ($f_year > 0)     { $where .= ' AND YEAR(i.issued_at) = :yr'; $params[':yr'] = $f_year; }

$invoices = [];
try {
    $sql = "SELECT i.id, i.invoice_number, i.issued_at, i.due_at, i.amount_ttc_cents, i.status,
                   COALESCE(NULLIF(c.display_name, ''),
                            JSON_UNQUOTE(JSON_EXTRACT(i.client_snapshot, '$.display_name')),
                            'Client') AS client_name
            FROM asso_invoices i
            LEFT JOIN asso_clients c ON c.id = i.client_id
            WHERE $where
            ORDER BY i.issued_at DESC, i.id DESC
            LIMIT 300";
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $invoices = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('[factures-client] ' . $e->getMessage());
    $invoices = [];
}

$fmt_cents = fn($c) => function_exists('_fh_fmt_cents') ? _fh_fmt_cents((int)$c) : number_format((int)$c / 100, 2, ',', ' ') . ' €';

$status_style = function(string $s): array {
    switch ($s) {
        case 'paid':      return ['#065F46', '#D1FAE5', 'Payée'];
        case 'pending':   return ['#92400E', '#FEF3C7', 'En attente'];
        case 'overdue':   return ['#991B1B', '#FEE2E2', 'En retard'];
        case 'draft':     return ['#475569', '#F1F5F9', 'Brouillon'];
        case 'cancelled': return ['#475569', '#F1F5F9', 'Annulée'];
        default:          return ['#475569', '#F1F5F9', function_exists('ak_asso_invoice_status_label') ? ak_asso_invoice_status_label($s) : ucfirst($s)];
    }
};

render_head('Factures');
render_sidebar('factures');
?>
<main class="main">

  <?php render_facturation_hub($pdo, $org_id, 'factures', [
      'actions' =>
          '<a href="/mon-asso-devis-new" class="fh-btn fh-btn-ghost"><svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/></svg> Nouveau devis</a>'
        . '<a href="/mon-asso-facture-new" class="fh-btn fh-btn-primary"><svg viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg> Nouvelle facture</a>',
  ]); ?>

  <!-- Filtres -->
  <form method="get" action="/mon-asso-factures-client" class="card" style="padding:14px 18px;margin-bottom:18px;display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
    <select name="status" style="padding:9px 12px;border:1px solid #E2E8F0;border-radius:8px;font-size:13.5px;">
      <option value="">Tous les statuts</option>
      <option value="paid" <?= $f_status === 'paid' ? 'selected' : '' ?>>Payées</option>
      <option value="pending" <?= $f_status === 'pending' ? 'selected' : '' ?>>En attente</option>
      <option value="overdue" <?= $f_status === 'overdue' ? 'selected' : '' ?>>En retard</option>
      <option value="draft" <?= $f_status === 'draft' ? 'selected' : '' ?>>Brouillons</option>
      <option value="cancelled" <?= $f_status === 'cancelled' ? 'selected' : '' ?>>Annulées</option>
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
      <a href="/mon-asso-factures-client" style="color:#64748B;font-size:13px;text-decoration:none;">Réinitialiser</a>
    <?php endif; ?>
  </form>

  <!-- Tableau factures client -->
  <div class="card" style="overflow:hidden;">
    <?php if (empty($invoices)): ?>
      <div style="padding:60px 20px;text-align:center;color:#64748B;">
        <div style="color:#64748B;margin-bottom:12px;"><?= ak_icon('file-text',44,'1.5') ?></div>
        <div style="font-weight:600;font-size:16px;color:#0F172A;margin-bottom:6px;">Aucune facture</div>
        <div style="font-size:13.5px;">Créez votre première facture avec « Nouvelle facture ».</div>
      </div>
    <?php else: ?>
      <div style="overflow-x:auto;">
      <table style="width:100%;border-collapse:collapse;font-size:14px;min-width:640px;">
        <thead>
          <tr style="background:#F8FAFC;">
            <th style="text-align:left;padding:12px 16px;font-size:11px;text-transform:uppercase;color:#64748B;font-weight:700;letter-spacing:0.04em;">Numéro</th>
            <th style="text-align:left;padding:12px 16px;font-size:11px;text-transform:uppercase;color:#64748B;font-weight:700;letter-spacing:0.04em;">Client</th>
            <th style="text-align:left;padding:12px 16px;font-size:11px;text-transform:uppercase;color:#64748B;font-weight:700;letter-spacing:0.04em;">Date</th>
            <th style="text-align:right;padding:12px 16px;font-size:11px;text-transform:uppercase;color:#64748B;font-weight:700;letter-spacing:0.04em;">Montant TTC</th>
            <th style="text-align:center;padding:12px 16px;font-size:11px;text-transform:uppercase;color:#64748B;font-weight:700;letter-spacing:0.04em;">Statut</th>
            <th style="text-align:right;padding:12px 16px;font-size:11px;text-transform:uppercase;color:#64748B;font-weight:700;letter-spacing:0.04em;">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($invoices as $inv):
            [$st_color, $st_bg, $st_label] = $status_style((string)$inv['status']);
          ?>
          <tr style="border-top:1px solid #F1F5F9;">
            <td style="padding:14px 16px;font-family:monospace;font-size:13px;font-weight:600;"><?= h($inv['invoice_number'] ?? ('#' . $inv['id'])) ?></td>
            <td style="padding:14px 16px;color:#0F172A;font-weight:600;"><?= h($inv['client_name']) ?></td>
            <td style="padding:14px 16px;color:#475569;"><?= !empty($inv['issued_at']) ? h(date('d/m/Y', strtotime($inv['issued_at']))) : '—' ?></td>
            <td style="padding:14px 16px;text-align:right;font-weight:700;"><?= h($fmt_cents($inv['amount_ttc_cents'])) ?></td>
            <td style="padding:14px 16px;text-align:center;">
              <span style="background:<?= $st_bg ?>;color:<?= $st_color ?>;font-size:11px;padding:3px 10px;border-radius:999px;font-weight:600;"><?= h($st_label) ?></span>
            </td>
            <td style="padding:14px 16px;text-align:right;">
              <a href="/mon-asso-facture-edit?id=<?= (int)$inv['id'] ?>" style="display:inline-flex;align-items:center;gap:5px;background:#F0FDF4;color:#047857;padding:6px 12px;border-radius:6px;font-size:12px;text-decoration:none;font-weight:600;"><?= ak_icon('inbox',14) ?>Ouvrir</a>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      </div>
    <?php endif; ?>
  </div>

  <p style="text-align:center;margin-top:18px;color:#64748B;font-size:13px;">
    Vos factures émises à vos clients. Les factures d'abonnement Assokit sont dans <a href="/abonnement" style="color:#059669;font-weight:600;text-decoration:none;">Abonnement</a>.
  </p>
</main>
<?php render_foot(); ?>
