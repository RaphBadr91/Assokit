<?php
/**
 * ============================================================
 * ASSOKIT — Fiche Adhérent (détail) — v3
 * ============================================================
 * v3 : ajout bouton Supprimer (admin only) + filtre deleted_at
 * ============================================================
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes-layout.php';
@require_once __DIR__ . '/password-token-helper.php';
@require_once __DIR__ . '/rate-limit-helper.php';

require_login();

// Suiveur (financeur externe) : il ne voit que les projets qu'il suit, pas l'annuaire
// (même garde que /adherents et le tableau de bord).
if (is_follower()) { header('Location: /projets'); exit; }

$current = current_user();
$org_id = (int)$current['org_id'];
$id = (int)($_GET['id'] ?? 0);

if ($id <= 0) {
    header('Location: /adherents');
    exit;
}

// Flash message
$flash = $_SESSION['flash_adherent'] ?? null;
unset($_SESSION['flash_adherent']);

// Chargement de l'adhérent (non supprimé)
$stmt = $pdo->prepare("
    SELECT id, email, first_name, last_name, role, phone, city, avatar_color,
           adhesion_date, adhesion_valid_until, is_active, created_at, last_login_at,
           can_create_projects, must_change_password, deleted_at
    FROM users
    WHERE id = ? AND org_id = ? AND deleted_at IS NULL
    LIMIT 1
");
$stmt->execute([$id, $org_id]);
$adherent = $stmt->fetch();

if (!$adherent) {
    render_head('Adhérent introuvable');
    render_sidebar('adherents');
    echo '<div class="main"><div class="empty-state" style="margin-top: 60px;">Adhérent introuvable ou hors de votre organisation.</div></div>';
    render_foot();
    exit;
}

// Projets dont il est référent (hors archives : un projet archivé n'est plus « en cours »)
$stmt = $pdo->prepare("
    SELECT p.id, p.name, p.progress_percent, p.status, f.name AS folder_name, f.color_theme
    FROM projects p
    JOIN folders f ON p.folder_id = f.id
    WHERE p.referent_id = ? AND f.org_id = ?
      AND p.archived_at IS NULL AND f.archived_at IS NULL
    ORDER BY FIELD(p.status, 'warning', 'active', 'done', 'archived'), p.progress_percent ASC
");
$stmt->execute([$id, $org_id]);
$ref_projects = $stmt->fetchAll();

$color_class = in_array($adherent['avatar_color'], ['blue','purple','amber','pink','teal'], true)
    ? 'av-' . $adherent['avatar_color'] : 'av-blue';

if (!function_exists('format_date_fr_long')) {
    function format_date_fr_long($date_str) {
        if (!$date_str) return '—';
        $months = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
        $t = strtotime($date_str);
        return (int)date('j', $t) . ' ' . $months[(int)date('n', $t) - 1] . ' ' . date('Y', $t);
    }
}

// Gestionnaires des adhérents : admin, coordinateur, ou droit « Gérer les adhérents » donné par
// l'admin (même règle que /nouveau-adherent et /modifier-adherent).
$role_me = (string)($current['role'] ?? '');
$is_admin_or_coord = in_array($role_me, ['admin', 'coordinator'], true)
    || (function_exists('can') && can('manage_members'));
$roles_reserved = $role_me === 'admin' ? [] : ($role_me === 'coordinator' ? ['admin'] : ['admin', 'coordinator']);
$is_self = (int)$adherent['id'] === (int)$current['id'];
// Mêmes règles que /modifier-adherent : pas soi-même, pas un compte plateforme,
// et un coordinateur ne modifie pas un administrateur.
$plat = function_exists('ak_platform_flags') ? (ak_platform_flags(['id' => (int)$adherent['id']]) ?? []) : [];
$can_edit = $is_admin_or_coord && !$is_self
    && empty($plat['is_founder']) && empty($plat['is_super_admin'])
    && !in_array($adherent['role'], $roles_reserved, true);
$can_message = $is_admin_or_coord && !$is_self && !empty($adherent['email']);
// Invitation (lien de création de mot de passe) : compte actif qui n'a pas encore choisi son mot de passe
$can_invite = $can_edit && (int)$adherent['is_active'] === 1 && !empty($adherent['email'])
    && (!empty($adherent['must_change_password']) || empty($adherent['last_login_at']))
    && function_exists('create_password_token') && function_exists('send_welcome_email');

// Coordonnées (e-mail, téléphone, ville) et dernière connexion : gestionnaires (admin,
// coordinateur, fondateur, super-admin) ou l'intéressé lui-même — même règle que l'export et l'appli.
$plat_me = function_exists('ak_platform_flags') ? (ak_platform_flags($current) ?? []) : $current;
$can_see_contacts = $is_admin_or_coord || $is_self
    || !empty($plat_me['is_founder']) || !empty($plat_me['is_super_admin']);

// Envoi (ou renvoi) de l'invitation : nouveau lien de création de mot de passe, valable 7 jours
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'send_invite') {
    if (!check_csrf($_POST['csrf_token'] ?? '')) {
        $flash = ['type' => 'error', 'message' => 'Votre session a expiré : l’invitation n’a pas été envoyée. Merci de réessayer.'];
    } elseif (!$can_invite) {
        $flash = ['type' => 'error', 'message' => 'Invitation impossible pour ce compte (déjà activé, désactivé ou hors de vos droits).'];
    } elseif (function_exists('ak_rate_limit') && !ak_rate_limit('member_invite', 3, 3600, (string)$adherent['id'])) {
        $flash = ['type' => 'error', 'message' => 'Plusieurs invitations ont déjà été envoyées à cette personne dans l’heure : patientez avant de réessayer.'];
    } else {
        $sent = false;
        try {
            $token = create_password_token($pdo, (int)$adherent['id'], 'set_password');
            $st = $pdo->prepare("SELECT name FROM organizations WHERE id = ?");
            $st->execute([$org_id]);
            $org_name = (string)($st->fetchColumn() ?: 'votre association');
            $sent = send_welcome_email((string)$adherent['email'], (string)$adherent['first_name'], $org_name, $token);
        } catch (Throwable $e) {
            error_log('[ADHERENT invite] ' . $e->getMessage());
        }
        $flash = $sent
            ? ['type' => 'success', 'message' => 'Invitation envoyée à ' . $adherent['email'] . ' : le lien de création de mot de passe est valable 7 jours.']
            : ['type' => 'error', 'message' => 'L’email n’a pas pu être envoyé. Vérifiez l’adresse puis réessayez dans quelques minutes.'];
    }
    $_SESSION['flash_adherent'] = $flash;
    header('Location: /adherent?id=' . (int)$adherent['id']);
    exit;
}

// Seul un ADMIN (pas coordinator) peut supprimer, et pas soi-meme
$can_delete = ($current['role'] === 'admin') && !$is_self;

render_head($adherent['first_name'] . ' ' . $adherent['last_name']);
render_sidebar('adherents');
?>

<div class="main">

  <nav class="crumbs">
    <a href="/dashboard">Dashboard</a>
    <span class="sep">›</span>
    <a href="/adherents">Adhérents</a>
    <span class="sep">›</span>
    <span class="current"><?= h($adherent['first_name'] . ' ' . $adherent['last_name']) ?></span>
  </nav>

  <?php if ($flash): ?>
    <div class="alert <?= $flash['type'] === 'success' ? 'alert-success' : 'alert-error' ?>" style="margin-bottom: 18px;">
      <span><?= $flash['type'] === 'success' ? ak_icon('check-circle',16) : ak_icon('alert-tri',16) ?></span>
      <div><?= h($flash['message']) ?></div>
    </div>
  <?php endif; ?>

  <!-- En-tête fiche -->
  <div style="display: flex; gap: 20px; align-items: center; margin-bottom: 32px; flex-wrap: wrap;">
    <span class="adh-avatar <?= $color_class ?>" style="width: 72px; height: 72px; font-size: 22px;">
      <?= h(user_initials($adherent['first_name'], $adherent['last_name'])) ?>
    </span>
    <div style="flex: 1; min-width: 0;">
      <h1 class="page-title" style="margin-bottom: 6px;"><?= h($adherent['first_name'] . ' ' . $adherent['last_name']) ?></h1>
      <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
        <span class="adh-role-badge role-<?= h($adherent['role']) ?>"><?= h(role_label($adherent['role'])) ?></span>
        <?php if ($adherent['can_create_projects']): ?>
          <span class="adh-role-badge" style="background: var(--acc-light); color: var(--acc-dark);">Peut créer des projets</span>
        <?php endif; ?>
        <?php if (!$adherent['is_active']): ?>
          <span class="adh-role-badge status-rejected">Inactif</span>
        <?php endif; ?>
      </div>
    </div>
    <?php if ($can_edit || $can_message || $can_delete || $can_invite): ?>
    <div class="head-actions" style="display:flex; gap:8px; flex-wrap:wrap; position:relative;">
      <?php if ($can_edit): ?>
      <a href="/modifier-adherent/<?= (int)$adherent['id'] ?>" class="btn btn-ghost">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
        Modifier
      </a>
      <?php endif; ?>
      <?php if ($can_message): ?>
      <a href="mailto:<?= h($adherent['email']) ?>?subject=<?= rawurlencode('Message de la part de ' . $current['first_name'] . ' ' . $current['last_name']) ?>" class="btn btn-ghost" title="Ouvre votre app mail">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16v14H5.17L4 19.17z"/></svg>
        Envoyer un email
      </a>
      <?php endif; ?>

      <?php if ($can_invite): ?>
      <form method="POST" action="/adherent?id=<?= (int)$adherent['id'] ?>" style="display:inline;">
        <input type="hidden" name="csrf_token" value="<?= h(function_exists('csrf_token') ? csrf_token() : (empty($_SESSION['csrf_token']) ? ($_SESSION['csrf_token'] = bin2hex(random_bytes(32))) : $_SESSION['csrf_token'])) ?>">
        <input type="hidden" name="action" value="send_invite">
        <button type="submit" class="btn btn-ghost" title="Envoie à <?= h($adherent['email']) ?> un lien pour créer son mot de passe (valable 7 jours)">
          <?= ak_icon('send', 14) ?>
          Envoyer l’invitation
        </button>
      </form>
      <?php endif; ?>

      <?php if ($can_delete): ?>
      <a href="/supprimer-adherent/<?= (int)$adherent['id'] ?>" class="btn" style="background:#FEE2E2; color:#991B1B; border:1px solid #FECACA;">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
        Supprimer
      </a>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </div>

  <?php if (!$can_see_contacts): ?>
  <div class="list" style="margin-bottom: 32px;">
    <div class="list-row" style="grid-template-columns: auto 1fr; cursor: default; color: var(--ink-3); font-size: 13px;">
      <span><?= ak_icon('lock', 16) ?></span>
      <span>Les coordonnées des membres sont réservées aux administrateurs et coordinateurs de l’association.</span>
    </div>
  </div>
  <?php else: ?>
  <!-- Infos de contact -->
  <div class="section-head">
    <h2>Coordonnées</h2>
  </div>

  <div class="list" style="margin-bottom: 32px;">
    <div class="list-row" style="grid-template-columns: 140px 1fr; cursor: default;">
      <span style="font-size: 12px; color: var(--ink-3);">Email</span>
      <a href="mailto:<?= h($adherent['email']) ?>" style="color: var(--acc); font-size: 14px; font-weight: 500;"><?= h($adherent['email']) ?></a>
    </div>
    <?php if ($adherent['phone']): ?>
    <div class="list-row" style="grid-template-columns: 140px 1fr; cursor: default;">
      <span style="font-size: 12px; color: var(--ink-3);">Téléphone</span>
      <a href="tel:<?= h(preg_replace('/\s+/', '', $adherent['phone'])) ?>" style="color: var(--acc); font-size: 14px; font-weight: 500;"><?= h($adherent['phone']) ?></a>
    </div>
    <?php endif; ?>
    <?php if ($adherent['city']): ?>
    <div class="list-row" style="grid-template-columns: 140px 1fr; cursor: default;">
      <span style="font-size: 12px; color: var(--ink-3);">Ville</span>
      <span style="font-size: 14px;"><?= h($adherent['city']) ?></span>
    </div>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <!-- Adhésion -->
  <div class="section-head">
    <h2>Adhésion</h2>
  </div>

  <div class="stats-bar" style="margin-bottom: 32px;">
    <div class="metric">
      <div class="metric-lbl">Adhérent depuis</div>
      <div class="metric-val" style="font-size: 18px;"><?= h(format_date_fr_long($adherent['adhesion_date'])) ?></div>
    </div>
    <div class="metric">
      <div class="metric-lbl">Valide jusqu'au</div>
      <div class="metric-val" style="font-size: 18px;"><?= h(format_date_fr_long($adherent['adhesion_valid_until'])) ?></div>
    </div>
    <?php if ($can_see_contacts): ?>
    <div class="metric">
      <div class="metric-lbl">Dernière connexion</div>
      <div class="metric-val" style="font-size: 18px;">
        <?= $adherent['last_login_at'] ? h(format_date_fr_long($adherent['last_login_at'])) : 'Jamais' ?>
      </div>
    </div>
    <?php endif; ?>
  </div>

  <!-- Projets dont il est référent -->
  <?php if (!empty($ref_projects)): ?>
  <div class="section-head">
    <h2>Projets dont <?= h($adherent['first_name']) ?> est référent</h2>
    <div class="section-head-meta"><?= count($ref_projects) ?> projet<?= count($ref_projects) > 1 ? 's' : '' ?></div>
  </div>

  <div class="my-proj-grid" style="margin-bottom: 32px;">
    <?php foreach ($ref_projects as $p): ?>
    <a href="/projet/<?= (int)$p['id'] ?>" class="mp-card">
      <div class="mp-head">
        <div class="mp-title-wrap">
          <div class="mp-folder-tag"><?= h($p['folder_name']) ?></div>
          <div class="mp-title"><?= h($p['name']) ?></div>
        </div>
        <?php
          $status_class = $p['status'] === 'warning' ? 'badge-warn' : ($p['status'] === 'done' ? 'badge-done' : 'badge-ok');
          $status_label = ['warning' => 'À surveiller', 'done' => 'Terminé', 'active' => 'En cours', 'archived' => 'Archivé'][$p['status']] ?? 'En cours';
        ?>
        <span class="project-badge <?= $status_class ?>"><?= h($status_label) ?></span>
      </div>
      <div class="mp-progress">
        <div class="mp-bar-bg"><div class="mp-bar" style="width:<?= (int)$p['progress_percent'] ?>%"></div></div>
        <span class="mp-pct"><?= (int)$p['progress_percent'] ?> %</span>
      </div>
    </a>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

</div>

<?php render_foot(); ?>
