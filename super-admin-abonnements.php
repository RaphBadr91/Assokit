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
 *
 * Chaque action se termine par une redirection 303 (rechargement sans renvoi du formulaire).
 * Seule une facture envoyée ou en retard peut être payée (une seule fois, comme dans l'appli
 * Fondateur) ; une facture payée n'est jamais annulée. Les e-mails partent à l'adresse de
 * facturation de l'association (sinon à son premier admin) et l'écran dit s'ils sont partis.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes-layout.php';
require_once __DIR__ . '/superadmin-layout.php';
require_once __DIR__ . '/sa-plan-state-helpers.php';
@require_once __DIR__ . '/resend-helper.php';
require_once __DIR__ . '/trial-helpers.php';   // ak_db_columns / ak_db_insert (colonnes selon le schéma)
require_login();
$user = sa_require_super_admin();

// Message de l'action précédente (motif PRG : chaque POST se termine par une redirection 303, un
// rechargement de la page ne renvoie jamais le formulaire, donc ni seconde facture ni second paiement)
$flash = $_SESSION['sa_abonnements_flash'] ?? null;
unset($_SESSION['sa_abonnements_flash']);
$error = ($flash && $flash['type'] === 'error') ? $flash['message'] : null;
$success = ($flash && $flash['type'] === 'success') ? $flash['message'] : null;
$warning = ($flash && $flash['type'] === 'warning') ? $flash['message'] : null;

/**
 * Destinataire des e-mails de facturation : adresse de facturation de l'association, sinon son
 * premier administrateur actif (la colonne organizations.email n'existe pas : la requête échouait
 * et aucun e-mail ne partait). ['name' => …, 'recipient' => …] ou null.
 */
function sa_abo_recipient(PDO $pdo, int $org_id): ?array {
    $ocols = ak_db_columns($pdo, 'organizations');
    $ucols = ak_db_columns($pdo, 'users');
    $mails = [];
    foreach (['billing_email', 'email'] as $c) if (isset($ocols[$c])) $mails[] = "NULLIF(o.$c, '')";
    $mails[] = "(SELECT u.email FROM users u WHERE u.org_id = o.id AND u.role = 'admin' AND u.is_active = 1"
             . (isset($ucols['deleted_at']) ? " AND u.deleted_at IS NULL" : "") . " ORDER BY u.id ASC LIMIT 1)";
    $st = $pdo->prepare("SELECT o.name, COALESCE(" . implode(', ', $mails) . ") AS recipient FROM organizations o WHERE o.id = ? LIMIT 1");
    $st->execute([$org_id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) return null;
    $row['recipient'] = trim((string)($row['recipient'] ?? ''));
    return $row;
}

/** Envoi d'un e-mail de facturation : true seulement s'il est réellement parti. */
function sa_abo_send(?array $org, string $subject, string $html, string $tag, string $log): bool {
    if (!$org || $org['recipient'] === '' || !filter_var($org['recipient'], FILTER_VALIDATE_EMAIL) || !function_exists('send_transactional_email')) return false;
    try {
        $res = send_transactional_email($org['recipient'], $subject, $html, ['tag' => $tag]);
    } catch (Throwable $e) {
        $res = ['success' => false, 'error' => $e->getMessage()];
    }
    if (empty($res['success'])) error_log('[super-admin-abonnements] ' . $log . ' : ' . ($res['error'] ?? 'échec'));
    return !empty($res['success']);
}

/** Date AAAA-MM-JJ valide, sinon null. */
function sa_abo_date(string $v): ?string {
    return (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m) && checkdate((int)$m[2], (int)$m[3], (int)$m[1])) ? $v : null;
}

