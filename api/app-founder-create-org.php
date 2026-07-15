<?php
/**
 * api/app-founder-create-org.php — Créer une association / TPE (Fondateur, natif).
 * GET  → liste des plans disponibles.
 * POST → crée organisation + compte admin + abonnement (porte la logique du site,
 *        sans le modifier), renvoie les identifiants générés.
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
$is_sa = app_is_founder($pdo, $user) || !empty($user['is_super_admin']) || (($user['role'] ?? '') === 'super_admin');
if (!$is_sa) oorg_fail(403, 'forbidden');

// --- Liste des plans (GET) ---
$plans = [];
try {
    $plans = $pdo->query("SELECT id, name, slug, price_cents, tagline, is_trial, trial_converts_to
                          FROM asso_plans WHERE is_visible = 1 OR is_trial = 1
                          ORDER BY is_trial ASC, price_cents ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    $out = [];
    foreach ($plans as $p) {
        $out[] = [
            'id' => (int) $p['id'],
            'name' => (string) $p['name'],
            'slug' => (string) $p['slug'],
            'price' => round(((int) ($p['price_cents'] ?? 0)) / 100, 2),
            'tagline' => (string) ($p['tagline'] ?? ''),
            'is_trial' => !empty($p['is_trial']),
        ];
    }
    echo json_encode(['ok' => true, 'plans' => $out], JSON_UNESCAPED_UNICODE);
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

    $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $org_name));
    $slug = trim($slug, '-');
    if (strlen($slug) < 3) $slug = 'asso-' . substr(md5(microtime()), 0, 6);
    $cs = $pdo->prepare("SELECT COUNT(*) FROM organizations WHERE slug = ?");
    $cs->execute([$slug]);
    if ((int) $cs->fetchColumn() > 0) $slug .= '-' . substr(md5(microtime()), 0, 4);

    $password_plain = $custom_password !== '' ? $custom_password : bin2hex(random_bytes(6));
    $password_hash  = password_hash($password_plain, PASSWORD_BCRYPT);

    $chosen = null;
    foreach ($plans as $p) { if ((int) $p['id'] === $plan_id) { $chosen = $p; break; } }
    if (!$chosen) oorg_fail(400, 'plan', 'Plan introuvable.');
    $is_trial = !empty($chosen['is_trial']);
    $chosen_slug = $chosen['slug'] ?? null;
    // Statut : essai brdié -> trial ; stripe -> en attente de paiement ; sinon actif
    $sub_status = $is_trial ? 'trial' : ($payment_mode === 'stripe' ? 'pending_payment' : 'active');
    $period_end = date('Y-m-d H:i:s', strtotime('+' . max(1, $period_days) . ' days'));

    $pdo->beginTransaction();
    $pdo->prepare("INSERT INTO organizations (name, slug, billing_email, created_at) VALUES (?, ?, ?, NOW())")
        ->execute([$org_name, $slug, $email]);
    $new_org_id = (int) $pdo->lastInsertId();

    $pdo->prepare("INSERT INTO users (org_id, email, password_hash, first_name, last_name, role, is_active, created_at)
                   VALUES (?, ?, ?, ?, ?, 'admin', 1, NOW())")
        ->execute([$new_org_id, $email, $password_hash, $first_name, $last_name]);

    $new_sub_id = 0;
    try {
        $pdo->prepare("INSERT INTO asso_subscriptions (org_id, plan_id, payment_mode, status, current_period_end, created_at, updated_at)
                       VALUES (?, ?, ?, ?, ?, NOW(), NOW())")
            ->execute([$new_org_id, $plan_id, $payment_mode, $sub_status, $period_end]);
        $new_sub_id = (int) $pdo->lastInsertId();
    } catch (Throwable $e) {}

    // Add-on domaine personnalisé si demandé (10 €/mois), comme sur le web
    if ($with_addon_domain && $new_sub_id > 0) {
        try {
            $pdo->prepare("INSERT INTO asso_subscription_addons (subscription_id, org_id, addon_type, addon_name, price_cents_monthly, status)
                           VALUES (?, ?, 'custom_domain', 'Domaine personnalisé', 1000, 'active')")
                ->execute([$new_sub_id, $new_org_id]);
        } catch (Throwable $e) {}
    }

    if ($is_trial) {
        $pdo->prepare("UPDATE organizations SET plan = ?, status = 'trial', trial_ends_at = ? WHERE id = ?")
            ->execute([$chosen_slug, $period_end, $new_org_id]);
    } else {
        $pdo->prepare("UPDATE organizations SET plan = ?, status = 'active' WHERE id = ?")
            ->execute([$chosen_slug, $new_org_id]);
    }
    $pdo->commit();

    $email_sent = false;
    if ($send_email) {
        try {
            @require_once __DIR__ . '/../resend-helper.php';
            if (function_exists('send_transactional_email')) {
                $body = "<h2>🌿 Bienvenue chez Assokit, " . htmlspecialchars($first_name) . " !</h2>"
                    . "<p>Votre compte <strong>" . htmlspecialchars($org_name) . "</strong> est prêt.</p>"
                    . "<p><strong>Email :</strong> " . htmlspecialchars($email) . "<br>"
                    . "<strong>Mot de passe :</strong> <code>" . htmlspecialchars($password_plain) . "</code></p>"
                    . "<p>👉 <a href='https://assokit.fr/login'>Se connecter</a></p>";
                send_transactional_email($email, 'Bienvenue chez Assokit !', $body, ['tag' => 'founder_new_org']);
                $email_sent = true;
            }
        } catch (Throwable $e) {}
    }

    echo json_encode([
        'ok' => true,
        'org_id' => $new_org_id,
        'org_name' => $org_name,
        'email' => $email,
        'password' => $password_plain,
        'email_sent' => $email_sent,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('[app-founder-create-org] ' . $e->getMessage());
    oorg_fail(500, 'server', 'Création impossible.');
}
