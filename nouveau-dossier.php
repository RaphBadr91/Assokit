<?php
/**
 * ============================================================
 * ASSOKIT — Nouveau dossier
 * ============================================================
 * Formulaire de création d'un dossier (regroupe des projets).
 * Accessible uniquement aux admins.
 * ============================================================
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes-layout.php';

require_login();

$user = current_user();
$org_id = (int)$user['org_id'];

// Permission : admin uniquement
if ($user['role'] !== 'admin') {
    http_response_code(403);
    render_head('Accès refusé');
    render_sidebar('projets');
    echo '<div class="main"><div class="empty-state" style="margin-top:60px;">Seul un administrateur peut créer un dossier.</div></div>';
    render_foot();
    exit;
}

// =============================================================
// 16 COULEURS DISPONIBLES
// =============================================================
$available_themes = [
    'blue'     => ['label' => 'Bleu',     'color' => '#3B82F6'],
    'indigo'   => ['label' => 'Indigo',   'color' => '#6366F1'],
    'purple'   => ['label' => 'Violet',   'color' => '#8B5CF6'],
    'magenta'  => ['label' => 'Magenta',  'color' => '#D946EF'],
    'pink'     => ['label' => 'Rose',     'color' => '#EC4899'],
    'red'      => ['label' => 'Rouge',    'color' => '#EF4444'],
    'orange'   => ['label' => 'Orange',   'color' => '#F97316'],
    'amber'    => ['label' => 'Ambre',    'color' => '#F59E0B'],
    'yellow'   => ['label' => 'Jaune',    'color' => '#EAB308'],
    'lime'     => ['label' => 'Lime',     'color' => '#84CC16'],
    'green'    => ['label' => 'Vert',     'color' => '#10B981'],
    'emerald'  => ['label' => 'Émeraude', 'color' => '#059669'],
    'teal'     => ['label' => 'Sarcelle', 'color' => '#14B8A6'],
    'cyan'     => ['label' => 'Cyan',     'color' => '#06B6D4'],
    'slate'    => ['label' => 'Ardoise',  'color' => '#64748B'],
    'brown'    => ['label' => 'Marron',   'color' => '#8B4513'],
];

// =============================================================
// 20 ICÔNES LUCIDE DISPONIBLES (SVG inline)
// =============================================================
$available_icons = [
    'folder'    => ['label' => 'Dossier',       'svg' => '<path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/>'],
    'home'      => ['label' => 'Maison',        'svg' => '<path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/>'],
    'building'  => ['label' => 'Bâtiment',      'svg' => '<path d="M3 21h18M5 21V7l8-4v18M19 21V11l-6-4"/>'],
    'clipboard' => ['label' => 'Clipboard',     'svg' => '<rect x="8" y="2" width="8" height="4" rx="1" ry="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/>'],
    'target'    => ['label' => 'Cible',         'svg' => '<circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="2"/>'],
    'star'      => ['label' => 'Étoile',        'svg' => '<polygon points="12 2 15 9 22 9 17 14 19 21 12 17 5 21 7 14 2 9 9 9 12 2"/>'],
    'briefcase' => ['label' => 'Travail',       'svg' => '<rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/>'],
    'palette'   => ['label' => 'Art',           'svg' => '<circle cx="13.5" cy="6.5" r="1.5"/><circle cx="17.5" cy="10.5" r="1.5"/><circle cx="8.5" cy="7.5" r="1.5"/><circle cx="6.5" cy="12.5" r="1.5"/><path d="M12 2C6.5 2 2 6.5 2 12s4.5 10 10 10c.926 0 1.648-.746 1.648-1.688 0-.437-.18-.835-.437-1.125-.29-.289-.438-.652-.438-1.125a1.64 1.64 0 0 1 1.668-1.668h1.996c3.051 0 5.555-2.503 5.555-5.554C21.965 6.012 17.461 2 12 2z"/>'],
    'graduation-cap' => ['label' => 'Éducation', 'svg' => '<path d="M22 10v6M2 10l10-5 10 5-10 5z"/><path d="M6 12v5c3 3 9 3 12 0v-5"/>'],
    'handshake' => ['label' => 'Solidarité',    'svg' => '<path d="M11 17l-5-5-2 2 5 5 2-2zM21 12l-2-2-7 7 2 2 7-7zM12 7l3 3"/>'],
    'lightbulb' => ['label' => 'Idée',          'svg' => '<path d="M9 18h6M10 22h4M12 2a7 7 0 0 1 5 12c-1 1-2 2-2 4H9c0-2-1-3-2-4a7 7 0 0 1 5-12z"/>'],
    'leaf'      => ['label' => 'Environnement', 'svg' => '<path d="M11 20A7 7 0 0 1 9.8 6.1C15.5 5 17 4.48 19.2 2.96c.86 8.66-3.32 17.04-12.2 17.04L4 20l7-9"/>'],
    'activity'  => ['label' => 'Sport',         'svg' => '<polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/>'],
    'megaphone' => ['label' => 'Communication', 'svg' => '<path d="M3 11l18-5v12L3 14v-3z"/><path d="M11.6 16.8a3 3 0 1 1-5.8-1.6"/>'],
    'wallet'    => ['label' => 'Finance',       'svg' => '<path d="M20 12V8H6a2 2 0 0 1-2-2c0-1.1.9-2 2-2h12v4"/><path d="M4 6v12a2 2 0 0 0 2 2h14v-4"/><path d="M18 12a2 2 0 0 0-2 2c0 1.1.9 2 2 2h4v-4z"/>'],
    'heart'     => ['label' => 'Santé',         'svg' => '<path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/>'],
    'baby'      => ['label' => 'Jeunesse',      'svg' => '<path d="M9 12h.01M15 12h.01M10 16c.5.3 1.2.5 2 .5s1.5-.2 2-.5"/><path d="M19 6.3a9 9 0 0 1 1.8 3.9 2 2 0 0 1 0 3.6 9 9 0 0 1-17.6 0 2 2 0 0 1 0-3.6A9 9 0 0 1 12 3a9 9 0 0 1 7 3.3z"/>'],
    'users'     => ['label' => 'Famille',       'svg' => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>'],
    'calendar'  => ['label' => 'Événement',     'svg' => '<rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>'],
    'globe'     => ['label' => 'International', 'svg' => '<circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/>'],
];

$error = null;
$form = [
    'name'        => '',
    'color_theme' => 'blue',
    'icon'        => 'folder',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && check_csrf($_POST['csrf_token'] ?? '')) {
    $form['name'] = trim($_POST['name'] ?? '');
    $form['color_theme'] = $_POST['color_theme'] ?? 'blue';
    $form['icon'] = $_POST['icon'] ?? 'folder';

    if (!array_key_exists($form['color_theme'], $available_themes)) {
        $form['color_theme'] = 'blue';
    }
    if (!array_key_exists($form['icon'], $available_icons)) {
        $form['icon'] = 'folder';
    }

    if ($form['name'] === '' || strlen($form['name']) < 2) {
        $error = 'Le nom du dossier doit contenir au moins 2 caractères.';
    } elseif (strlen($form['name']) > 100) {
        $error = 'Le nom du dossier est trop long (max 100 caractères).';
    } else {
        // Verifier doublon dans l'org
        $stmt = $pdo->prepare("SELECT id FROM folders WHERE org_id = ? AND LOWER(name) = LOWER(?)");
        $stmt->execute([$org_id, $form['name']]);
        if ($stmt->fetch()) {
            $error = 'Un dossier avec ce nom existe déjà dans votre association.';
        } else {
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO folders (org_id, name, color_theme, icon, created_by, created_at)
                    VALUES (?, ?, ?, ?, ?, NOW())
                ");
                $stmt->execute([$org_id, $form['name'], $form['color_theme'], $form['icon'], (int)$user['id']]);
                $new_id = (int) $pdo->lastInsertId();

                header('Location: /projets#f' . $new_id);
                exit;
            } catch (Throwable $e) {
                $error = 'Erreur technique : ' . $e->getMessage();
            }
        }
    }
}

render_head('Nouveau dossier');
render_sidebar('projets');
?>

<div class="main">

  <nav class="crumbs">
    <a href="/dashboard">Dashboard</a>
    <span class="sep">›</span>
    <a href="/projets">Projets</a>
    <span class="sep">›</span>
    <span class="current">Nouveau dossier</span>
  </nav>

  <div class="main-head">
    <div>
      <h1 class="page-title">📁 Nouveau dossier</h1>
      <div class="page-sub">Les dossiers regroupent vos projets par thématique (ex : Événements, Social, Culturel, etc.).</div>
    </div>
  </div>

  <?php if ($error): ?>
    <div class="alert alert-error">
      <span>⚠️</span>
      <div><?= h($error) ?></div>
    </div>
  <?php endif; ?>

  <form method="POST" action="/nouveau-dossier"
        style="background:var(--bg); border:1px solid var(--border); border-radius:12px; padding:24px; max-width:760px;">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">

    <!-- ===== APERÇU LIVE ===== -->
    <div style="margin-bottom:24px; padding:20px; background:var(--bg-2); border:1px solid var(--border); border-radius:12px;">
      <div style="font-size:11.5px; color:var(--ink-3); font-weight:500; text-transform:uppercase; letter-spacing:0.05em; margin-bottom:12px;">Aperçu</div>
      <div style="display:flex; align-items:center; gap:14px;">
        <div id="preview-icon-box" style="width:56px; height:56px; border-radius:14px; display:flex; align-items:center; justify-content:center; background:#3B82F620; color:#3B82F6; transition:all 0.2s;">
          <svg id="preview-icon-svg" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
            <path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/>
          </svg>
        </div>
        <div>
          <div id="preview-name" style="font-size:16px; font-weight:600; color:var(--ink);">Mon dossier</div>
          <div style="font-size:12px; color:var(--ink-3); margin-top:2px;">0 projet en cours</div>
        </div>
      </div>
    </div>

    <!-- ===== NOM ===== -->
    <div style="margin-bottom:18px;">
      <label for="name" style="display:block; font-size:13px; font-weight:500; margin-bottom:6px;">Nom du dossier *</label>
      <input type="text" id="name" name="name" required maxlength="100" autofocus
             placeholder="Ex : Événements, Social, Culturel…"
             value="<?= h($form['name']) ?>"
             oninput="document.getElementById('preview-name').textContent = this.value || 'Mon dossier'"
             style="width:100%; padding:10px 12px; background:var(--bg); border:1px solid var(--border-strong); border-radius:8px; font-family:inherit; font-size:14px; color:var(--ink);">
    </div>

    <!-- ===== COULEUR (16 OPTIONS) ===== -->
    <div style="margin-bottom:24px;">
      <label style="display:block; font-size:13px; font-weight:500; margin-bottom:10px;">Couleur</label>
      <div style="display:grid; grid-template-columns: repeat(auto-fill, minmax(44px, 1fr)); gap:8px;">
        <?php foreach ($available_themes as $key => $t): ?>
          <label class="color-pick" data-color="<?= h($t['color']) ?>" data-key="<?= $key ?>"
                 title="<?= h($t['label']) ?>"
                 style="cursor:pointer; aspect-ratio:1; border-radius:10px; background:<?= $t['color'] ?>; border:3px solid <?= $form['color_theme'] === $key ? 'var(--ink)' : 'transparent' ?>; transition:transform 0.15s, border-color 0.15s; position:relative;">
            <input type="radio" name="color_theme" value="<?= $key ?>"
                   <?= $form['color_theme'] === $key ? 'checked' : '' ?>
                   style="position:absolute; opacity:0; pointer-events:none;">
            <?php if ($form['color_theme'] === $key): ?>
              <span style="position:absolute; inset:0; display:flex; align-items:center; justify-content:center; color:white; font-size:18px; font-weight:700;">✓</span>
            <?php endif; ?>
          </label>
        <?php endforeach; ?>
      </div>
      <div style="font-size:11.5px; color:var(--ink-3); margin-top:6px;" id="color-label">
        <?= h($available_themes[$form['color_theme']]['label']) ?>
      </div>
    </div>

    <!-- ===== ICÔNE (20 OPTIONS) ===== -->
    <div style="margin-bottom:24px;">
      <label style="display:block; font-size:13px; font-weight:500; margin-bottom:10px;">Icône</label>
      <div style="display:grid; grid-template-columns: repeat(auto-fill, minmax(44px, 1fr)); gap:8px;">
        <?php foreach ($available_icons as $key => $ic): ?>
          <label class="icon-pick" data-key="<?= $key ?>" data-svg='<?= htmlspecialchars($ic['svg'], ENT_QUOTES) ?>'
                 title="<?= h($ic['label']) ?>"
                 style="cursor:pointer; aspect-ratio:1; border-radius:10px; background:var(--bg-2); border:2px solid <?= $form['icon'] === $key ? 'var(--ink)' : 'var(--border)' ?>; display:flex; align-items:center; justify-content:center; transition:all 0.15s; color:var(--ink-2);">
            <input type="radio" name="icon" value="<?= $key ?>"
                   <?= $form['icon'] === $key ? 'checked' : '' ?>
                   style="position:absolute; opacity:0; pointer-events:none;">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
              <?= $ic['svg'] ?>
            </svg>
          </label>
        <?php endforeach; ?>
      </div>
      <div style="font-size:11.5px; color:var(--ink-3); margin-top:6px;" id="icon-label">
        <?= h($available_icons[$form['icon']]['label']) ?>
      </div>
    </div>

    <!-- ===== ACTIONS ===== -->
    <div style="display:flex; gap:10px; justify-content:flex-end;">
      <a href="/projets" class="btn btn-ghost">Annuler</a>
      <button type="submit" class="btn btn-primary">+ Créer le dossier</button>
    </div>
  </form>

</div>

<script>
(function() {
    // ===== Couleurs =====
    var colorLabels = <?= json_encode(array_combine(array_keys($available_themes), array_column($available_themes, 'label'))) ?>;
    var colorHex = <?= json_encode(array_combine(array_keys($available_themes), array_column($available_themes, 'color'))) ?>;
    
    document.querySelectorAll('.color-pick').forEach(function(el) {
        el.addEventListener('click', function(e) {
            e.preventDefault();
            var key = this.dataset.key;
            var color = this.dataset.color;
            
            // Cocher le radio
            this.querySelector('input').checked = true;
            
            // Update borders
            document.querySelectorAll('.color-pick').forEach(function(c) {
                c.style.border = '3px solid transparent';
                var check = c.querySelector('span');
                if (check) check.remove();
            });
            this.style.border = '3px solid var(--ink)';
            
            // Add checkmark
            var check = document.createElement('span');
            check.style.cssText = 'position:absolute; inset:0; display:flex; align-items:center; justify-content:center; color:white; font-size:18px; font-weight:700;';
            check.textContent = '✓';
            this.appendChild(check);
            
            // Update preview + label
            document.getElementById('preview-icon-box').style.background = color + '20';
            document.getElementById('preview-icon-box').style.color = color;
            document.getElementById('color-label').textContent = colorLabels[key];
        });
    });
    
    // ===== Icônes =====
    var iconLabels = <?= json_encode(array_combine(array_keys($available_icons), array_column($available_icons, 'label'))) ?>;
    
    document.querySelectorAll('.icon-pick').forEach(function(el) {
        el.addEventListener('click', function(e) {
            e.preventDefault();
            var key = this.dataset.key;
            var svg = this.dataset.svg;
            
            // Cocher le radio
            this.querySelector('input').checked = true;
            
            // Update borders
            document.querySelectorAll('.icon-pick').forEach(function(c) {
                c.style.border = '2px solid var(--border)';
            });
            this.style.border = '2px solid var(--ink)';
            
            // Update preview SVG
            document.getElementById('preview-icon-svg').innerHTML = svg;
            document.getElementById('icon-label').textContent = iconLabels[key];
        });
    });
})();
</script>

<?php render_foot(); ?>
