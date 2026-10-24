<?php
/**
 * valider-inscription.php — Le Fondateur valide ou refuse une demande de création d'espace faite
 * dans l'app iPhone, depuis le lien de son e-mail d'alerte, sans se connecter.
 * --------------------------------------------------------------
 * /valider-inscription?t={org}.{v|r}.{expiration}.{HMAC}   (signup-review-helpers.php)
 *   - le lien est signé (HMAC-SHA256, secret serveur), propre à UNE association et à UNE action
 *     (v = valider, r = refuser) et expire au bout de 7 jours : un lien modifié, d'une autre
 *     association ou expiré est refusé ;
 *   - GET n'agit jamais (les antivirus de messagerie ouvrent les liens tout seuls) : la page montre
 *     la demande et un bouton de confirmation ; « Refuser » propose un motif facultatif, repris
 *     dans l'e-mail au demandeur ;
 *   - POST (jeton du lien + jeton CSRF de la session) appelle ak_signup_review_validate() ou
 *     ak_signup_review_reject(), les mêmes que le cockpit et l'appli. Idempotent : une demande déjà
 *     traitée affiche son état, rien n'est refait (ni essai rouvert, ni second e-mail).
 * Aucune connexion requise : le jeton suffit, comme les liens d'émargement ou de désinscription.
 * --------------------------------------------------------------
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/signup-review-helpers.php';
require_once __DIR__ . '/rate-limit-helper.php';

header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');   // le jeton est dans l'adresse
header('X-Robots-Tag: noindex, nofollow');

$h = 'ak_signup_review_h';
$is_post = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
$t = $is_post ? ($_POST['t'] ?? '') : ($_GET['t'] ?? '');
$t = is_string($t) ? trim($t) : '';
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

// Vue : flood | invalid | expired | no_secret | form | validated | rejected | already_validated | already_rejected | withdrawn | not_request | error
$view = '';
$org = null;
$action = null;
$res = null;
$csrf_error = false;

if (!ak_rate_limit('signup_review_link', 60, 900)) {
    $view = 'flood';
} else {
    $p = ak_signup_review_parse($pdo, $t);
    $org = $p['org'];
    if (!$p['ok']) {
        $view = $p['error'] === 'expired' ? 'expired' : ($p['error'] === 'no_secret' ? 'no_secret' : 'invalid');
    } else {
        $action = $p['action'];
        // Le bouton envoie l'action du lien : un lien « Refuser » ne valide jamais, et inversement
        if ($is_post && ($_POST['do'] ?? '') === $action) {
            if (!function_exists('check_csrf') || !check_csrf($_POST['csrf_token'] ?? '')) {
                $csrf_error = true;
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            } else {
                try {
                    $res = $action === 'reject'
                        ? ak_signup_review_reject($pdo, (int)$org['id'], is_string($_POST['reason'] ?? null) ? $_POST['reason'] : '', 0)
                        : ak_signup_review_validate($pdo, (int)$org['id'], 0);
                    // Décision enregistrée seulement ; demande retirée ou plus en attente : l'état relu ci-dessous
                    if (in_array($res['state'], ['validated', 'rejected'], true)) {
                        $view = $res['state'];
                        // Journal du cockpit (/super-admin/logs), comme « Valider » / « Refuser » du cockpit et de l'appli
                        try {
                            ak_db_insert($pdo, 'platform_activity_log', [
                                'super_admin_id' => 0, 'action' => $action === 'reject' ? 'reject_org' : 'validate_org',
                                'target_org_id' => (int)$org['id'], 'target_user_id' => null,
                                'details_json' => json_encode(['source' => 'lien_email'] + ($action === 'reject' ? ['reason' => trim((string)($_POST['reason'] ?? ''))] : []), JSON_UNESCAPED_UNICODE),
                                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null, 'user_agent' => mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
                            ], ['created_at' => 'NOW()']);
                        } catch (Throwable $e) {}
                    }
                } catch (Throwable $e) {
                    error_log('[valider-inscription] ' . get_class($e) . ' ' . $e->getMessage());
                    $view = 'error';
                }
            }
        }
        if ($view === '') {
            // État relu après une éventuelle action : la page dit toujours où en est la demande
            $st = $pdo->prepare('SELECT * FROM organizations WHERE id = ?');
            $st->execute([(int)$org['id']]);
            $org = $st->fetch(PDO::FETCH_ASSOC) ?: $org;
            if (ak_signup_review_pending($org)) $view = 'form';
            // Retirée par le demandeur (/retirer-demande) avant ou après un refus : plus rien à décider
            elseif (ak_signup_review_withdrawn($org)) $view = 'withdrawn';
            elseif (($org['validation_status'] ?? '') === 'rejected') $view = 'already_rejected';
            elseif (in_array($org['validation_status'] ?? '', ['validated', 'approved'], true)) $view = 'already_validated';
            else $view = 'not_request';
        }
    }
}

$lead = ($org && in_array($view, ['form', 'validated', 'rejected'], true)) ? ak_signup_review_lead($pdo, $org) : null;
$cockpit = 'https://assokit.fr/super-admin/associations' . ($org ? '?id=' . (int)$org['id'] : '?filter=pending');
$org_name = $org ? (string)$org['name'] : '';
$validated_on = $org ? ak_signup_review_when($org['validated_at'] ?? null) : '';
if ($view === 'flood') http_response_code(429);
elseif (in_array($view, ['invalid', 'expired', 'no_secret'], true)) http_response_code(403);
?><!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="robots" content="noindex, nofollow">
  <meta name="referrer" content="no-referrer">
  <title><?= $action === 'reject' ? 'Refuser une demande d’espace' : 'Valider une demande d’espace' ?> — Assokit</title>
  <style>
    *{margin:0;padding:0;box-sizing:border-box}
    :root{--acc:#059669;--acc-dark:#047857;--bg:#FAFAF9;--ink:#1C1917;--ink-2:#44403C;--ink-3:#78716C;--border:#E7E5E4}
    body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;background:var(--bg);color:var(--ink);min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px}
    .card{background:#fff;border:1px solid var(--border);border-radius:16px;padding:34px;max-width:560px;width:100%;box-shadow:0 10px 30px rgba(0,0,0,0.04)}
    @media (max-width:480px){.card{padding:22px}}
    .logo{font-size:20px;font-weight:600;letter-spacing:-0.03em;margin-bottom:22px}.logo span{color:var(--acc)}
    h1{font-size:20px;font-weight:600;letter-spacing:-0.02em;margin-bottom:8px}
    p{font-size:14px;line-height:1.6;color:var(--ink-2);margin-bottom:12px}
    .dl{display:grid;grid-template-columns:auto 1fr;gap:6px 14px;font-size:13.5px;margin:16px 0 20px;padding:14px 16px;background:#FAFAF9;border:1px solid var(--border);border-radius:12px}
    .dl dt{color:var(--ink-3)}.dl dd{color:var(--ink);overflow-wrap:anywhere}
    label{display:block;font-size:12.5px;font-weight:500;color:var(--ink-2);margin:4px 0 6px}
    textarea{width:100%;padding:10px 12px;border:1px solid var(--border);border-radius:10px;font:inherit;font-size:14px;min-height:80px;margin-bottom:12px}
    @media (max-width:480px),(pointer:coarse){textarea{font-size:16px}}
    .btn{display:inline-block;width:100%;padding:12px;border:none;border-radius:10px;font:inherit;font-size:14.5px;font-weight:600;color:#fff;cursor:pointer;text-align:center;text-decoration:none}
    .btn-ok{background:var(--acc)}.btn-ok:hover{background:var(--acc-dark)}
    .btn-no{background:#B91C1C}.btn-no:hover{background:#991B1B}
    .box{padding:12px 14px;border-radius:10px;font-size:13.5px;line-height:1.55;margin-bottom:16px}
    .box-ok{background:#ECFDF5;border:1px solid #A7F3D0;color:#065F46}
    .box-warn{background:#FFFBEB;border:1px solid #FCD34D;color:#92400E}
    .box-err{background:#FEF2F2;border:1px solid #FCA5A5;color:#991B1B}
    .small{font-size:12.5px;color:var(--ink-3)}
    a{color:var(--acc)}
  </style>
</head>
<body>
<main class="card">
  <div class="logo">asso<span>kit</span>.</div>

<?php if ($view === 'flood'): ?>
  <h1>Trop de tentatives</h1>
  <p>Patientez quelques minutes avant de rouvrir ce lien, ou décidez depuis le <a href="<?= $h($cockpit) ?>">cockpit</a>.</p>

<?php elseif ($view === 'invalid' || $view === 'no_secret'): ?>
  <h1>Lien non valable</h1>
  <p><?= $view === 'no_secret' ? 'Les liens de validation ne sont pas configurés sur ce serveur.' : 'Ce lien est incomplet ou a été modifié.' ?>
     Ouvrez la demande depuis le <a href="<?= $h($cockpit) ?>">cockpit</a> pour la valider ou la refuser.</p>

<?php elseif ($view === 'expired'): ?>
  <h1>Lien expiré</h1>
  <p>Les liens de l’e-mail sont valables 7 jours. Décidez depuis le <a href="<?= $h($cockpit) ?>">cockpit</a>
     <?= $org_name !== '' ? '(demande de « ' . $h($org_name) . ' »)' : '' ?>.</p>

<?php elseif ($view === 'error'): ?>
  <h1>Action impossible pour le moment</h1>
  <div class="box box-err">Une erreur technique a empêché l’enregistrement. Rien n’a été modifié : réessayez dans un instant ou passez par le <a href="<?= $h($cockpit) ?>">cockpit</a>.</div>

<?php elseif ($view === 'validated'): ?>
  <h1>Demande validée</h1>
  <div class="box box-ok">L’espace de <strong><?= $h($org_name) ?></strong> est activé. L’essai PRO de <?= (int)AK_TRIAL_DAYS ?> jours court jusqu’au <strong><?= $h(ak_trial_end_label($res['trial_end'] ?? null)) ?></strong>.</div>
  <p><?= !empty($res['mailed']) ? 'L’administrateur a reçu l’e-mail « Votre espace Assokit est activé » avec le lien de connexion.'
        : 'L’e-mail « Votre espace Assokit est activé » n’a pas pu partir : prévenez l’administrateur' . ($lead && $lead['email'] !== '' ? ' (' . $h($lead['email']) . ')' : '') . '.' ?></p>
  <?php if (!empty($res['warnings'])): ?>
  <div class="box box-warn"><strong>À vérifier :</strong><br><?= implode('<br>', array_map($h, $res['warnings'])) ?></div>
  <?php endif; ?>
  <p class="small"><a href="<?= $h($cockpit) ?>">Ouvrir l’association dans le cockpit</a></p>

<?php elseif ($view === 'rejected'): ?>
  <h1>Demande refusée</h1>
  <div class="box box-warn">La demande de <strong><?= $h($org_name) ?></strong> est refusée : l’espace reste fermé.</div>
  <p><?= !empty($res['mailed']) ? 'Le demandeur a reçu un e-mail l’en informant.' : 'L’e-mail au demandeur n’a pas pu partir : prévenez-le si besoin.' ?></p>
  <p class="small"><a href="<?= $h($cockpit) ?>">Ouvrir l’association dans le cockpit</a></p>

<?php elseif ($view === 'already_validated'): ?>
  <h1>Demande déjà validée</h1>
  <p>La demande de <strong><?= $h($org_name) ?></strong> a déjà été validée<?= $validated_on !== '' ? ' le ' . $h($validated_on) : '' ?>. Rien n’a été refait.</p>
  <p class="small"><a href="<?= $h($cockpit) ?>">Ouvrir l’association dans le cockpit</a></p>

<?php elseif ($view === 'already_rejected'): ?>
  <h1>Demande déjà refusée</h1>
  <p>La demande de <strong><?= $h($org_name) ?></strong> a déjà été refusée<?= $validated_on !== '' ? ' le ' . $h($validated_on) : '' ?>. Rien n’a été refait.</p>
  <p class="small"><a href="<?= $h($cockpit) ?>">Ouvrir l’association dans le cockpit</a></p>

<?php elseif ($view === 'withdrawn'): ?>
  <h1>Demande retirée</h1>
  <p>Le demandeur a retiré sa demande pour « <?= $h($org_name) ?> » : l’espace est fermé et les informations transmises sont effacées. Ce lien ne permet plus aucune action.</p>
  <p class="small"><a href="<?= $h($cockpit) ?>">Ouvrir l’association dans le cockpit</a></p>

<?php elseif ($view === 'not_request'): ?>
  <h1>Plus de demande en attente</h1>
  <p>« <?= $h($org_name) ?> » n’attend plus de décision par ce lien. Consultez son état dans le <a href="<?= $h($cockpit) ?>">cockpit</a>.</p>

<?php else: /* form */ ?>
  <h1><?= $action === 'reject' ? 'Refuser cette demande d’espace ?' : 'Valider cette demande d’espace ?' ?></h1>
  <?php if ($csrf_error): ?><div class="box box-err">La page a expiré : vérifiez la demande puis confirmez de nouveau.</div><?php endif; ?>
  <p>Demande faite depuis l’application iPhone. Rien n’est actif tant qu’elle n’est pas validée.</p>
  <dl class="dl">
    <dt>Structure</dt><dd><strong><?= $h($lead['org_name']) ?></strong><?= $lead['type'] === 'tpe' ? ' · TPE / entreprise' : ' · Association' ?></dd>
    <dt>Contact</dt><dd><?= $h(trim($lead['first_name'] . ' ' . $lead['last_name'])) ?></dd>
    <dt>E-mail</dt><dd><?= $h($lead['email']) ?></dd>
    <?php if ($lead['phone'] !== ''): ?><dt>Téléphone</dt><dd><?= $h($lead['phone']) ?></dd><?php endif; ?>
    <?php foreach ($lead['details'] as $k => $v): if (in_array($k, ['Type', 'Téléphone'], true)) continue; ?>
    <dt><?= $h($k) ?></dt><dd><?= nl2br($h($v)) ?></dd>
    <?php endforeach; ?>
    <?php if (($when = ak_signup_review_when($lead['requested_at'])) !== ''): ?><dt>Demandée le</dt><dd><?= $h($when) ?></dd><?php endif; ?>
  </dl>
  <form method="POST" action="/valider-inscription">
    <input type="hidden" name="t" value="<?= $h($t) ?>">
    <input type="hidden" name="csrf_token" value="<?= $h($_SESSION['csrf_token']) ?>">
    <input type="hidden" name="do" value="<?= $h($action) ?>">
    <?php if ($action === 'reject'): ?>
      <label for="reason">Motif (facultatif, repris dans l’e-mail au demandeur)</label>
      <textarea id="reason" name="reason" maxlength="250" placeholder="Ex. : structure introuvable, doublon d’un espace existant…"></textarea>
      <button type="submit" class="btn btn-no">Confirmer le refus</button>
      <p class="small" style="margin-top:12px;">Le demandeur reçoit un e-mail poli ; l’espace reste fermé.</p>
    <?php else: ?>
      <button type="submit" class="btn btn-ok">Confirmer la validation</button>
      <p class="small" style="margin-top:12px;">L’essai PRO de <?= (int)AK_TRIAL_DAYS ?> jours démarre maintenant et l’administrateur reçoit l’e-mail « Votre espace Assokit est activé ».</p>
    <?php endif; ?>
  </form>
  <p class="small" style="margin-top:6px;">Vous pouvez aussi décider depuis le <a href="<?= $h($cockpit) ?>">cockpit</a>.</p>
<?php endif; ?>
</main>
</body>
</html>
