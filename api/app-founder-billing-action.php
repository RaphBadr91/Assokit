<?php
/**
 * api/app-founder-billing-action.php — Marquer une facture d'abonnement payée (Fondateur).
 * POST { invoice_id:int, action:'mark_paid', payment_method?, payment_date? (AAAA-MM-JJ), reference?, csrf }.
 * Même traitement que le cockpit web (super-admin-abonnements.php, « Marquer payée ») : seule une facture
 * envoyée ou en retard peut être payée (404 si introuvable, 409 sinon), le paiement est enregistré
 * (subscription_payments), l'action journalisée (mark_invoice_paid) et le client reçoit l'e-mail
 * « Paiement reçu ». Réponse : email_sent ; 'warning' quand le paiement est enregistré mais que
 * l'e-mail de confirmation n'est pas parti. NE MODIFIE PAS le site.
 */
require __DIR__ . '/_app-write-boot.php';
require_once __DIR__ . '/_app-founder.php';
require_once __DIR__ . '/../trial-helpers.php';          // ak_db_columns / ak_db_insert (colonnes selon le schéma)
@include_once __DIR__ . '/../superadmin-layout.php';     // sa_log_action() : même journal que le cockpit web
@require_once __DIR__ . '/../resend-helper.php';

$is_sa = app_is_founder($pdo, $user) || !empty($user['is_super_admin']) || (($user['role'] ?? '') === 'super_admin');
if (!$is_sa) app_fail(403, 'forbidden');

$id = (int) ($input['invoice_id'] ?? 0);
$action = (string) ($input['action'] ?? '');
if ($id <= 0) app_fail(400, 'invoice_id');
if ($action !== 'mark_paid') app_fail(400, 'action');

// Moyens de paiement du cockpit web ; virement par défaut (l'appli n'en envoie pas encore)
$method = (string) ($input['payment_method'] ?? 'virement');
if (!in_array($method, ['virement', 'cheque', 'especes', 'carte', 'autre'], true)) $method = 'virement';
$today = date('Y-m-d');
$pay_date = (string) ($input['payment_date'] ?? $today);
if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $pay_date, $d) || !checkdate((int) $d[2], (int) $d[3], (int) $d[1]) || $pay_date > $today) $pay_date = $today;
$reference = mb_substr(trim((string) ($input['reference'] ?? '')), 0, 120);

try {
    $st = $pdo->prepare("SELECT * FROM subscription_invoices WHERE id = ? LIMIT 1");
    $st->execute([$id]);
    $inv = $st->fetch(PDO::FETCH_ASSOC);
    if (!$inv) app_fail(404, 'not_found', 'Facture introuvable.');
    $number = (string) ($inv['invoice_number'] ?? '') !== '' ? (string) $inv['invoice_number'] : ('AB-' . $id);

    // Seule une facture envoyée ou en retard attend un paiement : une facture annulée, en brouillon
    // ou déjà payée ne doit jamais (re)passer « payée » (le CA encaissé serait faux)
    $status = (string) ($inv['status'] ?? '');
    if (!in_array($status, ['sent', 'overdue'], true)) {
        app_fail(409, 'status', $status === 'paid' ? 'Cette facture est déjà payée.' : 'Seule une facture envoyée ou en retard peut être marquée payée.');
    }

    $pdo->beginTransaction();
    $icols = ak_db_columns($pdo, 'subscription_invoices');
    $sets = ["status = 'paid'"]; $params = [];
    if (!$icols || isset($icols['paid_at'])) {
        if ($pay_date === $today) $sets[] = 'paid_at = NOW()';
        else { $sets[] = 'paid_at = ?'; $params[] = $pay_date; }
    }
    if (!$icols || isset($icols['payment_method'])) { $sets[] = 'payment_method = ?'; $params[] = $method; }
    $params[] = $id;
    // Condition sur le statut : deux appuis simultanés n'enregistrent qu'un seul paiement
    $up = $pdo->prepare("UPDATE subscription_invoices SET " . implode(', ', $sets) . " WHERE id = ? AND status IN ('sent', 'overdue')");
    $up->execute($params);
    if ($up->rowCount() === 0) {
        $pdo->rollBack();
        app_fail(409, 'status', 'Cette facture est déjà payée.');
    }

    // Encaissement enregistré, comme sur le web (table absente sur un ancien schéma : ignoré)
    if (ak_db_columns($pdo, 'subscription_payments')) {
        ak_db_insert($pdo, 'subscription_payments', [
            'invoice_id' => $id, 'org_id' => (int) $inv['org_id'], 'amount' => $inv['amount_ttc'] ?? 0,
            'payment_date' => $pay_date, 'payment_method' => $method, 'reference' => $reference !== '' ? $reference : null,
            'recorded_by_user_id' => (int) $uid,
        ], ['created_at' => 'NOW()']);
    }
    $pdo->commit();

    if (function_exists('sa_log_action')) {
        try {
            sa_log_action((int) $uid, 'mark_invoice_paid', (int) $inv['org_id'], null, [
                'invoice_number' => $number, 'amount' => $inv['amount_ttc'] ?? null, 'method' => $method, 'source' => 'appli',
            ]);
        } catch (Throwable $e) {}
    }

    // E-mail « Paiement reçu » : adresse de facturation de l'association, sinon son premier admin actif
    $email_sent = false; $recipient = '';
    if (function_exists('send_transactional_email') && function_exists('render_email_invoice_paid')) {
        try {
            $ocols = ak_db_columns($pdo, 'organizations');
            $mails = [];
            foreach (['billing_email', 'email'] as $c) if (!$ocols || isset($ocols[$c])) $mails[] = "NULLIF(o.$c, '')";
            $mails[] = "(SELECT u.email FROM users u WHERE u.org_id = o.id AND u.role = 'admin' AND u.is_active = 1 AND u.deleted_at IS NULL ORDER BY u.id ASC LIMIT 1)";
            $so = $pdo->prepare("SELECT o.name, COALESCE(" . implode(', ', $mails) . ") AS recipient FROM organizations o WHERE o.id = ? LIMIT 1");
            $so->execute([(int) $inv['org_id']]);
            $org = $so->fetch(PDO::FETCH_ASSOC) ?: [];
            $recipient = trim((string) ($org['recipient'] ?? ''));
            if ($recipient !== '' && filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
                $res = send_transactional_email(
                    $recipient,
                    'Paiement reçu — Facture ' . $number,
                    render_email_invoice_paid([
                        'org_name' => (string) ($org['name'] ?? ''),
                        'invoice_number' => $number,
                        'amount_ttc' => $inv['amount_ttc'] ?? 0,
                        'payment_date' => $pay_date,
                        'payment_method' => $method,
                    ]),
                    ['tag' => 'invoice_paid']
                );
                $email_sent = !empty($res['success']);
            }
        } catch (Throwable $e) {
            error_log('[api/app-founder-billing-action] e-mail : ' . $e->getMessage());
        }
    }

    $out = ['ok' => true, 'invoice_id' => $id, 'status' => 'paid', 'email_sent' => $email_sent];
    if (!$email_sent) {
        $out['warning'] = 'Paiement enregistré, mais l’e-mail de confirmation n’est pas parti'
                        . ($recipient === '' ? ' (aucune adresse e-mail pour cette association).' : ' : prévenez le client vous-même.');
    }
    echo json_encode($out, JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('[api/app-founder-billing-action] ' . $e->getMessage());
    app_fail(500, 'server');
}
