<?php
/**
 * demo-selector.php — Sélecteur d'organisation DEMO (version PROD)
 * 
 * 2 modes :
 *  - GET : affiche le sélecteur des 4 assos
 *  - POST avec target_org_id : switch vers cette asso et redirige vers /dashboard
 *  - GET ?back=1 : restaure le compte demo@assokit.fr (depuis le bandeau "Changer d'asso")
 */
require_once __DIR__ . '/config.php';

require_login();

$user = current_user();

// =============================================================
// MODE "back" : restaurer le compte demo@assokit.fr
// (appelé quand on clique "Changer d'asso" dans le bandeau)
// =============================================================
if (!empty($_GET['back']) && !empty($_SESSION['demo_real_email'])) {
    // On était impersonné, on revient au compte demo
    $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ? AND is_active = 1 LIMIT 1");
    $stmt->execute([$_SESSION['demo_real_email']]);
    $demo_user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($demo_user) {
        $_SESSION['user_id'] = (int)$demo_user['id'];
        $_SESSION['org_id'] = (int)$demo_user['org_id'];
        $_SESSION['user_email'] = $demo_user['email'];
        $_SESSION['user_name'] = $demo_user['first_name'] . ' ' . $demo_user['last_name'];
        $_SESSION['user_role'] = $demo_user['role'];
        $_SESSION['is_super_admin'] = (int)($demo_user['is_super_admin'] ?? 0);
        // Reset des markers démo (mais on garde demo_real_email pour le prochain switch)
        unset($_SESSION['demo_active']);
        unset($_SESSION['demo_org_name']);
        unset($_SESSION['demo_org_slug']);
        unset($_SESSION['demo_org_plan']);
    }
    
    session_write_close();
    header('Location: /demo-selector.php');
    exit;
}

// =============================================================
// PROTECTION : seul demo@assokit.fr peut accéder au sélecteur
// (sauf en mode "back" géré ci-dessus)
// =============================================================
$is_demo_account = ($user['email'] ?? '') === 'demo@assokit.fr'
                || ($_SESSION['demo_real_email'] ?? '') === 'demo@assokit.fr';

if (!$is_demo_account) {
    header('Location: /dashboard');
    exit;
}

// Jeton CSRF pour les formulaires de switch d'organisation
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// =============================================================
// ACTION POST : switch d'organisation
// =============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['target_org_id'])) {
    // CSRF (fail-closed)
    if (!function_exists('check_csrf') || !check_csrf($_POST['csrf'] ?? '')) {
        $_SESSION['demo_error'] = 'Session expirée, réessayez.';
        header('Location: /demo-selector.php');
        exit;
    }
    $target_org_id = (int)$_POST['target_org_id'];
    
    try {
        $stmt = $pdo->prepare("SELECT id, name, slug, plan FROM organizations WHERE id = ? AND slug LIKE 'demo-%'");
        $stmt->execute([$target_org_id]);
        $target_org = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$target_org) {
            $_SESSION['demo_error'] = 'Organisation invalide';
            header('Location: /demo-selector.php');
            exit;
        }
        
        // Récupérer l'admin (PAS demo@assokit.fr)
        $stmt = $pdo->prepare("
            SELECT id, email, first_name, last_name, role, is_super_admin 
            FROM users 
            WHERE org_id = ? AND role = 'admin' AND is_active = 1 
              AND email != 'demo@assokit.fr'
            LIMIT 1
        ");
        $stmt->execute([$target_org_id]);
        $admin = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$admin) {
            $_SESSION['demo_error'] = 'Aucun admin de démo trouvé pour ' . $target_org['name'];
            header('Location: /demo-selector.php');
            exit;
        }
        
        // Switch session vers l'admin de l'asso choisie
        $_SESSION['org_id'] = $target_org_id;
        $_SESSION['demo_active'] = 1;
        $_SESSION['demo_org_name'] = $target_org['name'];
        $_SESSION['demo_org_slug'] = $target_org['slug'];
        $_SESSION['demo_org_plan'] = $target_org['plan'];
        
        $_SESSION['user_id'] = (int)$admin['id'];
        $_SESSION['user_email'] = $admin['email'];
        $_SESSION['user_name'] = $admin['first_name'] . ' ' . $admin['last_name'];
        $_SESSION['user_role'] = $admin['role'];
        $_SESSION['is_super_admin'] = (int)($admin['is_super_admin'] ?? 0);
        $_SESSION['demo_real_email'] = 'demo@assokit.fr'; // Pour le retour
        
        if (file_exists(__DIR__ . '/activity-tracker.php')) {
            require_once __DIR__ . '/activity-tracker.php';
            if (function_exists('activity_log_action')) {
                activity_log_action('demo_switch_org', ['target_org' => $target_org['slug']], (string)$target_org_id);
            }
        }
        
        session_write_close();
        header('Location: /dashboard');
        exit;
        
    } catch (Throwable $e) {
        error_log('demo-selector: ' . $e->getMessage());
        $_SESSION['demo_error'] = 'Erreur : ' . $e->getMessage();
        header('Location: /demo-selector.php');
        exit;
    }
}

