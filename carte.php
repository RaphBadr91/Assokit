<?php
/**
 * carte.php — Carte de visite numérique derrière un QR code.
 *
 * /carte/1       page de présentation, avec un bouton d'ajout au répertoire
 * /carte/1.vcf   le fichier vCard lui-même
 *
 * Le QR code encode l'URL, pas les coordonnées. Conséquence directe : les
 * informations se corrigent depuis /fondateur-cartes sans réimprimer un seul
 * QR code. Encoder la vCard dans le QR aurait figé les coordonnées dans
 * l'encre, et produit un QR bien plus dense donc plus dur à scanner.
 *
 * Les fiches vivent en base (table qr_cards, éditée par le fondateur). Si la
 * base est injoignable ou la migration pas encore passée, la page retombe sur
 * cartes-contacts.php. Ce filet n'est pas de la coquetterie : un QR imprimé ne
 * se rappelle pas, la page doit répondre même pendant une maintenance.
 *
 * Ni session, ni includes-layout : rien qui puisse tomber avec le reste.
 */

declare(strict_types=1);

$slug = isset($_GET['c']) ? (string) $_GET['c'] : '';
$wantVcf = !empty($_GET['vcf']);

$c = null;
$dbFaitFoi = false;   // la table a répondu : son verdict est définitif

