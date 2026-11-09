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
// GRILLE TARIFAIRE (novembre 2026) : Essentiel, Pro, Équipe, Sur-mesure
// ============================================================
// Un seul calcul pour le site public, la page de paiement, Mon abonnement et les e-mails :
// prix HT de chaque formule (asso_plans.price_cents), TVA d'ak_vat_applicable_rate()
// (stripe-config-helpers.php, la même que celle que Stripe facture), TTC = HT + TVA.
// Slugs de la grille : ak-essentiel (Essentiel), assokit (affichée « Pro »), ak-equipe (Équipe),
// sur-mesure (sur devis, prix jamais affiché). Les prix de référence ci-dessous ne servent que
// si la base ne répond pas, ou tant que la ligne n'a pas reçu les valeurs de la grille
// (asso_plans.grille_version < 202611 : migrations/2026-11-09-grille-tarifaire.sql) ; le TTC
// n'est jamais écrit en dur. Aucun prix d'ancienne formule n'est affiché sur le site.
if (!defined('AK_OFFER_HT_CENTS')) define('AK_OFFER_HT_CENTS', 2999);         // Pro (slug assokit)
if (!defined('AK_ADDON_DOMAIN_HT_CENTS')) define('AK_ADDON_DOMAIN_HT_CENTS', 1000); // option domaine : plus vendue (fonction non livrée)
if (!defined('AK_GRID_VERSION')) define('AK_GRID_VERSION', 202611);
/** Prix masqué du Sur-mesure : jamais de montant, toujours suivi de « Sur devis — nous contacter ». */
if (!defined('AK_PRICE_MASK')) define('AK_PRICE_MASK', "****,**\u{00A0}€\u{00A0}HT/mois");
/** Note de franchise de TVA : seulement quand le taux de la plateforme vaut 0 (ak_vat_note). */
if (!defined('AK_VAT_293B_NOTE')) define('AK_VAT_293B_NOTE', 'TVA non applicable (art. 293 B du CGI) : le prix HT est le prix payé.');

if (!function_exists('ak_grid_reference')) {
    /**
     * Valeurs de référence de la grille (spécification du 09/11/2026, limites de membres revues
     * ensuite : Essentiel 50, Équipe 3 000), par slug : prix HT en centimes et limites affichées.
     * Servent de repli ; la base fait foi dès que la ligne porte grille_version >= AK_GRID_VERSION.
     */
    function ak_grid_reference(): array {
        return [
            'ak-essentiel' => ['price' => 1999, 'adherents' => 50,   'managers' => 3,  'ai' => 30,  'emails' => 500,  'mail_ai' => 0],
            'assokit'      => ['price' => 2999, 'adherents' => 500,  'managers' => 10, 'ai' => 300, 'emails' => 3000, 'mail_ai' => 0],
            'ak-equipe'    => ['price' => 5999, 'adherents' => 3000, 'managers' => 25, 'ai' => 500, 'emails' => 8000, 'mail_ai' => 500],
        ];
    }
}

if (!function_exists('ak_price_eur')) {
    /** 2999 → « 29,99 € » (espace insécable avant €). */
    function ak_price_eur(int $cents): string {
        return number_format($cents / 100, 2, ',', "\u{00A0}") . "\u{00A0}€";
    }
}

