<?php
/**
 * Support pour les agents mairie / collectivité
 * URL : /mairie-support
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes-layout.php';
require_once __DIR__ . '/includes-permissions.php';
require_login();

$current = current_user();
$parent_org = current_parent_org();

if (!$parent_org && !is_platform_admin($current)) {
    http_response_code(403);
    die('Accès réservé aux agents mairie/collectivité.');
}

// Envoi message support
$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['message'])) {
    if (!check_csrf($_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        die('Requête invalide (jeton CSRF manquant ou expiré).');
    }
    $subject = trim($_POST['subject'] ?? '');
    $message = trim($_POST['message'] ?? '');
    if ($subject && $message) {
        try {
            $pdo->prepare("INSERT INTO assokit_activity_log (user_id, user_email, event_type, event_action, event_target, event_meta, created_at) VALUES (?, ?, 'mairie_support', 'message_sent', ?, ?, NOW())")
                ->execute([$current['id'], $current['email'], $subject, json_encode(['parent_org_id' => $parent_org['id'] ?? null, 'message' => $message])]);
        } catch (Exception $e) {}

        // Envoi email simple si Resend dispo (silent fail)
        try {
            $to = 'contact@assokit.fr';
            // Expediteur fixe (evite l'injection d'en-tete via l'email utilisateur) ; reponse vers l'agent
            $reply = filter_var($current['email'] ?? '', FILTER_VALIDATE_EMAIL) ?: 'contact@assokit.fr';
            $headers = "From: Support Mairie <contact@assokit.fr>\r\nReply-To: " . $reply . "\r\nContent-Type: text/plain; charset=UTF-8\r\n";
            $body = "Mairie : " . ($parent_org['name'] ?? '—') . "\nAgent : " . $current['first_name'] . ' ' . $current['last_name'] . " (" . $current['email'] . ")\n\nObjet : $subject\n\n$message";
            @mail($to, '[Support Mairie] ' . $subject, $body, $headers);
        } catch (Exception $e) {}

        $flash = "✅ Votre message a bien été envoyé à l'équipe Assokit. Nous vous répondrons sous 24-48h.";
    }
}

render_head('Support · ' . ($parent_org['name'] ?? 'Mairie'));
render_sidebar('support');
?>

<div style="max-width:900px;margin:0 auto;padding:clamp(12px,3vw,24px);">

  <h1 style="margin:0 0 6px;font-size:24px;color:#0A0A0B;font-weight:700;">🛟 Support Assokit</h1>
  <p style="color:#71717A;font-size:13.5px;margin:0 0 24px;">L'équipe Assokit vous accompagne dans la gestion de votre <?= h($parent_org['type'] ?? 'collectivité') ?>.</p>

  <?php if ($flash): ?>
    <div style="background:#D1FAE5;border:1px solid #10B981;color:#065F46;padding:14px 18px;border-radius:8px;margin-bottom:20px;font-weight:600;"><?= h($flash) ?></div>
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
        <input type="text" name="subject" required maxlength="120" placeholder="Ex: Question sur la création de campagne emailing" style="width:100%;padding:11px 14px;border:1px solid #D4D4D8;border-radius:8px;font-size:13.5px;box-sizing:border-box;">
      </div>
      <div>
        <label style="display:block;font-size:12.5px;font-weight:600;color:#3F3F46;margin-bottom:6px;">Message *</label>
        <textarea name="message" required rows="6" placeholder="Décrivez votre besoin en détail..." style="width:100%;padding:11px 14px;border:1px solid #D4D4D8;border-radius:8px;font-size:13.5px;font-family:inherit;box-sizing:border-box;resize:vertical;"></textarea>
      </div>
      <div style="display:flex;justify-content:space-between;align-items:center;gap:14px;flex-wrap:wrap;">
        <div style="font-size:12px;color:#A1A1AA;">📧 Envoyé depuis : <strong style="color:#3F3F46;"><?= h($current['email']) ?></strong></div>
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
    <details style="padding:12px 0;">
      <summary style="font-weight:600;color:#0A0A0B;cursor:pointer;font-size:13.5px;">Quand seront disponibles les modules Emailing et Paramètres ?</summary>
      <p style="margin:10px 0 0;color:#3F3F46;font-size:13px;line-height:1.6;">Ces modules sont en développement. Sortie prévue T3 2026. Vous serez notifié dès leur disponibilité.</p>
    </details>
  </div>

</div>
