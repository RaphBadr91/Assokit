<?php
/**
 * api/app-founder-create-org.php — Créer une association / TPE (Fondateur, natif).
 * GET  → liste des plans disponibles.
 * POST → crée organisation + compte admin + abonnement (porte la logique du site,
 *        sans le modifier), renvoie les identifiants générés. Création par un Super Admin
 *        non fondateur : association « en attente de validation Fondateur », fondateurs
 *        notifiés (comme /fondateur-create-organization et /super-admin/nouvelle-asso).
 * NE MODIFIE PAS le site : dédié à l'application.
 */
ob_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes-layout.php';
ob_end_clean();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function oorg_fail($c, $e, $m = null) { http_response_code($c); echo json_encode(['ok' => false, 'error' => $e, 'message' => $m], JSON_UNESCAPED_UNICODE); exit; }

if (empty($_SESSION['user_id'])) oorg_fail(401, 'auth');
$user = function_exists('current_user') ? current_user() : null;
require_once __DIR__ . '/_app-founder.php';
// Compte désactivé ou supprimé depuis l'ouverture de la session : session fermée, 401
if (!app_session_account_alive($pdo)) oorg_fail(401, 'auth');
require_once __DIR__ . '/_app-founder-plan.php';
$is_founder = app_is_founder($pdo, $user);
$is_sa = $is_founder || !empty($user['is_super_admin']) || (($user['role'] ?? '') === 'super_admin');
if (!$is_sa) oorg_fail(403, 'forbidden');

// --- Liste des plans (GET) : les mêmes que le sélecteur de formule de la fiche association ---
$plans = fd_plan_choices($pdo);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    $out = [];
    foreach ($plans as $p) {
        $out[] = [
            'id' => $p['id'],
            'name' => $p['name'],
            'label' => $p['label'],
            'slug' => $p['slug'],
            'price' => $p['price'],
            'price_text' => $p['price_text'],
            'is_trial' => $p['is_trial'],
        ];
    }
    echo json_encode(['ok' => true, 'plans' => $out, 'trial_days' => (int) AK_TRIAL_DAYS], JSON_UNESCAPED_UNICODE);
    exit;
}

// --- Création (POST) ---
$raw = file_get_contents('php://input');
$input = json_decode((string) $raw, true);
if (!is_array($input)) $input = [];
$token = (string) ($input['csrf'] ?? '');
if (empty($_SESSION['csrf_token']) || !hash_equals((string) $_SESSION['csrf_token'], $token)) oorg_fail(403, 'csrf', 'Session expirée, réessaie.');

$org_name   = trim((string) ($input['org_name'] ?? ''));
$email      = trim((string) ($input['billing_email'] ?? ''));
$first_name = trim((string) ($input['first_name'] ?? ''));
$last_name  = trim((string) ($input['last_name'] ?? ''));
$plan_id    = (int) ($input['plan_id'] ?? 0);
$send_email = !empty($input['send_welcome_email']);
$period_days = max(1, (int) ($input['period_days'] ?? 30));
$payment_mode = (string) ($input['payment_mode'] ?? 'free_grant');
if (!in_array($payment_mode, ['free_grant', 'manual', 'wire_transfer', 'stripe'], true)) $payment_mode = 'free_grant';
$with_addon_domain = !empty($input['with_addon_domain']);
$custom_password = trim((string) ($input['custom_password'] ?? ''));

if ($org_name === '') oorg_fail(400, 'org_name', 'Nom de l\'organisation obligatoire.');
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) oorg_fail(400, 'email', 'Email invalide.');
if ($first_name === '') oorg_fail(400, 'first_name', 'Prénom obligatoire.');
if ($plan_id <= 0) oorg_fail(400, 'plan', 'Choisis un plan.');

