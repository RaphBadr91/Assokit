<?php
/**
 * admin-domains.php
 * --------------------------------------------------------------
 * Page d'administration des domaines personnalisés (FONDATEUR)
 *
 * Phase 1 : page placeholder qui montre la structure prévue
 * Phase 2 : activation des sous-domaines *.assokit.fr
 * Phase 3 : gestion des domaines perso clients
 * --------------------------------------------------------------
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes-layout.php';
@require_once __DIR__ . '/domain-helpers.php';

require_login();
$user = current_user();

// Restriction : fondateur uniquement
$has_access = !empty($user['is_founder']) || !empty($user['is_super_admin']) || (int)$user['org_id'] === 1;
if (!$has_access) {
    http_response_code(403);
    die('Accès réservé aux fondateurs.');
}

// Stats globales (si tables existent)
$stats = ['subdomains' => 0, 'custom_domains' => 0, 'pending' => 0];
try {
    $st = $pdo->query("SELECT COUNT(*) FROM organizations WHERE subdomain_slug IS NOT NULL");
    $stats['subdomains'] = (int)$st->fetchColumn();
} catch (Throwable $e) { /* migration pas passée */ }

try {
    $st = $pdo->query("SELECT COUNT(*) FROM asso_custom_domains WHERE status = 'active'");
    $stats['custom_domains'] = (int)$st->fetchColumn();

    $st = $pdo->query("SELECT COUNT(*) FROM asso_custom_domains WHERE status IN ('pending','verifying','dns_pending')");
    $stats['pending'] = (int)$st->fetchColumn();
} catch (Throwable $e) { /* migration pas passée */ }

render_head('Gestion des domaines');
render_sidebar('admin-domains');
?>

