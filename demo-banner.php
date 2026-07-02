<?php
/**
 * demo-banner.php v2 — Bandeau "MODE DÉMO" avec bouton fonctionnel
 * 
 * Le bouton "Changer d'asso" pointe vers /demo-selector.php?back=1
 * qui restaure le compte demo@assokit.fr avant d'afficher le sélecteur.
 */

if (!function_exists('render_demo_banner')) {

function render_demo_banner(): void {
    if (empty($_SESSION['demo_active'])) return;
    
    $org_name = $_SESSION['demo_org_name'] ?? 'Organisation DEMO';
    ?>
    <div id="demo-banner" style="
        position: sticky; top: 0; z-index: 9999;
        background: linear-gradient(90deg, #f59e0b, #ef4444);
        color: white; padding: 8px 16px;
        font-size: 13px; font-weight: 600;
        display: flex; align-items: center; justify-content: space-between;
        gap: 12px; flex-wrap: wrap;
        box-shadow: 0 2px 8px rgba(0,0,0,0.15);
    ">
        <div style="display: flex; align-items: center; gap: 10px; min-width: 0;">
            <span style="
                background: rgba(255,255,255,0.25);
                padding: 3px 10px; border-radius: 999px;
                font-size: 11px; letter-spacing: 0.05em;
                text-transform: uppercase; flex-shrink: 0;
            ">🎬 MODE DÉMO</span>
            <span style="overflow: hidden; text-overflow: ellipsis;">Vous présentez <strong><?= htmlspecialchars($org_name, ENT_QUOTES, 'UTF-8') ?></strong> · données fictives, reset chaque nuit</span>
        </div>
        <div style="display: flex; gap: 8px; flex-shrink: 0;">
            <a href="/demo-selector.php?back=1" style="
                padding: 6px 14px;
                background: rgba(255,255,255,0.25);
                border: 1px solid rgba(255,255,255,0.5);
                color: white; text-decoration: none;
                border-radius: 6px; font-size: 12px; font-weight: 700;
                transition: background 0.15s;
                white-space: nowrap;
            " onmouseover="this.style.background='rgba(255,255,255,0.4)'" 
               onmouseout="this.style.background='rgba(255,255,255,0.25)'">
                🔄 Changer d'asso
            </a>
            <a href="/deconnexion.php" style="
                padding: 6px 14px;
                background: rgba(0,0,0,0.25);
                border: 1px solid rgba(255,255,255,0.4);
                color: white; text-decoration: none;
                border-radius: 6px; font-size: 12px; font-weight: 600;
                white-space: nowrap;
            ">
                Quitter
            </a>
        </div>
    </div>
    <?php
}

}
