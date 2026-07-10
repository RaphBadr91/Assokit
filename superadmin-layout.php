<?php
/**
 * superadmin-layout.php — Layout partage du Super Admin
 * ======================================================
 * Expose 3 fonctions utilisees par toutes les pages /super-admin/* :
 *   - sa_render_head($title)    : HTML <head> + CSS commun
 *   - sa_render_sidebar($active): sidebar gauche avec les 6 sections
 *   - sa_render_foot()          : fermeture HTML + JS minimal
 *
 * Design : dark mode violet/indigo pour distinction claire avec l'app
 * classique (vert emeraude). Style Stripe/Linear minimaliste.
 *
 * Sections :
 *   dashboard     /super-admin
 *   associations  /super-admin/associations
 *   projets       /super-admin/projets
 *   abonnements   /super-admin/abonnements
 *   superadmins   /super-admin/super-admins
 *   logs          /super-admin/logs
 */

if (!defined('SA_LAYOUT_LOADED')) {
    define('SA_LAYOUT_LOADED', true);
}

/**
 * Garde-fou : n'importe quelle page /super-admin/* doit appeler ca
 * avant le rendu. Rejette si l'utilisateur n'est pas super admin.
 */
function sa_require_super_admin(): array {
    global $pdo;
    $user = current_user();

    // Fallback : si la colonne is_super_admin n'est pas chargee par current_user(),
    // on la recupere directement en BDD pour garantir que la detection marche.
    if ($user && !array_key_exists('is_super_admin', $user)) {
        try {
            $stmt = $pdo->prepare("SELECT is_super_admin FROM users WHERE id = ?");
            $stmt->execute([(int) $user['id']]);
            $user['is_super_admin'] = (int) $stmt->fetchColumn();
        } catch (Throwable $e) {
            $user['is_super_admin'] = 0;
        }
    }

    // Double casquette : role='super_admin' OU is_super_admin=1
    $is_sa = $user && (
        ($user['role'] ?? '') === 'super_admin'
        || !empty($user['is_super_admin'])
    );
    if (!$is_sa) {
        http_response_code(403);
        die('<!DOCTYPE html><html><body style="font-family:sans-serif;padding:40px;background:#0A0A0B;color:#fff;text-align:center"><h1>403 — Acces interdit</h1><p>Cette page est reservee au super administrateur de la plateforme Assokit.</p><a href="/connexion" style="color:#C4B5FD">Se connecter</a></body></html>');
    }
    return $user;
}

/**
 * Helper : detecte si un user est super admin (role ou double casquette)
 * Gere le cas ou la colonne is_super_admin n'est pas chargee par current_user().
 */
