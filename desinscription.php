<?php
/**
 * desinscription.php — Désinscription d'un prospect. Route : /desinscription/{id.token}
 * Marque le prospect 'unsubscribed' (plus jamais recontacté) — obligation RGPD. Public.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/api/_app-prospect.php';

$seg = (string) ($_GET['t'] ?? '');
$p = ak_prospect_parse($pdo, $seg);
$ok = false;
if ($p) {
    try {
        $pdo->prepare("UPDATE asso_prospects SET status = 'unsubscribed', next_send_at = NULL WHERE id = ?")->execute([(int) $p['id']]);
        ak_prospect_event($pdo, (int) $p['id'], 'unsubscribe');
        $ok = true;
    } catch (Throwable $e) {}
}
header('Content-Type: text/html; charset=utf-8');
?><!DOCTYPE html>
<html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex"><title>Désinscription · Assokit</title>
<style>body{font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;background:#F0FDF4;margin:0;display:flex;min-height:100vh;align-items:center;justify-content:center;padding:20px;color:#0F172A}
.card{background:#fff;border:1px solid #E2E8F0;border-radius:18px;max-width:440px;padding:36px 30px;text-align:center;box-shadow:0 12px 40px rgba(5,150,105,.10)}
h1{font-size:22px;margin:14px 0 8px}p{color:#475569;line-height:1.6;font-size:15px;margin:0 0 8px}a{color:#059669;font-weight:600;text-decoration:none}</style>
</head><body>
<div class="card">
<div style="font-size:44px;"><?= $ok ? '✅' : '↩️' ?></div>
<?php if ($ok): ?>
  <h1>C'est fait, vous êtes désinscrit·e</h1>
  <p>Vous ne recevrez plus aucun email de notre part. Aucune action supplémentaire n'est nécessaire.</p>
  <p>Si c'était une erreur, écrivez-nous à <a href="mailto:contact@assokit.fr">contact@assokit.fr</a>.</p>
<?php else: ?>
  <h1>Lien invalide ou expiré</h1>
  <p>Nous n'avons pas pu traiter cette demande. Écrivez-nous à <a href="mailto:contact@assokit.fr">contact@assokit.fr</a> et nous vous retirons manuellement.</p>
<?php endif; ?>
<p style="margin-top:18px;"><a href="https://assokit.fr">← assokit.fr</a></p>
</div>
</body></html>
