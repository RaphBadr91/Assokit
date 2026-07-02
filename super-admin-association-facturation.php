<?php
/**
 * super-admin-association-facturation.php
 * Version basée sur mon-asso-facturation.php (qui fonctionne à 100%)
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes-layout.php';
require_once __DIR__ . '/org-billing-helpers.php';

require_login();
$user = current_user();

// Vérifier que l'utilisateur est Super Admin ou Fondateur via BDD directement
$is_sa = false;
$is_founder = false;
try {
    $stmt = $pdo->prepare("SELECT is_super_admin, is_founder FROM users WHERE id = :id AND is_active = 1 AND deleted_at IS NULL");
    $stmt->execute([':id' => (int)$user['id']]);
    $row = $stmt->fetch();
    if ($row) {
        $is_sa = (int)$row['is_super_admin'] === 1;
        $is_founder = (int)$row['is_founder'] === 1;
    }
} catch (Throwable $e) {}

if (!$is_sa && !$is_founder) {
    http_response_code(403);
    die('Accès réservé aux Super Admins et Fondateurs.');
}

$org_id = (int)($_GET['id'] ?? 0);
if ($org_id <= 0) {
    header('Location: /super-admin/associations');
    exit;
}

// Charger l'asso
$stmt = $pdo->prepare("SELECT * FROM organizations WHERE id = :id LIMIT 1");
$stmt->execute([':id' => $org_id]);
$org = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$org) {
    http_response_code(404);
    die('Association introuvable.');
}

// Récupérer les infos
$info = get_org_billing_info($pdo, $org_id);
if (!$info) $info = [];

// CSRF
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

// Flash
$flash = $_SESSION['flash_orgbill'] ?? null;
unset($_SESSION['flash_orgbill']);

// Variables pour le template
$action_url = '/super-admin/associations/facturation/save';
$cancel_url = '/super-admin/associations?id=' . $org_id;
$is_founder_view = $is_founder; // Affiche la section Fondateur
$can_edit = true;

render_head('Facturation — ' . $org['name']);
render_sidebar('factures');
?>

<div class="main">

    <nav class="crumbs">
        <a href="/super-admin">Super Admin</a>
        <span class="sep">›</span>
        <a href="/super-admin/associations">Associations</a>
        <span class="sep">›</span>
        <a href="/super-admin/associations?id=<?= (int)$org_id ?>"><?= h($org['name']) ?></a>
        <span class="sep">›</span>
        <span class="current">Facturation</span>
    </nav>

    <div class="main-head">
        <div>
            <h1 class="page-title">📄 <?= h($org['name']) ?> — Infos de facturation</h1>
            <div class="page-sub">
                <?php if ($is_founder): ?>
                    <span style="display:inline-block; background:linear-gradient(135deg, #FCD34D 0%, #F59E0B 100%); color:#78350F; padding:3px 10px; border-radius:5px; font-size:11px; font-weight:700; margin-right:8px;">🏗️ FONDATEUR</span>
                <?php endif; ?>
                Ces infos servent aux factures PDF, emails automatiques et mentions légales.
            </div>
        </div>
        <div>
            <a href="/super-admin/associations?id=<?= (int)$org_id ?>" class="btn btn-ghost">← Retour vue d'ensemble</a>
        </div>
    </div>

    <?php include __DIR__ . '/org-billing-form-template.php'; ?>

</div>

<?php render_foot(); ?>