// =====================================================================
// Traitement POST
// =====================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $flash = null;
    if (!function_exists('check_csrf') || !check_csrf($_POST['csrf_token'] ?? '')) {
        $flash = ['type' => 'error', 'message' => 'Session expirée : rechargez la page et recommencez.'];
    } else {
    $action = $_POST['action'] ?? '';

    // ---- CREATE INVOICE ----
    if ($action === 'create_invoice') {
        $org_id = (int) ($_POST['org_id'] ?? 0);
        $period_start = sa_abo_date((string) ($_POST['period_start'] ?? ''));
        $period_end = sa_abo_date((string) ($_POST['period_end'] ?? ''));
        $amount_ht = round((float) str_replace(',', '.', (string) ($_POST['amount_ht'] ?? 0)), 2);
        $tva_rate = (float) str_replace(',', '.', (string) ($_POST['tva_rate'] ?? 20));
        $due_date = sa_abo_date((string) ($_POST['due_date'] ?? ''));

        $org_ok = false;
        if ($org_id > 0) {
            $chk = $pdo->prepare("SELECT 1 FROM organizations WHERE id = ?");
            $chk->execute([$org_id]);
            $org_ok = (bool) $chk->fetchColumn();
        }
        if (!$org_ok || !$period_start || !$period_end || $amount_ht <= 0) {
            $flash = ['type' => 'error', 'message' => 'Tous les champs sont obligatoires (association, période, montant HT positif).'];
        } elseif ($period_end < $period_start) {
            $flash = ['type' => 'error', 'message' => 'La fin de période est antérieure à son début.'];
        } elseif ($tva_rate < 0 || $tva_rate > 100) {
            $flash = ['type' => 'error', 'message' => 'Taux de TVA invalide.'];
        } else {
            $amount_tva = round($amount_ht * $tva_rate / 100, 2);
            $amount_ttc = round($amount_ht + $amount_tva, 2);

            // Double envoi du formulaire (double clic) : la même facture n'est pas créée deux fois
            $dup = $pdo->prepare("SELECT invoice_number FROM subscription_invoices
                                  WHERE org_id = ? AND period_start = ? AND period_end = ? AND amount_ttc = ?
                                    AND COALESCE(status, '') <> 'cancelled' AND created_at >= NOW() - INTERVAL 10 MINUTE
                                  ORDER BY id DESC LIMIT 1");
            $dup->execute([$org_id, $period_start, $period_end, $amount_ttc]);
            $dup_number = $dup->fetchColumn();
            if ($dup_number) {
                $flash = ['type' => 'warning', 'message' => "La facture {$dup_number} vient déjà d’être créée pour cette période : aucune seconde facture."];
            } else {
                // Numéro AAAA-XXXX : le plus grand numéro de l'année + 1 (COUNT + 1 redonnait un numéro
                // existant dès qu'une facture manquait dans la suite)
                $year = date('Y');
                $stmt = $pdo->prepare("SELECT MAX(CAST(SUBSTRING_INDEX(invoice_number, '-', -1) AS UNSIGNED)) FROM subscription_invoices WHERE invoice_number LIKE ?");
                $stmt->execute([$year . '-%']);
                $invoice_number = sprintf('%s-%04d', $year, (int) $stmt->fetchColumn() + 1);

                ak_db_insert($pdo, 'subscription_invoices', [
                    'org_id' => $org_id, 'invoice_number' => $invoice_number,
                    'period_start' => $period_start, 'period_end' => $period_end,
                    'amount_ht' => $amount_ht, 'tva_rate' => $tva_rate, 'amount_tva' => $amount_tva, 'amount_ttc' => $amount_ttc,
                    'status' => 'sent', 'issued_at' => date('Y-m-d'), 'due_date' => $due_date,
                    'created_by_user_id' => (int) $user['id'],
                ], ['created_at' => 'NOW()']);

                sa_log_action((int) $user['id'], 'create_invoice', $org_id, null, [
                    'invoice_number' => $invoice_number,
                    'amount_ttc' => $amount_ttc,
                ]);

                // Envoi email facture via Resend (adresse de facturation de l'asso, sinon premier admin)
                $org_data = sa_abo_recipient($pdo, $org_id);
                $sent = function_exists('render_email_invoice_sent') && sa_abo_send($org_data,
                    'Nouvelle facture ' . $invoice_number . ' — Assokit',
                    render_email_invoice_sent([
                        'org_name' => $org_data['name'] ?? '',
                        'invoice_number' => $invoice_number,
                        'amount_ttc' => $amount_ttc,
                        'period_start' => $period_start,
                        'period_end' => $period_end,
                        'due_date' => $due_date,
                    ]),
                    'invoice_sent', 'e-mail facture ' . $invoice_number);

                $amount_fr = number_format($amount_ttc, 2, ',', ' ') . ' € TTC';
                $flash = $sent
                    ? ['type' => 'success', 'message' => "Facture {$invoice_number} créée ({$amount_fr}) et envoyée par email à " . $org_data['recipient'] . '.']
                    : ['type' => 'warning', 'message' => "Facture {$invoice_number} créée ({$amount_fr}), mais l’e-mail n’est pas parti"
                        . (empty($org_data['recipient']) ? ' (aucune adresse e-mail pour cette association)' : '') . ' : envoyez-la au client vous-même.'];
            }
        }
    }

    // ---- MARK AS PAID ----
    elseif ($action === 'mark_paid') {
        $invoice_id = (int) ($_POST['invoice_id'] ?? 0);
        $today = date('Y-m-d');
        $payment_date = sa_abo_date((string) ($_POST['payment_date'] ?? '')) ?? $today;
        if ($payment_date > $today) $payment_date = $today;
        $payment_method = (string) ($_POST['payment_method'] ?? 'virement');
        if (!in_array($payment_method, ['virement', 'cheque', 'especes', 'carte', 'autre'], true)) $payment_method = 'virement';
        $reference = mb_substr(trim((string) ($_POST['reference'] ?? '')), 0, 120);

        $stmt = $pdo->prepare("SELECT * FROM subscription_invoices WHERE id = ?");
        $stmt->execute([$invoice_id]);
        $inv = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$inv) {
            $flash = ['type' => 'error', 'message' => 'Facture introuvable.'];
        } else {
            // Seule une facture envoyée ou en retard attend un paiement (même règle que l'appli Fondateur,
            // api/app-founder-billing-action.php) ; la condition est dans l'UPDATE : un second envoi du
            // formulaire (rechargement, double clic) n'enregistre jamais un second paiement
            $pdo->beginTransaction();
            $icols = ak_db_columns($pdo, 'subscription_invoices');
            $sets = ["status = 'paid'"]; $params = [];
            if (isset($icols['paid_at'])) { $sets[] = 'paid_at = ?'; $params[] = $payment_date; }
            if (isset($icols['payment_method'])) { $sets[] = 'payment_method = ?'; $params[] = $payment_method; }
            $params[] = $invoice_id;
            $stmt = $pdo->prepare("UPDATE subscription_invoices SET " . implode(', ', $sets) . " WHERE id = ? AND status IN ('sent', 'overdue')");
            $stmt->execute($params);

            if ($stmt->rowCount() !== 1) {
                $pdo->rollBack();
                $flash = ['type' => 'error', 'message' => ($inv['status'] ?? '') === 'paid'
                    ? "La facture {$inv['invoice_number']} est déjà payée : aucun second paiement enregistré."
                    : "La facture {$inv['invoice_number']} n’attend pas de paiement (" . (($inv['status'] ?? '') === 'cancelled' ? 'annulée' : 'brouillon') . ').'];
            } else {
                // Enregistrer le paiement (table absente sur un ancien schéma : ignoré)
                if (ak_db_columns($pdo, 'subscription_payments')) {
                    ak_db_insert($pdo, 'subscription_payments', [
                        'invoice_id' => $invoice_id, 'org_id' => (int) $inv['org_id'], 'amount' => $inv['amount_ttc'],
                        'payment_date' => $payment_date, 'payment_method' => $payment_method,
                        'reference' => $reference !== '' ? $reference : null, 'recorded_by_user_id' => (int) $user['id'],
                    ], ['created_at' => 'NOW()']);
                }
                $pdo->commit();

                sa_log_action((int) $user['id'], 'mark_invoice_paid', $inv['org_id'], null, [
                    'invoice_number' => $inv['invoice_number'],
                    'amount' => $inv['amount_ttc'],
                    'method' => $payment_method,
                ]);

                // Envoi email de confirmation via Resend
                $org_data = sa_abo_recipient($pdo, (int) $inv['org_id']);
                $sent = function_exists('render_email_invoice_paid') && sa_abo_send($org_data,
                    'Paiement reçu — Facture ' . $inv['invoice_number'],
                    render_email_invoice_paid([
                        'org_name' => $org_data['name'] ?? '',
                        'invoice_number' => $inv['invoice_number'],
                        'amount_ttc' => $inv['amount_ttc'],
                        'payment_date' => $payment_date,
                        'payment_method' => $payment_method,
                    ]),
                    'invoice_paid', 'e-mail paiement ' . $inv['invoice_number']);

                $flash = $sent
                    ? ['type' => 'success', 'message' => "Facture {$inv['invoice_number']} marquée comme payée. Email de confirmation envoyé à " . $org_data['recipient'] . '.']
                    : ['type' => 'warning', 'message' => "Facture {$inv['invoice_number']} marquée comme payée, mais l’e-mail de confirmation n’est pas parti"
                        . (empty($org_data['recipient']) ? ' (aucune adresse e-mail pour cette association)' : '') . ' : prévenez le client vous-même.'];
            }
        }
    }

    // ---- CANCEL INVOICE ----
    elseif ($action === 'cancel_invoice') {
        $invoice_id = (int) ($_POST['invoice_id'] ?? 0);
        // Jamais une facture payée (ses paiements resteraient rattachés à une facture « annulée »)
        $stmt = $pdo->prepare("UPDATE subscription_invoices SET status = 'cancelled' WHERE id = ? AND status IN ('draft', 'sent', 'overdue')");
        $stmt->execute([$invoice_id]);
        if ($stmt->rowCount() === 1) {
            sa_log_action((int) $user['id'], 'cancel_invoice', null, null, ['invoice_id' => $invoice_id]);
            $flash = ['type' => 'success', 'message' => 'Facture annulée.'];
        } else {
            $flash = ['type' => 'error', 'message' => 'Cette facture ne peut pas être annulée (introuvable, déjà payée ou déjà annulée).'];
        }
    }
    }

    if ($flash) $_SESSION['sa_abonnements_flash'] = $flash;
    header('Location: /super-admin/abonnements', true, 303);
    exit;
}