function is_super_admin_user(?array $user = null): bool {
    global $pdo;
    if ($user === null) {
        $user = function_exists('current_user') ? current_user() : null;
    }
    if (!$user) return false;

    // Si role='super_admin' pur, c'est bon direct
    if (($user['role'] ?? '') === 'super_admin') return true;

    // Sinon on verifie is_super_admin (chargee ou pas)
    if (array_key_exists('is_super_admin', $user)) {
        return !empty($user['is_super_admin']);
    }

    // Fallback BDD
    try {
        $stmt = $pdo->prepare("SELECT is_super_admin FROM users WHERE id = ?");
        $stmt->execute([(int) $user['id']]);
        return (int) $stmt->fetchColumn() === 1;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Helper : detecte si un user est FONDATEUR.
 * Meme logique defensive que is_super_admin_user (fallback BDD).
 */
function is_founder_user(?array $user = null): bool {
    global $pdo;
    if ($user === null) {
        $user = function_exists('current_user') ? current_user() : null;
    }
    if (!$user) return false;

    if (array_key_exists('is_founder', $user)) {
        return !empty($user['is_founder']);
    }

    try {
        $stmt = $pdo->prepare("SELECT is_founder FROM users WHERE id = ?");
        $stmt->execute([(int) $user['id']]);
        return (int) $stmt->fetchColumn() === 1;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Helper : recupere is_founder pour un user_id (pour les listes)
 */
function sa_get_founder_status(int $user_id): bool {
    global $pdo;
    try {
        $stmt = $pdo->prepare("SELECT is_founder FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        return (int) $stmt->fetchColumn() === 1;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Affiche le <head> + les styles CSS communs du Super Admin.
 */
function sa_render_head(string $page_title = 'Super Admin'): void {
    ?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= h($page_title) ?> — Assokit Super Admin</title>
<link rel="icon" type="image/svg+xml" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'%3E%3Crect x='2' y='2' width='28' height='28' rx='7' fill='%23059669'/%3E%3Ccircle cx='22' cy='22' r='4.5' fill='%23FFFFFF'/%3E%3C/svg%3E">
<link rel="apple-touch-icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 180 180'%3E%3Crect width='180' height='180' rx='40' fill='%23059669'/%3E%3Ccircle cx='125' cy='125' r='25' fill='%23FFFFFF'/%3E%3C/svg%3E">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Geist:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
*, *::before, *::after { box-sizing: border-box; }
html { -webkit-font-smoothing: antialiased; -moz-osx-font-smoothing: grayscale; }
body { margin: 0; padding: 0; font-family: 'Geist', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: #0A0A0B; color: #FAFAFA; font-size: 14px; line-height: 1.55; letter-spacing: -0.005em; }
a { color: inherit; text-decoration: none; }
button { font: inherit; cursor: pointer; border: none; background: none; color: inherit; }

/* ============ Variables ============ */
:root {
    --sa-bg: #0A0A0B;
    --sa-bg-2: #131316;
    --sa-bg-3: #1C1C1F;
    --sa-bg-hover: rgba(255,255,255,0.04);
    --sa-border: rgba(255,255,255,0.07);
    --sa-border-strong: rgba(255,255,255,0.12);
    --sa-ink: #FAFAFA;
    --sa-ink-2: #D4D4D8;
    --sa-ink-3: #A1A1AA;
    --sa-ink-4: #71717A;
    --sa-violet: #7F77DD;
    --sa-violet-light: rgba(127, 119, 221, 0.15);
    --sa-violet-dark: #5B52A6;
    --sa-green: #10B981;
    --sa-amber: #F59E0B;
    --sa-red: #EF4444;
    --sa-radius: 10px;
    --sa-radius-lg: 14px;
    --sa-sidebar-w: 240px;
}

/* ============ Structure ============ */
.sa-app { display: grid; grid-template-columns: var(--sa-sidebar-w) 1fr; min-height: 100vh; }

/* ============ Sidebar ============ */
.sa-sidebar {
    background: #0F0F12;
    border-right: 1px solid var(--sa-border);
    padding: 18px 14px;
    display: flex; flex-direction: column;
    position: sticky; top: 0; height: 100vh;
    overflow-y: auto;
}

.sa-brand {
    display: flex; align-items: center; gap: 11px;
    padding: 6px 10px 18px;
    border-bottom: 1px solid var(--sa-border);
    margin-bottom: 14px;
}
.sa-brand-icon {
    width: 32px; height: 32px;
    background: linear-gradient(135deg, #7F77DD 0%, #5B52A6 100%);
    border-radius: 8px;
    display: flex; align-items: center; justify-content: center;
    font-size: 16px;
    box-shadow: 0 2px 8px rgba(127, 119, 221, 0.3);
}
.sa-brand-text { display: flex; flex-direction: column; line-height: 1.1; }
.sa-brand-name { font-size: 14px; font-weight: 600; letter-spacing: -0.01em; }
.sa-brand-sub { font-size: 11px; color: var(--sa-ink-3); }

.sa-nav { display: flex; flex-direction: column; gap: 2px; flex: 1; }

.sa-nav-section {
    font-size: 10.5px;
    color: var(--sa-ink-4);
    text-transform: uppercase;
    letter-spacing: 0.08em;
    font-weight: 600;
    padding: 14px 10px 6px;
}

.sa-link {
    display: flex; align-items: center; gap: 10px;
    padding: 9px 10px;
    border-radius: 8px;
    font-size: 13.5px;
    color: var(--sa-ink-2);
    transition: background 0.15s, color 0.15s;
}
.sa-link:hover { background: var(--sa-bg-hover); color: var(--sa-ink); }
.sa-link.active { background: var(--sa-violet-light); color: #C4B5FD; font-weight: 500; }
.sa-link-icon { font-size: 15px; width: 18px; text-align: center; }
.sa-link-badge {
    margin-left: auto;
    background: var(--sa-bg-3);
    color: var(--sa-ink-3);
    font-size: 10.5px;
    padding: 1px 7px;
    border-radius: 999px;
    font-weight: 500;
}
.sa-link.active .sa-link-badge { background: rgba(127,119,221,0.3); color: #C4B5FD; }

/* ============ Menu Déroulant Fondateur ============ */
.sa-dropdown {
    margin-top: 6px;
}
.sa-dropdown-trigger {
    display: flex; align-items: center; gap: 10px;
    padding: 10px 12px;
    border-radius: 10px;
    font-size: 13px;
    font-weight: 600;
    color: #FCD34D;
    background: linear-gradient(135deg, rgba(252,211,77,0.10) 0%, rgba(245,158,11,0.05) 100%);
    border: 1px solid rgba(252,211,77,0.25);
    cursor: pointer;
    transition: all 0.2s;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    list-style: none;
    user-select: none;
}
.sa-dropdown-trigger::-webkit-details-marker { display: none; }
.sa-dropdown-trigger::marker { content: ''; }
.sa-dropdown-trigger:hover {
    background: linear-gradient(135deg, rgba(252,211,77,0.18) 0%, rgba(245,158,11,0.10) 100%);
    border-color: rgba(252,211,77,0.45);
}
.sa-dropdown[open] .sa-dropdown-trigger {
    background: linear-gradient(135deg, rgba(252,211,77,0.22) 0%, rgba(245,158,11,0.12) 100%);
    border-color: rgba(252,211,77,0.55);
}
.sa-dropdown-icon {
    font-size: 14px;
    width: 18px;
    text-align: center;
}
.sa-dropdown-arrow {
    margin-left: auto;
    font-size: 11px;
    transition: transform 0.25s;
    opacity: 0.7;
}
.sa-dropdown[open] .sa-dropdown-arrow {
    transform: rotate(180deg);
}
.sa-dropdown-content {
    margin-top: 4px;
    padding: 4px 0 4px 8px;
    border-left: 2px solid rgba(252,211,77,0.20);
    margin-left: 14px;
    display: flex;
    flex-direction: column;
    gap: 1px;
    animation: sa-dropdown-fade 0.2s ease-out;
}
@keyframes sa-dropdown-fade {
    from { opacity: 0; transform: translateY(-4px); }
    to { opacity: 1; transform: translateY(0); }
}
.sa-dropdown-content .sa-link {
    padding: 8px 10px;
    font-size: 13px;
    color: var(--sa-ink-3);
}
.sa-dropdown-content .sa-link:hover {
    color: #FCD34D;
    background: rgba(252,211,77,0.08);
}
.sa-dropdown-content .sa-link.active {
    background: rgba(252,211,77,0.15);
    color: #FCD34D;
    font-weight: 500;
}

/* ============ Sidebar foot (user) ============ */
.sa-user {
    margin-top: auto;
    padding-top: 14px;
    border-top: 1px solid var(--sa-border);
    display: flex; align-items: center; gap: 10px;
}
.sa-user-avatar {
    width: 32px; height: 32px;
    border-radius: 8px;
    background: linear-gradient(135deg, #7F77DD 0%, #5B52A6 100%);
    display: flex; align-items: center; justify-content: center;
    font-size: 12px; font-weight: 600;
    color: white;
    flex-shrink: 0;
}
.sa-user-body { flex: 1; min-width: 0; }
.sa-user-name { font-size: 12.5px; font-weight: 500; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.sa-user-email { font-size: 11px; color: var(--sa-ink-3); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.sa-user-logout {
    color: var(--sa-ink-3);
    padding: 6px;
    border-radius: 6px;
    flex-shrink: 0;
}
.sa-user-logout:hover { background: var(--sa-bg-hover); color: var(--sa-ink); }

/* ============ Main ============ */
.sa-main { padding: 28px 36px 56px; max-width: 1400px; width: 100%; min-width: 0; }

.sa-page-head { display: flex; justify-content: space-between; align-items: flex-end; margin-bottom: 26px; gap: 16px; flex-wrap: wrap; }
.sa-page-title { font-size: 26px; font-weight: 500; letter-spacing: -0.03em; line-height: 1.1; margin: 0 0 4px; }
.sa-page-sub { font-size: 13px; color: var(--sa-ink-3); }

.sa-page-actions { display: flex; gap: 8px; flex-wrap: wrap; }

/* ============ Buttons ============ */
.sa-btn { display: inline-flex; align-items: center; gap: 7px; font-size: 13px; font-weight: 500; padding: 9px 16px; border-radius: 9px; transition: all 0.15s; border: 1px solid transparent; cursor: pointer; }
.sa-btn-primary { background: #FAFAFA; color: #0A0A0B; }
.sa-btn-primary:hover { opacity: 0.9; transform: translateY(-1px); }
.sa-btn-violet { background: var(--sa-violet); color: white; }
.sa-btn-violet:hover { background: var(--sa-violet-dark); transform: translateY(-1px); }
.sa-btn-ghost { background: var(--sa-bg-hover); color: var(--sa-ink); border-color: var(--sa-border-strong); }
.sa-btn-ghost:hover { background: rgba(255,255,255,0.08); }
.sa-btn-danger { background: rgba(239, 68, 68, 0.12); color: #FCA5A5; border-color: rgba(239, 68, 68, 0.3); }
.sa-btn-danger:hover { background: rgba(239, 68, 68, 0.2); }
.sa-btn-sm { padding: 5px 11px; font-size: 12px; }

/* ============ Cards ============ */
.sa-card { background: var(--sa-bg-2); border: 1px solid var(--sa-border); border-radius: var(--sa-radius-lg); padding: 22px; }
.sa-card-title { font-size: 14px; font-weight: 600; margin: 0 0 4px; display: flex; align-items: center; gap: 8px; }
.sa-card-sub { font-size: 12.5px; color: var(--sa-ink-3); margin-bottom: 16px; }

/* ============ Stats / KPI ============ */
.sa-kpi-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 12px; margin-bottom: 28px; }

/* === Grids du dashboard Fondateur === */
.sa-comm-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
.sa-period-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px; margin-top: 10px; }
.sa-cards-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 12px; }
.sa-quick-actions-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 12px; }
.sa-kpi { background: var(--sa-bg-2); border: 1px solid var(--sa-border); border-radius: var(--sa-radius-lg); padding: 18px; position: relative; overflow: hidden; }
.sa-kpi::before { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 2px; background: var(--sa-violet); opacity: 0.6; }
.sa-kpi-label { font-size: 11.5px; color: var(--sa-ink-3); text-transform: uppercase; letter-spacing: 0.06em; margin-bottom: 8px; }
.sa-kpi-value { font-size: 28px; font-weight: 600; letter-spacing: -0.03em; line-height: 1.1; }
.sa-kpi-trend { font-size: 12px; color: var(--sa-ink-3); margin-top: 4px; display: flex; align-items: center; gap: 4px; }
.sa-kpi-trend.up { color: var(--sa-green); }
.sa-kpi-trend.down { color: var(--sa-red); }

/* ============ Tables ============ */
.sa-table-wrap { background: var(--sa-bg-2); border: 1px solid var(--sa-border); border-radius: var(--sa-radius-lg); overflow: hidden; }
.sa-table { width: 100%; border-collapse: collapse; }
.sa-table thead { background: rgba(255,255,255,0.02); }
.sa-table th { text-align: left; padding: 12px 16px; font-size: 11px; font-weight: 500; color: var(--sa-ink-3); text-transform: uppercase; letter-spacing: 0.06em; border-bottom: 1px solid var(--sa-border); }
.sa-table td { padding: 14px 16px; font-size: 13.5px; border-bottom: 1px solid var(--sa-border); vertical-align: middle; }
.sa-table tr:last-child td { border-bottom: none; }
.sa-table tbody tr:hover td { background: rgba(255,255,255,0.02); }

.sa-main-col { font-weight: 500; color: var(--sa-ink); }
.sa-sub-col { font-size: 12px; color: var(--sa-ink-4); margin-top: 2px; }

/* ============ Badges ============ */
.sa-badge { display: inline-block; padding: 2px 10px; font-size: 11px; font-weight: 500; border-radius: 999px; }
.sa-badge-green { background: rgba(16, 185, 129, 0.15); color: #6EE7B7; }
.sa-badge-violet { background: rgba(127, 119, 221, 0.18); color: #C4B5FD; }
.sa-badge-amber { background: rgba(245, 158, 11, 0.15); color: #FCD34D; }
.sa-badge-red { background: rgba(239, 68, 68, 0.15); color: #FCA5A5; }
.sa-badge-gray { background: rgba(161, 161, 170, 0.15); color: #D4D4D8; }
.sa-badge-gold { background: linear-gradient(135deg, rgba(251, 191, 36, 0.2) 0%, rgba(245, 158, 11, 0.25) 100%); color: #FCD34D; border: 1px solid rgba(251, 191, 36, 0.35); font-weight: 600; letter-spacing: 0.03em; box-shadow: 0 1px 4px rgba(251, 191, 36, 0.15); }

/* ============ Alerts ============ */
.sa-alert { padding: 14px 16px; border-radius: var(--sa-radius); margin-bottom: 18px; font-size: 13.5px; display: flex; gap: 10px; align-items: flex-start; }
.sa-alert-error { background: rgba(239, 68, 68, 0.1); border: 1px solid rgba(239, 68, 68, 0.3); color: #FCA5A5; }
.sa-alert-success { background: rgba(16, 185, 129, 0.1); border: 1px solid rgba(16, 185, 129, 0.3); color: #6EE7B7; }
.sa-alert-info { background: var(--sa-violet-light); border: 1px solid rgba(127, 119, 221, 0.3); color: #C4B5FD; }

/* ============ Forms ============ */
.sa-group { margin-bottom: 14px; }
.sa-group label { display: block; font-size: 12.5px; font-weight: 500; margin-bottom: 5px; color: var(--sa-ink-2); }
.sa-group label .req { color: #FCA5A5; }
.sa-group input, .sa-group textarea, .sa-group select {
    width: 100%; padding: 9px 12px;
    border: 1px solid var(--sa-border-strong);
    border-radius: 8px;
    background: var(--sa-bg); color: var(--sa-ink);
    font-family: inherit; font-size: 13px;
}
.sa-group input:focus, .sa-group textarea:focus, .sa-group select:focus {
    outline: none; border-color: var(--sa-violet);
}
.sa-group textarea { resize: vertical; min-height: 70px; line-height: 1.45; }
.sa-row-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
.sa-row-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 12px; }

/* ============ Empty state ============ */
.sa-empty { padding: 60px 20px; text-align: center; color: var(--sa-ink-4); }
.sa-empty-icon { font-size: 48px; opacity: 0.35; margin-bottom: 10px; }
.sa-empty-title { font-size: 16px; color: var(--sa-ink-2); margin-bottom: 6px; font-weight: 500; }

/* ============ Breadcrumb ============ */
.sa-breadcrumb { font-size: 12.5px; color: var(--sa-ink-4); margin-bottom: 16px; }
.sa-breadcrumb a { color: var(--sa-ink-3); }
.sa-breadcrumb a:hover { color: var(--sa-ink); }
.sa-breadcrumb .sep { margin: 0 8px; color: var(--sa-ink-4); }

/* ============ Mobile burger ============ */
.sa-mobile-header {
    display: none;
    position: sticky; top: 0; z-index: 50;
    background: #0F0F12;
    border-bottom: 1px solid var(--sa-border);
    padding: 10px 14px;
    align-items: center; gap: 12px;
}
.sa-burger {
    width: 38px; height: 38px;
    border-radius: 8px;
    display: inline-flex; align-items: center; justify-content: center;
    background: rgba(255,255,255,0.06); color: var(--sa-ink);
    cursor: pointer; transition: background 0.15s;
    flex-shrink: 0;
    border: 1px solid var(--sa-border);
}
.sa-burger:hover { background: rgba(255,255,255,0.10); }
.sa-mobile-title {
    display: flex; align-items: center; gap: 10px;
    font-weight: 600; font-size: 15px; color: var(--sa-ink);
}
.sa-mobile-title-icon {
    width: 26px; height: 26px;
    background: linear-gradient(135deg, #7F77DD 0%, #5B52A6 100%);
    border-radius: 7px;
    display: inline-flex; align-items: center; justify-content: center;
    font-size: 13px;
    box-shadow: 0 2px 8px rgba(127, 119, 221, 0.3);
}
.sa-overlay {
    display: none;
    position: fixed; top: 0; left: 0; right: 0; bottom: 0;
    background: rgba(0,0,0,0.6);
    z-index: 99;
}
.sa-overlay.active { display: block; }

/* ============ Responsive ============ */
@media (max-width: 900px) {
    .sa-app { grid-template-columns: 1fr; }
    .sa-sidebar {
        position: fixed; top: 0; left: -100%;
        width: 280px; height: 100vh;
        z-index: 100;
        transition: left 0.25s ease;
        box-shadow: 2px 0 24px rgba(0,0,0,0.4);
        border-right: 1px solid var(--sa-border);
    }
    .sa-sidebar.open { left: 0; }
    .sa-mobile-header { display: flex; }
    .sa-main { padding: 16px 14px 40px !important; min-width: 0; max-width: 100%; overflow-x: hidden; }

    /* Force ALL grids à 1 colonne — y compris les styles inline */
    .sa-row-2, .sa-row-3, .sa-kpi-grid,
    .sa-comm-grid, .sa-cards-grid, .sa-quick-actions-grid {
        grid-template-columns: 1fr !important;
    }
    .sa-period-grid { grid-template-columns: repeat(2, 1fr) !important; }
    .sa-main [style*="grid-template-columns"] { grid-template-columns: 1fr !important; }
    .sa-main [style*="grid-template-columns: 1fr 1fr"],
    .sa-main [style*="grid-template-columns:1fr 1fr"] { grid-template-columns: 1fr !important; }

    /* Force toutes les cartes/contenus à respecter la largeur */
    .sa-card, .sa-kpi, .sa-table-wrap {
        max-width: 100%;
        min-width: 0;
        word-wrap: break-word;
        overflow-wrap: break-word;
    }
    .sa-card { padding: 16px !important; }
    .sa-kpi { padding: 14px !important; }

    /* Tables : scroll horizontal interne */
    .sa-table-wrap { overflow-x: auto; -webkit-overflow-scrolling: touch; }
    .sa-table { min-width: 500px; }
    .sa-table th, .sa-table td { padding: 10px 12px !important; font-size: 12px !important; white-space: nowrap; }

    /* KPIs plus compacts */
    .sa-kpi-value { font-size: 22px !important; }
    .sa-kpi-label { font-size: 10.5px !important; }

    /* Forcer toutes les images à respecter largeur */
    .sa-main img { max-width: 100%; height: auto; }

    /* Empêche tout débordement global */
    body, html { overflow-x: hidden; max-width: 100vw; }
    .sa-app { overflow-x: hidden; max-width: 100vw; }
    .sa-main * { max-width: 100%; box-sizing: border-box; }
}
@media (max-width: 700px) {
    /* Force grids 4 colonnes (semaine/mois/trimestre/année) en 2 colonnes */
    .sa-main [style*="repeat(4"] { grid-template-columns: repeat(2, 1fr) !important; }
    .sa-main [style*="repeat(3"] { grid-template-columns: repeat(2, 1fr) !important; }
    .sa-main [style*="minmax(320px"] { grid-template-columns: 1fr !important; }
    .sa-main [style*="minmax(250px"] { grid-template-columns: 1fr 1fr !important; }
    .sa-main [style*="minmax(200px"] { grid-template-columns: 1fr 1fr !important; }
}
@media (max-width: 540px) {
    .sa-main { padding: 14px 10px 40px !important; }
    .sa-card { padding: 14px !important; }
    .sa-kpi-value { font-size: 20px !important; }
    h1, .sa-page-title { font-size: 22px !important; word-break: break-word; }
    h2 { font-size: 17px !important; }

    /* En très petit écran, force tout à 1 colonne */
    .sa-main [style*="repeat(4"],
    .sa-main [style*="repeat(3"],
    .sa-main [style*="repeat(2"],
    .sa-main [style*="minmax(250px"],
    .sa-main [style*="minmax(200px"] { grid-template-columns: 1fr !important; }
}

/* =======================================================================
   PREMIUM UPGRADE — harmonisation avec le cockpit Fondateur
   (raffine les composants .sa-* partagés par toutes les pages super-admin)
   ======================================================================= */
.sa-page-title { font-weight: 750; letter-spacing: -0.03em; }
.sa-page-sub { font-size: 13.5px; color: var(--sa-ink-3); }
.sa-page-head { margin-bottom: 22px; }

/* Panneaux : léger reflet + ombre douce */
.sa-card, .sa-kpi, .sa-table-wrap {
    background:
        linear-gradient(180deg, rgba(255,255,255,.030), rgba(255,255,255,0) 60%),
        var(--sa-bg-2);
    border: 1px solid rgba(255,255,255,.085);
    border-radius: 16px;
    box-shadow: 0 1px 2px rgba(0,0,0,.3), 0 20px 44px -24px rgba(0,0,0,.75);
}

/* KPI */
.sa-kpi { padding: 18px 20px; }
.sa-kpi::before { height: 3px; opacity: 1; background: linear-gradient(90deg, #A78BFA, #7F77DD); }
.sa-kpi-label { font-size: 11px; font-weight: 700; letter-spacing: .06em; }
.sa-kpi-value { font-size: 30px; font-weight: 800; letter-spacing: -0.035em; font-variant-numeric: tabular-nums; }

/* Cartes */
.sa-card-title { font-weight: 700; letter-spacing: -0.01em; }

/* Tableaux */
.sa-table thead { background: transparent; }
.sa-table th { font-size: 10.5px; font-weight: 700; letter-spacing: .05em; color: var(--sa-ink-4); padding: 12px 16px; }
.sa-table td { font-size: 13px; border-bottom-color: var(--sa-border); }
.sa-table tbody tr { transition: background .12s ease; }
.sa-table tbody tr:hover td { background: rgba(255,255,255,.028); }
.sa-main-col { font-weight: 650; }

/* Badges */
.sa-badge { padding: 3px 10px; font-weight: 700; font-size: 11px; letter-spacing: .01em; }

/* Boutons */
.sa-btn { border-radius: 10px; font-weight: 600; }
.sa-btn-violet { box-shadow: 0 10px 22px -10px rgba(127,119,221,.65), inset 0 1px 0 rgba(255,255,255,.18); }
.sa-btn-violet:hover { transform: translateY(-1px); }
.sa-btn-primary:hover { transform: translateY(-1px); }

/* Champs de formulaire / recherche */
.sa-group input, .sa-group textarea, .sa-group select {
    border-radius: 10px; padding: 11px 13px; font-size: 13.5px;
    background: rgba(255,255,255,.03); border-color: rgba(255,255,255,.10);
}
.sa-group input:focus, .sa-group textarea:focus, .sa-group select:focus {
    box-shadow: 0 0 0 3px rgba(127,119,221,.18);
}

/* Alertes */
.sa-alert { border-radius: 12px; }

/* Empty state un peu plus premium */
.sa-empty-title { color: var(--sa-ink-2); }
</style>
</head>
<body>
<?php
}

/**
 * Sidebar avec les 6 sections. $active = clef pour highlight.
 */
function sa_render_sidebar(string $active = 'dashboard'): void {
    global $pdo;
    $user = current_user();
    $initials = strtoupper(mb_substr($user['first_name'] ?? 'S', 0, 1) . mb_substr($user['last_name'] ?? 'A', 0, 1));

    // Detection du role
    $is_founder = function_exists('is_founder_user') ? is_founder_user($user) : false;

    // Badges dynamiques
    $nb_orgs = (int) $pdo->query("SELECT COUNT(*) FROM organizations")->fetchColumn();
    $nb_unpaid = 0;
    try {
        $nb_unpaid = (int) $pdo->query("SELECT COUNT(*) FROM subscription_invoices WHERE status IN ('sent','overdue')")->fetchColumn();
    } catch (Throwable $e) {}

    $nb_pending = 0;
    try {
        $nb_pending = (int) $pdo->query("SELECT COUNT(*) FROM organizations WHERE validation_status = 'pending_founder'")->fetchColumn();
    } catch (Throwable $e) {}

    $nb_notifs = 0;
    if ($is_founder) {
        try {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM platform_notifications WHERE recipient_user_id = ? AND is_read = 0");
            $stmt->execute([(int) $user['id']]);
            $nb_notifs = (int) $stmt->fetchColumn();
        } catch (Throwable $e) {}
    }

    // Support : tickets non assignes + messages non lus
    $support_nb_pool = 0;
    $support_nb_unread = 0;
    try {
        $support_nb_pool = (int) $pdo->query("
            SELECT COUNT(*) FROM support_tickets
            WHERE assigned_to_user_id IS NULL
              AND status IN ('open','in_progress','waiting_user')
        ")->fetchColumn();

        $stmt = $pdo->prepare("
            SELECT COUNT(DISTINCT t.id)
            FROM support_tickets t
            JOIN support_messages m ON m.ticket_id = t.id
            WHERE m.author_side = 'org' AND m.read_by_support = 0 AND m.is_internal_note = 0
        ");
        $stmt->execute();
        $support_nb_unread = (int) $stmt->fetchColumn();
    } catch (Throwable $e) {}
    $support_badge_total = max($support_nb_pool, $support_nb_unread);
    ?>
<!-- Header mobile (visible <900px uniquement) -->
<header class="sa-mobile-header">
    <button type="button" class="sa-burger" onclick="document.getElementById('sa-sidebar').classList.toggle('open'); document.getElementById('sa-overlay').classList.toggle('active');" aria-label="Menu">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
    </button>
    <div class="sa-mobile-title">
        <span class="sa-mobile-title-icon" style="<?= $is_founder ? 'background: linear-gradient(135deg, #FCD34D 0%, #F59E0B 100%);' : '' ?>"><?= $is_founder ? '🏗️' : '👑' ?></span>
        <span><?= $is_founder ? 'Mode Fondateur' : 'Cockpit SA' ?></span>
    </div>
</header>

<div class="sa-overlay" id="sa-overlay" onclick="document.getElementById('sa-sidebar').classList.remove('open'); this.classList.remove('active');"></div>

<div class="sa-app">
  <aside class="sa-sidebar" id="sa-sidebar">

    <div class="sa-brand">
      <div class="sa-brand-icon" style="<?= $is_founder ? 'background: linear-gradient(135deg, #FCD34D 0%, #F59E0B 100%);' : '' ?>"><?= $is_founder ? '🏗️' : '👑' ?></div>
      <div class="sa-brand-text">
        <div class="sa-brand-name">Assokit</div>
        <div class="sa-brand-sub"><?= $is_founder ? 'Fondateur' : 'Super Admin' ?></div>
      </div>
    </div>

    <nav class="sa-nav">

      <div class="sa-nav-section">Pilotage</div>

      <a href="/super-admin" class="sa-link <?= $active === 'dashboard' ? 'active' : '' ?>">
        <span class="sa-link-icon">📊</span> Dashboard
      </a>

      <?php if ($is_founder): ?>
        <a href="/super-admin/notifications" class="sa-link <?= $active === 'notifications' ? 'active' : '' ?>">
          <span class="sa-link-icon">🔔</span> Notifications
          <?php if ($nb_notifs > 0): ?>
            <span class="sa-link-badge" style="background:rgba(239,68,68,0.25);color:#FCA5A5"><?= $nb_notifs ?></span>
          <?php endif; ?>
        </a>

        <a href="/super-admin/stats" class="sa-link <?= $active === 'stats' ? 'active' : '' ?>">
          <span class="sa-link-icon">📈</span> Stats approfondies
        </a>
      <?php endif; ?>

      <a href="/super-admin/support" class="sa-link <?= $active === 'support' ? 'active' : '' ?>">
        <span class="sa-link-icon">💬</span> Support
        <?php if ($support_badge_total > 0): ?>
          <span class="sa-link-badge" style="background:rgba(239,68,68,0.25);color:#FCA5A5"><?= $support_badge_total ?></span>
        <?php endif; ?>
      </a>

      <a href="/super-admin/associations" class="sa-link <?= $active === 'associations' ? 'active' : '' ?>">
        <span class="sa-link-icon">🏛️</span> Associations
        <?php if ($is_founder && $nb_pending > 0): ?>
          <span class="sa-link-badge" style="background:rgba(251,191,36,0.25);color:#FCD34D"><?= $nb_pending ?></span>
        <?php else: ?>
          <span class="sa-link-badge"><?= $nb_orgs ?></span>
        <?php endif; ?>
      </a>

      <?php if ($is_founder): ?>
        <a href="/super-admin/projets" class="sa-link <?= $active === 'projets' ? 'active' : '' ?>">
          <span class="sa-link-icon">📁</span> Projets
        </a>
      <?php endif; ?>

      <a href="/super-admin/abonnements" class="sa-link <?= $active === 'abonnements' ? 'active' : '' ?>">
        <span class="sa-link-icon">💳</span> Abonnements
        <?php if ($nb_unpaid > 0): ?>
          <span class="sa-link-badge" style="background:rgba(239,68,68,0.2);color:#FCA5A5"><?= $nb_unpaid ?></span>
        <?php endif; ?>
      </a>

      <?php // === [PACK 6.4] Section Pilotage Fondateur — MENU DÉROULANT === ?>
      <?php if ($is_founder): ?>
      <?php
        // Détection auto : si la page active fait partie du menu, on ouvre le dropdown
        $founder_pages = ['fondateur-plans', 'fondateur-create-organization', 'fondateur-domains', 'fondateur-stripe-config', 'admin-blog', 'fondateur-activity'];
        $founder_open = in_array($active, $founder_pages, true);
      ?>
      <details class="sa-dropdown" <?= $founder_open ? 'open' : '' ?>>
        <summary class="sa-dropdown-trigger">
          <span class="sa-dropdown-icon">⭐</span>
          <span>Pilotage Fondateur</span>
          <span class="sa-dropdown-arrow">▼</span>
        </summary>
        <div class="sa-dropdown-content">
          <a href="/fondateur-pricing" class="sa-link <?= $active === 'fondateur-pricing' ? 'active' : '' ?>">
            <span style="font-size:14px;">💶</span> Tarifs &amp; TVA
          </a>
          <a href="/fondateur-plans" class="sa-link <?= $active === 'fondateur-plans' ? 'active' : '' ?>">
            <span class="sa-link-icon">💼</span> Plans tarifaires
          </a>
          <a href="/fondateur-create-organization" class="sa-link <?= $active === 'fondateur-create-organization' ? 'active' : '' ?>">
            <span class="sa-link-icon">🌱</span> Créer un compte
          </a>
          <a href="/fondateur-domains" class="sa-link <?= $active === 'fondateur-domains' ? 'active' : '' ?>">
            <span class="sa-link-icon">🌐</span> Domaines
          </a>
          <a href="/fondateur-stripe-config" class="sa-link <?= $active === 'fondateur-stripe-config' ? 'active' : '' ?>">
            <span class="sa-link-icon">💳</span> Config Stripe
          </a>
          <a href="/admin-blog/" class="sa-link <?= $active === 'admin-blog' ? 'active' : '' ?>" target="_blank" rel="noopener">
            <span class="sa-link-icon">✍️</span> Blog SEO
            <span style="margin-left:auto; font-size:10px; opacity:0.5;">↗</span>
          </a>
          <a href="/fondateur-activity.php" class="sa-link <?= $active === 'fondateur-activity' ? 'active' : '' ?>">
            <span class="sa-link-icon">🕵️</span> Activité utilisateurs
          </a>
        </div>
      </details>
      <?php endif; ?>

      <div class="sa-nav-section">Administration</div>

      <?php if ($is_founder): ?>
        <a href="/super-admin/super-admins" class="sa-link <?= $active === 'superadmins' ? 'active' : '' ?>">
          <span class="sa-link-icon">👑</span> Super admins
        </a>
      <?php endif; ?>

      <a href="/super-admin/logs" class="sa-link <?= $active === 'logs' ? 'active' : '' ?>">
        <span class="sa-link-icon">📜</span> Historique
      </a>

      <div class="sa-nav-section">Retour</div>

      <a href="/dashboard" class="sa-link" style="color:var(--sa-ink-4);">
        <span class="sa-link-icon">↩</span> Mode asso
      </a>
    </nav>

    <div class="sa-user">
      <div class="sa-user-avatar" style="<?= $is_founder ? 'background: linear-gradient(135deg, #FCD34D 0%, #F59E0B 100%); color:#78350F;' : '' ?>"><?= h($initials) ?></div>
      <div class="sa-user-body">
        <div class="sa-user-name">
            <?= h($user['first_name'] . ' ' . $user['last_name']) ?>
            <?php if ($is_founder): ?>
                <span style="display:inline-block; background:linear-gradient(135deg, #FCD34D 0%, #F59E0B 100%); color:#78350F; font-size:8px; padding:1px 5px; border-radius:4px; letter-spacing:0.05em; font-weight:700; vertical-align:middle;">FONDATEUR</span>
            <?php endif; ?>
        </div>
        <div class="sa-user-email"><?= h($user['email']) ?></div>
      </div>
      <a href="/deconnexion.php" class="sa-user-logout" title="Deconnexion">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
      </a>
    </div>

  </aside>

  <main class="sa-main">
    <?php
}

/**
 * Ferme le HTML.
 */
function sa_render_foot(): void {
    ?>
  </main>
</div>
</body>
</html>
    <?php
}

/**
 * Helper : log d'une action super admin dans platform_activity_log
 */
function sa_log_action(int $super_admin_id, string $action, ?int $target_org_id = null, ?int $target_user_id = null, array $details = []): void {
    global $pdo;
    try {
        $stmt = $pdo->prepare("
            INSERT INTO platform_activity_log
                (super_admin_id, action, target_org_id, target_user_id, details_json, ip_address, user_agent, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([
            $super_admin_id,
            $action,
            $target_org_id,
            $target_user_id,
            $details ? json_encode($details, JSON_UNESCAPED_UNICODE) : null,
            $_SERVER['REMOTE_ADDR'] ?? null,
            substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
        ]);
    } catch (Throwable $e) {
        // Silencieux
    }
}

/**
 * Helper : genere un mot de passe temporaire lisible.
 */
function sa_generate_temp_password(): string {
    $words = ['Alpha','Bravo','Delta','Echo','Nova','Oscar','Papa','Tango','Victor','Zulu','Soleil','Lune','Terre','Mars','Venus','Azur','Emeraude','Saphir','Rubis','Jade','Onyx','Ivoire','Opale'];
    return $words[array_rand($words)] . '-' . $words[array_rand($words)] . '-' . random_int(1000, 9999);
}
