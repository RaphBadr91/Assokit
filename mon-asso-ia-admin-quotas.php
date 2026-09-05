<?php
/**
 * mon-asso-ia-admin-quotas.php
 * --------------------------------------------------------------
 * Page CONSULTATION quotas IA — Pack PHASE 4.6 v3
 *
 * Lecture seule. Aucune modification possible (système immuable).
 * Réservée aux fondateurs et super-admins.
 * --------------------------------------------------------------
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes-layout.php';
require_once __DIR__ . '/asso-ai-helpers.php';
require_once __DIR__ . '/asso-ai-quotas.php';

require_login();
$user = current_user();
$org_id = (int)($user['org_id'] ?? 0);
if ($org_id <= 0) { header('Location: /'); exit; }

// Accès strict : fondateur ou super-admin uniquement
if (!ak_ai_quota_can_view_admin($pdo, $user)) {
    header('Location: /mon-asso-ia');
    exit;
}

// Récupère les rôles configurés (hardcoded)
$defaults = ak_ai_quota_defaults();

// Filtre : on ne montre pas la ligne "*" (fallback technique)
$visible_roles = array_filter($defaults, fn($k) => $k !== '*', ARRAY_FILTER_USE_KEY);

// Heure du serveur pour info reset
$server_time = date('H:i');

render_head('Assokit IA — Quotas');
render_sidebar('ia');
?>

<main class="main">
  <style>
    .qa-page { font-family: 'Geist', system-ui, sans-serif; color: #0F172A; }

    /* Bandeau identique au hub IA */
    .ia-bar {
      display: flex; align-items: center; justify-content: space-between;
      background: white; border: 1px solid #E2E8F0; border-radius: 12px;
      padding: 12px 18px; margin-bottom: 22px; gap: 18px; flex-wrap: wrap;
    }
    .ia-bar-brand { display: flex; align-items: center; gap: 12px; min-width: 0; }
    .ia-bar-logo {
      width: 36px; height: 36px; border-radius: 9px;
      background: linear-gradient(135deg, #0F172A 0%, #7E22CE 100%);
      color: white; display: flex; align-items: center; justify-content: center;
      font-weight: 800; font-size: 15px; letter-spacing: -0.02em; flex-shrink: 0;
      box-shadow: 0 2px 8px rgba(126, 34, 206, 0.25);
    }
    .ia-bar-text { min-width: 0; }
    .ia-bar-title { font-size: 16px; font-weight: 700; letter-spacing: -0.01em; display: flex; align-items: center; gap: 8px; }
    .ia-bar-title .badge { font-size: 10px; font-weight: 700; padding: 2px 7px; border-radius: 999px; background: linear-gradient(135deg, #7E22CE, #EC4899); color: white; letter-spacing: 0.05em; text-transform: uppercase; }
    .ia-bar-sub { font-size: 12px; color: #64748B; margin-top: 1px; }

    .qa-breadcrumb { font-size: 13px; color: #64748B; margin-bottom: 14px; }
    .qa-breadcrumb a { color: #64748B; text-decoration: none; }

    .qa-intro {
      background: white; border: 1px solid #E2E8F0; border-radius: 14px;
      padding: 22px; margin-bottom: 18px; display: flex; gap: 16px; align-items: flex-start;
    }
    .qa-intro .ico { width: 44px; height: 44px; border-radius: 11px; background: #FEF3C7; color: #92400E; display: flex; align-items: center; justify-content: center; font-size: 22px; flex-shrink: 0; }
    .qa-intro h1 { margin: 0 0 6px; font-size: 20px; font-weight: 700; }
    .qa-intro p { margin: 0; font-size: 14px; color: #475569; line-height: 1.6; }

    .qa-info {
      background: linear-gradient(135deg, #F0F9FF, #EFF6FF);
      border: 1px solid #BFDBFE; border-radius: 12px;
      padding: 14px 18px; margin-bottom: 22px;
      font-size: 13px; color: #1E3A8A; line-height: 1.5;
      display: flex; gap: 12px; align-items: center;
    }
    .qa-info .ico-info { font-size: 22px; flex-shrink: 0; }

    /* Tableau récap */
    .qa-table {
      background: white; border: 1px solid #E2E8F0; border-radius: 14px;
      overflow: hidden;
    }
    .qa-table-row {
      display: grid; grid-template-columns: 1.4fr 1fr 1fr 0.8fr;
      gap: 14px; padding: 16px 22px;
      border-bottom: 1px solid #F1F5F9;
      align-items: center;
    }
    .qa-table-row:last-child { border-bottom: none; }
    .qa-table-head {
      background: #F8FAFC; padding-top: 12px; padding-bottom: 12px;
      font-size: 12px; font-weight: 700; color: #64748B;
      text-transform: uppercase; letter-spacing: 0.05em;
    }
    .qa-role { display: flex; align-items: center; gap: 10px; }
    .qa-role-ico { width: 32px; height: 32px; border-radius: 8px; display: flex; align-items: center; justify-content: center; font-size: 16px; flex-shrink: 0; }
    .qa-role-name { font-weight: 600; font-size: 14px; }
    .qa-role-key { font-size: 11px; color: #64748B; font-family: ui-monospace, Menlo, monospace; margin-top: 1px; }

    .qa-quota { font-weight: 700; font-size: 14px; color: #0F172A; }
    .qa-quota .unit { font-size: 12px; color: #64748B; font-weight: 500; }
    .qa-quota.unlimited { color: #92400E; }

    .qa-status { display: inline-flex; align-items: center; gap: 4px; padding: 4px 10px; border-radius: 999px; font-size: 12px; font-weight: 600; }
    .qa-status.yes { background: #D1FAE5; color: #065F46; }
    .qa-status.no  { background: #FEE2E2; color: #991B1B; }

    .qa-locked-pill { display: inline-flex; align-items: center; gap: 4px; padding: 3px 8px; border-radius: 999px; font-size: 11px; font-weight: 600; background: #F1F5F9; color: #64748B; }

    @media (max-width: 720px) {
      .ia-bar { padding: 10px 14px; }
      .qa-table-row { grid-template-columns: 1fr 1fr; }
      .qa-table-row > div:nth-child(3), .qa-table-row > div:nth-child(4) { display: none; }
      .qa-table-row.mobile-extra { display: grid; grid-template-columns: 1fr 1fr; }
      .qa-table-head > div:nth-child(3), .qa-table-head > div:nth-child(4) { display: none; }
    }
  </style>

  <div class="qa-page">

    <!-- Bandeau Assokit IA -->
    <div class="ia-bar">
      <div class="ia-bar-brand">
        <div class="ia-bar-logo">A</div>
        <div class="ia-bar-text">
          <div class="ia-bar-title">Assokit IA <span class="badge">Beta</span></div>
          <div class="ia-bar-sub">Quotas en vigueur</div>
        </div>
      </div>
    </div>

    <div class="qa-breadcrumb">
      <a href="/mon-asso-ia">Communication IA</a> &nbsp;›&nbsp; <strong style="color:#0F172A;">Quotas</strong>
    </div>

    <!-- Intro simple -->
    <div class="qa-intro">
      <div class="ico">🛡️</div>
      <div>
        <h1>Quotas IA en vigueur</h1>
        <p>Pour préserver une utilisation responsable, chaque rôle de l'association dispose d'un nombre de générations IA quotidien, par outil. Ces quotas sont fixes et ne peuvent pas être modifiés depuis l'application.</p>
      </div>
    </div>

    <!-- Bloc info reset auto -->
    <div class="qa-info">
      <div class="ico-info">🔄</div>
      <div>
        <strong>Renouvellement automatique chaque jour à minuit.</strong>
        Tous vos utilisateurs récupèrent leurs crédits quotidiens dès le passage à 00h00 (heure du serveur · il est <strong><?= h($server_time) ?></strong> actuellement). Aucune action n'est nécessaire.
      </div>
    </div>

    <!-- Tableau récap -->
    <div class="qa-table">
      <div class="qa-table-row qa-table-head">
        <div>Rôle</div>
        <div>Quota / jour / outil</div>
        <div>Génération d'images</div>
        <div>Statut</div>
      </div>

      <!-- Ligne spéciale Fondateur / super-admin -->
      <div class="qa-table-row">
        <div class="qa-role">
          <div class="qa-role-ico" style="background:#FEF3C7;color:#92400E;">👑</div>
          <div>
            <div class="qa-role-name">Fondateur · Super-admin</div>
            <div class="qa-role-key">illimité</div>
          </div>
        </div>
        <div class="qa-quota unlimited">∞ <span class="unit">illimité</span></div>
        <div><span class="qa-status yes">✓ Autorisée</span></div>
        <div><span class="qa-locked-pill">🔒 Fixé</span></div>
      </div>

      <?php
      // Icônes par rôle
      $role_icons = [
          'admin'       => ['🛡️', '#7E22CE', '#F3E8FF'],
          'manager'     => ['🛡️', '#7E22CE', '#F3E8FF'],
          'coordinator' => ['🤝', '#0EA5E9', '#E0F2FE'],
          'editor'      => ['✏️', '#0EA5E9', '#E0F2FE'],
          'member'      => ['👥', '#059669', '#D1FAE5'],
          'adherent'    => ['👥', '#059669', '#D1FAE5'],
          'follower'    => ['👤', '#64748B', '#F1F5F9'],
          'viewer'      => ['👁️', '#64748B', '#F1F5F9'],
      ];
      foreach ($visible_roles as $key => $cfg):
          $ic = $role_icons[$key] ?? ['👤', '#64748B', '#F1F5F9'];
      ?>
        <div class="qa-table-row">
          <div class="qa-role">
            <div class="qa-role-ico" style="background:<?= h($ic[2]) ?>;color:<?= h($ic[1]) ?>;"><?= $ic[0] ?></div>
            <div>
              <div class="qa-role-name"><?= h($cfg['label']) ?></div>
              <div class="qa-role-key"><?= h($key) ?></div>
            </div>
          </div>
          <div class="qa-quota">
            <?= (int)$cfg['daily_limit'] ?> <span class="unit">/ outil / jour</span>
          </div>
          <div>
            <?php if (!empty($cfg['allow_images'])): ?>
              <span class="qa-status yes">✓ Autorisée</span>
            <?php else: ?>
              <span class="qa-status no">✗ Bloquée</span>
            <?php endif; ?>
          </div>
          <div><span class="qa-locked-pill">🔒 Fixé</span></div>
        </div>
      <?php endforeach; ?>
    </div>

    <p style="font-size: 12px; color: #64748B; margin-top: 16px; text-align: center;">
      Les quotas sont définis par Assokit pour garantir l'équité d'accès.
    </p>

  </div>
</main>

<?php render_foot(); ?>
