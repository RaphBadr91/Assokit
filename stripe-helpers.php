<?php
/**
 * stripe-helpers.php
 * --------------------------------------------------------------
 * Helpers métier Stripe (abonnements, factures, customer).
 *
 * Toutes les fonctions sont SAFE :
 *   - Retournent null si Stripe non configuré
 *   - Aucune fatale si tables absentes
 *   - try/catch partout
 *
 * Phase 1 (actuelle) : fonctions retournent null/false partout
 * Phase 2 (Stripe activé) : fonctions deviennent actives automatiquement
 * --------------------------------------------------------------
 */

@require_once __DIR__ . '/stripe-config-helpers.php';

if (!function_exists('ak_get_stripe_customer_id')) {
    /**
     * Récupère le stripe_customer_id d'une organisation.
     *
     * @param PDO $pdo
     * @param int $org_id
     * @return string|null
     */
    function ak_get_stripe_customer_id(PDO $pdo, int $org_id): ?string {
        try {
            $st = $pdo->prepare("SELECT stripe_customer_id FROM organizations WHERE id = :id LIMIT 1");
            $st->execute([':id' => $org_id]);
            $cid = $st->fetchColumn();
            return $cid ?: null;
        } catch (Throwable $e) {
            return null;
        }
    }
}

if (!function_exists('ak_stripe_site_base')) {
    /**
     * Adresse du site vue par Stripe (webhook, portail client, retour de paiement) : schéma + hôte de la
     * requête, sans « www. » (.htaccess redirige www vers assokit.fr, et Stripe ne suit pas les
     * redirections). Hors requête web (ligne de commande) : SITE_URL, sinon https://assokit.fr.
     */
    function ak_stripe_site_base(): string {
        $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
        if ($host !== '' && preg_match('/^[a-z0-9.-]+(:\d+)?$/', $host)) {
            $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https'
                || (int)($_SERVER['SERVER_PORT'] ?? 0) === 443;
            return ($https ? 'https' : 'http') . '://' . preg_replace('/^www\./', '', $host);
        }
        if (defined('SITE_URL') && preg_match('#^https?://[a-z0-9.-]+(:\d+)?#i', (string)SITE_URL, $m)) return strtolower($m[0]);
        return 'https://assokit.fr';
    }
}

if (!function_exists('ak_stripe_invoice_cols')) {
    /** Colonnes d'asso_invoices_stripe (vide si la table manque) : billing_reason, stripe_livemode… selon la migration. */
    function ak_stripe_invoice_cols(PDO $pdo): array {
        if (!function_exists('ak_db_columns') && is_file(__DIR__ . '/trial-helpers.php')) require_once __DIR__ . '/trial-helpers.php';
        return function_exists('ak_db_columns') ? ak_db_columns($pdo, 'asso_invoices_stripe') : [];
    }
}

