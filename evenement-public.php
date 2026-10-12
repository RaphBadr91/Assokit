<?php
/**
 * evenement-public.php — Page publique d'un evenement (RSVP)
 * ==============================================================
 * URL : /evenement-public/{slug}
 * Accessible sans connexion. Permet aux invites de repondre
 * Oui / Non / Peut-etre + nombre d'accompagnants + commentaire.
 */

require_once __DIR__ . '/config.php';

$slug = is_string($_GET['slug'] ?? null) ? $_GET['slug'] : '';
$slug = preg_replace('/[^a-z0-9-]/i', '', $slug);

if (!$slug) {
    http_response_code(404);
    die('Événement introuvable.');
}

// Charger l'event
$stmt = $pdo->prepare("
    SELECT e.*, o.name AS org_name
    FROM communication_events e
    JOIN organizations o ON e.org_id = o.id
    WHERE e.public_slug = ? AND e.is_public = 1 AND e.status = 'published'
");
$stmt->execute([$slug]);
$event = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$event) {
    http_response_code(404);
    die('Événement introuvable ou non public.');
}

@require_once __DIR__ . '/resend-helper.php';      // send_transactional_email() : lien de modification d'une réponse
@require_once __DIR__ . '/rate-limit-helper.php';  // ak_rate_limit() : anti-remplissage de la jauge par un robot

/** Compteurs de réponses ; total_yes = places prises (« oui » + leurs accompagnants). */
function rsvp_counts(PDO $pdo, int $event_id): array {
    $stmt = $pdo->prepare("
        SELECT response, COUNT(*) AS nb, SUM(nb_accompanying) AS acc
        FROM communication_event_rsvps
        WHERE event_id = ?
        GROUP BY response
    ");
    $stmt->execute([$event_id]);
    $counts = ['yes' => 0, 'no' => 0, 'maybe' => 0, 'total_yes' => 0];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if (!isset($counts[$r['response']])) continue;
        $counts[$r['response']] = (int) $r['nb'];
        if ($r['response'] === 'yes') {
            $counts['total_yes'] = (int) $r['nb'] + (int) $r['acc'];
        }
    }
    return $counts;
}

/** Secret des liens « modifier ma réponse » ('' : aucun secret configuré, liens désactivés). */
function rsvp_secret(): string {
    foreach (['RESEND_API_KEY', 'DB_PASS', 'CRON_TOKEN'] as $c) {
        if (defined($c) && (string) constant($c) !== '') return hash('sha256', 'akrsvp|' . $c . '|' . constant($c));
    }
    return '';
}

/** Jeton personnel d'une réponse : seul le titulaire de l'adresse (lien reçu par e-mail) ou l'appareil qui a répondu le possède. */
function rsvp_token(array $rsvp): string {
    $secret = rsvp_secret();
    $email = mb_strtolower(trim((string) ($rsvp['guest_email'] ?? '')));
    if ($secret === '' || $email === '') return '';
    return (int) $rsvp['id'] . '-' . substr(hash_hmac('sha256', 'rsvp|' . (int) $rsvp['id'] . '|' . (int) $rsvp['event_id'] . '|' . $email, $secret), 0, 32);
}

/** Réponse désignée par un jeton valide pour cet événement, sinon null. */
function rsvp_from_token(PDO $pdo, int $event_id, $token): ?array {
    if (!is_string($token) || !preg_match('/^(\d+)-([a-f0-9]{32})$/', $token, $m)) return null;
    $stmt = $pdo->prepare("SELECT * FROM communication_event_rsvps WHERE id = ? AND event_id = ? LIMIT 1");
    $stmt->execute([(int) $m[1], $event_id]);
    $r = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$r) return null;
    $expected = rsvp_token($r);
    return ($expected !== '' && hash_equals($expected, $token)) ? $r : null;
}

$event_id = (int) $event['id'];
$max_attendees = (int) ($event['max_attendees'] ?? 0);
$counts = rsvp_counts($pdo, $event_id);
$is_full = $max_attendees > 0 && $counts['total_yes'] >= $max_attendees;

