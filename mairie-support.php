<?php
/**
 * Support pour les agents mairie / collectivité
 * URL : /mairie-support
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes-layout.php';
require_once __DIR__ . '/includes-permissions.php';
require_once __DIR__ . '/includes-mairie-channels.php';
require_once __DIR__ . '/resend-helper.php';
require_once __DIR__ . '/sa-permissions.php';   // sa_notify_all_founders()
if (function_exists('ak_session_guard')) ak_session_guard(); // agent retiré depuis sa connexion : session fermée tout de suite
require_login();
require_parent_org_user(); // collectivité en attente, suspendue ou refusée : page d'explication

$current = current_user();
$parent_org = current_parent_org();

if (!$parent_org && !is_platform_admin($current)) {
    http_response_code(403);
    die('Accès réservé aux agents mairie/collectivité.');
}
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

// Envoi message support : journal + notification cockpit des fondateurs + e-mail
// (Resend, sinon mail()). Le succès n'est annoncé que si la demande a bien été
// enregistrée ou envoyée ; sinon l'agent garde son texte et l'adresse directe.
$flash = null;
$flash_error = null;
$form = ['subject' => '', 'message' => ''];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!check_csrf($_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        die('Requête invalide (jeton CSRF manquant ou expiré).');
    }
    $subject = mb_substr(trim(preg_replace('/[\r\n\t]+/', ' ', (string)($_POST['subject'] ?? ''))), 0, 120);
    $message = mb_substr(trim((string)($_POST['message'] ?? '')), 0, 10000);
    $form = ['subject' => $subject, 'message' => $message];
    if ($subject === '' || $message === '') {
        $flash_error = "L'objet et le message sont obligatoires.";
    } else {
        $mairie_name = $parent_org['name'] ?? 'Équipe plateforme';
        $agent_name = trim(($current['first_name'] ?? '') . ' ' . ($current['last_name'] ?? ''));
        $agent_email = (string)($current['email'] ?? '');

        // 1. Journal (lu dans l'activité fondateur)
        $stored = false;
        try {
            $pdo->prepare("INSERT INTO assokit_activity_log (user_id, user_email, event_type, event_action, event_target, event_meta, created_at) VALUES (?, ?, 'mairie_support', 'message_sent', ?, ?, NOW())")
                ->execute([$current['id'], $agent_email, $subject, json_encode(['parent_org_id' => $parent_org['id'] ?? null, 'message' => $message], JSON_UNESCAPED_UNICODE)]);
            $stored = true;
        } catch (Throwable $e) { error_log('[mairie-support] journal : ' . $e->getMessage()); }

        // 2. Notification dans le cockpit des fondateurs
        sa_notify_all_founders(
            'new_ticket',
            '🏛️ Support collectivité — ' . $subject,
            mb_substr($mairie_name . ' · ' . $agent_name . ' (' . $agent_email . ') : ' . $message, 0, 250),
            !empty($parent_org['id']) ? '/super-admin-mairie-detail?id=' . (int)$parent_org['id'] : '/super-admin-mairies'
        );

        // 3. E-mail à l'équipe : Resend, à défaut mail() ; réponse directe vers l'agent
        $reply = filter_var($agent_email, FILTER_VALIDATE_EMAIL) ?: null;
        $sent = false;
        try {
            $content = '<h1 style="font-size:20px;margin:0 0 14px;font-weight:500;color:#1C1917">🛟 Demande de support — collectivité</h1>'
                     . '<p><strong>Collectivité :</strong> ' . htmlspecialchars($mairie_name) . '<br>'
                     . '<strong>Agent :</strong> ' . htmlspecialchars($agent_name) . ' (' . htmlspecialchars($agent_email) . ')<br>'
                     . '<strong>Objet :</strong> ' . htmlspecialchars($subject) . '</p>'
                     . '<blockquote style="border-left:3px solid #059669;padding:12px 16px;margin:16px 0;background:#F0FDF4;color:#1C1917;border-radius:0 6px 6px 0;">'
                     . nl2br(htmlspecialchars($message)) . '</blockquote>'
                     . '<p style="font-size:12px;color:#78716C;">Répondez directement à cet e-mail pour écrire à l\'agent.</p>';
            $html = function_exists('email_wrap') ? email_wrap('Support collectivité', $content) : $content;
            $res = send_transactional_email('contact@assokit.fr', '[Support Mairie] ' . $subject, $html,
                array_filter(['reply_to' => $reply, 'tag' => 'mairie_support']));
            $sent = !empty($res['success']);
        } catch (Throwable $e) { error_log('[mairie-support] resend : ' . $e->getMessage()); }
        if (!$sent) {
            // Expéditeur fixe (évite l'injection d'en-tête via l'e-mail utilisateur) ; réponse vers l'agent
            $headers = "From: Support Mairie <contact@assokit.fr>\r\nReply-To: " . ($reply ?: 'contact@assokit.fr') . "\r\nContent-Type: text/plain; charset=UTF-8\r\n";
            $body = "Collectivité : $mairie_name\nAgent : $agent_name ($agent_email)\n\nObjet : $subject\n\n$message";
            $sent = (bool)@mail('contact@assokit.fr', '=?UTF-8?B?' . base64_encode('[Support Mairie] ' . $subject) . '?=', $body, $headers);
        }

        if ($sent || $stored) {
            $flash = "✅ Votre message a bien été transmis à l'équipe Assokit. Nous vous répondrons sous 24-48h.";
            $form = ['subject' => '', 'message' => ''];
            if (!$sent) error_log('[mairie-support] e-mail non parti, demande conservée dans le journal (user ' . (int)$current['id'] . ')');
        } else {
            $flash_error = "Votre message n'a pas pu être envoyé. Réessayez dans quelques minutes ou écrivez-nous directement à contact@assokit.fr.";
        }
    }
}

render_head('Support · ' . ($parent_org['name'] ?? 'Mairie'));
render_sidebar('support');
?>

<div class="main" style="max-width:900px;margin:0 auto;padding:clamp(12px,3vw,24px);">

  <h1 style="margin:0 0 6px;font-size:24px;color:#0A0A0B;font-weight:700;">🛟 Support Assokit</h1>
  <p style="color:#71717A;font-size:13.5px;margin:0 0 24px;">L'équipe Assokit vous accompagne dans la gestion de votre <?= h(ak_parent_org_type_label($parent_org['type'] ?? null, true)) ?>.</p>

  <?php if ($flash): ?>
    <div style="background:#D1FAE5;border:1px solid #10B981;color:#065F46;padding:14px 18px;border-radius:8px;margin-bottom:20px;font-weight:600;"><?= h($flash) ?></div>
  <?php endif; ?>
  <?php if ($flash_error): ?>
    <div role="alert" style="background:#FEE2E2;border:1px solid #DC2626;color:#991B1B;padding:14px 18px;border-radius:8px;margin-bottom:20px;font-weight:600;">❌ <?= h($flash_error) ?></div>
  <?php endif; ?>

  <!-- Contact rapide -->
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:14px;margin-bottom:24px;">
    <div style="background:#fff;border:1px solid #E5E7EB;border-radius:12px;padding:20px;">
      <div style="font-size:28px;margin-bottom:8px;">✉️</div>
      <div style="font-size:13.5px;font-weight:700;color:#0A0A0B;margin-bottom:4px;">Email</div>
      <a href="mailto:contact@assokit.fr" style="color:#059669;font-size:14px;font-weight:600;text-decoration:none;">contact@assokit.fr</a>
      <div style="font-size:12px;color:#71717A;margin-top:6px;">Réponse sous 24-48h ouvrées</div>
    </div>
    <div style="background:#fff;border:1px solid #E5E7EB;border-radius:12px;padding:20px;">
      <div style="font-size:28px;margin-bottom:8px;">📚</div>
      <div style="font-size:13.5px;font-weight:700;color:#0A0A0B;margin-bottom:4px;">Documentation</div>
      <a href="https://assokit.fr/contact" target="_blank" style="color:#059669;font-size:14px;font-weight:600;text-decoration:none;">Centre d'aide →</a>
      <div style="font-size:12px;color:#71717A;margin-top:6px;">Guides, tutos, FAQ</div>
    </div>
    <div style="background:#fff;border:1px solid #E5E7EB;border-radius:12px;padding:20px;">
      <div style="font-size:28px;margin-bottom:8px;">🚀</div>
      <div style="font-size:13.5px;font-weight:700;color:#0A0A0B;margin-bottom:4px;">Demande urgente</div>
      <div style="font-size:13px;color:#3F3F46;">Précisez "URGENT" dans l'objet de votre message</div>
    </div>
  </div>

  <!-- Formulaire contact -->
  <div style="background:#fff;border:1px solid #E5E7EB;border-radius:14px;padding:24px;">
    <h2 style="margin:0 0 6px;font-size:18px;color:#0A0A0B;font-weight:700;">📝 Nous contacter directement</h2>
    <p style="color:#71717A;font-size:13px;margin:0 0 18px;">Posez votre question, signalez un problème ou suggérez une amélioration.</p>

    <form method="POST" style="display:grid;gap:14px;">
      <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token'] ?? '') ?>">
      <div>
        <label style="display:block;font-size:12.5px;font-weight:600;color:#3F3F46;margin-bottom:6px;">Objet *</label>
        <input type="text" name="subject" required maxlength="120" value="<?= h($form['subject']) ?>" placeholder="Ex: Question sur la création de campagne emailing" style="width:100%;padding:11px 14px;border:1px solid #D4D4D8;border-radius:8px;font-size:13.5px;box-sizing:border-box;">
      </div>
      <div>
        <label style="display:block;font-size:12.5px;font-weight:600;color:#3F3F46;margin-bottom:6px;">Message *</label>
        <textarea name="message" required rows="6" placeholder="Décrivez votre besoin en détail..." style="width:100%;padding:11px 14px;border:1px solid #D4D4D8;border-radius:8px;font-size:13.5px;font-family:inherit;box-sizing:border-box;resize:vertical;"><?= h($form['message']) ?></textarea>
      </div>
      <div style="display:flex;justify-content:space-between;align-items:center;gap:14px;flex-wrap:wrap;">
        <div style="font-size:12px;color:#6B7280;">📧 Envoyé depuis : <strong style="color:#3F3F46;"><?= h($current['email']) ?></strong></div>
        <button type="submit" style="background:#0A0A0B;color:#fff;padding:11px 26px;border:none;border-radius:8px;font-size:14px;font-weight:600;cursor:pointer;">✉ Envoyer le message</button>
      </div>
    </form>
  </div>

  <!-- FAQ rapide -->
  <div style="background:#fff;border:1px solid #E5E7EB;border-radius:14px;padding:24px;margin-top:18px;">
    <h2 style="margin:0 0 14px;font-size:18px;color:#0A0A0B;font-weight:700;">❓ Questions fréquentes</h2>
    <details style="padding:12px 0;border-bottom:1px solid #F4F4F5;">
      <summary style="font-weight:600;color:#0A0A0B;cursor:pointer;font-size:13.5px;">Comment ajouter une nouvelle association à mon suivi ?</summary>
      <p style="margin:10px 0 0;color:#3F3F46;font-size:13px;line-height:1.6;">Contactez l'équipe Assokit (contact@assokit.fr) avec le nom de l'association et son SIRET. Nous procéderons à la liaison sous 24h.</p>
    </details>
    <details style="padding:12px 0;border-bottom:1px solid #F4F4F5;">
      <summary style="font-weight:600;color:#0A0A0B;cursor:pointer;font-size:13.5px;">Puis-je modifier les données d'une association suivie ?</summary>
      <p style="margin:10px 0 0;color:#3F3F46;font-size:13px;line-height:1.6;">Vous êtes en mode lecture seule. Les modifications doivent être faites par l'association elle-même. Vous pouvez les contacter via la messagerie Assokit.</p>
    </details>
    <details style="padding:12px 0;border-bottom:1px solid #F4F4F5;">
      <summary style="font-weight:600;color:#0A0A0B;cursor:pointer;font-size:13.5px;">Comment augmenter mon quota d'associations ?</summary>
      <p style="margin:10px 0 0;color:#3F3F46;font-size:13px;line-height:1.6;">Envoyez-nous un message en précisant le nouveau quota souhaité. Devis personnalisé sous 48h.</p>
    </details>
    <details style="padding:12px 0;border-bottom:1px solid #F4F4F5;">
      <summary style="font-weight:600;color:#0A0A0B;cursor:pointer;font-size:13.5px;">Comment écrire à toutes mes associations en une fois ?</summary>
      <p style="margin:10px 0 0;color:#3F3F46;font-size:13px;line-height:1.6;">Menu <a href="/mairie-emailing" style="color:#059669;">Emailing</a> : rédigez votre message puis choisissez « Tous les administrateurs » ou une sélection d'associations. L'historique de vos campagnes s'affiche en bas de la page.</p>
    </details>
    <details style="padding:12px 0;">
      <summary style="font-weight:600;color:#0A0A0B;cursor:pointer;font-size:13.5px;">Comment ajouter un collègue ou modifier les informations de la collectivité ?</summary>
      <p style="margin:10px 0 0;color:#3F3F46;font-size:13px;line-height:1.6;">Menu <a href="/mairie-parametres" style="color:#059669;">Paramètres</a> : les administrateurs de la collectivité y modifient le profil et ajoutent ou retirent des agents. Chaque agent peut y changer son mot de passe.</p>
    </details>
  </div>

</div>

<?php render_foot(); ?>
