<?php
/**
 * ============================================================
 * ASSOKIT — Page Abonnement (v2 — 5.2.2 + 5.2.3 + 5.2.4)
 * URL : /abonnement
 * ============================================================
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes-layout.php';
require_login();
$current = current_user();
$user_id = (int)$current['id'];
$org_id  = (int)($current['org_id'] ?? 0);

$tab = $_GET['tab'] ?? 'mon-abonnement';
$valid_tabs = ['mon-abonnement', 'plans', 'factures', 'danger'];
if (!in_array($tab, $valid_tabs, true)) $tab = 'mon-abonnement';

// Subscription
$subscription = null;
if ($org_id > 0) {
    $stmt = $pdo->prepare("SELECT * FROM subscriptions WHERE org_id = ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$org_id]);
    $subscription = $stmt->fetch();
}

// Plans visibles, ordre prix croissant
$plans = $pdo->query("SELECT * FROM asso_plans WHERE is_visible = 1 ORDER BY price_cents ASC")->fetchAll();

// Plan courant (match slug)
$current_plan = null;
if ($subscription) {
    foreach ($plans as $p) {
        if ($p['slug'] === ($subscription['plan'] ?? '')) { $current_plan = $p; break; }
    }
}

// Factures
$invoices = [];
if ($org_id > 0) {
    $stmt = $pdo->prepare("SELECT * FROM subscription_invoices WHERE org_id = ? ORDER BY id DESC");
    $stmt->execute([$org_id]);
    $invoices = $stmt->fetchAll();
}

// Org
$org_full = null;
if ($org_id > 0) {
    $stmt = $pdo->prepare("SELECT * FROM organizations WHERE id = ? LIMIT 1");
    $stmt->execute([$org_id]);
    $org_full = $stmt->fetch();
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$flash      = $_GET['flash'] ?? '';
$flash_type = $_GET['ft'] ?? 'success';

// === Helpers locaux ===
function ab_date_fr($d) {
    if (!$d) return '—';
    $ts = strtotime($d);
    return $ts === false ? h($d) : date('d/m/Y', $ts);
}
function ab_status_badge($s) {
    $map = [
        'active'    => ['#D1FAE5', '#065F46', '✓ Actif'],
        'trial'     => ['#DBEAFE', '#1E40AF', '🎁 Essai gratuit'],
        'cancelled' => ['#FEE2E2', '#991B1B', '⨯ Annulé'],
        'suspended' => ['#FEF3C7', '#92400E', '⏸ Suspendu'],
        'past_due'  => ['#FEE2E2', '#991B1B', '⚠ En retard'],
    ];
    $i = $map[$s] ?? ['#F4F4F5', '#3F3F46', h($s ?? '—')];
    return '<span style="display:inline-block;padding:4px 10px;background:'.$i[0].';color:'.$i[1].';border-radius:999px;font-size:12px;font-weight:600;">'.$i[2].'</span>';
}
function ab_trial_days_left($trial_ends_at) {
    if (empty($trial_ends_at)) return null;
    $end = strtotime($trial_ends_at);
    if (!$end) return null;
    $diff = $end - time();
    if ($diff <= 0) return 0;
    return (int) ceil($diff / 86400);
}
function ab_inv_badge($s) {
    $map = [
        'draft'     => ['#F4F4F5', '#3F3F46', 'Brouillon'],
        'sent'      => ['#DBEAFE', '#1E40AF', 'Envoyée'],
        'paid'      => ['#D1FAE5', '#065F46', '✓ Payée'],
        'overdue'   => ['#FEE2E2', '#991B1B', '⚠ Retard'],
        'cancelled' => ['#F4F4F5', '#3F3F46', '⨯ Annulée'],
    ];
    $i = $map[$s] ?? ['#F4F4F5', '#3F3F46', h($s ?? '—')];
    return '<span style="display:inline-block;padding:3px 8px;background:'.$i[0].';color:'.$i[1].';border-radius:999px;font-size:11px;font-weight:600;">'.$i[2].'</span>';
}

// === Features détaillées par plan (alignées avec page publique pricing) ===
$detailed_features = [
  'essentiel' => [
    'header' => 'INCLUS :',
    'items'  => [
      'Jusqu&rsquo;à <strong>30 contacts / adhérents / clients</strong>',
      'Jusqu&rsquo;à <strong>2 utilisateurs</strong>',
      '<strong>20 factures ou devis</strong> / mois',
      '<strong>20 générations IA</strong> / mois',
      '<strong>100 emails</strong> / mois',
      'Tableau de bord basique',
      'Gestion simple des contacts',
      'Espace sécurisé',
      'Support email',
    ],
  ],
  'pro' => [
    'header' => "TOUT D'ESSENTIEL, PLUS :",
    'items'  => [
      'Jusqu&rsquo;à <strong>400 adhérents actifs</strong>',
      '<strong>Contacts / clients illimités</strong>',
      'Jusqu&rsquo;à <strong>20 utilisateurs</strong>',
      '<strong>Facturation illimitée</strong>',
      '<strong>Devis illimités</strong> avec signature',
      'Factures récurrentes',
      '<strong>300 générations IA</strong> / mois',
      '<strong>2 000 emails</strong> / mois',
      'Modèles IA prêts à l&rsquo;emploi',
      'Relances automatiques clients / adhérents',
      'Gestion projets, tâches et suivis',
      'Documents IA : convocations, CR, courriers',
      'Statistiques avancées',
      'Exports PDF & Excel',
      'Sous-domaine personnalisé <em>tonasso.assokit.fr</em>',
      'Logo & couleurs personnalisables',
      'Domaine personnel : <strong>+10 € HT / mois</strong>',
      'Support prioritaire &lt; 24h',
    ],
  ],
  'sur-mesure' => [
    'header' => 'TOUT DE PRO, PLUS :',
    'items'  => [
      '<strong>Contacts / adhérents / clients illimités</strong>',
      '<strong>Utilisateurs illimités</strong>',
      'Volume IA personnalisé ou <strong>illimité</strong>',
      'Diffusion email sur volume personnalisé',
      '<strong>Domaine personnel inclus</strong>',
      '<strong>White-label</strong> possible',
      'Tableau de bord avancé personnalisé',
      'Exports avancés PDF, Excel et données comptables',
      'Accompagnement à la mise en place',
      'Formation de votre équipe',
      'Support dédié',
      'Développements spécifiques sur demande',
    ],
  ],
];

$active = 'abonnement';
render_head('Abonnement · Assokit');
render_sidebar($active);
?>
<div class="pa-wrap">
  <div class="pa-header">
    <h1 class="pa-title">💳 Abonnement</h1>
    <p class="pa-sub">Gérez votre formule, vos factures et votre compte Assokit.</p>
  </div>

  <?php if ($flash): ?>
    <div class="pa-flash pa-flash-<?= h($flash_type) ?>"><?= h($flash) ?></div>
  <?php endif; ?>

  <div class="pa-grid">
    <nav class="pa-tabs">
      <a href="?tab=mon-abonnement" class="pa-tab <?= $tab === 'mon-abonnement' ? 'is-active' : '' ?>">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg>
        <span>Mon abonnement</span>
      </a>
      <a href="?tab=plans" class="pa-tab <?= $tab === 'plans' ? 'is-active' : '' ?>">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
        <span>Plans disponibles</span>
      </a>
      <a href="/mon-asso-factures" class="pa-tab">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
        <span>Factures →</span>
      </a>
      <a href="?tab=danger" class="pa-tab <?= $tab === 'danger' ? 'is-active' : '' ?>">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#DC2626" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
        <span style="color:#DC2626;">Zone dangereuse</span>
      </a>
    </nav>

    <main class="pa-content">

      <?php if ($tab === 'mon-abonnement'): ?>
      <!-- ============ 5.2.2 MON ABONNEMENT ============ -->
      <?php
        $plan_name = $current_plan['name'] ?? ucfirst($subscription['plan'] ?? 'Plan personnalisé');
        $plan_tagline = $current_plan['tagline'] ?? '';
        $sub_status = $subscription['status'] ?? 'unknown';
        $sub_price = $subscription['price_ht'] ?? null;
        $sub_cycle = $subscription['billing_cycle'] ?? '';
        $cycle_label = $sub_cycle === 'yearly' ? 'an' : 'mois';
        // [trial-14j] Charge trial_ends_at depuis organizations
        $is_trial = ($sub_status === 'trial');
        $trial_end_date = null;
        $trial_days_left = null;
        if ($is_trial) {
            try {
                $_u = current_user();
                $_oid = (int)($_u['org_id'] ?? 0);
                if ($_oid) {
                    $_stmt = $pdo->prepare("SELECT trial_ends_at FROM organizations WHERE id = ?");
                    $_stmt->execute([$_oid]);
                    $trial_end_date = $_stmt->fetchColumn() ?: null;
                    $trial_days_left = ab_trial_days_left($trial_end_date);
                }
            } catch (Throwable $e) {}
        }
      ?>
      <?php if ($is_trial && $trial_days_left !== null): ?>
      <!-- [trial-14j] Banniere essai gratuit -->
      <div style="background:linear-gradient(135deg,#FEF3C7,#FDE68A);border:1px solid #F59E0B;border-radius:12px;padding:16px 20px;margin-bottom:18px;display:flex;align-items:center;justify-content:space-between;gap:14px;flex-wrap:wrap;">
        <div style="display:flex;align-items:center;gap:14px;">
          <span style="font-size:32px;line-height:1;">🎁</span>
          <div>
            <div style="font-weight:700;color:#78350F;font-size:15px;margin-bottom:2px;">Essai gratuit Pro en cours</div>
            <div style="color:#92400E;font-size:13px;">
              <?php if ($trial_days_left === 0): ?>
                Votre essai prend fin <strong>aujourd'hui</strong> · acces limite demain
              <?php elseif ($trial_days_left === 1): ?>
                Votre essai prend fin <strong>demain</strong> (<?= ab_date_fr($trial_end_date) ?>)
              <?php else: ?>
                Plus que <strong><?= $trial_days_left ?> jours</strong> · fin le <?= ab_date_fr($trial_end_date) ?>
              <?php endif; ?>
            </div>
          </div>
        </div>
        <a href="?tab=plans" style="background:#92400E;color:#fff;padding:10px 20px;border-radius:8px;text-decoration:none;font-weight:600;font-size:13.5px;white-space:nowrap;">⚡ Choisir une formule</a>
      </div>
      <?php endif; ?>
      <div class="pa-form-card" style="background:linear-gradient(135deg,#ECFDF5,#FFFFFF);border:1px solid #D1FAE5;">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:16px;flex-wrap:wrap;">
          <div>
            <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px;flex-wrap:wrap;">
              <?= ab_status_badge($sub_status) ?>
              <?php if ($is_trial && $trial_days_left !== null && $trial_days_left > 0): ?>
                <span style="padding:4px 10px;background:#DBEAFE;color:#1E40AF;border-radius:999px;font-size:12px;font-weight:600;">
                  ⏱ <?= $trial_days_left ?> jour<?= $trial_days_left > 1 ? 's' : '' ?> restant<?= $trial_days_left > 1 ? 's' : '' ?>
                </span>
              <?php endif; ?>
              <?php if ($current_plan && $current_plan['is_featured']): ?>
                <span style="padding:4px 10px;background:#FEF3C7;color:#92400E;border-radius:999px;font-size:12px;font-weight:600;">⭐ Le plus choisi</span>
              <?php endif; ?>
            </div>
            <h2 style="margin:0 0 4px;font-size:26px;font-weight:700;color:#0A0A0B;">Plan <?= h($plan_name) ?></h2>
            <?php if ($plan_tagline): ?>
              <p style="margin:0;color:#71717A;font-size:14px;"><?= h($plan_tagline) ?></p>
            <?php endif; ?>
          </div>
          <div style="text-align:right;">
            <div style="font-size:30px;font-weight:700;color:#059669;line-height:1;">
              <?= $sub_price !== null ? number_format((float)$sub_price, 2, ',', ' ') . ' €' : '—' ?>
            </div>
            <div style="font-size:12px;color:#71717A;margin-top:4px;">HT / <?= h($cycle_label) ?></div>
          </div>
        </div>
      </div>

      <div class="pa-form-card">
        <h3 style="margin:0 0 14px;font-size:16px;font-weight:600;color:#0A0A0B;">Détails de la période</h3>
        <div class="pa-info-row"><span class="pa-info-label">Période en cours</span><span class="pa-info-value"><?= ab_date_fr($subscription['current_period_start'] ?? null) ?> → <?= ab_date_fr($subscription['current_period_end'] ?? null) ?></span></div>
        <div class="pa-info-row"><span class="pa-info-label">Prochain renouvellement</span><span class="pa-info-value"><?= ab_date_fr($subscription['current_period_end'] ?? null) ?></span></div>
        <div class="pa-info-row"><span class="pa-info-label">Démarré le</span><span class="pa-info-value"><?= ab_date_fr($subscription['started_at'] ?? null) ?></span></div>
        <?php if ($is_trial && $trial_end_date): ?>
        <div class="pa-info-row" style="background:#EFF6FF;border-radius:6px;padding:6px 10px;margin:4px -10px;">
          <span class="pa-info-label" style="color:#1E40AF;font-weight:600;">🎁 Fin de l'essai gratuit</span>
          <span class="pa-info-value" style="color:#1E40AF;font-weight:700;"><?= ab_date_fr($trial_end_date) ?><?php if ($trial_days_left !== null && $trial_days_left > 0): ?> · <?= $trial_days_left ?> jour<?= $trial_days_left > 1 ? 's' : '' ?> restant<?= $trial_days_left > 1 ? 's' : '' ?><?php endif; ?></span>
        </div>
        <?php endif; ?>
        <?php if (!empty($subscription['cancelled_at'])): ?>
        <div class="pa-info-row"><span class="pa-info-label">Annulé le</span><span class="pa-info-value" style="color:#DC2626;"><?= ab_date_fr($subscription['cancelled_at']) ?></span></div>
        <?php endif; ?>
        <div class="pa-info-row"><span class="pa-info-label">Cycle de facturation</span><span class="pa-info-value"><?= $sub_cycle === 'yearly' ? 'Annuel' : 'Mensuel' ?></span></div>
      </div>

      <?php if ($current_plan): ?>
      <div class="pa-form-card">
        <h3 style="margin:0 0 14px;font-size:16px;font-weight:600;color:#0A0A0B;">Inclus dans votre formule</h3>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px;">
          <?php
            $lim = [
              'limit_adherents' => 'adhérents',
              'limit_users' => 'utilisateurs',
              'limit_invoices_total' => 'factures',
              'limit_quotes_total' => 'devis',
              'limit_contacts' => 'contacts',
              'limit_emails_per_month' => 'emails / mois',
              'limit_ai_text_per_month' => 'requêtes IA texte / mois',
              'limit_ai_image_per_month' => 'images IA / mois',
            ];
            foreach ($lim as $k => $l):
              $v = $current_plan[$k] ?? null;
              if ($v === null) continue;
              $disp = (int)$v === 0 ? 'Illimité' : number_format((int)$v, 0, ',', ' ');
          ?>
            <div style="display:flex;align-items:center;gap:8px;font-size:14px;color:#3F3F46;">
              <span style="color:#10B981;font-weight:700;">✓</span><strong><?= $disp ?></strong> <?= h($l) ?>
            </div>
          <?php endforeach; ?>
          <?php
            $bf = [
              'feature_recurring_invoices' => 'Factures récurrentes',
              'feature_signature_quotes' => 'Signature électronique devis',
              'feature_email_diffusion' => 'Diffusion email',
              'feature_advanced_stats' => 'Statistiques avancées',
              'feature_priority_support' => 'Support prioritaire',
              'feature_custom_domain' => 'Domaine personnalisé',
              'feature_dedicated_support' => 'Support dédié',
            ];
            foreach ($bf as $k => $l):
              if (empty($current_plan[$k])) continue;
          ?>
            <div style="display:flex;align-items:center;gap:8px;font-size:14px;color:#3F3F46;">
              <span style="color:#10B981;font-weight:700;">✓</span> <?= h($l) ?>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>

      <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:16px;">
        <a href="?tab=plans" style="display:inline-flex;align-items:center;gap:6px;text-decoration:none;background:#059669;color:#fff;padding:10px 18px;border-radius:10px;font-size:14px;font-weight:600;">⚡ Voir tous les plans</a>
        <a href="?tab=factures" style="display:inline-flex;align-items:center;gap:6px;text-decoration:none;background:#fff;color:#3F3F46;border:1px solid #E4E4E7;padding:10px 18px;border-radius:10px;font-size:14px;font-weight:600;">📄 Voir mes factures</a>
      </div>

      <?php elseif ($tab === 'plans'): ?>
      <!-- ============ 5.2.3 PLANS DISPONIBLES ============ -->
      <div>
        <h2 class="pa-form-h2">Plans Assokit disponibles</h2>
        <p class="pa-form-sub">Choisissez la formule qui correspond à vos besoins. Sans engagement, modifiable à tout moment.</p>

        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:18px;margin-top:32px;">
          <?php foreach ($plans as $p):
            $is_current = $current_plan && $current_plan['slug'] === $p['slug'];
            $is_featured = !empty($p['is_featured']);
            $is_custom = !empty($p['is_custom_quote']);
            $card_border = $is_featured ? '2px solid #F59E0B' : '1px solid #E4E4E7';
            $card_bg = $is_current ? 'linear-gradient(135deg,#ECFDF5,#FFFFFF)' : '#fff';
          ?>
          <div style="position:relative;padding:20px;background:<?= $card_bg ?>;border:<?= $card_border ?>;border-radius:14px;display:flex;flex-direction:column;gap:12px;">
            <?php if ($is_featured): ?>
              <div style="position:absolute;top:-10px;left:50%;transform:translateX(-50%);background:#F59E0B;color:#fff;font-size:11px;font-weight:700;padding:3px 10px;border-radius:999px;letter-spacing:0.05em;">⭐ LE PLUS CHOISI</div>
            <?php endif; ?>
            <?php if ($is_current): ?>
              <div style="position:absolute;top:14px;right:14px;background:#10B981;color:#fff;font-size:10px;font-weight:700;padding:3px 8px;border-radius:999px;">ACTIF</div>
            <?php endif; ?>
            <div>
              <h3 style="margin:0 0 4px;font-size:18px;font-weight:700;color:#0A0A0B;"><?= h($p['name']) ?></h3>
              <p style="margin:0;font-size:12px;color:#71717A;"><?= h($p['tagline'] ?? '') ?></p>
            </div>
            <div style="margin:6px 0;">
              <?php if ($is_custom): ?>
                <div style="font-size:22px;font-weight:700;color:#0A0A0B;">Sur devis</div>
                <div style="font-size:11px;color:#71717A;">contact direct</div>
              <?php elseif ((int)$p['price_cents'] === 0): ?>
                <div style="font-size:28px;font-weight:700;color:#059669;">Gratuit</div>
                <div style="font-size:11px;color:#71717A;">à vie</div>
              <?php else: ?>
                <div style="font-size:24px;font-weight:700;color:#0A0A0B;"><?= number_format((int)$p['price_cents'] / 100, 2, ',', ' ') ?> €</div>
                <div style="font-size:11px;color:#71717A;">HT / mois</div>
                <?php if ((int)$p['price_yearly_cents'] > 0): ?>
                  <div style="font-size:11px;color:#10B981;margin-top:2px;">soit <?= number_format((int)$p['price_yearly_cents'] / 100, 0, ',', ' ') ?> € HT / an</div>
                <?php endif; ?>
              <?php endif; ?>
            </div>
            <?php $df = $detailed_features[$p['slug']] ?? null; ?>
            <?php if ($df): ?>
              <div style="margin:4px 0 14px;flex:1;">
                <div style="font-size:10px;font-weight:700;color:#71717A;text-transform:uppercase;letter-spacing:0.06em;margin-bottom:10px;"><?= h($df['header']) ?></div>
                <ul style="list-style:none;padding:0;margin:0;font-size:12px;color:#3F3F46;line-height:1.55;">
                  <?php foreach ($df['items'] as $item): ?>
                    <li style="display:flex;align-items:flex-start;gap:6px;margin-bottom:6px;">
                      <span style="color:#10B981;flex-shrink:0;margin-top:1px;font-weight:700;">✓</span>
                      <span><?= $item ?></span>
                    </li>
                  <?php endforeach; ?>
                </ul>
              </div>
            <?php else: ?>
              <ul style="list-style:none;padding:0;margin:8px 0;font-size:13px;color:#3F3F46;line-height:1.7;">
                <li><strong><?= $p['limit_adherents'] === null ? '∞' : number_format((int)$p['limit_adherents'], 0, ',', ' ') ?></strong> adhérents</li>
                <li><strong><?= $p['limit_users'] === null ? '∞' : number_format((int)$p['limit_users'], 0, ',', ' ') ?></strong> utilisateurs</li>
              </ul>
            <?php endif; ?>
            <div style="margin-top:auto;">
              <?php if ($is_current): ?>
                <button disabled style="width:100%;padding:10px;background:#E4E4E7;color:#71717A;border:0;border-radius:10px;font-size:13px;font-weight:600;cursor:not-allowed;">Formule actuelle</button>
              <?php elseif ($is_custom): ?>
                <a href="mailto:contact@assokit.fr?subject=Demande%20de%20devis%20Sur-mesure" style="display:block;width:100%;padding:10px;background:#0A0A0B;color:#fff;border-radius:10px;font-size:13px;font-weight:600;text-align:center;text-decoration:none;">Demander un devis</a>
              <?php else: ?>
                <a href="mailto:contact@assokit.fr?subject=Changement%20vers%20<?= urlencode($p['name']) ?>" style="display:block;width:100%;padding:10px;background:<?= $is_featured ? '#F59E0B' : '#059669' ?>;color:#fff;border-radius:10px;font-size:13px;font-weight:600;text-align:center;text-decoration:none;">Demander cette formule</a>
              <?php endif; ?>
            </div>
          </div>
          <?php endforeach; ?>
        </div>

        <div style="margin-top:24px;padding:14px;background:#FEF3C7;border:1px solid #FBBF24;border-radius:10px;font-size:13px;color:#92400E;line-height:1.5;">
          <strong>ℹ️ Note :</strong> Le paiement par carte sera disponible prochainement. En attendant, contactez <a href="mailto:contact@assokit.fr" style="color:#92400E;text-decoration:underline;">contact@assokit.fr</a> pour changer de formule.
        </div>
      </div>

      <?php elseif ($tab === 'factures'): ?>
      <!-- ============ 5.2.4 FACTURES ============ -->
      <div class="pa-form-card">
        <h2 class="pa-form-h2">Factures Assokit</h2>
        <p class="pa-form-sub">Historique de vos factures. Téléchargez chaque facture en PDF.</p>

        <?php if (empty($invoices)): ?>
          <div style="padding:30px;text-align:center;color:#71717A;font-size:14px;background:#FAFAF9;border-radius:10px;margin-top:16px;">Aucune facture pour le moment.</div>
        <?php else: ?>
          <div style="margin-top:16px;overflow-x:auto;">
            <table style="width:100%;border-collapse:collapse;font-size:13px;">
              <thead>
                <tr style="background:#FAFAF9;border-bottom:1px solid #E4E4E7;">
                  <th style="padding:10px;text-align:left;font-weight:600;color:#3F3F46;">N° Facture</th>
                  <th style="padding:10px;text-align:left;font-weight:600;color:#3F3F46;">Période</th>
                  <th style="padding:10px;text-align:right;font-weight:600;color:#3F3F46;">TTC</th>
                  <th style="padding:10px;text-align:center;font-weight:600;color:#3F3F46;">Statut</th>
                  <th style="padding:10px;text-align:center;font-weight:600;color:#3F3F46;">Émise le</th>
                  <th style="padding:10px;text-align:center;font-weight:600;color:#3F3F46;">PDF</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($invoices as $inv): ?>
                  <tr style="border-bottom:1px solid #F4F4F5;">
                    <td style="padding:10px;font-weight:600;color:#0A0A0B;"><?= h($inv['invoice_number'] ?? '—') ?></td>
                    <td style="padding:10px;color:#3F3F46;font-size:12px;"><?= ab_date_fr($inv['period_start']) ?> → <?= ab_date_fr($inv['period_end']) ?></td>
                    <td style="padding:10px;text-align:right;font-weight:600;color:#0A0A0B;"><?= number_format((float)($inv['amount_ttc'] ?? 0), 2, ',', ' ') ?> €</td>
                    <td style="padding:10px;text-align:center;"><?= ab_inv_badge($inv['status']) ?></td>
                    <td style="padding:10px;text-align:center;color:#71717A;font-size:12px;"><?= ab_date_fr($inv['issued_at']) ?></td>
                    <td style="padding:10px;text-align:center;"><a href="/facture-abonnement-pdf?id=<?= (int)$inv['id'] ?>" target="_blank" style="display:inline-block;padding:5px 10px;background:#059669;color:#fff;border-radius:6px;font-size:11px;font-weight:600;text-decoration:none;">📥 PDF</a></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>

      <?php elseif ($tab === 'danger'): ?>
      <!-- ============ 5.2.5 ZONE DANGEREUSE ============ -->
      <?php
        $is_admin_user = ($current['role'] ?? '') === 'admin';
        $sub_status = $subscription['status'] ?? '';
        $is_cancelled = $sub_status === 'cancelled';
        $org_already_deleted = !empty($org_full['deleted_at']);
      ?>

      <?php if (!$is_admin_user): ?>
        <div class="pa-form-card">
          <h2 class="pa-form-h2">🔒 Accès restreint</h2>
          <p>Seul un administrateur de l'organisation peut accéder à la zone dangereuse.</p>
        </div>
      <?php else: ?>

      <!-- === Section 1 : Annuler abonnement === -->
      <div class="pa-form-card" style="border-left:4px solid #F59E0B;">
        <h2 class="pa-form-h2">⏸ Annuler mon abonnement</h2>
        <?php if ($is_cancelled): ?>
          <div style="padding:14px;background:#FEF3C7;border-radius:10px;font-size:13px;color:#92400E;">
            <strong>Abonnement déjà annulé</strong> le <?= ab_date_fr($subscription['cancelled_at'] ?? null) ?>.
            <?php if (!empty($subscription['cancellation_reason'])): ?>
              <br>Raison : <em><?= h($subscription['cancellation_reason']) ?></em>
            <?php endif; ?>
          </div>
        <?php else: ?>
          <p class="pa-form-sub">
            Votre abonnement sera annulé immédiatement. Vous gardez l'accès jusqu'à la fin de la période en cours
            (<strong><?= ab_date_fr($subscription['current_period_end'] ?? null) ?></strong>).
          </p>
          <form method="POST" action="/action-abonnement" onsubmit="return confirm('Êtes-vous sûr de vouloir annuler votre abonnement ?\n\nVous garderez l\'accès jusqu\'au <?= ab_date_fr($subscription['current_period_end'] ?? null) ?>.');" style="margin-top:14px;">
            <input type="hidden" name="action" value="cancel_subscription">
            <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf_token']) ?>">
            <label style="display:block;font-size:12px;font-weight:600;color:#3F3F46;margin-bottom:6px;">Raison de l'annulation (optionnel)</label>
            <textarea name="reason" maxlength="500" rows="3" placeholder="Ex: Trop cher, fonctionnalités manquantes, changement de besoin..." style="width:100%;padding:10px;border:1px solid #E4E4E7;border-radius:8px;font-family:inherit;font-size:13px;resize:vertical;"></textarea>
            <button type="submit" style="margin-top:12px;padding:10px 18px;background:#F59E0B;color:#fff;border:0;border-radius:10px;font-size:13px;font-weight:600;cursor:pointer;">⏸ Confirmer l'annulation</button>
          </form>
        <?php endif; ?>
      </div>

      <!-- === Section 2 : Supprimer compte === -->
      <div class="pa-form-card" style="border-left:4px solid #DC2626;margin-top:16px;">
        <h2 class="pa-form-h2" style="color:#DC2626;">⚠️ Supprimer définitivement mon compte</h2>
        <?php if ($org_already_deleted): ?>
          <div style="padding:14px;background:#FEE2E2;border-radius:10px;font-size:13px;color:#991B1B;">
            <strong>Compte déjà marqué pour suppression</strong> le <?= ab_date_fr($org_full['deleted_at']) ?>.
          </div>
        <?php else: ?>
          <div style="padding:14px;background:#FEF2F2;border:1px solid #FECACA;border-radius:10px;font-size:13px;color:#7F1D1D;line-height:1.6;margin-bottom:14px;">
            <strong>⚠️ Cette action est irréversible.</strong><br>
            Toutes les données de votre organisation seront archivées et inaccessibles :
            <ul style="margin:6px 0 0 18px;padding:0;">
              <li>Subventions, projets, adhérents, factures</li>
              <li>Utilisateurs liés à votre organisation</li>
              <li>Historiques, messages, documents</li>
            </ul>
          </div>
          <form method="POST" action="/action-abonnement" onsubmit="return confirm('⚠️ DERNIÈRE CONFIRMATION\n\nVoulez-vous vraiment supprimer DÉFINITIVEMENT votre compte ?\n\nVous serez déconnecté immédiatement.');">
            <input type="hidden" name="action" value="delete_account">
            <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf_token']) ?>">
            <label style="display:block;font-size:12px;font-weight:600;color:#3F3F46;margin-bottom:6px;">Pourquoi partez-vous ? (optionnel)</label>
            <textarea name="reason" maxlength="500" rows="3" placeholder="Votre retour nous aide à améliorer Assokit..." style="width:100%;padding:10px;border:1px solid #E4E4E7;border-radius:8px;font-family:inherit;font-size:13px;resize:vertical;margin-bottom:12px;"></textarea>

            <label style="display:block;font-size:12px;font-weight:600;color:#DC2626;margin-bottom:6px;">Tapez <strong>SUPPRIMER</strong> pour confirmer</label>
            <input type="text" name="confirm" id="ak-delete-confirm" autocomplete="off" required pattern="SUPPRIMER" style="width:100%;padding:10px;border:2px solid #FECACA;border-radius:8px;font-family:inherit;font-size:14px;font-weight:600;text-transform:uppercase;letter-spacing:0.1em;">

            <button type="submit" id="ak-delete-btn" disabled style="margin-top:14px;padding:10px 18px;background:#9CA3AF;color:#fff;border:0;border-radius:10px;font-size:13px;font-weight:600;cursor:not-allowed;transition:background 0.15s;">🗑 Supprimer définitivement</button>
          </form>
          <script>
            (function(){
              var inp = document.getElementById('ak-delete-confirm');
              var btn = document.getElementById('ak-delete-btn');
              if (!inp || !btn) return;
              inp.addEventListener('input', function(){
                var ok = inp.value.trim() === 'SUPPRIMER';
                btn.disabled = !ok;
                btn.style.background = ok ? '#DC2626' : '#9CA3AF';
                btn.style.cursor = ok ? 'pointer' : 'not-allowed';
              });
            })();
          </script>
        <?php endif; ?>
      </div>

      <?php endif; ?>

      <?php endif; ?>

    </main>
  </div>
</div>

<link rel="stylesheet" href="/css/pa-namespace.css">

<style>
/* ===== Abonnement — surcouche Liquid Glass 2.0 (page-scoped) ===== */
.pa-wrap { max-width: 1120px; padding: 28px 24px 60px; }
.pa-header { margin-bottom: 22px; }
.pa-title { font-size: 30px; font-weight: 800; letter-spacing: -0.03em; color: var(--ink, #0B1A13); line-height: 1.05; }
.pa-sub { color: var(--ink-3, #78857F); font-size: 14px; }
.pa-grid { gap: 26px; }

/* Onglets — pilules glass */
.pa-tabs { gap: 5px; }
.pa-tab { border: 1px solid transparent; border-radius: 12px; color: var(--ink-2, #45544D); font-weight: 600; padding: 11px 14px; transition: background .14s ease, color .14s ease, box-shadow .14s ease; }
.pa-tab svg { opacity: .85; }
.pa-tab:hover { background: var(--bg-2, #EDF2EF); color: var(--ink, #0B1A13); }
.pa-tab.is-active {
  background: var(--glass, rgba(255,255,255,0.72));
  border-color: var(--glass-border, rgba(255,255,255,0.65));
  color: var(--acc-dark, #047857); font-weight: 700;
  box-shadow: var(--shadow-card, 0 1px 2px rgba(9,30,22,0.04), 0 14px 34px -16px rgba(9,30,22,0.16));
  backdrop-filter: blur(14px) saturate(1.4); -webkit-backdrop-filter: blur(14px) saturate(1.4);
}
.pa-tab.is-active svg { stroke: var(--acc, #059669); opacity: 1; }

/* Cartes — verre dépoli */
.pa-form-card {
  background: var(--glass, rgba(255,255,255,0.72));
  border: 1px solid var(--glass-border, rgba(255,255,255,0.65));
  border-radius: var(--radius-lg, 18px);
  box-shadow: var(--shadow-card, 0 1px 2px rgba(9,30,22,0.04), 0 14px 34px -16px rgba(9,30,22,0.16));
  backdrop-filter: blur(22px) saturate(1.5); -webkit-backdrop-filter: blur(22px) saturate(1.5);
  padding: 24px 26px; margin-bottom: 16px;
}
.pa-form-h2 { font-size: 16px; font-weight: 750; color: var(--ink, #0B1A13); }
.pa-form-sub { color: var(--ink-3, #78857F); }

/* Carte "plan actuel" (hero) — accent premium */
.pa-content > .pa-form-card:first-child {
  border: 1px solid rgba(16,185,129,0.28) !important;
  box-shadow: 0 1px 2px rgba(9,30,22,0.04), 0 20px 44px -18px rgba(5,150,105,0.35) !important;
  position: relative; overflow: hidden;
}
.pa-content > .pa-form-card:first-child::before {
  content: ""; position: absolute; inset: 0 0 auto 0; height: 3px;
  background: linear-gradient(90deg,#34D399,#059669);
}

/* Lignes d'info */
.pa-info-row { border-bottom: 1px solid var(--border, rgba(12,40,28,0.07)); padding: 11px 0; }
.pa-info-label { color: var(--ink-3, #78857F); }
.pa-info-value { color: var(--ink, #0B1A13); font-weight: 650; }

/* Puces "Inclus dans la formule" */
.pa-content .pa-form-card [style*="grid-template-columns:repeat(auto-fit,minmax(220px"] > div {
  background: var(--bg-2, #EDF2EF); border-radius: 10px; padding: 9px 12px !important;
}
</style>

<?php render_foot(); ?>