// 1. La base fait foi.
try {
    require_once __DIR__ . '/config.php';
    if (isset($pdo) && $pdo instanceof PDO) {
        $st = $pdo->prepare("SELECT * FROM qr_cards WHERE slug = ? AND is_active = 1 LIMIT 1");
        $st->execute([$slug]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        // La requête a abouti : la table existe. Qu'elle renvoie une fiche ou
        // rien, c'est elle qui tranche — sinon « mettre hors ligne » depuis
        // /fondateur-cartes serait sans effet, le filet reprenant la main.
        $dbFaitFoi = true;
        if ($row) {
            $c = [
                'prenom' => $row['prenom'], 'nom' => $row['nom'],
                'fonction' => $row['fonction'], 'societe' => $row['societe'],
                'tel' => $row['tel'], 'tel_fixe' => $row['tel_fixe'],
                'email' => $row['email'], 'site' => $row['site'],
                'linkedin' => $row['linkedin'], 'note' => $row['note'],
                'adresse' => [
                    'rue' => $row['adr_rue'], 'cp' => $row['adr_cp'],
                    'ville' => $row['adr_ville'], 'pays' => $row['adr_pays'],
                ],
            ];
            // Compteur de scans. Best-effort : une fiche doit s'afficher même
            // si l'écriture échoue (base en lecture seule, verrou…).
            if (!$wantVcf) {
                try {
                    $pdo->prepare("UPDATE qr_cards SET scan_count = scan_count + 1, last_scan_at = NOW() WHERE slug = ?")
                        ->execute([$slug]);
                } catch (Throwable $e) { /* sans importance */ }
            }
        }
    }
} catch (Throwable $e) { /* on passe au filet */ }

// 2. Filet : le fichier livré avec le code. Il ne sert QUE si la base n'a pas
// pu répondre — base injoignable ou migration pas encore passée.
if ($c === null && !$dbFaitFoi && is_file(__DIR__ . '/cartes-contacts.php')) {
    $contacts = require __DIR__ . '/cartes-contacts.php';
    if (is_array($contacts) && isset($contacts[$slug])) $c = $contacts[$slug];
}

if ($c === null) {
    http_response_code(404);
    header('Content-Type: text/html; charset=utf-8');
    exit('<!doctype html><meta charset="utf-8"><title>Carte introuvable</title>'
        . '<p style="font:15px/1.6 system-ui;padding:40px">Cette carte n\'existe pas. '
        . '<a href="https://assokit.fr" style="color:#059669">assokit.fr</a></p>');
}

$prenom  = trim((string) ($c['prenom'] ?? ''));
$nom     = trim((string) ($c['nom'] ?? ''));
$fn      = trim($prenom . ' ' . $nom);
$adr     = $c['adresse'] ?? [];
$rue     = trim((string) ($adr['rue'] ?? ''));
$cp      = trim((string) ($adr['cp'] ?? ''));
$ville   = trim((string) ($adr['ville'] ?? ''));
$pays    = trim((string) ($adr['pays'] ?? ''));
// Le pays seul ne fait pas une adresse : sans rue ni ville, la ligne entière
// disparaît plutôt que d'afficher « France » comme domicile.
$adrLine = ($rue !== '' || $ville !== '')
    ? trim(trim($rue . ', ' . trim($cp . ' ' . $ville), ', ') . ($pays !== '' ? ', ' . $pays : ''), ', ')
    : '';

// ── Sortie vCard ────────────────────────────────────────────────────────────
if ($wantVcf) {
    /** Échappe une valeur vCard : l'ordre compte, l'antislash d'abord. */
    $esc = static function (string $v): string {
        return str_replace(["\\", ";", ",", "\r\n", "\n", "\r"], ["\\\\", "\\;", "\\,", "\\n", "\\n", "\\n"], $v);
    };

    $lines = ['BEGIN:VCARD', 'VERSION:3.0'];
    $lines[] = 'N:' . $esc($nom) . ';' . $esc($prenom) . ';;;';
    $lines[] = 'FN:' . $esc($fn);
    if (!empty($c['societe']))  $lines[] = 'ORG:' . $esc((string) $c['societe']);
    if (!empty($c['fonction'])) $lines[] = 'TITLE:' . $esc((string) $c['fonction']);
    if (!empty($c['tel']))      $lines[] = 'TEL;TYPE=CELL,VOICE:' . $esc((string) $c['tel']);
    if (!empty($c['tel_fixe'])) $lines[] = 'TEL;TYPE=WORK,VOICE:' . $esc((string) $c['tel_fixe']);
    if (!empty($c['email']))    $lines[] = 'EMAIL;TYPE=INTERNET,PREF:' . $esc((string) $c['email']);
    if ($rue !== '' || $ville !== '') {
        // ADR : boîte;complément;rue;ville;région;code postal;pays
        $lines[] = 'ADR;TYPE=WORK:;;' . $esc($rue) . ';' . $esc($ville) . ';;' . $esc($cp) . ';' . $esc($pays);
    }
    if (!empty($c['site']))     $lines[] = 'URL:' . $esc((string) $c['site']);
    if (!empty($c['linkedin'])) $lines[] = 'X-SOCIALPROFILE;TYPE=linkedin:' . $esc((string) $c['linkedin']);
    if (!empty($c['note']))     $lines[] = 'NOTE:' . $esc((string) $c['note']);
    $lines[] = 'REV:' . gmdate('Y-m-d\TH:i:s\Z');
    $lines[] = 'END:VCARD';

    // CRLF obligatoire : la RFC 6350 l'impose et certains Android refusent
    // silencieusement un fichier en LF seul.
    $vcf = implode("\r\n", $lines) . "\r\n";

    // Nom du fichier : on translittère avant de filtrer, sinon « Raphaël »
    // ressortirait en « Rapha-l ».
    $safe = $fn !== '' ? $fn : 'contact';
    if (function_exists('iconv')) {
        $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $safe);
        if ($t !== false && $t !== '') $safe = $t;
    }
    $safe = preg_replace('/[^A-Za-z0-9._-]+/', '-', $safe);
    $safe = trim((string) $safe, '-') ?: 'contact';

    header('Content-Type: text/vcard; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $safe . '.vcf"');
    header('Content-Length: ' . strlen($vcf));
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex, nofollow');
    echo $vcf;
    exit;
}

// ── Page de présentation ────────────────────────────────────────────────────
$h = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$initiales = mb_strtoupper(mb_substr($prenom, 0, 1) . mb_substr($nom, 0, 1));
$vcfUrl = '/carte/' . rawurlencode($slug) . '.vcf';
$mapsUrl = $adrLine !== '' ? 'https://maps.google.com/?q=' . rawurlencode($adrLine) : '';

header('X-Robots-Tag: noindex, nofollow');
?><!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= $h($fn) ?> — Assokit</title>
<meta name="robots" content="noindex, nofollow">
<meta name="theme-color" content="#0B3B2A">
<link rel="icon" type="image/png" href="/assets/favicon.png">
<link rel="preconnect" href="https://fonts.bunny.net" crossorigin>
<link rel="stylesheet" href="https://fonts.bunny.net/css?family=geist:400,500,600,700&display=swap">
<style>
  :root{--acc:#059669;--ink:#0B1A13;--ink-2:#45544D;--ink-3:#5F6D66;--line:#E7EEEA;--canvas:#F4F8F6}
  *{box-sizing:border-box;margin:0;padding:0}
  body{font-family:Geist,-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;
       background:var(--canvas);color:var(--ink);letter-spacing:-.01em;
       min-height:100dvh;display:flex;align-items:center;justify-content:center;padding:20px}
  .card{width:100%;max-width:420px;background:#fff;border:1px solid var(--line);
        border-radius:24px;overflow:hidden;box-shadow:0 18px 44px -18px rgba(10,59,41,.28)}
  .head{position:relative;height:132px;
        background:linear-gradient(115deg,#0B3B2A 0%,#0E7A5A 42%,#12B886 100%)}
  .head::after{content:"";position:absolute;right:-14%;bottom:-150%;width:58%;aspect-ratio:1;
        border-radius:50%;border:3px solid rgba(255,255,255,.14)}
  .head img{position:absolute;left:22px;top:20px;width:104px;height:auto}
  .av{position:absolute;left:24px;bottom:-38px;width:84px;height:84px;border-radius:24px;
      background:var(--acc);color:#fff;border:4px solid #fff;display:flex;align-items:center;
      justify-content:center;font-size:29px;font-weight:600;letter-spacing:.01em}
  .body{padding:52px 24px 24px}
  h1{font-size:25px;font-weight:600;line-height:1.2}
  .role{color:var(--ink-3);font-size:15px;margin-top:5px}
  .cta{display:flex;align-items:center;justify-content:center;gap:9px;width:100%;
       margin-top:22px;padding:16px;border-radius:16px;background:var(--acc);color:#fff;
       font-size:16px;font-weight:600;text-decoration:none;
       box-shadow:0 8px 18px -6px rgba(5,150,105,.55)}
  .cta:active{transform:scale(.985)}
  .hint{text-align:center;color:var(--ink-3);font-size:12.5px;margin-top:10px;line-height:1.5}
  ul{list-style:none;margin-top:22px;border-top:1px solid var(--line)}
  li a,li div{display:flex;align-items:center;gap:13px;padding:14px 2px;
      border-bottom:1px solid var(--line);color:var(--ink);text-decoration:none}
  li span{display:block}
  .lab{font-size:11.5px;color:var(--ink-3);letter-spacing:.03em;text-transform:uppercase}
  .val{font-size:15px;font-weight:500;margin-top:2px;overflow-wrap:anywhere}
  .ico{flex:none;width:38px;height:38px;border-radius:11px;background:#ECF7F2;color:var(--acc);
       display:flex;align-items:center;justify-content:center}
  .foot{text-align:center;padding:18px 0 4px}
  .foot a{color:var(--ink-3);font-size:12.5px;text-decoration:none}
</style>
</head>
<body>
<main class="card">
  <div class="head">
    <img src="/assets/brand/svg/assokit-logo-blanc.svg" alt="Assokit" width="104">
    <div class="av"><?= $h($initiales) ?></div>
  </div>

  <div class="body">
    <h1><?= $h($fn) ?></h1>
    <?php if (!empty($c['fonction']) || !empty($c['societe'])): ?>
      <p class="role"><?= $h(trim(($c['fonction'] ?? '') . (!empty($c['fonction']) && !empty($c['societe']) ? ' · ' : '') . ($c['societe'] ?? ''))) ?></p>
    <?php endif; ?>

    <a class="cta" href="<?= $h($vcfUrl) ?>">
      <svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="19" y1="8" x2="19" y2="14"/><line x1="22" y1="11" x2="16" y2="11"/></svg>
      Ajouter à mes contacts
    </a>
    <p class="hint">Le fichier s'ouvre dans l'application Contacts de votre téléphone.</p>

    <ul>
      <?php if (!empty($c['tel'])): ?>
      <li><a href="tel:<?= $h(preg_replace('/[^0-9+]/', '', (string) $c['tel'])) ?>">
        <span class="ico"><svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.13.96.36 1.9.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.9.34 1.85.57 2.81.7A2 2 0 0 1 22 16.92z"/></svg></span>
        <span><span class="lab">Téléphone</span><span class="val"><?= $h($c['tel']) ?></span></span></a></li>
      <?php endif; ?>

      <?php if (!empty($c['email'])): ?>
      <li><a href="mailto:<?= $h($c['email']) ?>">
        <span class="ico"><svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-10 6L2 7"/></svg></span>
        <span><span class="lab">E-mail</span><span class="val"><?= $h($c['email']) ?></span></span></a></li>
      <?php endif; ?>

      <?php if ($adrLine !== ''): ?>
      <li><a href="<?= $h($mapsUrl) ?>" target="_blank" rel="noopener">
        <span class="ico"><svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg></span>
        <span><span class="lab">Adresse</span><span class="val"><?= $h($adrLine) ?></span></span></a></li>
      <?php endif; ?>

      <?php if (!empty($c['linkedin'])): ?>
      <li><a href="<?= $h($c['linkedin']) ?>" target="_blank" rel="noopener">
        <span class="ico"><svg width="17" height="17" viewBox="0 0 24 24" fill="currentColor"><path d="M4.98 3.5a2.5 2.5 0 1 1 0 5 2.5 2.5 0 0 1 0-5zM3 9h4v12H3zM9 9h3.8v1.7h.05c.53-.95 1.83-1.95 3.77-1.95 4.03 0 4.78 2.5 4.78 5.75V21h-4v-5.6c0-1.34-.03-3.06-1.9-3.06-1.9 0-2.2 1.45-2.2 2.96V21H9z"/></svg></span>
        <span><span class="lab">LinkedIn</span><span class="val">Voir le profil</span></span></a></li>
      <?php endif; ?>
    </ul>

    <p class="foot"><a href="https://assokit.fr" target="_blank" rel="noopener">assokit.fr</a></p>
  </div>
</main>
</body>
</html>