// =====================================================================
// Filtres et requetes
// =====================================================================
$filter = $_GET['filter'] ?? '';
$filter_org = (int) ($_GET['org_id'] ?? 0);

$where = [];
$params = [];
if ($filter === 'unpaid') {
    // Mêmes impayées que le compteur ci-dessous, le signal du cockpit et l'appli : hors associations supprimées
    $where[] = "i.status IN ('sent','overdue') AND o.deleted_at IS NULL";
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
    $stmt = $pdo->query("SELECT COUNT(*) AS nb, COALESCE(SUM(i.amount_ttc), 0) AS total
                         FROM subscription_invoices i
                         JOIN organizations o ON o.id = i.org_id AND o.deleted_at IS NULL
                         WHERE i.status IN ('sent','overdue')");
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
// Plan et statut = ak_org_plan_state (même vérité que la fiche et l'appli) : une association
// en essai est affichée « Essai PRO · fin … · 0 € » et aucun montant n'est pré-rempli.
$orgs_with_sub = [];
try {
    $sc = ak_db_columns($pdo, 'subscriptions');
    $legacy_sel = [];
    foreach (['price_ht', 'tva_rate', 'billing_cycle'] as $c) $legacy_sel[] = isset($sc[$c]) ? "s.$c" : "NULL AS $c";
    $orgs_with_sub = $pdo->query("
        SELECT o.id, o.name, " . implode(', ', $legacy_sel) . "
        FROM organizations o
        LEFT JOIN subscriptions s ON s.id = (SELECT MAX(s2.id) FROM subscriptions s2 WHERE s2.org_id = o.id)
        WHERE o.status IN ('active','trial') AND o.deleted_at IS NULL
        ORDER BY o.name
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('[super-admin-abonnements] orgs: ' . get_class($e) . ' ' . $e->getMessage());
}
$org_states = sa_org_plan_states($pdo, array_merge(array_column($orgs_with_sub, 'id'), array_column($invoices, 'org_id')));
foreach ($orgs_with_sub as &$o) {
    $ps = $org_states[(int) $o['id']] ?? null;
    $o['_trial'] = $ps && ($ps['is_trial'] || $ps['trial_expired']);
    // Facturable : plan payant ou sur devis ; un ancien compte sans abonnement garde son ancien tarif
    $billable = $ps && !$o['_trial'] && (!$ps['plan_id'] || (int) $ps['price_cents'] > 0 || $ps['plan_slug'] === 'sur-mesure');
    if ($o['_trial']) {
        $o['_label'] = $o['name'] . ' — ' . $ps['plan_label'] . ' · '
                     . ($ps['trial_expired'] ? $ps['badge'] : ($ps['trial_ends_at'] ? 'fin ' . date('d/m/Y', strtotime((string) $ps['trial_ends_at'])) : 'essai')) . ' · 0 €';
        $o['price_ht'] = null;
    } else {
        if (!$billable) $o['price_ht'] = null;   // plan gratuit : jamais l'ancien tarif
        $price = (float) ($o['price_ht'] ?? 0) > 0
            ? number_format((float) $o['price_ht'], 2, ',', ' ') . ' € HT'
            : ($ps && $ps['plan_id'] ? sa_plan_price_label((int) $ps['price_cents'], (string) $ps['plan_slug']) : '');
        $o['_label'] = $o['name'] . ($ps ? ' — ' . ($ps['plan_id'] ? '' : 'ancien plan ') . $ps['plan_label'] : '') . ($price !== '' ? ' (' . $price . ')' : '');
    }
}
unset($o);

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
<?php if ($warning): ?><div class="sa-alert sa-alert-error">⚠️ <?= h($warning) ?></div><?php endif; ?>
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
                    // En retard : statut « overdue », ou envoyée et échéance passée (le jour même n'est pas un retard)
                    $is_overdue = $i['status'] === 'overdue'
                        || ($i['status'] === 'sent' && $i['due_date'] && date('Y-m-d', strtotime($i['due_date'])) < date('Y-m-d'));
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
                            <?php $ips = $org_states[(int) $i['org_id']] ?? null; if ($ips && ($ips['is_trial'] || $ips['trial_expired'])): ?>
                                <div><span class="sa-badge <?= sa_plan_tone_class($ips['badge_tone']) ?>" title="<?= h($ips['badge']) ?>"><?= sa_plan_tone_icon($ips['badge_tone']) ?> <?= h($ips['plan_label'] . ' · ' . sa_plan_state_short($ips)) ?></span></div>
                            <?php endif; ?>
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
                            <?php if (in_array($i['status'], ['sent','overdue'], true)): ?>
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
                                data-price="<?= $o['price_ht'] !== null ? h((string) (float) $o['price_ht']) : '' ?>"
                                data-tva="<?= h((string) (float) ($o['tva_rate'] ?? 20)) ?>"
                                data-trial="<?= $o['_trial'] ? '1' : '0' ?>">
                            <?= h($o['_label']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <div id="trial-warning" style="display:none; margin-top:6px; font-size:12px; color:#C4B5FD;">
                    ⏱ Cette association est en essai gratuit (0 €) : passez-la d’abord en abonnement depuis sa fiche.
                </div>
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
    // Essai gratuit : rien à facturer, on ne pré-remplit jamais un tarif
    document.getElementById('amount_ht').value = price ? price : '';
    if (tva) document.getElementById('tva_rate').value = tva;
    document.getElementById('trial-warning').style.display = opt.getAttribute('data-trial') === '1' ? 'block' : 'none';
}

function openPayModal(id, number, amount) {
    document.getElementById('pay-invoice-id').value = id;
    document.getElementById('pay-info').innerHTML = '<strong>Facture ' + number + '</strong><br>Montant : ' + amount.toFixed(2).replace('.', ',') + ' € TTC';
    document.getElementById('modal-pay').style.display = 'flex';
}
</script>

<?php sa_render_foot(); ?>
