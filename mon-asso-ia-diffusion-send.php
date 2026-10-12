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
require_once __DIR__ . '/resend-helper.php';   // envoi, expéditeur (ak_comm_sender_options), désinscription (ak_optout_*)

/**
 * Retour vers la page Diffusion avec un message qu'elle affiche (clé de session dédiée :
 * flash_error / flash_success seraient affichés plus tard sur une autre page).
 * $type : error | warning | success. $keep_form : rien n'est parti, la page
 * reprend l'objet, le texte et la sélection saisis pour qu'on puisse corriger.
 */
function dif_back(string $type, string $message, string $query = '', bool $keep_form = false): void {
    $flash = ['type' => $type, 'message' => $message];
    if ($keep_form) {
        $flash['form'] = [
            'subject'       => mb_substr(trim((string)($_POST['subject'] ?? '')), 0, 255),
            'body_md'       => mb_substr(trim((string)($_POST['body_md'] ?? '')), 0, 100000),
            'manual_emails' => mb_substr((string)($_POST['manual_emails'] ?? ''), 0, 20000),
            'roles'         => array_values(array_filter(array_map('strval', (array)($_POST['roles'] ?? [])), 'strlen')),
            'project_ids'   => array_values(array_filter(array_map('intval', (array)($_POST['project_ids'] ?? [])))),
        ];
    }
    $_SESSION['flash_ia_diffusion'] = $flash;
    header('Location: /mon-asso-ia-diffusion' . $query);
    exit;
}

require_login();
$user = current_user();
$org_id  = (int)($user['org_id'] ?? 0);
$user_id = (int)($user['id'] ?? 0);
if ($org_id <= 0) { header('Location: /'); exit; }

// Envoi de masse : reserve aux admins/coordinateurs (comms officielles au nom de l'asso)
if (!in_array(($user['role'] ?? ''), ['admin', 'coordinator'], true)) {
    header('Location: /dashboard');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /mon-asso-ia-diffusion');
    exit;
}

if (!check_csrf($_POST['csrf_token'] ?? '')) {
    dif_back('error', 'Session expirée : rien n’a été envoyé. Rechargez la page puis réessayez.', '', true);
}

$subject     = trim((string)($_POST['subject'] ?? ''));
$body_md     = trim((string)($_POST['body_md'] ?? ''));
$gen_id      = (int)($_POST['generation_id'] ?? 0) ?: null;

if ($subject === '' || $body_md === '') {
    dif_back('error', 'L’objet et le corps de l’e-mail sont obligatoires.', '', true);
}

$sources = [
    'roles'       => $_POST['roles']       ?? [],
    'project_ids' => $_POST['project_ids'] ?? [],
    'manual'      => (string)($_POST['manual_emails'] ?? ''),
];

try {
    $recipients = ak_ai_collect_recipients($pdo, $org_id, $sources);
} catch (Throwable $e) {
    error_log('[ia-diffusion-send] destinataires (org ' . $org_id . ') : ' . $e->getMessage());
    dif_back('error', 'Impossible de préparer la liste des destinataires : rien n’a été envoyé. Réessayez dans un instant.', '', true);
}

if (empty($recipients)) {
    dif_back('error', 'Aucun destinataire valide : rien n’a été envoyé.', '', true);
}

// Les personnes (membres ou adresses saisies à la main) désinscrites des envois groupés
// de l'association ne sont plus contactées.
$optouts = ak_optout_emails($pdo, $org_id);
$nb_skipped_optout = 0;
if ($optouts) {
    $nb_before = count($recipients);
    $recipients = array_values(array_filter($recipients, fn($r) => !isset($optouts[strtolower(trim((string)$r['email']))])));
    $nb_skipped_optout = $nb_before - count($recipients);
    if (empty($recipients)) {
        dif_back('error', 'Aucun destinataire : toutes ces adresses se sont désinscrites des envois groupés de l’association.', '', true);
    }
}

if (count($recipients) > 200) {
    dif_back('error', 'Maximum 200 destinataires par envoi (limite de sécurité) : rien n’a été envoyé. Restreignez votre sélection.', '', true);
}