// Modification d'une réponse existante : lien reçu par e-mail (?modifier=…) ou cookie
// posé sur l'appareil qui a répondu. Sans ce jeton, une réponse existante n'est jamais écrasée.
$host = $_SERVER['HTTP_HOST'] ?? 'assokit.fr';
$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
$cookie_name = 'ak_rsvp_' . $event_id;
$set_rsvp_cookie = function (string $token) use ($cookie_name, $https) {
    if ($token === '' || headers_sent()) return;
    setcookie($cookie_name, $token, ['expires' => time() + 180 * 86400, 'path' => '/evenement-public/', 'secure' => $https, 'httponly' => true, 'samesite' => 'Lax']);
};
$edit_rsvp = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $edit_rsvp = rsvp_from_token($pdo, $event_id, $_POST['edit_token'] ?? null);
} elseif (isset($_GET['modifier'])) {
    $edit_rsvp = rsvp_from_token($pdo, $event_id, $_GET['modifier']);
    if ($edit_rsvp) $set_rsvp_cookie(rsvp_token($edit_rsvp));   // la suite se fait sur cet appareil
}
if (!$edit_rsvp && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $edit_rsvp = rsvp_from_token($pdo, $event_id, $_COOKIE[$cookie_name] ?? null);
}
// Page personnelle (réponse pré-remplie, jeton de modification) : jamais mise en cache
if ($edit_rsvp || $_SERVER['REQUEST_METHOD'] === 'POST') header('Cache-Control: private, no-store');

// Traitement du RSVP
$success = false;
$error = null;
$notice = null;   // réponse existante non modifiée : lien envoyé à son adresse
$saved = null;    // réponse enregistrée (écran de confirmation)

