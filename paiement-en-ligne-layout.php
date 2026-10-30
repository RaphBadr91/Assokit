<?php
/**
 * paiement-en-ligne-layout.php
 * --------------------------------------------------------------
 * Mise en page des pages publiques de paiement en ligne des associations (cotiser.php, paiement-confirme.php) :
 * page autonome (pas render_public_head, qui renvoie l'application vers /connexion), aux couleurs d'Assokit,
 * au nom de l'association qui encaisse. Aucun script externe : le paiement se fait sur la page de Stripe.
 * --------------------------------------------------------------
 */

if (!function_exists('ak_pay_head')) {
    function ak_pay_head(string $title, string $org_name): void {
        $h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        if (!headers_sent()) {
            header('Content-Type: text/html; charset=utf-8');
            header('Cache-Control: no-store');
            header('X-Robots-Tag: noindex, nofollow');
            header('Referrer-Policy: same-origin');
        }
        ?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= $h($title) ?> — <?= $h($org_name) ?></title>
<style>
* { box-sizing: border-box; }
body { margin: 0; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif; background: #F9FAFB; color: #1F2937; line-height: 1.55; }
.pp-wrap { max-width: 620px; margin: 0 auto; padding: 24px 16px 40px; }
.pp-card { background: #fff; border: 1px solid #E5E7EB; border-radius: 14px; box-shadow: 0 4px 20px rgba(0,0,0,.04); overflow: hidden; margin-bottom: 16px; }
.pp-head { background: linear-gradient(135deg, #059669 0%, #10B981 100%); color: #fff; padding: 24px 24px 20px; }
.pp-org { font-size: 13px; opacity: .9; letter-spacing: .02em; }
.pp-head h1 { margin: 4px 0 0; font-size: 22px; line-height: 1.3; overflow-wrap: anywhere; }
.pp-body { padding: 22px 24px; }
.pp-body p { margin: 0 0 12px; font-size: 14.5px; }
.pp-muted { color: #6B7280; font-size: 13px !important; }
.pp-amount { font-size: 30px; font-weight: 800; color: #059669; font-variant-numeric: tabular-nums; margin: 4px 0 2px; }
.pp-tiers { display: grid; gap: 8px; margin: 6px 0 14px; }
.pp-tier { display: flex; align-items: center; gap: 10px; border: 1px solid #E5E7EB; border-radius: 10px; padding: 12px 14px; cursor: pointer; }
.pp-tier:has(input:checked) { border-color: #059669; background: #F0FDF4; }
.pp-tier .pp-t-name { font-weight: 600; flex: 1; min-width: 0; overflow-wrap: anywhere; }
.pp-tier .pp-t-amt { font-weight: 700; white-space: nowrap; }
.pp-tier .pp-t-desc { display: block; font-size: 12.5px; color: #6B7280; font-weight: 400; }
.pp-fld { margin-bottom: 12px; }
.pp-fld label { display: block; font-size: 12.5px; font-weight: 600; color: #374151; margin-bottom: 4px; }
.pp-fld input { width: 100%; padding: 11px 12px; border: 1px solid #D1D5DB; border-radius: 9px; font-size: 16px; font-family: inherit; }
.pp-quick { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 8px; }
.pp-quick button { border: 1px solid #A7F3D0; background: #ECFDF5; color: #065F46; border-radius: 999px; padding: 7px 14px; font-weight: 600; cursor: pointer; font-size: 14px; font-family: inherit; }
.pp-btn { display: inline-flex; justify-content: center; align-items: center; gap: 8px; width: 100%; background: #059669; color: #fff; border: 0; border-radius: 10px; padding: 14px 18px; font-size: 16px; font-weight: 700; cursor: pointer; text-decoration: none; font-family: inherit; }
.pp-btn:hover { background: #047857; } .pp-btn:disabled { opacity: .6; cursor: wait; }
.pp-btn-ghost { display: inline-block; color: #065F46; font-weight: 600; text-decoration: none; font-size: 14px; }
.pp-alert { border-radius: 10px; padding: 12px 14px; font-size: 14px; margin-bottom: 14px; }
.pp-alert-error { background: #FEE2E2; color: #991B1B; } .pp-alert-ok { background: #D1FAE5; color: #065F46; } .pp-alert-wait { background: #FEF3C7; color: #92400E; } .pp-alert-info { background: #EFF6FF; color: #1E40AF; }
.pp-rows { width: 100%; border-collapse: collapse; font-size: 14px; margin: 6px 0 14px; }
.pp-rows td { padding: 6px 0; border-bottom: 1px solid #F3F4F6; vertical-align: top; }
.pp-rows td:first-child { color: #6B7280; width: 42%; padding-right: 10px; }
.pp-rows td:last-child { font-weight: 600; overflow-wrap: anywhere; }
.pp-foot { text-align: center; color: #6B7280; font-size: 12px; line-height: 1.6; }
.pp-foot a { color: #059669; }
.pp-lock { display: flex; gap: 8px; align-items: center; justify-content: center; color: #6B7280; font-size: 12.5px; margin-top: 10px; }
</style>
</head>
<body>
<div class="pp-wrap">
<?php
    }
}

if (!function_exists('ak_pay_foot')) {
    function ak_pay_foot(string $org_name): void {
        $h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        ?>
<div class="pp-foot">
  Paiement encaissé par <strong><?= $h($org_name) ?></strong> via Stripe. Page fournie par <a href="https://assokit.fr">Assokit</a>, l’outil de gestion de l’association, qui n’encaisse aucun fonds.<br>
  <a href="/confidentialite">Confidentialité</a> · <a href="/cgu">Conditions générales</a>
</div>
</div>
</body>
</html>
<?php
    }
}
