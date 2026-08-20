<?php
/**
 * ============================================================
 * ASSOKIT — Fiche Projet v2 (avec onglets)
 * ============================================================
 * URL : /projet/42/messages
 * 4 onglets : Vue / Messages / Fichiers / Assistant IA
 * ============================================================
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes-layout.php';
require_once __DIR__ . '/ai-helper.php';

require_login();

$current = current_user();
$org_id = (int)$current['org_id'];
$project_id = (int)($_GET['id'] ?? 0);
$active_tab = $_GET['tab'] ?? 'overview';

// [PACK 6.5 - SECURITY] Helper de check finances (cache budgets + onglet factures aux non-financiers)
require_once __DIR__ . '/finance-permissions.php';
$can_view_finances = user_can_view_finances($current);

// Protection follower : vérifier qu'il peut accéder à ce projet
if (!follower_can_access_project($project_id)) {
    header('Location: /projets?error=forbidden');
    exit;
}

// Pour les followers : onglets limités (pas de factures, pas d'IA interne)
$is_follower_user = is_follower();
if ($is_follower_user) {
    $valid_tabs = ['overview', 'messages'];
} else {
    $valid_tabs = ['overview', 'messages', 'fichiers', 'ia'];
    // [PACK 6.5 - SECURITY] Onglet "factures" réservé aux personnes habilitées finances
    if ($can_view_finances) {
        $valid_tabs[] = 'factures';
    }
    // Historique : admins uniquement
    if ($current['role'] === 'admin') {
        $valid_tabs[] = 'historique';
    }
}
if (!in_array($active_tab, $valid_tabs, true)) $active_tab = 'overview';

if ($project_id <= 0) {
    header('Location: /projets');
    exit;
}

// Chargement du projet
$stmt = $pdo->prepare("
    SELECT p.*, f.name AS folder_name, f.color_theme,
           u.id AS ref_id, u.first_name AS ref_first, u.last_name AS ref_last,
           u.avatar_color AS ref_color
    FROM projects p
    JOIN folders f ON p.folder_id = f.id
    LEFT JOIN users u ON p.referent_id = u.id
    WHERE p.id = ? AND f.org_id = ?
");
$stmt->execute([$project_id, $org_id]);
$project = $stmt->fetch();

if (!$project) {
    render_head('Projet introuvable');
    render_sidebar('projets');
    echo '<main class="main"><div class="empty-state" style="margin-top: 60px;">Ce projet n\'existe pas ou ne fait pas partie de votre organisation.</div></main>';
    render_foot();
    exit;
}

// Étapes
$stmt = $pdo->prepare("
    SELECT s.*, u.first_name AS by_first, u.last_name AS by_last, u.avatar_color AS by_color
    FROM project_steps s
    LEFT JOIN users u ON s.completed_by = u.id
    WHERE s.project_id = ?
    ORDER BY s.position ASC, s.id ASC
");
$stmt->execute([$project_id]);
$steps = $stmt->fetchAll();

$total_steps = count($steps);
$done_steps = 0;
foreach ($steps as $s) if ($s['is_completed']) $done_steps++;
$computed_progress = $total_steps > 0 ? (int)round(($done_steps / $total_steps) * 100) : (int)$project['progress_percent'];

// Permissions
$is_admin = ($current['role'] === 'admin');
$is_coord = ($current['role'] === 'coordinator');
$is_referent = ($project['referent_id'] == $current['id']);
// Édition des étapes : admin, coord, référent (comme avant)
$can_edit_steps = $is_admin || $is_coord || $is_referent;
// Édition du projet lui-même : admin + référent UNIQUEMENT
$can_edit_project = $is_admin || $is_referent;

// [NEW] Charger les membres potentiellement mentionnables (équipe + référent + admins de l'org)
// pour le dropdown d'autocomplete @
$mention_targets = [];
try {
    $stmt = $pdo->prepare("
        SELECT DISTINCT u.id, u.first_name, u.last_name, u.role, u.avatar_color
        FROM users u
        WHERE u.is_active = 1
          AND u.org_id = :org_id
          AND (u.deleted_at IS NULL OR u.deleted_at = '')
          AND (
              u.id IN (SELECT user_id FROM project_members WHERE project_id = :pid1)
              OR u.id = :referent_id
              OR u.role = 'admin'
          )
        ORDER BY u.first_name ASC, u.last_name ASC
    ");
    $stmt->execute([
        ':org_id' => (int)$current['org_id'],
        ':pid1' => $project_id,
        ':referent_id' => (int)($project['referent_id'] ?? 0),
    ]);
    $mention_targets = $stmt->fetchAll();
} catch (Throwable $e) {
    $mention_targets = [];
}

// Helper pour surligner les @mentions dans un message
function ak_highlight_mentions(string $content): string {
    // Échappe HTML d'abord
    $escaped = htmlspecialchars($content, ENT_QUOTES, 'UTF-8');
    // Puis surligne les @prenom (avec point/tiret optionnel pour prenom.nom)
    $highlighted = preg_replace_callback(
        '/(@[a-zA-ZÀ-ÿ][a-zA-ZÀ-ÿ\.\-]+)/u',
        function($m) {
            return '<span style="background:#DBEAFE; color:#1E40AF; padding:1px 6px; border-radius:4px; font-weight:500;">' . $m[1] . '</span>';
        },
        $escaped
    );
    return nl2br($highlighted);
}

// Messages
$stmt = $pdo->prepare("
    SELECT m.id, m.content, m.created_at, u.id AS user_id,
           u.first_name, u.last_name, u.avatar_color
    FROM project_messages m
    JOIN users u ON m.author_id = u.id
    WHERE m.project_id = ? AND m.message_type = 'text'
    ORDER BY m.created_at ASC
");
$stmt->execute([$project_id]);
$messages = $stmt->fetchAll();

// Fichiers
$stmt = $pdo->prepare("
    SELECT f.*, u.first_name, u.last_name
    FROM project_files f
    JOIN users u ON f.uploaded_by = u.id
    WHERE f.project_id = ?
    ORDER BY f.created_at DESC
");
$stmt->execute([$project_id]);
$files = $stmt->fetchAll();

// Factures liées au projet
$stmt = $pdo->prepare("
    SELECT i.*,
           u1.first_name AS up_first, u1.last_name AS up_last,
           u2.first_name AS val_first, u2.last_name AS val_last
    FROM project_invoices i
    LEFT JOIN users u1 ON i.uploaded_by = u1.id
    LEFT JOIN users u2 ON i.validated_by = u2.id
    WHERE i.project_id = ?
    ORDER BY
        FIELD(i.status, 'pending', 'validated', 'rejected'),
        i.invoice_date DESC
");
$stmt->execute([$project_id]);
$invoices = $stmt->fetchAll();

// Calcul des totaux factures
$total_validated = 0;
$total_validated_ht = 0;
$total_pending = 0;
$count_pending = 0;
foreach ($invoices as $inv) {
    if ($inv['status'] === 'validated') {
        $total_validated += (float)$inv['amount_ttc'];
        $total_validated_ht += (float)($inv['amount_ht'] ?? $inv['amount_ttc']);
    } elseif ($inv['status'] === 'pending') {
        $total_pending += (float)$inv['amount_ttc'];
        $count_pending++;
    }
}
$total_vat = $total_validated - $total_validated_ht;
$budget_remaining = (float)$project['budget_planned'] - $total_validated;

// Conversation IA active
$active_conv_id = (int)($_GET['conv'] ?? 0);
$active_conv_messages = [];
if ($active_conv_id > 0) {
    $stmt = $pdo->prepare("SELECT role, content FROM ai_messages WHERE conversation_id = ? ORDER BY created_at ASC, id ASC");
    $stmt->execute([$active_conv_id]);
    $active_conv_messages = $stmt->fetchAll();
}

// Documents IA générés
$stmt = $pdo->prepare("
    SELECT g.*, u.first_name, u.last_name
    FROM ai_generated_docs g
    JOIN users u ON g.user_id = u.id
    WHERE g.project_id = ?
    ORDER BY g.created_at DESC
");
$stmt->execute([$project_id]);
$generated_docs = $stmt->fetchAll();

// Helpers
function status_info_p($s) {
    return [
        'active' => ['label' => 'En cours', 'class' => 'badge-ok'],
        'warning' => ['label' => 'À surveiller', 'class' => 'badge-warn'],
        'done' => ['label' => 'Terminé', 'class' => 'badge-done'],
        'draft' => ['label' => 'Brouillon', 'class' => 'badge-done'],
        'archived' => ['label' => 'Archivé', 'class' => 'badge-done'],
    ][$s] ?? ['label' => 'En cours', 'class' => 'badge-ok'];
}
function format_date_p($d) {
    if (!$d) return '—';
    $m = ['janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'];
    $t = strtotime($d);
    return (int)date('j', $t) . ' ' . $m[(int)date('n', $t) - 1] . ' ' . date('Y', $t);
}
function format_time_p($d) {
    if (!$d) return '';
    $today = date('Y-m-d');
    $dstr = date('Y-m-d', strtotime($d));
    if ($dstr === $today) return "Aujourd'hui, " . date('H:i', strtotime($d));
    if ($dstr === date('Y-m-d', strtotime('-1 day'))) return 'Hier, ' . date('H:i', strtotime($d));
    return format_date_p($d) . ' à ' . date('H:i', strtotime($d));
}
function file_icon_class($mime, $filename) {
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    if ($ext === 'pdf') return 'pdf';
    if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) return 'img';
    if (in_array($ext, ['doc', 'docx', 'odt', 'txt'])) return 'doc';
    return '';
}
function format_filesize($b) {
    if ($b < 1024) return $b . ' o';
    if ($b < 1048576) return round($b / 1024) . ' Ko';
    return round($b / 1048576, 1) . ' Mo';
}

$budget_pct = $project['budget_planned'] > 0
    ? min(100, (int)round(($project['budget_used'] / $project['budget_planned']) * 100))
    : 0;
$ref_color = in_array($project['ref_color'], ['blue','purple','amber','pink','teal'], true)
    ? 'av-' . $project['ref_color'] : 'av-blue';
$status = status_info_p($project['status']);
$ai_ready = is_ai_enabled();

render_head($project['name']);
render_sidebar('projets');
?>

<main class="main">

  <nav class="crumbs">
    <a href="/dashboard">Dashboard</a>
    <span class="sep">›</span>
    <a href="/projets">Projets</a>
    <span class="sep">›</span>
    <a href="/projets#f<?= (int)$project['folder_id'] ?>"><?= h($project['folder_name']) ?></a>
    <span class="sep">›</span>
    <span class="current"><?= h($project['name']) ?></span>
  </nav>

  <?php if (isset($_GET['updated'])): ?>
    <div class="alert alert-success">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
      ✅ Projet mis à jour. Les modifications sont enregistrées dans l'historique.
    </div>
  <?php elseif (isset($_GET['duplicated'])): ?>
    <div class="alert alert-success">
      ✨ Projet dupliqué avec succès. Tu peux maintenant l'adapter.
    </div>
  <?php elseif (isset($_GET['error'])):
    $err_labels = [
      'permission' => 'Seuls l\'administrateur et le référent du projet peuvent modifier ce projet.',
      'csrf' => 'Session expirée, réessayez.',
      'forbidden' => 'Vous n\'avez pas accès à ce projet.',
    ];
    $err_msg = $err_labels[$_GET['error']] ?? $_GET['error'];
  ?>
    <div class="alert alert-error">⚠️ <?= h($err_msg) ?></div>
  <?php endif; ?>

  <div class="proj-header">
    <div class="proj-header-icon <?= folder_icon_class($project['color_theme']) ?>">
      <?= folder_icon_svg($project['color_theme']) ?>
    </div>
    <div class="proj-header-info">
      <div class="proj-header-tag"><?= h($project['folder_name']) ?></div>
      <h1 class="proj-header-title"><?= h($project['name']) ?></h1>
      <div class="proj-header-meta">
        <span class="project-badge <?= $status['class'] ?>"><?= h($status['label']) ?></span>
        <?php if ($project['location']): ?>
          <span class="dot">·</span><span><?= h($project['location']) ?></span>
        <?php endif; ?>
        <?php if ($project['ref_first']): ?>
          <span class="dot">·</span>
          <span class="referent-tag">
            <span class="ref-avatar <?= $ref_color ?>"><?= h(user_initials($project['ref_first'], $project['ref_last'])) ?></span>
            <?= h($project['ref_first'] . ' ' . $project['ref_last']) ?>, référent
          </span>
        <?php endif; ?>
      </div>
    </div>
    <?php if ($can_edit_project || $is_admin): ?>
    <div class="head-actions" style="display:flex; gap:8px; flex-wrap:wrap;">
      <?php if ($can_edit_project): ?>
      <a href="/modifier-projet/<?= (int)$project['id'] ?>" class="btn btn-ghost">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
        Modifier
      </a>
      <?php endif; ?>
      <?php if ($is_admin && empty($project['archived_at'])): ?>
      <a href="/supprimer-projet/<?= (int)$project['id'] ?>"
         style="display:inline-flex; align-items:center; gap:6px; padding:8px 14px; font-size:13px; color:#991B1B; background:transparent; border:1px solid #FCA5A5; border-radius:8px; text-decoration:none; font-weight:500; transition:all 0.15s; font-family:inherit;"
         onmouseover="this.style.background='#FEF2F2'; this.style.borderColor='#DC2626'"
         onmouseout="this.style.background='transparent'; this.style.borderColor='#FCA5A5'"
         title="Archiver ce projet (restaurable 30 jours)">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-2 14a2 2 0 0 1-2 2H9a2 2 0 0 1-2-2L5 6"/></svg>
        Archiver
      </a>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </div>

  <!-- Onglets -->
  <div class="tabs">
    <a href="/projet/<?= $project_id ?>" class="tab <?= $active_tab === 'overview' ? 'active' : '' ?>">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="3" width="7" height="9"/><rect x="14" y="3" width="7" height="5"/><rect x="14" y="12" width="7" height="9"/><rect x="3" y="16" width="7" height="5"/></svg>
      Vue d'ensemble
    </a>
    <a href="/projet/<?= $project_id ?>/messages" class="tab <?= $active_tab === 'messages' ? 'active' : '' ?>">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 4h16v14H5.17L4 19.17z"/></svg>
      Messages
      <?php if (count($messages) > 0): ?><span class="tab-badge"><?= count($messages) ?></span><?php endif; ?>
    </a>
    <?php if (!$is_follower_user): ?>
    <a href="/projet/<?= $project_id ?>/fichiers" class="tab <?= $active_tab === 'fichiers' ? 'active' : '' ?>">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M13 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"/><polyline points="13 2 13 9 20 9"/></svg>
      Fichiers
      <?php if (count($files) > 0): ?><span class="tab-badge"><?= count($files) ?></span><?php endif; ?>
    </a>
    <?php if (can('manage_finances')): ?>
    <a href="/projet/<?= $project_id ?>/factures" class="tab <?= $active_tab === 'factures' ? 'active' : '' ?>">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M9 11H3v8h6v-8z"/><path d="M21 3H9v16h12V3z"/><line x1="13" y1="7" x2="17" y2="7"/><line x1="13" y1="11" x2="17" y2="11"/></svg>
      Factures
      <?php if (count($invoices) > 0): ?><span class="tab-badge"><?= count($invoices) ?></span><?php endif; ?>
    </a>
    <?php endif; ?>
    <a href="/projet/<?= $project_id ?>/ia" class="tab tab-ai <?= $active_tab === 'ia' ? 'active' : '' ?>">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
      Assistant IA
    </a>
    <?php if ($is_admin): ?>
    <a href="/projet/<?= $project_id ?>/historique" class="tab <?= $active_tab === 'historique' ? 'active' : '' ?>" style="margin-left: auto;" title="Historique des modifications (visible par les administrateurs uniquement)">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
      Historique
    </a>
    <?php endif; ?>
    <?php endif; ?>
  </div>

  <?php if ($active_tab === 'overview'): ?>
    <!-- ===== VUE D'ENSEMBLE ===== -->
    <div class="proj-layout">
      <div>
        <div class="panel">
          <div class="panel-title">Progression globale</div>
          <div class="big-progress">
            <div class="big-progress-bar-bg">
              <div class="big-progress-bar <?= $project['status'] === 'warning' ? 'warn' : '' ?>" style="width:<?= $computed_progress ?>%"></div>
            </div>
            <div class="big-progress-info">
              <span><b><?= $done_steps ?></b> sur <b><?= $total_steps ?></b> étapes terminées</span>
              <span><b><?= $computed_progress ?> %</b></span>
            </div>
          </div>
        </div>

        <?php if ($project['description']): ?>
        <div class="panel">
          <div class="panel-title">Description</div>
          <div class="proj-desc"><?= nl2br(h($project['description'])) ?></div>
        </div>
        <?php endif; ?>

        <?php if ($project['objective']): ?>
        <div class="panel">
          <div class="panel-title">🎯 Objectif</div>
          <div class="proj-objective"><?= nl2br(h($project['objective'])) ?></div>
        </div>
        <?php endif; ?>

        <div class="panel">
          <div class="panel-title">
            Étapes du projet
            <?php if ($can_edit_steps): ?>
              <a href="/modifier-etapes?id=<?= (int)$project['id'] ?>" class="panel-title-actions">⚙️ Modifier les étapes</a>
            <?php endif; ?>
          </div>
          <?php if (empty($steps)): ?>
            <div class="empty-state" style="padding: 20px;">Aucune étape définie.</div>
          <?php else: ?>
            <div class="step-list">
              <?php foreach ($steps as $step):
                $by_color = in_array($step['by_color'], ['blue','purple','amber','pink','teal'], true)
                  ? 'av-' . $step['by_color'] : 'av-blue';
              ?>
              <div class="step-item <?= $step['is_completed'] ? 'done' : '' ?>">
                <?php if ($can_edit_steps): ?>
                  <form method="POST" action="/action-etape" style="display: inline; margin: 0;">
                    <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
                    <input type="hidden" name="step_id" value="<?= (int)$step['id'] ?>">
                    <input type="hidden" name="project_id" value="<?= (int)$project['id'] ?>">
                    <button type="submit" class="step-check <?= $step['is_completed'] ? 'done' : '' ?>">
                      <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg>
                    </button>
                  </form>
                <?php else: ?>
                  <span class="step-check readonly <?= $step['is_completed'] ? 'done' : '' ?>">
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg>
                  </span>
                <?php endif; ?>
                <div class="step-body">
                  <div class="step-title"><?= h($step['title']) ?></div>
                  <?php if ($step['description']): ?><div class="step-desc"><?= nl2br(h($step['description'])) ?></div><?php endif; ?>
                  <?php if ($step['is_completed'] && $step['by_first']): ?>
                    <div class="step-meta">
                      <span class="<?= $by_color ?>" style="width:16px;height:16px;font-size:8px;display:inline-flex;align-items:center;justify-content:center;border-radius:50%;font-weight:500;"><?= h(user_initials($step['by_first'], $step['by_last'])) ?></span>
                      Validée par <?= h($step['by_first'] . ' ' . $step['by_last']) ?> · <?= h(format_date_p($step['completed_at'])) ?>
                    </div>
                  <?php endif; ?>
                </div>
              </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <aside>
        <?php if ($can_view_finances): ?>
        <div class="side-panel">
          <div class="side-panel-label">Budget</div>
          <div class="side-panel-value"><?= h(format_budget($project['budget_used'])) ?></div>
          <div class="side-panel-sub">sur <?= h(format_budget($project['budget_planned'])) ?> prévus</div>
          <div class="budget-bar-bg"><div class="budget-bar <?= $budget_pct >= 90 ? 'over' : '' ?>" style="width:<?= $budget_pct ?>%"></div></div>
          <div class="side-panel-sub" style="margin-top: 6px; text-align: right;"><?= $budget_pct ?> % engagé</div>
        </div>
        <?php endif; ?>

        <?php if ($project['participants_count'] > 0): ?>
        <div class="side-panel">
          <div class="side-panel-label">Participants</div>
          <div class="side-panel-value"><?= (int)$project['participants_count'] ?></div>
          <?php $pf = (int)$project['participants_female']; $pm = (int)$project['participants_male']; $th = $pf + $pm; ?>
          <?php if ($th > 0): ?>
            <div class="side-participants-bars">
              <div class="bar-f" style="width: <?= ($pf / $th) * 100 ?>%"></div>
              <div class="bar-m" style="width: <?= ($pm / $th) * 100 ?>%"></div>
            </div>
            <div class="side-participants-legend">
              <span>♀ <?= $pf ?></span><span>♂ <?= $pm ?></span>
            </div>
          <?php endif; ?>
        </div>
        <?php endif; ?>

        <div class="side-panel">
          <div class="side-panel-label">Informations</div>
          <?php if ($project['start_date']): ?><div class="side-kv"><span class="side-kv-label">Démarrage</span><span class="side-kv-value"><?= h(format_date_p($project['start_date'])) ?></span></div><?php endif; ?>
          <?php if ($project['end_date']): ?><div class="side-kv"><span class="side-kv-label">Clôture</span><span class="side-kv-value"><?= h(format_date_p($project['end_date'])) ?></span></div><?php endif; ?>
          <div class="side-kv"><span class="side-kv-label">Créé le</span><span class="side-kv-value"><?= h(format_date_p($project['created_at'])) ?></span></div>
        </div>
      </aside>
    </div>

  <?php elseif ($active_tab === 'messages'): ?>
    <!-- ===== MESSAGES ===== -->
    <div class="chat-wrap">
      <div class="chat-list" id="chatList">
        <?php if (empty($messages)): ?>
          <div class="empty-state">Aucun message pour l'instant. Démarrez la conversation d'équipe ci-dessous.</div>
        <?php else: foreach ($messages as $m):
          $color = in_array($m['avatar_color'], ['blue','purple','amber','pink','teal'], true) ? 'av-' . $m['avatar_color'] : 'av-blue';
          $is_self = ($m['user_id'] == $current['id']);
        ?>
        <div class="chat-msg">
          <span class="chat-avatar <?= $color ?>"><?= h(user_initials($m['first_name'], $m['last_name'])) ?></span>
          <div class="chat-bubble">
            <div class="chat-head-line">
              <span class="chat-author"><?= h($m['first_name'] . ' ' . $m['last_name']) ?><?= $is_self ? ' (vous)' : '' ?></span>
              <span class="chat-time"><?= h(format_time_p($m['created_at'])) ?></span>
            </div>
            <div class="chat-content"><?= ak_highlight_mentions($m['content']) ?></div>
          </div>
        </div>
        <?php endforeach; endif; ?>
      </div>
      <form method="POST" action="/action-message" class="chat-form" style="position:relative;">
        <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
        <input type="hidden" name="project_id" value="<?= $project_id ?>">
        
        <!-- Dropdown autocomplete @mentions (caché par défaut, JS le montre) -->
        <div id="mentionDropdown" style="display:none; position:absolute; bottom:calc(100% + 4px); left:0; right:0; background:var(--bg); border:1px solid var(--border); border-radius:10px; box-shadow:0 -4px 16px rgba(0,0,0,0.08); max-height:240px; overflow-y:auto; z-index:50;">
          <div style="font-size:11px; color:var(--ink-3); padding:8px 12px 4px; font-weight:500; text-transform:uppercase; letter-spacing:0.04em;">
            👥 Mentionner un membre
          </div>
          <div id="mentionList"></div>
        </div>
        
        <textarea name="content" class="chat-input" placeholder="Écrire un message… Tape @ pour mentionner un membre (il recevra un email)" required maxlength="5000" rows="1" id="chatInput" autocomplete="off"></textarea>
        <button type="submit" class="chat-send">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
          Envoyer
        </button>
      </form>
      
      <!-- Données JSON pour le JS autocomplete -->
      <script id="mentionDataJson" type="application/json"><?= json_encode(array_map(function($u) {
        return [
            'id' => (int)$u['id'],
            'first' => $u['first_name'],
            'last' => $u['last_name'],
            'name' => trim($u['first_name'] . ' ' . $u['last_name']),
            'role' => $u['role'],
            'color' => $u['avatar_color'] ?? 'blue',
            'initials' => mb_strtoupper(mb_substr($u['first_name'], 0, 1) . mb_substr($u['last_name'], 0, 1)),
            'tag' => mb_strtolower($u['first_name']),
        ];
      }, $mention_targets), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
    </div>

  <?php elseif ($active_tab === 'fichiers'): ?>
    <!-- ===== FICHIERS ===== -->
    <form method="POST" action="/action-fichier" enctype="multipart/form-data" class="drop-zone" onclick="document.getElementById('fileInput').click();" style="margin-bottom: 20px;">
      <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
      <input type="hidden" name="project_id" value="<?= $project_id ?>">
      <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
      <div class="drop-zone-title">Ajouter des fichiers au projet</div>
      <div class="drop-zone-sub">PDF, images, documents Word/Excel · jusqu'à 10 Mo par fichier · sélection multiple ⌘+clic</div>
      <div class="drop-zone-ai">
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
        <span>L'IA utilisera ces fichiers pour rédiger vos bilans</span>
      </div>
      <input type="file" id="fileInput" name="files[]" multiple style="display:none;" onchange="this.form.submit();" accept=".pdf,.jpg,.jpeg,.png,.gif,.webp,.doc,.docx,.xls,.xlsx,.txt,.csv,.ppt,.pptx,.odt,.ods">
    </form>

    <?php if (!empty($_SESSION['flash']) && $active_tab === 'fichiers'): 
        $f = $_SESSION['flash']; unset($_SESSION['flash']);
    ?>
      <div class="alert alert-<?= $f['type'] === 'error' ? 'error' : 'success' ?>"><?= h($f['message']) ?></div>
    <?php endif; ?>

    <?php if (isset($_GET['err'])): ?>
      <div class="alert alert-error">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/></svg>
        <?php
          $errs = ['size' => 'Fichier trop gros (max 10 Mo).', 'type' => 'Type non autorisé.', 'upload' => 'Erreur d\'upload.', 'mkdir' => 'Impossible de créer le dossier.', 'move' => 'Impossible de sauvegarder.'];
          echo h($errs[$_GET['err']] ?? 'Erreur inconnue');
        ?>
      </div>
    <?php endif; ?>

    <?php if (empty($files)): ?>
      <div class="panel"><div class="empty-state">Aucun fichier pour l'instant.</div></div>
    <?php else: ?>
      <div class="files-grid">
        <?php foreach ($files as $f): 
            $ic = file_icon_class($f['mime_type'], $f['filename']);
            // Permission de suppression : admin OU référent OU uploader
            $can_delete_file = $is_admin || $is_referent || ((int)$f['uploaded_by'] === (int)$current['id']);
        ?>
        <div class="file-card" style="position:relative;">
          <a href="/fichier-projet?type=file&amp;id=<?= (int)$f['id'] ?>" target="_blank" rel="noopener" style="display:flex; align-items:center; gap:12px; flex:1; text-decoration:none; color:inherit;">
            <div class="file-icon <?= $ic ?>">
              <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M13 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"/><polyline points="13 2 13 9 20 9"/></svg>
            </div>
            <div class="file-info">
              <div class="file-name"><?= h($f['filename']) ?></div>
              <div class="file-meta"><?= h(format_filesize($f['filesize_bytes'])) ?> · <?= h(format_date_p($f['created_at'])) ?><br><?= h($f['first_name'] . ' ' . $f['last_name']) ?></div>
            </div>
          </a>
          <?php if ($can_delete_file): ?>
            <form method="POST" action="/action-fichier-supprimer" style="margin:0;"
                  onsubmit="return confirm('Supprimer définitivement « <?= h(addslashes($f['filename'])) ?> » ?');">
              <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
              <input type="hidden" name="file_id" value="<?= (int)$f['id'] ?>">
              <input type="hidden" name="project_id" value="<?= $project_id ?>">
              <button type="submit" 
                      style="background:transparent; border:none; color:#DC2626; cursor:pointer; padding:6px; border-radius:6px; transition:background 0.15s;"
                      onmouseover="this.style.background='#FEF2F2'"
                      onmouseout="this.style.background='transparent'"
                      title="Supprimer ce fichier">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-2 14a2 2 0 0 1-2 2H9a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6M14 11v6"/></svg>
              </button>
            </form>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

  <?php elseif ($active_tab === 'factures'): ?>
    <!-- ===== FACTURES DU PROJET ===== -->
    <?php
      $can_validate = $is_admin || $is_coord;
      $can_add = true; // tous les membres de l'org peuvent ajouter une facture
    ?>

    <!-- Messages de confirmation -->
    <?php if (isset($_GET['added'])): ?>
      <div class="alert alert-success"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>Facture ajoutée avec succès.</div>
    <?php elseif (isset($_GET['validated'])): ?>
      <div class="alert alert-success"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>Facture validée. Le budget a été mis à jour automatiquement.</div>
    <?php elseif (isset($_GET['rejected'])): ?>
      <div class="alert alert-success"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>Facture rejetée.</div>
    <?php elseif (isset($_GET['deleted'])): ?>
      <div class="alert alert-success"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>Facture supprimée.</div>
    <?php elseif (isset($_GET['err'])): ?>
      <div class="alert alert-error"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/></svg><?php
        $errs = ['invalid' => 'Merci de remplir le fournisseur, le montant et la date.', 'filetype' => 'Format de fichier non autorisé (PDF, JPG, PNG uniquement).', 'filesize' => 'Fichier trop gros (max 10 Mo).'];
        echo h($errs[$_GET['err']] ?? 'Une erreur est survenue.');
      ?></div>
    <?php endif; ?>

    <!-- Résumé financier live -->
    <div class="inv-summary">
      <div class="inv-summary-card primary">
        <div class="inv-summary-lbl">Budget utilisé</div>
        <div class="inv-summary-val"><?= h(format_budget($total_validated)) ?> <span style="font-size: 11px; color: var(--ink-4); font-weight: 400;">TTC</span></div>
        <?php if ($total_vat > 0.01): ?>
          <div class="inv-summary-sub"><?= h(format_budget($total_validated_ht)) ?> HT · <?= h(format_budget($total_vat)) ?> TVA</div>
        <?php else: ?>
          <div class="inv-summary-sub">sur <?= h(format_budget($project['budget_planned'])) ?> prévus</div>
        <?php endif; ?>
      </div>
      <div class="inv-summary-card">
        <div class="inv-summary-lbl">Reste disponible</div>
        <div class="inv-summary-val" style="color: <?= $budget_remaining >= 0 ? 'var(--acc-dark)' : '#B91C1C' ?>"><?= h(format_budget($budget_remaining)) ?></div>
        <div class="inv-summary-sub"><?= $budget_remaining >= 0 ? 'Sur budget prévu TTC' : 'Dépassement' ?></div>
      </div>
      <?php if ($count_pending > 0): ?>
      <div class="inv-summary-card warn">
        <div class="inv-summary-lbl">En attente</div>
        <div class="inv-summary-val"><?= h(format_budget($total_pending)) ?></div>
        <div class="inv-summary-sub"><?= $count_pending ?> facture<?= $count_pending > 1 ? 's' : '' ?> à valider</div>
      </div>
      <?php endif; ?>
      <div class="inv-summary-card">
        <div class="inv-summary-lbl">Total factures</div>
        <div class="inv-summary-val"><?= count($invoices) ?></div>
        <div class="inv-summary-sub">sur ce projet</div>
      </div>
    </div>

    <!-- Formulaire d'ajout -->
    <?php if ($can_add): ?>
    <div class="inv-add-form">
      <h3 class="inv-add-title">➕ Ajouter une facture à ce projet</h3>

      <!-- Zone scan IA (visible si l'IA est configurée) -->
      <?php if ($ai_ready): ?>
      <div id="scanZone" style="background: linear-gradient(135deg, var(--ai-light) 0%, var(--bg) 100%); border: 1px dashed var(--ai); border-radius: 12px; padding: 18px 20px; margin-bottom: 18px;">
        <div style="display: flex; gap: 14px; align-items: flex-start;">
          <div style="width: 38px; height: 38px; border-radius: 10px; background: var(--ai); color: #fff; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
          </div>
          <div style="flex: 1;">
            <div style="font-size: 14.5px; font-weight: 500; margin-bottom: 3px;">Laissez l'IA lire votre facture</div>
            <div style="font-size: 12.5px; color: var(--ink-2); line-height: 1.5; margin-bottom: 10px;">Glissez votre PDF ou photo de facture, l'IA extrait automatiquement le fournisseur, les montants HT/TVA/TTC, la date et la catégorie.</div>
            <input type="file" id="scanFileInput" accept=".pdf,.jpg,.jpeg,.png" style="display:none;">
            <button type="button" id="scanBtn" class="btn btn-primary" style="padding: 8px 14px;">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
              Choisir une facture à scanner
            </button>
            <div id="scanStatus" style="margin-top: 10px; font-size: 12.5px; display: none;"></div>
          </div>
        </div>
      </div>

      <div style="text-align: center; margin: 0 0 18px; font-size: 11px; color: var(--ink-4); letter-spacing: 0.05em; text-transform: uppercase;">— ou remplir à la main —</div>
      <?php endif; ?>

      <form method="POST" action="/action-facture" enctype="multipart/form-data" id="invoiceForm">
        <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
        <input type="hidden" name="project_id" value="<?= $project_id ?>">
        <input type="hidden" name="action" value="add">
        <input type="hidden" name="temp_file" id="tempFileInput" value="">

        <div class="form-cols">
          <div class="form-row">
            <label class="form-label">Fournisseur <span class="required">*</span></label>
            <input type="text" name="supplier_name" class="form-input-lg" required placeholder="Ex : Fnac Pro" maxlength="120">
          </div>
          <div class="form-row">
            <label class="form-label">Catégorie</label>
            <select name="category" class="form-select-lg">
              <option value="">— Choisir —</option>
              <option value="Matériel vidéo">Matériel vidéo</option>
              <option value="Matériel audio">Matériel audio</option>
              <option value="Matériel informatique">Matériel informatique</option>
              <option value="Fournitures">Fournitures</option>
              <option value="Alimentation">Alimentation</option>
              <option value="Transport">Transport</option>
              <option value="Location">Location</option>
              <option value="Télécom">Télécom</option>
              <option value="Livres / Matériel pédagogique">Livres / Matériel pédagogique</option>
              <option value="Frais administratifs">Frais administratifs</option>
              <option value="Prestations externes">Prestations externes</option>
              <option value="Autre">Autre</option>
            </select>
          </div>
        </div>

        <!-- Mode de saisie HT / TTC / Pas de TVA -->
        <div class="form-row">
          <label class="form-label">Mode de saisie du montant</label>
          <div class="filter-chips" style="gap: 6px;">
            <label class="chip amount-mode-chip active" data-mode="ttc">
              <input type="radio" name="amount_mode" value="ttc" checked style="display:none;">
              Je connais le TTC
            </label>
            <label class="chip amount-mode-chip" data-mode="ht">
              <input type="radio" name="amount_mode" value="ht" style="display:none;">
              Je connais le HT
            </label>
            <label class="chip amount-mode-chip" data-mode="no_vat">
              <input type="radio" name="amount_mode" value="no_vat" style="display:none;">
              Pas de TVA (asso non assujettie)
            </label>
          </div>
          <div class="form-hint">Choisissez selon ce qui est indiqué sur votre facture. Le calcul se fait automatiquement.</div>
        </div>

        <!-- Champs de montant : TTC + TVA -->
        <div class="form-cols amount-fields" id="modeTtc">
          <div class="form-row">
            <label class="form-label">Montant TTC <span class="required">*</span></label>
            <div class="num-suffix-wrap">
              <input type="text" name="amount_ttc" id="inputTtc" class="form-input-lg" placeholder="0,00" inputmode="decimal">
              <span class="suffix">€</span>
            </div>
            <div class="form-hint" id="hintHtComputed">HT calculé : <b>—</b></div>
          </div>
          <div class="form-row">
            <label class="form-label">Taux de TVA</label>
            <select name="vat_rate" id="vatRateTtc" class="form-select-lg">
              <option value="20" selected>20 % (taux normal)</option>
              <option value="10">10 % (taux intermédiaire)</option>
              <option value="5.5">5,5 % (taux réduit)</option>
              <option value="2.1">2,1 % (taux super réduit)</option>
              <option value="0">0 % (non assujetti / exonéré)</option>
            </select>
          </div>
        </div>

        <!-- Champs de montant : HT + TVA (caché par défaut) -->
        <div class="form-cols amount-fields" id="modeHt" style="display:none;">
          <div class="form-row">
            <label class="form-label">Montant HT <span class="required">*</span></label>
            <div class="num-suffix-wrap">
              <input type="text" name="amount_ht" id="inputHt" class="form-input-lg" placeholder="0,00" inputmode="decimal">
              <span class="suffix">€</span>
            </div>
            <div class="form-hint" id="hintTtcComputed">TTC calculé : <b>—</b></div>
          </div>
          <div class="form-row">
            <label class="form-label">Taux de TVA</label>
            <select id="vatRateHt" class="form-select-lg">
              <option value="20" selected>20 % (taux normal)</option>
              <option value="10">10 % (taux intermédiaire)</option>
              <option value="5.5">5,5 % (taux réduit)</option>
              <option value="2.1">2,1 % (taux super réduit)</option>
            </select>
          </div>
        </div>

        <!-- Champs de montant : juste un montant (pas de TVA) -->
        <div class="form-row amount-fields" id="modeNoVat" style="display:none;">
          <label class="form-label">Montant <span class="required">*</span></label>
          <div class="num-suffix-wrap" style="max-width: 280px;">
            <input type="text" name="amount_ttc" id="inputNoVat" class="form-input-lg" placeholder="0,00" inputmode="decimal" disabled>
            <span class="suffix">€</span>
          </div>
          <div class="form-hint">Votre association n'est pas assujettie à la TVA. HT = TTC.</div>
        </div>

        <div class="form-cols">
          <div class="form-row">
            <label class="form-label">Date de la facture <span class="required">*</span></label>
            <input type="date" name="invoice_date" class="form-input-lg" required value="<?= h(date('Y-m-d')) ?>">
          </div>
          <div class="form-row">
            <label class="form-label">N° de facture (optionnel)</label>
            <input type="text" name="invoice_number" class="form-input-lg" placeholder="Ex : 2026-04-123" maxlength="60">
          </div>
        </div>

        <div class="form-row">
          <label class="form-label">Description / détail (optionnel)</label>
          <input type="text" name="description" class="form-input-lg" placeholder="Ex : Caméra Sony FX3 + 2 objectifs pour tournage du 4-6 mai" maxlength="500">
        </div>

        <div class="form-row">
          <label class="form-label" style="display: flex; align-items: center; gap: 8px;">
            <a href="#" id="toggleManualFile" style="color: var(--acc); font-weight: 500; font-size: 12.5px; text-decoration: none; display: inline-flex; align-items: center; gap: 5px;">
              <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"/></svg>
              <span id="toggleManualLabel">Joindre un justificatif manuellement</span>
            </a>
          </label>
          <div id="manualFileZone" style="display: none; margin-top: 8px;">
            <input type="file" name="invoice_file" accept=".pdf,.jpg,.jpeg,.png" class="form-input-lg">
            <div class="form-hint">PDF, JPG ou PNG — max 10 Mo. Utile si le scan IA n'a pas fonctionné ou si vous voulez ajouter un justificatif en plus.</div>
          </div>
        </div>

        <?php if ($can_validate): ?>
          <div class="form-hint" style="margin-bottom: 12px;">ℹ️ En tant qu'<?= h(role_label($current['role'])) ?>, votre facture sera <strong>automatiquement validée</strong> et déduite du budget.</div>
        <?php else: ?>
          <div class="form-hint" style="margin-bottom: 12px;">ℹ️ Votre facture sera <strong>en attente de validation</strong> par un admin ou coordinateur avant d'impacter le budget.</div>
        <?php endif; ?>

        <div style="display: flex; justify-content: flex-end; gap: 10px;">
          <button type="submit" class="btn btn-primary">Ajouter la facture</button>
        </div>
      </form>
    </div>
    <?php endif; ?>

    <!-- Liste des factures -->
    <?php if (empty($invoices)): ?>
      <div class="panel"><div class="empty-state">Aucune facture pour ce projet. Ajoutez la première ci-dessus.</div></div>
    <?php else: ?>
      <div class="inv-list-project">
        <div class="inv-row-project inv-row-header-proj">
          <span>Facture</span>
          <span>Montant</span>
          <span>Statut</span>
          <span></span>
        </div>
        <?php foreach ($invoices as $inv):
          $st = [
            'validated' => ['label' => 'Validée', 'class' => 'status-validated'],
            'pending' => ['label' => 'En attente', 'class' => 'status-pending'],
            'rejected' => ['label' => 'Rejetée', 'class' => 'status-rejected'],
          ][$inv['status']] ?? ['label' => '—', 'class' => ''];
        ?>
        <div class="inv-row-project">
          <div class="inv-row-main">
            <div class="inv-row-supplier"><?= h($inv['supplier_name']) ?></div>
            <div class="inv-row-details">
              <?php if ($inv['category']): ?>
                <span><?= h($inv['category']) ?></span>
                <span class="dot">·</span>
              <?php endif; ?>
              <span><?= h(format_date_p($inv['invoice_date'])) ?></span>
              <?php if ($inv['description']): ?>
                <span class="dot">·</span>
                <span><?= h($inv['description']) ?></span>
              <?php endif; ?>
              <?php if ($inv['file_path']): ?>
                <span class="dot">·</span>
                <a href="/fichier-projet?type=invoice&amp;id=<?= (int)$inv['id'] ?>" target="_blank" rel="noopener" class="inv-file-link">
                  <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M13 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"/><polyline points="13 2 13 9 20 9"/></svg>
                  PDF
                </a>
              <?php endif; ?>
              <span class="dot">·</span>
              <span>par <?= h($inv['up_first'] . ' ' . $inv['up_last']) ?></span>
            </div>
          </div>
          <div class="inv-row-amount" style="min-width: 110px; text-align: right;">
            <div style="font-size: 14.5px; font-weight: 500; font-variant-numeric: tabular-nums; line-height: 1.2;"><?= h(format_budget($inv['amount_ttc'])) ?> <span style="font-size: 10px; color: var(--ink-4); font-weight: 400;">TTC</span></div>
            <?php if (!empty($inv['amount_ht']) && $inv['amount_ht'] != $inv['amount_ttc']): ?>
              <div style="font-size: 11.5px; color: var(--ink-3); font-variant-numeric: tabular-nums;"><?= h(format_budget($inv['amount_ht'])) ?> HT <span style="color: var(--ink-4);">· TVA <?= rtrim(rtrim(number_format((float)$inv['vat_rate'], 2, ',', ''), '0'), ',') ?> %</span></div>
            <?php elseif (!empty($inv['amount_ht']) && (float)$inv['vat_rate'] === 0.0): ?>
              <div style="font-size: 11.5px; color: var(--ink-4);">Sans TVA</div>
            <?php endif; ?>
          </div>
          <span class="inv-row-status inv-status <?= $st['class'] ?>"><?= h($st['label']) ?></span>
          <div class="inv-actions-inline">
            <?php if ($inv['status'] === 'pending' && $can_validate): ?>
              <form method="POST" action="/action-facture" style="margin:0;">
                <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="project_id" value="<?= $project_id ?>">
                <input type="hidden" name="invoice_id" value="<?= (int)$inv['id'] ?>">
                <input type="hidden" name="action" value="validate">
                <button type="submit" class="inv-btn-sm inv-btn-validate" title="Valider">
                  <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                  Valider
                </button>
              </form>
              <form method="POST" action="/action-facture" style="margin:0;">
                <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="project_id" value="<?= $project_id ?>">
                <input type="hidden" name="invoice_id" value="<?= (int)$inv['id'] ?>">
                <input type="hidden" name="action" value="reject">
                <button type="submit" class="inv-btn-sm inv-btn-reject" title="Rejeter">
                  <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                </button>
              </form>
            <?php endif; ?>
            <?php if ($is_admin): ?>
              <form method="POST" action="/action-facture" style="margin:0;" onsubmit="return confirm('Supprimer définitivement cette facture ?');">
                <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="project_id" value="<?= $project_id ?>">
                <input type="hidden" name="invoice_id" value="<?= (int)$inv['id'] ?>">
                <input type="hidden" name="action" value="delete">
                <button type="submit" class="inv-btn-sm inv-btn-delete" title="Supprimer">
                  <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-2 14a2 2 0 0 1-2 2H9a2 2 0 0 1-2-2L5 6"/></svg>
                </button>
              </form>
            <?php endif; ?>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

  <?php elseif ($active_tab === 'ia'): ?>
    <!-- ===== ASSISTANT IA ===== -->
    <?php if (!$ai_ready): ?>
      <div class="alert alert-error">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/></svg>
        <div>
          <strong>L'Assistant IA n'est pas encore configuré.</strong><br>
          L'administrateur doit ajouter la clé API Anthropic dans <code>config.php</code> (variable <code>ANTHROPIC_API_KEY</code>).<br>
          Obtenir une clé : <a href="https://console.anthropic.com" target="_blank" style="color: var(--acc);">console.anthropic.com</a>
        </div>
      </div>
    <?php endif; ?>

    <?php if (!empty($_GET['err'])): ?>
      <div class="alert alert-error">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/></svg>
        <?= h($_GET['err']) ?>
      </div>
    <?php endif; ?>

    <?php if (isset($_GET['generated'])): ?>
      <div class="alert alert-success">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
        Document généré avec succès ! Vous le retrouvez ci-dessous.
      </div>
    <?php endif; ?>

    <div class="ai-hero">
      <div class="ai-hero-icon">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
      </div>
      <div class="ai-hero-body">
        <h2 class="ai-hero-title">Votre copilote pour ce projet <span class="ai-hero-badge">Claude</span></h2>
        <p class="ai-hero-desc">L'IA connaît toutes les infos de <?= h($project['name']) ?> : ses étapes, les <?= count($messages) ?> messages de l'équipe, les <?= count($files) ?> fichiers et <?= count($invoices) ?> facture<?= count($invoices) > 1 ? 's' : '' ?>. Elle peut rédiger vos bilans avec le détail financier intégré.</p>
      </div>
    </div>

    <div class="section-head">
      <h2>Aide à la rédaction</h2>
      <div class="section-head-meta">Un clic pour générer un document</div>
    </div>

    <form method="POST" action="/action-ia" style="margin-bottom: 24px;">
      <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
      <input type="hidden" name="project_id" value="<?= $project_id ?>">
      <input type="hidden" name="mode" value="generate">
      <div class="ai-actions-grid">
        <button type="submit" name="doc_type" value="bilan_ag" class="ai-action-card" <?= !$ai_ready ? 'disabled' : '' ?>>
          <span class="ai-action-emoji">📊</span>
          <span class="ai-action-title">Bilan pour l'AG</span>
          <span class="ai-action-desc">Rapport pour l'Assemblée Générale</span>
        </button>
        <button type="submit" name="doc_type" value="email_parents" class="ai-action-card" <?= !$ai_ready ? 'disabled' : '' ?>>
          <span class="ai-action-emoji">✉️</span>
          <span class="ai-action-title">Email aux parents</span>
          <span class="ai-action-desc">Message d'information aux familles</span>
        </button>
        <button type="submit" name="doc_type" value="rapport_subvention" class="ai-action-card" <?= !$ai_ready ? 'disabled' : '' ?>>
          <span class="ai-action-emoji">📋</span>
          <span class="ai-action-title">Rapport de subvention</span>
          <span class="ai-action-desc">Pour un financeur public</span>
        </button>
        <button type="submit" name="doc_type" value="fiche_com" class="ai-action-card" <?= !$ai_ready ? 'disabled' : '' ?>>
          <span class="ai-action-emoji">📣</span>
          <span class="ai-action-title">Fiche de com'</span>
          <span class="ai-action-desc">Pour vos réseaux sociaux</span>
        </button>
        <button type="submit" name="doc_type" value="synthese_etape" class="ai-action-card" <?= !$ai_ready ? 'disabled' : '' ?>>
          <span class="ai-action-emoji">📌</span>
          <span class="ai-action-title">Synthèse d'avancement</span>
          <span class="ai-action-desc">Point interne rapide</span>
        </button>
      </div>
    </form>

    <?php if (!empty($generated_docs)): ?>
    <div class="section-head">
      <h2>Documents générés</h2>
      <div class="section-head-meta"><?= count($generated_docs) ?> document<?= count($generated_docs) > 1 ? 's' : '' ?></div>
    </div>
    <div style="margin-bottom: 28px;">
      <?php foreach (array_slice($generated_docs, 0, 5) as $d):
        $preview = mb_substr(strip_tags(preg_replace('/\n+/', ' ', $d['content'])), 0, 220);
      ?>
      <div class="gen-doc">
        <div class="gen-doc-head">
          <div>
            <h3 class="gen-doc-title"><?= h($d['title']) ?></h3>
            <div class="gen-doc-meta">Par <?= h($d['first_name'] . ' ' . $d['last_name']) ?> · <?= h(format_date_p($d['created_at'])) ?></div>
          </div>
          <span class="gen-doc-tag">IA</span>
        </div>
        <div class="gen-doc-preview"><?= h($preview) ?>…</div>
        <div class="gen-doc-actions">
          <a href="#" onclick="showDoc(<?= (int)$d['id'] ?>); return false;">Voir en entier</a>
          <a href="#" onclick="copyDoc(<?= (int)$d['id'] ?>); return false;">Copier</a>
        </div>
        <div id="doc-full-<?= (int)$d['id'] ?>" style="display:none; margin-top: 14px; padding-top: 14px; border-top: 1px solid var(--border);">
          <div class="ai-msg-content"><?= ai_markdown_to_html($d['content']) ?></div>
        </div>
        <textarea id="doc-raw-<?= (int)$d['id'] ?>" style="position:absolute;left:-9999px;"><?= h($d['content']) ?></textarea>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <div class="section-head">
      <h2>Parler avec l'IA</h2>
      <div class="section-head-meta">Posez-lui des questions sur ce projet</div>
    </div>

    <div class="ai-chat-wrap">
      <div class="ai-chat-head">
        <div class="ai-chat-head-left">
          <span class="ai-chat-dot"></span>
          <span>Assistant IA</span>
        </div>
        <span class="ai-chat-model">Propulsé par Claude</span>
      </div>

      <div class="ai-chat-list" id="aiChatList">
        <?php if (empty($active_conv_messages)): ?>
          <div class="empty-state" style="padding: 20px;">
            Posez votre première question sur ce projet.<br>
            L'IA connaît toutes les infos : étapes, messages, fichiers.
          </div>
        <?php else: foreach ($active_conv_messages as $msg): ?>
          <div class="ai-msg <?= h($msg['role']) ?>">
            <div class="ai-msg-avatar">
              <?= $msg['role'] === 'user' ? h(user_initials($current['first_name'], $current['last_name'])) : 'IA' ?>
            </div>
            <div class="ai-msg-content">
              <?= $msg['role'] === 'assistant' ? ai_markdown_to_html($msg['content']) : nl2br(h($msg['content'])) ?>
            </div>
          </div>
        <?php endforeach; endif; ?>
      </div>

      <?php if (empty($active_conv_messages)): ?>
      <div class="ai-suggestions">
        <button type="button" class="ai-suggestion" onclick="fillAiInput('Quel est l&apos;état actuel de ce projet ?')">Où en est le projet ?</button>
        <button type="button" class="ai-suggestion" onclick="fillAiInput('Quels sont les points de vigilance à suivre ?')">Points de vigilance ?</button>
        <button type="button" class="ai-suggestion" onclick="fillAiInput('Propose-moi 3 idées pour accélérer ce projet.')">3 idées pour accélérer</button>
        <button type="button" class="ai-suggestion" onclick="fillAiInput('Fais-moi un résumé court pour le président.')">Résumé président</button>
      </div>
      <?php endif; ?>

      <form method="POST" action="/action-ia" class="ai-input-wrap">
        <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
        <input type="hidden" name="project_id" value="<?= $project_id ?>">
        <input type="hidden" name="mode" value="chat">
        <input type="hidden" name="conversation_id" value="<?= $active_conv_id ?>">
        <textarea name="message" class="ai-input" id="aiInput" placeholder="Posez une question à l'IA…" required maxlength="4000" rows="1" <?= !$ai_ready ? 'disabled' : '' ?>></textarea>
        <button type="submit" class="ai-send" <?= !$ai_ready ? 'disabled' : '' ?>>
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
          Envoyer
        </button>
      </form>
    </div>
    <p class="ai-disclaimer">L'IA peut faire des erreurs. Vérifiez les informations importantes. Ses réponses ne remplacent pas un avis professionnel.</p>

  <?php elseif ($active_tab === 'historique' && $is_admin): ?>
    <!-- ===== HISTORIQUE DU PROJET (admin uniquement) ===== -->
    <?php
    // Filtres
    $filter_action = $_GET['type'] ?? 'all';
    $filter_user = (int)($_GET['user'] ?? 0);
    $filter_since = $_GET['since'] ?? 'all';

    // Construction de la requête
    $where_parts = ['pal.project_id = ?'];
    $history_params = [$project_id];

    if ($filter_action !== 'all') {
        // On regroupe par catégorie
        $action_filters = [
            'project' => ['project_created', 'project_updated'],
            'status' => ['status_changed'],
            'budget' => ['budget_changed'],
            'team' => ['referent_changed', 'follower_added', 'follower_removed'],
            'steps' => ['step_added', 'step_updated', 'step_deleted'],
            'invoices' => ['invoice_added', 'invoice_updated', 'invoice_deleted'],
            'messages' => ['message_deleted'],
        ];
        if (isset($action_filters[$filter_action])) {
            $types = $action_filters[$filter_action];
            $placeholders = implode(',', array_fill(0, count($types), '?'));
            $where_parts[] = 'pal.action_type IN (' . $placeholders . ')';
            $history_params = array_merge($history_params, $types);
        }
    }

    if ($filter_user > 0) {
        $where_parts[] = 'pal.user_id = ?';
        $history_params[] = $filter_user;
    }

    if ($filter_since !== 'all') {
        $intervals = [
            '24h' => '1 DAY',
            '7d' => '7 DAY',
            '30d' => '30 DAY',
            '90d' => '90 DAY',
        ];
        if (isset($intervals[$filter_since])) {
            $where_parts[] = "pal.created_at >= DATE_SUB(NOW(), INTERVAL " . $intervals[$filter_since] . ")";
        }
    }

    $where_clause = implode(' AND ', $where_parts);

    $history_stmt = $pdo->prepare("
        SELECT pal.*,
               u.first_name, u.last_name, u.avatar_color, u.role AS user_role
        FROM project_activity_log pal
        JOIN users u ON pal.user_id = u.id
        WHERE $where_clause
        ORDER BY pal.created_at DESC
        LIMIT 500
    ");
    $history_stmt->execute($history_params);
    $activities = $history_stmt->fetchAll();

    // Liste des utilisateurs qui ont laissé des traces (pour le filtre)
    $users_filter_stmt = $pdo->prepare("
        SELECT DISTINCT u.id, u.first_name, u.last_name
        FROM project_activity_log pal
        JOIN users u ON pal.user_id = u.id
        WHERE pal.project_id = ?
        ORDER BY u.first_name
    ");
    $users_filter_stmt->execute([$project_id]);
    $users_who_acted = $users_filter_stmt->fetchAll();

    // Stats
    $total_stmt = $pdo->prepare("SELECT COUNT(*) FROM project_activity_log WHERE project_id = ?");
    $total_stmt->execute([$project_id]);
    $total_activities = (int)$total_stmt->fetchColumn();

    $avatar_colors_hex = [
        'blue' => '#4F80BD', 'purple' => '#7F77DD', 'amber' => '#EF9F27',
        'pink' => '#D77CA0', 'teal' => '#2AAE89', 'green' => '#059669',
        'red' => '#B91C1C', 'gray' => '#78716C'
    ];

    // Helpers d'affichage
    function action_icon($type) {
        $icons = [
            'project_created' => '✨',
            'project_updated' => '✏️',
            'status_changed' => '🔄',
            'budget_changed' => '💰',
            'referent_changed' => '👤',
            'follower_added' => '👁️',
            'follower_removed' => '🚪',
            'step_added' => '➕',
            'step_updated' => '📝',
            'step_deleted' => '🗑️',
            'invoice_added' => '🧾',
            'invoice_updated' => '📋',
            'invoice_deleted' => '🗑️',
            'message_deleted' => '💬',
        ];
        return $icons[$type] ?? '•';
    }
    function action_category($type) {
        $cats = [
            'project_created' => 'project',
            'project_updated' => 'project',
            'status_changed' => 'status',
            'budget_changed' => 'budget',
            'referent_changed' => 'team',
            'follower_added' => 'team',
            'follower_removed' => 'team',
            'step_added' => 'steps',
            'step_updated' => 'steps',
            'step_deleted' => 'steps',
            'invoice_added' => 'invoices',
            'invoice_updated' => 'invoices',
            'invoice_deleted' => 'invoices',
            'message_deleted' => 'messages',
        ];
        return $cats[$type] ?? 'other';
    }
    ?>

    <div class="history-wrapper">

      <!-- Header historique -->
      <div class="history-head">
        <div>
          <h2 class="history-title">📜 Historique du projet</h2>
          <p class="history-sub">
            <?= $total_activities ?> action<?= $total_activities > 1 ? 's' : '' ?> enregistrée<?= $total_activities > 1 ? 's' : '' ?> depuis la création.
            <span style="color: var(--ink-4);">· Visible par les administrateurs uniquement.</span>
          </p>
        </div>
      </div>

      <!-- Filtres -->
      <form method="GET" action="/projet/<?= $project_id ?>/historique" class="history-filters">
        <input type="hidden" name="tab" value="historique">

        <select name="type" onchange="this.form.submit()" class="history-filter-select">
          <option value="all" <?= $filter_action === 'all' ? 'selected' : '' ?>>Toutes les actions</option>
          <option value="project" <?= $filter_action === 'project' ? 'selected' : '' ?>>✏️ Modifications du projet</option>
          <option value="status" <?= $filter_action === 'status' ? 'selected' : '' ?>>🔄 Changements de statut</option>
          <option value="budget" <?= $filter_action === 'budget' ? 'selected' : '' ?>>💰 Budget</option>
          <option value="team" <?= $filter_action === 'team' ? 'selected' : '' ?>>👥 Équipe et suivi</option>
          <option value="steps" <?= $filter_action === 'steps' ? 'selected' : '' ?>>📝 Étapes</option>
          <option value="invoices" <?= $filter_action === 'invoices' ? 'selected' : '' ?>>🧾 Factures</option>
          <option value="messages" <?= $filter_action === 'messages' ? 'selected' : '' ?>>💬 Messages</option>
        </select>

        <?php if (count($users_who_acted) > 1): ?>
        <select name="user" onchange="this.form.submit()" class="history-filter-select">
          <option value="0" <?= $filter_user === 0 ? 'selected' : '' ?>>Tous les utilisateurs</option>
          <?php foreach ($users_who_acted as $u): ?>
            <option value="<?= (int)$u['id'] ?>" <?= $filter_user === (int)$u['id'] ? 'selected' : '' ?>>
              <?= h($u['first_name'] . ' ' . $u['last_name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
        <?php endif; ?>

        <select name="since" onchange="this.form.submit()" class="history-filter-select">
          <option value="all" <?= $filter_since === 'all' ? 'selected' : '' ?>>Toute l'historique</option>
          <option value="24h" <?= $filter_since === '24h' ? 'selected' : '' ?>>Dernières 24h</option>
          <option value="7d" <?= $filter_since === '7d' ? 'selected' : '' ?>>7 derniers jours</option>
          <option value="30d" <?= $filter_since === '30d' ? 'selected' : '' ?>>30 derniers jours</option>
          <option value="90d" <?= $filter_since === '90d' ? 'selected' : '' ?>>90 derniers jours</option>
        </select>

        <?php if ($filter_action !== 'all' || $filter_user > 0 || $filter_since !== 'all'): ?>
          <a href="/projet/<?= $project_id ?>/historique" class="history-filter-reset">Réinitialiser</a>
        <?php endif; ?>
      </form>

      <!-- Timeline -->
      <?php if (empty($activities)): ?>
        <div class="history-empty">
          <div style="font-size: 40px; margin-bottom: 10px;">📭</div>
          <div style="font-size: 15px; color: var(--ink-2); margin-bottom: 4px;">Aucune activité à afficher</div>
          <div style="font-size: 13px; color: var(--ink-3);">Essayez d'ajuster les filtres ou reviens plus tard.</div>
        </div>
      <?php else:
        $last_date = null;
        foreach ($activities as $act):
          $act_date = date('Y-m-d', strtotime($act['created_at']));
          $show_separator = ($act_date !== $last_date);
          $last_date = $act_date;

          $cat = action_category($act['action_type']);
          $avatar_hex = $avatar_colors_hex[$act['avatar_color'] ?? 'gray'] ?? '#78716C';

          // Format date lisible
          $ts = strtotime($act['created_at']);
          $today = strtotime(date('Y-m-d'));
          $yesterday = strtotime('-1 day', $today);
          $act_day_ts = strtotime($act_date);

          if ($act_day_ts === $today) $day_label = "Aujourd'hui";
          elseif ($act_day_ts === $yesterday) $day_label = "Hier";
          else {
            $days_fr = ['Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi', 'Dimanche'];
            $months_fr = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
            $day_label = $days_fr[(int)date('N', $ts) - 1] . ' ' . (int)date('j', $ts) . ' ' . $months_fr[(int)date('n', $ts) - 1] . ' ' . date('Y', $ts);
          }
      ?>
        <?php if ($show_separator): ?>
          <div class="history-day-separator"><?= h($day_label) ?></div>
        <?php endif; ?>

        <div class="history-item history-cat-<?= $cat ?>">
          <div class="history-time"><?= h(date('H:i:s', $ts)) ?></div>

          <div class="history-avatar" style="background: <?= $avatar_hex ?>;" title="<?= h($act['first_name'] . ' ' . $act['last_name']) ?>">
            <?= h(strtoupper(mb_substr($act['first_name'], 0, 1) . mb_substr($act['last_name'], 0, 1))) ?>
          </div>

          <div class="history-icon"><?= action_icon($act['action_type']) ?></div>

          <div class="history-body">
            <div class="history-text">
              <strong><?= h($act['first_name'] . ' ' . $act['last_name']) ?></strong>
              <?= h($act['action_label']) ?>
            </div>
            <?php if (!empty($act['changes'])):
              $changes_data = json_decode($act['changes'], true);
              if (is_array($changes_data) && !empty($changes_data['field'])): ?>
                <div class="history-detail">
                  <span class="history-detail-field"><?= h($changes_data['field']) ?></span>
                </div>
            <?php endif; endif; ?>
          </div>

          <div class="history-fulldate" title="<?= h($act['created_at']) ?>">
            <?= h(date('d/m/Y H:i:s', $ts)) ?>
          </div>
        </div>
      <?php endforeach; endif; ?>

      <?php if (count($activities) >= 500): ?>
        <div style="padding: 14px; text-align: center; color: var(--ink-3); font-size: 12.5px;">
          Seules les 500 actions les plus récentes sont affichées.
        </div>
      <?php endif; ?>

    </div>

    <style>
    .history-wrapper { max-width: 1000px; }
    .history-head { margin-bottom: 20px; }
    .history-title { font-size: 18px; font-weight: 500; letter-spacing: -0.01em; margin-bottom: 4px; }
    .history-sub { font-size: 13px; color: var(--ink-3); line-height: 1.5; }

    .history-filters { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; margin-bottom: 20px; padding: 12px; background: var(--bg-2); border: 1px solid var(--border); border-radius: 10px; }
    .history-filter-select { padding: 7px 12px; border: 1px solid var(--border); border-radius: 8px; background: var(--bg); font-size: 12.5px; color: var(--ink-2); cursor: pointer; font-family: inherit; }
    .history-filter-reset { font-size: 11.5px; color: var(--ink-3); text-decoration: none; padding: 7px 10px; border-radius: 6px; }
    .history-filter-reset:hover { background: var(--bg); color: var(--ink); }

    .history-day-separator { font-size: 11px; color: var(--ink-3); font-weight: 600; letter-spacing: 0.06em; text-transform: uppercase; padding: 20px 0 10px; border-bottom: 1px solid var(--border); margin-bottom: 10px; }

    .history-item { display: grid; grid-template-columns: 60px 32px 28px 1fr auto; gap: 12px; align-items: center; padding: 10px 12px; border: 1px solid var(--border); border-radius: 10px; margin-bottom: 6px; background: var(--bg); transition: border-color 0.12s ease; }
    .history-item:hover { border-color: var(--border-strong); }
    .history-item.history-cat-status { border-left: 3px solid #4F80BD; }
    .history-item.history-cat-budget { border-left: 3px solid #EF9F27; }
    .history-item.history-cat-team { border-left: 3px solid #7F77DD; }
    .history-item.history-cat-steps { border-left: 3px solid #2AAE89; }
    .history-item.history-cat-invoices { border-left: 3px solid #059669; }
    .history-item.history-cat-messages { border-left: 3px solid #D77CA0; }
    .history-item.history-cat-project { border-left: 3px solid #78716C; }

    .history-time { font-size: 11.5px; color: var(--ink-3); font-variant-numeric: tabular-nums; font-weight: 500; }
    .history-avatar { width: 32px; height: 32px; border-radius: 50%; color: #fff; display: flex; align-items: center; justify-content: center; font-size: 11px; font-weight: 600; flex-shrink: 0; }
    .history-icon { font-size: 16px; text-align: center; }
    .history-body { min-width: 0; }
    .history-text { font-size: 13px; color: var(--ink); line-height: 1.5; }
    .history-text strong { font-weight: 500; }
    .history-detail { font-size: 11px; color: var(--ink-4); margin-top: 3px; }
    .history-detail-field { padding: 1px 6px; background: var(--bg-2); border-radius: 4px; font-family: monospace; }
    .history-fulldate { font-size: 11px; color: var(--ink-4); font-variant-numeric: tabular-nums; white-space: nowrap; }

    .history-empty { padding: 60px 20px; text-align: center; }

    @media (max-width: 720px) {
      .history-item { grid-template-columns: 32px 28px 1fr; gap: 10px; }
      .history-time, .history-fulldate { grid-column: 1 / -1; font-size: 10.5px; color: var(--ink-3); }
      .history-item .history-time { display: none; }
    }
    </style>

  <?php endif; ?>

</main>

<script>
(function () {
  var chat = document.getElementById('chatList');
  if (chat) chat.scrollTop = chat.scrollHeight;
  var aiChat = document.getElementById('aiChatList');
  if (aiChat) aiChat.scrollTop = aiChat.scrollHeight;
})();

function autoExpand(el) {
  el.style.height = 'auto';
  el.style.height = Math.min(el.scrollHeight, 160) + 'px';
}
var chatInput = document.getElementById('chatInput');
if (chatInput) chatInput.addEventListener('input', function () { autoExpand(this); });
var aiInput = document.getElementById('aiInput');
if (aiInput) aiInput.addEventListener('input', function () { autoExpand(this); });

[chatInput, aiInput].filter(Boolean).forEach(function (el) {
  el.addEventListener('keydown', function (e) {
    if (e.key === 'Enter' && !e.shiftKey) {
      // Ne pas soumettre si dropdown @mention est ouvert (laisse le dropdown gérer)
      var dropdown = document.getElementById('mentionDropdown');
      if (dropdown && dropdown.style.display === 'block' && this.id === 'chatInput') {
        return; // Le handler du dropdown gère
      }
      e.preventDefault();
      this.form.submit();
    }
  });
});

// ============================================================
// AUTOCOMPLETE @MENTIONS DANS LES MESSAGES PROJET
// ============================================================
(function() {
    var input = document.getElementById('chatInput');
    var dropdown = document.getElementById('mentionDropdown');
    var listEl = document.getElementById('mentionList');
    var dataEl = document.getElementById('mentionDataJson');
    if (!input || !dropdown || !listEl || !dataEl) return;
    
    var members = [];
    try {
        members = JSON.parse(dataEl.textContent || '[]');
    } catch (e) { 
        return; 
    }
    if (!members.length) return;
    
    var COLORS = {
        'blue': '#3B82F6', 'purple': '#8B5CF6', 'amber': '#F59E0B',
        'pink': '#EC4899', 'teal': '#14B8A6', 'green': '#10B981',
        'red': '#EF4444', 'indigo': '#6366F1'
    };
    var ROLE_LABELS = {
        'admin': '🛡️ Admin',
        'coordinator': '🧭 Coord',
        'referent': '🎯 Référent',
        'member': '👤 Membre',
        'follower': '👀 Suiveur'
    };
    
    var currentSearch = '';
    var currentMatchStart = -1;
    var selectedIdx = 0;
    var filtered = [];
    
    function detectMentionContext() {
        var pos = input.selectionStart;
        var text = input.value;
        var beforeCursor = text.substring(0, pos);
        // Cherche le dernier @ avant le curseur, qui n'est PAS précédé par un caractère alphanumérique
        var match = beforeCursor.match(/(?:^|\s)@([a-zA-ZÀ-ÿ\.\-]*)$/u);
        if (match) {
            return {
                search: match[1],
                start: pos - match[1].length - 1, // position du @
            };
        }
        return null;
    }
    
    function renderDropdown() {
        if (!filtered.length) {
            dropdown.style.display = 'none';
            return;
        }
        var html = '';
        filtered.forEach(function(m, idx) {
            var color = COLORS[m.color] || '#3B82F6';
            var isActive = idx === selectedIdx;
            html += '<div class="mention-item" data-idx="' + idx + '" style="display:flex; align-items:center; gap:10px; padding:8px 12px; cursor:pointer; ' +
                'background:' + (isActive ? 'var(--bg-2)' : 'transparent') + ';' +
                'border-left:3px solid ' + (isActive ? color : 'transparent') + ';' +
                '">' +
                '<span style="width:28px; height:28px; border-radius:50%; background:' + color + '; color:#fff; display:inline-flex; align-items:center; justify-content:center; font-size:11px; font-weight:600; flex-shrink:0;">' + 
                (m.initials || '?') + '</span>' +
                '<div style="flex:1; min-width:0;">' +
                '<div style="font-size:13px; font-weight:500; color:var(--ink); white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">' +
                escapeHtml(m.name) + '</div>' +
                '<div style="font-size:11px; color:var(--ink-3); margin-top:1px;">@' + escapeHtml(m.tag) + ' · ' + (ROLE_LABELS[m.role] || m.role) + '</div>' +
                '</div>' +
                '</div>';
        });
        listEl.innerHTML = html;
        dropdown.style.display = 'block';
        
        // Click sur un item
        listEl.querySelectorAll('.mention-item').forEach(function(el) {
            el.addEventListener('mouseenter', function() {
                selectedIdx = parseInt(this.dataset.idx);
                renderDropdown();
            });
            el.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                selectedIdx = parseInt(this.dataset.idx);
                insertMention();
            });
        });
    }
    
    function escapeHtml(s) {
        var div = document.createElement('div');
        div.textContent = s;
        return div.innerHTML;
    }
    
    function filterMembers(search) {
        var s = (search || '').toLowerCase();
        if (!s) return members.slice(0, 8);
        return members.filter(function(m) {
            return m.tag.toLowerCase().indexOf(s) === 0 
                || m.name.toLowerCase().indexOf(s) >= 0;
        }).slice(0, 8);
    }
    
    function updateAutocomplete() {
        var ctx = detectMentionContext();
        if (!ctx) {
            dropdown.style.display = 'none';
            currentMatchStart = -1;
            return;
        }
        currentSearch = ctx.search;
        currentMatchStart = ctx.start;
        filtered = filterMembers(currentSearch);
        selectedIdx = 0;
        renderDropdown();
    }
    
    function insertMention() {
        if (!filtered.length || currentMatchStart < 0) return;
        var member = filtered[selectedIdx];
        if (!member) return;
        
        var text = input.value;
        var pos = input.selectionStart;
        // Remplacer @search par @prenom (espace après pour faciliter la suite)
        var before = text.substring(0, currentMatchStart);
        var after = text.substring(pos);
        var insertion = '@' + member.tag + ' ';
        input.value = before + insertion + after;
        var newPos = currentMatchStart + insertion.length;
        input.setSelectionRange(newPos, newPos);
        
        dropdown.style.display = 'none';
        currentMatchStart = -1;
        autoExpand(input);
        input.focus();
    }
    
    // Listeners
    input.addEventListener('input', updateAutocomplete);
    input.addEventListener('click', updateAutocomplete);
    input.addEventListener('keyup', function(e) {
        if (['ArrowDown', 'ArrowUp', 'Enter', 'Escape', 'Tab'].indexOf(e.key) >= 0) return;
        updateAutocomplete();
    });
    
    input.addEventListener('keydown', function(e) {
        if (dropdown.style.display !== 'block') return;
        
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            selectedIdx = (selectedIdx + 1) % filtered.length;
            renderDropdown();
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            selectedIdx = (selectedIdx - 1 + filtered.length) % filtered.length;
            renderDropdown();
        } else if (e.key === 'Enter' || e.key === 'Tab') {
            if (filtered.length > 0) {
                e.preventDefault();
                e.stopPropagation();
                insertMention();
            }
        } else if (e.key === 'Escape') {
            e.preventDefault();
            dropdown.style.display = 'none';
            currentMatchStart = -1;
        }
    });
    
    // Fermer si on clique en dehors
    document.addEventListener('click', function(e) {
        if (!dropdown.contains(e.target) && e.target !== input) {
            dropdown.style.display = 'none';
        }
    });
})();

function fillAiInput(text) {
  var el = document.getElementById('aiInput');
  if (!el) return;
  el.value = text;
  el.focus();
  autoExpand(el);
}

function showDoc(id) {
  var el = document.getElementById('doc-full-' + id);
  if (el) el.style.display = el.style.display === 'none' ? 'block' : 'none';
}

function copyDoc(id) {
  var ta = document.getElementById('doc-raw-' + id);
  if (!ta) return;
  ta.select();
  try {
    document.execCommand('copy');
    alert('Document copié dans le presse-papiers !');
  } catch (e) {
    alert('Impossible de copier automatiquement. Sélectionnez le texte manuellement.');
  }
}

// ===== Gestion des modes de saisie TVA pour les factures =====
(function () {
  var chips = document.querySelectorAll('.amount-mode-chip');
  if (chips.length === 0) return;

  var modeTtc = document.getElementById('modeTtc');
  var modeHt = document.getElementById('modeHt');
  var modeNoVat = document.getElementById('modeNoVat');
  var inputTtc = document.getElementById('inputTtc');
  var inputHt = document.getElementById('inputHt');
  var inputNoVat = document.getElementById('inputNoVat');
  var vatRateTtc = document.getElementById('vatRateTtc');
  var vatRateHt = document.getElementById('vatRateHt');
  var hintHtComputed = document.getElementById('hintHtComputed');
  var hintTtcComputed = document.getElementById('hintTtcComputed');

  function parseFr(v) {
    if (!v) return 0;
    return parseFloat(String(v).replace(/\s/g, '').replace(',', '.')) || 0;
  }
  function formatEur(v) {
    return v.toFixed(2).replace('.', ',') + ' €';
  }

  function switchMode(mode) {
    chips.forEach(function (c) { c.classList.toggle('active', c.dataset.mode === mode); });
    if (modeTtc) modeTtc.style.display = mode === 'ttc' ? '' : 'none';
    if (modeHt) modeHt.style.display = mode === 'ht' ? '' : 'none';
    if (modeNoVat) modeNoVat.style.display = mode === 'no_vat' ? '' : 'none';
    // Coche le bon radio
    var radio = document.querySelector('input[name="amount_mode"][value="' + mode + '"]');
    if (radio) radio.checked = true;
    // Active/désactive les champs pour éviter les doublons
    if (inputTtc) inputTtc.disabled = (mode !== 'ttc' && mode !== 'no_vat');
    if (inputHt) inputHt.disabled = (mode !== 'ht');
    if (inputNoVat) inputNoVat.disabled = (mode !== 'no_vat');
    // En mode no_vat, inputNoVat et inputTtc ont le même name "amount_ttc" — on doit renommer
    if (inputTtc) inputTtc.name = (mode === 'ttc') ? 'amount_ttc' : '';
    if (inputNoVat) inputNoVat.name = (mode === 'no_vat') ? 'amount_ttc' : '';
    recalc();
  }

  function recalc() {
    var mode = document.querySelector('input[name="amount_mode"]:checked');
    if (!mode) return;
    mode = mode.value;

    if (mode === 'ttc' && inputTtc && vatRateTtc && hintHtComputed) {
      var ttc = parseFr(inputTtc.value);
      var rate = parseFr(vatRateTtc.value);
      if (ttc > 0) {
        var ht = ttc / (1 + rate / 100);
        hintHtComputed.innerHTML = 'HT calculé : <b>' + formatEur(ht) + '</b> · TVA : <b>' + formatEur(ttc - ht) + '</b>';
      } else {
        hintHtComputed.innerHTML = 'HT calculé : <b>—</b>';
      }
    } else if (mode === 'ht' && inputHt && vatRateHt && hintTtcComputed) {
      var ht2 = parseFr(inputHt.value);
      var rate2 = parseFr(vatRateHt.value);
      if (ht2 > 0) {
        var ttc2 = ht2 * (1 + rate2 / 100);
        hintTtcComputed.innerHTML = 'TTC calculé : <b>' + formatEur(ttc2) + '</b> · TVA : <b>' + formatEur(ttc2 - ht2) + '</b>';
        // Sync le champ vat_rate (celui envoyé au serveur, qui est dans le modeTtc)
        if (vatRateTtc) vatRateTtc.value = vatRateHt.value;
      } else {
        hintTtcComputed.innerHTML = 'TTC calculé : <b>—</b>';
      }
    }
  }

  chips.forEach(function (chip) {
    chip.addEventListener('click', function (e) {
      e.preventDefault();
      switchMode(chip.dataset.mode);
    });
  });

  [inputTtc, inputHt, vatRateTtc, vatRateHt].filter(Boolean).forEach(function (el) {
    el.addEventListener('input', recalc);
    el.addEventListener('change', recalc);
  });

  // Style pour les chips actifs
  document.querySelectorAll('.amount-mode-chip').forEach(function (c) {
    c.style.cursor = 'pointer';
  });
})();

// ===== Scan IA d'une facture =====
(function () {
  var scanBtn = document.getElementById('scanBtn');
  var scanFileInput = document.getElementById('scanFileInput');
  var scanStatus = document.getElementById('scanStatus');
  var invoiceForm = document.getElementById('invoiceForm');
  var tempFileInput = document.getElementById('tempFileInput');

  if (!scanBtn || !scanFileInput || !invoiceForm) return;

  scanBtn.addEventListener('click', function () { scanFileInput.click(); });

  scanFileInput.addEventListener('change', function () {
    var file = scanFileInput.files[0];
    if (!file) return;
    scanFile(file);
  });

  // Permettre aussi le glisser-déposer sur la zone
  var scanZone = document.getElementById('scanZone');
  if (scanZone) {
    ['dragenter', 'dragover'].forEach(function (evt) {
      scanZone.addEventListener(evt, function (e) {
        e.preventDefault();
        scanZone.style.borderColor = 'var(--ai-dark)';
        scanZone.style.backgroundColor = 'var(--ai-light)';
      });
    });
    ['dragleave', 'drop'].forEach(function (evt) {
      scanZone.addEventListener(evt, function (e) {
        e.preventDefault();
        scanZone.style.borderColor = '';
        scanZone.style.backgroundColor = '';
      });
    });
    scanZone.addEventListener('drop', function (e) {
      e.preventDefault();
      var file = e.dataTransfer.files[0];
      if (file) scanFile(file);
    });
  }

  function scanFile(file) {
    var allowed = ['application/pdf', 'image/jpeg', 'image/png', 'image/jpg'];
    if (allowed.indexOf(file.type) === -1) {
      showStatus('❌ Format non supporté. Utilisez PDF, JPG ou PNG.', 'error');
      return;
    }
    if (file.size > 10 * 1024 * 1024) {
      showStatus('❌ Fichier trop gros (max 10 Mo).', 'error');
      return;
    }

    showStatus('<span class="ai-typing"><span></span><span></span><span></span></span> L\'IA lit votre facture…', 'loading');
    scanBtn.disabled = true;

    var fd = new FormData();
    fd.append('csrf_token', '<?= h($_SESSION['csrf_token']) ?>');
    fd.append('project_id', '<?= $project_id ?>');
    fd.append('invoice_file', file);

    fetch('/action-scan-facture', { method: 'POST', body: fd })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        scanBtn.disabled = false;
        if (!data.success) {
          showStatus('❌ ' + (data.error || 'Erreur inconnue'), 'error');
          return;
        }
        fillFormFromScan(data);
        var msg = '✅ Facture analysée ! Vérifiez les informations ci-dessous et ajustez si nécessaire.';
        if (data.confidence === 'low') msg = '⚠️ Analyse avec faible confiance. Vérifiez bien les informations ci-dessous.';
        if (data.warnings && data.warnings.length > 0) msg += '<br><span style="color: var(--ink-3); font-size: 11.5px;">Points à vérifier : ' + data.warnings.join(' · ') + '</span>';
        showStatus(msg, 'success');

        // Scroll doux vers le formulaire
        invoiceForm.scrollIntoView({ behavior: 'smooth', block: 'start' });
      })
      .catch(function (err) {
        scanBtn.disabled = false;
        showStatus('❌ Erreur réseau : ' + err.message, 'error');
      });
  }

  function showStatus(html, type) {
    scanStatus.style.display = 'block';
    scanStatus.innerHTML = html;
    if (type === 'success') scanStatus.style.color = 'var(--acc-dark)';
    else if (type === 'error') scanStatus.style.color = '#B91C1C';
    else scanStatus.style.color = 'var(--ai-dark)';
  }

  function fillFormFromScan(data) {
    // Fournisseur
    setVal('supplier_name', data.supplier_name);
    // Catégorie
    var catSelect = invoiceForm.querySelector('select[name="category"]');
    if (catSelect && data.category) {
      for (var i = 0; i < catSelect.options.length; i++) {
        if (catSelect.options[i].value === data.category) {
          catSelect.selectedIndex = i;
          break;
        }
      }
    }
    // Description
    setVal('description', data.description);
    // Date
    setVal('invoice_date', data.invoice_date);
    // N° de facture
    setVal('invoice_number', data.invoice_number);

    // On bascule en mode TTC et on remplit TTC + TVA
    var chipTtc = document.querySelector('.amount-mode-chip[data-mode="ttc"]');
    if (chipTtc) chipTtc.click();

    if (data.amount_ttc) {
      var inputTtc = document.getElementById('inputTtc');
      if (inputTtc) {
        inputTtc.value = String(data.amount_ttc).replace('.', ',');
      }
    }
    if (data.vat_rate !== null && data.vat_rate !== undefined) {
      var vatSelect = document.getElementById('vatRateTtc');
      if (vatSelect) {
        var rate = String(data.vat_rate);
        // Normaliser 5.5 / 2.1
        if (rate === '5.50') rate = '5.5';
        if (rate === '2.10') rate = '2.1';
        if (rate === '20.00') rate = '20';
        if (rate === '10.00') rate = '10';
        for (var j = 0; j < vatSelect.options.length; j++) {
          if (vatSelect.options[j].value === rate) {
            vatSelect.selectedIndex = j;
            break;
          }
        }
      }
    }

    // Mémoriser le chemin du fichier temporaire
    if (data.temp_file && tempFileInput) {
      tempFileInput.value = data.temp_file;
    }

    // Forcer le recalcul pour afficher le HT
    var inputTtc2 = document.getElementById('inputTtc');
    if (inputTtc2) {
      inputTtc2.dispatchEvent(new Event('input', { bubbles: true }));
    }
  }

  function setVal(name, value) {
    if (value === null || value === undefined) return;
    var el = invoiceForm.querySelector('[name="' + name + '"]');
    if (el) el.value = value;
  }
})();

// ===== Toggle zone de pièce jointe manuelle =====
(function () {
  var toggle = document.getElementById('toggleManualFile');
  var zone = document.getElementById('manualFileZone');
  var label = document.getElementById('toggleManualLabel');
  if (!toggle || !zone) return;

  toggle.addEventListener('click', function (e) {
    e.preventDefault();
    var isOpen = zone.style.display !== 'none';
    if (isOpen) {
      zone.style.display = 'none';
      if (label) label.textContent = 'Joindre un justificatif manuellement';
    } else {
      zone.style.display = 'block';
      if (label) label.textContent = 'Masquer la pièce jointe manuelle';
      // Focus sur le champ pour guider
      var input = zone.querySelector('input[type="file"]');
      if (input) setTimeout(function () { input.click(); }, 100);
    }
  });
})();
</script>

<?php render_foot(); ?>
