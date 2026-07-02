<?php
/**
 * fondateur-stripe-config.php
 * --------------------------------------------------------------
 * Page d'administration de la configuration Stripe (FONDATEUR).
 *
 * Permet de :
 *   - Activer/Désactiver Stripe globalement
 *   - Saisir/Modifier les clés API Stripe (publishable + secret)
 *   - Saisir le webhook secret
 *   - Tester la connexion à Stripe
 *   - Voir l'état des produits/prix configurés
 *   - Activer/Désactiver la TVA (pour quand RBPS sera assujettie)
 *
 * Sécurité :
 *   - Réservé aux fondateurs (is_founder=1 OU is_super_admin=1 OU org_id=1)
 *   - Les clés secrètes sont masquées dans l'affichage
 *   - HTTPS obligatoire (vérification au chargement)
 * --------------------------------------------------------------
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes-layout.php';
@require_once __DIR__ . '/stripe-config-helpers.php';
@require_once __DIR__ . '/stripe-helpers.php';

require_login();
$user = current_user();

// Restriction fondateur
$has_access = !empty($user['is_founder']) || !empty($user['is_super_admin']) || (int)$user['org_id'] === 1;
if (!$has_access) {
    http_response_code(403);
    die('Accès réservé aux fondateurs.');
}

// Vérification HTTPS (sécurité absolue pour les clés API)
$is_https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (int)($_SERVER['SERVER_PORT'] ?? 80) === 443;

// Traitement du POST
$flash_msg = null;
$flash_type = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');

    try {
        switch ($action) {
            case 'save_keys':
                $pk = trim((string)($_POST['stripe_publishable_key'] ?? ''));
                $sk = trim((string)($_POST['stripe_secret_key'] ?? ''));
                $whs = trim((string)($_POST['stripe_webhook_secret'] ?? ''));

                // Validation format
                if ($pk && !preg_match('/^pk_(test|live)_/', $pk)) {
                    throw new Exception('Format clé publique invalide (doit commencer par pk_test_ ou pk_live_)');
                }
                if ($sk && !preg_match('/^sk_(test|live)_/', $sk)) {
                    throw new Exception('Format clé secrète invalide (doit commencer par sk_test_ ou sk_live_)');
                }
                if ($whs && !preg_match('/^whsec_/', $whs)) {
                    throw new Exception('Format webhook secret invalide (doit commencer par whsec_)');
                }

                // Si les valeurs ne sont pas masquées, on enregistre
                if ($pk && $pk !== '••••••••') ak_stripe_config_set($pdo, 'stripe_publishable_key', $pk);
                if ($sk && $sk !== '••••••••') ak_stripe_config_set($pdo, 'stripe_secret_key', $sk);
                if ($whs && $whs !== '••••••••') ak_stripe_config_set($pdo, 'stripe_webhook_secret', $whs);

                // Détection automatique du mode
                $detected_mode = (strpos($sk, 'sk_live_') === 0) ? 'live' : 'test';
                if ($sk) ak_stripe_config_set($pdo, 'stripe_mode', $detected_mode);

                $flash_msg = 'Clés API enregistrées avec succès. Mode détecté : ' . $detected_mode;
                break;

            case 'test_connection':
                $result = ak_stripe_test_connection($pdo);
                if ($result['ok']) {
                    $flash_msg = '✅ ' . $result['message'];
                } else {
                    $flash_msg = '❌ ' . $result['message'];
                    $flash_type = 'error';
                }
                break;

            case 'enable_stripe':
                // Vérifier que les clés sont là avant d'activer
                if (empty(ak_stripe_get_secret_key($pdo)) || empty(ak_stripe_get_publishable_key($pdo))) {
                    throw new Exception('Impossible d\'activer Stripe : clés API manquantes');
                }
                ak_stripe_config_set($pdo, 'stripe_enabled', '1');
                $flash_msg = '✅ Stripe ACTIVÉ. Les paiements sont maintenant possibles.';
                break;

            case 'disable_stripe':
                ak_stripe_config_set($pdo, 'stripe_enabled', '0');
                $flash_msg = '⚠️ Stripe désactivé. Les boutons "Régulariser" basculent sur la page contact.';
                $flash_type = 'warn';
                break;

            case 'toggle_vat':
                $current = (int)ak_stripe_config_get($pdo, 'vat_enabled', 0);
                $new_val = $current === 1 ? '0' : '1';
                ak_stripe_config_set($pdo, 'vat_enabled', $new_val);
                $flash_msg = $new_val === '1' ? 'TVA activée (20%)' : 'TVA désactivée (franchise)';
                break;

            case 'save_addon_price':
                $addon_price_id = trim((string)($_POST['addon_domain_price_id'] ?? ''));
                if ($addon_price_id && !preg_match('/^price_/', $addon_price_id)) {
                    throw new Exception('Format price_id invalide (doit commencer par price_)');
                }
                ak_stripe_config_set($pdo, 'addon_domain_price_id', $addon_price_id ?: null);
                $flash_msg = 'Price ID add-on domaine enregistré.';
                break;

            case 'save_plan_price':
                $plan_id = (int)($_POST['plan_id'] ?? 0);
                $price_id = trim((string)($_POST['stripe_price_id'] ?? ''));
                if ($plan_id <= 0) throw new Exception('Plan ID invalide');
                if ($price_id && !preg_match('/^price_/', $price_id)) {
                    throw new Exception('Format price_id invalide');
                }
                $st = $pdo->prepare("UPDATE asso_plans SET stripe_price_id_monthly = :p WHERE id = :id");
                $st->execute([':p' => $price_id ?: null, ':id' => $plan_id]);
                $flash_msg = 'Price ID du plan enregistré.';
                break;

            default:
                throw new Exception('Action inconnue');
        }
    } catch (Throwable $e) {
        $flash_msg = 'Erreur : ' . $e->getMessage();
        $flash_type = 'error';
    }
}

// Récupération des données pour affichage
$stripe_enabled = (int)ak_stripe_config_get($pdo, 'stripe_enabled', 0);
$stripe_mode = ak_stripe_config_get($pdo, 'stripe_mode', 'test');
$pk = ak_stripe_get_publishable_key($pdo);
$sk = ak_stripe_get_secret_key($pdo);
$whs = ak_stripe_get_webhook_secret($pdo);
$vat_enabled = ak_vat_is_enabled($pdo);
$vat_rate = ak_vat_get_rate($pdo);
$addon_price_id = ak_stripe_config_get($pdo, 'addon_domain_price_id', null);

// Test connexion uniquement si clés présentes
$connection_status = null;
if (!empty($sk)) {
    $connection_status = ak_stripe_test_connection($pdo);
}

// Récupération des plans pour affichage des stripe_price_id
$plans = [];
try {
    $plans = $pdo->query("SELECT id, name, slug, price_cents AS price_cents_monthly, stripe_price_id_monthly FROM asso_plans ORDER BY price_cents ASC")
                 ->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { /* table peut-être absente */ }

