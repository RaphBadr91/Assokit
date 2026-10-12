<?php
/**
 * desinscription.php — Désinscription. Public, sans connexion.
 *   - Prospect d'Assokit : /desinscription/{id.token} → statut 'unsubscribed' (plus jamais recontacté).
 *   - Envois groupés d'une association (diffusions, invitations, diffusions IA) :
 *     /desinscription?l={org.email.signature} → l'adresse n'est plus destinataire des envois
 *     groupés de cette association (voir ak_optout_* dans resend-helper.php).
 *     GET affiche une confirmation (les antivirus de messagerie ouvrent les liens tout seuls) ;
 *     POST désinscrit, y compris le POST « List-Unsubscribe=One-Click » des messageries.
 * Obligation RGPD / CNIL.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/api/_app-prospect.php';
require_once __DIR__ . '/resend-helper.php';

$esc = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$mode = 'prospect';
$ok = false;
$org_name = '';
$email_masked = '';
$is_out = false;
$link = (string) ($_GET['l'] ?? '');

if ($link !== '') {
    // ---------- Envois groupés d'une association ----------
    $mode = 'asso';
    $o = ak_optout_parse($link);
    if ($o) {
        $ok = true;
        try {
            $st = $pdo->prepare("SELECT name FROM organizations WHERE id = ?");
            $st->execute([$o['org_id']]);
            $org_name = (string) ($st->fetchColumn() ?: '');
        } catch (Throwable $e) {}
        if ($org_name === '') $org_name = "l'association";
        [$local, $domain] = array_pad(explode('@', $o['email'], 2), 2, '');
        $email_masked = mb_substr($local, 0, 2) . str_repeat('•', max(1, min(6, mb_strlen($local) - 2))) . '@' . $domain;

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            $resub = (($_POST['action'] ?? '') === 'resubscribe');
            $done = ak_optout_set($pdo, $o['org_id'], $o['email'], !$resub, isset($_POST['List-Unsubscribe']) ? 'one_click' : 'link');
            if (!$done) $ok = false;
            // Désinscription en un clic depuis la messagerie : une réponse 200 suffit
            if (isset($_POST['List-Unsubscribe'])) {
                header('Content-Type: text/plain; charset=utf-8');
                echo $done ? 'OK' : 'ERREUR';
                exit;
            }
        }
        $is_out = isset(ak_optout_emails($pdo, $o['org_id'])[$o['email']]);
    }
} else {
    // ---------- Prospect d'Assokit ----------
    $seg = (string) ($_GET['t'] ?? '');
    $p = ak_prospect_parse($pdo, $seg);
    if ($p) {
        try {
            $pdo->prepare("UPDATE asso_prospects SET status = 'unsubscribed', next_send_at = NULL WHERE id = ?")->execute([(int) $p['id']]);
            ak_prospect_event($pdo, (int) $p['id'], 'unsubscribe');
            $ok = true;
        } catch (Throwable $e) {}
    }
}
header('Content-Type: text/html; charset=utf-8');
header('X-Robots-Tag: noindex');
?><!DOCTYPE html>
<html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex"><title>Désinscription · Assokit</title>
<style>body{font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;background:#F0FDF4;margin:0;display:flex;min-height:100vh;align-items:center;justify-content:center;padding:20px;color:#0F172A}
.card{background:#fff;border:1px solid #E2E8F0;border-radius:18px;max-width:440px;padding:36px 30px;text-align:center;box-shadow:0 12px 40px rgba(5,150,105,.10)}
h1{font-size:22px;margin:14px 0 8px}p{color:#475569;line-height:1.6;font-size:15px;margin:0 0 8px}a{color:#059669;font-weight:600;text-decoration:none}
button{font:inherit;font-weight:600;border-radius:10px;padding:11px 20px;cursor:pointer;margin-top:10px}
.btn-main{background:#059669;color:#fff;border:0}.btn-ghost{background:#fff;color:#475569;border:1px solid #CBD5E1}</style>
</head><body>
<div class="card">
<?php if ($mode === 'asso' && $ok): ?>
  <?php if ($is_out): ?>
    <div style="font-size:44px;">✅</div>
    <h1>C'est fait, vous êtes désinscrit·e</h1>
    <p>L'adresse <strong><?= $esc($email_masked) ?></strong> ne recevra plus les envois groupés (newsletters, invitations, annonces) de <strong><?= $esc($org_name) ?></strong>.</p>
    <p style="font-size:13.5px;">Les messages qui vous concernent personnellement (factures, reçus, compte) continuent de vous parvenir.</p>
    <form method="POST" action="/desinscription?l=<?= $esc(rawurlencode($link)) ?>">
      <input type="hidden" name="action" value="resubscribe">
      <button type="submit" class="btn-ghost">C'était une erreur : me réabonner</button>
    </form>
  <?php else: ?>
    <div style="font-size:44px;">✉️</div>
    <h1>Ne plus recevoir ces e-mails ?</h1>
    <p>L'adresse <strong><?= $esc($email_masked) ?></strong> ne recevra plus les envois groupés (newsletters, invitations, annonces) de <strong><?= $esc($org_name) ?></strong>.</p>
    <form method="POST" action="/desinscription?l=<?= $esc(rawurlencode($link)) ?>">
      <input type="hidden" name="action" value="unsubscribe">
      <button type="submit" class="btn-main">Me désinscrire</button>
    </form>
  <?php endif; ?>
<?php elseif ($ok): ?>
  <div style="font-size:44px;">✅</div>
  <h1>C'est fait, vous êtes désinscrit·e</h1>
  <p>Vous ne recevrez plus aucun email de notre part. Aucune action supplémentaire n'est nécessaire.</p>
  <p>Si c'était une erreur, écrivez-nous à <a href="mailto:contact@assokit.fr">contact@assokit.fr</a>.</p>
<?php else: ?>
  <div style="font-size:44px;">↩️</div>
  <h1>Lien invalide ou expiré</h1>
  <p>Nous n'avons pas pu traiter cette demande. Écrivez-nous à <a href="mailto:contact@assokit.fr">contact@assokit.fr</a> et nous vous retirons manuellement.</p>
<?php endif; ?>
<p style="margin-top:18px;"><a href="https://assokit.fr">← assokit.fr</a></p>
</div>
</body></html>
