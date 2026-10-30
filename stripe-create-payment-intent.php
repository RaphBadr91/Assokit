<?php
/**
 * stripe-create-payment-intent.php
 * --------------------------------------------------------------
 * Endpoint AJAX appelé par mon-asso-paiement.php pour créer
 * une subscription Stripe + récupérer le client_secret pour
 * que le formulaire Stripe Element puisse confirmer le paiement.
 *
 * Flow :
 *   1. Récupère/crée le Customer Stripe (lié à l'org), avec adresse de facturation, langue
 *      française et mentions du pied de facture (ak_stripe_ensure_customer)
 *   2. Relit chez Stripe les abonnements du client : déjà payé → 409 (et appliqué ici) ;
 *      prélèvement SEPA en cours de vérification → 409 ; tentative en attente de paiement
 *      pour le même contenu → reprise (même client_secret), sinon annulée
 *   3. Crée la Subscription Stripe (avec add-on optionnel), prix HT + TVA de la plateforme
 *      (default_tax_rates, ak_stripe_vat_tax_rate) ; moyens de paiement = ceux de la page
 *      (payment_settings[payment_method_types]) ; clé d'idempotence (double clic)
 *      Association en essai (ak_stripe_trial_end_for_org : essai en cours, fin à 48 h au moins) : le
 *      premier prélèvement attend la fin de l'essai. Période d'essai Stripe jusqu'à cette date
 *      (trial_end), la carte ou le mandat SEPA est seulement enregistré (pending_setup_intent),
 *      abonnement arrêté par Stripe à la fin de l'essai si rien n'a été enregistré
 *      (trial_settings missing_payment_method=cancel)
 *   4. Retourne { clientSecret, mode: 'payment' } (premier mois prélevé maintenant, stripe.confirmPayment)
 *      ou { clientSecret, mode: 'setup', trialEnd } (essai : stripe.confirmSetup) au JavaScript
 *   (la ligne asso_subscriptions naît au premier paiement réussi : page de retour ou webhook ; pendant
 *   l'essai, « carte enregistrée » est noté sur l'association, organizations.stripe_trial_*)
 *
 * Sécurité :
 *   - Authentification obligatoire et session vérifiée (ak_session_guard : compte désactivé ou
 *     supprimé, association fermée ou en attente de validation → 401 JSON), administrateurs de
 *     l'association (comme la page)
 *   - Paiement refusé (ak_stripe_payment_refusal, même règle que la page) : association suspendue par le
 *     support, abonnement en cours réglé sur facture, mode test hors des associations de test
 *   - 409 si un abonnement par carte est déjà en vigueur, ici ou chez Stripe (jamais deux abonnements) ;
 *     étapes 1 à 3 sous un verrou par association (deux administrateurs, deux onglets : un seul abonnement)
 *   - Prix Stripe vérifié (montant HT égal à celui affiché) avant toute création
 *   - Vérification CSRF via X-Requested-With
 *   - Réponse JSON uniquement ; jamais le message brut de Stripe (journal PHP seulement)
 * --------------------------------------------------------------
 */
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/app-context.php';
@require_once __DIR__ . '/stripe-config-helpers.php';
@require_once __DIR__ . '/stripe-helpers.php';
// Colonnes réelles d'asso_subscriptions (ak_db_columns) pour ak_org_live_stripe_subscription
require_once __DIR__ . '/trial-helpers.php';
// Client Stripe, abonnement déjà payé chez Stripe appliqué tout de suite (ak_stripe_sync_subscription)
require_once __DIR__ . '/stripe-sync-helpers.php';
// Montant HT de l'option domaine (AK_ADDON_DOMAIN_HT_CENTS), vérifié contre le prix Stripe
if (!defined('AK_ADDON_DOMAIN_HT_CENTS')) require_once __DIR__ . '/includes-pricing.php';

// Aucun paiement ne peut naître d'une requête de l'app : masquer le bouton ne
// suffirait pas, l'endpoint lui-même doit refuser (Apple 3.1.1).
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (ak_billing_hidden()) {
    http_response_code(403);
    echo json_encode(['error' => 'Paiement indisponible ici. Rendez-vous sur assokit.fr.']);
    exit;
}

