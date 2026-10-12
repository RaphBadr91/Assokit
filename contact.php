<?php
/**
 * contact.php — Page Contact Assokit (PREMIUM v2 + OBJETS)
 * --------------------------------------------------------------
 * NOUVEAUTÉS v2 :
 * - Système d'objets pré-définis en cartes cliquables (8 objets)
 * - Champ texte libre qui apparaît si "Autre" est sélectionné
 * - Pré-remplissage URL : ?subject=demo / quote / support / partnership / press / billing / question / other
 * - Mobile : 2 colonnes, très compact
 * --------------------------------------------------------------
 */
require_once __DIR__ . '/includes-public.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/resend-helper.php';

$breadcrumb = build_breadcrumb_jsonld([
    ['name' => 'Accueil', 'url' => '/'],
    ['name' => 'Contact', 'url' => '/contact'],
]);

render_public_head([
    'title'       => 'Contact · On vous répond en moins de 24h',
    'description' => 'Une question, une démo, un partenariat ? Contactez l\'équipe Assokit. Réponse en moins de 24h, par de vrais humains.',
    'path'        => '/contact',
    'schema_jsonld' => [$breadcrumb],
]);

render_public_nav('contact');

// === Objets pré-définis ===
$subjects_list = [
    'demo'        => ['icon' => '📅', 'label' => 'Réserver une démo',          'full' => 'Demande de démo'],
    'quote'       => ['icon' => '💰', 'label' => 'Devis sur-mesure',           'full' => 'Demande de devis sur-mesure'],
    'question'    => ['icon' => '❓', 'label' => 'Question fonctionnalités',   'full' => 'Question sur les fonctionnalités'],
    'support'     => ['icon' => '🔧', 'label' => 'Support technique',          'full' => 'Support technique'],
    'partnership' => ['icon' => '🤝', 'label' => 'Partenariat',                'full' => 'Partenariat / Collaboration'],
    'press'       => ['icon' => '📰', 'label' => 'Presse / Média',             'full' => 'Demande presse / média'],
    'billing'     => ['icon' => '💼', 'label' => 'Facturation',                'full' => 'Question facturation / abonnement'],
    'other'       => ['icon' => '✏️', 'label' => 'Autre',                      'full' => 'Autre demande'],
];

// Longueurs maximales (contrôlées côté serveur, rappelées par maxlength dans le formulaire)
$ct_max = ['name' => 100, 'email' => 190, 'phone' => 30, 'subject_other' => 150, 'message' => 5000];

// Champ texte reçu en GET/POST (un tableau forgé compte comme vide)
$ct_str = static fn($v): string => is_string($v) ? trim($v) : '';

// === Pré-remplissage URL ===
$prefill_subject_key = $ct_str($_GET['subject'] ?? '');
// Le plan est recopié dans l'objet des e-mails, y compris l'accusé de réception
// envoyé à l'adresse saisie : seuls les plans connus sont acceptés, sous leur nom.
// Offre publique : Essai PRO, Assokit, Sur-mesure (Démarrage n'est plus proposée)
$ct_plans = ['pro-essai' => 'Essai PRO', 'assokit' => 'Assokit', 'sur-mesure' => 'Sur-mesure'];
$prefill_plan = $ct_str($_GET['plan'] ?? '');
if (!isset($ct_plans[$prefill_plan])) $prefill_plan = '';

$selected_subject_key = '';
if (isset($subjects_list[$prefill_subject_key])) {
    $selected_subject_key = $prefill_subject_key;
}

// Cas spécial : si plan précisé, on force "demo" ou "quote"
if ($prefill_plan === 'sur-mesure') {
    $selected_subject_key = 'quote';
} elseif ($prefill_plan && $selected_subject_key === '') {
    $selected_subject_key = 'demo';
}

// Récupérer la valeur depuis POST (en cas d'erreur de soumission)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['subject_key'])) {
    $posted_key = $ct_str($_POST['subject_key']);
    if (isset($subjects_list[$posted_key])) {
        $selected_subject_key = $posted_key;
    }
}

