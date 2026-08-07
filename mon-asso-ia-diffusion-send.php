<?php
/**
 * mon-asso-ia-diffusion-send.php
 * --------------------------------------------------------------
 * Endpoint POST — Envoie une diffusion email
 *
 * Sécurité : max 200 destinataires par envoi
 * Log : chaque destinataire est tracé dans asso_ai_diffusion_recipients
 * --------------------------------------------------------------
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/rate-limit-helper.php';
ak_rate_limit_or_die('ia_diffusion_send', 5, 60, (string)($_SESSION['user_id'] ?? ($_SERVER['REMOTE_ADDR'] ?? 'anon')));
require_once __DIR__ . '/asso-ai-helpers.php';

require_login();
$user = current_user();
$org_id  = (int)($user['org_id'] ?? 0);
$user_id = (int)($user['id'] ?? 0);
if ($org_id <= 0) { header('Location: /'); exit; }

// Envoi de masse : reserve aux admins/coordinateurs (comms officielles au nom de l'asso)
if (!in_array(($user['role'] ?? ''), ['admin', 'coordinator'], true)) {
    http_response_code(403);
    $_SESSION['flash_error'] = "L'envoi de diffusions est reserve aux administrateurs et coordinateurs.";
    header('Location: /mon-asso-ia-diffusion');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /mon-asso-ia-diffusion');
    exit;
}

if (!check_csrf($_POST['csrf_token'] ?? '')) {
    $_SESSION['flash_error'] = 'Session expiree, veuillez reessayer.';
    header('Location: /mon-asso-ia-diffusion');
    exit;
}

$subject     = trim((string)($_POST['subject'] ?? ''));
$body_md     = trim((string)($_POST['body_md'] ?? ''));
$gen_id      = (int)($_POST['generation_id'] ?? 0) ?: null;

if ($subject === '' || $body_md === '') {
    $_SESSION['flash_error'] = 'Objet et corps de l\'email obligatoires.';
    header('Location: /mon-asso-ia-diffusion');
    exit;
}

$sources = [
    'roles'       => $_POST['roles']       ?? [],
    'project_ids' => $_POST['project_ids'] ?? [],
    'manual'      => (string)($_POST['manual_emails'] ?? ''),
];

try {
    $recipients = ak_ai_collect_recipients($pdo, $org_id, $sources);
} catch (Throwable $e) {
    $_SESSION['flash_error'] = 'Erreur destinataires : ' . $e->getMessage();
    header('Location: /mon-asso-ia-diffusion');
    exit;
}

if (empty($recipients)) {
    $_SESSION['flash_error'] = 'Aucun destinataire valide.';
    header('Location: /mon-asso-ia-diffusion');
    exit;
}

if (count($recipients) > 200) {
    $_SESSION['flash_error'] = 'Maximum 200 destinataires par envoi (limite de sécurité).';
    header('Location: /mon-asso-ia-diffusion');
    exit;
}

// Construction HTML email avec template propre
$body_html_inner = ak_ai_md_to_html($body_md);
$org_ctx = ak_ai_get_org_context($pdo, $org_id);
$org_name = $org_ctx['name'] ?: 'Notre association';

$body_html = '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>' . htmlspecialchars($subject, ENT_QUOTES, 'UTF-8') . '</title></head>'
           . '<body style="margin:0;padding:0;background:#F8FAFC;font-family:Arial,Helvetica,sans-serif;">'
           . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#F8FAFC;padding:30px 0;">'
           . '<tr><td align="center">'
           . '<table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="background:white;border-radius:12px;overflow:hidden;max-width:600px;width:100%;">'
           . '<tr><td style="padding:30px 32px;color:#0F172A;line-height:1.65;font-size:15px;">'
           . $body_html_inner
           . '</td></tr>'
           . '<tr><td style="padding:18px 32px;background:#F1F5F9;color:#64748B;font-size:12px;text-align:center;">'
           . htmlspecialchars($org_name, ENT_QUOTES, 'UTF-8')
           . ' · Email envoyé via Assokit'
           . '</td></tr>'
           . '</table></td></tr></table></body></html>';

// 1. Crée l'enregistrement diffusion
try {
    $stIns = $pdo->prepare("
        INSERT INTO asso_ai_diffusions
          (org_id, user_id, generation_id, subject, body_md, body_html, recipients_data, recipients_count, status, created_at)
        VALUES
          (:o, :uid, :gid, :sub, :md, :html, :rec, :n, 'sending', NOW())
    ");
    $stIns->execute([
        ':o' => $org_id, ':uid' => $user_id, ':gid' => $gen_id,
        ':sub' => mb_substr($subject, 0, 255),
        ':md' => $body_md, ':html' => $body_html,
        ':rec' => json_encode($recipients, JSON_UNESCAPED_UNICODE),
        ':n' => count($recipients),
    ]);
    $diffusion_id = (int)$pdo->lastInsertId();
} catch (Throwable $e) {
    $_SESSION['flash_error'] = 'Erreur DB : ' . $e->getMessage();
    header('Location: /mon-asso-ia-diffusion');
    exit;
}

// 2. Insère les destinataires en pending
try {
    $stRec = $pdo->prepare("INSERT INTO asso_ai_diffusion_recipients (diffusion_id, email, name, source, status, created_at) VALUES (:d, :e, :n, :s, 'pending', NOW())");
    foreach ($recipients as $r) {
        $stRec->execute([':d' => $diffusion_id, ':e' => $r['email'], ':n' => $r['name'], ':s' => $r['source']]);
    }
} catch (Throwable $e) { /* non bloquant */ }

// 3. Envoi (synchrone, simple)
@set_time_limit(120);
$sent_ok = 0;
$sent_ko = 0;
$last_error = null;

foreach ($recipients as $r) {
    $res = ak_ai_send_email_resend($r['email'], $r['name'], $subject, $body_html);
    if ($res['ok']) {
        $sent_ok++;
        try {
            $pdo->prepare("UPDATE asso_ai_diffusion_recipients SET status='sent', sent_at=NOW() WHERE diffusion_id=:d AND email=:e")
                ->execute([':d' => $diffusion_id, ':e' => $r['email']]);
        } catch (Throwable $e) {}
    } else {
        $sent_ko++;
        $last_error = $res['error'] ?? 'Erreur inconnue';
        try {
            $pdo->prepare("UPDATE asso_ai_diffusion_recipients SET status='failed', error_message=:err WHERE diffusion_id=:d AND email=:e")
                ->execute([':err' => mb_substr($last_error, 0, 500), ':d' => $diffusion_id, ':e' => $r['email']]);
        } catch (Throwable $e) {}
    }
    // Pause anti-rate-limit (Resend = 10 req/sec en free tier)
    usleep(120000); // 120ms
}

// 4. Update final
$final_status = 'sent';
if ($sent_ok === 0) $final_status = 'failed';
elseif ($sent_ko > 0) $final_status = 'partial';

try {
    $pdo->prepare("UPDATE asso_ai_diffusions SET status=:s, sent_count=:ok, failed_count=:ko, error_message=:err, sent_at=NOW() WHERE id=:id")
        ->execute([
            ':s' => $final_status,
            ':ok' => $sent_ok,
            ':ko' => $sent_ko,
            ':err' => $last_error,
            ':id' => $diffusion_id,
        ]);
} catch (Throwable $e) {}

$_SESSION['flash_success'] = "Diffusion envoyée : {$sent_ok} succès" . ($sent_ko ? " / {$sent_ko} échec(s)" : '') . '.';
header('Location: /mon-asso-ia-diffusion?sent=1');
exit;
