<?php
/**
 * stripe-sync-helpers.php
 * --------------------------------------------------------------
 * Synchronisation Stripe → base locale : UNE seule façon d'appliquer l'état d'un abonnement ou
 * d'une facture Stripe, utilisée par :
 *   - stripe-webhook.php (événements envoyés par Stripe) ;
 *   - mon-asso-paiement-success.php (retour du paiement : l'abonnement est activé tout de suite,
 *     sans attendre le webhook) ;
 *   - stripe-create-payment-intent.php (abonnement déjà payé chez Stripe mais pas encore connu ici).
 *
 * Principes :
 *   - l'abonnement est RELU chez Stripe avant d'être appliqué (ak_stripe_sync_subscription) : un
 *     événement en retard, rejoué ou reçu dans le désordre n'écrase jamais un état plus récent
 *     (avant : un « created / incomplete » arrivé après le paiement repassait l'abonnement en attente) ;
 *   - un verrou par association (GET_LOCK) : les événements simultanés de Stripe (created, updated,
 *     invoice.paid…) et la page de retour ne créent jamais deux lignes d'abonnement ;
 *   - écritures limitées aux colonnes présentes (ak_db_update / ak_db_insert, trial-helpers.php).
 *
 * Toutes les fonctions sont protégées par function_exists (fichier inclus par plusieurs pages).
 * --------------------------------------------------------------
 */

@require_once __DIR__ . '/stripe-config-helpers.php';
@require_once __DIR__ . '/stripe-helpers.php';
require_once __DIR__ . '/trial-helpers.php';

if (!defined('AK_STRIPE_ADDON_PRODUCT_ID')) define('AK_STRIPE_ADDON_PRODUCT_ID', 'ak_option_domaine');
if (!defined('AK_STRIPE_GRACE_DAYS')) define('AK_STRIPE_GRACE_DAYS', 15);
// Motifs de suspension (organizations.suspended_reason, en minuscules) que le paiement lève
// (ak_end_trial_after_payment) : fin de l'essai, impayé. Toute autre suspension (« Abus », « Fraude »…)
// reste en place, et aucun paiement n'est accepté tant qu'elle dure (ak_stripe_payment_refusal).
if (!defined('AK_SUSPENSION_TRIAL_REGEX')) define('AK_SUSPENSION_TRIAL_REGEX', "fin (de l|d).{0,2}essai|essai[^.;:]{0,30}(termin|expir|fini|[eé]chu|achev|clos)");
if (!defined('AK_SUSPENSION_UNPAID_REGEX')) define('AK_SUSPENSION_UNPAID_REGEX', "impay|retard de paiement|paiement en retard|d[eé]faut de paiement|non[- ]paiement");