// === Traitement formulaire ===
$form_message = '';
$form_status = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Caractères de contrôle retirés des champs sur une ligne (ils sont repris dans l'objet des e-mails)
    $ct_line = static fn(string $s): string => trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $s) ?? '');
    $name = $ct_line($ct_str($_POST['name'] ?? ''));
    $email = $ct_str($_POST['email'] ?? '');
    $phone = $ct_line($ct_str($_POST['phone'] ?? ''));
    $subject_key = $ct_str($_POST['subject_key'] ?? '');
    $subject_other = $subject_key === 'other' ? $ct_line($ct_str($_POST['subject_other'] ?? '')) : '';
    // Retours à la ligne normalisés : le navigateur envoie \r\n, mais compte 1 caractère (maxlength)
    $message = str_replace("\r\n", "\n", $ct_str($_POST['message'] ?? ''));
    $consent = !empty($_POST['consent']);
    $hp_website = $ct_str($_POST['website'] ?? '');
    require_once __DIR__ . '/rate-limit-helper.php';

    // Premier champ trop long, pour le nommer dans l'erreur
    $ct_labels = ['name' => 'le nom', 'email' => 'l’e-mail', 'phone' => 'le téléphone', 'subject_other' => 'l’objet', 'message' => 'le message'];
    $too_long = '';
    foreach (compact('name', 'email', 'phone', 'subject_other', 'message') as $k => $v) {
        if (mb_strlen($v) > $ct_max[$k]) {
            $too_long = '⚠️ Texte trop long pour ' . $ct_labels[$k] . ' : ' . number_format($ct_max[$k], 0, ',', "\u{00A0}") . ' caractères au maximum.';
            break;
        }
    }

    // Construction de l'objet final
    if ($subject_key === 'other' && $subject_other !== '') {
        $subject_final = $subject_other;
    } elseif (isset($subjects_list[$subject_key])) {
        $subject_final = $subjects_list[$subject_key]['full'];
        if ($prefill_plan && in_array($subject_key, ['demo', 'quote'], true)) {
            $subject_final .= ' — Plan ' . $ct_plans[$prefill_plan];
        }
    } else {
        $subject_final = '';
    }

    if ($hp_website !== '') {
        // Honeypot rempli = bot : faux succès, aucun envoi
        $form_status = 'success';
        $form_message = 'Merci ! Votre message a bien été envoyé. On vous répond en moins de 24h.';
    } elseif (!isset($subjects_list[$subject_key])) {
        // Objet absent (cartes non cliquées) ou forgé
        $form_status = 'error';
        $form_message = '⚠️ Choisissez l’objet de votre demande (cartes en haut du formulaire).';
    } elseif ($subject_key === 'other' && $subject_other === '') {
        $form_status = 'error';
        $form_message = '⚠️ Veuillez préciser l\'objet de votre demande.';
    } elseif ($name === '' || $email === '' || $message === '' || !$consent) {
        $form_status = 'error';
        $form_message = '⚠️ Tous les champs marqués * sont obligatoires (nom, email, message, consentement).';
    } elseif ($too_long !== '') {
        $form_status = 'error';
        $form_message = $too_long;
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $form_status = 'error';
        $form_message = '⚠️ Votre email ne semble pas valide.';
    } elseif (!ak_rate_limit('contact', 5, 600)) {
        // Compté ici seulement : une erreur de saisie ne consomme pas le quota de l'IP
        $form_status = 'error';
        $form_message = '⚠️ Trop de messages envoyés. Merci de patienter quelques minutes avant de réessayer.';
    } else {
        // === Persistance en base : le message arrive dans l'app Fondateur + active le double-sens ===
        // (table asso_contact_messages créée par migration v45)
        $saved = false;
        try {
            if (isset($pdo) && $pdo instanceof PDO) {
                $name_parts = preg_split('/\s+/', $name, 2);
                $ct_first = $name_parts[0] ?? $name;
                $ct_last  = $name_parts[1] ?? '';
                $ct_msg   = $message . ($phone !== '' ? "\n\nTéléphone : " . $phone : '');
                $st = $pdo->prepare("INSERT INTO asso_contact_messages
                    (firstname, lastname, email, organization, type, subject, message, ip, user_agent, created_at)
                    VALUES (:f, :l, :e, :o, :t, :s, :m, :ip, :ua, NOW())");
                $st->execute([
                    ':f' => $ct_first, ':l' => $ct_last, ':e' => $email,
                    ':o' => '', ':t' => '', ':s' => $subject_final, ':m' => $ct_msg,
                    ':ip' => $_SERVER['REMOTE_ADDR'] ?? '',
                    ':ua' => mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500),
                ]);
                $saved = true;
            }
        } catch (Throwable $eDb) {
            error_log('[contact save] ' . $eDb->getMessage());
        }

        $notified = false;
        if (function_exists('send_transactional_email')) {
            // Ces deux envois ne vont qu'à l'équipe et à l'adresse saisie : une session de
            // démo ouverte dans ce navigateur ne doit pas les bloquer (voir demo-guard.php)
            require_once __DIR__ . '/demo-guard.php';
            ak_demo_public_form_mail(true);
            try {
                $category = $subjects_list[$subject_key]['icon'] . ' ' . $subjects_list[$subject_key]['label'];
                $body_html = '<h2>Nouveau message contact Assokit</h2>';
                $body_html .= '<p><strong>De :</strong> ' . htmlspecialchars($name) . ' (' . htmlspecialchars($email) . ')</p>';
                if ($phone) $body_html .= '<p><strong>Téléphone :</strong> ' . htmlspecialchars($phone) . '</p>';
                $body_html .= '<p><strong>Catégorie :</strong> ' . htmlspecialchars($category) . '</p>';
                $body_html .= '<p><strong>Objet :</strong> ' . htmlspecialchars($subject_final) . '</p>';
                $body_html .= '<p><strong>Message :</strong></p><p>' . nl2br(htmlspecialchars($message)) . '</p>';

                try {
                    $sent = send_transactional_email(
                        'contact@assokit.fr',
                        '[' . strtoupper($subject_key) . '] ' . $subject_final,
                        $body_html,
                        ['tag' => 'contact_form', 'reply_to' => $email]
                    );
                    $notified = !empty($sent['success']);
                } catch (Throwable $e1) {
                    error_log('[contact notify] ' . $e1->getMessage());
                }

                // === Email de confirmation au visiteur ===
                // Il part vers l'adresse saisie, quelle qu'elle soit : aucun texte libre du
                // visiteur n'y est recopié (objet « Autre » exclu), sauf un prénom court
                // sans lien ni adresse, pour qu'il ne serve pas de relais de spam.
                $ack_first = mb_substr((string) (preg_split('/\s+/', $name)[0] ?? ''), 0, 40);
                if (preg_match('~[/:@<>]|www\.|\.[a-z]{2,}~i', $ack_first)) $ack_first = '';
                $ack_name = $ack_first !== '' ? ' ' . htmlspecialchars($ack_first) : '';
                $confirm_html = '<div style="font-family:system-ui,sans-serif;max-width:600px;margin:0 auto;padding:30px 20px;color:#0F172A;">';
                $confirm_html .= '<div style="background:linear-gradient(135deg,#059669,#047857);color:white;padding:36px 28px;border-radius:16px 16px 0 0;text-align:center;">';
                $confirm_html .= '<div style="font-size:48px;margin-bottom:10px;">🌿</div>';
                $confirm_html .= '<h1 style="margin:0;font-size:24px;font-weight:700;">Merci' . $ack_name . ' !</h1>';
                $confirm_html .= '<p style="margin:8px 0 0;opacity:0.95;font-size:15px;">Votre message a bien été reçu</p>';
                $confirm_html .= '</div>';
                $confirm_html .= '<div style="background:white;padding:32px 28px;border:1px solid #E2E8F0;border-top:none;border-radius:0 0 16px 16px;">';
                $confirm_html .= '<p style="margin:0 0 18px;line-height:1.6;font-size:15px;">Bonjour' . $ack_name . ',</p>';
                $confirm_html .= '<p style="margin:0 0 18px;line-height:1.6;font-size:15px;">Nous avons bien reçu votre demande sur Assokit et nous vous remercions pour votre intérêt.</p>';
                $confirm_html .= '<div style="background:#F0FDF4;border:1px solid #A7F3D0;border-radius:10px;padding:16px 18px;margin:0 0 22px;">';
                $confirm_html .= '<div style="font-size:11px;color:#047857;text-transform:uppercase;letter-spacing:0.06em;font-weight:700;margin-bottom:6px;">📋 Récapitulatif de votre demande</div>';
                $confirm_html .= '<div style="font-size:14px;color:#0F172A;line-height:1.6;"><strong>Catégorie :</strong> ' . htmlspecialchars($category) . '</div>';
                if ($subject_key !== 'other') {
                    $confirm_html .= '<div style="font-size:14px;color:#0F172A;line-height:1.6;"><strong>Objet :</strong> ' . htmlspecialchars($subject_final) . '</div>';
                }
                $confirm_html .= '</div>';
                $confirm_html .= '<p style="margin:0 0 18px;line-height:1.6;font-size:15px;"><strong>Notre équipe humaine</strong> vous répondra <strong>en moins de 24h ouvrées</strong>. Pas de bot, pas de FAQ qui tourne en rond — juste de vraies réponses, par de vraies personnes 🌿</p>';
                $confirm_html .= '<p style="margin:0 0 24px;line-height:1.6;font-size:14px;color:#475569;">En attendant, n\'hésitez pas à explorer nos <a href="https://assokit.fr/fonctionnalites" style="color:#047857;font-weight:600;">fonctionnalités</a> ou consulter nos <a href="https://assokit.fr/tarifs" style="color:#047857;font-weight:600;">tarifs</a>.</p>';
                $confirm_html .= '<hr style="border:none;border-top:1px solid #E2E8F0;margin:24px 0;">';
                $confirm_html .= '<p style="margin:0;font-size:13px;color:#64748B;line-height:1.5;">À très vite,<br><strong style="color:#0F172A;">L\'équipe Assokit</strong> · Édité par RBPS · Évry, France 🇫🇷</p>';
                $confirm_html .= '</div>';
                $confirm_html .= '<p style="text-align:center;font-size:12px;color:#64748B;margin:18px 0 0;">Vous recevez cet email suite à votre demande sur <a href="https://assokit.fr" style="color:#64748B;">assokit.fr</a></p>';
                $confirm_html .= '</div>';

                try {
                    send_transactional_email(
                        $email,
                        '🌿 Merci pour votre message — L\'équipe Assokit',
                        $confirm_html,
                        ['tag' => 'contact_confirmation']
                    );
                } catch (Throwable $e2) {
                    error_log('[contact confirm] ' . $e2->getMessage());
                    // On ne fait pas échouer pour autant
                }
            } finally {
                ak_demo_public_form_mail(false);
            }
        }

        if ($saved || $notified) {
            $form_status = 'success';
            $form_message = 'Merci ! Votre message a bien été envoyé. On vous répond en moins de 24h.';
            // Sauvegarder l'email pour l'afficher dans le succès
            $sent_email = $email;
        } else {
            // Ni enregistré ni transmis à l'équipe : ne pas annoncer un envoi qui n'a pas eu lieu
            $form_status = 'error';
            $form_message = '⚠️ Une erreur est survenue. Réessayez ou écrivez-nous directement à contact@assokit.fr';
        }
    }
}
?>

