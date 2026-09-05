<?php
/**
 * diffusion-email.php — Page diffusion email (v2)
 * --------------------------------------------------------------
 * - Plan Démarrage : effet flou + CTA upgrade
 * - Plans Assokit / Sur-mesure : listing réel des diffusions
 * --------------------------------------------------------------
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes-layout.php';
@require_once __DIR__ . '/plan-helpers.php';

require_login();
$user = current_user();
$org_id = (int)$user['org_id'];

// === Récupération plan + statut ===
$has_email_feature = true; // par défaut, si plan-helpers absent → on n'empêche rien
if (function_exists('ak_get_current_plan')) {
    $plan = ak_get_current_plan($pdo, $org_id);
    $has_email_feature = $plan && !empty($plan['feature_email_diffusion']);
}

// === Si plan OK : récupérer les vraies diffusions de l'org ===
$diffusions = [];
$stats = ['sent_month' => 0, 'open_rate' => 0, 'lists' => 0, 'quota_used' => 0];

if ($has_email_feature) {
    try {
        // Vérifier si la table existe (pour éviter erreur si pas encore créée)
        $check = $pdo->query("SHOW TABLES LIKE 'asso_email_diffusions'");
        if ($check->fetch()) {
            $st = $pdo->prepare("SELECT * FROM asso_email_diffusions WHERE org_id = :o ORDER BY created_at DESC LIMIT 20");
            $st->execute([':o' => $org_id]);
            $diffusions = $st->fetchAll(PDO::FETCH_ASSOC);

            // Stats du mois
            $st2 = $pdo->prepare("
                SELECT
                    COUNT(*) AS total_sent,
                    AVG(CASE WHEN recipients_count > 0 THEN (opens_count / recipients_count) * 100 ELSE 0 END) AS avg_open
                FROM asso_email_diffusions
                WHERE org_id = :o AND status = 'sent'
                  AND sent_at >= DATE_FORMAT(NOW(), '%Y-%m-01')
            ");
            $st2->execute([':o' => $org_id]);
            $row = $st2->fetch(PDO::FETCH_ASSOC);
            $stats['sent_month'] = (int)($row['total_sent'] ?? 0);
            $stats['open_rate'] = round((float)($row['avg_open'] ?? 0), 1);
        }
    } catch (Throwable $e) {
        error_log('[diffusion-email] ' . $e->getMessage());
    }
}

render_head('Diffusion email');
render_sidebar('diffusion-email');
?>

<main class="main">
  <?php
  // Bandeau régularisation si grâce expirée
  if (function_exists('ak_render_overdue_overlay')) {
      echo ak_render_overdue_overlay($pdo, $org_id);
  }
  ?>

  <div class="page-head" style="margin-bottom:24px; display:flex; justify-content:space-between; align-items:flex-start; gap:16px; flex-wrap:wrap;">
    <div>
      <h1 style="margin:0 0 4px;">📨 Diffusion email</h1>
      <p style="color:#64748b;margin:0;">Envoyez des messages ciblés à vos adhérents, équipes et contacts.</p>
    </div>
    <?php if ($has_email_feature): ?>
      <a href="/nouvelle-diffusion-email" class="btn btn-primary">+ Nouvelle diffusion</a>
    <?php endif; ?>
  </div>

  <?php if (!$has_email_feature): ?>
    <!-- ============ MODE FLOU + CTA UPGRADE (plan Démarrage) ============ -->
    <div class="ak-blur-wrapper">
      <div class="ak-blur-content">
        <div style="display:grid;grid-template-columns:repeat(4, 1fr);gap:16px;margin-bottom:24px;">
          <div class="card" style="text-align:center;padding:20px;background:white;border:1px solid #E2E8F0;border-radius:12px;">
            <div style="font-size:11px;color:#64748B;text-transform:uppercase;letter-spacing:0.05em;">Envoyés ce mois</div>
            <div style="font-size:28px;font-weight:700;color:#0F172A;margin-top:6px;">1 247</div>
          </div>
          <div class="card" style="text-align:center;padding:20px;background:white;border:1px solid #E2E8F0;border-radius:12px;">
            <div style="font-size:11px;color:#64748B;text-transform:uppercase;letter-spacing:0.05em;">Taux d'ouverture</div>
            <div style="font-size:28px;font-weight:700;color:#059669;margin-top:6px;">68%</div>
          </div>
          <div class="card" style="text-align:center;padding:20px;background:white;border:1px solid #E2E8F0;border-radius:12px;">
            <div style="font-size:11px;color:#64748B;text-transform:uppercase;letter-spacing:0.05em;">Listes</div>
            <div style="font-size:28px;font-weight:700;color:#0F172A;margin-top:6px;">5</div>
          </div>
          <div class="card" style="text-align:center;padding:20px;background:white;border:1px solid #E2E8F0;border-radius:12px;">
            <div style="font-size:11px;color:#64748B;text-transform:uppercase;letter-spacing:0.05em;">Quota</div>
            <div style="font-size:28px;font-weight:700;color:#0F172A;margin-top:6px;">2 000/mois</div>
          </div>
        </div>

        <div class="card" style="padding:24px;background:white;border:1px solid #E2E8F0;border-radius:12px;">
          <h3 style="margin:0 0 16px;font-size:16px;">Dernières diffusions</h3>
          <div style="border:1px solid #E2E8F0;border-radius:10px;overflow:hidden;">
            <div style="padding:14px 18px;border-bottom:1px solid #F1F5F9;display:flex;align-items:center;gap:14px;">
              <div style="width:40px;height:40px;background:#FCE7F3;color:#9D174D;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:18px;">📨</div>
              <div style="flex:1;">
                <div style="font-weight:600;color:#0F172A;">Newsletter Mai 2026</div>
                <div style="font-size:12px;color:#64748B;">320 destinataires · 78% d'ouverture</div>
              </div>
              <span style="background:#D1FAE5;color:#065F46;padding:3px 10px;border-radius:999px;font-size:11px;font-weight:600;">✓ Envoyée</span>
            </div>
            <div style="padding:14px 18px;border-bottom:1px solid #F1F5F9;display:flex;align-items:center;gap:14px;">
              <div style="width:40px;height:40px;background:#E0F2FE;color:#0C4A6E;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:18px;">📨</div>
              <div style="flex:1;">
                <div style="font-weight:600;color:#0F172A;">Convocation AG annuelle</div>
                <div style="font-size:12px;color:#64748B;">152 destinataires · 92% d'ouverture</div>
              </div>
              <span style="background:#D1FAE5;color:#065F46;padding:3px 10px;border-radius:999px;font-size:11px;font-weight:600;">✓ Envoyée</span>
            </div>
            <div style="padding:14px 18px;display:flex;align-items:center;gap:14px;">
              <div style="width:40px;height:40px;background:#FEF3C7;color:#92400E;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:18px;">📨</div>
              <div style="flex:1;">
                <div style="font-weight:600;color:#0F172A;">Appel aux dons fin d'année</div>
                <div style="font-size:12px;color:#64748B;">487 destinataires · 64% d'ouverture</div>
              </div>
              <span style="background:#D1FAE5;color:#065F46;padding:3px 10px;border-radius:999px;font-size:11px;font-weight:600;">✓ Envoyée</span>
            </div>
          </div>
        </div>
      </div>

      <!-- Overlay CTA -->
      <div class="ak-blur-overlay">
        <div class="ak-blur-card">
          <div class="ak-blur-icon">📨</div>
          <h2>La diffusion email arrive avec le plan Assokit</h2>
          <p>
            Envoyez des newsletters, convocations AG, appels aux dons et messages ciblés à vos adhérents et clients.
            <strong>Jusqu'à 2 000 emails/mois</strong> avec le suivi détaillé des ouvertures et taux de délivrance.
          </p>
          <ul class="ak-blur-features">
            <li>✓ Diffusion ciblée par rôle, projet ou liste personnalisée</li>
            <li>✓ Suivi détaillé : reçu, ouvert, échec</li>
            <li>✓ Domaine personnalisé (plan Sur-mesure)</li>
            <li>✓ Limite anti-spam intégrée pour préserver votre réputation</li>
            <li>✓ <strong>2 000 emails/mois</strong> inclus dans le plan Assokit</li>
          </ul>
          <div class="ak-blur-cta">
            <a href="/contact?subject=demo&plan=assokit" class="ak-blur-btn-primary">↗ Passer au plan Assokit (49,99€/mois)</a>
            <a href="/mon-asso-plan" class="ak-blur-btn-secondary">Voir tous les plans</a>
          </div>
          <p class="ak-blur-footer">
            🌿 Aucun engagement · Activation immédiate après paiement · Support inclus &lt;24h
          </p>
        </div>
      </div>
    </div>

  <?php else: ?>
    <!-- ============ MODE NORMAL (plan Assokit / Sur-mesure) ============ -->
    <?php
    // Bandeaux quota / grâce
    if (function_exists('ak_render_grace_banner')) echo ak_render_grace_banner($pdo, $org_id);
    if (function_exists('ak_render_quota_banner')) echo ak_render_quota_banner($pdo, $org_id);
    ?>

    <!-- Stats du mois -->
    <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(180px, 1fr));gap:14px;margin-bottom:22px;">
      <div style="background:white;border:1px solid #E2E8F0;border-radius:12px;padding:18px;">
        <div style="font-size:11px;color:#64748B;text-transform:uppercase;letter-spacing:0.05em;font-weight:700;">Envoyés ce mois</div>
        <div style="font-size:26px;font-weight:700;color:#0F172A;margin-top:6px;"><?= number_format($stats['sent_month'], 0, ',', ' ') ?></div>
      </div>
      <div style="background:white;border:1px solid #E2E8F0;border-radius:12px;padding:18px;">
        <div style="font-size:11px;color:#64748B;text-transform:uppercase;letter-spacing:0.05em;font-weight:700;">Taux d'ouverture moyen</div>
        <div style="font-size:26px;font-weight:700;color:#059669;margin-top:6px;"><?= $stats['open_rate'] ?>%</div>
      </div>
      <div style="background:white;border:1px solid #E2E8F0;border-radius:12px;padding:18px;">
        <div style="font-size:11px;color:#64748B;text-transform:uppercase;letter-spacing:0.05em;font-weight:700;">Total diffusions</div>
        <div style="font-size:26px;font-weight:700;color:#0F172A;margin-top:6px;"><?= count($diffusions) ?></div>
      </div>
    </div>

    <!-- Listing diffusions -->
    <div style="background:white;border:1px solid #E2E8F0;border-radius:12px;overflow:hidden;">
      <div style="padding:18px 22px;border-bottom:1px solid #F1F5F9;display:flex;justify-content:space-between;align-items:center;">
        <h3 style="margin:0;font-size:16px;">Dernières diffusions</h3>
      </div>

      <?php if (empty($diffusions)): ?>
        <div style="padding:60px 30px;text-align:center;color:#64748B;">
          <div style="font-size:42px;margin-bottom:14px;opacity:0.5;">📭</div>
          <h4 style="margin:0 0 8px;color:#0F172A;font-size:17px;">Aucune diffusion pour le moment</h4>
          <p style="margin:0 0 22px;font-size:14px;color:#64748B;">Lancez votre première diffusion email à vos adhérents.</p>
          <a href="/nouvelle-diffusion-email" class="btn btn-primary" style="background:#059669;color:white;padding:11px 22px;border-radius:10px;text-decoration:none;font-weight:600;">+ Nouvelle diffusion</a>
        </div>
      <?php else: ?>
        <?php foreach ($diffusions as $d):
          $recipients = (int)($d['recipients_count'] ?? 0);
          $opens = (int)($d['opens_count'] ?? 0);
          $rate = $recipients > 0 ? round(($opens / $recipients) * 100) : 0;
          $status = $d['status'] ?? 'draft';
          $status_label = ['draft' => '📝 Brouillon', 'sending' => '⏳ En cours', 'sent' => '✓ Envoyée', 'failed' => '⚠️ Échec'][$status] ?? $status;
          $status_color = ['draft' => '#94A3B8', 'sending' => '#0EA5E9', 'sent' => '#059669', 'failed' => '#DC2626'][$status] ?? '#94A3B8';
        ?>
          <div style="padding:14px 18px;border-bottom:1px solid #F1F5F9;display:flex;align-items:center;gap:14px;">
            <div style="width:40px;height:40px;background:#F1F5F9;color:#475569;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:18px;flex-shrink:0;">📨</div>
            <div style="flex:1;min-width:0;">
              <div style="font-weight:600;color:#0F172A;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?= h($d['subject'] ?? '(sans titre)') ?></div>
              <div style="font-size:12px;color:#64748B;">
                <?= $recipients ?> destinataire<?= $recipients > 1 ? 's' : '' ?>
                <?php if ($status === 'sent'): ?> · <?= $rate ?>% d'ouverture<?php endif; ?>
                · <?= h(date('d/m/Y', strtotime($d['created_at'] ?? 'now'))) ?>
              </div>
            </div>
            <span style="background:rgba(<?= hexdec(substr($status_color,1,2)) ?>,<?= hexdec(substr($status_color,3,2)) ?>,<?= hexdec(substr($status_color,5,2)) ?>,0.12);color:<?= $status_color ?>;padding:4px 10px;border-radius:999px;font-size:11px;font-weight:600;flex-shrink:0;"><?= h($status_label) ?></span>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</main>

<style>
  /* ============ STYLES EFFET FLOU + OVERLAY ============ */
  .ak-blur-wrapper { position: relative; min-height: 600px; }
  .ak-blur-content { filter: blur(8px) saturate(0.9); pointer-events: none; user-select: none; opacity: 0.85; }
  .ak-blur-overlay { position: absolute; inset: 0; display: flex; align-items: flex-start; justify-content: center; padding: 40px 20px; z-index: 10; }
  .ak-blur-card {
    background: white; max-width: 600px; width: 100%; border-radius: 20px; padding: 44px 40px;
    box-shadow: 0 20px 60px rgba(15, 23, 42, 0.18), 0 4px 14px rgba(15, 23, 42, 0.08);
    border: 1px solid #E2E8F0; text-align: center;
  }
  .ak-blur-icon { font-size: 56px; margin-bottom: 16px; line-height: 1; }
  .ak-blur-card h2 { margin: 0 0 14px; font-size: 24px; color: #0F172A; font-weight: 700; letter-spacing: -0.02em; }
  .ak-blur-card p { color: #475569; line-height: 1.65; margin: 0 0 22px; font-size: 15px; }
  .ak-blur-card p strong { color: #0F172A; }
  .ak-blur-features {
    list-style: none; padding: 18px 22px; margin: 0 0 28px; text-align: left;
    background: #F0FDF4; border-radius: 12px; border: 1px solid #A7F3D0;
  }
  .ak-blur-features li { padding: 5px 0; color: #1E293B; font-size: 14px; line-height: 1.55; }
  .ak-blur-cta { display: flex; flex-direction: column; gap: 10px; margin-bottom: 16px; }
  .ak-blur-btn-primary {
    background: linear-gradient(180deg, #059669 0%, #047857 100%); color: white;
    padding: 14px 24px; border-radius: 12px; font-weight: 600; font-size: 15px;
    text-decoration: none; box-shadow: 0 4px 14px rgba(5, 150, 105, 0.30);
    transition: all 0.2s ease; display: block; text-align: center;
  }
  .ak-blur-btn-primary:hover { transform: translateY(-1px); box-shadow: 0 8px 22px rgba(5, 150, 105, 0.40); }
  .ak-blur-btn-secondary {
    background: transparent; color: #475569; padding: 12px 24px; border-radius: 12px;
    border: 1px solid #E2E8F0; font-size: 14px; text-decoration: none;
    transition: all 0.2s ease; display: block; text-align: center;
  }
  .ak-blur-btn-secondary:hover { background: #F8FAFC; border-color: #CBD5E1; }
  .ak-blur-footer { margin: 0; color: #64748B; font-size: 12.5px; line-height: 1.5; }

  @media (max-width: 640px) {
    .ak-blur-card { padding: 32px 24px; border-radius: 16px; }
    .ak-blur-icon { font-size: 48px; }
    .ak-blur-card h2 { font-size: 20px; }
    .ak-blur-card p, .ak-blur-features li { font-size: 13.5px; }
    .ak-blur-features { padding: 14px 16px; }
    .ak-blur-content { filter: blur(5px); }
  }
</style>

<?php render_foot(); ?>
