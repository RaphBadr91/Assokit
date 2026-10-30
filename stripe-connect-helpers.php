<?php
/**
 * stripe-connect-helpers.php
 * --------------------------------------------------------------
 * Paiements en ligne REÇUS PAR LES ASSOCIATIONS (Stripe Connect) : factures, cotisations, dons.
 *
 * Fonctionnement :
 *   - chaque association relie SON compte Stripe (compte « Standard », créé depuis Assokit par
 *     /encaissement-en-ligne et complété sur la page d'inscription hébergée par Stripe) ;
 *   - le payeur ouvre un lien public (/facture/{uuid}, /cotiser/{jeton}, /don/{jeton}) ; Assokit crée une
 *     session Stripe Checkout AU NOM de ce compte (en-tête Stripe-Account, paiement « direct ») : l'argent
 *     va directement sur le compte Stripe de l'association, les frais Stripe sont à sa charge, Assokit ne
 *     détient jamais les fonds et ne prend aucune commission (AK_CONNECT_FEE_* = 0) ;
 *   - le montant vient TOUJOURS du serveur (facture, tarif de la campagne, montant libre borné) ;
 *   - le paiement est enregistré exactement comme un paiement saisi à la main (ak_connect_mark_paid) :
 *     facture « payée » (ak_asso_invoice_mark_paid), ligne de cotisation « payée » et adhésion prolongée,
 *     don enregistré ; reçu au payeur, cloche et e-mail à l'association ;
 *   - une seule façon d'appliquer une session Stripe (ak_connect_apply_session), utilisée par le webhook
 *     Connect (stripe-connect-webhook.php) et par la page de retour (paiement-confirme.php) : idempotente
 *     (UPDATE … WHERE status IN ('open', 'processing') puis rowCount) ;
 *   - prélèvement SEPA : « en cours » jusqu'à la réponse de la banque (checkout.session.async_payment_*) ;
 *   - remboursement fait par l'association dans son tableau de bord Stripe (charge.refunded) : paiement
 *     annulé côté Assokit (cotisation « remboursée », facture repassée « à payer » avec une ligne négative).
 *
 * Application mobile : aucune session de paiement n'est jamais créée pour l'agent « AssokitApp »
 * (règles Apple 3.1.1 / Google Play) ; les pages de paiement s'ouvrent dans le navigateur.
 * Mode test : seules les associations de test (ak_stripe_test_org_ids) peuvent encaisser, comme les abonnements.
 *
 * Toutes les fonctions sont protégées par function_exists (fichier inclus par plusieurs pages).
 * --------------------------------------------------------------
 */

@require_once __DIR__ . '/stripe-config-helpers.php';
@require_once __DIR__ . '/stripe-helpers.php';
require_once __DIR__ . '/trial-helpers.php';
require_once __DIR__ . '/stripe-sync-helpers.php';

// Commission d'Assokit sur chaque paiement (application_fee_amount) : aucune à ce jour. La changer
// modifie la position juridique d'Assokit (CGU, affichage du prix) : voir docs/stripe-mise-en-service.md.
if (!defined('AK_CONNECT_FEE_BPS')) define('AK_CONNECT_FEE_BPS', 0);           // en centièmes de pour cent
if (!defined('AK_CONNECT_FEE_FIXED_CENTS')) define('AK_CONNECT_FEE_FIXED_CENTS', 0);
// Montant libre (dons, cotisation « prix libre ») : 1 € au moins (cartes de test en série), 10 000 € au plus
if (!defined('AK_CONNECT_MIN_CENTS')) define('AK_CONNECT_MIN_CENTS', 100);
if (!defined('AK_CONNECT_MAX_CENTS')) define('AK_CONNECT_MAX_CENTS', 1000000);
// Durée de validité d'une page Stripe Checkout (Stripe : entre 30 minutes et 24 heures)
if (!defined('AK_CONNECT_SESSION_MINUTES')) define('AK_CONNECT_SESSION_MINUTES', 60);

if (!defined('AK_STRIPE_CONNECT_WEBHOOK_EVENTS')) {
    /** Événements des comptes connectés traités par stripe-connect-webhook.php (même liste pour la configuration automatique). */
    define('AK_STRIPE_CONNECT_WEBHOOK_EVENTS', [
        'checkout.session.completed',
        'checkout.session.async_payment_succeeded',
        'checkout.session.async_payment_failed',
        'checkout.session.expired',
        'charge.refunded',
        'charge.dispute.created',
        'account.updated',
        'account.application.deauthorized',
    ]);
}

if (!function_exists('ak_connect_schema_missing')) {
    /** Tables et colonnes des paiements en ligne (migration 2026-10-30-paiements-en-ligne-associations.sql). */
    function ak_connect_schema_missing(PDO $pdo): array {
        $need = [
            'org_stripe_accounts'         => ['org_id', 'mode', 'stripe_account_id', 'charges_enabled', 'disconnected_at'],
            'org_online_payment_settings' => ['org_id', 'invoices_enabled', 'cotisations_enabled', 'dons_enabled', 'sepa_enabled'],
            'asso_online_payments'        => ['org_id', 'kind', 'status', 'checkout_session_id', 'payment_intent_id', 'fee_cents', 'refunded_cents'],
            'asso_stripe_events'          => ['connect_account'],
            'cotisation_campaigns'        => ['kind', 'online_payment', 'free_amount', 'min_amount'],
        ];
        $missing = [];
        foreach ($need as $t => $cols) {
            $have = ak_db_columns($pdo, $t);
            if (!$have) { $missing[] = 'table ' . $t; continue; }
            foreach ($cols as $c) if (!isset($have[$c])) $missing[] = $t . '.' . $c;
        }
        return $missing;
    }
}

if (!function_exists('ak_connect_platform_state')) {
    /**
     * La plateforme est-elle prête à faire encaisser les associations ? Interrupteur du Fondateur
     * (asso_stripe_config « connect_enabled »), clés Stripe, migration, webhook Connect du mode en cours.
     * @return array{ok: bool, reason: string}
     */
    function ak_connect_platform_state(PDO $pdo): array {
        if (ak_connect_schema_missing($pdo)) return ['ok' => false, 'reason' => 'schema'];
        if ((int)ak_stripe_config_get($pdo, 'connect_enabled', 0) !== 1) return ['ok' => false, 'reason' => 'disabled'];
        if (!ak_stripe_get_secret_key($pdo)) return ['ok' => false, 'reason' => 'keys'];
        $mode = ak_stripe_get_mode($pdo);
        if (trim((string)ak_stripe_config_get($pdo, 'connect_webhook_secret_' . $mode, '')) === '') return ['ok' => false, 'reason' => 'webhook'];
        return ['ok' => true, 'reason' => ''];
    }
}

if (!function_exists('ak_connect_can_manage')) {
    /**
     * Relier ou déconnecter le compte Stripe, choisir ce qui se paie en ligne : administrateur, ou trésorier
     * (droit « Gérer les finances »), jamais un suiveur. Les coordinateurs voient l'état sans le modifier.
     */
    function ak_connect_can_manage(array $user): bool {
        $role = (string)($user['role'] ?? '');
        if ((int)($user['org_id'] ?? 0) <= 0 || $role === 'follower') return false;
        return $role === 'admin' || !empty($user['can_manage_finances']);
    }
}

