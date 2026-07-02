<?php
/**
 * ============================================================
 * ASSOKIT — Guide onboarding (modal overlay) — FIX
 * ============================================================
 * FIX v2 : lecture DIRECTE en BDD du flag onboarding_completed_at
 *          + fallback localStorage pour eviter reouverture meme
 *          si l'AJAX echoue.
 * ============================================================
 */

// =====================================================================
// DETERMINATION DU ROLE + lecture DIRECTE BDD du flag onboarding
// =====================================================================
$guide_role = in_array($user['role'] ?? 'member', ['admin', 'coordinator'], true) ? 'admin' : 'member';

// FIX : lire directement en BDD (pas via $user qui peut etre cache en session)
$auto_open = true;
try {
    $_stmt_guide = $pdo->prepare("SELECT onboarding_completed_at FROM users WHERE id = ?");
    $_stmt_guide->execute([(int) ($_SESSION['user_id'] ?? 0)]);
    $_completed = $_stmt_guide->fetchColumn();
    if (!empty($_completed)) {
        $auto_open = false;
    }
} catch (Throwable $e) {
    // En cas d'erreur SQL, on prefere ne pas spammer l'user
    $auto_open = false;
}

// =====================================================================
// DEFINITION DU CONTENU DES GUIDES
// =====================================================================

// Illustrations SVG inline (cohérent charte vert émeraude)
$svg_welcome = '<svg viewBox="0 0 120 120" xmlns="http://www.w3.org/2000/svg" width="100%" height="100%">
  <circle cx="60" cy="60" r="54" fill="#ECFDF5"/>
  <circle cx="60" cy="60" r="38" fill="#D1FAE5"/>
  <path d="M42 58l12 12 24-24" stroke="#059669" stroke-width="4" fill="none" stroke-linecap="round" stroke-linejoin="round"/>
  <circle cx="95" cy="28" r="5" fill="#FCD34D" opacity="0.8"/>
  <circle cx="22" cy="35" r="3" fill="#059669" opacity="0.4"/>
  <circle cx="25" cy="90" r="4" fill="#F59E0B" opacity="0.5"/>
</svg>';

$svg_projects = '<svg viewBox="0 0 120 120" xmlns="http://www.w3.org/2000/svg" width="100%" height="100%">
  <circle cx="60" cy="60" r="54" fill="#ECFDF5"/>
  <rect x="30" y="38" width="60" height="48" rx="6" fill="#059669" opacity="0.9"/>
  <rect x="35" y="30" width="20" height="10" rx="2" fill="#047857"/>
  <rect x="38" y="52" width="30" height="4" rx="2" fill="#ECFDF5"/>
  <rect x="38" y="62" width="44" height="4" rx="2" fill="#A7F3D0"/>
  <rect x="38" y="72" width="22" height="4" rx="2" fill="#A7F3D0"/>
</svg>';

$svg_people = '<svg viewBox="0 0 120 120" xmlns="http://www.w3.org/2000/svg" width="100%" height="100%">
  <circle cx="60" cy="60" r="54" fill="#ECFDF5"/>
  <circle cx="60" cy="48" r="14" fill="#059669"/>
  <path d="M32 92c0-15 12-24 28-24s28 9 28 24" fill="#059669"/>
  <circle cx="32" cy="52" r="10" fill="#10B981" opacity="0.7"/>
  <circle cx="88" cy="52" r="10" fill="#10B981" opacity="0.7"/>
</svg>';

$svg_calendar = '<svg viewBox="0 0 120 120" xmlns="http://www.w3.org/2000/svg" width="100%" height="100%">
  <circle cx="60" cy="60" r="54" fill="#ECFDF5"/>
  <rect x="28" y="34" width="64" height="54" rx="6" fill="#ffffff" stroke="#059669" stroke-width="3"/>
  <rect x="28" y="34" width="64" height="14" rx="6" fill="#059669"/>
  <circle cx="42" cy="60" r="3" fill="#059669"/>
  <circle cx="60" cy="60" r="3" fill="#059669"/>
  <circle cx="78" cy="60" r="3" fill="#059669"/>
  <circle cx="42" cy="72" r="3" fill="#A7F3D0"/>
  <circle cx="60" cy="72" r="3" fill="#FCD34D"/>
  <circle cx="78" cy="72" r="3" fill="#A7F3D0"/>
  <rect x="38" y="28" width="4" height="14" rx="2" fill="#047857"/>
  <rect x="78" y="28" width="4" height="14" rx="2" fill="#047857"/>
