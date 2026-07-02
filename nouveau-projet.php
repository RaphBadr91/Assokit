<?php
/**
 * ============================================================
 * ASSOKIT — Nouveau projet
 * ============================================================
 * Formulaire de création d'un projet.
 * Accessible uniquement aux admins et coordinateurs avec
 * can_create_projects = 1.
 * ============================================================
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes-layout.php';

require_login();

$user = current_user();
$org_id = (int)$user['org_id'];

// Permission
if (!($user['role'] === 'admin' || $user['can_create_projects'])) {
    render_head('Accès refusé');
    render_sidebar('projets');
    echo '<main class="main"><div class="empty-state" style="margin-top: 60px;">Seul un administrateur ou une personne désignée peut créer un projet.</div></main>';
    render_foot();
    exit;
}

// Charger les dossiers disponibles pour le <select>
// [FIX BUG 2] Filtrer les dossiers archivés
$stmt = $pdo->prepare("SELECT id, name FROM folders WHERE org_id = ? AND archived_at IS NULL ORDER BY name ASC");
$stmt->execute([$org_id]);
$folders = $stmt->fetchAll();

// [FIX BUG 3] Charger les référents potentiels - exclure users supprimés ou désactivés
$stmt = $pdo->prepare("
    SELECT id, first_name, last_name
    FROM users
    WHERE org_id = ?
      AND role IN ('admin', 'coordinator', 'referent')
      AND is_active = 1
      AND (deleted_at IS NULL OR deleted_at = '')
    ORDER BY first_name ASC, last_name ASC
");
$stmt->execute([$org_id]);
$potential_referents = $stmt->fetchAll();

// [NEW] Charger TOUS les membres actifs de l'asso (pour l'équipe projet)
$stmt = $pdo->prepare("
    SELECT id, first_name, last_name, role
    FROM users
    WHERE org_id = ?
      AND is_active = 1
      AND (deleted_at IS NULL OR deleted_at = '')
    ORDER BY first_name ASC, last_name ASC
");
$stmt->execute([$org_id]);
$potential_members = $stmt->fetchAll();

// ====== Templates de projets disponibles ======
$templates = [];
try {
    $stmt = $pdo->query("SELECT id, code, org_type, category, name, icon, color, short_desc, description, objective FROM project_templates WHERE is_active = 1 ORDER BY position ASC, name ASC");
    $tpls = $stmt->fetchAll();
    if ($tpls) {
        $ids = array_column($tpls, 'id');
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $sstmt = $pdo->prepare("SELECT template_id, title FROM project_template_steps WHERE template_id IN ($ph) ORDER BY template_id, position ASC");
        $sstmt->execute($ids);
        $steps_by_tpl = [];
        foreach ($sstmt->fetchAll() as $r) $steps_by_tpl[(int)$r['template_id']][] = $r['title'];
        foreach ($tpls as &$t) $t['steps'] = $steps_by_tpl[(int)$t['id']] ?? [];
        unset($t);
        $templates = $tpls;
    }
} catch (Throwable $e) {}

// ====== Traitement du formulaire ======
$errors = [];
$data = [
    'name' => '', 'folder_id' => (int)($_GET['folder'] ?? 0), 'location' => '',
    'description' => '', 'objective' => '',
    'referent_id' => $user['id'],
    'budget_planned' => '', 'participants_count' => '',
    'participants_female' => '', 'participants_male' => '',
    'start_date' => '', 'end_date' => '',
];
$steps_input = ['', '', '', ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!check_csrf($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Session expirée, rechargez la page.';
    } else {
        // Récupération des champs
        foreach ($data as $k => $v) {
            $data[$k] = trim($_POST[$k] ?? '');
        }
        // Les étapes arrivent comme un tableau
        $step_titles_raw = $_POST['step_title'] ?? [];
        $step_assigned_raw = $_POST['step_assigned'] ?? [];
        
        // Construire un tableau combiné [title, assigned_to] en filtrant les titres vides
        $steps_input = [];
        foreach ($step_titles_raw as $i => $t) {
            $t = trim($t);
            if ($t === '') continue;
            $steps_input[] = [
                'title' => $t,
                'assigned_to' => (int)($step_assigned_raw[$i] ?? 0) ?: null,
            ];
        }
        
        // [NEW] Récupérer les membres sélectionnés pour l'équipe
        $selected_members = array_map('intval', $_POST['team_members'] ?? []);
        $selected_members = array_filter($selected_members, fn($id) => $id > 0);

        // Validations
        if ($data['name'] === '') $errors[] = 'Le nom du projet est obligatoire.';
        if ((int)$data['folder_id'] <= 0) $errors[] = 'Merci de choisir un dossier.';
        if (count($steps_input) < 4) $errors[] = 'Un projet doit avoir au moins 4 étapes (vous en avez ' . count($steps_input) . ').';

        // Vérifier que le dossier appartient à l'org
        if (!$errors) {
            $check = $pdo->prepare("SELECT id FROM folders WHERE id = ? AND org_id = ?");
            $check->execute([(int)$data['folder_id'], $org_id]);
            if (!$check->fetch()) $errors[] = 'Dossier invalide.';
        }

        // Vérifier le référent
        $referent_id = (int)$data['referent_id'];
        if ($referent_id > 0 && !$errors) {
            $check = $pdo->prepare("SELECT id FROM users WHERE id = ? AND org_id = ?");
            $check->execute([$referent_id, $org_id]);
            if (!$check->fetch()) $errors[] = 'Référent invalide.';
        }

        // Insertion si OK
        if (!$errors) {
            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO projects (
                        folder_id, name, location, description, objective, referent_id,
                        budget_planned, budget_used, participants_count,
                        participants_female, participants_male,
                        start_date, end_date, status, progress_percent
                    ) VALUES (
                        :folder_id, :name, :location, :description, :objective, :referent_id,
                        :budget_planned, 0, :participants_count,
                        :participants_female, :participants_male,
                        :start_date, :end_date, 'active', 0
                    )
                ");
                $stmt->execute([
                    ':folder_id' => (int)$data['folder_id'],
                    ':name' => $data['name'],
                    ':location' => $data['location'] ?: null,
                    ':description' => $data['description'] ?: null,
                    ':objective' => $data['objective'] ?: null,
                    ':referent_id' => $referent_id ?: null,
                    ':budget_planned' => (float)str_replace(',', '.', $data['budget_planned']),
                    ':participants_count' => (int)$data['participants_count'],
                    ':participants_female' => (int)$data['participants_female'],
                    ':participants_male' => (int)$data['participants_male'],
                    ':start_date' => $data['start_date'] ?: null,
                    ':end_date' => $data['end_date'] ?: null,
                ]);
                $new_id = (int)$pdo->lastInsertId();

                // Insertion des étapes (avec assignation éventuelle)
                $stmt_step = $pdo->prepare("
                    INSERT INTO project_steps (project_id, position, title, assigned_to_user_id)
                    VALUES (?, ?, ?, ?)
                ");
                $assigned_emails_to_send = []; // [step_id => user_id]
                foreach ($steps_input as $i => $step_data) {
                    // Validation : l'assigné doit appartenir à l'org
                    $assigned_to = $step_data['assigned_to'];
                    if ($assigned_to) {
                        $check = $pdo->prepare("SELECT id FROM users WHERE id = ? AND org_id = ? AND is_active = 1");
                        $check->execute([$assigned_to, $org_id]);
                        if (!$check->fetch()) {
                            $assigned_to = null; // ignorer assignation invalide
                        }
                    }
                    
                    $stmt_step->execute([$new_id, $i + 1, $step_data['title'], $assigned_to]);
                    $new_step_id = (int)$pdo->lastInsertId();
                    
                    // Mémoriser pour envoyer email après le commit
                    if ($assigned_to && $assigned_to !== (int)$user['id']) {
                        $assigned_emails_to_send[$new_step_id] = $assigned_to;
                    }
                }

                // [NEW] Insertion de l'équipe (project_members)
                // Le référent est ajouté automatiquement comme membre s'il existe
                $members_to_add = $selected_members;
                if ($referent_id > 0 && !in_array($referent_id, $members_to_add)) {
                    $members_to_add[] = $referent_id;
                }
                if (!empty($members_to_add)) {
                    $stmt_mem = $pdo->prepare("
                        INSERT IGNORE INTO project_members (project_id, user_id, role_in_project, joined_at)
                        VALUES (?, ?, ?, NOW())
                    ");
                    foreach ($members_to_add as $uid) {
                        $role_proj = ($uid === $referent_id) ? 'referent' : 'member';
                        $stmt_mem->execute([$new_id, $uid, $role_proj]);
                    }
                }

                $pdo->commit();
                
                // ===== ENVOYER LES EMAILS APRÈS COMMIT =====
                // 1. Aux membres ajoutés à l'équipe (sauf soi-même)
                if (file_exists(__DIR__ . '/projet-email-helpers.php')) {
                    require_once __DIR__ . '/projet-email-helpers.php';
                    
                    // Email "ajouté à l'équipe" pour chaque nouveau membre (sauf soi-même)
                    foreach ($members_to_add as $uid) {
                        if ($uid !== (int)$user['id']) {
                            try {
                                ak_email_project_team_added($pdo, $new_id, $uid, (int)$user['id']);
                            } catch (Throwable $e) {
                                error_log('[nouveau-projet] Email équipe : ' . $e->getMessage());
                            }
                        }
                    }
                    
                    // Email "étape assignée" pour chaque étape avec assigné
                    foreach ($assigned_emails_to_send as $step_id => $assigned_uid) {
                        try {
                            ak_email_step_assigned($pdo, $step_id, $assigned_uid, (int)$user['id']);
                        } catch (Throwable $e) {
                            error_log('[nouveau-projet] Email étape : ' . $e->getMessage());
                        }
                    }
                }
                
                header('Location: /projet/' . $new_id);
                exit;
            } catch (PDOException $e) {
                $pdo->rollBack();
                $errors[] = 'Erreur technique : ' . (DEBUG ? $e->getMessage() : 'réessayez plus tard');
            }
        }
    }
}

// Assurer au moins 4 champs d'étape affichés
while (count($steps_input) < 4) {
    $steps_input[] = ['title' => '', 'assigned_to' => null];
}

render_head('Nouveau projet');
render_sidebar('projets');
?>

<main class="main">

  <nav class="crumbs">
    <a href="/dashboard">Dashboard</a>
    <span class="sep">›</span>
    <a href="/projets">Projets</a>
    <span class="sep">›</span>
    <span class="current">Nouveau projet</span>
  </nav>

  <div class="main-head">
    <div>
      <h1 class="page-title">Nouveau projet</h1>
      <div class="page-sub">Définissez les infos clés et les étapes à suivre</div>
    </div>
  </div>

  <?php if ($errors): ?>
    <div class="alert alert-error">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
      <div>
        <?php foreach ($errors as $err): ?>
          <div><?= h($err) ?></div>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endif; ?>

  <form method="POST" action="/nouveau-projet" novalidate>
    <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
    <input type="hidden" name="template_id" id="template_id" value="">

    <?php if (!empty($templates)): 
      // Grouper par org_type
      $by_type = ['asso' => [], 'tpe' => [], 'any' => []];
      $cats_by_type = ['asso' => [], 'tpe' => [], 'any' => []];
      $cat_labels = [
        'gestion' => 'Gouvernance',
        'sport' => 'Sport',
        'culture' => 'Culture',
        'social' => 'Social',
        'jeunesse' => 'Jeunesse',
        'environnement' => 'Environnement',
        'formation' => 'Formation',
        'com' => 'Communication',
        'creation' => 'Création',
        'rh' => 'RH',
        'commerce' => 'Commerce',
        'restauration' => 'Restauration',
        'service' => 'Services',
        'freelance' => 'Freelance',
        'artisan' => 'Artisanat',
        'beaute' => 'Beauté',
        'ecommerce' => 'E-commerce',
      ];
      foreach ($templates as $t) {
        $type = $t['org_type'] ?? 'any';
        $by_type[$type][] = $t;
        $cats_by_type[$type][$t['category']] = true;
      }
    ?>
    <!-- ============================================================ -->
    <!-- 🎯 TEMPLATES — Démarrage rapide (Asso / TPE)                -->
    <!-- ============================================================ -->
    <div class="tpl-bloc" id="tpl-bloc">
      <div class="tpl-head">
        <div>
          <h2 class="tpl-title">✨ Démarrer depuis un modèle</h2>
          <p class="tpl-desc">Choisis ton type d'organisation puis un modèle adapté. Tout sera modifiable.</p>
        </div>
        <button type="button" class="tpl-skip" id="tpl-skip">Partir de zéro →</button>
      </div>

      <!-- Onglets Asso / TPE / Tous -->
      <div class="tpl-tabs">
        <button type="button" class="tpl-tab is-active" data-org="all">🌟 Tous (<?= count($templates) ?>)</button>
        <?php if (!empty($by_type['asso'])): ?>
          <button type="button" class="tpl-tab" data-org="asso">🤝 Association (<?= count($by_type['asso']) ?>)</button>
        <?php endif; ?>
        <?php if (!empty($by_type['tpe'])): ?>
          <button type="button" class="tpl-tab" data-org="tpe">💼 TPE / Entrepreneur (<?= count($by_type['tpe']) ?>)</button>
        <?php endif; ?>
      </div>

      <!-- Filtres catégories (chips) -->
      <div class="tpl-cats" id="tpl-cats">
        <button type="button" class="tpl-chip is-active" data-cat="all">Toutes les catégories</button>
        <?php
          $all_cats = [];
          foreach ($cats_by_type as $cs) foreach ($cs as $c => $_) $all_cats[$c] = true;
          foreach (array_keys($all_cats) as $c):
        ?>
          <button type="button" class="tpl-chip" data-cat="<?= h($c) ?>"><?= h($cat_labels[$c] ?? $c) ?></button>
        <?php endforeach; ?>
      </div>

      <div class="tpl-grid" id="tpl-grid">
        <?php foreach ($templates as $t): ?>
        <button type="button" class="tpl-card" data-tpl-id="<?= (int)$t['id'] ?>"
                data-org="<?= h($t['org_type']) ?>" data-cat="<?= h($t['category']) ?>"
                data-tpl-name="<?= h($t['name']) ?>"
                data-tpl-desc="<?= h($t['description']) ?>"
                data-tpl-obj="<?= h($t['objective']) ?>"
                data-tpl-steps="<?= h(json_encode($t['steps'], JSON_UNESCAPED_UNICODE)) ?>"
                style="--tpl-color: <?= h($t['color']) ?>;">
          <span class="tpl-icon"><?= h($t['icon']) ?></span>
          <span class="tpl-name"><?= h($t['name']) ?></span>
          <span class="tpl-short"><?= h($t['short_desc']) ?></span>
          <span class="tpl-steps-count"><?= count($t['steps']) ?> étapes</span>
        </button>
        <?php endforeach; ?>
      </div>
      <div class="tpl-empty" id="tpl-empty" hidden>Aucun modèle dans cette catégorie.</div>
      <div class="tpl-active" id="tpl-active" hidden>
        <span class="tpl-active-icon" id="tpl-active-icon">✨</span>
        <div class="tpl-active-info">
          <div class="tpl-active-lbl">Modèle sélectionné</div>
          <div class="tpl-active-name" id="tpl-active-name">—</div>
        </div>
        <button type="button" class="tpl-active-clear" id="tpl-clear">Changer</button>
      </div>
    </div>

    <style>
    .tpl-bloc { background: linear-gradient(135deg, #fafbff, #f0fdf4); border: 1px solid #d1fae5; border-radius: 14px; padding: 20px 22px; margin-bottom: 22px; }
    .tpl-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 14px; margin-bottom: 14px; flex-wrap: wrap; }
    .tpl-title { font-size: 16px; font-weight: 700; color: #065F46; margin: 0 0 4px; }
    .tpl-desc { font-size: 13px; color: #4b5563; margin: 0; line-height: 1.5; }
    .tpl-skip { background: transparent; border: 0; color: #6b7280; font-size: 12.5px; cursor: pointer; font-family: inherit; padding: 6px 10px; border-radius: 6px; transition: background 0.15s; }
    .tpl-skip:hover { background: rgba(0,0,0,0.04); color: #111827; }
    .tpl-tabs { display: flex; gap: 6px; margin-bottom: 12px; flex-wrap: wrap; }
    .tpl-tab { padding: 8px 14px; background: #fff; border: 1px solid #e5e7eb; color: #4b5563; border-radius: 8px; font-size: 12.5px; font-weight: 600; cursor: pointer; font-family: inherit; transition: all 0.15s; }
    .tpl-tab:hover { border-color: #10B981; color: #10B981; }
    .tpl-tab.is-active { background: #10B981; border-color: #10B981; color: #fff; }
    .tpl-cats { display: flex; gap: 6px; margin-bottom: 14px; flex-wrap: wrap; }
    .tpl-chip { padding: 4px 10px; background: rgba(255,255,255,0.6); border: 1px solid #e5e7eb; color: #6b7280; border-radius: 999px; font-size: 11.5px; cursor: pointer; font-family: inherit; transition: all 0.15s; }
    .tpl-chip:hover { background: #fff; color: #111827; }
    .tpl-chip.is-active { background: #065F46; border-color: #065F46; color: #fff; }
    .tpl-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 10px; }
    .tpl-empty { text-align: center; padding: 24px; color: #9ca3af; font-size: 13px; }
    .tpl-card { display: flex; flex-direction: column; gap: 4px; padding: 14px 12px; background: #fff; border: 1.5px solid #e5e7eb; border-radius: 10px; cursor: pointer; text-align: left; font-family: inherit; transition: all 0.18s ease; position: relative; overflow: hidden; }
    .tpl-card::before { content: ''; position: absolute; left: 0; top: 0; bottom: 0; width: 3px; background: var(--tpl-color); opacity: 0; transition: opacity 0.18s ease; }
    .tpl-card:hover { border-color: var(--tpl-color); transform: translateY(-2px); box-shadow: 0 6px 16px rgba(0,0,0,0.06); }
    .tpl-card:hover::before { opacity: 1; }
    .tpl-card.is-selected { border-color: var(--tpl-color); background: #f0fdf4; }
    .tpl-card.is-selected::before { opacity: 1; }
    .tpl-card[hidden] { display: none !important; }
    .tpl-icon { font-size: 22px; }
    .tpl-name { font-size: 13.5px; font-weight: 700; color: #111827; }
    .tpl-short { font-size: 11.5px; color: #6b7280; line-height: 1.4; flex: 1; }
    .tpl-steps-count { font-size: 10.5px; color: var(--tpl-color); font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em; margin-top: 2px; }
    .tpl-active { display: flex; align-items: center; gap: 12px; margin-top: 14px; padding: 10px 14px; background: #fff; border: 1px solid #d1fae5; border-radius: 10px; }
    .tpl-active-icon { font-size: 22px; }
    .tpl-active-info { flex: 1; min-width: 0; }
    .tpl-active-lbl { font-size: 10px; color: #065F46; text-transform: uppercase; letter-spacing: 0.05em; font-weight: 700; }
    .tpl-active-name { font-size: 14px; font-weight: 600; color: #111827; }
    .tpl-active-clear { background: transparent; border: 1px solid #e5e7eb; padding: 5px 12px; border-radius: 6px; font-size: 12px; cursor: pointer; color: #6b7280; font-family: inherit; }
    .tpl-active-clear:hover { background: #f3f4f6; }
    @media (max-width: 540px) { .tpl-grid { grid-template-columns: 1fr 1fr; } .tpl-card { padding: 12px 10px; } .tpl-tab { font-size: 11.5px; padding: 7px 11px; } }
    </style>

    <script>
    (function() {
      const cards = document.querySelectorAll('.tpl-card');
      const tabs = document.querySelectorAll('.tpl-tab');
      const chips = document.querySelectorAll('.tpl-chip');
      const tplIdInput = document.getElementById('template_id');
      const skip = document.getElementById('tpl-skip');
      const activeBox = document.getElementById('tpl-active');
      const activeName = document.getElementById('tpl-active-name');
      const activeIcon = document.getElementById('tpl-active-icon');
      const clearBtn = document.getElementById('tpl-clear');
      const emptyEl = document.getElementById('tpl-empty');
      const descField = document.getElementById('description');
      const objField = document.getElementById('objective');

      let curOrg = 'all';
      let curCat = 'all';

      function refilter() {
        let visible = 0;
        // Set des catégories disponibles dans le tab actif
        const availCats = new Set();
        cards.forEach(c => {
          const okOrg = (curOrg === 'all') || c.dataset.org === curOrg || c.dataset.org === 'any';
          if (okOrg) availCats.add(c.dataset.cat);
        });
        // Update chips visibility
        chips.forEach(ch => {
          const cat = ch.dataset.cat;
          ch.hidden = (cat !== 'all' && !availCats.has(cat));
        });
        // Si chip actif n'est plus visible, reset à "all"
        const activeChip = document.querySelector('.tpl-chip.is-active');
        if (activeChip && activeChip.hidden) {
          chips.forEach(c => c.classList.remove('is-active'));
          chips[0].classList.add('is-active');
          curCat = 'all';
        }
        // Filter cards
        cards.forEach(c => {
          const okOrg = (curOrg === 'all') || c.dataset.org === curOrg || c.dataset.org === 'any';
          const okCat = (curCat === 'all') || c.dataset.cat === curCat;
          c.hidden = !(okOrg && okCat);
          if (okOrg && okCat) visible++;
        });
        emptyEl.hidden = (visible > 0);
      }

      tabs.forEach(t => t.addEventListener('click', () => {
        tabs.forEach(x => x.classList.remove('is-active'));
        t.classList.add('is-active');
        curOrg = t.dataset.org;
        refilter();
      }));
      chips.forEach(ch => ch.addEventListener('click', () => {
        chips.forEach(x => x.classList.remove('is-active'));
        ch.classList.add('is-active');
        curCat = ch.dataset.cat;
        refilter();
      }));

      function applyTemplate(card) {
        const tplId = card.dataset.tplId;
        const tplName = card.dataset.tplName;
        const desc = card.dataset.tplDesc;
        const obj = card.dataset.tplObj;
        let steps;
        try { steps = JSON.parse(card.dataset.tplSteps || '[]'); } catch (e) { steps = []; }

        const hasUserContent = (descField && descField.value.trim()) || (objField && objField.value.trim());
        const builder = document.getElementById('steps-builder');
        const stepInputs = builder ? Array.from(builder.querySelectorAll('input[name="step_title[]"]')) : [];
        const hasStepContent = stepInputs.some(i => i.value.trim());

        if ((hasUserContent || hasStepContent) && !confirm('Appliquer ce modèle ? Les champs déjà remplis seront remplacés.')) return;

        tplIdInput.value = tplId;
        cards.forEach(c => c.classList.toggle('is-selected', c === card));

        if (descField) descField.value = desc;
        if (objField) objField.value = obj;

        if (builder && steps.length > 0) {
          const items = builder.querySelectorAll('.step-builder-item');
          for (let i = items.length - 1; i > 0; i--) items[i].remove();
          const firstInput = builder.querySelector('input[name="step_title[]"]');
          if (firstInput) firstInput.value = '';
          steps.forEach((title, idx) => {
            let inputs = builder.querySelectorAll('input[name="step_title[]"]');
            if (idx < inputs.length) {
              inputs[idx].value = title;
            } else if (typeof window.addStep === 'function') {
              window.addStep();
              const newInputs = builder.querySelectorAll('input[name="step_title[]"]');
              if (newInputs[idx]) newInputs[idx].value = title;
            }
          });
        }

        activeBox.hidden = false;
        activeName.textContent = tplName;
        activeIcon.textContent = card.querySelector('.tpl-icon').textContent;

        const nameField = document.getElementById('name');
        if (nameField) { nameField.focus(); nameField.scrollIntoView({behavior: 'smooth', block: 'center'}); }
      }

      cards.forEach(c => c.addEventListener('click', () => applyTemplate(c)));

      if (skip) skip.addEventListener('click', function() {
        document.getElementById('tpl-bloc').style.display = 'none';
        const nameField = document.getElementById('name');
        if (nameField) nameField.focus();
      });

      if (clearBtn) clearBtn.addEventListener('click', function() {
        tplIdInput.value = '';
        cards.forEach(c => c.classList.remove('is-selected'));
        activeBox.hidden = true;
      });

      // Initial filter (cache les chips inutiles)
      refilter();
    })();
    </script>
    <?php endif; ?>

    <!-- BLOC 1 : L'essentiel -->
    <div class="form-section">
      <h2 class="form-section-title">L'essentiel</h2>
      <p class="form-section-desc">Ces infos permettent à toute l'équipe d'identifier le projet.</p>

      <div class="form-row">
        <label class="form-label" for="name">Nom du projet <span class="required">*</span></label>
        <input type="text" name="name" id="name" class="form-input-lg" required
               value="<?= h($data['name']) ?>" placeholder="Ex : Lycée Mendès France">
      </div>

      <div class="form-cols">
        <div class="form-row">
          <label class="form-label" for="folder_id">Dossier <span class="required">*</span></label>
          <select name="folder_id" id="folder_id" class="form-select-lg" required>
            <option value="">— Choisir un dossier —</option>
            <?php foreach ($folders as $f): ?>
              <option value="<?= (int)$f['id'] ?>" <?= $data['folder_id'] == $f['id'] ? 'selected' : '' ?>><?= h($f['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-row">
          <label class="form-label" for="location">Lieu</label>
          <input type="text" name="location" id="location" class="form-input-lg"
                 value="<?= h($data['location']) ?>" placeholder="Ex : Ris-Orangis">
        </div>
      </div>

      <div class="form-row">
        <label class="form-label" for="referent_id">Responsable du projet (référent)</label>
        <select name="referent_id" id="referent_id" class="form-select-lg">
          <option value="">— Aucun pour l'instant —</option>
          <?php foreach ($potential_referents as $r): ?>
            <option value="<?= (int)$r['id'] ?>" <?= $data['referent_id'] == $r['id'] ? 'selected' : '' ?>>
              <?= h($r['first_name'] . ' ' . $r['last_name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
        <div class="form-hint">Cette personne pourra valider les étapes et faire avancer le projet.</div>
      </div>
    </div>

    <!-- BLOC ÉQUIPE : Sélectionner les membres du projet -->
    <div class="form-section">
      <h2 class="form-section-title">👥 L'équipe du projet</h2>
      <p class="form-section-desc">Sélectionnez les adhérents qui font partie de l'équipe (le référent est ajouté automatiquement).</p>

      <div class="form-row">
        <div style="display:grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap:8px; max-height:320px; overflow-y:auto; padding:12px; background:var(--bg-2); border:1px solid var(--border); border-radius:10px;">
          <?php foreach ($potential_members as $m):
              $is_self = ((int)$m['id'] === (int)$user['id']);
              $role_label = [
                  'admin' => '🛡️ Admin',
                  'coordinator' => '🧭 Coord',
                  'referent' => '🎯 Référent',
                  'member' => '👤 Membre',
                  'follower' => '👀 Suiveur',
              ][$m['role']] ?? $m['role'];
          ?>
            <label style="cursor:pointer; display:flex; align-items:center; gap:10px; padding:8px 10px; background:var(--bg); border:1px solid var(--border); border-radius:8px; transition:border-color 0.15s;"
                   onmouseover="this.style.borderColor='var(--ink-3)';" onmouseout="this.style.borderColor='var(--border)';">
              <input type="checkbox" name="team_members[]" value="<?= (int)$m['id'] ?>" style="margin:0; cursor:pointer;">
              <div style="flex:1; min-width:0;">
                <div style="font-size:13px; font-weight:500; color:var(--ink); white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">
                  <?= h($m['first_name'] . ' ' . $m['last_name']) ?><?= $is_self ? ' (vous)' : '' ?>
                </div>
                <div style="font-size:11px; color:var(--ink-3); margin-top:1px;"><?= h($role_label) ?></div>
              </div>
            </label>
          <?php endforeach; ?>
        </div>
        <div class="form-hint">💡 Astuce : tu peux toujours modifier l'équipe plus tard via la page du projet.</div>
      </div>
    </div>

    <!-- BLOC 2 : Description & objectif -->
    <div class="form-section">
      <h2 class="form-section-title">Raconter le projet</h2>
      <p class="form-section-desc">À quoi sert ce projet ? Cela aide l'IA à rédiger de meilleurs bilans.</p>

      <div class="form-row">
        <label class="form-label" for="description">Description</label>
        <textarea name="description" id="description" class="form-textarea-lg" rows="4"
                  placeholder="En quelques lignes, qu'est-ce que ce projet apporte à votre association ?"><?= h($data['description']) ?></textarea>
      </div>

      <div class="form-row">
        <label class="form-label" for="objective">Objectif principal</label>
        <textarea name="objective" id="objective" class="form-textarea-lg" rows="3"
                  placeholder="Ex : Apprendre aux élèves le script et le tournage"><?= h($data['objective']) ?></textarea>
      </div>
    </div>

    <!-- BLOC 3 : Participants & budget -->
    <div class="form-section">
      <h2 class="form-section-title">Participants et budget</h2>
      <p class="form-section-desc">Pour les bilans, rapports et subventions.</p>

      <div class="form-cols">
        <div class="form-row">
          <label class="form-label" for="participants_count">Nombre total de participants</label>
          <input type="number" name="participants_count" id="participants_count" class="form-input-lg" min="0"
                 value="<?= h($data['participants_count']) ?>" placeholder="Ex : 15">
        </div>
        <div class="form-row">
          <label class="form-label" for="budget_planned">Budget prévu</label>
          <div class="num-suffix-wrap">
            <input type="text" name="budget_planned" id="budget_planned" class="form-input-lg"
                   value="<?= h($data['budget_planned']) ?>" placeholder="Ex : 5000" inputmode="decimal">
            <span class="suffix">€</span>
          </div>
        </div>
      </div>

      <div class="form-cols">
        <div class="form-row">
          <label class="form-label" for="participants_female">Dont femmes</label>
          <input type="number" name="participants_female" id="participants_female" class="form-input-lg" min="0"
                 value="<?= h($data['participants_female']) ?>" placeholder="0">
        </div>
        <div class="form-row">
          <label class="form-label" for="participants_male">Dont hommes</label>
          <input type="number" name="participants_male" id="participants_male" class="form-input-lg" min="0"
                 value="<?= h($data['participants_male']) ?>" placeholder="0">
        </div>
      </div>

      <div class="form-cols">
        <div class="form-row">
          <label class="form-label" for="start_date">Démarrage</label>
          <input type="date" name="start_date" id="start_date" class="form-input-lg"
                 value="<?= h($data['start_date']) ?>">
        </div>
        <div class="form-row">
          <label class="form-label" for="end_date">Clôture prévue</label>
          <input type="date" name="end_date" id="end_date" class="form-input-lg"
                 value="<?= h($data['end_date']) ?>">
        </div>
      </div>
    </div>

    <!-- BLOC 4 : Étapes -->
    <div class="form-section" id="etapes">
      <h2 class="form-section-title">Les étapes du projet</h2>
      <p class="form-section-desc">
        Définissez les grandes étapes à suivre (minimum 4, idéalement 5 à 7).
        La progression du projet se calculera automatiquement à mesure que vous les validez.
        <br>💡 Tu peux aussi <strong>assigner chaque étape à un membre</strong> qui recevra un email de notification.
      </p>

      <div class="steps-builder" id="steps-builder">
        <?php foreach ($steps_input as $i => $s): 
            $s_title = is_array($s) ? ($s['title'] ?? '') : $s;
            $s_assigned = is_array($s) ? ($s['assigned_to'] ?? null) : null;
        ?>
          <div class="step-builder-item" style="display:flex; gap:8px; align-items:center; flex-wrap:wrap; margin-bottom:8px;">
            <span class="step-builder-num"><?= $i + 1 ?></span>
            <input type="text" name="step_title[]" class="step-builder-input"
                   value="<?= h($s_title) ?>" placeholder="Nom de l'étape" style="flex:2; min-width:200px;">
            
            <select name="step_assigned[]" class="step-builder-select" style="flex:1; min-width:180px; padding:8px 10px; background:var(--bg); border:1px solid var(--border-strong); border-radius:8px; font-family:inherit; font-size:13px; color:var(--ink);">
              <option value="">— Non assignée —</option>
              <?php foreach ($potential_members as $m): ?>
                <option value="<?= (int)$m['id'] ?>" <?= ((int)$s_assigned === (int)$m['id']) ? 'selected' : '' ?>>
                  👤 <?= h($m['first_name'] . ' ' . $m['last_name']) ?>
                </option>
              <?php endforeach; ?>
            </select>
            
            <button type="button" class="step-builder-remove" onclick="removeStep(this)" title="Retirer">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
          </div>
        <?php endforeach; ?>
      </div>

      <button type="button" class="add-step-btn" onclick="addStep()">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
        Ajouter une étape
      </button>
    </div>

    <!-- ACTIONS -->
    <div class="form-actions">
      <div class="form-actions-left"><span class="required">*</span> Champs obligatoires</div>
      <div class="form-actions-right">
        <a href="/projets" class="btn btn-ghost">Annuler</a>
        <button type="submit" class="btn btn-primary">Créer le projet</button>
      </div>
    </div>
  </form>

</main>

<script>
// Liste des membres pour le dropdown d'assignation (générée par PHP)
var TEAM_MEMBERS = <?= json_encode(array_map(function($m) {
    return ['id' => (int)$m['id'], 'name' => $m['first_name'] . ' ' . $m['last_name']];
}, $potential_members), JSON_UNESCAPED_UNICODE) ?>;

function buildAssignedSelect() {
    var html = '<select name="step_assigned[]" class="step-builder-select" style="flex:1; min-width:180px; padding:8px 10px; background:var(--bg); border:1px solid var(--border-strong); border-radius:8px; font-family:inherit; font-size:13px; color:var(--ink);">';
    html += '<option value="">— Non assignée —</option>';
    TEAM_MEMBERS.forEach(function(m) {
        html += '<option value="' + m.id + '">👤 ' + m.name.replace(/[<>"]/g, '') + '</option>';
    });
    html += '</select>';
    return html;
}

function addStep() {
  var container = document.getElementById('steps-builder');
  var count = container.querySelectorAll('.step-builder-item').length + 1;
  var div = document.createElement('div');
  div.className = 'step-builder-item';
  div.style.cssText = 'display:flex; gap:8px; align-items:center; flex-wrap:wrap; margin-bottom:8px;';
  div.innerHTML = '<span class="step-builder-num">' + count + '</span>' +
    '<input type="text" name="step_title[]" class="step-builder-input" placeholder="Nom de l\'étape" autofocus style="flex:2; min-width:200px;">' +
    buildAssignedSelect() +
    '<button type="button" class="step-builder-remove" onclick="removeStep(this)" title="Retirer">' +
    '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>' +
    '</button>';
  container.appendChild(div);
  div.querySelector('input').focus();
}

function removeStep(btn) {
  var container = document.getElementById('steps-builder');
  if (container.querySelectorAll('.step-builder-item').length <= 1) {
    alert('Le projet doit avoir au moins une étape.');
    return;
  }
  btn.closest('.step-builder-item').remove();
  // Renuméroter
  container.querySelectorAll('.step-builder-num').forEach(function(el, i) {
    el.textContent = i + 1;
  });
}
</script>

<?php render_foot(); ?>
