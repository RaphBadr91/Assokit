<?php
/**
 * stripe-webhook.php
 * --------------------------------------------------------------
 * Webhook Stripe — Endpoint qui reçoit tous les événements Stripe
 * et synchronise automatiquement la BDD locale.
 *
 * URL à configurer dans Stripe Dashboard :
 *   https://assokit.fr/stripe-webhook
 *
 * Événements écoutés :
 *   - customer.subscription.created  → asso_subscriptions créée
 *   - customer.subscription.updated  → status mis à jour
 *   - customer.subscription.deleted  → status = cancelled
 *   - invoice.paid                   → asso_invoices_stripe + status active
 *   - invoice.payment_failed         → status = pending_payment / overdue
 *   - checkout.session.completed     → confirmation finale
 *
 * Sécurité :
 *   - Vérification signature Stripe (HMAC SHA-256)
 *   - Idempotency : événements déjà traités sont ignorés
 *   - Logging dans asso_stripe_events pour audit
 *
 * IMPORTANT : ce fichier doit être accessible SANS authentification
 * (Stripe ne peut pas se connecter avec un mot de passe).
 * --------------------------------------------------------------
 */

// Pas de session pour les webhooks
ini_set('session.use_cookies', 0);

require_once __DIR__ . '/config.php';
@require_once __DIR__ . '/stripe-config-helpers.php';
@require_once __DIR__ . '/stripe-helpers.php';

// Récupération du payload brut
$payload = file_get_contents('php://input');
$sig_header = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';

if (empty($payload) || empty($sig_header)) {
    http_response_code(400);
    error_log('[stripe-webhook] Payload ou signature manquant');
    exit('Bad Request');
}

// Vérification configuration
$webhook_secret = ak_stripe_get_webhook_secret($pdo);
if (empty($webhook_secret)) {
    http_response_code(503);
    error_log('[stripe-webhook] webhook_secret non configuré');
    exit('Webhook secret not configured');
}

// ============================================
// 1. Vérification de la signature Stripe (HMAC SHA-256)
// ============================================
function ak_stripe_verify_signature(string $payload, string $sig_header, string $secret, int $tolerance = 300): bool {
    // Format de la signature : t=TIMESTAMP,v1=SIGNATURE,...
    $items = explode(',', $sig_header);
    $timestamp = null;
    $signatures = [];
    foreach ($items as $item) {
        $parts = explode('=', $item, 2);
        if (count($parts) !== 2) continue;
        [$k, $v] = $parts;
        if ($k === 't') $timestamp = (int)$v;
        elseif ($k === 'v1') $signatures[] = $v;
    }

    if (!$timestamp || empty($signatures)) return false;

    // Vérification timestamp (anti-replay : pas plus de 5 min d'écart)
    if (abs(time() - $timestamp) > $tolerance) return false;

    // Calcul de la signature attendue
    $signed_payload = $timestamp . '.' . $payload;
    $expected = hash_hmac('sha256', $signed_payload, $secret);

    // Comparaison sécurisée
    foreach ($signatures as $sig) {
        if (hash_equals($expected, $sig)) return true;
    }
    return false;
}

if (!ak_stripe_verify_signature($payload, $sig_header, $webhook_secret)) {
    http_response_code(400);
    error_log('[stripe-webhook] Signature invalide');
    exit('Invalid signature');
}

// ============================================
// 2. Décoder l'événement
// ============================================
$event = json_decode($payload, true);
if (!is_array($event) || empty($event['id']) || empty($event['type'])) {
    http_response_code(400);
    exit('Invalid event payload');
}

$event_id = (string)$event['id'];
$event_type = (string)$event['type'];
$obj = $event['data']['object'] ?? [];

// Idempotency : si déjà traité, on ignore
try {
    $check = $pdo->prepare("SELECT processing_status FROM asso_stripe_events WHERE stripe_event_id = :eid LIMIT 1");
    $check->execute([':eid' => $event_id]);
    $existing_status = $check->fetchColumn();
    if ($existing_status === 'processed') {
        http_response_code(200);
        echo 'OK (already processed)';
        exit;
    }
} catch (Throwable $e) { /* table peut-être absente */ }