</svg>';

$svg_messages = '<svg viewBox="0 0 120 120" xmlns="http://www.w3.org/2000/svg" width="100%" height="100%">
  <circle cx="60" cy="60" r="54" fill="#ECFDF5"/>
  <path d="M30 45 l0 30 c0 3 2 5 5 5 l30 0 l10 10 l0 -10 l10 0 c3 0 5 -2 5 -5 l0 -30 c0 -3 -2 -5 -5 -5 l-50 0 c-3 0 -5 2 -5 5 z" fill="#059669"/>
  <circle cx="50" cy="60" r="3" fill="white"/>
  <circle cx="62" cy="60" r="3" fill="white"/>
  <circle cx="74" cy="60" r="3" fill="white"/>
</svg>';

$svg_communication = '<svg viewBox="0 0 120 120" xmlns="http://www.w3.org/2000/svg" width="100%" height="100%">
  <circle cx="60" cy="60" r="54" fill="#ECFDF5"/>
  <rect x="26" y="44" width="68" height="44" rx="6" fill="#059669"/>
  <path d="M26 48 L60 72 L94 48" stroke="white" stroke-width="3" fill="none" stroke-linejoin="round"/>
  <circle cx="92" cy="36" r="8" fill="#FCD34D"/>
  <path d="M92 32 L92 38 M88 36 L96 36" stroke="#92400E" stroke-width="2" stroke-linecap="round"/>
</svg>';

$svg_invoices = '<svg viewBox="0 0 120 120" xmlns="http://www.w3.org/2000/svg" width="100%" height="100%">
  <circle cx="60" cy="60" r="54" fill="#ECFDF5"/>
  <rect x="36" y="28" width="48" height="68" rx="4" fill="white" stroke="#059669" stroke-width="2.5"/>
  <rect x="42" y="36" width="20" height="3" rx="1" fill="#059669"/>
  <rect x="42" y="48" width="36" height="2.5" rx="1" fill="#D1FAE5"/>
  <rect x="42" y="55" width="30" height="2.5" rx="1" fill="#D1FAE5"/>
  <rect x="42" y="62" width="34" height="2.5" rx="1" fill="#D1FAE5"/>
  <rect x="42" y="76" width="36" height="14" rx="2" fill="#059669"/>
  <text x="60" y="87" text-anchor="middle" fill="white" font-size="10" font-weight="600" font-family="sans-serif">EUR</text>
</svg>';

$svg_settings = '<svg viewBox="0 0 120 120" xmlns="http://www.w3.org/2000/svg" width="100%" height="100%">
  <circle cx="60" cy="60" r="54" fill="#ECFDF5"/>
  <path d="M60 30 L64 38 L72 36 L70 44 L78 48 L72 54 L78 60 L70 64 L72 72 L64 70 L60 78 L56 70 L48 72 L50 64 L42 60 L48 54 L42 48 L50 44 L48 36 L56 38 Z" fill="#059669"/>
  <circle cx="60" cy="54" r="8" fill="#ECFDF5"/>
</svg>';

