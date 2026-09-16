<?php
require_once __DIR__ . '/ak-icons.php';   // jeu d'icônes maison (ak_icon, ak_icon_badge, ak_dot)
require_once __DIR__ . '/app-context.php'; // ak_billing_hidden() : rien qui ressemble à un achat dans l'app (Apple 3.1.1)
require_once __DIR__ . '/includes-nav.php'; // familles du menu latéral (ak_nav_rendre)

if (!function_exists('ak_render_rich_text')) {
    /**
     * Affiche proprement un texte pouvant contenir du HTML brut issu d'une
     * synchro (Google Calendar / Outlook) : convertit les balises de bloc en
     * sauts de ligne, retire tout le HTML (anti-XSS), puis rend cliquables les
     * URLs, emails et numéros de téléphone. Sûr : aucune balise d'origine ne
     * survit (strip_tags avant échappement).
     */
    function ak_render_rich_text($raw): string {
        $s = (string)$raw;
        if ($s === '') return '';
        // Balises de bloc / <br> -> sauts de ligne ; puces de liste
        $s = preg_replace('#<\s*br\s*/?\s*>#i', "\n", $s);
        $s = preg_replace('#</\s*(p|div|li|tr|h[1-6]|ul|ol)\s*>#i', "\n", $s);
        $s = preg_replace('#<\s*li[^>]*>#i', "• ", $s);
        // Retire toute balise restante puis décode les entités
        $s = strip_tags($s);
        $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // Nettoie les espaces/sauts multiples
        $s = preg_replace("/[ \t]+\n/", "\n", $s);
        $s = preg_replace("/\n{3,}/", "\n\n", $s);
        $s = trim($s);
        if ($s === '') return '';
        // Échappe AVANT d'insérer nos propres liens (aucun HTML d'origine ne subsiste)
        $s = htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        $lnk = 'color:#059669;text-decoration:underline;';
        // URLs
        $s = preg_replace_callback('#\bhttps?://[^\s<]+#i', function ($m) use ($lnk) {
            $u = rtrim($m[0], '.,);');
            return '<a href="' . $u . '" target="_blank" rel="noopener" style="' . $lnk . '">' . $u . '</a>';
        }, $s);
        // Emails
        $s = preg_replace('#(?<![\w.])([A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,})#', '<a href="mailto:$1" style="' . $lnk . '">$1</a>', $s);
        // Téléphones (FR/international) — évite de matcher à l'intérieur d'un lien déjà posé
        $s = preg_replace_callback('#(?<![\w/:=."+>])(\+?\d[\d ().\-]{7,}\d)#', function ($m) use ($lnk) {
            $disp = $m[0];
            $tel = preg_replace('/[^\d+]/', '', $disp);
            return '<a href="tel:' . $tel . '" style="' . $lnk . '">' . $disp . '</a>';
        }, $s);
        return nl2br($s);
    }
}

if (!function_exists('fr_format_date')) {
    function fr_format_date($fmt, $ts) {
        $jours = ['Sunday'=>'dimanche','Monday'=>'lundi','Tuesday'=>'mardi','Wednesday'=>'mercredi','Thursday'=>'jeudi','Friday'=>'vendredi','Saturday'=>'samedi'];
        $mois = ['January'=>'janvier','February'=>'février','March'=>'mars','April'=>'avril','May'=>'mai','June'=>'juin','July'=>'juillet','August'=>'août','September'=>'septembre','October'=>'octobre','November'=>'novembre','December'=>'décembre'];
        $mois_court = ['Jan'=>'janv.','Feb'=>'févr.','Mar'=>'mars','Apr'=>'avr.','May'=>'mai','Jun'=>'juin','Jul'=>'juil.','Aug'=>'août','Sep'=>'sept.','Oct'=>'oct.','Nov'=>'nov.','Dec'=>'déc.'];
        $r = $fmt;
        $r = str_replace('%A', $jours[date('l', $ts)] ?? '', $r);
        $r = str_replace('%B', $mois[date('F', $ts)] ?? '', $r);
        $r = str_replace('%b', $mois_court[date('M', $ts)] ?? '', $r);
        $r = str_replace('%d', date('d', $ts), $r);
        $r = str_replace('%Y', date('Y', $ts), $r);
        $r = str_replace('%H', date('H', $ts), $r);
        $r = str_replace('%M', date('i', $ts), $r);
        $r = str_replace('%m', date('m', $ts), $r);
        return $r;
    }
}

/**
 * ============================================================
 * ASSOKIT — Éléments partagés entre les pages connectées
 * ============================================================
 * Ce fichier contient :
 *   - render_head($title)    : HTML <head> + <style> commun
 *   - render_sidebar($active): la barre latérale de navigation
 *   - render_mobile_bar()    : la barre du haut sur mobile
 * ============================================================
 * Inclus dans dashboard.php, projets.php, projet.php, etc.
 * ============================================================
 */

if (!defined('SITE_NAME')) {
    require_once __DIR__ . '/config.php';
}

// === Tracking activité utilisateur (Fondateur audit) ===
if (file_exists(__DIR__ . '/activity-tracker.php')) {
    require_once __DIR__ . '/activity-tracker.php';
}

// === Bandeau MODE DÉMO ===
if (file_exists(__DIR__ . '/demo-banner.php')) {
    require_once __DIR__ . '/demo-banner.php';
}

/**
 * Renvoie les balises <link> pour le favicon Assokit unifie.
 * A appeler dans le <head> de TOUTES les pages du site.
 *
 * Design : carré vert émeraude (#059669) arrondi (style iOS),
 *          avec un cercle blanc en bas à droite (marque Assokit).
 *
 * Aucun fichier externe nécessaire : tout est en data-URI SVG.
 */
function assokit_favicon() {
    // SVG principal (affiche par les navigateurs modernes)
    $svg_main = "data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'%3E%3Crect x='2' y='2' width='28' height='28' rx='7' fill='%23059669'/%3E%3Ccircle cx='22' cy='22' r='4.5' fill='%23FFFFFF'/%3E%3C/svg%3E";

    // Apple Touch Icon (180x180, plus grand, pour iOS add-to-homescreen)
    $svg_apple = "data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 180 180'%3E%3Crect width='180' height='180' rx='40' fill='%23059669'/%3E%3Ccircle cx='125' cy='125' r='25' fill='%23FFFFFF'/%3E%3C/svg%3E";

    return '<link rel="icon" type="image/svg+xml" href="' . $svg_main . '">'
         . '<link rel="apple-touch-icon" href="' . $svg_apple . '">';
}

