<?php
/**
 * stripe-connect-webhook.php
 * --------------------------------------------------------------
 * Webhook Stripe CONNECT : événements des comptes Stripe des associations (paiements en ligne de leurs
 * factures, cotisations et dons). Point de terminaison distinct de /stripe-webhook (abonnements Assokit),
 * créé avec connect=true par « Configurer Stripe automatiquement » (stripe-setup-helpers.php) :
 *   https://assokit.fr/stripe-connect-webhook
 *
 * Événements traités : AK_STRIPE_CONNECT_WEBHOOK_EVENTS (stripe-connect-helpers.php) :
 *   - checkout.session.completed → payé (carte) ou prélèvement SEPA en cours ;
 *   - checkout.session.async_payment_succeeded / _failed → prélèvement SEPA confirmé / rejeté ;
 *   - checkout.session.expired → page de paiement abandonnée ;
 *   - charge.refunded → paiement remboursé par l'association (annulé côté Assokit) ;
 *   - charge.dispute.created → contestation : l'association est prévenue ;
 *   - account.updated → état du compte relu chez Stripe (inscription terminée, informations demandées) ;
 *   - account.application.deauthorized → l'association a retiré l'accès d'Assokit.
 *
 * Sécurité :
 *   - signature Stripe (secret du point de terminaison Connect, par mode : connect_webhook_secret_test / _live) ;
 *   - chaque événement doit porter « account » (compte connecté) ; l'association est retrouvée par ce compte
 *     (org_stripe_accounts), jamais par la seule métadonnée ;
 *   - événement d'un autre mode que les clés en place : ignoré ;
 *   - idempotence et reprise : même journal que les abonnements (asso_stripe_events, colonne connect_account).
 * Accessible SANS authentification (Stripe ne se connecte pas).
 * --------------------------------------------------------------
 */

ini_set('session.use_cookies', 0);

require_once __DIR__ . '/config.php';
@require_once __DIR__ . '/stripe-config-helpers.php';
@require_once __DIR__ . '/stripe-helpers.php';
require_once __DIR__ . '/trial-helpers.php';
require_once __DIR__ . '/stripe-sync-helpers.php';
require_once __DIR__ . '/stripe-connect-helpers.php';

header('Content-Type: text/plain; charset=utf-8');

$payload = file_get_contents('php://input');
$sig_header = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';
if (empty($payload) || empty($sig_header)) {
    http_response_code(400);
    exit('Bad Request');
}

// Secrets du point de terminaison Connect (un par mode, gardés par la configuration automatique)
$secrets = [];
foreach (['connect_webhook_secret_test', 'connect_webhook_secret_live'] as $k) {
    $v = trim((string)ak_stripe_config_get($pdo, $k, ''));
    if ($v !== '' && !in_array($v, $secrets, true)) $secrets[] = $v;
}
if (!$secrets) {
    http_response_code(503);
    error_log('[stripe-connect-webhook] secret non configuré');
    exit('Webhook secret not configured');
}
$valid = false;
foreach ($secrets as $secret) {
    if (ak_stripe_verify_signature($payload, $sig_header, $secret)) { $valid = true; break; }
}
if (!$valid) {
    http_response_code(400);
    error_log('[stripe-connect-webhook] Signature invalide ou périmée');
    exit('Invalid signature');
}

$event = json_decode($payload, true);
if (!is_array($event) || empty($event['id']) || empty($event['type'])) {
    http_response_code(400);
    exit('Invalid event payload');
}
$event_id = (string)$event['id'];
$event_type = (string)$event['type'];
$acct = (string)($event['account'] ?? '');
$obj = $event['data']['object'] ?? [];
if (!is_array($obj)) $obj = [];

// Un événement sans compte connecté n'a rien à faire ici (abonnements : /stripe-webhook)
if (!preg_match('/^acct_[A-Za-z0-9]+$/', $acct)) {
    http_response_code(200);
    exit('OK (ignored: not a connected account event)');
}