// --- Guide ADHÉRENT (5 etapes) ---
$guide_member = [
    [
        'icon' => '🎉',
        'svg' => $svg_welcome,
        'title' => 'Bienvenue sur Assokit !',
        'desc' => 'Nous sommes ravis de vous accueillir. Ce petit tour en 5 étapes vous aidera à comprendre comment utiliser votre espace.',
        'tip' => 'Vous pouvez passer ce guide à tout moment et le relancer depuis le bouton "Aide" sur votre tableau de bord.',
    ],
    [
        'icon' => '📁',
        'svg' => $svg_projects,
        'title' => 'Les projets de votre association',
        'desc' => 'Dans l\'onglet <strong>Projets</strong>, vous retrouvez tous les projets de votre asso, organisés par dossiers thématiques. Cliquez sur un projet pour voir ses détails, son avancement et son référent.',
        'tip' => '💡 Si vous êtes <strong>référent</strong> d\'un projet, c\'est vous qui en êtes responsable. Vous pourrez mettre à jour son avancement et suivre son budget.',
    ],
    [
        'icon' => '🎯',
        'svg' => $svg_people,
        'title' => 'Le rôle de référent',
        'desc' => 'Un <strong>référent</strong> est l\'adhérent qui pilote un projet. Il en suit l\'avancement, coordonne l\'équipe et met à jour les informations. Si vous êtes référent, vous verrez apparaître "Référent" sur vos projets.',
        'tip' => '👥 Tous les adhérents peuvent consulter les projets, mais seul le référent (ou un admin) peut les modifier.',
    ],
    [
        'icon' => '📅',
        'svg' => $svg_calendar,
        'title' => 'L\'agenda partagé',
        'desc' => 'L\'onglet <strong>Agenda</strong> affiche tous les événements de votre association : réunions, assemblées, événements publics. Vous pouvez aussi le synchroniser avec Google Calendar.',
        'tip' => '🔔 Les événements importants sont signalés par une pastille de couleur selon leur catégorie.',
    ],
    [
        'icon' => '💬',
        'svg' => $svg_messages,
        'title' => 'Messages & Communication',
        'desc' => 'L\'onglet <strong>Messages</strong> permet de discuter avec les autres adhérents en direct ou dans des groupes. Vous recevrez aussi les newsletters et invitations via <strong>Communication</strong>.',
        'tip' => '📧 Les invitations aux événements arrivent aussi par email pour ne rien manquer.',
    ],
];

// --- Guide ADMIN (8 etapes) ---
$guide_admin = [
    [
        'icon' => '🎉',
        'svg' => $svg_welcome,
        'title' => 'Bienvenue dans votre cockpit Assokit',
        'desc' => 'En tant qu\'administrateur, vous avez accès à toutes les fonctionnalités de gestion de votre association. Ce guide en 8 étapes vous présente l\'essentiel.',
        'tip' => '🚀 Prenez 3 minutes pour ce tour, vous gagnerez des heures ensuite.',
    ],
    [
        'icon' => '👥',
        'svg' => $svg_people,
        'title' => 'Gérer vos adhérents',
        'desc' => 'Dans <strong>Adhérents</strong>, vous pouvez ajouter des membres un par un, <strong>importer un fichier CSV</strong> en masse, ou <strong>exporter la liste</strong>. Chaque adhérent reçoit un email pour créer son mot de passe de façon sécurisée.',
        'tip' => '💼 4 rôles disponibles : Admin (accès total), Coordinateur (encadrement), Référent (responsable projet), Membre (standard).',
    ],
    [
        'icon' => '📁',
        'svg' => $svg_projects,
        'title' => 'Projets & dossiers',
        'desc' => 'Organisez vos <strong>projets par dossiers thématiques</strong> (Événements, Social, Culturel...). Pour chaque projet : nom, dates, budget, participants, et surtout le <strong>référent responsable</strong>.',
        'tip' => '🎯 Assignez un référent à chaque projet actif — cela clarifie les responsabilités dans l\'équipe.',
    ],
    [
        'icon' => '📅',
        'svg' => $svg_calendar,
        'title' => 'Gestion de l\'agenda',
        'desc' => 'Créez des <strong>événements</strong> avec dates, lieux, descriptions. Invitez vos adhérents. Synchronisez avec Google Calendar pour une vue unifiée sur mobile.',
        'tip' => '🔗 Les événements créés dans Communication peuvent avoir un <strong>lien public de RSVP</strong> partageable par SMS/WhatsApp.',
    ],
    [
        'icon' => '💬',
        'svg' => $svg_messages,
        'title' => 'Messages & groupes de discussion',
        'desc' => 'Discutez en <strong>1-à-1 avec vos adhérents</strong> ou créez des <strong>groupes de discussion</strong> par équipe, par projet ou par thématique. Un module essentiel pour coordonner l\'asso au quotidien.',
        'tip' => '👥 Créez des groupes par rôle (Admins, Coordinateurs) pour des échanges plus ciblés.',
    ],
    [
        'icon' => '📧',
        'svg' => $svg_communication,
        'title' => 'Communication & diffusion',
        'desc' => 'Dans <strong>Communication</strong>, envoyez des <strong>newsletters</strong> à tous les adhérents ou ciblez par rôle. Créez des <strong>événements avec RSVP</strong> (Oui/Non/Peut-être) et partagez le lien public.',
        'tip' => '✨ Utilisez l\'onglet "Rédiger" pour générer des textes professionnels avec l\'IA (convocations AG, appels à dons, newsletters...).',
    ],
    [
        'icon' => '💳',
        'svg' => $svg_invoices,
        'title' => 'Factures & finances',
        'desc' => 'Dans <strong>Factures</strong>, suivez les paiements, créez des factures pour vos partenaires ou adhérents, et gérez votre comptabilité simplifiée (HT/TVA/TTC).',
        'tip' => '📊 Ces données alimentent automatiquement votre rapport financier annuel.',
    ],
    [
        'icon' => '⚙️',
        'svg' => $svg_settings,
        'title' => 'Paramètres de l\'association',
        'desc' => 'Dans les <strong>paramètres</strong>, modifiez les infos de votre asso (nom, adresse, statuts), votre profil admin, et la gestion globale. Vous pouvez aussi créer d\'autres admins pour partager la charge.',
        'tip' => '🎓 Vous êtes prêt ! En cas de doute, cliquez sur le bouton "Aide" en bas du tableau de bord pour relancer ce guide.',
    ],
];

