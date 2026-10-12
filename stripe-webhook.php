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
 *   - customer.subscription.created  → asso_subscriptions créée (une fois l'abonnement payé)
 *   - customer.subscription.updated  → status mis à jour
 *   - customer.subscription.deleted  → status = cancelled (premier paiement jamais fait :
 *                                       la tentative « pending_payment » est retirée)
 *   - invoice.paid                   → asso_invoices_stripe + status active
 *   - invoice.payment_failed         → status = overdue (abonnement déjà payé seulement)
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
// Ligne courante, plan payant / d'essai, libellé organizations.plan : mêmes règles que le reste du site
require_once __DIR__ . '/trial-helpers.php';

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
        case 'invoice.voided':
        case 'invoice.marked_uncollectible':
            // Facture annulée (paiement abandonné : incomplete_expired) : plus de lien « Régulariser » vers elle
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
    $cpe_ts = ak_stripe_sub_period_end($sub);
    $current_period_end = $cpe_ts ? date('Y-m-d H:i:s', $cpe_ts) : null;

    // Mapping statut Stripe → statut local. Abonnement supprimé (customer.subscription.deleted) ou
    // premier paiement jamais fait dans les 23 h (incomplete_expired) : arrêté, quel que soit le statut reçu.
    $local_status = 'pending_payment';
    if ($stripe_status === 'active') $local_status = 'active';
    elseif ($stripe_status === 'trialing') $local_status = 'active';
    elseif ($stripe_status === 'past_due') $local_status = 'overdue';
    elseif ($stripe_status === 'unpaid') $local_status = 'overdue';
    elseif ($stripe_status === 'canceled' || $stripe_status === 'incomplete_expired') $local_status = 'cancelled';
    elseif ($stripe_status === 'incomplete') $local_status = 'pending_payment';
    if ($event_type === 'customer.subscription.deleted') $local_status = 'cancelled';

    // Recherche plan_id depuis stripe_price_id (à défaut : la formule choisie sur la page de paiement)
    $plan_id = null;
    if ($stripe_price_id) {
        $sp = $pdo->prepare("SELECT id FROM asso_plans WHERE stripe_price_id_monthly = :p LIMIT 1");
        $sp->execute([':p' => $stripe_price_id]);
        $found = $sp->fetchColumn();
        if ($found) $plan_id = (int)$found;
    }
    if (!$plan_id && !empty($sub['metadata']['plan_slug'])) {
        $sp = $pdo->prepare("SELECT id FROM asso_plans WHERE slug = :s LIMIT 1");
        $sp->execute([':s' => (string)$sub['metadata']['plan_slug']]);
        $found = $sp->fetchColumn();
        if ($found) $plan_id = (int)$found;
    }

    // Vérifie si subscription existe déjà
    $check = $pdo->prepare("SELECT id, status FROM asso_subscriptions WHERE stripe_subscription_id = :sid LIMIT 1");
    $check->execute([':sid' => $stripe_sub_id]);
    $existing_row = $check->fetch(PDO::FETCH_ASSOC) ?: null;
    $existing_id = $existing_row ? (int)$existing_row['id'] : false;

    // Paiement par carte abandonné ou refusé avant tout paiement (incomplete → incomplete_expired, ou
    // abonnement supprimé alors que la ligne attendait encore son premier paiement) : ce n'était qu'une
    // tentative. Aucune ligne n'en reste, sinon elle deviendrait l'abonnement courant d'une association
    // sans formule active (« Résiliée · accès jusqu'au … » offert, retard de paiement masqué) ; la
    // formule en place (essai, Démarrage, abonnement en cours) ne change pas. L'événement reste dans
    // asso_stripe_events, la facture Stripe annulée dans asso_invoices_stripe.
    if ($local_status === 'cancelled'
        && (in_array($stripe_status, ['incomplete', 'incomplete_expired'], true)
            || ($existing_row && ($existing_row['status'] ?? '') === 'pending_payment'))) {
        if ($existing_id) ak_stripe_drop_unpaid_attempt($pdo, (int)$existing_id);
        return;
    }

    if ($existing_id && ak_stripe_row_off_stripe_plan($pdo, (int)$existing_id)) {
        // Le Fondateur a passé ce client sur un plan que Stripe ne facture pas (gratuit, essai, Sur-mesure…,
        // les données Stripe sont gardées) : Stripe ne décide plus de son statut. Une fois l'abonnement
        // annulé dans Stripe, la ligne cesse d'être « payée par carte » ; elle n'est jamais marquée résiliée
        // ni en retard pour autant (un client Sur-mesure resterait sinon « Résiliée »).
        if ($local_status === 'cancelled' && ak_db_accepts($pdo, 'asso_subscriptions', 'payment_mode', 'manual')) {
            $pdo->prepare("UPDATE asso_subscriptions SET payment_mode = 'manual' WHERE id = ?")->execute([(int)$existing_id]);
        }
        return;
    }

    if ($existing_id) {
        // Abonnement en retard de paiement arrêté par Stripe (fin des relances) : la période en cours n'a
        // jamais été payée, l'accès s'arrête tout de suite (Démarrage via ak_sub_expire_cancelled plus bas),
        // comme « Arrêter mon abonnement » sur un retard (mon-asso-annuler-abonnement.php)
        if ($local_status === 'cancelled' && ($existing_row['status'] ?? '') === 'overdue') {
            $current_period_end = date('Y-m-d H:i:s');
        }
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
        // Aucune ligne locale pour un abonnement pas encore payé (incomplete) ou déjà arrêté : la ligne
        // naît au premier paiement réussi (événement « active »), jamais d'une simple tentative
        if (!in_array($local_status, ['active', 'overdue'], true)) return;
        // INSERT
        $ins = $pdo->prepare("
            INSERT INTO asso_subscriptions
                (org_id, plan_id, stripe_subscription_id, stripe_price_id, payment_mode, status,
                 current_period_end, cancel_at_period_end, created_at)
            VALUES
                (:org, :plan, :sid, :pid, 'stripe', :st, :cpe, :cape, NOW())
        ");
        try {
            $ins->execute([
                ':org' => $org_id,
                ':plan' => $plan_id,
                ':sid' => $stripe_sub_id,
                ':pid' => $stripe_price_id,
                ':st' => $local_status,
                ':cpe' => $current_period_end,
                ':cape' => $cancel_at_period_end ? 1 : 0,
            ]);
            $existing_id = (int)$pdo->lastInsertId();
        } catch (PDOException $e) {
            // Un seul abonnement par association (index unique) : la ligne existante (essai, Démarrage ou
            // abonnement en cours) n'est reprise par Stripe que si l'abonnement est VRAIMENT payé.
            // incomplete / past_due / canceled (carte refusée, 3D Secure raté, paiement abandonné) :
            // rien n'est payé, la ligne d'essai reste intacte et l'essai se termine normalement.
            // L'événement « active » qui suit un paiement réussi repasse ici et fait la conversion.
            if ((string)$e->getCode() !== '23000') throw $e;
            if ($local_status !== 'active') return;
            $cur = $pdo->prepare("SELECT s.id FROM asso_subscriptions s WHERE s.org_id = ? ORDER BY " . ak_sub_order('s') . " LIMIT 1");
            $cur->execute([$org_id]);
            $existing_id = (int)$cur->fetchColumn();
            if ($existing_id <= 0) throw $e;
            $upd = $pdo->prepare("
                UPDATE asso_subscriptions
                SET plan_id = COALESCE(:plan, plan_id), stripe_subscription_id = :sid, stripe_price_id = :pid,
                    payment_mode = 'stripe', status = :st, current_period_end = :cpe, cancel_at_period_end = :cape, updated_at = NOW()
                WHERE id = :id
            ");
            $upd->execute([
                ':plan' => $plan_id, ':sid' => $stripe_sub_id, ':pid' => $stripe_price_id, ':st' => $local_status,
                ':cpe' => $current_period_end, ':cape' => $cancel_at_period_end ? 1 : 0, ':id' => $existing_id,
            ]);
        }
    }

    // Paiement actif : l'essai gratuit est terminé (sinon le cron d'essai suspendrait un client payant).
    // Abonnement qui devient actif (premier paiement, ou retard réglé) : c'est un paiement
    $just_paid = $stripe_status === 'active' && in_array((string)($existing_row['status'] ?? 'pending_payment'), ['pending_payment', 'overdue'], true);
    if ($local_status === 'active') ak_end_trial_after_payment($pdo, (int)$org_id, (string)$stripe_sub_id, $just_paid);

    // Abonnement arrêté par Stripe : à la fin de la période payée, l'association passe au plan gratuit
    // Démarrage (jamais la formule payante gardée indéfiniment) ; avant, cron et pages s'en chargent à la date
    if ($local_status === 'cancelled' && function_exists('ak_sub_expire_cancelled')) ak_sub_expire_cancelled($pdo, (int)$org_id);

    // Gestion add-on domaine (si présent dans items) : seulement pour un abonnement payé (jamais un
    // domaine « actif » offert par un paiement en attente)
    if ($existing_id && $local_status !== 'pending_payment' && !empty($sub['items']['data'])) {
        foreach ($sub['items']['data'] as $item) {
            $price_id = $item['price']['id'] ?? null;
            $addon_price_id = ak_stripe_config_get($pdo, 'addon_domain_price_id', null);

            if ($price_id && $addon_price_id && $price_id === $addon_price_id) {
                // Insérer ou mettre à jour add-on domaine
                $sub_local_id = (int)$existing_id;
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
    // (jamais sur une ligne passée à la main sur un plan que Stripe ne facture pas : ce paiement
    // porte sur l'ancienne formule, il ne décide pas du statut de la formule actuelle)
    $sub_id = ak_stripe_invoice_sub_id($invoice);
    if ($sub_id) {
        $upd = $pdo->prepare("
            UPDATE asso_subscriptions
            SET status = 'active', updated_at = NOW()
            WHERE stripe_subscription_id = :sid AND status IN ('pending_payment', 'overdue')
              AND (plan_id IS NULL OR plan_id NOT IN (SELECT p.id FROM asso_plans p WHERE " . ak_plan_off_stripe_sql($pdo) . "))
        ");
        $upd->execute([':sid' => $sub_id]);
        try {
            $o = $pdo->prepare("SELECT id, org_id FROM asso_subscriptions WHERE stripe_subscription_id = :sid LIMIT 1");
            $o->execute([':sid' => $sub_id]);
            $paid_row = $o->fetch(PDO::FETCH_ASSOC) ?: [];
            $paid_org = (int)($paid_row['org_id'] ?? 0);
            // Ligne passée à la main sur un plan que Stripe ne facture pas : ce paiement ne convertit rien
            if ($paid_org > 0 && !ak_stripe_row_off_stripe_plan($pdo, (int)$paid_row['id'])) {
                ak_end_trial_after_payment($pdo, $paid_org, (string)$sub_id, (int)($invoice['amount_paid'] ?? 0) > 0);
            }
        } catch (Throwable $e) {
            error_log('[stripe-webhook] fin essai (invoice.paid): ' . get_class($e));
        }
    }
}

/**
 * Fin de l'essai gratuit quand l'association paie : association et abonnement
 * « historique » repassent actifs, la ligne d'essai est close ; sur un vrai paiement ($paid :
 * facture réglée, ou abonnement qui devient actif), une suspension à la main pour impayé est
 * levée. Jamais bloquant.
 */
function ak_end_trial_after_payment(PDO $pdo, int $org_id, string $paid_stripe_sub_id, bool $paid = false): void {
    if ($org_id <= 0) return;
    try {
        // Association en essai, ou suspendue à la fin de l'essai (cron d'essai : sans motif, ou motif
        // « Fin de l'essai », « Essai terminé ») : elle repasse active, sans motif. Une suspension
        // décidée par le support pendant l'essai (« Abus », « Fraude »…) reste en place : le paiement
        // ne la lève pas (connexion.php ne l'annonce d'ailleurs pas). Une suspension pour impayé se
        // lève sur un vrai paiement seulement ($paid), comme plus bas.
        $ocols = ak_db_columns($pdo, 'organizations');
        if (isset($ocols['suspended_reason'])) {
            $lift = "fin (de l|d).{0,2}essai|essai[^.;:]{0,30}(termin|expir|fini|[eé]chu|achev|clos)"
                  . ($paid ? "|impay|retard de paiement|paiement en retard|d[eé]faut de paiement|non[- ]paiement" : "");
            $pdo->prepare("UPDATE organizations SET status = 'active', suspended_reason = NULL
                           WHERE id = ? AND trial_ends_at IS NOT NULL
                             AND (status = 'trial'
                                  OR (status = 'suspended' AND (TRIM(COALESCE(suspended_reason, '')) = ''
                                                                OR LOWER(suspended_reason) REGEXP ?)))")
                ->execute([$org_id, $lift]);
        } else {
            // Sans colonne de motif, une suspension pendant l'essai ne peut venir que du cron d'essai
            $pdo->prepare("UPDATE organizations SET status = 'active'
                           WHERE id = ? AND trial_ends_at IS NOT NULL AND status IN ('trial', 'suspended')")
                ->execute([$org_id]);
        }
        // La fin d'essai est effacée quel que soit le statut (une association réactivée « active »
        // gardait sa date, et le cron d'essai l'aurait rétrogradée) : c'est le signe « converti ».
        // Une suspension restée en place (décision du support) se lève ensuite à la main.
        $pdo->prepare("UPDATE organizations SET trial_ends_at = NULL WHERE id = ? AND trial_ends_at IS NOT NULL")->execute([$org_id]);
    } catch (Throwable $e) { error_log('[stripe-webhook] fin essai org: ' . get_class($e)); }
    try {
        // Suspension à la main pour impayé (motif « Impayé », « Paiement en retard », « Défaut de paiement »…) :
        // levée par le paiement, comme l'annonce la page de connexion (« Régulariser mon abonnement »).
        // Les suspensions pour un autre motif (décision du support) restent en place, et un événement
        // Stripe sans paiement (abonnement modifié, période d'essai Stripe) ne lève rien.
        $ocols = ak_db_columns($pdo, 'organizations');
        if ($paid && isset($ocols['suspended_reason'])) {
            $pdo->prepare("UPDATE organizations SET status = 'active', suspended_reason = NULL
                           WHERE id = ? AND status = 'suspended' AND trial_ends_at IS NULL"
                           . (isset($ocols['deleted_at']) ? " AND deleted_at IS NULL" : "") . "
                             AND LOWER(COALESCE(suspended_reason, '')) REGEXP 'impay|retard de paiement|paiement en retard|d[eé]faut de paiement|non[- ]paiement'")
                ->execute([$org_id]);
        }
    } catch (Throwable $e) { error_log('[stripe-webhook] levée suspension impayé: ' . get_class($e)); }
    try {
        $pdo->prepare("UPDATE subscriptions SET status = 'active' WHERE org_id = ? AND status IN ('trial', 'suspended')")->execute([$org_id]);
    } catch (Throwable $e) { error_log('[stripe-webhook] fin essai subscriptions: ' . get_class($e)); }
    try {
        // Lignes d'essai restantes (statut « trial », ou plan d'essai écrit « active » quand l'ENUM n'a pas « trial »)
        $pdo->prepare("UPDATE asso_subscriptions SET status = 'cancelled'
                       WHERE org_id = ? AND status IN ('trial', 'active')
                         AND (status = 'trial' OR plan_id IN (SELECT p.id FROM asso_plans p WHERE " . ak_plan_trial_sql($pdo) . "))
                         AND (stripe_subscription_id IS NULL OR stripe_subscription_id <> ?)")
            ->execute([$org_id, $paid_stripe_sub_id]);
    } catch (Throwable $e) { error_log('[stripe-webhook] fin essai ligne d\'essai: ' . get_class($e)); }
    ak_org_sync_plan_label($pdo, $org_id);
}

/**
 * La ligne d'abonnement est-elle sur un plan que Stripe ne facture pas : essai, gratuit, Sur-mesure /
 * sur devis, plan sans prix Stripe (ak_plan_off_stripe_sql, trial-helpers.php : la même règle que
 * l'alerte « abonnement Stripe à annuler » du cockpit) ? Le Fondateur y a passé ce client à la main :
 * Stripe facture encore l'ancienne formule et ne décide plus du statut de la ligne.
 */
function ak_stripe_row_off_stripe_plan(PDO $pdo, int $row_id): bool {
    try {
        $st = $pdo->prepare("SELECT 1 FROM asso_subscriptions s JOIN asso_plans p ON p.id = s.plan_id WHERE s.id = ? AND " . ak_plan_off_stripe_sql($pdo));
        $st->execute([$row_id]);
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Gère invoice.payment_failed : passe l'abonnement en pending/overdue
 */
function ak_handle_invoice_failed(PDO $pdo, array $invoice): void {
    ak_handle_invoice_upsert($pdo, $invoice);

    $sub_id = ak_stripe_invoice_sub_id($invoice);
    if ($sub_id) {
        // Calcul grace period (15 jours par défaut)
        $grace_end = date('Y-m-d H:i:s', strtotime('+15 days'));

        // Jamais sur une ligne que le Fondateur a passée sur un plan que Stripe ne facture pas (gratuit,
        // essai, Sur-mesure…) : « en retard » y mettrait, 15 jours plus tard, l'écran bloquant de
        // régularisation pour un prélèvement qui ne porte plus sur la formule du client.
        // Seulement un abonnement déjà payé (renouvellement refusé) : un PREMIER paiement refusé (ligne
        // « pending_payment », carte refusée pendant l'essai par exemple) n'est pas un impayé, aucune
        // relance ni écran bloquant. Les nouvelles tentatives de Stripe ne repoussent pas la fin de la
        // période de grâce déjà ouverte (grace_period_end est lu avant le changement de statut).
        $upd = $pdo->prepare("
            UPDATE asso_subscriptions
            SET grace_period_end = IF(status = 'overdue' AND grace_period_end IS NOT NULL, grace_period_end, :ge),
                status = 'overdue',
                updated_at = NOW()
            WHERE stripe_subscription_id = :sid
              AND status IN ('active', 'overdue')
              AND (plan_id IS NULL OR plan_id NOT IN (SELECT p.id FROM asso_plans p WHERE " . ak_plan_off_stripe_sql($pdo) . "))
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

    // Montant : payé, sinon dû (brouillon, paiement refusé : amount_paid vaut 0, pas null)
    $amount = (int)($invoice['amount_paid'] ?? 0);
    if ($amount <= 0) $amount = (int)($invoice['amount_due'] ?? ($invoice['total'] ?? 0));
    // TVA : « tax » (API 2024-06-20), sinon la somme de total_taxes (versions plus récentes)
    $tax = $invoice['tax'] ?? null;
    if ($tax === null && !empty($invoice['total_taxes']) && is_array($invoice['total_taxes'])) {
        $tax = array_sum(array_map(fn($t) => (int)($t['amount'] ?? 0), $invoice['total_taxes']));
    }

    $data = [
        ':oid' => $org_id,
        ':iid' => $invoice_id,
        ':cid' => $customer_id,
        ':sid' => ak_stripe_invoice_sub_id($invoice),
        ':num' => ($invoice['number'] ?? '') !== '' ? $invoice['number'] : null,
        ':amt' => $amount,
        ':tax' => (int)($tax ?? 0),
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

    // Le numéro légal et la période n'existent qu'à la finalisation : invoice.created (brouillon) arrive
    // sans eux, invoice.finalized / invoice.paid les complètent (jamais effacés par un événement plus pauvre).
    // updated_at seulement si la colonne existe (absente du schéma d'origine : l'INSERT échouait).
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
            tax_cents = VALUES(tax_cents),
            invoice_number = COALESCE(VALUES(invoice_number), invoice_number),
            stripe_subscription_id = COALESCE(VALUES(stripe_subscription_id), stripe_subscription_id),
            hosted_invoice_url = COALESCE(VALUES(hosted_invoice_url), hosted_invoice_url),
            invoice_pdf_url = COALESCE(VALUES(invoice_pdf_url), invoice_pdf_url),
            paid_at = COALESCE(VALUES(paid_at), paid_at),
            due_date = COALESCE(VALUES(due_date), due_date),
            period_start = COALESCE(VALUES(period_start), period_start),
            period_end = COALESCE(VALUES(period_end), period_end),
            description = COALESCE(VALUES(description), description)
            " . (isset(ak_db_columns($pdo, 'asso_invoices_stripe')['updated_at']) ? ", updated_at = NOW()" : "") . "
    ";
    $st = $pdo->prepare($sql);
    $st->execute($data);
}

/**
 * Identifiant de l'abonnement Stripe d'une facture, quelle que soit la version d'API de l'endpoint
 * webhook : invoice.subscription (2024-06-20, celle d'ak_stripe_api_call), ou
 * invoice.parent.subscription_details.subscription (2025-03-31.basil et suivantes), ou la ligne de la
 * facture. Les événements suivent la version réglée sur l'endpoint dans le Dashboard Stripe, pas
 * l'en-tête Stripe-Version des appels : sans cette tolérance, invoice.paid ne réactivait plus rien.
 */
function ak_stripe_invoice_sub_id(array $invoice): ?string {
    $id = $invoice['subscription'] ?? ($invoice['parent']['subscription_details']['subscription'] ?? null);
    if ($id === null) {
        foreach (($invoice['lines']['data'] ?? []) as $line) {
            $id = $line['subscription'] ?? ($line['parent']['subscription_item_details']['subscription'] ?? null);
            if ($id !== null) break;
        }
    }
    if (is_array($id)) $id = $id['id'] ?? null;   // objet développé (expand)
    $id = is_string($id) ? trim($id) : '';
    return $id !== '' ? $id : null;
}

/**
 * Fin de la période en cours d'un abonnement Stripe (timestamp) : subscription.current_period_end
 * (2024-06-20), ou celle des éléments de l'abonnement (déplacée sur items.data[] depuis 2025-03-31.basil).
 */
function ak_stripe_sub_period_end(array $sub): ?int {
    if (!empty($sub['current_period_end'])) return (int)$sub['current_period_end'];
    $end = null;
    foreach (($sub['items']['data'] ?? []) as $item) {
        if (!empty($item['current_period_end'])) $end = max((int)$end, (int)$item['current_period_end']);
    }
    return $end ?: null;
}

/**
 * Tentative de paiement par carte jamais aboutie (premier paiement abandonné ou refusé) : la ligne
 * « pending_payment » pré-enregistrée est retirée, avec ses add-ons (jamais un domaine offert). Si la
 * suppression est refusée (contrainte), la ligne est close « cancelled » sans période payée.
 */
function ak_stripe_drop_unpaid_attempt(PDO $pdo, int $row_id): void {
    try {
        $pdo->prepare("UPDATE asso_subscription_addons SET status = 'cancelled' WHERE subscription_id = ?")->execute([$row_id]);
    } catch (Throwable $e) { /* table d'add-ons absente */ }
    try {
        $pdo->prepare("DELETE FROM asso_subscriptions WHERE id = ? AND status = 'pending_payment'")->execute([$row_id]);
    } catch (Throwable $e) {
        error_log('[stripe-webhook] tentative non payée #' . $row_id . ' : ' . get_class($e));
        ak_db_update($pdo, 'asso_subscriptions', $row_id, ['status' => 'cancelled', 'current_period_end' => null],
            ['cancelled_at' => 'NOW()', 'updated_at' => 'NOW()']);
    }
}
