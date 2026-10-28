<?php
/**
 * stripe-setup-helpers.php
 * --------------------------------------------------------------
 * « Configurer Stripe automatiquement » (fondateur-stripe-config.php) : tout ce que le Fondateur
 * devait créer à la main dans le tableau de bord Stripe, fait en un clic, en mode test ou en mode
 * réel selon les clés enregistrées :
 *   1. base de données prête (migration 2026-10-28-stripe-mise-en-service.sql) ;
 *   2. clés API valides et du même mode, compte Stripe ;
 *   3. moyens de paiement disponibles (carte + Apple Pay / Google Pay, prélèvement SEPA) ;
 *   4. produit + prix mensuel HT de chaque formule payante (asso_plans.price_cents) ;
 *   5. produit + prix de l'add-on « Domaine personnalisé » ;
 *   6. taux de TVA Stripe si la TVA est activée (ak_stripe_vat_tax_rate) ;
 *   7. point de terminaison webhook https://<site>/stripe-webhook, abonné exactement aux
 *      événements traités (AK_STRIPE_WEBHOOK_EVENTS), secret de signature gardé ;
 *   8. configuration du portail client (changer de carte, voir ses factures, résilier en fin de période) ;
 *   9. domaine des moyens de paiement (Apple Pay / Google Pay) ;
 *  10. en mode réel : données laissées par les essais en mode test effacées.
 *
 * Idempotent : relancé, il retrouve ce qu'il a créé (produits à identifiant fixe, prix par
 * lookup_key, webhook et portail par identifiant gardé) et ne crée jamais de doublon. Un prix dont
 * le montant a changé est remplacé par un nouveau prix pour les NOUVEAUX abonnements ; l'ancien est
 * archivé et continue d'être prélevé sur les abonnements déjà en place.
 * --------------------------------------------------------------
 */

@require_once __DIR__ . '/stripe-config-helpers.php';
@require_once __DIR__ . '/stripe-helpers.php';
require_once __DIR__ . '/trial-helpers.php';
require_once __DIR__ . '/stripe-sync-helpers.php';
if (!defined('AK_ADDON_DOMAIN_HT_CENTS')) require_once __DIR__ . '/includes-pricing.php';

if (!function_exists('ak_stripe_schema_check')) {
    /**
     * Tables et colonnes dont le code Stripe a besoin (migration 2026-10-28-stripe-mise-en-service.sql).
     * @return string[] éléments manquants (vide = base prête)
     */
    function ak_stripe_schema_check(PDO $pdo): array {
        $need = [
            'asso_stripe_config'       => ['config_key', 'config_value', 'updated_at'],
            'asso_stripe_events'       => ['stripe_event_id', 'event_type', 'payload_json', 'processing_status', 'error_message', 'processed', 'processed_at', 'claimed_at'],
            'asso_invoices_stripe'     => ['org_id', 'stripe_invoice_id', 'stripe_subscription_id', 'invoice_number', 'billing_reason', 'amount_cents', 'tax_cents', 'status', 'hosted_invoice_url', 'invoice_pdf_url', 'stripe_livemode'],
            'asso_plans'               => ['stripe_price_id_monthly'],
            'asso_subscriptions'       => ['payment_mode', 'stripe_subscription_id', 'stripe_price_id', 'stripe_livemode', 'current_period_end', 'cancel_at_period_end', 'cancelled_at', 'grace_period_end', 'updated_at'],
            'asso_subscription_addons' => ['stripe_price_id', 'stripe_subscription_item_id'],
            'organizations'            => ['stripe_customer_id', 'billing_email', 'stripe_payment_pending_until'],
        ];
        $missing = [];
        foreach ($need as $table => $cols) {
            $have = ak_db_columns($pdo, $table);
            if (!$have) { $missing[] = 'table ' . $table; continue; }
            foreach ($cols as $c) if (!isset($have[$c])) $missing[] = $table . '.' . $c;
        }
        $cid = ak_db_columns($pdo, 'organizations')['stripe_customer_id']['type'] ?? '';
        if ($cid !== '' && !preg_match('/char|text/', $cid)) $missing[] = 'organizations.stripe_customer_id en texte (actuellement ' . $cid . ')';
        // Une ligne par facture / par réglage / par événement (ON DUPLICATE KEY, INSERT IGNORE)
        foreach (['asso_invoices_stripe' => 'stripe_invoice_id', 'asso_stripe_config' => 'config_key', 'asso_stripe_events' => 'stripe_event_id'] as $t => $c) {
            if (!ak_db_columns($pdo, $t)) continue;
            try {
                $q = $pdo->prepare("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? AND NON_UNIQUE = 0 AND SEQ_IN_INDEX = 1");
                $q->execute([$t, $c]);
                if ((int)$q->fetchColumn() === 0) $missing[] = 'index unique ' . $t . '.' . $c;
            } catch (Throwable $e) { /* lecture d'information_schema refusée : non vérifié */ }
        }
        return $missing;
    }
}

