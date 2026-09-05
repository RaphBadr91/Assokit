<?php
/**
 * mon-asso-plan.php — VERSION ENRICHIE PACK 6.4 STRIPE
 * --------------------------------------------------------------
 * Dashboard complet de l'abonnement client avec :
 *   - État actuel du plan (active/grace/overdue)
 *   - Méthode de paiement (Stripe ou manuel)
 *   - Bouton Régulariser dynamique (URL Stripe ou contact)
 *   - Aperçu des 3 dernières factures (lien vers liste complète)
 *   - Bouton "Gérer le paiement" / "Annuler l'abonnement"
 *   - Section "Changer de plan" (upgrade/downgrade)
 * --------------------------------------------------------------
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes-layout.php';
@require_once __DIR__ . '/plan-helpers.php';
@require_once __DIR__ . '/stripe-config-helpers.php';
@require_once __DIR__ . '/stripe-helpers.php';

require_login();
$user = current_user();
$org_id = (int)($user['org_id'] ?? 0);
if ($org_id <= 0) { header('Location: /'); exit; }

// ============================================================
// [PACK 6.5 - SECURITY STRICT] Contrôle accès finances obligatoire
// ============================================================
// ⚠️ POLITIQUE STRICTE : seuls Admin / Fondateur / Super Admin accèdent.
$can_view_finances = (
    in_array($user['role'] ?? '', ['admin', 'founder', 'super_admin'], true)
    || !empty($user['is_founder'])
    || !empty($user['is_super_admin'])
);

if (!$can_view_finances) {
    http_response_code(403);
    render_head('Accès refusé');
    render_sidebar('mon-asso-plan');
    echo '<main class="main"><div style="max-width:600px;margin:60px auto;padding:32px;background:white;border:1px solid #FECACA;border-radius:14px;text-align:center;">';
    echo '<div style="color:#64748B;margin-bottom:14px;">'.ak_icon('lock',44,'1.5').'</div>';
    echo '<h1 style="font-size:22px;color:#0F172A;margin:0 0 12px;">Accès réservé</h1>';
    echo '<p style="color:#64748B;font-size:14px;line-height:1.6;margin:0 0 22px;">L\'abonnement et les factures sont strictement réservés aux <strong>Administrateurs</strong> de l\'association.</p>';
    echo '<a href="/dashboard" style="display:inline-block;background:#0F172A;color:white;padding:11px 22px;border-radius:10px;text-decoration:none;font-weight:600;font-size:14px;">← Retour au dashboard</a>';
    echo '</div></main>';
    render_foot();
    exit;
}
// ============================================================


// Récupération état abonnement
$subscription_status = function_exists('ak_get_subscription_status') ? ak_get_subscription_status($pdo, $org_id) : ['status' => 'none', 'plan_name' => 'Démarrage'];
$active_sub = function_exists('ak_get_org_active_subscription') ? ak_get_org_active_subscription($pdo, $org_id) : null;
$pricing = function_exists('ak_calculate_total_monthly_price') ? ak_calculate_total_monthly_price($pdo, $org_id) : ['plan_cents' => 0, 'addons_cents' => 0, 'total_cents' => 0, 'addons' => []];

// Récupération 3 dernières factures
$recent_invoices = function_exists('ak_get_org_invoices_stripe') ? ak_get_org_invoices_stripe($pdo, $org_id, 3) : [];

// Détection capacités Stripe
$stripe_ready = function_exists('ak_stripe_is_configured') && ak_stripe_is_configured($pdo);
$has_stripe_subscription = $active_sub && !empty($active_sub['stripe_subscription_id']);
$payment_mode = $active_sub['payment_mode'] ?? 'manual';

// Tous les plans disponibles pour upgrade/downgrade
$plans = [];
try {
    $plans = $pdo->query("SELECT * FROM asso_plans WHERE is_visible = 1 ORDER BY price_cents ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

$current_plan_id = $active_sub['plan_id'] ?? null;

// URL "Régulariser" dynamique
$regularize_url = function_exists('ak_get_regularize_url') ? ak_get_regularize_url($pdo, $org_id) : '/contact?subject=billing';

render_head('Mon abonnement');
render_sidebar('mon-asso-plan');
?>

<main class="main">

  <nav class="crumbs">
    <a href="/dashboard">Dashboard</a>
    <span class="sep">›</span>
    <span class="current">💎 Mon abonnement</span>
  </nav>

  <div class="main-head" style="margin-bottom:24px;">
    <div>
      <h1 style="margin:0 0 4px;">💎 Mon abonnement</h1>
      <p style="color:#64748B;margin:0;">Gérez votre plan, vos factures et votre méthode de paiement</p>
    </div>
  </div>

  <?php if (!empty($_SESSION['flash_success'])): ?>
    <div style="background:#D1FAE5;border:1px solid #A7F3D0;border-radius:10px;padding:12px 16px;margin-bottom:18px;color:#065F46;font-size:14px;">
      ✅ <?= htmlspecialchars($_SESSION['flash_success']) ?>
    </div>
    <?php unset($_SESSION['flash_success']); ?>
  <?php endif; ?>

  <?php if (!empty($_SESSION['flash_error'])): ?>
    <div style="background:#FEE2E2;border:1px solid #FECACA;border-radius:10px;padding:12px 16px;margin-bottom:18px;color:#991B1B;font-size:14px;">
      ❌ <?= htmlspecialchars($_SESSION['flash_error']) ?>
    </div>
    <?php unset($_SESSION['flash_error']); ?>
  <?php endif; ?>

  <!-- ======= BANDEAU ÉTAT ABONNEMENT ======= -->
  <?php
  $st = $subscription_status['status'] ?? 'none';
  $is_active = ($st === 'active');
  $is_grace = !empty($subscription_status['in_grace_period']);
  $is_overdue = !empty($subscription_status['is_overdue']);
  $is_pending = !empty($subscription_status['is_pending']);
  $bg_color = $is_active ? '#D1FAE5' : ($is_grace || $is_pending ? '#FEF3C7' : ($is_overdue ? '#FEE2E2' : '#F1F5F9'));
  $border_color = $is_active ? '#A7F3D0' : ($is_grace || $is_pending ? '#FCD34D' : ($is_overdue ? '#FECACA' : '#E2E8F0'));
  $accent_color = $is_active ? '#047857' : ($is_grace || $is_pending ? '#92400E' : ($is_overdue ? '#991B1B' : '#475569'));
  $emoji = $is_active ? '✅' : ($is_grace || $is_pending ? '⏳' : ($is_overdue ? '⚠️' : '📋'));
  ?>

  <div style="background:<?= $bg_color ?>;border:1px solid <?= $border_color ?>;border-radius:14px;padding:24px 28px;margin-bottom:24px;display:flex;align-items:center;gap:20px;flex-wrap:wrap;">
    <div style="font-size:40px;flex-shrink:0;"><?= $emoji ?></div>
    <div style="flex:1;min-width:240px;">
      <div style="font-size:11px;color:<?= $accent_color ?>;font-weight:700;text-transform:uppercase;letter-spacing:0.05em;margin-bottom:4px;">Plan actuel</div>
      <div style="font-size:22px;font-weight:700;color:<?= $accent_color ?>;line-height:1.2;">
        <?= htmlspecialchars($subscription_status['plan_name'] ?? 'Démarrage') ?>
        <?php if ($pricing['total_cents'] > 0): ?>
          · <span style="font-size:18px;"><?= ak_format_price_cents($pricing['total_cents']) ?>/mois</span>
        <?php endif; ?>
      </div>
      <div style="font-size:13px;color:<?= $accent_color ?>;margin-top:4px;opacity:0.9;">
        <?php if ($is_active): ?>
          ✅ Abonnement actif · Renouvellement automatique
        <?php elseif ($is_pending): ?>
          ⏳ Paiement en attente · <?= (int)($subscription_status['days_remaining'] ?? 0) ?> jours de tolérance
        <?php elseif ($is_grace): ?>
          ⏳ Période de grâce · Régularisez sous <?= (int)($subscription_status['days_remaining'] ?? 0) ?> jours
        <?php elseif ($is_overdue): ?>
          ⚠️ Paiement en retard · Régularisez pour conserver vos accès
        <?php else: ?>
          📋 Plan gratuit
        <?php endif; ?>
      </div>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap;">
      <?php if ($is_grace || $is_pending || $is_overdue): ?>
        <a href="<?= htmlspecialchars($regularize_url) ?>" style="display:inline-flex;align-items:center;gap:7px;background:#EA580C;color:white;padding:11px 20px;border-radius:10px;text-decoration:none;font-weight:600;font-size:14px;"><?= ak_icon('credit-card',14) ?>Régulariser</a>
      <?php endif; ?>
      <?php if ($st === 'none' || $st === 'cancelled'): ?>
        <a href="/mon-asso-paiement?plan=assokit" style="display:inline-flex;align-items:center;gap:7px;background:linear-gradient(180deg,#059669 0%,#047857 100%);color:white;padding:11px 22px;border-radius:10px;text-decoration:none;font-weight:600;font-size:14px;"><?= ak_icon('star-fill',14) ?>Passer à Assokit</a>
      <?php endif; ?>
    </div>
  </div>

  <!-- ======= GRILLE PRINCIPALE ======= -->
  <div style="display:grid;grid-template-columns:2fr 1fr;gap:20px;align-items:start;">

    <!-- COLONNE GAUCHE -->
    <div style="display:flex;flex-direction:column;gap:20px;">

      <!-- Détail prix -->
      <?php if ($pricing['total_cents'] > 0): ?>
      <div style="background:white;border:1px solid #E2E8F0;border-radius:14px;padding:22px 24px;">
        <h3 style="margin:0 0 14px;font-size:16px;display:inline-flex;align-items:center;gap:8px;"><?= ak_icon('euro',18) ?>Détail du tarif</h3>
        <div style="display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid #F1F5F9;font-size:14px;">
          <div>Plan <?= htmlspecialchars($subscription_status['plan_name'] ?? '') ?></div>
          <div style="font-weight:600;"><?= ak_format_price_cents($pricing['plan_cents']) ?>/mois</div>
        </div>
        <?php foreach ($pricing['addons'] as $addon): ?>
        <div style="display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid #F1F5F9;font-size:14px;">
          <div style="color:#1E40AF;">🏆 <?= htmlspecialchars($addon['addon_name']) ?></div>
          <div style="font-weight:600;color:#1E40AF;">+<?= ak_format_price_cents((int)$addon['price_cents_monthly']) ?>/mois</div>
        </div>
        <?php endforeach; ?>
        <div style="display:flex;justify-content:space-between;padding:12px 0 0;border-top:2px solid #0F172A;margin-top:6px;font-size:15px;font-weight:700;">
          <div>Total mensuel</div>
          <div style="color:#059669;font-size:18px;"><?= ak_format_price_cents($pricing['total_cents']) ?></div>
        </div>
      </div>
      <?php endif; ?>

      <!-- Aperçu factures -->
      <?php if (!empty($recent_invoices)): ?>
      <div style="background:white;border:1px solid #E2E8F0;border-radius:14px;padding:22px 24px;">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;">
          <h3 style="margin:0;font-size:16px;display:inline-flex;align-items:center;gap:8px;"><?= ak_icon('file-text',18) ?>Mes dernières factures</h3>
          <a href="/mon-asso-factures" style="font-size:13px;color:#059669;text-decoration:none;font-weight:600;">Voir toutes →</a>
        </div>
        <table style="width:100%;border-collapse:collapse;font-size:14px;">
          <?php foreach ($recent_invoices as $inv): ?>
          <tr style="border-top:1px solid #F1F5F9;">
            <td style="padding:10px 0;">
              <div style="font-weight:600;"><?= htmlspecialchars($inv['invoice_number'] ?? 'INV-' . $inv['id']) ?></div>
              <div style="font-size:12px;color:#64748B;"><?= htmlspecialchars(date('d/m/Y', strtotime($inv['created_at']))) ?></div>
            </td>
            <td style="padding:10px 0;text-align:right;">
              <div style="font-weight:700;"><?= ak_format_price_cents((int)$inv['amount_cents']) ?></div>
              <?php
              $st_color = '#94A3B8'; $st_bg = '#F1F5F9'; $st_label = $inv['status'];
              if ($inv['status'] === 'paid') { $st_color = '#065F46'; $st_bg = '#D1FAE5'; $st_label = 'Payée'; }
              elseif ($inv['status'] === 'open') { $st_color = '#92400E'; $st_bg = '#FEF3C7'; $st_label = 'En attente'; }
              elseif ($inv['status'] === 'void' || $inv['status'] === 'uncollectible') { $st_color = '#991B1B'; $st_bg = '#FEE2E2'; $st_label = 'Annulée'; }
              ?>
              <span style="background:<?= $st_bg ?>;color:<?= $st_color ?>;font-size:11px;padding:2px 8px;border-radius:999px;font-weight:600;"><?= htmlspecialchars($st_label) ?></span>
            </td>
            <td style="padding:10px 0 10px 12px;text-align:right;">
              <?php if (!empty($inv['invoice_pdf_url'])): ?>
                <a href="<?= htmlspecialchars($inv['invoice_pdf_url']) ?>" target="_blank" rel="noopener" style="color:#059669;font-size:13px;text-decoration:none;font-weight:600;">📥 PDF</a>
              <?php elseif (!empty($inv['hosted_invoice_url'])): ?>
                <a href="<?= htmlspecialchars($inv['hosted_invoice_url']) ?>" target="_blank" rel="noopener" style="color:#059669;font-size:13px;text-decoration:none;font-weight:600;">🔗 Voir</a>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </table>
      </div>
      <?php endif; ?>

      <!-- Changer de plan -->
      <?php if (!empty($plans) && count($plans) > 1): ?>
      <div style="background:white;border:1px solid #E2E8F0;border-radius:14px;padding:22px 24px;">
        <h3 style="margin:0 0 6px;font-size:16px;display:inline-flex;align-items:center;gap:8px;"><?= ak_icon('chart',18) ?>Changer de plan</h3>
        <p style="color:#64748B;font-size:13px;margin:0 0 16px;">Upgrade ou downgrade selon vos besoins.</p>
        <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(180px, 1fr));gap:12px;">
          <?php foreach ($plans as $p):
            $is_current = ((int)$p['id'] === (int)$current_plan_id);
            $price_str = (int)$p['price_cents'] === 0 ? 'Gratuit' : ak_format_price_cents((int)$p['price_cents']) . '/mois';
          ?>
          <div style="background:<?= $is_current ? '#F0FDF4' : '#FAF8F5' ?>;border:<?= $is_current ? '2px solid #059669' : '1px solid #E2E8F0' ?>;border-radius:10px;padding:14px;text-align:center;">
            <div style="font-weight:700;color:<?= $is_current ? '#047857' : '#0F172A' ?>;font-size:14px;"><?= htmlspecialchars($p['name']) ?></div>
            <div style="font-size:12px;color:#64748B;margin:4px 0 10px;"><?= htmlspecialchars($price_str) ?></div>
            <?php if ($is_current): ?>
              <div style="background:#059669;color:white;padding:6px 12px;border-radius:6px;font-size:11px;font-weight:600;">✓ PLAN ACTUEL</div>
            <?php elseif ((int)$p['price_cents'] > (int)($current_plan_id ? $pricing['plan_cents'] : 0)): ?>
              <a href="/mon-asso-paiement?plan=<?= htmlspecialchars($p['slug']) ?>" style="display:flex;align-items:center;justify-content:center;gap:6px;background:#059669;color:white;padding:7px;border-radius:6px;font-size:12px;text-decoration:none;font-weight:600;"><?= ak_icon('upload',14) ?>Upgrade</a>
            <?php elseif ((int)$p['price_cents'] > 0): ?>
              <a href="/mon-asso-paiement?plan=<?= htmlspecialchars($p['slug']) ?>" style="display:flex;align-items:center;justify-content:center;gap:6px;background:#F1F5F9;color:#475569;padding:7px;border-radius:6px;font-size:12px;text-decoration:none;font-weight:600;"><?= ak_icon('download',14) ?>Passer à ce plan</a>
            <?php else: ?>
              <a href="/mon-asso-annuler-abonnement" style="display:flex;align-items:center;justify-content:center;gap:6px;background:#F1F5F9;color:#475569;padding:7px;border-radius:6px;font-size:12px;text-decoration:none;font-weight:600;"><?= ak_icon('download',14) ?>Passer au gratuit</a>
            <?php endif; ?>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>

    </div>

    <!-- COLONNE DROITE -->
    <div style="display:flex;flex-direction:column;gap:20px;">

      <!-- Méthode paiement -->
      <div style="background:white;border:1px solid #E2E8F0;border-radius:14px;padding:22px 24px;">
        <h3 style="margin:0 0 14px;font-size:16px;display:inline-flex;align-items:center;gap:8px;"><?= ak_icon('credit-card',18) ?>Mode de paiement</h3>
        <?php if ($payment_mode === 'stripe'): ?>
          <div style="display:flex;align-items:center;gap:12px;padding:12px;background:#F0FDF4;border:1px solid #A7F3D0;border-radius:10px;margin-bottom:14px;">
            <div style="font-size:24px;">💳</div>
            <div style="flex:1;font-size:13px;line-height:1.4;">
              <div style="font-weight:700;color:#047857;">Carte bancaire automatique</div>
              <div style="color:#64748B;">Prélèvement mensuel via Stripe</div>
            </div>
          </div>
        <?php elseif ($payment_mode === 'free_grant'): ?>
          <div style="display:flex;align-items:center;gap:12px;padding:12px;background:#EFF6FF;border:1px solid #BFDBFE;border-radius:10px;margin-bottom:14px;">
            <div style="font-size:24px;">🎁</div>
            <div style="flex:1;font-size:13px;line-height:1.4;">
              <div style="font-weight:700;color:#1E40AF;">Plan offert</div>
              <div style="color:#64748B;">Activation par le fondateur</div>
            </div>
          </div>
        <?php elseif ($payment_mode === 'wire_transfer'): ?>
          <div style="display:flex;align-items:center;gap:12px;padding:12px;background:#FEF3C7;border:1px solid #FCD34D;border-radius:10px;margin-bottom:14px;">
            <div style="font-size:24px;">🏦</div>
            <div style="flex:1;font-size:13px;line-height:1.4;">
              <div style="font-weight:700;color:#92400E;">Virement bancaire</div>
              <div style="color:#64748B;">Paiement manuel</div>
            </div>
          </div>
        <?php else: ?>
          <div style="font-size:13px;color:#64748B;line-height:1.5;margin-bottom:14px;">
            Aucune méthode de paiement configurée.
          </div>
        <?php endif; ?>

        <?php if ($has_stripe_subscription): ?>
          <a href="/mon-asso-paiement?action=update_card" style="display:flex;align-items:center;justify-content:center;gap:6px;text-align:center;background:#F1F5F9;color:#0F172A;padding:9px;border-radius:8px;font-size:13px;text-decoration:none;font-weight:600;"><?= ak_icon('refresh',14) ?>Changer ma CB</a>
        <?php endif; ?>
      </div>

      <!-- Actions -->
      <div style="background:white;border:1px solid #E2E8F0;border-radius:14px;padding:22px 24px;">
        <h3 style="margin:0 0 14px;font-size:16px;display:inline-flex;align-items:center;gap:8px;"><?= ak_icon('gear',18) ?>Actions</h3>
        <div style="display:flex;flex-direction:column;gap:8px;">
          <a href="/mon-asso-factures" style="background:#FAF8F5;color:#0F172A;padding:10px 14px;border-radius:8px;text-decoration:none;font-size:13.5px;font-weight:600;display:flex;align-items:center;gap:8px;border:1px solid #E2E8F0;">
            <?= ak_icon('file-text',16) ?>Mes factures
          </a>
          <a href="/contact?subject=billing" style="background:#FAF8F5;color:#0F172A;padding:10px 14px;border-radius:8px;text-decoration:none;font-size:13.5px;font-weight:600;display:flex;align-items:center;gap:8px;border:1px solid #E2E8F0;">
            <?= ak_icon('phone',16) ?>Nous contacter
          </a>
          <?php if ($has_stripe_subscription || $st === 'active'): ?>
            <a href="/mon-asso-annuler-abonnement" style="background:#FEE2E2;color:#991B1B;padding:10px 14px;border-radius:8px;text-decoration:none;font-size:13.5px;font-weight:600;display:flex;align-items:center;gap:8px;border:1px solid #FECACA;margin-top:6px;">
              🛑 Annuler mon abonnement
            </a>
          <?php endif; ?>
        </div>
      </div>

      <!-- Add-on domaine (si pas déjà actif) -->
      <?php
      $has_addon = function_exists('ak_has_addon_custom_domain') && ak_has_addon_custom_domain($pdo, $org_id);
      if ($is_active && !$has_addon && $payment_mode === 'stripe'):
      ?>
      <div style="background:linear-gradient(135deg, #EFF6FF 0%, white 100%);border:1px solid #BFDBFE;border-radius:14px;padding:22px;">
        <div style="font-size:32px;margin-bottom:8px;">🏆</div>
        <h3 style="margin:0 0 6px;font-size:15px;color:#1E40AF;">Domaine personnalisé</h3>
        <p style="font-size:12.5px;color:#475569;margin:0 0 14px;line-height:1.5;">Ajoutez votre propre domaine (adherents.tonasso.fr) pour seulement <strong>+10€/mois</strong>.</p>
        <a href="/mon-asso-paiement?plan=<?= htmlspecialchars($subscription_status['plan_slug'] ?? 'assokit') ?>&addon_domain=1" style="display:block;background:#1E40AF;color:white;padding:9px;border-radius:8px;text-align:center;font-size:13px;text-decoration:none;font-weight:600;">+ Ajouter</a>
      </div>
      <?php endif; ?>

    </div>

  </div>

  <style>
  @media (max-width: 920px) {
    main.main > div[style*="grid-template-columns:2fr 1fr"] {
      grid-template-columns: 1fr !important;
    }
  }
  </style>

</main>

<?php render_foot(); ?>
