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

// === Pré-remplissage URL ===
$prefill_subject_key = $_GET['subject'] ?? '';
$prefill_plan = $_GET['plan'] ?? '';

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
    $posted_key = $_POST['subject_key'];
    if (isset($subjects_list[$posted_key])) {
        $selected_subject_key = $posted_key;
    }
}

// === Traitement formulaire ===
$form_message = '';
$form_status = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $subject_key = $_POST['subject_key'] ?? '';
    $subject_other = trim($_POST['subject_other'] ?? '');
    $message = trim($_POST['message'] ?? '');
    $consent = !empty($_POST['consent']);
    $hp_website = trim($_POST['website'] ?? '');
    require_once __DIR__ . '/rate-limit-helper.php';

    // Construction de l'objet final
    if ($subject_key === 'other' && $subject_other !== '') {
        $subject_final = $subject_other;
    } elseif (isset($subjects_list[$subject_key])) {
        $subject_final = $subjects_list[$subject_key]['full'];
        if ($prefill_plan && in_array($subject_key, ['demo', 'quote'], true)) {
            $subject_final .= ' — Plan ' . ucfirst($prefill_plan);
        }
    } else {
        $subject_final = '';
    }

    if ($hp_website !== '') {
        // Honeypot rempli = bot : faux succès, aucun envoi
        $form_status = 'success';
        $form_message = 'Merci ! Votre message a bien été envoyé. On vous répond en moins de 24h.';
    } elseif (!ak_rate_limit('contact', 5, 600)) {
        $form_status = 'error';
        $form_message = '⚠️ Trop de messages envoyés. Merci de patienter quelques minutes avant de réessayer.';
    } elseif (!$name || !$email || !$message || !$consent || !$subject_key) {
        $form_status = 'error';
        $form_message = '⚠️ Tous les champs marqués * sont obligatoires (objet, nom, email, message, consentement).';
    } elseif ($subject_key === 'other' && !$subject_other) {
        $form_status = 'error';
        $form_message = '⚠️ Veuillez préciser l\'objet de votre demande.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $form_status = 'error';
        $form_message = '⚠️ Votre email ne semble pas valide.';
    } else {
        try {
            // === Persistance en base : le message arrive dans l'app Fondateur + active le double-sens ===
            // (table asso_contact_messages créée par migration v45 ; échec DB non bloquant pour l'email)
            try {
                if (isset($pdo) && $pdo instanceof PDO) {
                    $name_parts = preg_split('/\s+/', trim($name), 2);
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
                }
            } catch (Throwable $eDb) {
                error_log('[contact save] ' . $eDb->getMessage());
            }
            if (function_exists('send_transactional_email')) {
                $body_html = '<h2>Nouveau message contact Assokit</h2>';
                $body_html .= '<p><strong>De :</strong> ' . htmlspecialchars($name) . ' (' . htmlspecialchars($email) . ')</p>';
                if ($phone) $body_html .= '<p><strong>Téléphone :</strong> ' . htmlspecialchars($phone) . '</p>';
                $body_html .= '<p><strong>Catégorie :</strong> ' . htmlspecialchars($subjects_list[$subject_key]['icon'] . ' ' . $subjects_list[$subject_key]['label']) . '</p>';
                $body_html .= '<p><strong>Objet :</strong> ' . htmlspecialchars($subject_final) . '</p>';
                $body_html .= '<p><strong>Message :</strong></p><p>' . nl2br(htmlspecialchars($message)) . '</p>';

                send_transactional_email(
                    'contact@assokit.fr',
                    '[' . strtoupper($subject_key) . '] ' . $subject_final,
                    $body_html,
                    ['tag' => 'contact_form', 'reply_to' => $email]
                );

                // === NOUVEAU : Email de confirmation au visiteur ===
                $confirm_html = '<div style="font-family:system-ui,sans-serif;max-width:600px;margin:0 auto;padding:30px 20px;color:#0F172A;">';
                $confirm_html .= '<div style="background:linear-gradient(135deg,#059669,#047857);color:white;padding:36px 28px;border-radius:16px 16px 0 0;text-align:center;">';
                $confirm_html .= '<div style="font-size:48px;margin-bottom:10px;">🌿</div>';
                $confirm_html .= '<h1 style="margin:0;font-size:24px;font-weight:700;">Merci ' . htmlspecialchars(explode(' ', $name)[0]) . ' !</h1>';
                $confirm_html .= '<p style="margin:8px 0 0;opacity:0.95;font-size:15px;">Votre message a bien été reçu</p>';
                $confirm_html .= '</div>';
                $confirm_html .= '<div style="background:white;padding:32px 28px;border:1px solid #E2E8F0;border-top:none;border-radius:0 0 16px 16px;">';
                $confirm_html .= '<p style="margin:0 0 18px;line-height:1.6;font-size:15px;">Bonjour ' . htmlspecialchars(explode(' ', $name)[0]) . ',</p>';
                $confirm_html .= '<p style="margin:0 0 18px;line-height:1.6;font-size:15px;">Nous avons bien reçu votre demande sur Assokit et nous vous remercions pour votre intérêt.</p>';
                $confirm_html .= '<div style="background:#F0FDF4;border:1px solid #A7F3D0;border-radius:10px;padding:16px 18px;margin:0 0 22px;">';
                $confirm_html .= '<div style="font-size:11px;color:#047857;text-transform:uppercase;letter-spacing:0.06em;font-weight:700;margin-bottom:6px;">📋 Récapitulatif de votre demande</div>';
                $confirm_html .= '<div style="font-size:14px;color:#0F172A;line-height:1.6;"><strong>Catégorie :</strong> ' . htmlspecialchars($subjects_list[$subject_key]['icon'] . ' ' . $subjects_list[$subject_key]['label']) . '</div>';
                $confirm_html .= '<div style="font-size:14px;color:#0F172A;line-height:1.6;"><strong>Objet :</strong> ' . htmlspecialchars($subject_final) . '</div>';
                $confirm_html .= '</div>';
                $confirm_html .= '<p style="margin:0 0 18px;line-height:1.6;font-size:15px;"><strong>Notre équipe humaine</strong> vous répondra <strong>en moins de 24h ouvrées</strong>. Pas de bot, pas de FAQ qui tourne en rond — juste de vraies réponses, par de vraies personnes 🌿</p>';
                $confirm_html .= '<p style="margin:0 0 24px;line-height:1.6;font-size:14px;color:#475569;">En attendant, n\'hésitez pas à explorer nos <a href="https://assokit.fr/fonctionnalites" style="color:#047857;font-weight:600;">fonctionnalités</a> ou consulter nos <a href="https://assokit.fr/tarifs" style="color:#047857;font-weight:600;">tarifs</a>.</p>';
                $confirm_html .= '<hr style="border:none;border-top:1px solid #E2E8F0;margin:24px 0;">';
                $confirm_html .= '<p style="margin:0;font-size:13px;color:#94A3B8;line-height:1.5;">À très vite,<br><strong style="color:#0F172A;">L\'équipe Assokit</strong> · Édité par RBPS · Évry, France 🇫🇷</p>';
                $confirm_html .= '</div>';
                $confirm_html .= '<p style="text-align:center;font-size:12px;color:#94A3B8;margin:18px 0 0;">Vous recevez cet email suite à votre demande sur <a href="https://assokit.fr" style="color:#94A3B8;">assokit.fr</a></p>';
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
            }
            $form_status = 'success';
            $form_message = 'Merci ! Votre message a bien été envoyé. On vous répond en moins de 24h.';
            // Sauvegarder l'email pour l'afficher dans le succès
            $sent_email = $email;
        } catch (Throwable $e) {
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
                     value="<?= htmlspecialchars($_POST['subject_other'] ?? '') ?>">
            </div>

            <!-- ============ NOM + EMAIL ============ -->
            <div class="contact-row contact-row-2">
              <div class="contact-field">
                <label for="ct-name" class="contact-label">Nom complet <span class="contact-req">*</span></label>
                <input type="text" id="ct-name" name="name" required
                       class="contact-input"
                       placeholder="Marie Dupont"
                       value="<?= htmlspecialchars($_POST['name'] ?? '') ?>">
              </div>
              <div class="contact-field">
                <label for="ct-email" class="contact-label">Email <span class="contact-req">*</span></label>
                <input type="email" id="ct-email" name="email" required
                       class="contact-input"
                       placeholder="marie@exemple.fr"
                       value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
              </div>
            </div>

            <!-- ============ TÉLÉPHONE ============ -->
            <div class="contact-field">
              <label for="ct-phone" class="contact-label">Téléphone <span class="contact-optional">(optionnel)</span></label>
              <input type="tel" id="ct-phone" name="phone"
                     class="contact-input"
                     placeholder="06 12 34 56 78"
                     value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>">
            </div>

            <!-- ============ MESSAGE ============ -->
            <div class="contact-field">
              <label for="ct-message" class="contact-label">Votre message <span class="contact-req">*</span></label>
              <textarea id="ct-message" name="message" required rows="6"
                        class="contact-textarea"
                        placeholder="Parlez-nous de votre association ou de votre TPE, vos besoins, vos objectifs..."><?= htmlspecialchars($_POST['message'] ?? '') ?></textarea>
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
            <div class="contact-info-value contact-info-rbps">RBPS</div>
            <div class="contact-info-sub">Évry · France 🇫🇷<br>En cours d'immatriculation</div>
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
    color: #94A3B8;
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
    font-size: 14.5px;
    color: var(--c-encre, #0F172A);
    transition: all 0.2s ease;
    box-sizing: border-box;
    appearance: none;
    -webkit-appearance: none;
  }
  .contact-input::placeholder { color: #94A3B8; font-weight: 400; }
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
    font-size: 14.5px;
    line-height: 1.6;
    color: var(--c-encre, #0F172A);
    resize: vertical;
    transition: all 0.2s ease;
    box-sizing: border-box;
    appearance: none;
    -webkit-appearance: none;
  }
  .contact-textarea::placeholder { color: #94A3B8; font-weight: 400; }
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
      font-size: 15px;
    }
    .contact-textarea {
      min-height: 130px;
      font-size: 15px;
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