if (!function_exists('ak_price_split')) {
    /**
     * Montant HT (centimes) → TVA → TTC. $vat_rate en % : 0 = pas de TVA (art. 293 B du CGI),
     * null = taux inconnu (base indisponible : seul le HT est affiché).
     * Clés : ht_cents, vat_cents, ttc_cents, vat_rate, has_vat, vat_known, ht, vat, ttc (« 29,99 € »),
     * rate_label (« 20 % »), per_month (« 29,99 € HT/mois », toujours « HT », même sans TVA),
     * ttc_note (« 35,99 € TTC », « TVA non applicable » ou ''), label (« 29,99 € HT/mois (35,99 € TTC) »).
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
        // « HT/mois » même quand le taux vaut 0 : c'est le format affiché partout (grille 2026)
        $p['per_month'] = $p['ht'] . "\u{00A0}HT/mois";
        if ($p['has_vat'])  $p['ttc_note'] = $p['ttc'] . "\u{00A0}TTC";
        elseif ($known)     $p['ttc_note'] = 'TVA non applicable';
        else                $p['ttc_note'] = '';
        $p['label'] = $p['per_month'] . ($p['has_vat'] ? ' (' . $p['ttc_note'] . ')' : '');
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

if (!function_exists('ak_manual_invoice_vat_rate')) {
    /**
     * Taux de TVA des factures d'abonnement émises hors Stripe (paiement sur facture ou virement :
     * cron-job-renouvellements.php, « Mon abonnement »). Le prix de la formule est HT (asso_plans).
     * Société assujettie (company_settings.vat_subject) : taux de la plateforme (ak_vat_applicable_rate,
     * le même que la page de paiement et Stripe), 20 % s'il y est désactivé ; société non assujettie :
     * null, la facture porte « TVA non applicable, art. 293 B du CGI ».
     */
    function ak_manual_invoice_vat_rate(PDO $pdo): ?float {
        if (!function_exists('ak_company') && is_file(__DIR__ . '/company-helpers.php')) require_once __DIR__ . '/company-helpers.php';
        if (!function_exists('ak_company') || (int)ak_company('vat_subject') !== 1) return null;
        $rate = ak_vat_rate_for_display($pdo);
        return ($rate !== null && $rate > 0) ? (float)$rate : 20.0;
    }
}

if (!defined('AK_OFFER_CACHE_FILE')) define('AK_OFFER_CACHE_FILE', __DIR__ . '/uploads/offer-price.json');

