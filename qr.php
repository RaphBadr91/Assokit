<?php
/**
 * qr.php — Page publique derrière le QR d'une association.
 *
 * /qr/<token>       la page : fiche contact de l'asso + formulaire de contact
 * /qr/<token>.vcf   la vCard de l'association
 *
 * Deux usages en une page, parce que c'est le même geste sur un stand :
 * la personne enregistre l'association dans son téléphone, et laisse ses
 * coordonnées. Les contacts collectés atterrissent dans asso_prospects,
 * la table de l'onglet Prospection : ils sont donc rappelables sans
 * recopie.
 *
 * Page publique, sans connexion. Défenses :
 *   - jeton de 32 hexadécimaux, non devinable depuis l'id de l'organisation
 *   - limitation de débit par IP
 *   - champ piège invisible + délai minimal de remplissage
 *   - consentement obligatoire et horodaté (RGPD)
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';
@require_once __DIR__ . '/rate-limit-helper.php';
require_once __DIR__ . '/asso-vcard-helper.php';

$token   = isset($_GET['t']) ? (string) $_GET['t'] : '';
$wantVcf = !empty($_GET['vcf']);

function qr_404(): void {
    http_response_code(404);
    header('Content-Type: text/html; charset=utf-8');
    exit('<!doctype html><meta charset="utf-8"><title>Page introuvable</title>'
        . '<p style="font:15px/1.6 system-ui;padding:40px">Ce code QR n\'est plus actif. '
        . '<a href="https://assokit.fr" style="color:#059669">assokit.fr</a></p>');
}

if (!preg_match('/^[a-f0-9]{32}$/', $token)) qr_404();

try {
    $st = $pdo->prepare("SELECT q.*, o.name AS org_name, o.logo_path,
                                o.billing_email, o.billing_phone,
                                o.billing_address_street, o.billing_address_zip,
                                o.billing_address_city, o.billing_address_country,
                                o.branding_primary_color
                         FROM asso_qr_codes q
                         JOIN organizations o ON o.id = q.org_id
                         WHERE q.token = ? AND q.is_active = 1 AND q.deleted_at IS NULL
                         LIMIT 1");
    $st->execute([$token]);
    $qr = $st->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $qr = null;
}
if (!$qr) qr_404();

$org_id  = (int) $qr['org_id'];
$orgName = trim((string) $qr['org_name']) !== '' ? (string) $qr['org_name'] : 'Assokit';
$accent  = preg_match('/^#[0-9a-f]{6}$/i', (string) $qr['branding_primary_color'])
           ? (string) $qr['branding_primary_color'] : '#059669';

$rue   = trim((string) $qr['billing_address_street']);
$cp    = trim((string) $qr['billing_address_zip']);
$ville = trim((string) $qr['billing_address_city']);
$pays  = trim((string) $qr['billing_address_country']);
$adr   = ($rue !== '' || $ville !== '')
    ? trim(trim($rue . ', ' . trim($cp . ' ' . $ville), ', ') . ($pays !== '' ? ', ' . $pays : ''), ', ')
    : '';

// ── vCard de l'association ──────────────────────────────────────────────────
// Le contenu vient du helper partagé : le QR « carte de visite » embarque
// exactement la même fiche, elles ne doivent pas pouvoir diverger.
if ($wantVcf) {
    if (empty($qr['show_vcard'])) qr_404();
    // La requête aliase o.name en org_name pour ne pas écraser q.label :
    // on rétablit la clé attendue par le helper, sinon la fiche s'appellerait
    // « Association ».
    $vcf = ak_org_vcard(array_merge($qr, ['name' => $orgName]), 'https://assokit.fr');
    header('Content-Type: text/vcard; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . ak_vcard_filename($orgName) . '.vcf"');
    header('X-Robots-Tag: noindex, nofollow');
    echo $vcf;
    exit;
}

// ── Enregistrement d'un contact ─────────────────────────────────────────────
$done = false; $err = '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    // Le champ « website » est invisible : seuls les robots le remplissent.
    // On répond « merci » sans rien écrire, pour ne pas leur apprendre l'échec.
    if (trim((string) ($_POST['website'] ?? '')) !== '') {
        $done = true;
    } else {
        if (function_exists('ak_rate_limit_or_die')) {
            ak_rate_limit_or_die('qr_lead', 10, 600, $_SERVER['REMOTE_ADDR'] ?? '');
        }
        $prenom = trim((string) ($_POST['prenom'] ?? ''));
        $nom    = trim((string) ($_POST['nom'] ?? ''));
        $email  = trim((string) ($_POST['email'] ?? ''));
        $tel    = trim((string) ($_POST['telephone'] ?? ''));
        $msg    = trim((string) ($_POST['message'] ?? ''));
        $ok     = !empty($_POST['consent']);

        if ($prenom === '' && $nom === '')            $err = 'Indiquez au moins votre nom.';
        elseif ($email === '' && $tel === '')          $err = 'Laissez un e-mail ou un téléphone pour être recontacté.';
        elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $err = "Cette adresse e-mail n'est pas valide.";
        elseif (!$ok)                                  $err = 'Merci de cocher la case d\'accord.';
        else {
            try {
                $pdo->prepare("INSERT INTO asso_prospects
                        (org_id, prenom, nom, telephone, email, notes, source, qr_id, consent_at, updated_at)
                        VALUES (?, ?, ?, ?, ?, ?, 'qr', ?, NOW(), NOW())")
                    ->execute([$org_id, mb_substr($prenom, 0, 120), mb_substr($nom, 0, 120),
                               mb_substr($tel, 0, 40), mb_substr($email, 0, 190),
                               mb_substr($msg, 0, 500), (int) $qr['id']]);
                $pid = (int) $pdo->lastInsertId();

                $etiquette = trim((string) $qr['label']) !== '' ? (string) $qr['label'] : 'QR';
                $pdo->prepare("INSERT INTO asso_prospect_events (org_id, prospect_id, user_id, type, detail)
                               VALUES (?, ?, NULL, 'create', ?)")
                    ->execute([$org_id, $pid, mb_substr('Coordonnées laissées via ' . $etiquette, 0, 255)]);

                $pdo->prepare("UPDATE asso_qr_codes SET submit_count = submit_count + 1 WHERE id = ?")
                    ->execute([(int) $qr['id']]);
                $done = true;
            } catch (Throwable $e) {
                $err = "L'enregistrement a échoué. Réessayez dans un instant.";
            }
        }
    }
}

// Compteur de scans : sur l'affichage seulement, jamais sur l'envoi.
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    try {
        $pdo->prepare("UPDATE asso_qr_codes SET scan_count = scan_count + 1, last_scan_at = NOW() WHERE id = ?")
            ->execute([(int) $qr['id']]);
    } catch (Throwable $e) { /* sans importance */ }
}

