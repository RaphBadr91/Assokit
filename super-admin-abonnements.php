<?php
/**
 * super-admin-abonnements.php — Gestion des abonnements et paiements
 * ====================================================================
 * Page unifiee pour suivre les paiements de la plateforme.
 *
 * Fonctionnalites :
 *   - Liste des factures (filtrable : draft/sent/paid/overdue)
 *   - Creation d'une nouvelle facture (periode, montant)
 *   - Marquage d'une facture comme payee (date, methode, reference)
 *   - Statistiques : CA du mois, impayes, prochains renouvellements
 *
 * Flow type :
 *   1. Le super admin cree une facture pour une asso (ex: janvier 2026)
 *   2. La facture passe de "draft" a "sent" quand envoyee
 *   3. Quand l'asso paye (virement, cheque), le super admin l'enregistre
 *   4. La facture passe a "paid"
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes-layout.php';
require_once __DIR__ . '/superadmin-layout.php';
@require_once __DIR__ . '/resend-helper.php';
require_login();
$user = sa_require_super_admin();

$error = null;
$success = null;

// =====================================================================
// Traitement POST
// =====================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (!function_exists('check_csrf') || check_csrf($_POST['csrf_token'] ?? ''))) {
    $action = $_POST['action'] ?? '';

    // ---- CREATE INVOICE ----
    if ($action === 'create_invoice') {
        $org_id = (int) ($_POST['org_id'] ?? 0);
        $period_start = $_POST['period_start'] ?? '';
        $period_end = $_POST['period_end'] ?? '';
        $amount_ht = (float) ($_POST['amount_ht'] ?? 0);
        $tva_rate = (float) ($_POST['tva_rate'] ?? 20);
        $due_date = $_POST['due_date'] ?? '';

        if (!$org_id || !$period_start || !$period_end || !$amount_ht) {
            $error = 'Tous les champs sont obligatoires.';
        } else {
            $amount_tva = round($amount_ht * $tva_rate / 100, 2);
            $amount_ttc = round($amount_ht + $amount_tva, 2);

            // Generer un numero de facture : AAAA-XXXX
            $year = date('Y');
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM subscription_invoices WHERE invoice_number LIKE ?");
            $stmt->execute([$year . '-%']);
            $count = (int) $stmt->fetchColumn();
            $invoice_number = sprintf('%s-%04d', $year, $count + 1);

            $stmt = $pdo->prepare("
                INSERT INTO subscription_invoices
                    (org_id, invoice_number, period_start, period_end,
                     amount_ht, tva_rate, amount_tva, amount_ttc,
                     status, issued_at, due_date, created_by_user_id, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'sent', CURDATE(), ?, ?, NOW())
            ");
            $stmt->execute([
                $org_id, $invoice_number, $period_start, $period_end,
                $amount_ht, $tva_rate, $amount_tva, $amount_ttc,
                $due_date ?: null, (int) $user['id']
            ]);

            sa_log_action((int) $user['id'], 'create_invoice', $org_id, null, [
                'invoice_number' => $invoice_number,
                'amount_ttc' => $amount_ttc,
            ]);

            // Envoi email facture via Resend (a l'email de l'asso si defini, sinon au premier admin)
            if (function_exists('send_transactional_email')) {
                try {
                    $stmt_org = $pdo->prepare("
                        SELECT o.name, o.email,
                               (SELECT u.email FROM users u WHERE u.org_id = o.id AND u.role = 'admin' AND u.is_active = 1 ORDER BY u.id ASC LIMIT 1) AS admin_email
                        FROM organizations o WHERE o.id = ?
                    ");
                    $stmt_org->execute([$org_id]);
                    $org_data = $stmt_org->fetch(PDO::FETCH_ASSOC);
                    $recipient = $org_data['email'] ?: $org_data['admin_email'];

                    if ($recipient) {
                        send_transactional_email(
                            $recipient,
                            'Nouvelle facture ' . $invoice_number . ' — Assokit',
                            render_email_invoice_sent([
                                'org_name' => $org_data['name'],
                                'invoice_number' => $invoice_number,
                                'amount_ttc' => $amount_ttc,
                                'period_start' => $period_start,
                                'period_end' => $period_end,
                                'due_date' => $due_date,
                            ]),
                            ['tag' => 'invoice_sent']
                        );
                    }
                } catch (Throwable $e) {
                    error_log('Invoice email fail: ' . $e->getMessage());
                }
            }

            $success = "Facture {$invoice_number} créée (" . number_format($amount_ttc, 2, ',', ' ') . " € TTC) et envoyée par email.";
        }
    }

    // ---- MARK AS PAID ----
    elseif ($action === 'mark_paid') {
        $invoice_id = (int) ($_POST['invoice_id'] ?? 0);
        $payment_date = $_POST['payment_date'] ?? date('Y-m-d');
        $payment_method = $_POST['payment_method'] ?? 'virement';
        $reference = trim($_POST['reference'] ?? '');

        $stmt = $pdo->prepare("SELECT * FROM subscription_invoices WHERE id = ?");
        $stmt->execute([$invoice_id]);
        $inv = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$inv) {
            $error = 'Facture introuvable.';
        } else {
            $pdo->beginTransaction();

            // Marquer comme payee
            $stmt = $pdo->prepare("
                UPDATE subscription_invoices
                SET status = 'paid', paid_at = ?, payment_method = ?
                WHERE id = ?
            ");
            $stmt->execute([$payment_date, $payment_method, $invoice_id]);

            // Enregistrer le paiement
            $stmt = $pdo->prepare("
                INSERT INTO subscription_payments
                    (invoice_id, org_id, amount, payment_date, payment_method, reference, recorded_by_user_id, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([
                $invoice_id, $inv['org_id'], $inv['amount_ttc'],
                $payment_date, $payment_method, $reference ?: null,
                (int) $user['id']
            ]);

            $pdo->commit();

            sa_log_action((int) $user['id'], 'mark_invoice_paid', $inv['org_id'], null, [
                'invoice_number' => $inv['invoice_number'],
                'amount' => $inv['amount_ttc'],
                'method' => $payment_method,
            ]);

            // Envoi email de confirmation via Resend
            if (function_exists('send_transactional_email')) {
                try {
                    $stmt_org = $pdo->prepare("
                        SELECT o.name, o.email,
                               (SELECT u.email FROM users u WHERE u.org_id = o.id AND u.role = 'admin' AND u.is_active = 1 ORDER BY u.id ASC LIMIT 1) AS admin_email
                        FROM organizations o WHERE o.id = ?
                    ");
                    $stmt_org->execute([$inv['org_id']]);
                    $org_data = $stmt_org->fetch(PDO::FETCH_ASSOC);
                    $recipient = $org_data['email'] ?: $org_data['admin_email'];

                    if ($recipient) {
                        send_transactional_email(
                            $recipient,
                            'Paiement reçu — Facture ' . $inv['invoice_number'],
                            render_email_invoice_paid([
                                'org_name' => $org_data['name'],
                                'invoice_number' => $inv['invoice_number'],
                                'amount_ttc' => $inv['amount_ttc'],
                                'payment_date' => $payment_date,
                                'payment_method' => $payment_method,
                            ]),
                            ['tag' => 'invoice_paid']
                        );
                    }
                } catch (Throwable $e) {
                    error_log('Payment confirm email fail: ' . $e->getMessage());
                }
            }

            $success = "Facture {$inv['invoice_number']} marquée comme payée. Email de confirmation envoyé.";
        }
    }

    // ---- CANCEL INVOICE ----
    elseif ($action === 'cancel_invoice') {
        $invoice_id = (int) ($_POST['invoice_id'] ?? 0);
        $stmt = $pdo->prepare("UPDATE subscription_invoices SET status = 'cancelled' WHERE id = ?");
        $stmt->execute([$invoice_id]);
        sa_log_action((int) $user['id'], 'cancel_invoice', null, null, ['invoice_id' => $invoice_id]);
        $success = 'Facture annulée.';
    }
}

// =====================================================================
// Filtres et requetes
// =====================================================================
$filter = $_GET['filter'] ?? '';
$filter_org = (int) ($_GET['org_id'] ?? 0);

$where = [];
$params = [];
if ($filter === 'unpaid') {
    $where[] = "i.status IN ('sent','overdue')";
} elseif ($filter === 'paid') {
    $where[] = "i.status = 'paid'";
} elseif ($filter === 'draft') {
    $where[] = "i.status = 'draft'";
}
if ($filter_org > 0) {
    $where[] = 'i.org_id = ?';
    $params[] = $filter_org;
}
$where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$invoices = [];
try {
    $stmt = $pdo->prepare("
        SELECT i.*, o.name AS org_name
        FROM subscription_invoices i
        JOIN organizations o ON i.org_id = o.id
        $where_sql
        ORDER BY i.created_at DESC
        LIMIT 200
    ");
    $stmt->execute($params);
    $invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $db_error = $e->getMessage();
}

// Stats
$stats = ['unpaid' => 0, 'unpaid_total' => 0, 'paid_month' => 0, 'paid_total_month' => 0];
try {
    $stmt = $pdo->query("SELECT COUNT(*) AS nb, COALESCE(SUM(amount_ttc), 0) AS total FROM subscription_invoices WHERE status IN ('sent','overdue')");
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $stats['unpaid'] = (int) $row['nb'];
    $stats['unpaid_total'] = (float) $row['total'];

    $stmt = $pdo->query("
        SELECT COUNT(*) AS nb, COALESCE(SUM(amount_ttc), 0) AS total
        FROM subscription_invoices
        WHERE status = 'paid'
          AND MONTH(paid_at) = MONTH(CURDATE())
          AND YEAR(paid_at) = YEAR(CURDATE())
    ");
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $stats['paid_month'] = (int) $row['nb'];
    $stats['paid_total_month'] = (float) $row['total'];
} catch (Throwable $e) {}

// Assos avec subscription active (pour creer une facture)
$orgs_with_sub = [];
try {
    $orgs_with_sub = $pdo->query("
        SELECT o.id, o.name, s.price_ht, s.tva_rate, s.billing_cycle
        FROM organizations o
        LEFT JOIN subscriptions s ON s.org_id = o.id
        WHERE o.status IN ('active','trial')
        ORDER BY o.name
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

// Toutes les assos pour filtre
$all_orgs = $pdo->query("SELECT id, name FROM organizations ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

sa_render_head('Abonnements');
sa_render_sidebar('abonnements');
?>

<div class="sa-page-head">
    <div>
        <h1 class="sa-page-title">💳 Abonnements & paiements</h1>
        <div class="sa-page-sub">Suivi manuel des factures et règlements</div>
    </div>
    <div class="sa-page-actions">
        <button type="button" class="sa-btn sa-btn-violet" onclick="document.getElementById('modal-new-invoice').style.display='flex'">
            + Nouvelle facture
        </button>
    </div>
</div>

<?php if ($error): ?><div class="sa-alert sa-alert-error">⚠️ <?= h($error) ?></div><?php endif; ?>
<?php if ($success): ?><div class="sa-alert sa-alert-success">✅ <?= h($success) ?></div><?php endif; ?>
<?php if (isset($db_error)): ?>
    <div class="sa-alert sa-alert-error">
        ⚠️ Base de données : <?= h($db_error) ?>.
        Avez-vous exécuté <code>db-update-v17-subscriptions.sql</code> ?
    </div>
<?php endif; ?>

<!-- Stats -->
<div class="sa-kpi-grid">
    <div class="sa-kpi">
        <div class="sa-kpi-label">Encaissé ce mois</div>
        <div class="sa-kpi-value" style="color:#6EE7B7"><?= number_format($stats['paid_total_month'], 2, ',', ' ') ?> €</div>
        <div class="sa-kpi-trend"><?= $stats['paid_month'] ?> facture<?= $stats['paid_month'] > 1 ? 's' : '' ?></div>
    </div>
    <div class="sa-kpi">
        <div class="sa-kpi-label">En attente de paiement</div>
        <div class="sa-kpi-value" style="color:<?= $stats['unpaid'] > 0 ? '#FCA5A5' : 'inherit' ?>"><?= number_format($stats['unpaid_total'], 2, ',', ' ') ?> €</div>
        <div class="sa-kpi-trend"><?= $stats['unpaid'] ?> facture<?= $stats['unpaid'] > 1 ? 's' : '' ?></div>
    </div>
    <div class="sa-kpi">
        <div class="sa-kpi-label">Total factures</div>
        <div class="sa-kpi-value"><?= count($invoices) ?></div>
    </div>
</div>

<!-- Filtres -->
<div style="display:flex; gap:6px; margin-bottom:16px; flex-wrap:wrap;">
    <a href="/super-admin/abonnements" class="sa-btn <?= !$filter ? 'sa-btn-violet' : 'sa-btn-ghost' ?> sa-btn-sm">
        Toutes
    </a>
    <a href="/super-admin/abonnements?filter=unpaid" class="sa-btn <?= $filter === 'unpaid' ? 'sa-btn-violet' : 'sa-btn-ghost' ?> sa-btn-sm">
        💰 Impayées (<?= $stats['unpaid'] ?>)
    </a>
    <a href="/super-admin/abonnements?filter=paid" class="sa-btn <?= $filter === 'paid' ? 'sa-btn-violet' : 'sa-btn-ghost' ?> sa-btn-sm">
        ✅ Payées
    </a>
    <a href="/super-admin/abonnements?filter=draft" class="sa-btn <?= $filter === 'draft' ? 'sa-btn-violet' : 'sa-btn-ghost' ?> sa-btn-sm">
        📝 Brouillons
    </a>
</div>

<!-- Liste des factures -->
<?php if (empty($invoices)): ?>
    <div class="sa-card">
        <div class="sa-empty">
            <div class="sa-empty-icon">💳</div>
            <div class="sa-empty-title">Aucune facture</div>
            <div>Cliquez sur "+ Nouvelle facture" pour commencer.</div>
        </div>
    </div>
<?php else: ?>
    <div class="sa-table-wrap">
        <table class="sa-table">
            <thead>
                <tr>
                    <th>N° Facture</th>
                    <th>Association</th>
                    <th>Période</th>
                    <th>Montant TTC</th>
                    <th>Statut</th>
                    <th>Échéance</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($invoices as $i): ?>
                    <?php
                    $is_overdue = ($i['status'] === 'sent' && $i['due_date'] && strtotime($i['due_date']) < time());
                    ?>
                    <tr>
                        <td>
                            <div class="sa-main-col"><?= h($i['invoice_number']) ?></div>
                            <div class="sa-sub-col">Émise <?= $i['issued_at'] ? date('d/m/Y', strtotime($i['issued_at'])) : '—' ?></div>
                        </td>
                        <td>
                            <a href="/super-admin/associations?id=<?= (int) $i['org_id'] ?>" style="color:#C4B5FD">
                                <?= h($i['org_name']) ?>
                            </a>
                        </td>
                        <td style="font-size:12.5px">
                            <?= date('d/m/Y', strtotime($i['period_start'])) ?>
                            <br>→ <?= date('d/m/Y', strtotime($i['period_end'])) ?>
                        </td>
                        <td>
                            <strong><?= number_format((float) $i['amount_ttc'], 2, ',', ' ') ?> €</strong>
                            <div class="sa-sub-col">HT <?= number_format((float) $i['amount_ht'], 2, ',', ' ') ?> € + <?= $i['tva_rate'] ?>%</div>
                        </td>
                        <td>
                            <?php if ($i['status'] === 'paid'): ?>
                                <span class="sa-badge sa-badge-green">✓ Payée</span>
                                <div class="sa-sub-col">le <?= date('d/m/Y', strtotime($i['paid_at'])) ?></div>
                            <?php elseif ($is_overdue): ?>
                                <span class="sa-badge sa-badge-red">⚠ En retard</span>
                            <?php elseif ($i['status'] === 'sent'): ?>
                                <span class="sa-badge sa-badge-amber">⏳ En attente</span>
                            <?php elseif ($i['status'] === 'draft'): ?>
                                <span class="sa-badge sa-badge-gray">📝 Brouillon</span>
                            <?php elseif ($i['status'] === 'cancelled'): ?>
                                <span class="sa-badge sa-badge-gray">✕ Annulée</span>
                            <?php endif; ?>
                        </td>
                        <td style="font-size:12.5px; color:var(--sa-ink-3)">
                            <?= $i['due_date'] ? date('d/m/Y', strtotime($i['due_date'])) : '—' ?>
                        </td>
                        <td>
                            <?php if (in_array($i['status'], ['sent','draft','overdue'], true)): ?>
                                <button type="button" class="sa-btn sa-btn-violet sa-btn-sm"
                                        onclick="openPayModal(<?= (int) $i['id'] ?>, '<?= h(addslashes($i['invoice_number'])) ?>', <?= (float) $i['amount_ttc'] ?>)">
                                    ✓ Marquer payée
                                </button>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<!-- ============================================================ -->
<!-- MODAL : Créer une facture                                     -->
<!-- ============================================================ -->
<div id="modal-new-invoice" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.7); z-index:100; align-items:center; justify-content:center; padding:20px;">
    <div style="background:var(--sa-bg-2); border:1px solid var(--sa-border-strong); border-radius:14px; padding:28px; max-width:540px; width:100%; max-height:90vh; overflow-y:auto;">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:18px;">
            <h2 style="margin:0; font-size:18px;">➕ Nouvelle facture</h2>
            <button onclick="document.getElementById('modal-new-invoice').style.display='none'" style="background:none; border:none; color:var(--sa-ink-3); font-size:24px; cursor:pointer;">×</button>
        </div>

        <form method="POST" action="/super-admin/abonnements">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
            <input type="hidden" name="action" value="create_invoice">

            <div class="sa-group">
                <label>Association <span class="req">*</span></label>
                <select name="org_id" required onchange="autoFillPrice(this)">
                    <option value="">— Choisir —</option>
                    <?php foreach ($orgs_with_sub as $o): ?>
                        <option value="<?= (int) $o['id'] ?>"
                                data-price="<?= $o['price_ht'] ?? '' ?>"
                                data-tva="<?= $o['tva_rate'] ?? 20 ?>">
                            <?= h($o['name']) ?>
                            <?php if ($o['price_ht']): ?> (<?= number_format((float) $o['price_ht'], 2, ',', ' ') ?> € HT)<?php endif; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="sa-row-2">
                <div class="sa-group">
                    <label>Période début <span class="req">*</span></label>
                    <input type="date" name="period_start" required value="<?= date('Y-m-01') ?>">
                </div>
                <div class="sa-group">
                    <label>Période fin <span class="req">*</span></label>
                    <input type="date" name="period_end" required value="<?= date('Y-m-t') ?>">
                </div>
            </div>

            <div class="sa-row-2">
                <div class="sa-group">
                    <label>Montant HT (€) <span class="req">*</span></label>
                    <input type="number" step="0.01" name="amount_ht" id="amount_ht" required>
                </div>
                <div class="sa-group">
                    <label>TVA (%)</label>
                    <input type="number" step="0.01" name="tva_rate" id="tva_rate" value="20" required>
                </div>
            </div>

            <div class="sa-group">
                <label>Date d'échéance</label>
                <input type="date" name="due_date" value="<?= date('Y-m-d', strtotime('+30 days')) ?>">
            </div>

            <div style="display:flex; gap:8px; margin-top:20px;">
                <button type="submit" class="sa-btn sa-btn-violet" style="flex:1">✓ Créer la facture</button>
                <button type="button" onclick="document.getElementById('modal-new-invoice').style.display='none'" class="sa-btn sa-btn-ghost">Annuler</button>
            </div>
        </form>
    </div>
</div>

<!-- ============================================================ -->
<!-- MODAL : Marquer payée                                         -->
<!-- ============================================================ -->
<div id="modal-pay" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.7); z-index:100; align-items:center; justify-content:center; padding:20px;">
    <div style="background:var(--sa-bg-2); border:1px solid var(--sa-border-strong); border-radius:14px; padding:28px; max-width:480px; width:100%;">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:18px;">
            <h2 style="margin:0; font-size:18px;">✓ Enregistrer un paiement</h2>
            <button onclick="document.getElementById('modal-pay').style.display='none'" style="background:none; border:none; color:var(--sa-ink-3); font-size:24px; cursor:pointer;">×</button>
        </div>

        <div id="pay-info" style="background:var(--sa-violet-light); padding:12px; border-radius:8px; margin-bottom:16px; font-size:13px;"></div>

        <form method="POST" action="/super-admin/abonnements">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
            <input type="hidden" name="action" value="mark_paid">
            <input type="hidden" name="invoice_id" id="pay-invoice-id">

            <div class="sa-group">
                <label>Date de paiement <span class="req">*</span></label>
                <input type="date" name="payment_date" required value="<?= date('Y-m-d') ?>">
            </div>

            <div class="sa-group">
                <label>Moyen de paiement</label>
                <select name="payment_method">
                    <option value="virement">Virement bancaire</option>
                    <option value="cheque">Chèque</option>
                    <option value="especes">Espèces</option>
                    <option value="carte">Carte bancaire</option>
                    <option value="autre">Autre</option>
                </select>
            </div>

            <div class="sa-group">
                <label>Référence (optionnel)</label>
                <input type="text" name="reference" placeholder="N° de chèque, référence virement...">
            </div>

            <div style="display:flex; gap:8px; margin-top:20px;">
                <button type="submit" class="sa-btn sa-btn-violet" style="flex:1">✓ Enregistrer le paiement</button>
                <button type="button" onclick="document.getElementById('modal-pay').style.display='none'" class="sa-btn sa-btn-ghost">Annuler</button>
            </div>
        </form>
    </div>
</div>

<script>
function autoFillPrice(select) {
    const opt = select.options[select.selectedIndex];
    const price = opt.getAttribute('data-price');
    const tva = opt.getAttribute('data-tva');
    if (price) document.getElementById('amount_ht').value = price;
    if (tva) document.getElementById('tva_rate').value = tva;
}

function openPayModal(id, number, amount) {
    document.getElementById('pay-invoice-id').value = id;
    document.getElementById('pay-info').innerHTML = '<strong>Facture ' + number + '</strong><br>Montant : ' + amount.toFixed(2).replace('.', ',') + ' € TTC';
    document.getElementById('modal-pay').style.display = 'flex';
}
</script>

<?php sa_render_foot(); ?>