if (!function_exists('ak_pricing_state')) {
    /**
     * État tarifaire public : ['vat_rate' => ?float, 'plans' => [slug => [price, adherents, managers,
     * ai, emails, mail_ai]], 'from_cents' => int, 'stripe' => bool, 'source' => 'db'|'cache'|'ref'].
     * $pdo null : celui de la page ($GLOBALS['pdo'], config.php chargé). Les pages vitrines n'ouvrent
     * pas la base (pas de session ni de dépendance à MariaDB pour l'accueil) : elles lisent le dernier
     * relevé gardé dans AK_OFFER_CACHE_FILE (données publiques), réécrit à chaque calcul avec la base
     * (Tarifs, inscription, Mon abonnement…). Sans relevé : valeurs de référence, HT seul, sans TTC inventé.
     */
    function ak_pricing_state(?PDO $pdo = null): array {
        static $cache = [];
        if (!$pdo && isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) $pdo = $GLOBALS['pdo'];
        $key = $pdo ? 'db' : 'none';
        if (isset($cache[$key])) return $cache[$key];
        $ref = ak_grid_reference();
        $ref_from = min(array_column($ref, 'price'));

        if (!$pdo) {
            $saved = is_file(AK_OFFER_CACHE_FILE) ? json_decode((string)@file_get_contents(AK_OFFER_CACHE_FILE), true) : null;
            if (is_array($saved) && isset($saved['vat_rate']) && is_numeric($saved['vat_rate'])) {
                $plans = $ref;
                // Relevé de la grille (« plans ») ; un ancien relevé ne contient que ht_cents (prix
                // d'avant la grille) : il n'est pas repris, la référence s'applique.
                if (isset($saved['plans']) && is_array($saved['plans'])) {
                    foreach ($ref as $slug => $r) {
                        if (isset($saved['plans'][$slug]) && is_array($saved['plans'][$slug]) && (int)($saved['plans'][$slug]['price'] ?? 0) > 0) {
                            $plans[$slug] = array_merge($r, array_map(fn($v) => $v === null ? null : (int)$v, $saved['plans'][$slug]));
                        }
                    }
                }
                $from = (int)($saved['from_cents'] ?? 0);
                return $cache[$key] = [
                    'vat_rate' => (float)$saved['vat_rate'], 'plans' => $plans,
                    'from_cents' => $from > 0 ? $from : min(array_column($plans, 'price')),
                    'stripe' => !empty($saved['stripe']), 'source' => 'cache',
                ];
            }
            return $cache[$key] = ['vat_rate' => null, 'plans' => $ref, 'from_cents' => $ref_from, 'stripe' => false, 'source' => 'ref'];
        }

        $plans = $ref;
        $from = 0;
        try {
            $slugs = "'" . implode("','", array_keys($ref)) . "'";
            $rows = $pdo->query("SELECT slug, price_cents, grille_version, limit_adherents, limit_managers,
                                        limit_ai_text_per_month, limit_emails_per_month, limit_mail_ai_batches_per_month
                                 FROM asso_plans WHERE slug IN ($slugs)")->fetchAll(PDO::FETCH_ASSOC);
            $n = function ($v) { return ($v === null || $v === '') ? null : (int)$v; };
            foreach ($rows as $r) {
                // Ligne pas encore passée à la grille (ex. assokit à l'ancien prix, tant que des
                // clients y sont rattachés) : la référence s'applique, l'ancien prix n'est jamais affiché.
                if ((int)($r['grille_version'] ?? 0) < AK_GRID_VERSION || (int)$r['price_cents'] <= 0) continue;
                $plans[$r['slug']] = [
                    'price' => (int)$r['price_cents'], 'adherents' => $n($r['limit_adherents']), 'managers' => $n($r['limit_managers']),
                    'ai' => $n($r['limit_ai_text_per_month']), 'emails' => $n($r['limit_emails_per_month']),
                    'mail_ai' => $n($r['limit_mail_ai_batches_per_month']),
                ];
            }
            // « dès … » : plus petit prix visible, payant, hors essai et hors devis, d'une ligne de la grille
            $from = (int)$pdo->query("SELECT MIN(price_cents) FROM asso_plans
                                      WHERE is_visible = 1 AND price_cents > 0 AND COALESCE(is_trial, 0) = 0
                                        AND COALESCE(is_custom_quote, 0) = 0 AND COALESCE(grille_version, 0) >= " . (int)AK_GRID_VERSION)->fetchColumn();
        } catch (Throwable $e) {
            // Base sans la migration de la grille (colonne grille_version absente) : référence
        }
        if ($from <= 0) $from = min(array_column($plans, 'price'));
        $stripe = false;
        try {
            if (!function_exists('ak_stripe_is_configured') && is_file(__DIR__ . '/stripe-config-helpers.php')) require_once __DIR__ . '/stripe-config-helpers.php';
            $stripe = function_exists('ak_stripe_is_configured') && ak_stripe_is_configured($pdo);
        } catch (Throwable $e) {}
        $vat = ak_vat_rate_for_display($pdo);
        $state = ['vat_rate' => $vat, 'plans' => $plans, 'from_cents' => $from, 'stripe' => $stripe, 'source' => 'db'];
        if ($vat !== null) {
            $json = json_encode([
                'ht_cents' => $plans['assokit']['price'], 'vat_rate' => $vat, 'plans' => $plans,
                'from_cents' => $from, 'stripe' => $stripe, 'grid' => AK_GRID_VERSION,
            ]);
            if (@file_get_contents(AK_OFFER_CACHE_FILE) !== $json && is_dir(dirname(AK_OFFER_CACHE_FILE))) {
                @file_put_contents(AK_OFFER_CACHE_FILE, $json, LOCK_EX);
            }
        }
        return $cache[$key] = $state;
    }
}

if (!function_exists('ak_plan_price')) {
    /** Prix d'une formule de la grille (clés d'ak_price_split). Slug inconnu : prix de Pro. */
    function ak_plan_price(string $slug, ?PDO $pdo = null): array {
        $st = ak_pricing_state($pdo);
        $cents = (int)($st['plans'][$slug]['price'] ?? $st['plans']['assokit']['price']);
        return ak_price_split($cents, $st['vat_rate']);
    }
}

if (!function_exists('ak_plan_price_label')) {
    /**
     * « 19,99 € HT/mois », toujours « HT », même quand le taux vaut 0 ; « 19,99 € HT/mois
     * (23,99 € TTC) » si la TVA s'applique un jour (et la note 293 B disparaît : ak_vat_note).
     */
    function ak_plan_price_label(int $cents): string {
        return ak_price_split($cents, ak_pricing_state()['vat_rate'])['label'];
    }
}

if (!function_exists('ak_vat_note')) {
    /**
     * Note à placer sous les prix : « TVA non applicable (art. 293 B du CGI) : le prix HT est le
     * prix payé. » seulement si le taux de la plateforme est connu et nul ; « Prix HT, TVA de 20 %
     * en sus. » s'il s'applique ; « Prix HT. » si le taux est inconnu (base indisponible).
     */
    function ak_vat_note(?PDO $pdo = null): string {
        $st = ak_pricing_state($pdo);
        if ($st['vat_rate'] === null) return 'Prix HT.';
        $p = ak_price_split(100, $st['vat_rate']);
        return $p['has_vat'] ? 'Prix HT, TVA de ' . $p['rate_label'] . ' en sus.' : AK_VAT_293B_NOTE;
    }
}

if (!function_exists('ak_offer_price')) {
    /**
     * Prix de la formule Pro (slug assokit), clés d'ak_price_split. Conservé pour les pages et les
     * e-mails qui ne citent qu'un prix : « Gardez tout pour 29,99 € HT/mois ».
     */
    function ak_offer_price(?PDO $pdo = null): array {
        return ak_plan_price('assokit', $pdo);
    }
}

if (!function_exists('ak_offer_from_price')) {
    /** « dès 19,99 € HT/mois » : plus petit prix visible et payant de la grille (pages SEO). */
    function ak_offer_from_price(?PDO $pdo = null): string {
        $st = ak_pricing_state($pdo);
        return 'dès ' . ak_price_split((int)$st['from_cents'], $st['vat_rate'])['per_month'];
    }
}

if (!function_exists('ak_grid_is_reference')) {
    /**
     * Vrai si les prix et les volumes lus sont exactement ceux de la grille de référence : les
     * phrases calculées à la main (« environ 1 € HT par jour », « 6 fois plus de membres »…) ne
     * s'affichent qu'à cette condition, pour ne jamais devenir fausses après un réglage dans Pilotage.
     */
    function ak_grid_is_reference(?PDO $pdo = null): bool {
        $st = ak_pricing_state($pdo);
        foreach (ak_grid_reference() as $slug => $r) {
            foreach ($r as $k => $v) {
                if (($st['plans'][$slug][$k] ?? null) !== $v) return false;
            }
        }
        return true;
    }
}

if (!function_exists('ak_software_jsonld')) {
    /**
     * L'entité schema.org SoftwareApplication d'Assokit, la même partout (@id …/#software).
     * Décision documentée dans _landing-seo.php : ni 'offers', ni note ('aggregateRating',
     * 'review'). Le résultat enrichi « application logicielle » exige les deux ; sans avis noté
     * vérifiable, la page n'y est pas éligible, et un 'offers' seul fait réclamer une note par
     * Google. Les prix restent en contenu visible (/tarifs, ak_landing_pricing).
     */
    function ak_software_jsonld(string $description = '', array $features = [], string $category = 'BusinessApplication'): array {
        $domain = defined('AK_PUBLIC_DOMAIN') ? AK_PUBLIC_DOMAIN : 'https://assokit.fr';
        $j = [
            '@context' => 'https://schema.org',
            '@type'    => 'SoftwareApplication',
            '@id'      => $domain . '/#software',
            'name'     => 'Assokit',
            'url'      => $domain . '/',
            'applicationCategory' => $category,
            'operatingSystem' => 'Web, iOS, Android',
            'description' => $description !== '' ? $description
                : 'Logiciel de gestion pour associations loi 1901 et TPE : adhérents, cotisations, factures et devis, comptabilité, assemblées générales, subventions, communication et IA.',
            'inLanguage' => 'fr-FR',
            'publisher' => ['@id' => $domain . '/#organization'],
        ];
        if ($features) $j['featureList'] = array_values($features);
        return $j;
    }
}

if (!function_exists('ak_offer_jsonld')) {
    /**
     * Offre schema.org de la formule Pro (prix mensuel HT, TVA non comprise). N'est appelée par
     * aucune page : la décision de _landing-seo.php exclut 'offers' du balisage tant qu'aucun avis
     * noté vérifiable n'existe. Gardée pour le jour où cette décision changerait.
     */
    function ak_offer_jsonld(?PDO $pdo = null): array {
        $o = ak_offer_price($pdo);
        $price = number_format($o['ht_cents'] / 100, 2, '.', '');
        return [
            '@type' => 'Offer', 'name' => 'Assokit Pro', 'price' => $price, 'priceCurrency' => 'EUR',
            'priceSpecification' => [
                '@type' => 'UnitPriceSpecification', 'price' => $price, 'priceCurrency' => 'EUR',
                'valueAddedTaxIncluded' => false, 'unitText' => 'mois',
            ],
            'url' => 'https://assokit.fr/tarifs',
        ];
    }
}
