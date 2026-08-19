<?php
/**
 * ============================================================
 * ASSOKIT — mon-asso-facturation.php
 * Édition des infos facturation de SON PROPRE asso
 * ============================================================
 * URL : /mon-asso/facturation
 * Accès : admin de l'asso connecté
 * ============================================================
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes-layout.php';
require_once __DIR__ . '/org-billing-helpers.php';

require_login();
$user = current_user();

// Doit être admin de son asso
if ($user['role'] !== 'admin') {
    http_response_code(403);
    die('Accès réservé aux administrateurs de l\'association.');
}

$org_id = (int)$user['org_id'];
if ($org_id <= 0) {
    http_response_code(404);
    die('Association introuvable.');
}

// Vérif droits
$can_edit = can_edit_org_billing($pdo, (int)$user['id'], $org_id);
if (!$can_edit) {
    http_response_code(403);
    exit('Accès refusé.');
}

// Récupérer les infos
$info = get_org_billing_info($pdo, $org_id);
if (!$info) {
    http_response_code(404);
    die('Association introuvable.');
}

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
$cancel_url = '/dashboard';
$is_founder_view = false; // Vue admin asso classique

render_head('Infos de facturation');
render_sidebar('factures');
?>

<div class="main">

    <nav class="crumbs">
        <a href="/dashboard">Dashboard</a>
        <span class="sep">›</span>
        <span class="current">Infos de facturation</span>
    </nav>

    <div class="main-head">
        <div>
            <h1 class="page-title" style="display:flex; align-items:center; gap:11px;"><?= ak_icon_badge('file','#059669',36) ?><span>Informations de facturation</span></h1>
            <div class="page-sub">
                Ces infos apparaîtront sur vos factures et dans les emails d'envoi.
                Complétez-les pour des factures 100% conformes.
            </div>
        </div>
    </div>

    <?php include __DIR__ . '/org-billing-form-template.php'; ?>

</div>

<style>
/* Adaptation des styles SA au design asso (vert émeraude) */
.orgbill-section {
    background: var(--bg, #fff);
    border: 1px solid var(--border, #E5E7EB);
    border-radius: 12px;
    margin-bottom: 12px;
    overflow: hidden;
}
.orgbill-section summary {
    padding: 16px 20px;
    cursor: pointer;
    font-weight: 600;
    color: var(--ink, #111827);
    font-size: 15px;
    user-select: none;
    list-style: none;
    display: flex;
    align-items: center;
    gap: 10px;
    background: var(--bg-2, #F9FAFB);
}
.orgbill-section summary:hover { background: rgba(5, 150, 105, 0.05); }
.orgbill-section summary::before {
    content: '▶';
    font-size: 10px;
    color: #059669;
    transition: transform 0.2s;
}
.orgbill-section[open] summary::before { transform: rotate(90deg); }
.orgbill-section[open] summary { border-bottom: 1px solid var(--border); }

.orgbill-grid .field input:focus,
.orgbill-grid .field select:focus {
    border-color: #059669;
    box-shadow: 0 0 0 3px rgba(5, 150, 105, 0.15);
}
.orgbill-grid .field input[type="checkbox"] {
    accent-color: #059669;
}
.sa-btn-violet {
    background: #059669 !important;
}
.sa-btn-violet:hover {
    background: #047857 !important;
}
</style>

<?php render_foot(); ?>
