<?php
/**
 * fondateur-create-organization.php
 * --------------------------------------------------------------
 * Création de compte client DIRECTEMENT par le fondateur.
 *
 * Cas d'usage :
 *   1. Démo / onboarding personnalisé (client convaincu en visio)
 *   2. Cadeau / amis / famille (compte gratuit ou plan offert)
 *   3. Sur-mesure (paiement par virement, activation manuelle)
 *
 * Workflow :
 *   1. Fondateur remplit formulaire (asso, email, plan, mode paiement)
 *   2. Création organization + user + subscription d'un coup
 *   3. Email de bienvenue envoyé avec mot de passe temporaire
 *   4. Le client se connecte et a son compte ACTIF immédiatement
 * --------------------------------------------------------------
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes-layout.php';
require_once __DIR__ . '/trial-helpers.php';
@require_once __DIR__ . '/stripe-helpers.php';

// Fallback si stripe-helpers.php n'est pas encore déployé
if (!function_exists('ak_format_price_cents')) {
    function ak_format_price_cents(int $cents): string {
        return number_format($cents / 100, 2, ',', ' ') . '€';
    }
}

require_login();
$user = ak_platform_flags(current_user());

// Restriction fondateur
$has_access = !empty($user['is_founder']) || !empty($user['is_super_admin']);
if (!$has_access) {
    http_response_code(403);
    die('Accès réservé aux fondateurs.');
}

$flash_msg = null;
$flash_type = 'success';
$created_credentials = null;

// Récupération plans pour le formulaire
$plans = [];
try {
    // Colonnes lues selon le schéma réel (trial_converts_to / tagline n'existent pas partout) :
    // une colonne absente vidait toute la liste des plans.
    $pc = ak_db_columns($pdo, 'asso_plans');
    $opt = fn(string $c, string $def) => isset($pc[$c]) || !$pc ? $c : "$def AS $c";
    $trial_where = isset($pc['is_trial']) || !$pc ? "is_trial = 1 OR slug = 'pro-essai'" : "slug = 'pro-essai'";
    $plans = $pdo->query("SELECT id, name, slug, price_cents, " . $opt('tagline', 'NULL') . ", " . $opt('is_trial', '0') . ", " . $opt('trial_converts_to', 'NULL') . ", " . $opt('is_custom_quote', '0') . "
                          FROM asso_plans WHERE " . (isset($pc['is_visible']) || !$pc ? "is_visible = 1 OR $trial_where" : "1 = 1") . "
                          ORDER BY is_trial ASC, price_cents ASC")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($plans as &$__pp) $__pp['is_trial'] = !empty($__pp['is_trial']) || $__pp['slug'] === 'pro-essai';
    unset($__pp);
} catch (Throwable $e) {
    error_log('[fondateur-create-organization] plans: ' . get_class($e));
}

// Traitement du POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!check_csrf($_POST['csrf_token'] ?? '')) { http_response_code(403); die('Requete invalide (jeton CSRF manquant ou expire).'); }
    try {
        $org_name = trim((string)($_POST['org_name'] ?? ''));
        $billing_email = trim((string)($_POST['billing_email'] ?? ''));
        $first_name = trim((string)($_POST['first_name'] ?? ''));
        $last_name = trim((string)($_POST['last_name'] ?? ''));
        $plan_id = (int)($_POST['plan_id'] ?? 0);
        $payment_mode = (string)($_POST['payment_mode'] ?? 'manual');
        $with_addon_domain = !empty($_POST['with_addon_domain']);
        $period_days = (int)($_POST['period_days'] ?? 30);
        $send_welcome_email = !empty($_POST['send_welcome_email']);
        $custom_password = trim((string)($_POST['custom_password'] ?? ''));

        // Validations
        if (empty($org_name)) throw new Exception('Nom de l\'organisation obligatoire');
        if (empty($billing_email) || !filter_var($billing_email, FILTER_VALIDATE_EMAIL)) throw new Exception('Email invalide');
        if (empty($first_name)) throw new Exception('Prénom obligatoire');
        if ($plan_id <= 0) throw new Exception('Plan obligatoire');
        if (!in_array($payment_mode, ['stripe', 'manual', 'wire_transfer', 'free_grant'], true)) {
            throw new Exception('Mode de paiement invalide');
        }

        // Vérifier que l'email n'existe pas déjà
        $check = $pdo->prepare("SELECT id FROM users WHERE email = :e LIMIT 1");
        $check->execute([':e' => $billing_email]);
        if ($check->fetchColumn()) {
            throw new Exception('Cet email est déjà utilisé pour un autre compte');
        }

        // Génération slug et mot de passe
        $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $org_name));
        $slug = trim($slug, '-');
        if (strlen($slug) < 3) $slug = 'asso-' . substr(md5(microtime()), 0, 6);

        // Vérifier unicité slug
        $cs = $pdo->prepare("SELECT COUNT(*) FROM organizations WHERE slug = :s");
        $cs->execute([':s' => $slug]);
        if ((int)$cs->fetchColumn() > 0) {
            $slug .= '-' . substr(md5(microtime()), 0, 4);
        }

        $password_plain = $custom_password ?: bin2hex(random_bytes(6)); // 12 chars hex
        $password_hash = password_hash($password_plain, PASSWORD_BCRYPT);

        $pdo->beginTransaction();

        // 1. Créer organization
        $ins_org = $pdo->prepare("
            INSERT INTO organizations (name, slug, billing_email, created_at)
            VALUES (:n, :s, :be, NOW())
        ");
        $ins_org->execute([
            ':n' => $org_name,
            ':s' => $slug,
            ':be' => $billing_email,
        ]);
        $new_org_id = (int)$pdo->lastInsertId();

        // 2. Créer user (admin de l'org)
        $ins_user = $pdo->prepare("
            INSERT INTO users (org_id, email, password_hash, first_name, last_name, role, is_active, created_at)
            VALUES (:o, :e, :p, :f, :l, 'admin', 1, NOW())
        ");
        $ins_user->execute([
            ':o' => $new_org_id,
            ':e' => $billing_email,
            ':p' => $password_hash,
            ':f' => $first_name,
            ':l' => $last_name,
        ]);
        $new_user_id = (int)$pdo->lastInsertId();

        // 3. Créer subscription
        $period_end = date('Y-m-d H:i:s', strtotime('+' . max(1, $period_days) . ' days'));
        // Plan choisi : essai bride ?
        $chosen_plan = null;
        foreach ($plans as $__pp) { if ((int)$__pp['id'] === $plan_id) { $chosen_plan = $__pp; break; } }
        $is_trial_plan = $chosen_plan && !empty($chosen_plan['is_trial']);
        $chosen_slug   = $chosen_plan['slug'] ?? null;
        $sub_status = 'active';
        if ($payment_mode === 'stripe') {
            // En mode stripe, activation se fera via paiement client
            $sub_status = 'pending_payment';
        }
        if ($is_trial_plan) {
            // Essai bride -> statut trial (expiry gere par cron-job-essai)
            $sub_status = 'trial';
        }

        // Colonnes écrites selon le schéma réel (ak_db_insert) ; le plan doit exister
        if (!$chosen_plan) throw new Exception('Plan inconnu : choisissez un plan de la liste');
        $new_sub_id = ak_db_insert($pdo, 'asso_subscriptions', [
            'org_id' => $new_org_id,
            'plan_id' => $plan_id,
            'payment_mode' => $payment_mode,
            'status' => $sub_status,
            'current_period_end' => $period_end,
        ], ['started_at' => 'NOW()', 'created_at' => 'NOW()', 'updated_at' => 'NOW()']);

        // 4. Add-on domaine si demandé
        if ($with_addon_domain) {
            $ins_addon = $pdo->prepare("
                INSERT INTO asso_subscription_addons
                    (subscription_id, org_id, addon_type, addon_name, price_cents_monthly, status)
                VALUES
                    (:sid, :oid, 'custom_domain', 'Domaine personnalisé', 1000, 'active')
            ");
            $ins_addon->execute([
                ':sid' => $new_sub_id,
                ':oid' => $new_org_id,
            ]);
        }

        // Sync organizations : plan + statut essai (statut toujours explicite : la valeur par défaut
        // de la colonne ne doit pas faire passer un client pour un essai, ni l'inverse)
        if ($is_trial_plan) {
            $pdo->prepare("UPDATE organizations SET plan = :pl, status = 'trial', trial_ends_at = :te WHERE id = :id")
                ->execute([':pl' => $chosen_slug, ':te' => $period_end, ':id' => $new_org_id]);
        } else {
            $pdo->prepare("UPDATE organizations SET plan = :pl, status = 'active', trial_ends_at = NULL WHERE id = :id")
                ->execute([':pl' => $chosen_slug, ':id' => $new_org_id]);
        }

        $pdo->commit();

        // Essai : même trace « historique » que l'inscription en ligne (bandeau d'essai dans
        // l'espace de l'association + rappels J-7 / J-3 / J-0), hors transaction.
        if ($is_trial_plan) {
            try {
                if (ak_db_columns($pdo, 'subscriptions') && ($legacy_status = ak_db_pick($pdo, 'subscriptions', 'status', ['trial']))) {
                    ak_db_insert($pdo, 'subscriptions', [
                        'org_id' => $new_org_id,
                        'plan' => ak_db_pick($pdo, 'subscriptions', 'plan', ['pro', 'association', 'essentiel']) ?? 'pro',
                        'status' => $legacy_status,
                        'billing_cycle' => ak_db_pick($pdo, 'subscriptions', 'billing_cycle', ['monthly']) ?? 'monthly',
                        'price_ht' => 0.00, 'tva_rate' => 20.00, 'current_period_end' => $period_end,
                    ], ['started_at' => 'NOW()', 'current_period_start' => 'NOW()', 'created_at' => 'NOW()']);
                }
            } catch (Throwable $e) {
                error_log('[fondateur-create-organization] subscriptions essai: ' . get_class($e));
            }
        }

        // 5. Email de bienvenue (best-effort)
        $email_sent = false;
        if ($send_welcome_email) {
            try {
                @require_once __DIR__ . '/resend-helper.php';
                // Même libellé que partout ailleurs (« Essai PRO », « Assokit »…)
                $plan_name = $chosen_plan ? ak_plan_label($chosen_plan['slug'], $chosen_plan['name']) : '';
                $base_url = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'assokit.fr');
                $subject = 'Bienvenue chez Assokit !';
                $body = "<h2>🌿 Bienvenue chez Assokit, " . htmlspecialchars($first_name) . " !</h2>"
                     . "<p>Votre compte <strong>" . htmlspecialchars($org_name) . "</strong> est prêt.</p>"
                     . "<p><strong>Plan actif :</strong> " . htmlspecialchars($plan_name) . ($is_trial_plan ? " (essai gratuit, 0 €)" : "") . "<br>"
                     . "<strong>" . ($is_trial_plan ? "Fin de l'essai" : "Validité") . " :</strong> " . ($is_trial_plan ? "le " : "jusqu'au ") . date('d/m/Y', strtotime($period_end)) . "</p>"
                     . "<hr><h3>Vos identifiants</h3>"
                     . "<p><strong>Email :</strong> " . htmlspecialchars($billing_email) . "<br>"
                     . "<strong>Mot de passe :</strong> <code style='background:#F1F5F9;padding:4px 8px;border-radius:4px;'>" . htmlspecialchars($password_plain) . "</code></p>"
                     . "<p>👉 <a href='{$base_url}/login'>Se connecter à Assokit</a></p>"
                     . "<p>Vous pourrez modifier votre mot de passe depuis votre profil.</p>"
                     . "<p>🌿 L'équipe Assokit</p>";
                if (function_exists('send_transactional_email')) {
                    $res = send_transactional_email($billing_email, $subject, $body, ['tag' => 'welcome_founder']);
                    $email_sent = !empty($res['success']);
                } elseif (function_exists('ak_send_email')) {
                    $email_sent = ak_send_email($billing_email, $subject, $body);
                }
            } catch (Throwable $e) {
                error_log('[fondateur-create-organization] Email: ' . $e->getMessage());
            }
        }

        $created_credentials = [
            'org_name' => $org_name,
            'org_id' => $new_org_id,
            'email' => $billing_email,
            'password' => $password_plain,
            'plan_id' => $plan_id,
            'period_end' => $period_end,
            'email_sent' => $email_sent,
            'is_trial' => $is_trial_plan,
            'plan_label' => $chosen_plan ? ak_plan_label($chosen_plan['slug'], $chosen_plan['name']) : '',
        ];

        $flash_msg = '✅ Compte créé avec succès !';

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $flash_msg = 'Erreur : ' . $e->getMessage();
        $flash_type = 'error';
    }
}

render_head('Créer un compte client');
render_sidebar('fondateur-create-organization');
?>

<main class="main">
  <nav class="crumbs">
    <a href="/dashboard">Dashboard</a>
    <span class="sep">›</span>
    <span class="current">🌱 Créer un compte client</span>
  </nav>

  <div class="main-head" style="margin-bottom:24px;">
    <div>
      <h1 style="margin:0 0 4px;">🌱 Créer un compte client</h1>
      <p style="color:#64748B;margin:0;">Création directe avec activation immédiate du plan</p>
    </div>
  </div>

  <?php if ($flash_msg): ?>
    <div style="padding:14px 18px;border-radius:10px;margin-bottom:18px;font-size:14px;<?= $flash_type === 'error' ? 'background:#FEE2E2;border:1px solid #FECACA;color:#991B1B;' : 'background:#D1FAE5;border:1px solid #A7F3D0;color:#065F46;' ?>">
      <?= htmlspecialchars($flash_msg) ?>
    </div>
  <?php endif; ?>

  <?php if ($created_credentials): ?>

    <!-- ============== AFFICHAGE DES CREDENTIALS APRÈS CRÉATION ============== -->
    <div style="background:linear-gradient(135deg, #F0FDF4 0%, #FAF8F5 100%); border:2px solid #A7F3D0; border-radius:18px; padding:32px;margin-bottom:22px;">
      <div style="text-align:center;margin-bottom:24px;">
        <div style="font-size:54px;margin-bottom:10px;">🎉</div>
        <h2 style="margin:0 0 6px;color:#047857;font-size:24px;">Compte créé avec succès !</h2>
        <p style="color:#065F46;font-size:14px;margin:0;">Le client peut maintenant se connecter et utiliser Assokit immédiatement.</p>
      </div>

      <div style="background:white;border:1px solid #A7F3D0;border-radius:12px;padding:20px;margin-bottom:18px;">
        <h3 style="margin:0 0 14px;font-size:16px;color:#0F172A;">📋 Identifiants à transmettre au client</h3>
        <div style="display:grid;gap:10px;font-family:monospace;font-size:13.5px;">
          <div style="display:flex;justify-content:space-between;padding:10px 14px;background:#F8FAFC;border-radius:8px;">
            <span style="color:#64748B;">Organisation :</span>
            <strong><?= htmlspecialchars($created_credentials['org_name']) ?></strong>
          </div>
          <div style="display:flex;justify-content:space-between;padding:10px 14px;background:#F8FAFC;border-radius:8px;">
            <span style="color:#64748B;">Email :</span>
            <strong><?= htmlspecialchars($created_credentials['email']) ?></strong>
          </div>
          <div style="display:flex;justify-content:space-between;padding:10px 14px;background:#FEF3C7;border:1px solid #FCD34D;border-radius:8px;">
            <span style="color:#92400E;">🔑 Mot de passe :</span>
            <strong style="color:#92400E;font-size:15px;letter-spacing:0.04em;"><?= htmlspecialchars($created_credentials['password']) ?></strong>
          </div>
          <?php if ($created_credentials['plan_label'] !== ''): ?>
          <div style="display:flex;justify-content:space-between;padding:10px 14px;background:<?= $created_credentials['is_trial'] ? '#EDE9FE' : '#F8FAFC' ?>;border-radius:8px;">
            <span style="color:#64748B;">Plan :</span>
            <strong<?= $created_credentials['is_trial'] ? ' style="color:#5B21B6;"' : '' ?>><?= htmlspecialchars($created_credentials['plan_label']) ?><?= $created_credentials['is_trial'] ? ' · ESSAI · 0 €' : '' ?></strong>
          </div>
          <?php endif; ?>
          <div style="display:flex;justify-content:space-between;padding:10px 14px;background:#F8FAFC;border-radius:8px;">
            <span style="color:#64748B;"><?= $created_credentials['is_trial'] ? 'Fin de l’essai :' : 'Validité :' ?></span>
            <strong><?= $created_credentials['is_trial'] ? 'le ' : 'jusqu’au ' ?><?= htmlspecialchars(date('d/m/Y', strtotime($created_credentials['period_end']))) ?></strong>
          </div>
        </div>
      </div>

      <div style="background:#FEF3C7;border:1px solid #FCD34D;border-radius:10px;padding:14px 18px;margin-bottom:18px;font-size:13px;color:#78350F;">
        ⚠️ <strong>Note importante :</strong> Le mot de passe affiché ci-dessus ne sera <strong>plus jamais accessible</strong>. Copiez-le maintenant et transmettez-le au client par un canal sécurisé.
        <?php if ($created_credentials['email_sent']): ?>
          <br><br>✅ Un email de bienvenue avec ces informations a été envoyé à <code><?= htmlspecialchars($created_credentials['email']) ?></code>.
        <?php else: ?>
          <br><br>📧 L'email de bienvenue n'a pas été envoyé (option non cochée). Pensez à transmettre les identifiants manuellement.
        <?php endif; ?>
      </div>

      <div style="display:flex;gap:10px;flex-wrap:wrap;">
        <a href="/fondateur-create-organization" style="background:#059669;color:white;padding:11px 20px;border-radius:10px;text-decoration:none;font-weight:600;font-size:14px;">+ Créer un autre compte</a>
        <a href="/fondateur-subscriptions" style="background:white;color:#475569;padding:11px 20px;border-radius:10px;text-decoration:none;font-weight:600;font-size:14px;border:1px solid #E2E8F0;">📊 Voir tous les abonnements</a>
      </div>
    </div>

  <?php else: ?>

    <!-- ============== FORMULAIRE DE CRÉATION ============== -->
    <form method="post" action="" style="background:white;border:1px solid #E2E8F0;border-radius:14px;padding:28px;max-width:780px;">
      <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token'] ?? '') ?>">

      <h2 style="margin:0 0 6px;font-size:18px;">📋 Informations du client</h2>
      <p style="color:#64748B;font-size:13px;margin:0 0 22px;">Remplissez ce formulaire pour créer un compte client avec activation immédiate.</p>

      <!-- Organisation -->
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px;">
        <div>
          <label style="display:block;font-size:13px;font-weight:600;color:#475569;margin-bottom:6px;">Nom de l'organisation *</label>
          <input type="text" name="org_name" required placeholder="Ex: Latitude91" style="width:100%;padding:10px 14px;border:1px solid #E2E8F0;border-radius:8px;font-size:14px;">
        </div>
        <div>
          <label style="display:block;font-size:13px;font-weight:600;color:#475569;margin-bottom:6px;">Email du contact principal *</label>
          <input type="email" name="billing_email" required placeholder="contact@latitude91.fr" style="width:100%;padding:10px 14px;border:1px solid #E2E8F0;border-radius:8px;font-size:14px;">
        </div>
      </div>

      <!-- Identité user -->
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px;">
        <div>
          <label style="display:block;font-size:13px;font-weight:600;color:#475569;margin-bottom:6px;">Prénom *</label>
          <input type="text" name="first_name" required placeholder="Jean" style="width:100%;padding:10px 14px;border:1px solid #E2E8F0;border-radius:8px;font-size:14px;">
        </div>
        <div>
          <label style="display:block;font-size:13px;font-weight:600;color:#475569;margin-bottom:6px;">Nom</label>
          <input type="text" name="last_name" placeholder="Dupont" style="width:100%;padding:10px 14px;border:1px solid #E2E8F0;border-radius:8px;font-size:14px;">
        </div>
      </div>

      <!-- Mot de passe -->
      <div style="margin-bottom:22px;">
        <label style="display:block;font-size:13px;font-weight:600;color:#475569;margin-bottom:6px;">Mot de passe (optionnel)</label>
        <input type="text" name="custom_password" placeholder="Laissez vide pour générer automatiquement" style="width:100%;padding:10px 14px;border:1px solid #E2E8F0;border-radius:8px;font-size:14px;font-family:monospace;">
        <div style="font-size:11.5px;color:#64748B;margin-top:4px;">Si vide, un mot de passe sécurisé sera généré automatiquement.</div>
      </div>

      <hr style="border:none;border-top:1px solid #F1F5F9;margin:22px 0;">

      <!-- Plan -->
      <h3 style="margin:0 0 14px;font-size:16px;">💎 Plan à activer</h3>
      <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(180px, 1fr));gap:10px;margin-bottom:18px;">
        <?php foreach ($plans as $i => $p):
          if (!empty($p['is_trial'])) {
              $price_str = 'Essai gratuit · 0 € · durée choisie ci-dessous';
          } else {
              $price_str = (int)$p['price_cents'] > 0 ? ak_format_price_cents((int)$p['price_cents']) . '/mois'
                         : (!empty($p['is_custom_quote']) || $p['slug'] === 'sur-mesure' ? 'Sur devis' : 'Gratuit');
          }
        ?>
        <label data-plan style="display:block;padding:14px;background:#FAF8F5;border:2px solid #E2E8F0;border-radius:10px;cursor:pointer;transition:all 0.15s;" onclick="this.querySelector('input').checked=true;document.querySelectorAll('label[data-plan]').forEach(l=>{l.style.borderColor='#E2E8F0';l.style.background='#FAF8F5';});this.style.borderColor='#059669';this.style.background='#ECFDF5';">
          <input type="radio" name="plan_id" value="<?= (int)$p['id'] ?>" required <?= $i === 0 ? '' : '' ?> style="display:none;">
          <div style="font-weight:700;color:<?= !empty($p['is_trial']) ? '#5B21B6' : '#0F172A' ?>;font-size:14px;"><?= htmlspecialchars(ak_plan_label($p['slug'], $p['name'])) ?><?php if (!empty($p['is_trial'])): ?> <span style="background:#EDE9FE;color:#5B21B6;padding:1px 7px;border-radius:999px;font-size:10.5px;font-weight:800;letter-spacing:.03em;">ESSAI</span><?php endif; ?></div>
          <div style="font-size:13px;color:#64748B;margin-top:4px;"><?= htmlspecialchars($price_str) ?></div>
          <?php if (!empty($p['tagline'])): ?>
            <div style="font-size:11.5px;color:#64748B;margin-top:6px;line-height:1.4;"><?= htmlspecialchars($p['tagline']) ?></div>
          <?php endif; ?>
        </label>
        <?php endforeach; ?>
      </div>

      <!-- Add-on -->
      <label style="display:flex;align-items:center;gap:10px;padding:14px;background:#EFF6FF;border:1px solid #BFDBFE;border-radius:10px;cursor:pointer;margin-bottom:22px;">
        <input type="checkbox" name="with_addon_domain" value="1" style="width:18px;height:18px;">
        <div>
          <div style="font-weight:700;font-size:14px;color:#1E40AF;">🏆 + Domaine personnalisé (+10€/mois)</div>
          <div style="font-size:12px;color:#475569;">Ajoute l'option white-label avec domaine perso</div>
        </div>
      </label>

      <hr style="border:none;border-top:1px solid #F1F5F9;margin:22px 0;">

      <!-- Mode paiement -->
      <h3 style="margin:0 0 14px;font-size:16px;">💳 Mode de paiement</h3>
      <div style="display:grid;gap:10px;margin-bottom:18px;">
        <label style="display:flex;align-items:flex-start;gap:10px;padding:14px;background:#FAF8F5;border:2px solid #E2E8F0;border-radius:10px;cursor:pointer;">
          <input type="radio" name="payment_mode" value="free_grant" checked style="margin-top:3px;">
          <div>
            <div style="font-weight:700;font-size:14px;color:#0F172A;">🎁 Plan offert (gratuit)</div>
            <div style="font-size:12px;color:#64748B;">Activation gratuite (cadeau, démo, ami) - pas de paiement</div>
          </div>
        </label>
        <label style="display:flex;align-items:flex-start;gap:10px;padding:14px;background:#FAF8F5;border:2px solid #E2E8F0;border-radius:10px;cursor:pointer;">
          <input type="radio" name="payment_mode" value="manual" style="margin-top:3px;">
          <div>
            <div style="font-weight:700;font-size:14px;color:#0F172A;">📝 Activation manuelle</div>
            <div style="font-size:12px;color:#64748B;">Paiement à régler hors plateforme (ex: Sur-mesure facturé manuellement)</div>
          </div>
        </label>
        <label style="display:flex;align-items:flex-start;gap:10px;padding:14px;background:#FAF8F5;border:2px solid #E2E8F0;border-radius:10px;cursor:pointer;">
          <input type="radio" name="payment_mode" value="wire_transfer" style="margin-top:3px;">
          <div>
            <div style="font-weight:700;font-size:14px;color:#0F172A;">🏦 Virement bancaire reçu</div>
            <div style="font-size:12px;color:#64748B;">Le client a déjà payé par virement, vous activez son accès</div>
          </div>
        </label>
        <label style="display:flex;align-items:flex-start;gap:10px;padding:14px;background:#FAF8F5;border:2px solid #E2E8F0;border-radius:10px;cursor:pointer;">
          <input type="radio" name="payment_mode" value="stripe" style="margin-top:3px;">
          <div>
            <div style="font-weight:700;font-size:14px;color:#0F172A;">💳 Stripe (paiement client)</div>
            <div style="font-size:12px;color:#64748B;">Le compte est créé en attente, le client paye via /mon-asso-paiement</div>
          </div>
        </label>
      </div>

      <!-- Période -->
      <div style="margin-bottom:22px;">
        <label style="display:block;font-size:13px;font-weight:600;color:#475569;margin-bottom:6px;">Durée d'activation (en jours)</label>
        <select name="period_days" style="width:200px;padding:10px 14px;border:1px solid #E2E8F0;border-radius:8px;font-size:14px;">
          <option value="14">14 jours (essai Pro)</option>
          <option value="30" selected>30 jours</option>
          <option value="40">40 jours (essai Pro)</option>
          <option value="90">90 jours (3 mois)</option>
          <option value="180">180 jours (6 mois)</option>
          <option value="365">365 jours (1 an)</option>
          <option value="3650">10 ans (illimité)</option>
        </select>
      </div>

      <!-- Email bienvenue -->
      <label style="display:flex;align-items:center;gap:10px;padding:12px 16px;background:#F0FDF4;border:1px solid #A7F3D0;border-radius:10px;margin-bottom:22px;cursor:pointer;">
        <input type="checkbox" name="send_welcome_email" value="1" checked style="width:18px;height:18px;">
        <div>
          <div style="font-weight:700;font-size:14px;color:#047857;">📧 Envoyer un email de bienvenue avec les identifiants</div>
          <div style="font-size:12px;color:#065F46;">Le client recevra son mot de passe par email automatiquement</div>
        </div>
      </label>

      <!-- Bouton -->
      <button type="submit" style="width:100%;background:linear-gradient(180deg,#059669 0%,#047857 100%);color:white;padding:14px 24px;border:none;border-radius:12px;font-weight:600;font-size:15px;cursor:pointer;box-shadow:0 4px 14px rgba(5,150,105,0.30);">
        🚀 Créer le compte et activer le plan
      </button>

    </form>

  <?php endif; ?>

</main>

<script>
// Highlight de la carte de plan sélectionnée
document.querySelectorAll('input[name="plan_id"]').forEach(input => {
  input.addEventListener('change', function() {
    document.querySelectorAll('input[name="plan_id"]').forEach(i => {
      i.closest('label').style.borderColor = i.checked ? '#059669' : '#E2E8F0';
    });
  });
});
</script>

<?php render_foot(); ?>
