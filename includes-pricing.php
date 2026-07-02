<?php
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