<!-- ============================================================ -->
<!-- HERO contact -->
<!-- ============================================================ -->
<section class="pub-hero" style="padding: 60px 0 30px;">
  <div class="pub-container">
    <div class="pub-breadcrumb">
      <a href="/">Accueil</a><span class="pub-breadcrumb-sep">›</span>
      <strong style="color:var(--c-encre);">Contact</strong>
    </div>
    <span class="pub-eyebrow">💬 On vous répond toujours</span>
    <h1 class="pub-h1" style="max-width:780px;">Une équipe humaine, <em>derrière l'écran</em>.</h1>
    <p class="pub-tagline" style="max-width:680px;">
      Démo, question, partenariat, devis sur-mesure… <strong>Pas de bot, pas de FAQ qui tourne en rond.</strong> On vous répond personnellement, en moins de 24h.
    </p>
  </div>
</section>

<!-- ============================================================ -->
<!-- FORMULAIRE PREMIUM AVEC OBJETS -->
<!-- ============================================================ -->
<section class="pub-section" style="padding-top:30px;">
  <div class="pub-container">
    <div class="contact-grid">

      <!-- ================ FORMULAIRE ================ -->
      <div>
        <?php if ($form_status === 'success'): ?>
          <div class="contact-success">
            <div class="contact-success-icon">✨</div>
            <h2>Message envoyé avec succès !</h2>
            <p><?= htmlspecialchars($form_message) ?></p>
            <p class="contact-success-email">Vous recevrez une réponse à <strong><?= htmlspecialchars($sent_email ?? '') ?></strong> en moins de 24h.</p>
            <a href="/" class="pub-btn pub-btn-primary" style="margin-top:18px;">← Retour à l'accueil</a>
          </div>
        <?php else: ?>
          <form method="post" class="contact-form" novalidate id="contact-form">
            <div style="position:absolute;left:-9999px;top:-9999px;height:0;overflow:hidden;" aria-hidden="true">
              <label>Laissez ce champ vide<input type="text" name="website" tabindex="-1" autocomplete="off"></label>
            </div>
            <h2 class="contact-form-title">Écrivez-nous</h2>
            <p class="contact-form-subtitle">Décrivez votre besoin, nous reviendrons rapidement vers vous.</p>
            <?php if ($prefill_plan === 'sur-mesure'): ?>
              <p class="contact-form-subtitle" style="background:#ECFDF5;border:1px solid #A7F3D0;border-radius:10px;padding:10px 14px;color:#065F46;">
                <strong>Offre Sur-mesure</strong> : réseaux, fédérations, grandes structures, collectivités. Indiquez votre téléphone et quelques mots sur votre structure : nous vous rappelons pour construire votre devis.
              </p>
            <?php endif; ?>

            <?php if ($form_status === 'error'): ?>
              <div class="contact-error"><?= $form_message ?></div>
            <?php endif; ?>

            <!-- ============ OBJETS PRÉ-DÉFINIS ============ -->
            <div class="contact-field">
              <label class="contact-label">Quel est l'objet de votre demande ? <span class="contact-req">*</span></label>
              <div class="contact-subjects-grid" id="subjects-grid">
                <?php foreach ($subjects_list as $key => $subj):
                  $is_selected = ($selected_subject_key === $key);
                ?>
                  <button type="button"
                          class="contact-subject-card <?= $is_selected ? 'selected' : '' ?>"
                          data-key="<?= htmlspecialchars($key) ?>"
                          data-label="<?= htmlspecialchars($subj['full']) ?>"
                          aria-pressed="<?= $is_selected ? 'true' : 'false' ?>">
                    <span class="contact-subject-icon"><?= $subj['icon'] ?></span>
                    <span class="contact-subject-label"><?= htmlspecialchars($subj['label']) ?></span>
                  </button>
                <?php endforeach; ?>
              </div>
              <input type="hidden" name="subject_key" id="subject_key_input" value="<?= htmlspecialchars($selected_subject_key) ?>">
            </div>

            <!-- Champ "Autre" qui apparaît si Other sélectionné -->
            <div class="contact-field contact-field-other" id="contact-field-other" style="<?= $selected_subject_key === 'other' ? 'display:block;' : 'display:none;' ?>">
              <label for="ct-subject-other" class="contact-label">Précisez l'objet <span class="contact-req">*</span></label>
              <input type="text" id="ct-subject-other" name="subject_other"
                     class="contact-input"
                     placeholder="Décrivez en quelques mots l'objet de votre demande..."
                     maxlength="<?= $ct_max['subject_other'] ?>"
                     value="<?= htmlspecialchars($ct_str($_POST['subject_other'] ?? '')) ?>">
            </div>

            <!-- ============ NOM + EMAIL ============ -->
            <div class="contact-row contact-row-2">
              <div class="contact-field">
                <label for="ct-name" class="contact-label">Nom complet <span class="contact-req">*</span></label>
                <input type="text" id="ct-name" name="name" required
                       class="contact-input"
                       placeholder="Marie Dupont"
                       maxlength="<?= $ct_max['name'] ?>"
                       value="<?= htmlspecialchars($ct_str($_POST['name'] ?? '')) ?>">
              </div>
              <div class="contact-field">
                <label for="ct-email" class="contact-label">Email <span class="contact-req">*</span></label>
                <input type="email" id="ct-email" name="email" required
                       class="contact-input"
                       placeholder="marie@exemple.fr"
                       maxlength="<?= $ct_max['email'] ?>"
                       value="<?= htmlspecialchars($ct_str($_POST['email'] ?? '')) ?>">
              </div>
            </div>

            <!-- ============ TÉLÉPHONE ============ -->
            <div class="contact-field">
              <label for="ct-phone" class="contact-label">Téléphone <span class="contact-optional">(optionnel)</span></label>
              <input type="tel" id="ct-phone" name="phone"
                     class="contact-input"
                     placeholder="06 12 34 56 78"
                     maxlength="<?= $ct_max['phone'] ?>"
                     value="<?= htmlspecialchars($ct_str($_POST['phone'] ?? '')) ?>">
            </div>

            <!-- ============ MESSAGE ============ -->
            <div class="contact-field">
              <label for="ct-message" class="contact-label">Votre message <span class="contact-req">*</span></label>
              <textarea id="ct-message" name="message" required rows="6"
                        class="contact-textarea"
                        maxlength="<?= $ct_max['message'] ?>"
                        placeholder="Parlez-nous de votre association ou de votre TPE, vos besoins, vos objectifs..."><?= htmlspecialchars($ct_str($_POST['message'] ?? '')) ?></textarea>
            </div>

            <!-- Checkbox RGPD -->
            <label class="contact-consent">
              <input type="checkbox" name="consent" required <?= !empty($_POST['consent']) ? 'checked' : '' ?>>
              <span>
                J'accepte que mes informations soient utilisées par <strong>RBPS</strong> (éditeur d'Assokit, basé à Évry) pour traiter ma demande, conformément à la <a href="/confidentialite">politique de confidentialité</a>. Mes données ne sont jamais revendues.
                <span class="contact-req">*</span>
              </span>
            </label>

            <button type="submit" class="contact-submit">
              Envoyer mon message
              <span class="contact-submit-arrow">→</span>
            </button>

            <p class="contact-form-footer">
              <span>⚡</span> Réponse en moins de 24h ouvrées · Pas de bot
            </p>
          </form>
        <?php endif; ?>
      </div>

      <!-- ================ SIDEBAR INFOS ================ -->
      <aside class="contact-sidebar">
        <div class="contact-info-card contact-info-primary">
          <h3>📍 Nos coordonnées</h3>

          <div class="contact-info-row">
            <div class="contact-info-label">Email</div>
            <a href="mailto:contact@assokit.fr" class="contact-info-link">contact@assokit.fr</a>
          </div>

          <div class="contact-info-row">
            <div class="contact-info-label">Téléphone</div>
            <a href="tel:+33756897336" class="contact-info-link">+33 7 56 89 73 36</a>
            <div class="contact-info-sub">Lun-Ven · 9h-18h</div>
          </div>

          <div class="contact-info-row">
            <div class="contact-info-label">Délai de réponse</div>
            <div class="contact-info-value">⚡ Moins de 24h ouvrées</div>
          </div>

          <div class="contact-info-row">
            <div class="contact-info-label">Société éditrice</div>
            <?php $legal = pub_legal_identity(); // paramètres société (super-admin) ?>
            <div class="contact-info-value contact-info-rbps"><?= htmlspecialchars($legal['name']) ?></div>
            <div class="contact-info-sub"><?= htmlspecialchars($legal['city'] ?: 'Évry') ?> · France 🇫🇷<br><?= $legal['siren'] !== '' ? 'SIREN ' . htmlspecialchars($legal['siren']) : 'En cours d\'immatriculation' ?></div>
          </div>

          <hr class="contact-info-divider">

          <p class="contact-info-tagline">
            🌿 <strong>L'équipe Assokit</strong> répond personnellement à chaque message. Vraie équipe française, vraies réponses.
          </p>
        </div>

        <div class="contact-info-card contact-info-cta">
          <h3>📅 Plutôt une démo ?</h3>
          <p>30 minutes en visio pour découvrir Assokit en direct, sur vos vrais cas d'usage.</p>
          <button type="button" class="pub-btn pub-btn-primary contact-cta-btn" onclick="selectSubjectByKey('demo')">Réserver une démo</button>
        </div>
      </aside>

    </div>
  </div>