// Webhook URL absolue pour copier dans Stripe Dashboard
$webhook_url = (($_SERVER['HTTPS'] ?? '') === 'on' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'assokit.fr') . '/stripe-webhook';

render_head('Configuration Stripe');
render_sidebar('fondateur-stripe-config');
?>

<main class="main">
  <nav class="crumbs">
    <a href="/dashboard">Dashboard</a>
    <span class="sep">›</span>
    <span class="current">💳 Configuration Stripe</span>
  </nav>

  <div class="main-head" style="margin-bottom:24px;">
    <div>
      <h1 style="margin:0 0 4px;">💳 Configuration Stripe</h1>
      <p style="color:#64748B;margin:0;">Gestion des clés API et de la configuration des paiements</p>
    </div>
  </div>

  <?php if (!$is_https): ?>
    <div style="background:#FEE2E2;border:2px solid #DC2626;border-radius:12px;padding:18px 22px;margin-bottom:22px;color:#991B1B;font-size:14px;line-height:1.5;">
      🚨 <strong>HTTPS REQUIS</strong> — Cette page contient des clés sensibles. Activez HTTPS sur votre serveur avant de saisir des clés Stripe.
    </div>
  <?php endif; ?>

  <?php if ($flash_msg): ?>
    <div style="padding:14px 18px;border-radius:10px;margin-bottom:18px;font-size:14px;<?= $flash_type === 'error' ? 'background:#FEE2E2;border:1px solid #FECACA;color:#991B1B;' : ($flash_type === 'warn' ? 'background:#FEF3C7;border:1px solid #FCD34D;color:#92400E;' : 'background:#D1FAE5;border:1px solid #A7F3D0;color:#065F46;') ?>">
      <?= htmlspecialchars($flash_msg, ENT_QUOTES, 'UTF-8') ?>
    </div>
  <?php endif; ?>

  <!-- État global -->
  <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(250px, 1fr));gap:16px;margin-bottom:24px;">
    <div style="background:white;border:1px solid #E2E8F0;border-radius:14px;padding:20px;">
      <div style="font-size:11px;color:#94A3B8;text-transform:uppercase;letter-spacing:0.05em;font-weight:700;">Stripe</div>
      <div style="font-size:22px;font-weight:700;margin-top:6px;color:<?= $stripe_enabled ? '#059669' : '#94A3B8' ?>;">
        <?= $stripe_enabled ? '✅ ACTIVÉ' : '⏸ DÉSACTIVÉ' ?>
      </div>
      <div style="font-size:12px;color:#94A3B8;margin-top:2px;">Mode : <strong><?= htmlspecialchars($stripe_mode) ?></strong></div>
    </div>
    <div style="background:white;border:1px solid #E2E8F0;border-radius:14px;padding:20px;">
      <div style="font-size:11px;color:#94A3B8;text-transform:uppercase;letter-spacing:0.05em;font-weight:700;">Connexion API</div>
      <div style="font-size:22px;font-weight:700;margin-top:6px;color:<?= $connection_status && $connection_status['ok'] ? '#059669' : '#94A3B8' ?>;">
        <?php if ($connection_status === null): ?>
          ⚪ NON TESTÉ
        <?php elseif ($connection_status['ok']): ?>
          ✅ OK
        <?php else: ?>
          ❌ ERREUR
        <?php endif; ?>
      </div>
      <div style="font-size:12px;color:#94A3B8;margin-top:2px;"><?= $connection_status ? htmlspecialchars($connection_status['message']) : 'Renseignez vos clés' ?></div>
    </div>
    <div style="background:white;border:1px solid #E2E8F0;border-radius:14px;padding:20px;">
      <div style="font-size:11px;color:#94A3B8;text-transform:uppercase;letter-spacing:0.05em;font-weight:700;">TVA</div>
      <div style="font-size:22px;font-weight:700;margin-top:6px;color:<?= $vat_enabled ? '#059669' : '#94A3B8' ?>;">
        <?= $vat_enabled ? '✅ ' . $vat_rate . '%' : '⏸ DÉSACTIVÉE' ?>
      </div>
      <div style="font-size:12px;color:#94A3B8;margin-top:2px;"><?= $vat_enabled ? 'Société assujettie' : 'Franchise (art. 293 B)' ?></div>
    </div>
  </div>

  <!-- BLOC 1 : Clés API -->
  <div style="background:white;border:1px solid #E2E8F0;border-radius:14px;padding:24px;margin-bottom:22px;">
    <h2 style="margin:0 0 6px;font-size:18px;">🔑 Clés API Stripe</h2>
    <p style="color:#64748B;font-size:13.5px;margin:0 0 18px;">Récupérez vos clés depuis <a href="https://dashboard.stripe.com/apikeys" target="_blank" rel="noopener" style="color:#059669;">dashboard.stripe.com/apikeys</a></p>

    <form method="post" action="" style="display:grid;gap:16px;">
      <input type="hidden" name="action" value="save_keys">

      <div>
        <label style="display:block;font-size:13px;font-weight:600;color:#475569;margin-bottom:6px;">Clé publique <span style="color:#94A3B8;font-weight:400;">(publishable_key)</span></label>
        <input type="text" name="stripe_publishable_key" value="<?= htmlspecialchars($pk ?? '') ?>" placeholder="pk_test_... ou pk_live_..." style="width:100%;padding:10px 14px;border:1px solid #E2E8F0;border-radius:8px;font-family:monospace;font-size:13px;">
      </div>

      <div>
        <label style="display:block;font-size:13px;font-weight:600;color:#475569;margin-bottom:6px;">Clé secrète <span style="color:#94A3B8;font-weight:400;">(secret_key)</span> 🔒</label>
        <input type="password" name="stripe_secret_key" value="<?= $sk ? '••••••••' : '' ?>" placeholder="sk_test_... ou sk_live_..." style="width:100%;padding:10px 14px;border:1px solid #E2E8F0;border-radius:8px;font-family:monospace;font-size:13px;">
        <div style="font-size:11.5px;color:#94A3B8;margin-top:4px;">⚠️ NE JAMAIS partager cette clé. Elle reste sur le serveur.</div>
      </div>

      <div>
        <label style="display:block;font-size:13px;font-weight:600;color:#475569;margin-bottom:6px;">Secret de webhook <span style="color:#94A3B8;font-weight:400;">(webhook_secret)</span> 🔒</label>
        <input type="password" name="stripe_webhook_secret" value="<?= $whs ? '••••••••' : '' ?>" placeholder="whsec_..." style="width:100%;padding:10px 14px;border:1px solid #E2E8F0;border-radius:8px;font-family:monospace;font-size:13px;">
        <div style="font-size:11.5px;color:#94A3B8;margin-top:4px;">URL webhook à configurer dans Stripe : <code style="background:#F1F5F9;padding:2px 6px;border-radius:4px;"><?= htmlspecialchars($webhook_url) ?></code></div>
      </div>

      <div style="display:flex;gap:10px;">
        <button type="submit" style="background:#059669;color:white;padding:11px 22px;border:none;border-radius:10px;font-weight:600;cursor:pointer;font-size:14px;">💾 Enregistrer les clés</button>
      </div>
    </form>
  </div>

  <!-- BLOC 2 : Test connexion + Activation -->
  <div style="background:white;border:1px solid #E2E8F0;border-radius:14px;padding:24px;margin-bottom:22px;">
    <h2 style="margin:0 0 6px;font-size:18px;">⚡ Test & Activation</h2>
    <p style="color:#64748B;font-size:13.5px;margin:0 0 18px;">Vérifiez la connexion puis activez Stripe pour permettre les paiements clients.</p>

    <div style="display:flex;gap:10px;flex-wrap:wrap;">
      <form method="post" action="" style="display:inline;">
        <input type="hidden" name="action" value="test_connection">
        <button type="submit" style="background:#F1F5F9;color:#0F172A;padding:11px 20px;border:1px solid #E2E8F0;border-radius:10px;font-weight:600;cursor:pointer;font-size:14px;">🔌 Tester la connexion</button>
      </form>

      <?php if (!$stripe_enabled): ?>
        <form method="post" action="" style="display:inline;" onsubmit="return confirm('Activer Stripe ? Les paiements seront possibles immédiatement.');">
          <input type="hidden" name="action" value="enable_stripe">
          <button type="submit" style="background:linear-gradient(180deg,#059669 0%,#047857 100%);color:white;padding:11px 22px;border:none;border-radius:10px;font-weight:600;cursor:pointer;font-size:14px;">🚀 Activer Stripe</button>
        </form>
      <?php else: ?>
        <form method="post" action="" style="display:inline;" onsubmit="return confirm('Désactiver Stripe ? Les boutons Régulariser basculeront sur la page contact.');">
          <input type="hidden" name="action" value="disable_stripe">
          <button type="submit" style="background:#FEE2E2;color:#991B1B;padding:11px 22px;border:1px solid #FECACA;border-radius:10px;font-weight:600;cursor:pointer;font-size:14px;">⏸ Désactiver Stripe</button>
        </form>
      <?php endif; ?>
    </div>
  </div>

  <!-- BLOC 3 : Mapping plans <-> Stripe price_ids -->
  <div style="background:white;border:1px solid #E2E8F0;border-radius:14px;padding:24px;margin-bottom:22px;">
    <h2 style="margin:0 0 6px;font-size:18px;">📋 Mapping plans Assokit → Stripe</h2>
    <p style="color:#64748B;font-size:13.5px;margin:0 0 18px;">Reliez vos plans Assokit aux <code>price_id</code> Stripe correspondants. Créez d'abord les produits dans <a href="https://dashboard.stripe.com/products" target="_blank" rel="noopener" style="color:#059669;">Stripe Dashboard</a>.</p>

    <table style="width:100%;border-collapse:collapse;font-size:14px;">
      <thead>
        <tr style="background:#F8FAFC;">
          <th style="text-align:left;padding:10px 14px;font-size:11px;text-transform:uppercase;color:#64748B;letter-spacing:0.04em;">Plan Assokit</th>
          <th style="text-align:left;padding:10px 14px;font-size:11px;text-transform:uppercase;color:#64748B;letter-spacing:0.04em;">Prix mensuel</th>
          <th style="text-align:left;padding:10px 14px;font-size:11px;text-transform:uppercase;color:#64748B;letter-spacing:0.04em;">Stripe price_id</th>
          <th style="padding:10px 14px;"></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($plans as $p): ?>
        <tr style="border-top:1px solid #F1F5F9;">
          <td style="padding:12px 14px;font-weight:600;"><?= htmlspecialchars($p['name']) ?></td>
          <td style="padding:12px 14px;color:#475569;"><?= ak_format_price_cents((int)$p['price_cents_monthly']) ?></td>
          <td style="padding:12px 14px;">
            <form method="post" action="" style="display:flex;gap:6px;">
              <input type="hidden" name="action" value="save_plan_price">
              <input type="hidden" name="plan_id" value="<?= (int)$p['id'] ?>">
              <input type="text" name="stripe_price_id" value="<?= htmlspecialchars($p['stripe_price_id_monthly'] ?? '') ?>" placeholder="price_..." style="flex:1;padding:7px 10px;border:1px solid #E2E8F0;border-radius:6px;font-family:monospace;font-size:12px;">
              <button type="submit" style="background:#059669;color:white;padding:7px 14px;border:none;border-radius:6px;font-size:12px;cursor:pointer;font-weight:600;">OK</button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

    <!-- Add-on domaine perso -->
    <div style="margin-top:20px;padding:16px;background:#EFF6FF;border:1px solid #BFDBFE;border-radius:10px;">
      <div style="font-weight:700;font-size:14px;color:#1E40AF;margin-bottom:6px;">🏆 Add-on : Domaine personnalisé (+10€/mois)</div>
      <form method="post" action="" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-top:10px;">
        <input type="hidden" name="action" value="save_addon_price">
        <input type="text" name="addon_domain_price_id" value="<?= htmlspecialchars($addon_price_id ?? '') ?>" placeholder="price_... (Stripe price_id pour +10€/mois)" style="flex:1;min-width:240px;padding:8px 12px;border:1px solid #BFDBFE;border-radius:8px;font-family:monospace;font-size:13px;background:white;">
        <button type="submit" style="background:#1E40AF;color:white;padding:8px 16px;border:none;border-radius:8px;font-size:13px;cursor:pointer;font-weight:600;">Enregistrer</button>
      </form>
    </div>
  </div>

  <!-- BLOC 4 : TVA -->
  <div style="background:white;border:1px solid #E2E8F0;border-radius:14px;padding:24px;margin-bottom:22px;">
    <h2 style="margin:0 0 6px;font-size:18px;">📊 TVA (Taxe sur la valeur ajoutée)</h2>
    <p style="color:#64748B;font-size:13.5px;margin:0 0 18px;">
      <?php if (!$vat_enabled): ?>
        Actuellement <strong>désactivée</strong> (franchise en base de TVA, art. 293 B du CGI). À activer quand RBPS sera assujettie à la TVA.
      <?php else: ?>
        Actuellement <strong>activée à <?= $vat_rate ?>%</strong>. La TVA est ajoutée automatiquement aux factures Stripe.
      <?php endif; ?>
    </p>

    <form method="post" action="" style="display:inline;">
      <input type="hidden" name="action" value="toggle_vat">
      <button type="submit" style="background:<?= $vat_enabled ? '#FEE2E2' : '#D1FAE5' ?>;color:<?= $vat_enabled ? '#991B1B' : '#065F46' ?>;padding:10px 18px;border:1px solid <?= $vat_enabled ? '#FECACA' : '#A7F3D0' ?>;border-radius:8px;font-weight:600;cursor:pointer;font-size:13px;">
        <?= $vat_enabled ? '⏸ Désactiver TVA' : '✅ Activer TVA (20%)' ?>
      </button>
    </form>
  </div>

  <!-- BLOC 5 : Documentation rapide -->
  <div style="background:linear-gradient(135deg, #FAF8F5 0%, #F0FDF4 100%);border:1px solid #A7F3D0;border-radius:14px;padding:24px;">
    <h2 style="margin:0 0 14px;font-size:17px;color:#047857;">📚 Guide d'activation rapide</h2>
    <ol style="margin:0;padding-left:24px;color:#0F172A;line-height:1.8;font-size:14px;">
      <li><strong>Créer un compte Stripe</strong> sur <a href="https://stripe.com" target="_blank" rel="noopener" style="color:#059669;">stripe.com</a> avec les infos RBPS</li>
      <li><strong>Créer 3 produits</strong> dans Stripe Dashboard :
        <ul style="margin-top:4px;">
          <li>Plan Assokit Mensuel — 49,99€ récurrent</li>
          <li>Plan Sur-mesure — sur devis (peut être laissé vide)</li>
          <li>Add-on Domaine personnalisé — 10€/mois récurrent</li>
        </ul>
      </li>
      <li><strong>Copier les clés API</strong> depuis <em>Developers > API keys</em> dans le bloc 🔑 ci-dessus</li>
      <li><strong>Configurer le webhook</strong> :
        <ul style="margin-top:4px;">
          <li>URL : <code style="background:white;padding:2px 6px;border-radius:4px;font-size:12.5px;"><?= htmlspecialchars($webhook_url) ?></code></li>
          <li>Événements à écouter : <code>checkout.session.completed</code>, <code>invoice.paid</code>, <code>invoice.payment_failed</code>, <code>customer.subscription.deleted</code></li>
        </ul>
      </li>
      <li><strong>Renseigner les <code>price_id</code></strong> de chaque plan dans le bloc 📋 ci-dessus</li>
      <li><strong>Tester la connexion</strong> avec le bouton 🔌</li>
      <li><strong>Activer Stripe</strong> 🚀 — les paiements deviennent possibles</li>
    </ol>
  </div>

</main>

<?php render_foot(); ?>