// Anti-relais : les adresses saisies à la main (hors membres de l'asso) sont limitées,
// et chaque association a un quota quotidien — sinon le domaine sert à envoyer du spam.
$manual_n = count(array_filter($recipients, fn($r) => ($r['source'] ?? '') === 'manual'));
if ($manual_n > 30) {
    dif_back('error', 'Maximum 30 adresses saisies à la main par envoi (celui-ci en compte ' . $manual_n . ') : rien n’a été envoyé. Ajoutez plutôt ces contacts comme membres ou clients.', '', true);
}
try {
    $q = $pdo->prepare("SELECT COALESCE(SUM(recipients_count), 0) FROM asso_ai_diffusions WHERE org_id = ? AND created_at > NOW() - INTERVAL 1 DAY");
    $q->execute([$org_id]);
    if ((int)$q->fetchColumn() + count($recipients) > 500) {
        dif_back('error', 'Quota atteint : 500 destinataires par 24 h et par association. Rien n’a été envoyé : réessayez demain ou contactez le support Assokit.', '', true);
    }
} catch (Throwable $e) {}

// Essai gratuit : le quota de 500 / 24 h couvre aussi les diffusions classiques,
// les invitations aux événements et les convocations d'AG.
require_once __DIR__ . '/trial-limits-helpers.php';
if ($trial_block = ak_trial_send_block($pdo, $org_id, count($recipients))) {
    dif_back('warning', $trial_block, '', true);
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
           . '{{AK_UNSUB}}'
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
        ':md' => $body_md, ':html' => str_replace('{{AK_UNSUB}}', '', $body_html),
        ':rec' => json_encode($recipients, JSON_UNESCAPED_UNICODE),
        ':n' => count($recipients),
    ]);
    $diffusion_id = (int)$pdo->lastInsertId();
} catch (Throwable $e) {
    error_log('[ia-diffusion-send] enregistrement (org ' . $org_id . ') : ' . $e->getMessage());
    dif_back('error', 'La diffusion n’a pas pu être enregistrée : rien n’a été envoyé. Réessayez dans un instant.', '', true);
}

// 2. Insère les destinataires en pending
try {
    $stRec = $pdo->prepare("INSERT INTO asso_ai_diffusion_recipients (diffusion_id, email, name, source, status, created_at) VALUES (:d, :e, :n, :s, 'pending', NOW())");
    foreach ($recipients as $r) {
        $stRec->execute([':d' => $diffusion_id, ':e' => $r['email'], ':n' => $r['name'], ':s' => $r['source']]);
    }
} catch (Throwable $e) { /* non bloquant */ }

// 3. Envoi (synchrone). Chaque destinataire est noté dès son envoi (lignes « pending » ci-dessus) ;
// l'envoi va au bout même si l'onglet est fermé, au rythme permis par Resend (option pace).
ignore_user_abort(true);
@set_time_limit(0);
$sent_ok = 0;
$sent_ko = 0;
$last_error = null;
// Au nom de l'association (adresse Assokit vérifiée), réponses vers son e-mail de contact
// (ou celui de l'auteur de l'envoi) plutôt que vers noreply@.
$sender = ak_comm_sender_options($pdo, $org_id, $user_id);
if (empty($sender['from_name'])) $sender['from_name'] = $org_name;

foreach ($recipients as $r) {
    // Lien de désinscription personnel en pied de l'e-mail
    $unsub = ak_optout_footer_html($org_id, $r['email'], $org_name);
    $html_r = str_replace('{{AK_UNSUB}}', $unsub !== '' ? '<br><br>' . $unsub : '', $body_html);
    try {
        $sr = send_transactional_email($r['email'], $subject, $html_r,
            $sender + ['tag' => 'ia_broadcast', 'headers' => ak_optout_headers($org_id, $r['email']), 'pace' => true]);
        $res = ['ok' => !empty($sr['success']), 'error' => $sr['error'] ?? null];
    } catch (Throwable $e) {
        $res = ['ok' => false, 'error' => $e->getMessage()];
    }
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

// Bilan affiché sur la page Diffusion, selon le résultat réel de l'envoi
$optout_note = $nb_skipped_optout ? " {$nb_skipped_optout} adresse(s) désinscrite(s) n’ont pas été contactées." : '';
if ($final_status === 'sent') {
    dif_back('success', "Diffusion envoyée à {$sent_ok} destinataire(s).{$optout_note}", '?sent=sent');
} elseif ($final_status === 'partial') {
    dif_back('warning', "Diffusion partiellement envoyée : {$sent_ok} e-mail(s) parti(s), {$sent_ko} en échec"
        . ($last_error ? ' (dernière erreur : ' . mb_substr((string)$last_error, 0, 160) . ')' : '') . ".{$optout_note}", '?sent=partial');
} else {
    dif_back('error', "La diffusion a échoué : aucun des {$sent_ko} e-mail(s) n’est parti"
        . ($last_error ? ' (erreur : ' . mb_substr((string)$last_error, 0, 160) . ')' : '') . '. Réessayez plus tard ou contactez le support Assokit.', '?sent=failed', true);
}