</section>

<!-- ============================================================ -->
<!-- JS pour gérer la sélection des objets -->
<!-- ============================================================ -->
<script>
(function() {
  const grid = document.getElementById('subjects-grid');
  const hiddenInput = document.getElementById('subject_key_input');
  const otherField = document.getElementById('contact-field-other');
  const otherInput = document.getElementById('ct-subject-other');

  if (!grid) return;

  function selectCard(card) {
    // Désélectionner toutes les autres
    grid.querySelectorAll('.contact-subject-card').forEach(c => {
      c.classList.remove('selected');
      c.setAttribute('aria-pressed', 'false');
    });
    // Sélectionner celle cliquée
    card.classList.add('selected');
    card.setAttribute('aria-pressed', 'true');
    const key = card.getAttribute('data-key');
    hiddenInput.value = key;
    const hint = document.getElementById('contact-subject-hint');
    if (hint) hint.remove();

    // Afficher/masquer le champ "Autre"
    if (key === 'other') {
      otherField.style.display = 'block';
      setTimeout(() => otherInput.focus(), 100);
    } else {
      otherField.style.display = 'none';
    }
  }

  // Click handlers sur chaque carte
  grid.querySelectorAll('.contact-subject-card').forEach(card => {
    card.addEventListener('click', () => selectCard(card));
  });

  // Objet obligatoire : les cartes ne ressemblent pas à des champs, on le rappelle
  // avant l'envoi plutôt que de recharger la page pour une erreur
  const form = document.getElementById('contact-form');
  if (form) form.addEventListener('submit', (e) => {
    if (hiddenInput.value) return;
    e.preventDefault();
    if (!document.getElementById('contact-subject-hint')) {
      const hint = document.createElement('div');
      hint.id = 'contact-subject-hint';
      hint.className = 'contact-error';
      hint.setAttribute('role', 'alert');
      hint.textContent = '⚠️ Choisissez l’objet de votre demande en cliquant sur une carte.';
      grid.parentNode.insertBefore(hint, grid);
    }
    grid.scrollIntoView({ behavior: 'smooth', block: 'center' });
  });

  // Permettre la sélection via une fonction globale (depuis CTA)
  window.selectSubjectByKey = function(key) {
    const card = grid.querySelector(`[data-key="${key}"]`);
    if (card) {
      selectCard(card);
      // Scroll vers le formulaire
      document.getElementById('contact-form').scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
  };
})();
</script>

<!-- ============================================================ -->
<!-- STYLES PREMIUM -->
<!-- ============================================================ -->
<style>
  /* ===== Grille principale ===== */
  .contact-grid {
    display: grid;
    grid-template-columns: 1.4fr 1fr;
    gap: 32px;
    align-items: start;
  }

  /* ===== FORMULAIRE ===== */
  .contact-form {
    background: white;
    border: 1px solid var(--c-border, #E2E8F0);
    border-radius: 18px;
    padding: 36px;
    box-shadow: 0 4px 14px rgba(15, 23, 42, 0.04);
  }

  .contact-form-title {
    margin: 0 0 6px;
    font-size: 24px;
    font-weight: 700;
    color: var(--c-encre, #0F172A);
    letter-spacing: -0.02em;
  }

  .contact-form-subtitle {
    margin: 0 0 28px;
    font-size: 14px;
    color: var(--c-text-2, #475569);
    line-height: 1.5;
  }

  .contact-row-2 {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;
    margin-bottom: 0;
  }

  .contact-field {
    margin-bottom: 18px;
  }

  /* ===== OBJETS — CARTES VISUELLES ===== */
  .contact-subjects-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 10px;
    margin-top: 4px;
  }

  .contact-subject-card {
    appearance: none;
    -webkit-appearance: none;
    background: white;
    border: 1.5px solid var(--c-border, #E2E8F0);
    border-radius: 12px;
    padding: 16px 10px;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 8px;
    cursor: pointer;
    font-family: 'Geist', system-ui, sans-serif;
    transition: all 0.18s ease;
    text-align: center;
    min-height: 96px;
  }

  .contact-subject-card:hover {
    border-color: #A7F3D0;
    background: #F0FDF4;
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(5, 150, 105, 0.08);
  }

  .contact-subject-card.selected {
    border-color: var(--c-emeraude, #059669);
    background: linear-gradient(135deg, #F0FDF4 0%, #D1FAE5 100%);
    box-shadow: 0 4px 14px rgba(5, 150, 105, 0.15);
    transform: translateY(-1px);
  }

  .contact-subject-card.selected::after {
    content: "✓";
    position: absolute;
    top: 6px;
    right: 8px;
    color: var(--c-emeraude, #059669);
    font-weight: 700;
    font-size: 13px;
  }

  .contact-subject-card {
    position: relative;
  }

  .contact-subject-icon {
    font-size: 24px;
    line-height: 1;
  }

  .contact-subject-label {
    font-size: 12px;
    font-weight: 600;
    color: var(--c-encre, #0F172A);
    line-height: 1.25;
  }

  .contact-subject-card.selected .contact-subject-label {
    color: var(--c-emeraude-dark, #047857);
  }

  /* Animation slide-down pour le champ "Autre" */
  .contact-field-other {
    animation: slideDown 0.3s ease-out;
  }

  @keyframes slideDown {
    from {
      opacity: 0;
      max-height: 0;
      transform: translateY(-8px);
    }
    to {
      opacity: 1;
      max-height: 200px;
      transform: translateY(0);
    }
  }

  /* ===== Labels & inputs ===== */
  .contact-label {
    display: block;
    font-size: 13px;
    font-weight: 600;
    color: var(--c-encre, #0F172A);
    margin-bottom: 7px;
    letter-spacing: 0.01em;
  }
  .contact-req { color: #DC2626; font-weight: 700; }
  .contact-optional {
    color: #64748B;
    font-weight: 400;
    font-size: 12px;
    font-style: italic;
  }

  .contact-input {
    width: 100%;
    height: 48px;
    padding: 0 16px;
    background: white;
    border: 1.5px solid var(--c-border, #E2E8F0);
    border-radius: 10px;
    font-family: 'Geist', system-ui, -apple-system, sans-serif;
    font-size: 16px; /* 16px minimum : en dessous, Safari iOS zoome au focus */
    color: var(--c-encre, #0F172A);
    transition: all 0.2s ease;
    box-sizing: border-box;
    appearance: none;
    -webkit-appearance: none;
  }
  .contact-input::placeholder { color: #64748B; font-weight: 400; }
  .contact-input:hover { border-color: #CBD5E1; }
  .contact-input:focus {
    outline: none;
    border-color: var(--c-emeraude, #059669);
    box-shadow: 0 0 0 4px rgba(5, 150, 105, 0.10);
  }

  .contact-textarea {
    width: 100%;
    min-height: 160px;
    padding: 14px 16px;
    background: white;
    border: 1.5px solid var(--c-border, #E2E8F0);
    border-radius: 10px;
    font-family: 'Geist', system-ui, -apple-system, sans-serif;
    font-size: 16px; /* idem .contact-input */
    line-height: 1.6;
    color: var(--c-encre, #0F172A);
    resize: vertical;
    transition: all 0.2s ease;
    box-sizing: border-box;
    appearance: none;
    -webkit-appearance: none;
  }
  .contact-textarea::placeholder { color: #64748B; font-weight: 400; }
  .contact-textarea:hover { border-color: #CBD5E1; }
  .contact-textarea:focus {
    outline: none;
    border-color: var(--c-emeraude, #059669);
    box-shadow: 0 0 0 4px rgba(5, 150, 105, 0.10);
  }

  /* ===== Checkbox RGPD ===== */
  .contact-consent {
    display: flex;
    align-items: flex-start;
    gap: 12px;
    padding: 16px 18px;
    background: var(--c-creme, #FAF8F5);
    border: 1px solid var(--c-border-soft, #E2E8F0);
    border-radius: 12px;
    font-size: 13px;
    line-height: 1.55;
    color: var(--c-text-2, #475569);
    cursor: pointer;
    margin: 6px 0 24px;
    transition: all 0.2s ease;
  }
  .contact-consent:hover {
    background: #F0FDF4;
    border-color: #A7F3D0;
  }
  .contact-consent input[type="checkbox"] {
    flex-shrink: 0;
    width: 18px;
    height: 18px;
    margin-top: 2px;
    cursor: pointer;
    accent-color: var(--c-emeraude, #059669);
  }
  .contact-consent span {
    flex: 1 1 auto;
    min-width: 0;
    word-break: break-word;
    overflow-wrap: break-word;
  }
  .contact-consent strong {
    color: var(--c-encre, #0F172A);
    font-weight: 700;
  }
  .contact-consent a {
    color: var(--c-emeraude-dark, #047857);
    text-decoration: underline;
    text-decoration-thickness: 1.5px;
    text-underline-offset: 2px;
  }

  /* ===== Bouton submit ===== */
  .contact-submit {
    width: 100%;
    height: 56px;
    background: linear-gradient(180deg, #059669 0%, #047857 100%);
    color: white;
    border: none;
    border-radius: 12px;
    font-family: 'Geist', system-ui, sans-serif;
    font-size: 16px;
    font-weight: 600;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
    transition: all 0.2s ease;
    box-shadow: 0 4px 12px rgba(5, 150, 105, 0.25);
    letter-spacing: -0.01em;
  }
  .contact-submit:hover {
    transform: translateY(-1px);
    box-shadow: 0 8px 20px rgba(5, 150, 105, 0.35);
  }
  .contact-submit:active { transform: translateY(0); }
  .contact-submit-arrow {
    font-size: 18px;
    transition: transform 0.2s ease;
  }
  .contact-submit:hover .contact-submit-arrow { transform: translateX(4px); }

  .contact-form-footer {
    margin: 16px 0 0;
    text-align: center;
    color: var(--c-text-3, #94A3B8);
    font-size: 13px;
    line-height: 1.5;
  }
  .contact-form-footer span { color: var(--c-emeraude, #059669); }

  .contact-error {
    background: #FEE2E2;
    border: 1px solid #FCA5A5;
    color: #991B1B;
    padding: 12px 16px;
    border-radius: 10px;
    margin-bottom: 22px;
    font-size: 13.5px;
    line-height: 1.5;
  }

  .contact-success {
    background: linear-gradient(135deg, #D1FAE5 0%, white 100%);
    border: 2px solid var(--c-emeraude, #059669);
    border-radius: 18px;
    padding: 48px 36px;
    text-align: center;
    box-shadow: 0 12px 30px rgba(5, 150, 105, 0.10);
  }
  .contact-success-icon { font-size: 56px; margin-bottom: 16px; }
  .contact-success h2 {
    margin: 0 0 12px;
    color: var(--c-encre, #0F172A);
    font-size: 24px;
    font-weight: 700;
  }
  .contact-success p {
    margin: 0 0 12px;
    color: var(--c-text-2, #475569);
    line-height: 1.6;
    font-size: 15px;
  }
  .contact-success-email {
    color: var(--c-emeraude-dark, #047857) !important;
    font-size: 14px !important;
  }

  /* ===== SIDEBAR ===== */
  .contact-sidebar {
    display: flex;
    flex-direction: column;
    gap: 16px;
    position: sticky;
    top: 100px;
  }

  .contact-info-card {
    background: white;
    border: 1px solid var(--c-border, #E2E8F0);
    border-radius: 16px;
    padding: 26px;
  }

  .contact-info-card h3 {
    margin: 0 0 18px;
    font-size: 16px;
    font-weight: 700;
    color: var(--c-encre, #0F172A);
    letter-spacing: -0.01em;
  }

  .contact-info-primary {
    background: linear-gradient(135deg, #FAF8F5 0%, #F0FDF4 100%);
    border-color: #A7F3D0;
  }

  .contact-info-row { margin-bottom: 16px; }
  .contact-info-row:last-of-type { margin-bottom: 0; }

  .contact-info-label {
    font-size: 11px;
    text-transform: uppercase;
    color: var(--c-text-3, #94A3B8);
    letter-spacing: 0.06em;
    margin-bottom: 4px;
    font-weight: 700;
  }

  .contact-info-link {
    color: var(--c-emeraude-dark, #047857);
    font-weight: 600;
    text-decoration: none;
    font-size: 14.5px;
    word-break: break-all;
  }
  .contact-info-link:hover { text-decoration: underline; }

  .contact-info-value {
    color: var(--c-encre, #0F172A);
    font-weight: 600;
    font-size: 14.5px;
  }
  .contact-info-rbps {
    font-size: 17px;
    color: var(--c-emeraude-dark, #047857);
    font-weight: 700;
    letter-spacing: 0.02em;
  }
  .contact-info-sub {
    color: var(--c-text-2, #475569);
    font-size: 13px;
    margin-top: 2px;
    line-height: 1.5;
  }

  .contact-info-divider {
    border: none;
    border-top: 1px solid var(--c-border-soft, #E2E8F0);
    margin: 20px 0;
  }

  .contact-info-tagline {
    margin: 0;
    font-size: 13.5px;
    color: var(--c-text-2, #475569);
    line-height: 1.6;
  }
  .contact-info-tagline strong { color: var(--c-encre, #0F172A); font-weight: 700; }

  .contact-info-cta { background: white; }
  .contact-info-cta p {
    margin: 0 0 16px;
    color: var(--c-text-2, #475569);
    font-size: 14px;
    line-height: 1.55;
  }
  .contact-cta-btn {
    width: 100%;
    text-align: center;
    justify-content: center;
  }

  /* ============================================================ */
  /* RESPONSIVE */
  /* ============================================================ */

  /* Tablette : 4 -> 4 colonnes objets, layout passe en 1 colonne */
  @media (max-width: 980px) {
    .contact-grid {
      grid-template-columns: 1fr;
      gap: 24px;
    }
    .contact-sidebar { position: static; }
    .contact-form { padding: 28px 24px; }
  }

  /* Tablette portrait : 2 colonnes objets */
  @media (max-width: 680px) {
    .contact-subjects-grid {
      grid-template-columns: repeat(2, 1fr);
      gap: 8px;
    }
    .contact-subject-card {
      padding: 14px 8px;
      min-height: 88px;
    }
    .contact-subject-icon { font-size: 22px; }
    .contact-subject-label { font-size: 11.5px; }
  }

  /* Mobile */
  @media (max-width: 600px) {
    .contact-form {
      padding: 24px 20px;
      border-radius: 14px;
    }
    .contact-form-title { font-size: 22px; }
    .contact-form-subtitle {
      font-size: 13.5px;
      margin-bottom: 22px;
    }
    .contact-row-2 {
      grid-template-columns: 1fr;
      gap: 0;
    }
    .contact-input {
      height: 46px;
      font-size: 16px;
    }
    .contact-textarea {
      min-height: 130px;
      font-size: 16px;
    }
    .contact-consent {
      padding: 14px;
      font-size: 12.5px;
      line-height: 1.55;
    }
    .contact-submit {
      height: 52px;
      font-size: 15px;
    }
    .contact-info-card { padding: 22px 18px; }
    .contact-success { padding: 36px 24px; }
    .contact-success-icon { font-size: 48px; }
    .contact-success h2 { font-size: 20px; }
  }

  /* Très petit mobile */
  @media (max-width: 380px) {
    .contact-form { padding: 20px 16px; }
    .contact-subject-card {
      padding: 12px 6px;
      min-height: 80px;
    }
    .contact-subject-icon { font-size: 20px; }
    .contact-subject-label { font-size: 11px; }
    .contact-consent {
      padding: 12px;
      font-size: 12px;
      gap: 10px;
    }
  }
</style>

<!-- MARQUE DEPOSEE (ajout) -->
<section class="pub-section" style="padding:50px 0 70px;">
  <div class="pub-container" style="max-width:760px;">
    <div style="display:flex;align-items:center;gap:18px;padding:24px 26px;background:#FAF8F5;border:1px solid #E2E8F0;border-radius:18px;box-shadow:0 1px 3px rgba(15,23,42,.05);">
      <div style="flex:0 0 auto;width:54px;height:54px;border-radius:15px;background:#D1FAE5;color:#047857;display:flex;align-items:center;justify-content:center;font-size:27px;font-weight:700;line-height:1;">&reg;</div>
      <div style="min-width:0;">
        <div style="font-weight:700;color:#0F172A;font-size:16px;line-height:1.35;">&laquo;&nbsp;Assokit&nbsp;&raquo; est une marque d&eacute;pos&eacute;e</div>
        <div style="color:#475569;font-size:14px;margin-top:4px;line-height:1.55;">
          N&deg; National <strong>26&nbsp;5259962</strong> &middot; D&eacute;pos&eacute;e le <strong>20 mai 2026</strong> &middot; INPI (92)
        </div>
      </div>
    </div>
  </div>
</section>
<!-- /MARQUE DEPOSEE -->
<?php
render_public_footer();
render_public_foot();
