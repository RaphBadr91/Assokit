<?php
/**
 * ============================================================
 * SNIPPET — Bannière timer SA 30 min + bouton déconnexion
 * ============================================================
 * À inclure dans superadmin-layout.php, fonction sa_render_head()
 * Juste après l'ouverture du <body>.
 *
 * Ou copie-colle le HTML directement là où tu veux l'afficher.
 * ============================================================
 */

if (!function_exists('sa_auth_time_left')) {
    require_once __DIR__ . '/sa-auth-helpers.php';
}

$_sa_time_left = sa_auth_time_left();
if ($_sa_time_left <= 0) return; // Pas de bannière si pas de session active

?>

<!-- ========== DÉBUT BANNIÈRE TIMER SA ========== -->
<div id="sa-session-banner" style="
    position: fixed;
    top: 0; left: 0; right: 0;
    z-index: 9998;
    background: linear-gradient(90deg, rgba(127, 119, 221, 0.95) 0%, rgba(252, 211, 77, 0.95) 100%);
    backdrop-filter: blur(10px);
    color: #0F0E1A;
    padding: 8px 24px;
    font-size: 12px;
    font-weight: 600;
    font-family: 'Geist', -apple-system, system-ui, sans-serif;
    letter-spacing: 0.02em;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 14px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.15);
">
    <div style="display:flex; align-items:center; gap:10px;">
        <span>🛡 <strong>Cockpit Fondateur</strong> — session sécurisée</span>
    </div>
    <div style="display:flex; align-items:center; gap:14px;">
        <span style="
            background: rgba(15, 14, 26, 0.2);
            padding: 3px 10px;
            border-radius: 999px;
            font-family: 'SF Mono', Monaco, Consolas, monospace;
            font-weight: 700;
        ">
            ⏱ <span id="sa-timer-display" data-seconds="<?= (int)$_sa_time_left ?>"></span>
        </span>
        <a href="/super-admin-logout" style="
            color: #0F0E1A;
            text-decoration: underline;
            font-weight: 700;
            opacity: 0.85;
        " onmouseover="this.style.opacity=1" onmouseout="this.style.opacity=0.85">
            Déconnexion
        </a>
    </div>
</div>

<style>
/* Décale tout le body pour que la bannière ne recouvre pas le contenu */
body { padding-top: 36px !important; }
</style>

<script>
(function() {
    var el = document.getElementById('sa-timer-display');
    if (!el) return;
    var seconds = parseInt(el.dataset.seconds || 0, 10);

    function tick() {
        if (seconds <= 0) {
            window.location.href = '/super-admin-login?expired=1';
            return;
        }
        var mm = Math.floor(seconds / 60);
        var ss = seconds % 60;
        el.textContent = (mm < 10 ? '0' : '') + mm + ':' + (ss < 10 ? '0' : '') + ss;
        seconds--;
    }
    tick();
    setInterval(tick, 1000);
})();
</script>
<!-- ========== FIN BANNIÈRE TIMER SA ========== -->
