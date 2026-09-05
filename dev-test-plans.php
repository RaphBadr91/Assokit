<?php
/**
 * dev-test-plans.php — Outil de test des plans (v2 assouplie)
 * --------------------------------------------------------------
 * Permet de basculer rapidement TON org entre les 3 plans
 * pour visualiser les blocages.
 *
 * Critères d'accès (assouplis) :
 *  - is_founder = 1
 *  - OU is_super_admin = 1
 *  - OU role = 'super_admin' (pour les rôles enum)
 *  - OU org_id = 1 (Latitude91 = org fondatrice)
 *
 * Si rien ne passe : affiche un diagnostic clair (pas de mort silencieuse).
 * --------------------------------------------------------------
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes-layout.php';
@require_once __DIR__ . '/plan-helpers.php';

require_login();
$user = current_user();
$org_id = (int)($user['org_id'] ?? 0);

// === Critères d'accès assouplis ===
$is_founder       = !empty($user['is_founder']);
$is_super_admin   = !empty($user['is_super_admin']);
$is_super_admin_role = ($user['role'] ?? '') === 'super_admin';
$is_first_org     = $org_id === 1;

$has_access = $is_founder || $is_super_admin || $is_super_admin_role || $is_first_org;

// === Si pas d'accès : diagnostic clair ===
if (!$has_access) {
    render_head('Accès refusé');
    render_sidebar('');
    ?>
    <main class="main">
      <div class="main-head">
        <h1 style="margin:0;">🔒 Accès refusé</h1>
      </div>

      <div style="background:#FEE2E2;border:1px solid #FCA5A5;color:#991B1B;padding:18px 22px;border-radius:12px;margin-bottom:22px;line-height:1.6;">
        Cette page est réservée aux fondateurs de RBPS.
      </div>

      <div style="background:white;border:1px solid #E2E8F0;border-radius:14px;padding:22px;">
        <h3 style="margin:0 0 14px;font-size:16px;">🔍 Diagnostic de tes droits actuels</h3>
        <table style="width:100%;border-collapse:collapse;font-size:14px;">
          <tr>
            <td style="padding:8px 0;color:#64748B;">Email :</td>
            <td style="padding:8px 0;font-weight:600;"><?= h($user['email'] ?? '?') ?></td>
          </tr>
          <tr>
            <td style="padding:8px 0;color:#64748B;">User ID :</td>
            <td style="padding:8px 0;font-weight:600;"><?= (int)($user['id'] ?? 0) ?></td>
          </tr>
          <tr>
            <td style="padding:8px 0;color:#64748B;">Org ID :</td>
            <td style="padding:8px 0;font-weight:600;">
              <?= $org_id ?> <?= $is_first_org ? '✅' : '❌ (devrait être 1 pour Latitude91)' ?>
            </td>
          </tr>
          <tr>
            <td style="padding:8px 0;color:#64748B;">Rôle :</td>
            <td style="padding:8px 0;font-weight:600;"><?= h($user['role'] ?? '?') ?></td>
          </tr>
          <tr>
            <td style="padding:8px 0;color:#64748B;">is_founder :</td>
            <td style="padding:8px 0;font-weight:600;">
              <?= $is_founder ? '✅ 1' : '❌ 0 (devrait être 1)' ?>
            </td>
          </tr>
          <tr>
            <td style="padding:8px 0;color:#64748B;">is_super_admin :</td>
            <td style="padding:8px 0;font-weight:600;">
              <?= $is_super_admin ? '✅ 1' : '❌ 0' ?>
            </td>
          </tr>
        </table>

        <hr style="border:none;border-top:1px solid #E2E8F0;margin:20px 0;">

        <h3 style="margin:0 0 12px;font-size:15px;">🛠️ Comment activer l'accès</h3>
        <p style="margin:0 0 12px;font-size:14px;color:#475569;line-height:1.6;">
          Va dans <strong>phpMyAdmin → table <code>users</code></strong> et exécute :
        </p>
        <pre style="background:#0F172A;color:#A7F3D0;padding:14px 16px;border-radius:8px;font-size:12.5px;overflow-x:auto;line-height:1.5;">UPDATE users
SET is_founder = 1, is_super_admin = 1
WHERE email = '<?= h($user['email'] ?? '') ?>';</pre>
        <p style="margin:14px 0 0;font-size:13px;color:#64748B;">
          Puis <strong>déconnecte-toi et reconnecte-toi</strong> pour rafraîchir la session.
        </p>
      </div>
    </main>
    <?php
    render_foot();
    exit;
}

// === À partir d'ici : accès OK ===

$message = '';
$message_type = '';

// === Action : changer de plan ===
if ($_SERVER['REQUEST_METHOD'] === 'POST' && check_csrf($_POST['csrf_token'] ?? '')) {
    $new_plan_slug = $_POST['plan_slug'] ?? '';
    $new_status = $_POST['status'] ?? 'active';

    if (in_array($new_plan_slug, ['demarrage', 'assokit', 'sur-mesure'], true)) {
        try {
            // Récupérer l'ID du plan
            $st = $pdo->prepare("SELECT id FROM asso_plans WHERE slug = :s LIMIT 1");
            $st->execute([':s' => $new_plan_slug]);
            $new_plan_id = (int)$st->fetchColumn();

            if ($new_plan_id > 0) {
                $check = $pdo->prepare("SELECT id FROM asso_subscriptions WHERE org_id = :o LIMIT 1");
                $check->execute([':o' => $org_id]);

                if ($check->fetch()) {
                    $upd = $pdo->prepare("
                        UPDATE asso_subscriptions
                        SET plan_id = :p, status = :s,
                            grace_period_end = CASE WHEN :s2 = 'overdue' THEN DATE_SUB(NOW(), INTERVAL 1 DAY) ELSE NULL END,
                            current_period_end = CASE WHEN :s3 = 'pending_payment' THEN DATE_SUB(NOW(), INTERVAL 1 DAY) ELSE NULL END,
                            updated_at = NOW()
                        WHERE org_id = :o
                    ");
                    $upd->execute([
                        ':p' => $new_plan_id, ':s' => $new_status,
                        ':s2' => $new_status, ':s3' => $new_status,
                        ':o' => $org_id
                    ]);
                } else {
                    $ins = $pdo->prepare("
                        INSERT INTO asso_subscriptions (org_id, plan_id, status, started_at)
                        VALUES (:o, :p, :s, NOW())
                    ");
                    $ins->execute([':o' => $org_id, ':p' => $new_plan_id, ':s' => $new_status]);
                }

                $message = "✅ Plan basculé sur '{$new_plan_slug}' avec statut '{$new_status}'";
                $message_type = 'success';
            } else {
                $message = "❌ Plan '{$new_plan_slug}' introuvable en BDD. As-tu lancé migration-v46-REPARATION.sql ?";
                $message_type = 'error';
            }
        } catch (Throwable $e) {
            $message = "❌ Erreur : " . $e->getMessage();
            $message_type = 'error';
        }
    }
}

// État actuel
$subscription = null;
try {
    $sub = $pdo->prepare("
        SELECT s.*, p.slug AS plan_slug, p.name AS plan_name
        FROM asso_subscriptions s
        JOIN asso_plans p ON p.id = s.plan_id
        WHERE s.org_id = :o
        LIMIT 1
    ");
    $sub->execute([':o' => $org_id]);
    $subscription = $sub->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $message = "⚠️ Tables asso_subscriptions / asso_plans absentes. Lance migration-v46-REPARATION.sql en phpMyAdmin.";
    $message_type = 'error';
}

render_head('Test des plans (DEV)');
render_sidebar('');
?>

<main class="main">
  <nav class="crumbs">
    <a href="/dashboard">Dashboard</a>
    <span class="sep">›</span>
    <span class="current">🧪 Test des plans</span>
  </nav>

  <div class="main-head" style="margin-bottom:24px;">
    <div>
      <h1 style="margin:0 0 4px;">🧪 Test des plans (mode dev)</h1>
      <p style="color:#64748B;margin:0;">Bascule rapidement ton org entre les plans pour voir les blocages en action.</p>
    </div>
  </div>

  <!-- Avertissement -->
  <div style="background:#FEF3C7;border:1px solid #FDE68A;color:#92400E;padding:14px 18px;border-radius:12px;margin-bottom:22px;font-size:13.5px;line-height:1.55;">
    ⚠️ <strong>Cette page est un outil de test.</strong> Elle modifie le plan de TON org actuelle (ID #<?= $org_id ?>) pour visualiser les blocages.
    Une fois tes tests terminés, n'oublie pas de remettre ton plan sur <strong>Sur-mesure</strong> pour retrouver l'accès illimité.
  </div>

  <?php if ($message): ?>
    <div style="padding:14px 18px;border-radius:10px;margin-bottom:22px;font-size:14px;
                <?= $message_type === 'success' ? 'background:#D1FAE5;border:1px solid #A7F3D0;color:#065F46;' : 'background:#FEE2E2;border:1px solid #FCA5A5;color:#991B1B;' ?>">
      <?= h($message) ?>
    </div>
  <?php endif; ?>

  <!-- État actuel -->
  <div style="background:white;border:1px solid #E2E8F0;border-radius:14px;padding:22px;margin-bottom:22px;">
    <h3 style="margin:0 0 14px;font-size:16px;">📊 État actuel de ton org</h3>
    <?php if ($subscription): ?>
      <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(180px, 1fr));gap:14px;">
        <div>
          <div style="font-size:11px;color:#64748B;text-transform:uppercase;letter-spacing:0.05em;font-weight:700;">Plan actuel</div>
          <div style="font-size:18px;font-weight:700;color:#0F172A;margin-top:4px;"><?= h($subscription['plan_name']) ?></div>
        </div>
        <div>
          <div style="font-size:11px;color:#64748B;text-transform:uppercase;letter-spacing:0.05em;font-weight:700;">Statut</div>
          <div style="font-size:18px;font-weight:700;margin-top:4px;
                      <?= $subscription['status'] === 'active' ? 'color:#059669;' : ($subscription['status'] === 'overdue' ? 'color:#DC2626;' : 'color:#EA580C;') ?>">
            <?= h($subscription['status']) ?>
          </div>
        </div>
        <?php if ($subscription['grace_period_end']): ?>
        <div>
          <div style="font-size:11px;color:#64748B;text-transform:uppercase;letter-spacing:0.05em;font-weight:700;">Fin de grâce</div>
          <div style="font-size:14px;color:#0F172A;margin-top:4px;"><?= h(date('d/m/Y', strtotime($subscription['grace_period_end']))) ?></div>
        </div>
        <?php endif; ?>
      </div>
    <?php else: ?>
      <p style="margin:0;color:#64748B;">Aucune subscription pour cette org. Bascule un plan ci-dessous pour en créer une.</p>
    <?php endif; ?>
  </div>

  <!-- Boutons de bascule -->
  <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(280px, 1fr));gap:16px;">

    <form method="POST" style="background:white;border:2px solid #E2E8F0;border-radius:14px;padding:22px;">
      <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token'] ?? '') ?>">
      <input type="hidden" name="plan_slug" value="demarrage">
      <input type="hidden" name="status" value="active">
      <div style="font-size:32px;margin-bottom:10px;">🆓</div>
      <h3 style="margin:0 0 6px;font-size:16px;">Démarrage (actif)</h3>
      <p style="margin:0 0 16px;font-size:13px;color:#64748B;line-height:1.5;">
        Pour tester les <strong>blocages stricts</strong> et l'effet <strong>flou</strong> sur diffusion email.
      </p>
      <button type="submit" style="width:100%;background:#0F172A;color:white;padding:11px;border-radius:10px;border:none;font-weight:600;cursor:pointer;font-family:inherit;font-size:14px;">
        Basculer Démarrage actif →
      </button>
    </form>

    <form method="POST" style="background:white;border:2px solid #059669;border-radius:14px;padding:22px;position:relative;">
      <span style="position:absolute;top:-10px;right:14px;background:#059669;color:white;padding:3px 10px;border-radius:999px;font-size:10px;font-weight:700;">RECOMMANDÉ</span>
      <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token'] ?? '') ?>">
      <input type="hidden" name="plan_slug" value="assokit">
      <input type="hidden" name="status" value="active">
      <div style="font-size:32px;margin-bottom:10px;">⭐</div>
      <h3 style="margin:0 0 6px;font-size:16px;">Assokit (actif)</h3>
      <p style="margin:0 0 16px;font-size:13px;color:#64748B;line-height:1.5;">
        Pour voir le <strong>fonctionnement normal</strong> avec quotas mensuels.
      </p>
      <button type="submit" style="width:100%;background:#059669;color:white;padding:11px;border-radius:10px;border:none;font-weight:600;cursor:pointer;font-family:inherit;font-size:14px;">
        Basculer Assokit actif →
      </button>
    </form>

    <form method="POST" style="background:white;border:2px solid #FCD34D;border-radius:14px;padding:22px;">
      <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token'] ?? '') ?>">
      <input type="hidden" name="plan_slug" value="assokit">
      <input type="hidden" name="status" value="pending_payment">
      <div style="font-size:32px;margin-bottom:10px;">⏳</div>
      <h3 style="margin:0 0 6px;font-size:16px;">Assokit (en grâce)</h3>
      <p style="margin:0 0 16px;font-size:13px;color:#64748B;line-height:1.5;">
        Pour voir le <strong>bandeau orange</strong> "Régularisez sous Xj".
      </p>
      <button type="submit" style="width:100%;background:#EA580C;color:white;padding:11px;border-radius:10px;border:none;font-weight:600;cursor:pointer;font-family:inherit;font-size:14px;">
        Basculer en grâce →
      </button>
    </form>

    <form method="POST" style="background:white;border:2px solid #DC2626;border-radius:14px;padding:22px;">
      <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token'] ?? '') ?>">
      <input type="hidden" name="plan_slug" value="assokit">
      <input type="hidden" name="status" value="overdue">
      <div style="font-size:32px;margin-bottom:10px;">🔒</div>
      <h3 style="margin:0 0 6px;font-size:16px;">Assokit (overdue J+15)</h3>
      <p style="margin:0 0 16px;font-size:13px;color:#64748B;line-height:1.5;">
        Pour voir l'<strong>overlay flou bloquant</strong> sur le dashboard.
      </p>
      <button type="submit" style="width:100%;background:#DC2626;color:white;padding:11px;border-radius:10px;border:none;font-weight:600;cursor:pointer;font-family:inherit;font-size:14px;">
        Basculer overdue →
      </button>
    </form>

    <form method="POST" style="background:white;border:2px solid #7E22CE;border-radius:14px;padding:22px;">
      <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token'] ?? '') ?>">
      <input type="hidden" name="plan_slug" value="sur-mesure">
      <input type="hidden" name="status" value="active">
      <div style="font-size:32px;margin-bottom:10px;">💎</div>
      <h3 style="margin:0 0 6px;font-size:16px;">Sur-mesure (illimité)</h3>
      <p style="margin:0 0 16px;font-size:13px;color:#64748B;line-height:1.5;">
        Plan <strong>fondateur</strong>. À remettre une fois tes tests finis.
      </p>
      <button type="submit" style="width:100%;background:#7E22CE;color:white;padding:11px;border-radius:10px;border:none;font-weight:600;cursor:pointer;font-family:inherit;font-size:14px;">
        Revenir au Sur-mesure →
      </button>
    </form>
  </div>

  <!-- Liens de test rapide -->
  <div style="background:white;border:1px solid #E2E8F0;border-radius:14px;padding:22px;margin-top:22px;">
    <h3 style="margin:0 0 14px;font-size:16px;">🔗 Liens de test rapide</h3>
    <div style="display:flex;gap:10px;flex-wrap:wrap;">
      <a href="/dashboard" class="btn btn-ghost">→ Dashboard (bandeaux)</a>
      <a href="/diffusion-email" class="btn btn-ghost">→ Diffusion email</a>
      <a href="/nouveau-adherent" class="btn btn-ghost">→ Nouvel adhérent</a>
      <a href="/admin-nouveau-utilisateur" class="btn btn-ghost">→ Nouvel utilisateur</a>
      <a href="/mon-asso-plan" class="btn btn-ghost">→ Mon abonnement</a>
      <a href="/mon-asso-ia" class="btn btn-ghost">→ IA</a>
    </div>
  </div>

</main>

<?php render_foot(); ?>