try {
    $check = $pdo->prepare("SELECT processing_status FROM asso_stripe_events WHERE stripe_event_id = :eid LIMIT 1");
    $check->execute([':eid' => $event_id]);
    if (in_array($check->fetchColumn(), ['processed', 'ignored'], true)) {
        http_response_code(200);
        exit('OK (already processed)');
    }
} catch (Throwable $e) { /* table absente */ }

$row = ak_connect_account_by_stripe_id($pdo, $acct);
$org_id = $row ? (int)$row['org_id'] : null;
ak_stripe_log_event($pdo, $event_id, $event_type, $event, $org_id);
try {
    if (isset(ak_db_columns($pdo, 'asso_stripe_events')['connect_account'])) {
        $pdo->prepare("UPDATE asso_stripe_events SET connect_account = ? WHERE stripe_event_id = ?")->execute([$acct, $event_id]);
    }
} catch (Throwable $e) {}

if (array_key_exists('livemode', $event) && ((bool)$event['livemode']) !== (ak_stripe_get_mode($pdo) === 'live')) {
    ak_stripe_mark_event_processed($pdo, $event_id, 'ignored', 'mode ' . ($event['livemode'] ? 'réel' : 'test') . ' différent des clés en place');
    http_response_code(200);
    exit('OK (ignored: other mode)');
}
// Compte inconnu d'Assokit (association supprimée, compte relié ailleurs) : rien à appliquer
if (!$row) {
    ak_stripe_mark_event_processed($pdo, $event_id, 'ignored', 'compte connecté inconnu');
    http_response_code(200);
    exit('OK (ignored: unknown account)');
}

// Revendication atomique (comme stripe-webhook.php) : jamais deux traitements simultanés du même événement
try {
    $claim = $pdo->prepare("UPDATE asso_stripe_events SET processing_status = 'processing', claimed_at = NOW()
                            WHERE stripe_event_id = :eid
                              AND (processing_status IN ('pending', 'failed')
                                   OR (processing_status = 'processing' AND (claimed_at IS NULL OR claimed_at < NOW() - INTERVAL 5 MINUTE)))");
    $claim->execute([':eid' => $event_id]);
    if ($claim->rowCount() === 0) {
        http_response_code(200);
        exit('OK (already claimed)');
    }
} catch (Throwable $e) {
    error_log('[stripe-connect-webhook] claim skip : ' . $e->getMessage());
}

try {
    switch ($event_type) {
        case 'checkout.session.completed':
            ak_connect_apply_session($pdo, $acct, $obj, 'completed');
            break;
        case 'checkout.session.async_payment_succeeded':
            ak_connect_apply_session($pdo, $acct, $obj, 'async_succeeded');
            break;
        case 'checkout.session.async_payment_failed':
            ak_connect_apply_session($pdo, $acct, $obj, 'async_failed');
            break;
        case 'checkout.session.expired':
            ak_connect_apply_session($pdo, $acct, $obj, 'expired');
            break;
        case 'charge.refunded':
            ak_connect_handle_refund($pdo, $acct, $obj);
            break;
        case 'charge.dispute.created':
            ak_connect_handle_dispute($pdo, $acct, $obj);
            break;
        case 'account.updated':
            ak_connect_refresh_account($pdo, $acct);
            break;
        case 'account.application.deauthorized':
            ak_connect_handle_deauthorized($pdo, $acct);
            break;
        default:
            ak_stripe_mark_event_processed($pdo, $event_id, 'ignored');
            http_response_code(200);
            exit('OK (ignored: ' . $event_type . ')');
    }
    ak_stripe_mark_event_processed($pdo, $event_id, 'processed');
    http_response_code(200);
    echo 'OK';
} catch (Throwable $e) {
    error_log('[stripe-connect-webhook] ' . $event_type . ' : ' . $e->getMessage());
    ak_stripe_mark_event_processed($pdo, $event_id, 'failed', mb_substr($e->getMessage(), 0, 1000));
    http_response_code(500);
    echo 'Error logged, will retry';
}