// Vérification AJAX
if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'XMLHttpRequest') {
    http_response_code(403);
    echo json_encode(['error' => 'Requête non autorisée']);
    exit;
}

// Vérification login, puis session encore valable (compte désactivé ou supprimé, association fermée,
// supprimée ou en attente de validation : 401 JSON, comme les autres API ; ak_guard_is_api : X-Requested-With)
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
ak_session_guard();
if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Non authentifié']);
    exit;
}

// Récupération user
$user_id = (int)$_SESSION['user_id'];
try {
    $st = $pdo->prepare("SELECT u.*, o.id AS org_id, o.name AS org_name, o.stripe_customer_id, o.billing_email FROM users u
                         LEFT JOIN organizations o ON o.id = u.org_id AND o.deleted_at IS NULL AND COALESCE(o.status, '') <> 'cancelled'
                         WHERE u.id = :id AND COALESCE(u.is_active, 1) = 1 AND u.deleted_at IS NULL LIMIT 1");
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

// Même accès que la page de paiement (mon-asso-paiement) : administrateurs de l'association seulement.
// Un membre ne crée ni client Stripe ni abonnement pour toute l'association.
require_once __DIR__ . '/finance-permissions.php';
if (!user_can_view_finances($user)) {
    http_response_code(403);
    echo json_encode(['error' => 'Le paiement de l’abonnement est réservé aux administrateurs de l’association.']);
    exit;
}

// Abonnement par carte déjà en vigueur (actif, en retard ou résiliation programmée) : jamais un second
// abonnement (double prélèvement). Même règle que la page de paiement, qui n'affiche alors pas le formulaire.
if (function_exists('ak_org_live_stripe_subscription') && ak_org_live_stripe_subscription($pdo, $org_id)) {
    http_response_code(409);
    echo json_encode(['error' => 'Votre association a déjà un abonnement par carte en cours : gérez-le depuis Mon abonnement.']);
    exit;
}

// Abonnement déjà souscrit pendant l'essai (carte enregistrée, premier prélèvement à la fin de l'essai)
$trial_card = function_exists('ak_org_trial_card') ? ak_org_trial_card($pdo, $org_id) : null;
if ($trial_card) {
    http_response_code(409);
    echo json_encode(['error' => 'Votre association a déjà souscrit son abonnement' . ($trial_card['charge_fr'] !== '' ? ' : premier prélèvement le ' . $trial_card['charge_fr'] . ', à la fin de votre essai' : '') . '. Gérez-le depuis Mon abonnement.', 'redirect' => '/mon-asso-plan']);
    exit;
}

// Vérification Stripe configuré
if (!ak_stripe_is_configured($pdo)) {
    http_response_code(503);
    echo json_encode(['error' => 'Stripe n\'est pas encore activé. Contactez-nous.']);
    exit;
}

// Paiement impossible pour cette association (même règle et même message que la page de paiement)
if ($refusal = ak_stripe_payment_refusal($pdo, $org_id)) {
    http_response_code($refusal['code']);
    echo json_encode(['error' => $refusal['message'], 'redirect' => '/mon-asso-plan']);
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
    http_response_code(404);
    echo json_encode(['error' => 'Plan introuvable']);
    exit;
}

// Seules les formules payantes se souscrivent par carte : ni Démarrage (plus proposée), ni l'essai,
// ni Sur-mesure (sur devis)
if ((int)($plan['price_cents'] ?? 0) <= 0 || ($plan['slug'] ?? '') === 'sur-mesure' || !empty($plan['is_custom_quote'])
    || !empty($plan['is_trial']) || ($plan['slug'] ?? '') === 'pro-essai') {
    http_response_code(400);
    echo json_encode(['error' => 'Cette formule ne se souscrit pas en ligne. Choisissez Assokit, ou contactez-nous pour le Sur-mesure.']);
    exit;
}

$plan_price_id = $plan['stripe_price_id_monthly'] ?? null;
if (empty($plan_price_id)) {
    http_response_code(503);
    echo json_encode(['error' => 'Le paiement en ligne de cette formule n’est pas encore prêt. Contactez-nous : nous activons votre abonnement.']);
    exit;
}
// Le prix Stripe doit être EXACTEMENT celui affiché sur la page (prix HT modifié dans « Plans tarifaires »
// sans relancer « Configurer Stripe automatiquement » : le client paierait l'ancien montant)
$unavailable = 'Le paiement en ligne est momentanément indisponible. Aucun montant n’a été prélevé. Réessayez dans quelques minutes ou contactez-nous.';
if ($why = ak_stripe_price_problem($pdo, (string)$plan_price_id, (int)$plan['price_cents'])) {
    error_log('[stripe-create-payment-intent] prix Stripe ' . $plan_price_id . ' de la formule ' . $plan_slug . ' : ' . $why);
    ak_stripe_once($pdo, 0, 'prix:' . $plan_price_id . ':' . (int)$plan['price_cents'] . ':' . date('Ymd'), function () use ($pdo, $plan, $why) {
        ak_stripe_notify_founder($pdo, '⚠️ Paiement bloqué : prix Stripe à mettre à jour',
            '<p>Une association a voulu souscrire la formule « ' . ak_stripe_h(ak_plan_label($plan['slug'] ?? '', $plan['name'] ?? null)) . ' », mais son prix Stripe ne correspond plus au prix affiché ('
            . ak_stripe_h(ak_format_price_cents((int)$plan['price_cents'])) . ' HT) : ' . ak_stripe_h($why) . '</p>'
            . '<p>Le paiement a été refusé (aucun montant prélevé). Cliquez sur « Configurer Stripe automatiquement » dans Configuration Stripe pour mettre le prix à jour.</p>', null);
    });
    http_response_code(503);
    echo json_encode(['error' => $unavailable]);
    exit;
}

// Récupération add-on price_id si demandé
$addon_price_id = null;
if ($with_addon_domain) {
    $addon_price_id = ak_stripe_config_get($pdo, 'addon_domain_price_id', null);
    if (empty($addon_price_id)) {
        http_response_code(503);
        echo json_encode(['error' => 'L’option Domaine personnalisé n’est pas encore disponible en ligne. Payez sans l’option, ou contactez-nous.']);
        exit;
    }
    if ($why = ak_stripe_price_problem($pdo, (string)$addon_price_id, AK_ADDON_DOMAIN_HT_CENTS)) {
        error_log('[stripe-create-payment-intent] prix Stripe de l’option domaine ' . $addon_price_id . ' : ' . $why);
        http_response_code(503);
        echo json_encode(['error' => 'L’option Domaine personnalisé est momentanément indisponible en ligne. Payez sans l’option, ou contactez-nous. Aucun montant n’a été prélevé.']);
        exit;
    }
}

// TVA : les prix sont HT, Stripe ajoute la TVA de la plateforme (ak_vat_applicable_rate, le taux affiché
// sur la page de paiement) à chaque échéance. Sans taux Stripe, aucun abonnement : jamais de prélèvement
// sans la TVA annoncée. TVA non applicable (art. 293 B) : pas de taux.
$vat_rate = function_exists('ak_vat_applicable_rate') ? ak_vat_applicable_rate($pdo) : 0.0;
$tax_rate_id = null;
if ($vat_rate > 0) {
    $tax_err = null;
    $tax_rate_id = ak_stripe_vat_tax_rate($pdo, $vat_rate, $tax_err);
    if (!$tax_rate_id) {
        error_log('[stripe-create-payment-intent] org #' . $org_id . ' TVA Stripe indisponible : ' . $tax_err);
        http_response_code(502);
        echo json_encode(['error' => 'Le paiement est momentanément indisponible : la TVA n’a pas pu être préparée chez notre prestataire de paiement. Aucun montant n’a été prélevé. Réessayez dans quelques minutes ou contactez-nous.']);
        exit;
    }
}

// Moyens de paiement : ceux relevés par « Configurer Stripe automatiquement » (carte, et prélèvement SEPA
// s'il est activé sur le compte). La page de paiement propose exactement les mêmes (paymentMethodTypes).
$pm_types = ak_stripe_payment_method_types($pdo);

// Essai en cours : le premier prélèvement a lieu le jour de la fin de l'essai (timestamp), sinon tout de suite.
// Même règle que la page de paiement (mode « setup » ou « payment » du formulaire Stripe).
$trial_end = ak_stripe_trial_end_for_org($pdo, $org_id);
$mode = $trial_end ? 'setup' : 'payment';

// Une association à la fois (deux administrateurs, deux onglets, double clic) : la lecture des abonnements
// Stripe (étape 2) et la création (étape 3) ne se croisent jamais. Verrou distinct de celui de la
// synchronisation (« org_… », pris par ak_stripe_sync_subscription à l'étape 2).
if (!ak_stripe_lock($pdo, 'create_' . $org_id, 20)) {
    http_response_code(409);
    echo json_encode(['error' => 'Un paiement est déjà en cours de préparation pour votre association. Patientez quelques secondes puis réessayez. Aucun montant n’a été prélevé.']);
    exit;
}
// Rendu à la fin de la requête, quelle que soit la sortie (chaque réponse se termine par exit, qui
// n'exécute pas les blocs finally)
register_shutdown_function(function () use ($pdo, $org_id) { ak_stripe_unlock($pdo, 'create_' . $org_id); });
try {
    // ============================================
    // 1. Client Stripe de l'association : créé, ou mis à jour (adresse de facturation, langue
    //    française, mentions du pied de facture). Client introuvable chez Stripe (supprimé, ou créé
    //    avec les clés de test avant le passage en réel) : remplacé par un nouveau.
    // ============================================
    [$customer_id, $cus_err] = ak_stripe_ensure_customer($pdo, $org_id, $user);
    if (!$customer_id) {
        error_log('[stripe-create-payment-intent] org #' . $org_id . ' ' . $cus_err);
        http_response_code(502);
        echo json_encode(['error' => $unavailable]);
        exit;
    }

    // ============================================
    // 2. Jamais deux abonnements : ce que Stripe connaît fait foi, pas seulement la base locale
    //    (webhook en retard, prélèvement SEPA en cours de vérification, deux onglets, double clic)
    // ============================================
    $wanted_items = array_values(array_filter([$plan_price_id, $addon_price_id]));
    $wanted_tax = $tax_rate_id ? [$tax_rate_id] : [];
    $existing = ak_stripe_api_call($pdo, 'GET', '/subscriptions', [
        'customer' => $customer_id, 'status' => 'all', 'limit' => 20,
        'expand' => ['data.latest_invoice.payment_intent', 'data.pending_setup_intent'],
    ]);
    if (empty($existing['ok'])) {
        error_log('[stripe-create-payment-intent] org #' . $org_id . ' abonnements Stripe illisibles : ' . ($existing['error'] ?? '?'));
        http_response_code(502);
        echo json_encode(['error' => 'Le paiement est momentanément indisponible. Aucun montant n’a été prélevé. Réessayez dans quelques minutes.']);
        exit;
    }
    $reuse = null;
    $reuse_secret = '';
    $sepa_processing = false;
    // Abonnements déjà connus de Stripe pour ce client (avant les annulations ci-dessous) : ils entrent dans la
    // clé d'idempotence de l'étape 3, qui change dès que cette liste change (une ancienne clé rendrait sinon
    // l'abonnement annulé entre-temps, et son paiement, inutilisables)
    $seen = implode(',', array_map(fn($s) => ($s['id'] ?? '') . ':' . ($s['status'] ?? ''), $existing['data']['data'] ?? []));
    foreach (($existing['data']['data'] ?? []) as $es) {
        $es_status = (string)($es['status'] ?? '');
        $es_pi = $es['latest_invoice']['payment_intent'] ?? null;
        $es_pi_status = is_array($es_pi) ? (string)($es_pi['status'] ?? '') : '';
        // Souscription pendant l'essai : souscrite seulement si la carte ou le mandat est enregistré et qu'elle
        // n'est pas résiliée. Sinon ce n'est qu'une tentative (page fermée avant l'enregistrement, résiliation
        // pendant l'essai) : reprise si elle porte sur le même contenu et la même fin d'essai, sinon annulée
        // (rien n'a été prélevé).
        $es_trial_open = $es_status === 'trialing'
            && (ak_stripe_obj_id($es['default_payment_method'] ?? '') === '' || !empty($es['cancel_at_period_end']));
        if (in_array($es_status, ['active', 'trialing', 'past_due', 'unpaid'], true) && !$es_trial_open) {
            // Déjà payé chez Stripe mais pas encore connu ici : appliqué tout de suite, et pas de second abonnement
            try { ak_stripe_sync_subscription($pdo, (string)$es['id'], $es); } catch (Throwable $e) { error_log('[stripe-create-payment-intent] sync : ' . $e->getMessage()); }
            http_response_code(409);
            echo json_encode(['error' => 'Votre association a déjà un abonnement par carte en cours : gérez-le depuis Mon abonnement.', 'redirect' => '/mon-asso-plan']);
            exit;
        }
        if ($es_status !== 'incomplete' && !$es_trial_open) continue;
        if ($es_pi_status === 'processing') { $sepa_processing = true; continue; }
        // Tentative restée en attente de paiement (carte refusée, 3-D Secure abandonné, page fermée) ou
        // d'enregistrement de la carte (essai) : reprise telle quelle si elle porte sur la même formule, la même
        // option, la même TVA et le même mode (même fin d'essai, ou prélèvement immédiat) ; sinon annulée
        $es_items = [];
        foreach (($es['items']['data'] ?? []) as $it) $es_items[] = (string)(is_array($it['price'] ?? null) ? ($it['price']['id'] ?? '') : ($it['price'] ?? ''));
        $es_tax = array_map(fn($t) => is_array($t) ? (string)($t['id'] ?? '') : (string)$t, $es['default_tax_rates'] ?? []);
        sort($es_items); $w = $wanted_items; sort($w); sort($es_tax);
        if ($es_trial_open) {
            $es_si = $es['pending_setup_intent'] ?? null;
            $secret = is_array($es_si) ? (string)($es_si['client_secret'] ?? '') : '';
            $resumable = $trial_end && (int)($es['trial_end'] ?? 0) === $trial_end && empty($es['cancel_at_period_end'])
                && is_array($es_si) && in_array((string)($es_si['status'] ?? ''), ['requires_payment_method', 'requires_confirmation', 'requires_action'], true);
        } else {
            $secret = is_array($es_pi) ? (string)($es_pi['client_secret'] ?? '') : '';
            $resumable = !$trial_end && in_array($es_pi_status, ['requires_payment_method', 'requires_confirmation', 'requires_action'], true);
        }
        if (!$reuse && $es_items === $w && $es_tax === $wanted_tax && $secret !== '' && $resumable) {
            $reuse = $es;
            $reuse_secret = $secret;
        } else {
            ak_stripe_api_call($pdo, 'DELETE', '/subscriptions/' . rawurlencode((string)$es['id']));
        }
    }
    if ($sepa_processing) {
        if (function_exists('ak_stripe_set_payment_pending')) ak_stripe_set_payment_pending($pdo, $org_id, true);
        http_response_code(409);
        echo json_encode(['error' => 'Un prélèvement SEPA est déjà en cours de vérification par votre banque pour votre abonnement (quelques jours ouvrés). Inutile de payer une seconde fois : votre abonnement s’activera dès sa confirmation.', 'redirect' => '/mon-asso-plan']);
        exit;
    }
    // Aucun prélèvement en cours chez Stripe : une ancienne indication « SEPA en cours » n'a plus lieu d'être
    if (function_exists('ak_stripe_set_payment_pending')) ak_stripe_set_payment_pending($pdo, $org_id, false);

    if ($reuse) {
        echo json_encode([
            'clientSecret' => $reuse_secret,
            'mode' => $mode,
            'type' => $mode,
            'trialEnd' => $trial_end,
            'subscriptionId' => (string)$reuse['id'],
            'customerId' => $customer_id,
        ]);
        exit;
    }

    // ============================================
    // 3. Création de la Subscription. Hors essai : premier mois prélevé tout de suite (le paiement met fin à
    //    l'essai terminé, ak_end_trial_after_payment ; 3-D Secure géré par Stripe.js sur la page). Essai en
    //    cours : période d'essai Stripe jusqu'à la fin de l'essai, aucun prélèvement maintenant ; la carte ou
    //    le mandat SEPA est enregistré par la SetupIntent de l'abonnement (pending_setup_intent).
    // ============================================
    $sub_data = [
        'customer' => $customer_id,
        'items[0][price]' => $plan_price_id,
        'payment_behavior' => 'default_incomplete',
        'payment_settings[save_default_payment_method]' => 'on_subscription',
        'expand' => ['latest_invoice.payment_intent', 'pending_setup_intent'],
        'description' => 'Abonnement Assokit — ' . ($user['org_name'] ?: ('association n°' . $org_id)),
        'metadata[org_id]' => (string)$org_id,
        'metadata[user_id]' => (string)$user_id,
        'metadata[plan_slug]' => $plan_slug,
        'metadata[with_addon_domain]' => $with_addon_domain ? '1' : '0',
    ];
    foreach ($pm_types as $i => $t) $sub_data['payment_settings[payment_method_types][' . $i . ']'] = $t;

    // Ajout add-on si demandé
    if ($addon_price_id) {
        $sub_data['items[1][price]'] = $addon_price_id;
    }

    // TVA ajoutée au prix HT de chaque ligne (formule et add-on), sur chaque facture de l'abonnement
    if ($tax_rate_id) {
        $sub_data['default_tax_rates[0]'] = $tax_rate_id;
        $sub_data['metadata[vat_rate]'] = (string)$vat_rate;
    }

    // Essai en cours : premier prélèvement le jour de la fin de l'essai. Sans carte ni mandat enregistré
    // d'ici là (page fermée), Stripe arrête l'abonnement à cette date sans rien prélever.
    if ($trial_end) {
        $sub_data['trial_end'] = $trial_end;
        $sub_data['trial_settings[end_behavior][missing_payment_method]'] = 'cancel';
        $sub_data['metadata[first_charge_at_trial_end]'] = '1';
    }

    // Requête rejouée (coupure réseau : nouvelle tentative d'ak_stripe_api_call, ou même demande dans la même
    // demi-heure sans que rien n'ait changé chez Stripe) : Stripe renvoie le même abonnement. La clé ne dépend
    // ni de l'administrateur qui clique (metadata[user_id]) ni du nom affiché, mais des abonnements déjà
    // connus ($seen) : une nouvelle demande après une annulation obtient un nouvel abonnement.
    $idem_data = $sub_data;
    unset($idem_data['metadata[user_id]'], $idem_data['description']);
    $idem = 'ak-sub-' . ak_stripe_get_mode($pdo) . '-' . $org_id . '-' . substr(sha1(json_encode($idem_data) . '|' . $seen), 0, 16) . '-' . (int)floor(time() / 1800);
    $resp = ak_stripe_api_call($pdo, 'POST', '/subscriptions', $sub_data, $idem);
    if (!$resp['ok']) {
        error_log('[stripe-create-payment-intent] org #' . $org_id . ' abonnement : ' . ($resp['error'] ?? '?'));
        http_response_code(502);
        echo json_encode(['error' => $unavailable]);
        exit;
    }

    $subscription = $resp['data'];

    // Récupération du client_secret : paiement du premier mois, ou enregistrement de la carte (essai)
    $client_secret = $trial_end
        ? ($subscription['pending_setup_intent']['client_secret'] ?? null)
        : ($subscription['latest_invoice']['payment_intent']['client_secret'] ?? null);
    if ($trial_end && ($subscription['status'] ?? '') !== 'trialing') $client_secret = null;
    if (empty($client_secret)) {
        error_log('[stripe-create-payment-intent] org #' . $org_id . ' abonnement ' . ($subscription['id'] ?? '?') . ' sans client_secret');
        http_response_code(502);
        echo json_encode(['error' => $unavailable]);
        exit;
    }

    // ============================================
    // 4. Aucune ligne locale tant que rien n'est payé
    // ============================================
    // L'abonnement Stripe reste « incomplete » jusqu'au paiement. La ligne d'abonnement est écrite au
    // premier paiement réussi : par la page de retour (mon-asso-paiement-success, qui relit l'abonnement
    // chez Stripe) ou par le webhook, le premier des deux (stripe-sync-helpers.php, sans doublon).

    // Réponse JSON
    echo json_encode([
        'clientSecret' => $client_secret,
        'mode' => $mode,
        'type' => $mode,
        'trialEnd' => $trial_end,
        'subscriptionId' => $subscription['id'],
        'customerId' => $customer_id,
    ]);
    exit;

} catch (Throwable $e) {
    http_response_code(500);
    error_log('[stripe-create-payment-intent] FATAL: ' . $e->getMessage());
    echo json_encode(['error' => 'Erreur serveur, réessayez dans un instant. Aucun montant n’a été prélevé.']);
    exit;
}