// Extraction de l'org_id depuis metadata si présent
$org_id = null;
if (isset($obj['metadata']['org_id'])) {
    $org_id = (int)$obj['metadata']['org_id'];
} elseif (isset($obj['customer'])) {
    // Chercher org via stripe_customer_id
    try {
        $st = $pdo->prepare("SELECT id FROM organizations WHERE stripe_customer_id = :cid LIMIT 1");
        $st->execute([':cid' => $obj['customer']]);
        $found = $st->fetchColumn();
        if ($found) $org_id = (int)$found;
    } catch (Throwable $e) { /* skip */ }
}

// Log de l'événement (INSERT IGNORE -> la ligne existe désormais en 'pending' si nouvelle)
ak_stripe_log_event($pdo, $event_id, $event_type, $event, $org_id);

// Idempotence ATOMIQUE : on "revendique" l'événement en un seul UPDATE.
// Si une livraison concurrente l'a déjà revendiqué (ou traité), rowCount = 0 -> on sort.
try {
    $claim = $pdo->prepare("UPDATE asso_stripe_events SET processing_status = 'processing'
                            WHERE stripe_event_id = :eid AND processing_status IN ('pending', 'failed')");
    $claim->execute([':eid' => $event_id]);
    if ($claim->rowCount() === 0) {
        http_response_code(200);
        echo 'OK (already claimed)';
        exit;
    }
} catch (Throwable $e) {
    // Table absente / colonne manquante : on ne bloque pas le traitement (best effort)
    error_log('[stripe-webhook] claim skip : ' . $e->getMessage());
}

// ============================================
// 3. Traitement par type d'événement
// ============================================
try {
    switch ($event_type) {

        // -------- SUBSCRIPTION events --------
        case 'customer.subscription.created':
        case 'customer.subscription.updated':
        case 'customer.subscription.deleted':
            ak_handle_subscription_event($pdo, $obj, $event_type);
            break;

        // -------- INVOICE events --------
        case 'invoice.paid':
        case 'invoice.payment_succeeded':
            ak_handle_invoice_paid($pdo, $obj);
            break;

        case 'invoice.payment_failed':
            ak_handle_invoice_failed($pdo, $obj);
            break;

        case 'invoice.created':
        case 'invoice.updated':
        case 'invoice.finalized':
            ak_handle_invoice_upsert($pdo, $obj);
            break;

        // -------- CHECKOUT (au cas où) --------
        case 'checkout.session.completed':
            // Géré par customer.subscription.created en mode subscription
            break;

        default:
            // Ignoré silencieusement
            ak_stripe_mark_event_processed($pdo, $event_id, 'ignored');
            http_response_code(200);
            exit('OK (ignored: ' . $event_type . ')');
    }

    ak_stripe_mark_event_processed($pdo, $event_id, 'processed');
    http_response_code(200);
    echo 'OK';
    exit;

} catch (Throwable $e) {
    error_log('[stripe-webhook] FATAL ' . $event_type . ' : ' . $e->getMessage());
    ak_stripe_mark_event_processed($pdo, $event_id, 'failed', $e->getMessage());
    // On renvoie 500 : l'événement est marqué 'failed' (donc re-revendicable) et Stripe
    // va réessayer plus tard. L'idempotence évite tout double-traitement.
    // (Sur une erreur transitoire — deadlock, DB indispo — c'est ce qui garantit la resync.)
    http_response_code(500);
    echo 'Error logged, will retry';
    exit;
}

// ============================================
// HANDLERS — Traitement spécifique par événement
// ============================================

/**
 * Gère customer.subscription.* (created, updated, deleted)
 */
function ak_handle_subscription_event(PDO $pdo, array $sub, string $event_type): void {
    $stripe_sub_id = $sub['id'] ?? '';
    if (!$stripe_sub_id) return;

    $org_id = (int)($sub['metadata']['org_id'] ?? 0);
    $customer_id = $sub['customer'] ?? '';
    if (!$org_id && $customer_id) {
        $st = $pdo->prepare("SELECT id FROM organizations WHERE stripe_customer_id = :cid LIMIT 1");
        $st->execute([':cid' => $customer_id]);
        $found = $st->fetchColumn();
        if ($found) $org_id = (int)$found;
    }
    if (!$org_id) return;

    $stripe_status = $sub['status'] ?? 'unknown';
    $stripe_price_id = $sub['items']['data'][0]['price']['id'] ?? null;
    $cancel_at_period_end = !empty($sub['cancel_at_period_end']);
    $current_period_end = !empty($sub['current_period_end']) ? date('Y-m-d H:i:s', (int)$sub['current_period_end']) : null;

    // Mapping statut Stripe → statut local
    $local_status = 'pending_payment';
    if ($stripe_status === 'active') $local_status = 'active';
    elseif ($stripe_status === 'trialing') $local_status = 'active';
    elseif ($stripe_status === 'past_due') $local_status = 'overdue';
    elseif ($stripe_status === 'unpaid') $local_status = 'overdue';
    elseif ($stripe_status === 'canceled') $local_status = 'cancelled';
    elseif ($stripe_status === 'incomplete' || $stripe_status === 'incomplete_expired') $local_status = 'pending_payment';

    // Recherche plan_id depuis stripe_price_id
    $plan_id = null;
    if ($stripe_price_id) {
        $sp = $pdo->prepare("SELECT id FROM asso_plans WHERE stripe_price_id_monthly = :p LIMIT 1");
        $sp->execute([':p' => $stripe_price_id]);
        $found = $sp->fetchColumn();
        if ($found) $plan_id = (int)$found;
    }

    // Vérifie si subscription existe déjà
    $check = $pdo->prepare("SELECT id FROM asso_subscriptions WHERE stripe_subscription_id = :sid LIMIT 1");
    $check->execute([':sid' => $stripe_sub_id]);
    $existing_id = $check->fetchColumn();

    if ($existing_id) {
        // UPDATE
        $upd = $pdo->prepare("
            UPDATE asso_subscriptions
            SET status = :st,
                stripe_price_id = :pid,
                cancel_at_period_end = :cape,
                current_period_end = :cpe,
                cancelled_at = :cancelled,
                updated_at = NOW()
            WHERE id = :id
        ");
        $upd->execute([
            ':st' => $local_status,
            ':pid' => $stripe_price_id,
            ':cape' => $cancel_at_period_end ? 1 : 0,
            ':cpe' => $current_period_end,
            ':cancelled' => $local_status === 'cancelled' ? date('Y-m-d H:i:s') : null,
            ':id' => $existing_id,
        ]);
    } else {
        // INSERT
        $ins = $pdo->prepare("
            INSERT INTO asso_subscriptions
                (org_id, plan_id, stripe_subscription_id, stripe_price_id, payment_mode, status,
                 current_period_end, cancel_at_period_end, created_at)
            VALUES
                (:org, :plan, :sid, :pid, 'stripe', :st, :cpe, :cape, NOW())
        ");
        $ins->execute([
            ':org' => $org_id,
            ':plan' => $plan_id,
            ':sid' => $stripe_sub_id,
            ':pid' => $stripe_price_id,
            ':st' => $local_status,
            ':cpe' => $current_period_end,
            ':cape' => $cancel_at_period_end ? 1 : 0,
        ]);
    }

    // Gestion add-on domaine (si présent dans items)
    if (!empty($sub['items']['data'])) {
        foreach ($sub['items']['data'] as $item) {
            $price_id = $item['price']['id'] ?? null;
            $addon_price_id = ak_stripe_config_get($pdo, 'addon_domain_price_id', null);

            if ($price_id && $addon_price_id && $price_id === $addon_price_id) {
                // Insérer ou mettre à jour add-on domaine
                $sub_local_id = (int)($existing_id ?: $pdo->lastInsertId());
                $check_addon = $pdo->prepare("
                    SELECT id FROM asso_subscription_addons
                    WHERE subscription_id = :sid AND addon_type = 'custom_domain' LIMIT 1
                ");
                $check_addon->execute([':sid' => $sub_local_id]);
                $existing_addon = $check_addon->fetchColumn();

                if (!$existing_addon) {
                    $ins_addon = $pdo->prepare("
                        INSERT INTO asso_subscription_addons
                            (subscription_id, org_id, addon_type, addon_name, price_cents_monthly,
                             stripe_price_id, stripe_subscription_item_id, status)
                        VALUES
                            (:sid, :org, 'custom_domain', 'Domaine personnalisé', 1000,
                             :pid, :siid, :status)
                    ");
                    $ins_addon->execute([
                        ':sid' => $sub_local_id,
                        ':org' => $org_id,
                        ':pid' => $price_id,
                        ':siid' => $item['id'] ?? null,
                        ':status' => $local_status === 'cancelled' ? 'cancelled' : 'active',
                    ]);
                }
            }
        }
    }
}

/**
 * Gère invoice.paid : enregistre la facture + active l'abonnement
 */
function ak_handle_invoice_paid(PDO $pdo, array $invoice): void {
    ak_handle_invoice_upsert($pdo, $invoice);

    // Si la facture est liée à une subscription, on s'assure que le status local = active
    $sub_id = $invoice['subscription'] ?? null;
    if ($sub_id) {
        $upd = $pdo->prepare("
            UPDATE asso_subscriptions
            SET status = 'active', updated_at = NOW()
            WHERE stripe_subscription_id = :sid AND status IN ('pending_payment', 'overdue')
        ");
        $upd->execute([':sid' => $sub_id]);
    }
}

/**
 * Gère invoice.payment_failed : passe l'abonnement en pending/overdue
 */
function ak_handle_invoice_failed(PDO $pdo, array $invoice): void {
    ak_handle_invoice_upsert($pdo, $invoice);

    $sub_id = $invoice['subscription'] ?? null;
    if ($sub_id) {
        // Calcul grace period (15 jours par défaut)
        $grace_end = date('Y-m-d H:i:s', strtotime('+15 days'));

        $upd = $pdo->prepare("
            UPDATE asso_subscriptions
            SET status = 'overdue',
                grace_period_end = :ge,
                updated_at = NOW()
            WHERE stripe_subscription_id = :sid
        ");
        $upd->execute([':ge' => $grace_end, ':sid' => $sub_id]);
    }
}

/**
 * INSERT/UPDATE de la facture dans asso_invoices_stripe
 */
function ak_handle_invoice_upsert(PDO $pdo, array $invoice): void {
    $invoice_id = $invoice['id'] ?? '';
    if (!$invoice_id) return;

    // Trouver org via customer
    $customer_id = $invoice['customer'] ?? '';
    $org_id = null;
    if ($customer_id) {
        $st = $pdo->prepare("SELECT id FROM organizations WHERE stripe_customer_id = :cid LIMIT 1");
        $st->execute([':cid' => $customer_id]);
        $found = $st->fetchColumn();
        if ($found) $org_id = (int)$found;
    }
    if (!$org_id) return;

    $data = [
        ':oid' => $org_id,
        ':iid' => $invoice_id,
        ':cid' => $customer_id,
        ':sid' => $invoice['subscription'] ?? null,
        ':num' => $invoice['number'] ?? null,
        ':amt' => (int)($invoice['amount_paid'] ?? $invoice['amount_due'] ?? 0),
        ':tax' => (int)($invoice['tax'] ?? 0),
        ':cur' => strtolower($invoice['currency'] ?? 'eur'),
        ':st'  => $invoice['status'] ?? 'open',
        ':url' => $invoice['hosted_invoice_url'] ?? null,
        ':pdf' => $invoice['invoice_pdf'] ?? null,
        ':paid' => !empty($invoice['status_transitions']['paid_at']) ? date('Y-m-d H:i:s', (int)$invoice['status_transitions']['paid_at']) : null,
        ':due' => !empty($invoice['due_date']) ? date('Y-m-d', (int)$invoice['due_date']) : null,
        ':ps' => !empty($invoice['period_start']) ? date('Y-m-d H:i:s', (int)$invoice['period_start']) : null,
        ':pe' => !empty($invoice['period_end']) ? date('Y-m-d H:i:s', (int)$invoice['period_end']) : null,
        ':desc' => $invoice['description'] ?? null,
    ];

    $sql = "
        INSERT INTO asso_invoices_stripe
            (org_id, stripe_invoice_id, stripe_customer_id, stripe_subscription_id,
             invoice_number, amount_cents, tax_cents, currency, status,
             hosted_invoice_url, invoice_pdf_url, paid_at, due_date,
             period_start, period_end, description)
        VALUES
            (:oid, :iid, :cid, :sid, :num, :amt, :tax, :cur, :st,
             :url, :pdf, :paid, :due, :ps, :pe, :desc)
        ON DUPLICATE KEY UPDATE
            status = VALUES(status),
            amount_cents = VALUES(amount_cents),
            hosted_invoice_url = VALUES(hosted_invoice_url),
            invoice_pdf_url = VALUES(invoice_pdf_url),
            paid_at = VALUES(paid_at),
            updated_at = NOW()
    ";
    $st = $pdo->prepare($sql);
    $st->execute($data);
}