try {
    $check = $pdo->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
    $check->execute([$email]);
    if ($check->fetchColumn()) oorg_fail(409, 'email_used', 'Cet email est déjà utilisé.');

    // Même règle que l'inscription : unique, jamais « demo-… » (ces espaces sont traités
    // comme des démos : e-mails simulés, fin d'essai jamais appliquée par le cron)
    $slug = ak_signup_slug($pdo, $org_name);

    $password_plain = $custom_password !== '' ? $custom_password : bin2hex(random_bytes(6));
    $password_hash  = password_hash($password_plain, PASSWORD_BCRYPT);

    $chosen = null;
    foreach ($plans as $p) { if ((int) $p['id'] === $plan_id) { $chosen = $p; break; } }
    if (!$chosen) oorg_fail(400, 'plan', 'Plan introuvable.');
    $is_trial = !empty($chosen['is_trial']) || $chosen['slug'] === 'pro-essai';
    $chosen_slug = $chosen['slug'] ?? null;
    // Statut : essai -> trial ; stripe -> en attente de paiement ; sinon actif
    $sub_status = $is_trial ? (ak_db_pick($pdo, 'asso_subscriptions', 'status', ['trial', 'active']) ?? 'trial')
                            : ($payment_mode === 'stripe' ? 'pending_payment' : 'active');
    // Fin d'essai calculée par la base, comme l'inscription et ak_trial_extend()
    $period_end = $is_trial
        ? (string) $pdo->query("SELECT DATE_ADD(NOW(), INTERVAL " . min(3650, $period_days) . " DAY)")->fetchColumn()
        : date('Y-m-d H:i:s', strtotime('+' . max(1, $period_days) . ' days'));

    $pdo->beginTransaction();
    $pdo->prepare("INSERT INTO organizations (name, slug, billing_email, created_at) VALUES (?, ?, ?, NOW())")
        ->execute([$org_name, $slug, $email]);
    $new_org_id = (int) $pdo->lastInsertId();
    // Auteur + statut de validation, comme le web : Fondateur = validée, Super Admin non
    // fondateur = en attente de validation Fondateur (onglet « À valider » du cockpit)
    $validation_status = ak_db_pick($pdo, 'organizations', 'validation_status', [$is_founder ? 'validated' : 'pending_founder']);
    ak_db_update($pdo, 'organizations', $new_org_id, ['created_by_user_id' => (int) $_SESSION['user_id']]
        + ($validation_status !== null ? ['validation_status' => $validation_status] : []));

    $pdo->prepare("INSERT INTO users (org_id, email, password_hash, first_name, last_name, role, is_active, created_at)
                   VALUES (?, ?, ?, ?, ?, 'admin', 1, NOW())")
        ->execute([$new_org_id, $email, $password_hash, $first_name, $last_name]);
    $new_user_id = (int) $pdo->lastInsertId();

    $new_sub_id = 0;
    try {
        $new_sub_id = ak_db_insert($pdo, 'asso_subscriptions', ['org_id' => $new_org_id, 'plan_id' => $plan_id, 'payment_mode' => $payment_mode,
            'status' => $sub_status, 'current_period_end' => $period_end], ['started_at' => 'NOW()', 'created_at' => 'NOW()', 'updated_at' => 'NOW()']);
    } catch (Throwable $e) { error_log('[app-founder-create-org] abonnement : ' . get_class($e)); }

    // Essai : ligne « historique » (bandeau d'essai + rappels J-7 / J-3 / J-1 / J-0), comme l'inscription
    if ($is_trial) {
        try {
            $legacy_status = ak_db_pick($pdo, 'subscriptions', 'status', ['trial']);
            if (ak_db_columns($pdo, 'subscriptions') && $legacy_status) {
                ak_db_insert($pdo, 'subscriptions', [
                    'org_id' => $new_org_id,
                    'plan' => ak_db_pick($pdo, 'subscriptions', 'plan', ['pro', 'association', 'essentiel']) ?? 'pro',
                    'status' => $legacy_status, 'billing_cycle' => ak_db_pick($pdo, 'subscriptions', 'billing_cycle', ['monthly']) ?? 'monthly',
                    'price_ht' => 49.99, 'tva_rate' => 20.00, 'current_period_end' => $period_end,
                ], ['started_at' => 'NOW()', 'current_period_start' => 'NOW()', 'created_at' => 'NOW()']);
            }
        } catch (Throwable $e) { error_log('[app-founder-create-org] essai historique : ' . get_class($e)); }
    }

    // Add-on domaine personnalisé si demandé (10 €/mois), comme sur le web
    if ($with_addon_domain && $new_sub_id > 0) {
        try {
            $pdo->prepare("INSERT INTO asso_subscription_addons (subscription_id, org_id, addon_type, addon_name, price_cents_monthly, status)
                           VALUES (?, ?, 'custom_domain', 'Domaine personnalisé', 1000, 'active')")
                ->execute([$new_sub_id, $new_org_id]);
        } catch (Throwable $e) {}
    }

    // organizations.plan (ancien libellé) = slug du plan, seulement si la colonne l'accepte
    // (un ENUM d'anciens libellés refuserait « pro-essai » et ferait échouer toute la création)
    $org_plan = $chosen_slug !== null && (!ak_db_columns($pdo, 'organizations') || ak_db_accepts($pdo, 'organizations', 'plan', (string) $chosen_slug))
        ? ['plan' => $chosen_slug] : [];
    if ($is_trial) {
        ak_db_update($pdo, 'organizations', $new_org_id, $org_plan + ['trial_ends_at' => $period_end]
            + (ak_db_accepts($pdo, 'organizations', 'status', 'trial') || !ak_db_columns($pdo, 'organizations') ? ['status' => 'trial'] : ['status' => 'active']));
    } else {
        ak_db_update($pdo, 'organizations', $new_org_id, $org_plan + ['status' => 'active']);
    }
    $pdo->commit();

    // Historique du cockpit (filtre « Création asso ») : même code et mêmes détails que le web
    @include_once __DIR__ . '/../superadmin-layout.php';
    if (function_exists('sa_log_action')) {
        try {
            sa_log_action((int) ($user['id'] ?? $_SESSION['user_id']), 'create_org', $new_org_id, $new_user_id ?: null, [
                'org_name' => $org_name, 'plan' => $chosen_slug, 'status' => $sub_status, 'payment_mode' => $payment_mode,
                'days' => $period_days, 'admin_email' => $email, 'validation_status' => $validation_status, 'source' => 'appli',
            ]);
        } catch (Throwable $e) {}
    }

    // Création par un Super Admin non fondateur : les fondateurs valident (in-app + e-mail), comme le web
    if (!$is_founder) {
        $creator = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')) ?: (string) ($user['email'] ?? 'Un Super Admin');
        @include_once __DIR__ . '/../sa-permissions.php';
        if (function_exists('sa_notify_all_founders')) {
            sa_notify_all_founders(
                'new_org_pending',
                'Nouvelle association à valider',
                $creator . ' a créé « ' . $org_name . ' » (en attente de validation).',
                '/super-admin/associations?id=' . $new_org_id,
                $new_org_id
            );
        }
        try {
            @require_once __DIR__ . '/../resend-helper.php';
            if (function_exists('send_transactional_email')) {
                $founders = $pdo->query("SELECT email, first_name FROM users WHERE is_founder = 1 AND is_active = 1")->fetchAll(PDO::FETCH_ASSOC);
                foreach ($founders as $f) {
                    $content = '<p>Bonjour ' . htmlspecialchars((string) $f['first_name']) . ',</p>'
                        . '<p><strong>' . htmlspecialchars($creator) . '</strong> (Super Admin) vient de créer le compte <strong>' . htmlspecialchars($org_name) . '</strong>'
                        . ' (plan ' . htmlspecialchars(ak_plan_label($chosen_slug, $chosen['name'] ?? null)) . ', jusqu’au ' . date('d/m/Y', strtotime($period_end)) . ').</p>'
                        . '<p>Il est en attente de votre validation.</p>';
                    $html = function_exists('email_wrap')
                        ? email_wrap('Validation requise', $content, 'Examiner ce compte', 'https://assokit.fr/super-admin/associations?id=' . $new_org_id)
                        : $content;
                    send_transactional_email((string) $f['email'], '🏗️ Nouvelle association à valider — ' . $org_name, $html, ['tag' => 'org_pending_validation']);
                }
            }
        } catch (Throwable $e) { error_log('[app-founder-create-org] notification fondateurs : ' . get_class($e)); }
    }

    $email_sent = false;
    if ($send_email) {
        try {
            @require_once __DIR__ . '/../resend-helper.php';
            if (function_exists('send_transactional_email')) {
                $body = "<h2>🌿 Bienvenue chez Assokit, " . htmlspecialchars($first_name) . " !</h2>"
                    . "<p>Votre compte <strong>" . htmlspecialchars($org_name) . "</strong> est prêt.</p>"
                    . "<p><strong>Email :</strong> " . htmlspecialchars($email) . "<br>"
                    . "<strong>Mot de passe :</strong> <code>" . htmlspecialchars($password_plain) . "</code></p>"
                    . ($is_trial ? "<p>Votre période d’essai gratuit (" . htmlspecialchars(ak_plan_label($chosen_slug, $chosen['name'] ?? null)) . ") est active jusqu’au <strong>" . htmlspecialchars(ak_trial_end_label($period_end)) . "</strong>.</p>" : "")
                    . "<p>👉 <a href='https://assokit.fr/login'>Se connecter</a></p>";
                // « Email de bienvenue envoyé » seulement s'il est vraiment parti : sinon l'appli
                // demande au fondateur de transmettre lui-même les identifiants
                $res = send_transactional_email($email, 'Bienvenue chez Assokit !', $body, ['tag' => 'founder_new_org']);
                $email_sent = !empty($res['success']);
            }
        } catch (Throwable $e) { error_log('[app-founder-create-org] e-mail de bienvenue : ' . $e->getMessage()); }
    }

    echo json_encode([
        'ok' => true,
        'org_id' => $new_org_id,
        'org_name' => $org_name,
        'email' => $email,
        'password' => $password_plain,
        'email_sent' => $email_sent,
        'validation_status' => $validation_status,   // 'pending_founder' : création d'un Super Admin, à valider par le Fondateur
        'state' => fd_org_plan($pdo, $new_org_id),
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('[app-founder-create-org] ' . $e->getMessage());
    oorg_fail(500, 'server', 'Création impossible.');
}