$post_str = fn(string $k): string => is_scalar($_POST[$k] ?? null) ? trim((string) $_POST[$k]) : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $event['rsvp_enabled']) {
    $guest_name = mb_substr($post_str('guest_name'), 0, 100);
    // L'adresse d'une réponse existante ne change pas (c'est elle qui l'identifie)
    $guest_email = $edit_rsvp ? (string) $edit_rsvp['guest_email'] : mb_substr($post_str('guest_email'), 0, 200);
    $response = $post_str('response');
    // Plafond serveur (le max=10 du formulaire se contourne facilement)
    $nb_accompanying = min(10, max(0, (int) $post_str('nb_accompanying')));
    $comment = mb_substr($post_str('comment'), 0, 500);

    if ($guest_name === '' || mb_strlen($guest_name) < 2) {
        $error = 'Merci d\'indiquer votre nom.';
    } elseif (!filter_var($guest_email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Adresse email invalide.';
    } elseif (!in_array($response, ['yes', 'no', 'maybe'], true)) {
        $error = 'Merci de choisir une réponse.';
    } elseif (!$edit_rsvp && function_exists('ak_rate_limit') && !ak_rate_limit('rsvp_' . $event_id, 60, 600)) {
        $error = 'Trop de réponses envoyées depuis cette connexion. Merci de réessayer dans quelques minutes.';
    } else {
        $existing = null;
        try {
            // Jauge : une réponse à la fois (sinon deux « oui » simultanés dépasseraient les places)
            $pdo->beginTransaction();
            $pdo->prepare("SELECT id FROM communication_events WHERE id = ? FOR UPDATE")->execute([$event_id]);

            if ($edit_rsvp) {
                $stmt = $pdo->prepare("SELECT * FROM communication_event_rsvps WHERE id = ? AND event_id = ? LIMIT 1");
                $stmt->execute([(int) $edit_rsvp['id'], $event_id]);
            } else {
                $stmt = $pdo->prepare("SELECT * FROM communication_event_rsvps WHERE event_id = ? AND guest_email = ? ORDER BY id LIMIT 1");
                $stmt->execute([$event_id, $guest_email]);
            }
            $existing = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

            if ($existing && (!$edit_rsvp || (int) $edit_rsvp['id'] !== (int) $existing['id'])) {
                // Cette adresse a déjà répondu : on n'écrase pas la réponse d'autrui, on envoie
                // à cette adresse un lien personnel pour la modifier.
                $pdo->rollBack();
                $token = rsvp_token($existing);
                $sent = false;
                if ($token !== '' && function_exists('send_transactional_email')
                    && (!function_exists('ak_rate_limit') || ak_rate_limit('rsvp_link_' . (int) $existing['id'], 3, 3600, 'rsvp'))) {
                    $link = ($https ? 'https' : 'http') . '://' . $host . '/evenement-public/' . $slug . '?modifier=' . $token;
                    $html = '<div style="font-family:sans-serif;max-width:520px;margin:0 auto;padding:24px;">'
                        . '<p style="font-size:14px;color:#374151;line-height:1.6;">Bonjour,</p>'
                        . '<p style="font-size:14px;color:#374151;line-height:1.6;">Une modification de votre réponse à l’événement <strong>' . htmlspecialchars($event['title']) . '</strong> (' . htmlspecialchars($event['org_name']) . ') a été demandée. Pour modifier votre réponse, cliquez sur ce lien personnel :</p>'
                        . '<p><a href="' . htmlspecialchars($link) . '" style="display:inline-block;background:#059669;color:#fff;padding:12px 22px;border-radius:8px;text-decoration:none;font-weight:600;font-size:14px;">Modifier ma réponse →</a></p>'
                        . '<p style="font-size:12px;color:#6b7280;">Si vous n’êtes pas à l’origine de cette demande, ignorez ce message : votre réponse n’a pas été modifiée.</p>'
                        . '</div>';
                    try {
                        $res = send_transactional_email($guest_email, 'Modifier votre réponse : ' . $event['title'], $html, ['tag' => 'rsvp_modification', 'from_name' => (string) $event['org_name']]);
                        $sent = !empty($res['success']);
                    } catch (Throwable $e) {
                        error_log('RSVP link email: ' . $e->getMessage());
                    }
                }
                $notice = $sent
                    ? 'Une réponse est déjà enregistrée avec cette adresse : elle n’a pas été modifiée. Pour la changer, cliquez sur le lien que nous venons d’envoyer à ' . $guest_email . '.'
                    : 'Une réponse est déjà enregistrée avec cette adresse : elle n’a pas été modifiée. Pour la changer, utilisez le lien « Modifier ma réponse » reçu par e-mail, ou contactez ' . $event['org_name'] . '.';
            } else {
                // Places restantes, en retirant celles que cette personne occupait déjà. Un inscrit
                // qui ne demande pas plus de places qu'il n'en a garde sa réponse, même si
                // l'événement est déjà au-delà de sa jauge (jauge réduite depuis, anciennes réponses).
                $counts = rsvp_counts($pdo, $event_id);
                $mine = ($existing && $existing['response'] === 'yes') ? 1 + (int) $existing['nb_accompanying'] : 0;
                $left = $max_attendees - ($counts['total_yes'] - $mine);
                if ($response === 'yes' && $max_attendees > 0 && 1 + $nb_accompanying > $left && 1 + $nb_accompanying > $mine) {
                    $pdo->rollBack();
                    $error = $left <= 0
                        ? 'Désolé, l\'événement est complet.'
                        : ($left === 1
                            ? 'Il ne reste qu’une place : vous pouvez venir, mais sans accompagnant.'
                            : 'Il ne reste que ' . $left . ' places : vous pouvez venir avec ' . ($left - 1) . ' accompagnant' . ($left > 2 ? 's' : '') . ' au maximum.');
                } else {
                    if ($existing) {
                        $stmt = $pdo->prepare("
                            UPDATE communication_event_rsvps
                            SET response = ?, guest_name = ?, nb_accompanying = ?, comment = ?, responded_at = NOW()
                            WHERE id = ? AND event_id = ?
                        ");
                        $stmt->execute([$response, $guest_name, $nb_accompanying, $comment ?: null, (int) $existing['id'], $event_id]);
                        $saved_id = (int) $existing['id'];
                    } else {
                        $stmt = $pdo->prepare("
                            INSERT INTO communication_event_rsvps
                                (event_id, guest_name, guest_email, response, nb_accompanying, comment, responded_at)
                            VALUES (?, ?, ?, ?, ?, ?, NOW())
                        ");
                        $stmt->execute([$event_id, $guest_name, $guest_email, $response, $nb_accompanying, $comment ?: null]);
                        $saved_id = (int) $pdo->lastInsertId();
                    }
                    $pdo->commit();
                    $success = true;
                    $saved = ['id' => $saved_id, 'event_id' => $event_id, 'guest_email' => $guest_email];
                    $set_rsvp_cookie(rsvp_token($saved));   // modification possible depuis cet appareil
                }
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error = 'Erreur technique. Merci de réessayer.';
            error_log('RSVP error: ' . $e->getMessage());
        }
    }
}

// Compteurs à jour (l'écran de confirmation inclut la réponse qui vient d'être enregistrée)
if ($success) {
    $counts = rsvp_counts($pdo, $event_id);
    $is_full = $max_attendees > 0 && $counts['total_yes'] >= $max_attendees;
}
$saved_link = $saved ? rsvp_token($saved) : '';
$saved_link = $saved_link !== '' ? '/evenement-public/' . $slug . '?modifier=' . $saved_link : '';

// Valeurs du formulaire : saisie en cours, sinon la réponse en cours de modification
$fv = [
    'guest_name'      => $edit_rsvp['guest_name'] ?? '',
    'guest_email'     => $edit_rsvp['guest_email'] ?? '',
    'response'        => $edit_rsvp['response'] ?? '',
    'nb_accompanying' => (string) (int) ($edit_rsvp['nb_accompanying'] ?? 0),
    'comment'         => $edit_rsvp['comment'] ?? '',
];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach (['guest_name', 'response', 'nb_accompanying', 'comment'] as $k) $fv[$k] = $post_str($k);
    if (!$edit_rsvp) $fv['guest_email'] = $post_str('guest_email');
}
// « Oui » reste possible pour une personne déjà inscrite qui modifie sa réponse
$yes_disabled = $is_full && !($edit_rsvp && $edit_rsvp['response'] === 'yes');

// Dates formatees
$start_ts = strtotime($event['start_date']);
$end_ts = $event['end_date'] ? strtotime($event['end_date']) : null;
$months_fr = ['', 'janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
$days_fr = ['dimanche', 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi'];
$date_fr = $days_fr[(int)date('w', $start_ts)] . ' ' . (int)date('j', $start_ts) . ' ' . $months_fr[(int)date('n', $start_ts)] . ' ' . date('Y', $start_ts);

?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="index, follow">
<title><?= htmlspecialchars($event['title']) ?> — <?= htmlspecialchars($event['org_name']) ?></title>

<!-- Open Graph (pour Facebook, WhatsApp, etc.) -->
<meta property="og:title" content="<?= htmlspecialchars($event['title']) ?>">
<meta property="og:description" content="<?= htmlspecialchars($event['description'] ?? 'Événement organisé par ' . $event['org_name']) ?>">
<meta property="og:type" content="event">

<style>
  * { box-sizing: border-box; }
  body {
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Inter, system-ui, sans-serif;
    margin: 0; padding: 0;
    background: linear-gradient(135deg, #FAFAF9 0%, #F5F5F4 100%);
    color: #1C1917;
    line-height: 1.6;
    min-height: 100vh;
  }
  .container { max-width: 600px; margin: 0 auto; padding: 24px 20px 48px; }

  .hero {
    background: linear-gradient(135deg, #059669 0%, #10B981 100%);
    color: white;
    padding: 40px 28px 32px;
    border-radius: 18px 18px 0 0;
    position: relative;
    overflow: hidden;
  }
  .hero::before {
    content: '';
    position: absolute; top: -40%; right: -20%;
    width: 300px; height: 300px;
    background: rgba(255,255,255,0.08);
    border-radius: 50%;
  }
  .hero-badge { font-size: 12px; opacity: 0.9; text-transform: uppercase; letter-spacing: 0.1em; margin-bottom: 8px; font-weight: 500; }
  .hero-title { font-size: 28px; font-weight: 600; letter-spacing: -0.02em; margin: 0 0 14px; line-height: 1.15; }
  .hero-org { font-size: 13px; opacity: 0.9; }

  .card {
    background: white;
    border: 1px solid #E7E5E4;
    border-top: none;
    padding: 24px 26px;
  }
  .card:first-of-type { padding-top: 28px; }
  .card:last-of-type { border-radius: 0 0 18px 18px; }

  .detail-row {
    display: flex; align-items: center; gap: 14px;
    padding: 12px 0;
    border-bottom: 1px solid #F5F5F4;
  }
  .detail-row:last-child { border-bottom: none; }
  .detail-icon {
    width: 36px; height: 36px;
    background: #ECFDF5; color: #059669;
    border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    font-size: 18px;
    flex-shrink: 0;
  }
  .detail-info { flex: 1; min-width: 0; }
  .detail-label { font-size: 11px; color: #78716C; text-transform: uppercase; letter-spacing: 0.05em; font-weight: 500; }
  .detail-value { font-size: 14.5px; color: #1C1917; margin-top: 2px; font-weight: 500; }

  .desc-block {
    background: #ECFDF5;
    border-left: 3px solid #059669;
    padding: 14px 16px;
    border-radius: 8px;
    font-size: 14px;
    color: #065F46;
    line-height: 1.6;
    white-space: pre-wrap;
    margin-top: 14px;
  }

  .rsvp-section { margin-top: 20px; padding-top: 20px; border-top: 1px solid #E7E5E4; }
  .rsvp-title { font-size: 18px; font-weight: 600; margin: 0 0 6px; letter-spacing: -0.01em; }
  .rsvp-sub { font-size: 13px; color: #78716C; margin: 0 0 18px; }

  .form-group { margin-bottom: 14px; }
  .form-label { display: block; font-size: 12.5px; font-weight: 500; margin-bottom: 6px; color: #44403C; }
  .form-input, .form-textarea {
    width: 100%;
    padding: 11px 13px;
    background: #FAFAF9;
    border: 1px solid #D6D3D1;
    border-radius: 9px;
    font-family: inherit; font-size: 14px; color: #1C1917;
    transition: border-color 0.15s, background 0.15s;
  }
  .form-input:focus, .form-textarea:focus {
    outline: none;
    border-color: #059669;
    background: white;
    box-shadow: 0 0 0 3px rgba(5, 150, 105, 0.1);
  }
  .form-textarea { resize: vertical; min-height: 70px; }

  .response-group { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 8px; }
  .response-option {
    cursor: pointer;
    padding: 14px 10px; text-align: center;
    background: #FAFAF9;
    border: 2px solid #E7E5E4;
    border-radius: 10px;
    transition: all 0.15s;
  }
  .response-option input { display: none; }
  .response-option:hover { border-color: #059669; background: #F0FDF4; }
  .response-option.selected { border-color: #059669; background: #D1FAE5; }
  .response-option.selected.no { border-color: #6B7280; background: #F3F4F6; }
  .response-option.selected.maybe { border-color: #F59E0B; background: #FEF3C7; }
  .response-icon { font-size: 22px; margin-bottom: 3px; }
  .response-label { font-size: 12.5px; font-weight: 500; color: #44403C; }

  .btn-primary {
    width: 100%;
    background: #059669;
    color: white;
    border: none;
    padding: 14px 20px;
    border-radius: 10px;
    font-family: inherit; font-size: 15px; font-weight: 500;
    cursor: pointer;
    transition: background 0.15s;
    letter-spacing: -0.01em;
  }
  .btn-primary:hover { background: #047857; }
  .btn-primary:disabled { background: #D6D3D1; cursor: not-allowed; }

  .alert-error {
    background: #FEF2F2;
    border: 1px solid #FECACA;
    color: #991B1B;
    padding: 12px 14px;
    border-radius: 10px;
    font-size: 13px;
    margin-bottom: 16px;
  }

  .success-state {
    text-align: center;
    padding: 30px 20px;
  }
  .success-icon {
    width: 64px; height: 64px;
    background: #D1FAE5; color: #059669;
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: 34px;
    margin: 0 auto 16px;
  }
  .success-title { font-size: 20px; font-weight: 600; margin: 0 0 8px; letter-spacing: -0.01em; }
  .success-text { font-size: 14px; color: #57534E; margin: 0 0 18px; }

  .stats-bar {
    display: flex; justify-content: center; gap: 16px;
    font-size: 12.5px; color: #78716C;
    margin-top: 14px;
    padding-top: 14px;
    border-top: 1px solid #E7E5E4;
  }
  .stats-bar strong { color: #059669; font-weight: 600; }

  .full-badge {
    background: #FEE2E2; color: #991B1B;
    padding: 4px 10px; border-radius: 999px;
    font-size: 11.5px; font-weight: 500;
    display: inline-block; margin-top: 10px;
  }

  .footer {
    text-align: center;
    margin-top: 24px;
    font-size: 11.5px;
    color: #A8A29E;
  }
  .footer a { color: #059669; text-decoration: none; font-weight: 500; }

  @media (max-width: 480px) {
    .hero { padding: 32px 22px 28px; border-radius: 14px 14px 0 0; }
    .hero-title { font-size: 22px; }
    .card { padding: 20px 22px; }
    .response-group { gap: 6px; }
    .response-option { padding: 12px 6px; }
  }
</style>
</head>
<body>

<div class="container">

  <!-- HERO -->
  <div class="hero">
    <div class="hero-badge">🎪 Invitation</div>
    <h1 class="hero-title"><?= htmlspecialchars($event['title']) ?></h1>
    <div class="hero-org">Organisé par <strong><?= htmlspecialchars($event['org_name']) ?></strong></div>
  </div>

  <!-- DETAILS -->
  <div class="card">
    <div class="detail-row">
      <div class="detail-icon">📅</div>
      <div class="detail-info">
        <div class="detail-label">Quand</div>
        <div class="detail-value">
          <?= $date_fr ?>
          <br><span style="font-weight: 400; color: #57534E; font-size: 13px;">
            à <?= date('H\hi', $start_ts) ?>
            <?php if ($end_ts): ?> · jusqu'à <?= date('H\hi', $end_ts) ?><?php endif; ?>
          </span>
        </div>
      </div>
    </div>

    <?php if ($event['location']): ?>
    <div class="detail-row">
      <div class="detail-icon">📍</div>
      <div class="detail-info">
        <div class="detail-label">Où</div>
        <div class="detail-value">
          <?= htmlspecialchars($event['location']) ?>
          <?php if ($event['location_address']): ?>
            <br><span style="font-weight: 400; color: #57534E; font-size: 13px;"><?= htmlspecialchars($event['location_address']) ?></span>
          <?php endif; ?>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <?php if ($event['max_attendees']): ?>
    <div class="detail-row">
      <div class="detail-icon">🎟️</div>
      <div class="detail-info">
        <div class="detail-label">Places</div>
        <div class="detail-value">
          <?= (int) $counts['total_yes'] ?> / <?= (int) $event['max_attendees'] ?>
          <?php if ($is_full): ?>
            <span class="full-badge">Complet</span>
          <?php else: ?>
            <br><span style="font-weight: 400; color: #57534E; font-size: 13px;">Il reste <?= $max_attendees - (int) $counts['total_yes'] ?> place<?= $max_attendees - (int) $counts['total_yes'] > 1 ? 's' : '' ?> (accompagnants compris)</span>
          <?php endif; ?>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <?php if ($event['description']): ?>
      <div class="desc-block"><?= htmlspecialchars($event['description']) ?></div>
    <?php endif; ?>
  </div>

  <!-- FORMULAIRE RSVP -->
  <?php if ($event['rsvp_enabled']): ?>
  <div class="card">
    <?php if ($success): ?>
      <div class="success-state">
        <div class="success-icon">✓</div>
        <h2 class="success-title">Merci pour votre réponse !</h2>
        <p class="success-text">
          Votre réponse a bien été enregistrée.<?= $saved_link !== '' ? ' Pour la modifier plus tard, gardez ce lien personnel (ne le partagez pas) :' : ' Pour la modifier, contactez ' . htmlspecialchars($event['org_name']) . '.' ?>
        </p>
        <?php if ($saved_link !== ''): ?>
          <p class="success-text"><a href="<?= htmlspecialchars($saved_link) ?>">Modifier ma réponse</a></p>
        <?php endif; ?>
        <div class="stats-bar">
          <span><strong><?= $counts['yes'] ?></strong> oui</span>
          <span><strong style="color:#F59E0B"><?= $counts['maybe'] ?></strong> peut-être</span>
          <span><strong style="color:#A8A29E"><?= $counts['no'] ?></strong> non</span>
        </div>
      </div>
    <?php else: ?>
      <div class="rsvp-section" style="border-top:none; padding-top:0;">
        <h2 class="rsvp-title"><?= $edit_rsvp ? 'Modifier votre réponse' : 'Votre réponse' ?></h2>
        <p class="rsvp-sub"><?= $edit_rsvp ? 'Vous avez déjà répondu avec l’adresse ' . htmlspecialchars((string) $edit_rsvp['guest_email']) . ' : vous pouvez changer votre réponse ci-dessous.' : 'Merci de confirmer votre présence ci-dessous.' ?></p>

        <?php if ($error): ?>
          <div class="alert-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <?php if ($notice): ?>
          <div class="alert-error" style="background:#FFFBEB;border-color:#FDE68A;color:#92400E;"><?= htmlspecialchars($notice) ?></div>
        <?php endif; ?>

        <form method="POST" action="/evenement-public/<?= htmlspecialchars($slug) ?>">
          <?php if ($edit_rsvp): ?>
            <input type="hidden" name="edit_token" value="<?= htmlspecialchars(rsvp_token($edit_rsvp)) ?>">
          <?php endif; ?>
          <div class="form-group">
            <label class="form-label" for="guest_name">Votre nom *</label>
            <input type="text" id="guest_name" name="guest_name" class="form-input" required minlength="2" maxlength="100"
                   value="<?= htmlspecialchars($fv['guest_name']) ?>" placeholder="Prénom Nom">
          </div>

          <div class="form-group">
            <label class="form-label" for="guest_email">Email *</label>
            <input type="email" id="guest_email" name="guest_email" class="form-input" required maxlength="200"
                   value="<?= htmlspecialchars($fv['guest_email']) ?>" placeholder="votre@email.fr"<?= $edit_rsvp ? ' readonly' : '' ?>>
          </div>

          <div class="form-group">
            <label class="form-label">Votre réponse *</label>
            <div class="response-group">
              <label class="response-option<?= $fv['response'] === 'yes' ? ' selected' : '' ?>" data-value="yes">
                <input type="radio" name="response" value="yes" required <?= $fv['response'] === 'yes' ? 'checked' : '' ?><?= $yes_disabled ? ' disabled' : '' ?>>
                <div class="response-icon">✓</div>
                <div class="response-label"><?= $yes_disabled ? 'Complet' : 'Oui, je viens' ?></div>
              </label>
              <label class="response-option<?= $fv['response'] === 'maybe' ? ' selected maybe' : '' ?>" data-value="maybe">
                <input type="radio" name="response" value="maybe" required <?= $fv['response'] === 'maybe' ? 'checked' : '' ?>>
                <div class="response-icon">?</div>
                <div class="response-label">Peut-être</div>
              </label>
              <label class="response-option<?= $fv['response'] === 'no' ? ' selected no' : '' ?>" data-value="no">
                <input type="radio" name="response" value="no" required <?= $fv['response'] === 'no' ? 'checked' : '' ?>>
                <div class="response-icon">✕</div>
                <div class="response-label">Non, désolé</div>
              </label>
            </div>
          </div>

          <div class="form-group">
            <label class="form-label" for="nb_accompanying">Accompagnants (optionnel, 10 au maximum)</label>
            <input type="number" id="nb_accompanying" name="nb_accompanying" class="form-input" min="0" max="10"
                   value="<?= htmlspecialchars($fv['nb_accompanying'] !== '' ? $fv['nb_accompanying'] : '0') ?>" placeholder="0">
          </div>

          <div class="form-group">
            <label class="form-label" for="comment">Message pour l'organisateur (optionnel)</label>
            <textarea id="comment" name="comment" class="form-textarea" maxlength="500" placeholder="Allergie alimentaire, horaires, questions..."><?= htmlspecialchars($fv['comment']) ?></textarea>
          </div>

          <button type="submit" class="btn-primary">Envoyer ma réponse →</button>
        </form>
      </div>
    <?php endif; ?>
  </div>
  <?php else: ?>
    <div class="card">
      <div style="text-align:center; padding:20px; color:#78716C;">
        <div style="font-size:32px; margin-bottom:8px; opacity:0.5;">📩</div>
        Pas de RSVP en ligne pour cet événement.<br>
        Contactez directement <?= htmlspecialchars($event['org_name']) ?> pour confirmer votre venue.
      </div>
    </div>
  <?php endif; ?>

  <div class="footer">
    Propulsé par <a href="https://assokit.fr" target="_blank">Assokit</a> · Le SaaS des associations françaises
  </div>

</div>

<script>
// Animation selection radio
document.querySelectorAll('.response-option').forEach(function(opt) {
  opt.addEventListener('click', function() {
    var value = this.dataset.value;
    document.querySelectorAll('.response-option').forEach(function(o) {
      o.classList.remove('selected', 'no', 'maybe');
    });
    this.classList.add('selected');
    if (value === 'no') this.classList.add('no');
    if (value === 'maybe') this.classList.add('maybe');
  });
});
</script>
</body>
</html>