$steps = $guide_role === 'admin' ? $guide_admin : $guide_member;
$total_steps = count($steps);
$current_user_id = (int) ($_SESSION['user_id'] ?? 0);
?>

<!-- ========== BOUTON AIDE (toujours visible sur le dashboard) ========== -->
<button type="button" id="ak-help-btn"
        onclick="akOnboardingOpen()"
        style="display:inline-flex; align-items:center; gap:8px; padding:8px 14px; background:var(--bg); color:var(--ink-2); border:1px solid var(--border); border-radius:999px; font-size:12.5px; font-weight:500; cursor:pointer; font-family:inherit; transition: all 0.15s;"
        onmouseover="this.style.background='var(--bg-2)'; this.style.borderColor='var(--acc)'; this.style.color='var(--acc-dark)'"
        onmouseout="this.style.background='var(--bg)'; this.style.borderColor='var(--border)'; this.style.color='var(--ink-2)'">
  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
    <circle cx="12" cy="12" r="10"/>
    <path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/>
    <line x1="12" y1="17" x2="12.01" y2="17"/>
  </svg>
  Guide & Aide
</button>

<!-- ========== MODAL GUIDE ONBOARDING ========== -->
<div id="ak-onboarding-modal" style="display:none; position:fixed; inset:0; z-index:9999; background:rgba(0,0,0,0.55); backdrop-filter:blur(4px); -webkit-backdrop-filter:blur(4px); align-items:center; justify-content:center; padding:20px; animation:akFadeIn 0.2s ease;">
  <div style="background:white; border-radius:20px; max-width:540px; width:100%; max-height:92vh; overflow-y:auto; box-shadow:0 20px 60px rgba(0,0,0,0.25); position:relative; animation:akScaleIn 0.25s ease;">

    <!-- Bouton fermer -->
    <button type="button" onclick="akOnboardingClose()"
            style="position:absolute; top:16px; right:16px; background:transparent; border:none; width:32px; height:32px; border-radius:8px; cursor:pointer; display:flex; align-items:center; justify-content:center; color:#78716C; z-index:2; font-size:20px; transition:background 0.15s;"
            onmouseover="this.style.background='#F5F5F4'" onmouseout="this.style.background='transparent'"
            title="Fermer">✕</button>

    <!-- Corps -->
    <div style="padding:36px 32px 24px; text-align:center;">

      <!-- Illustration SVG dans un cercle vert clair -->
      <div id="ak-step-icon" style="width:140px; height:140px; margin:0 auto 20px;">
        <?= $steps[0]['svg'] ?>
      </div>

      <!-- Titre -->
      <h2 id="ak-step-title" style="font-size:22px; font-weight:600; color:#1C1917; margin:0 0 12px; letter-spacing:-0.02em; line-height:1.3;">
        <?= $steps[0]['icon'] ?> <?= htmlspecialchars($steps[0]['title']) ?>
      </h2>

      <!-- Description -->
      <div id="ak-step-desc" style="font-size:14px; line-height:1.65; color:#44403C; margin-bottom:18px; max-width:420px; margin-left:auto; margin-right:auto;">
        <?= $steps[0]['desc'] ?>
      </div>

      <!-- Tip -->
      <div id="ak-step-tip-wrap" style="background:#ECFDF5; border:1px solid #A7F3D0; border-radius:10px; padding:12px 16px; margin-bottom:22px; text-align:left; font-size:13px; line-height:1.55; color:#065F46;">
        <div id="ak-step-tip"><?= $steps[0]['tip'] ?></div>
      </div>

      <!-- Progression (petits cercles) -->
      <div style="display:flex; justify-content:center; gap:8px; margin-bottom:24px;">
        <?php for ($i = 0; $i < $total_steps; $i++): ?>
          <span class="ak-dot" data-step="<?= $i ?>" onclick="akOnboardingGoTo(<?= $i ?>)"
                style="width:8px; height:8px; border-radius:50%; background:<?= $i === 0 ? '#059669' : '#E7E5E4' ?>; transition:all 0.25s; cursor:pointer;"></span>
        <?php endfor; ?>
      </div>

      <!-- Navigation -->
      <div style="display:flex; gap:10px; justify-content:space-between; align-items:center;">
        <button type="button" id="ak-btn-prev" onclick="akOnboardingPrev()"
                style="padding:10px 18px; background:transparent; color:#78716C; border:1px solid #D6D3D1; border-radius:10px; font-family:inherit; font-size:13.5px; cursor:pointer; visibility:hidden; transition:all 0.15s;"
                onmouseover="this.style.background='#F5F5F4'" onmouseout="this.style.background='transparent'">
          ← Précédent
        </button>

        <div id="ak-step-counter" style="font-size:12px; color:#A8A29E; font-weight:500;">
          Étape 1 sur <?= $total_steps ?>
        </div>

        <button type="button" id="ak-btn-next" onclick="akOnboardingNext()"
                style="padding:10px 22px; background:#059669; color:white; border:none; border-radius:10px; font-family:inherit; font-size:13.5px; font-weight:500; cursor:pointer; transition:background 0.15s;"
                onmouseover="this.style.background='#047857'" onmouseout="this.style.background='#059669'">
          Suivant →
        </button>
      </div>
    </div>

    <!-- Footer avec skip -->
    <div style="border-top:1px solid #F5F5F4; padding:14px 24px; text-align:center; background:#FAFAF9; border-radius:0 0 20px 20px;">
      <button type="button" onclick="akOnboardingSkip()"
              style="background:transparent; border:none; color:#A8A29E; font-size:12px; cursor:pointer; font-family:inherit; transition:color 0.15s;"
              onmouseover="this.style.color='#059669'" onmouseout="this.style.color='#A8A29E'">
        Passer le guide
      </button>
    </div>

  </div>