$h = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
// Initiales des deux premiers mots : « Les Amis du Parc » donne « LA », pas
// « LE » — deux lettres prises au début du nom ne veulent rien dire.
$mots = preg_split('/[\s\-]+/u', $orgName, -1, PREG_SPLIT_NO_EMPTY) ?: [$orgName];
$initiales = mb_strtoupper(mb_substr($mots[0], 0, 1) . (isset($mots[1]) ? mb_substr($mots[1], 0, 1) : ''));
$logo = trim((string) $qr['logo_path']);

header('X-Robots-Tag: noindex, nofollow');
?><!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= $h($orgName) ?></title>
<meta name="robots" content="noindex, nofollow">
<meta name="theme-color" content="<?= $h($accent) ?>">
<link rel="icon" type="image/png" href="/assets/favicon.png">
<link rel="preconnect" href="https://fonts.bunny.net" crossorigin>
<link rel="stylesheet" href="https://fonts.bunny.net/css?family=geist:400,500,600,700&display=swap">
<style>
  :root{--acc:<?= $h($accent) ?>;--ink:#0B1A13;--ink-2:#45544D;--ink-3:#5F6D66;--line:#E7EEEA;--canvas:#F4F8F6}
  *,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
  body{font-family:Geist,-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;background:var(--canvas);
       color:var(--ink);letter-spacing:-.01em;min-height:100dvh;display:flex;align-items:flex-start;
       justify-content:center;padding:20px}
  .card{width:100%;max-width:440px;background:#fff;border:1px solid var(--line);border-radius:24px;
        overflow:hidden;box-shadow:0 18px 44px -18px rgba(10,59,41,.26);margin:auto}
  .head{position:relative;padding:26px 24px 22px;color:#fff;
        background:linear-gradient(115deg,color-mix(in srgb,var(--acc) 78%, #000) 0%,var(--acc) 58%,color-mix(in srgb,var(--acc) 62%, #fff) 100%)}
  .head::after{content:"";position:absolute;right:-12%;bottom:-140%;width:56%;aspect-ratio:1;
        border-radius:50%;border:3px solid rgba(255,255,255,.15)}
  .id{display:flex;align-items:center;gap:14px;position:relative;z-index:1}
  .av{width:56px;height:56px;border-radius:17px;background:rgba(255,255,255,.2);border:2px solid rgba(255,255,255,.55);
      display:flex;align-items:center;justify-content:center;font-weight:700;font-size:19px;flex-shrink:0;overflow:hidden}
  .av img{width:100%;height:100%;object-fit:cover}
  .id h1{font-size:22px;font-weight:600;line-height:1.2;overflow-wrap:anywhere}
  .id p{font-size:13px;opacity:.85;margin-top:3px}
  .body{padding:22px 24px 26px}
  .intro{font-size:14.5px;color:var(--ink-2);line-height:1.55;margin-bottom:18px}
  .vcard{display:flex;align-items:center;justify-content:center;gap:9px;width:100%;padding:15px;
         border-radius:15px;background:var(--canvas);border:1px solid var(--line);color:var(--ink);
         font-size:15px;font-weight:600;text-decoration:none;margin-bottom:22px}
  .coord{list-style:none;margin:0 0 4px}
  .coord li a{display:flex;align-items:center;gap:12px;padding:13px 2px;border-bottom:1px solid var(--line);
              color:var(--ink);text-decoration:none;font-size:14.5px;overflow-wrap:anywhere}
  .coord li:last-child a{border-bottom:none}
  .coord .ic{flex:none;width:34px;height:34px;border-radius:10px;background:var(--canvas);color:var(--acc);
             display:flex;align-items:center;justify-content:center}
  .sep{display:flex;align-items:center;gap:12px;color:var(--ink-3);font-size:12px;margin-bottom:18px}
  .sep::before,.sep::after{content:"";flex:1;height:1px;background:var(--line)}
  label{display:block;font-size:12.5px;font-weight:600;color:var(--ink-2);margin-bottom:5px}
  .row{display:grid;grid-template-columns:1fr 1fr;gap:10px}
  input,textarea{width:100%;padding:13px 14px;border:1px solid var(--line);border-radius:12px;
                 font:inherit;font-size:16px;background:#fff;margin-bottom:13px}
  input:focus,textarea:focus{outline:2px solid color-mix(in srgb,var(--acc) 25%, transparent);border-color:var(--acc)}
  .hp{position:absolute;left:-9999px;width:1px;height:1px;opacity:0}
  .consent{display:flex;gap:10px;align-items:flex-start;font-size:12.5px;color:var(--ink-3);
           line-height:1.5;margin:4px 0 18px}
  .consent input{width:19px;height:19px;flex:none;margin:1px 0 0}
  .send{width:100%;padding:16px;border:none;border-radius:15px;background:var(--acc);color:#fff;
        font:inherit;font-size:16px;font-weight:600;cursor:pointer}
  .err{background:#FEF2F2;border:1px solid #FECACA;color:#991B1B;border-radius:12px;padding:12px 14px;
       font-size:13.5px;margin-bottom:16px}
  .ok{text-align:center;padding:14px 0 6px}
  .ok .tick{width:64px;height:64px;border-radius:50%;background:var(--acc);color:#fff;display:flex;
            align-items:center;justify-content:center;margin:0 auto 18px;font-size:30px}
  .ok h2{font-size:21px;font-weight:600;margin-bottom:8px}
  .ok p{color:var(--ink-3);font-size:14.5px;line-height:1.55}
  .foot{text-align:center;padding:16px 0 2px;font-size:12px;color:var(--ink-3)}
  .foot a{color:var(--ink-3)}
  @media (max-width:380px){ .row{grid-template-columns:1fr} }
</style>
</head>
<body>
<main class="card">
  <div class="head">
    <div class="id">
      <div class="av"><?php if ($logo !== ''): ?><img src="<?= $h($logo) ?>" alt=""><?php else: ?><?= $h($initiales) ?><?php endif; ?></div>
      <div>
        <h1><?= $h($orgName) ?></h1>
        <?php if (trim((string) $qr['label']) !== ''): ?><p><?= $h($qr['label']) ?></p><?php endif; ?>
      </div>
    </div>
  </div>

  <div class="body">
    <?php if ($done): ?>
      <div class="ok">
        <div class="tick">✓</div>
        <h2>Merci !</h2>
        <p><?= $h($orgName) ?> a bien reçu vos coordonnées et vous recontactera.</p>
      </div>
    <?php else: ?>

      <?php $mode = (string) ($qr['mode'] ?? 'both');
            $voirContact = $mode !== 'collecte' && !empty($qr['show_vcard']);
            $voirForm    = $mode !== 'contact'; ?>
      <?php if (trim((string) $qr['intro']) !== ''): ?>
        <p class="intro"><?= $h($qr['intro']) ?></p>
      <?php endif; ?>

      <?php if ($voirContact): ?>
        <a class="vcard" href="/qr/<?= $h($token) ?>.vcf">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="19" y1="8" x2="19" y2="14"/><line x1="22" y1="11" x2="16" y2="11"/></svg>
          Ajouter <?= $h($orgName) ?> à mes contacts
        </a>
        <?php if ($mode === 'contact'): ?>
          <ul class="coord">
            <?php if (!empty($qr['billing_phone'])):
                    $tc = preg_replace('/[^0-9+]/', '', (string) $qr['billing_phone']); ?>
              <li><a href="tel:<?= $h($tc) ?>"><span class="ic">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6A19.79 19.79 0 0 1 2.12 4.18 2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.13.96.36 1.9.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.9.34 1.85.57 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
              </span><?= $h($qr['billing_phone']) ?></a></li>
            <?php endif; ?>
            <?php if (!empty($qr['billing_email'])): ?>
              <li><a href="mailto:<?= $h($qr['billing_email']) ?>"><span class="ic">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-10 6L2 7"/></svg>
              </span><?= $h($qr['billing_email']) ?></a></li>
            <?php endif; ?>
            <?php if ($adr !== ''): ?>
              <li><a href="https://maps.google.com/?q=<?= $h(rawurlencode($adr)) ?>" target="_blank" rel="noopener"><span class="ic">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
              </span><?= $h($adr) ?></a></li>
            <?php endif; ?>
          </ul>
        <?php endif; ?>
        <?php if ($voirForm): ?><div class="sep">ou laissez-nous vos coordonnées</div><?php endif; ?>
      <?php endif; ?>

      <?php if ($err !== ''): ?><div class="err"><?= $h($err) ?></div><?php endif; ?>

      <?php if ($voirForm): ?>
      <form method="post" action="/qr/<?= $h($token) ?>">
        <div class="hp" aria-hidden="true">
          <label for="website">Ne pas remplir</label>
          <input type="text" id="website" name="website" tabindex="-1" autocomplete="off">
        </div>

        <div class="row">
          <div><label for="prenom">Prénom</label>
            <input id="prenom" name="prenom" autocomplete="given-name" value="<?= $h($_POST['prenom'] ?? '') ?>"></div>
          <div><label for="nom">Nom</label>
            <input id="nom" name="nom" autocomplete="family-name" value="<?= $h($_POST['nom'] ?? '') ?>"></div>
        </div>

        <label for="email">E-mail</label>
        <input id="email" name="email" type="email" inputmode="email" autocomplete="email" value="<?= $h($_POST['email'] ?? '') ?>">

        <?php if (!empty($qr['ask_phone'])): ?>
          <label for="telephone">Téléphone</label>
          <input id="telephone" name="telephone" type="tel" inputmode="tel" autocomplete="tel" value="<?= $h($_POST['telephone'] ?? '') ?>">
        <?php endif; ?>

        <?php if (!empty($qr['ask_message'])): ?>
          <label for="message">Votre message</label>
          <textarea id="message" name="message" rows="3"><?= $h($_POST['message'] ?? '') ?></textarea>
        <?php endif; ?>

        <label class="consent">
          <input type="checkbox" name="consent" value="1" required>
          <span>J'accepte que <?= $h($orgName) ?> conserve ces coordonnées pour me recontacter.
          Je peux demander leur suppression à tout moment.</span>
        </label>

        <button class="send" type="submit">Envoyer mes coordonnées</button>
      </form>
      <?php endif; ?>
    <?php endif; ?>

    <p class="foot">Propulsé par <a href="https://assokit.fr" target="_blank" rel="noopener">Assokit</a></p>
  </div>
</main>
</body>
</html>
