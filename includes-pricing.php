<?php
// asso_plans.price_cents (et price_yearly_cents) = prix HT, comme la grille publique (tarifs.php :
// « /mois HT », « Tarifs HT »), la saisie de fondateur-plans (« Prix mensuel HT ») et le MRR du
// cockpit (« MRR (HT) »). La TVA est ajoutée ici pour les structures non assujetties (TTC).
if (!function_exists('pricing_compute_for_org')) {
    function pricing_compute_for_org(array $plan, ?array $org, string $cycle = 'monthly'): array {
        $vat_rate = (float)($plan['vat_rate_pct'] ?? 20.00);
        $is_company = !empty($org) && (!empty($org['vat_subject']) || (!empty($org['siret']) && trim($org['siret']) !== ''));
        if ($cycle === 'yearly') {
            $ht_cents = (int)($plan['price_yearly_cents'] ?? 0);
            if ($ht_cents <= 0) {
                $monthly_ht = (int)($plan['price_cents'] ?? 0);
                $disc = (float)($plan['yearly_discount_pct'] ?? 15.00);
                $ht_cents = (int)round($monthly_ht * 12 * (1 - $disc/100));
            }
        } else {
            $ht_cents = (int)($plan['price_cents'] ?? 0);
        }
        $vat_cents = (int)round($ht_cents * $vat_rate / 100);
        $ttc_cents = $ht_cents + $vat_cents;
        $billed_unit = $is_company ? 'ht' : 'ttc';
        $billed_cents = $is_company ? $ht_cents : $ttc_cents;
        return [
            'ht_cents' => $ht_cents, 'ttc_cents' => $ttc_cents, 'vat_cents' => $vat_cents,
            'vat_rate' => $vat_rate, 'cycle' => $cycle,
            'billed_unit' => $billed_unit, 'billed_cents' => $billed_cents,
            'label_unit' => $is_company ? 'HT' : 'TTC', 'is_company' => $is_company,
        ];
    }
}
if (!function_exists('pricing_format_eur')) {
    function pricing_format_eur(int $cents, bool $with_currency = true): string {
        return number_format($cents/100, 2, ',', ' ') . ($with_currency ? ' €' : '');
    }
}
if (!function_exists('pricing_label_period')) {
    function pricing_label_period(string $cycle): string {
        return $cycle === 'yearly' ? '/an' : '/mois';
    }
}
if (!function_exists('pricing_org_is_vat_subject')) {
    function pricing_org_is_vat_subject(?array $org): bool {
        if (empty($org)) return false;
        if (!empty($org['vat_subject'])) return true;
        if (!empty($org['siret']) && trim($org['siret']) !== '') return true;
        return false;
    }
}

// ============================================================
// OFFRE ASSOKIT — prix HT, TVA de la plateforme, TTC
// ============================================================
// Un seul calcul pour le site public, la page de paiement, Mon abonnement et les e-mails :
// prix HT de la formule « assokit » (asso_plans.price_cents), TVA d'ak_vat_applicable_rate()
// (stripe-config-helpers.php, la même que celle que Stripe facture), TTC = HT + TVA.
// Le prix de référence ne sert que si la base ne répond pas ; le TTC n'est jamais écrit en dur.
if (!defined('AK_OFFER_HT_CENTS')) define('AK_OFFER_HT_CENTS', 4999);
if (!defined('AK_ADDON_DOMAIN_HT_CENTS')) define('AK_ADDON_DOMAIN_HT_CENTS', 1000);

if (!function_exists('ak_price_eur')) {
    /** 4999 → « 49,99 € » (espace insécable avant €). */
    function ak_price_eur(int $cents): string {
        return number_format($cents / 100, 2, ',', "\u{00A0}") . "\u{00A0}€";
    }
}

if (!function_exists('ak_price_split')) {
    /**
     * Montant HT (centimes) → TVA → TTC. $vat_rate en % : 0 = pas de TVA (art. 293 B du CGI),
     * null = taux inconnu (base indisponible : seul le HT est affiché).
     * Clés : ht_cents, vat_cents, ttc_cents, vat_rate, has_vat, vat_known, ht, vat, ttc (« 49,99 € »),
     * rate_label (« 20 % »), per_month (« 49,99 € HT/mois »), ttc_note (« 59,99 € TTC »,
     * « TVA non applicable » ou ''), label (« 49,99 € HT/mois (59,99 € TTC) »).
     */
    function ak_price_split(int $ht_cents, ?float $vat_rate): array {
        $known = $vat_rate !== null;
        $rate = $known ? max(0.0, (float)$vat_rate) : 0.0;
        $vat_cents = (int)round($ht_cents * $rate / 100);
        $p = [
            'ht_cents' => $ht_cents, 'vat_cents' => $vat_cents, 'ttc_cents' => $ht_cents + $vat_cents,
            'vat_rate' => $rate, 'has_vat' => $rate > 0, 'vat_known' => $known,
            'ht' => ak_price_eur($ht_cents), 'vat' => ak_price_eur($vat_cents), 'ttc' => ak_price_eur($ht_cents + $vat_cents),
            'rate_label' => rtrim(rtrim(number_format($rate, 2, ',', ''), '0'), ',') . "\u{00A0}%",
        ];
        if ($p['has_vat'] || !$known) {
            $p['per_month'] = $p['ht'] . ' HT/mois';
            $p['ttc_note'] = $p['has_vat'] ? $p['ttc'] . ' TTC' : '';
        } else {
            $p['per_month'] = $p['ht'] . '/mois';
            $p['ttc_note'] = 'TVA non applicable';
        }
        $p['label'] = $p['per_month'] . ($p['ttc_note'] !== '' ? ' (' . $p['ttc_note'] . ')' : '');
        return $p;
    }
}

