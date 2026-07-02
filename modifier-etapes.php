<?php
/**
 * ============================================================
 * ASSOKIT — Modifier les étapes d'un projet (v2 avec assignation)
 * ============================================================
 * URL : /modifier-etapes?id={project_id}
 * 
 * Permet :
 *   - Voir / ajouter / modifier / supprimer / réordonner étapes
 *   - ASSIGNER une étape à un membre de l'équipe (NOUVEAU)
 *   - Email auto envoyé à l'assigné
 * ============================================================
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes-layout.php';

require_login();

$current = current_user();
$org_id = (int)$current['org_id'];

$project_id = (int)($_GET['id'] ?? 0);
if ($project_id <= 0) {
    header('Location: /projets');
    exit;
}

// Charger projet
$stmt = $pdo->prepare("
    SELECT p.id, p.name, p.referent_id, p.progress_percent, f.org_id, f.name AS folder_name
    FROM projects p
    JOIN folders f ON p.folder_id = f.id
    WHERE p.id = ?
");
$stmt->execute([$project_id]);
$project = $stmt->fetch();

if (!$project || (int)$project['org_id'] !== $org_id) {
    header('Location: /projets');
    exit;
}

// Permissions
$is_admin = ($current['role'] === 'admin');
$is_referent = ((int)$project['referent_id'] === (int)$current['id']);

if (!$is_admin && !$is_referent) {
    header('Location: /projet/' . $project_id . '?error=permission');
    exit;
}

// Charger les étapes (avec infos assigné via JOIN)
$stmt = $pdo->prepare("
    SELECT s.id, s.position, s.title, s.description, s.is_completed, 
           s.completed_at, s.completed_by, s.assigned_to_user_id,
           u.first_name AS assigned_first, u.last_name AS assigned_last
    FROM project_steps s
    LEFT JOIN users u ON s.assigned_to_user_id = u.id
    WHERE s.project_id = ?
    ORDER BY s.position ASC, s.id ASC
");
$stmt->execute([$project_id]);
$steps = $stmt->fetchAll();

// Charger l'équipe du projet (membres + référent) pour le dropdown d'assignation
$stmt = $pdo->prepare("
    SELECT DISTINCT u.id, u.first_name, u.last_name, u.role
    FROM users u
    WHERE u.is_active = 1
      AND u.org_id = ?
      AND (
          u.id IN (SELECT user_id FROM project_members WHERE project_id = ?)
          OR u.id = ?
      )
    ORDER BY u.first_name ASC, u.last_name ASC
");
$stmt->execute([$org_id, $project_id, (int)$project['referent_id']]);
$team_members = $stmt->fetchAll();

// Flash
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

render_head('Modifier les étapes — ' . $project['name']);
render_sidebar('projets');
?>

<main class="main">

  <nav class="crumbs">
    <a href="/dashboard">Dashboard</a>
    <span class="sep">›</span>
    <a href="/projets">Projets</a>
    <span class="sep">›</span>
    <a href="/projet/<?= $project_id ?>"><?= h($project['name']) ?></a>
    <span class="sep">›</span>
    <span class="current">Modifier les étapes</span>
  </nav>

  <div class="main-head">
    <div>
      <h1 class="page-title">⚙️ Modifier les étapes</h1>
      <div class="page-sub">
        <?= h($project['folder_name']) ?> · <?= h($project['name']) ?>
        · <strong><?= count($steps) ?> étape<?= count($steps) > 1 ? 's' : '' ?></strong>
        · <?= (int)$project['progress_percent'] ?>% complété
      </div>
    </div>
    <div class="head-actions">
      <a href="/projet/<?= $project_id ?>" class="btn btn-ghost">← Retour au projet</a>
    </div>
  </div>

  <?php if ($flash): ?>
    <div class="alert alert-<?= $flash['type'] === 'error' ? 'error' : 'success' ?>">
      <span><?= $flash['type'] === 'error' ? '⚠️' : '✅' ?></span>
      <div><?= h($flash['message']) ?></div>
    </div>
  <?php endif; ?>

  <?php if (empty($team_members)): ?>
    <div class="alert" style="background:#FEF3C7; border:1px solid #F59E0B; color:#92400E; padding:12px 16px; border-radius:10px; margin-bottom:20px;">
      💡 <strong>Astuce :</strong> Aucun membre dans l'équipe du projet. 
      <a href="/modifier-projet?id=<?= $project_id ?>" style="color:#92400E; text-decoration:underline;">Ajoute des membres</a> 
      pour pouvoir assigner les étapes.
    </div>
  <?php endif; ?>

  <!-- ===== AJOUTER UNE ÉTAPE ===== -->
  <div style="background:var(--bg); border:1px solid var(--border); border-radius:12px; padding:20px; margin-bottom:20px;">
    <h2 style="font-size:16px; font-weight:600; margin:0 0 12px;">➕ Ajouter une étape</h2>
    <form method="POST" action="/action-etape">
      <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
      <input type="hidden" name="action" value="add">
      <input type="hidden" name="project_id" value="<?= (int)$project_id ?>">
      
      <div style="display:grid; grid-template-columns: 1fr 2fr; gap:10px; margin-bottom:10px;">
        <input type="text" name="title" required maxlength="200" placeholder="Titre de l'étape *"
               style="padding:10px 12px; background:var(--bg-2); border:1px solid var(--border-strong); border-radius:8px; font-family:inherit; font-size:14px; color:var(--ink);">
        
        <input type="text" name="description" maxlength="500" placeholder="Description (optionnelle)"
               style="padding:10px 12px; background:var(--bg-2); border:1px solid var(--border-strong); border-radius:8px; font-family:inherit; font-size:14px; color:var(--ink);">
      </div>
      
      <div style="display:flex; gap:10px; align-items:center;">
        <select name="assigned_to" 
                style="flex:1; padding:10px 12px; background:var(--bg-2); border:1px solid var(--border-strong); border-radius:8px; font-family:inherit; font-size:14px; color:var(--ink);">
          <option value="">— Assigner à... (optionnel) —</option>
          <?php foreach ($team_members as $m): ?>
            <option value="<?= (int)$m['id'] ?>"><?= h($m['first_name'] . ' ' . $m['last_name']) ?></option>
          <?php endforeach; ?>
        </select>
        
        <button type="submit" class="btn btn-primary">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
          Ajouter
        </button>
      </div>
      <div style="font-size:11.5px; color:var(--ink-3); margin-top:6px;">
        💡 Si tu assignes l'étape à un membre, il recevra un email de notification.
      </div>
    </form>
  </div>

  <!-- ===== LISTE DES ÉTAPES ===== -->
  <?php if (empty($steps)): ?>
    <div class="empty-state">
      <div style="font-size:40px; margin-bottom:10px;">📝</div>
      <div>Aucune étape pour ce projet.</div>
      <div style="font-size:13px; color:var(--ink-3); margin-top:4px;">Ajoute la première étape avec le formulaire ci-dessus.</div>
    </div>
  <?php else: ?>
    <div style="background:var(--bg); border:1px solid var(--border); border-radius:12px; padding:20px;">
      <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px;">
        <h2 style="font-size:16px; font-weight:600; margin:0;">📋 Étapes du projet</h2>
        <span style="font-size:11.5px; color:var(--ink-3);">💡 Glisse-dépose pour réordonner</span>
      </div>
      
      <div id="steps-list" style="display:flex; flex-direction:column; gap:8px;">
        <?php foreach ($steps as $step): 
            $assigned_name = $step['assigned_first'] ? trim($step['assigned_first'] . ' ' . $step['assigned_last']) : '';
        ?>
          <div class="step-card" data-step-id="<?= (int)$step['id'] ?>"
               style="display:flex; align-items:flex-start; gap:12px; padding:14px; background:var(--bg-2); border:1px solid var(--border); border-radius:10px; cursor:move; transition:border-color 0.15s;">
            
            <!-- Drag handle -->
            <div style="color:var(--ink-3); padding-top:2px; cursor:grab;" title="Glisse pour réordonner">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="9" cy="6" r="1"/><circle cx="15" cy="6" r="1"/><circle cx="9" cy="12" r="1"/><circle cx="15" cy="12" r="1"/><circle cx="9" cy="18" r="1"/><circle cx="15" cy="18" r="1"/></svg>
            </div>
            
            <!-- Statut -->
            <div style="width:24px; height:24px; border-radius:50%; display:flex; align-items:center; justify-content:center; background:<?= $step['is_completed'] ? '#10B981' : 'var(--border)' ?>; color:white; font-size:12px; font-weight:700; flex-shrink:0;">
              <?= $step['is_completed'] ? '✓' : (int)$step['position'] ?>
            </div>
            
            <!-- Contenu -->
            <div style="flex:1; min-width:0;">
              <div class="step-display-<?= (int)$step['id'] ?>">
                <div style="font-size:14px; font-weight:500; color:var(--ink); <?= $step['is_completed'] ? 'text-decoration:line-through; opacity:0.6;' : '' ?>">
                  <?= h($step['title']) ?>
                </div>
                <?php if ($step['description']): ?>
                  <div style="font-size:12px; color:var(--ink-3); margin-top:4px;"><?= h($step['description']) ?></div>
                <?php endif; ?>
                
                <!-- Badge assigné -->
                <?php if ($assigned_name): ?>
                  <div style="display:inline-flex; align-items:center; gap:6px; margin-top:6px; padding:3px 8px; background:#DBEAFE; color:#1E40AF; border-radius:12px; font-size:11px; font-weight:500;">
                    👤 <?= h($assigned_name) ?>
                  </div>
                <?php endif; ?>
                
                <?php if ($step['is_completed'] && $step['completed_at']): ?>
                  <div style="font-size:11px; color:#10B981; margin-top:4px;">
                    ✓ Validée le <?= date('d/m/Y', strtotime($step['completed_at'])) ?>
                  </div>
                <?php endif; ?>
              </div>
              
              <!-- Form édition (caché par défaut) -->
              <form method="POST" action="/action-etape" class="step-edit-<?= (int)$step['id'] ?>" style="display:none;">
                <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="project_id" value="<?= (int)$project_id ?>">
                <input type="hidden" name="step_id" value="<?= (int)$step['id'] ?>">
                
                <input type="text" name="title" required maxlength="200" value="<?= h($step['title']) ?>"
                       style="width:100%; padding:8px 10px; background:var(--bg); border:1px solid var(--border-strong); border-radius:6px; font-family:inherit; font-size:13px; color:var(--ink); margin-bottom:6px;">
                
                <input type="text" name="description" maxlength="500" value="<?= h($step['description'] ?? '') ?>" placeholder="Description"
                       style="width:100%; padding:8px 10px; background:var(--bg); border:1px solid var(--border-strong); border-radius:6px; font-family:inherit; font-size:12px; color:var(--ink); margin-bottom:6px;">
                
                <select name="assigned_to" style="width:100%; padding:8px 10px; background:var(--bg); border:1px solid var(--border-strong); border-radius:6px; font-family:inherit; font-size:12px; color:var(--ink); margin-bottom:8px;">
                  <option value="">— Non assignée —</option>
                  <?php foreach ($team_members as $m): ?>
                    <option value="<?= (int)$m['id'] ?>" <?= ((int)($step['assigned_to_user_id'] ?? 0) === (int)$m['id']) ? 'selected' : '' ?>>
                      👤 <?= h($m['first_name'] . ' ' . $m['last_name']) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
                
                <div style="display:flex; gap:6px;">
                  <button type="submit" class="btn btn-primary" style="font-size:12px; padding:6px 12px;">💾 Enregistrer</button>
                  <button type="button" class="btn btn-ghost" style="font-size:12px; padding:6px 12px;" onclick="cancelEdit(<?= (int)$step['id'] ?>)">Annuler</button>
                </div>
              </form>
            </div>
            
            <!-- Actions -->
            <div style="display:flex; gap:6px; flex-shrink:0;">
              <button type="button" onclick="startEdit(<?= (int)$step['id'] ?>)" class="btn btn-ghost" style="padding:6px 10px; font-size:12px;" title="Modifier">
                ✏️
              </button>
              
              <form method="POST" action="/action-etape" style="margin:0;" 
                    onsubmit="return confirm('Supprimer définitivement cette étape ?');">
                <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="project_id" value="<?= (int)$project_id ?>">
                <input type="hidden" name="step_id" value="<?= (int)$step['id'] ?>">
                <button type="submit" class="btn btn-ghost" style="padding:6px 10px; font-size:12px; color:#DC2626;" title="Supprimer">
                  🗑️
                </button>
              </form>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
      
      <!-- Bouton sauvegarde de l'ordre -->
      <form method="POST" action="/action-etape" id="reorder-form" style="display:none; margin-top:14px; text-align:right;">
        <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
        <input type="hidden" name="action" value="reorder">
        <input type="hidden" name="project_id" value="<?= (int)$project_id ?>">
        <div id="reorder-inputs"></div>
        <button type="submit" class="btn btn-primary">💾 Enregistrer le nouvel ordre</button>
      </form>
    </div>
  <?php endif; ?>

</main>

<script>
function startEdit(stepId) {
    document.querySelector('.step-display-' + stepId).style.display = 'none';
    document.querySelector('.step-edit-' + stepId).style.display = 'block';
}
function cancelEdit(stepId) {
    document.querySelector('.step-display-' + stepId).style.display = 'block';
    document.querySelector('.step-edit-' + stepId).style.display = 'none';
}

(function() {
    var list = document.getElementById('steps-list');
    if (!list) return;
    var dragSrc = null;
    
    list.querySelectorAll('.step-card').forEach(function(card) {
        card.draggable = true;
        card.addEventListener('dragstart', function(e) {
            dragSrc = this;
            this.style.opacity = '0.4';
            e.dataTransfer.effectAllowed = 'move';
        });
        card.addEventListener('dragend', function() {
            this.style.opacity = '1';
            list.querySelectorAll('.step-card').forEach(function(c) { c.style.borderColor = 'var(--border)'; });
        });
        card.addEventListener('dragover', function(e) { e.preventDefault(); e.dataTransfer.dropEffect = 'move'; this.style.borderColor = 'var(--ink)'; return false; });
        card.addEventListener('dragleave', function() { this.style.borderColor = 'var(--border)'; });
        card.addEventListener('drop', function(e) {
            e.preventDefault(); e.stopPropagation();
            if (dragSrc !== this) {
                var allCards = Array.from(list.querySelectorAll('.step-card'));
                var srcIdx = allCards.indexOf(dragSrc);
                var tgtIdx = allCards.indexOf(this);
                if (srcIdx < tgtIdx) list.insertBefore(dragSrc, this.nextSibling);
                else list.insertBefore(dragSrc, this);
                showReorderForm();
            }
            return false;
        });
    });
    
    function showReorderForm() {
        var form = document.getElementById('reorder-form');
        var inputs = document.getElementById('reorder-inputs');
        inputs.innerHTML = '';
        list.querySelectorAll('.step-card').forEach(function(card) {
            var input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'order[]';
            input.value = card.dataset.stepId;
            inputs.appendChild(input);
        });
        form.style.display = 'block';
    }
})();
</script>

<?php render_foot(); ?>
