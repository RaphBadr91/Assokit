<?php
/**
 * ============================================================
 * ASSOKIT — Partiel d'affichage des suggestions Today AI
 * ============================================================
 * À inclure dans dashboard.php.
 *
 * Utilise les variables attendues :
 *   $user (via current_user())
 *   $today_fr (texte français de la date du jour)
 *
 * Récupère les suggestions via today_get_or_generate().
 * Affiche la liste + bouton refresh (rate-limité).
 * ============================================================
 */

@require_once __DIR__ . '/today-ai-helper.php';

// Récupération des suggestions
$today_data = today_get_or_generate($pdo, $user);
$suggestions = $today_data['suggestions'] ?? [];
$can_refresh = $today_data['can_refresh'] ?? true;
$refresh_count = $today_data['refresh_count'] ?? 0;
$has_error = $today_data['has_error'] ?? false;

// Mapping des priorités vers des classes CSS
$priority_colors = [
    'urgent'    => ['bg' => '#FEF2F2', 'border' => '#FCA5A5', 'text' => '#991B1B'],
    'important' => ['bg' => '#FFFBEB', 'border' => '#FCD34D', 'text' => '#92400E'],
    'info'      => ['bg' => '#F0FDF4', 'border' => '#86EFAC', 'text' => '#166534'],
];
?>

<section class="today">
  <div class="today-head" style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px;">
    <div style="display:flex; align-items:center; gap:8px;">
      <div class="today-label"><span class="today-label-dot"></span>Aujourd'hui</div>
      <?php if (!empty($suggestions) && !$has_error): ?>
        <span style="display:inline-flex; align-items:center; gap:4px; padding:2px 8px; background:linear-gradient(135deg, #7F77DD 0%, #A78BFA 100%); color:white; font-size:10.5px; font-weight:600; border-radius:999px; text-transform:uppercase; letter-spacing:0.03em;">
          <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
          Copilote IA
        </span>
      <?php endif; ?>
    </div>
    <div style="display:flex; align-items:center; gap:12px;">
      <div class="today-date"><?= h($today_fr) ?></div>
      <?php if (!empty($suggestions)): ?>
        <button type="button" id="today-refresh-btn" onclick="todayRefresh()"
                <?= !$can_refresh ? 'disabled title="Vous avez atteint la limite de 3 rafraîchissements par jour"' : 'title="Rafraîchir les suggestions"' ?>
                style="background:transparent; border:1px solid var(--border); color:var(--ink-3); width:30px; height:30px; border-radius:8px; cursor:<?= $can_refresh ? 'pointer' : 'not-allowed' ?>; display:inline-flex; align-items:center; justify-content:center; transition:all 0.15s; opacity:<?= $can_refresh ? '1' : '0.4' ?>;"
                onmouseover="if(!this.disabled){this.style.background='var(--bg-2)';this.style.borderColor='#7F77DD';this.style.color='#7F77DD'}"
                onmouseout="if(!this.disabled){this.style.background='transparent';this.style.borderColor='var(--border)';this.style.color='var(--ink-3)'}">
          <svg id="today-refresh-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <polyline points="23 4 23 10 17 10"></polyline>
            <polyline points="1 20 1 14 7 14"></polyline>
            <path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"></path>
          </svg>
        </button>
      <?php endif; ?>
    </div>
  </div>

  <?php if (empty($suggestions)): ?>

    <div style="padding:20px; text-align:center; color:var(--ink-3); font-size:13.5px;">
      Aucune suggestion disponible pour aujourd'hui.
    </div>

  <?php else: ?>

    <div class="today-list" id="today-list">
      <?php foreach ($suggestions as $sugg): ?>
        <?php
          $priority = $sugg['priority'] ?? 'info';
          $colors = $priority_colors[$priority] ?? $priority_colors['info'];
          $link = $sugg['link'] ?? '#';
          $icon = $sugg['icon'] ?? '✨';
        ?>
        <a href="<?= h($link) ?>" class="today-item" style="text-decoration:none; color:inherit;">
          <div class="today-icon" style="background:<?= $colors['bg'] ?>; color:<?= $colors['text'] ?>; font-size:18px; line-height:1;">
            <?= h($icon) ?>
          </div>
          <div class="today-body">
            <div class="today-title"><?= h($sugg['title'] ?? '') ?></div>
            <div class="today-meta"><?= h($sugg['description'] ?? '') ?></div>
          </div>
          <div style="display:flex; align-items:center; gap:8px;">
            <?php if (!empty($sugg['link_label'])): ?>
              <span style="font-size:11.5px; color:var(--acc-dark); font-weight:500; white-space:nowrap;"><?= h($sugg['link_label']) ?></span>
            <?php endif; ?>
            <svg class="today-arrow" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
              <line x1="5" y1="12" x2="19" y2="12"/>
              <polyline points="12 5 19 12 12 19"/>
            </svg>
          </div>
        </a>
      <?php endforeach; ?>
    </div>

    <?php if ($refresh_count > 0): ?>
      <div style="text-align:right; font-size:10.5px; color:var(--ink-3); margin-top:6px;">
        <?= $refresh_count ?>/<?= TODAY_AI_MAX_REFRESH_PER_DAY ?> rafraîchissements utilisés aujourd'hui
      </div>
    <?php endif; ?>

  <?php endif; ?>

</section>

<script>
(function(){
  let todayRefreshing = false;

  window.todayRefresh = async function() {
    if (todayRefreshing) return;
    const btn = document.getElementById('today-refresh-btn');
    const icon = document.getElementById('today-refresh-icon');
    if (!btn || btn.disabled) return;

    todayRefreshing = true;
    icon.style.animation = 'todaySpin 0.8s linear infinite';
    btn.disabled = true;

    try {
      const fd = new FormData();
      fd.append('csrf_token', '<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>');
      const r = await fetch('/today-ai-refresh', {
        method: 'POST',
        credentials: 'same-origin',
        body: fd,
      });
      const j = await r.json();

      if (j.ok) {
        // Recharger pour afficher les nouvelles suggestions
        // (plus simple et plus sûr que regen le HTML côté JS)
        location.reload();
      } else {
        alert('Impossible de rafraîchir : ' + (j.error || 'erreur inconnue'));
        icon.style.animation = '';
        btn.disabled = false;
        todayRefreshing = false;
      }
    } catch(e) {
      alert('Erreur réseau');
      icon.style.animation = '';
      btn.disabled = false;
      todayRefreshing = false;
    }
  };
})();
</script>

<style>
@keyframes todaySpin {
  from { transform: rotate(0deg); }
  to { transform: rotate(360deg); }
}
</style>