if (!function_exists('ak_connect_account')) {
    /** Compte Stripe relié de l'association pour le mode (test / réel) des clés en place ; null si aucun ou déconnecté. */
    function ak_connect_account(PDO $pdo, int $org_id, ?string $mode = null, bool $with_disconnected = false): ?array {
        if ($org_id <= 0 || !ak_db_columns($pdo, 'org_stripe_accounts')) return null;
        $mode = $mode ?: ak_stripe_get_mode($pdo);
        try {
            $q = $pdo->prepare("SELECT * FROM org_stripe_accounts WHERE org_id = ? AND mode = ? LIMIT 1");
            $q->execute([$org_id, $mode]);
            $r = $q->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (Throwable $e) {
            return null;
        }
        if ($r && !$with_disconnected && !empty($r['disconnected_at'])) return null;
        return $r;
    }
}

if (!function_exists('ak_connect_account_by_stripe_id')) {
    function ak_connect_account_by_stripe_id(PDO $pdo, string $acct): ?array {
        if ($acct === '' || !ak_db_columns($pdo, 'org_stripe_accounts')) return null;
        try {
            $q = $pdo->prepare("SELECT * FROM org_stripe_accounts WHERE stripe_account_id = ? LIMIT 1");
            $q->execute([$acct]);
            return $q->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (Throwable $e) {
            return null;
        }
    }
}

if (!function_exists('ak_connect_settings')) {
    /** Ce que l'association fait payer en ligne (valeurs par défaut : factures et cotisations oui, dons et SEPA non). */
    function ak_connect_settings(PDO $pdo, int $org_id): array {
        $def = ['invoices_enabled' => 1, 'cotisations_enabled' => 1, 'dons_enabled' => 0, 'sepa_enabled' => 0];
        if (!ak_db_columns($pdo, 'org_online_payment_settings')) return $def;
        try {
            $q = $pdo->prepare("SELECT * FROM org_online_payment_settings WHERE org_id = ?");
            $q->execute([$org_id]);
            $r = $q->fetch(PDO::FETCH_ASSOC);
            if (!$r) return $def;
            foreach ($def as $k => $v) $def[$k] = (int)($r[$k] ?? $v);
        } catch (Throwable $e) {}
        return $def;
    }
}

if (!function_exists('ak_connect_save_settings')) {
    function ak_connect_save_settings(PDO $pdo, int $org_id, array $s, int $user_id): bool {
        try {
            $pdo->prepare("INSERT INTO org_online_payment_settings (org_id, invoices_enabled, cotisations_enabled, dons_enabled, sepa_enabled, updated_at, updated_by_user_id)
                           VALUES (?, ?, ?, ?, ?, NOW(), ?)
                           ON DUPLICATE KEY UPDATE invoices_enabled = VALUES(invoices_enabled), cotisations_enabled = VALUES(cotisations_enabled),
                                                   dons_enabled = VALUES(dons_enabled), sepa_enabled = VALUES(sepa_enabled),
                                                   updated_at = NOW(), updated_by_user_id = VALUES(updated_by_user_id)")
                ->execute([$org_id, empty($s['invoices_enabled']) ? 0 : 1, empty($s['cotisations_enabled']) ? 0 : 1,
                           empty($s['dons_enabled']) ? 0 : 1, empty($s['sepa_enabled']) ? 0 : 1, $user_id ?: null]);
            return true;
        } catch (Throwable $e) {
            error_log('[connect] réglages org #' . $org_id . ' : ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('ak_connect_org_row')) {
    function ak_connect_org_row(PDO $pdo, int $org_id): ?array {
        $oc = ak_db_columns($pdo, 'organizations');
        try {
            $q = $pdo->prepare("SELECT id, name, status, billing_email, "
                . (isset($oc['deleted_at']) ? 'deleted_at' : 'NULL') . " AS deleted_at, "
                . (isset($oc['suspended_reason']) ? 'suspended_reason' : 'NULL') . " AS suspended_reason
                FROM organizations WHERE id = ?");
            $q->execute([$org_id]);
            return $q->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (Throwable $e) {
            return null;
        }
    }
}

if (!function_exists('ak_connect_demo_blocked')) {
    /**
     * Espaces de démonstration (DEMO F, démos commerciales, demo-guard.php) : jamais de vrai compte Stripe ni de
     * vrai paiement (identifiants publics, données remises à zéro chaque nuit). En mode test, seulement si le
     * Fondateur les a mis dans les associations de test.
     */
    function ak_connect_demo_blocked(PDO $pdo, int $org_id): bool {
        if (!function_exists('ak_is_demo_org')) @require_once __DIR__ . '/demo-guard.php';
        if (!function_exists('ak_is_demo_org') || !ak_is_demo_org($org_id)) return false;
        return ak_stripe_get_mode($pdo) === 'live';
    }
}

if (!function_exists('ak_connect_available')) {
    /**
     * L'association peut-elle faire payer ce type d'élément en ligne MAINTENANT ?
     * $kind : invoice | cotisation | don. Même règle partout : page publique (bouton), envoi des liens
     * (e-mails, PDF, relances), création de la session de paiement (vérifiée encore au clic).
     * @return array{ok: bool, reason: string, account: ?array, settings: array}
     *         reason : platform | test_mode | closed | suspended | no_account | not_ready | kind_off
     */
    function ak_connect_available(PDO $pdo, int $org_id, string $kind): array {
        $out = ['ok' => false, 'reason' => 'platform', 'account' => null, 'settings' => []];
        if (!ak_connect_platform_state($pdo)['ok']) return $out;
        if (ak_stripe_get_mode($pdo) === 'test' && !in_array($org_id, ak_stripe_test_org_ids($pdo), true)) { $out['reason'] = 'test_mode'; return $out; }
        if (ak_connect_demo_blocked($pdo, $org_id)) { $out['reason'] = 'demo'; return $out; }
        $org = ak_connect_org_row($pdo, $org_id);
        if (!$org || !empty($org['deleted_at']) || in_array((string)$org['status'], ['cancelled', 'closed', 'deleted'], true)) { $out['reason'] = 'closed'; return $out; }
        // Association suspendue (à la main, fin d'essai ou impayé) : plus d'encaissement en ligne tant que ça dure
        if ((string)$org['status'] === 'suspended') { $out['reason'] = 'suspended'; return $out; }
        $acc = ak_connect_account($pdo, $org_id);
        if (!$acc) { $out['reason'] = 'no_account'; return $out; }
        $out['account'] = $acc;
        if (empty($acc['charges_enabled']) || ($acc['card_capability'] ?? '') !== 'active') { $out['reason'] = 'not_ready'; return $out; }
        $set = ak_connect_settings($pdo, $org_id);
        $out['settings'] = $set;
        $key = ['invoice' => 'invoices_enabled', 'cotisation' => 'cotisations_enabled', 'don' => 'dons_enabled'][$kind] ?? '';
        if ($key === '' || empty($set[$key])) { $out['reason'] = 'kind_off'; return $out; }
        $out['ok'] = true; $out['reason'] = '';
        return $out;
    }
}

if (!function_exists('ak_connect_unavailable_text')) {
    /** Phrase affichée au payeur quand le paiement en ligne n'est pas proposé. */
    function ak_connect_unavailable_text(string $org_name): string {
        return 'Le paiement en ligne n’est pas proposé pour le moment. Pour régler, contactez ' . $org_name . '.';
    }
}

if (!function_exists('ak_connect_payment_types')) {
    /** Moyens proposés sur la page Stripe : carte (avec Apple Pay et Google Pay) ; prélèvement SEPA si l'association l'a activé et que Stripe l'accepte, en euros. */
    function ak_connect_payment_types(array $acc, array $settings, string $currency = 'EUR'): array {
        $types = ['card'];
        if (!empty($settings['sepa_enabled']) && ($acc['sepa_capability'] ?? '') === 'active' && strtoupper($currency) === 'EUR') $types[] = 'sepa_debit';
        return $types;
    }
}

if (!function_exists('ak_connect_sepa_offered')) {
    /** Le prélèvement SEPA est-il proposé pour ce type d'élément (textes des e-mails, pages) ? */
    function ak_connect_sepa_offered(PDO $pdo, int $org_id, string $kind, string $currency = 'EUR'): bool {
        $av = ak_connect_available($pdo, $org_id, $kind);
        return $av['ok'] && in_array('sepa_debit', ak_connect_payment_types($av['account'], $av['settings'], $currency), true);
    }
}

if (!function_exists('ak_connect_application_fee')) {
    /** Commission d'Assokit sur un paiement (0 à ce jour) : un seul endroit. */
    function ak_connect_application_fee(int $cents): int {
        $fee = (int)floor($cents * AK_CONNECT_FEE_BPS / 10000) + (AK_CONNECT_FEE_FIXED_CENTS > 0 ? AK_CONNECT_FEE_FIXED_CENTS : 0);
        return max(0, min($fee, $cents - 1));
    }
}

// ---------------------------------------------------------------- liens publics et signatures

if (!function_exists('ak_connect_secret')) {
    /**
     * Secret des liens personnels de cotisation et des pages de retour : 32 octets aléatoires créés une fois
     * (asso_stripe_config « pay_link_secret »). Indépendant de config.php et des clés Resend / Stripe :
     * changer une clé ne casse aucun lien déjà envoyé. Deux créations simultanées : la première gagne.
     */
    function ak_connect_secret(PDO $pdo): string {
        static $cache = null;
        if ($cache !== null) return $cache;
        $s = (string)ak_stripe_config_get($pdo, 'pay_link_secret', '');
        if ($s === '') {
            try {
                $pdo->prepare("INSERT IGNORE INTO asso_stripe_config (config_key, config_value, updated_at) VALUES ('pay_link_secret', ?, NOW())")
                    ->execute([bin2hex(random_bytes(32))]);
            } catch (Throwable $e) {}
            $s = (string)ak_stripe_config_get($pdo, 'pay_link_secret', '');
        }
        if ($s === '') throw new RuntimeException('Secret des liens de paiement indisponible (migration 2026-10-28)');
        return $cache = $s;
    }
}

if (!function_exists('ak_connect_sign')) {
    function ak_connect_sign(PDO $pdo, string $data): string {
        return substr(hash_hmac('sha256', $data, ak_connect_secret($pdo)), 0, 24);
    }
}

if (!function_exists('ak_connect_member_ref')) {
    /** « 123.signature » : lien personnel d'un adhérent pour une campagne (prérempli, prolonge SON adhésion). */
    function ak_connect_member_ref(PDO $pdo, int $campaign_id, int $adherent_id): string {
        return $adherent_id . '.' . ak_connect_sign($pdo, 'cotiser|' . $campaign_id . '|' . $adherent_id);
    }
}

if (!function_exists('ak_connect_member_from_ref')) {
    /** Adhérent d'un lien personnel, si la signature est bonne ; 0 sinon (lien modifié ou d'une autre campagne). */
    function ak_connect_member_from_ref(PDO $pdo, int $campaign_id, string $ref): int {
        if (!preg_match('/^(\d{1,10})\.([a-f0-9]{24})$/', $ref, $m)) return 0;
        return hash_equals(ak_connect_sign($pdo, 'cotiser|' . $campaign_id . '|' . $m[1]), $m[2]) ? (int)$m[1] : 0;
    }
}

if (!function_exists('ak_connect_op_ref')) {
    /** « id.signature » d'un paiement en ligne : page de retour (paiement-confirme) sans deviner les numéros. */
    function ak_connect_op_ref(PDO $pdo, int $op_id): string {
        return $op_id . '.' . ak_connect_sign($pdo, 'op|' . $op_id);
    }
}

if (!function_exists('ak_connect_op_from_ref')) {
    function ak_connect_op_from_ref(PDO $pdo, string $ref): int {
        if (!preg_match('/^(\d{1,10})\.([a-f0-9]{24})$/', $ref, $m)) return 0;
        return hash_equals(ak_connect_sign($pdo, 'op|' . $m[1]), $m[2]) ? (int)$m[1] : 0;
    }
}

if (!function_exists('ak_connect_campaign_token')) {
    /** Jeton public de la campagne (cotisation_campaigns.public_token), créé s'il manque (anciennes campagnes). */
    function ak_connect_campaign_token(PDO $pdo, array $campaign): string {
        $t = (string)($campaign['public_token'] ?? '');
        if (preg_match('/^[a-f0-9]{32,64}$/', $t)) return $t;
        $t = bin2hex(random_bytes(20));
        try {
            $pdo->prepare("UPDATE cotisation_campaigns SET public_token = ? WHERE id = ? AND (public_token IS NULL OR public_token = '' OR public_token = ?)")
                ->execute([$t, (int)$campaign['id'], (string)($campaign['public_token'] ?? '')]);
            $q = $pdo->prepare("SELECT public_token FROM cotisation_campaigns WHERE id = ?");
            $q->execute([(int)$campaign['id']]);
            $t = (string)$q->fetchColumn();
        } catch (Throwable $e) {
            return '';
        }
        return preg_match('/^[a-f0-9]{32,64}$/', $t) ? $t : '';
    }
}

if (!function_exists('ak_connect_campaign_is_don')) {
    function ak_connect_campaign_is_don(array $campaign): bool {
        return ($campaign['kind'] ?? '') === 'don';
    }
}

if (!function_exists('ak_connect_campaign_url')) {
    /** Lien public de paiement d'une campagne (/cotiser/… ou /don/…), personnel si $adherent_id. '' si impossible. */
    function ak_connect_campaign_url(PDO $pdo, array $campaign, int $adherent_id = 0): string {
        $tok = ak_connect_campaign_token($pdo, $campaign);
        if ($tok === '') return '';
        $url = ak_stripe_site_base() . (ak_connect_campaign_is_don($campaign) ? '/don/' : '/cotiser/') . $tok;
        if ($adherent_id > 0) $url .= '?m=' . ak_connect_member_ref($pdo, (int)$campaign['id'], $adherent_id);
        return $url;
    }
}

if (!function_exists('ak_connect_campaign_online')) {
    /** La campagne se paie-t-elle en ligne ? Réglage de la campagne (NULL = oui) + réglages de l'association. */
    function ak_connect_campaign_online(PDO $pdo, array $campaign): array {
        $kind = ak_connect_campaign_is_don($campaign) ? 'don' : 'cotisation';
        $av = ak_connect_available($pdo, (int)$campaign['org_id'], $kind);
        if ($av['ok'] && isset($campaign['online_payment']) && $campaign['online_payment'] !== null && (int)$campaign['online_payment'] === 0) {
            $av['ok'] = false; $av['reason'] = 'campaign_off';
        }
        if ($av['ok'] && strtoupper((string)($campaign['currency'] ?: 'EUR')) !== 'EUR') { $av['ok'] = false; $av['reason'] = 'currency'; }
        return $av;
    }
}

if (!function_exists('ak_connect_campaign_open')) {
    /** Campagne ouverte au paiement aujourd'hui : active, non archivée, entre ouverture et clôture. Message sinon. */
    function ak_connect_campaign_open(array $c): ?string {
        $today = date('Y-m-d');
        if (empty($c['is_active']) || !empty($c['archived_at'])) return 'Cette campagne est terminée.';
        $o = substr((string)($c['opens_at'] ?? ''), 0, 10);
        $e = substr((string)($c['closes_at'] ?? ''), 0, 10);
        if ($o !== '' && $o !== '0000-00-00' && $o > $today) return 'Cette campagne ouvre le ' . date('d/m/Y', strtotime($o)) . '.';
        if ($e !== '' && $e !== '0000-00-00' && $e < $today) return 'Cette campagne est close depuis le ' . date('d/m/Y', strtotime($e)) . '.';
        return null;
    }
}

if (!function_exists('ak_connect_campaign_member_link')) {
    /**
     * Lien personnel de paiement le plus utile pour un adhérent : campagne active qui renouvelle l'adhésion et
     * se paie en ligne (la plus récente). '' si aucune (relances de cotisation, espace membre).
     */
    function ak_connect_campaign_member_link(PDO $pdo, int $org_id, int $adherent_id): string {
        if (!ak_connect_available($pdo, $org_id, 'cotisation')['ok']) return '';
        require_once __DIR__ . '/includes-cotisations.php';
        try {
            $q = $pdo->prepare("SELECT * FROM cotisation_campaigns WHERE org_id = ? AND is_active = 1 AND archived_at IS NULL
                                ORDER BY COALESCE(opens_at, created_at) DESC, id DESC LIMIT 20");
            $q->execute([$org_id]);
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $c) {
                if (ak_connect_campaign_is_don($c) || !ck_campaign_renews_membership($c)) continue;
                if (ak_connect_campaign_open($c) !== null || !ak_connect_campaign_online($pdo, $c)['ok']) continue;
                return ak_connect_campaign_url($pdo, $c, $adherent_id);
            }
        } catch (Throwable $e) {
            error_log('[connect] lien adhérent : ' . $e->getMessage());
        }
        return '';
    }
}

if (!function_exists('ak_connect_invoice_payable')) {
    /** Facture que le client peut payer en ligne : émise, à payer (en attente ou en retard), pas un avoir, en euros, montant > 0. */
    function ak_connect_invoice_payable(array $inv): bool {
        if (($inv['invoice_type'] ?? 'invoice') === 'credit_note') return false;
        if (!in_array((string)($inv['status'] ?? ''), ['pending', 'overdue'], true)) return false;
        if (strtoupper((string)($inv['currency'] ?? 'EUR') ?: 'EUR') !== 'EUR') return false;
        return (int)($inv['amount_ttc_cents'] ?? 0) >= 50;
    }
}

if (!function_exists('ak_connect_invoice_pay_url')) {
    /** Lien « Payer en ligne » d'une facture (page publique, ancre du bouton) ; '' si la facture ne se paie pas en ligne. */
    function ak_connect_invoice_pay_url(PDO $pdo, array $inv): string {
        if (empty($inv['public_uuid']) || !ak_connect_invoice_payable($inv)) return '';
        if (!ak_connect_available($pdo, (int)$inv['org_id'], 'invoice')['ok']) return '';
        return ak_stripe_site_base() . '/facture/' . $inv['public_uuid'] . '#payer';
    }
}

if (!function_exists('ak_connect_pay_paragraph')) {
    /**
     * Paragraphe ajouté aux e-mails (facture, relances, rappels de cotisation) quand le modèle ne contient pas
     * déjà le lien : « Payer en ligne par carte bancaire (…) : {lien} ». Texte brut (les e-mails sont des modèles texte).
     */
    function ak_connect_pay_paragraph(string $url, bool $sepa = false, string $intro = 'Payer en ligne'): string {
        return $intro . ' par carte bancaire, Apple Pay ou Google Pay' . ($sepa ? ', ou par prélèvement SEPA' : '') . ' :' . "\n" . $url;
    }
}

if (!function_exists('ak_connect_add_link')) {
    /**
     * Ajoute le lien de paiement à un texte d'e-mail : remplace {LIEN_PAIEMENT} / {LIEN_COTISATION} s'ils y sont,
     * sinon ajoute le paragraphe avant la formule de politesse (dernier paragraphe). Sans lien : marqueurs retirés.
     */
    function ak_connect_add_link(string $body, string $url, string $paragraph): string {
        $has = strpos($body, '{LIEN_PAIEMENT}') !== false || strpos($body, '{LIEN_COTISATION}') !== false;
        if ($url === '') return trim(str_replace(['{LIEN_PAIEMENT}', '{LIEN_COTISATION}'], '', $body));
        if ($has) return str_replace(['{LIEN_PAIEMENT}', '{LIEN_COTISATION}'], $url, $body);
        if (strpos($body, $url) !== false) return $body;
        $parts = preg_split("/\n\s*\n/", rtrim($body));
        if (count($parts) >= 2) {
            $last = array_pop($parts);
            return implode("\n\n", $parts) . "\n\n" . $paragraph . "\n\n" . $last;
        }
        return rtrim($body) . "\n\n" . $paragraph;
    }
}

// ---------------------------------------------------------------- compte Stripe de l'association

if (!function_exists('ak_connect_store_account')) {
    /** Enregistre l'état d'un compte Stripe (objet account) dans org_stripe_accounts. */
    function ak_connect_store_account(PDO $pdo, string $acct, array $a): void {
        $caps = $a['capabilities'] ?? [];
        $req = $a['requirements'] ?? [];
        $due = array_values(array_unique(array_merge((array)($req['currently_due'] ?? []), (array)($req['past_due'] ?? []))));
        $name = (string)($a['settings']['dashboard']['display_name'] ?? ($a['business_profile']['name'] ?? ''));
        $pdo->prepare("UPDATE org_stripe_accounts SET charges_enabled = ?, payouts_enabled = ?, details_submitted = ?, card_capability = ?,
                              sepa_capability = ?, requirements_json = ?, disabled_reason = ?, display_name = COALESCE(NULLIF(?, ''), display_name),
                              updated_at = NOW()
                       WHERE stripe_account_id = ?")
            ->execute([!empty($a['charges_enabled']) ? 1 : 0, !empty($a['payouts_enabled']) ? 1 : 0, !empty($a['details_submitted']) ? 1 : 0,
                       mb_substr((string)($caps['card_payments'] ?? ''), 0, 20), mb_substr((string)($caps['sepa_debit_payments'] ?? ''), 0, 20),
                       json_encode($due, JSON_UNESCAPED_UNICODE), mb_substr((string)($req['disabled_reason'] ?? ''), 0, 100) ?: null,
                       mb_substr($name, 0, 255), $acct]);
    }
}

if (!function_exists('ak_connect_refresh_account')) {
    /**
     * Relit le compte chez Stripe (clé de la plateforme) et l'enregistre. Un événement account.updated n'est
     * jamais appliqué tel quel : il peut arriver en retard. Compte inaccessible (accès retiré) : déconnecté.
     * @return array|null la ligne à jour
     */
    function ak_connect_refresh_account(PDO $pdo, string $acct): ?array {
        if (!preg_match('/^acct_[A-Za-z0-9]+$/', $acct)) return null;
        $r = ak_stripe_api_call($pdo, 'GET', '/accounts/' . rawurlencode($acct));
        if (!empty($r['ok']) && is_array($r['data'])) {
            ak_connect_store_account($pdo, $acct, $r['data']);
        } elseif (in_array((int)($r['http_code'] ?? 0), [401, 403, 404], true)) {
            ak_connect_mark_disconnected($pdo, $acct, 'accès retiré');
        }
        return ak_connect_account_by_stripe_id($pdo, $acct);
    }
}

if (!function_exists('ak_connect_mark_disconnected')) {
    function ak_connect_mark_disconnected(PDO $pdo, string $acct, string $why = ''): bool {
        try {
            $st = $pdo->prepare("UPDATE org_stripe_accounts SET disconnected_at = NOW(), charges_enabled = 0, updated_at = NOW(),
                                        disabled_reason = ? WHERE stripe_account_id = ? AND disconnected_at IS NULL");
            $st->execute([mb_substr($why, 0, 100) ?: null, $acct]);
            return $st->rowCount() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('ak_connect_create_account')) {
    /**
     * Compte Stripe Standard de l'association (une seule fois par mode) : créé par l'API, ou repris s'il
     * existe déjà (déconnecté puis reconnecté : le même compte, s'il est encore accessible).
     * @return array{ok: bool, acct: string, error: string}
     */
    function ak_connect_create_account(PDO $pdo, int $org_id, array $user): array {
        $mode = ak_stripe_get_mode($pdo);
        if (ak_connect_demo_blocked($pdo, $org_id)) return ['ok' => false, 'acct' => '', 'error' => 'Espace de démonstration : le paiement en ligne se relie depuis l’espace réel de votre association.'];
        if (!ak_stripe_lock($pdo, 'connect_acct_' . $org_id, 10)) return ['ok' => false, 'acct' => '', 'error' => 'Une connexion est déjà en cours : patientez quelques secondes.'];
        try {
            $row = ak_connect_account($pdo, $org_id, $mode, true);
            if ($row) {
                $acct = (string)$row['stripe_account_id'];
                $g = ak_stripe_api_call($pdo, 'GET', '/accounts/' . rawurlencode($acct));
                if (!empty($g['ok'])) {
                    $pdo->prepare("UPDATE org_stripe_accounts SET disconnected_at = NULL, disabled_reason = NULL, updated_at = NOW() WHERE id = ?")->execute([(int)$row['id']]);
                    ak_connect_store_account($pdo, $acct, $g['data']);
                    return ['ok' => true, 'acct' => $acct, 'error' => ''];
                }
                if ((int)($g['http_code'] ?? 0) === 0) return ['ok' => false, 'acct' => '', 'error' => 'Stripe ne répond pas : réessayez dans quelques minutes.'];
                // Compte supprimé ou accès retiré par l'association : un nouveau compte remplace l'ancien
            }
            $org = ak_connect_org_row($pdo, $org_id) ?: ['name' => 'Association', 'billing_email' => ''];
            $email = trim((string)($org['billing_email'] ?? '')) ?: trim((string)($user['email'] ?? ''));
            $params = [
                'type' => 'standard', 'country' => 'FR',
                'business_profile[name]' => mb_substr((string)$org['name'], 0, 100),
                'metadata[ak_org_id]' => (string)$org_id, 'metadata[ak]' => 'assokit',
            ];
            if (filter_var($email, FILTER_VALIDATE_EMAIL)) $params['email'] = $email;
            $c = ak_stripe_api_call($pdo, 'POST', '/accounts', $params, 'ak-acct-' . $mode . '-' . $org_id . '-' . ($row ? (int)strtotime((string)$row['connected_at']) : 0));
            if (empty($c['ok']) || empty($c['data']['id'])) {
                $err = (string)($c['error'] ?? 'réponse sans identifiant');
                error_log('[connect] création du compte org #' . $org_id . ' : ' . $err);
                if (stripos($err, 'connect') !== false) return ['ok' => false, 'acct' => '', 'error' => 'Les paiements en ligne ne sont pas encore ouverts sur Assokit. Réessayez plus tard.'];
                return ['ok' => false, 'acct' => '', 'error' => 'Stripe n’a pas pu créer le compte : ' . $err];
            }
            $acct = (string)$c['data']['id'];
            if ($row) {
                $pdo->prepare("UPDATE org_stripe_accounts SET stripe_account_id = ?, charges_enabled = 0, payouts_enabled = 0, details_submitted = 0,
                                      disconnected_at = NULL, disabled_reason = NULL, connected_by_user_id = ?, connected_at = NOW(), updated_at = NOW() WHERE id = ?")
                    ->execute([$acct, (int)($user['id'] ?? 0) ?: null, (int)$row['id']]);
            } else {
                $pdo->prepare("INSERT INTO org_stripe_accounts (org_id, mode, stripe_account_id, connected_by_user_id, connected_at, updated_at, display_name)
                               VALUES (?, ?, ?, ?, NOW(), NOW(), ?)")
                    ->execute([$org_id, $mode, $acct, (int)($user['id'] ?? 0) ?: null, mb_substr((string)$org['name'], 0, 255)]);
            }
            ak_connect_store_account($pdo, $acct, $c['data']);
            return ['ok' => true, 'acct' => $acct, 'error' => ''];
        } finally {
            ak_stripe_unlock($pdo, 'connect_acct_' . $org_id);
        }
    }
}

if (!function_exists('ak_connect_onboarding_link')) {
    /** Lien d'inscription hébergé par Stripe (valable quelques minutes : créé au clic, jamais envoyé par e-mail). */
    function ak_connect_onboarding_link(PDO $pdo, string $acct): array {
        $base = ak_stripe_site_base();
        $r = ak_stripe_api_call($pdo, 'POST', '/account_links', [
            'account' => $acct, 'type' => 'account_onboarding',
            'refresh_url' => $base . '/encaissement-en-ligne?relancer=1',
            'return_url' => $base . '/encaissement-en-ligne?retour=1',
        ]);
        if (empty($r['ok']) || empty($r['data']['url'])) return ['ok' => false, 'url' => '', 'error' => (string)($r['error'] ?? 'pas de lien')];
        return ['ok' => true, 'url' => (string)$r['data']['url'], 'error' => ''];
    }
}

if (!function_exists('ak_connect_requirements')) {
    /** Informations encore demandées par Stripe, en clair (les clés techniques de Stripe, traduites quand on les connaît). */
    function ak_connect_requirements(array $acc): array {
        $list = json_decode((string)($acc['requirements_json'] ?? '[]'), true);
        if (!is_array($list)) return [];
        $fr = [
            'external_account' => 'compte bancaire (IBAN) de versement', 'business_profile.mcc' => 'activité de l’association',
            'business_profile.url' => 'site web ou description de l’activité', 'tos_acceptance.date' => 'acceptation des conditions de Stripe',
            'tos_acceptance.ip' => 'acceptation des conditions de Stripe', 'company.tax_id' => 'numéro SIREN / RNA',
            'company.address.line1' => 'adresse de l’association', 'company.name' => 'nom de l’association',
            'representative.first_name' => 'représentant légal (président·e)', 'representative.last_name' => 'représentant légal (président·e)',
            'representative.dob.day' => 'date de naissance du représentant', 'representative.verification.document' => 'pièce d’identité du représentant',
            'representative.address.line1' => 'adresse du représentant', 'owners.provided' => 'dirigeants de l’association',
        ];
        $out = [];
        foreach ($list as $k) {
            $k = (string)$k;
            $out[$fr[$k] ?? (preg_match('/^(representative|person_)/', $k) ? 'informations sur le représentant légal' : (strpos($k, 'company.') === 0 ? 'informations sur l’association' : 'autres informations demandées par Stripe'))] = true;
        }
        return array_keys($out);
    }
}

// ---------------------------------------------------------------- paiement

if (!function_exists('ak_connect_op')) {
    function ak_connect_op(PDO $pdo, int $id): ?array {
        try {
            $q = $pdo->prepare("SELECT * FROM asso_online_payments WHERE id = ?");
            $q->execute([$id]);
            return $q->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (Throwable $e) {
            return null;
        }
    }
}

if (!function_exists('ak_connect_invoice_pending_op')) {
    /** Prélèvement SEPA en attente de la banque pour cette facture (ne pas proposer de payer une seconde fois). */
    function ak_connect_invoice_pending_op(PDO $pdo, int $invoice_id): ?array {
        try {
            $q = $pdo->prepare("SELECT * FROM asso_online_payments WHERE kind = 'invoice' AND invoice_id = ? AND status = 'processing' ORDER BY id DESC LIMIT 1");
            $q->execute([$invoice_id]);
            return $q->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (Throwable $e) {
            return null;
        }
    }
}

if (!function_exists('ak_connect_start_checkout')) {
    /**
     * Crée (ou reprend) la page de paiement Stripe Checkout d'un élément, au nom du compte de l'association.
     * $ctx : org_id, kind (invoice|cotisation|don), invoice_id | campaign_id, tier_id, adherent_id (lien signé seulement),
     *        amount_cents (calculé par le serveur), currency, label (ligne affichée par Stripe), description,
     *        payer_name, payer_email, cancel_url, ip.
     * Double clic, deux onglets : même page Stripe (session encore valable plus de 10 minutes, mêmes montant et payeur).
     * @return array{ok: bool, url: string, error: string, op_id: int}
     */
    function ak_connect_start_checkout(PDO $pdo, array $ctx): array {
        $fail = fn(string $e) => ['ok' => false, 'url' => '', 'error' => $e, 'op_id' => 0];
        if (function_exists('ak_is_mobile_app') && ak_is_mobile_app()) return $fail('Le paiement s’ouvre dans votre navigateur, jamais dans l’application.');
        $org_id = (int)($ctx['org_id'] ?? 0);
        $kind = (string)($ctx['kind'] ?? '');
        if (!in_array($kind, ['invoice', 'cotisation', 'don'], true)) return $fail('Paiement inconnu.');
        $av = ak_connect_available($pdo, $org_id, $kind);
        if (!$av['ok']) return $fail('Le paiement en ligne n’est pas proposé pour le moment.');
        $acc = $av['account'];
        $acct = (string)$acc['stripe_account_id'];
        $mode = ak_stripe_get_mode($pdo);
        $amount = (int)($ctx['amount_cents'] ?? 0);
        $currency = strtoupper((string)($ctx['currency'] ?? 'EUR')) ?: 'EUR';
        if ($currency !== 'EUR') return $fail('Le paiement en ligne n’est proposé qu’en euros.');
        if ($amount < 50 || $amount > AK_CONNECT_MAX_CENTS) return $fail('Montant hors limites pour un paiement en ligne.');
        $email = mb_strtolower(trim((string)($ctx['payer_email'] ?? '')));
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) return $fail('Adresse e-mail invalide.');
        $name = mb_substr(trim((string)($ctx['payer_name'] ?? '')), 0, 150);
        $invoice_id = (int)($ctx['invoice_id'] ?? 0);
        $campaign_id = (int)($ctx['campaign_id'] ?? 0);
        $tier_id = (int)($ctx['tier_id'] ?? 0);
        $adherent_id = (int)($ctx['adherent_id'] ?? 0);
        $ref = $kind === 'invoice' ? 'inv' . $invoice_id : 'cmp' . $campaign_id . '_' . substr(sha1($tier_id . '|' . $adherent_id . '|' . $email . '|' . $amount), 0, 12);
        $lock = 'cpay_' . $ref;
        if (!ak_stripe_lock($pdo, $lock, 10)) return $fail('Un paiement est déjà en préparation : patientez quelques secondes.');
        try {
            if ($kind === 'invoice') {
                if (ak_connect_invoice_pending_op($pdo, $invoice_id)) return $fail('Un prélèvement SEPA est déjà en cours pour cette facture : la banque le confirme sous quelques jours ouvrés.');
            }
            // Session encore valable pour le même élément, le même montant et le même payeur : reprise
            $q = $pdo->prepare("SELECT * FROM asso_online_payments
                                WHERE org_id = ? AND kind = ? AND status = 'open' AND stripe_account_id = ? AND amount_cents = ?
                                  AND COALESCE(invoice_id, 0) = ? AND COALESCE(campaign_id, 0) = ? AND COALESCE(tier_id, 0) = ? AND COALESCE(adherent_id, 0) = ?
                                  AND COALESCE(payer_email, '') = ? AND checkout_session_id IS NOT NULL
                                  AND checkout_expires_at > NOW() + INTERVAL 10 MINUTE
                                ORDER BY id DESC LIMIT 1");
            $q->execute([$org_id, $kind, $acct, $amount, $invoice_id, $campaign_id, $tier_id, $adherent_id, $email]);
            if ($op = $q->fetch(PDO::FETCH_ASSOC)) {
                $g = ak_stripe_api_call($pdo, 'GET', '/checkout/sessions/' . rawurlencode((string)$op['checkout_session_id']), [], null, $acct);
                if (!empty($g['ok']) && ($g['data']['status'] ?? '') === 'open' && !empty($g['data']['url'])) {
                    return ['ok' => true, 'url' => (string)$g['data']['url'], 'error' => '', 'op_id' => (int)$op['id']];
                }
            }
            $fee = ak_connect_application_fee($amount);
            $op_id = ak_db_insert($pdo, 'asso_online_payments', [
                'org_id' => $org_id, 'mode' => $mode, 'stripe_account_id' => $acct, 'kind' => $kind,
                'invoice_id' => $invoice_id ?: null, 'campaign_id' => $campaign_id ?: null, 'tier_id' => $tier_id ?: null, 'adherent_id' => $adherent_id ?: null,
                'payer_name' => $name !== '' ? $name : null, 'payer_email' => $email !== '' ? $email : null,
                'amount_cents' => $amount, 'currency' => $currency, 'application_fee_cents' => $fee, 'status' => 'open',
                'created_ip' => mb_substr((string)($ctx['ip'] ?? ''), 0, 45) ?: null,
            ], ['created_at' => 'NOW()', 'updated_at' => 'NOW()']);
            if ($op_id <= 0) return $fail('Paiement non préparé (base de données).');
            $base = ak_stripe_site_base();
            $types = ak_connect_payment_types($acc, $av['settings'], $currency);
            $org = ak_connect_org_row($pdo, $org_id) ?: ['name' => ''];
            $meta = ['ak_kind' => $kind, 'ak_org_id' => (string)$org_id, 'ak_op_id' => (string)$op_id,
                     'ak_ref_id' => (string)($kind === 'invoice' ? $invoice_id : $campaign_id), 'ak' => 'assokit'];
            $params = [
                'mode' => 'payment', 'locale' => 'fr',
                'line_items[0][quantity]' => '1',
                'line_items[0][price_data][currency]' => 'eur',
                'line_items[0][price_data][unit_amount]' => (string)$amount,
                'line_items[0][price_data][product_data][name]' => mb_substr((string)($ctx['label'] ?? 'Paiement'), 0, 120),
                'client_reference_id' => 'akop_' . $op_id,
                'submit_type' => $kind === 'don' ? 'donate' : 'pay',
                'expires_at' => (string)(time() + AK_CONNECT_SESSION_MINUTES * 60),
                'success_url' => $base . '/paiement-confirme?op=' . ak_connect_op_ref($pdo, $op_id) . '&cs={CHECKOUT_SESSION_ID}',
                'cancel_url' => (string)($ctx['cancel_url'] ?? $base),
                'payment_intent_data[description]' => mb_substr((string)($ctx['description'] ?? ($ctx['label'] ?? '')), 0, 250),
            ];
            if (!empty($ctx['product_description'])) $params['line_items[0][price_data][product_data][description]'] = mb_substr((string)$ctx['product_description'], 0, 250);
            foreach ($types as $i => $t) $params['payment_method_types[' . $i . ']'] = $t;
            foreach ($meta as $k => $v) { $params['metadata[' . $k . ']'] = $v; $params['payment_intent_data[metadata][' . $k . ']'] = $v; }
            if ($email !== '') $params['customer_email'] = $email;
            if ($fee > 0) $params['payment_intent_data[application_fee_amount]'] = (string)$fee;
            $r = ak_stripe_api_call($pdo, 'POST', '/checkout/sessions', $params, 'ak-cs-' . $mode . '-' . $op_id, $acct);
            if (empty($r['ok']) || empty($r['data']['id']) || empty($r['data']['url'])) {
                $err = (string)($r['error'] ?? 'pas de réponse');
                $pdo->prepare("UPDATE asso_online_payments SET status = 'failed', failure_message = ?, updated_at = NOW() WHERE id = ? AND status = 'open'")
                    ->execute([mb_substr('Page de paiement non créée : ' . $err, 0, 500), $op_id]);
                error_log('[connect] session org #' . $org_id . ' op #' . $op_id . ' : ' . $err);
                // Compte devenu inaccessible ou désactivé : état relu pour ne plus proposer le bouton
                if (in_array((int)($r['http_code'] ?? 0), [400, 401, 403], true)) ak_connect_refresh_account($pdo, $acct);
                return $fail('Stripe n’a pas pu ouvrir la page de paiement. Réessayez dans quelques minutes ou contactez ' . ($org['name'] ?: 'l’association') . '.');
            }
            $cs = $r['data'];
            $pdo->prepare("UPDATE asso_online_payments SET checkout_session_id = ?, checkout_expires_at = FROM_UNIXTIME(?), updated_at = NOW() WHERE id = ?")
                ->execute([(string)$cs['id'], (int)($cs['expires_at'] ?? (time() + 3600)), $op_id]);
            return ['ok' => true, 'url' => (string)$cs['url'], 'error' => '', 'op_id' => $op_id];
        } finally {
            ak_stripe_unlock($pdo, $lock);
        }
    }
}

if (!function_exists('ak_connect_apply_session')) {
    /**
     * Applique l'état d'une session Stripe Checkout (événement du webhook Connect ou page de retour).
     * $acct : compte Stripe qui a envoyé l'événement (ou relu) — doit être celui du paiement enregistré.
     * $event : completed | async_succeeded | async_failed | expired | return (page de retour : relue chez Stripe).
     * @return string état du paiement après application (paid, processing, failed, expired, open, ignored)
     */
    function ak_connect_apply_session(PDO $pdo, string $acct, array $cs, string $event): string {
        $cs_id = (string)($cs['id'] ?? '');
        if ($cs_id === '') return 'ignored';
        $q = $pdo->prepare("SELECT * FROM asso_online_payments WHERE checkout_session_id = ? LIMIT 1");
        $q->execute([$cs_id]);
        $op = $q->fetch(PDO::FETCH_ASSOC);
        if (!$op) {
            // Session créée mais identifiant pas encore noté (coupure entre Stripe et la base) : par la métadonnée,
            // seulement pour le même compte et le même montant
            $oid = (int)($cs['metadata']['ak_op_id'] ?? 0);
            $op = $oid > 0 ? ak_connect_op($pdo, $oid) : null;
            if (!$op || !empty($op['checkout_session_id'])) return 'ignored';
            $pdo->prepare("UPDATE asso_online_payments SET checkout_session_id = ? WHERE id = ? AND checkout_session_id IS NULL")->execute([$cs_id, (int)$op['id']]);
        }
        // Jamais la métadonnée seule : le compte qui paie doit être celui de l'association, le montant celui demandé
        if ((string)$op['stripe_account_id'] !== $acct) {
            error_log('[connect] session ' . $cs_id . ' : compte ' . $acct . ' différent de celui du paiement #' . $op['id']);
            return 'ignored';
        }
        if ((int)($cs['amount_total'] ?? -1) !== (int)$op['amount_cents'] || strtolower((string)($cs['currency'] ?? '')) !== strtolower((string)$op['currency'])) {
            error_log('[connect] session ' . $cs_id . ' : montant ' . ($cs['amount_total'] ?? '?') . ' différent du paiement #' . $op['id']);
            return 'ignored';
        }
        $pi = is_array($cs['payment_intent'] ?? null) ? (string)($cs['payment_intent']['id'] ?? '') : (string)($cs['payment_intent'] ?? '');
        $status = (string)($cs['status'] ?? '');
        $pay = (string)($cs['payment_status'] ?? '');
        if ($event === 'async_failed') {
            $st = $pdo->prepare("UPDATE asso_online_payments SET status = 'failed', payment_intent_id = COALESCE(?, payment_intent_id),
                                        failure_message = 'Prélèvement SEPA rejeté par la banque', updated_at = NOW()
                                 WHERE id = ? AND status IN ('open', 'processing')");
            $st->execute([$pi !== '' ? $pi : null, (int)$op['id']]);
            if ($st->rowCount() === 1) ak_connect_notify_failed($pdo, ak_connect_op($pdo, (int)$op['id']));
            return (string)(ak_connect_op($pdo, (int)$op['id'])['status'] ?? 'failed');
        }
        if ($status === 'expired') {
            $pdo->prepare("UPDATE asso_online_payments SET status = 'expired', updated_at = NOW() WHERE id = ? AND status = 'open'")->execute([(int)$op['id']]);
            return (string)(ak_connect_op($pdo, (int)$op['id'])['status'] ?? 'expired');
        }
        if ($status === 'complete' && $pay === 'paid') {
            ak_connect_mark_paid($pdo, $op, $pi, $cs);
            return (string)(ak_connect_op($pdo, (int)$op['id'])['status'] ?? 'paid');
        }
        if ($status === 'complete' && $pay === 'unpaid') {
            // Prélèvement SEPA envoyé à la banque : en cours (confirmation sous quelques jours ouvrés)
            $st = $pdo->prepare("UPDATE asso_online_payments SET status = 'processing', payment_intent_id = COALESCE(?, payment_intent_id),
                                        payment_method_type = 'sepa_debit', payer_email = COALESCE(payer_email, ?), updated_at = NOW()
                                 WHERE id = ? AND status = 'open'");
            $st->execute([$pi !== '' ? $pi : null, ak_connect_cs_email($cs), (int)$op['id']]);
            if ($st->rowCount() === 1) ak_connect_notify_processing($pdo, ak_connect_op($pdo, (int)$op['id']));
            return (string)(ak_connect_op($pdo, (int)$op['id'])['status'] ?? 'processing');
        }
        return (string)$op['status'];
    }
}

if (!function_exists('ak_connect_cs_email')) {
    function ak_connect_cs_email(array $cs): ?string {
        $e = mb_strtolower(trim((string)($cs['customer_details']['email'] ?? ($cs['customer_email'] ?? ''))));
        return filter_var($e, FILTER_VALIDATE_EMAIL) ? $e : null;
    }
}

if (!function_exists('ak_connect_mark_paid')) {
    /**
     * Paiement réussi : enregistré EXACTEMENT comme un paiement saisi à la main, une seule fois.
     *   - facture : ak_asso_invoice_mark_paid (statut payé, ligne de règlement « stripe ») ; facture déjà réglée,
     *     annulée ou remboursée entre-temps : le paiement est gardé (« trop-perçu ») et l'association est
     *     prévenue de le rembourser depuis Stripe ;
     *   - cotisation / don : ligne cotisation_payments « payée » (méthode stripe) ; adhésion prolongée pour un
     *     adhérent venu par SON lien signé, sur une campagne qui renouvelle l'adhésion (jamais pour un don).
     * Frais Stripe relus sur la transaction de solde (expand latest_charge.balance_transaction).
     */
    function ak_connect_mark_paid(PDO $pdo, array $op, string $pi_id, array $cs = []): void {
        $acct = (string)$op['stripe_account_id'];
        // Lecture chez Stripe avant la transaction (réseau) : frais, net, moyen, payeur
        $fee = null; $net = null; $charge_id = null; $pm_type = null;
        if ($pi_id !== '') {
            $r = ak_stripe_api_call($pdo, 'GET', '/payment_intents/' . rawurlencode($pi_id), ['expand' => ['latest_charge.balance_transaction']], null, $acct);
            if (!empty($r['ok'])) {
                $ch = $r['data']['latest_charge'] ?? null;
                if (is_array($ch)) {
                    $charge_id = (string)($ch['id'] ?? '') ?: null;
                    $pm_type = (string)($ch['payment_method_details']['type'] ?? '') ?: null;
                    $bt = $ch['balance_transaction'] ?? null;
                    if (is_array($bt)) { $fee = (int)($bt['fee'] ?? 0); $net = (int)($bt['net'] ?? 0); }
                } elseif (is_string($ch)) {
                    $charge_id = $ch;
                }
                if (!$pm_type) $pm_type = (string)(($r['data']['payment_method_types'] ?? ['card'])[0] ?? 'card');
            }
        }
        $email = ak_connect_cs_email($cs);
        $cname = trim((string)($cs['customer_details']['name'] ?? ''));
        $op_id = (int)$op['id'];
        $paid_now = date('Y-m-d H:i:s');
        $pdo->beginTransaction();
        try {
            $st = $pdo->prepare("UPDATE asso_online_payments SET status = 'paid', paid_at = ?, payment_intent_id = COALESCE(?, payment_intent_id),
                                        charge_id = COALESCE(?, charge_id), payment_method_type = COALESCE(?, payment_method_type),
                                        fee_cents = COALESCE(?, fee_cents), net_cents = COALESCE(?, net_cents),
                                        payer_email = COALESCE(payer_email, ?), payer_name = COALESCE(payer_name, ?), updated_at = NOW()
                                 WHERE id = ? AND status IN ('open', 'processing', 'expired', 'failed')");
            $st->execute([$paid_now, $pi_id !== '' ? $pi_id : null, $charge_id, $pm_type, $fee, $net, $email, $cname !== '' ? mb_substr($cname, 0, 150) : null, $op_id]);
            if ($st->rowCount() !== 1) { $pdo->rollBack(); return; }   // déjà enregistré (webhook et page de retour)
            $op = ak_connect_op($pdo, $op_id);
            $method_lbl = $pm_type === 'sepa_debit' ? 'prélèvement SEPA' : 'carte bancaire';
            $overpaid = false;
            if ($op['kind'] === 'invoice') {
                require_once __DIR__ . '/asso-invoice-helpers.php';
                $res = ak_asso_invoice_mark_paid($pdo, (int)$op['org_id'], (int)$op['invoice_id'], 0, [
                    'method' => $pm_type === 'sepa_debit' ? 'prelevement' : 'cb', 'reference' => $pi_id ?: ('Stripe ' . ($cs['id'] ?? '')),
                    'source' => 'stripe', 'online_payment_id' => $op_id,
                ]);
                if (!empty($res['ok'])) {
                    $pdo->prepare("UPDATE asso_online_payments SET invoice_payment_id = ? WHERE id = ?")->execute([(int)($res['payment_id'] ?? 0) ?: null, $op_id]);
                    ak_asso_invoice_audit($pdo, (int)$op['org_id'], (int)$op['invoice_id'], 'paid_online', null, null, 'paid',
                        ['online_payment_id' => $op_id, 'payment_intent' => $pi_id, 'method' => $pm_type, 'amount_cents' => (int)$op['amount_cents']]);
                } else {
                    $overpaid = true;
                    $pdo->prepare("UPDATE asso_online_payments SET overpaid = 1, failure_message = ? WHERE id = ?")
                        ->execute([mb_substr('Facture déjà ' . ($res['state_label'] ?? 'réglée') . ' : paiement à rembourser', 0, 500), $op_id]);
                }
            } else {
                require_once __DIR__ . '/includes-cotisations.php';
                $c = $pdo->prepare("SELECT * FROM cotisation_campaigns WHERE id = ? AND org_id = ?");
                $c->execute([(int)$op['campaign_id'], (int)$op['org_id']]);
                $camp = $c->fetch(PDO::FETCH_ASSOC) ?: [];
                $payer = trim((string)($op['payer_name'] ?? '')) ?: ($cname !== '' ? $cname : (string)($op['payer_email'] ?? ''));
                $cp = ak_db_insert($pdo, 'cotisation_payments', [
                    'org_id' => (int)$op['org_id'], 'campaign_id' => (int)$op['campaign_id'], 'tier_id' => $op['tier_id'] ? (int)$op['tier_id'] : null,
                    'adherent_id' => $op['adherent_id'] ? (int)$op['adherent_id'] : null,
                    'payer_name' => mb_substr($payer, 0, 150), 'payer_email' => $op['payer_email'] ?: $email,
                    'amount' => number_format((int)$op['amount_cents'] / 100, 2, '.', ''), 'currency' => (string)$op['currency'],
                    'payment_method' => 'stripe', 'status' => 'paid', 'reference' => $pi_id ?: null,
                    'notes' => ($op['kind'] === 'don' ? 'Don en ligne' : 'Paiement en ligne') . ' (Stripe, ' . $method_lbl . ')',
                    'paid_at' => $paid_now, 'created_by' => null, 'online_payment_id' => $op_id,
                ], ['created_at' => 'NOW()']);
                $pdo->prepare("UPDATE asso_online_payments SET cotisation_payment_id = ? WHERE id = ?")->execute([$cp ?: null, $op_id]);
                if ($op['kind'] === 'cotisation' && $camp && !empty($op['adherent_id'])) {
                    ck_extend_membership($pdo, (int)$op['org_id'], (int)$op['adherent_id'], $camp, date('Y-m-d'));
                }
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        // Après l'enregistrement : reçu au payeur, cloche et e-mail à l'association (une seule fois)
        $op = ak_connect_op($pdo, $op_id);
        ak_connect_notify_paid($pdo, $op, $overpaid);
    }
}

if (!function_exists('ak_connect_op_context')) {
    /** Libellés d'un paiement : objet (« facture F-2026-012 », « cotisation Adhésions 2026 · Tarif plein », « don »), lien de gestion. */
    function ak_connect_op_context(PDO $pdo, array $op): array {
        $org = ak_connect_org_row($pdo, (int)$op['org_id']) ?: ['name' => 'Association'];
        $ctx = ['org_name' => (string)$org['name'], 'object' => 'paiement', 'link' => '/encaissement-en-ligne', 'number' => '', 'campaign' => ''];
        try {
            if ($op['kind'] === 'invoice') {
                $q = $pdo->prepare("SELECT invoice_number FROM asso_invoices WHERE id = ? AND org_id = ?");
                $q->execute([(int)$op['invoice_id'], (int)$op['org_id']]);
                $ctx['number'] = (string)$q->fetchColumn();
                $ctx['object'] = 'facture ' . $ctx['number'];
                $ctx['link'] = '/mon-asso-facture-edit?id=' . (int)$op['invoice_id'];
            } else {
                $q = $pdo->prepare("SELECT c.name, t.name AS tier FROM cotisation_campaigns c LEFT JOIN cotisation_tiers t ON t.id = ? WHERE c.id = ? AND c.org_id = ?");
                $q->execute([(int)$op['tier_id'], (int)$op['campaign_id'], (int)$op['org_id']]);
                $r = $q->fetch(PDO::FETCH_ASSOC) ?: [];
                $ctx['campaign'] = (string)($r['name'] ?? '');
                $ctx['object'] = ($op['kind'] === 'don' ? 'don' : 'cotisation') . ($ctx['campaign'] !== '' ? ' « ' . $ctx['campaign'] . ' »' : '') . (!empty($r['tier']) ? ' · ' . $r['tier'] : '');
                $ctx['link'] = '/cotisation/' . (int)$op['campaign_id'];
            }
        } catch (Throwable $e) {}
        return $ctx;
    }
}

if (!function_exists('ak_connect_money')) {
    function ak_connect_money(int $cents): string {
        return number_format($cents / 100, 2, ',', "\u{202F}") . "\u{00A0}€";
    }
}

if (!function_exists('ak_connect_notify_org')) {
    /**
     * Prévient l'association : cloche (administrateurs et trésoriers) et e-mail (adresse de facturation et
     * administrateurs). Jamais bloquant. true si au moins un e-mail est parti (ou personne à prévenir).
     */
    function ak_connect_notify_org(PDO $pdo, int $org_id, string $subject, string $html, string $link): bool {
        try {
            @require_once __DIR__ . '/notification-helpers.php';
            if (function_exists('ak_notif_create')) {
                $uc = ak_db_columns($pdo, 'users');
                $q = $pdo->prepare("SELECT id FROM users WHERE org_id = ? AND is_active = 1"
                    . (isset($uc['deleted_at']) ? " AND deleted_at IS NULL" : "")
                    . " AND (role = 'admin'" . (isset($uc['can_manage_finances']) ? " OR (can_manage_finances = 1 AND role <> 'follower')" : "") . ")");
                $q->execute([$org_id]);
                $text = trim(html_entity_decode(strip_tags(str_replace(['</p>', '<br>'], ' ', $html)), ENT_QUOTES, 'UTF-8'));
                foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $uid) ak_notif_create($pdo, (int)$uid, 'paiement', $subject, mb_substr($text, 0, 240), $link);
            }
        } catch (Throwable $e) {
            error_log('[connect] cloche org #' . $org_id . ' : ' . $e->getMessage());
        }
        return ak_stripe_notify_org($pdo, $org_id, $subject, $html, 'Ouvrir dans Assokit', 'https://assokit.fr' . $link);
    }
}

if (!function_exists('ak_connect_send_payer')) {
    /** E-mail au payeur, au nom de l'association (réponse à son adresse de facturation). */
    function ak_connect_send_payer(PDO $pdo, array $op, string $subject, string $html): bool {
        $to = trim((string)($op['payer_email'] ?? ''));
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) return true;   // personne à prévenir
        try {
            @require_once __DIR__ . '/resend-helper.php';
            if (!function_exists('send_transactional_email')) return false;
            $org = ak_connect_org_row($pdo, (int)$op['org_id']) ?: ['name' => 'Association', 'billing_email' => ''];
            $opts = ['tag' => 'paiement_en_ligne', 'from_name' => (string)$org['name']];
            if (filter_var((string)($org['billing_email'] ?? ''), FILTER_VALIDATE_EMAIL)) $opts['reply_to'] = (string)$org['billing_email'];
            $body = function_exists('email_wrap') ? email_wrap($subject, $html) : $html;
            $r = send_transactional_email($to, $subject, $body, $opts);
            return !empty($r['success']);
        } catch (Throwable $e) {
            error_log('[connect] e-mail payeur op #' . $op['id'] . ' : ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('ak_connect_notify_paid')) {
    /** Reçu de paiement au payeur (ce n'est pas un reçu fiscal), alerte à l'association. Une seule fois par paiement. */
    function ak_connect_notify_paid(PDO $pdo, array $op, bool $overpaid): void {
        $c = ak_connect_op_context($pdo, $op);
        $h = 'ak_stripe_h';
        $amount = ak_connect_money((int)$op['amount_cents']);
        $method = ($op['payment_method_type'] ?? '') === 'sepa_debit' ? 'prélèvement SEPA' : 'carte bancaire';
        $org_id = (int)$op['org_id'];
        $who = trim((string)($op['payer_name'] ?? '')) ?: (string)($op['payer_email'] ?? 'un payeur');
        ak_stripe_once($pdo, $org_id, 'cpay_org_' . $op['id'], function () use ($pdo, $op, $c, $h, $amount, $method, $org_id, $who, $overpaid) {
            if ($overpaid) {
                return ak_connect_notify_org($pdo, $org_id, '⚠ Paiement en ligne à rembourser : ' . $c['object'],
                    '<p>' . $h($who) . ' a payé ' . $h($amount) . ' en ligne (' . $method . ') pour la <strong>' . $h($c['object']) . '</strong>, qui était déjà réglée, annulée ou remboursée.</p>'
                    . '<p>Ce paiement est arrivé sur votre compte Stripe : remboursez-le depuis votre tableau de bord Stripe (Paiements › le paiement › Rembourser). Assokit n’a rien modifié sur la facture.</p>',
                    $c['link']);
            }
            $fee = $op['fee_cents'] !== null ? ' Frais Stripe : ' . ak_connect_money((int)$op['fee_cents']) . ', net versé : ' . ak_connect_money((int)$op['net_cents']) . '.' : '';
            $extra = $op['kind'] === 'invoice' ? 'La facture est marquée payée et ses relances s’arrêtent.'
                : ($op['kind'] === 'don' ? 'Le don est enregistré dans la campagne.' : 'Le paiement est enregistré dans la campagne' . (!empty($op['adherent_id']) ? ' et l’adhésion est prolongée.' : '.'));
            return ak_connect_notify_org($pdo, $org_id, '💳 Paiement en ligne reçu : ' . $amount,
                '<p>' . $h($who) . ' a payé <strong>' . $h($amount) . '</strong> en ligne (' . $method . ') pour la ' . $h($c['object']) . '.</p>'
                . '<p>' . $extra . $h($fee) . '</p><p>Les fonds sont versés par Stripe sur votre compte bancaire selon le calendrier de votre compte Stripe.</p>',
                $c['link']);
        });
        if ($overpaid) return;
        ak_stripe_once($pdo, $org_id, 'cpay_rcpt_' . $op['id'], function () use ($pdo, $op, $c, $h, $amount, $method) {
            $date = date('d/m/Y à H:i', strtotime((string)($op['paid_at'] ?: 'now')));
            $rows = [
                ['Bénéficiaire', $c['org_name']], ['Objet', ucfirst($c['object'])], ['Montant', $amount],
                ['Date', $date], ['Moyen de paiement', ucfirst($method)], ['Référence', (string)($op['payment_intent_id'] ?? '')],
            ];
            $tbl = '<table role="presentation" cellspacing="0" cellpadding="0" border="0" style="width:100%;border-collapse:collapse;margin:14px 0;font-size:14px">';
            foreach ($rows as [$k, $v]) {
                if ($v === '') continue;
                $tbl .= '<tr><td style="padding:6px 0;color:#78716C;width:42%">' . $h($k) . '</td><td style="padding:6px 0;color:#1C1917;font-weight:600">' . $h($v) . '</td></tr>';
            }
            $tbl .= '</table>';
            $note = $op['kind'] === 'don'
                ? '<p style="font-size:13px;color:#78716C">Ce reçu confirme votre paiement ; ce n’est pas un reçu fiscal. Pour un reçu fiscal, adressez-vous à ' . $h($c['org_name']) . '.</p>'
                : '<p style="font-size:13px;color:#78716C">Ce reçu confirme votre paiement ; il ne remplace pas la facture de ' . $h($c['org_name']) . '.</p>';
            return ak_connect_send_payer($pdo, $op, 'Reçu de paiement — ' . $c['org_name'],
                '<p>Bonjour,</p><p>' . $h($c['org_name']) . ' a bien reçu votre paiement. Merci !</p>' . $tbl . $note
                . '<p style="font-size:12px;color:#78716C">Paiement encaissé par ' . $h($c['org_name']) . ' via Stripe. Assokit est l’outil de gestion de l’association : il n’encaisse aucun fonds.</p>');
        });
    }
}

if (!function_exists('ak_connect_notify_processing')) {
    /** Prélèvement SEPA envoyé : le payeur sait que la banque confirme sous quelques jours ouvrés. */
    function ak_connect_notify_processing(PDO $pdo, ?array $op): void {
        if (!$op) return;
        $c = ak_connect_op_context($pdo, $op);
        $h = 'ak_stripe_h';
        ak_stripe_once($pdo, (int)$op['org_id'], 'cpay_proc_' . $op['id'], function () use ($pdo, $op, $c, $h) {
            return ak_connect_send_payer($pdo, $op, 'Prélèvement SEPA enregistré — ' . $c['org_name'],
                '<p>Bonjour,</p><p>Votre prélèvement SEPA de <strong>' . $h(ak_connect_money((int)$op['amount_cents'])) . '</strong> pour la ' . $h($c['object'])
                . ' est enregistré. Votre banque le confirme sous quelques jours ouvrés ; vous recevrez alors votre reçu.</p>'
                . '<p style="font-size:12px;color:#78716C">Paiement encaissé par ' . $h($c['org_name']) . ' via Stripe.</p>');
        });
    }
}

if (!function_exists('ak_connect_notify_failed')) {
    /** Prélèvement SEPA rejeté : le payeur et l'association sont prévenus ; rien n'a été enregistré comme payé. */
    function ak_connect_notify_failed(PDO $pdo, ?array $op): void {
        if (!$op) return;
        $c = ak_connect_op_context($pdo, $op);
        $h = 'ak_stripe_h';
        $amount = ak_connect_money((int)$op['amount_cents']);
        ak_stripe_once($pdo, (int)$op['org_id'], 'cpay_fail_' . $op['id'], function () use ($pdo, $op, $c, $h, $amount) {
            $retry = $op['kind'] === 'invoice' ? ' Vous pouvez régler de nouveau depuis la page de la facture.' : ' Vous pouvez payer de nouveau avec le même lien.';
            ak_connect_send_payer($pdo, $op, 'Prélèvement rejeté — ' . $c['org_name'],
                '<p>Bonjour,</p><p>Votre banque a rejeté le prélèvement SEPA de <strong>' . $h($amount) . '</strong> pour la ' . $h($c['object']) . '.' . $h($retry) . '</p>');
            return ak_connect_notify_org($pdo, (int)$op['org_id'], '⚠ Prélèvement SEPA rejeté : ' . $amount,
                '<p>La banque a rejeté le prélèvement SEPA de ' . $h($amount) . ' pour la ' . $h($c['object']) . '. Rien n’a été enregistré comme payé ; le payeur est prévenu.</p>',
                $c['link']);
        });
    }
}

if (!function_exists('ak_connect_handle_refund')) {
    /**
     * Remboursement fait par l'association dans son tableau de bord Stripe (charge.refunded) : le paiement est
     * annulé côté Assokit comme une annulation manuelle.
     *   - cotisation / don remboursé en entier : ligne « remboursée » (hors encaissements et FEC) ; l'adhésion n'est
     *     pas raccourcie (comme l'annulation manuelle : à corriger sur la fiche si besoin) ;
     *   - facture remboursée en entier : règlement retiré (ligne négative dans l'historique), facture « en attente »
     *     ou « en retard » (relances suspendues jusqu'à la décision de l'association : avoir ou nouveau règlement) ;
     *   - remboursement partiel : noté, l'association est prévenue (un avoir partiel s'établit à la main).
     */
    function ak_connect_handle_refund(PDO $pdo, string $acct, array $charge): void {
        $pi = is_array($charge['payment_intent'] ?? null) ? (string)($charge['payment_intent']['id'] ?? '') : (string)($charge['payment_intent'] ?? '');
        if ($pi === '') return;
        $q = $pdo->prepare("SELECT * FROM asso_online_payments WHERE payment_intent_id = ? AND stripe_account_id = ? LIMIT 1");
        $q->execute([$pi, $acct]);
        $op = $q->fetch(PDO::FETCH_ASSOC);
        if (!$op || !in_array($op['status'], ['paid', 'partially_refunded', 'disputed', 'refunded'], true)) return;
        $refunded = min((int)($charge['amount_refunded'] ?? 0), (int)$op['amount_cents']);
        if ($refunded <= (int)$op['refunded_cents']) return;   // déjà appliqué (événement rejoué ou en retard)
        $full = $refunded >= (int)$op['amount_cents'];
        $c = ak_connect_op_context($pdo, $op);
        $h = 'ak_stripe_h';
        $pdo->beginTransaction();
        try {
            $st = $pdo->prepare("UPDATE asso_online_payments SET refunded_cents = ?, status = ?, updated_at = NOW() WHERE id = ? AND refunded_cents < ?");
            $st->execute([$refunded, $full ? 'refunded' : 'partially_refunded', (int)$op['id'], $refunded]);
            if ($st->rowCount() !== 1) { $pdo->rollBack(); return; }
            $note = '';
            if ($full && empty($op['overpaid'])) {
                if ($op['kind'] === 'invoice') {
                    $inv = $pdo->prepare("SELECT id, status, due_at, invoice_number FROM asso_invoices WHERE id = ? AND org_id = ? FOR UPDATE");
                    $inv->execute([(int)$op['invoice_id'], (int)$op['org_id']]);
                    $i = $inv->fetch(PDO::FETCH_ASSOC);
                    if ($i && $i['status'] === 'paid') {
                        $back = (!empty($i['due_at']) && strtotime((string)$i['due_at']) < time()) ? 'overdue' : 'pending';
                        // Relances automatiques suspendues (dunning_enabled = 0, comme une facture annulée) : le client vient
                        // d'être remboursé, l'association décide (avoir ou nouveau règlement) avant toute relance
                        $ic = ak_db_columns($pdo, 'asso_invoices');
                        $pdo->prepare("UPDATE asso_invoices SET status = ?, paid_at = NULL, confirmed_paid_by_org_at = NULL, confirmed_paid_by_user_id = NULL,
                                              payment_method = NULL, payment_reference = NULL"
                                      . (isset($ic['dunning_enabled']) ? ", dunning_enabled = 0" : "") . (isset($ic['next_dunning_at']) ? ", next_dunning_at = NULL" : "")
                                      . ", updated_at = NOW() WHERE id = ?")
                            ->execute([$back, (int)$i['id']]);
                        require_once __DIR__ . '/asso-invoice-helpers.php';
                        $src = ak_db_pick($pdo, 'asso_invoice_payments', 'source', ['stripe', 'admin']) ?? 'admin';
                        try {
                            $pdo->prepare("INSERT INTO asso_invoice_payments (invoice_id, org_id, amount_cents, paid_at, payment_method, reference, notes, source, recorded_by_user_id)
                                           VALUES (?, ?, ?, NOW(), ?, ?, ?, ?, NULL)")
                                ->execute([(int)$i['id'], (int)$op['org_id'], -$refunded, ($op['payment_method_type'] ?? '') === 'sepa_debit' ? 'prelevement' : 'cb',
                                           $pi, 'Remboursement Stripe du paiement en ligne', $src]);
                        } catch (Throwable $e) {
                            error_log('[connect] historique remboursement : ' . $e->getMessage());
                        }
                        ak_asso_invoice_audit($pdo, (int)$op['org_id'], (int)$i['id'], 'refunded_online', null, 'paid', $back,
                            ['online_payment_id' => (int)$op['id'], 'payment_intent' => $pi, 'amount_cents' => $refunded]);
                        $note = 'La facture ' . $i['invoice_number'] . ' repasse « à payer », sans relance automatique. Si la vente est annulée, établissez un avoir (bouton « Créer un avoir ») ; sinon, attendez un nouveau règlement.';
                    }
                } elseif (!empty($op['cotisation_payment_id'])) {
                    $pdo->prepare("UPDATE cotisation_payments SET status = 'refunded', notes = CONCAT(COALESCE(notes, ''), ?) WHERE id = ? AND org_id = ?")
                        ->execute([' · Remboursé depuis Stripe le ' . date('d/m/Y'), (int)$op['cotisation_payment_id'], (int)$op['org_id']]);
                    $note = 'Le paiement est passé « Remboursé » dans la campagne'
                        . ($op['kind'] === 'cotisation' && !empty($op['adherent_id']) ? ' ; la date de fin d’adhésion n’a pas été modifiée (corrigez-la sur la fiche de l’adhérent si besoin).' : '.');
                }
            } elseif (!$full) {
                if (!empty($op['cotisation_payment_id'])) {
                    $pdo->prepare("UPDATE cotisation_payments SET notes = CONCAT(COALESCE(notes, ''), ?) WHERE id = ? AND org_id = ?")
                        ->execute([' · Remboursement partiel Stripe : ' . ak_connect_money($refunded) . ' le ' . date('d/m/Y'), (int)$op['cotisation_payment_id'], (int)$op['org_id']]);
                }
                $note = $op['kind'] === 'invoice' ? 'Remboursement partiel : la facture reste payée ; établissez un avoir du montant remboursé.' : 'Remboursement partiel noté sur le paiement.';
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        ak_stripe_once($pdo, (int)$op['org_id'], 'cpay_ref_' . $op['id'] . '_' . $refunded, function () use ($pdo, $op, $c, $h, $refunded, $note) {
            return ak_connect_notify_org($pdo, (int)$op['org_id'], '↩ Paiement en ligne remboursé : ' . ak_connect_money($refunded),
                '<p>Un remboursement de <strong>' . $h(ak_connect_money($refunded)) . '</strong> a été fait depuis votre compte Stripe pour la ' . $h($c['object']) . '.</p>'
                . ($note !== '' ? '<p>' . $h($note) . '</p>' : ''), $c['link']);
        });
    }
}

if (!function_exists('ak_connect_handle_dispute')) {
    /** Contestation du payeur (charge.dispute.created) : l'association répond depuis son tableau de bord Stripe. */
    function ak_connect_handle_dispute(PDO $pdo, string $acct, array $dispute): void {
        $pi = (string)($dispute['payment_intent'] ?? '');
        $q = $pdo->prepare("SELECT * FROM asso_online_payments WHERE stripe_account_id = ? AND (payment_intent_id = ? OR charge_id = ?) LIMIT 1");
        $q->execute([$acct, $pi, (string)($dispute['charge'] ?? '')]);
        $op = $q->fetch(PDO::FETCH_ASSOC);
        if (!$op) return;
        $pdo->prepare("UPDATE asso_online_payments SET status = 'disputed', updated_at = NOW() WHERE id = ? AND status = 'paid'")->execute([(int)$op['id']]);
        $c = ak_connect_op_context($pdo, $op);
        $h = 'ak_stripe_h';
        ak_stripe_once($pdo, (int)$op['org_id'], 'cpay_disp_' . ($dispute['id'] ?? $op['id']), function () use ($pdo, $op, $c, $h) {
            return ak_connect_notify_org($pdo, (int)$op['org_id'], '⚠ Paiement en ligne contesté : ' . ak_connect_money((int)$op['amount_cents']),
                '<p>Le payeur conteste auprès de sa banque le paiement de ' . $h(ak_connect_money((int)$op['amount_cents'])) . ' pour la ' . $h($c['object']) . '.</p>'
                . '<p>Répondez depuis votre tableau de bord Stripe (Paiements › Litiges) avant la date indiquée par Stripe, avec vos justificatifs. Les fonds contestés sont retenus par Stripe jusqu’à la décision de la banque.</p>',
                $c['link']);
        });
    }
}

if (!function_exists('ak_connect_handle_deauthorized')) {
    /** L'association a retiré l'accès d'Assokit à son compte Stripe : plus de paiement en ligne, elle est prévenue. */
    function ak_connect_handle_deauthorized(PDO $pdo, string $acct): void {
        $row = ak_connect_account_by_stripe_id($pdo, $acct);
        if (!$row || !ak_connect_mark_disconnected($pdo, $acct, 'accès retiré depuis Stripe')) return;
        ak_stripe_once($pdo, (int)$row['org_id'], 'cpay_deauth_' . $acct, function () use ($pdo, $row) {
            return ak_connect_notify_org($pdo, (int)$row['org_id'], 'Paiements en ligne désactivés',
                '<p>L’accès d’Assokit à votre compte Stripe a été retiré depuis Stripe : le paiement en ligne n’est plus proposé sur vos factures, cotisations et dons.</p>'
                . '<p>Pour le rétablir, reliez de nouveau votre compte depuis la page « Paiements en ligne ».</p>', '/encaissement-en-ligne');
        });
    }
}

if (!function_exists('ak_connect_platform_stats')) {
    /** Cockpit du Fondateur : associations reliées et volumes encaissés (mois en cours, total), pour le mode en cours. */
    function ak_connect_platform_stats(PDO $pdo): array {
        $out = ['accounts' => [], 'month_count' => 0, 'month_cents' => 0, 'total_cents' => 0];
        if (ak_connect_schema_missing($pdo)) return $out;
        $mode = ak_stripe_get_mode($pdo);
        try {
            $q = $pdo->prepare("SELECT a.*, o.name AS org_name,
                                       (SELECT COUNT(*) FROM asso_online_payments p WHERE p.org_id = a.org_id AND p.mode = a.mode AND p.status IN ('paid', 'partially_refunded', 'disputed')) AS nb_paid,
                                       (SELECT COALESCE(SUM(p.amount_cents - p.refunded_cents), 0) FROM asso_online_payments p WHERE p.org_id = a.org_id AND p.mode = a.mode AND p.status IN ('paid', 'partially_refunded', 'disputed')) AS total_cents,
                                       (SELECT COALESCE(SUM(p.amount_cents - p.refunded_cents), 0) FROM asso_online_payments p WHERE p.org_id = a.org_id AND p.mode = a.mode AND p.status IN ('paid', 'partially_refunded', 'disputed') AND p.paid_at >= DATE_FORMAT(NOW(), '%Y-%m-01')) AS month_cents,
                                       (SELECT MAX(p.paid_at) FROM asso_online_payments p WHERE p.org_id = a.org_id AND p.mode = a.mode AND p.paid_at IS NOT NULL) AS last_paid
                                FROM org_stripe_accounts a LEFT JOIN organizations o ON o.id = a.org_id
                                WHERE a.mode = ? ORDER BY a.disconnected_at IS NOT NULL, a.charges_enabled DESC, total_cents DESC, a.id DESC LIMIT 200");
            $q->execute([$mode]);
            $out['accounts'] = $q->fetchAll(PDO::FETCH_ASSOC);
            $m = $pdo->prepare("SELECT COUNT(*), COALESCE(SUM(amount_cents - refunded_cents), 0) FROM asso_online_payments
                                WHERE mode = ? AND status IN ('paid', 'partially_refunded', 'disputed') AND paid_at >= DATE_FORMAT(NOW(), '%Y-%m-01')");
            $m->execute([$mode]);
            [$out['month_count'], $out['month_cents']] = array_map('intval', $m->fetch(PDO::FETCH_NUM));
            $t = $pdo->prepare("SELECT COALESCE(SUM(amount_cents - refunded_cents), 0) FROM asso_online_payments WHERE mode = ? AND status IN ('paid', 'partially_refunded', 'disputed')");
            $t->execute([$mode]);
            $out['total_cents'] = (int)$t->fetchColumn();
        } catch (Throwable $e) {
            error_log('[connect] statistiques : ' . $e->getMessage());
        }
        return $out;
    }
}