</div>

<style>
@keyframes akFadeIn {
  from { opacity: 0; }
  to { opacity: 1; }
}
@keyframes akScaleIn {
  from { opacity: 0; transform: scale(0.95); }
  to { opacity: 1; transform: scale(1); }
}
#ak-onboarding-modal.open { display: flex !important; }
@media (max-width: 520px) {
  #ak-onboarding-modal > div { max-height: 100vh; border-radius: 16px; }
  #ak-step-icon { width: 100px !important; height: 100px !important; }
}
</style>

<script>
(function() {
  const akSteps = <?= json_encode(array_map(function($s) {
      return [
          'icon' => $s['icon'],
          'svg' => $s['svg'],
          'title' => $s['title'],
          'desc' => $s['desc'],
          'tip' => $s['tip'],
      ];
  }, $steps)) ?>;
  const akTotal = akSteps.length;
  let akCurrent = 0;
  const akAutoOpen = <?= $auto_open ? 'true' : 'false' ?>;
  const akUserId = <?= $current_user_id ?>;
  const akLocalKey = 'assokit_onboarding_done_' + akUserId;

  // =========================
  // FONCTIONS GLOBALES
  // =========================
  window.akOnboardingOpen = function() {
    const m = document.getElementById('ak-onboarding-modal');
    if (!m) return;
    akCurrent = 0;
    akRenderStep();
    m.classList.add('open');
    document.body.style.overflow = 'hidden';
  };

  window.akOnboardingClose = function() {
    const m = document.getElementById('ak-onboarding-modal');
    if (!m) return;
    m.classList.remove('open');
    document.body.style.overflow = '';
    // FIX : marquer aussi localement a la fermeture pour eviter reouverture
    akMarkDoneLocal();
  };

  window.akOnboardingSkip = function() {
    akMarkDone();
    akOnboardingClose();
  };

  window.akOnboardingNext = function() {
    if (akCurrent < akTotal - 1) {
      akCurrent++;
      akRenderStep();
    } else {
      akMarkDone();
      akOnboardingClose();
    }
  };

  window.akOnboardingPrev = function() {
    if (akCurrent > 0) {
      akCurrent--;
      akRenderStep();
    }
  };

  window.akOnboardingGoTo = function(idx) {
    if (idx >= 0 && idx < akTotal) {
      akCurrent = idx;
      akRenderStep();
    }
  };

  function akRenderStep() {
    const step = akSteps[akCurrent];
    document.getElementById('ak-step-icon').innerHTML = step.svg;
    document.getElementById('ak-step-title').innerHTML = step.icon + ' ' + step.title;
    document.getElementById('ak-step-desc').innerHTML = step.desc;
    document.getElementById('ak-step-tip').innerHTML = step.tip;
    document.getElementById('ak-step-counter').textContent = 'Étape ' + (akCurrent + 1) + ' sur ' + akTotal;

    document.querySelectorAll('.ak-dot').forEach((dot, i) => {
      dot.style.background = i === akCurrent ? '#059669' : (i < akCurrent ? '#A7F3D0' : '#E7E5E4');
      dot.style.width = i === akCurrent ? '22px' : '8px';
      dot.style.borderRadius = i === akCurrent ? '4px' : '50%';
    });

    const btnPrev = document.getElementById('ak-btn-prev');
    const btnNext = document.getElementById('ak-btn-next');
    btnPrev.style.visibility = akCurrent === 0 ? 'hidden' : 'visible';
    btnNext.innerHTML = akCurrent === akTotal - 1 ? '🎉 Terminer' : 'Suivant →';
  }

  function akMarkDoneLocal() {
    // FIX : persistance locale dans le navigateur
    try { localStorage.setItem(akLocalKey, '1'); } catch(e) {}
  }

  function akMarkDone() {
    // Marquage local ET serveur
    akMarkDoneLocal();
    try {
      fetch('/guide-onboarding-done', {
        method: 'POST',
        credentials: 'same-origin',
        headers: {'Content-Type': 'application/json'},
      }).catch(() => {});
    } catch(e) {}
  }

  // =========================
  // AUTO-OPEN : FIX critique
  // =========================
  // On verifie A LA FOIS le flag BDD (via PHP $auto_open) ET le localStorage
  // Si l'un des deux dit "deja vu" → on n'ouvre PAS automatiquement
  let shouldAutoOpen = akAutoOpen;
  try {
    if (localStorage.getItem(akLocalKey) === '1') {
      shouldAutoOpen = false;
    }
  } catch(e) {}

  if (shouldAutoOpen) {
    setTimeout(() => { akOnboardingOpen(); }, 600);
  }

  // ESC ferme la modal
  document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') akOnboardingClose();
  });

  // Click sur le backdrop ferme la modal
  document.getElementById('ak-onboarding-modal').addEventListener('click', function(e) {
    if (e.target === this) akOnboardingClose();
  });
})();
</script>
