<?php
/**
 * ============================================================
 * ASSOKIT — impersonation-banner.php
 * Bannière orange fixe affichée sur CHAQUE page en incarnation
 * ============================================================
 * Inclus automatiquement par includes-layout.php (voir patch)
 * ============================================================
 */

if (!is_impersonating()) return;

$realUser = get_real_user();
$targetId = get_impersonated_user_id();
$sessionId = get_impersonation_session_id();

// Récup des infos du user cible
global $pdo;
$target = null;
try {
    $stmt = $pdo->prepare("SELECT id, first_name, last_name, email, role FROM users WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $targetId]);
    $target = $stmt->fetch();
} catch (Throwable $e) {}

// Calcul du temps restant
$startedAt = (int) ($_SESSION['impersonation_started_at'] ?? 0);
$elapsed = time() - $startedAt;
$remaining = max(0, IMPERSONATION_MAX_DURATION - $elapsed);
?>

<!-- ========== BANNIÈRE INCARNATION — Z-INDEX MAX ========== -->
<div id="imp-banner" style="
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    z-index: 2147483647;
    background: linear-gradient(90deg, #F97316 0%, #EA580C 100%);
    color: #FFFFFF;
    padding: 0;
    box-shadow: 0 4px 20px rgba(234, 88, 12, 0.4);
    font-family: 'Geist', -apple-system, system-ui, sans-serif;
    font-size: 14px;
    line-height: 1.4;
    border-bottom: 2px solid #C2410C;
">
    <div style="
        max-width: 100%;
        padding: 10px 20px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 16px;
        flex-wrap: wrap;
    ">
        <!-- Pictogramme + texte principal -->
        <div style="display: flex; align-items: center; gap: 12px; min-width: 0; flex: 1;">
            <div style="
                font-size: 20px;
                animation: imp-pulse 2s infinite;
                flex-shrink: 0;
            ">🎭</div>
            <div style="min-width: 0;">
                <div style="font-weight: 600; letter-spacing: 0.01em;">
                    MODE INCARNATION
                    <span style="opacity: 0.9; font-weight: 400; margin-left: 8px;">
                        · Fondateur <?= htmlspecialchars($realUser['first_name'] ?? '?', ENT_QUOTES) ?>
                    </span>
                </div>
                <div style="font-size: 12.5px; opacity: 0.92; margin-top: 2px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                    Vous voyez l'espace de
                    <strong><?= htmlspecialchars(($target['first_name'] ?? '?') . ' ' . ($target['last_name'] ?? ''), ENT_QUOTES) ?></strong>
                    · <?= htmlspecialchars($target['email'] ?? '', ENT_QUOTES) ?>
                </div>
            </div>
        </div>

        <!-- Timer + bouton Stop -->
        <div style="display: flex; align-items: center; gap: 12px; flex-shrink: 0;">
            <div id="imp-timer" data-remaining="<?= (int)$remaining ?>" style="
                background: rgba(0, 0, 0, 0.25);
                padding: 5px 12px;
                border-radius: 99px;
                font-family: 'SF Mono', Monaco, Consolas, monospace;
                font-size: 12.5px;
                font-weight: 500;
                letter-spacing: 0.02em;
            ">⏱ <span id="imp-timer-display"></span></div>

            <form method="POST" action="/super-admin/incarner-stop" style="margin: 0;">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES) ?>">
                <button type="submit" style="
                    background: #FFFFFF;
                    color: #EA580C;
                    border: none;
                    padding: 8px 16px;
                    border-radius: 8px;
                    font-size: 13px;
                    font-weight: 600;
                    cursor: pointer;
                    font-family: inherit;
                    transition: transform 0.15s, box-shadow 0.15s;
                    display: inline-flex;
                    align-items: center;
                    gap: 6px;
                ">
                    ← Revenir à mon compte
                </button>
            </form>
        </div>
    </div>
</div>

<!-- Spacer pour que le contenu ne soit pas caché sous la bannière -->
<div style="height: 68px;" id="imp-banner-spacer"></div>

<style>
@keyframes imp-pulse {
    0%, 100% { opacity: 1; transform: scale(1); }
    50% { opacity: 0.7; transform: scale(1.1); }
}

/* Ajuste le spacer sur mobile si la banni\u00e8re wrap */
@media (max-width: 700px) {
    #imp-banner-spacer { height: 120px; }
}

#imp-banner button:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
}

/* Force la sidebar/header du site à descendre s'ils sont en position:fixed */
.sa-sidebar, .sidebar, nav.sticky, .navbar-fixed-top {
    top: 68px !important;
}
@media (max-width: 700px) {
    .sa-sidebar, .sidebar, nav.sticky, .navbar-fixed-top {
        top: 120px !important;
    }
}
</style>

<script>
(function() {
    var el = document.getElementById('imp-timer');
    var display = document.getElementById('imp-timer-display');
    if (!el || !display) return;
    var remaining = parseInt(el.dataset.remaining || 0, 10);

    function tick() {
        if (remaining <= 0) {
            // Session expirée → reload pour déclencher le middleware
            window.location.reload();
            return;
        }
        var mm = Math.floor(remaining / 60);
        var ss = remaining % 60;
        display.textContent = (mm < 10 ? '0' : '') + mm + ':' + (ss < 10 ? '0' : '') + ss;

        // Changement de couleur si < 5 min
        if (remaining <= 300) {
            el.style.background = 'rgba(220, 38, 38, 0.4)';
            el.style.animation = 'imp-pulse 1s infinite';
        }
        remaining--;
    }
    tick();
    setInterval(tick, 1000);
})();
</script>
<!-- ========== FIN BANNIÈRE INCARNATION ========== -->
