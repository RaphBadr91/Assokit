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

<section class="today today-copilot">
  <div class="today-band" aria-hidden="true"></div>
  <div class="today-head" style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px; position:relative;">
    <div class="today-copilot-title">
      <span class="today-copilot-badge">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2l2.5 6.5L21 11l-6.5 2.5L12 20l-2.5-6.5L3 11l6.5-2.5L12 2z"/></svg>
      </span>
      <div class="today-copilot-txt">
        <b>Assokit, votre copilote</b>
        <?php $n = count($suggestions); ?>
        <small><?php if ($n > 0): ?><?= $n ?> chose<?= $n > 1 ? 's' : '' ?> mérite<?= $n > 1 ? 'nt' : '' ?> votre attention aujourd'hui<?php else: ?>Tout est sous contrôle aujourd'hui<?php endif; ?></small>
      </div>
    </div>
    <div style="display:flex; align-items:center; gap:12px; position:relative;">
      <?php if (!empty($suggestions) && !$has_error): ?>
        <span class="today-copilot-pill">✦ Suggestions personnalisées</span>
      <?php endif; ?>
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

/* ===== Panneau « Assokit, votre copilote » (maquette 2.0) ===== */
.today.today-copilot {
  position: relative;
  overflow: hidden;
  background: var(--glass, rgba(255,255,255,0.72));
  backdrop-filter: blur(22px) saturate(1.5);
  -webkit-backdrop-filter: blur(22px) saturate(1.5);
  border: 1px solid var(--glass-border, rgba(255,255,255,0.65));
  border-radius: var(--radius-lg, 18px);
  box-shadow: var(--shadow-card, 0 1px 2px rgba(9,30,22,0.04), 0 14px 34px -16px rgba(9,30,22,0.16));
}
.today.today-copilot .today-band {
  position: absolute; inset: 0; pointer-events: none; z-index: 0;
  background:
    radial-gradient(120% 100% at 0% 0%, rgba(99,102,241,0.16), transparent 55%),
    radial-gradient(120% 120% at 100% 0%, rgba(139,92,246,0.14), transparent 55%);
}
.today.today-copilot .today-list { position: relative; z-index: 1; }
.today-copilot-title { display: flex; align-items: center; gap: 11px; }
.today-copilot-badge {
  width: 34px; height: 34px; border-radius: 11px; flex: none;
  background: linear-gradient(140deg, #8B5CF6, #6366F1); color: #fff;
  display: inline-flex; align-items: center; justify-content: center;
  box-shadow: 0 8px 20px -6px rgba(99,102,241,0.6), inset 0 1px 0 rgba(255,255,255,0.4);
  position: relative; overflow: hidden;
}
.today-copilot-badge svg { position: relative; z-index: 1; }
.today-copilot-badge::after {
  content: ''; position: absolute; inset: 0;
  background: linear-gradient(115deg, transparent 30%, rgba(255,255,255,0.55) 50%, transparent 70%);
  transform: translateX(-120%);
  animation: todaySheen 4.5s ease-in-out infinite;
}
@keyframes todaySheen { 0%,70%{transform:translateX(-120%)} 85%,100%{transform:translateX(120%)} }
.today-copilot-txt b { font-size: 15.5px; font-weight: 700; letter-spacing: -0.01em; color: var(--ink, #0B1A13); display: block; }
.today-copilot-txt small { display: block; color: var(--ink-3, #7C8983); font-size: 11.5px; font-weight: 500; margin-top: 1px; }
.today-copilot-pill {
  font-size: 11.5px; font-weight: 600; color: var(--ai, #6366F1);
  background: var(--ai-light, rgba(99,102,241,0.10)); padding: 6px 12px; border-radius: 999px;
  border: 1px solid rgba(99,102,241,0.2); white-space: nowrap;
}
/* items en cartes de verre indigo */
.today.today-copilot .today-item {
  background: var(--bg-2, rgba(255,255,255,0.55));
  border: 1px solid var(--border, rgba(12,40,28,0.06));
  border-radius: 15px;
  transition: transform 0.18s ease, box-shadow 0.18s ease, border-color 0.18s ease;
}
.today.today-copilot .today-item:hover {
  transform: translateY(-1px);
  border-color: rgba(99,102,241,0.3);
  box-shadow: var(--shadow-card, 0 1px 2px rgba(9,30,22,0.04), 0 14px 34px -16px rgba(9,30,22,0.16));
}
.today.today-copilot .today-item:hover .today-arrow { color: var(--ai, #6366F1); }
@media (max-width: 640px) {
  .today-copilot-pill { display: none; }
}
</style>
