<?php
/**
 * fondateur-stripe-config.php
 * --------------------------------------------------------------
 * Page d'administration de la configuration Stripe (FONDATEUR).
 *
 * Permet de :
 *   - Saisir les clés API Stripe (publique + secrète), en mode test puis en mode réel
 *   - « Configurer Stripe automatiquement » (stripe-setup-helpers.php) : produits et prix,
 *     option domaine, TVA, webhook (et son secret), portail client, domaine Apple Pay / Google Pay,
 *     avec une liste de contrôle ✓ / ✕ et ce qu'il faut faire quand une étape échoue
 *   - Activer / mettre en pause les paiements des associations
 *   - Activer/Désactiver la TVA (pour quand RBPS sera assujettie) ; le pied de facture de chaque client
 *     Stripe existant est remis à jour (mention 293 B retirée ou remise)
 *   - Mode test : choisir les associations de test qui peuvent payer (les autres voient « bientôt »)
 *   - Réglages avancés : prix saisis à la main (vérifiés chez Stripe), secret de webhook
 *   - Paiements reçus par les associations (Stripe Connect) : ouverture / pause, associations reliées
 *     et montants encaissés (l'argent ne transite jamais par RBPS : stripe-connect-helpers.php)
 *
 * Sécurité :
 *   - Réservé au Fondateur (is_founder=1 ou role='founder'), comme toute la section
 *     Pilotage Fondateur : un super-admin n'écrit pas les clés de paiement de la plateforme
 *   - Confirmation d'identité du cockpit (sa_auth_gate), jeton CSRF sur chaque action
 *   - Les clés secrètes ne sont jamais réaffichées (champ vide = clé gardée)
 *   - HTTPS obligatoire (avertissement au chargement)
 * Ancienne adresse /admin-stripe-config : redirigée ici (admin-stripe-config.php).
 * --------------------------------------------------------------
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes-layout.php';
require_once __DIR__ . '/sa-auth-helpers.php';
require_once __DIR__ . '/stripe-config-helpers.php';
require_once __DIR__ . '/stripe-helpers.php';
require_once __DIR__ . '/stripe-setup-helpers.php';
require_once __DIR__ . '/stripe-sync-helpers.php';
require_once __DIR__ . '/stripe-connect-helpers.php';
require_once __DIR__ . '/includes-pricing.php';

require_login();
sa_leave_impersonation_first();   // incarnation en cours : page « Revenir à mon compte », pas un 403
$user = ak_platform_flags(current_user());

// Restriction fondateur (ak_platform_flags relit is_founder en base et gère role='founder')
$has_access = !empty($user['is_founder']);
if (!$has_access) {
    http_response_code(403);
    die('Accès réservé aux fondateurs.');
}

// Confirmation d'identité du cockpit (/super-admin-login), prolongée à chaque page
sa_auth_gate();

// Vérification HTTPS (sécurité absolue pour les clés API)
$is_https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (int)($_SERVER['SERVER_PORT'] ?? 80) === 443;

$flash_msg = null;
$flash_type = 'success';
$db_hint = 'Enregistrement impossible dans la base : lancez d’abord les migrations (php migrations/run.php 2026-10-28-stripe-mise-en-service.sql, puis 2026-10-29-stripe-souscription-pendant-essai.sql, voir docs/stripe-mise-en-service.md).';

if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

/** Prix Stripe saisi à la main : vérifié chez Stripe (montant HT, euros, mensuel, bon mode). Message d'erreur ou null. */
function sc_check_manual_price(PDO $pdo, string $price_id, int $amount_cents): ?string {
    return ak_stripe_price_problem($pdo, $price_id, $amount_cents);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!check_csrf($_POST['csrf_token'] ?? '')) { http_response_code(403); die('Requête invalide (jeton CSRF manquant ou expiré).'); }
    $action = (string)($_POST['action'] ?? '');

    try {
        switch ($action) {
            case 'save_keys':
                // Champ laissé vide = clé déjà enregistrée gardée (les secrets ne sont jamais réaffichés)
                $pk = trim((string)($_POST['stripe_publishable_key'] ?? ''));
                $sk = trim((string)($_POST['stripe_secret_key'] ?? ''));
                $whs = trim((string)($_POST['stripe_webhook_secret'] ?? ''));
                if ($pk === '' && $sk === '' && $whs === '') throw new Exception('Aucune clé saisie.');
                if ($pk !== '' && !preg_match('/^pk_(test|live)_[A-Za-z0-9]+$/', $pk)) {
                    throw new Exception('Format de clé publique invalide (pk_test_… ou pk_live_…).');
                }
                if ($sk !== '' && !preg_match('/^(sk|rk)_(test|live)_[A-Za-z0-9]+$/', $sk)) {
                    throw new Exception('Format de clé secrète invalide (sk_test_… ou sk_live_… ; ou clé restreinte rk_…).');
                }
                if ($whs !== '' && !preg_match('/^whsec_[A-Za-z0-9+\/=]+$/', $whs)) {
                    throw new Exception('Format de secret de webhook invalide (whsec_…).');
                }
                $old_sk = (string)ak_stripe_get_secret_key($pdo);
                $new_pk_mode = ak_stripe_key_mode($pk !== '' ? $pk : ak_stripe_get_publishable_key($pdo));
                $new_sk_mode = ak_stripe_key_mode($sk !== '' ? $sk : $old_sk);
                if ($new_pk_mode !== '' && $new_sk_mode !== '' && $new_pk_mode !== $new_sk_mode) {
                    throw new Exception('La clé publique et la clé secrète doivent être du même mode (toutes deux « test » ou toutes deux « live »).');
                }
                $saved = true;
                if ($pk !== '') $saved = ak_stripe_config_set($pdo, 'stripe_publishable_key', $pk) && $saved;
                if ($sk !== '') $saved = ak_stripe_config_set($pdo, 'stripe_secret_key', $sk) && $saved;
                if ($whs !== '') $saved = ak_stripe_config_set($pdo, 'stripe_webhook_secret', $whs) && $saved;
                if ($new_sk_mode !== '') $saved = ak_stripe_config_set($pdo, 'stripe_mode', $new_sk_mode) && $saved;
                if (!$saved) throw new Exception($db_hint);
                // Changement de mode (test → réel) : les prix, le webhook et le portail du mode précédent ne
                // valent rien avec les nouvelles clés. Paiements en pause jusqu'à la configuration automatique.
                if ($sk !== '' && ak_stripe_key_mode($old_sk) !== '' && ak_stripe_key_mode($old_sk) !== $new_sk_mode) {
                    ak_stripe_config_set($pdo, 'stripe_enabled', '0');
                    $flash_msg = 'Clés du mode ' . ($new_sk_mode === 'live' ? 'réel' : 'test') . ' enregistrées. Les paiements sont en pause : cliquez sur « Configurer Stripe automatiquement », puis sur « Activer les paiements ».';
                    $flash_type = 'warn';
                } else {
                    $flash_msg = 'Clés enregistrées (mode ' . ($new_sk_mode === 'live' ? 'réel' : 'test') . '). Étape suivante : « Configurer Stripe automatiquement ».';
                }
                break;

            case 'auto_setup':
                $report = ak_stripe_auto_setup($pdo, ak_stripe_site_base());
                ak_stripe_config_set($pdo, 'stripe_setup_report', json_encode($report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                $_SESSION['stripe_setup_report'] = $report;   // affiché même si la base n'a pas pu le garder
                if ($report['ok']) {
                    $flash_msg = 'Stripe est configuré en mode ' . ($report['mode'] === 'live' ? 'réel' : 'test') . '.'
                        . ((int)ak_stripe_config_get($pdo, 'stripe_enabled', 0) === 1 ? ' Les paiements sont actifs.' : ' Dernière étape : « Activer les paiements ».');
                } else {
                    $flash_msg = 'La configuration n’est pas terminée : voyez les étapes marquées ✕ ci-dessous.';
                    $flash_type = 'error';
                }
                break;

            case 'test_connection':
                $result = ak_stripe_test_connection($pdo);
                $flash_msg = ($result['ok'] ? '✅ ' : '❌ ') . $result['message'];
                if (!$result['ok']) $flash_type = 'error';
                break;

            case 'enable_stripe':
                // Vérifier que tout est prêt avant d'activer
                if (empty(ak_stripe_get_secret_key($pdo)) || empty(ak_stripe_get_publishable_key($pdo))) {
                    throw new Exception('Impossible d’activer les paiements : clés API manquantes.');
                }
                if (ak_stripe_schema_check($pdo)) throw new Exception($db_hint);
                if ((string)ak_stripe_config_get($pdo, 'stripe_setup_mode', '') !== ak_stripe_get_mode($pdo)) {
                    throw new Exception('Cliquez d’abord sur « Configurer Stripe automatiquement » avec ces clés (mode ' . (ak_stripe_get_mode($pdo) === 'live' ? 'réel' : 'test') . ') : toutes les étapes doivent être ✓.');
                }
                if (!ak_stripe_config_set($pdo, 'stripe_enabled', '1')) throw new Exception($db_hint);
                $flash_msg = '✅ Paiements activés (mode ' . (ak_stripe_get_mode($pdo) === 'live' ? 'réel : de vrais paiements' : 'test : aucun vrai prélèvement') . '). Les associations peuvent s’abonner depuis « Mon abonnement ».';
                break;

            case 'disable_stripe':
                if (!ak_stripe_config_set($pdo, 'stripe_enabled', '0')) throw new Exception($db_hint);
                $flash_msg = '⏸ Paiements en pause : plus aucun nouvel abonnement par carte. Les abonnements déjà en place continuent d’être prélevés par Stripe ; « Régulariser » mène à la page contact.';
                $flash_type = 'warn';
                break;

            case 'toggle_vat':
                $current = (int)ak_stripe_config_get($pdo, 'vat_enabled', 0);
                $new_val = $current === 1 ? '0' : '1';
                if (!ak_stripe_config_set($pdo, 'vat_enabled', $new_val)) throw new Exception($db_hint);
                $rate_lbl = rtrim(rtrim(number_format(ak_vat_get_rate($pdo), 2, ',', ''), '0'), ',') . ' %';
                // Pied de facture des clients Stripe existants : la mention « TVA non applicable, art. 293 B »
                // est retirée (ou remise) sur chaque client, sinon elle resterait sur toutes leurs factures
                $foot = ak_stripe_get_secret_key($pdo) ? ak_stripe_refresh_customer_footers($pdo) : ['ok' => 0, 'failed' => 0];
                $foot_msg = ($foot['ok'] ? ' Pied de facture mis à jour pour ' . $foot['ok'] . ' client(s) Stripe.' : '')
                    . ($foot['failed'] ? ' ' . $foot['failed'] . ' client(s) Stripe n’ont pas pu être mis à jour : relancez « Configurer Stripe automatiquement ».' : '');
                $flash_msg = $new_val === '1'
                    ? 'TVA activée (' . $rate_lbl . ') : les nouveaux abonnements par carte sont facturés HT + TVA.' . $foot_msg
                      . ' La TVA est due sur TOUS les abonnements à partir de la date d’assujettissement : appliquez-la aussi aux abonnements déjà en place (voir le guide, « Le jour où RBPS devient assujettie »).'
                    : 'TVA désactivée (franchise en base, art. 293 B du CGI) pour les nouveaux abonnements.' . $foot_msg;
                // Abonnements souscrits pendant l'essai, pas encore prélevés (aucune ligne d'abonnement) : ils gardent
                // le taux de leur souscription, et leurs pages affichent le montant que Stripe prélèvera vraiment
                try {
                    $n_trial = isset(ak_db_columns($pdo, 'organizations')['stripe_trial_sub_id'])
                        ? (int)$pdo->query("SELECT COUNT(*) FROM organizations WHERE COALESCE(stripe_trial_sub_id, '') <> ''")->fetchColumn() : 0;
                } catch (Throwable $e) { $n_trial = 0; }
                if ($n_trial > 0) {
                    $flash_msg .= ' ' . $n_trial . ' abonnement(s) souscrit(s) pendant l’essai et pas encore prélevé(s) gardent le taux de TVA de leur souscription'
                        . ($new_val === '1' ? ' : ajoutez-y aussi la TVA dans Stripe (Abonnements › l’abonnement › Modifier › Taxes) si elle est due dès leur premier prélèvement.' : '.');
                }
                if ($foot['failed']) $flash_type = 'warn';
                break;

            case 'save_test_orgs':
                // Associations qui peuvent payer en mode test (les autres voient « Le paiement en ligne ouvre très bientôt »)
                $raw = (string)($_POST['stripe_test_org_ids'] ?? '');
                $ids = array_values(array_unique(array_filter(array_map('intval', preg_split('/[^0-9]+/', $raw)), fn($i) => $i > 0)));
                if ($ids) {
                    $in = implode(',', array_fill(0, count($ids), '?'));
                    $q = $pdo->prepare("SELECT id FROM organizations WHERE id IN ($in)");
                    $q->execute($ids);
                    $found = array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN));
                    $missing = array_diff($ids, $found);
                    if ($missing) throw new Exception('Association(s) introuvable(s) : n° ' . implode(', ', $missing) . '.');
                }
                if (!ak_stripe_config_set($pdo, 'stripe_test_org_ids', $ids ? implode(',', $ids) : null)) throw new Exception($db_hint);
                $flash_msg = $ids ? 'Associations autorisées à payer en mode test : n° ' . implode(', ', $ids) . '.'
                                  : 'Associations autorisées à payer en mode test : celles du Fondateur.';
                break;

            case 'save_test_clock':
                // Horloge de test Stripe (mode test) : les NOUVEAUX clients Stripe de test y sont rattachés, pour
                // avancer le temps chez Stripe (fin d'essai, premier prélèvement) sans attendre
                if (ak_stripe_get_mode($pdo) !== 'test') throw new Exception('Les horloges de test ne servent qu’avec les clés du mode test.');
                $clock = trim((string)($_POST['stripe_test_clock_id'] ?? ''));
                if ($clock !== '' && !preg_match('/^clock_[A-Za-z0-9]+$/', $clock)) throw new Exception('Identifiant d’horloge invalide : il commence par clock_ (Stripe › Facturation › Horloges de test).');
                if (!ak_stripe_config_set($pdo, 'stripe_test_clock_id', $clock !== '' ? $clock : null)) throw new Exception($db_hint);
                $flash_msg = $clock !== '' ? 'Horloge de test ' . $clock . ' enregistrée : les nouveaux clients Stripe de test y seront rattachés (créez une nouvelle association de test).'
                                           : 'Horloge de test retirée : les nouveaux clients Stripe de test suivent l’heure réelle.';
                break;

            case 'save_addon_price':
                $addon_price_id = trim((string)($_POST['addon_domain_price_id'] ?? ''));
                if ($addon_price_id !== '' && !preg_match('/^price_[A-Za-z0-9]+$/', $addon_price_id)) {
                    throw new Exception('Format price_id invalide (doit commencer par price_).');
                }
                if ($addon_price_id !== '' && ($why = sc_check_manual_price($pdo, $addon_price_id, AK_ADDON_DOMAIN_HT_CENTS))) throw new Exception($why);
                if (!ak_stripe_config_set($pdo, 'addon_domain_price_id', $addon_price_id !== '' ? $addon_price_id : null)) throw new Exception($db_hint);
                $flash_msg = 'Prix de l’option domaine enregistré.';
                break;

            case 'save_plan_price':
                $plan_id = (int)($_POST['plan_id'] ?? 0);
                $price_id = trim((string)($_POST['stripe_price_id'] ?? ''));
                $plan = null;
                foreach (ak_stripe_paid_plans($pdo) as $p) if ((int)$p['id'] === $plan_id) $plan = $p;
                if (!$plan) throw new Exception('Seules les formules payantes ont un prix Stripe.');
                if ($price_id !== '' && !preg_match('/^price_[A-Za-z0-9]+$/', $price_id)) throw new Exception('Format price_id invalide.');
                if ($price_id !== '' && ($why = sc_check_manual_price($pdo, $price_id, (int)$plan['price_cents']))) throw new Exception($why);
                $pdo->prepare("UPDATE asso_plans SET stripe_price_id_monthly = :p WHERE id = :id")->execute([':p' => $price_id !== '' ? $price_id : null, ':id' => $plan_id]);
                $flash_msg = 'Prix Stripe de la formule enregistré.';
                break;

            case 'connect_enable':
                // Paiements reçus par les associations : seulement quand Connect et son webhook sont en place
                if (ak_connect_schema_missing($pdo)) throw new Exception('Lancez d’abord la migration 2026-10-30-paiements-en-ligne-associations.sql.');
                if (trim((string)ak_stripe_config_get($pdo, 'connect_webhook_secret_' . ak_stripe_get_mode($pdo), '')) === '') {
                    throw new Exception('Le webhook des comptes connectés n’est pas encore créé : activez Connect dans Stripe, puis cliquez sur « Configurer Stripe automatiquement ».');
                }
                ak_stripe_config_set($pdo, 'connect_enabled', '1');
                $flash_msg = 'Paiements en ligne ouverts : chaque association peut relier son compte Stripe depuis « Paiements en ligne »'
                    . (ak_stripe_get_mode($pdo) === 'test' ? ' (mode test : seulement les associations de test).' : '.');
                break;

            case 'connect_disable':
                ak_stripe_config_set($pdo, 'connect_enabled', '0');
                $flash_msg = 'Paiements en ligne des associations en pause : plus aucun bouton « Payer en ligne ». Les paiements déjà faits restent enregistrés, et les notifications de Stripe continuent d’être traitées.';
                break;

            case 'purge_test':
                if (ak_stripe_get_mode($pdo) !== 'live') throw new Exception('Disponible seulement avec les clés du mode réel.');
                $n = ak_stripe_purge_test_data($pdo);
                $flash_msg = $n['subs'] . ' abonnement(s) et ' . $n['invoices'] . ' facture(s) de test retirés.';
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
$schema_missing = ak_stripe_schema_check($pdo);
$stripe_enabled = (int)ak_stripe_config_get($pdo, 'stripe_enabled', 0);
$stripe_mode = ak_stripe_get_mode($pdo);
$mode_fr = $stripe_mode === 'live' ? 'réel' : 'test';
$pk = ak_stripe_get_publishable_key($pdo);
$sk = ak_stripe_get_secret_key($pdo);
$whs = ak_stripe_get_webhook_secret($pdo);
$vat_enabled = ak_vat_is_enabled($pdo);
$vat_rate = ak_vat_get_rate($pdo);
$vat_lbl = rtrim(rtrim(number_format($vat_rate, 2, ',', ''), '0'), ',') . ' %';
$addon_price_id = ak_stripe_config_get($pdo, 'addon_domain_price_id', null);
$setup_mode = (string)ak_stripe_config_get($pdo, 'stripe_setup_mode', '');
// Mode test : associations qui peuvent payer (les autres voient « Le paiement en ligne ouvre très bientôt »)
$test_org_ids_raw = (string)ak_stripe_config_get($pdo, 'stripe_test_org_ids', '');
$test_clock_id = (string)ak_stripe_config_get($pdo, 'stripe_test_clock_id', '');
$test_orgs = [];
try {
    $tids = ak_stripe_test_org_ids($pdo);
    if ($tids) $test_orgs = $pdo->query("SELECT id, name FROM organizations WHERE id IN (" . implode(',', array_map('intval', $tids)) . ") ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}
$setup_done = $setup_mode !== '' && $setup_mode === $stripe_mode;

// Dernier bilan de la configuration automatique
$report = $_SESSION['stripe_setup_report'] ?? null;
unset($_SESSION['stripe_setup_report']);
if (!$report) {
    $raw = (string)ak_stripe_config_get($pdo, 'stripe_setup_report', '');
    $report = $raw !== '' ? json_decode($raw, true) : null;
}

// Test connexion uniquement si clés présentes
$connection_status = !empty($sk) ? ak_stripe_test_connection($pdo) : null;

// Formules vendues par carte, avec leur prix Stripe
$plans = [];
try { $plans = ak_stripe_paid_plans($pdo); } catch (Throwable $e) { /* colonne absente : migration */ }

// Abonnements / factures de test restés en base (mode réel)
$test_left = 0;
if ($stripe_mode === 'live' && isset(ak_db_columns($pdo, 'asso_subscriptions')['stripe_livemode'])) {
    try {
        $test_left = (int)$pdo->query("SELECT COUNT(*) FROM asso_subscriptions WHERE stripe_livemode = 0 AND payment_mode = 'stripe' AND status IN ('active', 'overdue', 'pending_payment')")->fetchColumn()
                   + (int)$pdo->query("SELECT COUNT(*) FROM asso_invoices_stripe WHERE stripe_livemode = 0")->fetchColumn();
    } catch (Throwable $e) {}
}

// Activité récente : derniers paiements, notifications Stripe reçues ou en échec (visible ici, pas
// seulement dans le tableau de bord Stripe)
$recent_inv = []; $ev_last = null; $ev_failed = []; $ev_failed_n = 0;
try {
    $recent_inv = $pdo->query("SELECT i.created_at, i.invoice_number, i.amount_cents, i.status, i.billing_reason, o.name AS org_name, i.org_id
                               FROM asso_invoices_stripe i LEFT JOIN organizations o ON o.id = i.org_id
                               WHERE COALESCE(i.status, '') <> 'draft' ORDER BY i.id DESC LIMIT 8")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}
try {
    $ev_last = $pdo->query("SELECT MAX(created_at) FROM asso_stripe_events")->fetchColumn() ?: null;
    $ev_failed_n = (int)$pdo->query("SELECT COUNT(*) FROM asso_stripe_events WHERE processing_status = 'failed' AND created_at > NOW() - INTERVAL 7 DAY")->fetchColumn();
    $ev_failed = $pdo->query("SELECT event_type, error_message, created_at FROM asso_stripe_events WHERE processing_status = 'failed' ORDER BY id DESC LIMIT 3")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

// Webhook URL absolue (celle que crée la configuration automatique)
$webhook_url = ak_stripe_site_base() . '/stripe-webhook';
$csrf = h($_SESSION['csrf_token']);
$card = 'background:white;border:1px solid #E2E8F0;border-radius:14px;padding:24px;margin-bottom:22px;';
$input = 'width:100%;padding:10px 14px;border:1px solid #E2E8F0;border-radius:8px;font-family:monospace;font-size:13px;box-sizing:border-box;';
$state_icon = ['ok' => ['✓', '#059669', '#ECFDF5'], 'ko' => ['✕', '#DC2626', '#FEF2F2'], 'warn' => ['⚠', '#B45309', '#FFFBEB'], 'info' => ['ℹ', '#2563EB', '#EFF6FF']];

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
      <p style="color:#64748B;margin:0;">Paiement des abonnements des associations par carte, Apple Pay, Google Pay et prélèvement SEPA</p>
    </div>
  </div>

  <?php if (!$is_https): ?>
    <div style="background:#FEE2E2;border:2px solid #DC2626;border-radius:12px;padding:18px 22px;margin-bottom:22px;color:#991B1B;font-size:14px;line-height:1.5;">
      🚨 <strong>HTTPS REQUIS</strong> — Cette page contient des clés sensibles. Ouvrez-la en https://assokit.fr avant de saisir des clés Stripe.
    </div>
  <?php endif; ?>

  <?php if ($schema_missing): ?>
    <div style="background:#FEF2F2;border:1px solid #FECACA;border-radius:12px;padding:16px 20px;margin-bottom:22px;color:#991B1B;font-size:14px;line-height:1.6;">
      ✕ <strong>Base de données à mettre à jour</strong> avant toute chose : il manque <?= h(implode(', ', array_slice($schema_missing, 0, 6))) ?><?= count($schema_missing) > 6 ? '…' : '' ?>.<br>
      Sur le serveur : <code style="background:white;padding:2px 6px;border-radius:4px;">cd ~/public_html &amp;&amp; /usr/local/bin/php migrations/run.php 2026-10-28-stripe-mise-en-service.sql &amp;&amp; /usr/local/bin/php migrations/run.php 2026-10-29-stripe-souscription-pendant-essai.sql</code>
    </div>
  <?php endif; ?>

  <?php if ($flash_msg): ?>
    <div style="padding:14px 18px;border-radius:10px;margin-bottom:18px;font-size:14px;line-height:1.5;<?= $flash_type === 'error' ? 'background:#FEE2E2;border:1px solid #FECACA;color:#991B1B;' : ($flash_type === 'warn' ? 'background:#FEF3C7;border:1px solid #FCD34D;color:#92400E;' : 'background:#D1FAE5;border:1px solid #A7F3D0;color:#065F46;') ?>">
      <?= h($flash_msg) ?>
    </div>
  <?php endif; ?>

  <!-- État global -->
  <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(200px, 1fr));gap:16px;margin-bottom:24px;">
    <div style="background:white;border:1px solid #E2E8F0;border-radius:14px;padding:20px;">
      <div style="font-size:11px;color:#64748B;text-transform:uppercase;letter-spacing:0.05em;font-weight:700;">Paiements</div>
      <div style="font-size:20px;font-weight:700;margin-top:6px;color:<?= $stripe_enabled ? '#059669' : '#94A3B8' ?>;"><?= $stripe_enabled ? '✅ Activés' : '⏸ En pause' ?></div>
      <div style="font-size:12px;color:#64748B;margin-top:2px;">Mode : <strong><?= $stripe_mode === 'live' ? 'réel (vrais paiements)' : 'test (aucun vrai prélèvement)' ?></strong></div>
    </div>
    <div style="background:white;border:1px solid #E2E8F0;border-radius:14px;padding:20px;">
      <div style="font-size:11px;color:#64748B;text-transform:uppercase;letter-spacing:0.05em;font-weight:700;">Configuration</div>
      <div style="font-size:20px;font-weight:700;margin-top:6px;color:<?= $setup_done ? '#059669' : '#94A3B8' ?>;"><?= $setup_done ? '✅ Faite' : '⚪ À faire' ?></div>
      <div style="font-size:12px;color:#64748B;margin-top:2px;"><?= $setup_done ? 'Mode ' . $mode_fr . ', le ' . h(date('d/m/Y à H:i', strtotime((string)ak_stripe_config_get($pdo, 'stripe_setup_at', 'now')))) : 'Bouton « Configurer Stripe automatiquement »' ?></div>
    </div>
    <div style="background:white;border:1px solid #E2E8F0;border-radius:14px;padding:20px;">
      <div style="font-size:11px;color:#64748B;text-transform:uppercase;letter-spacing:0.05em;font-weight:700;">Connexion API</div>
      <div style="font-size:20px;font-weight:700;margin-top:6px;color:<?= $connection_status && $connection_status['ok'] ? '#059669' : '#94A3B8' ?>;">
        <?= $connection_status === null ? '⚪ Non testée' : ($connection_status['ok'] ? '✅ OK' : '❌ Erreur') ?>
      </div>
      <div style="font-size:12px;color:#64748B;margin-top:2px;"><?= $connection_status ? h($connection_status['message']) : 'Renseignez vos clés' ?></div>
    </div>
    <div style="background:white;border:1px solid #E2E8F0;border-radius:14px;padding:20px;">
      <div style="font-size:11px;color:#64748B;text-transform:uppercase;letter-spacing:0.05em;font-weight:700;">TVA</div>
      <div style="font-size:20px;font-weight:700;margin-top:6px;color:<?= $vat_enabled ? '#059669' : '#94A3B8' ?>;"><?= $vat_enabled ? '✅ ' . h($vat_lbl) : '⏸ Désactivée' ?></div>
      <div style="font-size:12px;color:#64748B;margin-top:2px;"><?= $vat_enabled ? 'Société assujettie' : 'Franchise (art. 293 B du CGI)' ?></div>
    </div>
  </div>

  <!-- ÉTAPE 1 : Clés API -->
  <div style="<?= $card ?>">
    <h2 style="margin:0 0 6px;font-size:18px;">1. 🔑 Clés API Stripe</h2>
    <p style="color:#64748B;font-size:13.5px;margin:0 0 18px;line-height:1.55;">
      Dans le tableau de bord Stripe : <strong>Développeurs › Clés API</strong> (<a href="https://dashboard.stripe.com/test/apikeys" target="_blank" rel="noopener" style="color:#059669;">mode test</a>,
      puis <a href="https://dashboard.stripe.com/apikeys" target="_blank" rel="noopener" style="color:#059669;">mode réel</a>). Commencez par les clés de <strong>test</strong>.
      Un champ laissé vide garde la clé déjà enregistrée.
    </p>
    <form method="post" action="" style="display:grid;gap:16px;">
      <input type="hidden" name="action" value="save_keys">
      <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
      <div>
        <label style="display:block;font-size:13px;font-weight:600;color:#475569;margin-bottom:6px;">Clé publiable <span style="color:#64748B;font-weight:400;">(pk_test_… ou pk_live_…)</span></label>
        <input type="text" name="stripe_publishable_key" value="" placeholder="<?= $pk ? h(ak_stripe_mask($pk)) . ' — enregistrée' : 'pk_test_…' ?>" autocomplete="off" style="<?= $input ?>">
      </div>
      <div>
        <label style="display:block;font-size:13px;font-weight:600;color:#475569;margin-bottom:6px;">Clé secrète <span style="color:#64748B;font-weight:400;">(sk_test_… ou sk_live_…)</span> 🔒</label>
        <input type="password" name="stripe_secret_key" value="" placeholder="<?= $sk ? h(ak_stripe_mask($sk)) . ' — enregistrée (laisser vide pour la garder)' : 'sk_test_…' ?>" autocomplete="new-password" style="<?= $input ?>">
        <div style="font-size:11.5px;color:#64748B;margin-top:4px;">⚠️ Ne partagez jamais cette clé. Elle reste sur le serveur et n’est jamais réaffichée.</div>
      </div>
      <div style="display:flex;gap:10px;">
        <button type="submit" style="background:#059669;color:white;padding:11px 22px;border:none;border-radius:10px;font-weight:600;cursor:pointer;font-size:14px;">💾 Enregistrer les clés</button>
      </div>
    </form>
  </div>

  <!-- ÉTAPE 2 : Configuration automatique -->
  <div style="<?= $card ?>border:2px solid #A7F3D0;">
    <h2 style="margin:0 0 6px;font-size:18px;">2. ⚙️ Configurer Stripe automatiquement</h2>
    <p style="color:#475569;font-size:13.5px;margin:0 0 14px;line-height:1.6;">
      Crée ou retrouve chez Stripe, en mode <strong><?= $mode_fr ?></strong> : le produit et le prix mensuel HT de la formule Assokit, l’option Domaine personnalisé,
      la TVA si elle est activée, le webhook <code style="background:#F1F5F9;padding:2px 6px;border-radius:4px;"><?= h($webhook_url) ?></code> et son secret,
      le portail client (changer de carte, voir ses factures, résilier en fin de période), le domaine pour Apple Pay / Google Pay
      et, quand Stripe Connect est activé sur votre compte, le webhook des paiements reçus par les associations (<code style="background:#F1F5F9;padding:2px 6px;border-radius:4px;"><?= h(ak_stripe_site_base() . '/stripe-connect-webhook') ?></code>).
      Vous pouvez relancer autant de fois que nécessaire : rien n’est créé en double.
    </p>
    <p style="color:#64748B;font-size:12.5px;margin:0 0 16px;line-height:1.55;">
      💡 <strong>Prix modifié dans Pilotage › Plans tarifaires ?</strong> Relancez la configuration : un nouveau prix Stripe est créé pour les <em>nouveaux</em> abonnements
      et l’ancien est archivé. Les associations déjà abonnées gardent leur montant actuel (Stripe ne change jamais le prix d’un abonnement en cours).
    </p>
    <form method="post" action="" onsubmit="this.querySelector('button').disabled=true;this.querySelector('button').textContent='Configuration en cours…';">
      <input type="hidden" name="action" value="auto_setup">
      <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
      <button type="submit" <?= (!$sk || $schema_missing) ? 'disabled title="Enregistrez d’abord vos clés (et lancez la migration)"' : '' ?> style="background:linear-gradient(180deg,#059669 0%,#047857 100%);color:white;padding:13px 26px;border:none;border-radius:10px;font-weight:700;cursor:pointer;font-size:15px;<?= (!$sk || $schema_missing) ? 'opacity:.5;cursor:not-allowed;' : '' ?>">⚙️ Configurer Stripe automatiquement</button>
    </form>

    <?php if ($report && !empty($report['steps'])): ?>
      <div style="margin-top:20px;border-top:1px solid #F1F5F9;padding-top:16px;">
        <div style="font-size:13px;color:#64748B;margin-bottom:10px;">
          Dernier bilan : mode <?= ($report['mode'] ?? '') === 'live' ? 'réel' : 'test' ?>, le <?= h(date('d/m/Y à H:i', strtotime((string)($report['at'] ?? 'now')))) ?>
          <?php if (($report['mode'] ?? '') !== $stripe_mode): ?><strong style="color:#B45309;"> — fait avec les clés de l’autre mode : relancez la configuration.</strong><?php endif; ?>
        </div>
        <ul style="list-style:none;margin:0;padding:0;display:grid;gap:8px;">
          <?php foreach ($report['steps'] as $s): [$ic, $col, $bg] = $state_icon[$s['state']] ?? $state_icon['info']; ?>
            <li style="display:flex;gap:12px;align-items:flex-start;background:<?= $bg ?>;border-radius:10px;padding:10px 14px;">
              <span style="font-weight:800;color:<?= $col ?>;font-size:16px;line-height:1.3;min-width:16px;text-align:center;"><?= $ic ?></span>
              <div style="font-size:13.5px;line-height:1.5;color:#0F172A;min-width:0;overflow-wrap:anywhere;">
                <strong><?= h($s['label']) ?></strong> — <?= h($s['detail']) ?>
                <?php if (($s['fix'] ?? '') !== '' && $s['state'] !== 'ok'): ?><div style="color:#475569;font-size:12.5px;margin-top:3px;">👉 <?= h($s['fix']) ?></div><?php endif; ?>
              </div>
            </li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>
  </div>

  <!-- ÉTAPE 3 : Activation -->
  <div style="<?= $card ?>">
    <h2 style="margin:0 0 6px;font-size:18px;">3. 🚀 Activer les paiements</h2>
    <p style="color:#64748B;font-size:13.5px;margin:0 0 18px;line-height:1.55;">
      Une fois toutes les étapes ✓, les associations peuvent payer depuis « Mon abonnement ».
      <?= $stripe_mode === 'test' ? 'En mode test, seules les associations de test ci-dessous voient le paiement (avec un bandeau « MODE TEST »), et payent avec les cartes de test de Stripe (voir le guide) : aucun vrai prélèvement. Les autres associations voient « Le paiement en ligne ouvre très bientôt ».' : '<strong>Mode réel : les paiements sont de vrais prélèvements, ouverts à toutes les associations.</strong>' ?>
    </p>
    <?php if ($stripe_mode === 'test'): ?>
    <form method="post" action="" style="display:grid;gap:8px;margin:0 0 18px;padding:14px;background:#F8FAFC;border:1px solid #E2E8F0;border-radius:10px;">
      <input type="hidden" name="action" value="save_test_orgs">
      <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
      <label style="font-size:13px;font-weight:600;color:#475569;">Associations de test (numéros, séparés par des virgules) <span style="font-weight:400;color:#64748B;">— vide : les associations du Fondateur</span></label>
      <div style="display:flex;gap:8px;flex-wrap:wrap;">
        <input type="text" name="stripe_test_org_ids" value="<?= h($test_org_ids_raw) ?>" placeholder="ex. 12, 34" style="<?= $input ?>flex:1;min-width:160px;width:auto;">
        <button type="submit" style="background:#475569;color:white;padding:8px 16px;border:none;border-radius:8px;font-size:13px;cursor:pointer;font-weight:600;">Enregistrer</button>
      </div>
      <div style="font-size:12.5px;color:#64748B;">Autorisées en ce moment : <?= $test_orgs ? h(implode(' · ', array_map(fn($o) => 'n° ' . (int)$o['id'] . ' ' . $o['name'], $test_orgs))) : 'aucune' ?>. Le numéro d’une association figure dans le cockpit (Associations › la fiche).</div>
    </form>
    <form method="post" action="" style="display:grid;gap:8px;margin:0 0 18px;padding:14px;background:#F8FAFC;border:1px solid #E2E8F0;border-radius:10px;">
      <input type="hidden" name="action" value="save_test_clock">
      <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
      <label style="font-size:13px;font-weight:600;color:#475569;">Horloge de test Stripe <span style="font-weight:400;color:#64748B;">— facultatif, pour essayer la fin d’essai sans attendre</span></label>
      <div style="display:flex;gap:8px;flex-wrap:wrap;">
        <input type="text" name="stripe_test_clock_id" value="<?= h($test_clock_id) ?>" placeholder="clock_…" style="<?= $input ?>flex:1;min-width:160px;width:auto;">
        <button type="submit" style="background:#475569;color:white;padding:8px 16px;border:none;border-radius:8px;font-size:13px;cursor:pointer;font-weight:600;">Enregistrer</button>
      </div>
      <div style="font-size:12.5px;color:#64748B;">Créée dans Stripe › Facturation › Horloges de test. Les <strong>nouveaux</strong> clients Stripe de test y sont rattachés (3 au plus par horloge) : avancez l’horloge dans Stripe pour simuler la fin de l’essai et le premier prélèvement (voir le guide). Vide : heure réelle.</div>
    </form>
    <?php endif; ?>
    <div style="display:flex;gap:10px;flex-wrap:wrap;">
      <form method="post" action="" style="display:inline;">
        <input type="hidden" name="action" value="test_connection">
        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
        <button type="submit" style="background:#F1F5F9;color:#0F172A;padding:11px 20px;border:1px solid #E2E8F0;border-radius:10px;font-weight:600;cursor:pointer;font-size:14px;">🔌 Tester la connexion</button>
      </form>
      <?php if (!$stripe_enabled): ?>
        <form method="post" action="" style="display:inline;" onsubmit="return confirm(<?= h(json_encode($stripe_mode === 'live' ? 'Activer les paiements RÉELS ? Les associations pourront payer immédiatement.' : 'Activer les paiements de test ?', JSON_UNESCAPED_UNICODE)) ?>);">
          <input type="hidden" name="action" value="enable_stripe">
          <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
          <button type="submit" style="background:linear-gradient(180deg,#059669 0%,#047857 100%);color:white;padding:11px 22px;border:none;border-radius:10px;font-weight:600;cursor:pointer;font-size:14px;">🚀 Activer les paiements</button>
        </form>
      <?php else: ?>
        <form method="post" action="" style="display:inline;" onsubmit="return confirm('Mettre les paiements en pause ? Plus aucun nouvel abonnement par carte ; les abonnements en cours continuent d’être prélevés.');">
          <input type="hidden" name="action" value="disable_stripe">
          <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
          <button type="submit" style="background:#FEE2E2;color:#991B1B;padding:11px 22px;border:1px solid #FECACA;border-radius:10px;font-weight:600;cursor:pointer;font-size:14px;">⏸ Mettre en pause</button>
        </form>
      <?php endif; ?>
    </div>
  </div>

  <!-- ÉTAPE 4 : paiements reçus par les associations (Stripe Connect) -->
  <?php $cx_on = (int)ak_stripe_config_get($pdo, 'connect_enabled', 0) === 1; $cx_wh = trim((string)ak_stripe_config_get($pdo, 'connect_webhook_secret_' . $stripe_mode, '')) !== ''; $cx = ak_connect_platform_stats($pdo); $cx_miss = ak_connect_schema_missing($pdo); ?>
  <div style="<?= $card ?>">
    <h2 style="margin:0 0 6px;font-size:18px;">4. 🤝 Paiements reçus par les associations (Stripe Connect)</h2>
    <p style="color:#64748B;font-size:13.5px;margin:0 0 14px;line-height:1.55;">
      Chaque association relie <strong>son propre compte Stripe</strong> (page « Paiements en ligne ») et fait payer en ligne ses factures, ses cotisations et ses dons.
      L’argent va directement sur le compte de l’association : il ne transite jamais par RBPS, les frais Stripe sont à sa charge et Assokit ne prend aucune commission.
      <?= $stripe_mode === 'test' ? 'En mode test, seules les associations de test (bloc 3) voient le paiement en ligne.' : '' ?>
    </p>
    <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-bottom:14px;">
      <span style="font-weight:700;color:<?= $cx_on ? '#059669' : '#94A3B8' ?>;"><?= $cx_on ? '✅ Ouverts aux associations' : '⏸ Fermés' ?></span>
      <span style="color:#64748B;font-size:13px;">· Webhook des comptes connectés : <?= $cx_wh ? '✓ en place' : '✕ à créer (« Configurer Stripe automatiquement »)' ?><?= $cx_miss ? ' · ✕ migration 2026-10-30 à lancer' : '' ?></span>
      <form method="post" action="" style="display:inline;margin-left:auto;" <?= $cx_on ? 'onsubmit="return confirm(' . h(json_encode('Mettre en pause ? Les boutons « Payer en ligne » disparaissent ; les paiements déjà faits restent enregistrés.', JSON_UNESCAPED_UNICODE)) . ');"' : '' ?>>
        <input type="hidden" name="action" value="<?= $cx_on ? 'connect_disable' : 'connect_enable' ?>">
        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
        <button type="submit" style="<?= $cx_on ? 'background:#FEE2E2;color:#991B1B;border:1px solid #FECACA;' : 'background:#059669;color:white;border:none;' ?>padding:10px 18px;border-radius:10px;font-weight:600;cursor:pointer;font-size:13.5px;"><?= $cx_on ? '⏸ Mettre en pause' : '🚀 Ouvrir les paiements en ligne aux associations' ?></button>
      </form>
    </div>
    <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(160px, 1fr));gap:12px;margin-bottom:14px;">
      <div style="background:#F8FAFC;border:1px solid #E2E8F0;border-radius:10px;padding:12px 14px;"><div style="font-size:11px;color:#64748B;text-transform:uppercase;font-weight:700;">Associations reliées</div><div style="font-size:20px;font-weight:700;"><?= count(array_filter($cx['accounts'], fn($a) => empty($a['disconnected_at']))) ?></div><div style="font-size:12px;color:#64748B;">dont <?= count(array_filter($cx['accounts'], fn($a) => empty($a['disconnected_at']) && !empty($a['charges_enabled']))) ?> prête(s) à encaisser</div></div>
      <div style="background:#F8FAFC;border:1px solid #E2E8F0;border-radius:10px;padding:12px 14px;"><div style="font-size:11px;color:#64748B;text-transform:uppercase;font-weight:700;">Encaissé ce mois-ci</div><div style="font-size:20px;font-weight:700;"><?= h(ak_format_price_cents($cx['month_cents'])) ?></div><div style="font-size:12px;color:#64748B;"><?= (int)$cx['month_count'] ?> paiement(s), mode <?= $mode_fr ?></div></div>
      <div style="background:#F8FAFC;border:1px solid #E2E8F0;border-radius:10px;padding:12px 14px;"><div style="font-size:11px;color:#64748B;text-transform:uppercase;font-weight:700;">Encaissé au total</div><div style="font-size:20px;font-weight:700;"><?= h(ak_format_price_cents($cx['total_cents'])) ?></div><div style="font-size:12px;color:#64748B;">remboursements déduits</div></div>
    </div>
    <?php if ($cx['accounts']): ?>
      <div style="overflow-x:auto;">
      <table style="width:100%;border-collapse:collapse;font-size:13px;min-width:560px;">
        <thead><tr style="background:#F8FAFC;">
          <th style="text-align:left;padding:8px 10px;font-size:11px;color:#64748B;text-transform:uppercase;">Association</th>
          <th style="text-align:left;padding:8px 10px;font-size:11px;color:#64748B;text-transform:uppercase;">Compte Stripe</th>
          <th style="text-align:left;padding:8px 10px;font-size:11px;color:#64748B;text-transform:uppercase;">État</th>
          <th style="text-align:right;padding:8px 10px;font-size:11px;color:#64748B;text-transform:uppercase;">Ce mois</th>
          <th style="text-align:right;padding:8px 10px;font-size:11px;color:#64748B;text-transform:uppercase;">Total</th>
        </tr></thead>
        <tbody>
        <?php foreach ($cx['accounts'] as $a):
            $etat = !empty($a['disconnected_at']) ? '✕ Déconnecté' : (!empty($a['charges_enabled']) ? '✓ Encaisse' : (!empty($a['details_submitted']) ? '⏳ Vérification Stripe' : '⚠ Inscription à terminer')); ?>
          <tr style="border-top:1px solid #F1F5F9;">
            <td style="padding:8px 10px;"><a href="/super-admin/associations?id=<?= (int)$a['org_id'] ?>" style="color:#059669;text-decoration:none;"><?= h($a['org_name'] ?? ('n°' . (int)$a['org_id'])) ?></a></td>
            <td style="padding:8px 10px;font-family:monospace;font-size:12px;"><?= h($a['stripe_account_id']) ?></td>
            <td style="padding:8px 10px;"><?= h($etat) ?><?= (int)$a['nb_paid'] > 0 ? ' · ' . (int)$a['nb_paid'] . ' paiement(s)' : '' ?></td>
            <td style="padding:8px 10px;text-align:right;white-space:nowrap;"><?= h(ak_format_price_cents((int)$a['month_cents'])) ?></td>
            <td style="padding:8px 10px;text-align:right;white-space:nowrap;"><?= h(ak_format_price_cents((int)$a['total_cents'])) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div>
    <?php else: ?>
      <p style="color:#64748B;font-size:13px;margin:0;">Aucune association n’a encore relié son compte Stripe.</p>
    <?php endif; ?>
  </div>

  <!-- Activité récente -->
  <div style="<?= $card ?>">
    <h2 style="margin:0 0 6px;font-size:18px;">📈 Activité récente</h2>
    <p style="color:#64748B;font-size:13.5px;margin:0 0 14px;line-height:1.55;">
      Dernière notification reçue de Stripe : <strong><?= $ev_last ? h(date('d/m/Y à H:i', strtotime((string)$ev_last))) : 'aucune pour le moment' ?></strong>.
      <?php if ($ev_failed_n > 0): ?>
        <span style="color:#991B1B;"><strong><?= (int)$ev_failed_n ?> notification(s) en échec</strong> ces 7 derniers jours : Stripe les renvoie automatiquement ; si cela dure, voyez Développeurs › Webhooks dans Stripe.</span>
      <?php endif; ?>
    </p>
    <?php if ($ev_failed): ?>
      <ul style="margin:0 0 14px;padding-left:20px;font-size:12.5px;color:#7F1D1D;line-height:1.6;">
        <?php foreach ($ev_failed as $f): ?><li><?= h(date('d/m H:i', strtotime((string)$f['created_at']))) ?> · <?= h($f['event_type']) ?> · <?= h(mb_substr((string)$f['error_message'], 0, 160)) ?></li><?php endforeach; ?>
      </ul>
    <?php endif; ?>
    <?php if ($recent_inv): ?>
      <div style="overflow-x:auto;">
      <table style="width:100%;border-collapse:collapse;font-size:13px;min-width:480px;">
        <thead><tr style="background:#F8FAFC;">
          <th style="text-align:left;padding:8px 10px;font-size:11px;color:#64748B;text-transform:uppercase;">Date</th>
          <th style="text-align:left;padding:8px 10px;font-size:11px;color:#64748B;text-transform:uppercase;">Association</th>
          <th style="text-align:left;padding:8px 10px;font-size:11px;color:#64748B;text-transform:uppercase;">Facture</th>
          <th style="text-align:right;padding:8px 10px;font-size:11px;color:#64748B;text-transform:uppercase;">Montant</th>
          <th style="text-align:left;padding:8px 10px;font-size:11px;color:#64748B;text-transform:uppercase;">État</th>
        </tr></thead>
        <tbody>
        <?php foreach ($recent_inv as $ri):
            $lbl = ['paid' => '✓ Payée', 'open' => (($ri['billing_reason'] ?? '') === 'subscription_create' ? 'Premier paiement non abouti' : '⚠ À régulariser'), 'void' => 'Annulée', 'uncollectible' => '✕ Impayée'][$ri['status']] ?? (string)$ri['status']; ?>
          <tr style="border-top:1px solid #F1F5F9;">
            <td style="padding:8px 10px;white-space:nowrap;"><?= h(date('d/m/Y', strtotime((string)$ri['created_at']))) ?></td>
            <td style="padding:8px 10px;"><a href="/super-admin/associations?id=<?= (int)$ri['org_id'] ?>" style="color:#059669;text-decoration:none;"><?= h($ri['org_name'] ?? ('n°' . (int)$ri['org_id'])) ?></a></td>
            <td style="padding:8px 10px;font-family:monospace;"><?= h($ri['invoice_number'] ?? '—') ?></td>
            <td style="padding:8px 10px;text-align:right;white-space:nowrap;"><?= h(ak_format_price_cents((int)$ri['amount_cents'])) ?></td>
            <td style="padding:8px 10px;"><?= h($lbl) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div>
    <?php else: ?>
      <p style="color:#64748B;font-size:13px;margin:0;">Aucun paiement pour le moment.</p>
    <?php endif; ?>
  </div>

  <!-- TVA -->
  <div style="<?= $card ?>">
    <h2 style="margin:0 0 6px;font-size:18px;">📊 TVA (taxe sur la valeur ajoutée)</h2>
    <p style="color:#64748B;font-size:13.5px;margin:0 0 18px;line-height:1.55;">
      <?php if (!$vat_enabled): ?>
        Actuellement <strong>désactivée</strong> (franchise en base de TVA, art. 293 B du CGI) : le site affiche alors « TVA non applicable ». À activer quand RBPS sera assujettie à la TVA.
      <?php else: ?>
        Actuellement <strong>activée à <?= h($vat_lbl) ?></strong>. Les nouveaux abonnements par carte sont facturés HT + TVA. La TVA est due sur tous les abonnements depuis la date d’assujettissement : appliquez-la aussi aux abonnements déjà en place (voir le guide).
        Cochez aussi « Société assujettie à la TVA » dans <a href="/fondateur-cockpit/societe" style="color:#059669;">Paramètres société</a>.
      <?php endif; ?>
    </p>
    <form method="post" action="" style="display:inline;">
      <input type="hidden" name="action" value="toggle_vat">
      <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
      <button type="submit" style="background:<?= $vat_enabled ? '#FEE2E2' : '#D1FAE5' ?>;color:<?= $vat_enabled ? '#991B1B' : '#065F46' ?>;padding:10px 18px;border:1px solid <?= $vat_enabled ? '#FECACA' : '#A7F3D0' ?>;border-radius:8px;font-weight:600;cursor:pointer;font-size:13px;">
        <?= $vat_enabled ? '⏸ Désactiver la TVA' : '✅ Activer la TVA (' . h($vat_lbl) . ')' ?>
      </button>
    </form>
  </div>

  <?php if ($stripe_mode === 'live' && $test_left > 0): ?>
  <div style="<?= $card ?>background:#FFFBEB;border-color:#FCD34D;">
    <h2 style="margin:0 0 6px;font-size:17px;color:#92400E;">🧹 Données des essais en mode test</h2>
    <p style="color:#92400E;font-size:13.5px;margin:0 0 14px;line-height:1.55;"><?= (int)$test_left ?> abonnement(s) ou facture(s) de test sont encore en base. Ils sont retirés par « Configurer Stripe automatiquement » en mode réel, ou ici.</p>
    <form method="post" action="" onsubmit="return confirm('Retirer les abonnements et factures de test ? Les associations de test repassent au plan Démarrage.');">
      <input type="hidden" name="action" value="purge_test">
      <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
      <button type="submit" style="background:#92400E;color:white;padding:10px 18px;border:none;border-radius:8px;font-weight:600;cursor:pointer;font-size:13px;">Retirer les données de test</button>
    </form>
  </div>
  <?php endif; ?>

  <!-- Réglages avancés -->
  <details style="<?= $card ?>">
    <summary style="cursor:pointer;font-weight:700;font-size:16px;">🛠 Réglages avancés (saisie manuelle)</summary>
    <p style="color:#64748B;font-size:13px;margin:12px 0 16px;line-height:1.55;">Inutile si la configuration automatique est ✓. Un prix saisi ici est vérifié chez Stripe : montant HT identique à celui d’Assokit, en euros, mensuel, du mode des clés.</p>
    <table style="width:100%;border-collapse:collapse;font-size:14px;">
      <thead>
        <tr style="background:#F8FAFC;">
          <th style="text-align:left;padding:10px 14px;font-size:11px;text-transform:uppercase;color:#64748B;">Formule</th>
          <th style="text-align:left;padding:10px 14px;font-size:11px;text-transform:uppercase;color:#64748B;">Prix mensuel HT</th>
          <th style="text-align:left;padding:10px 14px;font-size:11px;text-transform:uppercase;color:#64748B;">Prix Stripe</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($plans as $p): ?>
        <tr style="border-top:1px solid #F1F5F9;">
          <td style="padding:12px 14px;font-weight:600;"><?= h(ak_plan_label($p['slug'], $p['name'] ?? null)) ?></td>
          <td style="padding:12px 14px;color:#475569;white-space:nowrap;"><?= h(ak_format_price_cents((int)$p['price_cents'])) ?></td>
          <td style="padding:12px 14px;">
            <form method="post" action="" style="display:flex;gap:6px;">
              <input type="hidden" name="action" value="save_plan_price">
              <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
              <input type="hidden" name="plan_id" value="<?= (int)$p['id'] ?>">
              <input type="text" name="stripe_price_id" value="<?= h($p['stripe_price_id_monthly'] ?? '') ?>" placeholder="price_…" style="flex:1;min-width:0;padding:7px 10px;border:1px solid #E2E8F0;border-radius:6px;font-family:monospace;font-size:12px;">
              <button type="submit" style="background:#059669;color:white;padding:7px 14px;border:none;border-radius:6px;font-size:12px;cursor:pointer;font-weight:600;">OK</button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <div style="margin-top:18px;padding:14px;background:#EFF6FF;border:1px solid #BFDBFE;border-radius:10px;">
      <div style="font-weight:700;font-size:14px;color:#1E40AF;">🏆 Option Domaine personnalisé (<?= h(ak_format_price_cents(AK_ADDON_DOMAIN_HT_CENTS)) ?> HT / mois)</div>
      <form method="post" action="" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-top:10px;">
        <input type="hidden" name="action" value="save_addon_price">
        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
        <input type="text" name="addon_domain_price_id" value="<?= h($addon_price_id ?? '') ?>" placeholder="price_…" style="flex:1;min-width:200px;padding:8px 12px;border:1px solid #BFDBFE;border-radius:8px;font-family:monospace;font-size:13px;background:white;">
        <button type="submit" style="background:#1E40AF;color:white;padding:8px 16px;border:none;border-radius:8px;font-size:13px;cursor:pointer;font-weight:600;">Enregistrer</button>
      </form>
    </div>
    <form method="post" action="" style="margin-top:18px;display:grid;gap:8px;">
      <input type="hidden" name="action" value="save_keys">
      <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
      <label style="font-size:13px;font-weight:600;color:#475569;">Secret de signature du webhook <span style="font-weight:400;color:#64748B;">(whsec_… — rempli par la configuration automatique<?= $whs ? ', actuel : ' . h(ak_stripe_mask($whs)) : '' ?>)</span></label>
      <div style="display:flex;gap:8px;flex-wrap:wrap;">
        <input type="password" name="stripe_webhook_secret" value="" placeholder="whsec_…" autocomplete="new-password" style="<?= $input ?>flex:1;min-width:200px;width:auto;">
        <button type="submit" style="background:#475569;color:white;padding:8px 16px;border:none;border-radius:8px;font-size:13px;cursor:pointer;font-weight:600;">Enregistrer</button>
      </div>
    </form>
  </details>

  <!-- Guide rapide -->
  <div style="background:linear-gradient(135deg, #FAF8F5 0%, #F0FDF4 100%);border:1px solid #A7F3D0;border-radius:14px;padding:24px;">
    <h2 style="margin:0 0 14px;font-size:17px;color:#047857;">📚 Mise en service en bref</h2>
    <ol style="margin:0;padding-left:24px;color:#0F172A;line-height:1.8;font-size:14px;">
      <li>Compte Stripe au nom de RBPS (activation, IBAN, libellé « ASSOKIT » sur les relevés).</li>
      <li>Clés de <strong>test</strong> dans le bloc 1, puis <strong>« Configurer Stripe automatiquement »</strong> et <strong>« Activer les paiements »</strong>.</li>
      <li>Essai complet avec une association de test (bloc 3, seules les associations de test voient le paiement) : carte 4242 4242 4242 4242, carte 3-D Secure 4000 0025 0000 3155, carte refusée 4000 0000 0000 9995, IBAN de test.</li>
      <li>Clés du mode <strong>réel</strong> dans le bloc 1, puis de nouveau « Configurer Stripe automatiquement » et « Activer les paiements ».</li>
      <li>Mentions légales des factures : renseignez <a href="/fondateur-cockpit/societe" style="color:#059669;">Paramètres société</a> (raison sociale, SIREN, adresse) — Assokit les écrit sur chaque client Stripe, avec la mention de TVA. Dans Stripe : e-mails aux clients, relances automatiques (3 semaines).</li>
    </ol>
    <p style="margin:12px 0 0;font-size:13px;color:#475569;">Guide complet, pas à pas : <code>docs/stripe-mise-en-service.md</code>.</p>
  </div>

</main>

<?php render_foot(); ?>