// =============================================================
// AFFICHAGE DU SÉLECTEUR (GET)
// =============================================================
$demos = $pdo->query("
    SELECT 
        o.id, o.name, o.slug, o.plan, o.legal_form, 
        o.president_first_name, o.president_last_name,
        o.billing_address_city, o.created_at, o.monthly_price_cents,
        (SELECT COUNT(*) FROM users WHERE org_id = o.id AND is_active = 1) AS member_count,
        (SELECT COUNT(*) FROM projects p JOIN folders f ON p.folder_id = f.id WHERE f.org_id = o.id AND p.archived_at IS NULL) AS project_count,
        (SELECT COUNT(*) FROM asso_invoices WHERE org_id = o.id) AS invoice_count
    FROM organizations o
    WHERE o.slug LIKE 'demo-%'
    ORDER BY 
        CASE o.slug 
            WHEN 'demo-evry' THEN 1
            WHEN 'demo-corbeil' THEN 2
            WHEN 'demo-paris' THEN 3
            WHEN 'demo-tpe' THEN 4
        END
")->fetchAll(PDO::FETCH_ASSOC);

$error = $_SESSION['demo_error'] ?? null;
unset($_SESSION['demo_error']);

function h_dem($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$org_configs = [
    'demo-evry' => ['icon' => '🤝', 'color' => '#10b981', 'tagline' => 'Petite asso de proximité', 'pitch' => 'Idéal pour démontrer Assokit à une petite asso loi 1901 qui démarre', 'cible_membres' => 50, 'cible_projets' => 20, 'cible_factures' => 40],
    'demo-corbeil' => ['icon' => '💜', 'color' => '#a855f7', 'tagline' => 'Asso dynamique de taille moyenne', 'pitch' => 'Parfait pour montrer la puissance d\'Assokit à une asso en croissance', 'cible_membres' => 120, 'cible_projets' => 50, 'cible_factures' => 120],
    'demo-paris' => ['icon' => '🏛️', 'color' => '#3b82f6', 'tagline' => 'Grande asso parisienne — full max', 'pitch' => 'Démo VIP : version SUR-MESURE white-label, domaine perso, support dédié', 'cible_membres' => 250, 'cible_projets' => 80, 'cible_factures' => 300],
    'demo-tpe' => ['icon' => '🏢', 'color' => '#f59e0b', 'tagline' => 'Petite entreprise (TPE/SARL)', 'pitch' => 'Pour démontrer Assokit à un dirigeant de TPE — gestion équipe & facturation client', 'cible_membres' => 4, 'cible_projets' => 20, 'cible_factures' => 170],
];

$plan_display = [
    'essentiel' => ['label' => 'DÉMARRAGE', 'price' => 'Gratuit', 'subtitle' => 'Pour découvrir', 'color' => '#94a3b8'],
    'association' => ['label' => 'ASSOKIT', 'price' => '49,99 €/mois', 'subtitle' => 'Le plus populaire', 'color' => '#10b981'],
    'organisation' => ['label' => 'SUR-MESURE', 'price' => 'Sur devis', 'subtitle' => 'Full max', 'color' => '#3b82f6'],
];
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex,nofollow">
<title>Sélectionner une démo — Assokit</title>
<style>
* { box-sizing: border-box; }
body { font-family: -apple-system, sans-serif; background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%); color: #f1f5f9; min-height: 100vh; margin: 0; padding: 40px 20px; }
.container { max-width: 1280px; margin: 0 auto; }
.demo-header { text-align: center; margin-bottom: 32px; }
.demo-badge { display: inline-flex; padding: 6px 16px; background: rgba(245, 158, 11, 0.15); border: 1px solid rgba(245, 158, 11, 0.4); border-radius: 999px; color: #fbbf24; font-size: 12px; font-weight: 600; text-transform: uppercase; margin-bottom: 16px; }
.demo-header h1 { font-size: 32px; margin: 0 0 10px; font-weight: 700; }
.demo-header p { color: #94a3b8; font-size: 16px; margin: 0; }
.error-box { background: rgba(239, 68, 68, 0.1); border: 1px solid rgba(239, 68, 68, 0.3); color: #fca5a5; padding: 12px 18px; border-radius: 10px; margin-bottom: 24px; text-align: center; font-size: 14px; }
.demo-info { background: rgba(16, 185, 129, 0.08); border: 1px solid rgba(16, 185, 129, 0.25); border-radius: 12px; padding: 16px 20px; margin-bottom: 28px; font-size: 13px; color: #d1fae5; }
.demo-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(290px, 1fr)); gap: 18px; margin-bottom: 32px; }
.demo-form { margin: 0; display: flex; }
.demo-card { width: 100%; background: linear-gradient(135deg, #1e293b 0%, #1a2238 100%); border: 1px solid #334155; border-radius: 16px; padding: 22px; position: relative; overflow: hidden; display: flex; flex-direction: column; transition: all 0.2s; }
.demo-card:hover { border-color: var(--card-color); transform: translateY(-3px); box-shadow: 0 12px 30px rgba(0,0,0,0.4), 0 0 0 1px var(--card-color); }
.demo-card::before { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 4px; background: var(--card-color); }
.demo-card.is-premium::after { content: '⭐ FULL MAX'; position: absolute; top: 14px; right: 14px; padding: 3px 10px; background: rgba(59,130,246,0.2); border: 1px solid rgba(59,130,246,0.5); border-radius: 999px; font-size: 9px; font-weight: 700; color: #93c5fd; }
.demo-card-icon { font-size: 32px; margin-bottom: 10px; }
.demo-card-name { font-size: 18px; font-weight: 700; color: #f8fafc; margin-bottom: 3px; }
.demo-card-tagline { font-size: 12px; color: #94a3b8; margin-bottom: 14px; }
.demo-card-pitch { font-size: 11px; color: #cbd5e0; padding: 8px 10px; background: rgba(0,0,0,0.25); border-radius: 8px; margin-bottom: 14px; font-style: italic; border-left: 2px solid var(--card-color); }
.demo-card-meta { font-size: 11.5px; color: #94a3b8; margin-bottom: 5px; }
.demo-card-meta strong { color: #cbd5e0; }
.demo-card-stats { display: grid; grid-template-columns: repeat(3, 1fr); gap: 6px; margin: 14px 0; padding: 12px; background: rgba(0,0,0,0.25); border-radius: 10px; }
.demo-card-stat { text-align: center; }
.demo-card-stat-value { font-size: 17px; font-weight: 700; color: #f8fafc; }
.demo-card-stat-target { font-size: 9px; color: #64748b; }
.demo-card-stat-label { font-size: 9px; color: #64748b; text-transform: uppercase; margin-top: 4px; }
.demo-card-plan-box { display: flex; justify-content: space-between; align-items: center; padding: 10px 12px; background: rgba(0,0,0,0.3); border-radius: 8px; margin-bottom: 14px; }
.demo-card-plan-label { font-size: 11px; font-weight: 700; text-transform: uppercase; }
.demo-card-plan-subtitle { font-size: 9.5px; color: #64748b; margin-top: 2px; }
.demo-card-plan-price { font-size: 13px; font-weight: 700; color: #f8fafc; }
.demo-card-cta { display: flex; align-items: center; justify-content: center; gap: 8px; width: 100%; padding: 12px; background: var(--card-color); color: white; border: 0; border-radius: 10px; font-size: 14px; font-weight: 600; cursor: pointer; font-family: inherit; margin-top: auto; transition: all 0.15s; }
.demo-card-cta:hover { filter: brightness(1.15); transform: scale(1.02); }
.demo-footer { text-align: center; padding-top: 24px; border-top: 1px solid #334155; color: #64748b; font-size: 13px; }
.demo-footer a { color: #94a3b8; text-decoration: none; }
@media (max-width: 600px) { body { padding: 20px 14px; } .demo-header h1 { font-size: 24px; } .demo-grid { grid-template-columns: 1fr; } }
</style>
</head>
<body>
<div class="container">
<div class="demo-header">
    <div class="demo-badge">🎬 Mode démo commerciale</div>
    <h1>Bonjour <?= h_dem($user['first_name'] ?? 'Démo') ?> 👋</h1>
    <p>Choisissez l'organisation à présenter à votre prospect</p>
</div>

<div class="demo-info">
    💡 <strong>Conseil :</strong> Adapte ton choix au profil du prospect. Toutes les données sont fictives et <strong>réinitialisées chaque nuit à minuit</strong>.
</div>

<?php if ($error): ?><div class="error-box">⚠️ <?= h_dem($error) ?></div><?php endif; ?>

<div class="demo-grid">
    <?php foreach ($demos as $org):
        $cfg = $org_configs[$org['slug']] ?? ['icon' => '🏛️', 'color' => '#94a3b8', 'tagline' => '', 'pitch' => '', 'cible_membres' => 0, 'cible_projets' => 0, 'cible_factures' => 0];
        $plan = $plan_display[$org['plan']] ?? $plan_display['essentiel'];
        $is_premium = ($org['plan'] === 'organisation');
    ?>
    <form method="POST" action="" class="demo-form">
        <input type="hidden" name="target_org_id" value="<?= (int)$org['id'] ?>">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES) ?>">
        <div class="demo-card <?= $is_premium ? 'is-premium' : '' ?>" style="--card-color: <?= $cfg['color'] ?>">
            <div class="demo-card-icon"><?= $cfg['icon'] ?></div>
            <div class="demo-card-name"><?= h_dem($org['name']) ?></div>
            <div class="demo-card-tagline"><?= h_dem($cfg['tagline']) ?></div>
            <div class="demo-card-pitch">💼 <?= h_dem($cfg['pitch']) ?></div>
            <div class="demo-card-meta">📍 <strong><?= h_dem($org['billing_address_city']) ?></strong></div>
            <div class="demo-card-meta">👤 <?= h_dem($org['president_first_name']) ?> <?= h_dem($org['president_last_name']) ?></div>
            <div class="demo-card-stats">
                <div class="demo-card-stat"><div class="demo-card-stat-value"><?= (int)$org['member_count'] ?></div><div class="demo-card-stat-target">/ <?= (int)$cfg['cible_membres'] ?></div><div class="demo-card-stat-label">Membres</div></div>
                <div class="demo-card-stat"><div class="demo-card-stat-value"><?= (int)$org['project_count'] ?></div><div class="demo-card-stat-target">/ <?= (int)$cfg['cible_projets'] ?></div><div class="demo-card-stat-label">Projets</div></div>
                <div class="demo-card-stat"><div class="demo-card-stat-value"><?= (int)$org['invoice_count'] ?></div><div class="demo-card-stat-target">/ <?= (int)$cfg['cible_factures'] ?></div><div class="demo-card-stat-label">Factures</div></div>
            </div>
            <div class="demo-card-plan-box">
                <div><div class="demo-card-plan-label" style="color: <?= $plan['color'] ?>;"><?= h_dem($plan['label']) ?></div><div class="demo-card-plan-subtitle"><?= h_dem($plan['subtitle']) ?></div></div>
                <div class="demo-card-plan-price"><?= h_dem($plan['price']) ?></div>
            </div>
            <button type="submit" class="demo-card-cta">Démarrer la démo →</button>
        </div>
    </form>
    <?php endforeach; ?>
</div>

<div class="demo-footer">
    <p>🔄 <strong>Reset auto</strong> chaque nuit à 00h00 — vos modifs pendant la démo seront effacées.</p>
    <p><a href="/super-admin">← Cockpit Fondateur</a> · <a href="/deconnexion.php">Déconnexion</a></p>
</div>

</div>
</body>
</html>
