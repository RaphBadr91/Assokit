<?php
/**
 * mon-asso-client-detail.php
 * Fiche détaillée d'un client avec historique factures
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes-layout.php';
require_once __DIR__ . '/asso-invoice-helpers.php';

require_login();
$user = current_user();

if (empty($user['org_id'])) { http_response_code(403); die('Aucune asso.'); }
$org_id = (int)$user['org_id'];

// [PACK 6.5 - SECURITY] Accès finances obligatoire (factures clients = données comptables)
require_once __DIR__ . '/finance-permissions.php';
require_billing_access('factures', 'la fiche détaillée d\'un client');

$client_id = (int)($_GET['id'] ?? 0);
if ($client_id <= 0) { header('Location: /mon-asso-clients.php'); exit; }

$stmt = $pdo->prepare("SELECT * FROM asso_clients WHERE id = :id AND org_id = :org AND deleted_at IS NULL LIMIT 1");
$stmt->execute([':id' => $client_id, ':org' => $org_id]);
$client = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$client) { http_response_code(404); die('Client introuvable.'); }

if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$csrf = $_SESSION['csrf_token'];

// ────── Modification de la fiche client ──────
// Les factures et devis déjà émis gardent les coordonnées figées à leur émission ;
// les prochains (et les brouillons à leur prochain enregistrement) prennent les nouvelles.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_client') {
    if (!hash_equals($csrf, (string)($_POST['csrf_token'] ?? ''))) { http_response_code(419); exit('Session expirée.'); }
    $data = [];
    foreach (['client_type', 'display_name', 'legal_name', 'contact_first_name', 'contact_last_name', 'email', 'phone',
              'address_street', 'address_complement', 'address_zip', 'address_city', 'address_country',
              'siren', 'siret', 'vat_number'] as $f) {
        if (array_key_exists($f, $_POST)) $data[$f] = trim((string)$_POST[$f]);
    }
    if (trim((string)($data['display_name'] ?? '')) === '' || !filter_var($data['email'] ?? '', FILTER_VALIDATE_EMAIL)) {
        $_SESSION['flash_asso_client'] = ['type' => 'error', 'message' => 'Le nom et une adresse e-mail valide sont obligatoires.'];
    } else {
        try {
            ak_asso_client_update($pdo, $org_id, $client_id, $data);
            $_SESSION['flash_asso_client'] = ['type' => 'success', 'message' => 'Fiche client mise à jour. Les factures et devis déjà émis gardent les coordonnées de leur émission.'];
        } catch (Throwable $e) {
            error_log('[mon-asso-client-detail] ' . $e->getMessage());
            $_SESSION['flash_asso_client'] = ['type' => 'error', 'message' => 'La fiche n’a pas pu être enregistrée. Réessayez dans un instant.'];
        }
    }
    header('Location: /mon-asso-client-detail?id=' . $client_id);
    exit;
}
$flash = $_SESSION['flash_asso_client'] ?? null;
unset($_SESSION['flash_asso_client']);

$stats = ak_asso_client_stats($pdo, $client_id, $org_id);

// Historique factures
$stmt = $pdo->prepare("
    SELECT * FROM asso_invoices
    WHERE client_id = :cid AND org_id = :org
    ORDER BY id DESC
");
$stmt->execute([':cid' => $client_id, ':org' => $org_id]);
$invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);

render_head('Client : ' . $client['display_name']);
render_sidebar('factures');
?>

<div class="main">
    <nav class="crumbs">
        <a href="/mon-asso-clients.php">Mes clients</a>
        <span class="sep">›</span>
        <span class="current"><?= h($client['display_name']) ?></span>
    </nav>

    <div class="main-head">
        <div>
            <h1 class="page-title" style="display:flex; align-items:center; gap:11px;"><?= ak_icon_badge('user','#059669',36) ?><span><?= h($client['display_name']) ?></span></h1>
            <div class="page-sub">
                <?php if ($client['client_type'] === 'individual'): ?>
                    <span style="background:#DBEAFE; color:#1E40AF; padding:2px 8px; border-radius:4px; font-size:11px;">Particulier</span>
                <?php else: ?>
                    <span style="background:#F3E8FF; color:#7E22CE; padding:2px 8px; border-radius:4px; font-size:11px;">Entreprise / Asso</span>
                <?php endif; ?>
                · <?= h($client['email']) ?>
            </div>
        </div>
        <div>
            <a href="/mon-asso-clients.php" class="btn btn-ghost">← Retour</a>
        </div>
    </div>

    <?php if ($flash): ?>
    <div class="alert <?= $flash['type'] === 'success' ? 'alert-success' : 'alert-error' ?>" style="margin-bottom:16px;"><?= h($flash['message']) ?></div>
    <?php endif; ?>

    <!-- Stats -->
    <div style="display:grid; grid-template-columns:repeat(4, 1fr); gap:12px; margin-bottom:20px;">
        <div class="card" style="padding:16px;">
            <div style="font-size:11px; color:#6B7280; text-transform:uppercase;">Factures</div>
            <div style="font-size:24px; font-weight:700; margin-top:4px;"><?= (int)$stats['nb_invoices'] ?></div>
        </div>
        <div class="card" style="padding:16px;">
            <div style="font-size:11px; color:#6B7280; text-transform:uppercase;">CA total</div>
            <div style="font-size:20px; font-weight:700; color:#111827; margin-top:4px; font-variant-numeric:tabular-nums;">
                <?= h(ak_asso_fmt_cents($stats['total_ttc_cents'])) ?>
            </div>
        </div>
        <div class="card" style="padding:16px; background:#F0FDF4;">
            <div style="font-size:11px; color:#065F46; text-transform:uppercase; display:inline-flex; align-items:center; gap:5px;"><?= ak_icon('check-circle',12) ?> Encaissé</div>
            <div style="font-size:20px; font-weight:700; color:#10B981; margin-top:4px; font-variant-numeric:tabular-nums;">
                <?= h(ak_asso_fmt_cents($stats['paid_ttc_cents'])) ?>
            </div>
            <div style="font-size:11px; color:#065F46; margin-top:2px;"><?= (int)$stats['nb_paid'] ?> facture(s)</div>
        </div>
        <div class="card" style="padding:16px; background:<?= $stats['nb_overdue'] > 0 ? '#FEE2E2' : '#FEF3C7' ?>;">
            <div style="font-size:11px; color:<?= $stats['nb_overdue'] > 0 ? '#991B1B' : '#92400E' ?>; text-transform:uppercase; display:inline-flex; align-items:center; gap:5px;">
                <?= $stats['nb_overdue'] > 0 ? ak_icon('alert-tri',12).' En retard' : ak_icon('clock',12).' En attente' ?>
            </div>
            <div style="font-size:20px; font-weight:700; color:<?= $stats['nb_overdue'] > 0 ? '#DC2626' : '#F59E0B' ?>; margin-top:4px; font-variant-numeric:tabular-nums;">
                <?= h(ak_asso_fmt_cents($stats['nb_overdue'] > 0 ? $stats['overdue_ttc_cents'] : $stats['pending_ttc_cents'])) ?>
            </div>
            <div style="font-size:11px; margin-top:2px; color:<?= $stats['nb_overdue'] > 0 ? '#991B1B' : '#92400E' ?>;">
                <?= (int)($stats['nb_overdue'] > 0 ? $stats['nb_overdue'] : $stats['nb_pending']) ?> facture(s)
            </div>
        </div>
    </div>

    <!-- Coordonnées -->
    <div class="card" style="padding:22px; margin-bottom:16px;">
        <h3 style="margin:0 0 16px 0; font-size:15px; display:flex; align-items:center; gap:8px;"><?= ak_icon('clipboard',18) ?> Coordonnées</h3>
        <div style="display:grid; grid-template-columns:1fr 1fr; gap:14px; font-size:13px;">
            <?php if (!empty($client['legal_name'])): ?>
                <div><strong>Raison sociale :</strong> <?= h($client['legal_name']) ?></div>
            <?php endif; ?>
            <?php if (!empty($client['contact_first_name']) || !empty($client['contact_last_name'])): ?>
                <div><strong>Contact :</strong> <?= h(trim($client['contact_first_name'] . ' ' . $client['contact_last_name'])) ?></div>
            <?php endif; ?>
            <div><strong>Email :</strong> <?= h($client['email']) ?></div>
            <?php if (!empty($client['phone'])): ?>
                <div><strong>Téléphone :</strong> <?= h($client['phone']) ?></div>
            <?php endif; ?>
            <?php if (!empty($client['address_street']) || !empty($client['address_city'])): ?>
                <div style="grid-column:1/-1;">
                    <strong>Adresse :</strong>
                    <?= h($client['address_street'] ?? '') ?>
                    <?php if (!empty($client['address_complement'])): ?> · <?= h($client['address_complement']) ?><?php endif; ?>
                    <?php if (!empty($client['address_zip']) || !empty($client['address_city'])): ?>
                        — <?= h(trim(($client['address_zip'] ?? '') . ' ' . ($client['address_city'] ?? ''))) ?>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            <?php if (!empty($client['siren'])): ?>
                <div><strong>SIREN :</strong> <?= h($client['siren']) ?></div>
            <?php endif; ?>
            <?php if (!empty($client['vat_number'])): ?>
                <div><strong>TVA :</strong> <?= h($client['vat_number']) ?></div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Modifier la fiche -->
    <details class="card" style="padding:0; margin-bottom:16px; overflow:hidden;">
        <summary style="padding:14px 22px; cursor:pointer; font-size:14px; font-weight:600; display:flex; align-items:center; gap:8px;"><?= ak_icon('edit',16) ?> Modifier les coordonnées</summary>
        <form method="POST" action="/mon-asso-client-detail?id=<?= (int)$client_id ?>" style="padding:4px 22px 20px;">
            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
            <input type="hidden" name="action" value="update_client">
            <?php
            $fld = function (string $name, string $label, string $type = 'text', bool $req = false, bool $wide = false) use ($client) {
                return '<div' . ($wide ? ' style="grid-column:1/-1;"' : '') . '><label style="display:block; font-size:12px; color:#6B7280; margin-bottom:6px;">'
                     . h($label) . ($req ? ' *' : '') . '</label><input type="' . $type . '" name="' . $name . '" value="' . h($client[$name] ?? '') . '"'
                     . ($req ? ' required' : '') . ' maxlength="' . ($name === 'display_name' ? 200 : 255) . '" style="width:100%; padding:10px; border:1px solid #E5E7EB; border-radius:8px;"></div>';
            };
            ?>
            <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(220px, 1fr)); gap:14px;">
                <div>
                    <label style="display:block; font-size:12px; color:#6B7280; margin-bottom:6px;">Type</label>
                    <select name="client_type" style="width:100%; padding:10px; border:1px solid #E5E7EB; border-radius:8px;">
                        <option value="company" <?= ($client['client_type'] ?? 'company') === 'company' ? 'selected' : '' ?>>Entreprise / Asso</option>
                        <option value="individual" <?= ($client['client_type'] ?? '') === 'individual' ? 'selected' : '' ?>>Particulier</option>
                    </select>
                </div>
                <?= $fld('display_name', 'Nom affiché', 'text', true) ?>
                <?= $fld('email', 'Email', 'email', true) ?>
                <?= $fld('phone', 'Téléphone') ?>
                <?= $fld('legal_name', 'Raison sociale', 'text', false, true) ?>
                <?= $fld('contact_first_name', 'Prénom contact') ?>
                <?= $fld('contact_last_name', 'Nom contact') ?>
                <?= $fld('address_street', 'Adresse (n° + rue)', 'text', false, true) ?>
                <?= $fld('address_complement', 'Complément', 'text', false, true) ?>
                <?= $fld('address_zip', 'Code postal') ?>
                <?= $fld('address_city', 'Ville') ?>
                <?= $fld('address_country', 'Pays') ?>
                <?= $fld('siren', 'SIREN') ?>
                <?= $fld('siret', 'SIRET') ?>
                <?= $fld('vat_number', 'N° TVA intracommunautaire') ?>
            </div>
            <div style="display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap; margin-top:16px;">
                <div style="font-size:12px; color:#6B7280;">Les factures et devis déjà émis gardent les coordonnées de leur émission.</div>
                <button type="submit" class="btn btn-primary" style="padding:10px 20px;">💾 Enregistrer la fiche</button>
            </div>
        </form>
    </details>

    <!-- Historique factures -->
    <div class="card" style="padding:0; overflow:hidden;">
        <h3 style="margin:0; padding:16px 22px; border-bottom:1px solid #E5E7EB; font-size:15px; display:flex; align-items:center; gap:8px;"><?= ak_icon('receipt',18) ?> Historique des factures</h3>
        <?php if (empty($invoices)): ?>
            <div style="padding:40px; text-align:center; color:#6B7280;">
                Aucune facture émise pour ce client.
            </div>
        <?php else: ?>
        <table style="width:100%; border-collapse:collapse;">
            <thead style="background:#F9FAFB;">
                <tr>
                    <th style="text-align:left; padding:10px 14px; font-size:11px; color:#6B7280; text-transform:uppercase;">N°</th>
                    <th style="text-align:left; padding:10px 14px; font-size:11px; color:#6B7280; text-transform:uppercase;">Émise</th>
                    <th style="text-align:left; padding:10px 14px; font-size:11px; color:#6B7280; text-transform:uppercase;">Échéance</th>
                    <th style="text-align:right; padding:10px 14px; font-size:11px; color:#6B7280; text-transform:uppercase;">TTC</th>
                    <th style="text-align:center; padding:10px 14px; font-size:11px; color:#6B7280; text-transform:uppercase;">Statut</th>
                    <th style="text-align:right; padding:10px 14px; font-size:11px; color:#6B7280; text-transform:uppercase;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($invoices as $inv):
                    $is_overdue = ($inv['status'] === 'pending' && strtotime($inv['due_at']) < time());
                    $status_label = $is_overdue ? 'En retard' : ak_asso_invoice_status_label($inv['status']);
                ?>
                <tr style="border-top:1px solid #E5E7EB;">
                    <td style="padding:10px 14px; font-size:13px; font-variant-numeric:tabular-nums;"><strong><?= h($inv['invoice_number']) ?></strong></td>
                    <td style="padding:10px 14px; font-size:13px; color:#6B7280;"><?= h(date('d/m/Y', strtotime($inv['issued_at']))) ?></td>
                    <td style="padding:10px 14px; font-size:13px; color:<?= $is_overdue ? '#DC2626' : '#6B7280' ?>;">
                        <?= h(date('d/m/Y', strtotime($inv['due_at']))) ?>
                    </td>
                    <td style="padding:10px 14px; text-align:right; font-size:13px; font-weight:600; font-variant-numeric:tabular-nums;">
                        <?= h(ak_asso_fmt_cents((int)$inv['amount_ttc_cents'])) ?>
                    </td>
                    <td style="padding:10px 14px; text-align:center;">
                        <span style="display:inline-block; padding:2px 8px; border-radius:10px; font-size:11px;
                            background:<?= $inv['status']==='paid' ? '#D1FAE5' : ($is_overdue ? '#FEE2E2' : '#FEF3C7') ?>;
                            color:<?= $inv['status']==='paid' ? '#065F46' : ($is_overdue ? '#991B1B' : '#92400E') ?>;">
                            <?= h($status_label) ?>
                        </span>
                    </td>
                    <td style="padding:10px 14px; text-align:right; white-space:nowrap;">
                        <?php if (!empty($inv['pdf_path'])): ?>
                            <a href="<?= h($inv['pdf_path']) ?>" target="_blank" class="btn btn-ghost" style="padding:4px 8px; font-size:12px; margin-right:4px; display:inline-flex; align-items:center;"><?= ak_icon('download',15) ?></a>
                        <?php endif; ?>
                        <a href="/mon-asso-facture-edit.php?id=<?= (int)$inv['id'] ?>" class="btn btn-ghost" style="padding:4px 8px; font-size:12px; background:#FEF3C7; color:#92400E; border-color:#FCD34D; display:inline-flex; align-items:center;"><?= ak_icon('edit',15) ?></a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>

<?php render_foot(); ?>