if (!function_exists('ak_get_active_invoice_url')) {
    /**
     * Récupère l'URL Stripe de la facture en attente de paiement (= bouton "Régulariser").
     * Si pas de facture impayée, retourne null.
     *
     * @param PDO $pdo
     * @param int $org_id
     * @return string|null URL hosted_invoice_url ou null
     */
    function ak_get_active_invoice_url(PDO $pdo, int $org_id): ?string {
        try {
            // Jamais la facture d'une première tentative de paiement (paiement refusé ou abandonné sur la
            // page de paiement) : seule une échéance d'un abonnement déjà payé est à « régulariser »
            $attempt = isset(ak_stripe_invoice_cols($pdo)['billing_reason']) ? "AND COALESCE(billing_reason, '') <> 'subscription_create'" : '';
            $st = $pdo->prepare("
                SELECT hosted_invoice_url
                FROM asso_invoices_stripe
                WHERE org_id = :id
                  AND status = 'open'
                  AND hosted_invoice_url IS NOT NULL
                  $attempt
                ORDER BY created_at DESC
                LIMIT 1
            ");
            $st->execute([':id' => $org_id]);
            $url = $st->fetchColumn();
            return $url ?: null;
        } catch (Throwable $e) {
            return null;
        }
    }
}

if (!function_exists('ak_get_regularize_url')) {
    /**
     * Génère l'URL du bouton "Régulariser" :
     *   - Si Stripe configuré ET facture impayée → URL Stripe directe (paiement immédiat)
     *   - Sinon → page de paiement interne /mon-asso-paiement
     *   - Sinon (pas Stripe) → /contact?subject=billing
     *
     * @param PDO $pdo
     * @param int $org_id
     * @return string URL absolue ou relative
     */
    function ak_get_regularize_url(PDO $pdo, int $org_id): string {
        // 0. Application mobile (Apple 3.1.1, règles de paiement de Google Play) : jamais une page de
        // paiement Stripe ni le tunnel de paiement, seulement la page de contact
        if (function_exists('ak_billing_hidden') && ak_billing_hidden()) {
            return '/contact?subject=billing';
        }
        // 1. Si Stripe configuré et facture impayée : URL hosted_invoice
        if (ak_stripe_is_configured($pdo)) {
            $invoice_url = ak_get_active_invoice_url($pdo, $org_id);
            if ($invoice_url) {
                return $invoice_url;
            }
            // Abonnement par carte déjà en place (paiement refusé) : mettre à jour la carte dans le portail
            // Stripe, qui relance le paiement. Jamais un second abonnement (double facturation).
            if (ak_org_live_stripe_subscription($pdo, $org_id)) {
                return '/mon-asso-paiement?action=update_card';
            }
            // Pas de facture impayée mais Stripe actif → page paiement
            return '/mon-asso-paiement';
        }

        // 2. Stripe non configuré → contact
        return '/contact?subject=billing';
    }
}

if (!function_exists('ak_get_org_invoices_stripe')) {
    /**
     * Liste les factures Stripe d'une organisation.
     *
     * @param PDO $pdo
     * @param int $org_id
     * @param int $limit
     * @return array
     */
    function ak_get_org_invoices_stripe(PDO $pdo, int $org_id, int $limit = 50): array {
        try {
            // Ni brouillon (facture pas encore émise, sans numéro), ni facture d'une première tentative
            // de paiement restée impayée (carte refusée, paiement abandonné : annulée par Stripe ensuite)
            $cols = ak_stripe_invoice_cols($pdo);
            $attempt = isset($cols['billing_reason'])
                ? "AND NOT (COALESCE(billing_reason, '') = 'subscription_create' AND COALESCE(status, '') IN ('open', 'void'))" : '';
            $st = $pdo->prepare("
                SELECT * FROM asso_invoices_stripe
                WHERE org_id = :id
                  AND COALESCE(status, '') <> 'draft'
                  $attempt
                ORDER BY created_at DESC
                LIMIT :lim
            ");
            $st->bindValue(':id', $org_id, PDO::PARAM_INT);
            $st->bindValue(':lim', $limit, PDO::PARAM_INT);
            $st->execute();
            $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
            // Facture restée impayée d'un abonnement arrêté depuis (fin des relances, arrêt d'un retard) : plus
            // due (le Fondateur l'annule par un avoir dans Stripe). Montrée « Annulée », sans PDF ni lien : son
            // PDF Stripe porte « Payer en ligne », et un paiement ne réactiverait pas l'abonnement arrêté.
            $live = $pdo->prepare("SELECT 1 FROM asso_subscriptions WHERE stripe_subscription_id = ? AND status IN ('active', 'overdue')"
                . (isset(ak_db_columns($pdo, 'asso_subscriptions')['payment_mode']) ? " AND COALESCE(payment_mode, 'stripe') = 'stripe'" : '') . " LIMIT 1");
            foreach ($rows as &$r) {
                $sid = trim((string)($r['stripe_subscription_id'] ?? ''));
                if ($sid === '' || !in_array((string)($r['status'] ?? ''), ['open', 'uncollectible'], true)) continue;
                $live->execute([$sid]);
                if ($live->fetchColumn()) continue;
                $r['status'] = 'void';
                $r['invoice_pdf_url'] = null;
                $r['hosted_invoice_url'] = null;
            }
            unset($r);
            return $rows;
        } catch (Throwable $e) {
            return [];
        }
    }
}

if (!function_exists('ak_get_org_active_subscription')) {
    /**
     * Récupère l'abonnement actif d'une org (avec données du plan).
     *
     * @param PDO $pdo
     * @param int $org_id
     * @return array|null
     */
    function ak_get_org_active_subscription(PDO $pdo, int $org_id): ?array {
        try {
            // Colonnes du plan absentes (schéma sans Stripe) : la ligne est lue quand même
            $pcols = function_exists('ak_db_columns') ? ak_db_columns($pdo, 'asso_plans') : [];
            $scols = function_exists('ak_db_columns') ? ak_db_columns($pdo, 'asso_subscriptions') : [];
            $price_id = (!$pcols || isset($pcols['stripe_price_id_monthly'])) ? 'p.stripe_price_id_monthly' : 'NULL AS stripe_price_id_monthly';
            $quote = (!$pcols || isset($pcols['is_custom_quote'])) ? 'COALESCE(p.is_custom_quote, 0)' : '0';
            // La ligne qui compte : d'abord l'abonnement par carte en vigueur (actif ou en retard : c'est
            // lui qui est prélevé), puis la ligne courante (même ordre qu'ak_get_current_plan), jamais un
            // paiement par carte en attente (tentative pas encore payée) devant l'essai ou la formule en place.
            // Avant : la ligne la plus récente, qui pouvait être cette tentative (« Plan actuel : Assokit »
            // et « Annuler mon abonnement » pour une association en essai).
            $has_sid = !$scols || isset($scols['stripe_subscription_id']);
            $live = $has_sid ? "(COALESCE(s.stripe_subscription_id, '') <> '' AND s.status IN ('active', 'overdue')) DESC, " : '';
            $attempt = $has_sid ? "(s.status = 'pending_payment' AND COALESCE(s.stripe_subscription_id, '') <> '') ASC, " : '';
            $order = function_exists('ak_sub_order') ? ak_sub_order('s') : 's.id DESC';
            $st = $pdo->prepare("
                SELECT s.*, p.name AS plan_name, p.slug AS plan_slug,
                       p.price_cents AS price_cents_monthly, $price_id, $quote AS plan_is_custom_quote
                FROM asso_subscriptions s
                INNER JOIN asso_plans p ON p.id = s.plan_id
                WHERE s.org_id = :id
                ORDER BY {$live}{$attempt}{$order}
                LIMIT 1
            ");
            $st->execute([':id' => $org_id]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (Throwable $e) {
            return null;
        }
    }
}

if (!function_exists('ak_stripe_price_problem')) {
    /**
     * Le prix Stripe $price_id est-il bien celui qu'Assokit affiche : actif, en euros, mensuel, hors taxes
     * (TVA ajoutée par Stripe), de $amount_cents HT ? Message du problème, ou null si tout va bien.
     * Vérifié à chaque création d'abonnement (stripe-create-payment-intent.php) et à la saisie manuelle
     * d'un prix (fondateur-stripe-config.php).
     */
    function ak_stripe_price_problem(PDO $pdo, string $price_id, int $amount_cents): ?string {
        $r = ak_stripe_api_call($pdo, 'GET', '/prices/' . rawurlencode($price_id));
        if (empty($r['ok'])) return 'Stripe ne trouve pas ce prix avec les clés en place (' . ($r['error'] ?? '?') . ') : vérifiez le mode test / réel.';
        $p = $r['data'];
        if (empty($p['active'])) return 'Ce prix est archivé dans Stripe.';
        if (strtolower((string)($p['currency'] ?? '')) !== 'eur') return 'Ce prix n’est pas en euros.';
        if (($p['recurring']['interval'] ?? '') !== 'month' || (int)($p['recurring']['interval_count'] ?? 1) !== 1) return 'Ce prix n’est pas mensuel.';
        if ((int)($p['unit_amount'] ?? -1) !== $amount_cents) {
            return 'Montant différent : ' . ak_format_price_cents((int)($p['unit_amount'] ?? 0)) . ' chez Stripe, ' . ak_format_price_cents($amount_cents) . ' HT dans Assokit.';
        }
        if (($p['tax_behavior'] ?? '') === 'inclusive') return 'Ce prix est TTC (« taxes incluses ») : il doit être hors taxes.';
        return null;
    }
}

if (!function_exists('ak_org_live_stripe_subscription')) {
    /**
     * Abonnement Stripe encore en vigueur pour l'association : ligne « payée par carte » (identifiant
     * Stripe renseigné) active ou en retard de paiement, quelle que soit sa formule (un client passé à la
     * main sur une autre formule est encore prélevé tant que Stripe n'a pas arrêté l'abonnement).
     * Tant qu'il existe, aucun nouvel abonnement ne doit être créé : changer de carte passe par le portail
     * Stripe, l'add-on domaine s'ajoute à cet abonnement.
     * Clés : id, org_id, plan_id, plan_slug, plan_name, status, stripe_subscription_id, current_period_end,
     * cancel_at_period_end (0/1). null s'il n'y en a pas.
     */
    function ak_org_live_stripe_subscription(PDO $pdo, int $org_id): ?array {
        try {
            $cols = function_exists('ak_db_columns') ? ak_db_columns($pdo, 'asso_subscriptions') : [];
            if ($cols && !isset($cols['stripe_subscription_id'])) return null;
            $cape = (!$cols || isset($cols['cancel_at_period_end'])) ? "COALESCE(s.cancel_at_period_end, 0)" : "0";
            $pay = (!$cols || isset($cols['payment_mode'])) ? " AND COALESCE(s.payment_mode, 'stripe') = 'stripe'" : "";
            $st = $pdo->prepare("
                SELECT s.id, s.org_id, s.plan_id, s.status, s.stripe_subscription_id, s.current_period_end,
                       $cape AS cancel_at_period_end, p.slug AS plan_slug, p.name AS plan_name
                FROM asso_subscriptions s
                LEFT JOIN asso_plans p ON p.id = s.plan_id
                WHERE s.org_id = :id AND COALESCE(s.stripe_subscription_id, '') <> ''
                  AND s.status IN ('active', 'overdue')$pay
                ORDER BY (s.status = 'active') DESC, s.id DESC
                LIMIT 1
            ");
            $st->execute([':id' => $org_id]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (Throwable $e) {
            return null;
        }
    }
}

if (!function_exists('ak_live_sub_vat_rate')) {
    /**
     * TVA réellement appliquée à l'abonnement par carte en place ($live = ak_org_live_stripe_subscription) :
     * taux de la plateforme si la dernière facture Stripe payante de cet abonnement porte de la TVA
     * (souscrit avec la TVA), sinon null : abonnement plus ancien sans TVA, TVA non applicable, pas
     * d'abonnement par carte. null = montant prélevé tel quel (« 10,00 €/mois »), sans « HT ».
     * Même règle pour « Mon abonnement » et l'ajout du domaine personnalisé.
     */
    function ak_live_sub_vat_rate(PDO $pdo, int $org_id, ?array $live): ?float {
        if (!$live || empty($live['stripe_subscription_id'])) return null;
        $rate = function_exists('ak_vat_applicable_rate') ? ak_vat_applicable_rate($pdo) : 0.0;
        if ($rate <= 0) return null;
        try {
            $tq = $pdo->prepare("SELECT tax_cents FROM asso_invoices_stripe WHERE org_id = ? AND stripe_subscription_id = ? AND amount_cents > 0 ORDER BY id DESC LIMIT 1");
            $tq->execute([$org_id, $live['stripe_subscription_id']]);
            $tax = $tq->fetchColumn();
            return ($tax !== false && (int)$tax > 0) ? $rate : null;
        } catch (Throwable $e) {
            return null;
        }
    }
}

if (!function_exists('ak_get_subscription_addons')) {
    /**
     * Récupère les add-ons actifs d'un abonnement (ex: domaine perso).
     *
     * @param PDO $pdo
     * @param int $subscription_id
     * @return array
     */
    function ak_get_subscription_addons(PDO $pdo, int $subscription_id): array {
        try {
            $st = $pdo->prepare("
                SELECT * FROM asso_subscription_addons
                WHERE subscription_id = :id AND status = 'active'
                ORDER BY created_at ASC
            ");
            $st->execute([':id' => $subscription_id]);
            return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            return [];
        }
    }
}

if (!function_exists('ak_has_addon_custom_domain')) {
    /**
     * Vérifie si l'org a l'add-on "domaine personnalisé" actif (+10€/mois).
     *
     * @param PDO $pdo
     * @param int $org_id
     * @return bool
     */
    function ak_has_addon_custom_domain(PDO $pdo, int $org_id): bool {
        try {
            $st = $pdo->prepare("
                SELECT COUNT(*) FROM asso_subscription_addons
                WHERE org_id = :id AND addon_type = 'custom_domain' AND status = 'active'
            ");
            $st->execute([':id' => $org_id]);
            return (int)$st->fetchColumn() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('ak_calculate_total_monthly_price')) {
    /**
     * Calcule le prix mensuel total (plan + add-ons) d'une org.
     *
     * @param PDO $pdo
     * @param int $org_id
     * @return array ['plan_cents' => N, 'addons_cents' => N, 'total_cents' => N, 'addons' => [...]]
     */
    function ak_calculate_total_monthly_price(PDO $pdo, int $org_id): array {
        $result = ['plan_cents' => 0, 'addons_cents' => 0, 'total_cents' => 0, 'addons' => []];

        $sub = ak_get_org_active_subscription($pdo, $org_id);
        if ($sub) {
            $result['plan_cents'] = (int)($sub['price_cents_monthly'] ?? 0);
            $addons = ak_get_subscription_addons($pdo, (int)$sub['id']);
            $result['addons'] = $addons;
            foreach ($addons as $a) {
                $result['addons_cents'] += (int)($a['price_cents_monthly'] ?? 0);
            }
        }
        $result['total_cents'] = $result['plan_cents'] + $result['addons_cents'];
        return $result;
    }
}

if (!function_exists('ak_format_price_cents')) {
    /**
     * Formate un prix en centimes vers "49,99€".
     *
     * @param int $cents
     * @return string
     */
    function ak_format_price_cents(int $cents): string {
        // Typographie française : espaces insécables (milliers, et avant « € »), comme ak_price_eur
        return number_format($cents / 100, 2, ',', "\u{00A0}") . "\u{00A0}€";
    }
}

if (!function_exists('ak_stripe_log_event')) {
    /**
     * Log un événement Stripe dans asso_stripe_events.
     *
     * @param PDO $pdo
     * @param string $event_id
     * @param string $event_type
     * @param array $payload
     * @param int|null $org_id
     * @return bool
     */
    function ak_stripe_log_event(PDO $pdo, string $event_id, string $event_type, array $payload, ?int $org_id = null): bool {
        try {
            // Mode de l'événement (test / réel) gardé quand la colonne existe (migration 2026-10-28)
            $cols = function_exists('ak_db_columns') ? ak_db_columns($pdo, 'asso_stripe_events') : [];
            $lm = isset($cols['livemode']) && array_key_exists('livemode', $payload);
            $st = $pdo->prepare("
                INSERT IGNORE INTO asso_stripe_events
                  (stripe_event_id, event_type, org_id, payload_json, processing_status" . ($lm ? ", livemode" : "") . ")
                VALUES (:eid, :et, :oid, :pl, 'pending'" . ($lm ? ", :lm" : "") . ")
            ");
            $params = [
                ':eid' => $event_id,
                ':et' => $event_type,
                ':oid' => $org_id,
                ':pl' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ];
            if ($lm) $params[':lm'] = !empty($payload['livemode']) ? 1 : 0;
            $st->execute($params);
            return true;
        } catch (Throwable $e) {
            error_log('[ak_stripe_log_event] ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('ak_stripe_mark_event_processed')) {
    /**
     * Marque un événement Stripe comme traité.
     *
     * @param PDO $pdo
     * @param string $event_id
     * @param string $status processed|failed|ignored
     * @param string|null $error_message
     * @return bool
     */
    function ak_stripe_mark_event_processed(PDO $pdo, string $event_id, string $status = 'processed', ?string $error_message = null): bool {
        try {
            // processed = 1 seulement pour un événement terminé (traité ou ignoré), jamais pour un échec
            $st = $pdo->prepare("
                UPDATE asso_stripe_events
                SET processing_status = :st, error_message = :err, processed_at = NOW(), processed = :done
                WHERE stripe_event_id = :eid
            ");
            $st->execute([':st' => $status, ':err' => $error_message, ':done' => $status === 'failed' ? 0 : 1, ':eid' => $event_id]);
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('ak_stripe_api_call')) {
    /**
     * Wrapper pour appels API Stripe (utilise cURL, pas besoin du SDK Stripe pour MVP).
     *
     * @param PDO $pdo
     * @param string $method GET|POST|DELETE
     * @param string $endpoint Ex: '/customers' ou '/subscriptions/sub_xxx'
     * @param array $params Paramètres à envoyer
     * @param string|null $idempotency_key POST seulement : en-tête Idempotency-Key. Stripe renvoie la même
     *        réponse pour la même clé (24 h) : un double clic, une requête rejouée ou une nouvelle tentative
     *        après une coupure réseau ne créent jamais deux clients ni deux abonnements.
     * @return array ['ok' => bool, 'data' => array|null, 'error' => string|null, 'http_code' => int]
     */
    function ak_stripe_api_call(PDO $pdo, string $method, string $endpoint, array $params = [], ?string $idempotency_key = null): array {
        $sk = ak_stripe_get_secret_key($pdo);
        if (empty($sk)) {
            return ['ok' => false, 'data' => null, 'error' => 'Stripe non configuré', 'http_code' => 0];
        }

        $method = strtoupper($method);
        if (!in_array($method, ['GET', 'POST', 'DELETE'], true)) {
            return ['ok' => false, 'data' => null, 'error' => 'Méthode non supportée', 'http_code' => 0];
        }
        // https://api.stripe.com, sauf sur le banc de test (ak_stripe_api_base, stripe-config-helpers.php)
        $url = ak_stripe_api_base() . '/v1' . $endpoint;
        if ($method === 'GET' && !empty($params)) $url .= '?' . http_build_query($params);
        $headers = [
            'Authorization: Bearer ' . $sk,
            'Stripe-Version: 2024-06-20',
            'Content-Type: application/x-www-form-urlencoded',
        ];
        if ($method === 'POST' && $idempotency_key !== null && $idempotency_key !== '') {
            $headers[] = 'Idempotency-Key: ' . substr(preg_replace('/[^A-Za-z0-9_.:-]/', '', $idempotency_key), 0, 255);
        }

        // Coupure réseau : une seule nouvelle tentative, et seulement quand elle ne peut rien créer
        // deux fois (lecture, ou écriture protégée par une clé d'idempotence)
        $retry = $method === 'GET' || ($method === 'POST' && $idempotency_key) ? 1 : 0;
        for ($try = 0; ; $try++) {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            curl_setopt($ch, CURLOPT_URL, $url);
            if ($method === 'POST') {
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
            } elseif ($method === 'DELETE') {
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
            }
            $response = curl_exec($ch);
            $http_code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curl_err = curl_error($ch);
            curl_close($ch);
            if (($curl_err || $http_code >= 500) && $try < $retry) { usleep(400000); continue; }
            break;
        }

        if ($curl_err) {
            return ['ok' => false, 'data' => null, 'error' => 'cURL: ' . $curl_err, 'http_code' => 0];
        }

        $data = json_decode($response, true);
        if (!is_array($data)) {
            return ['ok' => false, 'data' => null, 'error' => 'Réponse JSON invalide', 'http_code' => $http_code];
        }

        if ($http_code >= 200 && $http_code < 300) {
            return ['ok' => true, 'data' => $data, 'error' => null, 'http_code' => $http_code];
        }

        $err = $data['error']['message'] ?? 'Erreur HTTP ' . $http_code;
        return ['ok' => false, 'data' => $data, 'error' => $err, 'http_code' => $http_code];
    }
}

if (!function_exists('ak_stripe_vat_tax_rate')) {
    /**
     * Taux de TVA Stripe (TaxRate « TVA », non inclus : ajouté au prix HT) pour $rate %, créé une seule
     * fois (POST /v1/tax_rates) puis gardé dans asso_stripe_config, un par mode (clés test / live) et par
     * taux : stripe_tax_rate_id_<mode>_<taux>. L'identifiant gardé est relu chez Stripe avant usage :
     * archivé ou différent (taux, inclus), un nouveau est créé.
     *
     * @return string|null identifiant txr_…, null si Stripe ne l'a pas fourni ($error : raison, pour le journal)
     */
    function ak_stripe_vat_tax_rate(PDO $pdo, float $rate, ?string &$error = null): ?string {
        $error = null;
        if ($rate <= 0 || $rate >= 100) { $error = 'taux invalide'; return null; }
        $pct = rtrim(rtrim(number_format($rate, 4, '.', ''), '0'), '.');
        $sk = (string)ak_stripe_get_secret_key($pdo);
        $mode = preg_match('/^(sk|rk)_live_/', $sk) ? 'live' : 'test';
        $key = 'stripe_tax_rate_id_' . $mode . '_' . str_replace('.', '_', $pct);

        $cached = (string)ak_stripe_config_get($pdo, $key, '');
        if ($cached !== '') {
            $r = ak_stripe_api_call($pdo, 'GET', '/tax_rates/' . rawurlencode($cached));
            $d = is_array($r['data'] ?? null) ? $r['data'] : [];
            if (!empty($r['ok'])) {
                if (!empty($d['active']) && empty($d['inclusive']) && abs((float)($d['percentage'] ?? -1) - (float)$pct) < 0.0001) {
                    return $cached;
                }
            } elseif ((int)($r['http_code'] ?? 0) !== 404) {
                // Stripe injoignable : pas de doublon créé à l'aveugle
                $error = 'lecture du taux ' . $cached . ' : ' . ($r['error'] ?? '?');
                return null;
            }
        }

        $r = ak_stripe_api_call($pdo, 'POST', '/tax_rates', [
            'display_name' => 'TVA',
            'description'  => 'TVA ' . $pct . ' % (Assokit)',
            'percentage'   => $pct,
            'inclusive'    => 'false',
            'jurisdiction' => 'FR',
            'country'      => 'FR',
            'tax_type'     => 'vat',
        ]);
        $id = (string)($r['data']['id'] ?? '');
        if (empty($r['ok']) || $id === '') {
            $error = 'création du taux ' . $pct . ' % : ' . ($r['error'] ?? 'réponse sans identifiant');
            return null;
        }
        if (!ak_stripe_config_set($pdo, $key, $id)) {
            error_log('[ak_stripe_vat_tax_rate] ' . $id . ' créé mais non enregistré (' . $key . ')');
        }
        return $id;
    }
}


if (!function_exists('ak_get_org_invoices_all')) {
    /**
     * [unified] Retourne toutes les factures abonnement Assokit pour une org :
     *   - subscription_invoices (manuelles, pre-Stripe)
     *   - asso_invoices_stripe (auto via webhook Stripe)
     *   - invoices (factures AK-AAAA-NNNNNN : cockpit Factures et renouvellements
     *     du cron), hors brouillons et hors spécimens du mode test
     * Format unifie compatible avec mon-asso-factures.php.
     * Chaque ligne contient `source` = 'manual', 'stripe' ou 'platform'.
     */
    function ak_get_org_invoices_all(PDO $pdo, int $org_id, int $limit = 200): array {
        $invoices = [];

        // 1. Stripe
        if (function_exists('ak_get_org_invoices_stripe')) {
            try {
                foreach (ak_get_org_invoices_stripe($pdo, $org_id, $limit) as $i) {
                    $i['source'] = 'stripe';
                    $invoices[] = $i;
                }
            } catch (Throwable $e) {
                error_log('[invoices_all] stripe: ' . $e->getMessage());
            }
        }

        // 2. Manuelles (subscription_invoices)
        try {
            $stmt = $pdo->prepare("
                SELECT id, invoice_number, period_start, period_end,
                       amount_ht, amount_tva, amount_ttc, status,
                       issued_at, due_date, paid_at, created_at
                FROM subscription_invoices
                WHERE org_id = ?
                ORDER BY created_at DESC
                LIMIT ?
            ");
            $stmt->bindValue(1, $org_id, PDO::PARAM_INT);
            $stmt->bindValue(2, $limit, PDO::PARAM_INT);
            $stmt->execute();

            $status_map = ['draft'=>'draft','sent'=>'open','paid'=>'paid','overdue'=>'open','cancelled'=>'void'];

            while ($row = $stmt->fetch()) {
                $invoices[] = [
                    'id' => (int)$row['id'],
                    'source' => 'manual',
                    'invoice_number' => $row['invoice_number'],
                    'amount_cents' => (int)round((float)$row['amount_ttc'] * 100),
                    'tax_cents' => (int)round((float)$row['amount_tva'] * 100),
                    'currency' => 'eur',
                    'status' => $status_map[$row['status']] ?? $row['status'],
                    'period_start' => $row['period_start'],
                    'period_end' => $row['period_end'],
                    'paid_at' => $row['paid_at'],
                    'due_date' => $row['due_date'],
                    'created_at' => $row['created_at'],
                    'invoice_pdf_url' => '/facture-abonnement-pdf?id=' . (int)$row['id'],
                    'hosted_invoice_url' => null,
                ];
            }
        } catch (Throwable $e) {
            error_log('[invoices_all] manual: ' . $e->getMessage());
        }

        // 3. Factures Assokit de la table invoices (colonnes du cockpit ou du cron selon le cas)
        try {
            if (!function_exists('ak_db_columns') && is_file(__DIR__ . '/trial-helpers.php')) require_once __DIR__ . '/trial-helpers.php';
            $ic = function_exists('ak_db_columns') ? ak_db_columns($pdo, 'invoices') : [];
            if ($ic && isset($ic['org_id'], $ic['invoice_number'], $ic['status'])) {
                $c = fn(string $col) => isset($ic[$col]) ? "i.$col" : 'NULL';
                $amount = isset($ic['amount_ttc_cents'])
                    ? (isset($ic['amount_cents']) ? 'COALESCE(NULLIF(i.amount_ttc_cents, 0), i.amount_cents)' : 'i.amount_ttc_cents')
                    : $c('amount_cents');
                $issued = isset($ic['issued_at']) ? (isset($ic['created_at']) ? 'COALESCE(i.issued_at, i.created_at)' : 'i.issued_at') : $c('created_at');
                $due    = isset($ic['due_at']) ? (isset($ic['due_date']) ? 'COALESCE(DATE(i.due_at), i.due_date)' : 'DATE(i.due_at)') : $c('due_date');
                $stmt = $pdo->prepare("
                    SELECT i.id, i.invoice_number, i.status, $amount AS amount_cents,
                           " . $c('amount_vat_cents') . " AS tax_cents,
                           " . $c('period_start') . " AS period_start, " . $c('period_end') . " AS period_end,
                           " . $c('paid_at') . " AS paid_at, $due AS due_date, $issued AS created_at,
                           " . $c('pdf_path') . " AS pdf_path,
                           " . (isset($ic['client_snapshot']) ? "(COALESCE(i.client_snapshot, '') <> '')" : '1') . " AS has_snapshot
                    FROM invoices i
                    WHERE i.org_id = ? AND i.status <> 'draft'
                    " . (isset($ic['is_test_mode']) ? 'AND COALESCE(i.is_test_mode, 0) = 0' : '') . "
                    ORDER BY $issued DESC, i.id DESC
                    LIMIT ?
                ");
                $stmt->bindValue(1, $org_id, PDO::PARAM_INT);
                $stmt->bindValue(2, $limit, PDO::PARAM_INT);
                $stmt->execute();

                $status_map = ['pending' => 'open', 'overdue' => 'open', 'paid' => 'paid', 'cancelled' => 'void', 'refunded' => 'void'];

                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    // PDF servi par /facture-abonnement-pdf (contrôle d'association et de rôle), jamais en lien direct.
                    // Facture sans coordonnées figées (ancien cron) : son PDF éventuel, régénéré à tort par
                    // l'écran « Modifier » (0,00 €, échéance 01/01/1970), n'est pas proposé.
                    $has_pdf = preg_match('/\.pdf$/i', (string)($row['pdf_path'] ?? '')) && !empty($row['has_snapshot']);
                    $invoices[] = [
                        'id' => (int)$row['id'],
                        'source' => 'platform',
                        'invoice_number' => $row['invoice_number'],
                        'amount_cents' => (int)$row['amount_cents'],
                        'tax_cents' => (int)$row['tax_cents'],
                        'currency' => 'eur',
                        'status' => $status_map[$row['status']] ?? $row['status'],
                        'period_start' => $row['period_start'],
                        'period_end' => $row['period_end'],
                        'paid_at' => $row['paid_at'],
                        'due_date' => $row['due_date'],
                        'created_at' => $row['created_at'],
                        'invoice_pdf_url' => $has_pdf ? '/facture-abonnement-pdf?source=platform&id=' . (int)$row['id'] : null,
                        'hosted_invoice_url' => null,
                    ];
                }
            }
        } catch (Throwable $e) {
            error_log('[invoices_all] platform: ' . $e->getMessage());
        }

        // 4. Tri par date desc
        usort($invoices, function($a, $b) {
            return strtotime($b['created_at'] ?? '0') - strtotime($a['created_at'] ?? '0');
        });

        return array_slice($invoices, 0, $limit);
    }
}