<main class="main">
  <nav class="crumbs">
    <a href="/dashboard">Dashboard</a>
    <span class="sep">›</span>
    <span class="current">🌐 Gestion des domaines</span>
  </nav>

  <div class="main-head" style="margin-bottom:24px;">
    <div>
      <h1 style="margin:0 0 4px;">🌐 Gestion des domaines</h1>
      <p style="color:#64748B;margin:0;">Sous-domaines Assokit + domaines personnalisés clients (white-label)</p>
    </div>
  </div>

  <!-- Bandeau "fonctionnalité en préparation" -->
  <div style="background:linear-gradient(135deg, #F0FDF4 0%, #FAF8F5 100%); border:1px solid #A7F3D0; border-radius:14px; padding:24px 28px; margin-bottom:22px; display:flex; gap:18px; align-items:flex-start; flex-wrap:wrap;">
    <div style="font-size:42px; line-height:1; flex-shrink:0;">🚧</div>
    <div style="flex:1; min-width:240px;">
      <h3 style="margin:0 0 8px; font-size:18px; color:#047857;">Fonctionnalité en cours d'activation</h3>
      <p style="margin:0; color:#475569; line-height:1.6; font-size:14px;">
        L'infrastructure des domaines personnalisés est <strong>préparée en base de données</strong>.<br>
        L'activation se fera en <strong>3 phases progressives</strong> après la mise en production de Stripe.
      </p>
    </div>
  </div>

  <!-- Stats actuelles -->
  <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(200px, 1fr));gap:14px;margin-bottom:24px;">
    <div style="background:white;border:1px solid #E2E8F0;border-radius:14px;padding:18px;">
      <div style="font-size:11px;color:#94A3B8;text-transform:uppercase;letter-spacing:0.05em;font-weight:700;">Sous-domaines configurés</div>
      <div style="font-size:28px;font-weight:700;color:#0F172A;margin-top:6px;"><?= (int)$stats['subdomains'] ?></div>
      <div style="font-size:11px;color:#94A3B8;margin-top:2px;">Phase 2 — *.assokit.fr</div>
    </div>
    <div style="background:white;border:1px solid #E2E8F0;border-radius:14px;padding:18px;">
      <div style="font-size:11px;color:#94A3B8;text-transform:uppercase;letter-spacing:0.05em;font-weight:700;">Domaines perso actifs</div>
      <div style="font-size:28px;font-weight:700;color:#059669;margin-top:6px;"><?= (int)$stats['custom_domains'] ?></div>
      <div style="font-size:11px;color:#94A3B8;margin-top:2px;">Phase 3 — domaine client</div>
    </div>
    <div style="background:white;border:1px solid #E2E8F0;border-radius:14px;padding:18px;">
      <div style="font-size:11px;color:#94A3B8;text-transform:uppercase;letter-spacing:0.05em;font-weight:700;">En attente validation</div>
      <div style="font-size:28px;font-weight:700;color:#EA580C;margin-top:6px;"><?= (int)$stats['pending'] ?></div>
      <div style="font-size:11px;color:#94A3B8;margin-top:2px;">Configuration DNS</div>
    </div>
  </div>

  <!-- Roadmap des phases -->
  <div style="background:white;border:1px solid #E2E8F0;border-radius:14px;padding:24px;margin-bottom:22px;">
    <h3 style="margin:0 0 18px;font-size:17px;">🗺️ Roadmap d'activation</h3>

    <div style="display:flex;gap:16px;align-items:flex-start;padding:14px;background:#D1FAE5;border:1px solid #A7F3D0;border-radius:10px;margin-bottom:10px;">
      <div style="background:#059669;color:white;width:32px;height:32px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:700;flex-shrink:0;">1</div>
      <div style="flex:1;min-width:0;">
        <div style="font-weight:700;color:#065F46;margin-bottom:4px;">✅ Phase 1 — Préparation BDD <span style="background:#059669;color:white;font-size:10px;padding:2px 8px;border-radius:999px;margin-left:6px;">ACTIF</span></div>
        <div style="font-size:13.5px;color:#047857;line-height:1.55;">Tables et champs créés. Helpers PHP en place. Aucune fonctionnalité visible côté client pour l'instant.</div>
      </div>
    </div>

    <div style="display:flex;gap:16px;align-items:flex-start;padding:14px;background:#FAF8F5;border:1px solid #E2E8F0;border-radius:10px;margin-bottom:10px;">
      <div style="background:#94A3B8;color:white;width:32px;height:32px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:700;flex-shrink:0;">2</div>
      <div style="flex:1;min-width:0;">
        <div style="font-weight:700;color:#0F172A;margin-bottom:4px;">⏳ Phase 2 — Sous-domaines <code>*.assokit.fr</code> <span style="background:#94A3B8;color:white;font-size:10px;padding:2px 8px;border-radius:999px;margin-left:6px;">APRÈS STRIPE</span></div>
        <div style="font-size:13.5px;color:#64748B;line-height:1.55;">Activation des sous-domaines pour les clients du plan Assokit. URL personnalisée du type <code>latitude91.assokit.fr</code>.</div>
      </div>
    </div>

    <div style="display:flex;gap:16px;align-items:flex-start;padding:14px;background:#FAF8F5;border:1px solid #E2E8F0;border-radius:10px;margin-bottom:10px;">
      <div style="background:#94A3B8;color:white;width:32px;height:32px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:700;flex-shrink:0;">3</div>
      <div style="flex:1;min-width:0;">
        <div style="font-weight:700;color:#0F172A;margin-bottom:4px;">⏳ Phase 3 — Domaines perso (Sur-mesure) <span style="background:#94A3B8;color:white;font-size:10px;padding:2px 8px;border-radius:999px;margin-left:6px;">PREMIER CLIENT</span></div>
        <div style="font-size:13.5px;color:#64748B;line-height:1.55;">Permet aux clients Sur-mesure d'utiliser leur propre domaine (ex: <code>adherents.latitude91.fr</code>). Génération automatique du SSL Let's Encrypt.</div>
      </div>
    </div>

    <div style="display:flex;gap:16px;align-items:flex-start;padding:14px;background:#FAF8F5;border:1px solid #E2E8F0;border-radius:10px;">
      <div style="background:#94A3B8;color:white;width:32px;height:32px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:700;flex-shrink:0;">4</div>
      <div style="flex:1;min-width:0;">
        <div style="font-weight:700;color:#0F172A;margin-bottom:4px;">⏳ Phase 4 — Emails domaine client <span style="background:#94A3B8;color:white;font-size:10px;padding:2px 8px;border-radius:999px;margin-left:6px;">RESEND PRO</span></div>
        <div style="font-size:13.5px;color:#64748B;line-height:1.55;">Envoi des emails depuis le domaine du client (ex: <code>contact@latitude91.fr</code>). Activation Resend Pro à 18€/mois HT.</div>
      </div>
    </div>
  </div>

  <!-- État des migrations -->
  <div style="background:white;border:1px solid #E2E8F0;border-radius:14px;padding:24px;">
    <h3 style="margin:0 0 14px;font-size:17px;">🔍 État de l'infrastructure BDD</h3>
    <table style="width:100%;border-collapse:collapse;font-size:14px;">
      <?php
      $checks = [
          'organizations.subdomain_slug' => 'organizations',
          'organizations.branding_primary_color' => 'organizations',
          'organizations.branding_secondary_color' => 'organizations',
          'organizations.hide_assokit_branding' => 'organizations',
      ];
      foreach ($checks as $col => $table):
          $col_name = explode('.', $col)[1];
          $exists = false;
          try {
              $st = $pdo->prepare("
                  SELECT COUNT(*) FROM information_schema.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c
              ");
              $st->execute([':t' => $table, ':c' => $col_name]);
              $exists = (int)$st->fetchColumn() > 0;
          } catch (Throwable $e) {}
      ?>
      <tr style="border-bottom:1px solid #F1F5F9;">
        <td style="padding:10px 0;color:#475569;font-family:monospace;font-size:13px;"><?= h($col) ?></td>
        <td style="padding:10px 0;text-align:right;">
          <?php if ($exists): ?>
            <span style="background:#D1FAE5;color:#065F46;padding:3px 10px;border-radius:999px;font-size:11px;font-weight:600;">✅ OK</span>
          <?php else: ?>
            <span style="background:#FEE2E2;color:#991B1B;padding:3px 10px;border-radius:999px;font-size:11px;font-weight:600;">❌ Manquante</span>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>

      <?php
      $tables = ['asso_custom_domains', 'asso_domain_email_config'];
      foreach ($tables as $tbl):
          $exists = false;
          try {
              $st = $pdo->query("SHOW TABLES LIKE '" . $tbl . "'");
              $exists = (bool)$st->fetch();
          } catch (Throwable $e) {}
      ?>
      <tr style="border-bottom:1px solid #F1F5F9;">
        <td style="padding:10px 0;color:#475569;font-family:monospace;font-size:13px;">Table <?= h($tbl) ?></td>
        <td style="padding:10px 0;text-align:right;">
          <?php if ($exists): ?>
            <span style="background:#D1FAE5;color:#065F46;padding:3px 10px;border-radius:999px;font-size:11px;font-weight:600;">✅ OK</span>
          <?php else: ?>
            <span style="background:#FEE2E2;color:#991B1B;padding:3px 10px;border-radius:999px;font-size:11px;font-weight:600;">❌ Manquante</span>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </table>

    <p style="margin:18px 0 0;font-size:13px;color:#94A3B8;line-height:1.5;">
      💡 Si des éléments sont manquants, exécute <code>migration-v47-domains.sql</code> dans phpMyAdmin.
    </p>
  </div>

</main>

<?php render_foot(); ?>
