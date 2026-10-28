<?php
/**
 * stripe-webhook.php
 * --------------------------------------------------------------
 * Webhook Stripe — Endpoint qui reçoit les événements Stripe et
 * synchronise la base locale (stripe-sync-helpers.php).
 *
 * URL (créée par « Configurer Stripe automatiquement », fondateur-stripe-config.php) :
 *   https://assokit.fr/stripe-webhook   (sans www ni .php : une redirection compte comme un échec)
 *
 * Événements traités : AK_STRIPE_WEBHOOK_EVENTS (stripe-config-helpers.php), la même liste que
 * celle de l'abonnement du point de terminaison :
 *   - customer.subscription.created / updated / deleted → l'abonnement est RELU chez Stripe puis
 *     appliqué (un événement en retard ou rejoué n'écrase jamais un état plus récent) : ligne
 *     asso_subscriptions créée au premier paiement, fin de l'essai, résiliation, add-on domaine ;
 *   - invoice.paid → facture enregistrée, abonnement actif (relu chez Stripe s'il n'est pas encore connu) ;
 *   - invoice.payment_failed / invoice.payment_action_required → échéance refusée : « en retard »,
 *     15 jours de tolérance, e-mail à l'association, alerte au Fondateur ;
 *   - invoice.created / finalized / updated / voided / marked_uncollectible → facture enregistrée ;
 *   - customer.updated → nouvelle carte du portail appliquée aux abonnements, échéance impayée relancée ;
 *   - customer.deleted → identifiant client effacé (le prochain paiement en recrée un) ;
 *   - charge.refunded / charge.dispute.created → alerte au Fondateur.
 *
 * Sécurité :
 *   - signature Stripe (HMAC SHA-256, 5 minutes de tolérance) avec le secret du point de terminaison
 *     (mode en cours, ou secret test / réel gardé par la configuration automatique) ;
 *   - un événement d'un autre mode que les clés en place (test reçu après le passage en réel) est ignoré ;
 *   - idempotence : un événement traité ne l'est jamais deux fois ; un événement resté « processing »
 *     plus de 5 minutes (script coupé) est repris à la livraison suivante ;
 *   - journal dans asso_stripe_events.
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
// Ligne courante, plan payant / d'essai, libellé organizations.plan : mêmes règles que le reste du site
require_once __DIR__ . '/trial-helpers.php';
require_once __DIR__ . '/stripe-sync-helpers.php';

header('Content-Type: text/plain; charset=utf-8');

// Récupération du payload brut
$payload = file_get_contents('php://input');
$sig_header = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';

if (empty($payload) || empty($sig_header)) {
    http_response_code(400);
    error_log('[stripe-webhook] Payload ou signature manquant');
    exit('Bad Request');
}

// Secrets acceptés : celui du point de terminaison en place, et ceux gardés par mode (test / réel)
// par la configuration automatique, pour reconnaître (puis ignorer) un événement de test après le
// passage en mode réel au lieu de le refuser (Stripe désactiverait l'ancien point de terminaison).
$secrets = [];
foreach (['stripe_webhook_secret', 'stripe_webhook_secret_test', 'stripe_webhook_secret_live'] as $k) {
    $v = trim((string)ak_stripe_config_get($pdo, $k, ''));
    if ($v !== '' && !in_array($v, $secrets, true)) $secrets[] = $v;
}
if (!$secrets) {
    http_response_code(503);
    error_log('[stripe-webhook] webhook_secret non configuré');
    exit('Webhook secret not configured');
}

// ============================================
// 1. Vérification de la signature Stripe (HMAC SHA-256)
// ============================================
function ak_stripe_verify_signature(string $payload, string $sig_header, string $secret, int $tolerance = 300): bool {
    // Format de la signature : t=TIMESTAMP,v1=SIGNATURE,...
    $timestamp = null;
    $signatures = [];
    foreach (explode(',', $sig_header) as $item) {
        $parts = explode('=', trim($item), 2);
        if (count($parts) !== 2) continue;
        [$k, $v] = $parts;
        if ($k === 't') $timestamp = (int)$v;
        elseif ($k === 'v1') $signatures[] = $v;
    }
    if (!$timestamp || empty($signatures)) return false;

    // Anti-rejeu : pas plus de 5 min d'écart
    if (abs(time() - $timestamp) > $tolerance) return false;

    $expected = hash_hmac('sha256', $timestamp . '.' . $payload, $secret);
    foreach ($signatures as $sig) {
        if (hash_equals($expected, $sig)) return true;
    }
    return false;
}

$valid = false;
foreach ($secrets as $secret) {
    if (ak_stripe_verify_signature($payload, $sig_header, $secret)) { $valid = true; break; }
}
if (!$valid) {
    http_response_code(400);
    error_log('[stripe-webhook] Signature invalide ou périmée');
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
if (!is_array($obj)) $obj = [];

// Idempotence : déjà traité (ou ignoré) → rien à refaire
try {
    $check = $pdo->prepare("SELECT processing_status FROM asso_stripe_events WHERE stripe_event_id = :eid LIMIT 1");
    $check->execute([':eid' => $event_id]);
    $existing_status = $check->fetchColumn();
    if (in_array($existing_status, ['processed', 'ignored'], true)) {
        http_response_code(200);
        echo 'OK (already processed)';
        exit;
    }
} catch (Throwable $e) { /* table peut-être absente */ }

