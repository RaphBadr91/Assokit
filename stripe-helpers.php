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
            $st = $pdo->prepare("
                SELECT hosted_invoice_url
                FROM asso_invoices_stripe
                WHERE org_id = :id
                  AND status = 'open'
                  AND hosted_invoice_url IS NOT NULL
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
        // 1. Si Stripe configuré et facture impayée : URL hosted_invoice
        if (ak_stripe_is_configured($pdo)) {
            $invoice_url = ak_get_active_invoice_url($pdo, $org_id);
            if ($invoice_url) {
                return $invoice_url;
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
            $st = $pdo->prepare("
                SELECT * FROM asso_invoices_stripe
                WHERE org_id = :id
                ORDER BY created_at DESC
                LIMIT :lim
            ");
            $st->bindValue(':id', $org_id, PDO::PARAM_INT);
            $st->bindValue(':lim', $limit, PDO::PARAM_INT);
            $st->execute();
            return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
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
            $st = $pdo->prepare("
                SELECT s.*, p.name AS plan_name, p.slug AS plan_slug,
                       p.price_cents AS price_cents_monthly, p.stripe_price_id_monthly
                FROM asso_subscriptions s
                INNER JOIN asso_plans p ON p.id = s.plan_id
                WHERE s.org_id = :id
                ORDER BY s.id DESC
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
        return number_format($cents / 100, 2, ',', ' ') . '€';
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
            $st = $pdo->prepare("
                INSERT IGNORE INTO asso_stripe_events
                  (stripe_event_id, event_type, org_id, payload_json, processing_status)
                VALUES (:eid, :et, :oid, :pl, 'pending')
            ");
            $st->execute([
                ':eid' => $event_id,
                ':et' => $event_type,
                ':oid' => $org_id,
                ':pl' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
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
            $st = $pdo->prepare("
                UPDATE asso_stripe_events
                SET processing_status = :st, error_message = :err, processed_at = NOW(), processed = 1
                WHERE stripe_event_id = :eid
            ");
            $st->execute([':st' => $status, ':err' => $error_message, ':eid' => $event_id]);
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
     * @return array ['ok' => bool, 'data' => array|null, 'error' => string|null, 'http_code' => int]
     */
    function ak_stripe_api_call(PDO $pdo, string $method, string $endpoint, array $params = []): array {
        $sk = ak_stripe_get_secret_key($pdo);
        if (empty($sk)) {
            return ['ok' => false, 'data' => null, 'error' => 'Stripe non configuré', 'http_code' => 0];
        }

        $url = 'https://api.stripe.com/v1' . $endpoint;
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $sk,
            'Stripe-Version: 2024-06-20',
            'Content-Type: application/x-www-form-urlencoded',
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        $method = strtoupper($method);
        if ($method === 'GET') {
            if (!empty($params)) {
                $url .= '?' . http_build_query($params);
            }
            curl_setopt($ch, CURLOPT_URL, $url);
        } elseif ($method === 'POST') {
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
        } elseif ($method === 'DELETE') {
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
        } else {
            return ['ok' => false, 'data' => null, 'error' => 'Méthode non supportée', 'http_code' => 0];
        }

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_err = curl_error($ch);
        curl_close($ch);

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


if (!function_exists('ak_get_org_invoices_all')) {
    /**
     * [unified] Retourne toutes les factures abonnement Assokit pour une org :
     *   - subscription_invoices (manuelles, pre-Stripe)
     *   - asso_invoices_stripe (auto via webhook Stripe)
     * Format unifie compatible avec mon-asso-factures.php.
     * Chaque ligne contient `source` = 'manual' ou 'stripe'.
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

        // 3. Tri par date desc
        usort($invoices, function($a, $b) {
            return strtotime($b['created_at'] ?? '0') - strtotime($a['created_at'] ?? '0');
        });

        return array_slice($invoices, 0, $limit);
    }
}