/**
 * Affiche le <head> + tous les styles CSS communs.
 */
function render_head($page_title) {
    // Track la pageview (silencieux si tracking pas dispo)
    if (function_exists('activity_track_pageview') && !empty($_SESSION['user_id'])) {
        activity_track_pageview();
    }
    
    // Le bandeau démo s'affichera après <body> (voir hook plus bas)
    ?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="color-scheme" content="light">
<meta name="theme-color" content="#059669" media="(prefers-color-scheme: light)">
<meta name="theme-color" content="#065F46" media="(prefers-color-scheme: dark)">
<meta name="robots" content="noindex, nofollow">
<title><?= h($page_title) ?> — Assokit</title>
<?= assokit_favicon() ?>

<!-- 📱 PWA -->
<link rel="manifest" href="/manifest.json">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<meta name="apple-mobile-web-app-title" content="Assokit">
<meta name="mobile-web-app-capable" content="yes">
<link rel="apple-touch-icon" sizes="192x192" href="/icons/icon-192.png">
<link rel="apple-touch-icon" sizes="512x512" href="/icons/icon-512.png">

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<!-- 700 est nécessaire : la barre latérale, les noms d'organisation, les badges
     et tous les <strong> l'utilisent. Sans lui le navigateur fabrique un faux
     gras en épaississant le 600, plus lourd et moins net que le vrai dessin. -->
<link href="https://fonts.googleapis.com/css2?family=Geist:wght@400;500;600;700&display=swap" rel="stylesheet">
<!-- DARK MODE DISABLED — ak-theme-early-init removed -->
<!-- DARK MODE DISABLED -- <link rel="stylesheet" href="/css/dark-fixes.css?v=1778772802"> -->
<!-- La feuille de style commune est servie en fichier statique et non plus
     inline : 84 Ko (17 Ko compressés) repartaient à chaque page, alors que
     le navigateur peut la garder en cache. ?v=filemtime la renouvelle au
     déploiement, sinon un correctif resterait invisible pendant 7 jours. -->
<link rel="stylesheet" href="/assets/assokit-app.css?v=<?= @filemtime(__DIR__ . '/assets/assokit-app.css') ?>">
</head>
<body>
<?php if (function_exists('render_demo_banner')) render_demo_banner(); ?>
<?php if (function_exists('render_trial_banner')) render_trial_banner(); ?>
    <?php
}


/**
 * [trial-14j] Banniere globale essai gratuit
 * S'affiche en haut de chaque page si l'org est en status='trial'
 * Mobile responsive (flex-wrap + media query 640px)
 */