if (!function_exists('ak_vat_rate_for_display')) {
    /** Taux de TVA de la plateforme (ak_vat_applicable_rate), null si la base n'est pas disponible. */
    function ak_vat_rate_for_display(?PDO $pdo): ?float {
        if (!$pdo) return null;
        if (!function_exists('ak_vat_applicable_rate') && is_file(__DIR__ . '/stripe-config-helpers.php')) {
            require_once __DIR__ . '/stripe-config-helpers.php';
        }
        return function_exists('ak_vat_applicable_rate') ? ak_vat_applicable_rate($pdo) : null;
    }
}

if (!defined('AK_OFFER_CACHE_FILE')) define('AK_OFFER_CACHE_FILE', __DIR__ . '/uploads/offer-price.json');

if (!function_exists('ak_offer_price')) {
    /**
     * Prix de la formule Assokit (voir ak_price_split pour les clés). $pdo null : celui de la page
     * ($GLOBALS['pdo'], config.php chargé). Les pages vitrines n'ouvrent pas la base (pas de session ni
     * de dépendance à MariaDB pour l'accueil) : elles lisent le dernier prix lu en base, gardé dans
     * AK_OFFER_CACHE_FILE (données publiques) à chaque calcul avec la base (Tarifs, inscription,
     * Mon abonnement…). Sans ce relevé : prix de référence HT seul, sans TTC inventé.
     */
    function ak_offer_price(?PDO $pdo = null): array {
        static $cache = [];
        if (!$pdo && isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) $pdo = $GLOBALS['pdo'];
        $key = $pdo ? 'db' : 'none';
        if (isset($cache[$key])) return $cache[$key];
        if (!$pdo) {
            $saved = is_file(AK_OFFER_CACHE_FILE) ? json_decode((string)@file_get_contents(AK_OFFER_CACHE_FILE), true) : null;
            if (is_array($saved) && (int)($saved['ht_cents'] ?? 0) > 0 && isset($saved['vat_rate']) && is_numeric($saved['vat_rate'])) {
                return $cache[$key] = ak_price_split((int)$saved['ht_cents'], (float)$saved['vat_rate']);
            }
            return $cache[$key] = ak_price_split(AK_OFFER_HT_CENTS, null);
        }
        $ht = AK_OFFER_HT_CENTS;
        try {
            $v = (int)$pdo->query("SELECT price_cents FROM asso_plans WHERE slug = 'assokit' LIMIT 1")->fetchColumn();
            if ($v > 0) $ht = $v;
        } catch (Throwable $e) {}
        $p = ak_price_split($ht, ak_vat_rate_for_display($pdo));
        if ($p['vat_known']) {
            $json = json_encode(['ht_cents' => $p['ht_cents'], 'vat_rate' => $p['vat_rate']]);
            if (@file_get_contents(AK_OFFER_CACHE_FILE) !== $json && is_dir(dirname(AK_OFFER_CACHE_FILE))) {
                @file_put_contents(AK_OFFER_CACHE_FILE, $json, LOCK_EX);
            }
        }
        return $cache[$key] = $p;
    }
}

if (!function_exists('ak_offer_jsonld')) {
    /** Offre schema.org de la formule Assokit : prix mensuel HT, TVA non comprise. */
    function ak_offer_jsonld(?PDO $pdo = null): array {
        $o = ak_offer_price($pdo);
        $price = number_format($o['ht_cents'] / 100, 2, '.', '');
        return [
            '@type' => 'Offer', 'name' => 'Assokit', 'price' => $price, 'priceCurrency' => 'EUR',
            'priceSpecification' => [
                '@type' => 'UnitPriceSpecification', 'price' => $price, 'priceCurrency' => 'EUR',
                'valueAddedTaxIncluded' => false, 'unitText' => 'mois',
            ],
            'url' => 'https://assokit.fr/tarifs',
        ];
    }
}