// Association concernée (journal)
$org_id = null;
if (isset($obj['metadata']['org_id'])) {
    $org_id = (int)$obj['metadata']['org_id'];
} elseif (isset($obj['customer']) || ($obj['object'] ?? '') === 'customer') {
    $found = ak_stripe_org_by_customer($pdo, ($obj['object'] ?? '') === 'customer' ? ($obj['id'] ?? '') : $obj['customer']);
    if ($found) $org_id = $found;
}

// Log de l'événement (INSERT IGNORE -> la ligne existe désormais en 'pending' si nouvelle)
ak_stripe_log_event($pdo, $event_id, $event_type, $event, $org_id);

// Événement d'un autre mode que les clés en place (événement de test reçu après le passage en mode
// réel, ou l'inverse) : jamais appliqué. 200 pour que Stripe ne le renvoie pas.
if (array_key_exists('livemode', $event) && ((bool)$event['livemode']) !== (ak_stripe_get_mode($pdo) === 'live')) {
    ak_stripe_mark_event_processed($pdo, $event_id, 'ignored', 'mode ' . ($event['livemode'] ? 'réel' : 'test') . ' différent des clés en place');
    http_response_code(200);
    exit('OK (ignored: other mode)');
}

// Idempotence ATOMIQUE : on « revendique » l'événement en un seul UPDATE. Une livraison concurrente
// qui l'a déjà revendiqué → rowCount = 0 → on sort. Un événement resté « processing » plus de
// 5 minutes (script coupé par une erreur fatale, une limite de temps ou le serveur) est repris :
// sans cela, Stripe recevait « 200 » à chaque nouvelle livraison et l'événement était perdu.
try {
    $ev_cols = ak_db_columns($pdo, 'asso_stripe_events');
    $has_claim = isset($ev_cols['claimed_at']);
    $claim = $pdo->prepare("UPDATE asso_stripe_events SET processing_status = 'processing'" . ($has_claim ? ", claimed_at = NOW()" : "") . "
                            WHERE stripe_event_id = :eid
                              AND (processing_status IN ('pending', 'failed')"
                            . ($has_claim ? " OR (processing_status = 'processing' AND (claimed_at IS NULL OR claimed_at < NOW() - INTERVAL 5 MINUTE))" : "") . ")");
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

        // -------- Abonnements : relus chez Stripe, puis appliqués --------
        case 'customer.subscription.created':
        case 'customer.subscription.updated':
        case 'customer.subscription.deleted':
            ak_stripe_sync_subscription($pdo, (string)($obj['id'] ?? ''), $obj, $event_type);
            break;

        // -------- Factures --------
        case 'invoice.paid':
        case 'invoice.payment_succeeded':   // doublon d'invoice.paid (ancien réglage) : sans effet de plus
            ak_handle_invoice_paid($pdo, $obj);
            break;

        case 'invoice.payment_failed':
            ak_handle_invoice_failed($pdo, $obj);
            break;

        case 'invoice.payment_action_required':
            ak_handle_invoice_failed($pdo, $obj, true);
            break;

        case 'invoice.created':
        case 'invoice.updated':
        case 'invoice.finalized':
        case 'invoice.voided':
        case 'invoice.marked_uncollectible':
            // Facture annulée (paiement abandonné : incomplete_expired) : plus de lien « Régulariser » vers elle
            ak_handle_invoice_upsert($pdo, $obj);
            break;

        // -------- Client Stripe --------
        case 'customer.updated':
            ak_handle_customer_updated($pdo, $obj, is_array($event['data']['previous_attributes'] ?? null) ? $event['data']['previous_attributes'] : []);
            break;

        case 'customer.deleted':
            ak_handle_customer_deleted($pdo, $obj);
            break;

        // -------- Remboursements et litiges : alerte au Fondateur --------
        case 'charge.refunded':
        case 'charge.dispute.created':
            ak_handle_charge_event($pdo, $obj, $event_type);
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
    ak_stripe_mark_event_processed($pdo, $event_id, 'failed', mb_substr($e->getMessage(), 0, 1000));
    // On renvoie 500 : l'événement est marqué 'failed' (donc re-revendicable) et Stripe
    // va réessayer plus tard. L'idempotence évite tout double-traitement.
    http_response_code(500);
    echo 'Error logged, will retry';
    exit;
}
