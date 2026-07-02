<?php
/**
 * support-nouveau.php — Creer un nouveau ticket cote ASSO
 * =========================================================
 * Formulaire avec titre, categorie, priorite, 1er message.
 * Envoie notifications aux SA+Fondateurs apres creation.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes-layout.php';
require_once __DIR__ . '/support-helper.php';
@require_once __DIR__ . '/sa-permissions.php';
@require_once __DIR__ . '/resend-helper.php';
require_login();

$user = current_user();
$org_id = (int) ($user['org_id'] ?? 0);

if ($org_id <= 0) {
    header('Location: /super-admin/support');
    exit;
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && check_csrf($_POST['csrf_token'] ?? '')) {
    $title = trim($_POST['title'] ?? '');
    $category = $_POST['category'] ?? 'question';
    $priority = $_POST['priority'] ?? 'normal';
    $body = trim($_POST['body'] ?? '');

    $valid_cats = ['question','bug','feature_request','billing','account','other'];
    $valid_prio = ['low','normal','high','urgent'];

    if ($title === '' || strlen($title) < 5) {
        $error = 'Le titre doit contenir au moins 5 caractères.';
    } elseif ($body === '' || strlen($body) < 10) {
        $error = 'Merci de décrire votre demande (au moins 10 caractères).';
    } elseif (!in_array($category, $valid_cats, true)) {
        $error = 'Catégorie invalide.';
    } elseif (!in_array($priority, $valid_prio, true)) {
        $error = 'Priorité invalide.';
    } else {
        $pdo->beginTransaction();
        try {
            // Creer le ticket
            $stmt = $pdo->prepare("
                INSERT INTO support_tickets
                    (org_id, created_by_user_id, title, category, priority, status,
                     last_message_at, last_message_by, created_at)
                VALUES (?, ?, ?, ?, ?, 'open', NOW(), 'org', NOW())
            ");
            $stmt->execute([$org_id, (int) $user['id'], $title, $category, $priority]);
            $ticket_id = (int) $pdo->lastInsertId();

            // Ajouter le 1er message
            $stmt = $pdo->prepare("
                INSERT INTO support_messages
                    (ticket_id, author_user_id, author_side, body, read_by_org, created_at)
                VALUES (?, ?, 'org', ?, 1, NOW())
            ");
            $stmt->execute([$ticket_id, (int) $user['id'], $body]);

            // Log event
            support_log_event($ticket_id, (int) $user['id'], 'created');

            $pdo->commit();

            // Notifier support (async best-effort)
            try {
                support_notify_new_message($ticket_id, (int) $user['id'], 'org', $body);
            } catch (Throwable $e) {
                error_log('Support notify: ' . $e->getMessage());
            }

            header('Location: /support/ticket/' . $ticket_id);
            exit;

        } catch (Throwable $e) {
            $pdo->rollBack();
            $error = 'Erreur technique : ' . $e->getMessage();
        }
    }
}

render_head('Nouveau ticket');
render_sidebar('support');
?>

<div class="main">

  <div style="font-size:12px; margin-bottom:10px; color:var(--ink-3);">
    <a href="/support" style="color:var(--ink-3); text-decoration:none;">Support</a>
    <span style="color:var(--ink-4);"> › </span>
    <span>Nouveau ticket</span>
  </div>

  <div class="main-head">
    <div>
      <h1 class="page-title">💬 Nouveau ticket</h1>
      <div class="page-sub">Notre équipe vous répondra dans les meilleurs délais.</div>
    </div>
  </div>

  <?php if ($error): ?>
    <div class="alert alert-error">
      <span>⚠️</span>
      <div><?= h($error) ?></div>
    </div>
  <?php endif; ?>

  <form method="POST" action="/support/nouveau"
        style="background:var(--bg); border:1px solid var(--border); border-radius:12px; padding:24px; max-width:720px;">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">

    <div style="margin-bottom:16px;">
      <label for="title" style="display:block; font-size:13px; font-weight:500; margin-bottom:6px;">Titre *</label>
      <input type="text" id="title" name="title" required minlength="5" maxlength="200"
             placeholder="Ex : Problème d'accès aux factures"
             value="<?= h($_POST['title'] ?? '') ?>"
             style="width:100%; padding:10px 12px; background:var(--bg); border:1px solid var(--border-strong); border-radius:8px; font-family:inherit; font-size:14px; color:var(--ink);">
    </div>

    <div style="display:grid; grid-template-columns: 1fr 1fr; gap:14px; margin-bottom:16px;">
      <div>
        <label for="category" style="display:block; font-size:13px; font-weight:500; margin-bottom:6px;">Catégorie *</label>
        <select id="category" name="category" required
                style="width:100%; padding:10px 12px; background:var(--bg); border:1px solid var(--border-strong); border-radius:8px; font-family:inherit; font-size:14px; color:var(--ink);">
          <option value="question" <?= ($_POST['category'] ?? '') === 'question' ? 'selected' : '' ?>>❓ Question</option>
          <option value="bug" <?= ($_POST['category'] ?? '') === 'bug' ? 'selected' : '' ?>>🐛 Bug / Problème technique</option>
          <option value="feature_request" <?= ($_POST['category'] ?? '') === 'feature_request' ? 'selected' : '' ?>>💡 Demande de fonctionnalité</option>
          <option value="billing" <?= ($_POST['category'] ?? '') === 'billing' ? 'selected' : '' ?>>💳 Facturation</option>
          <option value="account" <?= ($_POST['category'] ?? '') === 'account' ? 'selected' : '' ?>>👤 Compte</option>
          <option value="other" <?= ($_POST['category'] ?? '') === 'other' ? 'selected' : '' ?>>📌 Autre</option>
        </select>
      </div>
      <div>
        <label for="priority" style="display:block; font-size:13px; font-weight:500; margin-bottom:6px;">Priorité *</label>
        <select id="priority" name="priority" required
                style="width:100%; padding:10px 12px; background:var(--bg); border:1px solid var(--border-strong); border-radius:8px; font-family:inherit; font-size:14px; color:var(--ink);">
          <option value="low">🟢 Basse — question générale</option>
          <option value="normal" selected>⚪ Normale — demande standard</option>
          <option value="high">🟠 Haute — impact sur utilisation</option>
          <option value="urgent">🔴 Urgente — plateforme inutilisable</option>
        </select>
      </div>
    </div>

    <div style="margin-bottom:20px;">
      <label for="body" style="display:block; font-size:13px; font-weight:500; margin-bottom:6px;">Décrivez votre demande *</label>
      <textarea id="body" name="body" required minlength="10" rows="8"
                placeholder="Soyez précis : étapes pour reproduire le problème, ce que vous attendez, captures d'écran si possible (à envoyer par email en cas de besoin)..."
                style="width:100%; padding:10px 12px; background:var(--bg); border:1px solid var(--border-strong); border-radius:8px; font-family:inherit; font-size:14px; color:var(--ink); resize:vertical;"><?= h($_POST['body'] ?? '') ?></textarea>
      <div style="font-size:11.5px; color:var(--ink-4); margin-top:4px;">
        Plus votre description est précise, plus vite nous pourrons vous aider.
      </div>
    </div>

    <div style="display:flex; gap:10px; justify-content:flex-end;">
      <a href="/support" class="btn btn-ghost">Annuler</a>
      <button type="submit" class="btn btn-primary">💬 Envoyer le ticket</button>
    </div>
  </form>

</div>

<?php render_foot(); ?>