if (!function_exists('ak_stripe_h')) {
    /** Échappement HTML des e-mails (sans dépendre de h() de config.php). */
    function ak_stripe_h($s): string {
        return htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('ak_stripe_plan_product_id')) {
    /** Identifiant du produit Stripe d'une formule (choisi par Assokit, le même en test et en réel). */
    function ak_stripe_plan_product_id(string $slug): string {
        return 'ak_formule_' . preg_replace('/[^a-z0-9_-]/', '_', strtolower($slug));
    }
}

if (!function_exists('ak_stripe_payment_method_types')) {
    /**
     * Moyens de paiement proposés : relevés par « Configurer Stripe automatiquement » (carte, plus
     * prélèvement SEPA s'il est activé sur le compte Stripe). Apple Pay et Google Pay font partie de « card ».
     * La page de paiement (paymentMethodTypes) et l'abonnement (payment_settings) utilisent la même liste.
     */
    function ak_stripe_payment_method_types(PDO $pdo): array {
        $raw = (string)ak_stripe_config_get($pdo, 'payment_method_types', 'card');
        $types = array_values(array_intersect(['card', 'sepa_debit'], array_map('trim', explode(',', $raw))));
        return $types ?: ['card'];
    }
}

if (!function_exists('ak_stripe_test_org_ids')) {
    /**
     * Associations autorisées à payer quand Stripe est en mode TEST (asso_stripe_config
     * « stripe_test_org_ids », réglée sur fondateur-stripe-config). Liste vide : les associations du
     * Fondateur. En mode test, les vraies associations ne voient pas le paiement : une vraie carte y est
     * refusée, et la carte de test publique 4242… offrirait un abonnement (puis le passage en réel le retire).
     * @return int[]
     */
    function ak_stripe_test_org_ids(PDO $pdo): array {
        $raw = (string)ak_stripe_config_get($pdo, 'stripe_test_org_ids', '');
        $ids = array_values(array_unique(array_filter(array_map('intval', preg_split('/[^0-9]+/', $raw)), fn($i) => $i > 0)));
        if ($ids) return $ids;
        try {
            if (!isset(ak_db_columns($pdo, 'users')['is_founder'])) return [];
            return array_map('intval', $pdo->query("SELECT DISTINCT org_id FROM users WHERE is_founder = 1 AND COALESCE(org_id, 0) > 0")->fetchAll(PDO::FETCH_COLUMN));
        } catch (Throwable $e) {
            return [];
        }
    }
}

if (!function_exists('ak_stripe_payment_refusal')) {
    /**
     * Raison pour laquelle cette association ne peut PAS souscrire par carte maintenant, null sinon.
     * Même règle pour la page de paiement (message à la place du formulaire) et pour
     * stripe-create-payment-intent.php (réponse JSON) :
     *   - espace fermé ou supprimé ;
     *   - suspension que le paiement ne lève pas (« Abus », « Fraude »… : seules la fin d'essai et
     *     l'impayé sont levées par un paiement, ak_end_trial_after_payment) ;
     *   - abonnement en cours réglé autrement que par carte (facture, virement, offert) : la période
     *     déjà facturée serait prélevée une seconde fois ;
     *   - Stripe en mode test et association hors de la liste de test (ak_stripe_test_org_ids).
     * @return array{code: int, kind: string, message: string}|null
     */
    function ak_stripe_payment_refusal(PDO $pdo, int $org_id): ?array {
        $ocols = ak_db_columns($pdo, 'organizations');
        try {
            $q = $pdo->prepare("SELECT status, trial_ends_at, "
                . (isset($ocols['suspended_reason']) ? "suspended_reason" : "NULL") . " AS suspended_reason, "
                . (isset($ocols['deleted_at']) ? "deleted_at" : "NULL") . " AS deleted_at FROM organizations WHERE id = ?");
            $q->execute([$org_id]);
            $o = $q->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            $o = null;
        }
        if (!$o || !empty($o['deleted_at']) || ($o['status'] ?? '') === 'cancelled') {
            return ['code' => 403, 'kind' => 'closed', 'message' => 'Cet espace est fermé : aucun paiement n’est possible. Contactez-nous si vous souhaitez le rouvrir.'];
        }
        if (($o['status'] ?? '') === 'suspended') {
            $reason = mb_strtolower(trim((string)($o['suspended_reason'] ?? '')), 'UTF-8');
            $by_trial = !empty($o['trial_ends_at'])
                && ($reason === '' || preg_match('/' . AK_SUSPENSION_TRIAL_REGEX . '|' . AK_SUSPENSION_UNPAID_REGEX . '/u', $reason));
            $by_unpaid = $reason !== '' && preg_match('/' . AK_SUSPENSION_UNPAID_REGEX . '/u', $reason);
            if (!$by_trial && !$by_unpaid) {
                return ['code' => 403, 'kind' => 'suspended', 'message' => 'Votre espace est suspendu : contactez le support avant tout paiement. Aucun montant n’a été prélevé.'];
            }
        }
        // Abonnement payé en cours, réglé sur facture, par virement ou offert
        $scols = ak_db_columns($pdo, 'asso_subscriptions');
        if (isset($scols['payment_mode'])) {
            try {
                $end = isset($scols['current_period_end']) ? " AND (s.current_period_end IS NULL OR s.current_period_end > NOW())" : "";
                $m = $pdo->prepare("SELECT 1 FROM asso_subscriptions s JOIN asso_plans p ON p.id = s.plan_id
                                    WHERE s.org_id = ? AND s.status = 'active' AND COALESCE(s.payment_mode, 'manual') <> 'stripe'
                                      AND NOT (" . ak_plan_trial_sql($pdo) . ") AND (COALESCE(p.price_cents, 0) > 0 OR p.slug = 'sur-mesure')$end
                                    LIMIT 1");
                $m->execute([$org_id]);
                if ($m->fetchColumn()) {
                    return ['code' => 409, 'kind' => 'manual', 'message' => 'Votre abonnement en cours est réglé sur facture : contactez-nous pour passer au paiement par carte, sans double facturation.'];
                }
            } catch (Throwable $e) {
                error_log('[stripe-sync] abonnement sur facture org #' . $org_id . ' : ' . get_class($e));
            }
        }
        if (ak_stripe_get_mode($pdo) === 'test' && !in_array($org_id, ak_stripe_test_org_ids($pdo), true)) {
            return ['code' => 403, 'kind' => 'test_mode', 'message' => 'Le paiement en ligne ouvre très bientôt. Contactez-nous si vous souhaitez activer votre abonnement dès maintenant.'];
        }
        return null;
    }
}

if (!function_exists('ak_stripe_invoice_footer')) {
    /**
     * Pied des factures Stripe de l'association (mentions obligatoires en France entre professionnels) :
     * identité du vendeur (Paramètres société : raison sociale, forme, capital, RCS, SIREN, adresse, n° de
     * TVA), TVA (franchise en base tant que RBPS n'est pas assujettie), conditions de paiement, pénalités
     * de retard, indemnité de 40 €. Le pied posé sur le client Stripe REMPLACE celui du compte Stripe
     * (il ne s'y ajoute pas) : tout doit donc y figurer.
     */
    function ak_stripe_invoice_footer(PDO $pdo): string {
        $vat = function_exists('ak_vat_applicable_rate') ? ak_vat_applicable_rate($pdo) : 0.0;
        $lines = [];
        if (!function_exists('ak_company') && is_file(__DIR__ . '/company-helpers.php')) require_once __DIR__ . '/company-helpers.php';
        if (function_exists('ak_company')) {
            $c = ak_company() ?: [];
            $name = trim((string)($c['legal_name'] ?? ''));
            if ($name !== '') {
                $l = $name;
                if (trim((string)($c['legal_form'] ?? '')) !== '') $l .= ', ' . trim((string)$c['legal_form']);
                if (!empty($c['capital_cents'])) $l .= ' au capital de ' . number_format((int)$c['capital_cents'] / 100, 0, ',', ' ') . ' €';
                $lines[] = $l;
            }
            $ids = [];
            if (trim((string)($c['rcs_city'] ?? '')) !== '' && trim((string)($c['rcs_number'] ?? '')) !== '') $ids[] = 'RCS ' . trim((string)$c['rcs_city']) . ' ' . trim((string)$c['rcs_number']);
            if (trim((string)($c['siret'] ?? '')) !== '') $ids[] = 'SIRET ' . trim((string)$c['siret']);
            elseif (trim((string)($c['siren'] ?? '')) !== '') $ids[] = 'SIREN ' . trim((string)$c['siren']);
            if ($vat > 0 && trim((string)($c['vat_number'] ?? '')) !== '') $ids[] = 'TVA intracommunautaire ' . trim((string)$c['vat_number']);
            if ($ids) $lines[] = implode(' · ', $ids);
            $addr = trim(implode(', ', array_filter([trim((string)($c['address_street'] ?? '')), trim((string)($c['address_complement'] ?? '')),
                trim(trim((string)($c['address_zip'] ?? '')) . ' ' . trim((string)($c['address_city'] ?? '')))])));
            if ($addr !== '') $lines[] = $addr;
        }
        if ($vat <= 0) $lines[] = 'TVA non applicable, art. 293 B du CGI.';
        $lines[] = 'Paiement à réception, par prélèvement automatique : pas d’escompte pour paiement anticipé.';
        $lines[] = 'En cas de retard de paiement : pénalités au taux de trois fois le taux d’intérêt légal et indemnité forfaitaire de 40 € pour frais de recouvrement (art. L441-10 du Code de commerce).';
        return mb_substr(implode("\n", $lines), 0, 4900);
    }
}

if (!function_exists('ak_stripe_refresh_customer_footers')) {
    /**
     * Pied de facture de TOUS les clients Stripe des associations remis à jour (TVA activée ou désactivée,
     * Paramètres société modifiés) : le pied est posé sur chaque client, il ne suit pas tout seul.
     * Client inconnu de Stripe (autre mode, supprimé) : ignoré. @return array{ok: int, failed: int}
     */
    function ak_stripe_refresh_customer_footers(PDO $pdo): array {
        $n = ['ok' => 0, 'failed' => 0];
        if (!isset(ak_db_columns($pdo, 'organizations')['stripe_customer_id'])) return $n;
        $footer = ak_stripe_invoice_footer($pdo);
        try {
            $ids = $pdo->query("SELECT id, stripe_customer_id FROM organizations WHERE COALESCE(stripe_customer_id, '') <> ''")->fetchAll(PDO::FETCH_KEY_PAIR);
        } catch (Throwable $e) {
            return $n;
        }
        foreach ($ids as $org_id => $cid) {
            // Sans clé d'idempotence : la mise à jour est sans risque à rejouer, et une clé fixe ferait
            // renvoyer par Stripe sa réponse en cache (24 h) sans rien appliquer quand le pied revient
            // à une valeur déjà posée (TVA activée, désactivée par erreur, puis réactivée)
            $r = ak_stripe_api_call($pdo, 'POST', '/customers/' . rawurlencode((string)$cid), ['invoice_settings[footer]' => $footer]);
            if (!empty($r['ok'])) $n['ok']++;
            elseif ((int)($r['http_code'] ?? 0) !== 404) {
                $n['failed']++;
                error_log('[stripe-sync] pied de facture org #' . (int)$org_id . ' : ' . ($r['error'] ?? '?'));
            }
        }
        return $n;
    }
}

if (!function_exists('ak_stripe_customer_params')) {
    /** Données du client Stripe d'une association : nom, e-mail de facturation, adresse, langue, pied de facture. */
    function ak_stripe_customer_params(PDO $pdo, array $org, array $user): array {
        $email = trim((string)($org['billing_email'] ?? '')) ?: trim((string)($user['email'] ?? ''));
        $name = trim((string)($org['legal_name'] ?? '')) ?: (trim((string)($org['name'] ?? '')) ?: trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')));
        $p = [
            'name' => mb_substr($name, 0, 250),
            'preferred_locales[0]' => 'fr',
            'invoice_settings[footer]' => ak_stripe_invoice_footer($pdo),
            'metadata[org_id]' => (string)(int)($org['id'] ?? 0),
        ];
        if (filter_var($email, FILTER_VALIDATE_EMAIL)) $p['email'] = $email;
        if (!empty($user['id'])) $p['metadata[user_id]'] = (string)(int)$user['id'];
        // Adresse de facturation (obligatoire sur une facture entre professionnels) quand elle est connue
        $line1 = trim((string)($org['billing_address_street'] ?? ''));
        $zip = trim((string)($org['billing_address_zip'] ?? ''));
        $city = trim((string)($org['billing_address_city'] ?? ''));
        if ($line1 !== '' && $city !== '') {
            $p['address[line1]'] = mb_substr($line1, 0, 200);
            $line2 = trim((string)($org['billing_address_complement'] ?? ''));
            if ($line2 !== '') $p['address[line2]'] = mb_substr($line2, 0, 200);
            if ($zip !== '') $p['address[postal_code]'] = $zip;
            $p['address[city]'] = mb_substr($city, 0, 100);
            $country = strtoupper(trim((string)($org['billing_address_country'] ?? '')));
            $map = ['' => 'FR', 'FRANCE' => 'FR', 'BELGIQUE' => 'BE', 'SUISSE' => 'CH', 'LUXEMBOURG' => 'LU', 'MONACO' => 'MC'];
            $country = $map[$country] ?? (preg_match('/^[A-Z]{2}$/', $country) ? $country : 'FR');
            $p['address[country]'] = $country;
        }
        return $p;
    }
}

if (!function_exists('ak_stripe_ensure_customer')) {
    /**
     * Client Stripe de l'association : mis à jour s'il existe, créé sinon (identifiant gardé dans
     * organizations.stripe_customer_id). Client inconnu de Stripe (supprimé, ou créé avec les clés de
     * test avant le passage en mode réel) : remplacé par un nouveau.
     * @return array{0: ?string, 1: ?string} [identifiant cus_…, raison de l'échec]
     */
    function ak_stripe_ensure_customer(PDO $pdo, int $org_id, array $user): array {
        $q = $pdo->prepare("SELECT * FROM organizations WHERE id = ?");
        $q->execute([$org_id]);
        $org = $q->fetch(PDO::FETCH_ASSOC) ?: ['id' => $org_id];
        $params = ak_stripe_customer_params($pdo, $org, $user + ['id' => $user['id'] ?? ($user['user_id'] ?? 0)]);
        $cid = trim((string)($org['stripe_customer_id'] ?? ''));
        if ($cid !== '') {
            $r = ak_stripe_api_call($pdo, 'POST', '/customers/' . rawurlencode($cid), $params);
            if (!empty($r['ok']) && empty($r['data']['deleted'])) return [$cid, null];
            if ((int)($r['http_code'] ?? 0) !== 404 && empty($r['data']['deleted'])) {
                return [null, 'client Stripe : ' . ($r['error'] ?? 'pas de réponse')];
            }
            error_log('[stripe] org #' . $org_id . ' : client ' . $cid . ' inconnu de Stripe (autre mode ou supprimé), remplacé');
            $pdo->prepare("UPDATE organizations SET stripe_customer_id = NULL WHERE id = ? AND stripe_customer_id = ?")->execute([$org_id, $cid]);
        }
        // Clé d'idempotence propre à CET appel : la nouvelle tentative après une coupure réseau (ak_stripe_api_call)
        // ne crée pas de doublon, mais une clé déjà vue ne rend jamais un client supprimé depuis (Stripe rejoue la
        // réponse d'une clé pendant 24 h). Deux demandes simultanées : verrou de stripe-create-payment-intent.php.
        $r = ak_stripe_api_call($pdo, 'POST', '/customers', $params,
            'ak-cus-' . ak_stripe_get_mode($pdo) . '-' . $org_id . '-' . bin2hex(random_bytes(8)));
        $new = (string)($r['data']['id'] ?? '');
        if (empty($r['ok']) || $new === '') return [null, 'client Stripe : ' . ($r['error'] ?? 'réponse sans identifiant')];
        // Deux demandes simultanées : la première enregistrée gagne (la clé d'idempotence rend d'ailleurs le même client)
        $pdo->prepare("UPDATE organizations SET stripe_customer_id = ? WHERE id = ? AND (stripe_customer_id IS NULL OR stripe_customer_id = '')")->execute([$new, $org_id]);
        $q = $pdo->prepare("SELECT stripe_customer_id FROM organizations WHERE id = ?");
        $q->execute([$org_id]);
        $saved = (string)$q->fetchColumn();
        return [$saved !== '' ? $saved : $new, null];
    }
}

if (!function_exists('ak_stripe_lock')) {
    /**
     * Verrou MariaDB nommé (une association à la fois) : deux livraisons simultanées de Stripe, ou
     * le webhook et la page de retour du paiement, n'écrivent jamais en même temps. true si obtenu
     * (ou si le serveur ne sait pas verrouiller : on continue, comme avant).
     */
    function ak_stripe_lock(PDO $pdo, string $name, int $timeout = 15): bool {
        try {
            $st = $pdo->prepare("SELECT GET_LOCK(?, ?)");
            $st->execute(['ak_stripe_' . $name, $timeout]);
            return (int)$st->fetchColumn() === 1;
        } catch (Throwable $e) {
            return true;
        }
    }
}

if (!function_exists('ak_stripe_unlock')) {
    function ak_stripe_unlock(PDO $pdo, string $name): void {
        try { $pdo->prepare("SELECT RELEASE_LOCK(?)")->execute(['ak_stripe_' . $name]); } catch (Throwable $e) {}
    }
}

if (!function_exists('ak_stripe_org_by_customer')) {
    /** Association d'un client Stripe (cus_…), 0 si inconnu. */
    function ak_stripe_org_by_customer(PDO $pdo, $customer): int {
        if (is_array($customer)) $customer = $customer['id'] ?? '';
        $customer = trim((string)$customer);
        if ($customer === '') return 0;
        try {
            $st = $pdo->prepare("SELECT id FROM organizations WHERE stripe_customer_id = ? ORDER BY id LIMIT 1");
            $st->execute([$customer]);
            return (int)$st->fetchColumn();
        } catch (Throwable $e) {
            return 0;
        }
    }
}

if (!function_exists('ak_stripe_obj_id')) {
    /** Identifiant d'un champ Stripe, développé (objet) ou non (chaîne). */
    function ak_stripe_obj_id($v): string {
        if (is_array($v)) return (string)($v['id'] ?? '');
        return is_string($v) ? $v : '';
    }
}

if (!function_exists('ak_stripe_invoice_sub_id')) {
    /**
     * Identifiant de l'abonnement Stripe d'une facture, quelle que soit la version d'API de l'endpoint
     * webhook : invoice.subscription (2024-06-20, celle d'ak_stripe_api_call), ou
     * invoice.parent.subscription_details.subscription (2025-03-31.basil et suivantes), ou la ligne de la
     * facture. Les événements suivent la version réglée sur l'endpoint, pas l'en-tête Stripe-Version
     * des appels (« Configurer Stripe automatiquement » crée l'endpoint en 2024-06-20).
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
}

if (!function_exists('ak_stripe_sub_period_end')) {
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
}

if (!function_exists('ak_stripe_sub_items')) {
    /**
     * Lecture des éléments d'un abonnement Stripe : la formule (prix, plan Assokit) et l'add-on
     * domaine. Une formule est reconnue par son prix (asso_plans.stripe_price_id_monthly), sinon par
     * son produit Stripe (ak_formule_<slug>, prix plus ancien remplacé depuis), sinon par
     * metadata[plan_slug] ; l'add-on par son prix (addon_domain_price_id) ou son produit.
     * @return array{plan_id: ?int, plan_price_id: ?string, addon_item: ?array}
     */
    function ak_stripe_sub_items(PDO $pdo, array $sub): array {
        $out = ['plan_id' => null, 'plan_price_id' => null, 'addon_item' => null];
        $addon_price = (string)ak_stripe_config_get($pdo, 'addon_domain_price_id', '');
        $has_col = isset(ak_db_columns($pdo, 'asso_plans')['stripe_price_id_monthly']);
        foreach (($sub['items']['data'] ?? []) as $item) {
            $price = $item['price'] ?? ($item['plan'] ?? []);
            $price_id = is_array($price) ? (string)($price['id'] ?? '') : (string)$price;
            $product = is_array($price) ? ak_stripe_obj_id($price['product'] ?? '') : '';
            if (($addon_price !== '' && $price_id === $addon_price) || $product === AK_STRIPE_ADDON_PRODUCT_ID) {
                $out['addon_item'] = $item;
                continue;
            }
            if ($out['plan_price_id'] !== null) continue;
            $out['plan_price_id'] = $price_id !== '' ? $price_id : null;
            if ($price_id !== '' && $has_col) {
                $sp = $pdo->prepare("SELECT id FROM asso_plans WHERE stripe_price_id_monthly = ? LIMIT 1");
                $sp->execute([$price_id]);
                $found = (int)$sp->fetchColumn();
                if ($found) { $out['plan_id'] = $found; continue; }
            }
            if (strpos($product, 'ak_formule_') === 0) {
                $sp = $pdo->prepare("SELECT id FROM asso_plans WHERE slug = ? LIMIT 1");
                $sp->execute([substr($product, strlen('ak_formule_'))]);
                $found = (int)$sp->fetchColumn();
                if ($found) $out['plan_id'] = $found;
            }
        }
        if (!$out['plan_id'] && !empty($sub['metadata']['plan_slug'])) {
            $sp = $pdo->prepare("SELECT id FROM asso_plans WHERE slug = ? LIMIT 1");
            $sp->execute([(string)$sub['metadata']['plan_slug']]);
            $found = (int)$sp->fetchColumn();
            if ($found) $out['plan_id'] = $found;
        }
        return $out;
    }
}

if (!function_exists('ak_stripe_fetch_subscription')) {
    /**
     * Abonnement tel qu'il est MAINTENANT chez Stripe (avec la dernière facture et son paiement).
     * @return array|null|false l'abonnement ; null s'il n'existe pas dans ce compte / ce mode (404) ;
     *         false si Stripe n'a pas répondu ($err : raison)
     */
    function ak_stripe_fetch_subscription(PDO $pdo, string $sub_id, ?string &$err = null) {
        $err = null;
        if (!preg_match('/^sub_[A-Za-z0-9_]+$/', $sub_id)) { $err = 'identifiant invalide'; return null; }
        $r = ak_stripe_api_call($pdo, 'GET', '/subscriptions/' . rawurlencode($sub_id), ['expand' => ['latest_invoice.payment_intent']]);
        if (!empty($r['ok']) && is_array($r['data'] ?? null)) return $r['data'];
        if ((int)($r['http_code'] ?? 0) === 404) return null;
        $err = (string)($r['error'] ?? 'réponse invalide');
        return false;
    }
}

if (!function_exists('ak_stripe_sync_subscription')) {
    /**
     * Relit un abonnement chez Stripe et l'applique (ak_handle_subscription_event). $fallback : l'objet
     * reçu dans l'événement, appliqué seulement si Stripe ne connaît pas (plus) l'abonnement.
     * Lève une RuntimeException si Stripe ne répond pas : le webhook répond alors 500 et Stripe
     * renvoie l'événement plus tard (rien n'est appliqué à partir d'un état peut-être périmé).
     * @return string|null statut local appliqué (active, overdue, cancelled, pending_payment) ou null
     */
    function ak_stripe_sync_subscription(PDO $pdo, string $sub_id, ?array $fallback = null, string $event_type = 'customer.subscription.updated'): ?string {
        $err = null;
        $sub = ak_stripe_fetch_subscription($pdo, $sub_id, $err);
        if ($sub === false) throw new RuntimeException('Stripe injoignable pour relire ' . $sub_id . ' : ' . $err);
        if ($sub === null) {
            if (!$fallback) return null;
            $sub = $fallback;
        }
        return ak_handle_subscription_event($pdo, $sub, $event_type);
    }
}

if (!function_exists('ak_stripe_local_status')) {
    /** Statut Stripe d'un abonnement → statut local d'asso_subscriptions. */
    function ak_stripe_local_status(string $stripe_status, bool $deleted = false): string {
        if ($deleted) return 'cancelled';
        switch ($stripe_status) {
            case 'active': case 'trialing': return 'active';
            case 'past_due': case 'unpaid': return 'overdue';
            case 'canceled': case 'incomplete_expired': return 'cancelled';
            default: return 'pending_payment';   // incomplete, paused
        }
    }
}

if (!function_exists('ak_handle_subscription_event')) {
    /**
     * Applique l'état d'un abonnement Stripe (customer.subscription.* ou abonnement relu chez Stripe).
     * @return string|null statut local appliqué, null si rien n'a été écrit
     */
    function ak_handle_subscription_event(PDO $pdo, array $sub, string $event_type): ?string {
        $stripe_sub_id = (string)($sub['id'] ?? '');
        if ($stripe_sub_id === '') return null;

        $org_id = (int)($sub['metadata']['org_id'] ?? 0);
        $customer_id = ak_stripe_obj_id($sub['customer'] ?? '');
        $by_customer = ak_stripe_org_by_customer($pdo, $customer_id);
        // metadata[org_id] vient d'Assokit, mais seul le client Stripe enregistré fait foi quand il existe
        if ($by_customer > 0) $org_id = $by_customer;
        if ($org_id <= 0) return null;

        if (!ak_stripe_lock($pdo, 'org_' . $org_id)) throw new RuntimeException('verrou association #' . $org_id . ' indisponible');
        try {
            return ak_stripe_apply_subscription($pdo, $org_id, $sub, $event_type);
        } finally {
            ak_stripe_unlock($pdo, 'org_' . $org_id);
        }
    }
}

if (!function_exists('ak_stripe_apply_subscription')) {
    /** Corps d'ak_handle_subscription_event, sous le verrou de l'association. */
    function ak_stripe_apply_subscription(PDO $pdo, int $org_id, array $sub, string $event_type): ?string {
        $stripe_sub_id = (string)$sub['id'];
        $stripe_status = (string)($sub['status'] ?? 'unknown');
        $local_status = ak_stripe_local_status($stripe_status, $event_type === 'customer.subscription.deleted');
        $items = ak_stripe_sub_items($pdo, $sub);
        $plan_id = $items['plan_id'];
        $stripe_price_id = $items['plan_price_id'];
        $cancel_at_period_end = !empty($sub['cancel_at_period_end']);
        $cpe_ts = ak_stripe_sub_period_end($sub);
        $current_period_end = $cpe_ts ? date('Y-m-d H:i:s', $cpe_ts) : null;
        $livemode = array_key_exists('livemode', $sub) ? (!empty($sub['livemode']) ? 1 : 0) : null;
        // Paiement du premier mois (dernière facture) : « processing » = prélèvement SEPA en cours de vérification
        $pi = $sub['latest_invoice']['payment_intent'] ?? null;
        $pi_status = is_array($pi) ? (string)($pi['status'] ?? '') : '';

        // Ligne locale de cet abonnement
        $check = $pdo->prepare("SELECT id, status, plan_id, "
            . (isset(ak_db_columns($pdo, 'asso_subscriptions')['grace_period_end']) ? "grace_period_end" : "NULL") . " AS grace_period_end
            FROM asso_subscriptions WHERE stripe_subscription_id = ? ORDER BY id DESC LIMIT 1");
        $check->execute([$stripe_sub_id]);
        $existing_row = $check->fetch(PDO::FETCH_ASSOC) ?: null;
        $existing_id = $existing_row ? (int)$existing_row['id'] : 0;
        $before = (string)($existing_row['status'] ?? '');

        // Premier paiement pas encore abouti : aucune ligne (elle naît au paiement réussi). Prélèvement
        // SEPA en cours de vérification (plusieurs jours) : noté sur l'association, pour que l'essai ne
        // se termine pas pendant ce temps et qu'aucun second abonnement ne soit proposé ni créé.
        if ($stripe_status === 'incomplete') {
            ak_stripe_set_payment_pending($pdo, $org_id, $pi_status === 'processing');
        }

        // Paiement par carte abandonné ou refusé avant tout paiement (incomplete → incomplete_expired, ou
        // abonnement supprimé alors que la ligne attendait encore son premier paiement) : ce n'était qu'une
        // tentative. Aucune ligne n'en reste ; la formule en place (essai, Démarrage, abonnement en cours)
        // ne change pas. L'événement reste dans asso_stripe_events, la facture annulée dans asso_invoices_stripe.
        if ($local_status === 'cancelled'
            && (in_array($stripe_status, ['incomplete', 'incomplete_expired'], true) || $before === 'pending_payment' || !$existing_id)) {
            if ($existing_id && $before === 'pending_payment') ak_stripe_drop_unpaid_attempt($pdo, $existing_id);
            if (!$existing_id || $before === 'pending_payment') {
                ak_stripe_set_payment_pending($pdo, $org_id, false);
                return null;
            }
        }

        if ($existing_id && ak_stripe_row_off_stripe_plan($pdo, $existing_id)) {
            // Le Fondateur a passé ce client sur un plan que Stripe ne facture pas (gratuit, essai, Sur-mesure…,
            // les données Stripe sont gardées) : Stripe ne décide plus de son statut. Une fois l'abonnement
            // annulé dans Stripe, la ligne cesse d'être « payée par carte » ; elle n'est jamais marquée résiliée
            // ni en retard pour autant (un client Sur-mesure resterait sinon « Résiliée »).
            if ($local_status === 'cancelled' && ak_db_accepts($pdo, 'asso_subscriptions', 'payment_mode', 'manual')) {
                $pdo->prepare("UPDATE asso_subscriptions SET payment_mode = 'manual' WHERE id = ?")->execute([$existing_id]);
            }
            return null;
        }

        if ($existing_id) {
            // Abonnement en retard de paiement arrêté par Stripe (fin des relances) : la période en cours n'a
            // jamais été payée. L'accès complet promis par l'e-mail « à régulariser » dure jusqu'à la fin de la
            // tolérance (grace_period_end) ; ensuite, ou tout de suite si elle est passée, plan Démarrage
            // (ak_sub_expire_cancelled, ici plus bas, puis pages et crons à la date)
            if ($local_status === 'cancelled' && $before === 'overdue') {
                $ge = (string)($existing_row['grace_period_end'] ?? '');
                $current_period_end = ($ge !== '' && strtotime($ge) > time()) ? date('Y-m-d H:i:s', strtotime($ge)) : date('Y-m-d H:i:s');
            }
            $data = [
                'status' => $local_status,
                'cancel_at_period_end' => $cancel_at_period_end ? 1 : 0,
                'current_period_end' => $current_period_end,
            ];
            // Date de résiliation : posée à l'arrêt, gardée pendant une résiliation programmée (page
            // « Annuler mon abonnement » ou portail), effacée si le client revient sur sa résiliation
            if ($local_status === 'cancelled') $data['cancelled_at'] = date('Y-m-d H:i:s');
            elseif (!$cancel_at_period_end) $data['cancelled_at'] = null;
            if ($stripe_price_id) $data['stripe_price_id'] = $stripe_price_id;
            if ($livemode !== null) $data['stripe_livemode'] = $livemode;
            // Plus de retard : la tolérance et les notes de relance de l'épisode terminé sont effacées
            if ($local_status === 'active') $data['grace_period_end'] = null;
            ak_db_update($pdo, 'asso_subscriptions', $existing_id, $data, ['updated_at' => 'NOW()']);
            if ($local_status === 'active' && $before === 'overdue') ak_stripe_clear_overdue_notes($pdo, $existing_id);
        } else {
            // Aucune ligne locale pour un abonnement pas encore payé (incomplete) ou déjà arrêté : la ligne
            // naît au premier paiement réussi (statut « active »), jamais d'une simple tentative
            if (!in_array($local_status, ['active', 'overdue'], true)) return null;
            // Association fermée ou supprimée depuis le clic sur « Payer » (prélèvement SEPA confirmé des jours
            // plus tard) : aucun abonnement ne démarre sur un espace fermé. Arrêté chez Stripe, le Fondateur
            // rembourse le premier prélèvement.
            $oc = ak_db_columns($pdo, 'organizations');
            $cq = $pdo->prepare("SELECT status" . (isset($oc['deleted_at']) ? ", deleted_at" : ", NULL AS deleted_at") . " FROM organizations WHERE id = ?");
            $cq->execute([$org_id]);
            $org_row = $cq->fetch(PDO::FETCH_ASSOC) ?: [];
            if (($org_row['status'] ?? '') === 'cancelled' || !empty($org_row['deleted_at'])) {
                $del = ak_stripe_api_call($pdo, 'DELETE', '/subscriptions/' . rawurlencode($stripe_sub_id));
                $stopped = !empty($del['ok']) || (int)($del['http_code'] ?? 0) === 404;
                ak_stripe_notify_founder($pdo, '⚠️ Paiement reçu pour une association fermée',
                    '<p>« ' . ak_stripe_h(ak_stripe_org_name($pdo, $org_id)) . ' » (fermée ou supprimée) vient de régler son premier paiement (abonnement '
                    . ak_stripe_h($stripe_sub_id) . ').</p><p>' . ($stopped ? 'L’abonnement a été arrêté chez Stripe : plus aucun prélèvement. ' : '<strong>L’abonnement n’a pas pu être arrêté : annulez-le dans Stripe.</strong> ')
                    . 'Remboursez le premier prélèvement dans le tableau de bord Stripe (Paiements › le paiement › Rembourser).</p>', $org_id);
                error_log('[stripe-sync] org #' . $org_id . ' fermée : abonnement ' . $stripe_sub_id . ($stopped ? ' arrêté' : ' NON arrêté'));
                return null;
            }
            // Un autre abonnement par carte est déjà en vigueur pour cette association : jamais écrasé
            // (il continuerait d'être prélevé sans que personne ne le voie). Le Fondateur est prévenu.
            $other = ak_org_live_stripe_subscription($pdo, $org_id);
            if ($other && (string)$other['stripe_subscription_id'] !== $stripe_sub_id) {
                ak_stripe_notify_founder($pdo, '⚠️ Deux abonnements Stripe pour une association',
                    '<p>L’association n°' . $org_id . ' a deux abonnements Stripe actifs : <strong>' . ak_stripe_h($other['stripe_subscription_id'])
                    . '</strong> (connu d’Assokit) et <strong>' . ak_stripe_h($stripe_sub_id) . '</strong> (nouveau).</p>'
                    . '<p>Annulez et remboursez le doublon dans le tableau de bord Stripe (Clients › le client › Abonnements).</p>', $org_id);
                error_log('[stripe-sync] org #' . $org_id . ' : second abonnement ' . $stripe_sub_id . ' ignoré (en place : ' . $other['stripe_subscription_id'] . ')');
                return null;
            }
            $data = [
                'org_id' => $org_id, 'plan_id' => $plan_id, 'stripe_subscription_id' => $stripe_sub_id,
                'stripe_price_id' => $stripe_price_id, 'payment_mode' => 'stripe', 'status' => $local_status,
                'current_period_end' => $current_period_end, 'cancel_at_period_end' => $cancel_at_period_end ? 1 : 0,
            ];
            if ($livemode !== null) $data['stripe_livemode'] = $livemode;
            try {
                $existing_id = ak_db_insert($pdo, 'asso_subscriptions', $data, ['created_at' => 'NOW()', 'started_at' => 'NOW()', 'updated_at' => 'NOW()']);
            } catch (PDOException $e) {
                // Un seul abonnement par association (index unique) : la ligne existante (essai, Démarrage ou
                // abonnement payé autrement) n'est reprise par Stripe que si l'abonnement est VRAIMENT payé.
                if ((string)$e->getCode() !== '23000') throw $e;
                if ($local_status !== 'active') return null;
                $cur = $pdo->prepare("SELECT s.id FROM asso_subscriptions s WHERE s.org_id = ? ORDER BY " . ak_sub_order('s') . " LIMIT 1");
                $cur->execute([$org_id]);
                $existing_id = (int)$cur->fetchColumn();
                if ($existing_id <= 0) throw $e;
                unset($data['org_id']);
                if (!$plan_id) unset($data['plan_id']);
                ak_db_update($pdo, 'asso_subscriptions', $existing_id, $data + ['grace_period_end' => null], ['updated_at' => 'NOW()']);
            }
        }

        // Abonnement payé : l'essai gratuit est terminé (sinon le cron d'essai suspendrait un client payant).
        // Abonnement qui devient actif (premier paiement, ou retard réglé) : c'est un paiement.
        $first_payment = in_array($before, ['', 'pending_payment'], true);
        $just_paid = $stripe_status === 'active' && ($first_payment || $before === 'overdue');
        if ($local_status === 'active') {
            ak_end_trial_after_payment($pdo, $org_id, $stripe_sub_id, $just_paid);
            if ($first_payment) ak_stripe_on_first_payment($pdo, $org_id, $plan_id, $sub);
        }

        // Abonnement arrêté par Stripe : à la fin de la période payée, l'association passe au plan gratuit
        // Démarrage (jamais la formule payante gardée indéfiniment) ; avant, cron et pages s'en chargent à la date
        if ($local_status === 'cancelled') {
            if ($before !== 'cancelled') {
                $unpaid = $before === 'overdue';
                ak_stripe_notify_founder($pdo, '🛑 Abonnement Stripe arrêté',
                    '<p>L’abonnement Stripe <strong>' . ak_stripe_h($stripe_sub_id) . '</strong> de « ' . ak_stripe_h(ak_stripe_org_name($pdo, $org_id)) . ' » est arrêté'
                    . ($unpaid ? ' après des paiements refusés (fin des relances de Stripe)' : '') . '.</p>'
                    . '<p>L’association passe au plan Démarrage ' . ($unpaid && $current_period_end ? 'le ' . date('d/m/Y', strtotime($current_period_end)) . ' (fin de la tolérance)' : 'à la fin de la période payée') . '.</p>'
                    . ($unpaid ? '<p>La dernière facture reste impayée chez Stripe : annulez-la par un avoir (Factures › la facture › Créer un avoir) pour qu’elle ne soit plus due.</p>' : ''), $org_id);
                // Arrêt que l'association n'a pas demandé (impayé, ou arrêt depuis Stripe) : confirmé par e-mail.
                // Une résiliation programmée par l'association a déjà été confirmée (ak_stripe_notify_resiliation).
                if ($unpaid || !$cancel_at_period_end) ak_stripe_notify_stop($pdo, $org_id, $stripe_sub_id, $current_period_end, $unpaid);
            }
            if (function_exists('ak_sub_expire_cancelled')) ak_sub_expire_cancelled($pdo, $org_id);
        } elseif ($cancel_at_period_end && $existing_row && $before !== 'cancelled') {
            // Résiliation programmée (page Assokit ou portail Stripe) : confirmée à l'association et signalée
            // au Fondateur, une seule fois par échéance
            ak_stripe_notify_resiliation($pdo, $org_id, $stripe_sub_id, $current_period_end);
        }

        // Add-on domaine (élément de l'abonnement) : seulement pour un abonnement payé (jamais un domaine
        // « actif » offert par un paiement en attente). Retiré de l'abonnement dans Stripe : désactivé ici.
        if ($existing_id && in_array($local_status, ['active', 'overdue'], true)) {
            ak_stripe_sync_addon($pdo, $org_id, $existing_id, $items['addon_item']);
        }
        return $local_status;
    }
}

if (!function_exists('ak_stripe_sync_addon')) {
    /** Ligne asso_subscription_addons de l'add-on domaine, alignée sur l'abonnement Stripe. */
    function ak_stripe_sync_addon(PDO $pdo, int $org_id, int $sub_local_id, ?array $item): void {
        try {
            $q = $pdo->prepare("SELECT id, status FROM asso_subscription_addons WHERE subscription_id = ? AND addon_type = 'custom_domain' ORDER BY id DESC LIMIT 1");
            $q->execute([$sub_local_id]);
            $row = $q->fetch(PDO::FETCH_ASSOC) ?: null;
            if ($item) {
                $price = $item['price'] ?? [];
                $data = ['status' => 'active', 'stripe_subscription_item_id' => $item['id'] ?? null,
                         'stripe_price_id' => is_array($price) ? ($price['id'] ?? null) : (string)$price];
                if ($row) {
                    ak_db_update($pdo, 'asso_subscription_addons', (int)$row['id'], $data);
                } else {
                    $amount = is_array($price) && isset($price['unit_amount']) ? (int)$price['unit_amount']
                        : (defined('AK_ADDON_DOMAIN_HT_CENTS') ? AK_ADDON_DOMAIN_HT_CENTS : 1000);
                    ak_db_insert($pdo, 'asso_subscription_addons', $data + [
                        'subscription_id' => $sub_local_id, 'org_id' => $org_id, 'addon_type' => 'custom_domain',
                        'addon_name' => 'Domaine personnalisé', 'price_cents_monthly' => $amount,
                    ], ['created_at' => 'NOW()']);
                    ak_stripe_notify_founder($pdo, '🏆 Domaine personnalisé à mettre en place',
                        '<p>« ' . ak_stripe_h(ak_stripe_org_name($pdo, $org_id)) . ' » a souscrit l’option Domaine personnalisé.</p>'
                        . '<p>Configurez son domaine dans le cockpit (Domaines).</p>', $org_id);
                }
            } elseif ($row && $row['status'] === 'active') {
                ak_db_update($pdo, 'asso_subscription_addons', (int)$row['id'], ['status' => 'cancelled']);
            }
        } catch (Throwable $e) {
            error_log('[stripe-sync] add-on org #' . $org_id . ' : ' . get_class($e) . ' ' . $e->getMessage());
        }
    }
}

if (!function_exists('ak_stripe_set_payment_pending')) {
    /**
     * Premier paiement SEPA en cours de vérification (organizations.stripe_payment_pending_until,
     * migration 2026-10-28) : posé pour 16 jours au plus, retiré au paiement réussi ou refusé.
     * Pendant ce temps l'essai ne se termine pas (ak_org_not_payment_pending_sql) et aucun second
     * abonnement n'est créé.
     */
    function ak_stripe_set_payment_pending(PDO $pdo, int $org_id, bool $pending): void {
        if (!isset(ak_db_columns($pdo, 'organizations')['stripe_payment_pending_until'])) return;
        try {
            if ($pending) {
                $pdo->prepare("UPDATE organizations SET stripe_payment_pending_until = DATE_ADD(NOW(), INTERVAL 16 DAY)
                               WHERE id = ? AND (stripe_payment_pending_until IS NULL OR stripe_payment_pending_until < NOW())")->execute([$org_id]);
            } else {
                $pdo->prepare("UPDATE organizations SET stripe_payment_pending_until = NULL WHERE id = ? AND stripe_payment_pending_until IS NOT NULL")->execute([$org_id]);
            }
        } catch (Throwable $e) {
            error_log('[stripe-sync] paiement en attente org #' . $org_id . ' : ' . get_class($e));
        }
    }
}

if (!function_exists('ak_stripe_payment_pending_until')) {
    /** Date limite d'un premier paiement SEPA en cours de vérification, null s'il n'y en a pas. */
    function ak_stripe_payment_pending_until(PDO $pdo, int $org_id): ?string {
        if (!isset(ak_db_columns($pdo, 'organizations')['stripe_payment_pending_until'])) return null;
        try {
            $q = $pdo->prepare("SELECT stripe_payment_pending_until FROM organizations WHERE id = ? AND stripe_payment_pending_until > NOW()");
            $q->execute([$org_id]);
            $v = $q->fetchColumn();
            return $v ? (string)$v : null;
        } catch (Throwable $e) {
            return null;
        }
    }
}

if (!function_exists('ak_stripe_clear_overdue_notes')) {
    /** Retard réglé : notes de relance de l'épisode terminé effacées (le prochain retard sera relancé). */
    function ak_stripe_clear_overdue_notes(PDO $pdo, int $row_id): void {
        if (!isset(ak_db_columns($pdo, 'asso_subscriptions')['notes'])) return;
        try {
            $pdo->prepare("UPDATE asso_subscriptions
                           SET notes = NULLIF(TRIM(REGEXP_REPLACE(COALESCE(notes, ''), '\\\\|(grace_expired_notified|overdue_mail_retry|overdue_mail_sent)\\\\|[0-9: -]*', '')), '')
                           WHERE id = ?")->execute([$row_id]);
        } catch (Throwable $e) {
            error_log('[stripe-sync] notes de relance #' . $row_id . ' : ' . get_class($e));
        }
    }
}

if (!function_exists('ak_handle_invoice_paid')) {
    /**
     * invoice.paid : facture enregistrée ; abonnement actif. Abonnement pas encore connu ici (premier
     * paiement, customer.subscription.updated pas encore reçu ou perdu) : relu chez Stripe et appliqué.
     */
    function ak_handle_invoice_paid(PDO $pdo, array $invoice): void {
        ak_handle_invoice_upsert($pdo, $invoice);
        $sub_id = ak_stripe_invoice_sub_id($invoice);
        if (!$sub_id) return;

        $o = $pdo->prepare("SELECT id, org_id, status FROM asso_subscriptions WHERE stripe_subscription_id = ? ORDER BY id DESC LIMIT 1");
        $o->execute([$sub_id]);
        $row = $o->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$row || in_array((string)$row['status'], ['pending_payment', 'overdue'], true)) {
            // L'état de l'abonnement chez Stripe fait foi (payé : actif ; une facture plus ancienne payée en
            // retard ne réactive pas un abonnement arrêté depuis)
            ak_stripe_sync_subscription($pdo, $sub_id);
            $o->execute([$sub_id]);
            $row = $o->fetch(PDO::FETCH_ASSOC) ?: null;
        }
        if (!$row) return;
        // Ligne passée à la main sur un plan que Stripe ne facture pas : ce paiement ne convertit rien
        if (!ak_stripe_row_off_stripe_plan($pdo, (int)$row['id']) && $row['status'] === 'active') {
            ak_end_trial_after_payment($pdo, (int)$row['org_id'], $sub_id, (int)($invoice['amount_paid'] ?? 0) > 0);
        }
    }
}

if (!function_exists('ak_end_trial_after_payment')) {
    /**
     * Fin de l'essai gratuit quand l'association paie : association et abonnement « historique »
     * repassent actifs, la ligne d'essai est close ; sur un vrai paiement ($paid : facture réglée, ou
     * abonnement qui devient actif), une suspension à la main pour impayé est levée. Jamais bloquant.
     */
    function ak_end_trial_after_payment(PDO $pdo, int $org_id, string $paid_stripe_sub_id, bool $paid = false): void {
        if ($org_id <= 0) return;
        ak_stripe_set_payment_pending($pdo, $org_id, false);
        try {
            // Association en essai, ou suspendue à la fin de l'essai (cron d'essai : sans motif, ou motif
            // « Fin de l'essai », « Essai terminé ») : elle repasse active, sans motif. Une suspension
            // décidée par le support pendant l'essai (« Abus », « Fraude »…) reste en place : le paiement
            // ne la lève pas. Une suspension pour impayé se lève sur un vrai paiement seulement ($paid).
            $ocols = ak_db_columns($pdo, 'organizations');
            if (isset($ocols['suspended_reason'])) {
                $lift = AK_SUSPENSION_TRIAL_REGEX . ($paid ? '|' . AK_SUSPENSION_UNPAID_REGEX : '');
                $pdo->prepare("UPDATE organizations SET status = 'active', suspended_reason = NULL
                               WHERE id = ? AND trial_ends_at IS NOT NULL
                                 AND (status = 'trial'
                                      OR (status = 'suspended' AND (TRIM(COALESCE(suspended_reason, '')) = ''
                                                                    OR LOWER(suspended_reason) REGEXP ?)))")
                    ->execute([$org_id, $lift]);
            } else {
                $pdo->prepare("UPDATE organizations SET status = 'active'
                               WHERE id = ? AND trial_ends_at IS NOT NULL AND status IN ('trial', 'suspended')")
                    ->execute([$org_id]);
            }
            // La fin d'essai est effacée quel que soit le statut : c'est le signe « converti ».
            $pdo->prepare("UPDATE organizations SET trial_ends_at = NULL WHERE id = ? AND trial_ends_at IS NOT NULL")->execute([$org_id]);
        } catch (Throwable $e) { error_log('[stripe-sync] fin essai org: ' . get_class($e)); }
        try {
            // Suspension à la main pour impayé : levée par le paiement, comme l'annonce la page de connexion.
            $ocols = ak_db_columns($pdo, 'organizations');
            if ($paid && isset($ocols['suspended_reason'])) {
                $pdo->prepare("UPDATE organizations SET status = 'active', suspended_reason = NULL
                               WHERE id = ? AND status = 'suspended' AND trial_ends_at IS NULL"
                               . (isset($ocols['deleted_at']) ? " AND deleted_at IS NULL" : "") . "
                                 AND LOWER(COALESCE(suspended_reason, '')) REGEXP ?")
                    ->execute([$org_id, AK_SUSPENSION_UNPAID_REGEX]);
            }
        } catch (Throwable $e) { error_log('[stripe-sync] levée suspension impayé: ' . get_class($e)); }
        try {
            $pdo->prepare("UPDATE subscriptions SET status = 'active' WHERE org_id = ? AND status IN ('trial', 'suspended')")->execute([$org_id]);
        } catch (Throwable $e) { error_log('[stripe-sync] fin essai subscriptions: ' . get_class($e)); }
        try {
            // Lignes d'essai restantes (statut « trial », ou plan d'essai écrit « active » quand l'ENUM n'a pas
            // « trial »), et Démarrage d'un essai terminé : l'abonnement payé devient la seule ligne vivante
            $pdo->prepare("UPDATE asso_subscriptions SET status = 'cancelled'
                           WHERE org_id = ? AND status IN ('trial', 'active')
                             AND (status = 'trial' OR plan_id IN (SELECT p.id FROM asso_plans p WHERE " . ak_plan_trial_sql($pdo) . "))
                             AND (stripe_subscription_id IS NULL OR stripe_subscription_id <> ?)")
                ->execute([$org_id, $paid_stripe_sub_id]);
        } catch (Throwable $e) { error_log('[stripe-sync] fin essai ligne d\'essai: ' . get_class($e)); }
        try {
            // Formule gratuite Démarrage restée active (essai terminé, ou ancien abonnement arrêté) : l'abonnement
            // payé la remplace, une seule ligne active (sans index unique par association, il en restait deux)
            $pdo->prepare("UPDATE asso_subscriptions SET status = 'cancelled'
                           WHERE org_id = ? AND status = 'active'
                             AND plan_id IN (SELECT p.id FROM asso_plans p WHERE p.slug = 'demarrage')
                             AND (stripe_subscription_id IS NULL OR stripe_subscription_id <> ?)
                             AND EXISTS (SELECT 1 FROM (SELECT id FROM asso_subscriptions WHERE org_id = ? AND stripe_subscription_id = ? AND status = 'active') x)")
                ->execute([$org_id, $paid_stripe_sub_id, $org_id, $paid_stripe_sub_id]);
        } catch (Throwable $e) { error_log('[stripe-sync] fin ligne Démarrage: ' . get_class($e)); }
        ak_org_sync_plan_label($pdo, $org_id);
    }
}

if (!function_exists('ak_stripe_row_off_stripe_plan')) {
    /**
     * La ligne d'abonnement est-elle sur un plan que Stripe ne facture pas : essai, gratuit, Sur-mesure /
     * sur devis, plan sans prix Stripe (ak_plan_off_stripe_sql, trial-helpers.php) ? Le Fondateur y a
     * passé ce client à la main : Stripe facture encore l'ancienne formule et ne décide plus du statut.
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
}

if (!function_exists('ak_handle_invoice_failed')) {
    /**
     * invoice.payment_failed / invoice.payment_action_required : facture enregistrée ; échéance d'un
     * abonnement déjà payé refusée (ou qui demande une confirmation 3-D Secure) → « en retard » avec
     * AK_STRIPE_GRACE_DAYS jours de tolérance, e-mail à l'association (une fois par facture) et alerte
     * au Fondateur. Un PREMIER paiement refusé n'est pas un impayé (aucune ligne, aucune relance).
     */
    function ak_handle_invoice_failed(PDO $pdo, array $invoice, bool $action_required = false): void {
        ak_handle_invoice_upsert($pdo, $invoice);
        $sub_id = ak_stripe_invoice_sub_id($invoice);
        if (!$sub_id) return;
        $org_id = ak_stripe_org_by_customer($pdo, $invoice['customer'] ?? '');

        if (($invoice['billing_reason'] ?? '') === 'subscription_create') {
            // Premier paiement refusé (carte refusée, SEPA rejeté) : plus de prélèvement en cours
            if ($org_id > 0) ak_stripe_set_payment_pending($pdo, $org_id, false);
            return;
        }

        // Les nouvelles tentatives de Stripe ne repoussent pas la fin de la période de grâce déjà ouverte.
        // Jamais sur une ligne passée sur un plan que Stripe ne facture pas (voir ak_stripe_row_off_stripe_plan).
        $grace_end = date('Y-m-d H:i:s', strtotime('+' . AK_STRIPE_GRACE_DAYS . ' days'));
        $upd = $pdo->prepare("
            UPDATE asso_subscriptions
            SET grace_period_end = IF(status = 'overdue' AND grace_period_end IS NOT NULL, grace_period_end, ?),
                status = 'overdue'" . (isset(ak_db_columns($pdo, 'asso_subscriptions')['updated_at']) ? ", updated_at = NOW()" : "") . "
            WHERE stripe_subscription_id = ?
              AND status IN ('active', 'overdue')
              AND (plan_id IS NULL OR plan_id NOT IN (SELECT p.id FROM asso_plans p WHERE " . ak_plan_off_stripe_sql($pdo) . "))
        ");
        $upd->execute([$grace_end, $sub_id]);
        // Seulement si une ligne d'abonnement payé est bien passée « en retard »
        $q = $pdo->prepare("SELECT COUNT(*) FROM asso_subscriptions WHERE stripe_subscription_id = ? AND status = 'overdue'");
        $q->execute([$sub_id]);
        if ((int)$q->fetchColumn() < 1 || $org_id <= 0) return;

        $inv_id = (string)($invoice['id'] ?? '');
        ak_stripe_once($pdo, $org_id, 'echec:' . $inv_id, function () use ($pdo, $org_id, $invoice, $sub_id, $action_required) {
            $q = $pdo->prepare("SELECT grace_period_end FROM asso_subscriptions WHERE stripe_subscription_id = ? ORDER BY id DESC LIMIT 1");
            $q->execute([$sub_id]);
            $ge = (string)$q->fetchColumn();
            $until = $ge !== '' && strtotime($ge) ? date('d/m/Y', strtotime($ge)) : '';
            $amount = ak_format_price_cents((int)($invoice['amount_due'] ?? 0));
            $why = $action_required
                ? 'Votre banque demande de confirmer le paiement (3-D Secure) de votre abonnement Assokit (' . $amount . ').'
                : 'Le prélèvement de votre abonnement Assokit (' . $amount . ') a été refusé par votre banque.';
            $sent = ak_stripe_notify_org($pdo, $org_id, '⚠️ Paiement de votre abonnement Assokit à régulariser',
                '<p>Bonjour,</p><p>' . ak_stripe_h($why) . '</p>'
                . '<p>Vous gardez l’accès complet à Assokit' . ($until !== '' ? ' jusqu’au <strong>' . ak_stripe_h($until) . '</strong>' : '')
                . ' : réglez la facture ou changez de carte depuis la page « Mon abonnement », le paiement est relancé tout de suite.</p>'
                . '<p>Sans régularisation' . ($until !== '' ? ' d’ici là' : '') . ', l’Assistant IA et la diffusion d’e-mails seront bloqués, puis, si les nouvelles tentatives de prélèvement échouent aussi, l’abonnement sera arrêté (accès limité, plan Démarrage). Vos données restent conservées.</p>',
                'Régulariser mon abonnement', 'https://assokit.fr/mon-asso-plan');
            ak_stripe_notify_founder($pdo, '💳 Paiement refusé',
                '<p>L’échéance de « ' . ak_stripe_h(ak_stripe_org_name($pdo, $org_id)) . ' » (' . ak_stripe_h($amount) . ', abonnement ' . ak_stripe_h($sub_id) . ') a été '
                . ($action_required ? 'bloquée en attente de confirmation 3-D Secure' : 'refusée') . '. '
                . 'L’association est « en retard de paiement »' . ($until !== '' ? ' jusqu’au ' . ak_stripe_h($until) : '') . ' ; Stripe retente le prélèvement.</p>', $org_id);
            return $sent;
        });
    }
}

if (!function_exists('ak_handle_invoice_upsert')) {
    /** INSERT/UPDATE de la facture dans asso_invoices_stripe (une ligne par facture). */
    function ak_handle_invoice_upsert(PDO $pdo, array $invoice): void {
        $invoice_id = (string)($invoice['id'] ?? '');
        if ($invoice_id === '') return;
        $customer_id = ak_stripe_obj_id($invoice['customer'] ?? '');
        $org_id = ak_stripe_org_by_customer($pdo, $customer_id);
        if ($org_id <= 0) return;
        $cols = ak_db_columns($pdo, 'asso_invoices_stripe');
        if (!$cols) throw new RuntimeException('table asso_invoices_stripe absente : lancez la migration 2026-10-28-stripe-mise-en-service.sql');

        // Montant : payé, sinon dû (brouillon, paiement refusé : amount_paid vaut 0, pas null)
        $amount = (int)($invoice['amount_paid'] ?? 0);
        if ($amount <= 0) $amount = (int)($invoice['amount_due'] ?? ($invoice['total'] ?? 0));
        // TVA : « tax » (API 2024-06-20), sinon la somme de total_taxes (versions plus récentes)
        $tax = $invoice['tax'] ?? null;
        if ($tax === null && !empty($invoice['total_taxes']) && is_array($invoice['total_taxes'])) {
            $tax = array_sum(array_map(fn($t) => (int)($t['amount'] ?? 0), $invoice['total_taxes']));
        }
        $data = [
            'org_id' => $org_id,
            'stripe_invoice_id' => $invoice_id,
            'stripe_customer_id' => $customer_id,
            'stripe_subscription_id' => ak_stripe_invoice_sub_id($invoice),
            'invoice_number' => ($invoice['number'] ?? '') !== '' ? (string)$invoice['number'] : null,
            'billing_reason' => ($invoice['billing_reason'] ?? '') !== '' ? (string)$invoice['billing_reason'] : null,
            'amount_cents' => $amount,
            'tax_cents' => (int)($tax ?? 0),
            'currency' => strtolower((string)($invoice['currency'] ?? 'eur')),
            'status' => (string)($invoice['status'] ?? 'open'),
            'hosted_invoice_url' => $invoice['hosted_invoice_url'] ?? null,
            'invoice_pdf_url' => $invoice['invoice_pdf'] ?? null,
            'paid_at' => !empty($invoice['status_transitions']['paid_at']) ? date('Y-m-d H:i:s', (int)$invoice['status_transitions']['paid_at']) : null,
            'due_date' => !empty($invoice['due_date']) ? date('Y-m-d', (int)$invoice['due_date']) : null,
            'period_start' => !empty($invoice['period_start']) ? date('Y-m-d H:i:s', (int)$invoice['period_start']) : null,
            'period_end' => !empty($invoice['period_end']) ? date('Y-m-d H:i:s', (int)$invoice['period_end']) : null,
            'description' => isset($invoice['description']) ? mb_substr((string)$invoice['description'], 0, 255) : null,
            'stripe_livemode' => array_key_exists('livemode', $invoice) ? (!empty($invoice['livemode']) ? 1 : 0) : null,
        ];
        // Seulement les colonnes présentes. Le numéro légal et la période n'existent qu'à la finalisation :
        // invoice.created (brouillon) arrive sans eux, invoice.finalized / invoice.paid les complètent
        // (jamais effacés par un événement plus pauvre). Un événement plus ancien (facture « open » reçue
        // après « paid ») ne fait pas revenir une facture payée en arrière.
        $names = []; $vals = []; $sets = [];
        foreach ($data as $k => $v) {
            if (!isset($cols[$k])) continue;
            $names[] = "`$k`"; $vals[] = ak_db_fit($cols, $k, $v);
            if ($k === 'org_id' || $k === 'stripe_invoice_id') continue;
            if ($k === 'status') $sets[] = "status = IF(status IN ('paid', 'void', 'uncollectible') AND VALUES(status) IN ('draft', 'open'), status, VALUES(status))";
            elseif (in_array($k, ['amount_cents', 'tax_cents', 'currency'], true)) $sets[] = "`$k` = VALUES(`$k`)";
            else $sets[] = "`$k` = COALESCE(VALUES(`$k`), `$k`)";
        }
        if (isset($cols['updated_at'])) $sets[] = 'updated_at = NOW()';
        $sql = "INSERT INTO asso_invoices_stripe (" . implode(', ', $names) . ") VALUES (" . implode(', ', array_fill(0, count($names), '?')) . ")
                ON DUPLICATE KEY UPDATE " . implode(', ', $sets);
        $pdo->prepare($sql)->execute($vals);
    }
}

if (!function_exists('ak_stripe_drop_unpaid_attempt')) {
    /**
     * Tentative de paiement par carte jamais aboutie (premier paiement abandonné ou refusé) : la ligne
     * « pending_payment » éventuelle est retirée, avec ses add-ons (jamais un domaine offert). Si la
     * suppression est refusée (contrainte), la ligne est close « cancelled » sans période payée.
     */
    function ak_stripe_drop_unpaid_attempt(PDO $pdo, int $row_id): void {
        try {
            $pdo->prepare("UPDATE asso_subscription_addons SET status = 'cancelled' WHERE subscription_id = ?")->execute([$row_id]);
        } catch (Throwable $e) { /* table d'add-ons absente */ }
        try {
            $pdo->prepare("DELETE FROM asso_subscriptions WHERE id = ? AND status = 'pending_payment'")->execute([$row_id]);
        } catch (Throwable $e) {
            error_log('[stripe-sync] tentative non payée #' . $row_id . ' : ' . get_class($e));
            ak_db_update($pdo, 'asso_subscriptions', $row_id, ['status' => 'cancelled', 'current_period_end' => null],
                ['cancelled_at' => 'NOW()', 'updated_at' => 'NOW()']);
        }
    }
}

if (!function_exists('ak_handle_customer_updated')) {
    /**
     * customer.updated : nouvelle carte par défaut du client (portail Stripe « Changer ma carte »). Les
     * abonnements créés par Assokit gardent LEUR carte (save_default_payment_method=on_subscription),
     * qui passe avant celle du client : sans cette mise à jour, les relances de Stripe repartaient sur
     * l'ancienne carte. La nouvelle carte devient celle de chaque abonnement en vigueur ; une échéance
     * restée impayée est relancée tout de suite.
     */
    function ak_handle_customer_updated(PDO $pdo, array $customer, array $previous): void {
        if (!array_key_exists('invoice_settings', $previous)) return;
        $pm = ak_stripe_obj_id($customer['invoice_settings']['default_payment_method'] ?? '');
        if ($pm === '') return;
        $org_id = ak_stripe_org_by_customer($pdo, $customer['id'] ?? '');
        if ($org_id <= 0) return;
        $st = $pdo->prepare("SELECT id, status, stripe_subscription_id FROM asso_subscriptions
                             WHERE org_id = ? AND COALESCE(stripe_subscription_id, '') <> '' AND status IN ('active', 'overdue')");
        $st->execute([$org_id]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $sid = (string)$row['stripe_subscription_id'];
            $r = ak_stripe_api_call($pdo, 'POST', '/subscriptions/' . rawurlencode($sid), ['default_payment_method' => $pm], 'ak-pm-' . $sid . '-' . $pm);
            if (empty($r['ok'])) {
                error_log('[stripe-sync] carte de l\'abonnement ' . $sid . ' : ' . ($r['error'] ?? '?'));
                continue;
            }
            if ($row['status'] !== 'overdue') continue;
            // Échéance impayée : nouvelle tentative avec la nouvelle carte (Stripe envoie ensuite invoice.paid)
            $cols = ak_stripe_invoice_cols($pdo);
            $q = $pdo->prepare("SELECT stripe_invoice_id FROM asso_invoices_stripe WHERE org_id = ? AND stripe_subscription_id = ? AND status = 'open'"
                . (isset($cols['billing_reason']) ? " AND COALESCE(billing_reason, '') <> 'subscription_create'" : '') . " ORDER BY id DESC LIMIT 1");
            $q->execute([$org_id, $sid]);
            $inv = (string)$q->fetchColumn();
            if ($inv === '') continue;
            $pay = ak_stripe_api_call($pdo, 'POST', '/invoices/' . rawurlencode($inv) . '/pay', [], 'ak-pay-' . $inv . '-' . $pm);
            if (!empty($pay['ok']) && ($pay['data']['status'] ?? '') === 'paid') {
                ak_handle_invoice_paid($pdo, $pay['data']);
            } else {
                error_log('[stripe-sync] nouvelle tentative ' . $inv . ' : ' . ($pay['error'] ?? 'non payée'));
            }
        }
    }
}

if (!function_exists('ak_handle_customer_deleted')) {
    /** customer.deleted : client supprimé dans Stripe, le prochain paiement en recrée un (plus de « No such customer »). */
    function ak_handle_customer_deleted(PDO $pdo, array $customer): void {
        $cid = (string)($customer['id'] ?? '');
        if ($cid === '') return;
        $pdo->prepare("UPDATE organizations SET stripe_customer_id = NULL WHERE stripe_customer_id = ?")->execute([$cid]);
    }
}

if (!function_exists('ak_handle_charge_event')) {
    /** charge.refunded / charge.dispute.created : alerte au Fondateur (rien ne change côté association). */
    function ak_handle_charge_event(PDO $pdo, array $obj, string $type): void {
        $customer = $obj['customer'] ?? '';
        if ($type === 'charge.dispute.created') {
            $charge = $obj['charge'] ?? '';
            $amount = ak_format_price_cents((int)($obj['amount'] ?? 0));
            ak_stripe_notify_founder($pdo, '🚨 Contestation de paiement (litige)',
                '<p>Un paiement de ' . ak_stripe_h($amount) . ' est contesté auprès de la banque (paiement ' . ak_stripe_h(ak_stripe_obj_id($charge)) . ', motif : '
                . ak_stripe_h((string)($obj['reason'] ?? '?')) . ').</p><p>Répondez dans le tableau de bord Stripe (Paiements › Litiges) avant la date limite indiquée.</p>', null);
            return;
        }
        $org_id = ak_stripe_org_by_customer($pdo, $customer);
        $amount = ak_format_price_cents((int)($obj['amount_refunded'] ?? 0));
        ak_stripe_notify_founder($pdo, '↩️ Remboursement Stripe',
            '<p>' . ak_stripe_h($amount) . ' remboursés' . ($org_id ? ' à « ' . ak_stripe_h(ak_stripe_org_name($pdo, $org_id)) . ' »' : '')
            . ' (paiement ' . ak_stripe_h((string)($obj['id'] ?? '')) . ').</p><p>L’abonnement n’est pas modifié : pour l’arrêter, annulez-le dans Stripe ou depuis le cockpit.</p>', $org_id ?: null);
    }
}

if (!function_exists('ak_stripe_on_first_payment')) {
    /** Premier paiement réussi : e-mail de bienvenue à l'association, alerte au Fondateur. */
    function ak_stripe_on_first_payment(PDO $pdo, int $org_id, ?int $plan_id, array $sub): void {
        ak_stripe_once($pdo, $org_id, 'actif:' . (string)$sub['id'], function () use ($pdo, $org_id, $plan_id, $sub) {
            $plan = 'Assokit';
            try {
                if ($plan_id) {
                    $q = $pdo->prepare("SELECT slug, name FROM asso_plans WHERE id = ?");
                    $q->execute([$plan_id]);
                    $p = $q->fetch(PDO::FETCH_ASSOC);
                    if ($p) $plan = ak_plan_label($p['slug'], $p['name']);
                }
            } catch (Throwable $e) {}
            $end = ak_stripe_sub_period_end($sub);
            $sepa = false;
            $pm_types = $sub['latest_invoice']['payment_intent']['payment_method_types'] ?? [];
            if (is_array($pm_types) && $pm_types === ['sepa_debit']) $sepa = true;
            $sent = ak_stripe_notify_org($pdo, $org_id, '✅ Votre abonnement Assokit est actif',
                '<p>Bonjour,</p><p>Merci ! Votre paiement est confirmé : le plan <strong>' . ak_stripe_h($plan) . '</strong> est actif pour « '
                . ak_stripe_h(ak_stripe_org_name($pdo, $org_id)) . ' ».</p>'
                . '<p>Le renouvellement est automatique chaque mois' . ($end ? ' (prochaine échéance le ' . date('d/m/Y', $end) . ')' : '')
                . '. Vos factures sont disponibles à tout moment dans « Mon abonnement › Mes factures ».</p>'
                . '<p>Sans engagement : vous pouvez résilier à tout moment, la résiliation prend effet à la fin du mois payé.</p>',
                'Voir mon abonnement', 'https://assokit.fr/mon-asso-plan');
            ak_stripe_notify_founder($pdo, '🎉 Nouvel abonnement payé',
                '<p>« ' . ak_stripe_h(ak_stripe_org_name($pdo, $org_id)) . ' » vient de payer le plan ' . ak_stripe_h($plan) . ' (abonnement ' . ak_stripe_h((string)$sub['id']) . ').</p>', $org_id);
            return $sent;
        });
    }
}

if (!function_exists('ak_stripe_notify_resiliation')) {
    /**
     * Résiliation programmée (page « Annuler mon abonnement », portail Stripe, ou résiliation d'un abonnement
     * payé hors Stripe) : confirmation à l'association sur un support durable (e-mail, avec la date de fin)
     * et alerte au Fondateur. Une seule fois par abonnement et par échéance. $ref : identifiant Stripe
     * (sub_…) ou « manuel-<ligne> » ; $end : fin d'accès 'Y-m-d H:i:s'.
     */
    function ak_stripe_notify_resiliation(PDO $pdo, int $org_id, string $ref, ?string $end): void {
        $end_fr = $end && strtotime($end) ? date('d/m/Y', strtotime($end)) : '';
        ak_stripe_once($pdo, $org_id, 'resil:' . $ref . ':' . ($end ? date('Ymd', strtotime($end)) : '-'), function () use ($pdo, $org_id, $ref, $end_fr) {
            $sent = ak_stripe_notify_org($pdo, $org_id, 'Résiliation de votre abonnement Assokit enregistrée',
                '<p>Bonjour,</p><p>Nous confirmons la résiliation de l’abonnement Assokit de « ' . ak_stripe_h(ak_stripe_org_name($pdo, $org_id)) . ' ».</p>'
                . '<p>Vous gardez votre formule et toutes ses fonctionnalités ' . ($end_fr !== '' ? 'jusqu’au <strong>' . ak_stripe_h($end_fr) . '</strong>' : 'jusqu’à la fin de la période payée')
                . ' : aucun nouveau prélèvement n’aura lieu. Ensuite, votre association passe en accès limité (plan Démarrage) ; vos données sont conservées.</p>'
                . '<p>Vous pouvez reprendre Assokit à tout moment depuis la page « Mon abonnement ».</p>',
                'Voir mon abonnement', 'https://assokit.fr/mon-asso-plan');
            ak_stripe_notify_founder($pdo, '🛑 Résiliation programmée',
                '<p>« ' . ak_stripe_h(ak_stripe_org_name($pdo, $org_id)) . ' » a résilié son abonnement (' . ak_stripe_h($ref) . ')'
                . ($end_fr !== '' ? ' : accès jusqu’au ' . ak_stripe_h($end_fr) : '') . '.</p>', $org_id);
            return $sent;
        });
    }
}

if (!function_exists('ak_stripe_notify_stop')) {
    /**
     * Abonnement arrêté sans résiliation de l'association : fin des relances après des paiements refusés
     * ($unpaid), arrêt depuis Stripe, ou « Arrêter mon abonnement » sur un retard. E-mail à l'association
     * (date, effets, comment reprendre), une seule fois par abonnement. $end : fin d'accès.
     */
    function ak_stripe_notify_stop(PDO $pdo, int $org_id, string $ref, ?string $end, bool $unpaid): void {
        ak_stripe_once($pdo, $org_id, 'arret:' . $ref, function () use ($pdo, $org_id, $end, $unpaid) {
            $later = $end && strtotime($end) > time() + 3600;
            $end_fr = $later ? date('d/m/Y', strtotime($end)) : '';
            return ak_stripe_notify_org($pdo, $org_id, 'Votre abonnement Assokit est arrêté',
                '<p>Bonjour,</p><p>L’abonnement Assokit de « ' . ak_stripe_h(ak_stripe_org_name($pdo, $org_id)) . ' » est arrêté'
                . ($unpaid ? ' : les paiements de la dernière échéance n’ont pas pu être prélevés' : '') . '. Aucun nouveau prélèvement n’aura lieu.</p>'
                . '<p>' . ($end_fr !== '' ? 'Vous gardez l’accès complet jusqu’au <strong>' . ak_stripe_h($end_fr) . '</strong>, puis votre association passe'
                                         : 'Votre association passe dès maintenant')
                . ' en accès limité (plan Démarrage) ; vos données sont conservées.</p>'
                . '<p>Pour retrouver toutes les fonctionnalités, reprenez Assokit à tout moment depuis la page « Mon abonnement ».</p>',
                'Reprendre Assokit', 'https://assokit.fr/mon-asso-plan');
        });
    }
}

if (!function_exists('ak_stripe_once')) {
    /**
     * Exécute $fn une seule fois pour ($org_id, $key) : carnet cron_envois (job « stripe »), comme les
     * rappels des crons. Carnet absent : exécuté (mieux vaut un e-mail en double qu'aucun).
     * $fn qui renvoie false (aucun e-mail parti : Resend indisponible) : la réservation est rendue, le
     * prochain envoi de l'événement par Stripe (ou une nouvelle tentative) réessaie, comme les crons d'essai.
     */
    function ak_stripe_once(PDO $pdo, int $org_id, string $key, callable $fn): void {
        $ok = true;
        $booked = false;
        $cle = mb_substr($key, 0, 60);
        try {
            $st = $pdo->prepare("INSERT IGNORE INTO cron_envois (job, org_id, cle) VALUES ('stripe', ?, ?)");
            $st->execute([max(0, $org_id), $cle]);
            $ok = $booked = $st->rowCount() === 1;
        } catch (Throwable $e) {
            $ok = true;
        }
        if (!$ok) return;
        $res = null;
        try { $res = $fn(); } catch (Throwable $e) { error_log('[stripe-sync] envoi ' . $key . ' : ' . get_class($e) . ' ' . $e->getMessage()); $res = false; }
        if ($res === false && $booked) {
            try {
                $pdo->prepare("DELETE FROM cron_envois WHERE job = 'stripe' AND org_id = ? AND cle = ?")->execute([max(0, $org_id), $cle]);
            } catch (Throwable $e) {}
        }
    }
}

if (!function_exists('ak_stripe_org_name')) {
    function ak_stripe_org_name(PDO $pdo, int $org_id): string {
        try {
            $q = $pdo->prepare("SELECT name FROM organizations WHERE id = ?");
            $q->execute([$org_id]);
            return (string)($q->fetchColumn() ?: ('Association n°' . $org_id));
        } catch (Throwable $e) {
            return 'Association n°' . $org_id;
        }
    }
}

if (!function_exists('ak_stripe_notify_org')) {
    /**
     * E-mail à l'association : adresse de facturation et administrateurs actifs (chacun une fois).
     * Jamais bloquant : un échec est noté dans le journal PHP. true si au moins un e-mail est parti
     * (ou s'il n'y a personne à prévenir : rien à réessayer).
     */
    function ak_stripe_notify_org(PDO $pdo, int $org_id, string $subject, string $content_html, ?string $cta_label = null, ?string $cta_url = null): bool {
        try {
            @require_once __DIR__ . '/resend-helper.php';
            if (!function_exists('send_transactional_email')) return false;
            $to = [];
            $q = $pdo->prepare("SELECT billing_email FROM organizations WHERE id = ?");
            $q->execute([$org_id]);
            $be = trim((string)$q->fetchColumn());
            if ($be !== '') $to[] = strtolower($be);
            $a = $pdo->prepare("SELECT email FROM users WHERE org_id = ? AND role = 'admin' AND is_active = 1");
            $a->execute([$org_id]);
            foreach ($a->fetchAll(PDO::FETCH_COLUMN) as $e) $to[] = strtolower(trim((string)$e));
            $to = array_values(array_unique(array_filter($to, fn($e) => filter_var($e, FILTER_VALIDATE_EMAIL))));
            $html = function_exists('email_wrap') ? email_wrap($subject, $content_html, $cta_label, $cta_url) : $content_html;
            $sent = !$to;
            foreach ($to as $dest) {
                $r = send_transactional_email($dest, $subject, $html, ['tag' => 'stripe_abonnement']);
                if (empty($r['success'])) error_log('[stripe-sync] e-mail org #' . $org_id . ' : ' . ($r['error'] ?? 'échec'));
                else $sent = true;
            }
            return $sent;
        } catch (Throwable $e) {
            error_log('[stripe-sync] e-mail org #' . $org_id . ' : ' . get_class($e));
            return false;
        }
    }
}

if (!function_exists('ak_stripe_notify_founder')) {
    /** Alerte au Fondateur : e-mail (ak_founder_emails) et cloche du cockpit. Jamais bloquant. */
    function ak_stripe_notify_founder(PDO $pdo, string $subject, string $content_html, ?int $org_id): void {
        try {
            @require_once __DIR__ . '/resend-helper.php';
            $link = $org_id ? '/super-admin/associations?id=' . (int)$org_id : '/fondateur-stripe-config';
            if (function_exists('send_transactional_email')) {
                $html = function_exists('email_wrap') ? email_wrap($subject, $content_html, 'Ouvrir le cockpit', 'https://assokit.fr' . $link) : $content_html;
                foreach (ak_founder_emails() as $dest) {
                    send_transactional_email($dest, '[Stripe] ' . $subject, $html, ['tag' => 'stripe_fondateur']);
                }
            }
            @require_once __DIR__ . '/notification-helpers.php';
            if (function_exists('ak_notif_create') && function_exists('ak_founder_user_ids')) {
                $text = trim(html_entity_decode(strip_tags(str_replace('</p>', ' ', $content_html)), ENT_QUOTES, 'UTF-8'));
                foreach (ak_founder_user_ids($pdo) as $uid) {
                    ak_notif_create($pdo, (int)$uid, 'stripe', $subject, mb_substr($text, 0, 240), $link);
                }
            }
        } catch (Throwable $e) {
            error_log('[stripe-sync] alerte fondateur : ' . get_class($e) . ' ' . $e->getMessage());
        }
    }
}