if (!function_exists('ak_stripe_paid_plans')) {
    /** Formules vendues par carte : payantes, proposées, hors essai, hors Sur-mesure / sur devis. */
    function ak_stripe_paid_plans(PDO $pdo): array {
        $pcols = ak_db_columns($pdo, 'asso_plans');
        $rows = $pdo->query("SELECT * FROM asso_plans ORDER BY price_cents ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC);
        return array_values(array_filter($rows, function ($p) use ($pcols) {
            if ((int)($p['price_cents'] ?? 0) <= 0) return false;
            if (($p['slug'] ?? '') === 'sur-mesure' || ($p['slug'] ?? '') === 'pro-essai') return false;
            if (isset($pcols['is_custom_quote']) && !empty($p['is_custom_quote'])) return false;
            if (isset($pcols['is_trial']) && !empty($p['is_trial'])) return false;
            // Seules les formules proposées se souscrivent (stripe-create-payment-intent : is_visible = 1)
            if (isset($pcols['is_visible']) && empty($p['is_visible'])) return false;
            return true;
        }));
    }
}

if (!function_exists('ak_stripe_mask')) {
    /** « whsec_…a1b2 » : jamais un secret en entier à l'écran. */
    function ak_stripe_mask(?string $s): string {
        $s = (string)$s;
        if ($s === '') return '';
        $prefix = preg_match('/^([a-z]+_(?:test_|live_)?)/', $s, $m) ? $m[1] : '';
        return $prefix . '…' . substr($s, -4);
    }
}

if (!function_exists('ak_stripe_setup_product')) {
    /**
     * Produit Stripe à identifiant fixe (ak_formule_<slug>, ak_option_domaine) : relu, créé s'il manque,
     * réactivé / renommé s'il a changé. @return array{ok: bool, created: bool, error: ?string}
     */
    function ak_stripe_setup_product(PDO $pdo, string $id, string $name, string $description): array {
        $r = ak_stripe_api_call($pdo, 'GET', '/products/' . rawurlencode($id));
        if (!empty($r['ok'])) {
            $p = $r['data'];
            if (empty($p['active']) || ($p['name'] ?? '') !== $name) {
                $u = ak_stripe_api_call($pdo, 'POST', '/products/' . rawurlencode($id), ['active' => 'true', 'name' => $name, 'description' => $description]);
                if (empty($u['ok'])) return ['ok' => false, 'created' => false, 'error' => (string)($u['error'] ?? '?')];
            }
            return ['ok' => true, 'created' => false, 'error' => null];
        }
        if ((int)($r['http_code'] ?? 0) !== 404) return ['ok' => false, 'created' => false, 'error' => (string)($r['error'] ?? '?')];
        $c = ak_stripe_api_call($pdo, 'POST', '/products', [
            'id' => $id, 'name' => $name, 'description' => $description, 'metadata[ak]' => 'assokit',
        ], 'ak-setup-product-' . ak_stripe_get_mode($pdo) . '-' . $id);
        if (empty($c['ok'])) return ['ok' => false, 'created' => false, 'error' => (string)($c['error'] ?? '?')];
        return ['ok' => true, 'created' => true, 'error' => null];
    }
}

if (!function_exists('ak_stripe_price_matches')) {
    /** Le prix Stripe est-il bien : actif, en euros, mensuel, HT (TVA ajoutée), au montant voulu, du bon produit ? */
    function ak_stripe_price_matches(?array $p, string $product, int $amount): bool {
        if (!$p) return false;
        return !empty($p['active']) && (int)($p['unit_amount'] ?? -1) === $amount
            && strtolower((string)($p['currency'] ?? '')) === 'eur'
            && ($p['type'] ?? 'recurring') === 'recurring'
            && ($p['recurring']['interval'] ?? '') === 'month' && (int)($p['recurring']['interval_count'] ?? 1) === 1
            && ($p['tax_behavior'] ?? '') === 'exclusive'
            && ak_stripe_obj_id($p['product'] ?? '') === $product;
    }
}

if (!function_exists('ak_stripe_setup_price')) {
    /**
     * Prix mensuel HT d'un produit : retrouvé par sa lookup_key, créé s'il manque. Montant changé (ou
     * prix inutilisable : autre devise, autre périodicité, TTC) : un nouveau prix reprend la lookup_key
     * et sert aux NOUVEAUX abonnements ; l'ancien est archivé, et Stripe continue de le prélever sur les
     * abonnements déjà en place (ils gardent leur montant).
     * @return array{ok: bool, id: ?string, created: bool, archived: string[], error: ?string}
     */
    function ak_stripe_setup_price(PDO $pdo, string $product, string $lookup_key, int $amount, string $nickname, ?string $current_id): array {
        $out = ['ok' => false, 'id' => null, 'created' => false, 'archived' => [], 'error' => null];
        $old = [];
        $l = ak_stripe_api_call($pdo, 'GET', '/prices', ['lookup_keys' => [$lookup_key], 'limit' => 10]);
        if (empty($l['ok'])) { $out['error'] = (string)($l['error'] ?? '?'); return $out; }
        foreach (($l['data']['data'] ?? []) as $p) {
            if (ak_stripe_price_matches($p, $product, $amount)) {
                return ['ok' => true, 'id' => (string)$p['id'], 'created' => false, 'archived' => [], 'error' => null];
            }
            if (!empty($p['active'])) $old[] = (string)$p['id'];
        }
        // Prix enregistré dans Assokit (saisi à la main) encore valable : gardé, il reçoit la lookup_key
        if ($current_id && preg_match('/^price_[A-Za-z0-9]+$/', $current_id)) {
            $g = ak_stripe_api_call($pdo, 'GET', '/prices/' . rawurlencode($current_id));
            if (!empty($g['ok']) && ak_stripe_price_matches($g['data'], $product, $amount)) {
                ak_stripe_api_call($pdo, 'POST', '/prices/' . rawurlencode($current_id), ['lookup_key' => $lookup_key, 'transfer_lookup_key' => 'true']);
                return ['ok' => true, 'id' => $current_id, 'created' => false, 'archived' => [], 'error' => null];
            }
        }
        $c = ak_stripe_api_call($pdo, 'POST', '/prices', [
            'product' => $product, 'unit_amount' => (string)$amount, 'currency' => 'eur',
            'recurring[interval]' => 'month', 'recurring[interval_count]' => '1',
            'tax_behavior' => 'exclusive', 'lookup_key' => $lookup_key, 'transfer_lookup_key' => 'true',
            'nickname' => $nickname, 'metadata[ak]' => 'assokit',
        ], 'ak-setup-price-' . ak_stripe_get_mode($pdo) . '-' . $lookup_key . '-' . $amount . '-' . ($old[0] ?? 'aucun'));
        if (empty($c['ok']) || empty($c['data']['id'])) { $out['error'] = (string)($c['error'] ?? 'réponse sans identifiant'); return $out; }
        $new = (string)$c['data']['id'];
        // Ancien prix archivé : plus proposé aux nouveaux abonnements, toujours prélevé sur les anciens
        foreach (array_unique($old) as $oid) {
            if ($oid === $new) continue;
            $a = ak_stripe_api_call($pdo, 'POST', '/prices/' . rawurlencode($oid), ['active' => 'false']);
            if (!empty($a['ok'])) $out['archived'][] = $oid;
        }
        return ['ok' => true, 'id' => $new, 'created' => true, 'archived' => $out['archived'], 'error' => null];
    }
}

if (!function_exists('ak_stripe_purge_test_data')) {
    /**
     * Passage en mode réel : ce qu'ont laissé les essais en mode test est retiré. Les abonnements de
     * test (stripe_livemode = 0) sont arrêtés (l'association passe au plan Démarrage, comme une
     * résiliation), les factures de test effacées (elles apparaissaient comme de vraies factures).
     * Un identifiant client de test est remplacé automatiquement au prochain paiement (« No such customer »).
     * Prélèvement SEPA « en cours » : l'indication n'est retirée que pour un premier paiement de TEST
     * (facture de test ouverte, aucune facture réelle ouverte). Un vrai prélèvement en cours garde sa
     * protection (sinon le cron d'essai suspendait une association qui vient de payer) : la purge est
     * relancée à chaque « Configurer Stripe automatiquement » en mode réel.
     * @return array{subs: int, invoices: int}
     */
    function ak_stripe_purge_test_data(PDO $pdo): array {
        $n = ['subs' => 0, 'invoices' => 0];
        $scols = ak_db_columns($pdo, 'asso_subscriptions');
        if (isset($scols['stripe_livemode'])) {
            // Seulement les abonnements par carte encore en vigueur : une ligne déjà retirée est passée au
            // plan Démarrage, payée « manual », et n'est jamais reprise
            $pay = isset($scols['payment_mode']) ? "AND payment_mode = 'stripe'" : '';
            $st = $pdo->query("SELECT id, org_id FROM asso_subscriptions
                               WHERE stripe_livemode = 0 AND COALESCE(stripe_subscription_id, '') <> '' $pay
                                 AND status IN ('active', 'overdue', 'pending_payment')");
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                ak_db_update($pdo, 'asso_subscriptions', (int)$r['id'],
                    ['status' => 'cancelled', 'current_period_end' => date('Y-m-d H:i:s', time() - 60), 'cancel_at_period_end' => 0],
                    ['cancelled_at' => 'NOW()', 'updated_at' => 'NOW()']);
                if (isset($scols['notes'])) $pdo->prepare("UPDATE asso_subscriptions SET notes = CONCAT(COALESCE(notes, ''), '|test_stripe_efface|') WHERE id = ?")->execute([(int)$r['id']]);
                ak_sub_expire_cancelled($pdo, (int)$r['org_id']);
                $n['subs']++;
            }
        }
        $icols = ak_db_columns($pdo, 'asso_invoices_stripe');
        // Avant d'effacer les factures de test : elles disent quel « SEPA en cours » était un essai
        try {
            if (isset(ak_db_columns($pdo, 'organizations')['stripe_payment_pending_until'], $icols['stripe_livemode'], $icols['billing_reason'])) {
                $pdo->exec("UPDATE organizations o SET o.stripe_payment_pending_until = NULL
                            WHERE o.stripe_payment_pending_until IS NOT NULL
                              AND EXISTS (SELECT 1 FROM asso_invoices_stripe i WHERE i.org_id = o.id AND i.billing_reason = 'subscription_create'
                                            AND i.status = 'open' AND i.stripe_livemode = 0)
                              AND NOT EXISTS (SELECT 1 FROM asso_invoices_stripe i WHERE i.org_id = o.id AND i.billing_reason = 'subscription_create'
                                            AND i.status = 'open' AND i.stripe_livemode = 1)");
            }
        } catch (Throwable $e) {}
        if (isset($icols['stripe_livemode'])) {
            $n['invoices'] = (int)$pdo->exec("DELETE FROM asso_invoices_stripe WHERE stripe_livemode = 0");
        }
        return $n;
    }
}

if (!function_exists('ak_stripe_auto_setup')) {
    /**
     * « Configurer Stripe automatiquement ». $base : adresse du site (ak_stripe_site_base()).
     * @return array{ok: bool, mode: string, at: string, steps: array<int, array{label: string, state: string, detail: string, fix: string}>}
     *         state : ok (✓), ko (✕, à corriger), warn (⚠, possible sans), info
     */
    function ak_stripe_auto_setup(PDO $pdo, string $base): array {
        $steps = [];
        $add = function (string $label, string $state, string $detail, string $fix = '') use (&$steps) {
            $steps[] = ['label' => $label, 'state' => $state, 'detail' => $detail, 'fix' => $fix];
        };
        $mode = ak_stripe_get_mode($pdo);
        // Fonction classique (use par référence) : une fonction fléchée figerait la liste vide
        $res = function (bool $ok) use (&$steps, $mode) {
            return ['ok' => $ok, 'mode' => $mode, 'at' => date('Y-m-d H:i:s'), 'steps' => $steps];
        };
        $mode_fr = $mode === 'live' ? 'réel' : 'test';

        // 1. Base de données
        $missing = ak_stripe_schema_check($pdo);
        if ($missing) {
            $add('Base de données prête', 'ko', 'Il manque : ' . implode(', ', array_slice($missing, 0, 8)) . (count($missing) > 8 ? '…' : '') . '.',
                 'Sur le serveur, lancez : /usr/local/bin/php migrations/run.php 2026-10-28-stripe-mise-en-service.sql (voir docs/stripe-mise-en-service.md), puis recommencez.');
            return $res(false);
        }
        $add('Base de données prête', 'ok', 'Tables et colonnes des paiements en place.');

        // Une seule configuration à la fois (double clic, deux onglets) : jamais de doublon
        if (!ak_stripe_lock($pdo, 'setup', 2)) {
            $add('Configuration', 'ko', 'Une configuration est déjà en cours.', 'Attendez quelques secondes puis rechargez la page.');
            return $res(false);
        }
        try {
            $all_ok = true;
            // 2. Clés et compte
            $sk = (string)ak_stripe_get_secret_key($pdo);
            $pk = (string)ak_stripe_get_publishable_key($pdo);
            if ($sk === '' || $pk === '') {
                $add('Clés API', 'ko', 'Clé ' . ($sk === '' ? 'secrète' : 'publique') . ' manquante.',
                     'Copiez vos clés depuis le tableau de bord Stripe (Développeurs › Clés API) dans le bloc « Clés API » ci-dessous.');
                return $res(false);
            }
            if (ak_stripe_key_mode($pk) !== ak_stripe_key_mode($sk)) {
                $add('Clés API', 'ko', 'La clé publique et la clé secrète ne sont pas du même mode (test / réel).',
                     'Recopiez les deux clés depuis la même page du tableau de bord Stripe (interrupteur « Mode test » identique).');
                return $res(false);
            }
            $acc = ak_stripe_api_call($pdo, 'GET', '/account');
            if (empty($acc['ok'])) {
                $add('Clés API', 'ko', 'Stripe refuse la clé secrète : ' . ($acc['error'] ?? 'pas de réponse') . '.',
                     (int)($acc['http_code'] ?? 0) === 0 ? 'Le serveur n’arrive pas à joindre Stripe : réessayez dans quelques minutes.'
                     : 'Recopiez la clé secrète (sk_' . ($mode === 'live' ? 'live' : 'test') . '_…) depuis Développeurs › Clés API. Une clé restreinte (rk_…) doit avoir les droits d’écriture sur Produits, Prix, Abonnements, Clients, Webhooks et Portail client.');
                return $res(false);
            }
            $account = $acc['data'];
            $add('Clés API', 'ok', 'Clés du mode ' . $mode_fr . ' acceptées par Stripe.');
            $name = (string)($account['settings']['dashboard']['display_name'] ?? ($account['business_profile']['name'] ?? ''));
            $desc = (string)($account['settings']['payments']['statement_descriptor'] ?? '');
            if ($mode === 'live' && empty($account['charges_enabled'])) {
                $all_ok = false;
                $add('Compte Stripe activé', 'ko', 'Le compte' . ($name !== '' ? ' « ' . $name . ' »' : '') . ' ne peut pas encore encaisser de paiements réels.',
                     'Terminez l’activation dans le tableau de bord Stripe (bandeau « Activer les paiements » : entreprise RBPS, SIREN, représentant, IBAN).');
            } else {
                $add('Compte Stripe', (strtoupper($desc) === 'ASSOKIT' || ($desc === '' && $mode === 'test')) ? 'ok' : 'warn',
                     ($name !== '' ? '« ' . $name . ' »' : 'Compte') . ($desc !== '' ? ', libellé sur les relevés bancaires : ' . $desc : '') . '.',
                     strtoupper($desc) === 'ASSOKIT' ? '' : 'Conseillé : libellé de relevé bancaire « ASSOKIT » (Paramètres › Détails publics, voir le guide).');
            }

            // 3. Moyens de paiement
            $caps = $account['capabilities'] ?? [];
            $types = ['card'];
            if (($caps['card_payments'] ?? 'active') !== 'active') {
                $all_ok = false;
                $add('Paiement par carte', 'ko', 'La carte bancaire n’est pas encore activée sur le compte (' . ($caps['card_payments'] ?? '?') . ').',
                     'Terminez l’activation du compte Stripe, puis recommencez.');
            } else {
                $add('Carte bancaire, Apple Pay, Google Pay', 'ok', 'Proposés sur la page de paiement.');
            }
            if (($caps['sepa_debit_payments'] ?? '') === 'active') {
                $types[] = 'sepa_debit';
                $add('Prélèvement SEPA', 'ok', 'Proposé sur la page de paiement (confirmation par la banque en quelques jours).');
            } else {
                $add('Prélèvement SEPA', 'warn', 'Non activé sur le compte Stripe : seuls la carte, Apple Pay et Google Pay sont proposés.',
                     'Pour le proposer : Paramètres › Moyens de paiement › Prélèvement SEPA › Activer, puis recommencez.');
            }
            ak_stripe_config_set($pdo, 'payment_method_types', implode(',', $types));

            // 4. Formules payantes : produit + prix mensuel HT
            $plans = ak_stripe_paid_plans($pdo);
            if (!$plans) $all_ok = false;
            if (!$plans) {
                $add('Formules', 'ko', 'Aucune formule payante (prix mensuel HT > 0) dans Assokit.', 'Renseignez le prix de la formule Assokit dans Pilotage › Plans tarifaires.');
            }
            foreach ($plans as $p) {
                $slug = (string)$p['slug'];
                $label = ak_plan_label($slug, $p['name'] ?? null);
                $prod = ak_stripe_plan_product_id($slug);
                $pr = ak_stripe_setup_product($pdo, $prod, $label, 'Abonnement mensuel Assokit — formule ' . $label);
                if (!$pr['ok']) {
                    $all_ok = false;
                    $add('Formule ' . $label, 'ko', 'Produit Stripe non créé : ' . $pr['error'] . '.', 'Réessayez ; si l’erreur revient, vérifiez les droits de la clé.');
                    continue;
                }
                $amount = (int)$p['price_cents'];
                $price = ak_stripe_setup_price($pdo, $prod, $prod . '_mensuel', $amount, $label . ' — ' . ak_format_price_cents($amount) . ' HT / mois', $p['stripe_price_id_monthly'] ?? null);
                if (!$price['ok']) {
                    $all_ok = false;
                    $add('Formule ' . $label, 'ko', 'Prix Stripe non créé : ' . $price['error'] . '.', 'Réessayez dans quelques minutes.');
                    continue;
                }
                $pdo->prepare("UPDATE asso_plans SET stripe_price_id_monthly = ? WHERE id = ?")->execute([$price['id'], (int)$p['id']]);
                $add('Formule ' . $label, 'ok', ak_format_price_cents($amount) . ' HT par mois, en euros (prix ' . $price['id'] . ($price['created'] ? ', créé' : ', déjà en place') . ').'
                    . ($price['archived'] ? ' Ancien prix ' . implode(', ', $price['archived']) . ' archivé : les associations déjà abonnées gardent leur montant, les nouvelles paient le nouveau.' : ''));
            }
            // Les autres formules (Démarrage, Essai, Sur-mesure) ne sont jamais vendues par carte : aucun prix Stripe
            $paid_ids = array_map(fn($p) => (int)$p['id'], $plans);
            $q = $pdo->query("SELECT id FROM asso_plans WHERE COALESCE(stripe_price_id_monthly, '') <> ''");
            foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $pid) {
                if (!in_array((int)$pid, $paid_ids, true)) $pdo->prepare("UPDATE asso_plans SET stripe_price_id_monthly = NULL WHERE id = ?")->execute([(int)$pid]);
            }

            // 5. Add-on Domaine personnalisé
            $pr = ak_stripe_setup_product($pdo, AK_STRIPE_ADDON_PRODUCT_ID, 'Domaine personnalisé', 'Option Assokit : votre propre nom de domaine (adherents.votre-asso.fr)');
            $price = $pr['ok'] ? ak_stripe_setup_price($pdo, AK_STRIPE_ADDON_PRODUCT_ID, AK_STRIPE_ADDON_PRODUCT_ID . '_mensuel', AK_ADDON_DOMAIN_HT_CENTS,
                'Domaine personnalisé — ' . ak_format_price_cents(AK_ADDON_DOMAIN_HT_CENTS) . ' HT / mois', ak_stripe_config_get($pdo, 'addon_domain_price_id', null))
                : ['ok' => false, 'error' => $pr['error']];
            if ($price['ok']) {
                ak_stripe_config_set($pdo, 'addon_domain_price_id', $price['id']);
                $add('Option Domaine personnalisé', 'ok', ak_format_price_cents(AK_ADDON_DOMAIN_HT_CENTS) . ' HT par mois (prix ' . $price['id'] . ').');
            } else {
                $add('Option Domaine personnalisé', 'warn', 'Non créée : ' . $price['error'] . '.', 'Les abonnements fonctionnent sans ; réessayez plus tard pour proposer l’option.');
            }

            // 6. TVA
            $vat = ak_vat_applicable_rate($pdo);
            if ($vat > 0) {
                $err = null;
                $txr = ak_stripe_vat_tax_rate($pdo, $vat, $err);
                if ($txr) $add('TVA', 'ok', 'TVA ' . rtrim(rtrim(number_format($vat, 2, ',', ''), '0'), ',') . ' % ajoutée au prix HT des nouveaux abonnements (taux Stripe ' . $txr . ').');
                else { $all_ok = false; $add('TVA', 'ko', 'Taux de TVA Stripe non créé : ' . $err . '.', 'Réessayez ; sans ce taux, aucun abonnement ne peut être créé tant que la TVA est activée.'); }
            } else {
                $add('TVA', 'info', 'TVA non applicable (franchise en base, art. 293 B du CGI) : les prix HT sont prélevés tels quels. La mention « TVA non applicable, art. 293 B du CGI » est écrite par Assokit sur chaque client Stripe : rien à faire.');
            }

            // 6 bis. Pied de facture des clients Stripe existants (identité de RBPS, mention de TVA)
            if (function_exists('ak_stripe_refresh_customer_footers')) {
                $ft = ak_stripe_refresh_customer_footers($pdo);
                $co = function_exists('ak_company') ? (ak_company() ?: []) : [];
                $who = trim((string)($co['legal_name'] ?? '')) !== '' && (trim((string)($co['siren'] ?? '')) !== '' || trim((string)($co['siret'] ?? '')) !== '');
                if ($ft['failed']) {
                    $add('Mentions des factures', 'warn', $ft['failed'] . ' client(s) Stripe n’ont pas pu être mis à jour.', 'Relancez la configuration dans quelques minutes.');
                } elseif (!$who) {
                    $add('Mentions des factures', 'warn', 'Raison sociale ou SIREN de RBPS absents des Paramètres société : ils manquent sur les factures Stripe.',
                         'Renseignez Paramètres société (raison sociale, forme, SIREN, adresse), puis relancez la configuration.');
                } else {
                    $add('Mentions des factures', 'ok', 'Identité de ' . $co['legal_name'] . ', mention de TVA, conditions de paiement et pénalités de retard écrites sur les factures'
                        . ($ft['ok'] ? ' (' . $ft['ok'] . ' client(s) Stripe mis à jour)' : '') . '.');
                }
            }

            // 7. Webhook
            $wh = ak_stripe_setup_webhook($pdo, rtrim($base, '/') . '/stripe-webhook', $mode);
            $add('Webhook (notifications de Stripe)', $wh['state'], $wh['detail'], $wh['fix']);
            if ($wh['state'] === 'ko') $all_ok = false;

            // 8. Portail client
            $po = ak_stripe_setup_portal($pdo, rtrim($base, '/'), $mode);
            $add('Portail client (changer de carte)', $po['state'], $po['detail'], $po['fix']);
            if ($po['state'] === 'ko') $all_ok = false;

            // 9. Apple Pay / Google Pay
            $dm = ak_stripe_setup_domain($pdo, rtrim($base, '/'));
            $add('Domaine Apple Pay / Google Pay', $dm['state'], $dm['detail'], $dm['fix']);

            // 10. Mode réel : données des essais en mode test
            if ($mode === 'live') {
                $purge = ak_stripe_purge_test_data($pdo);
                $add('Données des essais en mode test', 'ok', ($purge['subs'] || $purge['invoices'])
                    ? $purge['subs'] . ' abonnement(s) et ' . $purge['invoices'] . ' facture(s) de test retirés (associations de test repassées au plan Démarrage).'
                    : 'Aucune donnée de test à retirer.');
            }

            if ($all_ok) {
                ak_stripe_config_set($pdo, 'stripe_setup_mode', $mode);
                ak_stripe_config_set($pdo, 'stripe_setup_at', date('Y-m-d H:i:s'));
            }
            return $res($all_ok);
        } finally {
            ak_stripe_unlock($pdo, 'setup');
        }
    }
}

if (!function_exists('ak_stripe_setup_webhook')) {
    /**
     * Point de terminaison webhook $url abonné à AK_STRIPE_WEBHOOK_EVENTS, version d'API 2024-06-20.
     * Gardé s'il est connu (identifiant + secret enregistrés pour ce mode), mis à jour si sa liste
     * d'événements a changé ; recréé sinon (le secret de signature n'est donné qu'à la création).
     * @return array{state: string, detail: string, fix: string}
     */
    function ak_stripe_setup_webhook(PDO $pdo, string $url, string $mode): array {
        if ($mode === 'live' && strpos($url, 'https://') !== 0) {
            return ['state' => 'ko', 'detail' => 'Adresse ' . $url . ' : Stripe exige une adresse https en mode réel.', 'fix' => 'Ouvrez cette page en https://assokit.fr, puis recommencez.'];
        }
        $events = AK_STRIPE_WEBHOOK_EVENTS;
        $l = ak_stripe_api_call($pdo, 'GET', '/webhook_endpoints', ['limit' => 100]);
        if (empty($l['ok'])) return ['state' => 'ko', 'detail' => 'Liste des webhooks illisible : ' . ($l['error'] ?? '?') . '.', 'fix' => 'Réessayez dans quelques minutes.'];
        $stored_id = (string)ak_stripe_config_get($pdo, 'stripe_webhook_endpoint_id_' . $mode, '');
        $stored_secret = (string)ak_stripe_config_get($pdo, 'stripe_webhook_secret_' . $mode, '');
        $mine = null; $others = []; $same_url = [];
        $host = (string)parse_url($url, PHP_URL_HOST);
        foreach (($l['data']['data'] ?? []) as $e) {
            $eurl = (string)($e['url'] ?? '');
            if ($eurl === $url) {
                if ($stored_id !== '' && $e['id'] === $stored_id && $stored_secret !== '') $mine = $e;
                else $same_url[] = $e;
            } elseif (preg_match('#^https?://(www\.)?' . preg_quote(preg_replace('/^www\./', '', $host), '#') . '(:\d+)?/stripe-webhook#i', $eurl)) {
                $others[] = $eurl;
            }
        }
        $warn = $others ? ' Autre(s) adresse(s) vers ce site : ' . implode(', ', array_unique($others)) . '.' : '';
        $fix_others = $others ? 'Supprimez ce(s) webhook(s) en double dans Développeurs › Webhooks (une redirection compte comme un échec).' : '';
        if ($mine) {
            $have = $mine['enabled_events'] ?? [];
            sort($have); $want = $events; sort($want);
            if ($have !== $want || ($mine['status'] ?? 'enabled') !== 'enabled') {
                $params = ['disabled' => 'false'];
                foreach ($events as $i => $ev) $params['enabled_events[' . $i . ']'] = $ev;
                $u = ak_stripe_api_call($pdo, 'POST', '/webhook_endpoints/' . rawurlencode($mine['id']), $params);
                if (empty($u['ok'])) return ['state' => 'ko', 'detail' => 'Webhook non mis à jour : ' . ($u['error'] ?? '?') . '.', 'fix' => 'Réessayez.'];
            }
            ak_stripe_config_set($pdo, 'stripe_webhook_secret', $stored_secret);
            foreach ($same_url as $dup) ak_stripe_api_call($pdo, 'DELETE', '/webhook_endpoints/' . rawurlencode($dup['id']));
            return ['state' => $others ? 'warn' : 'ok', 'detail' => $url . ' (' . $mine['id'] . ', ' . count($events) . ' événements, secret ' . ak_stripe_mask($stored_secret) . ').' . $warn, 'fix' => $fix_others];
        }
        // Webhook vers la même adresse dont Assokit n'a pas le secret (créé à la main, ou base remise à zéro) :
        // inutilisable, remplacé (sinon chaque événement serait envoyé deux fois, l'un des deux refusé)
        $replaced = 0;
        foreach ($same_url as $dup) {
            $d = ak_stripe_api_call($pdo, 'DELETE', '/webhook_endpoints/' . rawurlencode($dup['id']));
            if (!empty($d['ok'])) $replaced++;
        }
        $params = ['url' => $url, 'api_version' => '2024-06-20', 'description' => 'Assokit — abonnements des associations', 'metadata[ak]' => 'assokit'];
        foreach ($events as $i => $ev) $params['enabled_events[' . $i . ']'] = $ev;
        $c = ak_stripe_api_call($pdo, 'POST', '/webhook_endpoints', $params);
        $secret = (string)($c['data']['secret'] ?? '');
        if (empty($c['ok']) || $secret === '') {
            return ['state' => 'ko', 'detail' => 'Webhook non créé : ' . ($c['error'] ?? 'réponse sans secret') . '.', 'fix' => 'Vérifiez que ' . $url . ' est joignable depuis Internet, puis recommencez.'];
        }
        $ok = ak_stripe_config_set($pdo, 'stripe_webhook_secret', $secret)
            && ak_stripe_config_set($pdo, 'stripe_webhook_secret_' . $mode, $secret)
            && ak_stripe_config_set($pdo, 'stripe_webhook_endpoint_id_' . $mode, (string)$c['data']['id']);
        if (!$ok) return ['state' => 'ko', 'detail' => 'Webhook créé (' . $c['data']['id'] . ') mais son secret n’a pas pu être enregistré.', 'fix' => 'Vérifiez la base de données (migration), puis recommencez : il sera recréé.'];
        return ['state' => $others ? 'warn' : 'ok',
                'detail' => $url . ' créé (' . $c['data']['id'] . ', ' . count($events) . ' événements, secret ' . ak_stripe_mask($secret) . ' enregistré).'
                    . ($replaced ? ' Ancien webhook vers la même adresse remplacé (son secret n’était pas connu d’Assokit).' : '') . $warn,
                'fix' => $fix_others];
    }
}

if (!function_exists('ak_stripe_setup_portal')) {
    /**
     * Configuration du portail client Stripe : changer de carte, voir ses factures, résilier en fin de
     * période ; rien d'autre (ni changement de formule, ni modification des coordonnées). Son
     * identifiant (bpc_…) est gardé par mode et passé à chaque ouverture du portail.
     * @return array{state: string, detail: string, fix: string}
     */
    function ak_stripe_setup_portal(PDO $pdo, string $base, string $mode): array {
        $params = [
            'business_profile[headline]' => 'Assokit — gérer mon abonnement',
            'business_profile[privacy_policy_url]' => $base . '/confidentialite',
            'business_profile[terms_of_service_url]' => $base . '/cgu',
            'default_return_url' => $base . '/mon-asso-plan',
            'features[payment_method_update][enabled]' => 'true',
            'features[invoice_history][enabled]' => 'true',
            'features[subscription_cancel][enabled]' => 'true',
            'features[subscription_cancel][mode]' => 'at_period_end',
            'features[subscription_cancel][cancellation_reason][enabled]' => 'false',
            'features[customer_update][enabled]' => 'false',
            'features[subscription_update][enabled]' => 'false',
            'metadata[ak]' => 'assokit',
        ];
        $key = 'billing_portal_configuration_id_' . $mode;
        $id = (string)ak_stripe_config_get($pdo, $key, '');
        if ($id !== '') {
            $g = ak_stripe_api_call($pdo, 'GET', '/billing_portal/configurations/' . rawurlencode($id));
            if (!empty($g['ok']) && !empty($g['data']['active'])) {
                $u = ak_stripe_api_call($pdo, 'POST', '/billing_portal/configurations/' . rawurlencode($id), $params);
                if (!empty($u['ok'])) return ['state' => 'ok', 'detail' => 'Changer de carte, voir ses factures, résilier en fin de période (' . $id . ').', 'fix' => ''];
                return ['state' => 'ko', 'detail' => 'Portail non mis à jour : ' . ($u['error'] ?? '?') . '.', 'fix' => 'Réessayez.'];
            }
        }
        $c = ak_stripe_api_call($pdo, 'POST', '/billing_portal/configurations', $params);
        if (empty($c['ok']) || empty($c['data']['id'])) {
            return ['state' => 'ko', 'detail' => 'Portail non configuré : ' . ($c['error'] ?? '?') . '.', 'fix' => 'Réessayez ; sinon, enregistrez une fois la page Paramètres › Facturation › Portail client du tableau de bord Stripe.'];
        }
        ak_stripe_config_set($pdo, $key, (string)$c['data']['id']);
        return ['state' => 'ok', 'detail' => 'Changer de carte, voir ses factures, résilier en fin de période (' . $c['data']['id'] . ', créé).', 'fix' => ''];
    }
}

if (!function_exists('ak_stripe_setup_domain')) {
    /**
     * Domaine du site enregistré pour Apple Pay et Google Pay (/v1/payment_method_domains) : sans lui,
     * ces deux moyens n'apparaissent pas sur la page de paiement.
     * @return array{state: string, detail: string, fix: string}
     */
    function ak_stripe_setup_domain(PDO $pdo, string $base): array {
        $host = strtolower((string)parse_url($base, PHP_URL_HOST));
        if ($host === '' || $host === 'localhost' || filter_var($host, FILTER_VALIDATE_IP)) {
            return ['state' => 'warn', 'detail' => 'Adresse locale (' . ($host ?: '?') . ') : rien à enregistrer.', 'fix' => 'Sur le serveur assokit.fr, ce domaine est enregistré automatiquement.'];
        }
        $l = ak_stripe_api_call($pdo, 'GET', '/payment_method_domains', ['domain_name' => $host, 'limit' => 10]);
        if (empty($l['ok'])) return ['state' => 'warn', 'detail' => 'Domaines illisibles : ' . ($l['error'] ?? '?') . '.', 'fix' => 'La carte reste proposée ; réessayez plus tard pour Apple Pay / Google Pay.'];
        $d = null;
        foreach (($l['data']['data'] ?? []) as $x) if (strtolower((string)($x['domain_name'] ?? '')) === $host) { $d = $x; break; }
        if (!$d) {
            $c = ak_stripe_api_call($pdo, 'POST', '/payment_method_domains', ['domain_name' => $host, 'enabled' => 'true']);
            if (empty($c['ok'])) return ['state' => 'warn', 'detail' => 'Domaine ' . $host . ' non enregistré : ' . ($c['error'] ?? '?') . '.', 'fix' => 'Paramètres › Moyens de paiement › Domaines des moyens de paiement › Ajouter ' . $host . '.'];
            $d = $c['data'];
        } else {
            if (empty($d['enabled'])) ak_stripe_api_call($pdo, 'POST', '/payment_method_domains/' . rawurlencode($d['id']), ['enabled' => 'true']);
            if (($d['apple_pay']['status'] ?? '') !== 'active' || ($d['google_pay']['status'] ?? '') !== 'active') {
                $v = ak_stripe_api_call($pdo, 'POST', '/payment_method_domains/' . rawurlencode($d['id']) . '/validate');
                if (!empty($v['ok'])) $d = $v['data'];
            }
        }
        $ap = (string)($d['apple_pay']['status'] ?? '?');
        $gp = (string)($d['google_pay']['status'] ?? '?');
        if ($ap === 'active' && $gp === 'active') return ['state' => 'ok', 'detail' => $host . ' : Apple Pay et Google Pay actifs.', 'fix' => ''];
        $why = (string)($d['apple_pay']['status_details']['error_message'] ?? ($d['google_pay']['status_details']['error_message'] ?? ''));
        return ['state' => 'warn', 'detail' => $host . ' : Apple Pay ' . ($ap === 'active' ? 'actif' : 'inactif') . ', Google Pay ' . ($gp === 'active' ? 'actif' : 'inactif') . ($why !== '' ? ' (' . $why . ')' : '') . '.',
                'fix' => 'La carte reste proposée. Voir Paramètres › Moyens de paiement › Domaines des moyens de paiement ; si Stripe demande un fichier de vérification Apple, déposez-le dans ~/public_html/.well-known/ (voir le guide).'];
    }
}