function render_trial_banner() {
    global $pdo;
    // Le bandeau porte un bouton « S'abonner » : interdit dans l'app (Apple 3.1.1).
    if (ak_billing_hidden()) return;
    if (!function_exists('current_user')) return;
    $user = current_user();
    if (!$user || empty($user['org_id'])) return;
    $org_id = (int)$user['org_id'];

    try {
        $stmt = $pdo->prepare("
            SELECT s.status, o.trial_ends_at
            FROM subscriptions s
            JOIN organizations o ON o.id = s.org_id
            WHERE s.org_id = ?
            ORDER BY s.id DESC LIMIT 1
        ");
        $stmt->execute([$org_id]);
        $row = $stmt->fetch();
        if (!$row || $row['status'] !== 'trial' || empty($row['trial_ends_at'])) return;

        $end_ts = strtotime($row['trial_ends_at']);
        if (!$end_ts) return;
        $days_left = max(0, (int)ceil(($end_ts - time()) / 86400));
        $end_fr = date('d/m/Y', $end_ts);

        if ($days_left === 0) {
            $msg = "se termine <strong>aujourd'hui</strong>";
        } elseif ($days_left === 1) {
            $msg = "se termine <strong>demain</strong> (" . $end_fr . ")";
        } else {
            $msg = "Plus que <strong>" . $days_left . " jours</strong> &middot; fin le " . $end_fr;
        }
        ?>
<style id="ak-trial-banner-css">
.ak-trial-banner { grid-column: 1 / -1; background: linear-gradient(135deg,#FEF3C7,#FDE68A); border-bottom: 2px solid #F59E0B; padding: 10px 18px; display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; font-size: 13px; }
.ak-trial-banner-info { display: flex; align-items: center; gap: 12px; min-width: 0; flex: 1; }
.ak-trial-banner-icon { font-size: 22px; line-height: 1; flex-shrink: 0; }
.ak-trial-banner-text { color: #92400E; }
.ak-trial-banner-text strong { color: #78350F; }
.ak-trial-banner-btn { background: #92400E; color: #fff !important; padding: 8px 16px; border-radius: 8px; text-decoration: none; font-weight: 600; font-size: 12.5px; white-space: nowrap; flex-shrink: 0; transition: background 0.15s; }
.ak-trial-banner-btn:hover { background: #78350F; }
@media (max-width: 640px) {
  .ak-trial-banner { padding: 10px 14px; }
  .ak-trial-banner-info { flex-basis: 100%; }
  .ak-trial-banner-btn { width: 100%; text-align: center; padding: 10px; }
  .ak-trial-banner-text { font-size: 12.5px; }
}
</style>
<div class="ak-trial-banner">
  <div class="ak-trial-banner-info">
    <span class="ak-trial-banner-icon">🚀</span>
    <div class="ak-trial-banner-text"><strong>Mode Démo</strong> &middot; <?= $msg ?></div>
  </div>
  <a href="/abonnement?tab=plans" class="ak-trial-banner-btn">⚡ S'abonner</a>
</div>
        <?php
    } catch (Throwable $e) {}
}

/**
 * Affiche la barre latérale (sidebar).
 * @param string $active Nom de la page active : 'accueil', 'projets', 'adherents', 'agenda', 'messages', 'factures'
 */
function render_sidebar($active = 'accueil') {
    global $pdo;
    $user = current_user();

    // === MODE MAIRIE : sidebar dédiée ===
    if (!empty($_SESSION['parent_org_id']) || !empty($user['parent_org_id'])) {
        render_sidebar_mairie($active);
        return;
    }

    $user_org_id = (int) ($user['org_id'] ?? 0);

    // Charger l'organisation (si l'utilisateur en a une)
    $org = null;
    if ($user_org_id > 0) {
        $stmt = $pdo->prepare('SELECT name FROM organizations WHERE id = ? LIMIT 1');
        $stmt->execute([$user_org_id]);
        $org = $stmt->fetch();
    }

    // Compter les projets et adhérents (0 si pas d'org)
    $proj_count = 0;
    $user_count = 0;
    if ($user_org_id > 0) {
        $proj_count = (int)$pdo->query("SELECT COUNT(*) FROM projects p JOIN folders f ON p.folder_id = f.id WHERE f.org_id = {$user_org_id} AND p.status IN ('active','warning') AND p.archived_at IS NULL AND f.archived_at IS NULL")->fetchColumn();
        $user_count = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE org_id = {$user_org_id} AND deleted_at IS NULL AND is_active = 1")->fetchColumn();
    }

    $org_initials = mb_strtoupper(mb_substr($org['name'] ?? 'A', 0, 1) . mb_substr($org['name'] ?? '', 1, 1));
    $user_initials = user_initials($user['first_name'], $user['last_name']);

    // ===== Detection Super Admin / Fondateur (defensive : recharge via BDD si colonnes absentes) =====
    $is_founder = false;
    $is_sa = ($user['role'] ?? '') === 'super_admin';
    if (array_key_exists('is_founder', $user)) {
        $is_founder = !empty($user['is_founder']);
    }
    if (!$is_sa && array_key_exists('is_super_admin', $user)) {
        $is_sa = !empty($user['is_super_admin']);
    }
    // Fallback BDD si une des colonnes n'est pas dans current_user()
    if (!$is_founder || (!$is_sa && !array_key_exists('is_super_admin', $user))) {
        try {
            $stmt_sa = $pdo->prepare("SELECT is_super_admin, is_founder FROM users WHERE id = ?");
            $stmt_sa->execute([(int) $user['id']]);
            $row_sa = $stmt_sa->fetch(PDO::FETCH_ASSOC);
            if ($row_sa) {
                if (!$is_sa) $is_sa = (int) ($row_sa['is_super_admin'] ?? 0) === 1;
                if (!$is_founder) $is_founder = (int) ($row_sa['is_founder'] ?? 0) === 1;
            }
        } catch (Throwable $e) {}
    }
    if ($is_founder) $is_sa = true;
    ?>
<!-- Header mobile (visible uniquement <900px) -->
<header class="sb-mobile-header">
    <button type="button" class="sb-burger" onclick="document.getElementById('sidebar').classList.toggle('open'); document.getElementById('overlay').classList.toggle('active');" aria-label="Menu">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
    </button>
    <?php if (!empty($org['name'])): ?>
    <div class="sb-mobile-org sb-mobile-org-main">
        <span class="sb-mobile-org-avatar"><?= h($org_initials ?? '?') ?></span>
        <span class="sb-mobile-org-name"><?= h($org['name']) ?></span>
    </div>
    <?php else: ?>
    <div class="sb-mobile-title">
        <span class="sb-logo-mark"></span>
        <span>Asso<i>kit</i></span>
    </div>
    <?php endif; ?>
    <button type="button" class="sb-mobile-logout" onclick="window.location.href='/deconnexion.php'" aria-label="Se déconnecter" title="Se déconnecter">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
    </button>
</header>

<div class="app">
  <div class="sidebar-overlay sb-overlay" id="overlay" onclick="document.getElementById('sidebar').classList.remove('open'); this.classList.remove('active');"></div>

  <aside class="sidebar" id="sidebar">

    <?php if ($is_sa): ?>
      <?php if ($is_founder): ?>
        <a href="/super-admin" class="sb-backto-sa sb-backto-sa--founder">
          <div class="sb-backto-sa-label">🏗️ Mode Fondateur</div>
          <div class="sb-backto-sa-title">
            <span>Retour au cockpit</span>
            <span class="sb-backto-sa-arrow">→</span>
          </div>
        </a>
      <?php else: ?>
        <a href="/super-admin" class="sb-backto-sa sb-backto-sa--sa">
          <div class="sb-backto-sa-label">👑 Super Admin</div>
          <div class="sb-backto-sa-title">
            <span>Retour au cockpit</span>
            <span class="sb-backto-sa-arrow">→</span>
          </div>
        </a>
      <?php endif; ?>
    <?php endif; ?>

    <a href="/" class="sb-logo"><span class="sb-logo-mark"></span><span>Asso<i>kit</i></span></a>

    <button class="sb-org" aria-label="Organisation">
      <span class="sb-org-avatar"><?= h($org_initials) ?></span>
      <div class="sb-org-body">
        <div class="sb-org-name"><?= h($org['name'] ?? '—') ?></div>
        <div class="sb-org-role"><?= h(role_label($user['role'])) ?></div>
      </div>
      <svg class="sb-org-chevron" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="7 15 12 20 17 15"/><polyline points="7 9 12 4 17 9"/></svg>
    </button>

    <nav class="sb-nav">
      <a href="/dashboard" class="sb-link <?= $active === 'accueil' ? 'active' : '' ?>">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/></svg>
        Accueil
      </a>

      <?php
      // ============ NOTIFICATIONS (cloche avec badge dynamique) ============
      // Calculer le badge initial (sera mis à jour en JS toutes les 30s)
      $notif_unread = 0;
      if (file_exists(__DIR__ . '/notification-helpers.php')) {
          require_once __DIR__ . '/notification-helpers.php';
          if (function_exists('ak_notif_count_unread')) {
              try { $notif_unread = ak_notif_count_unread($GLOBALS['pdo'] ?? null, (int)current_user()['id']); }
              catch (Throwable $e) { $notif_unread = 0; }
          }
      }
      ?>
      <a href="/notifications" class="sb-link sb-link-notif <?= $active === 'notifications' ? 'active' : '' ?>" id="sbNotifLink" style="position:relative;">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
        Notifications
        <span class="sb-badge sb-badge-notif" id="sbNotifBadge" style="<?= $notif_unread > 0 ? 'background:#EF4444; color:#fff; font-weight:600;' : 'display:none;' ?>"><?= $notif_unread > 0 ? ($notif_unread > 99 ? '99+' : $notif_unread) : '' ?></span>
        <button type="button" id="ak-sound-toggle" class="ak-sound-tgl" title="Activer/couper le son des notifications" onclick="event.preventDefault(); event.stopPropagation(); window.akToggleSound && window.akToggleSound();">
          <span class="ak-sound-on">🔔</span>
          <span class="ak-sound-off" hidden>🔕</span>
        </button>
        <button type="button" id="ak-push-toggle" class="ak-push-tgl" title="Activer/couper les notifications système" onclick="event.preventDefault(); event.stopPropagation(); window.akTogglePush && window.akTogglePush();">
          <span class="ak-push-on" hidden>📢</span>
          <span class="ak-push-off">📵</span>
        </button>
      </a>

      <?php
      // Support : tous les utilisateurs de l'asso voient les tickets de leur
      // organisation. Calculé ici parce que le compteur alimente un badge du menu.
      $support_unread = 0;
      if ($user_org_id > 0) {
          try {
              $stmt = $pdo->prepare("
                  SELECT COUNT(DISTINCT t.id)
                  FROM support_tickets t
                  JOIN support_messages m ON m.ticket_id = t.id
                  WHERE t.org_id = ?
                    AND m.author_side = 'support'
                    AND m.read_by_org = 0
                    AND m.is_internal_note = 0
              ");
              $stmt->execute([$user_org_id]);
              $support_unread = (int) $stmt->fetchColumn();
          } catch (Throwable $e) {}
      }

      // Le reste du menu est rangé par famille : voir includes-nav.php, où
      // chaque entrée tient sur une ligne, avec sa condition de visibilité.
      ak_nav_rendre([
          'active'         => $active,
          'role'           => $user['role'] ?? '',
          'is_follower'    => is_follower(),
          'peut_finances'  => can('manage_finances'),
          'peut_marketing' => can('access_marketing'),
          'peut_admin'     => can('admin'),
          'proj_count'     => $proj_count,
          'user_count'     => $user_count,
          'support_unread' => $support_unread,
      ]);
      ?>
    </nav>

    <div class="sb-foot">
      <span class="sb-user-avatar"><?= h($user_initials) ?></span>
      <div class="sb-user-body">
        <div class="sb-user-name"><?= h($user['first_name'] . ' ' . $user['last_name']) ?></div>
        <div class="sb-user-email"><?= h($user['email']) ?></div>
      </div>
      <a href="/deconnexion.php" class="sb-user-menu" title="Se déconnecter">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
      </a>
    </div>
  </aside>

  <div>
    <?php
}


/**
 * Sidebar dédiée pour les agents mairie / collectivité
 */
function render_sidebar_mairie($active = 'mairie-dashboard') {
    global $pdo;
    $user = current_user();
    $parent_org_id = (int)($_SESSION['parent_org_id'] ?? $user['parent_org_id'] ?? 0);

    $parent_org = null;
    if ($parent_org_id > 0) {
        $stmt = $pdo->prepare('SELECT * FROM parent_orgs WHERE id = ? LIMIT 1');
        $stmt->execute([$parent_org_id]);
        $parent_org = $stmt->fetch();
    }
    $nb_assos = 0;
    if ($parent_org_id > 0) {
        try {
            $nb_assos = (int)$pdo->query("SELECT COUNT(*) FROM organizations WHERE parent_org_id = $parent_org_id")->fetchColumn();
        } catch (Exception $e) {}
    }
    $type_emoji = match($parent_org['type'] ?? 'mairie') {
        'mairie' => '🏛', 'departement' => '🏢', 'region' => '🌍',
        'drac' => '🎭', 'caf' => '👨‍👩‍👧', 'federation' => '🤝', default => '🏢'
    };
    $user_initials = user_initials($user['first_name'], $user['last_name']);
    ?>
<header class="sb-mobile-header">
    <button type="button" class="sb-burger" onclick="document.getElementById('sidebar').classList.toggle('open'); document.getElementById('overlay').classList.toggle('active');" aria-label="Menu">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
    </button>
    <div class="sb-mobile-title"><span class="sb-logo-mark"></span><span><?= h($parent_org['name'] ?? 'Mairie') ?></span></div>
    <button type="button" class="sb-mobile-logout" onclick="window.location.href='/deconnexion.php'" aria-label="Se déconnecter">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
    </button>
</header>

<div class="app">
  <div class="sidebar-overlay sb-overlay" id="overlay" onclick="document.getElementById('sidebar').classList.remove('open'); this.classList.remove('active');"></div>

  <aside class="sidebar" id="sidebar">
    <a href="/mairie-dashboard" class="sb-logo"><span class="sb-logo-mark"></span><span>Assokit</span></a>

    <div style="margin:14px 12px 16px;padding:14px;background:linear-gradient(135deg,#0A0A0B 0%,#1F2937 100%);color:#fff;border-radius:10px;">
        <div style="font-size:10px;opacity:0.65;text-transform:uppercase;letter-spacing:0.12em;font-weight:600;">Mairie</div>
        <div style="font-size:13.5px;font-weight:700;line-height:1.25;margin-top:4px;display:flex;align-items:center;gap:6px;">
            <span style="font-size:15px;"><?= $type_emoji ?></span>
            <span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= h($parent_org['name'] ?? 'Mairie') ?></span>
        </div>
    </div>

    <nav style="padding:0 12px;flex:1;">

      <a href="/mairie-dashboard" class="sb-link <?= $active === 'mairie-dashboard' ? 'active' : '' ?>">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
        Accueil
      </a>

      <a href="/mairie-associations" class="sb-link <?= $active === 'mairie-assos' ? 'active' : '' ?>">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 21h18"/><path d="M5 21V7l8-4v18"/><path d="M19 21V11l-6-4"/></svg>
        Associations
        <span style="margin-left:auto;background:#F4F4F5;color:#3F3F46;font-size:11px;padding:1px 7px;border-radius:10px;font-weight:600;"><?= $nb_assos ?></span>
      </a>

      <a href="/mairie-messages" class="sb-link <?= $active === 'messages' ? 'active' : '' ?>">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
        Messages
      </a>

      <a href="/mairie-emailing" class="sb-link <?= $active === 'emailing' ? 'active' : '' ?>">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
        Emailing
      </a>

      <a href="/mairie-support" class="sb-link <?= $active === 'support' ? 'active' : '' ?>">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
        Support
      </a>

      <a href="/mairie-parametres" class="sb-link <?= $active === 'parametres' ? 'active' : '' ?>">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
        Paramètres
      </a>

    </nav>

    <div class="sb-foot">
      <span class="sb-user-avatar"><?= h($user_initials) ?></span>
      <div class="sb-user-body">
        <div class="sb-user-name"><?= h($user['first_name'] . ' ' . $user['last_name']) ?></div>
        <div class="sb-user-email"><?= h($user['email']) ?></div>
      </div>
      <a href="/deconnexion.php" class="sb-user-menu" title="Se déconnecter">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
      </a>
    </div>

  </aside>

  <div>
    <?php
}

/**
 * Ferme la zone principale + script JS partagé (menu mobile + accordéon folder).
 */
function render_foot() {
    ?>
  </div>
</div>

<!-- ========== TOAST NOTIFICATIONS (style Facebook) ========== -->
<div id="notifToastContainer" style="position:fixed; bottom:20px; right:20px; z-index:9999; display:flex; flex-direction:column; gap:8px; max-width:360px;"></div>

<?php
// ========== COPILOTE IA — lanceur flottant permanent (toutes pages) ==========
if (function_exists('current_user') && function_exists('can')):
    $cop_user = current_user();
    if ($cop_user && (int)($cop_user['org_id'] ?? 0) > 0 && can('manage_finances')):
        $cop_csrf = h($_SESSION['csrf_token'] ?? '');
?>
<button id="akCopFab" type="button" aria-label="Ouvrir le Copilote IA" title="Copilote IA — pose ta question">
  <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3l1.9 4.6L18.5 9l-4.6 1.4L12 15l-1.9-4.6L5.5 9l4.6-1.4z"/><path d="M18 15l.9 2.1L21 18l-2.1.9L18 21l-.9-2.1L15 18l2.1-.9z"/></svg>
</button>
<div id="akCopPanel" aria-hidden="true">
  <div class="akcop-head">
    <div class="akcop-head-t"><span class="akcop-dot"></span> Copilote IA</div>
    <button type="button" id="akCopClose" aria-label="Fermer">✕</button>
  </div>
  <div id="akCopLog" class="akcop-log">
    <div class="akcop-hello">Pose ta question sur ton asso 👇<br><span>adhérents · cotisations · factures · trésorerie · projets · événements</span></div>
    <div class="akcop-chips">
      <button type="button" class="akcop-chip">Combien d'adhérents actifs ?</button>
      <button type="button" class="akcop-chip">Quels adhérents relancer ?</button>
      <button type="button" class="akcop-chip">Quel est mon CA cette année ?</button>
      <button type="button" class="akcop-chip">Quelles factures sont en retard ?</button>
    </div>
  </div>
  <form id="akCopForm" class="akcop-form">
    <input id="akCopInput" type="text" autocomplete="off" maxlength="500" placeholder="Écris ta question…">
    <button type="submit" id="akCopSend" aria-label="Envoyer">➤</button>
  </form>
</div>
<style>
#akCopFab{position:fixed;right:22px;bottom:22px;z-index:10000;width:56px;height:56px;border:none;border-radius:50%;cursor:pointer;background:linear-gradient(135deg,#0CCB8F,#059669);box-shadow:0 10px 28px rgba(5,150,105,.45);display:flex;align-items:center;justify-content:center;transition:transform .15s;}
#akCopFab:hover{transform:translateY(-2px) scale(1.04);}
/* Le bouton flottant ne doit pas recouvrir une barre d'action collée en bas (facture/devis/copilote) ni le composeur de messages */
#akCopPanel{position:fixed;right:22px;bottom:88px;z-index:10000;width:370px;max-width:calc(100vw - 32px);height:520px;max-height:calc(100vh - 130px);background:#fff;border:1px solid #E2E8F0;border-radius:18px;box-shadow:0 24px 60px rgba(15,23,42,.22);display:none;flex-direction:column;overflow:hidden;}
#akCopPanel.open{display:flex;}
/* Décalage du bouton flottant et du panneau au-dessus d'une barre d'action collante ou du
   composeur de messages. Placé APRÈS la définition de base, sinon la cascade l'annulait. */
body:has(.main [style*="position:sticky"][style*="bottom"]), body:has(.main [style*="position: sticky"][style*="bottom"]), body:has(.msg-compose) { --ak-fab-off: 84px; }
#akCopFab { bottom: var(--ak-fab-off, 22px); }
#akCopPanel { bottom: calc(var(--ak-fab-off, 22px) + 66px); }
/* Le bouton flottant doit rester SOUS les modales (z-index 1000) : il masquait leurs boutons. */
#akCopFab { z-index: 900; }
#akCopPanel { z-index: 901; }
#notifToastContainer { bottom: 90px !important; right: 16px !important; max-width: calc(100vw - 32px) !important; }
.akcop-head{display:flex;align-items:center;justify-content:space-between;padding:14px 16px;background:linear-gradient(135deg,#0F172A,#065F46);color:#fff;}
.akcop-head-t{font-weight:700;font-size:15px;display:flex;align-items:center;gap:8px;}
.akcop-dot{width:8px;height:8px;border-radius:50%;background:#0CCB8F;box-shadow:0 0 0 4px rgba(12,203,143,.25);}
#akCopClose{background:transparent;border:none;color:#fff;font-size:16px;cursor:pointer;opacity:.8;}
.akcop-log{flex:1;overflow-y:auto;padding:14px;display:flex;flex-direction:column;gap:10px;background:#F8FAFC;}
.akcop-hello{color:#475569;font-size:13.5px;text-align:center;padding:6px 4px;}
.akcop-hello span{color:#64748B;font-size:12px;}
.akcop-chips{display:flex;flex-wrap:wrap;gap:6px;justify-content:center;margin-top:4px;}
.akcop-chip{background:#EEF2F7;border:1px solid #E2E8F0;border-radius:999px;padding:6px 11px;font-size:12px;color:#334155;cursor:pointer;}
.akcop-me{align-self:flex-end;max-width:82%;background:#059669;color:#fff;padding:9px 12px;border-radius:13px 13px 4px 13px;font-size:13.5px;}
.akcop-bot{align-self:flex-start;max-width:92%;background:#fff;border:1px solid #E2E8F0;color:#0F172A;padding:10px 12px;border-radius:13px 13px 13px 4px;font-size:13.5px;}
.akcop-bot table{width:100%;border-collapse:collapse;font-size:12px;margin-top:6px;}
.akcop-bot th{text-align:left;padding:5px 7px;border-bottom:2px solid #E2E8F0;color:#64748B;}
.akcop-bot td{padding:5px 7px;border-bottom:1px solid #F1F5F9;}
.akcop-bot a.akcop-act{display:inline-block;margin-top:8px;background:#0F172A;color:#fff;text-decoration:none;padding:6px 12px;border-radius:8px;font-size:12px;font-weight:600;}
.akcop-form{display:flex;gap:8px;padding:10px;border-top:1px solid #E2E8F0;background:#fff;}
#akCopInput{flex:1;border:1px solid #E2E8F0;border-radius:10px;padding:9px 11px;font-size:13.5px;outline:none;}
#akCopSend{background:linear-gradient(135deg,#0CCB8F,#059669);color:#fff;border:none;border-radius:10px;width:42px;font-size:15px;cursor:pointer;}
@media (max-width:900px){#akCopFab{bottom:var(--ak-fab-off,78px);} #akCopPanel{bottom:calc(var(--ak-fab-off,78px) + 66px);}}
</style>
<script>
(function(){
  var CSRF="<?= $cop_csrf ?>";
  var fab=document.getElementById('akCopFab'), panel=document.getElementById('akCopPanel'),
      log=document.getElementById('akCopLog'), form=document.getElementById('akCopForm'),
      input=document.getElementById('akCopInput'), send=document.getElementById('akCopSend');
  function esc(s){return (s==null?'':String(s)).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');}
  function open(){panel.classList.add('open');panel.setAttribute('aria-hidden','false');setTimeout(function(){input.focus();},80);}
  function close(){panel.classList.remove('open');panel.setAttribute('aria-hidden','true');}
  fab.addEventListener('click',function(){panel.classList.contains('open')?close():open();});
  document.getElementById('akCopClose').addEventListener('click',close);
  function tbl(html,who){var b=document.createElement('div');b.className=who==='me'?'akcop-me':'akcop-bot';b.innerHTML=html;log.appendChild(b);b.scrollIntoView({block:'end'});return b;}
  function renderTable(t){if(!t||!t.columns)return '';var h='<div style="font-weight:700;margin-bottom:2px;">'+esc(t.title||'')+'</div><table><tr>'+t.columns.map(function(c){return '<th>'+esc(c)+'</th>';}).join('')+'</tr>';(t.rows||[]).forEach(function(r){h+='<tr>'+r.map(function(c){return '<td>'+esc(c)+'</td>';}).join('')+'</tr>';});return h+'</table>';}
  function ask(q){if(!q||!q.trim())return;tbl(esc(q),'me');input.value='';send.disabled=true;var w=tbl('<span style="color:#64748B;">…</span>','bot');
    fetch('/mon-asso-copilote-ask.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-CSRF-Token':CSRF},body:JSON.stringify({question:q,csrf_token:CSRF})})
    .then(function(r){return r.json();}).then(function(d){var html='';
      if(d&&d.ok===false){html=esc(d.error==='forbidden'?'Accès non autorisé.':'Service momentanément indisponible.');}
      else{html='<div>'+esc(d.answer||'')+'</div>';if(d.table)html+=renderTable(d.table);if(d.action&&d.action.route)html+='<a class="akcop-act" href="'+esc(d.action.route)+'">'+esc(d.action.label||'Ouvrir')+' →</a>';}
      w.innerHTML=html;}).catch(function(){w.innerHTML=esc('Erreur réseau. Réessayez.');}).finally(function(){send.disabled=false;input.focus();});
  }
  form.addEventListener('submit',function(e){e.preventDefault();ask(input.value);});
  log.addEventListener('click',function(e){if(e.target.classList.contains('akcop-chip'))ask(e.target.textContent);});
})();
</script>
<?php endif; endif; ?>

<style>
.notif-toast {
    background: #fff;
    border: 1px solid #E5E7EB;
    border-radius: 12px;
    box-shadow: 0 8px 24px rgba(0,0,0,0.12);
    padding: 12px 14px;
    display: flex;
    align-items: flex-start;
    gap: 10px;
    cursor: pointer;
    text-decoration: none;
    color: inherit;
    transform: translateX(400px);
    opacity: 0;
    transition: transform 0.3s ease, opacity 0.3s ease;
    position: relative;
}
.notif-toast.show { transform: translateX(0); opacity: 1; }
.notif-toast.hide { transform: translateX(400px); opacity: 0; }
.notif-toast-close {
    position: absolute; top: 6px; right: 8px;
    background: transparent; border: none; cursor: pointer;
    color: #6B7280; padding: 2px; line-height: 1;
    font-size: 16px;
}
.notif-toast-icon {
    width: 38px; height: 38px; border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    color: #fff; font-size: 18px; flex-shrink: 0;
}
.notif-toast-body { flex: 1; min-width: 0; padding-right: 12px; }
.notif-toast-title { font-size: 13px; font-weight: 600; color: #111827; line-height: 1.3; margin-bottom: 2px; }
.notif-toast-text { font-size: 12px; color: #6B7280; line-height: 1.4; overflow: hidden; text-overflow: ellipsis; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; }
.notif-toast-time { font-size: 11px; color: #3B82F6; font-weight: 500; margin-top: 4px; }

/* Toggle son notifications */
.ak-sound-tgl, .ak-push-tgl { position: absolute; top: 50%; transform: translateY(-50%); background: transparent; border: 0; padding: 4px 6px; font-size: 12px; cursor: pointer; opacity: 0; visibility: hidden; border-radius: 5px; transition: opacity 0.15s, background 0.15s; }
.ak-sound-tgl { right: 32px; }
.ak-push-tgl { right: 6px; }
/* au repos : menu épuré (juste le badge rouge) ; au survol : les réglages son/push apparaissent */
.sb-link-notif:hover .ak-sound-tgl, .sb-link-notif:hover .ak-push-tgl { opacity: 0.6; visibility: visible; }
.sb-link-notif:hover .sb-badge-notif { opacity: 0; }
.ak-sound-tgl:hover, .ak-push-tgl:hover { opacity: 1 !important; background: rgba(0,0,0,0.05); }
.sb-link.sb-link-notif { padding-right: 62px !important; }

/* Pulse animation pour le badge cloche */
.sb-badge-notif { 
    animation: notifPulse 2s ease-in-out infinite;
}
@keyframes notifPulse {
    0%, 100% { transform: scale(1); }
    50% { transform: scale(1.08); }
}
</style>

<!-- Même raison que pour la feuille de style : 14 Ko de script identiques
     à chaque page. Ni defer ni async — l'ordre d'exécution vis-à-vis des
     blocs voisins doit rester celui d'avant. -->
<script src="/assets/assokit-app.js?v=<?= @filemtime(__DIR__ . '/assets/assokit-app.js') ?>"></script>

<!-- 📱 PWA : Service Worker uniquement (bouton install retire 2026-05-15) -->
<script>
(function() {
  if ('serviceWorker' in navigator) {
    window.addEventListener('load', function() {
      navigator.serviceWorker.register('/service-worker.js').catch(function(){});
    });
  }
})();
</script>

<!-- 📱 PWA : invite d'installation discrete (rejetable, memorisee) -->
<script>
(function() {
  var KEY = 'ak_pwa_install_dismissed';
  // Dans l'app native (Capacitor) : jamais d'invite d'installation PWA
  if (window.Capacitor) return;
  try { if (localStorage.getItem(KEY)) return; } catch (e) { return; }
  // Deja installe (mode standalone) : ne rien afficher
  if ((window.matchMedia && window.matchMedia('(display-mode: standalone)').matches) || window.navigator.standalone) return;
  // L'invite est fixee en bas d'ecran : elle recouvrait les barres d'action collantes
  // (valider une facture, un devis, envoyer un message). On ne l'affiche que sur les pages
  // d'accueil, ou rien n'est ancre en bas.
  var p = location.pathname.replace(/\/+$/, '');
  if (!(p === '' || p === '/dashboard' || p === '/dashboard.php' || p === '/accueil')) return;

  var deferred = null;
  function dismiss(el){ try { localStorage.setItem(KEY, '1'); } catch(e){} if (el) el.remove(); }
  function showBanner(mode) {
    if (document.getElementById('ak-pwa-banner')) return;
    var b = document.createElement('div');
    b.id = 'ak-pwa-banner';
    b.style.cssText = 'position:fixed;left:12px;right:12px;bottom:12px;z-index:99999;max-width:440px;margin:0 auto;background:#fff;border:1px solid #E2E8F0;border-radius:14px;box-shadow:0 10px 40px rgba(15,23,42,.18);padding:14px 16px;display:flex;align-items:center;gap:12px;font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,Arial,sans-serif;';
    var txt = (mode === 'ios')
      ? 'Appuyez sur Partager puis « Sur l\'écran d\'accueil »'
      : 'Accès rapide, plein écran, même hors-ligne';
    b.innerHTML = '<img src="/icons/icon-192.png" alt="" width="40" height="40" style="border-radius:9px;flex:none;">'
      + '<div style="flex:1;min-width:0;"><div style="font-weight:650;font-size:14px;color:#0F172A;">Installer Assokit</div>'
      + '<div style="font-size:12.5px;color:#475569;line-height:1.35;">' + txt + '</div></div>';
    var actions = document.createElement('div');
    actions.style.cssText = 'display:flex;gap:6px;flex:none;align-items:center;';
    if (mode === 'android') {
      var inst = document.createElement('button');
      inst.type = 'button';
      inst.textContent = 'Installer';
      inst.style.cssText = 'background:#059669;color:#fff;border:0;border-radius:9px;padding:9px 14px;font-weight:600;font-size:13px;cursor:pointer;';
      inst.onclick = function() {
        if (deferred) { deferred.prompt(); deferred.userChoice.finally(function(){ deferred = null; dismiss(b); }); }
        else { dismiss(b); }
      };
      actions.appendChild(inst);
    }
    var close = document.createElement('button');
    close.type = 'button';
    close.setAttribute('aria-label', 'Fermer');
    close.textContent = '✕';
    close.style.cssText = 'background:#F1F5F9;color:#475569;border:0;border-radius:9px;padding:9px 12px;font-size:13px;cursor:pointer;';
    close.onclick = function() { dismiss(b); };
    actions.appendChild(close);
    b.appendChild(actions);
    document.body.appendChild(b);
  }
  window.addEventListener('beforeinstallprompt', function(e) { e.preventDefault(); deferred = e; showBanner('android'); });
  var ua = navigator.userAgent || '';
  var isIOS = /iphone|ipad|ipod/i.test(ua);
  var isSafari = /safari/i.test(ua) && !/crios|fxios|chrome|android/i.test(ua);
  if (isIOS && isSafari) { setTimeout(function() { showBanner('ios'); }, 3000); }
})();
</script>

<script>
/* ────────────────────────────────────────────────────────────────────
   Tableaux défilants — filet responsive global.

   Un <table> ne peut pas défiler tout seul : il lui faut un conteneur.
   Plutôt que d'éditer les ~22 pages qui en contiennent, on l'ajoute ici
   au chargement. Sans ça, un tableau de factures ou d'adhérents pousse
   toute la page vers la droite sur téléphone.

   `display:block` sur le <table> aurait été plus simple mais casse
   l'alignement des colonnes : d'où l'enveloppe.
   ──────────────────────────────────────────────────────────────────── */
(function () {
  function envelopper() {
    var racine = document.querySelector('.main') || document.body;
    var tables = racine.querySelectorAll('table');
    for (var i = 0; i < tables.length; i++) {
      var t = tables[i];
      // Déjà enveloppé, ou déjà dans un bloc qui défile : on n'y touche pas.
      if (t.closest('.ak-tblwrap')) continue;
      var parent = t.parentNode;
      if (parent && parent.nodeType === 1) {
        var ox = getComputedStyle(parent).overflowX;
        if (ox === 'auto' || ox === 'scroll') continue;
      }
      var wrap = document.createElement('div');
      wrap.className = 'ak-tblwrap';
      parent.insertBefore(wrap, t);
      wrap.appendChild(t);
    }
    marquer();
  }
  // Le liseré n'apparaît que si le tableau dépasse réellement.
  function marquer() {
    var w = document.querySelectorAll('.ak-tblwrap');
    for (var i = 0; i < w.length; i++) {
      w[i].classList.toggle('is-scrollable', w[i].scrollWidth > w[i].clientWidth + 1);
    }
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', envelopper);
  } else {
    envelopper();
  }
  window.addEventListener('resize', marquer);
})();
</script>
</body>
</html>
    <?php
}

/**
 * Renvoie les couleurs Tailwind-like pour un thème de dossier.
 * Compatible avec 16 couleurs.
 */
function folder_icon_class($theme) {
    $valid = ['blue','indigo','purple','magenta','pink','red','orange','amber',
              'yellow','lime','green','emerald','teal','cyan','slate','brown'];
    $theme = in_array($theme, $valid, true) ? $theme : 'blue';
    return 'fi-' . $theme;
}

/**
 * Map des couleurs HEX (utilisé pour les rendus inline si besoin).
 */
function folder_color_hex($theme) {
    $colors = [
        'blue'    => '#3B82F6',
        'indigo'  => '#6366F1',
        'purple'  => '#8B5CF6',
        'magenta' => '#D946EF',
        'pink'    => '#EC4899',
        'red'     => '#EF4444',
        'orange'  => '#F97316',
        'amber'   => '#F59E0B',
        'yellow'  => '#EAB308',
        'lime'    => '#84CC16',
        'green'   => '#10B981',
        'emerald' => '#059669',
        'teal'    => '#14B8A6',
        'cyan'    => '#06B6D4',
        'slate'   => '#64748B',
        'brown'   => '#8B4513',
    ];
    return $colors[$theme] ?? $colors['blue'];
}

/**
 * Renvoie le SVG inner d'une icône Lucide.
 * Si $icon n'est pas reconnu, fallback sur l'icône par défaut "folder".
 */
function folder_icon_inner($icon = 'folder') {
    $icons = [
        'folder'    => '<path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/>',
        'home'      => '<path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/>',
        'building'  => '<path d="M3 21h18M5 21V7l8-4v18M19 21V11l-6-4"/>',
        'clipboard' => '<rect x="8" y="2" width="8" height="4" rx="1" ry="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/>',
        'target'    => '<circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="2"/>',
        'star'      => '<polygon points="12 2 15 9 22 9 17 14 19 21 12 17 5 21 7 14 2 9 9 9 12 2"/>',
        'briefcase' => '<rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/>',
        'palette'   => '<circle cx="13.5" cy="6.5" r="1.5"/><circle cx="17.5" cy="10.5" r="1.5"/><circle cx="8.5" cy="7.5" r="1.5"/><circle cx="6.5" cy="12.5" r="1.5"/><path d="M12 2C6.5 2 2 6.5 2 12s4.5 10 10 10c.926 0 1.648-.746 1.648-1.688 0-.437-.18-.835-.437-1.125-.29-.289-.438-.652-.438-1.125a1.64 1.64 0 0 1 1.668-1.668h1.996c3.051 0 5.555-2.503 5.555-5.554C21.965 6.012 17.461 2 12 2z"/>',
        'graduation-cap' => '<path d="M22 10v6M2 10l10-5 10 5-10 5z"/><path d="M6 12v5c3 3 9 3 12 0v-5"/>',
        'handshake' => '<path d="M11 17l-5-5-2 2 5 5 2-2zM21 12l-2-2-7 7 2 2 7-7zM12 7l3 3"/>',
        'lightbulb' => '<path d="M9 18h6M10 22h4M12 2a7 7 0 0 1 5 12c-1 1-2 2-2 4H9c0-2-1-3-2-4a7 7 0 0 1 5-12z"/>',
        'leaf'      => '<path d="M11 20A7 7 0 0 1 9.8 6.1C15.5 5 17 4.48 19.2 2.96c.86 8.66-3.32 17.04-12.2 17.04L4 20l7-9"/>',
        'activity'  => '<polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/>',
        'megaphone' => '<path d="M3 11l18-5v12L3 14v-3z"/><path d="M11.6 16.8a3 3 0 1 1-5.8-1.6"/>',
        'wallet'    => '<path d="M20 12V8H6a2 2 0 0 1-2-2c0-1.1.9-2 2-2h12v4"/><path d="M4 6v12a2 2 0 0 0 2 2h14v-4"/><path d="M18 12a2 2 0 0 0-2 2c0 1.1.9 2 2 2h4v-4z"/>',
        'heart'     => '<path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/>',
        'baby'      => '<path d="M9 12h.01M15 12h.01M10 16c.5.3 1.2.5 2 .5s1.5-.2 2-.5"/><path d="M19 6.3a9 9 0 0 1 1.8 3.9 2 2 0 0 1 0 3.6 9 9 0 0 1-17.6 0 2 2 0 0 1 0-3.6A9 9 0 0 1 12 3a9 9 0 0 1 7 3.3z"/>',
        'users'     => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
        'calendar'  => '<rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>',
        'globe'     => '<circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/>',
    ];
    return $icons[$icon] ?? $icons['folder'];
}

/**
 * Icône SVG complète selon le thème + l'icône choisie.
 * Si pas d'icône (ancien dossier), fallback vers icône par défaut.
 * 
 * @param string $theme Couleur (blue, indigo, purple, ...)
 * @param string|null $icon Nom de l'icône (folder, home, building, ...)
 */
function folder_icon_svg($theme, $icon = null) {
    if (!$icon) {
        $icon = 'folder';
    }
    $inner = folder_icon_inner($icon);
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">' . $inner . '</svg>';
}
