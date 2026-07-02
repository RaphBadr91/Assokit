<?php
/**
 * stripe-create-payment-intent.php
 * --------------------------------------------------------------
 * Endpoint AJAX appelé par mon-asso-paiement.php pour créer
 * une subscription Stripe + récupérer le client_secret pour
 * que le formulaire Stripe Element puisse confirmer le paiement.
 *
 * Flow :
 *   1. Récupère/crée le Customer Stripe (lié à l'org)
 *   2. Crée la Subscription Stripe (avec add-on optionnel)
 *   3. Récupère le client_secret du PaymentIntent associé
 *   4. Retourne { clientSecret } au JavaScript
 *
 * Sécurité :
 *   - Authentification obligatoire
 *   - Vérification CSRF via X-Requested-With
 *   - Réponse JSON uniquement
 * --------------------------------------------------------------
 */
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/config.php';
@require_once __DIR__ . '/stripe-config-helpers.php';
@require_once __DIR__ . '/stripe-helpers.php';

// Vérification AJAX
if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'XMLHttpRequest') {
    http_response_code(403);
    echo json_encode(['error' => 'Requête non autorisée']);
    exit;
}

// Vérification login
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Non authentifié']);
    exit;
}

// Récupération user
$user_id = (int)$_SESSION['user_id'];
try {
    $st = $pdo->prepare("SELECT u.*, o.id AS org_id, o.name AS org_name, o.stripe_customer_id, o.billing_email FROM users u LEFT JOIN organizations o ON o.id = u.org_id WHERE u.id = :id LIMIT 1");
    $st->execute([':id' => $user_id]);
    $user = $st->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Erreur BDD']);
    exit;
}

if (!$user || empty($user['org_id'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Organisation introuvable']);
    exit;
}
$org_id = (int)$user['org_id'];

// Vérification Stripe configuré
if (!ak_stripe_is_configured($pdo)) {
    http_response_code(503);
    echo json_encode(['error' => 'Stripe n\'est pas encore activé. Contactez-nous.']);
    exit;
}

// Récupération payload JSON
$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body)) {
    http_response_code(400);
    echo json_encode(['error' => 'Payload invalide']);
    exit;
}

$plan_slug = (string)($body['plan_slug'] ?? '');
$with_addon_domain = !empty($body['with_addon_domain']);

// Récupération du plan + son stripe_price_id
try {
    $st = $pdo->prepare("SELECT * FROM asso_plans WHERE slug = :s AND is_visible = 1 LIMIT 1");
    $st->execute([':s' => $plan_slug]);
    $plan = $st->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Erreur BDD plan']);
    exit;
}

if (!$plan) {
    echo json_encode(['error' => 'Plan introuvable']);
    exit;
}

$plan_price_id = $plan['stripe_price_id_monthly'] ?? null;
if (empty($plan_price_id)) {
    echo json_encode(['error' => 'Le price_id Stripe de ce plan n\'est pas configuré. Contactez l\'administrateur.']);
    exit;
}

// Récupération add-on price_id si demandé
$addon_price_id = null;
if ($with_addon_domain) {
    $addon_price_id = ak_stripe_config_get($pdo, 'addon_domain_price_id', null);
    if (empty($addon_price_id)) {
        echo json_encode(['error' => 'Le price_id de l\'add-on domaine n\'est pas configuré.']);
        exit;
    }
}

try {
    // ============================================
    // 1. Récupération ou création du Stripe Customer
    // ============================================
    $customer_id = $user['stripe_customer_id'] ?? null;

    if (empty($customer_id)) {
        // Créer un nouveau customer
        $customer_data = [
            'email' => $user['billing_email'] ?: $user['email'],
            'name' => $user['org_name'] ?: ($user['first_name'] . ' ' . $user['last_name']),
            'metadata[org_id]' => (string)$org_id,
            'metadata[user_id]' => (string)$user_id,
        ];

        $resp = ak_stripe_api_call($pdo, 'POST', '/customers', $customer_data);
        if (!$resp['ok']) {
            echo json_encode(['error' => 'Erreur création Stripe Customer : ' . $resp['error']]);
            exit;
        }

        $customer_id = $resp['data']['id'];

        // Sauvegarder dans organizations
        $up = $pdo->prepare("UPDATE organizations SET stripe_customer_id = :cid WHERE id = :id");
        $up->execute([':cid' => $customer_id, ':id' => $org_id]);
    }

    // ============================================
    // 2. Création de la Subscription
    // ============================================
    $sub_data = [
        'customer' => $customer_id,
        'items[0][price]' => $plan_price_id,
        'payment_behavior' => 'default_incomplete',
        'payment_settings[save_default_payment_method]' => 'on_subscription',
        'expand[]' => 'latest_invoice.payment_intent',
        'metadata[org_id]' => (string)$org_id,
        'metadata[user_id]' => (string)$user_id,
        'metadata[plan_slug]' => $plan_slug,
        'metadata[with_addon_domain]' => $with_addon_domain ? '1' : '0',
    ];

    // Ajout add-on si demandé
    if ($addon_price_id) {
        $sub_data['items[1][price]'] = $addon_price_id;
    }

    $resp = ak_stripe_api_call($pdo, 'POST', '/subscriptions', $sub_data);
    if (!$resp['ok']) {
        echo json_encode(['error' => 'Erreur création abonnement Stripe : ' . $resp['error']]);
        exit;
    }

    $subscription = $resp['data'];

    // Récupération du client_secret
    $client_secret = $subscription['latest_invoice']['payment_intent']['client_secret'] ?? null;
    if (empty($client_secret)) {
        echo json_encode(['error' => 'Impossible de récupérer le client_secret']);
        exit;
    }

    // ============================================
    // 3. Pré-enregistrement local de la subscription (en attente de webhook)
    // ============================================
    try {
        $check = $pdo->prepare("SELECT id FROM asso_subscriptions WHERE stripe_subscription_id = :sid LIMIT 1");
        $check->execute([':sid' => $subscription['id']]);
        $existing = $check->fetchColumn();

        if (!$existing) {
            $ins = $pdo->prepare("
                INSERT INTO asso_subscriptions
                    (org_id, plan_id, stripe_subscription_id, stripe_price_id, payment_mode, status, created_at)
                VALUES
                    (:org, :plan, :sid, :pid, 'stripe', 'pending_payment', NOW())
            ");
            $ins->execute([
                ':org' => $org_id,
                ':plan' => (int)$plan['id'],
                ':sid' => $subscription['id'],
                ':pid' => $plan_price_id,
            ]);
        }
    } catch (Throwable $e) {
        // Ne pas bloquer si l'insert local échoue, le webhook fera le job
        error_log('[stripe-create-payment-intent] Pré-insert: ' . $e->getMessage());
    }

    // Réponse JSON
    echo json_encode([
        'clientSecret' => $client_secret,
        'subscriptionId' => $subscription['id'],
        'customerId' => $customer_id,
    ]);
    exit;

} catch (Throwable $e) {
    http_response_code(500);
    error_log('[stripe-create-payment-intent] FATAL: ' . $e->getMessage());
    echo json_encode(['error' => 'Erreur serveur : ' . $e->getMessage()]);
    exit;
}
